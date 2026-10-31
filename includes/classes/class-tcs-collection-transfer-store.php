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

        if ($job['expires_at'] <= time()) {
            $this->cleanup_pending(
                $user_id,
                $token
            );

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

        $job = get_option($option_name, false);

        if (
            is_array($job) &&
            is_string($job['token'] ?? null) &&
            hash_equals($job['token'], $token) &&
            is_int($job['expires_at'] ?? null) &&
            $job['expires_at'] <= time()
        ) {
            delete_option($option_name);
        }
    }
}
