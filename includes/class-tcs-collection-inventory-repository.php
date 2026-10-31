<?php
defined('ABSPATH') || exit;

/** Owns collection inventory repository behavior; legacy function entry points delegate here. */
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
                    home_url('/comic-catalog/issue/')
                )
                : get_permalink($id);
        return $record;
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

        if (in_array(
            $sort,
            ['collection', 'price_asc', 'price_desc'],
            true
        )) {

            /*
             * Retrieve every matching collection entry first.
             * Sorting has to happen before pagination because
             * publisher and series are taxonomy-backed fields.
             */
            $collection_args = $args;

            $collection_args['posts_per_page'] = -1;
            $collection_args['paged'] = 1;
            $collection_args['fields'] = 'ids';

            unset($collection_args['orderby']);

            $collection_query = new WP_Query(
                $collection_args
            );

            $records = array_map(
                static function ($post_id): array {
                    return tcs_inventory_repository()->record(
                        (int) $post_id
                    );
                },
                $collection_query->posts
            );

            if ($sort === 'collection') {
                $this->sort_records($records);
            } else {
                $this->sort_by_price(
                    $records,
                    $sort === 'price_desc'
                );
            }

            $total = count($records);

            $per_page = 24;

            $pages = max(
                1,
                (int) ceil(
                    $total / $per_page
                )
            );

            $page = min(
                $page,
                $pages
            );

            $offset = (
                $page - 1
            ) * $per_page;

            $page_records = array_slice(
                $records,
                $offset,
                $per_page
            );

            return [
                'records' => $page_records,
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'html' =>
                    tcs_inventory_view()->items_html(
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
        int $series_term_id = 0
    ): array {
        global $wpdb;

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
                post_id,
                MAX(
                    CAST(meta_value AS UNSIGNED)
                ) AS qty
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'qty'
            GROUP BY post_id
        ) quantity
            ON quantity.post_id = posts.ID

        LEFT JOIN (
            SELECT
                post_id,
                MAX(meta_value) AS volume
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'volume'
            GROUP BY post_id
        ) volume_meta
            ON volume_meta.post_id = posts.ID

        WHERE posts.post_type = 'collection'
            AND posts.post_status = 'publish'
            AND posts.post_author = %d
    ";

        $parameters = [$user_id];

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
