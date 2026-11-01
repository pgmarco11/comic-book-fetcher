<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/class-tcs-collection-backup-exporter.php';
require_once __DIR__ . '/class-tcs-collection-backup-validator.php';
require_once __DIR__ . '/class-tcs-collection-transfer-store.php';
require_once __DIR__ . '/class-tcs-collection-import-planner.php';
require_once __DIR__ . '/class-tcs-collection-taxonomy-restorer.php';
require_once __DIR__ . '/class-tcs-collection-record-restorer.php';
require_once __DIR__ . '/class-tcs-collection-metadata-restorer.php';
require_once __DIR__ . '/class-tcs-collection-record-finalizer.php';
require_once __DIR__ . '/class-tcs-collection-import-lock.php';
require_once __DIR__ . '/class-tcs-collection-import-runner.php';

/** Stable transfer API composed from focused backup components. */
class TCS_Collection_Transfer_Service {
    private $exporter;
    private $validator;
    private $store;
    private $planner;
    private $taxonomy_restorer;
    private $record_restorer;
    private $record_finalizer;

    public function __construct(
        ?TCS_Collection_Backup_Exporter $exporter = null,
        ?TCS_Collection_Backup_Validator $validator = null,
        ?TCS_Collection_Transfer_Store $store = null,
        ?TCS_Collection_Import_Planner $planner = null,
        ?TCS_Collection_Taxonomy_Restorer $taxonomy_restorer = null,
        ?TCS_Collection_Record_Restorer $record_restorer = null,
        ?TCS_Collection_Record_Finalizer $record_finalizer = null
    ) {
        $this->exporter = $exporter
            ?? new TCS_Collection_Backup_Exporter();
    
        $this->validator = $validator
            ?? new TCS_Collection_Backup_Validator();
    
        $this->store = $store
            ?? new TCS_Collection_Transfer_Store();
    
        $this->planner = $planner
            ?? new TCS_Collection_Import_Planner();
    
        $this->taxonomy_restorer = $taxonomy_restorer
            ?? new TCS_Collection_Taxonomy_Restorer(
                $this->exporter
            );

        $this->record_restorer = $record_restorer
            ?? new TCS_Collection_Record_Restorer(
                $this->validator,
                $this->planner,
                new TCS_Collection_Metadata_Restorer(
                    $this->validator,
                    $this->exporter
                )
            );

        $this->record_finalizer = $record_finalizer
            ?? new TCS_Collection_Record_Finalizer(
                $this->validator,
                $this->planner,
                $this->record_restorer,
                $this->exporter
            );
    }

    public function read_meta(
        int $object_id,
        string $type = 'post'
    ): array {
        return $this->exporter->read_meta($object_id, $type);
    }

    public function read_record(int $post_id): array {
        return $this->exporter->read_record($post_id);
    }

    public function collect_term(
        WP_Term $term,
        array &$definitions,
        array &$visiting
    ): string {
        return $this->exporter->collect_term($term, $definitions, $visiting);
    }

    public function read_taxonomies(
        int $post_id
    ): array {
        return $this->exporter->read_taxonomies($post_id);
    }

    public function build_backup(
        int $owner_id
    ): array {
        return $this->exporter->build_backup($owner_id);
    }

    public function validate_meta_rows(
        array $rows,
        string $context
    ): int {
        return $this->validator->validate_meta_rows($rows, $context);
    }

    public function validate_metadata(
        array $backup
    ): array {
        return $this->validator->validate_metadata($backup);
    }

    public function validate_taxonomies(
        array $backup
    ): int {
        return $this->validator->validate_taxonomies($backup);
    }

    public function validate_owners(
        array $backup
    ): array {
        return $this->validator->validate_owners($backup);
    }

    public function stage_backup(
        array $backup,
        array $owners
    ): string {
        return $this->with_import_lock(
            function () use ($backup, $owners) {
                return $this->store->stage_backup(
                    $backup,
                    $owners
                );
            }
        );
    }

    public function read_pending(
        string $token
    ): array {
        return $this->store->read_pending($token);
    }

    public function cleanup_pending(
        int $user_id,
        string $token
    ): void {
        try {
            $this->with_import_lock(
                function () use ($user_id, $token) {
                    $this->store->cleanup_pending(
                        $user_id,
                        $token
                    );
                }
            );
        } catch (Throwable $error) {
            error_log(
                'Collection backup cleanup deferred: '
                . $error->getMessage()
            );
    
            wp_schedule_single_event(
                time() + 60,
                'tcs_collection_transfer_cleanup_pending',
                [$user_id, $token]
            );
        }
    }

