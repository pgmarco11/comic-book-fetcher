<?php
defined('ABSPATH') || exit;

class TCS_Collection_Metadata_Restorer {
    private $validator;
    private $exporter;

    public function __construct(
        TCS_Collection_Backup_Validator $validator,
        TCS_Collection_Backup_Exporter $exporter
    ) {
        $this->validator = $validator;
        $this->exporter = $exporter;
    }

    public function prepare(array $record): array {
        if (!is_user_logged_in() || !current_user_can('read')) {
            throw new RuntimeException(
                'Sign in to restore collection metadata.'
            );
        }

        if (!is_array($record['meta'] ?? null)) {
            throw new RuntimeException(
                'Comic metadata is missing.'
            );
        }

        $this->validator->validate_meta_rows(
            $record['meta'],
            'Comic'
        );

        $collection_keys = [
            'characters', 'concepts', 'creators', 'condition',
            'cv_issue_id', 'date_published', 'image_url', 'issue_id',
            'issue_number', 'price', 'qty', 'title_id', 'volume',
            'cost_basis', 'cover_image_url', 'date_purchased',
            'description', 'era', 'genres', 'grading_cert_id',
            'grading_company', 'notes', 'pricecharting_condition_id',
            'pricecharting_date_entered', 'pricecharting_import_notes',
            'pricecharting_product_id', 'pricecharting_product_name',
            'pricecharting_value', 'pricecharting_value_cents',
            'pricecharting_value_updated_at', 'pricecharting_variant',
            'series_name', 'storage_location', 'year',
        ];

        $rows = [];
        $is_admin = current_user_can('manage_options');

        foreach ($record['meta'] as $row) {
            $key = base64_decode($row['key_b64'], true);
            $value = base64_decode($row['value_b64'], true);

            if (
                str_starts_with(
                    strtolower($key),
                    '_tcs_collection_transfer_'
                )
            ) {
                continue;
            }

            if (!$is_admin) {
                $base_key = str_starts_with($key, '_')
                    ? substr($key, 1)
                    : $key;

                $allowed = in_array(
                    $base_key,
                    $collection_keys,
                    true
                ) || in_array(
                    $key,
                    ['_edit_lock', '_wp_old_slug'],
                    true
                );

                if (
                    !$allowed ||
                    is_serialized($value) ||
                    preg_match('//u', $value) !== 1 ||
                    wp_kses_post($value) !== $value ||
                    (
                        in_array(
                            $key,
                            ['image_url', 'cover_image_url'],
                            true
                        ) &&
                        $value !== '' &&
                        esc_url_raw(
                            $value,
                            ['http', 'https']
                        ) !== $value
                    )
                ) {
                    throw new RuntimeException(
                        'An administrator must review this metadata field: '
                        . $key
                    );
                }
            }

            $rows[] = [
                'key_b64' => base64_encode($key),
                'value_b64' => base64_encode($value),
            ];
        }

        return $rows;
    }

    public function restore(
        int $post_id,
        array $record,
        string $origin
    ): int {
        $rows = $this->prepare($record);
        $post = get_post($post_id);

        $fingerprint = hash(
            'sha256',
            wp_json_encode($record)
        );

        if (
            !preg_match('/^[a-f0-9]{64}$/D', $origin) ||
            !$post ||
            $post->post_type !== 'collection' ||
            $post->post_status !== 'draft' ||
            (
                (int) $post->post_author !== get_current_user_id() &&
                !current_user_can('manage_options')
            ) ||
            get_post_meta(
                $post_id,
                '_tcs_collection_transfer_pending',
                true
            ) !== $origin ||
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
                'This draft is not available for metadata restoration.'
            );
        }

        global $wpdb;

        $prefix = $wpdb->esc_like(
            '_tcs_collection_transfer_'
        ) . '%';

        try {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta}
                 WHERE post_id = %d
                   AND meta_key NOT LIKE %s",
                $post_id,
                $prefix
            ));

            if ($deleted === false) {
                throw new RuntimeException(
                    'The draft metadata could not be prepared.'
                );
            }

            foreach ($rows as $row) {
                $inserted = $wpdb->insert(
                    $wpdb->postmeta,
                    [
                        'post_id' => $post_id,
                        'meta_key' => base64_decode(
                            $row['key_b64'],
                            true
                        ),
                        'meta_value' => base64_decode(
                            $row['value_b64'],
                            true
                        ),
                    ],
                    ['%d', '%s', '%s']
                );

                if ($inserted !== 1) {
                    throw new RuntimeException(
                        'Comic metadata could not be restored. Retry this draft.'
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
                    'Restored comic metadata did not match the backup.'
                );
            }
        } finally {
            wp_cache_delete($post_id, 'post_meta');
        }

        return count($rows);
    }
}