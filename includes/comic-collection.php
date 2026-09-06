<?php
require_once __DIR__ . '/collection-catalog-data.php';

/**
 * Compute comic era from year
 */
function get_comic_era(int $year): string {
    if ($year < 1938) return 'Platinum';
    if ($year <= 1956) return 'Golden';
    if ($year <= 1970) return 'Silver';
    if ($year <= 1985) return 'Bronze';
    if ($year <= 2011) return 'Modern';
    return 'Post-Modern';
}

/**
 * =============================================
 * REGISTER CUSTOM POST TYPE: COLLECTION
 * URL: /my-collection/
 * =============================================
 */
function register_collection_post_type() {

    $labels = [
        'name'                  => 'Collections',
        'singular_name'         => 'Collection',
        'menu_name'             => 'Collections',
        'name_admin_bar'        => 'Collection',
        'add_new'               => 'Add New Comic',
        'add_new_item'          => 'Add New Comic to Collection',
        'new_item'              => 'New Comic',
        'edit_item'             => 'Edit Comic',
        'view_item'             => 'View Comic',
        'all_items'             => 'My Collection',
        'search_items'          => 'Search My Collection',
        'not_found'             => 'No comics found in your collection.',
        'not_found_in_trash'    => 'No comics found in Trash.',
        'featured_image'        => 'Cover Image',
        'set_featured_image'    => 'Set cover image',
        'remove_featured_image' => 'Remove cover image',
    ];

    $args = [
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => 'comicbooks-settings',
        'show_in_admin_bar'  => true,
        'query_var'          => true,
        'rewrite'            => ['slug' => 'my-collection'],
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'supports'           => ['title', 'editor', 'thumbnail', 'author', 'custom-fields'],
        'show_in_rest'       => true,
        'map_meta_cap'       => true,
        'menu_position'      => 25,
        'menu_icon'          => 'dashicons-book-alt',
    ];

    register_post_type('collection', $args);
}
add_action('init', 'register_collection_post_type', 0);


/**
* Register Custom Taxonomies
*/
function register_comic_taxonomies() {

        // ======================
        // Publisher Taxonomy
        // ======================
        $publisher_labels = [
            'name'          => 'Publishers',
            'singular_name' => 'Publisher',
            'menu_name'     => 'Publishers',
            'search_items'  => 'Search Publishers',
            'popular_items' => 'Popular Publishers',
            'all_items'     => 'All Publishers',
            'edit_item'     => 'Edit Publisher',
            'update_item'   => 'Update Publisher',
            'add_new_item'  => 'Add New Publisher',
            'new_item_name' => 'New Publisher Name',
            'not_found'     => 'No publishers found.',
            'no_terms'      => 'No publishers',
        ];

        if (!taxonomy_exists('publisher')) {

            register_taxonomy('publisher', 'collection', [
                'labels'            => $publisher_labels,
                'hierarchical'      => false,
                'public'            => true,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_nav_menus' => false,
                'show_tagcloud'     => false,
                'show_in_rest'      => true,
                'query_var'         => true,
                'rewrite'           => ['slug' => 'publisher'],
            ]);            
        }

        register_taxonomy_for_object_type('publisher', 'collection');

        // ======================
        // Genre Taxonomy
        // ======================
        $genre_labels = [
            'name'              => 'Genres',
            'singular_name'     => 'Genre',
            'menu_name'         => 'Genres',
            'search_items'      => 'Search Genres',
            'popular_items'     => 'Popular Genres',
            'all_items'         => 'All Genres',
            'edit_item'         => 'Edit Genre',
            'update_item'       => 'Update Genre',
            'add_new_item'      => 'Add New Genre',
            'new_item_name'     => 'New Genre Name',
            'parent_item'       => 'Parent Genre',
            'parent_item_colon' => 'Parent Genre:',
            'not_found'         => 'No genres found.',
        ];

        if (!taxonomy_exists('comic_genre')) {

            register_taxonomy('comic_genre', 'collection', [
                'labels'            => $genre_labels,
                'hierarchical'      => true,
                'public'            => true,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_nav_menus' => false,
                'show_tagcloud'     => false,
                'show_in_rest'      => true,
                'query_var'         => true,
                'rewrite'           => ['slug' => 'genre'],
            ]);
            
        }

        register_taxonomy_for_object_type('comic_genre', 'collection');


        // ======================
        // Comic Series Taxonomy
        // ======================
        $series_labels = [
            'name'          => 'Comic Series',
            'singular_name' => 'Comic Series',
            'menu_name'     => 'Comic Series',
            'search_items'  => 'Search Comic Series',
            'all_items'     => 'All Comic Series',
            'edit_item'     => 'Edit Comic Series',
            'update_item'   => 'Update Comic Series',
            'add_new_item'  => 'Add New Comic Series',
            'new_item_name' => 'New Comic Series Name',
            'not_found'     => 'No comic series found.',
        ];

        if (!taxonomy_exists('comic_series')) {
            register_taxonomy('comic_series', ['collection'], [
                'labels'            => $series_labels,
                'hierarchical'      => false,
                'public'            => true,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_nav_menus' => false,
                'show_tagcloud'     => false,
                'show_in_rest'      => true,
                'query_var'         => true,
                'rewrite'           => [
                    'slug'       => 'comic-series',
                    'with_front' => false,
                ],
            ]);
        }
        register_taxonomy_for_object_type(
            'comic_series',
            'collection'
        );
}
add_action('init', 'register_comic_taxonomies');


