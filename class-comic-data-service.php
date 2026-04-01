<?php
/**
 * ComicDataService – central data layer for Metron & Comic Vine.
 *
 * All public methods are cache-aware, rate-limit safe and return
 * consistent arrays.  Use via ComicRenderer (or any other class).
 *
 * @package ComicBooksFetcher
 * @since   1.0.0
 */
class ComicDataService {

    /** @var MetronClient */
    protected $client;

    /** Default TTL for cached API responses (2 weeks) */
    const DEFAULT_DATASET_TTL = 1209600; // 14 days in seconds

    public function __construct( MetronClient $client ) {
        $this->client = $client;
    }

    public function get_client() {
        return $this->client;
    }
    
    /**-----------------------------------------------------------------
     *  SAFE TTL GETTER (fixes undefined property)
     * ----------------------------------------------------------------- */
    private function get_dataset_ttl() {
        return isset( $this->client->dataset_ttl )
            ? (int) $this->client->dataset_ttl
            : self::DEFAULT_DATASET_TTL;
    }   

    /**-----------------------------------------------------------------
     *  PUBLISHERS – full list (cached once a week)
     * ----------------------------------------------------------------- */
    public function get_publishers(
        $name        = '',
        $page        = 1,
        $per_page    = 50,    
        $letter      = 'all',
        $bypass_cache = false
    ) {
        $transient_key = 'metron:publishers:full:v2'; // versioned

        $full = $bypass_cache ? false : get_transient($transient_key);
    
        if ($full === false) {
            $full = [];
            $api_page = 1;
            do {
                $url = $this->client->api_base . "publisher/?page={$api_page}&page_size=100";
                $data = $this->client->api_get($url);

                if (!$data || isset($data['detail']) && str_contains($data['detail'], 'Invalid page')) {
                    error_log("Publisher series_list: Invalid page or end reached for page {$api_page}");
                    break;
                }

                if (empty($data['results'])) {
                    break;
                }
    
                foreach ($data['results'] as $p) {
                    $full[] = ['id' => $p['id'], 'name' => $p['name']];
                }
                $api_page++;
                sleep(2); // respectful delay
            } while (!empty($data['next']));
    
            set_transient($transient_key, $full, WEEK_IN_SECONDS * 2); // longer TTL
        }

        if ( $letter !== 'all' ) {
            $full = array_filter(
                $full,
                function ( $p ) use ( $letter ) {
                    $first = strtoupper( substr( $p['name'], 0, 1 ) );
                    return $letter === '#' ? ! ctype_alpha( $first ) : $first === strtoupper( $letter );
                }
            );
        }

        if ( $name ) {
            $full = array_filter(
                $full,
                fn( $p ) => stripos( $p['name'], $name ) !== false
            );
        }

        $full  = array_values( $full );
        $total = count( $full );
        $start = ( $page - 1 ) * $per_page;
        $slice = array_slice( $full, $start, $per_page );        
        $result = [];

   
        $result = [
            'items' => $slice,
            'total' => $total,
        ];           
        return $result;
    }

    public function get_enriched_publishers( $page = 1, $per_page = 50, $letter = 'all', $bypass_cache = false ) {
        $key = "metron:publishers:{$page}:{$per_page}:{$letter}";
    
        // Only bypass cache when explicitly asked (e.g. admin “refresh” button)    
        if ( ! $bypass_cache ) {
            $cached = get_transient( $key );
            if ( $cached !== false ) {       
                return $cached;
            }         
        } else {
            error_log("CACHE BYPASSED → forcing API call");
        }
    
        // No cache (or forced bypass) → fetch from API
        $raw = $this->get_publishers( '', $page, $per_page, $letter, false );
    
        $items = [];
        foreach ( $raw['items'] ?? [] as $pub ) {
            $info    = $this->get_publisher_info( $pub['id'] );
            $items[] = [
                'id'      => $info['id'] ?? $pub['id'],
                'name'    => $info['name'] ?? $pub['name'],
                'image'   => $info['image'] ?? PUBLISHER_PLACEHOLDER_IMAGE_URL,
                'founded' => $info['founded'] ?? '',
                'desc'    => $info['desc'] ?? '',
            ];
        }       
        
        $result = [
            'items' => $items,
            'total' => $raw['total'] ?? 0,
        ];    
        // Cache for 12 hours (same as before)
        set_transient( $key, $result, 12 * HOUR_IN_SECONDS );        
        return $result;
    }

