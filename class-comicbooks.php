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

       /* add_action('wp_ajax_load_publishers', [$this, 'ajax_load_publishers']);
        add_action('wp_ajax_nopriv_load_publishers', [$this, 'ajax_load_publishers']);     

        add_action('wp_ajax_load_book', [$this, 'ajax_load_book']);
        add_action('wp_ajax_nopriv_load_book', [$this, 'ajax_load_book']);

        add_action( 'wp_ajax_load_issues',             [ $this, 'ajax_load_issues' ] );
        add_action( 'wp_ajax_nopriv_load_issues',      [ $this, 'ajax_load_issues' ] );

       add_action( 'wp_ajax_load_series_images_batch',   [ $this, 'ajax_load_series_images_batch' ] );
       add_action( 'wp_ajax_nopriv_load_series_images_batch',[ $this, 'ajax_load_series_images_batch' ] );

        add_action( 'wp_ajax_load_publisher_images_batch',[ $this, 'ajax_load_publisher_images_batch' ] );
        add_action( 'wp_ajax_nopriv_load_publisher_images_batch',[ $this, 'ajax_load_publisher_images_batch' ] );
        */

        $ajax_actions = [
            'load_publishers' => 'ajax_load_publishers',
            'load_book' => 'ajax_load_book',
            'load_issues' => 'ajax_load_issues',
            'load_series_images_batch' => 'ajax_load_series_images_batch',
            'load_publisher_images_batch' => 'ajax_load_publisher_images_batch',
        ];
        
        foreach ($ajax_actions as $action => $method) {
            $callback = function () use ($method) {
                MetronClient::enable_ajax_errors();
        
                try {
                    $this->$method();
                } catch (ComicApiTemporaryException $error) {
                    header('Retry-After: ' . $error->retry_after);
        
                    wp_send_json_error(
                        [
                            'message' => $error->getMessage(),
                            'temporary' => true,
                            'retry_after' => $error->retry_after,
                        ],
                        503
                    );
                } finally {
                    MetronClient::disable_ajax_errors();
                }
            };
        
            add_action(
                'wp_ajax_' . $action,
                $callback
            );
        
            add_action(
                'wp_ajax_nopriv_' . $action,
                $callback
            );
        }

        add_action( 'template_redirect', [ $this, 'check_collection_redirect' ] );

    }

    
         /* -----------------------------------------------------------------
     *  AJAX – Publishers (list + detailed info)
     * ----------------------------------------------------------------- */
    public function ajax_load_publishers() {
        check_ajax_referer(
            'comicbooks_fetchers_data',
            'nonce'
        );
    
        $name = sanitize_text_field(
            wp_unslash($_POST['name'] ?? '')
        );
    
        $page = max(
            1,
            absint($_POST['page'] ?? 1)
        );
    
        $letter = sanitize_text_field(
            wp_unslash($_POST['letter'] ?? 'all')
        );
    
        $letter = $letter !== '' ? $letter : 'all';
        $per_page = 10;
    
        $data = $this->data_service->get_publishers(
            $name,
            $page,
            $per_page,
            $letter,
            false
        );
    
        if (!$data['ready']) {
            header(
                'Retry-After: ' . $data['retry_after']
            );
    
            wp_send_json_success([
                'ready' => false,
                'publishers' => [],
                'total' => 0,
                'retry_after' => $data['retry_after'],
            ]);
        }
    
        $result = [
            'ready' => true,
            'stale' => $data['stale'],
            'publishers' =>
                $this->data_service->with_cached_catalog_details(
                    $data['items'],
                    'publishers'
                ),
            'total' => $data['total'],
            'page' => $page,
            'per_page' => $per_page,
            'max_pages' => (int) ceil(
                $data['total'] / $per_page
            ),
        ];
    
        if (!empty($_POST['include_options'])) {
            // Populate a dropdown that was empty during the initial build.
            $options = $this->data_service->get_publishers(
                '',
                1,
                PHP_INT_MAX,
                'all',
                false
            );
    
            $result['publisher_options'] = $options['items'];
        }
    
        wp_send_json_success($result);
    }

    
     /* -----------------------------------------------------------------
     *  AJAX – Load a page of series (books) for a publisher
     * ----------------------------------------------------------------- */
    public function ajax_load_book() {
        check_ajax_referer( 'comicbooks_fetchers_data', 'nonce' );

        $publisher_id = isset($_POST['publisher_id'])
            ? absint(wp_unslash($_POST['publisher_id']))
            : 0;
        
        $page = isset($_POST['page'])
            ? max(1, absint(wp_unslash($_POST['page'])))
            : 1;
        
        $per_page = isset($_POST['per_page'])
            ? absint(wp_unslash($_POST['per_page']))
            : 10;
        
        $name = isset($_POST['name'])
            ? sanitize_text_field(
                wp_unslash($_POST['name'])
            )
            : '';
        
        $letter = isset($_POST['letter'])
            ? sanitize_text_field(
                wp_unslash($_POST['letter'])
            )
            : 'all';
        
        $letter = $letter !== '' ? $letter : 'all';

        $series_data = $this->data_service->get_series( $publisher_id, $page, $per_page, $name, $letter );

        if (!empty($series_data['temporary_error'])) {
            wp_send_json_error(
                [
                    'message' =>
                        $series_data['temporary_error'],
                    'temporary' => true,
                ],
                503
            );
        }

        $series_data['items'] = $this->data_service->with_cached_catalog_details(
            $series_data['items'] ?? [],
            'books'
        );

        wp_send_json_success( [
            'series'         => $series_data['items'],
            'total'          => $series_data['total'],
            'is_total_exact' => $series_data['is_total_exact'] ?? true,
            'scan_complete'  => $series_data['scan_complete'] ?? true,
            'per_page'       => $series_data['per_page'],
            'page'           => $page,
            'max_pages'      => ceil( $series_data['total'] / $per_page ),
        ] );
    }




   

    /** -----------------------------------------------------------------
    * AJAX – Issues (for a series) – CLEAN VERSION
    * ----------------------------------------------------------------- */
    public function ajax_load_issues() {
        check_ajax_referer('comicbooks_fetchers_data', 'nonce');

        $title_id = isset($_POST['title_id'])
            ? absint($_POST['title_id'])
            : 0;
    
        $page = isset($_POST['page'])
            ? max(1, absint($_POST['page']))
            : 1;
    
        $search = isset($_POST['search'])
            ? strtolower(
                trim(
                    sanitize_text_field(
                        wp_unslash($_POST['search'])
                    )
                )
            )
            : '';
    
        if (!$title_id) {
            wp_send_json_error(
                ['message' => 'No title_id provided'],
                400
            );
        }
    
        $comic_renderer = new ComicRenderer();
        $data = $comic_renderer->get_series_issues(
            $title_id,
            $page,
            $search
        );
    
        if (isset($data['error'])) {
            wp_send_json_error(
                $data,
                !empty($data['temporary_error'])
                    ? 503
                    : 404
            );
        }
    
        $series = $data['series'] ?? [];
    
        $all_issues = isset($data['issue_list']['results'])
            && is_array($data['issue_list']['results'])
                ? $data['issue_list']['results']
                : [];
    
        $total_issues = (int) (
            $data['issue_list']['count'] ?? 0
        );
    
        $collection_status = [];
    
        $metron_ids = array_values(
            array_filter(
                array_map(
                    'absint',
                    array_column($all_issues, 'id')
                )
            )
        );
    
        /*
         * Only request Comic Vine information when Metron does not
         * already provide an issue cover.
         */
        $issues_needing_cv = array_values(
            array_filter(
                $all_issues,
                static function ($issue) {
                    return empty($issue['image']);
                }
            )
        );
    
        $cv_info_batch = !empty($issues_needing_cv)
            ? $this->data_service->get_cv_info_batch(
                $issues_needing_cv
            )
            : [];
    
        if (is_user_logged_in()) {
            $collection_status =
                ComicRenderer::get_collection_status($metron_ids);
        }
    
        ob_start();
 
        if (!empty($all_issues)) :
            ?>
            <ul class="issues-list">
                <?php 
                foreach ($all_issues as $issue) :
                    if (empty($issue['id'])) {
                        continue;
                    }

                    $metron_id = (int) $issue['id'];
                   
                    $cv_issue = $cv_info_batch[$metron_id] ?? [];

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
            'issues'       => $html,
            'page'         => $page,
            'current_page' => $page,
            'total_issues' => $total_issues,
            'total_pages'  => ceil($total_issues / 10),
            'search'       => $search
        ]);
    }

    /* -----------------------------------------------------------------
     *  AJAX – Batch series images
     * ----------------------------------------------------------------- */
    public function ajax_load_series_images_batch() {
        check_ajax_referer(
            'comicbooks_fetchers_data',
            'nonce'
        );
    
        $series_ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'absint',
                        (array) wp_unslash(
                            $_POST['series_ids'] ?? []
                        )
                    )
                )
            )
        );
    
        if (!$series_ids) {
            wp_send_json_error(
                ['message' => 'No series IDs provided'],
                400
            );
        }
    
        // Retain the existing batch limit.
        $series_ids = array_slice($series_ids, 0, 2);
    
        wp_send_json_success([
            'results' =>
                $this->data_service->get_series_cover_results(
                    $series_ids
                ),
        ]);
    }


    /* -----------------------------------------------------------------
     *  AJAX – Batch publisher images
     * ----------------------------------------------------------------- */
    public function ajax_load_publisher_images_batch()
    {
        check_ajax_referer(
            'comicbooks_fetchers_data',
            'nonce'
        );
    
        $publisher_ids = isset($_POST['publisher_ids'])
            ? array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'absint',
                            (array) wp_unslash($_POST['publisher_ids'])
                        )
                    )
                )
            )
            : [];
    
        /*
         * Prevent unusually large public requests.
         */
        $publisher_ids = array_slice($publisher_ids, 0, 2);
    
        if (empty($publisher_ids)) {
            wp_send_json_error(
                ['message' => 'No publisher IDs provided'],
                400
            );
        }
    
        $publishers = [];
    
        foreach ($publisher_ids as $publisher_id) {
            $info = $this->data_service->get_publisher_info(
                $publisher_id
            );

            $description =
                $this->data_service->normalize_publisher_description(
                    $info['desc'] ?? ''
                );       


            $publishers[$publisher_id] = [
                'image'   => $info['image'] ?? '',
                'founded' => $info['founded'] ?? '',
                'desc'    => $description ?: 'No description available.',
            ];
        }
    
        wp_send_json_success([
            'publishers' => $publishers,
        ]);
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