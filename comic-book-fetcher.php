<?php
/**
 * Plugin Name: Comic Books Fetcher & Manager
 * Description: Manage Comic Collection, edit API settings, and enqueues scripts for displaying comic book data from the Metron & Comic Vine API.
 * Version: 1.1.0
 * Author: Peter Giammarco
 * Author URI: https://www.pgiammarco.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: comic-books-fetcher
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// === CONSTANTS ===
define('COMICBOOKS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('COMICBOOKS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('COMICBOOKS_API_BASE', 'https://metron.cloud/api/');
define('PUBLISHER_PLACEHOLDER_IMAGE_URL', COMICBOOKS_PLUGIN_URL . 'images/placeholder.png');

// === INCLUDE CLASSES ===
require_once COMICBOOKS_PLUGIN_DIR . 'class-metron-client.php';
require_once COMICBOOKS_PLUGIN_DIR . 'class-comic-data-service.php';
require_once COMICBOOKS_PLUGIN_DIR . 'class-comic-renderer.php';
require_once COMICBOOKS_PLUGIN_DIR . 'class-comicbooks.php';

// === INITIALIZE CORE ===
add_action('init', function () {
    // Start AJAX handler (Comicbooks class)
    new Comicbooks();

    // Optional: Initialize renderer globally if needed
    // new ComicRenderer();
});

/* ==================================================================
 *  ENQUEUE SCRIPTS & STYLES
 * ================================================================== */
function comicbooks_enqueue_scripts() {
    global $post;

    $load_comic_assets = false;

    // Load on specific pages
    if (is_page(['comic-books', 'issues', 'issue'])) {
        $load_comic_assets = true;
    }

    // Load on collection posts
    if (is_singular('post') && $post) {
        $collection_cat = get_category_by_slug('collection');
        if ($collection_cat && (has_category($collection_cat->term_id, $post) || post_is_in_descendant_category($collection_cat->term_id, $post))) {
            $load_comic_assets = true;
        }
    }

    // === COMIC ASSETS ===
    if ($load_comic_assets) {
        wp_enqueue_style(
            'comicbook-style',
            COMICBOOKS_PLUGIN_URL . 'css/comic-book.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'comicbook-script',
            COMICBOOKS_PLUGIN_URL . 'js/comic-book.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('comicbook-script', 'comicbooks_fetchers_data', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('comicbooks_fetchers_data'),
            'placeholder' => PUBLISHER_PLACEHOLDER_IMAGE_URL,
            'per_page'    => 10,
        ]);
    }

    // === WISHLIST ASSETS (always load) ===
    wp_enqueue_script('toastify-js', 'https://cdn.jsdelivr.net/npm/toastify-js', [], null, true);
    wp_enqueue_style('toastify-css', 'https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css');

    wp_enqueue_script(
        'wishlist-script',
        COMICBOOKS_PLUGIN_URL . 'js/wishlist.js',
        ['jquery'],
        '1.0.0',
        true
    );

    wp_enqueue_style(
        'wishlist-style',
        COMICBOOKS_PLUGIN_URL . 'css/wishlist.css',
        [],
        '1.0.0'
    );

    wp_localize_script('wishlist-script', 'wishlist_ajax_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wishlist_nonce')
    ]);
}
add_action('wp_enqueue_scripts', 'comicbooks_enqueue_scripts');

/* ==================================================================
 *  ADMIN SETTINGS PAGE
 * ================================================================== */
function comic_book_api_settings_page() {
    add_menu_page(
        'Comic books',
        'Comic books',
        'manage_options',
        'comicbooks-settings',
        'render_api_settings_page',
        'dashicons-book'
    );
}
add_action('admin_menu', 'comic_book_api_settings_page');

function render_api_settings_page() {
    // Save settings
    if (isset($_POST['submit']) && check_admin_referer('save_api_settings')) {
        update_option('metron_api_username', sanitize_text_field($_POST['metron_api_username']));
        update_option('metron_api_password', sanitize_text_field($_POST['metron_api_password']));
        update_option('comic_vine_api_key', sanitize_text_field($_POST['comic_vine_api_key']));

        // Clear all Metron transients
        global $wpdb;
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_metron_%'");
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_metron_%'");

        echo '<div class="updated"><p>Settings saved and all caches cleared.</p></div>';
    }

    // Clear series cache only
    if (isset($_POST['clear_series_cache']) && check_admin_referer('clear_series_cache')) {
        global $wpdb;
        $patterns = [
            '_transient_metron:series_full:%',
            '_transient_timeout_metron:series_full:%'  
        ];
        foreach ($patterns as $like) {
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name LIKE %s", $like));
        }
        echo '<div class="notice notice-success is-dismissible"><p>Series caches cleared!</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Comic Books API Settings</h1>

        <form method="post">
            <?php wp_nonce_field('save_api_settings'); ?>

            <h2>Metron API</h2>
            <table class="form-table">
                <tr>
                    <th><label for="metron_api_username">Username</label></th>
                    <td><input type="text" id="metron_api_username" name="metron_api_username" value="<?php echo esc_attr(get_option('metron_api_username')); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><label for="metron_api_password">Password</label></th>
                    <td><input type="password" id="metron_api_password" name="metron_api_password" value="<?php echo esc_attr(get_option('metron_api_password')); ?>" class="regular-text" /></td>
                </tr>
            </table>

            <h2>Comic Vine API</h2>
            <table class="form-table">
                <tr>
                    <th><label for="comic_vine_api_key">API Key</label></th>
                    <td><input type="text" id="comic_vine_api_key" name="comic_vine_api_key" value="<?php echo esc_attr(get_option('comic_vine_api_key')); ?>" class="regular-text" /></td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="submit" class="button button-primary" value="Save Settings" />
            </p>
        </form>

        <hr style="margin: 3rem 0;">

        <h2>Cache Management</h2>
        <form method="post" style="display:inline;">
            <?php wp_nonce_field('clear_series_cache'); ?>
            <p>
                <input type="submit" name="clear_series_cache" class="button button-secondary" value="Clear Series Cache"
                       onclick="return confirm('Are you sure? This will force re-download of all series lists.');" />
                <span style="margin-left:10px; color:#666; font-style:italic;">
                    Clears only <code>metron:series_full:*</code> and filtered series caches.
                </span>
            </p>
        </form>
    </div>
    <?php
}

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

/* ==================================================================
 *  WISHLIST FUNCTIONS
 * ================================================================== */
add_action('wp_ajax_check_wishlist_status_batch', 'check_wishlist_status_batch');
function check_wishlist_status_batch() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error();

    $user_id = get_current_user_id();
    $wishlist = get_user_meta($user_id, 'user_wishlist', true) ?: [];
    $item_ids = array_map('sanitize_text_field', (array)($_POST['item_ids'] ?? []));

    $in_wishlist = array_intersect(array_column($wishlist, 'item_id'), $item_ids);
    wp_send_json_success(array_values($in_wishlist));
}

