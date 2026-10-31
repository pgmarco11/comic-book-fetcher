<?php
/**
 * Plugin Name: Comic Books Fetcher & Manager
 * Description: Manage Comic Collection, edit API settings, and enqueues scripts for displaying comic book data from the Metron & Comic Vine API.
 * Version: 1.1.1
 * Author: Peter Giammarco
 * Author URI: https://www.pgiammarco.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: comic-books-fetcher
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/includes/class-tcs-cv-issue-worker.php';
require_once __DIR__ . '/includes/class-tcs-catalog-background-worker.php';

function comicbooks_cv_worker(): TCS_CV_Issue_Worker {
    static $instance;
    if (!$instance) {
        $instance = new TCS_CV_Issue_Worker();
    }
    return $instance;
}

function comicbooks_catalog_worker(): TCS_Catalog_Background_Worker {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Catalog_Background_Worker();
    }
    return $instance;
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
require_once COMICBOOKS_PLUGIN_DIR . 'includes/collection-transfer.php';

add_action(
    'comicbooks_refresh_publisher_list',
    function () {
        $service = new ComicDataService(
            new MetronClient()
        );

        $service->refresh_publishers_batch();
    }
);

/*
 * Comic Vine issue-cover background queue.
 */
const COMICBOOKS_CV_ISSUE_QUEUE =
    'comicbooks_cv_issue_enrichment_queue_v1';

const COMICBOOKS_CV_ISSUE_QUEUE_LOCK =
    'cv-issue-enrichment-queue';

const COMICBOOKS_CV_MAPPING_WORKER_LOCK =
    'cv-issue-mapping-worker';

const COMICBOOKS_CV_MAPPING_NEXT_AT =
    'comicbooks_cv_mapping_next_at_v1';

const COMICBOOKS_CV_MAPPING_INTERVAL = 30;

/**
 * Force WordPress to re-read a non-autoloaded option.
 */
function comicbooks_read_cv_issue_queue(): array
{
    return comicbooks_cv_worker()->read_cv_issue_queue();
}

/**
 * Save the queue without enabling autoload.
 */
function comicbooks_save_cv_issue_queue(
    array $queue
): bool {
    return comicbooks_cv_worker()->save_cv_issue_queue($queue);
}

/**
 * Schedule the queue worker without creating duplicate events.
 */
function comicbooks_schedule_cv_issue_worker(
    int $delay = 2
): void {
    comicbooks_cv_worker()->schedule_cv_issue_worker($delay);
}

/**
 * Determine whether an issue still needs background enrichment.
 */
function comicbooks_issue_needs_cv_enrichment(
    array $issue
): bool {
    return comicbooks_cv_worker()->issue_needs_cv_enrichment($issue);
}

/**
 * Add a page of issues to the background queue atomically.
 */
function comicbooks_queue_cv_issue_enrichment(
    array $issues,
    int $attempt = 0,
    bool $requested = false
): void {
    comicbooks_cv_worker()->queue_cv_issue_enrichment($issues, $attempt, $requested);
}

/**
 * Retry a queue merge that could not acquire the lock.
 */
function comicbooks_retry_cv_issue_enrichment_queue(
    $issues,
    $attempt = 0
): void {
    comicbooks_cv_worker()->retry_cv_issue_enrichment_queue($issues, $attempt);
}

add_action(
    'comicbooks_retry_cv_issue_enrichment_queue',
    'comicbooks_retry_cv_issue_enrichment_queue',
    10,
    2
);

/**
 * Resolve an already-known Comic Vine ID without API requests.
 */
function comicbooks_known_cv_issue_id(array $issue): int
{
    return comicbooks_cv_worker()->known_cv_issue_id($issue);
}

/**
 * Read the shared earliest time for another mapping lookup.
 */
function comicbooks_next_cv_mapping_time(): int
{
    return comicbooks_cv_worker()->next_cv_mapping_time();
}

/**
 * Known-ID work can continue while mapping jobs wait.
 */
function comicbooks_cv_issue_worker_delay(array $queue): int
{
    return comicbooks_cv_worker()->cv_issue_worker_delay($queue);
}


/**
 * Determine whether background enrichment produced a confirmed result.
 *
 * A confirmed result is either:
 * - Metron confirmed that no Comic Vine mapping exists, or
 * - A Comic Vine ID and its image result are cached.
 *
 * Temporary API failures leave one of these caches missing.
 */
function comicbooks_issue_cv_enrichment_complete(
    array $issue
): bool {
    return comicbooks_cv_worker()->issue_cv_enrichment_complete($issue);
}

/**
 * Batch known Comic Vine IDs.
 * Resolve unknown mappings one issue at a time.
 */
function comicbooks_process_cv_issue_enrichment_queue(): void
{
    comicbooks_cv_worker()->process_cv_issue_enrichment_queue();
}

add_action(
    'comicbooks_process_cv_issue_enrichment_queue',
    'comicbooks_process_cv_issue_enrichment_queue'
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

    $load_comic_assets      = false;
    $load_category_archive  = false;

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

    // === WISHLIST ASSETS ===
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

    wp_localize_script(
        'wishlist-script', 
        'wishlist_ajax_obj', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wishlist_nonce')
        ]
    );
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
    return comicbooks_catalog_worker()->process_publisher_warm_queue();
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
    return comicbooks_catalog_worker()->refresh_issue_page_cache($title_id, $api_page, $attempt);
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
    return comicbooks_catalog_worker()->refresh_series_page_cache($publisher_id, $api_page, $api_page_size, $attempt);
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
    return comicbooks_catalog_worker()->continue_series_scan($publisher_id, $attempt);
}

