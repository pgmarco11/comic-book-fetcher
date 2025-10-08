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
    public $dataset_ttl = 7 * WEEK_IN_SECONDS; // 1 week
    public $cache_ttl = 24 * 3600; // 24 hours

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
    
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            error_log("api_get: Attempt $attempt/$retries for $url");
            $response = wp_remote_get($url, [
                'headers' => [
                    'User-Agent' => 'ComicBookFetcher/1.0 (+https://thecollectiblespot.com)',
                    'Accept' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode("$username:$password")
                ],
                'timeout' => 30,
            ]);
    
            if (is_wp_error($response)) {
                error_log("api_get: ERROR: WP_Error - " . $response->get_error_message() . " for $url");
                return ['error' => 'WP_Error: ' . $response->get_error_message()];
            }
    
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code == 429) {
                $retry_after = wp_remote_retrieve_header($response, 'retry-after') ?: $backoff;
                error_log("api_get: WARN: Rate limit hit (429) for $url, attempt $attempt/$retries, sleeping $retry_after seconds");
                if ($attempt < $retries) {
                    sleep($retry_after);
                    $backoff *= 2; // Exponential backoff
                    continue;
                }
                return ['error' => 'Rate limit exceeded'];
            }
    
            if ($status_code != 200) {
                error_log("api_get: ERROR: HTTP $status_code for $url");
                return ['error' => "HTTP $status_code"];
            }
    
            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                error_log("api_get: ERROR: Empty response body for $url");
                return ['error' => 'Empty response'];
            }
    
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("api_get: ERROR: Invalid JSON response from $url: " . json_last_error_msg());
                return ['error' => 'Invalid JSON: ' . json_last_error_msg()];
            }
    
            set_transient($cache_key, $data, 2 * WEEK_IN_SECONDS); // Cache for 2 weeks
            error_log("api_get: Data cached for $url");
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
        $cache_key = "metron:series:$publisher_id:s$search:l$letter";
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false && empty($search) && $letter === 'all') {
            $total = count($cached_data['items']);
            $offset = ($page - 1) * $per_page;
            $paged_series = array_slice($cached_data['items'], $offset, $per_page);
            return [
                'items' => $paged_series,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page
            ];
        }
    
        $series = [];
        $api_page = 1;
        $api_per_page = 100;
    
        do {
            $url = $this->api_base . "publisher/$publisher_id/series_list/?page=$api_page";
            $response = $this->api_get($url);
            
            if (!$response || empty($response['results'])) {               
                break;
            }
    
            foreach ($response['results'] as $item) {
                if (!is_array($item) || !isset($item['id'], $item['series'])) {
                    continue;
                }
    
                $series[] = [
                    'series_id' => (int)$item['id'],
                    'name' => $item['series'],
                    'volume' => $item['volume'] ?? 'N/A',
                    'issue_count' => $item['issue_count'] ?? 0,
                    'year_began' => $item['year_began'] ?? 'N/A',
                    'first_issue_image' => '' // Defer to lazy loading
                ];
            }
    
            $api_page++;
        } while (!empty($response['next']));
    
        // Apply search and letter filters
        $filtered_series = $series;
        if ($search) {
            $filtered_series = array_filter($filtered_series, function($item) use ($search) {
                return stripos($item['name'], $search) !== false;
            });
            $filtered_series = array_values($filtered_series);
        }
    
        if ($letter !== 'all') {
            $filtered_series = array_filter($filtered_series, function($item) use ($letter) {
                $firstChar = strtoupper(substr($item['name'], 0, 1));
                if ($letter === '#') {
                    return !preg_match('/^[A-Za-z]/', $firstChar);
                }
                return $firstChar === strtoupper($letter);
            });
            $filtered_series = array_values($filtered_series);
        }
    
        if (!empty($series) && empty($search) && $letter === 'all') {
            set_transient($cache_key, ['items' => $series], $this->dataset_ttl * 4);
        }
    
        $total = count($filtered_series);
        $offset = ($page - 1) * $per_page;
        $paged_series = array_slice($filtered_series, $offset, $per_page);
    
        return [
            'items' => $paged_series,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page
        ];
    }

    public function get_series_issues($title_id, $current_page, $search = '') {
        $series_cache_key = "metron:series:{$title_id}";
        $series = get_transient($series_cache_key);
        if ($series === false) {
            $series_url = "{$this->api_base}series/$title_id/";
            $series = $this->api_get($series_url);
            if (is_array($series) && isset($series['error'])) {
                error_log("get_series_issues: Series fetch error for title_id=$title_id: " . $series['error']);
                return ['error' => $series['error']];
            }
            if ($series && !empty($series['name'])) {
                set_transient($series_cache_key, $series, 2 * WEEK_IN_SECONDS);
            } else {
                error_log("get_series_issues: Series not found for title_id=$title_id");
                return ['error' => 'Series not found'];
            }
        }
    
        $issue_cache_key = "metron:issues:{$title_id}:page:{$current_page}:search:{$search}";
        $issue_list_data = get_transient($issue_cache_key);
        if ($issue_list_data === false) {
            $all_issues = [];
            $api_page = 1; // Use separate variable for API pagination
            $issue_list_url = "{$this->api_base}series/$title_id/issue_list/?per_page=100";
            error_log("get_series_issues: Fetching issues from $issue_list_url");
    
            do {
                $response = $this->api_get($issue_list_url . '&page=' . $api_page);
                if (is_array($response) && isset($response['error'])) {
                    error_log("get_series_issues: Failed to fetch issues for title_id=$title_id, page=$api_page: " . $response['error']);
                    break;
                }
                if (!$response || empty($response['results'])) {
                    error_log("get_series_issues: No issues found for title_id=$title_id, page=$api_page");
                    break;
                }
                $all_issues = array_merge($all_issues, $response['results']);
                $api_page++;
                usleep(300000); // 500ms delay to avoid rate limits
            } while (!empty($response['next']));
    
            // Log all issues fetched
            error_log("get_series_issues: Total issues fetched for title_id=$title_id: " . count($all_issues));
    
            // Sort issues by number
            usort($all_issues, function($a, $b) {
                $num_a = floatval($a['number'] ?? 0);
                $num_b = floatval($b['number'] ?? 0);
                return $num_a <=> $num_b;
            });
    
            // Apply search filtering
            $filtered_issues = $search ? array_filter($all_issues, function($issue) use ($search) {
                $issue_title = strtolower($issue['issue'] ?? '');
                $issue_number = strtolower($issue['number'] ?? '');
                $cover_date = strtolower($issue['cover_date'] ?? '');
                return strpos($issue_title, $search) !== false
                    || strpos($issue_number, $search) !== false
                    || strpos($cover_date, $search) !== false;
            }) : $all_issues;
    
            $total_issues = count($filtered_issues);
            $per_page = 10;
            $start = ($current_page - 1) * $per_page; // Fix: Use $current_page
            $paginated_issues = array_slice($filtered_issues, $start, $per_page);
    
            $issue_list_data = [
                'count' => $total_issues,
                'results' => $paginated_issues
            ];
    
            set_transient($issue_cache_key, $issue_list_data, 2 * WEEK_IN_SECONDS);
            error_log("get_series_issues: Issues cached for title_id=$title_id, page=$current_page, count=" . count($paginated_issues));
        }
    
        // Log the final issue list data
        error_log("get_series_issues: Returning issue_list_data=" . print_r($issue_list_data, true));
    
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