<?php
defined('ABSPATH') || exit;

/** Owns catalog background worker behavior; legacy function entry points delegate here. */
class TCS_Catalog_Background_Worker {
    public function process_publisher_warm_queue()
    {
        $queue = get_transient(
            'comicbooks:publisher_warm_queue'
        );

        if (!is_array($queue) || empty($queue)) {
            delete_transient(
                'comicbooks:publisher_warm_queue'
            );

            return;
        }

        /*
         * Process exactly one publisher during this request.
         */
        $publisher_id = absint(array_shift($queue));

        if ($publisher_id) {
            $service = new ComicDataService(
                new MetronClient()
            );

            /*
             * Warm only the first Metron series-list page.
             * Existing caches will be used when available.
             */
            $service->get_series(
                $publisher_id,
                1,
                10,
                '',
                'all',
                false
            );
        }

        if (!empty($queue)) {
            /*
             * Save the remaining publisher IDs.
             */
            set_transient(
                'comicbooks:publisher_warm_queue',
                $queue,
                DAY_IN_SECONDS
            );

            /*
             * Process the next publisher later in a separate request.
             */
            wp_schedule_single_event(
                time() + 10,
                'comicbooks_process_publisher_warm_queue'
            );
        } else {
            delete_transient(
                'comicbooks:publisher_warm_queue'
            );
        }
    }

    public function refresh_issue_page_cache(
        $title_id,
        $api_page,
        $attempt = 0
    ) {
        $title_id = absint($title_id);
        $api_page = max(1, absint($api_page));
        $attempt  = max(0, absint($attempt));

        if (!$title_id) {
            return;
        }

        $service = new ComicDataService(
            new MetronClient()
        );

        $result  = $service->refresh_issue_api_page(
            $title_id,
            $api_page
        );

        if (
            !is_array($result) ||
            empty($result['temporary'])
        ) {
            return;
        }

        /*
         * Attempt values 0 through 4 allow five total executions.
         * Stop after the fifth temporary failure.
         */
        $next_attempt = $attempt + 1;

        if ($next_attempt >= 5) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    sprintf(
                        'Comic Books: issue-page refresh stopped after %d attempts. Title %d, API page %d.',
                        $next_attempt,
                        $title_id,
                        $api_page
                    )
                );
            }

            return;
        }

        $delay = comicbooks_background_retry_delay(
            $attempt,
            isset($result['retry_after'])
                ? absint($result['retry_after'])
                : 0
        );

        $args = [
            $title_id,
            $api_page,
            $next_attempt,
        ];

        if (
            !wp_next_scheduled(
                'comicbooks_refresh_issue_page_cache',
                $args
            )
        ) {
            wp_schedule_single_event(
                time() + $delay,
                'comicbooks_refresh_issue_page_cache',
                $args
            );
        }
    }

    public function refresh_series_page_cache(
        $publisher_id,
        $api_page,
        $api_page_size,
        $attempt = 0
    ) {
        $publisher_id = absint($publisher_id);
        $api_page      = max(1, absint($api_page));
        $api_page_size = max(1, absint($api_page_size));
        $attempt       = max(0, absint($attempt));

        if (!$publisher_id) {
            return;
        }

        $service = new ComicDataService(
            new MetronClient()
        );

        $result  = $service->refresh_series_api_page(
            $publisher_id,
            $api_page,
            $api_page_size
        );

        if (
            !is_array($result) ||
            empty($result['temporary'])
        ) {
            return;
        }

        $next_attempt = $attempt + 1;

        if ($next_attempt >= 5) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    sprintf(
                        'Comic Books: series-page refresh stopped after %d attempts. Publisher %d, API page %d.',
                        $next_attempt,
                        $publisher_id,
                        $api_page
                    )
                );
            }

            return;
        }

        $delay = comicbooks_background_retry_delay(
            $attempt,
            isset($result['retry_after'])
                ? absint($result['retry_after'])
                : 0
        );

        $args = [
            $publisher_id,
            $api_page,
            $api_page_size,
            $next_attempt,
        ];

        if (
            !wp_next_scheduled(
                'comicbooks_refresh_series_page_cache',
                $args
            )
        ) {
            wp_schedule_single_event(
                time() + $delay,
                'comicbooks_refresh_series_page_cache',
                $args
            );
        }
    }

    public function continue_series_scan(
        $publisher_id,
        $attempt = 0
    ) {
        $publisher_id = absint($publisher_id);
        $attempt      = max(0, absint($attempt));

        if (!$publisher_id) {
            return;
        }

        $service = new ComicDataService(
            new MetronClient()
        );

        $result = $service->continue_series_scan(
            $publisher_id
        );

        if (!empty($result['complete'])) {
            return;
        }

        if (
            !empty($result['temporary']) &&
            $attempt >= 4
        ) {
            return;
        }

        $next_attempt = !empty($result['temporary'])
            ? $attempt + 1
            : 0;

            $delay = !empty($result['temporary'])
            ? comicbooks_background_retry_delay(
                $attempt,
                absint($result['retry_after'] ?? 5)
            )
            : 4;

            $args = [
                $publisher_id,
                $next_attempt,
            ];

            if (
                !wp_next_scheduled(
                    'comicbooks_continue_series_scan',
                    $args
                )
            ) {
                wp_schedule_single_event(
                    time() + $delay,
                    'comicbooks_continue_series_scan',
                    $args
                );
            }
    }

}
