<?php
/**
 * Register Comic Taxonomies
 */
add_action('init', function () {

    register_taxonomy('publisher', 'post', [
        'label' => 'Publishers',
        'public' => false,
        'show_ui' => true,
        'hierarchical' => false,
        'show_admin_column' => true,
    ]);

    register_taxonomy('comic_genre', 'post', [
        'label' => 'Genres',
        'public' => false,
        'show_ui' => true,
        'hierarchical' => false,
        'show_admin_column' => true,
    ]);

    register_taxonomy('character', 'post', [
        'label' => 'Characters',
        'public' => false,
        'show_ui' => false,
        'hierarchical' => false,
    ]);

    register_taxonomy('team', 'post', [
        'label' => 'Teams',
        'public' => false,
        'show_ui' => false,
        'hierarchical' => false,
    ]);
});

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
    $volume_id    = intval($data['titleId'] ?? 0);
    $issue_number = sanitize_text_field($data['issueNumber'] ?? '');
    $publisher    = sanitize_text_field($data['publisher'] ?? '');
    $image_url    = esc_url_raw($data['imageUrl'] ?? '');
    $cover_date   = sanitize_text_field($data['date'] ?? '');
    $description  = wp_kses_post($data['description'] ?? '');
    $genres_raw   = sanitize_text_field($data['genres'] ?? '');

    if (!$title || !$issue_id) {
        wp_send_json_error('Missing required data.');
    }

    $post_id = wp_insert_post([
        'post_type'    => 'post',
        'post_title'   => $title,
        'post_content' => $description,
        'post_status'  => 'private',
        'post_author'  => $user_id,
    ]);

    if (is_wp_error($post_id)) {
        wp_send_json_error('Post creation failed.');
    }

    /**
     * Ensure Collection category
     */
    $collection = term_exists('Collection', 'category');
    if (!$collection) {
        $collection = wp_insert_term('Collection', 'category');
    }

    $collection_id = is_array($collection)
        ? $collection['term_id']
        : $collection;

    wp_set_post_terms($post_id, [$collection_id], 'category');

    /**
     * Structured Meta
     */
    update_post_meta($post_id, 'issue_id', $issue_id);
    update_post_meta($post_id, 'volume_id', $volume_id);
    update_post_meta($post_id, 'issue_number', $issue_number);
    update_post_meta($post_id, 'cover_date', $cover_date);
    update_post_meta($post_id, 'cover_image_url', $image_url);

    /**
     * Year + Era
     */
    if (!empty($cover_date)) {
        $year = intval(date('Y', strtotime($cover_date)));
        if ($year > 0) {
            update_post_meta($post_id, 'year', $year);
            update_post_meta($post_id, 'era', get_comic_era($year));
        }
    }

    /**
     * Publisher Taxonomy
     */
    if (!empty($publisher)) {
        wp_set_object_terms($post_id, $publisher, 'publisher');
    }

    /**
     * Genre Taxonomy
     */
    if (!empty($genres_raw)) {
        $genres_array = array_map('trim', explode(',', $genres_raw));
        wp_set_object_terms($post_id, $genres_array, 'comic_genre');
    }

    wp_send_json_success([
        'post_id' => $post_id
    ]);
}



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