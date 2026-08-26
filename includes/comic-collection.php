<?php
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

if (!taxonomy_exists('publisher')) {
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

        register_taxonomy('publisher', 'collection', [
            'labels'            => $publisher_labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud'     => false,
            'show_in_rest'      => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => 'publisher'],
        ]);
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
        register_taxonomy_for_object_type('comic_genre', 'collection');
    }
    add_action('init', 'register_comic_taxonomies');
}

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

function ensure_publisher_terms(string $publisher_name) {

    $publisher = term_exists($publisher_name, 'publisher');

    if (!$publisher) {
        $publisher = wp_insert_term($publisher_name, 'publisher');
    }

    if (is_wp_error($publisher)) {
        return false;
    }

    $publisher_id = is_array($publisher) ? $publisher['term_id'] : $publisher;

    // Ensure "All Publisher"
    $all_slug = 'all-' . sanitize_title($publisher_name);
    $all_term = term_exists($all_slug, 'publisher');

    if (!$all_term) {
        $all_term = wp_insert_term('All ' . $publisher_name, 'publisher', [
            'slug'   => $all_slug,
            'parent' => $publisher_id,
        ]);
    }

    $all_id = is_array($all_term) ? $all_term['term_id'] : $all_term;

    return [
        'publisher_id' => $publisher_id,
        'all_id'       => $all_id,
    ];
}

function ensure_title_term(string $normalized_title, int $publisher_id) {

    $slug = sanitize_title($normalized_title);

    // Check if term exists under this parent
    $existing = get_terms([
        'taxonomy'   => 'publisher',
        'hide_empty' => false,
        'slug'       => $slug,
        'parent'     => $publisher_id,
    ]);

    if (!empty($existing)) {
        foreach ($existing as $term) {
            if ((int)$term->parent === $publisher_id) {
                return $term->term_id;
            }
        }
    }

    $term = wp_insert_term($normalized_title, 'publisher', [
        'slug'   => $slug,
        'parent' => $publisher_id,
    ]);

    if (is_wp_error($term)) {
        return false;
    }

    return $term['term_id'];
}

/**
 * Fetch basic Comic Vine issue data directly, without calling Metron /issue/?cv_id.
 * This avoids extra Metron API calls when adding one issue to collection.
 */
