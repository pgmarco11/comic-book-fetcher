<?php
defined('ABSPATH') || exit;

/** Resolves destination ownership and plans duplicate-safe imports. */
class TCS_Collection_Import_Planner {
    public function resolve_owner_map(
        array $owners,
        array $submitted
    ): array {
        if (!is_user_logged_in() || !current_user_can('read')) {
            throw new RuntimeException(
                'Sign in to choose destination owners.'
            );
        }

        $is_admin = current_user_can('manage_options');
        $mapping = [];

        foreach ($owners as $source_id => $owner) {
            if ($is_admin) {
                $requested = $submitted[$source_id] ?? '';

                if (
                    !is_string($requested) ||
                    !ctype_digit($requested)
                ) {
                    throw new RuntimeException(
                        'Choose a destination for source owner '
                        . $source_id . '.'
                    );
                }

                $destination_id = (int) $requested;
            } else {
                $destination_id = get_current_user_id();
            }

            if (
                $destination_id < 1 ||
                !get_userdata($destination_id)
            ) {
                throw new RuntimeException(
                    'The destination account does not exist.'
                );
            }

            $mapping[$source_id] = $destination_id;
        }

        return $mapping;
    }

    public function record_origin(
        array $backup,
        array $record
    ): string {
        return hash(
            'sha256',
            strtolower($backup['source_id'])
            . ':' . $record['source_post_id']
        );
    }

    public function existing_records(
        array $backup,
        array $owner_map
    ): array {
        global $wpdb;

        $local_source = get_option(
            'tcs_collection_transfer_source_id',
            ''
        );

        $same_source = is_string($local_source)
            && $local_source !== ''
            && strtolower($local_source)
                === strtolower($backup['source_id']);

        $result = [
            'same_source' => $same_source,
            'posts' => [],
            'origins' => [],
        ];

        if (!$backup['records']) {
            return $result;
        }

        $destination_ids = array_values(
            array_unique($owner_map)
        );

        $original_ids = $same_source
            ? array_column($backup['records'], 'source_post_id')
            : [0];

        $owner_slots = implode(
            ',',
            array_fill(0, count($destination_ids), '%d')
        );

        $post_slots = implode(
            ',',
            array_fill(0, count($original_ids), '%d')
        );

        $values = array_merge(
            ['_tcs_collection_transfer_origin'],
            $destination_ids,
            $original_ids
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID, p.post_author,
                    m.meta_value AS import_origin
             FROM {$wpdb->posts} AS p
             LEFT JOIN {$wpdb->postmeta} AS m
                ON m.post_id = p.ID
                AND m.meta_key = %s
             WHERE p.post_type = 'collection'
                AND p.post_author IN ({$owner_slots})
                AND (
                    m.meta_id IS NOT NULL
                    OR p.ID IN ({$post_slots})
                )",
                $values
            ),
            ARRAY_A
        );

        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException(
                'Existing collection records could not be checked.'
            );
        }

        foreach ($rows as $row) {
            $post_id = (int) $row['ID'];
            $owner_id = (int) $row['post_author'];

            $result['posts'][$post_id] = $owner_id;

            if (
                is_string($row['import_origin']) &&
                $row['import_origin'] !== ''
            ) {
                $key = $owner_id . ':' . $row['import_origin'];

                $result['origins'][$key][$post_id] = true;
            }
        }

        return $result;
    }

    public function build_import_plan(
        array $job
    ): array {
        $submitted = [];

        foreach (
            $job['owner_map'] as $source_id => $destination_id
        ) {
            if (!is_int($destination_id)) {
                throw new RuntimeException(
                    'Invalid saved destination.'
                );
            }

            $submitted[$source_id] = (string) $destination_id;
        }

        // Recheck permissions and destination accounts.
        $owner_map = $this->resolve_owner_map(
            $job['owners'],
            $submitted
        );

        $backup = $job['backup'];

        $existing = $this->existing_records(
            $backup,
            $owner_map
        );

        $plan = [
            'owner_map' => $owner_map,
            'create_count' => 0,
            'skip_count' => 0,
            'records' => [],
        ];

        foreach ($backup['records'] as $record) {
            $source_id = $record['source_post_id'];

            $destination_id =
                $owner_map[$record['source_owner_id']];

            $origin = $this->record_origin(
                $backup,
                $record
            );

            $key = $destination_id . ':' . $origin;

            $matches = $existing['origins'][$key] ?? [];

            if (
                $existing['same_source'] &&
                ($existing['posts'][$source_id] ?? null)
                    === $destination_id
            ) {
                $matches[$source_id] = true;
            }

            if (count($matches) > 1) {
                throw new RuntimeException(
                    'Multiple destination entries match source record '
                    . $source_id
                    . '. Resolve this before importing.'
                );
            }

            $skip = count($matches) === 1;

            $plan[$skip ? 'skip_count' : 'create_count']++;

            $plan['records'][] = [
                'source_post_id' => $source_id,
                'destination_owner_id' => $destination_id,
                'origin' => $origin,
                'action' => $skip ? 'skip' : 'create',
                'existing_post_id' => $skip
                    ? (int) array_key_first($matches)
                    : null,
            ];
        }

        return $plan;
    }
}
