<?php
defined('ABSPATH') || exit;

/** Owns catalog background worker behavior */
class TCS_Catalog_Background_Worker {
    private function valid_series_metadata(
        $series,
        int $series_id
    ): bool {
        return comicbooks_valid_series_header(
            $series,
            $series_id
        );
    }

    public function queue_series_metadata(
        int $series_id,
        int $attempt = 0,
        int $delay = 5
    ): void {
        if ($series_id <= 0 || $attempt < 0 || $attempt > 4) {
            return;
        }

        if (
            $this->valid_series_metadata(
                get_transient("metron:series:{$series_id}"),
                $series_id
            )
            || get_transient("tcs:series_metadata_cooldown:{$series_id}")
        ) {
            return;
        }

        for ($queued_attempt = 0; $queued_attempt < 5; $queued_attempt++) {
            if (wp_next_scheduled(
                'comicbooks_refresh_series_metadata',
                [$series_id, $queued_attempt]
            )) {
                return;
            }
        }

        wp_schedule_single_event(
            time() + max(1, $delay),
            'comicbooks_refresh_series_metadata',
            [$series_id, $attempt]
        );
    }

    public function refresh_series_metadata(
        int $series_id,
        int $attempt = 0
    ): void {
        if ($series_id <= 0 || $attempt < 0 || $attempt > 4) {
            return;
        }

        $cache_key = "metron:series:{$series_id}";

        if (
            $this->valid_series_metadata(
                get_transient($cache_key),
                $series_id
            )
            || get_transient("tcs:series_metadata_cooldown:{$series_id}")
        ) {
            return;
        }

        $scope = 'series-metadata:' . $series_id;
        $locked = false;
        $result = [];

        try {
            $locked = MetronClient::acquire_lock($scope);

            if ($locked) {
                $cached = get_transient($cache_key);

                if ($this->valid_series_metadata($cached, $series_id)) {
                    return;
                }

                $client = new MetronClient();

                $result = $client->api_get(
                    $client->api_base . "series/{$series_id}/"
                );

                if ($this->valid_series_metadata($result, $series_id)) {
                    comicbooks_remember_series_header($result);
                
                    set_transient(
                        $cache_key,
                        $result,
                        14 * DAY_IN_SECONDS
                    );

                    if ($this->valid_series_metadata(
                        get_transient($cache_key),
                        $series_id
                    )) {
                        return;
                    }
                }
            }
        } catch (Throwable $error) {
            $result = [
                'retry_after' => $error instanceof ComicApiTemporaryException
                    ? $error->retry_after
                    : 0,
            ];
        } finally {
            if ($locked) {
                MetronClient::release_lock($scope);
            }
        }

        if ($attempt >= 4) {
            set_transient(
                "tcs:series_metadata_cooldown:{$series_id}",
                true,
                5 * MINUTE_IN_SECONDS
            );
            return;
        }

        $retry_after = is_array($result)
            ? absint($result['retry_after'] ?? 0)
            : 0;

        $this->queue_series_metadata(
            $series_id,
            $attempt + 1,
            comicbooks_background_retry_delay($attempt, $retry_after)
        );
    }
    
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
