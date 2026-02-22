    <?php

    $issue = $this->data_service->get_single_issue( $title_id, $issue_id );
    if ( ! $issue ) {
        echo '<p>Issue not found or does not belong to this series.</p>';
        return;
    }    

    $series_cache_key = "metron:issue:{$title_id}_{$issue_id}";     
    $series = get_transient( $series_cache_key );

    if ( false === $series ) {
        $url_series = $this->client->api_base . "series/{$title_id}/";
        $series = $this->client->api_get( $url_series );

        if ( isset( $series['error'] ) || empty( $series['name'] ) ) {
            echo '<p>Series not found.</p>';
            return;
        }

        set_transient( $series_cache_key, $series, MONTH_IN_SECONDS );  // series rarely changes
    }

    // ─────────────────────────────────────────────────────────────
    //  ComicVine enrichment (same as before)
    // ───────────────────────────────────────────────────────────── 

    $cv_series_id = $this->data_service->get_metron_cv_id( $issue_id ); 


    $cv_issue = $cv_series_id 
        ? $this->data_service->get_comicvine_issue_info( $cv_series_id, $issue_id ) 
        : [];

    $description = $this->clean_cv_description(
        $cv_issue['description'] ?? $issue['description'] ?? $issue['desc'] ?? ''
    );
    $creators = $cv_issue['person_credits'] ?? $issue['credits'] ?? [];
    $creator_infos = array_map( function( $p ) {
        $name = $p['name'] ?? $p['creator'] ?? 'Unknown';
        $role = is_array( $p['role'] ?? [] )
            ? implode( ', ', array_column( $p['role'], 'name' ) )
            : ( $p['role'] ?? 'N/A' );
        return "$name – $role";
    }, $creators );

    $creator_info_string = implode( "\n", $creator_infos );

    $genre_sources = ! empty( $series['genres'] ) ? $series['genres'] : ( $cv_issue['concept_credits'] ?? [] );
    $genre_string = implode( ', ', array_column( $genre_sources, 'name' ) );

    $date_raw = $cv_issue['cover_date'] ?? $issue['cover_date'] ?? null;
    $cover_date_display = $date_raw ? date( 'F Y', strtotime( $date_raw ) ) : 'Unknown';

    // Optional: more fallbacks
    $issue_number = $issue['number'] ?? '??';
    $issue_title  = trim( ( $series['name'] ?? 'Series' ) . ' #' . $issue_number ); 

    ?>
       
       <div class="d-flex flex-column flex-md-row w-100">
            <main class="site-main flex-fill">
                <section id="body-content" class="page-section text-center">  
                    <div class="comic-issue-details-container">
                        <header class="page-header">                        
                            <nav class="category-breadcrumbs">
                                <a href="<?php echo esc_url(home_url('/comic-catalog')); ?>">Publishers</a>
                                <span class="separator">&#10148;</span>
                                <span class="category"><a href="<?php echo esc_url(home_url('/comic-catalog/?publisher_id=' . $series['publisher']['id'])); ?>">

                                    <?php echo esc_html($series['publisher']['name']); ?>

                                </a></span>
                                <span class="separator">&#10148;</span>
                                <span class="category">
                                    <a href="<?php echo esc_url(home_url('/comic-catalog/issues/?title_id=' . $title_id)); ?>">
                                    <?php echo esc_html($series['series']['name'] ?? 'Comic Series'); ?></a>
                                </span>
                                <span class="current-category"><?php echo '&nbsp; # ' . esc_html($issue['number'] ?? 'N/A'); ?></div>
                            </nav>
                            <h1 class="page-title">
                                <?php
                                $issue_title = esc_html($series['series']['name'] ?? 'Comic Series') . ' #' . esc_html($issue['number'] ?? 'N/A');
                                ?>
                                <span><?php echo $issue_title; ?></span>
                            </h1>
                        </header>
                        <div class="issue-details-header">
                            <div>
                                <p><?php echo esc_html($series['publisher']['name'] ?? 'N/A'); ?>&nbsp;&nbsp;/&nbsp;
                                <?php
                                    $date_raw = $cv_issue['cover_date'] ?? $issue['cover_date'] ?? null;
                                    echo $date_raw ? esc_html(date('F Y', strtotime($date_raw))) : 'Unknown';
                                ?>
                                </p>
                            </div>
                        </div>
                        <div class="issue-details-content">
                            
                            <?php if (!empty($issue['image'])): 
                           
                                ?>
                                <img src="<?php echo esc_url($issue['image']); ?>" alt="<?php echo esc_attr($issue['issue'] ?? 'Issue Cover'); ?>" loading="lazy">
                            <?php else:                               
                                ?>
                                <img src="<?php echo esc_url(PUBLISHER_PLACEHOLDER_IMAGE_URL); ?>" alt="Placeholder" class="issue-image" loading="lazy">
                                
                            <?php endif; ?>
    
                            <div class="issue-notes-box">
                                <?php if (!empty($issue) || !empty($cv_issue)): ?>
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                        <h2>Summary</h2>
                                        <?php
                                        $in_collection = false;
                                        $collection_post_id = 0;
    
                                        if (is_user_logged_in()) {
                                            $existing_posts = get_posts([
                                                'post_type'      => 'post',
                                                'author'         => get_current_user_id(),
                                                'posts_per_page' => 1,
                                                'meta_query'     => [
                                                    [
                                                        'key'     => 'issue_id',
                                                        'value'   => $issue_id,
                                                        'compare' => '='
                                                    ]
                                                ],
                                                'fields' => 'ids',
                                            ]);
    
                                            if (!empty($existing_posts)) {
                                                $in_collection = true;
                                                $collection_post_id = $existing_posts[0];
                                            }
                                        }
    
                                        // Collection and Wishlist Buttons
                                        if (is_user_logged_in()): ?>
                                            <div class="d-flex flex-wrap align-items-end gap-3">
                                                <!-- Collection Button -->
                                                <div class="text-center">
                                                    <button
                                                        class="add-to-collection <?php echo $in_collection ? 'in-collection' : ''; ?>"
                                                        style="<?php echo $in_collection ? 'background-color: red; color: white;' : ''; ?>"
                                                        data-title="<?php echo esc_attr($issue_title); ?>"
                                                        data-genres="<?php echo esc_attr($genre_string); ?>"
                                                        data-genre-origin="<?php echo empty($series['genres']) ? 'cv' : 'metron'; ?>"
                                                        data-description="<?php echo esc_attr($description); ?>"
                                                        data-issue-id="<?php echo esc_attr($issue_id); ?>"
                                                        data-title-id="<?php echo esc_attr($title_id); ?>"
                                                        data-publisher="<?php echo esc_attr($series['publisher']['name'] ?? 'Unknown'); ?>"
                                                        data-creators="<?php echo esc_attr($creator_info_string); ?>"
                                                        data-date="<?php echo esc_attr($date_raw); ?>"
                                                        data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
                                                        data-issue-number="<?php echo esc_attr($issue['number']); ?>"
                                                        data-image-url="<?php echo esc_url($issue['image']); ?>"
                                                        <?php if ($in_collection): ?>
                                                            data-post-id="<?php echo esc_attr($collection_post_id); ?>"
                                                            data-action="remove"
                                                        <?php else: ?>
                                                            data-action="add"
                                                        <?php endif; ?>>
                                                        <?php echo $in_collection ? 'Remove from Collection' : 'Add to My Collection'; ?>
                                                    </button>
                                                </div>
                                                <!-- Add to Wishlist Button -->
                                                <?php
                                                $url = add_query_arg(
                                                    [
                                                        'issue_id' => $issue_id,
                                                        'title_id' => $title_id
                                                    ],
                                                    site_url('/comic-catalog/issue/')
                                                );
                                                ?>
                                                <button
                                                    class="add-to-wishlist"
                                                    data-type="post"
                                                    data-item-id="<?php echo esc_attr($metron_cv_id); ?>"
                                                    data-title="<?php echo esc_attr($issue_title); ?>"
                                                    data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
                                                    data-item-url="<?php echo esc_url($url); ?>"
                                                    data-image-url="<?php echo esc_url($issue['image']); ?>">
                                                    Add to Wishlist
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
    
                                    <div class="issue-section description-block">
                                        <?php
                                            if (!empty($description)) {
                                                echo wp_kses_post($description);
                                            }
                                        ?>
                                    </div>
    
                                    <?php if (!empty($issue['characters'])): ?>
                                        <div class="issue-section characters">
                                            <h3>Key Characters</h3>
                                            <ul>
                                                <?php
                                                $char_count = count($issue['characters']);
                                                $char_num = 0;
                                                foreach ($issue['characters'] as $char):
                                                    $char_num++;
                                                ?>
                                                    <li>
                                                        <?php
                                                        echo esc_html($char['name']);
                                                        if ($char_num < $char_count) echo ',';
                                                        ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
    
                                    <?php if (!empty($cv_issue['location_credits'])): ?>
                                        <div class="issue-section location">
                                            <h3>Locations</h3>
                                            <ul>
                                                <?php
                                                $loc_count = count($cv_issue['location_credits']);
                                                $loc_num = 0;
                                                foreach ($cv_issue['location_credits'] as $loc):
                                                    $loc_num++;
                                                ?>
                                                    <li>
                                                        <?php
                                                        echo esc_html($loc['name']);
                                                        if ($loc_num < $loc_count) echo ',';
                                                        ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
    
                                    <?php
                                    $genre_label = 'Genres';
                                    if (empty($series['genres']) && !empty($cv_issue['concept_credits'])) {
                                        $genre_label = 'Concepts';
                                    }
                                    ?>
    
                                    <?php if (!empty($genre_string)): ?>
                                        <div class="issue-section genre">
                                            <h3><?php echo esc_html($genre_label); ?></h3>
                                            <ul>
                                                <li><?php echo esc_html($genre_string); ?></li>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
    
                                    <?php if (!empty($issue['reprints'])): ?>
                                        <div class="issue-section reprints">
                                            <h3>Reprints</h3>
                                            <ul>
                                                <?php
                                                $reprint_count = count($issue['reprints']);
                                                $reprint_num = 0;
                                                foreach ($issue['reprints'] as $reprint):
                                                    $reprint_num++;
                                                ?>
                                                    <li>
                                                        <?php
                                                        echo esc_html($reprint['issue']);
                                                        if ($reprint_num < $reprint_count) echo ',';
                                                        ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
    
                                    <?php if (!empty($creator_infos)): ?>
                                        <div class="issue-section creators">
                                            <h3>Creators</h3>
                                            <ul>
                                                <?php foreach ($creator_infos as $info): ?>
                                                    <li><?php echo esc_html($info); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
            </main>
        </div>
        <?php