    /* -----------------------------------------------------------------
     *  PUBLISHER INFO (single record)
     * ----------------------------------------------------------------- */
    public function get_publisher_info( $publisher_id ) {
        $key    = "metron_publisher_$publisher_id";
        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return $cached;
        }

        $url  = $this->client->api_base . "publisher/$publisher_id/";
        $data = $this->client->api_get( $url );

        if ( ! $data || empty( $data['name'] ) ) {
            return [];
        }

        $info = [
            'id'      => $data['id'],
            'name'    => $data['name'],
            'image'   => $data['image'] ?? '',
            'desc'    => $data['desc'] ?? '',
            'founded' => $data['founded'] ?? '',
        ];

        set_transient( $key, $info, WEEK_IN_SECONDS );
        return $info;
    }

    /** -----------------------------------------------------------------
     *  SERIES LIST for a publisher
     * ----------------------------------------------------------------- */
    public function get_series(
        $publisher_id,
        $page = 1,
        $per_page = 100,
        $search = '',
        $letter = 'all',
        $force_api = false,
        $batch_size = 5
    ) {
        $cache_key = "metron:series_full:$publisher_id";
        $full = get_transient($cache_key) ?: [];
    
        $last_page_key = "metron:series_last_page:$publisher_id";
        $api_page = get_transient($last_page_key) ?: 1;
    
        if ($force_api || empty($full)) {
    
            $pages_fetched = 0;
    
            do {
                $url = $this->client->api_base . "publisher/$publisher_id/series_list/?page={$api_page}&page_size=100";
                $response = $this->client->api_get($url);
    
                if (
                    !$response ||
                    empty($response['results']) ||
                    (isset($response['detail']) && str_contains($response['detail'], 'Invalid page'))
                ) {
                    break;
                }
    
                foreach ($response['results'] as $item) {
                    $full[] = [
                        'series_id'   => $item['id'],
                        'name'        => $item['series'],
                        'volume'      => $item['volume'] ?? '1',
                        'issue_count' => $item['issue_count'] ?? 0,
                        'year_began'  => $item['year_began'] ?? 'N/A',  
                        'first_issue_image' => $item['first_issue_image'] ?? '',
                    ];
                }
    
                $api_page++;
                $pages_fetched++;
    
                // reduce delay massively
                usleep(200000); // 0.2s
    
            } while (!empty($response['next']) && $pages_fetched < $batch_size);
    
            set_transient($cache_key, $full, 30 * DAY_IN_SECONDS);
            set_transient($last_page_key, $api_page, 12 * HOUR_IN_SECONDS);
        }
    
        // --- filtering ---
        $filtered = $full;

        if (!is_array($filtered)) {
            $filtered = [];
        }

        // search filter
        if ($search) {
            $search = strtolower(trim($search));
            $filtered = array_filter($filtered, fn($s) =>
                strpos(strtolower($s['name']), $search) !== false
            );
        }

        // letter filter
        if ($letter !== 'all') {
            $filtered = array_filter($filtered, function($s) use ($letter) {
                $first = strtoupper(substr($s['name'], 0, 1));
                return $letter === '#'
                    ? !ctype_alpha($first)
                    : $first === strtoupper($letter);
            });
        }

        // reindex
        $filtered = array_values($filtered);

        $total = count($filtered);
        $offset = ($page - 1) * $per_page;
        
        // FIRST: paginate
        $paged = array_slice($filtered, $offset, $per_page);              
        // RETURN the modified dataset
        return [
            'items' => $paged,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Get issues for a series with full caching, search, and pagination.
     *
     * This is now the SINGLE SOURCE OF TRUTH for issue data.
     *
     * @param int    $title_id     Series ID
     * @param int    $current_page Current page (1-based)
     * @param string $search       Search term (optional)
     * @return array
     */
    public function get_series_issues( $title_id, $current_page = 1, $search = '' ) {
        $title_id     = (int) $title_id;
        $current_page = max(1, (int) $current_page);
        $per_page     = 10;
    
        $full_list_key   = "metron:issue_list_full:{$title_id}:v5";
        $all_issues_data = get_transient( $full_list_key );
    
        error_log("get_series_issues called for series {$title_id} page {$current_page} - cache " . ($all_issues_data !== false ? 'HIT' : 'MISS'));
    
        // Fetch full list if not cached
        if ($all_issues_data === false || !isset($all_issues_data['results']) || !is_array($all_issues_data['results'])) {
            $all = [];
            $page_fetch = $current_page;
            // Start fetching from the requested page to optimize for user navigation patterns  
    
            do {
                $url = $this->client->api_base . "series/{$title_id}/issue_list/?page={$page_fetch}&page_size=100";
                $response = $this->client->api_get($url);
    
                if (isset($response['error']) || empty($response['results']) || !is_array($response['results'])) {
                    break;
                }
    
                $all = array_merge($all, $response['results']);
                $page_fetch++;
    
            } while (!empty($response['next']) && $page_fetch < 10); // safety limit
    
            // Sort by issue number
            usort($all, function($a, $b) {
                $numA = trim((string)($a['number'] ?? '0'));
                $numB = trim((string)($b['number'] ?? '0'));
                $valueA = is_numeric($numA) ? (float)$numA : INF;
                $valueB = is_numeric($numB) ? (float)$numB : INF;
                if ($valueA !== $valueB) return $valueA <=> $valueB;
                return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
            });
    
            $all_issues_data = [
                'count'   => count($all),
                'results' => $all
            ];
    
            if (!empty($all)) {
                set_transient($full_list_key, $all_issues_data, 30 * DAY_IN_SECONDS);
            } else {
                error_log("Warning: Empty issue list for series {$title_id}");
            }
        }
    
        // Get series metadata (cached)
        $series_key = "metron:series:{$title_id}";
        $series = get_transient($series_key);
        if ($series === false) {
            $series = $this->client->api_get($this->client->api_base . "series/{$title_id}/");
            if (empty($series['name'])) {
                return ['error' => 'Series not found'];
            }
            set_transient($series_key, $series, 14 * DAY_IN_SECONDS);
        }
    
        // Apply search
        $filtered = $search 
            ? array_filter($all_issues_data['results'], function($i) use ($search) {
                $s   = strtolower(trim($search));
                $num = strtolower(trim($i['number'] ?? ''));
                if ($num === $s || stripos($num, $s) === 0) return true;
                if (stripos(strtolower($i['issue'] ?? ''), $s) !== false || 
                    stripos($i['cover_date'] ?? '', $s) !== false) return true;
                return false;
            })
            : $all_issues_data['results'];
    
        $filtered = array_values($filtered);
    
        // Paginate
        $offset       = ($current_page - 1) * $per_page;
        $paged_issues = array_slice($filtered, $offset, $per_page);
    
        $total_filtered = count($filtered);
        $total_pages    = max(1, ceil($total_filtered / $per_page));
    
        // Graceful handling for page > total
        if ($current_page > $total_pages && $total_filtered > 0) {          
            $paged_issues = [];
        }
    
        return [
            'series'       => is_array($series) ? $series : [],
            'issue_list'   => [
                'count'   => $total_filtered,
                'results' => $paged_issues,
            ],
            'current_page' => $current_page,
            'total_pages'  => $total_pages,
            'total_issues' => $total_filtered,
            'per_page'     => $per_page,
        ];
    }
        

        /* -----------------------------------------------------------------
     *  SINGLE ISSUE
     * ----------------------------------------------------------------- */

    /**
     * Fetch a single issue, including verification it belongs to the given series.
     * Returns the issue data only (series is fetched but not merged — caller can fetch series separately if needed).
     *
     * @param int $title_id  Series ID
     * @param int $issue_id  Issue ID
     * @return array|null    Issue data array or null on failure
     */
    public function get_single_issue( $title_id, $issue_id ) {
        $cache_key = "metron:issue:{$title_id}_{$issue_id}"; 

        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {          
            if ( ($cached['series']['id'] ?? 0) !== $title_id ) {
                return null;
            }
            return $cached;
        }

        $url_issue = $this->client->api_base . "issue/{$issue_id}/";
        $issue_data = $this->client->api_get( $url_issue );

        if ( isset( $issue_data['error'] ) || ! is_array( $issue_data ) ) {
            error_log( "Metron issue fetch error {$issue_id}: " . ( $issue_data['error'] ?? 'No data' ) );
            return null;
        }

        // Verify it actually belongs to this series
        if ( ( $issue_data['series']['id'] ?? 0 ) !== (int) $title_id ) {
            error_log( "Issue {$issue_id} does not belong to series {$title_id}" );
            return null;
        }
        
        // Optional: small cleanup/normalization (add defaults, fix types, etc.)
        $issue_data = wp_parse_args( $issue_data, [
            'number'      => '',
            'name'        => '',
            'cover_date'  => '',
            'image'       => '',
            'description' => '',
            'credits'     => [],
            'characters'  => [],
            'reprints'    => [],
        ] );

        set_transient( $cache_key, $issue_data, 2 * WEEK_IN_SECONDS );

        return $issue_data;
    }
 

    /* -----------------------------------------------------------------
     *  COMIC VINE + METRON merged issue data
     * ----------------------------------------------------------------- */
    public function get_comicvine_issue_info( $cv_id ) {
        if ( ! $cv_id ) {
            return null;
        }

        $cv_key = get_option( 'comic_vine_api_key', '' );
        if ( ! $cv_key ) {
            error_log( 'Comic Vine API key missing' );
            return null;
        }

        $cache_key = "cv_issue_full_$cv_id";
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $url = "https://comicvine.gamespot.com/api/issue/4000-{$cv_id}/?api_key={$cv_key}&format=json";
        $res = wp_remote_get(
            $url,
            [
                'timeout' => 30,
                'headers' => [ 'User-Agent' => 'CollectibleSpotBot/1.1 (+' . get_site_url() . ')' ],
            ]
        );

        if ( is_wp_error( $res ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( empty( $body['results'] ) ) {
            return null;
        }

        $merged = $body['results'];

        $met_url = $this->client->api_base . 'issue/?cv_id=' . $cv_id;
        $met_res = $this->client->api_get( $met_url );

        if ( $met_res && ! empty( $met_res['results'][0] ) ) {
            $met = $met_res['results'][0];
            $merged['metron'] = $met;

            if ( empty( $merged['cover_date'] ) && ! empty( $met['cover_date'] ) ) {
                $merged['cover_date'] = $met['cover_date'];
            }
            if ( empty( $merged['description'] ) && ! empty( $met['desc'] ) ) {
                $merged['description'] = $met['desc'];
            }
            if ( ! empty( $met['reprints'] ) ) {
                $merged['reprint_info'] = array_column( $met['reprints'], 'issue' );
            }
        }

        $highlights = [];
        $fields = [
            'first_appearance_characters' => 'First Appearance of Characters',
            'characters_died_in'          => 'Character Deaths',
            'first_appearance_locations'  => 'New Locations Introduced',
            'first_appearance_objects'    => 'First Appearance of Objects',
            'first_appearance_concepts'   => 'First Appearance of Concepts',
        ];
        foreach ( $fields as $key => $txt ) {
            if ( ! empty( $merged[ $key ] ) ) {
                $highlights[] = $txt;
            }
        }

        if ( ! empty( $merged['concept_credits'] ) ) {
            foreach ( $merged['concept_credits'] as $c ) {
                $n = strtolower( $c['name'] );
                if ( strpos( $n, 'homage' ) !== false ) {
                    $highlights[] = 'Homage Cover';
                }
                if ( strpos( $n, 'reprint' ) !== false ) {
                    $highlights[] = 'Reprint Issue';
                }
            }
        }

        if ( ! empty( $merged['reprint_info'] ) ) {
            $highlights[] = 'Contains Reprinted Material';
        }

        if ( ! empty( $merged['description'] ) ) {
            $d = strtolower( $merged['description'] );
            if ( strpos( $d, 'first appearance' ) !== false ) {
                $highlights[] = 'First Appearance Mentioned';
            }
            if ( strpos( $d, 'death of' ) !== false ) {
                $highlights[] = 'Mentions a Death';
            }
            if ( strpos( $d, 'second appearance' ) !== false ) {
                $highlights[] = 'Second Appearance';
            }
        }

        $merged['_highlights'] = array_unique( $highlights );

        // Use safe TTL
        set_transient( $cache_key, $merged, $this->get_dataset_ttl() );

        return $merged;
    }

    /* -----------------------------------------------------------------
     *  Helper – clean ComicVine description
     * ----------------------------------------------------------------- */
        public function clean_cv_description( $desc ) {

            if ( empty( $desc ) ) {
                return '';
            }

            $desc = str_replace(
                ['Ã€', 'Ã', 'Ã‚', 'Ãƒ', 'Ã„', 'Ã…', 'Ã†', 'Ã‡', 'Ãˆ', 'Ã‰', 'ÃŠ', 'Ã‹', 'ÃŒ', 'Ã', 'ÃŽ', 'Ã', 'Ã', 'Ã‘', 'Ã’', 'Ã“', 'Ã”', 'Ã•', 'Ã–', 'Ã—', 'Ã˜', 'Ã™', 'Ãš', 'Ã›', 'Ãœ', 'Ã', 'Ãž', 'ÃŸ',
                 'Ã ', 'Ã¡', 'Ã¢', 'Ã£', 'Ã¤', 'Ã¥', 'Ã¦', 'Ã§', 'Ã¨', 'Ã©', 'Ãª', 'Ã«', 'Ã¬', 'Ã­', 'Ã®', 'Ã¯', 'Ã°', 'Ã±', 'Ã²', 'Ã³', 'Ã´', 'Ãµ', 'Ã¶', 'Ã·', 'Ã¸', 'Ã¹', 'Ãº', 'Ã»', 'Ã¼', 'Ã½', 'Ã¾', 'Ã¿'],
                ['À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Æ', 'Ç', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï', 'Ð', 'Ñ', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', '×', 'Ø', 'Ù', 'Ú', 'Û', 'Ü', 'Ý', 'Þ', 'ß',
                 'à', 'á', 'â', 'ã', 'ä', 'å', 'æ', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ð', 'ñ', 'ò', 'ó', 'ô', 'õ', 'ö', '÷', 'ø', 'ù', 'ú', 'û', 'ü', 'ý', 'þ', 'ÿ'],
                $desc
            );
        
            /* -------------------------------------------------
             * 1. Encoding Normalization (NO GUESSING)
             * ------------------------------------------------- */
        
            // Remove Unicode replacement characters FIRST
            $desc = str_replace("\xEF\xBF\xBD", '', $desc);

            // Decode HTML entities
            $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Only convert if the string is not valid UTF-8
            if (!mb_check_encoding($desc, 'UTF-8')) {
                $desc = mb_convert_encoding($desc, 'UTF-8', 'Windows-1252');
            }

            // Normalize Unicode composition
            if (class_exists('Normalizer')) {
                $desc = Normalizer::normalize($desc, Normalizer::FORM_C);
            }

            // Remove control characters
            $desc = preg_replace('/[^\P{C}\n]+/u', '', $desc);
        
            /* -------------------------------------------------
             * 2. Your Existing Cleanup Logic
             * ------------------------------------------------- */
        

            // Remove FULL-BLOCK italics but keep inline italics intact
            $desc = preg_replace('/<p>\s*<(em|i)>(.*?)<\/\1>\s*<\/p>/is', '<p>$2</p>', $desc);

            // Remove root-level italics (no <p> wrapper)
            $desc = preg_replace('/^<(em|i)>(.*?)<\/\1>$/is', '$2', $desc);
        
            // Strip links but keep text
            $desc = preg_replace( '/<a\s+[^>]*>(.*?)<\/a>/is', '$1', $desc );
        
            // Clean <li> formatting
            $desc = preg_replace_callback( '/<li>(.*?)<\/li>/is', function ( $m ) {
        
                $item = $m[1];
        
                $item = preg_replace( '/^<b>\s*["\']\s*(<[^>]+>[^<]+<\/[^>]+>)\s*["\']\s*<\/b>/i', '<b>$1</b>', $item );
                $item = preg_replace( '/<b>\s*["\']\s*(<em>[^<]+<\/em>)\s*["\']\s*<\/b>/i', '<b>$1</b>', $item );
                $item = preg_replace( '/<b>\s*["\']([^<]+)["\']\s*<\/b>/i', '<b>$1</b>', $item );
                $item = preg_replace( '/(<\/(?:em|strong|b)>)["\']/', '$1', $item );
                $item = preg_replace( '/"(<(?:em|strong)[^>]*>.*?<\/(?:em|strong)>)"/i', '$1', $item );
        
                return '<li>' . $item . '</li>';
        
            }, $desc );
        
            $desc = preg_replace( '/"(<(?:em|strong)[^>]*>.*?<\/(?:em|strong)>)"/i', '$1', $desc );
            $desc = preg_replace( '/^["\']\s*|\s*["\']$/i', '', $desc );
        
        
            /* -------------------------------------------------
             * 3. Table Cleanup
             * ------------------------------------------------- */
        
            $desc = preg_replace_callback( '/<table.*?>.*?<\/table>/is', function ( $m ) {
        
                $dom = new DOMDocument();
                libxml_use_internal_errors( true );
                $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $m[0] );
        
                $xpath = new DOMXPath( $dom );
                $header_ths = $xpath->query( '//th' );
        
                $sidebar_idx = -1;
        
                foreach ( $header_ths as $i => $th ) {
                    if ( trim( $th->textContent ) === 'Sidebar Location' ) {
                        $sidebar_idx = $i;
                        $th->parentNode->removeChild( $th );
                        break;
                    }
                }
        
                if ( $sidebar_idx > -1 ) {
                    foreach ( $xpath->query( '//tr' ) as $row ) {
                        $tds = $row->getElementsByTagName( 'td' );
                        if ( $tds->length > $sidebar_idx ) {
                            $row->removeChild( $tds->item( $sidebar_idx ) );
                        }
                    }
                }
        
                $body = $dom->getElementsByTagName( 'body' )->item( 0 );
                $html = '';
        
                foreach ( $body->childNodes as $child ) {
                    $html .= $dom->saveHTML( $child );
                }
        
                return $html;
        
            }, $desc );
        
        
            /* -------------------------------------------------
             * 4. Heading Normalization
             * ------------------------------------------------- */
        
            $has_h2 = preg_match( '/<h2\b/i', $desc );
        
            $desc = preg_replace( '/<h6([^>]*)>/i', '<h5$1>', $desc );
            $desc = preg_replace( '/<\/h6>/i', '</h5>', $desc );
        
            $desc = preg_replace( '/<h5([^>]*)>/i', '<h4$1>', $desc );
            $desc = preg_replace( '/<\/h5>/i', '</h4>', $desc );
        
            $desc = preg_replace( '/<h4([^>]*)>/i', '<h3$1>', $desc );
            $desc = preg_replace( '/<\/h4>/i', '</h3>', $desc );
        
            if ( $has_h2 ) {
                $desc = preg_replace( '/<h3([^>]*)>/i', '<h4$1>', $desc );
                $desc = preg_replace( '/<\/h3>/i', '</h4>', $desc );
        
                $desc = preg_replace( '/<h2([^>]*)>/i', '<h3$1>', $desc );
                $desc = preg_replace( '/<\/h2>/i', '</h3>', $desc );
            }
        
            $desc = preg_replace( '/<h1([^>]*)>/i', '<h3$1>', $desc );
            $desc = preg_replace( '/<\/h1>/i', '</h3>', $desc );
        
            return $desc;
        }

    
    /**-----------------------------------------------------------------
     *  BATCH COMICVINE INFO (used in issue list)
     * ----------------------------------------------------------------- */
    public function build_cv_map_for_series( $series_id, $page = 1) {

        $cache_key = "metron:cv_map:series:{$series_id}";
    
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }
    
        $url  = $this->client->api_base . "series/{$series_id}/issue_list/?page={$page}&page_size=100";
        $data = $this->client->api_get( $url ); 
    
        if ( empty( $data['results'] ) ) {
            return [];
        }
    
        $map = [];
    
        foreach ( $data['results'] as $issue ) {
    
            $id = $issue['id'];
    
            // Try cache first
            $cached_cv = get_transient("metron:issue_cv_id:{$id}");
    
            if ( $cached_cv !== false ) {
                $map[$id] = $cached_cv;
                continue;
            }
    
            // ⚠️ Only fetch ONE per run (throttle hard)
            $response = $this->client->api_get(
                $this->client->api_base . "issue/{$id}/"
            );
    
            $cv_id = $response['cv_id'] ?? null;
    
            $map[$id] = $cv_id;
    
            set_transient(
                "metron:issue_cv_id:{$id}",
                $cv_id,
                $cv_id ? 30 * DAY_IN_SECONDS : 6 * HOUR_IN_SECONDS
            );
    
            // 🔥 STOP EARLY → prevents bursts
            break;
        }
    
        set_transient( $cache_key, $map, 7 * DAY_IN_SECONDS );
    
        return $map;
    }

    public function get_comicvine_issue_info_batch( $metron_ids ) {
        if ( empty( $metron_ids ) || ! is_array( $metron_ids ) ) {
            return [];
        }
    
        $results = [];
    
        foreach ( $metron_ids as $mid ) {
            $mid = (int) $mid;
            if ( $mid <= 0 ) continue;
    
            // Fast path: Check if we already have full CV data cached
            $cv_full_key = "cv_issue_full_{$mid}";
            $cached_cv = get_transient( $cv_full_key );
            if ( $cached_cv !== false ) {
                $results[$mid] = $cached_cv;
                continue;
            }
    
            // Get cv_id (now heavily cached)
            $cv_id = $this->get_metron_cv_id( $mid );
    
            if ( $cv_id ) {
                $merged = $this->get_comicvine_issue_info( $cv_id );
                if ( $merged ) {
                    $results[$mid] = $merged;
                }
            }
        }
    
        return $results;
    }
    
    /* -----------------------------------------------------------------
     *  METRON to COMIC VINE ID lookup
     * ----------------------------------------------------------------- */
    public function get_metron_cv_id( $metron_id ) {
        if ( empty( $metron_id ) || $metron_id <= 0 ) {
            return null;
        }
    
        $cache_key = 'metron:issue_vine:' . md5( (string) $metron_id );
    
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }
    
        // Only hit API if not cached
        $url = $this->client->api_base . "issue/{$metron_id}/";
        $data = $this->client->api_get( $url );
    
        $cv_id = $data['cv_id'] ?? null;
    
        if ( $cv_id ) {
            set_transient( $cache_key, $cv_id, 30 * DAY_IN_SECONDS );   // long cache
        } else {
            // Cache negative result for a shorter time to avoid hammering
            set_transient( $cache_key, null, 6 * HOUR_IN_SECONDS );
        }
    
        return $cv_id;
    }

    

}