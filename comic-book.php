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

define('COMICBOOKS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('COMICBOOKS_API_BASE', 'https://metron.cloud/api/'); 
define('PUBLISHER_PLACEHOLDER_IMAGE_URL', plugin_dir_url(__FILE__) . 'images/placeholder.png');

require_once COMICBOOKS_PLUGIN_DIR . 'comic-book-api.php';
require_once COMICBOOKS_PLUGIN_DIR . 'comic-book-class.php';
require_once COMICBOOKS_PLUGIN_DIR . 'comic-book-render.php';

add_action('init', function() {
    new Comicbooks();
});

function comicbooks_enqueue_scripts() {
    global $post;

    $load_comic_assets = false;

    // Check by page slug
    if (is_page('comic-books') || is_page('issues') || is_page('issue')) {
        $load_comic_assets = true;
    }
    // Check if current post is in 'collection' category or any of its children
    if (is_singular() && isset($post)) {
        $collection_cat = get_category_by_slug('collection');
        if ($collection_cat) {
            $collection_cat_id = $collection_cat->term_id;
            if (has_category($collection_cat_id, $post) || post_is_in_descendant_category($collection_cat_id, $post)) {
                $load_comic_assets = true;
            }
        }
    }
    // Enqueue comic assets if needed
    if ($load_comic_assets) {
        wp_enqueue_style(
            'comicbook-style',
            plugins_url('css/comic-book.css', __FILE__),
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'comicbook-script',
            plugins_url('js/comic-book.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );
        wp_localize_script(
            'comicbook-script',
            'comicbooks_fetchers_data',
            [
                'ajax_url'    => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('comicbooks_fetchers_data'),
                'placeholder' => defined('PUBLISHER_PLACEHOLDER_IMAGE_URL') ? PUBLISHER_PLACEHOLDER_IMAGE_URL : '',
                'per_page'    => 10,
            ]
        );
    }
    // Always enqueue wishlist
    wp_enqueue_script('toastify-js', 'https://cdn.jsdelivr.net/npm/toastify-js', [], null, true);
    wp_enqueue_style('toastify-css', 'https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css');
        
    wp_enqueue_script(
        'wishlist-script',
        plugins_url('js/wishlist.js', __FILE__),
        ['jquery'],
        '1.0.0',
        true
    );
    wp_enqueue_style(
        'wishlist-style',
        plugins_url('css/wishlist.css', __FILE__),
        [],
        '1.0.0'
    );
    wp_localize_script(
        'wishlist-script',
        'wishlist_ajax_obj',
        [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wishlist_nonce')
        ]
    );
}
add_action('wp_enqueue_scripts', 'comicbooks_enqueue_scripts');

function comic_book_api_settings_page() {
    add_menu_page(
        'Comic books',
        'Comic books',
        'manage_options',
        'title-settings',
        'render_api_settings_page',
        'dashicons-book'
    );
    add_submenu_page(
        'title-settings',
        'Comic books',
        'API Settings',
        'manage_options',
        'title-settings',
        'render_api_settings_page'
    );
}
add_action('admin_menu', 'comic_book_api_settings_page');

function render_api_settings_page() {

    if (isset($_POST['submit']) && check_admin_referer('save_api_settings')) {
  
        update_option('metron_api_username', sanitize_text_field($_POST['metron_api_username']));
        update_option('metron_api_password', sanitize_text_field($_POST['metron_api_password']));
        update_option('comic_vine_api_key', sanitize_text_field($_POST['comic_vine_api_key']));
        delete_transient('metron_publishers');
        global $wpdb;
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_metron_%'"); 
        echo '<div class="updated"><p>Settings saved and caches cleared.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>API Settings</h1>
        <form method="post">
            <?php wp_nonce_field('save_api_settings'); ?>
            <h2>Metron API</h2>
            <p>
                <label for="metron_api_username">Username:</label><br>
                <input type="text" id="metron_api_username" name="metron_api_username" value="<?php echo esc_attr(get_option('metron_api_username')); ?>" />
            </p>
            <p>
                <label for="metron_api_password">Password:</label><br>
                <input type="password" id="metron_api_password" name="metron_api_password" value="<?php echo esc_attr(get_option('metron_api_password')); ?>" />
            </p>
            <h2>Comic Vine API</h2>
            <p>
                <label for="comic_vine_api_key">API Key:</label><br>
                <input type="text" id="comic_vine_api_key" name="comic_vine_api_key" value="<?php echo esc_attr(get_option('comic_vine_api_key')); ?>" />
            </p>
            <p><input type="submit" name="submit" class="button-primary" value="Save Settings" /></p>
        </form>
    </div>
    <?php
}

//toggle add/remove
add_action('wp_ajax_add_comic_to_collection', 'handle_add_comic_to_collection');
function handle_add_comic_to_collection() {

    // Verify nonce
    if (!check_ajax_referer('comicbooks_fetchers_data', 'security', false)) {
        error_log('Nonce verification failed');
        wp_send_json_error('Nonce verification failed');
    }

    // Check if user is logged in
    $user_id = get_current_user_id();
    if (!$user_id) {
        error_log('User not logged in');
        wp_send_json_error('User not logged in.');
    }

    // Get and sanitize POST data
    $data = $_POST['data'] ?? [];
    $issue_id = intval($data['issueId'] ?? 0);
    $title_id = intval($data['titleId'] ?? 0);
    $title = sanitize_text_field($data['title'] ?? '');
    $publisher = sanitize_text_field($data['publisher'] ?? '');
    $volume = intval($data['volume'] ?? 0);
    $issue_number = intval($data['issueNumber'] ?? 0);
    $image_url = esc_url_raw($data['imageUrl'] ?? ''); 
    $issue_date = sanitize_text_field($data['date'] ?? '');
    $description = wp_kses_post($data['description'] ?? '');
    $creators = sanitize_text_field($data['creators'] ?? '');
    $genres = sanitize_text_field($data['genres'] ?? '');
    $g_origin = sanitize_text_field($data['genre-origin'] ?? '');

    // Create the post
    $post_id = wp_insert_post([
        'post_type'    => 'post',
        'post_title'   => $title,
        'post_content' => $description,
        'post_status'  => 'publish',
        'post_author'  => $user_id,
    ]);

    if (is_wp_error($post_id)) {
        wp_send_json_error('Failed to create post: ' . $post_id->get_error_message());
    }

    // Ensure the "Collection" category exists
    $collection_term = get_term_by('name', 'Collection', 'category');
    if (!$collection_term) {
        $collection_term = wp_insert_term('Collection', 'category');
        if (is_wp_error($collection_term)) {    
            wp_send_json_error('Failed to create "Collection" category: ' . $collection_term->get_error_message());
        }
        $collection_term_id = $collection_term['term_id'];
    } else {
        $collection_term_id = $collection_term->term_id;
    }

    // Assign "Collection" as base category
    wp_set_post_terms($post_id, [$collection_term_id], 'category');

    // Create publisher as sub-category under "Collection" if it doesn't exist
    $publisher_term = get_term_by('name', $publisher, 'category');
    if (!$publisher_term) {
        $publisher_term = wp_insert_term($publisher, 'category', ['parent' => $collection_term_id]);
        if (!is_wp_error($publisher_term)) {
            $publisher_term_id = $publisher_term['term_id'];
        }
    } else {
        $publisher_term_id = $publisher_term->term_id;
    }

    // Assign publisher as additional category
    if (!empty($publisher_term_id)) {
        wp_set_post_terms($post_id, [$publisher_term_id], 'category', true);
    }

    // Save metadata
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

    // Include required WordPress files
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // Function to sideload an image and return its attachment ID
    function sideload_image($image_url, $post_id, $description = '') {
        
        // Validate URL
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {    
            return false;
        }
    
        // Custom HTTP request to handle encoding issues
        $args = [
            'timeout' => 15,
            'headers' => [
                'Accept-Encoding' => 'identity', // Request uncompressed data to avoid encoding issues
                'User-Agent' => 'CollectibleSpotBot/1.0 (+'  . get_site_url() . ') ',                
            ],
            'sslverify' => true,
        ];
    
        // First validate the image
        $response = wp_remote_head($image_url, $args);
        if (is_wp_error($response)) {
            error_log('Image validation failed for URL: ' . $image_url . ' - ' . $response->get_error_message());
            return false;
        }
    
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('Invalid response code for URL: ' . $image_url . ' - Code: ' . $response_code);
            return false;
        }
    
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!preg_match('/^image\/(jpeg|png|gif|webp)/', $content_type)) {
            error_log('Invalid image type for URL: ' . $image_url . ' - Content-Type: ' . $content_type);
            return false;
        }
    
        // Download image with custom cURL options
        $image_name = sanitize_file_name(basename(parse_url($image_url, PHP_URL_PATH)));
        $tmp = download_url($image_url, 15, [
            'headers' => ['Accept-Encoding' => 'identity'], // Avoid compression
            'sslverify' => true,
        ]);
    
        if (is_wp_error($tmp)) {
            // Fallback: Try direct file_get_contents if cURL fails
            $image_data = @file_get_contents($image_url);
            if ($image_data === false) {
                error_log('Fallback file_get_contents failed for URL: ' . $image_url);
                return false;
            }
            $tmp = wp_tempnam($image_name);
            if (!file_put_contents($tmp, $image_data)) {
                error_log('Failed to write image data to temporary file: ' . $tmp);
                return false;
            }
        }
    
        $file_array = [
            'name'     => $image_name,
            'tmp_name' => $tmp,
        ];
    
        $image_id = media_handle_sideload($file_array, $post_id, $description);
    
        if (is_wp_error($image_id)) {
            error_log('media_handle_sideload failed for URL: ' . $image_url . ' - ' . $image_id->get_error_message());
            @unlink($tmp);
            return false;
        }
    
        error_log('Image successfully sideloaded: Attachment ID ' . $image_id);
        return $image_id;
    }
    

    // Handle primary image
    $image_id = false;
    if (!empty($image_url)) {
        error_log('Processing primary image for post ID: ' . $post_id);
       // $image_id = sideload_image($image_url, $post_id, $title);
    } else {
        error_log('No primary image URL provided');
    }


    // Fallback to placeholder image if primary image fails or is empty
    if (!$image_id) {
        $placeholder_url = defined('PUBLISHER_PLACEHOLDER_IMAGE_URL') ? PUBLISHER_PLACEHOLDER_IMAGE_URL : '';
        if (!empty($placeholder_url)) {
     
            // Check if placeholder is already in media library
            $placeholder_id = get_option('publisher_placeholder_image_id');
            if (!$placeholder_id) {
                // Sideload placeholder image
                $args = [
                    'timeout' => 15,
                    'headers' => ['Accept-Encoding' => 'identity'],
                    'sslverify' => (strpos($placeholder_url, home_url()) !== false) ? false : true, // Disable SSL for local URLs
                ];
                $placeholder_id = sideload_image($placeholder_url, $post_id, 'Placeholder Image');
                if ($placeholder_id) {                 
                    update_option('publisher_placeholder_image_id', $placeholder_id);
                } else {               
                    // Fallback: Use file_get_contents for local placeholder
                    if (strpos($placeholder_url, home_url()) !== false) {
                        $local_path = str_replace(home_url(), ABSPATH, $placeholder_url);
                        if (file_exists($local_path)) {
                            $file_array = [
                                'name' => basename($placeholder_url),
                                'tmp_name' => $local_path,
                            ];
                            $placeholder_id = media_handle_sideload($file_array, $post_id, 'Placeholder Image');
                            if (!is_wp_error($placeholder_id)) {                           
                                update_option('publisher_placeholder_image_id', $placeholder_id);
                            } else {
                                error_log('Local placeholder sideload failed: ' . $placeholder_id->get_error_message());
                            }
                        } else {
                            error_log('Local placeholder file does not exist: ' . $local_path);
                        }
                    }
                }
            } else {
                error_log('Using existing placeholder image: Attachment ID ' . $placeholder_id);
            }
            $image_id = $placeholder_id;
        } else {
            error_log('No placeholder image URL defined');
        }
    }

    // Set featured image
    if ($image_id) {
        $result = set_post_thumbnail($post_id, $image_id);
        if ($result) {
            error_log('Featured image set successfully for post ID: ' . $post_id . ' with attachment ID: ' . $image_id);
        } else {
            error_log('Failed to set featured image for post ID: ' . $post_id . ' with attachment ID: ' . $image_id);
        }
    } else {
        error_log('No valid image ID to set as featured image for post ID: ' . $post_id);
    }

    wp_send_json_success(['post_id' => $post_id, 'image_id' => $image_id]);
}

//toggle add/remove
add_action('wp_ajax_remove_comic_from_collection', 'handle_remove_comic_from_collection');
function handle_remove_comic_from_collection() {
    // Verify nonce
    check_ajax_referer('comicbooks_fetchers_data', 'security');

    // Check if user is logged in
    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error('User not logged in.');
    }

    // Get and sanitize POST data
    $data = $_POST['data'] ?? [];
    $post_id = intval($data['postId'] ?? 0);

    if (!$post_id) {
        wp_send_json_error('Invalid post ID.');
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post' || $post->post_status === 'trash') {
        wp_send_json_error('Invalid post.');
    }

    if ($user_id !== (int) $post->post_author) {
        wp_send_json_error('Unauthorized.');
    }

    // Delete the post permanently
    $result = wp_delete_post($post_id, true);
    if (!$result) {
        wp_send_json_error('Failed to remove from collection.');
    }

    wp_send_json_success(['message' => 'Removed from collection.']);
}

//collection page removal, no toggle
add_action('template_redirect', 'handle_remove_from_collection');

function handle_remove_from_collection() {
    if (!isset($_POST['remove_collection_nonce'], $_POST['post_id'])) {
        return;
    }

    if (!wp_verify_nonce($_POST['remove_collection_nonce'], 'remove_collection_nonce')) {
        wp_die('Security check failed.');
    }

    $post_id = intval($_POST['post_id']);
    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'post' || $post->post_status === 'trash') {
        wp_die('Invalid post.');
    }

    if (get_current_user_id() !== (int) $post->post_author) {
        wp_die('Unauthorized.');
    }

    wp_delete_post($post_id, true); // Permanent deletion

    // Redirect with success message
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $redirect_url = add_query_arg('collection_message', 'removed', esc_url_raw($_SERVER['HTTP_REFERER']));
        wp_redirect($redirect_url);
        exit;
    }

    // Fallback if no referer
    wp_redirect(home_url());
    exit;
}