function normalize_comic_title(string $title): string {

    // Remove issue numbers like #1, #001, etc.
    $title = preg_replace('/^\s*#?\d+\s*[-–—]\s*/u', '', $title);  

    // Remove volume indicators (Vol. 1, Volume 2, v3)
    $title = preg_replace('/\b(vol(ume)?|v)\.?\s*\d+\b/i', '', $title);

    // Remove years in parentheses (1999), (2021-)
    $title = preg_replace('/\(\s*\d{4}.*?\)/', '', $title);

    // Remove extra separators
    $title = preg_replace('/[-–:]\s*$/', '', $title);

    // Normalize whitespace
    $title = trim(preg_replace('/\s+/', ' ', $title));     

    return $title;
}

function tcs_ensure_collection_term(
    string $term_name,
    string $taxonomy
): int {
    $term_name = trim(sanitize_text_field($term_name));

    if (
        $term_name === '' ||
        !taxonomy_exists($taxonomy)
    ) {
        return 0;
    }

    $existing = term_exists($term_name, $taxonomy);

    if ($existing) {
        return (int) (
            is_array($existing)
                ? $existing['term_id']
                : $existing
        );
    }

    $created = wp_insert_term(
        $term_name,
        $taxonomy
    );

    if (is_wp_error($created)) {
        return 0;
    }

    return (int) $created['term_id'];
}

/**
 * Fetch basic Comic Vine issue data directly, without calling Metron /issue/?cv_id.
 * This avoids extra Metron API calls when adding one issue to collection.
 */
