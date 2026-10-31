<?php
defined('ABSPATH') || exit;

/** Owns collection transfer behavior; legacy function entry points delegate here. */
class TCS_Collection_Transfer {
    private $service;

    public function __construct(TCS_Collection_Transfer_Service $service) {
        $this->service = $service;
    }

    public function register_page() {
        add_management_page(
            'Collection backup',
            'Collection backup',
            'read',
            'tcs-collection-backup',
            'tcs_collection_transfer_render_page'
        );
    }

    public function requested_owner(): int {
        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_die(
                'You must sign in to access collection transfers.',
                'Collection transfer',
                ['response' => 403]
            );
        }

        // Non-administrators always operate on their own collection.
        if (!current_user_can('manage_options')) {
            return get_current_user_id();
        }

        $requested = isset($_POST['collection_owner'])
            && is_string($_POST['collection_owner'])
            ? wp_unslash($_POST['collection_owner'])
            : '';

        if ($requested === '' || !ctype_digit($requested)) {
            wp_die(
                'Choose a valid collection owner.',
                'Collection transfer',
                ['response' => 400]
            );
        }

        $owner_id = (int) $requested;

        // Only administrators can reach this point.
        // Zero represents all users.
        if ($owner_id === 0) {
            return 0;
        }

        if (!get_userdata($owner_id)) {
            wp_die(
                'The selected user does not exist.',
                'Collection transfer',
                ['response' => 400]
            );
        }

