<?php
defined('ABSPATH') || exit;

class TCS_Collection_Import_Runner {
    private $store;
    private $planner;
    private $taxonomies;
    private $records;

    public function __construct(
        TCS_Collection_Transfer_Store $store,
        TCS_Collection_Import_Planner $planner,
        TCS_Collection_Taxonomy_Restorer $taxonomies,
        TCS_Collection_Record_Finalizer $records
    ) {
        $this->store = $store;
        $this->planner = $planner;
        $this->taxonomies = $taxonomies;
        $this->records = $records;
    }

    public function run(
        string $token,
        int $limit,
        TCS_Collection_Import_Lock $lock
    ): array {
        $lock->assert_owned();

        $progress = $this->store->read_progress($token);

        if (
            in_array(
                $progress['state'],
                ['paused', 'complete', 'cancelled'],
                true
            )
        ) {
            return $progress;
        }

        $job = $this->store->read_pending($token);
        $limit = max(1, min(5, $limit));

        try {
            $plan = $this->planner->build_import_plan($job);

            if ($plan['owner_map'] !== $job['owner_map']) {
                throw new RuntimeException(
                    'Destination permissions changed. Review this import before continuing.'
                );
            }

            $term_parents = array_column(
                $job['backup']['term_definitions'],
                'parent_ref',
                'ref'
            );

            $record_parents = [];
            $record_indexes = [];

            foreach (
                $job['backup']['records']
                as $index => $record
            ) {
                $source_id = $record['source_post_id'];

                $record_parents[$source_id] =
                    $record['post']['post_parent'] ?: null;

                $record_indexes[$source_id] = $index;
            }

            $term_order = $this->parent_order(
                $term_parents
            );

            $record_order = $this->parent_order(
                $record_parents
            );
        } catch (Throwable $error) {
            return $this->pause(
                $token,
                $progress,
                $error,
                $lock
            );
        }

        $deadline = microtime(true) + 4;

        $progress['state'] = 'running';
        $progress['last_error'] = '';

        // Existing records need bookkeeping only.
        if ($plan['create_count'] === 0) {
            $progress['phase'] = 'records';
            $progress['term_cursor'] = count($term_order);

            $steps = 0;

            while (
                $progress['record_cursor'] <
                    count($record_order) &&
                $steps < 100 &&
                microtime(true) < $deadline
            ) {
                $lock->assert_owned();

                $source_id = $record_order[
                    $progress['record_cursor']
                ];

                $item = $plan['records'][
                    $record_indexes[$source_id]
                ];

                $post_id = $item['existing_post_id'];

                $progress['results'][$source_id] = [
                    'status' => $this->outcome(
                        $post_id,
                        $token
                    ),
                    'post_id' => $post_id,
                ];

                $progress['post_map'][$source_id] = $post_id;
                $progress['record_cursor']++;

                $steps++;
            }

            $this->mark_complete(
                $progress,
                count($record_order)
            );

            return $this->store->save_progress(
                $token,
                $progress,
                $lock
            );
        }

        $steps = 0;

        while (
            $steps < $limit &&
            microtime(true) < $deadline
        ) {
            $lock->assert_owned();

            if ($progress['phase'] === 'terms') {
                if (
                    $progress['term_cursor'] ===
                    count($term_order)
                ) {
                    $progress['phase'] = 'records';

                    $progress = $this->store->save_progress(
                        $token,
                        $progress,
                        $lock
                    );

                    continue;
                }

                $reference = $term_order[
                    $progress['term_cursor']
                ];

                try {
                    $result = $this->taxonomies->restore_one(
                        $job['backup'],
                        $reference,
                        $progress['term_map']
                    );
                } catch (Throwable $error) {
                    return $this->pause(
                        $token,
                        $progress,
                        $error,
                        $lock
                    );
                }

                $progress['term_map'] = $result['term_map'];
                $progress['term_cursor']++;

                $steps++;

                $progress = $this->store->save_progress(
                    $token,
                    $progress,
                    $lock
                );

                continue;
            }

            if (
                $progress['record_cursor'] ===
                count($record_order)
            ) {
                break;
            }

            $source_id = $record_order[
                $progress['record_cursor']
            ];

            $known = $progress['results'][$source_id]['status']
                ?? '';

            if (
                in_array(
                    $known,
                    ['created', 'skipped'],
                    true
                )
            ) {
                $progress['record_cursor']++;
                continue;
            }

            // Save intent before attempting restoration.
            $progress['results'][$source_id] = [
                'status' => 'processing',
            ];

            $progress = $this->store->save_progress(
                $token,
                $progress,
                $lock
            );

            try {
                $result = $this->records->restore(
                    $job,
                    $source_id,
                    $progress['term_map'],
                    $progress['post_map']
                );
            } catch (Throwable $error) {
                $lock->assert_owned();

                $progress['results'][$source_id] = [
                    'status' => 'failed',
                    'error' => $this->error_message($error),
                ];

                return $this->pause(
                    $token,
                    $progress,
                    $error,
                    $lock
                );
            }

            $lock->assert_owned();

            $post_id = $result['post_id'];

            $progress['results'][$source_id] = [
                'status' => $result['status'] === 'restored'
                    ? 'created'
                    : $this->outcome($post_id, $token),
                'post_id' => $post_id,
            ];

            $progress['post_map'][$source_id] = $post_id;
            $progress['record_cursor']++;

            $steps++;

            // Let checkpoint failures propagate to the caller.
            $progress = $this->store->save_progress(
                $token,
                $progress,
                $lock
            );
        }

        if (
            $progress['phase'] === 'terms' &&
            $progress['term_cursor'] === count($term_order)
        ) {
            $progress['phase'] = 'records';
        }

        $this->mark_complete(
            $progress,
            count($record_order)
        );

        return $this->store->save_progress(
            $token,
            $progress,
            $lock
        );
    }

    private function parent_order(array $parents): array {
        $ordered = [];
        $states = [];

        $visit = function ($id) use (
            &$visit,
            &$ordered,
            &$states,
            $parents
        ): void {
            if (($states[$id] ?? 0) === 2) {
                return;
            }

            if (
                !array_key_exists($id, $parents) ||
                ($states[$id] ?? 0) === 1
            ) {
                throw new RuntimeException(
                    'A missing parent or cycle prevents batch processing.'
                );
            }

            $states[$id] = 1;

            if ($parents[$id] !== null) {
                $visit($parents[$id]);
            }

            $states[$id] = 2;
            $ordered[] = $id;
        };

        foreach (array_keys($parents) as $id) {
            $visit($id);
        }

        return $ordered;
    }

    private function outcome(
        int $post_id,
        string $token
    ): string {
        return get_post_meta(
            $post_id,
            '_tcs_collection_transfer_job',
            true
        ) === $token
            ? 'created'
            : 'skipped';
    }

    private function mark_complete(
        array &$progress,
        int $total
    ): void {
        if (
            $progress['phase'] === 'records' &&
            $progress['record_cursor'] === $total
        ) {
            $progress['phase'] = 'done';
            $progress['state'] = 'complete';
        }
    }

    private function error_message(
        Throwable $error
    ): string {
        return sanitize_text_field(
            substr($error->getMessage(), 0, 1800)
        ) ?: 'The import item failed.';
    }

    private function pause(
        string $token,
        array $progress,
        Throwable $error,
        TCS_Collection_Import_Lock $lock
    ): array {
        $lock->assert_owned();

        $progress['state'] = 'paused';
        $progress['last_error'] =
            $this->error_message($error);

        return $this->store->save_progress(
            $token,
            $progress,
            $lock
        );
    }
}