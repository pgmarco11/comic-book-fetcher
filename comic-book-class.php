<?php
/**
 * Comicbooks class for handling AJAX requests in the Comic Books Fetcher plugin.
 *
 * This class extends MetronAPI to provide AJAX endpoints for fetching comic book
 * publishers, series, series images, and publisher information from the Metron API.
 * It integrates with WordPress AJAX actions, handles nonce verification, and uses
 * caching to optimize API calls.
 *
 * @package ComicBooksFetcher
 * @since 1.0.0
 * @author Peter Giammarco
 */
class Comicbooks extends MetronAPI {
    public function __construct() {
        add_action('template_redirect', array($this, 'check_collection_redirect'));
        add_action('wp_ajax_load_book', [$this, 'ajax_load_book']);
        add_action('wp_ajax_nopriv_load_book', [$this, 'ajax_load_book']);
        add_action('wp_ajax_load_publishers', [$this, 'ajax_load_publishers']);
        add_action('wp_ajax_nopriv_load_publishers', [$this, 'ajax_load_publishers']);
        add_action('wp_ajax_load_issues', [$this, 'ajax_load_issues']);
        add_action('wp_ajax_nopriv_load_issues', [$this, 'ajax_load_issues']);
        add_action('wp_ajax_load_series_image', [$this, 'ajax_load_series_image']);
        add_action('wp_ajax_nopriv_load_series_image', [$this, 'ajax_load_series_image']);
        add_action('wp_ajax_load_series_images_batch', [$this, 'ajax_load_series_images_batch']);
        add_action('wp_ajax_nopriv_load_series_images_batch', [$this, 'ajax_load_series_images_batch']);
        add_action('wp_ajax_load_publisher_images_batch', [$this, 'ajax_load_publisher_images_batch']);
        add_action('wp_ajax_nopriv_load_series_images_batch', [$this, 'ajax_load_publisher_images_batch']);
        add_action('wp_ajax_load_publisher_info', [$this, 'ajax_load_publisher_info']);
        add_action('wp_ajax_nopriv_load_publisher_info', [$this, 'ajax_load_publisher_info']);
    }
    public function clean_cv_description($desc) {
        if (empty($desc)) {
            return '';
        }
    
        // Step 1: Remove <em> tags if they wrap the entire first <p> tag's content
        $desc = preg_replace('/<p>\s*<em>(.*?)<\/em>\s*<\/p>/is', '<p>$1</p>', $desc);

        // Step 1a: Remove <em> tags if they wrap the entire description
        $desc = preg_replace('/^<em>(.*?)<\/em>$/is', '$1', $desc);
        
        // Step 2: Remove all <a> tags, keeping their inner text
        $desc = preg_replace('/<a\s+[^>]*>(.*?)<\/a>/is', '$1', $desc);
    
        // Step 3: Clean all <li> entries by removing quotes around formatted titles
        $desc = preg_replace_callback('/<li>(.*?)<\/li>/is', function ($matches) {
            $item = $matches[1];
    
            // Remove quotes inside <b> or <strong> blocks
            $item = preg_replace('/^<b>\s*["\']\s*(<[^>]+>[^<]+<\/[^>]+>)\s*["\']\s*<\/b>/i', '<b>$1</b>', $item);
            $item = preg_replace('/<b>\s*["\']\s*(<em>[^<]+<\/em>)\s*["\']\s*<\/b>/i', '<b>$1</b>', $item);
            $item = preg_replace('/<b>\s*["\']([^<]+)["\']\s*<\/b>/i', '<b>$1</b>', $item);
            $item = preg_replace('/(<\/(?:em|strong|b)>)["\']/', '$1', $item);
    
            // Remove quotes directly wrapping inline tags like <em> or <strong>
            $item = preg_replace('/"(<(?:em|strong)[^>]*>.*?<\/(?:em|strong)>)"/i', '$1', $item);
    
            return '<li>' . $item . '</li>';
        }, $desc);
    
        // Step 4: Clean quotes around inline <em> or <strong> tags outside of <li>
        $desc = preg_replace('/"(<(?:em|strong)[^>]*>.*?<\/(?:em|strong)>)"/i', '$1', $desc);
    
        // Step 5: Remove any stray quotes at the start/end of the description
        $desc = preg_replace('/^["\']\s*|\s*["\']$/i', '', $desc);
    
        // Step 6: Remove "Sidebar Location" column from tables
        $desc = preg_replace_callback('/<table.*?>.*?<\/table>/is', function ($table_match) {
            $table_html = $table_match[0];
    
            // Remove <th>Sidebar Location</th> and get the index
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $table_html);
    
            $xpath = new DOMXPath($dom);
            $header_ths = $xpath->query('//th');
            $sidebar_index = -1;
            foreach ($header_ths as $i => $th) {
                if (trim($th->textContent) === 'Sidebar Location') {
                    $sidebar_index = $i;
                    $th->parentNode->removeChild($th);
                    break;
                }
            }
    
            // Remove the corresponding <td> in each row
            if ($sidebar_index > -1) {
                $rows = $xpath->query('//tr');
                foreach ($rows as $row) {
                    $tds = $row->getElementsByTagName('td');
                    if ($tds->length > $sidebar_index) {
                        $td = $tds->item($sidebar_index);
                        if ($td) {
                            $row->removeChild($td);
                        }
                    }
                }
            }
    
            // Extract updated HTML
            $body = $dom->getElementsByTagName('body')->item(0);
            $new_table = '';
            foreach ($body->childNodes as $child) {
                $new_table .= $dom->saveHTML($child);
            }
    
            return $new_table;
        }, $desc);
    