add_action('wp_ajax_add_to_wishlist', 'add_to_wishlist_ajax');
function add_to_wishlist_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error('Login required.');

    $user_id = get_current_user_id();
    $wishlist = get_user_meta($user_id, 'user_wishlist', true) ?: [];

    $new = [
        'type'      => sanitize_text_field($_POST['type']),
        'item_id'   => sanitize_text_field($_POST['item_id']),
        'title'     => sanitize_text_field($_POST['title']),
        'item_url'  => esc_url_raw($_POST['item_url']),
        'image_url' => esc_url_raw($_POST['image_url']),
        'volume'    => sanitize_text_field($_POST['volume'] ?? ''),
        'ebay_id'   => sanitize_text_field($_POST['ebay_id'] ?? ''),
        'added_at'  => current_time('mysql'),
    ];

    foreach ($wishlist as $item) {
        if ($item['type'] === $new['type'] && $item['item_id'] === $new['item_id']) {
            wp_send_json_error('Already in wishlist.');
        }
    }

    $wishlist[] = $new;
    update_user_meta($user_id, 'user_wishlist', $wishlist);
    wp_send_json_success();
}

add_action('wp_ajax_remove_from_wishlist', 'remove_from_wishlist_ajax');
function remove_from_wishlist_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error();

    $user_id = get_current_user_id();
    $item_id = sanitize_text_field($_POST['item_id']);
    $wishlist = get_user_meta($user_id, 'user_wishlist', true) ?: [];

    $wishlist = array_filter($wishlist, fn($i) => $i['item_id'] !== $item_id);
    update_user_meta($user_id, 'user_wishlist', array_values($wishlist));

    wp_send_json_success();
}

add_action('wp_ajax_check_wishlist_status', 'check_wishlist_status_ajax');
function check_wishlist_status_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_success(['in_wishlist' => false]);

    $item_id = sanitize_text_field($_POST['item_id']);
    $wishlist = get_user_meta(get_current_user_id(), 'user_wishlist', true) ?: [];

    $in = in_array($item_id, array_column($wishlist, 'item_id'), true);
    wp_send_json_success(['in_wishlist' => $in]);
}

add_shortcode('user_wishlist', 'mwp_display_user_wishlist');
function mwp_display_user_wishlist() {
    if (!is_user_logged_in()) {
        return '<p class="has-white-color">Please log in to view your wishlist.</p>';
    }

    $wishlist = get_user_meta(get_current_user_id(), 'user_wishlist', true) ?: [];
    if (empty($wishlist)) {
        return '<p class="has-white-color">Your wishlist is empty.</p>';
    }

    ob_start(); ?>
    <ul class="user-wishlist" style="list-style:none;padding:0;">
        <?php foreach ($wishlist as $item): ?>
            <li style="margin-bottom:15px;display:flex;align-items:center;gap:10px;">
                <img src="<?php echo esc_url($item['image_url']); ?>" alt="" style="width:50px;height:50px;object-fit:cover;">
                <div style="flex:1;">
                    <a href="<?php echo esc_url($item['item_url']); ?>" target="_blank"><strong><?php echo esc_html($item['title']); ?></strong></a>
                    <?php if ($item['volume']): ?>
                        <small>Vol <?php echo esc_html($item['volume']); ?></small>
                    <?php elseif ($item['ebay_id']): ?>
                        <small><a href="https://thecollectiblespot.com/tools/?item_id=<?php echo urlencode($item['ebay_id']); ?>">eBay <?php echo esc_html($item['ebay_id']); ?></a></small>
                    <?php endif; ?>
                </div>
                <button class="remove-from-wishlist button" data-item-id="<?php echo esc_attr($item['item_id']); ?>">Remove</button>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
    return ob_get_clean();
}