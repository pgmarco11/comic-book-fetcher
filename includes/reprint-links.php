<?php
defined('ABSPATH') || exit;

function tcs_reprint_positive_id($value): int {
    if (
        (!is_int($value) && !is_string($value))
        || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
    ) {
        return 0;
    }

    $id = (int) $value;

    return (string) $id === (string) $value ? $id : 0;
}

function tcs_reprint_issue_url($value): string {
    $issue_id = tcs_reprint_positive_id($value);

    if (!$issue_id) {
        return '';
    }

    $series_id = tcs_reprint_positive_id(
        get_transient("tcs:reprint_series:v1:{$issue_id}")
    );

    $args = $series_id > 0
        ? [
            'issue_id' => $issue_id,
            'title_id' => $series_id,
        ]
        : [
            'reprint_issue_id' => $issue_id,
        ];

    return add_query_arg(
        $args,
        home_url('/comic-database/issue/')
    );
}

function tcs_reprint_series_id(int $issue_id) {
    $key = "tcs:reprint_series:v1:{$issue_id}";

    $cached = tcs_reprint_positive_id(
        get_transient($key)
    );

    if ($cached > 0) {
        return $cached;
    }

    $client = new MetronClient();

    $url = esc_url_raw(
        $client->api_base . "issue/{$issue_id}/"
    );

    $issue = $client->api_get($url);

    $series_id = is_array($issue)
        ? tcs_reprint_positive_id(
            $issue['series']['id'] ?? null
        )
        : false;

    if (
        is_wp_error($issue)
        || !is_array($issue)
        || isset($issue['error'])
        || tcs_reprint_positive_id(
            $issue['id'] ?? null
        ) !== $issue_id
        || !$series_id
    ) {
        // An unusable response must not prevent a later retry.
        delete_transient(
            'metron:api:' . md5($url)
        );

        return new WP_Error(
            'tcs_reprint_unavailable',
            'This linked issue is temporarily unavailable. Please try again.'
        );
    }

    set_transient(
        $key,
        $series_id,
        30 * DAY_IN_SECONDS
    );

    return $series_id;
}

function tcs_reprints_html($reprints): string {
    if (!is_array($reprints)) {
        return '';
    }

    $items = [];

    foreach ($reprints as $reprint) {
        if (
            !is_array($reprint)
            || !is_scalar($reprint['issue'] ?? null)
        ) {
            continue;
        }

        $label = trim(
            (string) $reprint['issue']
        );

        if ($label === '') {
            continue;
        }

        $url = tcs_reprint_issue_url(
            $reprint['id'] ?? null
        );

        $text = esc_html($label);

        if ($url !== '') {
            $text = '<a href="' . esc_url($url) . '">'
                . $text . '</a>';
        }

        $items[] = '<li>' . $text . '</li>';
    }

    return $items
        ? '<div class="issue-section reprints">'
            . '<h3>Reprints</h3><ul>'
            . implode('', $items)
            . '</ul></div>'
        : '';
}

add_action('template_redirect', function () {
    if (
        !is_page('issue')
        || !isset($_GET['reprint_issue_id'])
    ) {
        return;
    }

    nocache_headers();

    $issue_id = tcs_reprint_positive_id(
        wp_unslash($_GET['reprint_issue_id'])
    );

    if (!$issue_id) {
        wp_die(
            'Invalid reprint link.',
            'Issue unavailable',
            ['response' => 400]
        );
    }

    $series_id = tcs_reprint_series_id($issue_id);

    if (is_wp_error($series_id)) {
        $retry_url = tcs_reprint_issue_url($issue_id);

        wp_die(
            '<p>'
                . esc_html($series_id->get_error_message())
                . '</p><p><a href="'
                . esc_url($retry_url)
                . '">Try again</a></p>',
            'Issue unavailable',
            ['response' => 503]
        );
    }

    wp_safe_redirect(
        add_query_arg(
            [
                'issue_id' => $issue_id,
                'title_id' => $series_id,
            ],
            home_url('/comic-database/issue/')
        ),
        302
    );

    exit;
}, 5);