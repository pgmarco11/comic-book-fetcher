<?php
/**
 * ComicRenderer – Renders comic book pages.
 * Uses ComicDataService for data, focuses on HTML/UX.
 */
class ComicRenderer {
    /** @var ComicDataService */
    private $data_service;
 
    public function __construct() {
        $client             = new MetronClient();
        $this->data_service = new ComicDataService( $client ); 
    }

    /* -----------------------------------------------------------------
     *  PUBLISHERS
     * ----------------------------------------------------------------- */
    public function get_publishers( $name = '', $page = 1, $per_page = 50, $letter = 'all', $bypass_cache = false ) {
        return $this->data_service->get_publishers( $name, $page, $per_page, $bypass_cache, $letter );
    }

    public function get_publisher_info( $publisher_id ) {
        return $this->data_service->get_publisher_info( $publisher_id );
    }

   
    public function get_enriched_publishers( $page = 1, $per_page = 50, $letter = 'all', $bypass_cache = false ) {
        $key = "metron:publishers:enriched:{$page}:{$per_page}:{$letter}";

        error_log("ENRICHED PUBLISHERS → key=$key | bypass_cache=" . ($bypass_cache ? 'YES' : 'NO'));
    
        // Only bypass cache when explicitly asked (e.g. admin “refresh” button)    
        if ( ! $bypass_cache ) {
            $cached = get_transient( $key );
            if ( $cached !== false ) {
                error_log("CACHE HIT → returning cached data with " . count($cached['items'] ?? []) . " items");
                return $cached;
            }
            error_log("CACHE MISS (or page>1) → will call API");
        } else {
            error_log("CACHE BYPASSED → forcing API call");
        }
    
        // No cache (or forced bypass) → fetch from API
        $raw = $this->data_service->get_publishers( '', $page, $per_page, false, $letter );
    
        $items = [];
        foreach ( $raw['items'] ?? [] as $pub ) {
            $info    = $this->data_service->get_publisher_info( $pub['id'] );
            $items[] = [
                'id'      => $info['id'] ?? $pub['id'],
                'name'    => $info['name'] ?? $pub['name'],
                'image'   => $info['image'] ?? PUBLISHER_PLACEHOLDER_IMAGE_URL,
                'founded' => $info['founded'] ?? '',
                'desc'    => $info['desc'] ?? '',
            ];
        }
    
        $result = [
            'items' => $items,
            'total' => $raw['total'] ?? 0,
        ];
    
        // Cache for 12 hours (same as before)
        set_transient( $key, $result, 12 * HOUR_IN_SECONDS );
        error_log("CACHE SAVED → $key with " . count($result['items'] ?? []) . " items");
        
        return $result;
    }

    /* -----------------------------------------------------------------
     *  SERIES LIST (for a publisher)
     * ----------------------------------------------------------------- */
    public function get_series(
        $publisher_id,
        $page      = 1,
        $per_page  = 50,
        $search    = '',
        $letter    = 'all',
        $force_api = false
    ) {
        return $this->data_service->get_series( $publisher_id, $page, $per_page, $search, $letter, $force_api );
    }
    
    /* -----------------------------------------------------------------
     *  SERIES ISSUES
     * ----------------------------------------------------------------- */
    public function get_series_issues( $title_id, $page = 1, $search = '' ) {
        $data = $this->data_service->get_series_issues( $title_id, $page, $search );
    
        // ADD DEBUG
        error_log("get_series_issues($title_id, $page, '$search') => " . print_r($data, true));
    
        return $data;
    }


    // === CACHE WARM-UP ===
    public function schedule_cache_warm($publisher_id) {
        $hook = "warm_series_cache_{$publisher_id}";
        if (!wp_next_scheduled($hook)) {
            wp_schedule_single_event(time() + 10, $hook, [$publisher_id]);
            add_action($hook, [$this, 'warm_series_cache_background']);
        }
    }

    public function warm_series_cache_background($publisher_id) {
        $this->get_series($publisher_id, 1, 10, '', 'all', true);
    }

