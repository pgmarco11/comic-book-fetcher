<?php
defined('ABSPATH') || exit;

require_once __DIR__ . 'classes/class-tcs-collection-transfer.php';
require_once __DIR__ . 'classes/class-tcs-collection-transfer-service.php';

function tcs_collection_transfer_controller(): TCS_Collection_Transfer {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Collection_Transfer(tcs_collection_transfer_service());
    }
    return $instance;
}

function tcs_collection_transfer_service(): TCS_Collection_Transfer_Service {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Collection_Transfer_Service();
    }
    return $instance;
}


function tcs_collection_transfer_register_page() {
    return tcs_collection_transfer_controller()->register_page();
}

add_action(
    'admin_menu',
    'tcs_collection_transfer_register_page'
);

function tcs_collection_transfer_requested_owner(): int {
    return tcs_collection_transfer_controller()->requested_owner();
}

function tcs_collection_transfer_render_page() {
    return tcs_collection_transfer_controller()->render_page();
}

function tcs_collection_transfer_read_meta(
    int $object_id,
    string $type = 'post'
): array {
    return tcs_collection_transfer_service()->read_meta($object_id, $type);
}

function tcs_collection_transfer_read_record(int $post_id): array {
    return tcs_collection_transfer_service()->read_record($post_id);
}

function tcs_collection_transfer_collect_term(
    WP_Term $term,
    array &$definitions,
    array &$visiting
): string {
    return tcs_collection_transfer_service()->collect_term($term, $definitions, $visiting);
}

function tcs_collection_transfer_read_taxonomies(
    int $post_id
): array {
    return tcs_collection_transfer_service()->read_taxonomies($post_id);
}

function tcs_collection_transfer_build_backup(
    int $owner_id
): array {
    return tcs_collection_transfer_service()->build_backup($owner_id);
}

function tcs_collection_transfer_handle_export() {
    return tcs_collection_transfer_controller()->handle_export();
}
add_action(
    'admin_post_tcs_collection_export',
    'tcs_collection_transfer_handle_export'
);

function tcs_collection_transfer_validate_meta_rows(
    array $rows,
    string $context
): int {
    return tcs_collection_transfer_service()->validate_meta_rows($rows, $context);
}

function tcs_collection_transfer_validate_metadata(
    array $backup
): array {
    return tcs_collection_transfer_service()->validate_metadata($backup);
}

function tcs_collection_transfer_validate_taxonomies(
    array $backup
): int {
    return tcs_collection_transfer_service()->validate_taxonomies($backup);
}

function tcs_collection_transfer_validate_owners(
    array $backup
): array {
    return tcs_collection_transfer_service()->validate_owners($backup);
}

function tcs_collection_transfer_stage_backup(
    array $backup,
    array $owners
): string {
    return tcs_collection_transfer_service()->stage_backup($backup, $owners);
}

function tcs_collection_transfer_read_pending(
    string $token
): array {
    return tcs_collection_transfer_service()->read_pending($token);
}

function tcs_collection_transfer_cleanup_pending(
    int $user_id,
    string $token
): void {
    tcs_collection_transfer_service()->cleanup_pending($user_id, $token);
}

add_action(
    'tcs_collection_transfer_cleanup_pending',
    'tcs_collection_transfer_cleanup_pending',
    10,
    2
);

function tcs_collection_transfer_handle_import_preview() {
    return tcs_collection_transfer_controller()->handle_import_preview();
}

add_action(
    'admin_post_tcs_collection_import_preview',
    'tcs_collection_transfer_handle_import_preview'
);

function tcs_collection_transfer_resolve_owner_map(
    array $owners,
    array $submitted
): array {
    return tcs_collection_transfer_service()->resolve_owner_map($owners, $submitted);
}
function tcs_collection_transfer_render_owner_mapping(): void {
    tcs_collection_transfer_controller()->render_owner_mapping();
}

function tcs_collection_transfer_handle_save_mapping() {
    return tcs_collection_transfer_controller()->handle_save_mapping();
}

add_action(
    'admin_post_tcs_collection_transfer_save_mapping',
    'tcs_collection_transfer_handle_save_mapping'
);

function tcs_collection_transfer_record_origin(
    array $backup,
    array $record
): string {
    return tcs_collection_transfer_service()->record_origin($backup, $record);
}

function tcs_collection_transfer_existing_records(
    array $backup,
    array $owner_map
): array {
    return tcs_collection_transfer_service()->existing_records($backup, $owner_map);
}

function tcs_collection_transfer_build_import_plan(
    array $job
): array {
    return tcs_collection_transfer_service()->build_import_plan($job);
}