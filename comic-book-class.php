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
        add_action('wp_ajax_load_publisher_info', [$this, 'ajax_load_publisher_info']);
        add_action('wp_ajax_nopriv_load_publisher_info', [$this, 'ajax_load_publisher_info']);
    }
    public function clean_cv_description($desc) {
        if (empty($desc)) {
            return '';
        }
    
        // Step 1: Remove <em> tags if they wrap the entire description
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
    
        // Step 6: Format with paragraph tags for non-<li> content
        $desc = wpautop($desc);
    
        // Step 7: Ensure no double <p> tags around <li> or <ol>
        $desc = preg_replace('/<p>(<(ol|ul|li)>.*?<\/\2>)<\/p>/is', '$1', $desc);
    
        // Step 8: Remove "Sidebar Location" column from tables
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
        error_log('[ajax_load_publishers] Called');
    
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'comicbooks_fetchers_data')) {
            error_log('[ajax_load_publishers] ERROR: Invalid or missing nonce');
            wp_send_json_error(['message' => 'Invalid security token'], 400);
            wp_die();
        }
    
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $letter = isset($_POST['letter']) && $_POST['letter'] !== '' ? sanitize_text_field($_POST['letter']) : 'all';
        $per_page = 10;
    
        error_log("[ajax_load_publishers] Fetching publishers: name='$name', page=$page, letter='$letter'");
    
        $publisher_data = $this->get_publishers($name, $page, $per_page, false, $letter);
    
        if (empty($publisher_data['items'])) {
            error_log("[ajax_load_publishers] WARNING: No publishers returned for page $page");
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
                usleep(100000); // 100ms delay to respect rate limits
            }
        }
        unset($item);
    
        error_log("[ajax_load_publishers] SUCCESS: Returning " . count($publisher_data['items']) . " publishers, total=" . $publisher_data['total']);
    
        wp_send_json_success([
            'publishers' => $publisher_data['items'],
            'total' => $publisher_data['total'],
            'page' => $page,
            'max_pages' => ceil($publisher_data['total'] / $per_page)
        ]);
        wp_die();
    }
    public function ajax_load_publisher_info() {
        error_log('[ajax_load_publisher_info] Called with POST data: ' . json_encode($_POST));
    
        // Validate nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'comicbooks_fetchers_data')) {
            error_log('[ajax_load_publisher_info] ERROR: Invalid or missing nonce: ' . ($_POST['nonce'] ?? 'none'));
            wp_send_json_error(['message' => 'Invalid security token'], 400);
            wp_die();
        }
    
        // Validate publisher_id
        $publisher_id = isset($_POST['publisher_id']) ? intval($_POST['publisher_id']) : 0;
        if ($publisher_id <= 0) {
            error_log('[ajax_load_publisher_info] ERROR: Invalid or missing publisher_id: ' . ($_POST['publisher_id'] ?? 'none'));
            wp_send_json_error(['message' => 'Invalid publisher ID'], 400);
            wp_die();
        }
    
        // Fetch publisher info
        $publisher_info = $this->get_publisher_info($publisher_id);
        if (empty($publisher_info) || !isset($publisher_info['name'])) {
            error_log("[ajax_load_publisher_info] ERROR: No publisher info found for publisher_id=$publisher_id");
            wp_send_json_error(['message' => 'Publisher not found'], 404);
            wp_die();
        }
    
        error_log("[ajax_load_publisher_info] SUCCESS: Publisher info retrieved for publisher_id=$publisher_id");
        wp_send_json_success($publisher_info);
    } 
    public function ajax_load_issues() {
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');

        $title_id = isset($_POST['title_id']) ? intval($_POST['title_id']) : 0;
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $search = isset($_POST['search']) ? strtolower(trim($_POST['search'])) : '';
        error_log(date('c') . " ajax_load_issues: title_id=$title_id, page=$page, search='$search'");
    
        if (!$title_id) {
            error_log(date('c') . " ajax_load_issues: Invalid title_id");
            wp_send_json_error(['message' => 'Invalid series ID']);
            return;
        }
    
        set_time_limit(60);
    
        $data = $this->get_series_issues($title_id, $page, $search); // Pass search to get_series_issues
        if (!$data || !$data['issue_list'] || empty($data['issue_list']['results'])) {
            error_log(date('c') . " ajax_load_issues: No issues found for title_id=$title_id, page=$page");
            wp_send_json_error(['message' => 'No issues found for this series']);
            return;
        }
    
        $all_issues = $data['issue_list']['results'];
        $series = $data['series'];
        $total_issues = $data['issue_list']['count']; // Use full count from get_series_issues
        error_log(date('c') . " ajax_load_issues: total_issues=$total_issues, results_count=" . count($all_issues));
    
        // Batch fetch images
        $series_ids = array_column($all_issues, 'series_id');
        $images = $this->get_series_images($series_ids);

        // Check execution time to avoid timeout
        $start_time = microtime(true);
    
        ob_start();
        ?>
        <ul class="issues-list">
        <?php foreach ($all_issues as $issue): ?>
            <?php       
            if (microtime(true) - $start_time > 50) {
                wp_send_json_error(['message' => 'Request timed out due to API rate limits']);
                return;
            }        
            ?>
            <li class="issue-item">
                <a href="<?php echo esc_url(add_query_arg(['issue_id' => $issue['id'], 'title_id' => $title_id], home_url('/comic-books/issue/'))); ?>" class="issue-link">
                    <?php if (!empty($issue['image'])): ?>
                        <img src="<?php echo esc_url($issue['image']); ?>" alt="<?php echo esc_attr($issue['issue'] ?? 'Issue Cover'); ?>" class="issue-image" loading="lazy">
                    <?php else: ?>
                        <img src="<?php echo esc_url(PUBLISHER_PLACEHOLDER_IMAGE_URL); ?>" alt="Placeholder" class="issue-image" loading="lazy">
                    <?php endif; ?>
                    <div class="issue-info">
                        <h5>#<?php echo esc_html($issue['number'] ?? 'N/A') . ' &mdash; ' . esc_html($issue['series']['name'] ?? 'Unknown'); ?></h5>
                        <?php
                        $date = $issue['cover_date'];
                        $formatted = (!empty($date) && strtotime($date)) ? date('F Y', strtotime($date)) : 'N/A';
                        ?>
                        <h6><?php echo esc_html($formatted); ?></h6>
                        <?php
                        $metron_id = $issue['id'];
                        $cv_info = null;
                        $issue_url = "{$this->api_base}issue/{$metron_id}/";
                        $issue_info= $this->api_get($issue_url);
                        $metron_cv_id = $this->get_metron_cv_id($metron_id);             

                        if (!empty($metron_cv_id)) {
                            $cv_info = $this->get_comicvine_issue_info($metron_cv_id);
                        }

                        $description = '';
                        if (!empty($cv_info['description'])) {
                            $description = $this->clean_cv_description($cv_info['description']);
                        } elseif (!empty($issue['desc'])) {
                            $description = $this->clean_cv_description($issue['desc']);
                        }
                        if (!empty($description)) {
                            $description = wpautop($description);
                        }

                        $creators = !empty($cv_info['person_credits']) ? $cv_info['person_credits'] : ($issue['credits'] ?? []);
                        $creator_infos = [];
                        foreach ($creators as $person) {
                            $name = $person['name'] ?? $person['creator'] ?? 'Unknown';
                            $role = is_array($person['role']) ? implode(', ', array_column($person['role'], 'name')) : ($person['role'] ?? 'N/A');
                            $creator_infos[] = $name . ' – ' . $role;
                        }
                        $creator_info_string = implode('; ', $creator_infos);

                        $genre_sources = [];
                        $genres_origin = 'metron';
                        if (!empty($series['genres']) && is_array($series['genres'])) {
                            $genre_sources = array_column($series['genres'], 'name');
                        } elseif (!empty($cv_info['concept_credits']) && is_array($cv_info['concept_credits'])) {
                            $genre_sources = array_column($cv_info['concept_credits'], 'name');
                            $genres_origin = 'cv';
                        }
                        $genre_string = implode(', ', $genre_sources);

                        if (!empty($cv_info['_highlights'])) {
                            echo '<div class="cv-highlights">';
                            foreach ($cv_info['_highlights'] as $note) {
                                echo '<p class="cv-note">' . esc_html($note) . '</p>';
                            }
                            echo '</div>';
                        }
                        ?>
                        </div>
                    </a>
                    <?php            

                    $in_collection = false;
                    $collection_post_id = 0;
                    $issue_id = $metron_id;
                    $issue_title = $issue_info ? $issue_info['series']['name'] . ' #' . $issue_info['number'] : ($cv_info['metron']['issue'] ?? 'Unknown');
                    $date_raw = $cv_info['cover_date'] ?? $issue_info['cover_date'] ?? '';
    
                    if (is_user_logged_in()) {
                        $existing_posts = get_posts([
                            'post_type' => 'post',
                            'author' => get_current_user_id(),
                            'posts_per_page' => 1,
                            'meta_query' => [
                                [
                                    'key' => 'issue_id',
                                    'value' => $issue_id,
                                    'compare' => '='
                                ]
                            ],
                            'fields' => 'ids',
                        ]);
                        if (!empty($existing_posts)) {
                            $in_collection = true;
                            $collection_post_id = $existing_posts[0];
                        }
                    }    
                    if (is_user_logged_in()): ?>
                        <div class="d-flex flex-nowrap align-items-end gap-3">
                            <div class="text-center">
                                <button 
                                    class="add-to-collection <?php echo $in_collection ? 'in-collection' : ''; ?>" 
                                    style="<?php echo $in_collection ? 'background-color: red; color: white;' : ''; ?>"
                                    data-title="<?php echo esc_attr($issue_title); ?>"
                                    data-genres="<?php echo esc_attr($genre_string); ?>"
                                    data-genre-origin="<?php echo esc_attr($genres_origin); ?>"
                                    data-description="<?php echo esc_html($description ?? $cleaned_description); ?>"
                                    data-issue-id="<?php echo esc_attr($issue_id); ?>"
                                    data-title-id="<?php echo esc_attr($title_id); ?>"
                                    data-publisher="<?php echo esc_attr($series['publisher']['name'] ?? 'Unknown'); ?>"
                                    data-creators="<?php echo esc_attr($creator_info_string); ?>"
                                    data-date="<?php echo esc_attr($date_raw); ?>"
                                    data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
                                    data-issue-number="<?php echo esc_attr($issue['number']); ?>"
                                    data-image-url="<?php echo esc_url($issue['image']); ?>"
                                    <?php if ($in_collection): ?>
                                        data-post-id="<?php echo esc_attr($collection_post_id); ?>"
                                        data-action="remove"
                                    <?php else: ?>
                                        data-action="add"
                                    <?php endif; ?>>
                                    <?php echo $in_collection ? 'Remove from Collection' : 'Add to My Collection'; ?>
                                </button>
                            </div>
                            <button 
                                class="add-to-wishlist"
                                data-type="post"
                                data-item-id="<?php echo esc_attr($metron_cv_id); ?>"
                                data-title="<?php echo esc_attr($issue_title); ?>"
                                data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
                                data-item-url="<?php echo esc_url(add_query_arg(['issue_id' => $issue_id, 'title_id' => $title_id], site_url('/comic-books/issue/'))); ?>"
                                data-image-url="<?php echo esc_url($issue['image']); ?>">
                                Add to Wishlist
                            </button>
                        </div>
                    <?php endif; ?>
                    </li>
        <?php endforeach; ?>
    </ul>
    <?php
        $issues_html = ob_get_clean();

        error_log(date('c') . " ajax_load_issues: Sending response - total_issues=$total_issues, current_page=$page");

        wp_send_json_success([
            'issues' => $issues_html,
            'total_issues' => $total_issues,
            'current_page' => $page,
        ]);
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
            error_log("Cache miss for key: $issue_cache_key. Making API call...");
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
    
    public function ajax_load_book() {
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');
    
        $publisher_id = isset($_POST['publisher_id']) ? intval($_POST['publisher_id']) : 0;
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $letter = isset($_POST['letter']) && $_POST['letter'] !== '' ? sanitize_text_field($_POST['letter']) : 'all';
    
        error_log("load_book params: publisher_id=$publisher_id, page=$page, per_page=$per_page, name=$name, letter=$letter");    
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


