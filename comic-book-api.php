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
class MetronAPI {

    protected $api_base = COMICBOOKS_API_BASE; // Ensure this is 'https://metron.cloud/api/'
    public $dataset_ttl = 2 * WEEK_IN_SECONDS; // Increased to 2 weeks for stable data
    public $cache_ttl = 24 * 3600; // 24 hours for other data


    protected function api_get($url, $retries = 3, $backoff = 1) {
        $cache_key = 'metron:api:' . md5($url);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            error_log("api_get: Cache hit for $url");
            return $cached;
        }
    
        $username = get_option('metron_api_username', '');
        $password = get_option('metron_api_password', '');
    
        if (!$username || !$password) {
            error_log('api_get: ERROR: Missing Metron API credentials');
            return ['error' => 'Missing API credentials'];
        }
    
        // Ensure retries is an integer
        $retries = is_array($retries) ? 3 : (int)$retries;
    
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            error_log("api_get: Attempt $attempt/$retries for $url");
            $response = wp_remote_get($url, [
                'headers' => [
                    'User-Agent' => 'ComicBookFetcher/1.0 (+https://thecollectiblespot.com)',
                    'Accept' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode("$username:$password")
                ],
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.1',
            ]);
    
            if (is_wp_error($response)) {
                error_log("api_get: ERROR: WP_Error - " . $response->get_error_message() . " for $url");
                if ($attempt < $retries && strpos($response->get_error_message(), 'timeout') !== false) {
                    sleep($backoff);
                    $backoff *= 2;
                    continue;
                }
                return ['error' => 'WP_Error: ' . $response->get_error_message()];
            }
    
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code == 429) {
                $retry_after = wp_remote_retrieve_header($response, 'retry-after') ?: $backoff;
                error_log("api_get: WARN: Rate limit hit (429) for $url, attempt $attempt/$retries, sleeping $retry_after seconds");
                if ($attempt < $retries) {
                    sleep($retry_after);
                    $backoff *= 2;
                    continue;
                }
                return ['error' => 'Rate limit exceeded'];
            }
    
            if ($status_code != 200) {
                $body = wp_remote_retrieve_body($response);
                error_log("api_get: ERROR: HTTP $status_code for $url, body preview: " . substr($body, 0, 200));
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
                return ['error' => 'Empty response'];
            }
    
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("api_get: ERROR: Invalid JSON response from $url: " . json_last_error_msg() . ". Raw body preview: " . substr($body, 0, 500));
                if ($attempt < $retries) {
                    sleep($backoff);
                    $backoff *= 2;
                    continue;
                }
                return ['error' => 'Invalid JSON: ' . json_last_error_msg()];
            }
    
            // Adjust cache duration based on response size
            $cache_duration = count($data['results'] ?? []) > 100 ? WEEK_IN_SECONDS : 2 * WEEK_IN_SECONDS;
            set_transient($cache_key, $data, $cache_duration);
            error_log("api_get: Data cached for $url, duration=$cache_duration seconds");
            return $data;
        }
    
        error_log("api_get: ERROR: All retries exhausted for $url");
        return ['error' => 'All retries exhausted'];
    }

    public function get_publishers($name = '', $page = 1, $per_page = 10, $bypass_cache = false, $letter = 'all') {    
        // Cache key for the full publisher list
        $transient_key = 'metron:publishers:json:' . md5(":$name:letter$letter:full");
        $full_publishers = [];
    
        if (!$bypass_cache) {
            $cached = get_transient($transient_key);
            if ($cached !== false) {           
                $full_publishers = $cached;
            }
        }
    
        if (empty($full_publishers)) {
            // Fetch page 1
            $url = $this->api_base . 'publisher/?page=1';
            $params = [];
            if (!empty($name)) {
                $params['name'] = $name;
            }
            if (!empty($params)) {
                $url .= '&' . http_build_query($params);
            }
            $data = $this->api_get($url);
    
            if (!$data || !isset($data['results']) || !is_array($data['results'])) {           
                return ['items' => [], 'total' => 0];
            }
    
            $full_publishers = array_map(function($item) {
                return [
                    'id' => isset($item['id']) ? (int)$item['id'] : 0,
                    'name' => $item['name'] ?? ''
                ];
            }, $data['results']);
    
            // Fetch page 2 if it exists
            if (!empty($data['next'])) {
                $url = $this->api_base . 'publisher/?page=2';
                if (!empty($params)) {
                    $url .= '&' . http_build_query($params);
                }
                $data_page2 = $this->api_get($url);          
    
                if ($data_page2 && isset($data_page2['results']) && is_array($data_page2['results'])) {
                    $page2_publishers = array_map(function($item) {
                        return [
                            'id' => isset($item['id']) ? (int)$item['id'] : 0,
                            'name' => $item['name'] ?? ''
                        ];
                    }, $data_page2['results']);
                    $full_publishers = array_merge($full_publishers, $page2_publishers);
                }
            }
    
            // Cache the full list
            set_transient($transient_key, $full_publishers, $this->cache_ttl);

        }
    
        // Filter publishers by letter if specified
        if ($letter !== 'all') {
            $full_publishers = array_filter($full_publishers, function($publisher) use ($letter) {
                $first_char = strtoupper(substr($publisher['name'], 0, 1));
                if ($letter === '#') {
                    return !ctype_alpha($first_char);
                }
                return $first_char === strtoupper($letter);
            });
        }
    
        // Apply name filter if provided
        if (!empty($name)) {
            $full_publishers = array_filter($full_publishers, function($publisher) use ($name) {
                return stripos($publisher['name'], $name) !== false;
            });
        }
    
        // Convert to indexed array
        $full_publishers = array_values($full_publishers);
    
        // Slice the filtered list for the requested page
        $total = count($full_publishers);
        $start = ($page - 1) * $per_page;
        $sliced_publishers = array_slice($full_publishers, $start, $per_page);
        
        return [
            'items' => $sliced_publishers,
            'total' => $total
        ];
    }

    public function get_publisher_info($publisher_id) {
        if (empty($publisher_id)) {
            return [];
        }
    
        $publisher_id = (int)$publisher_id;
        $transient_key = 'metron_publisher_' . $publisher_id;
        $cached = get_transient($transient_key);
    
        if ($cached !== false) {
            return $cached;
        }
    
        $url = $this->api_base . 'publisher/' . $publisher_id . '/';
        $data = $this->api_get($url);
    
        if (!$data || empty($data['name'])) { 
            return [];
        }
    
        $publisher_data = [
            'id' => isset($data['id']) ? (int)$data['id'] : 0,
            'name' => $data['name'] ?? '',
            'image' => $data['image'] ?? '',
            'desc' => $data['desc'] ?? '',
            'founded' => $data['founded'] ?? ''
        ];
    
        set_transient($transient_key, $publisher_data, $this->cache_ttl);
        return $publisher_data;
    }

    public function get_series($publisher_id, $page = 1, $per_page = 10, $search = '', $letter = 'all') {
        $publisher_id = (int)$publisher_id;
        $page         = max(1, (int)$page);
        $per_page     = max(1, (int)$per_page);
    
        // === 1. FULL LIST CACHE KEY ===
        $cache_key_full = "metron:series_full:$publisher_id";
        $full_series    = get_transient($cache_key_full);
    
        // === 2. FILTERED RESULT CACHE KEY ===
        $search_key = $search ? strtolower(trim($search)) : '';
        $letter_key = $letter !== 'all' ? strtolower($letter) : '';
        $cache_key  = "metron:series:$publisher_id:s$search_key:l$letter_key:p$page";
        $cached_result = get_transient($cache_key);
    
        // === 3. RETURN CACHED FILTERED RESULT IF AVAILABLE ===
        if ($cached_result !== false) {
            return $cached_result;
        }
    
        // === 4. FETCH FULL LIST IF NOT CACHED ===
        if ($full_series === false) {
            $full_series = [];
            $api_page = 1;
            $max_pages = 100;
    
            error_log("CACHING FULL SERIES LIST FOR PUBLISHER $publisher_id");
    
            do {
                $url = $this->api_base . "publisher/$publisher_id/series_list/?page=$api_page";
                $response = $this->api_get($url);
    
                if (!$response || empty($response['results'])) break;
    
                foreach ($response['results'] as $item) {
                    if (!isset($item['id'], $item['series'])) continue;
    
                    $full_series[] = [
                        'series_id'    => (int)$item['id'],
                        'name'         => $item['series'],
                        'volume'       => $item['volume'] ?? '1',
                        'issue_count'  => $item['issue_count'] ?? 0,
                        'year_began'   => $item['year_began'] ?? 'N/A',
                    ];
                }
    
                $api_page++;
                if ($api_page > $max_pages) break;
    
            } while (!empty($response['next']));
    
            // Cache full list for 30 days
            set_transient($cache_key_full, $full_series, 30 * DAY_IN_SECONDS);
            error_log("CACHED " . count($full_series) . " series for publisher $publisher_id");
        }
    
        // === 5. FILTER IN MEMORY ===
        $filtered = $full_series;
    
        if ($search_key) {
            $filtered = array_filter($filtered, function($s) use ($search_key) {
                return strpos(strtolower($s['name']), $search_key) !== false;
            });
        }
    
        if ($letter_key) {
            $filtered = array_filter($filtered, function($s) use ($letter_key) {
                $first = strtoupper(substr($s['name'], 0, 1));
                return $letter_key === '#' ? !ctype_alpha($first) : $first === strtoupper($letter_key);
            });
        }
    
        $filtered = array_values($filtered);
        $total = count($filtered);
        $offset = ($page - 1) * $per_page;
        $paged = array_slice($filtered, $offset, $per_page);
    
        // === 6. BUILD RESULT ===
        $result = [
            'items'     => $paged,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $per_page
        ];
    
        // === 7. CACHE FILTERED RESULT (short TTL) ===
        $filtered_ttl = HOUR_IN_SECONDS; // 1 hour — adjust as needed
        if ($search_key || $letter_key !== 'all') {
            $filtered_ttl = 6 * HOUR_IN_SECONDS; // longer for search/letter
        }
        set_transient($cache_key, $result, $filtered_ttl);
    
        return $result;
    }

    public function get_series_issues($title_id, $current_page, $search = '') {
        $start_time = microtime(true);
        error_log("get_series_issues: Start for title_id=$title_id, page=$current_page, search=$search");
    
        // Fetch series data
        $series_cache_key = "metron:series:{$title_id}";
        $series = get_transient($series_cache_key);
        if ($series === false) {
            $series_url = "{$this->api_base}series/$title_id/";
            $series = $this->api_get($series_url, 3);
            if (is_array($series) && isset($series['error'])) {
                error_log("get_series_issues: Series fetch error for title_id=$title_id: " . $series['error']);
                return ['error' => 'Failed to fetch series data: ' . $series['error']];
            }
            if ($series && !empty($series['name'])) {
                set_transient($series_cache_key, $series, 2 * WEEK_IN_SECONDS);
            } else {
                error_log("get_series_issues: Series not found for title_id=$title_id");
                return ['error' => 'Series not found'];
            }
        }
    
        // Fetch all issues (no pagination params)
        $full_issues_cache_key = "metron:issues_full:{$title_id}";
        $full_issues_data = get_transient($full_issues_cache_key);
        if ($full_issues_data === false) {
            $issue_list_url = "{$this->api_base}series/$title_id/issue_list/";
            error_log("get_series_issues: Fetching full issues from $issue_list_url");
            $response = $this->api_get($issue_list_url, 3);
            if (is_array($response) && isset($response['error'])) {
                error_log("get_series_issues: Failed to fetch issues for title_id=$title_id: " . $response['error']);
                return ['error' => 'Failed to fetch issues: ' . $response['error']];
            }
            if (!$response || empty($response['results'])) {
                error_log("get_series_issues: No issues found for title_id=$title_id");
                $full_issues_data = ['count' => 0, 'results' => []];
            } else {
                $all_issues = $response['results'];
                error_log("get_series_issues: Fetched " . count($all_issues) . " issues for title_id=$title_id");
    
                // Sort issues by number
                usort($all_issues, function($a, $b) {
                    $num_a = floatval($a['number'] ?? 0);
                    $num_b = floatval($b['number'] ?? 0);
                    return $num_a <=> $num_b;
                });
    
                $full_issues_data = ['count' => count($all_issues), 'results' => $all_issues];
            }
            set_transient($full_issues_cache_key, $full_issues_data, 2 * WEEK_IN_SECONDS);
        }
    
        // Apply search filter
        $filtered_issues = $search ? array_filter($full_issues_data['results'], function($issue) use ($search) {
            $issue_title = strtolower($issue['issue'] ?? '');
            $issue_number = strtolower($issue['number'] ?? '');
            $cover_date = strtolower($issue['cover_date'] ?? '');
            return strpos($issue_title, $search) !== false
                || strpos($issue_number, $search) !== false
                || strpos($cover_date, $search) !== false;
        }) : $full_issues_data['results'];
    
        $filtered_issues = array_values($filtered_issues);
        $total_issues = count($filtered_issues);
        $per_page = 10;
        $start = ($current_page - 1) * $per_page;
        $paginated_issues = array_slice($filtered_issues, $start, $per_page);
    
        $issue_list_data = [
            'count' => $total_issues,
            'results' => $paginated_issues
        ];
    
        $end_time = microtime(true);
        error_log("get_series_issues: Completed in " . round($end_time - $start_time, 2) . " seconds for title_id=$title_id");
    
        return [
            'series' => $series,
            'issue_list' => $issue_list_data
        ];
    }
    public function get_series_images($series_ids) {
        $images = [];
        $cache_key_prefix = 'metron:issue_list:';
        $missing_ids = [];
    
        // Check cache for each series ID
        foreach ($series_ids as $id) {
            $cache_key = $cache_key_prefix . $id;
            $cached = get_transient($cache_key);
            if ($cached !== false && !empty($cached['results'][0]['image'])) {
                $images[$id] = $cached['results'][0]['image'];        
            } else {
                $missing_ids[] = $id;
            }
        }
    
        // Batch fetch missing images
        if (!empty($missing_ids)) {
            foreach ($missing_ids as $id) {
                $url = $this->api_base . "series/$id/issue_list/?per_page=1";
                $data = $this->api_get($url);
                if ($data && !empty($data['results'][0]['image'])) {
                    $images[$id] = $data['results'][0]['image'];
                    set_transient($cache_key_prefix . $id, $data, $this->dataset_ttl * 2);                
                } else {
                    $images[$id] = PUBLISHER_PLACEHOLDER_IMAGE_URL;
                    error_log("No image found for series ID: $id");
                }
            }
        }
    
        return $images;
    }
    public function get_comicvine_issue_info($cv_id) {
        if (!$cv_id) return null;
    
        $cv_api_key = get_option('comic_vine_api_key', '');
        if (!$cv_api_key) {
            error_log('ERROR: Missing Comic Vine API Key');
            return null;
        }
    
        $cache_key = "cv_issue_full_$cv_id";
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $cv_url = "https://comicvine.gamespot.com/api/issue/4000-{$cv_id}/?api_key={$cv_api_key}&format=json";
        $cv_response = wp_remote_get($cv_url, [
            'headers' => [
                'User-Agent' => 'CollectibleSpotBot/1.0 (+'  . get_site_url() . ') '                
            ],
            'timeout' => 30,
        ]);
    
        if (is_wp_error($cv_response)) return null;
    
        $cv_data = json_decode(wp_remote_retrieve_body($cv_response), true);
        if (empty($cv_data['results'])) return null;
    
        $cv_result = $cv_data['results'];
        $merged = $cv_result; // start with ComicVine as base
    
        //Attempt to fetch from Metron using cv_id
        $metron_url = $this->api_base . 'issue/?cv_id=' . $cv_id;
        $metron_response = $this->api_get($metron_url);
    
        if (!is_wp_error($metron_response) && !empty($metron_response['results'][0])) {
            $metron = $metron_response['results'][0];
    
            // Merge Metron data into ComicVine base
            $merged['metron'] = $metron;
    
            // Optional: supplement missing fields
            if (empty($merged['cover_date']) && !empty($metron['cover_date'])) {
                $merged['cover_date'] = $metron['cover_date'];
            }
    
            if (empty($merged['description']) && !empty($metron['desc'])) {
                $merged['description'] = $metron['desc'];
            }
    
            // Add reprint data
            if (!empty($metron['reprints'])) {
                $merged['reprint_info'] = array_column($metron['reprints'], 'issue');
            }
        }
    
        // === 3. Extract highlights ===
        $highlights = [];
    
        if (!empty($merged['first_appearance_characters'])) $highlights[] = 'First Appearance of Characters';
        if (!empty($merged['characters_died_in'])) $highlights[] = 'Character Deaths';
        if (!empty($merged['first_appearance_locations'])) $highlights[] = 'New Locations Introduced';
        if (!empty($merged['first_appearance_objects'])) $highlights[] = 'First Appearance of Objects';
        if (!empty($merged['first_appearance_concepts'])) $highlights[] = 'First Appearance of Concepts';
    
        if (!empty($merged['concept_credits'])) {
            foreach ($merged['concept_credits'] as $concept) {
                $name = strtolower($concept['name']);
                if (strpos($name, 'homage') !== false) $highlights[] = 'Homage Cover';
                if (strpos($name, 'reprint') !== false) $highlights[] = 'Reprint Issue';
            }
        }
    
        if (!empty($merged['reprint_info'])) {
            $highlights[] = 'Contains Reprinted Material';
        }
    
        if (!empty($merged['description'])) {
            $desc = strtolower($merged['description']);
            if (strpos($desc, 'first appearance') !== false) $highlights[] = 'First Appearance Mentioned';
            if (strpos($desc, 'death of') !== false) $highlights[] = 'Mentions a Death';
            if (strpos($desc, 'second appearance') !== false) $highlights[] = 'Second Appearance';
        }
    
        $merged['_highlights'] = array_unique($highlights);
    
        // === 4. Cache and return ===
        set_transient($cache_key, $merged, $this->dataset_ttl);
        return $merged;
    }
    
    public function get_metron_cv_id($metron_id) {
        if (!$metron_id) {
            error_log("ERROR: Invalid metron_id provided");
            return null;
        }
    
        $cache_key = 'metron_cv_id_' . md5($metron_id);
        $cached = get_transient($cache_key);
    
        if ($cached !== false) {
            return $cached;
        }
    
        $url = $this->api_base . 'issue/' . $metron_id . '/';         
    
        $response = $this->api_get($url);
    
        if (is_wp_error($response) || empty($response['cv_id'])) {       
            return null;
        }
    
        $cv_id = $response['cv_id'];    
    
        set_transient($cache_key, $cv_id, WEEK_IN_SECONDS); // Cache for 1 day
    
        return $cv_id;
    }
}