function tcs_get_comicvine_issue_basic( int $cv_id ): array {

    if ( $cv_id <= 0 ) {
        return [];
    }

    $cache_key = "tcs:cv_issue_basic:v2:{$cv_id}";
    $cached = get_transient( $cache_key );

    if ( $cached !== false && is_array( $cached ) ) {

        // Re-populate per-series cv_id cache from the cached page data.
        foreach ( $cached['items'] ?? [] as $item ) {
    
            if (
                ! empty( $item['series_id'] ) &&
                ! empty( $item['cv_id'] )
            ) {
                set_transient(
                    "metron:series_cvid:{$item['series_id']}",
                    (int) $item['cv_id'],
                    YEAR_IN_SECONDS
                );
            }
        }
    
        return $cached;
    }

    $cv_key = get_option( 'comic_vine_api_key', '' );

    if ( empty( $cv_key ) ) {
        return [];
    }

    $url = add_query_arg(
        [
            'api_key'    => $cv_key,
            'format'     => 'json',
            'field_list' => implode(',', [
                'id',
                'name',
                'issue_number',
                'volume',
                'cover_date',
                'description',
                'deck',
                'image',
                'person_credits',
                'concept_credits',
                'character_credits',
            ]),
        ],
        "https://comicvine.gamespot.com/api/issue/4000-{$cv_id}/"
    );

    $response = wp_remote_get(
        $url,
        [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'ComicBookFetcher/1.1 (+' . get_site_url() . ')',
            ],
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [];
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $body['results'] ) || ! is_array( $body['results'] ) ) {
        return [];
    }

    $result = $body['results'];
    $result['cv_id'] = $cv_id;

    set_transient( $cache_key, $result, 30 * DAY_IN_SECONDS );

    return $result;
}

/**
 * Get Comic Vine concepts for a volume.
 *
 * Used only as a fallback when:
 * 1. Metron has no series genres, and
 * 2. the Comic Vine issue has no concept_credits.
 */