add_action('wp_ajax_check_wishlist_status_batch', 'check_wishlist_status_batch');
function check_wishlist_status_batch() {
    check_ajax_referer('wishlist_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('User not logged in');
    }

    $user_id = get_current_user_id();
    $wishlist = get_user_meta($user_id, 'user_wishlist', true);
    if (!is_array($wishlist)) $wishlist = [];

    $item_ids = isset($_POST['item_ids']) ? (array) $_POST['item_ids'] : [];
    $item_ids = array_map('sanitize_text_field', $item_ids);

    $in_wishlist = [];

    foreach ($wishlist as $item) {
        if (in_array($item['item_id'], $item_ids)) {
            $in_wishlist[] = $item['item_id'];
        }
    }

    wp_send_json_success($in_wishlist);
}

// Add to wishlist
add_action('wp_ajax_add_to_wishlist', 'add_to_wishlist_ajax');
function add_to_wishlist_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in to save to wishlist.');
    }

    $user_id = get_current_user_id();
    $type = sanitize_text_field($_POST['type']);
    $item_id = sanitize_text_field($_POST['item_id']);
    $title = sanitize_text_field($_POST['title']);
    $item_url = sanitize_text_field($_POST['item_url']);
    $image_url = esc_url_raw($_POST['image_url']);
    $volume = $_POST['volume'] ? sanitize_text_field($_POST['volume']) : null;
    $ebay_id = $_POST['ebay_id'] ? sanitize_text_field($_POST['ebay_id']) : null;

    // Get current wishlist or empty array
    $wishlist = get_user_meta($user_id, 'user_wishlist', true);
    if (!is_array($wishlist)) {
        $wishlist = [];
    }

    // Prevent duplicates
    foreach ($wishlist as $item) {
        if ($item['type'] === $type && $item['item_id'] === $item_id) {
            wp_send_json_error('Item already in wishlist.');
        }
    }

    // Add item
    $wishlist[] = [
        'type' => $type,
        'item_id' => $item_id,
        'title' => $title,
        'item_url' => $item_url,
        'image_url' => $image_url,
        'volume'    => $volume,
        'ebay_id'    => $ebay_id,
        'added_at' => current_time('mysql'),
    ];

    update_user_meta($user_id, 'user_wishlist', $wishlist);

    wp_send_json_success('Item added to wishlist.');
}

