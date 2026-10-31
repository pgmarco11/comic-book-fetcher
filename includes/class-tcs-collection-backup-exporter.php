<?php
defined('ABSPATH') || exit;

/** Reads complete collection backup data. */
class TCS_Collection_Backup_Exporter {
    public function read_meta(
        int $object_id,
        string $type = 'post'
    ): array {
        global $wpdb;

        if ($type === 'post') {
            $table = $wpdb->postmeta;
            $id_column = 'post_id';
        } elseif ($type === 'term') {
            $table = $wpdb->termmeta;
            $id_column = 'term_id';
        } else {
            throw new RuntimeException(
                'Unsupported metadata type.'
            );
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value
             FROM {$table}
             WHERE {$id_column} = %d
             ORDER BY meta_id ASC",
                $object_id
            ),
            ARRAY_A
        );

        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException(
                'The metadata could not be read.'
            );
        }

        $metadata = [];

        foreach ($rows as $row) {
            $metadata[] = [
                'key_b64' => base64_encode($row['meta_key']),
                'value_b64' => base64_encode($row['meta_value']),
            ];
        }

        return $metadata;
    }

    public function read_record(int $post_id): array {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'collection') {
            throw new RuntimeException(
                'The requested collection entry does not exist.'
            );
        }

        $post_fields = [];

        foreach ([
            'post_title',
            'post_content',
            'post_excerpt',
            'post_status',
            'post_name',
            'post_date',
            'post_date_gmt',
            'post_modified',
            'post_modified_gmt',
            'post_parent',
            'post_password',
            'comment_status',
            'ping_status',
            'menu_order',
        ] as $field) {
            $post_fields[$field] = $post->$field;
        }

        $taxonomy_data = $this->read_taxonomies(
            $post_id
        );

        return [
            'source_post_id' => (int) $post->ID,
            'source_owner_id' => (int) $post->post_author,
            'post' => $post_fields,
            'meta' => $this->read_meta($post_id),
            'terms' => $taxonomy_data['assignments'],
            'term_definitions' => $taxonomy_data['definitions'],
        ];
    }

    public function collect_term(
        WP_Term $term,
        array &$definitions,
        array &$visiting
    ): string {
        $reference = $term->taxonomy . ':' . $term->term_id;

        if (isset($definitions[$reference])) {
            return $reference;
        }

        if (isset($visiting[$reference])) {
            throw new RuntimeException(
                'A cycle was found in the taxonomy hierarchy.'
            );
        }

        $visiting[$reference] = true;
        $parent_reference = null;

        if ((int) $term->parent > 0) {
            $parent = get_term(
                (int) $term->parent,
                $term->taxonomy
            );

            if (!$parent || is_wp_error($parent)) {
                throw new RuntimeException(
                    'A taxonomy parent could not be read.'
                );
            }

            $parent_reference = $this->collect_term(
                $parent,
                $definitions,
                $visiting
            );
        }

        $definitions[$reference] = [
            'ref' => $reference,
            'taxonomy' => $term->taxonomy,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'parent_ref' => $parent_reference,
            'meta' => $this->read_meta(
                (int) $term->term_id,
                'term'
            ),
        ];

        unset($visiting[$reference]);

        return $reference;
    }

    public function read_taxonomies(
        int $post_id
    ): array {
        $taxonomies = get_object_taxonomies(
            'collection',
            'names'
        );

        $assignments = [];
        $definitions = [];
        $visiting = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms(
                $post_id,
                $taxonomy
            );

            if (is_wp_error($terms)) {
                throw new RuntimeException(
                    $terms->get_error_message()
                );
            }

            $assignments[$taxonomy] = [];

            foreach ($terms as $term) {
                $reference = $this->collect_term(
                    $term,
                    $definitions,
                    $visiting
                );

                $assignments[$taxonomy][] = $reference;
            }
        }

        return [
            'assignments' => $assignments,
            'definitions' => $definitions,
        ];
    }

    public function build_backup(
        int $owner_id
    ): array {
        $records = [];
        $term_definitions = [];
        $owners = [];
        $page = 1;

        do {
            $query_args = [
                'post_type' => 'collection',
                'post_status' => array_values(get_post_stati()),
                'posts_per_page' => 100,
                'paged' => $page,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
            ];

            if ($owner_id > 0) {
                $query_args['author'] = $owner_id;
            }

            $collection_query = new WP_Query($query_args);
            $post_ids = $collection_query->posts;

            foreach ($post_ids as $post_id) {
                $record = $this->read_record(
                    (int) $post_id
                );

                $record_owner_id = $record['source_owner_id'];

                // Verify ownership again before including the record.
                if (
                    $owner_id > 0 &&
                    $record_owner_id !== $owner_id
                ) {
                    throw new RuntimeException(
                        'An entry did not belong to the selected owner.'
                    );
                }

                // Store each term definition once for the whole backup.
                foreach (
                    $record['term_definitions'] as $reference => $term
                ) {
                    $term_definitions[$reference] = $term;
                }

                unset($record['term_definitions']);

                // Include owner references for mapping during import.
                if (!isset($owners[$record_owner_id])) {
                    $user = get_userdata($record_owner_id);

                    $owners[$record_owner_id] = [
                        'source_user_id' => $record_owner_id,
                        'user_login' => $user
                            ? $user->user_login
                            : null,
                    ];
                }

                $records[] = $record;
            }

            $page++;
        } while (count($post_ids) === 100);

        $source_id = get_option(
            'tcs_collection_transfer_source_id',
            ''
        );

        if (!is_string($source_id) || $source_id === '') {
            add_option(
                'tcs_collection_transfer_source_id',
                wp_generate_uuid4(),
                '',
                false
            );

            $source_id = get_option(
                'tcs_collection_transfer_source_id',
                ''
            );

            if (!is_string($source_id) || $source_id === '') {
                throw new RuntimeException(
                    'The backup source identifier could not be saved.'
                );
            }
        }

        return [
            'format' => 'tcs-collection-backup',
            'schema_version' => 1,
            'source_id' => $source_id,
            'exported_at' => gmdate('c'),
            'scope' => $owner_id === 0
                ? 'all_users'
                : 'single_user',
            'record_count' => count($records),
            'owners' => array_values($owners),
            'term_definitions' => array_values($term_definitions),
            'records' => $records,
        ];
    }
}
