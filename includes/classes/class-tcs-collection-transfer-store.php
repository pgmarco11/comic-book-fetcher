<?php
defined('ABSPATH') || exit;

/** Owns pending-job storage, token checks and expiration. */
class TCS_Collection_Transfer_Store {
    public function stage_backup(
        array $backup,
        array $owners
    ): string {
        if (!is_user_logged_in() || !current_user_can('read')) {
            throw new RuntimeException(
                'Sign in before staging a backup.'
            );
        }

        $this->assert_can_change_pending();

        $user_id = get_current_user_id();

        $option_name =
            'tcs_collection_transfer_pending_' . $user_id;

        $previous = get_option($option_name, false);

        $token = bin2hex(random_bytes(16));
        $expires_at = time() + (30 * MINUTE_IN_SECONDS);

        $job = [
            'token' => $token,
            'actor_id' => $user_id,
            'expires_at' => $expires_at,
            'backup' => $backup,
            'owners' => $owners,
        ];

        if (!update_option($option_name, $job, false)) {
            throw new RuntimeException(
                'The pending backup could not be saved.'
            );
        }

        $scheduled = wp_schedule_single_event(
            $expires_at,
            'tcs_collection_transfer_cleanup_pending',
            [$user_id, $token],
            true
        );

        if (is_wp_error($scheduled) || $scheduled !== true) {
            delete_option($option_name);

            throw new RuntimeException(
                'Backup cleanup could not be scheduled. Please try again.'
            );
        }

        if (
            is_array($previous) &&
            is_string($previous['token'] ?? null)
        ) {
            wp_clear_scheduled_hook(
                'tcs_collection_transfer_cleanup_pending',
                [$user_id, $previous['token']]
            );
        }

        return $token;
    }

    public function read_pending(
        string $token
    ): array {
        if (!is_user_logged_in() || !current_user_can('read')) {
            throw new RuntimeException(
                'Sign in to access the pending backup.'
            );
        }

        $user_id = get_current_user_id();

        wp_cache_delete(
            'tcs_collection_transfer_pending_' . $user_id,
            'options'
        );

        $job = get_option(
            'tcs_collection_transfer_pending_' . $user_id,
            false
        );

        if (
            !is_array($job) ||
            ($job['actor_id'] ?? null) !== $user_id ||
            !is_string($job['token'] ?? null) ||
            !hash_equals($job['token'], $token) ||
            !is_int($job['expires_at'] ?? null)
        ) {
            throw new RuntimeException(
                'The pending backup was not found. Upload it again.'
            );
        }

        if (
            $job['expires_at'] <= time() &&
            !$this->active_progress($user_id, $token)
        ) {
            throw new RuntimeException(
                'The pending backup expired. Upload it again.'
            );
        }

        return $job;
    }

    public function cleanup_pending(
        int $user_id,
        string $token
    ): void {
        $option_name =
            'tcs_collection_transfer_pending_' . $user_id;

        wp_cache_delete($option_name, 'options');

        $job = get_option($option_name, false);

        if (
            is_array($job) &&
            is_string($job['token'] ?? null) &&
            hash_equals($job['token'], $token) &&
            is_int($job['expires_at'] ?? null) &&
            $job['expires_at'] <= time() &&
            !$this->active_progress($user_id, $token)
        ) {
            delete_option($option_name);
        }
    }
    private function progress_name(int $user_id): string {
        return 'tcs_collection_transfer_progress_' . $user_id;
    }
    
    private function active_progress(
        int $user_id,
        ?string $token = null
    ): bool {
        $name = $this->progress_name($user_id);
    
        wp_cache_delete($name, 'options');
        $progress = get_option($name, false);
    
        return is_array($progress)
            && ($progress['actor_id'] ?? null) === $user_id
            && is_string($progress['token'] ?? null)
            && (
                $token === null ||
                hash_equals($progress['token'], $token)
            )
            && in_array(
                $progress['state'] ?? '',
                ['ready', 'running', 'paused'],
                true
            );
    }
    
    public function assert_can_change_pending(): void {
        if ($this->active_progress(get_current_user_id())) {
            throw new RuntimeException(
                'Finish or cancel the active import before changing the pending backup.'
            );
        }
    }
    
    public function read_progress(string $token): array {
        if (!is_user_logged_in() || !current_user_can('read')) {
            throw new RuntimeException(
                'Sign in to access import progress.'
            );
        }
    
        $user_id = get_current_user_id();
        $name = $this->progress_name($user_id);
    
        wp_cache_delete($name, 'options');
        $progress = get_option($name, false);
    
        if (
            !is_array($progress) ||
            ($progress['actor_id'] ?? null) !== $user_id ||
            !is_string($progress['token'] ?? null) ||
            !hash_equals($progress['token'], $token)
        ) {
            throw new RuntimeException(
                'Import progress was not found.'
            );
        }
    
        return $progress;
    }
    
