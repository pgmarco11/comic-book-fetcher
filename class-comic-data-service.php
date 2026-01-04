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
        $transient_key = 'metron:publishers:full';
        $full          = $bypass_cache ? false : get_transient( $transient_key );

        if ( $full === false ) {
            $full = [];
            $url  = $this->client->api_base . 'publisher/?page=1';
            $data = $this->client->api_get( $url );

            if ( $data && ! empty( $data['results'] ) ) {
                foreach ( $data['results'] as $p ) {
                    $full[] = [ 'id' => $p['id'], 'name' => $p['name'] ];
                }
            }

            if ( ! empty( $data['next'] ) ) {
                $next = $this->client->api_get( $data['next'] );
                if ( $next && ! empty( $next['results'] ) ) {
                    foreach ( $next['results'] as $p ) {
                        $full[] = [ 'id' => $p['id'], 'name' => $p['name'] ];
                    }
                }
            }

            set_transient( $transient_key, $full, WEEK_IN_SECONDS );
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
        $start = microtime( true );

        $title_key = "metron:issue_list:{$title_id}";
        $issues_data = get_transient( $title_key );        
   
        if ( $issues_data === false ) {

            $all = [];

            do {   

                $url_issues = $this->client->api_base . "series/{$title_id}/issue_list/";
                $response = $this->client->api_get($url_issues);

                if (isset($response['error'])) {
                    error_log("Metron error page {$page}: " . $response['error']);
                    break;
                }
            
                $results = $response['results'] ?? [];
                if (empty($results)) break;
            
                $all = array_merge($all, $results);
                error_log("Series {$title_id} page {$page}: " . count($results) . " issues (next: " . ($response['next'] ?? 'none') . ")");
            
                $page++;
                if ($page > 50) break;  // safety
                usleep(500000);  // ← add delay to avoid rate limit
            } while (!empty($response['next']));
            
            usort($all, fn($a, $b) => floatval($a['number'] ?? 0) <=> floatval($b['number'] ?? 0));

            $issues_key = "metron:issue_list:{$title_id}";   
            $issues_data = [ 'count' => count( $all ), 'results' => $all ];

            set_transient( $issues_key, $issues_data, 2 * WEEK_IN_SECONDS );
        }

        $url_title    = $this->client->api_base . "series/{$title_id}/";   
        $series = $this->client->api_get( $url_title );    

        if ( is_array( $series ) && isset( $series['error'] ) ) {
            error_log( "get_series_issues: series error $title_id – " . $series['error'] );
            return [ 'error' => $series['error'] ];
        }

        if ( $series && ! empty( $series['name'] ) ) {
            set_transient( $title_key, $series, 2 * WEEK_IN_SECONDS );
        } else {
            return [ 'error' => 'Series not found' ];
        }

        $filtered = $search
            ? array_filter(
                $issues_data['results'],
                function ( $i ) use ( $search ) {
                    $search = strtolower( $search );
                    return strpos( strtolower( $i['issue'] ?? '' ), $search ) !== false
                        || strpos( strtolower( $i['number'] ?? '' ), $search ) !== false
                        || strpos( strtolower( $i['cover_date'] ?? '' ), $search ) !== false;
               }
            )
            : $issues_data['results'];

        $filtered = array_values( $filtered );
        $total    = count( $filtered );
        $per_page = 10;
        $start    = ( $current_page - 1 ) * $per_page;
        $paged    = array_slice( $filtered, $start, $per_page );

        $elapsed = microtime(true) - $start;
        error_log( sprintf("get_series_issues: %d – %.4f s (fetched %d issues)", $title_id, $elapsed, count($all_issues ?? [])) );

        return [
            'series'      => $series,
            'issue_list'  => [
                'count'   => $total,
                'results' => $paged,
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
        $cache_key = "metron:issue_list:{$title_id}_{$issue_id}"; 

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
        $prefix       = 'metron:issue_list:';
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
    / *  BATCH COMICVINE INFO (used in issue list)
    /* ----------------------------------------------------------------- */
    public function get_comicvine_issue_info_batch( $metron_ids ) {
        if ( empty( $metron_ids ) || ! is_array( $metron_ids ) ) {
            return [];
        }

        $results = [];
        foreach ( $metron_ids as $mid ) {
            $cv_id = $this->get_metron_cv_id( $mid );
            if ( $cv_id ) {
                $info = $this->get_comicvine_issue_info( $cv_id );
                if ( $info ) {
                    $results[ $mid ] = $info;
                }
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

        $cache_key = 'metron:issue_list:' . md5( $metron_id );
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