function tcs_get_comicvine_issue_basic( int $cv_id ): array {

    if ( $cv_id <= 0 ) {
        return [];
    }

    $cache_key = "tcs:cv_issue_basic:{$cv_id}";
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
            'field_list' => 'id,name,issue_number,cover_date,description,deck,image,person_credits,concept_credits',
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

    if ( ! check_ajax_referer( 'comicbooks_fetchers_data', 'security', false ) ) {
        wp_send_json_error( 'Invalid nonce.' );
    }

    $user_id = get_current_user_id();

    if ( ! $user_id ) {
        wp_send_json_error( 'Not logged in.' );
    }

    $data = wp_unslash( $_POST['data'] ?? [] );

    if ( ! is_array( $data ) ) {
        wp_send_json_error( 'Invalid data.' );
    }

    /*
     * These names match jQuery btn.data():
     * data-issue-id      => issueId
     * data-title-id      => titleId
     * data-issue-number  => issueNumber
     * data-image-url     => imageUrl
     * data-cv-issue-id   => cvIssueId
     */
    $issue_id     = intval( $data['issueId'] ?? 0 );
    $title_id     = intval( $data['titleId'] ?? 0 );
    $cv_issue_id  = intval( $data['cvIssueId'] ?? 0 );

    $fallback_title        = sanitize_text_field( $data['title'] ?? '' );
    $fallback_issue_number = sanitize_text_field( $data['issueNumber'] ?? '' );
    $fallback_volume       = intval( $data['volume'] ?? 1 );
    $fallback_publisher    = sanitize_text_field( $data['publisher'] ?? '' );
    $fallback_image_url    = esc_url_raw( $data['imageUrl'] ?? '' );
    $fallback_cover_date   = sanitize_text_field( $data['date'] ?? '' );

    if ( ! $issue_id || ! $title_id ) {
        wp_send_json_error( 'Missing required issue_id or title_id.' );
    }

    if ( ! class_exists( 'ComicRenderer' ) ) {
        wp_send_json_error( 'ComicRenderer class not found.' );
    }

    $comic_renderer = new ComicRenderer();

    /*
     * Fetch full Metron issue data ONLY after the user clicks Add.
     * This is one issue, not every issue on the page.
     */
    $issue = $comic_renderer->get_single_issue( $title_id, $issue_id );

    if ( empty( $issue ) || ! is_array( $issue ) ) {
        wp_send_json_error( 'Could not load issue data.' );
    }

    $series = $issue['series'] ?? [];

    /*
     * Prefer Metron's cv_id if present.
     * Fall back to button value if it exists.
     */
    $cv_issue_id = intval( $issue['cv_id'] ?? $cv_issue_id );

    /*
     * Start with Metron data.
     */
    $issue_number = sanitize_text_field(
        $issue['number']
        ?? $fallback_issue_number
        ?? ''
    );

    $series_name = sanitize_text_field(
        $series['name']
        ?? $fallback_title
        ?? 'Unknown Series'
    );

    $title = $issue_number
        ? "#{$issue_number} — {$series_name}"
        : $series_name;

    $volume = intval(
        $series['volume']
        ?? $fallback_volume
        ?? 1
    );

    $publisher = sanitize_text_field(
        $series['publisher']['name']
        ?? $fallback_publisher
        ?? ''
    );

    $cover_date = sanitize_text_field(
        $issue['cover_date']
        ?? $fallback_cover_date
        ?? ''
    );

    $description_raw =
        $issue['description']
        ?? $issue['desc']
        ?? '';

    $creators_array = [];

    if ( ! empty( $issue['credits'] ) && is_array( $issue['credits'] ) ) {
        $creators_array = $issue['credits'];
    }

    /*
     * Only call Comic Vine if Metron does not already give us enough.
     * This does NOT call Metron /issue/?cv_id.
     */
    $needs_cv =
        $cv_issue_id > 0 &&
        (
            empty( $description_raw ) ||
            empty( $creators_array )
        );

    $cv_issue = [];

    if ( $needs_cv ) {
        $cv_issue = tcs_get_comicvine_issue_basic( $cv_issue_id );

        if ( empty( $description_raw ) ) {
            $description_raw =
                $cv_issue['description']
                ?? $cv_issue['deck']
                ?? '';
        }

        if ( empty( $creators_array ) && ! empty( $cv_issue['person_credits'] ) && is_array( $cv_issue['person_credits'] ) ) {
            $creators_array = $cv_issue['person_credits'];
        }
    }

    $description = $comic_renderer->clean_cv_description(
        $description_raw ?: 'No description available.'
    );

    $creators = tcs_format_creator_credits( $creators_array );

    /*
     * Genres: prefer Metron series genres.
     * Fall back to Comic Vine concepts only if we already fetched CV data.
     */
    $genres_array = [];

    if ( ! empty( $series['genres'] ) && is_array( $series['genres'] ) ) {
        $genres_array = array_filter( array_column( $series['genres'], 'name' ) );
    } elseif ( ! empty( $cv_issue['concept_credits'] ) && is_array( $cv_issue['concept_credits'] ) ) {
        $genres_array = array_filter( array_column( $cv_issue['concept_credits'], 'name' ) );
    } elseif ( ! empty( $data['genres'] ) ) {
        $genres_array = array_map( 'trim', explode( ',', sanitize_text_field( $data['genres'] ) ) );
    }

    $genres_raw = implode( ', ', array_filter( $genres_array ) );

    /*
     * Image: prefer button image if it is not empty.
     * Then Metron issue image.
     * Then Comic Vine image if we already fetched CV.
     */
    $image_url = $fallback_image_url;

    if ( empty( $image_url ) && ! empty( $issue['image'] ) ) {
        $image_url = esc_url_raw( $issue['image'] );
    }

    if ( empty( $image_url ) && ! empty( $cv_issue ) ) {
        $image_url = esc_url_raw( tcs_get_cv_image_url( $cv_issue ) );
    }

    if ( empty( $title ) || ! $issue_id ) {
        wp_send_json_error( 'Missing required data.' );
    }

    /*
     * Optional duplicate guard:
     * Prevent adding the same issue twice for the same user.
     */
    $existing = get_posts( [
        'post_type'      => 'collection',
        'author'         => $user_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => 'issue_id',
                'value'   => $issue_id,
                'compare' => '=',
            ],
        ],
    ] );

    if ( ! empty( $existing ) ) {
        wp_send_json_success( [
            'post_id' => (int) $existing[0],
            'message' => 'Comic is already in your collection.',
        ] );
    }

    $normalized_title = normalize_comic_title( $title );

    $post_id = wp_insert_post( [
        'post_type'    => 'collection',
        'post_title'   => $normalized_title,
        'post_content' => $description,
        'post_status'  => 'publish',
        'post_author'  => $user_id,
    ] );

    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( 'Post creation failed.' );
    }

    // Meta
    update_post_meta( $post_id, 'issue_id', $issue_id );
    update_post_meta( $post_id, 'cv_issue_id', $cv_issue_id );
    update_post_meta( $post_id, 'title_id', $title_id );
    update_post_meta( $post_id, 'issue_number', $issue_number );
    update_post_meta( $post_id, 'volume', $volume );
    update_post_meta( $post_id, 'date_published', $cover_date );
    update_post_meta( $post_id, 'creators', $creators );
    update_post_meta( $post_id, 'cover_image_url', $image_url );

    // Year + Era
    if ( ! empty( $cover_date ) && strtotime( $cover_date ) ) {
        $year = intval( date( 'Y', strtotime( $cover_date ) ) );

        if ( $year > 0 ) {
            update_post_meta( $post_id, 'year', $year );
            update_post_meta( $post_id, 'era', get_comic_era( $year ) );
        }
    }

    // Taxonomies
    if ( ! empty( $publisher ) ) {

        $terms = ensure_publisher_terms( $publisher );

        if ( $terms ) {

            $publisher_id = $terms['publisher_id'];
            $all_id       = $terms['all_id'];

            $title_term_id = ensure_title_term( $normalized_title, $publisher_id );

            $assign_terms = [ $publisher_id, $all_id ];

            if ( $title_term_id ) {
                $assign_terms[] = $title_term_id;
            }

            wp_set_object_terms( $post_id, array_map( 'intval', $assign_terms ), 'publisher', false );
            wp_update_term_count_now( $assign_terms, 'publisher' );
        }
    }

    if ( ! empty( $genres_raw ) ) {
        $genres_array = array_map( 'trim', explode( ',', $genres_raw ) );
        $genres_array = array_filter( $genres_array );

        if ( ! empty( $genres_array ) ) {
            wp_set_object_terms( $post_id, $genres_array, 'comic_genre' );
            wp_update_term_count_now( $genres_array, 'comic_genre' );
        }
    }

    // Defaults
    update_post_meta( $post_id, 'qty', 1 );
    update_post_meta( $post_id, 'price', '0.00' );
    update_post_meta( $post_id, 'condition', '9.4 (NEAR MINT)' );

    wp_send_json_success( [
        'post_id' => $post_id,
        'message' => 'Comic added to your private collection.',
    ] );
}

