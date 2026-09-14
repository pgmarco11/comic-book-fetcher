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

// === INCLUDE FUNCTIONS ===
require_once COMICBOOKS_PLUGIN_DIR . 'includes/wish-list.php';
require_once COMICBOOKS_PLUGIN_DIR . 'includes/comic-collection.php';
require_once COMICBOOKS_PLUGIN_DIR . 'includes/collection-inventory.php';

add_action(
    'comicbooks_refresh_publisher_list',
    function () {
        $service = new ComicDataService(
            new MetronClient()
        );

        $service->refresh_publishers_batch();
    }
);

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
    $load_category_archive = false;

    // Load on specific pages
    if (is_page(['comic-catalog', 'issues', 'issue'])) {
        $load_comic_assets = true;
    }

    if ( is_post_type_archive('collection') || is_tax('publisher') || is_tax('comic_genre') ) { 
        $load_category_archive = true;
    }

    // Load on collection posts
    if (is_singular('collection') && $post) {    
            $load_comic_assets = true;     
    }

    wp_enqueue_script(
        'comic-utils',
        COMICBOOKS_PLUGIN_URL . 'js/comic-utils.js',
        ['toastify-js'], 
        COMICBOOKS_PLUGIN_DIR . 'js/comic-utils.js',
        true
    );

    // === COMIC ASSETS ===
    if ($load_comic_assets) {
        wp_enqueue_style(
            'comicbook-style',
            COMICBOOKS_PLUGIN_URL . 'css/comic-book.css',
            [],
            filemtime( COMICBOOKS_PLUGIN_DIR . 'css/comic-book.css' )
        );

        wp_enqueue_script(
            'comicbook-script',
            COMICBOOKS_PLUGIN_URL . 'js/comic-book.js',
            ['jquery', 'comic-utils'],
            filemtime( COMICBOOKS_PLUGIN_DIR . 'js/comic-book.js' ),
            true
        );

        wp_localize_script(
            'comicbook-script',
            'comicbooks_fetchers_data',
            [
                'ajax_url'    => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('comicbooks_fetchers_data'),
                'placeholder' => PUBLISHER_PLACEHOLDER_IMAGE_URL,
                'per_page'    => 10,
            ]
        );
     
        wp_enqueue_script(
            'comic-collection-script',
            COMICBOOKS_PLUGIN_URL . 'js/comic-collection.js',
            ['jquery', 'comic-utils'], 
            COMICBOOKS_PLUGIN_DIR . 'js/comic-collection.js',
            true
        );     

    }

    // === WISHLIST ASSETS (always load) ===
    wp_enqueue_script('toastify-js', 'https://cdn.jsdelivr.net/npm/toastify-js', [], null, true);
    wp_enqueue_style('toastify-css', 'https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css');

    wp_enqueue_script(
        'wishlist-script',
        COMICBOOKS_PLUGIN_URL . 'js/wishlist.js',
        ['jquery', 'toastify-js'],
        filemtime(COMICBOOKS_PLUGIN_DIR . 'js/wishlist.js'),
        true
    );

    wp_enqueue_style(
        'wishlist-style',
        COMICBOOKS_PLUGIN_URL . 'css/wishlist.css',
        [],
        filemtime( COMICBOOKS_PLUGIN_DIR . 'css/wishlist.css' )
    );

    wp_localize_script('wishlist-script', 'wishlist_ajax_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wishlist_nonce')
    ]);
}
add_action('wp_enqueue_scripts', 'comicbooks_enqueue_scripts');

/** ==================================================================
 *  ADMIN SETTINGS PAGE
 * ================================================================== */
function comic_book_api_settings_page() {
    add_menu_page(
        'Comic books',
        'Comic books',
        'manage_options',
        'comicbooks-settings',
        'render_api_settings_page',
        'dashicons-book',
        25
    );
    // Publishers submenu
    add_submenu_page(
            'comicbooks-settings',                 // parent slug
            'Publishers',                          // page title
            'Publishers',                          // menu title
            'manage_categories',
            'edit-tags.php?taxonomy=publisher&post_type=collection'
    );    
    // Genres submenu
    add_submenu_page(
            'comicbooks-settings',
            'Genres',
            'Genres',
            'manage_categories',
            'edit-tags.php?taxonomy=comic_genre&post_type=collection'
    );
    // Metron and Comic Vine credentials.
    add_submenu_page(
            'comicbooks-settings',
            'Comic Books API Settings',
            'API Settings',
            'manage_options',
            'comicbooks-settings',
            'render_api_settings_page'
    );    
}

add_action(
    'comicbooks_process_publisher_warm_queue',
    'comicbooks_process_publisher_warm_queue'
);

