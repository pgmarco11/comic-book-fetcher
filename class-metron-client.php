<?php
/**
 * MetronAPI class for handling API interactions in the Comic Books Fetcher plugin.
 *
 * Provides methods to fetch comic book data (publishers, series, issues) from the
 * Metron API and Comic Vine API. Implements caching using WordPress transients,
 * handles authentication, and manages rate limiting with retries. This class is
 * extended by other plugin classes to render and process comic book data.
 *
 * @package ComicBooksFetcher
 * @since 1.0.0
 * @author Peter Giammarco
 */

 class ComicApiTemporaryException extends RuntimeException {
     public $retry_after;
 
     public function __construct($message, $retry_after = 2) {
         parent::__construct($message);
 
         $this->retry_after = max(
             1,
             (int) ceil($retry_after)
         );
     }
 }
 
 class MetronClient {
     public $api_base = COMICBOOKS_API_BASE;
     public $cache_ttl = 24 * 3600;
     public $dataset_ttl = 2 * WEEK_IN_SECONDS;
 
     private const REQUEST_BUDGET = 20.0;
     private const HTTP_TIMEOUT = 8.0;
     private const MIN_INTERVAL = 3.2;
 
     // Shared across all client instances in this PHP request.
     private static $deadline = null;
     private static $ajax_errors = false;
     private static $held_locks = [];
     private static $shutdown_registered = false;
 
     public function __construct() {
         self::remaining_seconds();
 
         if (!self::$shutdown_registered) {
             self::$shutdown_registered = true;
 
             register_shutdown_function(static function () {
                 foreach (array_keys(self::$held_locks) as $scope) {
                     self::release_lock($scope);
                 }
             });
         }
     }
 
     public static function remaining_seconds(): float {
         if (self::$deadline === null) {
             $started = (float) (
                 $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)
             );
 
             self::$deadline = $started + self::REQUEST_BUDGET;
         }
 
         return max(0.0, self::$deadline - microtime(true));
     }
 
     public static function enable_ajax_errors(): void {
         self::$ajax_errors = true;
         self::remaining_seconds();
     }
 
     public static function disable_ajax_errors(): void {
         self::$ajax_errors = false;
     }
 
     private static function temporary_failure(
         $message,
         $retry_after = 2
     ): array {
         if (self::$ajax_errors) {
             throw new ComicApiTemporaryException(
                 $message,
                 $retry_after
             );
         }
 
         // Templates still receive the existing array-based response.
         return [
             'error' => $message,
             'temporary_error' => true,
             'retry_after' => max(1, (int) ceil($retry_after)),
         ];
     }
 
     private static function pause(float $seconds): bool {
         $seconds = max(0.0, $seconds);
 
         // Reserve time for another attempt and response handling.
         if ($seconds + 1.0 > self::remaining_seconds()) {
             return false;
         }
 
         if ($seconds > 0) {
             usleep((int) ceil($seconds * 1000000));
         }
 
         return self::remaining_seconds() >= 1.0;
     }
 
     public static function acquire_lock(
         string $scope,
         float $wait = 0.0
     ): bool {
         global $wpdb;
 
         if (isset(self::$held_locks[$scope])) {
             return false;
         }
 
         $name = 'tcs_' . sha1(
             DB_NAME . ':' . $wpdb->prefix . ':' . $scope
         );
 
         $stop = microtime(true) + max(0.0, $wait);
 
         do {
             // Atomic acquisition; no transient check/set race.
             $result = $wpdb->get_var(
                 $wpdb->prepare(
                     'SELECT GET_LOCK(%s, 0)',
                     $name
                 )
             );
 
             if ((string) $result === '1') {
                 self::$held_locks[$scope] = $name;
                 return true;
             }
 
             if ($result === null) {
                 self::temporary_failure(
                     'Database coordination is unavailable. Please retry.'
                 );
 
                 return false;
             }
 
             if (
                 microtime(true) >= $stop ||
                 !self::pause(0.1)
             ) {
                 return false;
             }
         } while (true);
     }
 
     public static function release_lock(string $scope): void {
         global $wpdb;
 
         if (!isset(self::$held_locks[$scope])) {
             return;
         }
 
         $name = self::$held_locks[$scope];
         unset(self::$held_locks[$scope]);
 
         $wpdb->get_var(
             $wpdb->prepare(
                 'SELECT RELEASE_LOCK(%s)',
                 $name
             )
         );
     }
 
     private static function read_gate(): ?float {
         global $wpdb;
 
         // Bypass request-local option caching for shared coordination.
         $value = $wpdb->get_var(
             $wpdb->prepare(
                 "SELECT option_value
                  FROM {$wpdb->options}
                  WHERE option_name = %s",
                 'tcs_metron_next_allowed_v1'
             )
         );
 
         return $wpdb->last_error ? null : (float) $value;
     }
 
     private static function write_gate(float $next): bool {
         global $wpdb;
 
         // Called only while the Metron request lock is held.
         return false !== $wpdb->query(
             $wpdb->prepare(
                 "INSERT INTO {$wpdb->options}
                     (option_name, option_value, autoload)
                  VALUES (%s, %s, 'no')
                  ON DUPLICATE KEY UPDATE
                     option_value = VALUES(option_value)",
                 'tcs_metron_next_allowed_v1',
                 sprintf('%.6F', $next)
             )
         );
     }
 
     private static function retry_after(
         $response,
         $fallback = 2
     ): int {
         $header = trim(
             (string) wp_remote_retrieve_header(
                 $response,
                 'retry-after'
             )
         );
 
         if (ctype_digit($header)) {
             return max(1, (int) $header);
         }
 
         $timestamp = $header !== ''
             ? strtotime($header)
             : false;
 
         return $timestamp !== false
             ? max(1, $timestamp - time())
             : max(1, (int) $fallback);
     }

     /**
     * Route Comic Vine requests through shared coordination.
     * Other HTTP requests retain their existing behavior.
     */
    public function http_get(
        $url,
        array $args = [],
        bool $report_failure = true
    ) {
        $host = strtolower(
            (string) wp_parse_url($url, PHP_URL_HOST)
        );

        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        if (
            $host !== 'comicvine.gamespot.com' ||
            strpos($path, '/api/') !== 0
        ) {
            return $this->http_get_raw(
                $url,
                $args,
                $report_failure
            );
        }

        return $this->cv_http_get(
            $url,
            $args,
            $report_failure
        );
    }

    /**
     * Refresh the request-local option cache before a locked recheck.
     */
    private static function cv_cached_response(
        string $key,
        bool $fresh = false
    ) {
        if ($fresh && !wp_using_ext_object_cache()) {
            wp_cache_delete(
                '_transient_' . $key,
                'options'
            );

            wp_cache_delete(
                '_transient_timeout_' . $key,
                'options'
            );

            wp_cache_delete('notoptions', 'options');
        }

        return get_transient($key);
    }

    /**
     * Read the shared cooldown directly from the database.
     */
    private static function cv_read_cooldown(
        string $option
    ): ?int {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value
                FROM {$wpdb->options}
                WHERE option_name = %s",
                $option
            )
        );

        return $wpdb->last_error
            ? null
            : (int) $value;
    }

    /**
     * Called while the shared Comic Vine lock is held.
     * Never shorten an existing cooldown.
     */
    private static function cv_write_cooldown(
        string $option,
        int $until
    ): bool {
        global $wpdb;

        return false !== $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options}
                    (option_name, option_value, autoload)
                VALUES (%s, %s, 'no')
                ON DUPLICATE KEY UPDATE
                    option_value = GREATEST(
                        CAST(option_value AS UNSIGNED),
                        %d
                    )",
                $option,
                (string) $until,
                $until
            )
        );
    }

    /**
     * Preserve the existing AJAX exception behavior.
     * Other callers receive a WP_Error.
     */
    private static function cv_request_failure(
        string $message,
        int $retry_after,
        bool $report_failure
    ) {
        $retry_after = max(1, $retry_after);

        if ($report_failure) {
            self::temporary_failure(
                $message,
                $retry_after
            );
        }

        return new WP_Error(
            'comicvine_temporary',
            $message,
            ['retry_after' => $retry_after]
        );
    }

    /**
     * Shared response cache, request lock, and API cooldown.
     */
    private function cv_http_get(
        $url,
        array $args,
        bool $report_failure
    ) {
        $query = [];

        wp_parse_str(
            (string) wp_parse_url($url, PHP_URL_QUERY),
            $query
        );

        /*
        * Equivalent query parameter and batch-ID orders should
        * produce the same cache key.
        */
        foreach (['field_list', 'filter'] as $name) {
            if (!isset($query[$name]) || !is_string($query[$name])) {
                continue;
            }

            $parts = explode(',', $query[$name]);

            if ($name === 'filter') {
                foreach ($parts as &$part) {
                    $pair = explode(':', $part, 2);

                    if (count($pair) === 2) {
                        $values = explode('|', $pair[1]);
                        sort($values, SORT_NATURAL);

                        $part = $pair[0] . ':' . implode('|', $values);
                    }
                }

                unset($part);
            }

            sort($parts, SORT_STRING);
            $query[$name] = implode(',', $parts);
        }

        ksort($query);

        $request_id = hash(
            'sha256',
            (string) wp_parse_url($url, PHP_URL_PATH) .
            '?' .
            http_build_query($query, '', '&', PHP_QUERY_RFC3986)
        );

        $cache_key = 'tcs_cv_http_v1_' . $request_id;

        $cached = self::cv_cached_response($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        /*
        * Scope coordination to this site's Comic Vine API key.
        * Only the hash is used in coordination names.
        */
        $key_hash = hash(
            'sha256',
            (string) ($query['api_key'] ?? '')
        );

        $scope = 'cv-http-v1:' . $key_hash;
        $cooldown_option = 'tcs_cv_cooldown_v1_' . $key_hash;

        if (!self::acquire_lock($scope, 1.0)) {
            return self::cv_request_failure(
                'Comic Vine is processing another request. Please retry.',
                2,
                $report_failure
            );
        }

        try {
            /*
            * Another visitor may have populated this cache
            * while we were waiting for the lock.
            */
            $cached = self::cv_cached_response(
                $cache_key,
                true
            );

            if (is_array($cached)) {
                return $cached;
            }

            $until = self::cv_read_cooldown($cooldown_option);

            if ($until === null) {
                return self::cv_request_failure(
                    'Comic Vine coordination is unavailable. Please retry.',
                    2,
                    $report_failure
                );
            }

            if ($until > time()) {
                return self::cv_request_failure(
                    'Comic Vine requests are temporarily paused.',
                    $until - time(),
                    $report_failure
                );
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('TCS CV outbound: ' . $request_id);
            }

            /*
            * Inspect the original response before the normal
            * HTTP error handler can throw an exception.
            */
            $response = $this->http_get_raw(
                $url,
                $args,
                false
            );

            $http = is_wp_error($response)
                ? 0
                : (int) wp_remote_retrieve_response_code($response);

            $body = is_wp_error($response)
                ? null
                : json_decode(
                    wp_remote_retrieve_body($response),
                    true
                );

            $api_code = (
                is_array($body) &&
                is_scalar($body['status_code'] ?? null)
            )
                ? (string) $body['status_code']
                : '';

            $limited = $http === 429 || $api_code === '107';

            /*
            * Only a successful HTTP response with a successful
            * API envelope and a results array is reusable.
            */
            $valid = (
                $http === 200 &&
                $api_code === '1' &&
                is_array($body['results'] ?? null)
            );

            if (!$valid) {
                $retry = is_wp_error($response)
                    ? 2
                    : self::retry_after(
                        $response,
                        $limited ? 60 : 2
                    );

                $saved = self::cv_write_cooldown(
                    $cooldown_option,
                    time() + $retry
                );

                return self::cv_request_failure(
                    $saved
                        ? 'Comic Vine is unavailable or rejected the request.'
                        : 'Comic Vine cooldown could not be saved.',
                    $retry,
                    $report_failure
                );
            }

            /*
            * The HTTP transport object is unnecessary for callers
            * and should not be stored in the shared cache.
            */
            $reusable = $response;
            unset($reusable['http_response']);

            set_transient(
                $cache_key,
                $reusable,
                5 * MINUTE_IN_SECONDS
            );

            return $response;
        } finally {
            self::release_lock($scope);
        }
    }
 
     private function http_get_raw(
        $url,
        array $args = [],
        bool $report_failure = true
    ) {
         $remaining = self::remaining_seconds();
 
         if ($remaining < 1.0) {
             self::temporary_failure(
                 'The API time budget is exhausted. Please retry.'
             );
 
             return new WP_Error(
                 'comic_api_budget',
                 'The API time budget is exhausted.'
             );
         }
 
         $args['timeout'] = min(
             self::HTTP_TIMEOUT,
             max(
                 0.1,
                 (float) ($args['timeout'] ?? self::HTTP_TIMEOUT)
             ),
             $remaining - 0.5
         );
 
         // Canonical URLs avoid redirects multiplying HTTP timeouts.
         $args['redirection'] = 0;
         $args['blocking'] = true;
 
         $response = wp_remote_get($url, $args);
 
         if ($report_failure) {
             $status = is_wp_error($response)
                 ? 0
                 : (int) wp_remote_retrieve_response_code($response);
 
             if (
                 is_wp_error($response) ||
                 $status === 429 ||
                 $status >= 500
             ) {
                 $retry = $status === 429
                     ? self::retry_after($response)
                     : 2;
 
                 self::temporary_failure(
                     'The upstream API is temporarily unavailable.',
                     $retry
                 );
 
                 return new WP_Error(
                     'comic_api_temporary',
                     'The upstream API is temporarily unavailable.'
                 );
             }
         }
 
         return $response;
     }
 
     public function api_get(
         $url,
         $retries = 3,
         $backoff = 1,
         bool $cache_only = false
     ) {
        $url = esc_url_raw($url);
 
        if ($url === '') {
             return ['error' => 'Invalid Metron API URL'];
        }
 
        $cache_key = 'metron:api:' . md5($url);
        $cached = get_transient($cache_key);
 
        if ($cached !== false) {
             return $cached;
        }
 
        if ($cache_only) {
             return ['cache_miss' => true];
        }
 
        $username = get_option('metron_api_username', '');
        $password = get_option('metron_api_password', '');
 
        if (!$username || !$password) {
             return ['error' => 'Missing Metron API credentials'];
        }
 
        $retries = is_numeric($retries)
             ? max(1, min(3, (int) $retries))
             : 3;
 
        $backoff = is_numeric($backoff)
             ? max(1, (int) $backoff)
             : 1;
 
        $scope = 'metron-request';
 
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
             if (self::remaining_seconds() < 1.0) {
                 return self::temporary_failure(
                     'The API time budget is exhausted. Please retry.'
                 );
            }
 
            /*
            * A Metron request can legitimately hold the shared lock for more
            * than one second while waiting on the upstream response. Give
            * foreground catalog requests a fair chance to acquire it, while
            * reserving enough of this request's budget for the HTTP call and
            * response handling.
            */
            $lock_wait = min(
                6.0,
                max(
                    0.0,
                    self::remaining_seconds() - self::HTTP_TIMEOUT - 1.0
                )
            );

            if (!self::acquire_lock($scope, $lock_wait)) {
                return self::temporary_failure(
                    'The Metron request queue is busy. Please retry.'
                );
            }
 
            $slot_wait = 0.0;
            $retry_delay = $backoff;
            $retryable = false;
            $message = 'Metron request failed.';
 
            try {
                    // Another worker may have populated this cache.
                    $cached = get_transient($cache_key);
    
                    if ($cached !== false) {
                        return $cached;
                    }
    
                    $next = self::read_gate();

                    if ($next === null) {
                        return self::temporary_failure(
                            'Cannot read the Metron rate limiter.'
                        );
                    }
                    
                    $slot_wait = max(
                        0.0,
                        $next - microtime(true)
                    );         
    
                    if ($slot_wait > 0) {
                        // Do not make an HTTP request during this iteration.
                    } else {
                    
                        /*
                         * Reserve the next API slot before sending this request.
                         */
                        $next = microtime(true) + self::MIN_INTERVAL;
                    
                        if (!self::write_gate($next)) {
                            return self::temporary_failure(
                                'Cannot update the Metron rate limiter.'
                            );
                        }
                    
                        $response = $this->http_get(
                            $url,
                            [
                                'headers' => [
                                    'User-Agent' =>
                                        'ComicBookFetcher/1.1 (+' .
                                        get_site_url() . ')',
                                    'Accept' => 'application/json',
                                    'Authorization' =>
                                        'Basic ' .
                                        base64_encode(
                                            $username . ':' . $password
                                        ),
                                ],
                                'httpversion' => '1.1',
                            ],
                            false
                        );
                    
                        if (is_wp_error($response)) {
                            $retryable = true;
                            $message =
                                'Metron could not respond in time. Please retry.';
                        } else {
                            $status = (int)
                                wp_remote_retrieve_response_code($response);
                    
                            $burst = wp_remote_retrieve_header(
                                $response,
                                'x-ratelimit-burst-remaining'
                            );
                    
                            $gate_changed = false;
                    
                            if ($burst !== '' && (int) $burst <= 1) {
                                $next = max(
                                    $next,
                                    microtime(true) + 8.2
                                );
                    
                                $gate_changed = true;
                            }
                    
                            if ($status === 429) {
                                $retry_delay = self::retry_after(
                                    $response,
                                    $backoff
                                );
                    
                                $next = max(
                                    $next,
                                    microtime(true) + $retry_delay
                                );
                    
                                $gate_changed = true;
                            }
                    
                            if (
                                $gate_changed &&
                                !self::write_gate($next)
                            ) {
                                return self::temporary_failure(
                                    'Cannot update the Metron cooldown.'
                                );
                            }
                    
                            if ($status === 200) {
                    
                                $data = json_decode(
                                    wp_remote_retrieve_body($response),
                                    true
                                );
                    
                                if (
                                    json_last_error() === JSON_ERROR_NONE &&
                                    is_array($data)
                                ) {
                                    if (
                                        array_key_exists('results', $data) &&
                                        !is_array($data['results'])
                                    ) {
                                        return self::temporary_failure(
                                            'Metron returned invalid results. Please retry.'
                                        );
                                    }
                    
                                    $ttl = count($data['results'] ?? []) >= 100
                                        ? WEEK_IN_SECONDS
                                        : 2 * WEEK_IN_SECONDS;
                    
                                    set_transient(
                                        $cache_key,
                                        $data,
                                        $ttl
                                    );
                    
                                    return $data;
                                }
                    
                                $retryable = true;
                                $message =
                                    'Metron returned an invalid response. Please retry.';
                    
                            } else {
                    
                                $retryable =
                                    $status === 429 ||
                                    $status >= 500;
                    
                                $message =
                                    "Metron API returned HTTP {$status}.";
                            }
                        }
                    }
            } finally {
                 // Release before any rate-limit or retry sleep.
                 self::release_lock($scope);
            }
 
            if ($slot_wait > 0) {
                 if (!self::pause($slot_wait)) {
                     return self::temporary_failure(
                         'Metron is cooling down. Please retry.',
                         $slot_wait
                     );
                 }
 
                 // Waiting for permission to send is not an HTTP attempt.
                 $attempt--;
                 continue;
            }
 
            if (!$retryable) {
                 return ['error' => $message];
            }
 
            if (
                 $attempt === $retries ||
                 !self::pause($retry_delay)
            ) {
                 return self::temporary_failure(
                     $message,
                     $retry_delay
                 );
            }
 
            $backoff = min($backoff * 2, 8);
        }
 
        return self::temporary_failure(
             'Metron retries are exhausted. Please retry.'
        );
    }
}