<?php
defined('ABSPATH') || exit;

class TCS_Collection_Record_Finalizer {
    private $validator;
    private $planner;
    private $records;
    private $exporter;

    public function __construct(
        TCS_Collection_Backup_Validator $validator,
        TCS_Collection_Import_Planner $planner,
        TCS_Collection_Record_Restorer $records,
        TCS_Collection_Backup_Exporter $exporter
    ) {
        $this->validator = $validator;
        $this->planner = $planner;
        $this->records = $records;
        $this->exporter = $exporter;
    }

    public function restore(
        array $job,
        int $source_post_id,
        array $term_map,
        array $post_map = []
    ): array {

        if (
            array_key_exists('token', $job) &&
            (
                !is_string($job['token']) ||
                !preg_match('/^[a-f0-9]{32}$/D', $job['token'])
            )
        ) {
            throw new RuntimeException(
                'The import job token is invalid.'
            );
        }
        

        $backup = $job['backup'];

        $owners = $this->validator->validate_owners($backup);
        $this->validator->validate_post_fields($backup);
        $this->validator->validate_taxonomies($backup);

        $plan = $this->planner->build_import_plan([
            'backup' => $backup,
            'owners' => $owners,
            'owner_map' => $job['owner_map'],
        ]);

        $ids = array_column(
            $backup['records'],
            'source_post_id'
        );

        $index = array_search(
            $source_post_id,
            $ids,
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

        $assignments = $this->map_terms(
            $backup,
            $record,
            $term_map
        );

        $metadata = new TCS_Collection_Metadata_Restorer(
            $this->validator,
            $this->exporter
        );

        $rows = $metadata->prepare($record);

        $fingerprint = hash(
            'sha256',
            wp_json_encode($record)
        );

        $parent_id = 0;

        if ($record['post']['post_parent'] > 0) {
            $parent_index = array_search(
                $record['post']['post_parent'],
                $ids,
                true
            );

            $parent_item = $plan['records'][$parent_index];

            $parent_id = $parent_item['action'] === 'skip'
                ? $parent_item['existing_post_id']
                : (
                    $post_map[$record['post']['post_parent']]
                    ?? 0
                );

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
                    'Restore the mapped parent comic first.'
                );
            }
        }

        $post_id = $this->pending_id(
            $item['destination_owner_id'],
            $item['origin']
        );

        $phase = $post_id
            ? get_post_meta(
                $post_id,
                '_tcs_collection_transfer_finalizing',
                true
            )
            : '';

        if ($phase !== '' && $phase !== $fingerprint) {
            throw new RuntimeException(
                'The finalization marker does not match this backup.'
            );
        }

        if ($phase === '') {
            $draft = $this->records->restore_metadata(
                $job,
                $source_post_id,
                $post_map
            );

            if ($draft['status'] === 'skipped') {
                return $draft;
            }

            $post_id = $draft['post_id'];

            $this->guard(
                $post_id,
                $item,
                $fingerprint
            );

            foreach ($assignments as $taxonomy => $term_ids) {
                $result = wp_set_object_terms(
                    $post_id,
                    $term_ids,
                    $taxonomy,
                    false
                );

                if (is_wp_error($result)) {
                    throw new RuntimeException(
                        $result->get_error_message()
                    );
                }
            }
        }

        $this->guard(
            $post_id,
            $item,
            $fingerprint
        );

        $post = get_post($post_id);

        if (
            !in_array(
                $post->post_status,
                ['draft', $record['post']['post_status']],
                true
            )
        ) {
            throw new RuntimeException(
                'The unfinished comic status changed.'
            );
        }

        $this->verify(
            $post_id,
            $record,
            $item,
            $parent_id,
            $assignments,
            $rows,
            false
        );

        update_post_meta(
            $post_id,
            '_tcs_collection_transfer_finalizing',
            $fingerprint
        );

