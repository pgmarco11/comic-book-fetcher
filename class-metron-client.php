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

 class MetronClient {

    public $api_base = COMICBOOKS_API_BASE;
    public $cache_ttl = 24 * 3600;
    public $dataset_ttl = 2 * WEEK_IN_SECONDS;

    /** 
     * Helper functions to enforce a delay between API calls to respect rate limits.
    */
    private function metron_request_lock(): bool
    {
        $lock_key   = 'metron_request_lock';
        $started_at = microtime(true);
        $max_wait   = 60.0;
    
        while (get_transient($lock_key)) {
            if ((microtime(true) - $started_at) >= $max_wait) {
                error_log(
                    sprintf(
                        'api_get: Metron request lock timed out after %.0f seconds',
                        $max_wait
                    )
                );
    
                return false;
            }
    
            /*
             * Jitter prevents waiting PHP workers from checking the lock
             * at exactly the same moment.
             */
            usleep(random_int(200000, 400000));
        }
    
        set_transient(
            $lock_key,
            microtime(true),
            180
        );
    
        return true;
    }
    private function metron_rate_limit() {

        $key = 'metron_last_request_time';
    
        $last = get_transient( $key );
        $now  = microtime(true);
    
        $min_interval = 3.2; 
    
        if ( $last ) {
            $elapsed = $now - (float) $last;
    
            if ( $elapsed < $min_interval ) {
    
                $sleep_seconds = $min_interval - $elapsed;
    
                // Convert to microseconds safely
                $sleep_micro = (int) round( $sleep_seconds * 1000000 );
    
                if ( $sleep_micro > 0 ) {
                    usleep( $sleep_micro );
                }
            }
        }
    
        set_transient( $key, microtime(true), 10 );
        
    }


    public function api_get($url, $retries = 3, $backoff = 1) {

        $url = esc_url_raw($url);

        if (empty($url)) {
            return [
                'error' => 'Invalid Metron API URL',
            ];
        }    

        $cache_key = 'metron:api:' . md5($url);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        if (!$this->metron_request_lock()) {
            return [
                'error' => 'Metron request queue is busy. Please try again.',
            ];
        }


        try {
            /*
             * Another request may have populated the cache while this request
             * was waiting for the lock.
             */
            $cached = get_transient($cache_key);
    
            if ($cached !== false) {
                return $cached;
            }
    
            /*
             * Enforce the minimum interval between real Metron requests.
             */
            $this->metron_rate_limit();
    
            $username = get_option('metron_api_username', '');
            $password = get_option('metron_api_password', '');
    
            if (!$username || !$password) {               
                return [
                    'error' => 'Missing Metron API credentials',
                ];
            }
    
            $retries = is_numeric($retries)
                ? max(1, (int) $retries)
                : 3;
    
            $backoff = is_numeric($backoff)
                ? max(1, (int) $backoff)
                : 1;
    
            for ($attempt = 1; $attempt <= $retries; $attempt++) {
                if ($attempt > 1) {
                    /*
                     * Rate-limit retries too. This is important when the previous
                     * request timed out after reaching Metron.
                     */
                    $this->metron_rate_limit();
    
                    error_log(
                        "api_get: Attempt {$attempt}/{$retries} for {$url}"
                    );
                }
    
                $response = wp_remote_get(
                    $url,
                    [
                        'headers' => [
                            'User-Agent' =>
                                'ComicBookFetcher/1.1 (+' . get_site_url() . ')',
                            'Accept' =>
                                'application/json',
                            'Authorization' =>
                                'Basic ' . base64_encode(
                                    $username . ':' . $password
                                ),
                        ],
                        'timeout'     => 45,
                        'redirection' => 5,
                        'httpversion' => '1.1',
                    ]
                );
    
                /*
                 * Handle WordPress transport errors before reading response
                 * headers or response codes.
                 */
                if (is_wp_error($response)) {
                    $message = $response->get_error_message();
    
                    error_log(
                        "api_get: WP_Error for {$url}: {$message}"
                    );
    
                    $is_timeout =
                        stripos($message, 'timeout') !== false ||
                        stripos($message, 'timed out') !== false;
    
                    if ($attempt < $retries && $is_timeout) {
                        sleep($backoff);
                        $backoff *= 2;
                        continue;
                    }
    
                    return [
                        'error' => 'Metron request failed: ' . $message,
                    ];
                }
    
                $status_code = (int) wp_remote_retrieve_response_code(
                    $response
                );
    
                $burst_remaining = wp_remote_retrieve_header(
                    $response,
                    'x-ratelimit-burst-remaining'
                );
    
                if (
                    $burst_remaining !== '' &&
                    (int) $burst_remaining <= 1
                ) {
                    error_log(
                        "api_get: Metron burst remaining low " .
                        "({$burst_remaining}) for {$url}"
                    );
    
                    /*
                     * Add extra spacing before the next Metron request.
                     */
                    set_transient(
                        'metron_last_request_time',
                        microtime(true) + 5,
                        15
                    );
                }
    
                /*
                 * Respect a Metron 429 response and its Retry-After header.
                 */
                if ($status_code === 429) {
                    $retry_after = (int) wp_remote_retrieve_header(
                        $response,
                        'retry-after'
                    );
    
                    if ($retry_after <= 0) {
                        $retry_after = $backoff;
                    }
    
                    /*
                     * Prevent an unexpected header from holding the PHP process
                     * for an excessive amount of time.
                     */
                    $retry_after = min($retry_after, 60);
    
                    error_log(
                        "api_get: Rate limit reached for {$url}; " .
                        "attempt {$attempt}/{$retries}; " .
                        "retrying after {$retry_after} seconds"
                    );
    
                    if ($attempt < $retries) {
                        sleep($retry_after);
                        $backoff = min($backoff * 2, 60);
                        continue;
                    }
    
                    return [
                        'error' => 'Metron API rate limit exceeded',
                    ];
                }
    
                if ($status_code !== 200) {
                    $response_body = wp_remote_retrieve_body($response);
    
                    error_log(
                        "api_get: HTTP {$status_code} for {$url}; " .
                        'body preview: ' .
                        substr((string) $response_body, 0, 200)
                    );
    
                    /*
                     * Retry temporary server failures.
                     */
                    if (
                        $attempt < $retries &&
                        in_array($status_code, [500, 502, 503, 504], true)
                    ) {
                        sleep($backoff);
                        $backoff = min($backoff * 2, 60);
                        continue;
                    }
    
                    return [
                        'error' => "Metron API returned HTTP {$status_code}",
                    ];
                }
    
                $response_body = wp_remote_retrieve_body($response);
    
                if ($response_body === '') {              
    
                    if ($attempt < $retries) {
                        sleep($backoff);
                        $backoff = min($backoff * 2, 60);
                        continue;
                    }
    
                    return [
                        'error' => 'Empty Metron API response',
                    ];
                }
    
                $data = json_decode($response_body, true);
    
                if (
                    json_last_error() !== JSON_ERROR_NONE ||
                    !is_array($data)
                ) {
                    $json_error = json_last_error_msg();
    
                    error_log(
                        "api_get: Invalid JSON from {$url}: {$json_error}"
                    );
    
                    if ($attempt < $retries) {
                        sleep($backoff);
                        $backoff = min($backoff * 2, 60);
                        continue;
                    }
    
                    return [
                        'error' => 'Invalid Metron JSON: ' . $json_error,
                    ];
                }
    
                /*
                 * Cache successful responses only.
                 */
                $result_count = count($data['results'] ?? []);
    
                $cache_duration = $result_count >= 100
                    ? WEEK_IN_SECONDS
                    : 2 * WEEK_IN_SECONDS;
    
                set_transient(
                    $cache_key,
                    $data,
                    $cache_duration
                );
    
                error_log(
                    "api_get: Data cached for {$url}, " .
                    "duration={$cache_duration} seconds"
                );
    
                return $data;
            }
    
            error_log(
                "api_get: All retries exhausted for {$url}"
            );
    
            return [
                'error' => 'All Metron API retries exhausted',
            ];
    
        } finally {
            /*
             * This runs before every return from the try block, ensuring that
             * errors cannot leave the queue permanently locked.
             */
            delete_transient('metron_request_lock');
        }
    }   
}