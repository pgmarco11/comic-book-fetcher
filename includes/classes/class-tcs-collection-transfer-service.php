<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/class-tcs-collection-backup-exporter.php';
require_once __DIR__ . '/class-tcs-collection-backup-validator.php';
require_once __DIR__ . '/class-tcs-collection-transfer-store.php';
require_once __DIR__ . '/class-tcs-collection-import-planner.php';
require_once __DIR__ . '/class-tcs-collection-taxonomy-restorer.php';

/** Stable transfer API composed from focused backup components. */
class TCS_Collection_Transfer_Service {
    private $exporter;
    private $validator;
    private $store;
    private $planner;
    private $taxonomy_restorer;

    public function __construct(
        ?TCS_Collection_Backup_Exporter $exporter = null,
        ?TCS_Collection_Backup_Validator $validator = null,
        ?TCS_Collection_Transfer_Store $store = null,
        ?TCS_Collection_Import_Planner $planner = null,
        ?TCS_Collection_Taxonomy_Restorer $taxonomy_restorer = null
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
        return $this->store->stage_backup($backup, $owners);
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
        $this->store->cleanup_pending($user_id, $token);
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
}
