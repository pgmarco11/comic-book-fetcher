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
}
add_action('init', 'register_comic_taxonomies');

/**
 * Only show top-level publishers in checklist
 */
add_filter('wp_terms_checklist_args', function ($args, $post_id) {

    if ($args['taxonomy'] !== 'publisher') {
        return $args;
    }

    $args['walker'] = new class extends Walker_Category_Checklist {
        function walk($elements, $max_depth, ...$args) {
            $elements = array_filter($elements, function ($term) {
                return $term->parent == 0;
            });
            return parent::walk($elements, $max_depth, ...$args);
        }
    };

    return $args;

}, 10, 2);

/**
 * AJAX: Add Comic to Collection
 */
add_action('wp_ajax_add_comic_to_collection', 'handle_add_comic_to_collection');

function handle_add_comic_to_collection() {

    if (!check_ajax_referer('comicbooks_fetchers_data', 'security', false)) {
        wp_send_json_error('Invalid nonce.');
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error('Not logged in.');
    }

    $data = wp_unslash($_POST['data'] ?? []);

    $title        = sanitize_text_field($data['title'] ?? '');
    $issue_id     = intval($data['issueId'] ?? 0);
    $title_id     = intval($data['titleId'] ?? 0);
    $issue_number = sanitize_text_field($data['issueNumber'] ?? '');
    $volume       = intval($data['volume'] ?? 1);
    $publisher    = sanitize_text_field($data['publisher'] ?? '');
    $image_url    = esc_url_raw($data['imageUrl'] ?? '');
    $cover_date   = sanitize_text_field($data['date'] ?? '');
    $description  = wp_kses_post($data['description'] ?? '');
    $genres_raw   = sanitize_text_field($data['genres'] ?? '');
    $creators     = sanitize_text_field($data['creators'] ?? '');

    if (!$title || !$issue_id) {
        wp_send_json_error('Missing required data.');
    }

    $post_id = wp_insert_post([
        'post_type'    => 'collection',
        'post_title'   => $title,
        'post_content' => $description,
        'post_status'  => 'private',
        'post_author'  => $user_id,
    ]);

    if (is_wp_error($post_id)) {
        wp_send_json_error('Post creation failed.');
    }

    // Meta
    update_post_meta($post_id, 'issue_id', $issue_id);
    update_post_meta($post_id, 'title_id', $title_id);
    update_post_meta($post_id, 'issue_number', $issue_number);
    update_post_meta($post_id, 'volume', $volume);
    update_post_meta($post_id, 'date_published', $cover_date);
    update_post_meta($post_id, 'creators', $creators);
    update_post_meta($post_id, 'cover_image_url', $image_url);

    // Year + Era
    if (!empty($cover_date)) {
        $year = intval(date('Y', strtotime($cover_date)));
        if ($year > 0) {
            update_post_meta($post_id, 'year', $year);
            update_post_meta($post_id, 'era', get_comic_era($year));
        }
    }

    // Taxonomies
    if (!empty($publisher)) {
        wp_set_object_terms($post_id, $publisher, 'publisher');
    }

    if (!empty($genres_raw)) {
        $genres_array = array_map('trim', explode(',', $genres_raw));
        wp_set_object_terms($post_id, $genres_array, 'comic_genre');
    }

    // Defaults
    update_post_meta($post_id, 'qty', 1);
    update_post_meta($post_id, 'price', '0.00');
    update_post_meta($post_id, 'condition', '9.4 (NEAR MINT)');

    wp_send_json_success([
        'post_id' => $post_id,
        'message' => 'Comic added to your private collection.',
    ]);
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