function tcs_get_comicvine_volume_concepts( int $cv_volume_id ): array {

    if ( $cv_volume_id <= 0 ) {
        return [];
    }

    $cache_key = "tcs:cv_volume_concepts:v1:{$cv_volume_id}";
    $cached    = get_transient( $cache_key );

    if ( $cached !== false && is_array( $cached ) ) {
        return $cached;
    }

    $cv_key = get_option( 'comic_vine_api_key', '' );

    if ( empty( $cv_key ) ) {
        return [];
    }

    $url = add_query_arg(
        [
            'api_key'    => $cv_key,
            'format'     => 'json',
            'field_list' => 'id,name,concepts',
        ],
        "https://comicvine.gamespot.com/api/volume/4050-{$cv_volume_id}/"
    );

    $response = wp_remote_get(
        $url,
        [
            'timeout' => 30,
            'headers' => [
                'User-Agent' =>
                    'ComicBookFetcher/1.1 (+' . get_site_url() . ')',
            ],
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [];
    }

    $body = json_decode(
        wp_remote_retrieve_body( $response ),
        true
    );

    if (
        ! is_array( $body ) ||
        ! isset( $body['results'] ) ||
        ! is_array( $body['results'] )
    ) {
        return [];
    }

    $concepts = tcs_catalog_names(
        $body['results']['concepts'] ?? []
    );

    /*
     * Cache successful results for 30 days.
     * Cache a confirmed empty result only for one day.
     */
    set_transient(
        $cache_key,
        $concepts,
        ! empty( $concepts )
            ? 30 * DAY_IN_SECONDS
            : DAY_IN_SECONDS
    );

    return $concepts;
}

/**
 * Format creator/person credits into the text string your collection meta expects.
 */
function tcs_format_creator_credits( array $creators ): string {

    if ( empty( $creators ) ) {
        return '';
    }

    $creator_infos = [];

    foreach ( $creators as $person ) {
        if ( ! is_array( $person ) ) {
            continue;
        }

        $name = $person['name'] ?? $person['creator'] ?? '';

        if ( empty( $name ) ) {
            continue;
        }

        $role = $person['role'] ?? $person['roles'] ?? '';

        if ( is_array( $role ) ) {
            if ( isset( $role[0] ) && is_array( $role[0] ) ) {
                $role = implode( ', ', array_filter( array_column( $role, 'name' ) ) );
            } else {
                $role = implode( ', ', array_filter( $role ) );
            }
        }

        $role = $role ?: 'N/A';

        $creator_infos[] = "{$name} – {$role}";
    }

    return implode( "\n", $creator_infos );
}


/**
 * Pull a usable image URL from Comic Vine image array.
 */
function tcs_get_cv_image_url( array $cv_issue ): string {

    if ( empty( $cv_issue['image'] ) || ! is_array( $cv_issue['image'] ) ) {
        return '';
    }

    return $cv_issue['image']['small_url']
        ?? $cv_issue['image']['medium_url']
        ?? $cv_issue['image']['super_url']
        ?? $cv_issue['image']['original_url']
        ?? '';
}

/**
 * AJAX: Add Comic to Collection
 */
add_action( 'wp_ajax_add_comic_to_collection', 'handle_add_comic_to_collection' );

function handle_add_comic_to_collection() {
    if (
        !check_ajax_referer(
            'comicbooks_fetchers_data',
            'security',
            false
        )
    ) {
        wp_send_json_error('Invalid nonce.', 403);
    }

    $user_id = get_current_user_id();

    if (!$user_id) {
        wp_send_json_error('Not logged in.', 401);
    }

    $data = wp_unslash($_POST['data'] ?? []);

    if (!is_array($data)) {
        wp_send_json_error('Invalid request data.', 400);
    }

    $issue_id = absint($data['issueId'] ?? 0);
    $title_id = absint($data['titleId'] ?? 0);

    if (!$issue_id || !$title_id) {
        wp_send_json_error(
            'Missing required issue_id or title_id.',
            400
        );
    }

    /*
     * Load canonical server-side metadata. Do not depend on
     * incomplete data-* attributes from the listing button.
     */
    $catalog = tcs_collection_catalog_data(
        $title_id,
        $issue_id
    );

    if (is_wp_error($catalog)) {
        wp_send_json_error([
            'code'    => $catalog->get_error_code(),
            'message' => $catalog->get_error_message(),
        ], 503);
    }

    $series_name = trim(
        (string) ($catalog['title'] ?? '')
    );
    
    $issue_number = trim(
        (string) ($catalog['meta']['issue_number'] ?? '')
    );
    
    $issue_post_title = $series_name;
    
    if ($issue_number !== '') {
        $issue_post_title .= ' #' . $issue_number;
    }
    
    $issue_post_slug = sanitize_title($issue_post_title);

    $existing = get_posts([
        'post_type'      => 'collection',
        'post_status'    => ['publish', 'draft'],
        'author'         => $user_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => 'issue_id',
                'value'   => $issue_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ],
        ],
    ]);

    $created = empty($existing);

    if ($created) {
        /*
         * Start as a draft so an incomplete post is not made
         * public if metadata storage fails.
         */
        $post_id = wp_insert_post([
            'post_type'   => 'collection',
            'post_title'  => $issue_post_title,
            'post_name'   => $issue_post_slug,
            'post_status' => 'draft',
            'post_author' => $user_id,
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            wp_send_json_error(
                'Post creation failed.',
                500
            );
        }
    } else {
        $post_id = (int) $existing[0];
    }

    $stored = tcs_store_collection_catalog(
        $post_id,
        $catalog
    );

    if (is_wp_error($stored)) {
        if ($created) {
            wp_delete_post($post_id, true);
        }

        wp_send_json_error([
            'code'    => $stored->get_error_code(),
            'message' => $stored->get_error_message(),
        ], 500);
    }

    if ($created) {
        tcs_update_collection_value(
            $post_id,
            'qty',
            1
        );

        tcs_update_collection_value(
            $post_id,
            'price',
            '0.00'
        );

        tcs_update_collection_value(
            $post_id,
            'condition',
            '9.4 (NEAR MINT)'
        );

        $published = wp_update_post([
            'ID'          => $post_id,
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($published) || !$published) {
            wp_delete_post($post_id, true);

            wp_send_json_error(
                'The collection entry could not be published.',
                500
            );
        }
    }

    wp_send_json_success([
        'post_id' => $post_id,
        'message' => $created
            ? 'Comic added to your collection.'
            : 'Collection metadata refreshed.',
        'warnings' => $catalog['warnings'] ?? [],
    ]);
}

/**
 * AJAX: Remove Comic
 */
add_action('wp_ajax_remove_comic_from_collection', function () {

    check_ajax_referer('comicbooks_fetchers_data', 'security');

    $user_id = get_current_user_id();
    $post_id = isset($_POST['post_id'])
    ? absint(wp_unslash($_POST['post_id']))
    : 0;

    if (!$post_id) {
        wp_send_json_error('Invalid post.');
    }

    $post = get_post($post_id);

    if (!$post || $post->post_author != $user_id) {
        wp_send_json_error('Unauthorized.');
    }

    wp_delete_post($post_id, true);

    wp_send_json_success();
});

/**
 * Restrict admin query to current user's collection
 */
add_action('pre_get_posts', 'restrict_collections_to_owner');

function restrict_collections_to_owner($query) {

    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    if ($query->get('post_type') === 'collection') {
        $query->set('author', get_current_user_id());
    }
}

add_action('wp_ajax_check_collection_status_batch', function () {
    check_ajax_referer('comicbooks_fetchers_data', 'security');

    if (!is_user_logged_in()) {
        wp_send_json_error('Not logged in.');
    }

    $request = wp_unslash($_POST);
    $issue_ids = array_values(array_unique(array_filter(
        array_map('absint', (array) ($request['issue_ids'] ?? []))
    )));

    if (!$issue_ids) {
        wp_send_json_success([]);
    }

    // Includes membership and the WordPress collection post ID.
    wp_send_json_success(
        ComicRenderer::get_collection_status($issue_ids)
    );
});

add_action('wp_ajax_tcs_collection_button_data', function () {
    if (
        !is_user_logged_in() ||
        !check_ajax_referer(
            'comicbooks_fetchers_data',
            'security',
            false
        )
    ) {
        wp_send_json_error(
            ['message' => 'Please reload the page and sign in.'],
            403
        );
    }

    $issue_id = isset($_POST['issue_id'])
        && is_scalar($_POST['issue_id'])
        ? absint(wp_unslash($_POST['issue_id']))
        : 0;

    $title_id = isset($_POST['title_id'])
        && is_scalar($_POST['title_id'])
        ? absint(wp_unslash($_POST['title_id']))
        : 0;

    if (!$issue_id || !$title_id) {
        wp_send_json_error(
            ['message' => 'Missing issue or series ID.'],
            400
        );
    }

    if (!function_exists('tcs_collection_catalog_data')) {
        wp_send_json_error(
            [
                'message' =>
                    'The collection catalog helper must be installed first.',
            ],
            500
        );
    }

    $catalog = tcs_collection_catalog_data($title_id, $issue_id);

    if (is_wp_error($catalog)) {
        wp_send_json_error(
            [
                'code'     => $catalog->get_error_code(),
                'message'  => $catalog->get_error_message(),
                'issue_id' => $issue_id,
                'title_id' => $title_id,
            ],
            503
        );
    }

    $meta = $catalog['meta'];

    $attributes = [
        'issue-id'     => (string) $meta['issue_id'],
        'title-id'     => (string) $meta['title_id'],
        'cv-issue-id'  => $meta['cv_issue_id']
            ? (string) $meta['cv_issue_id']
            : '',
        'title'        => $catalog['title'],
        'description'  => wp_strip_all_tags($catalog['description']),
        'creators'     => $meta['creators'],
        'date'         => $meta['date_published'],
        'genres'       => $meta['genres'],
        'concepts'     => $meta['concepts'],
        'characters'   => $meta['characters'],
        'publisher'    => $catalog['publisher'],
        'volume'       => $meta['volume'],
        'issue-number' => $meta['issue_number'],
        'image-url'    => $meta['cover_image_url'],
    ];

    $warnings = $catalog['warnings'] ?? [];

    $attributes['catalog-state'] = $warnings ? 'partial' : 'ready';
    $attributes['catalog-warning'] = implode(' ', $warnings);

    wp_send_json_success([
        'attributes' => array_map('strval', $attributes),
    ]);
});