add_action(
    'comicbooks_continue_series_scan',
    'comicbooks_continue_series_scan',
    10,
    2
);

/**
 * Register Comic Catalog query variables.
 */
function comicbooks_register_catalog_query_vars(
    array $query_vars
): array {
    $catalog_query_vars = [
        'publisher_id',
        'title_id',
        'issue_id',
        'letter',
        'search',
        'catalog_page',
    ];

    return array_values(
        array_unique(
            array_merge(
                $query_vars,
                $catalog_query_vars
            )
        )
    );
}

add_filter(
    'query_vars',
    'comicbooks_register_catalog_query_vars'
);

/**
 * Remap the public "page" parameter before WordPress interprets it
 * as pagination within a WordPress Page.
 *
 * The browser URL remains:
 * ?letter=M&page=2
 *
 * Internally, the catalog reads:
 * catalog_page=2
*/
function comicbooks_remap_catalog_page_parameter(
    array $query_vars
): array {
    if (is_admin()) {
        return $query_vars;
    }

    $requested_page = isset($_GET['page'])
        ? absint(wp_unslash($_GET['page']))
        : 0;

    if ($requested_page < 1) {
        return $query_vars;
    }

    $request_uri = isset($_SERVER['REQUEST_URI'])
        ? wp_unslash($_SERVER['REQUEST_URI'])
        : '';

    $requested_path = wp_parse_url(
        $request_uri,
        PHP_URL_PATH
    );

    if (!is_string($requested_path)) {
        return $query_vars;
    }

    $requested_path = trailingslashit(
        $requested_path
    );

    $catalog_path = trailingslashit(
        wp_parse_url(
            home_url('/comic-catalog/'),
            PHP_URL_PATH
        )
    );

    $issues_path = trailingslashit(
        wp_parse_url(
            home_url('/comic-catalog/issues/'),
            PHP_URL_PATH
        )
    );

    if (
        $requested_path !== $catalog_path &&
        $requested_path !== $issues_path
    ) {
        return $query_vars;
    }

    /*
     * Preserve the catalog page under a plugin-owned query variable.
     */
    $query_vars['catalog_page'] = $requested_page;

    /*
     * Prevent WordPress from treating it as a request for page 2,
     * page 5, etc. of the WordPress Page's actual content.
     */
    unset($query_vars['page']);

    return $query_vars;
}

add_filter(
    'request',
    'comicbooks_remap_catalog_page_parameter',
    1
);

/**
 * Preserve pagination on Comic Catalog routes.
 *
 * WordPress treats "page" as pagination for a single WordPress page
 * and may remove it through redirect_canonical(). These catalog
 * templates use it for API-result pagination instead.
*/
function comicbooks_preserve_catalog_page_parameter(
    $redirect_url,
    $requested_url
) {
    if (is_admin()) {
        return $redirect_url;
    }

    $requested_page = isset($_GET['page'])
        ? absint(wp_unslash($_GET['page']))
        : 0;

    if ($requested_page < 1) {
        return $redirect_url;
    }

    $requested_path = wp_parse_url(
        $requested_url,
        PHP_URL_PATH
    );

    if (!is_string($requested_path)) {
        return $redirect_url;
    }

    $requested_path = trailingslashit(
        $requested_path
    );

    $catalog_path = trailingslashit(
        wp_parse_url(
            home_url('/comic-catalog/'),
            PHP_URL_PATH
        )
    );

    $issues_path = trailingslashit(
        wp_parse_url(
            home_url('/comic-catalog/issues/'),
            PHP_URL_PATH
        )
    );

    if (
        $requested_path === $catalog_path ||
        $requested_path === $issues_path
    ) {
        return false;
    }
    return $redirect_url;
}

add_filter(
    'redirect_canonical',
    'comicbooks_preserve_catalog_page_parameter',
    10,
    2
);

/**
 * Prevent full-page caching of logged-in Comic Catalog requests.
 *
 * The catalog exposes user-specific collection/wishlist controls whose
 * AJAX requests use WordPress nonces. A cached page can contain a nonce
 * belonging to another session or an expired nonce.
 */
function comicbooks_protect_logged_in_catalog_pages(): void
{
    if (!is_user_logged_in()) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI'])
        ? wp_unslash($_SERVER['REQUEST_URI'])
        : '';

    $path = wp_parse_url(
        $request_uri,
        PHP_URL_PATH
    );

    if (!is_string($path)) {
        return;
    }

    $path = trailingslashit($path);

    $catalog_paths = [
        trailingslashit(
            wp_parse_url(
                home_url('/comic-catalog/'),
                PHP_URL_PATH
            )
        ),
        trailingslashit(
            wp_parse_url(
                home_url('/comic-catalog/issues/'),
                PHP_URL_PATH
            )
        ),
        trailingslashit(
            wp_parse_url(
                home_url('/comic-catalog/issue/'),
                PHP_URL_PATH
            )
        ),
    ];

    if (!in_array($path, $catalog_paths, true)) {
        return;
    }

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    nocache_headers();
}

add_action(
    'template_redirect',
    'comicbooks_protect_logged_in_catalog_pages',
    0
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