    public function save_pending(
        array $job,
        TCS_Collection_Import_Lock $lock
    ): void {
        $lock->assert_owned();
    
        $current = $this->read_pending($job['token']);
    
        foreach ([
            'token',
            'actor_id',
            'expires_at',
            'backup',
            'owners',
        ] as $key) {
            if ($job[$key] !== $current[$key]) {
                throw new RuntimeException(
                    'The staged backup cannot be replaced during this operation.'
                );
            }
        }
    
        $name = 'tcs_collection_transfer_pending_'
            . get_current_user_id();
    
        if (
            !update_option($name, $job, false) &&
            get_option($name) !== $job
        ) {
            throw new RuntimeException(
                'The pending job could not be saved.'
            );
        }
    }
    
    public function begin_import(
        string $token,
        array $plan,
        TCS_Collection_Import_Lock $lock
    ): array {
        $lock->assert_owned();
    
        $job = $this->read_pending($token);
        $name = $this->progress_name(get_current_user_id());
    
        wp_cache_delete($name, 'options');
        $previous = get_option($name, false);
    
        if ($this->active_progress(get_current_user_id())) {
            if (($previous['token'] ?? null) !== $token) {
                throw new RuntimeException(
                    'Another import is already active for this account.'
                );
            }
    
            return $this->read_progress($token);
        }
    
        if (
            is_array($previous) &&
            ($previous['token'] ?? null) === $token &&
            in_array(
                $previous['state'] ?? '',
                ['complete', 'cancelled'],
                true
            )
        ) {
            return $this->read_progress($token);
        }
    
        if (
            !in_array(
                $job['state'] ?? '',
                ['mapped', 'running'],
                true
            )
        ) {
            throw new RuntimeException(
                'Save the destination mapping before starting an import.'
            );
        }
    
        $job['owner_map'] = $plan['owner_map'];
        $job['import_plan'] = $plan;
        $job['state'] = 'running';
    
        $this->save_pending($job, $lock);
    
        $progress = [
            'token' => $token,
            'actor_id' => get_current_user_id(),
            'revision' => 0,
            'state' => 'ready',
            'phase' => 'terms',
            'term_cursor' => 0,
            'record_cursor' => 0,
            'term_map' => [],
            'post_map' => [],
            'results' => [],
            'last_error' => '',
        ];
    
        return $this->write_progress(
            $job,
            $progress,
            $lock
        );
    }
    
    public function save_progress(
        string $token,
        array $progress,
        TCS_Collection_Import_Lock $lock
    ): array {
        $lock->assert_owned();
    
        return $this->write_progress(
            $this->read_pending($token),
            $progress,
            $lock
        );
    }
    