function comicbooks_process_publisher_warm_queue()
{
    $queue = get_transient(
        'comicbooks:publisher_warm_queue'
    );

    if (!is_array($queue) || empty($queue)) {
        delete_transient(
            'comicbooks:publisher_warm_queue'
        );

        return;
    }

    /*
     * Process exactly one publisher during this request.
     */
    $publisher_id = absint(array_shift($queue));

    if ($publisher_id) {
        $service = new ComicDataService(
            new MetronClient()
        );

        /*
         * Warm only the first Metron series-list page.
         * Existing caches will be used when available.
         */
        $service->get_series(
            $publisher_id,
            1,
            10,
            '',
            'all',
            false
        );
    }

    if (!empty($queue)) {
        /*
         * Save the remaining publisher IDs.
         */
        set_transient(
            'comicbooks:publisher_warm_queue',
            $queue,
            DAY_IN_SECONDS
        );

        /*
         * Process the next publisher later in a separate request.
         */
        wp_schedule_single_event(
            time() + 10,
            'comicbooks_process_publisher_warm_queue'
        );
    } else {
        delete_transient(
            'comicbooks:publisher_warm_queue'
        );
    }
}

add_action('admin_menu', 'comic_book_api_settings_page');

/**
 * Calculate the delay before retrying a failed background request.
 *
 * Attempts:
 * 0 -> 15 seconds
 * 1 -> 30 seconds
 * 2 -> 60 seconds
 * 3 -> 120 seconds
 */
function comicbooks_background_retry_delay(
    int $attempt,
    int $retry_after = 0
): int {
    $attempt = max(0, $attempt);

    $backoff = min(
        120,
        15 * (2 ** $attempt)
    );

    /*
     * Never retry sooner than the API's Retry-After value.
     * The extra two seconds provides a small safety buffer.
     */
    $api_delay = $retry_after > 0
        ? $retry_after + 2
        : 0;

    return max($backoff, $api_delay);
}

/**
 * Refresh one stale issue-list API page.
 */
function comicbooks_refresh_issue_page_cache(
    $title_id,
    $api_page,
    $attempt = 0
) {
    $title_id = absint($title_id);
    $api_page = max(1, absint($api_page));
    $attempt  = max(0, absint($attempt));

    if (!$title_id) {
        return;
    }

    $service = new ComicDataService(
        new MetronClient()
    );

    $result  = $service->refresh_issue_api_page(
        $title_id,
        $api_page
    );

    if (
        !is_array($result) ||
        empty($result['temporary'])
    ) {
        return;
    }

    /*
     * Attempt values 0 through 4 allow five total executions.
     * Stop after the fifth temporary failure.
     */
    $next_attempt = $attempt + 1;

    if ($next_attempt >= 5) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
                sprintf(
                    'Comic Books: issue-page refresh stopped after %d attempts. Title %d, API page %d.',
                    $next_attempt,
                    $title_id,
                    $api_page
                )
            );
        }

        return;
    }

    $delay = comicbooks_background_retry_delay(
        $attempt,
        isset($result['retry_after'])
            ? absint($result['retry_after'])
            : 0
    );

    $args = [
        $title_id,
        $api_page,
        $next_attempt,
    ];

    if (
        !wp_next_scheduled(
            'comicbooks_refresh_issue_page_cache',
            $args
        )
    ) {
        wp_schedule_single_event(
            time() + $delay,
            'comicbooks_refresh_issue_page_cache',
            $args
        );
    }
}

add_action(
    'comicbooks_refresh_issue_page_cache',
    'comicbooks_refresh_issue_page_cache',
    10,
    3
);

/**
 * Refresh one stale publisher series-list API page.
 */
function comicbooks_refresh_series_page_cache(
    $publisher_id,
    $api_page,
    $api_page_size,
    $attempt = 0
) {
    $publisher_id = absint($publisher_id);
    $api_page      = max(1, absint($api_page));
    $api_page_size = max(1, absint($api_page_size));
    $attempt       = max(0, absint($attempt));

    if (!$publisher_id) {
        return;
    }

    $service = new ComicDataService(
        new MetronClient()
    );

    $result  = $service->refresh_series_api_page(
        $publisher_id,
        $api_page,
        $api_page_size
    );

    if (
        !is_array($result) ||
        empty($result['temporary'])
    ) {
        return;
    }

    $next_attempt = $attempt + 1;

    if ($next_attempt >= 5) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
                sprintf(
                    'Comic Books: series-page refresh stopped after %d attempts. Publisher %d, API page %d.',
                    $next_attempt,
                    $publisher_id,
                    $api_page
                )
            );
        }

        return;
    }

    $delay = comicbooks_background_retry_delay(
        $attempt,
        isset($result['retry_after'])
            ? absint($result['retry_after'])
            : 0
    );

    $args = [
        $publisher_id,
        $api_page,
        $api_page_size,
        $next_attempt,
    ];

    if (
        !wp_next_scheduled(
            'comicbooks_refresh_series_page_cache',
            $args
        )
    ) {
        wp_schedule_single_event(
            time() + $delay,
            'comicbooks_refresh_series_page_cache',
            $args
        );
    }
}