// Remove from wishlist
add_action('wp_ajax_remove_from_wishlist', 'remove_from_wishlist_ajax');
function remove_from_wishlist_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in.');
    }

    $item_id = sanitize_text_field($_POST['item_id']);
    $user_id = get_current_user_id();
    $wishlist = get_user_meta($user_id, 'user_wishlist', true);

    if (!is_array($wishlist)) {
        wp_send_json_error('Wishlist not found.');
    }

    $found = false;
    foreach ($wishlist as $index => $item) {
        if ((string)$item['item_id'] === (string)$item_id) {
            unset($wishlist[$index]);
            $wishlist = array_values($wishlist); // Reindex
            update_user_meta($user_id, 'user_wishlist', $wishlist);
            $found = true;
            break;
        }
    }

    if (!$found) {
        wp_send_json_error('Item not found.');
    }

    wp_send_json_success('Item removed from wishlist.');
}

// Check wishlist status
add_action('wp_ajax_check_wishlist_status', 'check_wishlist_status_ajax');
function check_wishlist_status_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_success(['in_wishlist' => false]);
    }

    $item_id = sanitize_text_field($_POST['item_id']);
    $user_id = get_current_user_id();
    $wishlist = get_user_meta($user_id, 'user_wishlist', true);

    $in_wishlist = false;
    if (is_array($wishlist)) {
        foreach ($wishlist as $item) {
            if ((string)$item['item_id'] === (string)$item_id) {
                $in_wishlist = true;
                break;
            }
        }
    }

    wp_send_json_success(['in_wishlist' => $in_wishlist]);
}

