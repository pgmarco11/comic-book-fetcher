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

// === REQUIRED CLASSES ===
require_once COMICBOOKS_PLUGIN_DIR . 'class-metron-client.php';
require_once COMICBOOKS_PLUGIN_DIR . 'class-comic-data-service.php';
require_once COMICBOOKS_PLUGIN_DIR . 'class-comic-renderer.php';
require_once COMICBOOKS_PLUGIN_DIR . 'class-comicbooks.php';

// === INCLUDE FUNCTIONS ===
include_once COMICBOOKS_PLUGIN_DIR . 'includes/wish-list.php';
include_once COMICBOOKS_PLUGIN_DIR . 'includes/comic-collection.php';

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
    if (is_page(['comic-catalog', 'issues', 'issue'])) {
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
    // Save general API settings
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

    // Warm per-publisher full pages (your existing top 10 publishers × 10 pages each)
    if (isset($_POST['warm_top_publishers']) && check_admin_referer('warm_top_publishers')) {
        $top_publishers = [1,2,3,4,5,6,7,8,9,10];
        $renderer = new ComicRenderer();
        foreach ($top_publishers as $publisher_id) {
            for ($page = 1; $page <= 10; $page++) {
                $renderer->warm_catalog_page($publisher_id, $page);
            }
        }
        echo '<div class="notice notice-success"><p>Top publisher caches (10 pages each) warmed!</p></div>';
    }

    // Warm the compact "top 10 publishers × 10 series summary" cache
    if (isset($_POST['warm_top_summary']) && check_admin_referer('warm_top_summary')) {
        $renderer = new ComicRenderer();
        $renderer->get_top_publishers_with_series(); // this should warm it
        echo '<div class="notice notice-success"><p>Top 10 publishers × 10 series summary cache warmed!</p></div>';
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

        <form method="post" style="margin-top:15px;">
            <?php wp_nonce_field('warm_top_publishers'); ?>
            <input type="submit" name="warm_top_publishers" class="button button-primary"
                   value="Warm Top 10 Publishers Cache (full pages)"
                   onclick="return confirm('This will pre-cache 10 pages for each of publishers 1–10. Continue?');" />
            <p style="color:#666;font-style:italic;margin-top:5px;">
                Warms full paginated series lists for top 10 publishers (10 pages each).
            </p>
        </form>

        <form method="post" style="margin-top:15px;">
            <?php wp_nonce_field('warm_top_summary'); ?>
            <input type="submit" name="warm_top_summary" class="button button-primary"
                   value="Warm Featured Series Summary Cache"
                   onclick="return confirm('This will cache the top 10 series from each of the top 10 publishers. Continue?');" />
            <p style="color:#666;font-style:italic;margin-top:5px;">
                Warms compact cache: 10 publishers × 10 latest/first series each (ideal for homepage/widget).
            </p>
        </form>

        <hr style="margin: 3rem 0;">

        <h2>Featured Series (Top 10 Publishers)</h2>
        <p style="color:#555;">
            This section shows the first 10 series from each of the top 10 publishers (IDs 1–10).<br>
            No configuration needed — fixed to Marvel (1), DC (2), Image (3), etc.
        </p>
        <p style="color:#555; font-style:italic;">
            Use the "Warm Featured Series Summary Cache" button above to pre-load this data.
        </p>

    </div>
    <?php
}

//Cache warming endpoint for series list (called when admin visits catalog and finds empty page)
add_action('wp_ajax_schedule_warm_series_cache', function() {
    check_ajax_referer('comicbooks_fetchers_data', 'nonce');
    $publisher_id = intval($_POST['publisher_id'] ?? 0);
    if ($publisher_id > 0) {
        $renderer = new ComicRenderer();
        $renderer->schedule_cache_warm($publisher_id);
    }
    wp_send_json_success();  
});