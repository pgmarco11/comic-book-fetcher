<?php
/** Owner-scoped collection inventory. No Metron or Comic Vine requests. */
defined('ABSPATH') || exit;

// Keep existing frontend URLs; remove collection records from public discovery.
add_filter('register_post_type_args', function ($args, $post_type) {
    if ($post_type === 'collection') {
        $args['exclude_from_search'] = true;
        $args['show_in_rest'] = false;
    }
    return $args;
}, 10, 2);
add_filter('wp_sitemaps_post_types', function ($types) {
    unset($types['collection']);
    return $types;
});
add_filter('wp_sitemaps_taxonomies', function ($taxonomies) {
    unset(
        $taxonomies['publisher'],
        $taxonomies['comic_series'],
        $taxonomies['comic_genre']
    );
    return $taxonomies;
});
add_filter('oembed_response_data', function ($data, $post) {
    return $post->post_type === 'collection' ? false : $data;
}, 10, 2);
add_action('pre_get_posts', function ($query) {
    if (is_admin()) return;
    $types = (array) $query->get('post_type');
    if (in_array('collection', $types, true) ||
        (
            $query->is_main_query() && 
            ($query->is_post_type_archive('collection') || $query->is_tax([
                'publisher',
                'comic_series',
                'comic_genre',
            ]))
        ) 
        ){            
            $query->set('post_type', 'collection');
            if (is_user_logged_in()) {
                $query->set('author', get_current_user_id());
                $query->set('author__in', [get_current_user_id()]);
            } else {
                $query->set('post__in', [0]);
            }
        }
});
add_action('template_redirect', function () {
    if (is_singular('collection') || is_post_type_archive('collection') || is_tax([
        'publisher',
        'comic_series',
        'comic_genre',
    ])) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
    }
    if (is_singular('collection')) {
        $post = get_queried_object();
        if (!is_user_logged_in() || (int) $post->post_author !== get_current_user_id()) {
            status_header(404);
            wp_die('This collection entry is unavailable.', 'Collection entry unavailable', ['response' => 404]);
        }
    }
}, 0);

function tcs_inventory_text($value): string {
    return is_scalar($value) ? sanitize_text_field((string) $value) : '';
}

function tcs_inventory_asset_setup(): void {
    if (!is_post_type_archive('collection')) return;
    wp_enqueue_style('tcs-inventory', COMICBOOKS_PLUGIN_URL . 'css/collection-inventory.css', [], filemtime(COMICBOOKS_PLUGIN_DIR . 'css/collection-inventory.css'));
    wp_enqueue_script('tcs-inventory', COMICBOOKS_PLUGIN_URL . 'js/collection-inventory.js', [], filemtime(COMICBOOKS_PLUGIN_DIR . 'js/collection-inventory.js'), true);
    wp_localize_script('tcs-inventory', 'tcsInventory', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('tcs_inventory'),
    ]);
}
add_action('wp_enqueue_scripts', 'tcs_inventory_asset_setup', 30);