        if (
            get_post_meta(
                $post_id,
                '_tcs_collection_transfer_finalizing',
                true
            ) !== $fingerprint
        ) {
            throw new RuntimeException(
                'The finalization marker could not be saved.'
            );
        }

        if (isset($job['token'])) {
            update_post_meta(
                $post_id,
                '_tcs_collection_transfer_job',
                $job['token']
            );
        
            if (
                get_post_meta(
                    $post_id,
                    '_tcs_collection_transfer_job',
                    true
                ) !== $job['token']
            ) {
                throw new RuntimeException(
                    'The import job identity could not be saved.'
                );
            }
        }

        $needs_update =
            $post->post_status !== $record['post']['post_status'];

        foreach ([
            'post_date',
            'post_date_gmt',
            'post_modified',
            'post_modified_gmt',
        ] as $field) {
            $needs_update = $needs_update ||
                $post->$field !== $record['post'][$field];
        }

        if ($needs_update) {
            $source = $record['post'];

            $preserve = static function (
                array $data,
                array $postarr
            ) use ($post_id, $source): array {
                if (
                    (int) ($postarr['ID'] ?? 0) === $post_id &&
                    $data['post_type'] === 'collection'
                ) {
                    foreach ([
                        'post_status',
                        'post_date',
                        'post_date_gmt',
                        'post_modified',
                        'post_modified_gmt',
                    ] as $field) {
                        $data[$field] = $source[$field];
                    }
                }

                return $data;
            };

            add_filter(
                'wp_insert_post_data',
                $preserve,
                PHP_INT_MAX,
                2
            );

            try {
                $result = wp_update_post(
                    wp_slash([
                        'ID' => $post_id,
                        'post_status' => $source['post_status'],
                        'post_date' => $source['post_date'],
                        'post_date_gmt' => $source['post_date_gmt'],
                    ]),
                    true
                );

                if (is_wp_error($result) || !$result) {
                    throw new RuntimeException(
                        'The final comic status could not be restored.'
                    );
                }
            } finally {
                remove_filter(
                    'wp_insert_post_data',
                    $preserve,
                    PHP_INT_MAX
                );
            }
        }

        $this->guard(
            $post_id,
            $item,
            $fingerprint
        );

        $this->verify(
            $post_id,
            $record,
            $item,
            $parent_id,
            $assignments,
            $rows,
            true
        );

        update_post_meta(
            $post_id,
            '_tcs_collection_transfer_origin',
            $item['origin']
        );

        if (
            get_post_meta(
                $post_id,
                '_tcs_collection_transfer_origin',
                true
            ) !== $item['origin']
        ) {
            throw new RuntimeException(
                'The completed import identity could not be saved.'
            );
        }

        foreach ([
            'pending',
            'fingerprint',
            'finalizing',
        ] as $suffix) {
            delete_post_meta(
                $post_id,
                '_tcs_collection_transfer_' . $suffix
            );
        }