/**
 * AJAX: Remove Comic
 */
add_action('wp_ajax_remove_comic_from_collection', function () {

    check_ajax_referer('comicbooks_fetchers_data', 'security');

    $user_id = get_current_user_id();
    $post_id = intval($_POST['post_id'] ?? 0);

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

/**
 * Automatically create an "All {Publisher}" child term
 * whenever a top-level publisher is created.
 *
 * Example:
 * - Marvel
 *   - All Marvel
 * - DC
 *   - All DC
 */
add_action('created_publisher', function ($term_id, $tt_id) {

    $term = get_term($term_id, 'publisher');
    if (!$term || is_wp_error($term)) {
        return;
    }

    // Only create All term for top-level publishers
    if (!empty($term->parent)) {
        return;
    }

    $all_name = 'All ' . $term->name;
    $all_slug = 'all-' . sanitize_title($term->name);

    // Avoid duplicates
    $existing = term_exists($all_slug, 'publisher');
    if ($existing) {
        return;
    }

    wp_insert_term($all_name, 'publisher', [
        'slug'   => $all_slug,
        'parent' => $term_id,
    ]);
}, 10, 2);

/**
 * Only show top-level publishers in checklist
 */
add_filter('wp_terms_checklist_args', function ($args, $post_id) {

    if ($args['taxonomy'] !== 'publisher') return $args;

    $args['walker'] = new class extends Walker_Category_Checklist {
        function walk($elements, $max_depth, ...$args) {

            $elements = array_filter($elements, function ($term) {
                // Only top-level + hide "all-*"
                return $term->parent == 0 && strpos($term->slug, 'all-') !== 0;
            });

            return parent::walk($elements, $max_depth, ...$args);
        }
    };

    return $args;

}, 10, 2);

add_action('wp_ajax_check_collection_status_batch', function() {
    check_ajax_referer('comicbooks_fetchers_data', 'security');
    if (!is_user_logged_in()) wp_send_json_error('Not logged in.');

    $user_id = get_current_user_id();
    $issue_ids = array_map('intval', (array)($_POST['issue_ids'] ?? []));

    if (empty($issue_ids)) wp_send_json_success([]);

    $in_collection = [];

    foreach ($issue_ids as $issue_id) {
        $posts = get_posts([
            'post_type'   => 'collection',
            'meta_query'  => [
                [
                    'key'     => 'issue_id',
                    'value'   => $issue_id,
                    'compare' => '=',
                ],
            ],
            'author'      => $user_id,
            'fields'      => 'ids',
            'posts_per_page' => 1
        ]);

        if (!empty($posts)) {
            $in_collection[] = $issue_id;
        }
    }

    wp_send_json_success($in_collection);
});