    public function resolve_owner_map(
        array $owners,
        array $submitted
    ): array {
        return $this->planner->resolve_owner_map($owners, $submitted);
    }

    public function record_origin(
        array $backup,
        array $record
    ): string {
        return $this->planner->record_origin($backup, $record);
    }

    public function existing_records(
        array $backup,
        array $owner_map
    ): array {
        return $this->planner->existing_records($backup, $owner_map);
    }

    public function build_import_plan(
        array $job
    ): array {
        return $this->planner->build_import_plan($job);
    }

    public function restore_taxonomies(array $backup): array {
        return $this->taxonomy_restorer->restore($backup);
    }

    public function validate_post_fields(array $backup): int {
        return $this->validator->validate_post_fields($backup);
    }

    public function create_record_draft(
        array $job,
        int $source_post_id,
        array $post_map = []
    ): array {
        return $this->record_restorer->create_draft(
            $job,
            $source_post_id,
            $post_map
        );
    }

    public function restore_record_metadata(
        array $job,
        int $source_post_id,
        array $post_map = []
    ): array {
        return $this->record_restorer->restore_metadata(
            $job,
            $source_post_id,
            $post_map
        );
    }

    public function restore_record(
        array $job,
        int $source_post_id,
        array $term_map,
        array $post_map = []
    ): array {
        return $this->record_finalizer->restore(
            $job,
            $source_post_id,
            $term_map,
            $post_map
        );
    }
    public function with_import_lock(callable $work) {
        $lock = new TCS_Collection_Import_Lock();
    
        return $lock->run($work);
    }

    public function save_owner_mapping(
        string $token,
        array $submitted
    ): void {
        $this->with_import_lock(
            function (
                TCS_Collection_Import_Lock $lock
            ) use ($token, $submitted) {
                $this->store->assert_can_change_pending();
    
                $job = $this->store->read_pending($token);
    
                if (
                    !in_array(
                        $job['state'] ?? 'staged',
                        ['staged', 'mapped'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'This job can no longer change its owner mapping.'
                    );
                }
    
                $job['owner_map'] =
                    $this->planner->resolve_owner_map(
                        $job['owners'],
                        $submitted
                    );
    
                $plan = $this->planner->build_import_plan($job);
    
                $job['owner_map'] = $plan['owner_map'];
                $job['import_plan'] = $plan;
                $job['state'] = 'mapped';
    
                $this->store->save_pending($job, $lock);
            }
        );
    }
    
    public function begin_import_job(string $token): array {
        return $this->with_import_lock(
            function (
                TCS_Collection_Import_Lock $lock
            ) use ($token): array {
                $job = $this->store->read_pending($token);
    
                if (
                    !is_array($job['owner_map'] ?? null) ||
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
    
                $plan = $this->planner->build_import_plan($job);
    
                if ($plan['owner_map'] !== $job['owner_map']) {
                    throw new RuntimeException(
                        'Destination permissions changed. Review this import before continuing.'
                    );
                }
    
                return $this->store->begin_import(
                    $token,
                    $plan,
                    $lock
                );
            }
        );
    }
    
    public function read_import_progress(string $token): array {
        return $this->store->read_progress($token);
    }

    public function process_import_batch(
        string $token,
        int $limit = 3
    ): array {
        return $this->with_import_lock(
            function (
                TCS_Collection_Import_Lock $lock
            ) use ($token, $limit): array {
                $runner = new TCS_Collection_Import_Runner(
                    $this->store,
                    $this->planner,
                    $this->taxonomy_restorer,
                    $this->record_finalizer
                );
    
                return $runner->run(
                    $token,
                    $limit,
                    $lock
                );
            }
        );
    }
    
    public function resume_import_job(string $token): array {
        return $this->with_import_lock(
            function (
                TCS_Collection_Import_Lock $lock
            ) use ($token): array {
                $progress = $this->store->read_progress($token);
    
                if ($progress['state'] !== 'paused') {
                    return $progress;
                }
    
                $job = $this->store->read_pending($token);
                $plan = $this->planner->build_import_plan($job);
    
                if ($plan['owner_map'] !== $job['owner_map']) {
                    throw new RuntimeException(
                        'Destination permissions changed. Review this import before continuing.'
                    );
                }
    
                $progress['state'] = 'ready';
                $progress['last_error'] = '';
    
                return $this->store->save_progress(
                    $token,
                    $progress,
                    $lock
                );
            }
        );
    }
}