        return $desc;
    }

    public function ajax_load_publishers() {
    
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'comicbooks_fetchers_data')) {
            wp_send_json_error(['message' => 'Invalid security token'], 400);
            wp_die();
        }
    
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $letter = isset($_POST['letter']) && $_POST['letter'] !== '' ? sanitize_text_field($_POST['letter']) : 'all';
        $per_page = 10;    

        $publisher_data = $this->get_publishers($name, $page, $per_page, false, $letter);
    
        if (empty($publisher_data['items'])) {     
            wp_send_json_success([
                'publishers' => [],
                'total' => $publisher_data['total'],
                'page' => $page,
                'max_pages' => ceil($publisher_data['total'] / $per_page)
            ]);
            wp_die();
        }
    
        foreach ($publisher_data['items'] as &$item) {
            if (isset($item['id'])) {
                $detailed_info = $this->get_publisher_info($item['id']);
                $item = [
                    'id' => (int)$item['id'],
                    'name' => $item['name'],
                    'image' => $detailed_info['image'] ?? '',
                    'desc' => $detailed_info['desc'] ?? '',
                    'founded' => $detailed_info['founded'] ?? ''
                ];
                usleep(300000); // 300ms delay to respect rate limits
            }
        }
        unset($item);   
  
    
        wp_send_json_success([
            'publishers' => $publisher_data['items'],
            'total' => $publisher_data['total'],
            'page' => $page,
            'max_pages' => ceil($publisher_data['total'] / $per_page)
        ]);
        wp_die();
    }
    public function ajax_load_publisher_info() {
    
        // Validate nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'comicbooks_fetchers_data')) {
      
            wp_send_json_error(['message' => 'Invalid security token'], 400);
            wp_die();
        }
    
        // Validate publisher_id
        $publisher_id = isset($_POST['publisher_id']) ? intval($_POST['publisher_id']) : 0;
        if ($publisher_id <= 0) {      
            wp_send_json_error(['message' => 'Invalid publisher ID'], 400);
            wp_die();
        }
    
        // Fetch publisher info
        $publisher_info = $this->get_publisher_info($publisher_id);
        if (empty($publisher_info) || !isset($publisher_info['name'])) {    
            wp_send_json_error(['message' => 'Publisher not found'], 404);
            wp_die();
        }    

        wp_send_json_success($publisher_info);
    } 

    public function ajax_load_issues() {
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');
        $title_id = isset($_POST['title_id']) ? intval($_POST['title_id']) : 0;
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $search = isset($_POST['search']) ? strtolower(trim($_POST['search'])) : '';
        $cache_key = "metron:ajax_issues:{$title_id}:{$page}:{$search}";
    
        error_log("ajax_load_issues: title_id=$title_id, page=$page, search=$search");
    
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            error_log("ajax_load_issues: Returning cached data for $cache_key");
            wp_send_json_success($cached);
            return;
        }
    
        $data = $this->get_series_issues($title_id, $page, $search);
        if (is_array($data) && isset($data['error'])) {
            error_log("ajax_load_issues: Failed for title_id=$title_id: " . $data['error']);
            wp_send_json_error(['message' => 'No issues available: ' . $data['error']]);
            return;
        }
    
        $series = $data['series'] ?? [];
        $issue_list_data = $data['issue_list'] ?? [];
        $all_issues = $issue_list_data['results'] ?? [];
        $total_issues = $issue_list_data['count'] ?? 0;
        $per_page = 10;
        $total_pages = ceil($total_issues / $per_page);
    
        error_log("ajax_load_issues: all_issues count=" . count($all_issues) . ", total_issues=$total_issues, sample=" . json_encode($all_issues[0] ?? [], JSON_PRETTY_PRINT));
    
        ob_start();
        $issue_template = defined('COMICBOOKS_FETCHER_PATH') 
            ? COMICBOOKS_FETCHER_PATH . 'templates/issue-item-template.php'
            : trailingslashit(WP_PLUGIN_DIR) . 'comic-book-fetcher/templates/issue-item-template.php';
    
        if (!file_exists($issue_template)) {
            error_log("ajax_load_issues: Issue template file not found at: $issue_template");
            echo '<p class="no-results">Error: Issue template file not found.</p>';
            $issues_html = ob_get_clean();
            wp_send_json_error(['message' => 'Issue template file not found']);
            return;
        }
    
        $rendered_issues = 0;
        if (empty($all_issues) || !is_array($all_issues)) {
            error_log("ajax_load_issues: No issues found or invalid issue data for title_id=$title_id");
            echo '<p class="no-results">No issues found for this series.</p>';
        } else {
            echo '<ul class="issues-list">';
            foreach ($all_issues as $index => $issue) {
                if (!isset($issue['id'])) {
                    error_log("ajax_load_issues: Skipping invalid issue at index=$index for title_id=$title_id: " . json_encode($issue, JSON_PRETTY_PRINT));
                    continue;
                }
                error_log("ajax_load_issues: Processing issue ID=" . ($issue['id'] ?? 'N/A'));
                include $issue_template;
                $rendered_issues++;
            }
            echo '</ul>';
            if ($rendered_issues === 0) {
                error_log("ajax_load_issues: All issues skipped for title_id=$title_id");
                echo '<p class="no-results">No valid issues available for this series.</p>';
            }
        }
    
        $issues_html = ob_get_clean();
    
        $response = [
            'issues' => $issues_html,
            'total_issues' => $total_issues,
            'current_page' => $page,
            'total_pages' => $total_pages,
            'per_page' => $per_page
        ];
    
        set_transient($cache_key, $response, 2 * WEEK_IN_SECONDS);
        wp_send_json_success($response);
    }
    
    public function ajax_load_series_image() {
        $series_id = intval($_POST['series_id'] ?? 0);
        if (!$series_id) {
            error_log('Missing series ID in ajax_load_series_image');
            wp_send_json_error(['message' => 'Missing series ID']);
        }    
        $issue_cache_key = "metron:issue_list:$series_id";
        $issue_data = get_transient($issue_cache_key);
    
        if ($issue_data === false) {   
            $issue_data = $this->api_get($this->api_base . "series/$series_id/issue_list/?per_page=1");       

            if ($issue_data && !empty($issue_data['results'])) {
                set_transient($issue_cache_key, $issue_data, $this->dataset_ttl * 4); // 4 weeks
            } else {
                error_log("No results found in API response for series ID $series_id");
            }
        } else {
            error_log("Cache hit for key: $issue_cache_key");
        }    
        $image = $issue_data['results'][0]['image'] ?? '';
        if (!$image) {
            error_log("No image found for series ID $series_id");
        } else {
            error_log("Image found: $image");
        }    
        wp_send_json_success(['image' => $image]);
    }

    public function ajax_load_series_images_batch() {
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');
        $series_ids = isset($_POST['series_ids']) ? array_map('intval', (array)$_POST['series_ids']) : [];
        if (empty($series_ids)) {
            wp_send_json_error(['message' => 'No series IDs provided']);
            return;
        }
        $images = [];
        foreach ($series_ids as $series_id) {
            $issue_cache_key = "metron:issue_list:$series_id";
            $issue_data = get_transient($issue_cache_key);
            if ($issue_data === false) {
                $issue_data = $this->api_get($this->api_base . "series/$series_id/issue_list/?per_page=1");
                if ($issue_data && !empty($issue_data['results'])) {
                    set_transient($issue_cache_key, $issue_data, $this->dataset_ttl * 4);
                }
            }
            $images[$series_id] = $issue_data['results'][0]['image'] ?? '';
        }
        wp_send_json_success(['images' => $images]);
    }

    public function ajax_load_publisher_images_batch() {
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');
        
        $publisher_ids = isset($_POST['publisher_ids']) ? array_map('intval', (array)$_POST['publisher_ids']) : [];
        $images = [];
        
        foreach ($publisher_ids as $publisher_id) {
            $publisher_info = $this->get_publisher_info($publisher_id);
            if ($publisher_info && !empty($publisher_info['image'])) {
                $images[$publisher_id] = $publisher_info['image'];
            }
        }
        
        wp_send_json_success(['images' => $images]);
    }
    
    public function ajax_load_book() {
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');
    
        $publisher_id = isset($_POST['publisher_id']) ? intval($_POST['publisher_id']) : 0;
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $letter = isset($_POST['letter']) && $_POST['letter'] !== '' ? sanitize_text_field($_POST['letter']) : 'all';

        $series_data = $this->get_series($publisher_id, $page, $per_page, $name, $letter);
    
        wp_send_json_success([
            'series' => $series_data['items'],
            'total' => $series_data['total'],
            'per_page' => $series_data['per_page'],
            'page' => $page,
            'max_pages' => ceil($series_data['total'] / $per_page)
        ]);
    }
    public function check_collection_redirect() {
        
        if (is_page('comic-books/issue')) {
            $issue_id = isset($_GET['issue_id']) ? intval($_GET['issue_id']) : 0;
            $title_id = isset($_GET['title_id']) ? intval($_GET['title_id']) : 0;
    
            // Check if user is logged in and parameters are present
            if ($issue_id && $title_id && is_user_logged_in()) {
                $existing_posts = get_posts([
                    'post_type'      => 'post',
                    'author'         => get_current_user_id(),
                    'posts_per_page' => 1,
                    'meta_query'     => [
                        [
                            'key'     => 'issue_id',
                            'value'   => $issue_id,
                            'compare' => '='
                        ]
                    ],
                    'fields' => 'ids',
                ]);
    
                if (!empty($existing_posts)) {
                    $collection_post_id = $existing_posts[0];
                    $collection_post_url = get_permalink($collection_post_id);
                    wp_redirect($collection_post_url);
                    exit; // Always call exit after wp_redirect
                }
            }
        }
    }
}


