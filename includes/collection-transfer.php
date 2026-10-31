<?php
defined('ABSPATH') || exit;

function tcs_collection_transfer_register_page() {
    add_management_page(
        'Collection backup',
        'Collection backup',
        'read',
        'tcs-collection-backup',
        'tcs_collection_transfer_render_page'
    );
}

add_action(
    'admin_menu',
    'tcs_collection_transfer_register_page'
);

function tcs_collection_transfer_requested_owner(): int {
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

function tcs_collection_transfer_render_page() {
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

        <?php tcs_collection_transfer_render_owner_mapping(); ?>

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

function tcs_collection_transfer_read_meta(
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

function tcs_collection_transfer_read_record(int $post_id): array {
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

    $taxonomy_data = tcs_collection_transfer_read_taxonomies(
        $post_id
    );
    
    return [
        'source_post_id' => (int) $post->ID,
        'source_owner_id' => (int) $post->post_author,
        'post' => $post_fields,
        'meta' => tcs_collection_transfer_read_meta($post_id),
        'terms' => $taxonomy_data['assignments'],
        'term_definitions' => $taxonomy_data['definitions'],
    ];
}

function tcs_collection_transfer_collect_term(
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

        $parent_reference = tcs_collection_transfer_collect_term(
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
        'meta' => tcs_collection_transfer_read_meta(
            (int) $term->term_id,
            'term'
        ),
    ];

    unset($visiting[$reference]);

    return $reference;
}

function tcs_collection_transfer_read_taxonomies(
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
            $reference = tcs_collection_transfer_collect_term(
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

function tcs_collection_transfer_build_backup(
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
            $record = tcs_collection_transfer_read_record(
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

function tcs_collection_transfer_handle_export() {
    check_admin_referer('tcs_collection_export');

    $owner_id = tcs_collection_transfer_requested_owner();

    try {
        $backup = tcs_collection_transfer_build_backup(
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
add_action(
    'admin_post_tcs_collection_export',
    'tcs_collection_transfer_handle_export'
);

function tcs_collection_transfer_validate_meta_rows(
    array $rows,
    string $context
): int {
    if (!array_is_list($rows)) {
        throw new RuntimeException(
            $context . ': metadata must be a list.'
        );
    }

    foreach ($rows as $index => $row) {
        $location = $context . ', metadata row ' . ($index + 1);

        if (
            !is_array($row) ||
            !is_string($row['key_b64'] ?? null) ||
            !is_string($row['value_b64'] ?? null)
        ) {
            throw new RuntimeException(
                $location . ': the metadata structure is invalid.'
            );
        }

        $key = base64_decode($row['key_b64'], true);
        $value = base64_decode($row['value_b64'], true);

        if (
            $key === false ||
            $key === '' ||
            strpos($key, "\0") !== false ||
            $value === false
        ) {
            throw new RuntimeException(
                $location . ': the metadata key or encoding is invalid.'
            );
        }
    }

    return count($rows);
}

function tcs_collection_transfer_validate_metadata(
    array $backup
): array {
    $counts = [
        'comic_rows' => 0,
        'term_rows' => 0,
    ];

    foreach ($backup['records'] as $index => $record) {
        if (
            !is_array($record) ||
            !is_array($record['meta'] ?? null)
        ) {
            throw new RuntimeException(
                'Comic ' . ($index + 1) . ': metadata is missing.'
            );
        }

        $counts['comic_rows'] +=
            tcs_collection_transfer_validate_meta_rows(
                $record['meta'],
                'Comic ' . ($index + 1)
            );
    }

    foreach ($backup['term_definitions'] as $index => $term) {
        if (
            !is_array($term) ||
            !is_array($term['meta'] ?? null)
        ) {
            throw new RuntimeException(
                'Term ' . ($index + 1) . ': metadata is missing.'
            );
        }

        $counts['term_rows'] +=
            tcs_collection_transfer_validate_meta_rows(
                $term['meta'],
                'Term ' . ($index + 1)
            );
    }

    return $counts;
}

function tcs_collection_transfer_validate_taxonomies(
    array $backup
): int {
    $allowed = get_object_taxonomies('collection', 'names');
    $definitions = [];

    foreach ($backup['term_definitions'] as $index => $term) {
        $context = 'Term definition ' . ($index + 1);

        if (
            !is_array($term) ||
            !is_string($term['ref'] ?? null) ||
            !is_string($term['taxonomy'] ?? null) ||
            !in_array($term['taxonomy'], $allowed, true) ||
            !is_string($term['name'] ?? null) ||
            trim($term['name']) === '' ||
            !is_string($term['slug'] ?? null) ||
            trim($term['slug']) === '' ||
            !is_string($term['description'] ?? null) ||
            !array_key_exists('parent_ref', $term)
        ) {
            throw new RuntimeException(
                $context . ': invalid definition or unavailable taxonomy.'
            );
        }

        $prefix = $term['taxonomy'] . ':';
        $source_id = substr($term['ref'], strlen($prefix));

        if (
            !str_starts_with($term['ref'], $prefix) ||
            !preg_match('/^[1-9][0-9]*$/D', $source_id) ||
            isset($definitions[$term['ref']]) ||
            (
                $term['parent_ref'] !== null &&
                !is_string($term['parent_ref'])
            )
        ) {
            throw new RuntimeException(
                $context . ': invalid or duplicate reference.'
            );
        }

        $definitions[$term['ref']] = $term;
    }

    foreach ($definitions as $reference => $term) {
        $seen = [$reference => true];
        $parent = $term['parent_ref'];

        while ($parent !== null) {
            if (
                !isset($definitions[$parent]) ||
                isset($seen[$parent]) ||
                $definitions[$parent]['taxonomy'] !== $term['taxonomy']
            ) {
                throw new RuntimeException(
                    'Broken parent relationship or cycle at '
                    . $reference . '.'
                );
            }

            $seen[$parent] = true;
            $parent = $definitions[$parent]['parent_ref'];
        }
    }

    $assignment_count = 0;

    foreach ($backup['records'] as $index => $record) {
        if (!is_array($record['terms'] ?? null)) {
            throw new RuntimeException(
                'Comic ' . ($index + 1)
                . ': taxonomy assignments are missing.'
            );
        }

        foreach ($record['terms'] as $taxonomy => $references) {
            if (
                !in_array($taxonomy, $allowed, true) ||
                !is_array($references) ||
                !array_is_list($references)
            ) {
                throw new RuntimeException(
                    'Comic ' . ($index + 1)
                    . ': invalid taxonomy assignment list.'
                );
            }

            $seen = [];

            foreach ($references as $reference) {
                if (
                    !is_string($reference) ||
                    !isset($definitions[$reference]) ||
                    $definitions[$reference]['taxonomy'] !== $taxonomy ||
                    isset($seen[$reference])
                ) {
                    throw new RuntimeException(
                        'Comic ' . ($index + 1)
                        . ': missing, mismatched, or repeated term reference.'
                    );
                }

                $seen[$reference] = true;
                $assignment_count++;
            }
        }
    }

    return $assignment_count;
}

function tcs_collection_transfer_validate_owners(
    array $backup
): array {
    $source_id = $backup['source_id'] ?? null;

    if (
        !is_string($source_id) ||
        !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',
            $source_id
        ) ||
        !in_array(
            $backup['scope'] ?? null,
            ['single_user', 'all_users'],
            true
        )
    ) {
        throw new RuntimeException(
            'The backup source identifier or scope is invalid.'
        );
    }

    $owners = [];

    foreach ($backup['owners'] as $index => $owner) {
        if (
            !is_array($owner) ||
            !is_int($owner['source_user_id'] ?? null) ||
            $owner['source_user_id'] < 1 ||
            isset($owners[$owner['source_user_id']]) ||
            !array_key_exists('user_login', $owner) ||
            (
                $owner['user_login'] !== null &&
                !is_string($owner['user_login'])
            )
        ) {
            throw new RuntimeException(
                'Owner reference ' . ($index + 1)
                . ' is invalid or duplicated.'
            );
        }

        $owners[$owner['source_user_id']] = [
            'source_user_id' => $owner['source_user_id'],
            'user_login' => $owner['user_login'],
            'record_count' => 0,
        ];
    }

    if (
        $backup['scope'] === 'single_user' &&
        count($owners) > 1
    ) {
        throw new RuntimeException(
            'A single-user backup contains multiple owners.'
        );
    }

    $record_ids = [];

    foreach ($backup['records'] as $index => $record) {
        if (
            !is_array($record) ||
            !is_int($record['source_post_id'] ?? null) ||
            $record['source_post_id'] < 1 ||
            isset($record_ids[$record['source_post_id']]) ||
            !is_int($record['source_owner_id'] ?? null) ||
            !isset($owners[$record['source_owner_id']])
        ) {
            throw new RuntimeException(
                'Comic ' . ($index + 1)
                . ': invalid record ID, duplicate ID, or unknown owner.'
            );
        }

        $record_ids[$record['source_post_id']] = true;

        $owners[$record['source_owner_id']]['record_count']++;
    }

    return $owners;
}

function tcs_collection_transfer_stage_backup(
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

function tcs_collection_transfer_read_pending(
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
        tcs_collection_transfer_cleanup_pending(
            $user_id,
            $token
        );

        throw new RuntimeException(
            'The pending backup expired. Upload it again.'
        );
    }

    return $job;
}

function tcs_collection_transfer_cleanup_pending(
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

add_action(
    'tcs_collection_transfer_cleanup_pending',
    'tcs_collection_transfer_cleanup_pending',
    10,
    2
);

function tcs_collection_transfer_handle_import_preview() {
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
        
        $metadata_counts = tcs_collection_transfer_validate_metadata(
            $backup
        );

        $assignment_count = tcs_collection_transfer_validate_taxonomies(
            $backup
        );

        $validated_owners = tcs_collection_transfer_validate_owners(
            $backup
        );

        $job_token = tcs_collection_transfer_stage_backup(
            $backup,
            $validated_owners
        );
        
        // Confirm that this request can read the saved job.
        tcs_collection_transfer_read_pending($job_token);

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

add_action(
    'admin_post_tcs_collection_import_preview',
    'tcs_collection_transfer_handle_import_preview'
);

function tcs_collection_transfer_resolve_owner_map(
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
function tcs_collection_transfer_render_owner_mapping(): void {
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
        $job = tcs_collection_transfer_read_pending(
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

function tcs_collection_transfer_handle_save_mapping() {
    try {
        $token = isset($_POST['job_token'])
            && is_string($_POST['job_token'])
            ? wp_unslash($_POST['job_token'])
            : '';

        check_admin_referer(
            'tcs_collection_transfer_save_mapping_' . $token
        );

        $job = tcs_collection_transfer_read_pending($token);

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

        $mapping = tcs_collection_transfer_resolve_owner_map(
            $job['owners'],
            wp_unslash($submitted)
        );

        $job['owner_map'] = $mapping;

        $plan = tcs_collection_transfer_build_import_plan($job);
        
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

add_action(
    'admin_post_tcs_collection_transfer_save_mapping',
    'tcs_collection_transfer_handle_save_mapping'
);

function tcs_collection_transfer_record_origin(
    array $backup,
    array $record
): string {
    return hash(
        'sha256',
        strtolower($backup['source_id'])
        . ':' . $record['source_post_id']
    );
}

function tcs_collection_transfer_existing_records(
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

function tcs_collection_transfer_build_import_plan(
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
    $owner_map = tcs_collection_transfer_resolve_owner_map(
        $job['owners'],
        $submitted
    );

    $backup = $job['backup'];

    $existing = tcs_collection_transfer_existing_records(
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

        $origin = tcs_collection_transfer_record_origin(
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