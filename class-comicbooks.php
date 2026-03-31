<?php
/**
 * Comicbooks – AJAX handler for the Comic Books Fetcher plugin.
 *
 * Handles every AJAX request (publishers, series, images, issues, etc.).
 * Uses ComicDataService for all data fetching – no inheritance.
 *
 * @package ComicBooksFetcher
 * @since   1.0.0
 * @author  Peter Giammarco
 */
class Comicbooks {

    /** @var ComicDataService */
    private $data_service;

    public function __construct() {
        // -----------------------------------------------------------------
        // 1. Initialise the data service (MetronClient → ComicDataService)
        // -----------------------------------------------------------------
        $client               = new MetronClient();
        $this->data_service   = new ComicDataService( $client );

        // -----------------------------------------------------------------
        // 2. Register AJAX actions
        // -----------------------------------------------------------------
        add_action( 'template_redirect', [ $this, 'check_collection_redirect' ] );

        add_action('wp_ajax_load_publishers', [$this, 'ajax_load_publishers']);
        add_action('wp_ajax_nopriv_load_publishers', [$this, 'ajax_load_publishers']);

        add_action('wp_ajax_load_book', [$this, 'ajax_load_book']);
        add_action('wp_ajax_nopriv_load_book', [$this, 'ajax_load_book']);

        add_action( 'wp_ajax_load_issues',             [ $this, 'ajax_load_issues' ] );
        add_action( 'wp_ajax_nopriv_load_issues',      [ $this, 'ajax_load_issues' ] );

        add_action( 'wp_ajax_load_comic_vine_batch',   [ $this, 'ajax_load_comic_vine_batch' ] );
        add_action( 'wp_ajax_nopriv_load_comic_vine_batch', [ $this, 'ajax_load_comic_vine_batch' ] );

        add_action( 'wp_ajax_load_series_image',       [ $this, 'ajax_load_series_image' ] );
        add_action( 'wp_ajax_nopriv_load_series_image',[ $this, 'ajax_load_series_image' ] );

        add_action( 'wp_ajax_load_series_images_batch',   [ $this, 'ajax_load_series_images_batch' ] );
        add_action( 'wp_ajax_nopriv_load_series_images_batch',[ $this, 'ajax_load_series_images_batch' ] );

        add_action( 'wp_ajax_load_publisher_images_batch',[ $this, 'ajax_load_publisher_images_batch' ] );
        add_action( 'wp_ajax_nopriv_load_publisher_images_batch',[ $this, 'ajax_load_publisher_images_batch' ] );

        add_action( 'wp_ajax_load_publisher_info',     [ $this, 'ajax_load_publisher_info' ] );
        add_action( 'wp_ajax_nopriv_load_publisher_info',[ $this, 'ajax_load_publisher_info' ] );
    }

    
         /* -----------------------------------------------------------------
     *  AJAX – Publishers (list + detailed info)
     * ----------------------------------------------------------------- */
    public function ajax_load_publishers() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'comicbooks_fetchers_data' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 400 );
        }

        $name     = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
        $page     = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
        $letter   = isset( $_POST['letter'] ) && $_POST['letter'] !== '' ? sanitize_text_field( $_POST['letter'] ) : 'all';
        $per_page = 10;

        $publisher_data = $this->data_service->get_publishers( $name, $page, $per_page, $letter. false );

        if ( empty( $publisher_data['items'] ) ) {
            wp_send_json_success( [
                'publishers' => [],
                'total'      => $publisher_data['total'],
                'page'       => $page,
                'max_pages'  => ceil( $publisher_data['total'] / $per_page ),
            ] );
        }

        foreach ( $publisher_data['items'] as &$item ) {
            if ( ! empty( $item['id'] ) ) {
                $info = $this->data_service->get_publisher_info( $item['id'] );
                $item = [
                    'id'      => (int) $item['id'],
                    'name'    => $item['name'],
                    'image'   => $info['image'] ?? '',
                    'desc'    => $info['desc'] ?? '',
                    'founded' => $info['founded'] ?? '',
                ];
               sleep(2); // respect rate limits
            }
        }
        unset( $item );

        wp_send_json_success( [
            'publishers' => $publisher_data['items'],
            'total'      => $publisher_data['total'],
            'page'       => $page,
            'per_page'   => 10,
            'max_pages'  => ceil( $publisher_data['total'] / $per_page ),
        ] );
    }

     /* -----------------------------------------------------------------
     *  AJAX – Load a page of series (books) for a publisher
     * ----------------------------------------------------------------- */
    public function ajax_load_book() {
        check_ajax_referer( 'comicbooks_fetchers_data', 'nonce' );

        $publisher_id = isset( $_POST['publisher_id'] ) ? intval( $_POST['publisher_id'] ) : 0;
        $page         = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
        $per_page     = isset( $_POST['per_page'] ) ? intval( $_POST['per_page'] ) : 10;
        $name         = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
        $letter       = isset( $_POST['letter'] ) && $_POST['letter'] !== '' ? sanitize_text_field( $_POST['letter'] ) : 'all';

        $series_data = $this->data_service->get_series( $publisher_id, $page, $per_page, $name, $letter );

        wp_send_json_success( [
            'series'    => $series_data['items'],
            'total'     => $series_data['total'],
            'per_page'  => $series_data['per_page'],
            'page'      => $page,
            'max_pages' => ceil( $series_data['total'] / $per_page ),
        ] );
    }


    public function ajax_load_publisher_info() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'comicbooks_fetchers_data' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 400 );
        }

        $publisher_id = isset( $_POST['publisher_id'] ) ? intval( $_POST['publisher_id'] ) : 0;
        if ( $publisher_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid publisher ID' ], 400 );
        }

        $info = $this->data_service->get_publisher_info( $publisher_id );
        if ( empty( $info ) || empty( $info['name'] ) ) {
            wp_send_json_error( [ 'message' => 'Publisher not found' ], 404 );
        }

        wp_send_json_success( $info );
    }

    /** -----------------------------------------------------------------
    * AJAX – Issues (for a series) – CLEAN VERSION
    * ----------------------------------------------------------------- */
    public function ajax_load_issues() {
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');
    
        $title_id = isset($_POST['title_id']) ? intval($_POST['title_id']) : 0;
        $page     = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $search   = isset($_POST['search']) ? strtolower(trim(wp_strip_all_tags($_POST['search']))) : '';
    
        if (!$title_id) {
            wp_send_json_error(['message' => 'No title_id provided']);
        }
    
        $comic_renderer = new ComicRenderer();
        $data = $comic_renderer->get_series_issues($title_id, $page, $search);
    
        if (isset($data['error'])) {
            wp_send_json_error($data);
        }
    
        // Render the exact same HTML as the template
        ob_start();
        $series = $data['series'] ?? [];
        $all_issues = $data['issue_list']['results'] ?? [];
        $total_issues = (int) ($data['issue_list']['count'] ?? 0);
    
        if (!empty($all_issues)) :
            ?>
            <ul class="issues-list">
                <?php 
                foreach ($all_issues as $issue) :
                    if (empty($issue['id'])) continue;
                    $metron_id = $issue['id'];
                    // If you have CV data preloaded, pass it here if needed
                    include plugin_dir_path(__FILE__) . 'templates/issue-item-template.php';
                endforeach; 
                ?>
            </ul>
            <?php
        else :
            ?>
            <p class="no-results">
                <?php if (!empty($search)) : ?>
                    No issues matching "<?php echo esc_html($search); ?>".
                <?php else : ?>
                    No issues found.
                <?php endif; ?>
            </p>
            <?php
        endif;
    
        $html = ob_get_clean();
    
        wp_send_json_success([
            'issues'       => $html,           // Full HTML fragment
            'page'         => $page,
            'total_issues' => $total_issues,
            'total_pages'  => ceil($total_issues / 10),
            'search'       => $search
        ]);
    }

    /* -----------------------------------------------------------------
    *  AJAX – Load Comic Vine data (non-blocking)
    * ----------------------------------------------------------------- */
    public function ajax_load_comic_vine_batch() {
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');
    
        $metron_ids = isset($_POST['metron_ids'])
            ? array_map('intval', explode(',', $_POST['metron_ids']))
            : [];
    
        if (empty($metron_ids)) {
            wp_send_json_error(['message'=>'No IDs provided']);
        }
    
        $cv_info_batch = [];
    
        // Fetch CV data for the requested IDs using series-level cache
        foreach ($metron_ids as $mid) {

            $cv_info_batch[$mid] = get_transient("cv_issue_full_{$mid}");
        
            if ($cv_info_batch[$mid] === false) {
                $cv_info_batch[$mid] = null; // fallback
            }
        }
        $collection_status = is_user_logged_in()
            ? ComicRenderer::get_collection_status($metron_ids)
            : [];
    
        wp_send_json_success([
            'cv_data'           => $cv_info_batch,
            'collection_status' => $collection_status,
        ]);
    }

    /* -----------------------------------------------------------------
     *  AJAX – Single series image
     * ----------------------------------------------------------------- */
    public function ajax_load_series_image() {
        $title_id = intval( $_POST['series_id'] ?? 0 );
        if ( ! $title_id ) {
            wp_send_json_error( [ 'message' => 'Missing series ID' ] );
        }

        $cache_key = "metron:issue_list_full:$title_id";
        $data      = get_transient( $cache_key );

        if ( $data === false ) {
            $client = $this->data_service->get_client();              
            $url    = $client->api_base . "series/$title_id/issue_list/?per_page=100";
            $data   = $client->api_get( $url );
            if ( $data && ! empty( $data['results'] ) ) {
                set_transient( $cache_key, $data, $client->dataset_ttl * 4 );
            }
        }

        $image = $data['results'][0]['image'] ?? '';
        wp_send_json_success( [ 'image' => $image ] );
    }

    /* -----------------------------------------------------------------
     *  AJAX – Batch series images
     * ----------------------------------------------------------------- */
    public function ajax_load_series_images_batch() {
        check_ajax_referer( 'comicbooks_fetchers_data', 'nonce' );

        $series_ids = isset( $_POST['series_ids'] ) ? array_map( 'intval', (array) $_POST['series_ids'] ) : [];
        if ( empty( $series_ids ) ) {
            wp_send_json_error( [ 'message' => 'No series IDs provided' ] );
        }

        $client = $this->data_service->get_client();                  
        $images = [];

        foreach ( $series_ids as $sid ) {
            $ck   = "metron:issue_list_full:$sid";
            $data = get_transient( $ck );

            if ( $data === false ) {
                $url  = $client->api_base . "series/$sid/issue_list/?per_page=100";
                $data = $client->api_get( $url );
                if ( $data && ! empty( $data['results'] ) ) {
                    set_transient( $ck, $data, $client->dataset_ttl * 4 );
                }
            }
            $images[ $sid ] = $data['results'][0]['image'] ?? '';
        }

        wp_send_json_success( [ 'images' => $images ] );
    }

    /* -----------------------------------------------------------------
     *  AJAX – Batch publisher images
     * ----------------------------------------------------------------- */
    public function ajax_load_publisher_images_batch() {
        check_ajax_referer( 'comicbooks_fetchers_data', 'nonce' );

        $publisher_ids = isset( $_POST['publisher_ids'] ) ? array_map( 'intval', (array) $_POST['publisher_ids'] ) : [];
        $images        = [];

        foreach ( $publisher_ids as $pid ) {
            $info = $this->data_service->get_publisher_info( $pid );
            $images[ $pid ] = $info['image'] ?? '';
        }

        wp_send_json_success( [ 'images' => $images ] );
    }

   

    /* -----------------------------------------------------------------
     *  Redirect logged-in users to their collection post if it exists
     * ----------------------------------------------------------------- */
    public function check_collection_redirect() {
        if ( ! is_page( 'comic-catalog/issue' ) ) {
            return;
        }

        $issue_id = isset( $_GET['issue_id'] ) ? intval( $_GET['issue_id'] ) : 0;
        $title_id = isset( $_GET['title_id'] ) ? intval( $_GET['title_id'] ) : 0;

        if ( $issue_id && $title_id && is_user_logged_in() ) {
            $posts = get_posts( [
                'post_type'      => 'post',
                'author'         => get_current_user_id(),
                'posts_per_page' => 1,
                'meta_query'     => [
                    [
                        'key'     => 'issue_id',
                        'value'   => $issue_id,
                        'compare' => '=',
                    ],
                ],
                'fields' => 'ids',
            ] );

            if ( ! empty( $posts ) ) {
                wp_redirect( get_permalink( $posts[0] ) );
                exit;
            }
        }
    }
}