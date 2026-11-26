<?php
defined('ABSPATH') || exit;

/** Premium spreadsheet export. JSON remains the restore/backup format. */
final class TCS_Collection_CSV_Export {
    public static function render_action(bool $admin = false): string {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return '';
        }
        $class = $admin ? 'button button-secondary' : 'tci-button';
        if (!tcs_user_has_premium($user_id)) {
            return '<p>CSV export is included with Premium. <a href="'
                . esc_url(home_url('/membership/')) . '">View Premium plans</a></p>';
        }
        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tcs_collection_csv_export">
            <?php wp_nonce_field('tcs_collection_csv_export'); ?>
            <button type="submit" class="<?php echo esc_attr($class); ?>">Download my collection (.csv)</button>
            <p>Exports your own collection records with a separate column for each creator role. Use JSON backup to restore a collection.</p>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    private static function cell($value): string {
        $text = (string) $value;
        // Keep spreadsheet applications from executing text as a formula.
        if (preg_match('/\A(?:[\x00-\x20]*[=+\-@]|[\t\r\n])/', $text)) {
            $text = "'" . $text;
        }
        return $text;
    }

    private static function write_row($stream, array $row): void {
        if (fputcsv($stream, array_map([self::class, 'cell'], $row), ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('The CSV row could not be written.');
        }
    }

    private static function decode_meta(array $record): array {
        $meta = [];
        foreach ($record['meta'] as $entry) {
            $key = base64_decode($entry['key_b64'], true);
            $value = base64_decode($entry['value_b64'], true);
            if ($key === false || $value === false) {
                throw new RuntimeException('A collection field could not be read.');
            }
            // Read the first value, as get_post_meta(..., true) does.
            if (!array_key_exists($key, $meta)) {
                $meta[$key] = $value;
            }
        }
        return $meta;
    }

    /** @return resource A completed private temporary stream, rewound for download. */
    public static function build() {
        $user_id = get_current_user_id();
        if (!$user_id || !current_user_can('read') || !tcs_user_has_premium($user_id)) {
            throw new DomainException('CSV export requires a signed-in Premium membership.');
        }
        // Reuse JSON's owner-scoped reader, statuses, raw metadata, and taxonomy data.
        // No submitted owner ID or inventory pagination/filter can change this scope.
        $backup = tcs_collection_transfer_build_backup($user_id);
        $terms = array_column($backup['term_definitions'], null, 'ref');

        // Find every role before writing the shared header row.
        $role_set = [];
        foreach ($backup['records'] as $record) {
            if ((int) $record['source_owner_id'] !== $user_id) {
                throw new RuntimeException('Collection ownership could not be verified.');
            }
            $meta = self::decode_meta($record);
            foreach (tcs_creator_role_credits($meta['creators'] ?? '') as $role => $names) {
                $role_set[$role] = true;
            }
        }
        $roles = array_keys($role_set);
        sort($roles, SORT_NATURAL | SORT_FLAG_CASE);
        $role_headers = array_map(
            static fn(string $role): string => ucwords($role),
            $roles
        );

        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('The CSV download could not be prepared.');
        }
        try {
            // UTF-8 BOM helps Excel display accents correctly.
            if (fwrite($stream, "\xEF\xBB\xBF") !== 3) {
                throw new RuntimeException('The CSV header could not be written.');
            }
            self::write_row($stream, array_merge([
                'Collection entry ID', 'Title', 'Series', 'Issue number', 'Volume', 'Year',
                'Publication date', 'Publisher', 'Genres',
                'Quantity', 'Condition / grade', 'Price', 'Purchase cost',
                'Purchase date', 'Storage location', 'Notes',
                'Metron issue ID', 'Metron series ID', 'Cover URL',
            ], $role_headers));
            foreach ($backup['records'] as $record) {
                if ((int) $record['source_owner_id'] !== $user_id) {
                    throw new RuntimeException('Collection ownership could not be verified.');
                }
                $meta = self::decode_meta($record);
                $credits = tcs_creator_role_credits($meta['creators'] ?? '');
                $names = [];
                foreach (['comic_series', 'publisher', 'comic_genre'] as $taxonomy) {
                    $names[$taxonomy] = [];
                    foreach ($record['terms'][$taxonomy] ?? [] as $ref) {
                        if (!isset($terms[$ref])) {
                            throw new RuntimeException('A collection term could not be read.');
                        }
                        $names[$taxonomy][] = $terms[$ref]['name'];
                    }
                }
                $row = [
                    $record['source_post_id'], $record['post']['post_title'],
                    implode('; ', $names['comic_series']), $meta['issue_number'] ?? '',
                    $meta['volume'] ?? '', $meta['year'] ?? '',
                    $meta['date_published'] ?? '', implode('; ', $names['publisher']),
                    implode('; ', $names['comic_genre']),
                    max(1, (int) ($meta['qty'] ?? 1)), $meta['condition'] ?? '',
                    $meta['price'] ?? '', $meta['cost_basis'] ?? '',
                    $meta['date_purchased'] ?? '', $meta['storage_location'] ?? '',
                    $meta['notes'] ?? '', $meta['issue_id'] ?? '',
                    $meta['title_id'] ?? '', $meta['cover_image_url'] ?? '',
                ];
                // Always append every role in the same order, including empty cells.
                foreach ($roles as $role) {
                    $row[] = implode('; ', array_values($credits[$role] ?? []));
                }
                self::write_row($stream, $row);
            }
            if (!rewind($stream)) {
                throw new RuntimeException('The CSV download could not be prepared.');
            }
            return $stream;
        } catch (Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    public static function handle(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Use the CSV download button.', 'Collection CSV export', ['response' => 405]);
        }
        if (!is_user_logged_in()) {
            wp_die('Please sign in to export your collection.', 'Collection CSV export', ['response' => 403]);
        }
        check_admin_referer('tcs_collection_csv_export');
        $stream = null;
        try {
            $stream = self::build();
            if (headers_sent()) {
                throw new RuntimeException('Output was sent before the download could start.');
            }
        } catch (Throwable $error) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            error_log('TCS CSV export failed: ' . $error->getMessage());
            wp_die(
                $error instanceof DomainException
                    ? 'CSV export requires a Premium membership.'
                    : 'Your CSV export could not be prepared. Please try again.',
                'Collection CSV export',
                ['response' => $error instanceof DomainException ? 403 : 500, 'back_link' => true]
            );
            return;
        }
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: text/csv; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="collection-' . gmdate('Y-m-d-His') . '.csv"');
        fpassthru($stream);
        fclose($stream);
        exit;
    }
}

add_action('admin_post_tcs_collection_csv_export', [TCS_Collection_CSV_Export::class, 'handle']);
add_action('admin_post_nopriv_tcs_collection_csv_export', [TCS_Collection_CSV_Export::class, 'handle']);