    private function write_progress(
        array $job,
        array $progress,
        TCS_Collection_Import_Lock $lock
    ): array {
        $lock->assert_owned();
    
        $user_id = get_current_user_id();
        $name = $this->progress_name($user_id);
    
        wp_cache_delete($name, 'options');
        $previous = get_option($name, false);
    
        $revision = is_array($previous)
            && ($previous['token'] ?? null) === $job['token']
                ? ($previous['revision'] ?? null)
                : 0;
    
        if (!is_int($revision) || $revision < 0) {
            throw new RuntimeException(
                'The saved progress revision is invalid.'
            );
        }
    
        if (
            ($progress['actor_id'] ?? null) !== $user_id ||
            ($progress['token'] ?? null) !== $job['token'] ||
            ($progress['revision'] ?? null) !== $revision
        ) {
            throw new RuntimeException(
                'Import progress changed. Reload it before retrying.'
            );
        }
    
        if (
            is_array($previous) &&
            ($previous['token'] ?? null) === $job['token'] &&
            in_array(
                $previous['state'] ?? '',
                ['complete', 'cancelled'],
                true
            )
        ) {
            throw new RuntimeException(
                'A finished import cannot be changed.'
            );
        }

        $progress['total_terms'] =
            count($job['backup']['term_definitions']);

        $progress['total_records'] =
            count($job['backup']['records']);
    
        $allowed_fields = [
            'token', 'actor_id', 'revision', 'state', 'phase',
            'term_cursor', 'record_cursor', 'term_map', 'post_map',
            'results', 'last_error', 'updated_at',
            'total_terms', 'total_records',
        ];
    
        if (array_diff(array_keys($progress), $allowed_fields)) {
            throw new RuntimeException(
                'Unexpected fields were included in import progress.'
            );
        }
    
        if (
            !in_array(
                $progress['state'] ?? '',
                ['ready', 'running', 'paused', 'complete', 'cancelled'],
                true
            ) ||
            !in_array(
                $progress['phase'] ?? '',
                ['terms', 'records', 'done'],
                true
            ) ||
            !is_int($progress['term_cursor'] ?? null) ||
            $progress['term_cursor'] < 0 ||
            $progress['term_cursor'] >
                count($job['backup']['term_definitions']) ||
            !is_int($progress['record_cursor'] ?? null) ||
            $progress['record_cursor'] < 0 ||
            $progress['record_cursor'] >
                count($job['backup']['records']) ||
            !is_array($progress['term_map'] ?? null) ||
            !is_array($progress['post_map'] ?? null) ||
            !is_array($progress['results'] ?? null) ||
            !is_string($progress['last_error'] ?? null) ||
            strlen($progress['last_error']) > 2000
        ) {
            throw new RuntimeException(
                'The import progress structure is invalid.'
            );
        }
    
        $record_ids = array_fill_keys(
            array_column(
                $job['backup']['records'],
                'source_post_id'
            ),
            true
        );
    
        $term_refs = array_fill_keys(
            array_column(
                $job['backup']['term_definitions'],
                'ref'
            ),
            true
        );
    
        foreach ([
            'post_map' => $record_ids,
            'term_map' => $term_refs,
        ] as $field => $allowed) {
            foreach ($progress[$field] as $reference => $id) {
                if (
                    !isset($allowed[$reference]) ||
                    !is_int($id) ||
                    $id < 1
                ) {
                    throw new RuntimeException(
                        'The saved import ID map is invalid.'
                    );
                }
            }
        }
    
        foreach ($progress['results'] as $source_id => $result) {
            if (
                !isset($record_ids[$source_id]) ||
                !is_array($result) ||
                !in_array(
                    $result['status'] ?? '',
                    ['processing', 'created', 'skipped', 'failed'],
                    true
                ) ||
                (
                    isset($result['post_id']) &&
                    (
                        !is_int($result['post_id']) ||
                        $result['post_id'] < 0
                    )
                ) ||
                (
                    isset($result['error']) &&
                    (
                        !is_string($result['error']) ||
                        strlen($result['error']) > 2000
                    )
                )
            ) {
                throw new RuntimeException(
                    'The saved comic result is invalid.'
                );
            }
        }
    
        if ($progress['state'] === 'complete') {
            if (
                $progress['phase'] !== 'done' ||
                $progress['record_cursor'] !== count($record_ids) ||
                count($progress['results']) !== count($record_ids)
            ) {
                throw new RuntimeException(
                    'An unfinished import cannot be marked complete.'
                );
            }
    
            foreach ($progress['results'] as $result) {
                if (
                    !in_array(
                        $result['status'],
                        ['created', 'skipped'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Resolve failed or unfinished comics before completing the import.'
                    );
                }
            }
        }
    
        $progress['revision'] = $revision + 1;
        $progress['updated_at'] = time();
    
        if (
            !update_option($name, $progress, false) &&
            get_option($name) !== $progress
        ) {
            throw new RuntimeException(
                'Import progress could not be saved.'
            );
        }
    
        if (
            in_array(
                $progress['state'],
                ['complete', 'cancelled'],
                true
            )
        ) {
            $this->cleanup_pending(
                $user_id,
                $job['token']
            );
        }
    
        return $progress;
    }

    public function cancel_before_start(
        string $token,
        TCS_Collection_Import_Lock $lock
    ): array {
        $lock->assert_owned();
    
        $job = $this->read_pending($token);
        $this->assert_can_change_pending();
    
        if (
            !in_array(
                $job['state'] ?? 'staged',
                ['staged', 'mapped'],
                true
            )
        ) {
            throw new RuntimeException(
                'This import has already started.'
            );
        }
    
        // Prevent the mapping from appearing if deletion fails.
        $job['state'] = 'cancelled';
        $this->save_pending($job, $lock);
    
        $name = 'tcs_collection_transfer_pending_'
            . get_current_user_id();
    
        delete_option($name);
        wp_cache_delete($name, 'options');
    
        if (get_option($name, false) !== false) {
            throw new RuntimeException(
                'The cancelled backup could not be removed.'
            );
        }
    
        wp_clear_scheduled_hook(
            'tcs_collection_transfer_cleanup_pending',
            [get_current_user_id(), $token]
        );
    
        return [
            'state' => 'cancelled',
            'phase' => 'terms',
            'revision' => 0,
            'term_cursor' => 0,
            'results' => [],
            'last_error' => '',
            'total_terms' => count(
                $job['backup']['term_definitions']
            ),
            'total_records' => count(
                $job['backup']['records']
            ),
        ];
    }
}