add_action(
    'comicbooks_refresh_series_page_cache',
    'comicbooks_refresh_series_page_cache',
    10,
    4
);

function comicbooks_continue_series_scan(
    $publisher_id,
    $attempt = 0
) {
    $publisher_id = absint($publisher_id);
    $attempt      = max(0, absint($attempt));

    if (!$publisher_id) {
        return;
    }

    $service = new ComicDataService(
        new MetronClient()
    );

    $result = $service->continue_series_scan(
        $publisher_id
    );

    if (!empty($result['complete'])) {
        return;
    }

    if (
        !empty($result['temporary']) &&
        $attempt >= 4
    ) {
        return;
    }

    $next_attempt = !empty($result['temporary'])
        ? $attempt + 1
        : 0;

    $delay = !empty($result['temporary'])
        ? comicbooks_background_retry_delay(
            $attempt,
            absint($result['retry_after'] ?? 5)
        )
        : 5;

        $args = [
            $publisher_id,
            $next_attempt,
        ];
        
        if (
            !wp_next_scheduled(
                'comicbooks_continue_series_scan',
                $args
            )
        ) {
            wp_schedule_single_event(
                time() + $delay,
                'comicbooks_continue_series_scan',
                $args
            );
        }
}

add_action(
    'comicbooks_continue_series_scan',
    'comicbooks_continue_series_scan',
    10,
    2
);

function render_api_settings_page() {
    // Save API credentials
    if (isset($_POST['submit']) && check_admin_referer('save_api_settings')) {
        update_option('metron_api_username', sanitize_text_field($_POST['metron_api_username']));
        update_option('metron_api_password', sanitize_text_field($_POST['metron_api_password']));
        update_option('comic_vine_api_key', sanitize_text_field($_POST['comic_vine_api_key']));        
     }


    // Warm publisher caches – now supports custom IDs or top 5
    if (
        isset($_POST['warm_publisher_caches']) &&
        check_admin_referer('warm_publisher_caches')
    ) {
        $custom_input = sanitize_text_field(
            wp_unslash(
                $_POST['custom_publisher_ids'] ?? ''
            )
        );
    
        $publisher_ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'absint',
                        explode(',', $custom_input)
                    )
                )
            )
        );
    
        /*
         * Default publishers when no IDs were entered.
         */
        if (empty($publisher_ids)) {
            $publisher_ids = [2, 3];
        }
    
        /*
         * Save the work instead of performing it during this page request.
         */
        set_transient(
            'comicbooks:publisher_warm_queue',
            $publisher_ids,
            DAY_IN_SECONDS
        );
    
        /*
         * Prevent duplicate scheduled workers.
         */
        if (
            !wp_next_scheduled(
                'comicbooks_process_publisher_warm_queue'
            )
        ) {
            wp_schedule_single_event(
                time() + 5,
                'comicbooks_process_publisher_warm_queue'
            );
        }
    
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>Cache warming scheduled.</strong> ';
        echo esc_html(
            count($publisher_ids) .
            ' publisher(s) will be processed in the background.'
        );
        echo '</p></div>';
    }

    // HTML output
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

        <h2>Cache Warm-up Tools</h2>

        <form method="post">
            <?php wp_nonce_field('warm_publisher_caches'); ?>

            <p>
                <label for="custom_publisher_ids"><strong>Publisher IDs to warm (comma-separated or single ID):</strong></label><br>
                <input type="text" id="custom_publisher_ids" name="custom_publisher_ids" placeholder="e.g. 1,2,57 or leave blank for DC & Dark Horse" class="regular-text" style="width:400px;" />
            </p>

            <p>
                <input type="submit" name="warm_publisher_caches" class="button button-primary"
                       value="Warm Selected / DC & Dark Horse"
                       onclick="return confirm('This will make API calls to pre-cache series & some issues. May take 30–120 seconds depending on IDs. Continue?');" />

                <span style="margin-left:15px; color:#555; font-style:italic;">
                    If field empty → warms DC & Dark Horse.
                </span>
            </p>
        </form>

    </div>
    <?php
}