        return $owner_id;
    }

    public function render_page() {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return;
        }
        ?>
    <div class="wrap">

        <h1>Collection backup</h1>
        <p>
            Export and restore comic collections, including
            their metadata and taxonomy information.
        </p>

        <h2>Export collection</h2>
        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        >
            <input
                type="hidden"
                name="action"
                value="tcs_collection_export"
            >

            <?php wp_nonce_field('tcs_collection_export'); ?>

            <?php if (current_user_can('manage_options')) : ?>

                <p>
                    <label for="tcs-export-owner">
                        <strong>Collection owner</strong>
                    </label>
                </p>

                    <?php
                    wp_dropdown_users([
                            'name' => 'collection_owner',
                            'id' => 'tcs-export-owner',
                            'selected' => get_current_user_id(),
                            'show_option_all' => 'All users',
                        ]);
                        ?>

                <p>
                    Export the selected user's entire collection,
                    or choose All users.
                </p>

                <?php else : ?>

                <p>
                    Export your entire comic collection, including
                    its metadata and taxonomy information.
                </p>

                <?php endif; ?>

           <?php submit_button('Download collection backup (.json)'); ?>
        </form>

        <?php $this->render_owner_mapping(); ?>

        <h2>Import collection</h2>

        <p>
            PHP file limit:
            <?php echo esc_html(ini_get('upload_max_filesize')); ?>

            <br>

            PHP request limit:
            <?php echo esc_html(ini_get('post_max_size')); ?>

            <br>

            WordPress effective upload limit:
            <?php echo esc_html(size_format(wp_max_upload_size())); ?>
        </p>

        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            enctype="multipart/form-data"
        >
            <input
                type="hidden"
                name="action"
                value="tcs_collection_import_preview"
            >

            <?php wp_nonce_field('tcs_collection_import_preview'); ?>

            <p>
                <label for="tcs-import-file">
                    <strong>Collection JSON backup</strong>
                </label>
            </p>

            <input
                type="file"
                id="tcs-import-file"
                name="collection_backup"
                accept=".json,application/json"
                required
            >

            <p>
                Upload a backup to inspect it.
                No collection entries will be changed.
            </p>

            <?php submit_button('Preview backup', 'secondary'); ?>
        </form>

    </div>
    <?php
}

    public function handle_export() {
        check_admin_referer('tcs_collection_export');

        $owner_id = $this->requested_owner();

        try {
            $backup = $this->service->build_backup(
                $owner_id
            );

            $json = wp_json_encode(
                $backup,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            if (!is_string($json)) {
                throw new RuntimeException(
                    'The collection backup could not be encoded.'
                );
            }

            if (headers_sent()) {
                throw new RuntimeException(
                    'Output was sent before the download could start.'
                );
            }

            $scope = $owner_id === 0
                ? 'all-users'
                : 'user-' . $owner_id;

            $filename = sprintf(
                'collection-%s-%s.json',
                $scope,
                gmdate('Y-m-d-His')
            );

            nocache_headers();

            header(
                'Content-Type: application/json; charset=UTF-8'
            );

            header(
                'Content-Disposition: attachment; filename="' .
                $filename .
                '"'
            );

            header('X-Content-Type-Options: nosniff');

            echo $json;
            exit;
        } catch (Throwable $error) {
            wp_die(
                esc_html($error->getMessage()),
                'Collection export failed',
                [
                    'response' => 500,
                    'back_link' => true,
                ]
            );
        }
    }

    public function handle_import_preview() {
        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_die(
                'You must sign in to preview a collection backup.',
                'Collection import',
                ['response' => 403]
            );
        }

        check_admin_referer('tcs_collection_import_preview');

        try {
            $file = $_FILES['collection_backup'] ?? null;

            if (
                !is_array($file) ||
                !isset($file['error']) ||
                !is_int($file['error'])
            ) {
                throw new RuntimeException(
                    'No valid upload was received.'
                );
            }

            $upload_errors = [
                UPLOAD_ERR_INI_SIZE =>
                    'The file exceeds PHP upload_max_filesize, currently '
                    . ini_get('upload_max_filesize') . '.',

                UPLOAD_ERR_FORM_SIZE =>
                    'The file exceeds the form upload limit.',

                UPLOAD_ERR_PARTIAL =>
                    'Only part of the file arrived. Please upload it again.',

                UPLOAD_ERR_NO_FILE =>
                    'No file was selected.',

                UPLOAD_ERR_NO_TMP_DIR =>
                    'PHP is missing its temporary upload folder.',

                UPLOAD_ERR_CANT_WRITE =>
                    'PHP could not write the uploaded file to disk.',

                UPLOAD_ERR_EXTENSION =>
                    'A PHP extension stopped the upload.',
            ];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException(
                    $upload_errors[$file['error']]
                    ?? 'An unknown upload error occurred.'
                );
            }

            $temporary_path = $file['tmp_name'] ?? '';

            if (
                !is_string($temporary_path) ||
                !is_uploaded_file($temporary_path)
            ) {
                throw new RuntimeException(
                    'A valid uploaded file was not received.'
                );
            }

            $file_size = filesize($temporary_path);
            $maximum_size = 20 * 1024 * 1024;

            if (
                $file_size === false ||
                $file_size === 0 ||
                $file_size > $maximum_size
            ) {
                throw new RuntimeException(
                    'Choose a nonempty JSON backup no larger than 20 MiB.'
                );
            }

            $json = file_get_contents($temporary_path);

            if ($json === false) {
                throw new RuntimeException(
                    'The uploaded file could not be read.'
                );
            }

            $backup = json_decode(
                $json,
                true,
                128,
                JSON_THROW_ON_ERROR
            );

            if (
                !is_array($backup) ||
                ($backup['format'] ?? '') !== 'tcs-collection-backup' ||
                ($backup['schema_version'] ?? null) !== 1
            ) {
                throw new RuntimeException(
                    'This is not a supported collection backup.'
                );
            }

            if (
                !is_array($backup['records'] ?? null) ||
                !array_is_list($backup['records']) ||
                !is_array($backup['term_definitions'] ?? null) ||
                !array_is_list($backup['term_definitions']) ||
                !is_array($backup['owners'] ?? null) ||
                !array_is_list($backup['owners'])
            ) {
                throw new RuntimeException(
                    'The backup is missing its record, taxonomy, or owner lists.'
                );
            }

            $record_count = count($backup['records']);
            $term_count = count($backup['term_definitions']);
            $owner_count = count($backup['owners']);

            if (
                !is_int($backup['record_count'] ?? null) ||
                $backup['record_count'] !== $record_count
            ) {
                throw new RuntimeException(
                    'The declared entry count does not match the backup contents.'
                );
            }

            $metadata_counts = $this->service->validate_metadata(
                $backup
            );

            $assignment_count = $this->service->validate_taxonomies(
                $backup
            );

            $validated_owners = $this->service->validate_owners(
                $backup
            );

            $post_field_count = $this->service->validate_post_fields(
                $backup
            );

            $job_token = $this->service->stage_backup(
                $backup,
                $validated_owners
            );

            // Confirm that this request can read the saved job.
            $this->service->read_pending($job_token);

            $message = sprintf(
                'Backup opened successfully. It contains %d collection entries, %d taxonomy term definitions, and %d owner references. Nothing has been imported.',
                $record_count,
                $term_count,
                $owner_count
            );

            $message .= sprintf(
                ' Metadata encoding checks passed for %d comic metadata rows and %d term metadata rows.',
                $metadata_counts['comic_rows'],
                $metadata_counts['term_rows']
            );

            $message .= sprintf(
                ' Taxonomy checks passed for %d term assignments.',
                $assignment_count
            );

            $message .= sprintf(
                ' Owner checks passed for %d owner references; source record IDs are unique.',
                count($validated_owners)
            );

            $message .= sprintf(
                ' Post-field checks passed for %d comic records.',
                $post_field_count
            );

            $message .=
                ' The checked backup is staged for 30 minutes.';

        } catch (JsonException $error) {
            wp_die(
                'The file does not contain valid JSON.',
                'Collection import preview',
                ['response' => 400, 'back_link' => true]
            );
        } catch (Throwable $error) {
            wp_die(
                esc_html($error->getMessage()),
                'Collection import preview',
                ['response' => 400, 'back_link' => true]
            );
        }

        wp_die(
            esc_html($message),
            'Collection import preview',
            ['response' => 200, 'back_link' => true]
        );
    }

    public function render_owner_mapping(): void {
        $pending = get_option(
            'tcs_collection_transfer_pending_'
            . get_current_user_id(),
            false
        );

        if (
            !is_array($pending) ||
            !is_string($pending['token'] ?? null)
        ) {
            return;
        }

        try {
            $job = $this->service->read_pending(
                $pending['token']
            );
        } catch (Throwable $error) {
            echo '<p>' . esc_html($error->getMessage()) . '</p>';
            return;
        }

        $is_admin = current_user_can('manage_options');
        ?>
    <hr>

    <h2>Choose destination owners</h2>

    <p>
        These choices determine which accounts receive the comics.
        Saving this form does not import any entries.
    </p>

    <?php if (($job['state'] ?? '') === 'mapped') : ?>
        <p><strong>Destination mapping saved.</strong></p>
    <?php endif; ?>

    <?php if (isset($job['import_plan'])) : ?>
        <h3>Import preview</h3>

        <p>
            Would create:
            <strong>
                <?php
                echo esc_html(
                        (string) $job['import_plan']['create_count']
                    );
                    ?>
            </strong>

            <br>

            Would skip:
            <strong>
                <?php
                echo esc_html(
                        (string) $job['import_plan']['skip_count']
                    );
                    ?>
            </strong>
        </p>

        <p>No collection entries have been changed.</p>
    <?php endif; ?>

    <form
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
    >
        <input
            type="hidden"
            name="action"
            value="tcs_collection_transfer_save_mapping"
        >

        <input
            type="hidden"
            name="job_token"
            value="<?php echo esc_attr($job['token']); ?>"
        >

        <?php
        wp_nonce_field(
                'tcs_collection_transfer_save_mapping_'
                . $job['token']
            );
            ?>

        <?php foreach ($job['owners'] as $source_id => $owner) : ?>
            <p>
                <strong>
                    <?php
                    echo esc_html(sprintf(
                            '%s — source ID %d — %d entries',
                            $owner['user_login'] ?? 'Unknown source account',
                            $source_id,
                            $owner['record_count']
                        ));
                        ?>
                </strong>
            </p>

            <?php if ($is_admin) : ?>
                <p>
                    <label for="<?php
                        echo esc_attr('tcs-destination-' . $source_id);
                        ?>">
                        Destination account
                    </label>
                </p>

                <?php
                wp_dropdown_users([
                        'name' => 'destination_owners[' . $source_id . ']',
                        'id' => 'tcs-destination-' . $source_id,
                        'show_option_none' => 'Choose destination account',
                        'option_none_value' => '0',
                        'selected' => $job['owner_map'][$source_id] ?? 0,
                    ]);
                    ?>
            <?php else : ?>
                <p>These entries will go into your signed-in account.</p>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php submit_button('Save mapping and preview import'); ?>
    </form>

    <hr>
    <?php
}

    public function handle_save_mapping() {
        try {
            $token = isset($_POST['job_token'])
                && is_string($_POST['job_token'])
                ? wp_unslash($_POST['job_token'])
                : '';

            check_admin_referer(
                'tcs_collection_transfer_save_mapping_' . $token
            );

            $job = $this->service->read_pending($token);

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

            $submitted = $_POST['destination_owners'] ?? [];

            if (!is_array($submitted)) {
                throw new RuntimeException(
                    'The destination selections are invalid.'
                );
            }

            $mapping = $this->service->resolve_owner_map(
                $job['owners'],
                wp_unslash($submitted)
            );

            $job['owner_map'] = $mapping;

            $plan = $this->service->build_import_plan($job);

            $job['owner_map'] = $plan['owner_map'];
            $job['import_plan'] = $plan;
            $job['state'] = 'mapped';

            $option_name =
                'tcs_collection_transfer_pending_'
                . get_current_user_id();

            $saved = update_option($option_name, $job, false);

            if (!$saved && get_option($option_name) !== $job) {
                throw new RuntimeException(
                    'The destination mapping could not be saved.'
                );
            }

            wp_safe_redirect(
                admin_url('tools.php?page=tcs-collection-backup')
            );
            exit;
        } catch (Throwable $error) {
            wp_die(
                esc_html($error->getMessage()),
                'Destination mapping',
                ['response' => 400, 'back_link' => true]
            );
        }
    }

}
