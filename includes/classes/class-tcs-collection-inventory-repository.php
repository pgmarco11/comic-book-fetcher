<?php
defined('ABSPATH') || exit;

class TCS_Collection_Inventory_Repository {
    public function record(int $id): array {
        $meta = get_post_meta($id);
        $read = static function ($key, $fallback = '') use ($meta) {
            return isset($meta[$key][0]) ? (string) $meta[$key][0] : $fallback;
        };
        $record = [
            'id' => $id,
            'title' => get_the_title($id),
            'issue' => $read('issue_number'),
            'volume' => $read('volume'),
            'year' => $read('year'),
            'date_published' => $read('date_published'),
            'series_id' => absint($read('title_id')),
            'issue_id' => absint($read('issue_id')),
            'qty' => max(1, (int) $read('qty', '1')),
            'condition' => $read('condition'),
            'price' => $read('price'),
            'notes' => $read('notes'),
            'storage_location' => $read('storage_location'),
            'cover' => esc_url_raw($read('cover_image_url')),
            'publisher' => '',
        ];

        $series_terms = get_the_terms(
            $id,
            'comic_series'
        );

        if (
            $series_terms &&
            !is_wp_error($series_terms)
        ) {
            $series_term = reset($series_terms);

            if ($series_term instanceof WP_Term) {
                /*
                 * The inventory's "title" value represents the
                 * series name. The post title itself includes #issue.
                 */
                $record['title'] = $series_term->name;
            }
        }

        $publisher_terms = get_the_terms(
            $id,
            'publisher'
        );

        if (
            $publisher_terms &&
            !is_wp_error($publisher_terms)
        ) {
            foreach ($publisher_terms as $publisher_term) {
                if (
                    (int) $publisher_term->parent === 0 &&
                    strpos($publisher_term->slug, 'all-') !== 0
                ) {
                    $record['publisher'] =
                        $publisher_term->name;

                    break;
                }
            }
        }

        $record['version'] = hash('sha256', wp_json_encode([
            $record['qty'], $record['condition'], $record['price'],
            $record['notes'], $record['storage_location'],
        ]));

        /*
        * Collection links open the canonical issue page with the
        * My Collection tab selected.
        */
        $record['catalog_url'] =
            $record['issue_id'] && $record['series_id']
                ? add_query_arg(
                    [
                        'issue_id' => $record['issue_id'],
                        'title_id' => $record['series_id'],
                        'view'     => 'collection',
                    ],
                    home_url('/comic-database/issue/')
                )
                : get_permalink($id);
        return $record;
    }

    public function owned_series_records(int $title_id): array {
        if ($title_id <= 0) {
            return [];
        }
    
        return $this->owned_records([
            'meta_key'   => 'title_id',
            'meta_value' => (string) $title_id,
        ]);
    }

    public function remember_publisher_identity(
        int $metron_id,
        string $name
    ): void {
        if ($metron_id <= 0 || trim($name) === '') {
            return;
        }
    
        $term = get_term_by('name', $name, 'publisher');
    
        if ($term instanceof WP_Term) {
            update_term_meta(
                $term->term_id,
                '_tcs_metron_publisher_id',
                (string) $metron_id
            );
        }
    }
    
    public function local_publisher_info(int $metron_id): array {
        if ($metron_id <= 0) {
            return [];
        }
    
        $terms = get_terms([
            'taxonomy'   => 'publisher',
            'hide_empty' => false,
            'number'     => 1,
            'meta_key'   => '_tcs_metron_publisher_id',
            'meta_value' => (string) $metron_id,
        ]);
    
        if (!is_wp_error($terms) && $terms) {
            return [
                'id'   => $metron_id,
                'name' => $terms[0]->name,
            ];
        }
    
        $cached = get_transient('metron_publisher_v2_' . $metron_id);
        $snapshot = get_option('comicbooks_publishers_snapshot_v1', []);
    
        $items = is_array($snapshot) && is_array($snapshot['items'] ?? null)
            ? $snapshot['items']
            : [];
    
        foreach (array_merge([$cached], $items) as $publisher) {
            if (
                !is_array($publisher)
                || !empty($publisher['error'])
                || (int) ($publisher['id'] ?? 0) !== $metron_id
                || !is_string($publisher['name'] ?? null)
                || trim($publisher['name']) === ''
            ) {
                continue;
            }
    
            $this->remember_publisher_identity(
                $metron_id,
                $publisher['name']
            );
    
            return [
                'id'   => $metron_id,
                'name' => $publisher['name'],
            ];
        }
    
        return [];
    }

