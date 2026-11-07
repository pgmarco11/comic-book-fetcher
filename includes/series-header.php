<?php
defined('ABSPATH') || exit;

function comicbooks_valid_series_header($series, int $id): bool {
    if (
        $id <= 0
        || !is_array($series)
        || !empty($series['error'])
        || (int) ($series['id'] ?? 0) !== $id
        || !is_string($series['name'] ?? null)
        || trim($series['name']) === ''
    ) {
        return false;
    }

    $required = [
        'year_began',
        'year_end',
        'volume',
        'issue_count',
        'series_type',
        'publisher',
    ];

    foreach ($required as $key) {
        if (!array_key_exists($key, $series)) {
            return false;
        }
    }

    foreach (['year_began', 'year_end'] as $key) {
        if (
            $series[$key] !== null
            && (
                !is_scalar($series[$key])
                || preg_match(
                    '/^[1-9]\d{3}$/D',
                    (string) $series[$key]
                ) !== 1
            )
        ) {
            return false;
        }
    }

    return is_numeric($series['issue_count'])
        && (int) $series['issue_count'] >= 0
        && is_string($series['publisher']['name'] ?? null)
        && trim($series['publisher']['name']) !== ''
        && (
            $series['volume'] === null
            || is_scalar($series['volume'])
        )
        && (
            $series['series_type'] === null
            || is_string($series['series_type']['name'] ?? null)
        );
}

function comicbooks_remember_series_header(array $series): void {
    $id = (int) ($series['id'] ?? 0);

    if (comicbooks_valid_series_header($series, $id)) {
        update_option(
            'tcs_series_header_v1_' . $id,
            array_intersect_key(
                $series,
                array_flip([
                    'id',
                    'name',
                    'publisher',
                    'volume',
                    'year_began',
                    'year_end',
                    'issue_count',
                    'series_type',
                    'genres',
                ])
            ),
            false
        );
    }
}

function comicbooks_read_series_header(int $id): array {
    $cached = get_transient("metron:series:{$id}");

    if (comicbooks_valid_series_header($cached, $id)) {
        comicbooks_remember_series_header($cached);
        return $cached;
    }

    $saved = get_option(
        'tcs_series_header_v1_' . $id,
        []
    );

    return comicbooks_valid_series_header($saved, $id)
        ? $saved
        : [];
}

function comicbooks_series_header_html(array $series): string {
    ob_start();
    ?>
    <ul style="display:flex;gap:.5rem;justify-content:flex-start;flex-wrap:wrap;">
        <li>
            <?php echo esc_html($series['publisher']['name'] ?? 'N/A'); ?> /
        </li>
        <li>
            <?php echo esc_html($series['year_began'] ?? 'N/A'); ?>
            —
            <?php echo esc_html($series['year_end'] ?? 'Ongoing'); ?> /
        </li>
        <li>
            Volume <?php echo esc_html($series['volume'] ?? 'N/A'); ?> /
        </li>
        <li>
            <?php
            $count = (int) ($series['issue_count'] ?? 0);

            echo esc_html(
                $count . ($count === 1 ? ' issue' : ' issues')
            );
            ?>
        </li>
    </ul>

    <ul>
        <li>
            <strong>Series Type:</strong>
            <?php echo esc_html($series['series_type']['name'] ?? 'N/A'); ?>
        </li>

        <?php if (!empty($series['genres']) && is_array($series['genres'])) : ?>
            <li>
                <strong>Genres:</strong>
                <?php
                echo esc_html(
                    implode(', ', array_column($series['genres'], 'name'))
                );
                ?>
            </li>
        <?php endif; ?>
    </ul>
    <?php

    return (string) ob_get_clean();
}

add_action('wp_ajax_tcs_read_series_header', function (): void {
    check_ajax_referer('comicbooks_fetchers_data', 'nonce');

    $raw_id = $_POST['series_id'] ?? '';

    $id = is_string($raw_id) && ctype_digit($raw_id)
        ? (int) $raw_id
        : 0;

    if ($id <= 0) {
        wp_send_json_error(
            ['message' => 'Invalid series.'],
            400
        );
        return;
    }

    $series = comicbooks_read_series_header($id);

    if (!$series) {
        comicbooks_queue_series_metadata($id);
    }

    wp_send_json_success([
        'pending' => !$series,
        'html' => $series
            ? comicbooks_series_header_html($series)
            : '',
    ]);
});