    public function ajax_warm_series_cache() {
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');
        $publisher_id = intval($_POST['publisher_id'] ?? 0);
        if ($publisher_id > 0) {
            $this->get_series($publisher_id, 1, 10, '', 'all', true);
        }
        wp_send_json_success();
    }

    // === RENDER MAIN LIST PAGE ===
    public function render_template($initial_data = []) {
        $items = $initial_data['items'];
        $total = $initial_data['total'];
        $type = $initial_data['type'];
        $page = $initial_data['page'];
        $per_page = $initial_data['per_page'];
        $letter = $initial_data['letter'] ?? 'all';
        $selected_publisher = $initial_data['publisher_id'];
      
        // Hydrate JS
        wp_localize_script('comicbook-script', 'comicbooks_fetchers_data', [
            'items' => $items,
            'total' => $total,
            'type' => $type,
            'per_page' => $per_page,
            'page' => $page,
            'letter' => $letter,
            'publisher_id' => $selected_publisher,
            'search' => $initial_data['search'], // ← ADD THIS
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('comicbooks_fetchers_data'),
            'placeholder' => PUBLISHER_PLACEHOLDER_IMAGE_URL ?? '',
            'preload_enabled' => true,
        ]);

        

        if (defined('DOING_AJAX') && DOING_AJAX) ob_start();

        extract($initial_data);
        include plugin_dir_path(__FILE__) . 'templates/comic-catalog-template.php';

        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success(['html' => ob_get_clean()]);
        }
    }

    public function clean_cv_description($desc) {
        if (empty($desc)) return '';
    
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

    public function render_issue_details() {    
        $title_id = isset( $_GET['title_id'] ) ? (int) $_GET['title_id'] : 0;
        $issue_id = isset( $_GET['issue_id'] ) ? (int) $_GET['issue_id'] : 0;
    
        if ( ! $title_id || ! $issue_id ) {
            echo '<p>Required parameters missing (series or issue ID).</p>';
            return;
        } 
    
        // Pass everything to your template
        include plugin_dir_path( __FILE__ ) . 'templates/issue-details-template.php';
    }    
   

    /* -----------------------------------------------------------------
    / *  COLLECTION STATUS (user-owned issues)
    / * ----------------------------------------------------------------- */
    public function get_collection_status( $metron_ids ) {
        if ( ! is_user_logged_in() || empty( $metron_ids ) ) {
            return [];
        }

        $user_id = get_current_user_id();
        $placeholders = implode( ',', array_fill( 0, count( $metron_ids ), '%d' ) );
        $in = $metron_ids;

        global $wpdb;
        $table = $wpdb->prefix . 'comic_collection';

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
        $sql = $wpdb->prepare(
            "SELECT metron_id FROM `$table` WHERE user_id = %d AND metron_id IN ($placeholders)",
            array_merge( [ $user_id ], $in )
        );

        $owned = $wpdb->get_col( $sql ); // phpcs:ignore
        $status = [];
        foreach ( $metron_ids as $mid ) {
            $status[ $mid ] = in_array( $mid, $owned, true );
        }
        return $status;
    }

    /* -----------------------------------------------------------------
    / *  BATCH SERIES IMAGES (first issue)
    / * ----------------------------------------------------------------- */
    public function get_series_images( $series_ids ) {
        if ( empty( $series_ids ) || ! is_array( $series_ids ) ) {
            return [];
        }
        return $this->data_service->get_series_images( $series_ids );
    }

    /* -----------------------------------------------------------------
    / *  BATCH COMICVINE INFO (used in issue list)
    /* ----------------------------------------------------------------- */
    public function get_comicvine_issue_info_batch( $metron_ids ) {
        if ( empty( $metron_ids ) || ! is_array( $metron_ids ) ) {
            return [];
        }

        $results = [];
        foreach ( $metron_ids as $mid ) {
            $cv_id = $this->data_service->get_metron_cv_id( $mid );
            if ( $cv_id ) {
                $info = $this->data_service->get_comicvine_issue_info( $cv_id );
                if ( $info ) {
                    $results[ $mid ] = $info;
                }
            }
        }
        return $results;
    }

}