    public function has_owned_publisher_records(string $publisher_name): bool {
        $publisher_name = trim($publisher_name);
    
        if (!is_user_logged_in() || $publisher_name === '') {
            return false;
        }
    
        $term = get_term_by('name', $publisher_name, 'publisher');
    
        if (!($term instanceof WP_Term)) {
            return false;
        }
    
        return !empty(get_posts([
            'post_type' => 'collection',
            'post_status' => 'publish',
            'author' => get_current_user_id(),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query' => [[
                'taxonomy' => 'publisher',
                'field' => 'term_id',
                'terms' => [$term->term_id],
                'include_children' => false,
            ]],
        ]));
    }
    
    public function owned_publisher_records(string $publisher_name): array {
        $publisher_name = trim($publisher_name);
    
        if (!is_user_logged_in() || $publisher_name === '') {
            return [];
        }
    
        $term = get_term_by('name', $publisher_name, 'publisher');
    
        if (!($term instanceof WP_Term)) {
            return [];
        }
    
        return $this->owned_records([
            'tax_query' => [
                [
                    'taxonomy'         => 'publisher',
                    'field'            => 'term_id',
                    'terms'            => [$term->term_id],
                    'include_children' => false,
                ],
            ],
        ]);
    }
    
    private function owned_records(array $scope): array {
        $user_id = get_current_user_id();
    
        if ($user_id <= 0) {
            return [];
        }
    
        $posts = get_posts([
            'post_type'              => 'collection',
            'post_status'            => 'publish',
            'author'                 => $user_id,
            'posts_per_page'         => -1,
            'fields'                 => 'all',
            'no_found_rows'          => true,
            'cache_results'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        ] + $scope);
    
        $records = [];
    
        foreach ($posts as $post) {
            $records[] = $this->record( 
                (int) $post->ID
            );
        }
    
        $this->sort_records($records);
    
        return $records;
    }

    public function sort_records(
        array &$records
    ): void {
        usort(
            $records,
            static function (
                array $a,
                array $b
            ): int {

                /*
                 * 1. Publisher
                 */
                $comparison = strnatcasecmp(
                    (string) ($a['publisher'] ?? ''),
                    (string) ($b['publisher'] ?? '')
                );

                if ($comparison !== 0) {
                    return $comparison;
                }

                /*
                 * 2. Series title
                 */
                $comparison = strnatcasecmp(
                    (string) ($a['title'] ?? ''),
                    (string) ($b['title'] ?? '')
                );

                if ($comparison !== 0) {
                    return $comparison;
                }

                /*
                 * 3. Volume
                 */
                $volume_a = trim(
                    (string) ($a['volume'] ?? '')
                );

                $volume_b = trim(
                    (string) ($b['volume'] ?? '')
                );

                $comparison = strnatcasecmp(
                    $volume_a,
                    $volume_b
                );

                if ($comparison !== 0) {
                    return $comparison;
                }

                /*
                 * 4. Issue number
                 */
                $issue_a = trim(
                    (string) ($a['issue'] ?? '')
                );

                $issue_b = trim(
                    (string) ($b['issue'] ?? '')
                );

                $comparison = strnatcasecmp(
                    $issue_a,
                    $issue_b
                );

                if ($comparison !== 0) {
                    return $comparison;
                }

                /*
                 * Stable fallback.
                 */
                return ((int) ($a['id'] ?? 0))
                    <=> ((int) ($b['id'] ?? 0));
            }
        );
    }

    public function series_options(
        int $user_id,
        int $publisher_id = 0
    ): array {
        global $wpdb;

        $sql = "
        SELECT DISTINCT
            series_terms.term_id AS id,
            series_terms.name
        FROM {$wpdb->posts} posts

        INNER JOIN {$wpdb->term_relationships} series_rel
            ON series_rel.object_id = posts.ID

        INNER JOIN {$wpdb->term_taxonomy} series_tax
            ON series_tax.term_taxonomy_id =
                series_rel.term_taxonomy_id

        INNER JOIN {$wpdb->terms} series_terms
            ON series_terms.term_id =
                series_tax.term_id

        WHERE posts.post_type = 'collection'
            AND posts.post_status = 'publish'
            AND posts.post_author = %d
            AND series_tax.taxonomy = 'comic_series'
    ";

        $parameters = [$user_id];

        if ($publisher_id > 0) {
            $sql .= "
            AND EXISTS (
                SELECT 1
                FROM {$wpdb->term_relationships} publisher_rel

                INNER JOIN {$wpdb->term_taxonomy} publisher_tax
                    ON publisher_tax.term_taxonomy_id =
                        publisher_rel.term_taxonomy_id

                WHERE publisher_rel.object_id = posts.ID
                    AND publisher_tax.taxonomy = 'publisher'
                    AND publisher_tax.term_id = %d
            )
        ";

            $parameters[] = $publisher_id;
        }

        $sql .= ' ORDER BY series_terms.name ASC';

        $prepared = $wpdb->prepare(
            $sql,
            ...$parameters
        );

        $rows = $wpdb->get_results(
            $prepared,
            ARRAY_A
        ) ?: [];

        return array_map(
            static function (array $row): array {
                return [
                    'id'   => (int) $row['id'],
                    'name' => (string) $row['name'],
                ];
            },
            $rows
        );
    }

    public function matching_series_ids(
        int $series_term_id,
        int $publisher_id = 0
    ): array {
        if ($series_term_id < 1) {
            return [];
        }

        if ($publisher_id > 0) {
            return [$series_term_id];
        }

        $selected_series = get_term(
            $series_term_id,
            'comic_series'
        );

        if (
            !$selected_series instanceof WP_Term ||
            is_wp_error($selected_series)
        ) {
            return [];
        }

        $matching_ids = get_terms([
            'taxonomy'   => 'comic_series',
            'hide_empty' => false,
            'fields'     => 'ids',
            'name'       => $selected_series->name,
        ]);

        if (is_wp_error($matching_ids) || !$matching_ids) {
            return [$series_term_id];
        }

        return array_values(array_unique(array_map(
            'absint',
            $matching_ids
        )));
    }

    private function chart_entry_ids(int $user_id): array {
        if ($user_id <= 0) {
            return [];
        }
    
        $ids = get_posts([
            'post_type'      => 'collection',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'none',
        ]);
    
        update_meta_cache('post', $ids);
    
        return $ids;
    }
    
    public function creator_credit_counts(int $user_id): array {
        $counts = [];
        $labels = [];
    
        foreach ($this->chart_entry_ids($user_id) as $id) {
            $credits = (string) get_post_meta($id, 'creators', true);
            $seen = [];
    
            foreach (preg_split('/\R/u', $credits) ?: [] as $line) {
                $name = trim(explode(' – ', $line, 2)[0]);
    
                if ($name === '') {
                    continue;
                }
    
                $key = function_exists('mb_strtolower')
                    ? mb_strtolower($name, 'UTF-8')
                    : strtolower($name);
    
                if (isset($seen[$key])) {
                    continue;
                }
    
                $seen[$key] = true;
                $labels[$key] = $labels[$key] ?? $name;
                $label = $labels[$key];
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        }
    
        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
        arsort($counts, SORT_NUMERIC);
    
        return $counts;
    }
    
    public function collection_value(int $user_id): array {
        $ids = $this->chart_entry_ids($user_id);
        $total = 0;
        $priced = 0;
    
        foreach ($ids as $id) {
            $price = trim((string) get_post_meta($id, 'price', true));
    
            if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $price)) {
                continue;
            }
    
            [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');
            $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            $qty = max(1, (int) get_post_meta($id, 'qty', true));
    
            $total += $cents * $qty;
            $priced++;
        }
    
        return [
            'cents'   => $total,
            'priced'  => $priced,
            'entries' => count($ids),
        ];
    }

    public function collection_value_history(int $user_id, int $cents): ?array {
        if ($user_id <= 0) {
            return null;
        }
    
        $prefix = '_tcs_value_' . get_current_blog_id() . '_';
        $today = current_time('Y-m-d');
    
        update_user_meta($user_id, $prefix . $today, $cents);
    
        $history = [];
    
        foreach (get_user_meta($user_id) as $key => $values) {
            if (strpos($key, $prefix) !== 0) {
                continue;
            }
    
            $date = substr($key, strlen($prefix));
    
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) && $date <= $today) {
                $history[$date] = (int) $values[0];
            }
        }
    
        if (($history[$today] ?? null) !== $cents) {
            return null;
        }
    
        ksort($history);
    
        return array_slice($history, -90, null, true);
    }

    public function genre_assignment_counts(int $user_id): array {
        global $wpdb;
    
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.name, COUNT(DISTINCT p.ID) AS entry_count
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr
                 ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt
                 ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t
                 ON t.term_id = tt.term_id
             WHERE p.post_type = 'collection'
               AND p.post_status = 'publish'
               AND p.post_author = %d
               AND tt.taxonomy = 'comic_genre'
             GROUP BY t.name
             ORDER BY entry_count DESC, t.name ASC",
            $user_id
        ), ARRAY_A);
    
        $counts = [];
    
        foreach ($rows ?: [] as $row) {
            $counts[$row['name']] = (int) $row['entry_count'];
        }
    
        return $counts;
    }

    public function taxonomy_entry_counts(int $user_id, string $taxonomy): array {
        global $wpdb;
    
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.name, COUNT(*) AS entry_count
             FROM (
                 SELECT (
                     SELECT CASE WHEN COUNT(*) = 1 THEN MIN(tt.term_id) END
                     FROM {$wpdb->term_relationships} tr
                     INNER JOIN {$wpdb->term_taxonomy} tt
                         ON tt.term_taxonomy_id = tr.term_taxonomy_id
                     WHERE tr.object_id = p.ID AND tt.taxonomy = %s
                 ) AS term_id
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = 'collection'
                   AND p.post_status = 'publish'
                   AND p.post_author = %d
             ) assigned
             LEFT JOIN {$wpdb->terms} t ON t.term_id = assigned.term_id
             GROUP BY assigned.term_id, t.name
             ORDER BY entry_count DESC, t.name ASC",
            $taxonomy,
            $user_id
        ), ARRAY_A);
    
        $counts = [];
    
        foreach ($rows ?: [] as $row) {
            $label = $row['name'] ?? 'Unassigned or ambiguous';
            $counts[$label] = ($counts[$label] ?? 0) + (int) $row['entry_count'];
        }
    
        arsort($counts, SORT_NUMERIC);
    
        return $counts;
    }

    public function era_counts(int $user_id): array {
        global $wpdb;
    
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                (SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                 WHERE pm.post_id = p.ID AND pm.meta_key = 'year'
                 ORDER BY pm.meta_id LIMIT 1) AS publication_year,
                (SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                 WHERE pm.post_id = p.ID AND pm.meta_key = 'date_published'
                 ORDER BY pm.meta_id LIMIT 1) AS publication_date
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'collection'
               AND p.post_status = 'publish'
               AND p.post_author = %d",
            $user_id
        ), ARRAY_A);
    
        $counts = [];
    
        foreach ($rows ?: [] as $row) {
            $year = trim((string) $row['publication_year']);
    
            if (!preg_match('/^[1-9]\d{3}$/D', $year)) {
                preg_match(
                    '/^([1-9]\d{3})(?:-|$)/',
                    trim((string) $row['publication_date']),
                    $match
                );
                $year = $match[1] ?? '';
            }
    
            $era = $year === '' ? 'Unknown year' : get_comic_era((int) $year);
            $counts[$era] = ($counts[$era] ?? 0) + 1;
        }
    
        return $counts;
    }

    public function overview(
        int $user_id,
        int $publisher_id = 0
    ): array {
        global $wpdb;
        /*
         * Publisher dropdown always contains every publisher
         * represented in the user's collection.
         */
        $publisher_sql = $wpdb->prepare(
            "SELECT DISTINCT
            terms.term_id AS id,
            terms.name

        FROM {$wpdb->posts} posts

        INNER JOIN {$wpdb->term_relationships} relationships
            ON relationships.object_id = posts.ID

        INNER JOIN {$wpdb->term_taxonomy} taxonomy
            ON taxonomy.term_taxonomy_id =
                relationships.term_taxonomy_id

        INNER JOIN {$wpdb->terms} terms
            ON terms.term_id = taxonomy.term_id

        WHERE posts.post_type = 'collection'
            AND posts.post_status = 'publish'
            AND posts.post_author = %d
            AND taxonomy.taxonomy = 'publisher'

        ORDER BY terms.name ASC",
            $user_id
        );

        /*
         * Complete collection totals. These do not change
         * when a publisher or series filter is selected.
         */
        $summary_sql = $wpdb->prepare(
            "SELECT
            COUNT(*) AS entries,

            COALESCE(
                SUM(
                    GREATEST(
                        1,
                        COALESCE(
                            (
                                SELECT MAX(
                                    CAST(
                                        quantity.meta_value
                                        AS UNSIGNED
                                    )
                                )
                                FROM {$wpdb->postmeta} quantity
                                WHERE quantity.post_id = posts.ID
                                    AND quantity.meta_key = 'qty'
                            ),
                            1
                        )
                    )
                ),
                0
            ) AS copies

        FROM {$wpdb->posts} posts

        WHERE posts.post_type = 'collection'
            AND posts.post_status = 'publish'
            AND posts.post_author = %d",
            $user_id
        );

        $publishers = $wpdb->get_results(
            $publisher_sql,
            ARRAY_A
        ) ?: [];

        $publishers = array_map(
            static function (array $publisher): array {
                return [
                    'id'   => (int) $publisher['id'],
                    'name' => (string) $publisher['name'],
                ];
            },
            $publishers
        );

        /*
         * All series are used for the dashboard total.
         */
        $all_series = $this->series_options(
            $user_id
        );

        /*
         * The dropdown is restricted to the selected
         * publisher, when one is selected.
         */
        $series = $publisher_id > 0
            ? $this->series_options(
                $user_id,
                $publisher_id
            )
            : $all_series;

        $summary = $wpdb->get_row(
            $summary_sql,
            ARRAY_A
        ) ?: [
            'entries' => 0,
            'copies'  => 0,
        ];

        return [
            'publishers' => $publishers,
            'series'     => $series,

            'summary' => [
                'entries' => (int) $summary['entries'],
                'copies'  => (int) $summary['copies'],
                'series'  => count($all_series),
            ],
        ];
    }

    public function sort_by_price(
        array &$records,
        bool $descending = false
    ): void {
        $price_in_cents = static function ($record): ?int {
            $price = trim((string) ($record['price'] ?? ''));

            if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $price)) {
                return null;
            }

            $parts = explode('.', $price, 2);

            return ((int) $parts[0] * 100)
                + (int) str_pad($parts[1] ?? '', 2, '0');
        };

        usort(
            $records,
            static function ($a, $b) use (
                $price_in_cents,
                $descending
            ): int {
                $a_price = $price_in_cents($a);
                $b_price = $price_in_cents($b);

                // Blank or invalid prices stay last in either direction.
                if ($a_price === null && $b_price !== null) {
                    return 1;
                }

                if ($a_price !== null && $b_price === null) {
                    return -1;
                }

                $comparison = $descending
                    ? ($b_price <=> $a_price)
                    : ($a_price <=> $b_price);

                // Stable ordering when prices match.
                return $comparison ?: (
                    (int) $a['id'] <=> (int) $b['id']
                );
            }
        );
    }

    private function sort_by_year(array &$records, bool $descending): void {
        $years = [];
    
        foreach ($records as $record) {
            $year = trim((string) ($record['year'] ?? ''));
    
            if (!preg_match('/^[1-9]\d{3}$/D', $year)) {
                preg_match(
                    '/^([1-9]\d{3})(?:-|$)/',
                    trim((string) ($record['date_published'] ?? '')),
                    $match
                );
                $year = $match[1] ?? '';
            }
    
            $years[$record['id']] = $year === '' ? null : (int) $year;
        }
    
        usort($records, static function (array $a, array $b) use ($years, $descending): int {
            $year_a = $years[$a['id']];
            $year_b = $years[$b['id']];
    
            $unknown_order = ($year_a === null) <=> ($year_b === null);
            if ($unknown_order !== 0) {
                return $unknown_order;
            }
    
            $comparison = $descending ? $year_b <=> $year_a : $year_a <=> $year_b;
            return $comparison ?: ($a['id'] <=> $b['id']);
        });
    }

    private function sorting_records(array $posts, string $sort): array {
        global $wpdb;
    
        if (!$posts) {
            return [];
        }
    
        $ids = array_map(
            static fn($post): int => (int) $post->ID,
            $posts
        );
        
    
        $keys = match ($sort) {
            'collection' => ['volume', 'issue_number'],
            'year_asc', 'year_desc' => ['year', 'date_published'],
            'qty_desc' => ['qty'],
            default => ['price'],
        };
    
        $id_slots = implode(
            ', ',
            array_fill(0, count($ids), '%d')
        );
    
        $key_slots = implode(
            ', ',
            array_fill(0, count($keys), '%s')
        );
    
        $metadata = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$id_slots})
                     AND meta_key IN ({$key_slots})
                 ORDER BY meta_id ASC",
                ...array_merge($ids, $keys)
            ),
            ARRAY_A
        );
    
        $terms = $sort === 'collection'
            ? wp_get_object_terms(
                $ids,
                ['publisher', 'comic_series'],
                [
                    'fields'                 => 'all_with_object_id',
                    'orderby'                => 'name',
                    'order'                  => 'ASC',
                    'update_term_meta_cache' => false,
                ]
            )
            : [];
    
        // Preserve the existing path if a lightweight lookup fails.
        if (!is_array($metadata) || is_wp_error($terms)) {
            return array_map(
                fn($post): array => $this->record((int) $post->ID),
                $posts
            );
        }
    
        $values = [];
    
        foreach ($metadata as $row) {
            // Match get_post_meta(): the earliest value wins.
            if (!isset($values[$row['post_id']][$row['meta_key']])) {
                $values[$row['post_id']][$row['meta_key']] =
                    $row['meta_value'];
            }
        }
    
        $records = [];
    
        foreach ($posts as $post) {
            $id = (int) $post->ID;
    
            $records[$id] = [
                'id'        => $id,
                'title'     => get_the_title($post),
                'publisher' => '',
                'volume'    => (string) ($values[$id]['volume'] ?? ''),
                'issue'     => (string) ($values[$id]['issue_number'] ?? ''),
                'qty' => max(1, (int) ($values[$id]['qty'] ?? 1)),
                'price'     => (string) ($values[$id]['price'] ?? ''),
                'year'      => (string) ($values[$id]['year'] ?? ''),
                'date_published' => (string) ($values[$id]['date_published'] ?? ''),
            ];
        }
    
        $series_seen = [];
        $publisher_seen = [];
    
        foreach ($terms as $term) {
            $id = (int) $term->object_id;
    
            if (!isset($records[$id])) {
                continue;
            }
    
            if (
                $term->taxonomy === 'comic_series'
                && !isset($series_seen[$id])
            ) {
                $records[$id]['title'] = $term->name;
                $series_seen[$id] = true;
            }
    
            if (
                $term->taxonomy === 'publisher'
                && (int) $term->parent === 0
                && strpos($term->slug, 'all-') !== 0
                && !isset($publisher_seen[$id])
            ) {
                $records[$id]['publisher'] = $term->name;
                $publisher_seen[$id] = true;
            }
        }
    
        return array_values($records);
    }

    public function results(array $input): array {
        $page = max(1, absint(tcs_inventory_text($input['collection_page'] ?? 1)));
        $sort = tcs_inventory_text(
            $input['collection_sort'] ?? 'collection'
        );
        $args = [
            'post_type' => 'collection',
            'post_status' => 'publish',
            'author' => get_current_user_id(),
            'posts_per_page' => 24,
            'paged' => $page,
            'orderby' => [
                'date' => 'DESC',
                'ID' => 'DESC'
            ],
            'ignore_sticky_posts' => true,
        ];

        $chart_ids = TCS_Collection_Chart_Links::matching_ids($input);

        if ($chart_ids !== null) {
            $args['post__in'] = $chart_ids ?: [0];
        }

        $search = tcs_inventory_text($input['collection_search'] ?? '');
        if ($search !== '') {
            $args['s'] = $search;
            $args['search_columns'] = ['post_title'];
        }
        $meta = [];

        $taxonomy_filters = [];

        $publisher_id = absint(
            tcs_inventory_text(
                $input['collection_publisher'] ?? 0
            )
        );

        if ($publisher_id) {
            $taxonomy_filters[] = [
                'taxonomy'         => 'publisher',
                'field'            => 'term_id',
                'terms'            => [$publisher_id],
                'include_children' => false,
            ];
        }

        $series_term_id = absint(
            tcs_inventory_text(
                $input['collection_series'] ?? 0
            )
        );

        if ($series_term_id) {
            $matching_series_ids =
                $this->matching_series_ids(
                    $series_term_id,
                    $publisher_id
                );

            $taxonomy_filters[] = [
                'taxonomy'         => 'comic_series',
                'field'            => 'term_id',
                'terms'            => $matching_series_ids ?: [0],
                'include_children' => false,
            ];
        }

        if ($taxonomy_filters) {
            $args['tax_query'] = array_merge(
                ['relation' => 'AND'],
                $taxonomy_filters
            );
        }

        if (($input['collection_duplicates'] ?? '') === '1') {
            $meta[] = ['key' => 'qty', 'value' => 1, 'compare' => '>', 'type' => 'NUMERIC'];
        }

        if ($meta) $args['meta_query'] = $meta;

        if ($sort === 'title') $args['orderby'] = ['title' => 'ASC', 'ID' => 'ASC'];
        if ($sort === 'oldest') $args['orderby'] = ['date' => 'ASC', 'ID' => 'ASC'];

        // Named optional clause includes legacy records with no issue_number meta.
        if ($sort === 'issue') {
            if (!isset($args['meta_query'])) {
                $args['meta_query'] = [];
            }

            $args['meta_query']['series_order'] = [
                'key'     => 'series_name',
                'compare' => 'EXISTS',
                'type'    => 'CHAR',
            ];

            $args['meta_query']['issue_order'] = [
                'key'     => 'issue_number',
                'compare' => 'EXISTS',
                'type'    => 'DECIMAL(12,3)',
            ];

            $args['orderby'] = [
                'series_order' => 'ASC',
                'issue_order'  => 'ASC',
                'ID'           => 'ASC',
            ];
        }

        $sort_by_year = in_array($sort, ['year_asc', 'year_desc'], true);

        if ($sort_by_year || in_array($sort, ['collection', 'price_asc', 'price_desc', 'qty_desc'], true)) {
            $collection_args = $args;
        
            $collection_args['posts_per_page'] = -1;
            $collection_args['paged'] = 1;
            $collection_args['fields'] = 'all';
            $collection_args['no_found_rows'] = true;
        
            // Sorting does not need every comic's complete metadata or terms.
            $collection_args['update_post_meta_cache'] = false;
            $collection_args['update_post_term_cache'] = false;
        
            unset($collection_args['orderby']);
        
            $collection_query = new WP_Query($collection_args);
        
            $records = $this->sorting_records(
                $collection_query->posts,
                $sort
            );
        
            // Sort the complete matching collection before selecting a page.
            if ($sort === 'collection') {
                $this->sort_records($records);
            } elseif ($sort_by_year) {
                $this->sort_by_year($records, $sort === 'year_desc');
            } elseif ($sort === 'qty_desc') {
                usort($records, static function (array $a, array $b): int {
                    return ($b['qty'] <=> $a['qty']) ?: ($a['id'] <=> $b['id']);
                });    
            } else {
                $this->sort_by_price($records, $sort === 'price_desc');
            }
        
            $total = count($records);
            $per_page = 24;
            $pages = max(1, (int) ceil($total / $per_page));
            $page = min($page, $pages);
        
            $page_ids = array_column(
                array_slice(
                    $records,
                    ($page - 1) * $per_page,
                    $per_page
                ),
                'id'
            );
        
            /*
             * Load complete metadata and terms only for this page.
             *
             * An empty post__in would match all posts, so skip the query
             * when there are no matching IDs.
             */
            $page_posts = $page_ids ? get_posts([
                'post_type'              => 'collection',
                'post_status'            => 'publish',
                'author'                 => get_current_user_id(),
                'post__in'               => $page_ids,
                'orderby'                => 'post__in',
                'posts_per_page'         => count($page_ids),
                'fields'                 => 'all',
                'no_found_rows'          => true,
                'cache_results'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => true,
            ]) : [];
        
            $page_records = array_map(
                fn($post): array => $this->record((int) $post->ID),
                $page_posts
            );
        
            return [
                'records' => $page_records,
                'total'   => $total,
                'page'    => $page,
                'pages'   => $pages,
                'html'    => tcs_inventory_view()->items_html(
                    $page_records
                ),
            ];
        }
        
        $query = new WP_Query($args);
        // WordPress skips found_rows when an offset returns no posts. Read page one
        // to recover the real last page after deletions or an out-of-range URL.
        if ($page > 1 && !$query->posts) {
            $args['paged'] = 1;
            $query = new WP_Query($args);
            $page = min($page, max(1, (int) $query->max_num_pages));
            if ($page > 1) {
                $args['paged'] = $page;
                $query = new WP_Query($args);
            }
        }
        $pages = max(1, (int) $query->max_num_pages);
        $records = array_map(static function ($post) { return tcs_inventory_repository()->record((int) $post->ID); }, $query->posts);
        return [
            'records' => $records, 'total' => (int) $query->found_posts,
            'page' => $page, 'pages' => $pages,
            'html' => tcs_inventory_view()->items_html($records),
        ];
    }

    public function cleanup_orphaned_terms(
        array $terms_by_taxonomy
    ): void {

        foreach (
            [
                'publisher',
                'comic_genre',
            ] as $taxonomy
        ) {

            $term_ids = array_unique(
                array_filter(
                    array_map(
                        'absint',
                        (array) (
                            $terms_by_taxonomy[$taxonomy]
                            ?? []
                        )
                    )
                )
            );

            foreach ($term_ids as $term_id) {

                clean_term_cache(
                    $term_id,
                    $taxonomy
                );

                $term = get_term(
                    $term_id,
                    $taxonomy
                );

                if (
                    !$term ||
                    is_wp_error($term)
                ) {
                    continue;
                }

                /*
                 * If WordPress still has published collection
                 * entries using the term, leave it alone.
                 */
                if ((int) $term->count > 0) {
                    continue;
                }

                /*
                 * Extra safety: make sure there are no remaining
                 * object relationships at all.
                 */
                $object_ids = get_objects_in_term(
                    $term_id,
                    $taxonomy
                );

                if (
                    is_wp_error($object_ids) ||
                    !empty($object_ids)
                ) {
                    continue;
                }

                $deleted = wp_delete_term(
                    $term_id,
                    $taxonomy
                );

                if (is_wp_error($deleted)) {
                    error_log(
                        sprintf(
                            'TCS orphan term cleanup failed: %s #%d - %s',
                            $taxonomy,
                            $term_id,
                            $deleted->get_error_message()
                        )
                    );
                }
            }
        }
    }

    public function taxonomy_tree(
        int $user_id,
        int $publisher_id = 0,
        int $series_term_id = 0,
        ?array $entry_ids = null
    ): array {
        global $wpdb;
    
        if ($entry_ids === []) {
            return [];
        }

        $sql = "SELECT
            publisher_terms.term_id AS publisher_id,
            publisher_terms.name AS publisher_name,
            series_terms.term_id AS series_id,
            series_terms.name AS series_name,
            MAX(volume_meta.volume) AS volume,
            COUNT(DISTINCT posts.ID) AS issue_count,
            SUM(
                GREATEST(
                    1,
                    COALESCE(quantity.qty, 1)
                )
            ) AS copy_count

        FROM {$wpdb->posts} posts

        INNER JOIN {$wpdb->term_relationships} publisher_rel
            ON publisher_rel.object_id = posts.ID

        INNER JOIN {$wpdb->term_taxonomy} publisher_tax
            ON publisher_tax.term_taxonomy_id =
                publisher_rel.term_taxonomy_id
            AND publisher_tax.taxonomy = 'publisher'
            AND publisher_tax.parent = 0

        INNER JOIN {$wpdb->terms} publisher_terms
            ON publisher_terms.term_id =
                publisher_tax.term_id
            AND publisher_terms.slug NOT LIKE 'all-%'

        INNER JOIN {$wpdb->term_relationships} series_rel
            ON series_rel.object_id = posts.ID

        INNER JOIN {$wpdb->term_taxonomy} series_tax
            ON series_tax.term_taxonomy_id =
                series_rel.term_taxonomy_id
            AND series_tax.taxonomy = 'comic_series'

        INNER JOIN {$wpdb->terms} series_terms
            ON series_terms.term_id =
                series_tax.term_id

        LEFT JOIN (
            SELECT
                qty_meta.post_id,
                MAX(
                    CAST(qty_meta.meta_value AS UNSIGNED)
                ) AS qty

            FROM {$wpdb->posts} quantity_posts

            INNER JOIN {$wpdb->postmeta} qty_meta
                ON qty_meta.post_id = quantity_posts.ID
                AND qty_meta.meta_key = 'qty'

            WHERE quantity_posts.post_type = 'collection'
                AND quantity_posts.post_status = 'publish'
                AND quantity_posts.post_author = %d

            GROUP BY qty_meta.post_id
        ) quantity
            ON quantity.post_id = posts.ID

        LEFT JOIN (
            SELECT
                vol_meta.post_id,
                MAX(vol_meta.meta_value) AS volume

            FROM {$wpdb->posts} volume_posts

            INNER JOIN {$wpdb->postmeta} vol_meta
                ON vol_meta.post_id = volume_posts.ID
                AND vol_meta.meta_key = 'volume'

            WHERE volume_posts.post_type = 'collection'
                AND volume_posts.post_status = 'publish'
                AND volume_posts.post_author = %d

            GROUP BY vol_meta.post_id
        ) volume_meta
            ON volume_meta.post_id = posts.ID

        WHERE posts.post_type = 'collection'
            AND posts.post_status = 'publish'
            AND posts.post_author = %d
    ";

        $parameters = [
            $user_id, // Quantity aggregation.
            $user_id, // Volume aggregation.
            $user_id, // Main collection query.
        ];

        if ($entry_ids !== null) {
            $entry_ids = array_values(
                array_unique(
                    array_filter(
                        array_map('absint', $entry_ids)
                    )
                )
            );
        
            if (!$entry_ids) {
                return [];
            }
        
            $slots = implode(
                ', ',
                array_fill(0, count($entry_ids), '%d')
            );
        
            $sql .= " AND posts.ID IN ({$slots})";
        
            $parameters = array_merge(
                $parameters,
                $entry_ids
            );
        }

        if ($publisher_id > 0) {
            $sql .= ' AND publisher_terms.term_id = %d';
            $parameters[] = $publisher_id;
        }

        if ($series_term_id > 0) {
            $matching_series_ids =
                $this->matching_series_ids(
                    $series_term_id,
                    $publisher_id
                );

            if (!$matching_series_ids) {
                return [];
            }

            $placeholders = implode(
                ', ',
                array_fill(0, count($matching_series_ids), '%d')
            );

            $sql .= " AND series_terms.term_id IN ({$placeholders})";
            $parameters = array_merge(
                $parameters,
                $matching_series_ids
            );
        }

        $sql .= "
        GROUP BY
            publisher_terms.term_id,
            publisher_terms.name,
            series_terms.term_id,
            series_terms.name

        ORDER BY
            publisher_terms.name ASC,
            series_terms.name ASC";

        $sql = $wpdb->prepare(
            $sql,
            ...$parameters
        );

        $rows = $wpdb->get_results(
            $sql,
            ARRAY_A
        ) ?: [];

        $tree = [];

        foreach ($rows as $row) {
            $publisher_id = (int) $row['publisher_id'];

            if (!isset($tree[$publisher_id])) {
                $tree[$publisher_id] = [
                    'id'          => $publisher_id,
                    'name'        => (string) $row['publisher_name'],
                    'issue_count' => 0,
                    'copy_count'  => 0,
                    'series'      => [],
                ];
            }

            $issue_count = (int) $row['issue_count'];
            $copy_count = (int) $row['copy_count'];

            $tree[$publisher_id]['series'][] = [
                'id' =>
                    (int) $row['series_id'],

                'name' =>
                    (string) $row['series_name'],

                'volume' =>
                    (string) ($row['volume'] ?? ''),

                'issue_count' =>
                    $issue_count,

                'copy_count' =>
                    $copy_count,
            ];

            $tree[$publisher_id]['issue_count'] +=
                $issue_count;

            $tree[$publisher_id]['copy_count'] +=
                $copy_count;
        }

        return array_values($tree);
    }

}