        return [
            'status' => 'restored',
            'post_id' => $post_id,
            'metadata_rows' => count($rows),
            'term_assignments' => array_sum(
                array_map('count', $assignments)
            ),
        ];
    }

    private function pending_id(
        int $owner_id,
        string $origin
    ): int {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
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
            !is_array($ids) ||
            count($ids) > 1
        ) {
            throw new RuntimeException(
                'Unfinished imports could not be resolved uniquely.'
            );
        }

        return $ids ? (int) $ids[0] : 0;
    }

    private function guard(
        int $post_id,
        array $item,
        string $fingerprint
    ): void {
        clean_post_cache($post_id);
        $post = get_post($post_id);

        if (
            !$post ||
            $post->post_type !== 'collection' ||
            (int) $post->post_author !==
                $item['destination_owner_id'] ||
            get_post_meta(
                $post_id,
                '_tcs_collection_transfer_pending',
                true
            ) !== $item['origin'] ||
            get_post_meta(
                $post_id,
                '_tcs_collection_transfer_fingerprint',
                true
            ) !== $fingerprint ||
            get_post_meta(
                $post_id,
                '_tcs_collection_transfer_origin',
                true
            ) !== ''
        ) {
            throw new RuntimeException(
                'This unfinished comic no longer matches the import.'
            );
        }
    }

    private function map_terms(
        array $backup,
        array $record,
        array $term_map
    ): array {
        $definitions = array_column(
            $backup['term_definitions'],
            null,
            'ref'
        );

        $assignments = [];

        foreach (
            get_object_taxonomies('collection', 'names')
            as $taxonomy
        ) {
            $assignments[$taxonomy] = [];

            foreach (
                $record['terms'][$taxonomy] ?? []
                as $reference
            ) {
                $id = $term_map[$reference] ?? null;
                $definition = $definitions[$reference];

                $term = is_int($id) && $id > 0
                    ? get_term($id, $taxonomy, OBJECT, 'raw')
                    : null;

                $parent = $definition['parent_ref'] === null
                    ? 0
                    : (
                        $term_map[$definition['parent_ref']]
                        ?? -1
                    );

                if (
                    !$term ||
                    is_wp_error($term) ||
                    $term->taxonomy !== $taxonomy ||
                    $term->slug !== $definition['slug'] ||
                    $term->name !== $definition['name'] ||
                    $term->description !==
                        $definition['description'] ||
                    (int) $term->parent !== $parent
                ) {
                    throw new RuntimeException(
                        'A mapped term is missing or changed: '
                        . $reference
                    );
                }

                $assignments[$taxonomy][] = $id;
            }

            $assignments[$taxonomy] = array_values(
                array_unique($assignments[$taxonomy])
            );
        }

        return $assignments;
    }

    private function verify(
        int $post_id,
        array $record,
        array $item,
        int $parent_id,
        array $assignments,
        array $rows,
        bool $final
    ): void {
        clean_post_cache($post_id);
        $post = get_post($post_id);

        if (
            !$post ||
            $post->post_type !== 'collection' ||
            (int) $post->post_author !==
                $item['destination_owner_id'] ||
            (int) $post->post_parent !== $parent_id ||
            (
                $final &&
                $post->post_status !==
                    $record['post']['post_status']
            )
        ) {
            throw new RuntimeException(
                'The restored comic failed its ownership or status check.'
            );
        }

        foreach ($record['post'] as $field => $value) {
            if (
                in_array(
                    $field,
                    ['post_name', 'post_parent', 'post_status'],
                    true
                )
            ) {
                continue;
            }

            if (
                !$final &&
                in_array(
                    $field,
                    [
                        'post_date',
                        'post_date_gmt',
                        'post_modified',
                        'post_modified_gmt',
                    ],
                    true
                )
            ) {
                continue;
            }

            if ((string) $post->$field !== (string) $value) {
                throw new RuntimeException(
                    'The restored comic field does not match: '
                    . $field
                );
            }
        }

        foreach ($assignments as $taxonomy => $expected) {
            $actual = wp_get_object_terms(
                $post_id,
                $taxonomy,
                ['fields' => 'ids']
            );

            if (is_wp_error($actual)) {
                throw new RuntimeException(
                    $actual->get_error_message()
                );
            }

            $actual = array_map('intval', $actual);

            sort($actual, SORT_NUMERIC);
            sort($expected, SORT_NUMERIC);

            if ($actual !== $expected) {
                throw new RuntimeException(
                    'Term assignments did not match.'
                );
            }
        }

        $actual = array_values(array_filter(
            $this->exporter->read_meta($post_id),
            static function (array $row): bool {
                return !str_starts_with(
                    strtolower(base64_decode(
                        $row['key_b64'],
                        true
                    )),
                    '_tcs_collection_transfer_'
                );
            }
        ));

        if ($actual !== $rows) {
            throw new RuntimeException(
                'Comic metadata changed during finalization.'
            );
        }
    }
}