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
    private function metron_request_lock() {

        $lock_key = 'metron_request_lock';
    
        while ( get_transient( $lock_key ) ) {
            usleep(200000); // wait 0.2s
        }
    
        set_transient( $lock_key, 1, 5 );
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

        $cache_key = 'metron:api:' . md5($url);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        //Prevent concurrent requests
        $this->metron_request_lock();

        //Enforce 20/min limit
        $this->metron_rate_limit();
    
        $username = get_option('metron_api_username', '');
        $password = get_option('metron_api_password', '');
    
        if (!$username || !$password) {
            error_log('api_get: ERROR: Missing Metron API credentials');
            delete_transient('metron_request_lock'); // release before early return
            return ['error' => 'Missing API credentials'];
        }
    
        $retries = is_array($retries) ? 3 : (int)$retries;
    
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            if ($attempt > 2) {
                error_log("api_get: Attempt $attempt/$retries for $url");
            }
    
            $response = wp_remote_get($url, [
                'headers' => [
                    'User-Agent'    => 'ComicBookFetcher/1.1 (+' . get_site_url() . ')',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode("$username:$password"),
                ],
                'timeout'     => 45,
                'redirection' => 5,
                'httpversion' => '1.1',
            ]);
    
            // Check burst headroom on the REAL request, not a wasted extra one.
            $burst_remaining = wp_remote_retrieve_header($response, 'x-ratelimit-burst-remaining');
            if ($burst_remaining !== '' && (int) $burst_remaining <= 1) {
                error_log("api_get: WARN: burst remaining low ($burst_remaining) for $url");
                set_transient('metron_last_request_time', microtime(true) + 5, 10);
            }
    
            if (is_wp_error($response)) {
                error_log("api_get: ERROR: WP_Error - " . $response->get_error_message() . " for $url");
                if ($attempt < $retries && strpos($response->get_error_message(), 'timeout') !== false) {
                    sleep($backoff);
                    $backoff *= 2;
                    continue;
                }
                delete_transient('metron_request_lock');
                return ['error' => 'WP_Error: ' . $response->get_error_message()];
            }
    
            $status_code = wp_remote_retrieve_response_code($response);
    
            if ($status_code == 429) {
                $retry_after = wp_remote_retrieve_header($response, 'retry-after') ?: $backoff;
                error_log("api_get: WARN: Rate limit hit (429) for $url, attempt $attempt/$retries, sleeping $retry_after seconds");
                if ($attempt < $retries) {
                    sleep($retry_after);
                    $backoff *= 3;
                    continue;
                }
                delete_transient('metron_request_lock');
                return ['error' => 'Rate limit exceeded'];
            }
    
            if ($status_code != 200) {
                $body = wp_remote_retrieve_body($response);
                error_log("api_get: ERROR: HTTP $status_code for $url, body preview: " . substr($body, 0, 200));
                delete_transient('metron_request_lock');
                return ['error' => "HTTP $status_code"];
            }
    
            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                error_log("api_get: ERROR: Empty response body for $url");
                if ($attempt < $retries) {
                    sleep($backoff);
                    $backoff *= 2;
                    continue;
                }
                delete_transient('metron_request_lock');
                return ['error' => 'Empty response'];
            }
    
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("api_get: ERROR: Invalid JSON response from $url: " . json_last_error_msg());
                if ($attempt < $retries) {
                    sleep($backoff);
                    $backoff *= 2;
                    continue;
                }
                delete_transient('metron_request_lock');
                return ['error' => 'Invalid JSON: ' . json_last_error_msg()];
            }
    
            $cache_duration = count($data['results'] ?? []) > 100 ? WEEK_IN_SECONDS : 2 * WEEK_IN_SECONDS;
            set_transient($cache_key, $data, $cache_duration);
            error_log("api_get: Data cached for $url, duration=$cache_duration seconds");
    
            delete_transient('metron_request_lock'); // release on success
            return $data;
        }
    
        delete_transient('metron_request_lock');
        error_log("api_get: ERROR: All retries exhausted for $url");
        return ['error' => 'All retries exhausted'];
    }   
}