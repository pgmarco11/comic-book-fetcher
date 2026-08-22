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
                    $full[] = [ 'id' => $p['id'], 'name' => $p['name'] ];
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

            if ( empty( $info['founded'] ) ) {
                $info['founded'] = 'Unknown';
            }
        
            if ( empty( $info['desc'] ) ) {
                $info['desc'] = 'No description available.';
            }   

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

        // -----------------------------
        // METRON PRIMARY SOURCE
        // -----------------------------
        $url  = $this->client->api_base . "publisher/$publisher_id/";
        $data = $this->client->api_get( $url ); 

        if ( ! is_array( $data ) || empty( $data['name'] ) ) {
            return [];
        }

        $info = [
            'id'      => $data['id'] ?? $publisher_id,
            'name'    => $data['name'] ?? '',
            'image'   => $data['image'] ?? '',
            'desc'    => $data['desc'] ?? '',
            'founded' => $data['founded'] ?? '',
            'cv_id'   => $data['cv_id'] ?? '',
        ];

        // -----------------------------
        // COMIC VINE FALLBACK
        // Only if important fields missing
        // -----------------------------
        $needs_fallback =
            empty( $info['image'] ) ||
            empty( $info['desc'] ) ||
            empty( $info['founded'] );

            if ( $needs_fallback && ! empty( $info['cv_id'] ) ) {

                $cv_data = $this->get_comicvine_publisher_info( $info['cv_id'] );
            
                if ( ! empty( $cv_data ) ) {
                    if ( empty( $info['image'] ) && ! empty( $cv_data['image'] ) ) {
                        $info['image'] = $cv_data['image'];
                    }
            
                    if ( empty( $info['desc'] ) && ! empty( $cv_data['desc'] ) ) {
                        $info['desc'] = $cv_data['desc'];
                    }
            
                    if ( empty( $info['founded'] ) && ! empty( $cv_data['founded'] ) ) {
                        $info['founded'] = $cv_data['founded'];
                    }
                }
            }

        // Final image fallback
        $info['image'] = ! empty( $info['image'] )
            ? $info['image']
            : PUBLISHER_PLACEHOLDER_IMAGE_URL;

        if ( empty( $info['founded'] ) ) {
            $info['founded'] = 'Unknown';
        }
            
        if ( empty( $info['desc'] ) ) {
            $info['desc'] = 'No description available.';
        }

        set_transient( $key, $info, WEEK_IN_SECONDS );

        return $info;
    }

    /**
     * Shared Comic Vine GET request — handles key retrieval, headers,
     * and JSON decoding once instead of in every CV method.
     */
    private function cv_api_get( string $endpoint, array $query_args = [] ): ?array {
        $cv_key = get_option( 'comic_vine_api_key', '' );

        if ( empty( $cv_key ) ) {
            error_log( 'Comic Vine API key missing' );
            return null;
        }

        $url = add_query_arg(
            array_merge( [ 'api_key' => $cv_key, 'format' => 'json' ], $query_args ),
            $endpoint
        );

        $res = wp_remote_get(
            $url,
            [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'ComicBookFetcher/1.1 (+' . get_site_url() . ')',
                ],
            ]
        );

        if ( is_wp_error( $res ) ) {
            error_log( 'Comic Vine request failed (' . $endpoint . '): ' . $res->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            error_log( 'Comic Vine JSON decode failed (' . $endpoint . '): ' . json_last_error_msg() );
            return null;
        }

        return $body;
    }

        /**
     * Given [series_id => cv_volume_id], return [series_id => image_url]
     * in a single ComicVine request.
     */
    public function get_comicvine_first_issues_batch( array $series_to_cv_id ) {
    
        $volume_ids = array_filter( array_values( $series_to_cv_id ) );
        if ( empty( $volume_ids ) ) {
            error_log('CV BATCH: no volume ids after filtering — series_to_cv_id had no usable cv_id values, aborting');
            return [];
        }      
    
        $cv_key = get_option( 'comic_vine_api_key', '' );
        if ( empty( $cv_key ) ) {
            error_log( 'CV BATCH: Comic Vine API key missing' );
            return [];
        }
    
        $filter = 'volume:' . implode( '|', array_map( 'absint', $volume_ids ) ) . ',issue_number:1';
        $url    = add_query_arg(
            [
                'api_key'    => $cv_key,
                'format'     => 'json',
                'field_list' => 'volume,image,issue_number',
                'filter'     => $filter,
                'limit'      => 100,
            ],
            'https://comicvine.gamespot.com/api/issues/'        );
    
    
        $res = wp_remote_get(
            $url,
            [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'ComicBookFetcher/1.1 (+' . get_site_url() . ')',
                ],
            ]
        );
    
        if ( is_wp_error( $res ) ) {
            error_log( 'CV BATCH: wp_remote_get failed: ' . $res->get_error_message() );
            return [];
        }
    
        $status = wp_remote_retrieve_response_code( $res );
        $raw    = wp_remote_retrieve_body( $res );    
        $body = json_decode( $raw, true );
    
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            error_log('CV BATCH: JSON decode failed: ' . json_last_error_msg());
            return [];
        }
    
        if ( empty( $body['results'] ) ) {
            error_log( 'CV BATCH: no results in body' );
            return [];
        }
    
        $by_volume = [];
        foreach ( $body['results'] as $issue ) {
            $vol_id = $issue['volume']['id'] ?? null;
            $img_check = $issue['image'] ?? null;
            error_log("CV BATCH: result issue_id={$issue['id']} volume_id=" . ($vol_id ?? 'NULL') . " issue_number=" . ($issue['issue_number'] ?? '?') . " has_image=" . (!empty($img_check) ? 'yes' : 'no'));
    
            if ( $vol_id ) {
                $by_volume[ $vol_id ] = $issue['image']['small_url']
                    ?? $issue['image']['medium_url']
                    ?? $issue['image']['original_url']
                    ?? '';
            }
        }  

    
        $images = [];
        foreach ( $series_to_cv_id as $sid => $cv_id ) {
            $images[ $sid ] = $by_volume[ $cv_id ] ?? '';
            if (empty($images[$sid])) {
                error_log("CV BATCH: series $sid (cv_id=$cv_id) got NO image from by_volume map");
            }
        }
    
        return $images;
    }

    /**
     * Get Comic Vine volume IDs for Metron series IDs.
     *
     * Uses the per-series cv_id cache first. If missing, attempts to recover
     * the cv_id from cached series metadata, then falls back to the Metron
     * series endpoint and populates the cache.
    */
    public function get_known_cv_ids( array $series_ids ) : array {

        $map = [];

            foreach ( $series_ids as $sid ) {

                $sid = absint( $sid );

                if ( ! $sid ) {
                    continue;
                }

                $cv_cache_key   = "metron:series_cvid:{$sid}";
                $miss_cache_key = "metron:series_cvid_missing:{$sid}";
                $series_key     = "metron:series:{$sid}";

                /*
                * 1. Check dedicated CV ID cache.
                */
                $cached = get_transient( $cv_cache_key );

                if ( $cached !== false ) {
                    $map[ $sid ] = (int) $cached;

                    error_log(
                        "get_known_cv_ids: series {$sid} cv_id {$map[$sid]} from CV cache"
                    );

                    continue;
                }

                /*
                * Don't repeatedly hit Metron if we recently confirmed
                * that this series has no cv_id.
                */
                if ( get_transient( $miss_cache_key ) !== false ) {

                    $map[ $sid ] = null;

                    error_log(
                        "get_known_cv_ids: series {$sid} recently confirmed to have no cv_id"
                    );

                    continue;
                }

                /*
                * 2. Check cached series metadata.
                */
                $series = get_transient( $series_key );

                if (
                    is_array( $series ) &&
                    ! empty( $series['cv_id'] )
                ) {

                    $cv_id = (int) $series['cv_id'];

                    set_transient(
                        $cv_cache_key,
                        $cv_id,
                        YEAR_IN_SECONDS
                    );

                    $map[ $sid ] = $cv_id;

                    error_log(
                        "get_known_cv_ids: series {$sid} cv_id {$cv_id} recovered from series cache"
                    );

                    continue;
                }

                /*
                * 3. Nothing cached — fetch the series directly from Metron.
                */
                error_log(
                    "get_known_cv_ids: series {$sid} cv_id not cached — fetching from Metron"
                );

                $url = $this->client->api_base . "series/{$sid}/";
                $series = $this->client->api_get( $url );

                /*
                * Keep the complete series metadata too, since other methods
                * already use this transient.
                */
                if ( is_array( $series ) && ! empty( $series['name'] ) ) {
                    set_transient(
                        $series_key,
                        $series,
                        14 * DAY_IN_SECONDS
                    );
                }

                /*
                * 4. Found a CV ID — cache it.
                */
                if ( ! empty( $series['cv_id'] ) ) {

                    $cv_id = (int) $series['cv_id'];

                    set_transient(
                        $cv_cache_key,
                        $cv_id,
                        YEAR_IN_SECONDS
                    );

                    $map[ $sid ] = $cv_id;

                    error_log(
                        "get_known_cv_ids: series {$sid} fetched cv_id {$cv_id} from Metron and cached it"
                    );

                } else {

                    /*
                    * 5. Metron genuinely has no CV ID.
                    *
                    * Cache that fact briefly so AJAX requests don't repeatedly
                    * query the same series.
                    */
                    set_transient(
                        $miss_cache_key,
                        1,
                        6 * HOUR_IN_SECONDS
                    );

                    $map[ $sid ] = null;

                    error_log(
                        "get_known_cv_ids: series {$sid} has no cv_id in Metron"
                    );
                }
            }

            return $map;
        
    }

     /**
     * Build Comic Vine data for Metron issues.
     */
    public function get_cv_info_batch(array $issues): array
    {
        $cv_info_batch = [];

        foreach ($issues as $issue) {

            $mid = (int) ($issue['id'] ?? 0);

            if (!$mid) {
                continue;
            }

            $cv_id = null;

            /*
            * 1. Check cached Metron → Comic Vine mapping.
            */
            $cached = get_transient("metron:issue_cv_id:{$mid}");

            if ($cached !== false) {

                $cv_id = is_array($cached)
                    ? ($cached['cv_id'] ?? null)
                    : ($cached ?: null);

            /*
            * 2. Metron issue response already contains CV ID.
            */
            } elseif (!empty($issue['cv_id'])) {

                $cv_id = (int) $issue['cv_id'];

                set_transient(
                    "metron:issue_cv_id:{$mid}",
                    ['cv_id' => $cv_id],
                    30 * DAY_IN_SECONDS
                );

            /*
            * 3. IMPORTANT:
            * Resolve CV ID from Metron when issue_list
            * doesn't contain it.
            */
            } else {

                $cv_id = $this->get_metron_cv_id($mid);

                if ($cv_id) {

                    $cv_id = (int) $cv_id;

                    set_transient(
                        "metron:issue_cv_id:{$mid}",
                        ['cv_id' => $cv_id],
                        30 * DAY_IN_SECONDS
                    );
                }
            }

            /*
            * Get Comic Vine cover.
            */
            $comic_vine_image = '';

            if ($cv_id) {
                $comic_vine_image =
                    $this->get_comicvine_issue_image($cv_id);
            }

            $cv_info_batch[$mid] = [
                'cv_id'             => $cv_id ?: null,
                'comic_vine_image'  => $comic_vine_image ?: '',
                'metron_image'      => $issue['image'] ?? '',
            ];

            // Temporary debugging
            error_log(
                "ISSUE {$mid}: CV ID = " .
                ($cv_id ?: 'NONE') .
                " | CV IMAGE = " .
                ($comic_vine_image ?: 'NONE')
            );
        }

        return $cv_info_batch;
    }

    /**
     * Last-resort per-series Metron fallback, only used when a series
     * has no known cv_id at all.
     */
    public function get_series_first_issue_image( int $series_id ): string {
        $url  = $this->client->api_base . "series/{$series_id}/issue_list/?page=1&page_size=1";
        $data = $this->client->api_get( $url );
        return $data['results'][0]['image'] ?? '';
    }

    private function get_filtered_series_lazy(
        int $publisher_id, int $page, int $per_page,
        string $search, string $letter, bool $force_api
    ): array {
        $needed_count = $page * $per_page;
        $max_pages_per_call = 1;
    
        $progress_key = "metron:series_scan_progress:v1:{$publisher_id}";
        $progress = $force_api ? false : get_transient( $progress_key );
    
        if ( ! is_array( $progress ) ) {
            $progress = [
                'next_api_page' => 1,
                'exhausted'     => false,
                'raw_items'     => [],
            ];
        }
    
        $filtered = $this->filter_series_list( $progress['raw_items'], $letter, $search );
    
        // Only attempt to fetch more if we actually need to AND can get the lock.
        // If another request already holds the lock, just return what we have —
        // the caller (AJAX handler / template) treats scan_complete=false as
        // "poll again shortly," so this degrades gracefully instead of racing.
        if ( count( $filtered ) < $needed_count && ! $progress['exhausted'] ) {
    
            if ( $this->acquire_scan_lock( $publisher_id ) ) {
    
                try {
                    $pages_fetched_this_call = 0;
    
                    while (
                        count( $filtered ) < $needed_count &&
                        ! $progress['exhausted'] &&
                        $pages_fetched_this_call < $max_pages_per_call
                    ) {
                        $page_data = $this->get_series_api_page(
                            $publisher_id, $progress['next_api_page'], 100, $force_api
                        );
                        $pages_fetched_this_call++;
    
                        if ( empty( $page_data['items'] ) ) {
                            $progress['exhausted'] = true;
                            break;
                        }
    
                        $progress['raw_items'] = array_merge( $progress['raw_items'], $page_data['items'] );
                        $progress['next_api_page']++;
    
                        if ( empty( $page_data['has_next'] ) ) {
                            $progress['exhausted'] = true;
                        }
    
                        $filtered = $this->filter_series_list( $progress['raw_items'], $letter, $search );
                    }
    
                    set_transient( $progress_key, $progress, DAY_IN_SECONDS );
    
                } finally {
                    $this->release_scan_lock( $publisher_id );
                }
    
            }
            // else: lock held elsewhere — fall through and return current progress as-is.
        }
    
        $have_enough = count( $filtered ) >= $needed_count || $progress['exhausted'];
        $total_known = count( $filtered );
        $offset      = ( $page - 1 ) * $per_page;
    
        return [
            'items'          => array_slice( $filtered, $offset, $per_page ),
            'total'          => $total_known,
            'is_total_exact' => $progress['exhausted'],
            'scan_complete'  => $have_enough,
            'page'           => $page,
            'per_page'       => $per_page,
        ];
    }

    private function acquire_scan_lock( int $publisher_id ): bool {
        $lock_key = "metron:series_scan_lock:{$publisher_id}";
        if ( get_transient( $lock_key ) ) {
            return false; // another request is already scanning this publisher
        }
        set_transient( $lock_key, 1, 20 ); // short TTL — auto-releases if a worker dies mid-scan
        return true;
    }
    
    private function release_scan_lock( int $publisher_id ): void {
        delete_transient( "metron:series_scan_lock:{$publisher_id}" );
    }

    /** -----------------------------------------------------------------
     * SERIES LIST for a publisher — fixed 5-page block mode.
     *
     * Normal behavior with your current setup:
     * - Catalog page 1–50 uses Metron API pages 1–5.
     * - Catalog page 51–100 uses Metron API pages 6–10.
     *
     * Why? Your catalog shows 10 items per page, while Metron API returns
     * 100 items per page.
     * ----------------------------------------------------------------- */
    public function get_series(
        $publisher_id, $page = 1, $per_page = 10,
        $search = '', $letter = 'all', $force_api = false, $batch_size = null
    ) {
        $publisher_id = (int) $publisher_id;
        $page         = max( 1, (int) $page );
        $per_page     = max( 1, (int) $per_page );
        $is_filtered  = ! empty( $search ) || $letter !== 'all';

        if ( $is_filtered ) {
            return $this->get_filtered_series_lazy( $publisher_id, $page, $per_page, $search, $letter, $force_api );
        }

        $api_page_size = 100;
        $block_size    = 1;

        /*
        * IMPORTANT:
        * This maps your visible catalog page to the Metron API page.
        *
        * Because catalog page = 10 items
        * and Metron page = 100 items:
        *
        * catalog pages 1–10  need Metron page 1
        * catalog pages 11–20 need Metron page 2
        * catalog pages 41–50 need Metron page 5
        * catalog pages 51–60 need Metron page 6
        */
        $absolute_offset = ( $page - 1 ) * $per_page;
        $needed_api_page = (int) floor( $absolute_offset / $api_page_size ) + 1;

        /*
        * Fetch API pages in blocks:
        * needed 1–5  => fetch 1–5
        * needed 6–10 => fetch 6–10
        * needed 11–15 => fetch 11–15
        */
        $block_start = ( (int) floor( ( $needed_api_page - 1 ) / $block_size ) * $block_size ) + 1;
        $block_end   = $block_start + $block_size - 1;

        error_log(
            "get_series BLOCK MODE v4: publisher={$publisher_id}, catalog_page={$page}, needed_api_page={$needed_api_page}, block={$block_start}-{$block_end}"
        );

        $block_items = [];
        $api_total   = 0;
        $api_has_next = false;

        for ( $api_page = $block_start; $api_page <= $block_end; $api_page++ ) {
            $page_data = $this->get_series_api_page(
                $publisher_id,
                $api_page,
                $api_page_size,
                $force_api
            );

            if ( empty( $page_data['items'] ) ) {
                break;
            }

            $block_items = array_merge( $block_items, $page_data['items'] );

            if ( ! empty( $page_data['total'] ) ) {
                $api_total = (int) $page_data['total'];
            }

            if ( ! empty( $page_data['has_next'] ) ) {
                $api_has_next = true;
            }
        }

        $filtered = $this->filter_series_list( $block_items, $letter, $search );

        /*
        * For normal unfiltered "all" browsing, use Metron's real total.
        * For letter/search filters, we can only know the filtered total
        * inside the loaded block unless you fetch every API page.
        */
        $is_filtered = ! empty( $search ) || $letter !== 'all';

        $total = $is_filtered
            ? count( $filtered )
            : max( $api_total, count( $filtered ) );

        /*
        * Slice within the current 5-page API block.
        */
        if ( ! $is_filtered ) {
            $block_absolute_start = ( $block_start - 1 ) * $api_page_size;
            $offset_in_block      = max( 0, $absolute_offset - $block_absolute_start );
        } else {
            $offset_in_block = 0;
        }

        $paged_items = array_slice( $filtered, $offset_in_block, $per_page );

        return [
            'items'    => $paged_items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }


    /**
     * Fetch one exact Metron series_list API page.
     * No rolling "last page" state.
     */
    private function get_series_api_page(
        int $publisher_id,
        int $api_page,
        int $api_page_size = 100,
        bool $force_api = false
    ): array {
        $cache_key = "metron:series_api_page:v4:{$publisher_id}:{$api_page}:{$api_page_size}";

        if ( ! $force_api ) {
            $cached = get_transient( $cache_key );

            if ( $cached !== false && is_array( $cached ) ) {

                // Re-populate per-series cv_id cache from the cached page data.
                foreach ( $cached['items'] ?? [] as $item ) {
            
                    if (
                        ! empty( $item['series_id'] ) &&
                        ! empty( $item['cv_id'] )
                    ) {
                        set_transient(
                            "metron:series_cvid:{$item['series_id']}",
                            (int) $item['cv_id'],
                            YEAR_IN_SECONDS
                        );
                    }
                }
            
                return $cached;
            }
        }

        $url = $this->client->api_base . "publisher/{$publisher_id}/series_list/?page={$api_page}&page_size={$api_page_size}";

        $response = $this->client->api_get( $url );

        if (
            ! $response ||
            empty( $response['results'] ) ||
            ( ! empty( $response['detail'] ) && str_contains( $response['detail'], 'Invalid page' ) )
        ) {
            $empty = [
                'items'    => [],
                'total'    => 0,
                'has_next' => false,
            ];

            set_transient( $cache_key, $empty, 30 * DAY_IN_SECONDS );

            return $empty;
        }

        $items = [];

        foreach ( $response['results'] as $item ) {
            
            $items[] = [
                'series_id'   => $item['id'],
                'name'        => $item['series'],
                'volume'      => $item['volume']      ?? '1',
                'issue_count' => $item['issue_count'] ?? 0,
                'year_began'  => $item['year_began']  ?? 'N/A',
                'image'       => $item['image']       ?? '',
                'cv_id'       => $item['cv_id']       ?? null,
            ];
        
            if ( ! empty( $item['cv_id'] ) ) {
                set_transient( "metron:series_cvid:{$item['id']}", (int) $item['cv_id'], YEAR_IN_SECONDS );
            }
        }

        $result = [
            'items'    => $items,
            'total'    => (int) ( $response['count'] ?? 0 ),
            'has_next' => ! empty( $response['next'] ),
        ];

        set_transient( $cache_key, $result, 30 * DAY_IN_SECONDS );

        return $result;
    }

    /**
     * Apply letter and search filters to a raw series map.
     */
    private function filter_series_list( array $full, string $letter, string $search ): array {
        $data = $full;

        if ( $search ) {
            $s    = strtolower( trim( $search ) );
            $data = array_filter( $data, fn( $item ) => stripos( $item['name'], $s ) !== false );
        }

        if ( $letter !== 'all' ) {
            $data = array_filter( $data, function( $item ) use ( $letter ) {
                $title = preg_replace( '/^(The|A|An)\s+/i', '', trim( $item['name'] ) );
                $first = strtoupper( mb_substr( $title, 0, 1 ) );
                return $letter === '#' ? ! ctype_alpha( $first ) : $first === strtoupper( $letter );
            } );
        }

        return array_values( $data );
    }

    /**
     * Get issues for a series — incremental-page edition.
     *
     * Instead of looping through up to 10 API pages on every cold load,
     * we fetch only the three Metron API pages that surround the requested
     * display page: prev (if any), current, next.
     *
     * Cache structure
     * ──────────────
     *  metron:issue_page:{id}:{n}   – results[] for Metron API page n   (30 d)
     *  metron:issue_total:{id}      – total issue count from the API     (30 d)
     *  metron:series:{id}           – series metadata                    (14 d)
     *
     * Backward compat: if the old v5 full-list transient still exists it
     * is used as-is and expires naturally after 30 days.
     */
    public function get_series_issues( $title_id, $current_page = 1, $search = '' ) {
        $title_id     = (int) $title_id;
        $current_page = max( 1, (int) $current_page );
        $per_page     = 10;
        $api_size     = 100; // Metron page_size
    
        // ── Series metadata ───────────────────────────────────────────────
        $series_key = "metron:series:{$title_id}";
        $series     = get_transient( $series_key );
        if ( $series === false ) {
            $series = $this->client->api_get( $this->client->api_base . "series/{$title_id}/" );
            if ( empty( $series['name'] ) ) {
                return [ 'error' => 'Series not found' ];
            }
            set_transient( $series_key, $series, 14 * DAY_IN_SECONDS );
        }
    
        // ── Backward compat: legacy v5 full-list cache ────────────────────
        $full_key  = "metron:issue_list_full:{$title_id}:v5";
        $full_data = get_transient( $full_key );
        $use_new   = ( $full_data === false || ! isset( $full_data['results'] ) || ! is_array( $full_data['results'] ) );
    
        $combined = [];  // api_page => results[]  (new mode only)
        $total    = 0;
    
        if ( $use_new ) {
            // display pages 1-10 → api page 1, 11-20 → 2, etc.
            $api_pg_needed = max( 1, (int) ceil( $current_page * $per_page / $api_size ) );
    
            $total_key = "metron:issue_total:{$title_id}";
            $total     = (int)( get_transient( $total_key ) ?: 0 );
    
            /*
             * Fetch ONLY the Metron API page that covers the requested display
             * page. Previously we also pulled prev/next speculatively on every
             * load — up to 3 Metron calls per cold load. That only ever paid
             * off exactly at a 100-item page boundary (display page 11, 21...),
             * and the burst limit is shared across every visitor hitting this
             * plugin. Each page is still cached 30 days once fetched, so
             * repeat views of the same page cost nothing regardless.
             */
            $current_ap = $api_pg_needed;
    
            $current_key  = "metron:issue_page:{$title_id}:{$current_ap}";
            $current_data = get_transient( $current_key );
    
            if ( $current_data === false ) {
                $url = $this->client->api_base . "series/{$title_id}/issue_list/?page={$current_ap}&page_size={$api_size}";
                $current_resp = $this->client->api_get( $url );
    
                if ( ! empty( $current_resp['results'] ) && is_array( $current_resp['results'] ) ) {
                    $current_data = $current_resp['results'];
    
                    set_transient( $current_key, $current_data, 30 * DAY_IN_SECONDS );
    
                    if ( ! empty( $current_resp['count'] ) ) {
                        $total = (int) $current_resp['count'];
                        set_transient( $total_key, $total, 30 * DAY_IN_SECONDS );
                    }
                } else {
                    $current_data = [];
                    set_transient( $current_key, [], 30 * DAY_IN_SECONDS );
                }
            }
    
            if ( ! empty( $current_data ) ) {
                $combined[ $current_ap ] = $current_data;
            }
    
            $all = $combined ? array_merge( ...array_values( $combined ) ) : [];
    
        } else {
            // Legacy complete cache
            $all   = $full_data['results'];
            $total = count( $all );
        }
    
        // ── Sort by issue number ──────────────────────────────────────────
        usort( $all, function( $a, $b ) {
            $nA = is_numeric( trim( (string)( $a['number'] ?? '' ) ) ) ? (float) $a['number'] : INF;
            $nB = is_numeric( trim( (string)( $b['number'] ?? '' ) ) ) ? (float) $b['number'] : INF;
            return $nA !== $nB ? ( $nA <=> $nB ) : ( (int)( $a['id'] ?? 0 ) ) <=> ( (int)( $b['id'] ?? 0 ) );
        } );
    
        // ── Search filter ─────────────────────────────────────────────────
        if ( $search ) {
            $s   = strtolower( trim( $search ) );
            $all = array_values( array_filter( $all, fn( $i ) =>
                stripos( $i['number']     ?? '', $s ) !== false ||
                stripos( $i['issue']      ?? '', $s ) !== false ||
                stripos( $i['cover_date'] ?? '', $s ) !== false
            ) );
            $total = count( $all );
        }
    
        // ── Slice for the requested display page ──────────────────────────
        if ( $use_new && ! $search ) {
            $min_api_page    = $combined ? min( array_keys( $combined ) ) : $api_pg_needed;
            $assembled_start = ( $min_api_page - 1 ) * $api_size;
            $abs_start       = ( $current_page  - 1 ) * $per_page;
            $offset          = max( 0, $abs_start - $assembled_start );
            $paged_issues    = array_slice( $all, $offset, $per_page );
        } else {
            $paged_issues = array_slice( $all, ( $current_page - 1 ) * $per_page, $per_page );
        }
    
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
    
        if ( $current_page > $total_pages && $total > 0 ) {
            $paged_issues = [];
        }
    
        return [
            'series'       => is_array( $series ) ? $series : [],
            'issue_list'   => [ 'count' => $total, 'results' => $paged_issues ],
            'current_page' => $current_page,
            'total_pages'  => $total_pages,
            'total_issues' => $total,
            'per_page'     => $per_page,
        ];
    }
            

    /* -----------------------------------------------------------------
    **  SINGLE ISSUE
    ** ----------------------------------------------------------------- */

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
    *  COMIC VINE PUBLISHER FALLBACK
    * ----------------------------------------------------------------- */
    public function get_comicvine_publisher_info( $cv_id ) {

        if ( empty( $cv_id ) ) {
            return [];
        }
    
        $cache_key = 'cv_publisher_' . absint( $cv_id );
    
        $cached = get_transient( $cache_key );
    
        if ( $cached !== false ) {
            return $cached;
        }
    
        $cv_key = get_option( 'comic_vine_api_key', '' );
    
        if ( empty( $cv_key ) ) {
            return [];
        }
    
        $url = add_query_arg(
            [
                'api_key' => $cv_key,
                'format'  => 'json',
            ],
            'https://comicvine.gamespot.com/api/publisher/4010-' . absint( $cv_id ) . '/'
        );
    
        $response = wp_remote_get(
            $url,
            [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'ComicBookFetcher/1.1 (+' . get_site_url() . ')'
                ],
            ]
        );
    
        if ( is_wp_error( $response ) ) {
            error_log(
                'Comic Vine publisher lookup failed for cv_id ' .
                $cv_id .
                ': ' .
                $response->get_error_message()
            );
    
            return [];
        }
    
        $body = json_decode(
            wp_remote_retrieve_body( $response ),
            true
        );
    
        if ( empty( $body['results'] ) ) {
            error_log(
                'Comic Vine found no publisher for cv_id: ' . $cv_id
            );
    
            return [];
        }
    
        $publisher = $body['results'];
    
        $result = [
            'image'   => $publisher['image']['original_url'] ?? '',
            'desc'    => $publisher['deck']
                ?? $publisher['description']
                ?? '',
            'founded' => $publisher['start_year'] ?? '',
        ];
    
        if ( empty( $result['founded'] ) && ! empty( $publisher['description'] ) ) {
            if (
                preg_match(
                    '/founded.*?(\d{4})/i',
                    strip_tags( $publisher['description'] ),
                    $matches
                )
            ) {
                $result['founded'] = $matches[1];
            }
        }
    
        set_transient(
            $cache_key,
            $result,
            30 * DAY_IN_SECONDS
        );
    
        return $result;
    }
    
    /* -----------------------------------------------------------------
    *  COMIC VINE issue image only
    * ----------------------------------------------------------------- */
    public function get_comicvine_issue_image( $cv_id ) {

        $cv_id = intval( $cv_id );
        if ( empty( $cv_id ) ) {
            return '';
        }
    
        $cache_key = "cv_issue_image_$cv_id";
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }
    
        $body  = $this->cv_api_get( "https://comicvine.gamespot.com/api/issue/4000-{$cv_id}/", [ 'field_list' => 'image' ] );
        $image = $body['results']['image'] ?? [];
    
        $img = $image['small_url'] ?? $image['medium_url'] ?? $image['original_url'] ?? '';
    
        set_transient( $cache_key, $img, 30 * DAY_IN_SECONDS );
        return $img;
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

        $url = add_query_arg(
            [
                'api_key' => $cv_key,
                'format'  => 'json',
            ],
            "https://comicvine.gamespot.com/api/issue/4000-{$cv_id}/"
        );
        
        $res = wp_remote_get(
            $url,
            [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'CollectibleSpotBot/1.1 (+' . get_site_url() . ')',
                ],
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
        $merged['cv_id'] = (int) $cv_id;

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