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
        $per_page  = 100,
        $search    = '',
        $letter    = 'all',
        $force_api = false
    ) {
        return $this->data_service->get_series( $publisher_id, $page, $per_page, $search, $letter, $force_api );
    }
    
    /** --------------------------------------------------------------
     *  SERIES ISSUES * Issue information
     * ----------------------------------------------------------------- */
    public function get_series_issues( $title_id, $page = 1, $search = '' ) {    
        $data = $this->data_service->get_series_issues( $title_id, $page, $search );       
        return $data;
    }
  

    // === RENDER MAIN LIST PAGE ===
    public function render_template($initial_data = []) {
        $items            = $initial_data['items']        ?? [];
        $total            = $initial_data['total']        ?? 0;
        $type             = $initial_data['type']         ?? '';
        $page             = $initial_data['page']         ?? 1;
        $per_page         = $initial_data['per_page']     ?? 10;
        $letter           = $initial_data['letter']       ?? 'all';
        $selected_publisher = $initial_data['publisher_id'] ?? 0;
        $search           = $initial_data['search']       ?? '';
    
        wp_add_inline_script(
            'comicbook-script',
            'window.comicbooks_fetchers_data = Object.assign(window.comicbooks_fetchers_data || {}, '
                . wp_json_encode([
                    'items'           => $items,
                    'total'           => $total,
                    'type'            => $type,
                    'per_page'        => $per_page,
                    'page'            => $page,
                    'letter'          => $letter,
                    'publisher_id'    => $selected_publisher,
                    'search'          => $search,
                    'preload_enabled' => false,
                ]) . ');',
            'before'
        );


        // "No data at all" — no filters applied yet, let JS bootstrap the first fetch
        $has_filters = $search || $selected_publisher || ( $letter && $letter !== 'all' );
        $data_was_fetched = isset( $initial_data['items'] ); // key exists = a real query ran

        if ( ! $data_was_fetched && ! $has_filters ) {
            // Nothing fetched and no filters → show spinner, JS will fetch
            if ( defined('DOING_AJAX') && DOING_AJAX ) ob_start();
            ?>
            <div id="loading-spinner" class="spinner-overlay" aria-live="polite" aria-label="Loading content">
                <div class="spinner"></div>
                <p>Loading...</p>
            </div>
            <div id="book-container"></div>
            <?php
            if ( defined('DOING_AJAX') && DOING_AJAX ) {
                wp_send_json_success(['html' => ob_get_clean()]);
            }
            return;
        }

        // Normal render with data
        if (defined('DOING_AJAX') && DOING_AJAX) ob_start();

        extract($initial_data);
        include plugin_dir_path(__FILE__) . 'templates/comic-catalog-template.php';

        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success(['html' => ob_get_clean()]);
        }
    }

    public function get_single_issue( $title_id, $issue_id ) {
        if ( empty( $title_id ) || empty( $issue_id ) || $title_id <= 0 || $issue_id <= 0 ) {
            return null;
        }
        return $this->data_service->get_single_issue( $title_id, $issue_id );
    }

    public function get_metron_cv_id( $metron_id ) {
        if ( empty( $metron_id ) || $metron_id <= 0 ) {
            return null;
        }
        return $this->data_service->get_metron_cv_id( $metron_id );
    }
    

    public function get_comicvine_issue_info( $cv_id ) {
        if ( ! $cv_id ) {
            return null;
        }     
        return $this->data_service->get_comicvine_issue_info( $cv_id );
    }

    public function clean_cv_description($desc) {
        return $this->data_service->clean_cv_description($desc);
    }
   

    /* -----------------------------------------------------------------
    / *  COLLECTION STATUS (user-owned issues)
    / * ----------------------------------------------------------------- */
    public static function get_collection_status( $metron_ids ) {
        if ( ! is_user_logged_in() || empty( $metron_ids ) ) {
            return [];
        }
    
        $user_id = get_current_user_id();
    
        $cache_key = 'user_collection:' . $user_id . ':' . md5( implode( ',', $metron_ids ) );
        $cached = wp_cache_get( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }
    
        $placeholders = implode( ',', array_fill( 0, count( $metron_ids ), '%d' ) );
    
        global $wpdb;
    
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
        $sql = $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS issue_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                     ON pm.post_id = p.ID AND pm.meta_key = 'issue_id'
             WHERE p.post_type   = 'collection'
               AND p.post_author = %d
               AND p.post_status = 'publish'
               AND pm.meta_value IN ($placeholders)",
            array_merge( [ $user_id ], $metron_ids )
        );
    
        $rows = $wpdb->get_results( $sql ); // phpcs:ignore
    
        $owned_map = [];
        foreach ( (array) $rows as $row ) {
            $owned_map[ (int) $row->issue_id ] = (int) $row->ID;
        }
    
        $status = [];
        foreach ( $metron_ids as $mid ) {
            $status[ $mid ] = [
                'owned'   => isset( $owned_map[ $mid ] ),
                'post_id' => $owned_map[ $mid ] ?? 0,
            ];
        }
    
        wp_cache_set( $cache_key, $status );
    
        return $status;
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