add_shortcode('user_wishlist', 'mwp_display_user_wishlist');
// Shortcode to display wishlist
function mwp_display_user_wishlist() {
    if (!is_user_logged_in()) {
        return '<p class="has-white-color">Please log in to view your wishlist.</p>';
    }
    $user_id = get_current_user_id();
    $wishlist = get_user_meta($user_id, 'user_wishlist', true);

    if (empty($wishlist) || !is_array($wishlist)) {
        return '<p class="has-white-color">Your wishlist is empty.</p>';
    } 
    ob_start();

    ?>
    <ul class="user-wishlist" style="list-style:none;padding:0;">
    <?php foreach ($wishlist as $index => $item): ?>  

            <li style="margin-bottom:15px;display:flex;align-items:center;" data-index="<?php echo esc_attr($index); ?>">                
                <img src="<?php echo esc_url($item['image_url']); ?>" alt="<?php echo esc_attr($item['title']); ?>" style="width:50px;height:50px;margin-right:10px;">
                <a href="<?php echo esc_url($item['item_url']); ?>" title="<?php echo esc_html($item['title']); ?>" target="_blank"><strong><?php echo esc_html($item['title']); ?></strong></a>
               <div>&nbsp;
                <?php
                    if (!empty($item['volume'])) {
                        echo 'Volume ' . esc_html($item['volume']);
                    } elseif (!empty($item['ebay_id'])) {
                        $tools_url = 'https://thecollectiblespot.com/tools/?item_id=' . urlencode($item['ebay_id']);
                        echo '<a href="' . esc_url($tools_url) . '" title="Check Item ' . esc_attr($item['ebay_id']) . '">' . esc_html($item['ebay_id']) . '</a>';
                    }         
               ?>
               </div>
                <button class="remove-from-wishlist" data-item-id="<?php echo esc_attr($item['item_id']); ?>" style="margin-left:auto;">Remove</button>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
    return ob_get_clean();
}