function tcs_inventory_record(int $id): array {
    $meta = get_post_meta($id);
    $read = static function ($key, $fallback = '') use ($meta) {
        return isset($meta[$key][0]) ? (string) $meta[$key][0] : $fallback;
    };
    $record = [
        'id' => $id,
        'title' => get_the_title($id),
        'issue' => $read('issue_number'),
        'volume' => $read('volume'),
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
    $record['catalog_url'] = $record['issue_id'] && $record['series_id']
        ? add_query_arg(['issue_id' => $record['issue_id'], 'title_id' => $record['series_id']], home_url('/comic-catalog/issue/'))
        : get_permalink($id);
    return $record;
}

function tcs_inventory_series_options(
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

/** Facets and totals include only the current owner's published collection. */
function tcs_inventory_overview(
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
    $all_series = tcs_inventory_series_options(
        $user_id
    );

    /*
     * The dropdown is restricted to the selected
     * publisher, when one is selected.
     */
    $series = $publisher_id > 0
        ? tcs_inventory_series_options(
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

function tcs_inventory_results(array $input): array {
    $page = max(1, absint(tcs_inventory_text($input['collection_page'] ?? 1)));
    $sort = tcs_inventory_text($input['collection_sort'] ?? 'recent');
    $args = [
        'post_type' => 'collection', 'post_status' => 'publish',
        'author' => get_current_user_id(), 'posts_per_page' => 24, 'paged' => $page,
        'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
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
        $taxonomy_filters[] = [
            'taxonomy'         => 'comic_series',
            'field'            => 'term_id',
            'terms'            => [$series_term_id],
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
    $records = array_map(static function ($post) { return tcs_inventory_record((int) $post->ID); }, $query->posts);
    return [
        'records' => $records, 'total' => (int) $query->found_posts,
        'page' => $page, 'pages' => $pages,
        'html' => tcs_inventory_items_html($records),
    ];
}

function tcs_inventory_items_html(array $records): string {
    ob_start();
    if (!$records) {
        echo '<div class="tci-empty"><span aria-hidden="true">✦</span><h3>No issues here yet</h3><p>Try another filter, or add a comic from the catalog.</p><a class="tci-button tci-button-primary" href="' . esc_url(home_url('/comic-catalog/')) . '">Explore the comic catalog</a></div>';
    } else {
        echo '<ul class="tci-items" aria-label="Collection issues">';
        foreach ($records as $record) {
            $id = $record['id'];
            $name = $record['title'] . ($record['issue'] !== '' ? ' #' . $record['issue'] : '');
            ?>
            <li class="tci-item" data-record="<?php echo esc_attr(wp_json_encode($record)); ?>">
                <article aria-labelledby="tci-title-<?php echo (int) $id; ?>">
                    <label class="tci-select"><input type="checkbox" name="inventory_selected[]" value="<?php echo (int) $id; ?>"><span class="tci-sr-only">Select <?php echo esc_html($name); ?></span></label>
                    <a class="tci-cover" href="<?php echo esc_url($record['catalog_url']); ?>" aria-label="View <?php echo esc_attr($name); ?> in the catalog">
                        <?php if ($record['cover']) : ?><img src="<?php echo esc_url($record['cover']); ?>" alt="" loading="lazy" decoding="async" width="260" height="400"><?php else : ?><span class="tci-cover-placeholder"><span><?php echo esc_html($record['publisher'] ?: 'COLLECTION'); ?></span><strong><?php echo esc_html($record['title']); ?></strong><b>#<?php echo esc_html($record['issue'] ?: '—'); ?></b></span><?php endif; ?>
                        <?php if ($record['qty'] > 1) : ?><span class="tci-copy-badge"><?php echo (int) $record['qty']; ?> copies</span><?php endif; ?>
                    </a>
                    <div class="tci-item-info">
                        <p class="tci-publisher"><?php echo esc_html($record['publisher'] ?: 'Publisher not set'); ?></p>
                        <h3 id="tci-title-<?php echo (int) $id; ?>"><a href="<?php echo esc_url($record['catalog_url']); ?>"><?php echo esc_html($record['title']); ?> <span>#<?php echo esc_html($record['issue'] ?: '—'); ?></span></a></h3>
                        <p class="tci-volume">Volume <?php echo esc_html($record['volume'] ?: '—'); ?></p>
                    </div>
                    <dl class="tci-item-facts">
                        <div><dt>Quantity</dt><dd><?php echo (int) $record['qty']; ?></dd></div>
                        <div><dt>Condition</dt><dd><?php echo esc_html($record['condition'] ?: 'Not set'); ?></dd></div>
                        <div><dt>Price</dt><dd><?php echo $record['price'] === '' ? 'Not set' : esc_html($record['price']); ?></dd></div>
                        <div><dt>Location</dt><dd><?php echo esc_html($record['storage_location'] ?: 'Not filed'); ?></dd></div>
                    </dl>
                    <button type="button" class="tci-edit tci-button" aria-label="Edit <?php echo esc_attr($name); ?>">Edit inventory <span aria-hidden="true">↗</span></button>
                </article>
            </li>
            <?php
        }
        echo '</ul>';
    }
    return (string) ob_get_clean();
}

function tcs_inventory_authorize(): void {
    if (!is_user_logged_in()) wp_send_json_error('Please sign in again.', 401);
    if (!check_ajax_referer('tcs_inventory', 'nonce', false)) wp_send_json_error('Your session expired. Refresh the page.', 403);
}

function tcs_inventory_owned_post(int $id, array $statuses = ['publish']): WP_Post {
    $post = get_post($id);
    if (!$post || $post->post_type !== 'collection' || (int) $post->post_author !== get_current_user_id() || !in_array($post->post_status, $statuses, true)) {
        wp_send_json_error('This collection entry is unavailable.', 403);
    }
    return $post;
}

add_action('wp_ajax_tcs_inventory_list', function () {
    tcs_inventory_authorize();

    $input = wp_unslash($_POST);
    $user_id = get_current_user_id();

    $results = tcs_inventory_results($input);
    unset($results['records']);

    $publisher_id = absint(
        tcs_inventory_text(
            $input['collection_publisher'] ?? 0
        )
    );

    $overview = tcs_inventory_overview(
        $user_id,
        $publisher_id
    );

    $taxonomy_tree = tcs_inventory_taxonomy_tree(
        $user_id
    );

    $taxonomy_html = tcs_inventory_taxonomy_html(
        $taxonomy_tree
    );

    wp_send_json_success(
        array_merge(
            $results,
            $overview,
            [
                'taxonomy_html' => $taxonomy_html,
            ]
        )
    );
});

add_action('wp_ajax_tcs_inventory_save', function () {
    tcs_inventory_authorize();
    $input = wp_unslash($_POST);
    $id = absint(tcs_inventory_text($input['post_id'] ?? 0));
    tcs_inventory_owned_post($id);
    $current = tcs_inventory_record($id);
    if (!hash_equals($current['version'], tcs_inventory_text($input['version'] ?? ''))) {
        wp_send_json_error('This entry changed elsewhere. Close the editor, refresh the collection, and try again.', 409);
    }
    $qty = tcs_inventory_text($input['qty'] ?? '');
    $price = tcs_inventory_text($input['price'] ?? '');
    if (!ctype_digit($qty) || (int) $qty < 1 || (int) $qty > 9999) wp_send_json_error('Quantity must be a whole number from 1 to 9,999.', 422);
    if ($price !== '' && !preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $price)) wp_send_json_error('Price must be a non-negative amount with up to two decimal places, or blank.', 422);
    $condition = tcs_inventory_text($input['condition'] ?? '');
    $location = tcs_inventory_text($input['storage_location'] ?? '');
    $notes = isset($input['notes']) && is_scalar($input['notes']) ? sanitize_textarea_field((string) $input['notes']) : '';
    if (strlen($condition) > 120 || strlen($location) > 120 || strlen($notes) > 10000) wp_send_json_error('One of the fields is too long.', 422);
    $fields = ['qty' => (string) (int) $qty, 'price' => $price, 'condition' => $condition, 'notes' => $notes, 'storage_location' => $location];
    foreach ($fields as $key => $value) update_post_meta($id, $key, wp_slash($value));
    foreach ($fields as $key => $value) {
        if ((string) get_post_meta($id, $key, true) !== $value) wp_send_json_error('Some changes could not be saved. Refresh before retrying.', 500);
    }
    clean_post_cache($id);
    wp_send_json_success(['record' => tcs_inventory_record($id)]);
});

add_action('wp_ajax_tcs_inventory_bulk', function () {
    tcs_inventory_authorize();
    $input = wp_unslash($_POST);
    $ids = array_values(array_unique(array_filter(array_map('absint', (array) ($input['post_ids'] ?? [])))));
    $operation = tcs_inventory_text($input['operation'] ?? '');
    if (!$ids || count($ids) > 24 || !in_array($operation, ['location', 'trash', 'restore'], true)) wp_send_json_error('Select up to 24 entries and a valid action.', 422);
    // Validate every entry before applying any mutation.
    foreach ($ids as $id) tcs_inventory_owned_post($id, $operation === 'restore' ? ['trash'] : ['publish']);
    if ($operation === 'trash' && (!defined('EMPTY_TRASH_DAYS') || !EMPTY_TRASH_DAYS)) wp_send_json_error('Trash is disabled on this site; no entries were removed.', 409);
    $location = tcs_inventory_text($input['storage_location'] ?? '');
    if ($operation === 'location' && ($location === '' || strlen($location) > 120)) wp_send_json_error('Enter a storage location of up to 120 characters.', 422);
    $completed = [];
    foreach ($ids as $id) {
        if ($operation === 'location') {
            update_post_meta($id, 'storage_location', wp_slash($location));
            $ok = get_post_meta($id, 'storage_location', true) === $location;
        } elseif ($operation === 'trash') {
            $ok = wp_trash_post($id);
        } else {
            $restore_status = static function ($status, $post_id, $previous) use ($id) {
                return (int) $post_id === $id ? 'publish' : $status;
            };
            add_filter('wp_untrash_post_status', $restore_status, 10, 3);
            $ok = wp_untrash_post($id);
            remove_filter('wp_untrash_post_status', $restore_status, 10);
        }
        if (!$ok) {
            wp_send_json_error(['message' => 'The operation stopped before all entries were updated. Refresh to see the result.', 'completed' => $completed, 'operation' => $operation], 500);
        }
        clean_post_cache($id);
        $completed[] = $id;
    }
    wp_send_json_success(['completed' => $completed, 'operation' => $operation]);
});

function tcs_inventory_taxonomy_tree(
    int $user_id
): array {
    global $wpdb;

    $sql = $wpdb->prepare(
        "SELECT
            publisher_terms.term_id AS publisher_id,
            publisher_terms.name AS publisher_name,
            series_terms.term_id AS series_id,
            series_terms.name AS series_name,
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
            AND publisher_terms.slug NOT LIKE 'all-%'
        INNER JOIN {$wpdb->terms} publisher_terms
            ON publisher_terms.term_id =
                publisher_tax.term_id

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

        WHERE posts.post_type = 'collection'
            AND posts.post_status = 'publish'
            AND posts.post_author = %d

        GROUP BY
            publisher_terms.term_id,
            publisher_terms.name,
            series_terms.term_id,
            series_terms.name

        ORDER BY
            publisher_terms.name ASC,
            series_terms.name ASC",
        $user_id
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
            'id'          => (int) $row['series_id'],
            'name'        => (string) $row['series_name'],
            'issue_count' => $issue_count,
            'copy_count'  => $copy_count,
        ];

        $tree[$publisher_id]['issue_count'] +=
            $issue_count;

        $tree[$publisher_id]['copy_count'] +=
            $copy_count;
    }

    return array_values($tree);
}
function tcs_inventory_taxonomy_html(
    array $publishers
): string {
    $archive_url = get_post_type_archive_link(
        'collection'
    );

    ob_start();
    ?>

    <section
        class="tci-series-index"
        aria-labelledby="tci-series-index-title"
    >
        <header>
            <p class="tci-kicker">YOUR LIBRARY</p>
            <h2 id="tci-series-index-title">
                Browse by publisher
            </h2>
        </header>

        <?php if (!$publishers) : ?>

            <div class="tci-empty">
                <h3>No organized series yet</h3>
                <p>
                    Add or refresh collection issues to organize
                    them by publisher and series.
                </p>
            </div>

        <?php else : ?>

            <div class="tci-publisher-groups">

                <?php foreach ($publishers as $publisher) : ?>

                    <details class="tci-publisher-group">

                        <summary>
                            <span class="tci-publisher-name">
                                <?php echo esc_html(
                                    $publisher['name']
                                ); ?>
                            </span>

                            <span class="tci-count">
                                <?php
                                printf(
                                    esc_html(
                                        _n(
                                            '%s issue',
                                            '%s issues',
                                            $publisher['issue_count'],
                                            'comic-book-fetcher'
                                        )
                                    ),
                                    number_format_i18n(
                                        $publisher['issue_count']
                                    )
                                );
                                ?>
                            </span>
                        </summary>

                        <ul class="tci-series-list">

                            <?php
                            foreach (
                                $publisher['series']
                                as $series
                            ) :
                                $series_url = add_query_arg(
                                    [
                                        'collection_publisher' =>
                                            $publisher['id'],
                                        'collection_series' =>
                                            $series['id'],
                                        'collection_view' =>
                                            'shelf',
                                    ],
                                    $archive_url
                                );
                                ?>

                                <li>
                                    <a href="<?php
                                        echo esc_url($series_url);
                                    ?>">
                                        <span>
                                            <?php echo esc_html(
                                                $series['name']
                                            ); ?>
                                        </span>

                                        <span class="tci-count">
                                            <?php
                                            printf(
                                                esc_html(
                                                    _n(
                                                        '%s issue',
                                                        '%s issues',
                                                        $series[
                                                            'issue_count'
                                                        ],
                                                        'comic-book-fetcher'
                                                    )
                                                ),
                                                number_format_i18n(
                                                    $series[
                                                        'issue_count'
                                                    ]
                                                )
                                            );
                                            ?>
                                        </span>
                                    </a>
                                </li>

                            <?php endforeach; ?>

                        </ul>
                    </details>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>
    </section>

    <?php
    return (string) ob_get_clean();
}

function tcs_inventory_render_app(): void {
    if (!is_user_logged_in()) {
        echo '<div class="tci-empty"><h1>My collection</h1><p>Sign in to view and manage your comics.</p><a class="tci-button tci-button-primary" href="' . esc_url(wp_login_url(get_post_type_archive_link('collection'))) . '">Sign in</a></div>';
        return;
    }
    $input = wp_unslash($_GET);
    $results = tcs_inventory_results($input);
    $publisher_id = absint(
        tcs_inventory_text(
            $input['collection_publisher'] ?? 0
        )
    );
    
    $overview = tcs_inventory_overview(
        get_current_user_id(),
        $publisher_id
    );

    $taxonomy_tree = tcs_inventory_taxonomy_tree(
        get_current_user_id()
    );
    
    $taxonomy_html = tcs_inventory_taxonomy_html(
        $taxonomy_tree
    );
    include COMICBOOKS_PLUGIN_DIR . 'templates/collection-inventory.php';
}
