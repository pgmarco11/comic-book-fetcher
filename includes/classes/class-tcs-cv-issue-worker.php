<?php
defined('ABSPATH') || exit;

/** Owns cv issue worker behavior; legacy function entry points delegate here. */
class TCS_CV_Issue_Worker {
    public function read_cv_issue_queue(): array
    {
        wp_cache_delete(
            COMICBOOKS_CV_ISSUE_QUEUE,
            'options'
        );

        wp_cache_delete('notoptions', 'options');

        $queue = get_option(
            COMICBOOKS_CV_ISSUE_QUEUE,
            []
        );

        return is_array($queue) ? $queue : [];
    }

    public function save_cv_issue_queue(
        array $queue
    ): bool {
        $queue = array_values($queue);

        if (empty($queue)) {
            delete_option(COMICBOOKS_CV_ISSUE_QUEUE);

            return true;
        }

        $updated = update_option(
            COMICBOOKS_CV_ISSUE_QUEUE,
            $queue,
            false
        );

        /*
         * update_option() also returns false when the stored value was
         * already identical, so compare the saved value before treating
         * false as an error.
        */
        return $updated || $this->read_cv_issue_queue() === $queue;
    }

    public function schedule_cv_issue_worker(
        int $delay = 2
    ): void {
        $scope = 'cv-issue-enrichment-schedule';

        if (!MetronClient::acquire_lock($scope, 0.5)) {
            error_log('Comic Vine worker scheduling is busy.');
            return;
        }

        try {
            $hook = 'comicbooks_process_cv_issue_enrichment_queue';
            $requested_at = time() + max(1, $delay);

            // Re-read cron state after taking the scheduling lock.
            wp_cache_delete('cron', 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');

            $existing_at = wp_next_scheduled($hook);

            // Keep an existing run that is already early enough.
            if (
                $existing_at !== false &&
                $existing_at <= $requested_at
            ) {
                return;
            }

            // Remove the later run before scheduling its replacement.
            if (
                $existing_at !== false &&
                !wp_unschedule_event($existing_at, $hook)
            ) {
                error_log(
                    'Could not move the Comic Vine worker earlier.'
                );
                return;
            }

            if (!wp_schedule_single_event($requested_at, $hook)) {
                // Try to restore the original run on failure.
                if ($existing_at !== false) {
                    wp_schedule_single_event($existing_at, $hook);
                }

                error_log(
                    'Could not schedule the Comic Vine worker.'
                );
            }
        } finally {
            MetronClient::release_lock($scope);
        }
    }

    public function issue_needs_cv_enrichment(
        array $issue
    ): bool {
        $metron_id = absint($issue['id'] ?? 0);

        if (!$metron_id || !empty($issue['image'])) {
            return false;
        }

        $cv_id = absint($issue['cv_id'] ?? 0);

        if (!$cv_id) {
            $mapping = get_transient(
                "metron:issue_cv_id:{$metron_id}"
            );

            if ($mapping !== false) {
                $cv_id = is_array($mapping)
                    ? absint($mapping['cv_id'] ?? 0)
                    : absint($mapping);

                /*
                 * A cached null mapping means Metron already confirmed
                 * that no Comic Vine issue is available.
                 */
                if (!$cv_id) {
                    return false;
                }
            }
        }

        /*
         * A known Comic Vine ID with a cached image, including a
         * confirmed cached miss, does not need another request yet.
         */
        if (
            $cv_id &&
            get_transient("cv_issue_image_{$cv_id}") !== false
        ) {
            return false;
        }

        return true;
    }

    public function queue_cv_issue_enrichment(
        array $issues,
        int $attempt = 0,
        bool $requested = false
    ): void {
        $normalized = [];

        foreach ($issues as $issue) {
            if (
                !is_array($issue) ||
                !$this->issue_needs_cv_enrichment($issue)
            ) {
                continue;
            }

            $metron_id = absint($issue['id'] ?? 0);

            $normalized[$metron_id] = [
                'id' => $metron_id,
                'cv_id' => absint($issue['cv_id'] ?? 0),
                'image' => '',

                'queue_priority' => $requested
                    ? 1
                    : min(1, absint($issue['queue_priority'] ?? 0)),

                'queue_requested_at' => $requested
                    ? time()
                    : absint($issue['queue_requested_at'] ?? 0),
            ];
        }

        if (empty($normalized)) {
            return;
        }

        /*
         * Wait briefly because this operation only performs one small
         * option update.
         */
        if (
            !MetronClient::acquire_lock(
                COMICBOOKS_CV_ISSUE_QUEUE_LOCK,
                0.5
            )
        ) {
            /*
             * Do not discard the IDs. Pass them to a later merge attempt.
             */
            $delay = min(
                30,
                2 ** min(max(0, $attempt), 4)
            );

            wp_schedule_single_event(
                time() + $delay,
                'comicbooks_retry_cv_issue_enrichment_queue',
                [
                    array_values($normalized),
                    $attempt + 1,
                ]
            );

            return;
        }

        try {
            $queue = $this->read_cv_issue_queue();

            /*
             * Key both collections by Metron ID so repeated page loads
             * cannot create duplicate work.
             */
            $merged = [];

            foreach ($queue as $queued_issue) {
                $queued_id = absint(
                    $queued_issue['id'] ?? 0
                );

                if ($queued_id) {
                    $merged[$queued_id] = $queued_issue;
                }
            }

            foreach ($normalized as $metron_id => $issue) {
                $existing = $merged[$metron_id] ?? [];

                $issue['queue_priority'] = max(
                    absint($existing['queue_priority'] ?? 0),
                    absint($issue['queue_priority'] ?? 0)
                );

                $issue['queue_requested_at'] = max(
                    absint($existing['queue_requested_at'] ?? 0),
                    absint($issue['queue_requested_at'] ?? 0)
                );

                // Preserve a known mapping if the new entry lacks one.
                if (
                    empty($issue['cv_id']) &&
                    !empty($existing['cv_id'])
                ) {
                    $issue['cv_id'] = absint($existing['cv_id']);
                }

                $merged[$metron_id] = $issue;
            }

            if (
                !$this->save_cv_issue_queue(
                    array_values($merged)
                )
            ) {
                throw new RuntimeException(
                    'Could not save Comic Vine issue queue.'
                );
            }
        } catch (Throwable $error) {
            error_log(
                'Comic Vine issue queue: ' .
                $error->getMessage()
            );

            wp_schedule_single_event(
                time() + 10,
                'comicbooks_retry_cv_issue_enrichment_queue',
                [
                    array_values($normalized),
                    $attempt + 1,
                ]
            );
        } finally {
            MetronClient::release_lock(
                COMICBOOKS_CV_ISSUE_QUEUE_LOCK
            );
        }

        $this->schedule_cv_issue_worker();
    }

    public function retry_cv_issue_enrichment_queue(
        $issues,
        $attempt = 0
    ): void {
        $this->queue_cv_issue_enrichment(
            is_array($issues) ? $issues : [],
            absint($attempt)
        );
    }

    public function known_cv_issue_id(array $issue): int
    {
        $cv_id = absint($issue['cv_id'] ?? 0);

        if ($cv_id) {
            return $cv_id;
        }

        $metron_id = absint($issue['id'] ?? 0);

        if (!$metron_id) {
            return 0;
        }

        $mapping = get_transient(
            "metron:issue_cv_id:{$metron_id}"
        );

        return is_array($mapping)
            ? absint($mapping['cv_id'] ?? 0)
            : absint($mapping);
    }

    public function next_cv_mapping_time(): int
    {
        $key = COMICBOOKS_CV_MAPPING_NEXT_AT;

        // Refresh option-backed transients in long-running requests.
        wp_cache_delete('_transient_' . $key, 'options');
        wp_cache_delete('_transient_timeout_' . $key, 'options');
        wp_cache_delete('notoptions', 'options');

        return max(0, (int) get_transient($key));
    }

    public function cv_issue_worker_delay(array $queue): int
    {
        foreach ($queue as $issue) {
            if ($this->known_cv_issue_id($issue)) {
                return 5;
            }
        }

        return max(
            5,
            $this->next_cv_mapping_time() - time()
        );
    }

    public function issue_cv_enrichment_complete(
        array $issue
    ): bool {
        $metron_id = absint($issue['id'] ?? 0);

        if (!$metron_id) {
            return true;
        }

        $cv_id = $this->known_cv_issue_id($issue);

        if ($cv_id) {
            // An empty string is a confirmed missing image.
            // False means no confirmed result is cached.
            return get_transient(
                "cv_issue_image_{$cv_id}"
            ) !== false;
        }

        $mapping = get_transient(
            "metron:issue_cv_id:{$metron_id}"
        );

        // A cached mapping with no CV ID is a confirmed miss.
        // An absent mapping still needs resolution.
        return $mapping !== false;
    }

    public function process_cv_issue_enrichment_queue(): void
    {
        if (
            !MetronClient::acquire_lock(
                COMICBOOKS_CV_ISSUE_QUEUE_LOCK,
                0.5
            )
        ) {
            $this->schedule_cv_issue_worker(5);
            return;
        }

        $batch = [];
        $batch_limit = 10;
        $known_batch = false;
        $queue_has_more = false;
        $mapping_lock_held = false;

        try {
            $queue = $this->read_cv_issue_queue();

            if (empty($queue)) {
                delete_option(COMICBOOKS_CV_ISSUE_QUEUE);
                return;
            }

            /*
             * Requested issues first, newest request first.
             * Equal-ranked jobs retain their existing order on PHP 8.3.
             */
            usort($queue, static function ($a, $b) {
                $priority_a = absint($a['queue_priority'] ?? 0);
                $priority_b = absint($b['queue_priority'] ?? 0);

                if ($priority_a !== $priority_b) {
                    return $priority_b <=> $priority_a;
                }

                if ($priority_a === 0) {
                    return 0;
                }

                return absint($b['queue_requested_at'] ?? 0)
                    <=> absint($a['queue_requested_at'] ?? 0);
            });

            $first_index = null;
            $next_mapping_at = $this->next_cv_mapping_time();

            foreach ($queue as $index => $candidate) {
                // Known IDs do not need a Metron mapping lookup.
                if ($this->known_cv_issue_id($candidate)) {
                    $first_index = $index;
                    break;
                }

                // Leave unresolved jobs queued until their lane is eligible.
                if ($next_mapping_at > time()) {
                    continue;
                }

                // Only one mapping worker may be active at a time.
                if (
                    !MetronClient::acquire_lock(
                        COMICBOOKS_CV_MAPPING_WORKER_LOCK,
                        0
                    )
                ) {
                    continue;
                }

                $mapping_lock_held = true;

                // Reserve the next lookup time before releasing the queue lock.
                if (
                    !set_transient(
                        COMICBOOKS_CV_MAPPING_NEXT_AT,
                        time() + COMICBOOKS_CV_MAPPING_INTERVAL,
                        HOUR_IN_SECONDS
                    )
                ) {
                    throw new RuntimeException(
                        'Could not reserve the next Metron mapping lookup.'
                    );
                }

                $first_index = $index;
                break;
            }

            if ($first_index === null) {
                // No eligible job was removed from the queue.
                $this->schedule_cv_issue_worker(
                    $this->cv_issue_worker_delay($queue)
                );

                return;
            }

            $first = $queue[$first_index];

            unset($queue[$first_index]);

            $queue = array_values($queue);

            $first_priority = absint(
                $first['queue_priority'] ?? 0
            );

            $first_cv_id = $this->known_cv_issue_id($first);
            $known_batch = $first_cv_id > 0;

            if ($known_batch) {
                $first['cv_id'] = $first_cv_id;
            }

            $batch[] = $first;

            /*
             * Expand only a known-ID batch.
             * Do not pull background jobs into a visitor-priority batch.
             */
            if ($known_batch) {
                foreach ($queue as $index => $candidate) {
                    if (count($batch) >= $batch_limit) {
                        break;
                    }

                    if (
                        absint($candidate['queue_priority'] ?? 0)
                        !== $first_priority
                    ) {
                        continue;
                    }

                    $cv_id = $this->known_cv_issue_id(
                        $candidate
                    );

                    if (!$cv_id) {
                        continue;
                    }

                    $candidate['cv_id'] = $cv_id;
                    $batch[] = $candidate;

                    unset($queue[$index]);
                }
            }

            $queue = array_values($queue);
            $queue_has_more = !empty($queue);

            if (!$this->save_cv_issue_queue($queue)) {
                throw new RuntimeException(
                    'Could not update Comic Vine issue queue.'
                );
            }
        } catch (Throwable $error) {
            error_log(
                'Comic Vine issue worker: ' .
                $error->getMessage()
            );

            if ($mapping_lock_held) {
                MetronClient::release_lock(
                    COMICBOOKS_CV_MAPPING_WORKER_LOCK
                );

                $mapping_lock_held = false;
            }

            $this->schedule_cv_issue_worker(10);
            return;
        } finally {
            MetronClient::release_lock(
                COMICBOOKS_CV_ISSUE_QUEUE_LOCK
            );
        }

        /*
         * API work happens after releasing the queue lock.
         */
        try {
            $service = new ComicDataService(
                new MetronClient()
            );

            if ($known_batch) {
                $service->get_comicvine_issue_images_batch(
                    array_column($batch, 'cv_id')
                );
            } else {
                // The batch contains one unresolved issue.
                $service->get_cv_info_batch($batch);
            }
        } catch (Throwable $error) {
            error_log(
                'Comic Vine issue enrichment: ' .
                $error->getMessage()
            );
        } finally {
            if ($mapping_lock_held) {
                MetronClient::release_lock(
                    COMICBOOKS_CV_MAPPING_WORKER_LOCK
                );

                $mapping_lock_held = false;
            }
        }

        /*
         * Verify each issue independently.
         * A normal method return does not prove every lookup succeeded.
         */
        $incomplete = [];

        foreach ($batch as $issue) {
            if (!$this->issue_cv_enrichment_complete($issue)) {
                $incomplete[] = $issue;
            }
        }

        if (!empty($incomplete)) {
            $retry_args = [
                $incomplete,
                1,
            ];

            if (
                !wp_next_scheduled(
                    'comicbooks_retry_cv_issue_enrichment_queue',
                    $retry_args
                )
            ) {
                wp_schedule_single_event(
                    time() + 30,
                    'comicbooks_retry_cv_issue_enrichment_queue',
                    $retry_args
                );
            }
        }

        if ($queue_has_more) {
            $this->schedule_cv_issue_worker(
                $this->cv_issue_worker_delay($queue)
            );
        }
    }

}
