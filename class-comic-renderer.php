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
    public function get_publishers( 
        $name = '', 
        $page = 1, 
        $per_page = 50, 
        $letter = 'all', 
        $bypass_cache = false ) 
    {
        return $this->data_service->get_publishers( $name, $page, $per_page, $letter, $bypass_cache );
    }

    public function get_publisher_info( $publisher_id ) {
        return $this->data_service->get_publisher_info( $publisher_id );
    }

   
    public function get_enriched_publishers( $page = 1, $per_page = 50, $letter = 'all', $bypass_cache = false ) {
        $result = $this->data_service->get_enriched_publishers( $page, $per_page, $letter, $bypass_cache );
        return $result;
    }

    /** -----------------------------------------------------------------
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
    
    /** -----------------------------------------------------------------
     *  SERIES ISSUES
     * ----------------------------------------------------------------- */
    public function get_series_issues( $title_id, $page = 1, $search = '' ) {
        $data = $this->data_service->get_series_issues( $title_id, $page, $search );
    
        // ADD DEBUG
        error_log("get_series_issues($title_id, $page, '$search') => " . print_r($data, true));
    
        return $data;
    }

    public function build_cv_map_for_series( $series_id, $page = 1) {

        $map = $this->data_service->build_cv_map_for_series( $series_id, $page );
        
        return $map;
    }

/*
    // === Scheduled CACHE WARM-UP ===
    public function schedule_cache_warm($publisher_id) {
        $hook = "warm_series_cache_{$publisher_id}";
        if (!wp_next_scheduled($hook)) {
            wp_schedule_single_event(time() + 10, $hook, [$publisher_id]);   
        }
    }

    public function ajax_warm_series_cache() {
        
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');
    
        $errors = [];
        $service = new ComicDataService(new MetronClient());
    
        $publisher_info = $service->get_enriched_publishers(1, 50, 'all', true);
   
    
        foreach ($publisher_info['items'] as $index => $pub) {
            $pub_id = $pub['id'];
    
            try {
                // Only full series for first publisher, batch-aware
                if ($index === 0) {
                    $service->get_series($pub_id, 1, 50, '', 'all', true, 5);                        
                }
                sleep(3); // 3 seconds delay between publishers to avoid rate limits      
    
            } catch (Exception $e) {
                $errors[] = "Publisher ID $pub_id: " . $e->getMessage();
            }
        }
    
        if (!empty($errors)) {
            error_log("Cache warm errors: " . implode(', ', $errors));
        }
    
        wp_send_json_success(['errors' => $errors]);
    }
*/
    // === RENDER MAIN LIST PAGE ===
    public function render_template($initial_data = []) {
        $items = $initial_data['items'] ?? [];
        $total = $initial_data['total'] ?? 0;
        $type = $initial_data['type'];
        $page = $initial_data['page'] ?? 1; // Default to 1 if not set
        $per_page = $initial_data['per_page'] ?? 10; // Default to 10 if not set
        $letter = $initial_data['letter'] ?? 'all';
        $selected_publisher = $initial_data['publisher_id'] ?? 0;
      
        // Hydrate JS
        wp_localize_script('comicbook-script', 'comicbooks_fetchers_data', [
            'items' => $items,
            'total' => $total,
            'type' => $type,
            'per_page' => $per_page,
            'page' => $page,
            'letter' => $letter,
            'publisher_id' => $selected_publisher,
            'search' => $initial_data['search'] ?? '', // ← ADD THIS
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('comicbooks_fetchers_data'),
            'placeholder' => PUBLISHER_PLACEHOLDER_IMAGE_URL ?? '',
            'preload_enabled' => false,
        ]);

        

        if (defined('DOING_AJAX') && DOING_AJAX) ob_start();

        extract($initial_data);
        include plugin_dir_path(__FILE__) . 'templates/comic-catalog-template.php';

        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success(['html' => ob_get_clean()]);
        }
    }

    public function clean_cv_description($desc) {
        return $this->data_service->clean_cv_description($desc);
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
    public static function get_collection_status( $metron_ids ) {
        if ( ! is_user_logged_in() || empty( $metron_ids ) ) {
            return [];
        }
    
        $user_id = get_current_user_id();
        
        // Cache the result to avoid repeated DB hits on same page load
        $cache_key = 'user_collection:' . $user_id . ':' . md5( implode( ',', $metron_ids ) );
        $cached = wp_cache_get( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }
    
        $placeholders = implode( ',', array_fill( 0, count( $metron_ids ), '%d' ) );
    
        global $wpdb;
        $table = $wpdb->prefix . 'comic_collection';
    
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
        $sql = $wpdb->prepare(
            "SELECT metron_id FROM `$table` WHERE user_id = %d AND metron_id IN ($placeholders)",
            array_merge( [ $user_id ], $metron_ids )
        );
    
        $owned = $wpdb->get_col( $sql ); // phpcs:ignore
        $status = [];
        foreach ( $metron_ids as $mid ) {
            $status[ $mid ] = in_array( $mid, $owned, true );
        }
        
        // Cache for this page load
        wp_cache_set( $cache_key, $status );
        
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

    /**
    * Render issue details using ACF fields.
    *
    * Displays structured collection meta for the current post.
    */
    public function render_issue_details_from_acf(): void
    {
        if (!function_exists('get_field')) {
                echo '<p>ACF not active.</p>';
                return;
        }
        $fields = [
                'condition'      => 'Condition',
                'date_published' => 'Published',
                'volume'         => 'Volume',
                'issue_number'   => 'Issue #',
                'qty'            => 'Quantity',
                'price'          => 'Price',
                'creators'       => 'Creators',
                'genres'         => 'Genres',
                'notes'          => 'Notes',
        ];
        echo '<div class="collection-details">';
            foreach ($fields as $key => $label) {
                $value = get_field($key);

                if (!empty($value)) {
                    echo '<div class="collection-field">';
                    echo '<strong>' . esc_html($label) . ':</strong> ';
                    echo esc_html($value);
                    echo '</div>';
    }
            }
        echo '</div>';
    }
}