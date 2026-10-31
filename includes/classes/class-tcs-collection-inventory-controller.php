<?php
defined('ABSPATH') || exit;

/** Owns collection inventory controller behavior; legacy function entry points delegate here. */
class TCS_Collection_Inventory_Controller {
    private $repository;

    public function __construct(TCS_Collection_Inventory_Repository $repository) {
        $this->repository = $repository;
    }

    public function asset_setup(): void
    {
        $is_collection_page =
            is_page('my-collection') ||
            is_page_template('templates/page-my-collection.php');

        $is_issue_details = is_page('issue');

        if (!$is_collection_page && !$is_issue_details) {
            return;
        }

        $script_handle = '';

        /*
         * Full collection dashboard.
         */
        if ($is_collection_page) {
            $script_handle = 'tcs-inventory';

            wp_enqueue_style(
                'tcs-inventory',
                COMICBOOKS_PLUGIN_URL . 'css/collection-inventory.css',
                [],
                filemtime(
                    COMICBOOKS_PLUGIN_DIR . 'css/collection-inventory.css'
                )
            );

            wp_enqueue_script(
                $script_handle,
                COMICBOOKS_PLUGIN_URL . 'js/collection-inventory.js',
                [],
                filemtime(
                    COMICBOOKS_PLUGIN_DIR . 'js/collection-inventory.js'
                ),
                true
            );
        }

        /*
         * Collection editor on the issue-details page.
         */
        if ($is_issue_details) {
            $script_handle = 'tcs-issue-collection';

            wp_enqueue_style(
                'tcs-issue-collection',
                COMICBOOKS_PLUGIN_URL . 'css/issue-collection.css',
                ['comicbook-style'],
                filemtime(
                    COMICBOOKS_PLUGIN_DIR . 'css/issue-collection.css'
                )
            );

            wp_enqueue_script(
                $script_handle,
                COMICBOOKS_PLUGIN_URL . 'js/issue-collection.js',
                [],
                filemtime(
                    COMICBOOKS_PLUGIN_DIR . 'js/issue-collection.js'
                ),
                true
            );
        }

        wp_localize_script(
            $script_handle,
            'tcsInventory',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('tcs_inventory'),
            ]
        );
    }

    public function authorize(): void {
        if (!is_user_logged_in()) wp_send_json_error('Please sign in again.', 401);
        if (!check_ajax_referer('tcs_inventory', 'nonce', false)) wp_send_json_error('Your session expired. Refresh the page.', 403);
    }

    public function owned_post(int $id, array $statuses = ['publish']): WP_Post {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'collection' || (int) $post->post_author !== get_current_user_id() || !in_array($post->post_status, $statuses, true)) {
            wp_send_json_error('This collection entry is unavailable.', 403);
        }
        return $post;
    }


    public function list_items() {
        $this->authorize();

        $input = wp_unslash($_POST);
        $user_id = get_current_user_id();

        $results = $this->repository->results($input);
        unset($results['records']);

        $publisher_id = absint(
            tcs_inventory_text(
                $input['collection_publisher'] ?? 0
            )
        );

        $overview = $this->repository->overview(
            $user_id,
            $publisher_id
        );

        $series_term_id = absint(
            tcs_inventory_text(
                $input['collection_series'] ?? 0
            )
        );

        $taxonomy_tree = $this->repository->taxonomy_tree(
            $user_id,
            $publisher_id,
            $series_term_id
        );

        $taxonomy_html = tcs_inventory_view()->taxonomy_html(
            $taxonomy_tree
        );

        wp_send_json_success(
            array_merge(
                $results,
                $overview,
                [
                    'taxonomy_html' => $taxonomy_html,
                ]
            )
        );
    }

    public function save_item() {
        $this->authorize();
        $input = wp_unslash($_POST);
        $id = absint(tcs_inventory_text($input['post_id'] ?? 0));
        $this->owned_post($id);
        $current = $this->repository->record($id);
        if (!hash_equals($current['version'], tcs_inventory_text($input['version'] ?? ''))) {
            wp_send_json_error('This entry changed elsewhere. Close the editor, refresh the collection, and try again.', 409);
        }
        $qty = tcs_inventory_text($input['qty'] ?? '');
        $price = tcs_inventory_text($input['price'] ?? '');
        if (!ctype_digit($qty) || (int) $qty < 1 || (int) $qty > 9999) wp_send_json_error('Quantity must be a whole number from 1 to 9,999.', 422);
        if ($price !== '' && !preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $price)) wp_send_json_error('Price must be a non-negative amount with up to two decimal places, or blank.', 422);
        $condition = tcs_inventory_text($input['condition'] ?? '');
        $location = tcs_inventory_text($input['storage_location'] ?? '');
        $notes = isset($input['notes']) && is_scalar($input['notes']) ? sanitize_textarea_field((string) $input['notes']) : '';
        if (strlen($condition) > 120 || strlen($location) > 120 || strlen($notes) > 10000) wp_send_json_error('One of the fields is too long.', 422);
        $condition_options = tcs_inventory_condition_options();

        if (
            !array_key_exists($condition, $condition_options) &&
            $condition !== (string) ($current['condition'] ?? '')
        ) {
            wp_send_json_error(
                'Choose a condition or grade from the dropdown.',
                422
            );
        }

        $fields = ['qty' => (string) (int) $qty, 'price' => $price, 'condition' => $condition, 'notes' => $notes, 'storage_location' => $location];
        foreach ($fields as $key => $value) update_post_meta($id, $key, wp_slash($value));
        foreach ($fields as $key => $value) {
            if ((string) get_post_meta($id, $key, true) !== $value) wp_send_json_error('Some changes could not be saved. Refresh before retrying.', 500);
        }
        clean_post_cache($id);
        wp_send_json_success(['record' => $this->repository->record($id)]);
    }

    public function bulk_action() {

            $this->authorize();

            $input = wp_unslash($_POST);

            $ids = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'absint',
                            (array) (
                                $input['post_ids']
                                ?? []
                            )
                        )
                    )
                )
            );

            $operation = tcs_inventory_text(
                $input['operation'] ?? ''
            );

            if (
                !$ids ||
                count($ids) > 24 ||
                !in_array(
                    $operation,
                    [
                        'location',
                        'delete',
                    ],
                    true
                )
            ) {
                wp_send_json_error(
                    'Select up to 24 entries and a valid action.',
                    422
                );
            }

            /*
             * Everything manipulated by this screen must be
             * a published collection item owned by the user.
             */
            foreach ($ids as $id) {
                $this->owned_post(
                    $id,
                    ['publish']
                );
            }

            $location = tcs_inventory_text(
                $input['storage_location'] ?? ''
            );

            if (
                $operation === 'location' &&
                (
                    $location === '' ||
                    strlen($location) > 120
                )
            ) {
                wp_send_json_error(
                    'Enter a storage location of up to 120 characters.',
                    422
                );
            }

            $completed = [];

            foreach ($ids as $id) {

                if ($operation === 'location') {

                    update_post_meta(
                        $id,
                        'storage_location',
                        wp_slash($location)
                    );

                    $ok =
                        get_post_meta(
                            $id,
                            'storage_location',
                            true
                        ) === $location;

                    if ($ok) {
                        clean_post_cache($id);
                    }

                } else {

                    /*
                     * Capture publisher + genres BEFORE permanently
                     * deleting the collection post.
                     */
                    $terms_to_check = [
                        'publisher'   => [],
                        'comic_genre' => [],
                    ];

                    foreach (
                        array_keys($terms_to_check)
                        as $taxonomy
                    ) {

                        $term_ids = wp_get_object_terms(
                            $id,
                            $taxonomy,
                            [
                                'fields' => 'ids',
                            ]
                        );

                        if (is_wp_error($term_ids)) {
                            $ok = false;
                            break;
                        }

                        $terms_to_check[$taxonomy] =
                            array_map(
                                'absint',
                                $term_ids
                            );
                    }

                    if (!isset($ok) || $ok !== false) {

                        /*
                         * true = bypass Trash and permanently delete.
                         */
                        $deleted = wp_delete_post(
                            $id,
                            true
                        );

                        $ok = $deleted !== false;

                        if ($ok) {

                            /*
                             * Post relationships are now gone and
                             * taxonomy counts have been updated.
                             */
                            $this->repository->cleanup_orphaned_terms(
                                $terms_to_check
                            );
                        }
                    }
                }

                if (!$ok) {

                    wp_send_json_error(
                        [
                            'message' =>
                                'The operation stopped before all entries were updated. Refresh to see the result.',

                            'completed' => $completed,
                            'operation' => $operation,
                        ],
                        500
                    );
                }

                $completed[] = $id;

                unset($ok);
            }

            wp_send_json_success(
                [
                    'completed' => $completed,
                    'operation' => $operation,
                ]
            );
        }
}
