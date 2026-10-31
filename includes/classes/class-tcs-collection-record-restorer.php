<?php
defined('ABSPATH') || exit;

class TCS_Collection_Record_Restorer {
    private $validator;
    private $planner;

    public function __construct(
        TCS_Collection_Backup_Validator $validator,
        TCS_Collection_Import_Planner $planner
    ) {
        $this->validator = $validator;
        $this->planner = $planner;
    }

    public function create_draft(
        array $job,
        int $source_post_id,
        array $post_map = []
    ): array {
        $backup = $job['backup'];

        $owners = $this->validator->validate_owners($backup);
        $this->validator->validate_post_fields($backup);

        $plan = $this->planner->build_import_plan([
            'backup' => $backup,
            'owners' => $owners,
            'owner_map' => $job['owner_map'],
        ]);

        $index = array_search(
            $source_post_id,
            array_column($backup['records'], 'source_post_id'),
            true
        );

        if ($index === false) {
            throw new RuntimeException(
                'The source comic was not found.'
            );
        }

        $record = $backup['records'][$index];
        $item = $plan['records'][$index];

        if ($item['action'] === 'skip') {
            return [
                'status' => 'skipped',
                'post_id' => $item['existing_post_id'],
            ];
        }

        $owner_id = $item['destination_owner_id'];
        $origin = $item['origin'];

        $fingerprint = hash(
            'sha256',
            wp_json_encode($record)
        );

        global $wpdb;

        $pending = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} AS p
             INNER JOIN {$wpdb->postmeta} AS m
                ON m.post_id = p.ID
             WHERE p.post_type = 'collection'
               AND p.post_author = %d
               AND m.meta_key = %s
               AND m.meta_value = %s
             ORDER BY p.ID ASC
             LIMIT 2",
            $owner_id,
            '_tcs_collection_transfer_pending',
            $origin
        ));

        if (
            $wpdb->last_error !== '' ||
            !is_array($pending)
        ) {
            throw new RuntimeException(
                'Unfinished imports could not be checked.'
            );
        }

        if (count($pending) > 1) {
            throw new RuntimeException(
                'Multiple unfinished imports match this comic.'
            );
        }

        if ($pending) {
            $post_id = (int) $pending[0];
            $post = get_post($post_id);

            if (
                !$post ||
                $post->post_type !== 'collection' ||
                (int) $post->post_author !== $owner_id ||
                $post->post_status !== 'draft' ||
                get_post_meta(
                    $post_id,
                    '_tcs_collection_transfer_pending',
                    true
                ) !== $origin ||
                get_post_meta(
                    $post_id,
                    '_tcs_collection_transfer_fingerprint',
                    true
                ) !== $fingerprint
            ) {
                throw new RuntimeException(
                    'The unfinished import changed. Review it before retrying.'
                );
            }

            return [
                'status' => 'resumed_draft',
                'post_id' => $post_id,
            ];
        }

        $parent_id = 0;
        $source_parent = $record['post']['post_parent'];

        if ($source_parent > 0) {
            $parent_index = array_search(
                $source_parent,
                array_column(
                    $backup['records'],
                    'source_post_id'
                ),
                true
            );

            $parent_item = $plan['records'][$parent_index];

            $parent_id = $parent_item['action'] === 'skip'
                ? $parent_item['existing_post_id']
                : ($post_map[$source_parent] ?? 0);

            $parent = is_int($parent_id) && $parent_id > 0
                ? get_post($parent_id)
                : null;

            if (
                !$parent ||
                $parent->post_type !== 'collection' ||
                (int) $parent->post_author !==
                    $parent_item['destination_owner_id'] ||
                (
                    $parent_item['action'] !== 'skip' &&
                    get_post_meta(
                        $parent_id,
                        '_tcs_collection_transfer_origin',
                        true
                    ) !== $parent_item['origin'] &&
                    get_post_meta(
                        $parent_id,
                        '_tcs_collection_transfer_pending',
                        true
                    ) !== $parent_item['origin']
                )
            ) {
                throw new RuntimeException(
                    'Restore the parent comic before this comic.'
                );
            }
        }

        $fields = array_intersect_key(
            $record['post'],
            array_flip([
                'post_title',
                'post_content',
                'post_excerpt',
                'post_name',
                'post_date',
                'post_date_gmt',
                'post_password',
                'comment_status',
                'ping_status',
                'menu_order',
            ])
        );

        $fields['post_type'] = 'collection';
        $fields['post_status'] = 'draft';
        $fields['post_author'] = $owner_id;
        $fields['post_parent'] = $parent_id;

        $fields['meta_input'] = [
            '_tcs_collection_transfer_pending' => $origin,
            '_tcs_collection_transfer_fingerprint' =>
                $fingerprint,
        ];

        $post_id = wp_insert_post(
            wp_slash($fields),
            true
        );

        if (is_wp_error($post_id)) {
            throw new RuntimeException(
                $post_id->get_error_message()
            );
        }

        if (!$post_id) {
            throw new RuntimeException(
                'The comic draft could not be created.'
            );
        }

        $post_id = (int) $post_id;
        $post = get_post($post_id);

        if (
            !$post ||
            $post->post_type !== 'collection' ||
            (int) $post->post_author !== $owner_id ||
            $post->post_status !== 'draft' ||
            (int) $post->post_parent !== $parent_id ||
            get_post_meta(
                $post_id,
                '_tcs_collection_transfer_pending',
                true
            ) !== $origin ||
            get_post_meta(
                $post_id,
                '_tcs_collection_transfer_fingerprint',
                true
            ) !== $fingerprint
        ) {
            if (!wp_delete_post($post_id, true)) {
                throw new RuntimeException(
                    'Draft verification failed; manual cleanup is required for post '
                    . $post_id . '.'
                );
            }

            throw new RuntimeException(
                'The new comic draft did not pass verification.'
            );
        }

        return [
            'status' => 'created_draft',
            'post_id' => $post_id,
        ];
    }
}