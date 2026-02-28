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
    
    /* -----------------------------------------------------------------
     *  SAFE TTL GETTER (fixes undefined property)
     * ----------------------------------------------------------------- */
    private function get_dataset_ttl() {
        return isset( $this->client->dataset_ttl )
            ? (int) $this->client->dataset_ttl
            : self::DEFAULT_DATASET_TTL;
    }

    /* -----------------------------------------------------------------
     *  PUBLISHERS – full list (cached once a week)
     * ----------------------------------------------------------------- */
    public function get_publishers(
        $name        = '',
        $page        = 1,
        $per_page    = 50,
        $bypass_cache = false,
        $letter      = 'all'
    ) {
        $transient_key = 'metron:publishers:full:v1'; // versioned

        $full = $bypass_cache ? false : get_transient($transient_key);
    
        if ($full === false) {
            $full = [];
            $api_page = 1;
            do {
                $url = $this->client->api_base . "publisher/?page={$api_page}&page_size=100";
                $data = $this->client->api_get($url);
                if (!$data || empty($data['results'])) break;
    
                foreach ($data['results'] as $p) {
                    $full[] = ['id' => $p['id'], 'name' => $p['name']];
                }
                $api_page++;
                usleep(50000);
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

        return [ 'items' => $slice, 'total' => $total ];
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

    /* -----------------------------------------------------------------
     *  SERIES LIST for a publisher
     * ----------------------------------------------------------------- */
    public function get_series(
        $publisher_id,
        $page      = 1,
        $per_page  = 50,
        $search    = '',
        $letter    = 'all',
        $force_api = false
    ) {
        $cache_key = "metron:series_full:$publisher_id";
        $full      = get_transient( $cache_key );

        if ( ! $force_api && $full === false ) {
            return [
                'items'    => [],
                'total'    => 0,
                'page'     => $page,
                'per_page' => $per_page,
            ];
        }

        if ( $force_api || $full === false ) {
            $full     = [];
            $api_page = 1;

            do {
                $url      = $this->client->api_base . "publisher/$publisher_id/series_list/?page=$api_page";
                $response = $this->client->api_get( $url );

                if ( ! $response || empty( $response['results'] ) ) {
                    break;
                }

                foreach ( $response['results'] as $item ) {
                    $full[] = [
                        'series_id'        => $item['id'],
                        'name'             => $item['series'],
                        'volume'           => $item['volume'] ?? '1',
                        'issue_count'      => $item['issue_count'] ?? 0,
                        'year_began'       => $item['year_began'] ?? 'N/A',
                        'first_issue_image'=> $item['first_issue_image'] ?? '',
                    ];
                }

                $api_page++;
            } while ( ! empty( $response['next'] ) && $api_page <= 100 );

            set_transient( $cache_key, $full, 30 * DAY_IN_SECONDS );
        }

        $filtered = $full;

        if ( $search ) {
            $search = strtolower( trim( $search ) );
            $filtered = array_filter(
                $filtered,
                fn( $s ) => strpos( strtolower( $s['name'] ), $search ) !== false
            );
        }

        if ( $letter !== 'all' ) {
            $filtered = array_filter(
                $filtered,
                function ( $s ) use ( $letter ) {
                    $first = strtoupper( substr( $s['name'], 0, 1 ) );
                    return $letter === '#' ? ! ctype_alpha( $first ) : $first === strtoupper( $letter );
                }
            );
        }

        $filtered = array_values( $filtered );
        $total    = count( $filtered );
        $offset   = ( $page - 1 ) * $per_page;
        $paged    = array_slice( $filtered, $offset, $per_page );

        return [
            'items'    => $paged,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /* -----------------------------------------------------------------
     *  ISSUES for a series
     * ----------------------------------------------------------------- */
    public function get_series_issues( $title_id, $current_page, $search = '' ) {
        $timer_start = microtime(true);
        $per_page = 10;
    
        $full_list_key = "metron:issue_list_full:{$title_id}:v2";
    
        $all_issues_data = get_transient($full_list_key);
    
        if ($all_issues_data && count($all_issues_data['results'] ?? []) < 300 && $search === '') {
            delete_transient($full_list_key);
            $all_issues_data = false;
            error_log("Low issue count (<300) detected for series {$title_id} — forcing refetch");
        }
    
        if (false === $all_issues_data || !isset($all_issues_data['results'])) {
            $all = [];
            $page_fetch = 1;
            $next_url = null;
    
            do {
                $url = $this->client->api_base . "series/{$title_id}/issue_list/?page={$page_fetch}&page_size=100";
                $response = $this->client->api_get($url);
    
                if (isset($response['error'])) {
                    error_log("Metron API error on series {$title_id} page {$page_fetch}: " . json_encode($response['error']));
                    break;
                }
    
                if (empty($response['results'])) {
                    error_log("Empty results on series {$title_id} page {$page_fetch} — stopping. Next: " . ($response['next'] ?? 'null'));
                    break;
                }
    
                $all = array_merge($all, $response['results']);
                $next_url = $response['next'] ?? null;
                $page_fetch++;
                usleep(50000);
            } while ($next_url);
    
            // FIXED SORTING
            usort($all, function($a, $b) {
                $numA = isset($a['number']) ? trim((string)($a['number'] ?? '0')) : '0';
                $numB = isset($b['number']) ? trim((string)($b['number'] ?? '0')) : '0';
    
                $valueA = is_numeric($numA) ? (float)$numA : INF;
                $valueB = is_numeric($numB) ? (float)$numB : INF;
    
                if ($valueA !== $valueB) {
                    return $valueA <=> $valueB;
                }
    
                $idA = (int) ($a['id'] ?? 0);
                $idB = (int) ($b['id'] ?? 0);
                return $idA <=> $idB;
            });
    
            $fetched_count = count($all);
            error_log("Fetched {$fetched_count} issues for series {$title_id} (full list refresh)");    
    
            $all_issues_data = [
                'count'   => $fetched_count,
                'results' => $all,
            ];
    
            set_transient($full_list_key, $all_issues_data, 30 * DAY_IN_SECONDS);
        }
        /* -------------------------------------------------
         * Series metadata
         * ------------------------------------------------- */
        $series_key = "metron:series:{$title_id}";
        $series = get_transient($series_key);
    
        if ( false === $series ) {
            $url_title = $this->client->api_base . "series/{$title_id}/";
            $series = $this->client->api_get($url_title);
            if ( isset($series['error']) || empty($series['name']) ) {
                return [ 'error' => $series['error'] ?? 'Series not found' ];
            }
            set_transient($series_key, $series, 2 * WEEK_IN_SECONDS);
        }
    
        /* -------------------------------------------------
         * Search filter
         * ------------------------------------------------- */
        $filtered = $search
        ? array_filter($all_issues_data['results'], function($i) use($search) {
            $s = trim(strtolower($search));
            $number = isset($i['number']) ? trim(strtolower($i['number'])) : '';
            
            if ($number !== '') {
                if ($number === $s) return true;
                if (stripos($number, $s) === 0) return true;
                if (stripos($number, $s . '.') === 0 || 
                    stripos($number, $s . ' ') === 0 || 
                    stripos($number, $s . '-') === 0 || 
                    stripos($number, $s . '/') === 0) {
                    return true;
                }
            }
            
            $s_lower = strtolower($s);
            return stripos(strtolower($i['issue'] ?? ''), $s_lower) !== false
                || stripos($i['cover_date'] ?? '', $s_lower) !== false;
        })
        : $all_issues_data['results'];

        $filtered = array_values($filtered);
        $filtered_count = count($filtered);

        /* -------------------------------------------------
        * Reliable pagination total – prefer the larger number
        * ------------------------------------------------- */
        $series_reported_count = (int) ($series['issue_count'] ?? 0);
        $actually_fetched_count = count($all_issues_data['results']);

        $total_issues_for_pagination = max($series_reported_count, $actually_fetched_count);

        // Extra safety: when no search active, never trust reported count if it's lower
        if ($search === '' && $actually_fetched_count > $series_reported_count) {
            $total_issues_for_pagination = $actually_fetched_count;
        }

        // Log discrepancies >30 issues (helps identify problematic series)
        if (abs($series_reported_count - $actually_fetched_count) > 30) {
            error_log(sprintf(
                "Series %d (%s) pagination mismatch – reported: %d, fetched: %d → using %d",
                $title_id,
                $series['name'] ?? 'unknown',
                $series_reported_count,
                $actually_fetched_count,
                $total_issues_for_pagination
            ));
        }

        /* -------------------------------------------------
        * Pagination slice
        * ------------------------------------------------- */
        $current_page = max(1, (int) $current_page);
        $offset = ($current_page - 1) * $per_page;
        $paged_results = array_slice($filtered, $offset, $per_page);

        $elapsed = microtime(true) - $timer_start;   
    
        // Final count & pagination logic
        $display_count = $filtered_count;

        if ($search === '') {
            // When no filter: prefer the larger number to avoid truncating real data
            $display_count = max($series_reported_count, $actually_fetched_count);
        }

        return [
            'series'     => $series,
            'issue_list' => [
                'count'   => $display_count,
                'results' => $paged_results,
            ],
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
     *  BATCH SERIES IMAGES (first issue image)
     * ----------------------------------------------------------------- */
    public function get_series_images( array $series_ids ) {
        $images       = [];
        $prefix       = 'metron:issue_list_full:';
        $missing      = [];

        foreach ( $series_ids as $id ) {
            $key = $prefix . $id;
            $cached = get_transient( $key );
            if ( $cached !== false && ! empty( $cached['results'][0]['image'] ) ) {
                $images[ $id ] = $cached['results'][0]['image'];
            } else {
                $missing[] = $id;
            }
        }

        foreach ( $missing as $id ) {
            $url  = $this->client->api_base . "series/$id/issue_list/?per_page=1";
            $data = $this->client->api_get( $url );

            $img = $data['results'][0]['image'] ?? PUBLISHER_PLACEHOLDER_IMAGE_URL;
            $images[ $id ] = $img;

            // Use safe TTL
            set_transient( $prefix . $id, $data, $this->get_dataset_ttl() * 2 );
        }

        return $images;
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

            // Fix mixed encodings
            if (function_exists('mb_convert_encoding')) {
                $desc = mb_convert_encoding($desc, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            } elseif (function_exists('iconv')) {
                $desc = iconv('UTF-8', 'UTF-8//IGNORE', $desc);
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
        
            // Remove italic wrapper paragraphs
            $desc = preg_replace( '/<p>\s*<em>(.*?)<\/em>\s*<\/p>/is', '<p>$1</p>', $desc );
            $desc = preg_replace( '/^<em>(.*?)<\/em>$/is', '$1', $desc );
        
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

    
    /* -----------------------------------------------------------------
    / *  BATCH COMICVINE INFO (used in issue list)
    /* ----------------------------------------------------------------- */
    public function get_comicvine_issue_info_batch( $metron_ids ) {
        if ( empty( $metron_ids ) || ! is_array( $metron_ids ) ) {
            return [];
        }
    
        $results = [];
        $cv_key = get_option( 'comic_vine_api_key', '' );
        if ( ! $cv_key ) {
            error_log( 'Comic Vine API key missing' );
            return [];
        }
    
        // Step 1: Batch fetch CV IDs from Metron (single request with multiple IDs)
        $cv_ids_map = []; // metron_id => cv_id
        foreach ( $metron_ids as $mid ) {
            $cache_key = 'metron:issue_vine:' . md5( $mid );
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) {
                $cv_ids_map[ $mid ] = $cached;
            }
        }
    
        // Only fetch uncached IDs
        $uncached_ids = array_diff( $metron_ids, array_keys( $cv_ids_map ) );
        if ( ! empty( $uncached_ids ) ) {
            // Fetch all uncached IDs at once
            foreach ( $uncached_ids as $mid ) {
                $url = $this->client->api_base . "issue/$mid/";
                $data = $this->client->api_get( $url );
                $cv_id = $data['cv_id'] ?? null;
                
                if ( $cv_id ) {
                    $cv_ids_map[ $mid ] = $cv_id;
                    set_transient( 'metron:issue_vine:' . md5( $mid ), $cv_id, WEEK_IN_SECONDS );
                }
            }
        }
    
        // Step 2: Batch fetch Comic Vine data for all CV IDs
        if ( ! empty( $cv_ids_map ) ) {
            foreach ( $cv_ids_map as $mid => $cv_id ) {
                $cache_key = "cv_issue_full_$cv_id";
                $cached = get_transient( $cache_key );
                
                if ( $cached !== false ) {
                    $results[ $mid ] = $cached;
                    continue;
                }
    
                // Fetch from Comic Vine
                $url = "https://comicvine.gamespot.com/api/issue/4000-{$cv_id}/?api_key={$cv_key}&format=json";
                $res = wp_remote_get( $url, [
                    'timeout' => 30,
                    'headers' => [ 'User-Agent' => 'CollectibleSpotBot/1.1 (+' . get_site_url() . ')' ],
                ] );
    
                if ( is_wp_error( $res ) ) {
                    continue;
                }
    
                $body = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( empty( $body['results'] ) ) {
                    continue;
                }
    
                $merged = $body['results'];
    
                // Enrich with Metron data
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
    
                // Generate highlights
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
                set_transient( $cache_key, $merged, $this->get_dataset_ttl() );
                
                $results[ $mid ] = $merged;
            }
        }
    
        return $results;
    }

    /* -----------------------------------------------------------------
     *  METRON to COMIC VINE ID lookup
     * ----------------------------------------------------------------- */
    public function get_metron_cv_id( $metron_id ) {
        if ( ! $metron_id ) {
            return null;
        }

        $cache_key = 'metron:issue_vine:' . md5( $metron_id );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $url  = $this->client->api_base . "issue/$metron_id/";
        $data = $this->client->api_get( $url );

        $cv_id = $data['cv_id'] ?? null;
       
        if ( $cv_id ) {
            set_transient( $cache_key, $cv_id, WEEK_IN_SECONDS );
        }

        return $cv_id;
    }

    

}