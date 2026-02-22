<?php

/* ==================================================================
 *  COLLECTION: ADD / REMOVE
 * ================================================================== */
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
    $issue_id     = intval($data['issueId'] ?? 0);
    $title_id     = intval($data['titleId'] ?? 0);
    $title        = sanitize_text_field($data['title'] ?? '');
    $publisher    = sanitize_text_field($data['publisher'] ?? '');
    $volume       = intval($data['volume'] ?? 0);
    $issue_number = sanitize_text_field($data['issueNumber'] ?? '');
    $image_url    = esc_url_raw($data['imageUrl'] ?? '');
    $issue_date   = sanitize_text_field($data['date'] ?? '');
    $description  = wp_kses_post($data['description'] ?? '');
    $creators     = sanitize_text_field($data['creators'] ?? '');
    $genres       = sanitize_text_field($data['genres'] ?? '');
    $g_origin     = sanitize_text_field($data['genre-origin'] ?? '');

    if (!$issue_id || !$title_id || !$title) {
        wp_send_json_error('Missing required data.');
    }

    $post_id = wp_insert_post([
        'post_type'    => 'post',
        'post_title'   => $title,
        'post_content' => $description,
        'post_status'  => 'publish',
        'post_author'  => $user_id,
    ]);

    if (is_wp_error($post_id)) {
        wp_send_json_error('Failed to create post.');
    }

    // Ensure Collection category
    $collection_term = term_exists('Collection', 'category');
    if (!$collection_term) {
        $collection_term = wp_insert_term('Collection', 'category');
    }
    $collection_id = is_array($collection_term) ? $collection_term['term_id'] : $collection_term;

    wp_set_post_terms($post_id, [$collection_id], 'category');

    // Publisher sub-category
    $publisher_term = term_exists($publisher, 'category', $collection_id);
    if (!$publisher_term) {
        $publisher_term = wp_insert_term($publisher, 'category', ['parent' => $collection_id]);
    }
    $publisher_id = is_array($publisher_term) ? $publisher_term['term_id'] : $publisher_term;
    wp_set_post_terms($post_id, [$publisher_id], 'category', true);

    // Meta
    update_post_meta($post_id, 'issue_id', $issue_id);
    update_post_meta($post_id, 'title_id', $title_id);
    update_post_meta($post_id, 'issue_number', $issue_number);
    update_post_meta($post_id, 'date_published', $issue_date);
    update_post_meta($post_id, 'volume', $volume);
    update_post_meta($post_id, 'image_url', $image_url);
    update_post_meta($post_id, 'creators', $creators);
    update_post_meta($post_id, 'genres', $genres);
    update_post_meta($post_id, 'genre-origin', $g_origin);
    update_post_meta($post_id, 'qty', 1);

    // === IMAGE SIDELOAD ===
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $image_id = false;
    if ($image_url) {
        $image_id = sideload_image($image_url, $post_id, $title);
    }

    if (!$image_id) {
        $placeholder_id = get_option('publisher_placeholder_image_id');
        if (!$placeholder_id && defined('PUBLISHER_PLACEHOLDER_IMAGE_URL')) {
            $placeholder_id = sideload_image(PUBLISHER_PLACEHOLDER_IMAGE_URL, $post_id, 'Placeholder');
            if ($placeholder_id) {
                update_option('publisher_placeholder_image_id', $placeholder_id);
            }
        }
        $image_id = $placeholder_id;
    }

    if ($image_id) {
        set_post_thumbnail($post_id, $image_id);
    }

    wp_send_json_success(['post_id' => $post_id, 'image_id' => $image_id]);
}

// Sideload helper
function sideload_image($url, $post_id, $desc = '') {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

    $tmp = download_url($url, 15);
    if (is_wp_error($tmp)) return false;

    $file_array = [
        'name'     => basename(parse_url($url, PHP_URL_PATH)),
        'tmp_name' => $tmp,
    ];

    $id = media_handle_sideload($file_array, $post_id, $desc);
    if (is_wp_error($id)) {
        @unlink($tmp);
        return false;
    }

    return $id;
}

add_action('wp_ajax_remove_comic_from_collection', 'handle_remove_comic_from_collection');
function handle_remove_comic_from_collection() {
    check_ajax_referer('comicbooks_fetchers_data', 'security');
    $user_id = get_current_user_id();
    if (!$user_id) wp_send_json_error('Not logged in.');

    $data = wp_unslash($_POST['data'] ?? []);
    $post_id = intval($data['postId'] ?? 0);
    if (!$post_id) wp_send_json_error('Invalid post.');

    $post = get_post($post_id);
    if (!$post || $post->post_author != $user_id) {
        wp_send_json_error('Unauthorized.');
    }

    wp_delete_post($post_id, true);
    wp_send_json_success();
}