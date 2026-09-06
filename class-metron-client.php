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
 
     public function http_get(
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
 
            // Wait briefly; return a retryable response if still busy.
            if (!self::acquire_lock($scope, 1.0)) {
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