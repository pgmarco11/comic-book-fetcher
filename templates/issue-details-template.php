<?php
/**
 * Issue Details Template – Optimized
 * 
 */

    $cache_key = "metron:issue:{$title_id}_{$issue_id}";
    $issue = get_transient($cache_key);
    
    if ($issue === false) {

        if (!$comic_renderer) {
            echo '<p>Error: Comic renderer is not initialized.</p>';
            return;
        }

        $issue = $comic_renderer->get_single_issue($title_id, $issue_id); 
    
        if (!$issue) {
            echo '<p>Issue not found or does not belong to this series.</p>';
            return;
        }
    
        set_transient($cache_key, $issue, DAY_IN_SECONDS);
    }

    
    // Normalize structure
    $series    = $issue['series'] ?? [];
    $publisher = $issue['publisher'] ?? [];   

    $cached_series = get_transient("metron:series:{$title_id}");

    if (
        is_array($cached_series) &&
        (int) ($cached_series['id'] ?? 0) === (int) $title_id
    ) {
        $series = array_replace($series, $cached_series);
    }
  

    // ─────────────────────────────────────────────────────────────
    //  ComicVine enrichment 
    // ───────────────────────────────────────────────────────────── 

        /*
        * Use the mapping already supplied with this issue.
        * Only look it up separately if the cv_id field is absent.
        */
        $cv_issue_id = array_key_exists('cv_id', $issue)
            ? absint($issue['cv_id'])
            : absint($comic_renderer->get_metron_cv_id($issue_id));

        $cv_issue = $cv_issue_id
            ? (
                $comic_renderer->get_comicvine_issue_info(
                    $cv_issue_id,
                    $issue
                ) ?? []
            )
            : [];

    // CV uses its own IDs; passing a Metron ID can return a wrong issue.
    $metron_issue_number = $issue['number'] ?? null;
    $cv_issue_number     = $cv_issue['issue_number'] ?? null;
    
    $cv_data_is_valid = (
        !empty($cv_issue) &&
        $metron_issue_number !== null &&
        (string) $cv_issue_number === (string) $metron_issue_number
    );
    
    if ( ! $cv_data_is_valid ) {
        $cv_issue = [];
    }
    
    // Description: CV (only if valid match) → Metron → fallback
    $raw_description =
        ( $cv_data_is_valid ? ( $cv_issue['description'] ?? '' ) : '' )
        ?: ( $issue['description'] ?? '' )
        ?: ( $issue['desc']        ?? '' )
        ?: 'No description available.';
    
    $description = $comic_renderer->clean_cv_description( $raw_description );

    $metron_cv_id = $cv_data_is_valid ? ( $cv_issue['id'] ?? '' ) : '';

    // Creators
    $creators = $cv_data_is_valid
    ? ( $cv_issue['person_credits'] ?? $issue['credits'] ?? [] )
    : ( $issue['credits'] ?? [] );

    $creator_infos = [];
    
    foreach ($creators as $p) {
        $name = $p['name'] ?? $p['creator'] ?? 'Unknown';
    
        $role = is_array($p['role'] ?? null)
            ? implode(', ', array_column($p['role'], 'name'))
            : ($p['role'] ?? 'N/A');
    
        $creator_infos[] = "$name – $role";
    }
    
    $creator_info_string = implode("\n", $creator_infos);


    $genre_sources = tcs_catalog_names($series['genres'] ?? []);

    $genre_string = implode(', ', $genre_sources);
    
    $concept_string = implode(
        ', ',
        tcs_catalog_names($cv_issue['concept_credits'] ?? [])
    );

    // Optional: more fallbacks
    $series_name   = $series['name'] ?? 'Series';
    $issue_number  = $issue['number'] ?? '??';
    $issue_title   = trim($series_name . ' #' . $issue_number);
    
    $date_raw = $issue['cover_date'] ?? null; 
    $cover_date_display = $date_raw ? date('F Y', strtotime($date_raw)) : 'Unknown';

    $volume = $series['volume'] ?? '';

    ?>
       
       <div class="d-flex flex-column flex-md-row w-100">
            <main class="site-main flex-fill">
                <section id="body-content" class="page-section text-center">  
                    <div class="comic-issue-details-container">
                        <header class="page-header">                        
                            <nav class="category-breadcrumbs">
                                <a href="<?php echo esc_url(home_url('/comic-catalog')); ?>">Publishers</a>
                                <span class="separator">&#10148;</span>
                                <span class="category"><a href="<?php echo esc_url(home_url('/comic-catalog/?publisher_id=' . $publisher['id'])); ?>">

                                    <?php echo esc_html($publisher['name']); ?>

                                </a></span>
                                <span class="separator">&#10148;</span>
                                <span class="category">
                                    <a href="<?php echo esc_url(home_url('/comic-catalog/issues/?title_id=' . $title_id)); ?>">
                                    <?php echo esc_html($series['name'] ?? 'Comic Series'); ?></a>
                                </span>
                                <span class="current-category"><?php echo '&nbsp; # ' . esc_html($issue['number'] ?? 'N/A'); ?></div>
                            </nav>
                            <h1 class="page-title">
                                <?php
                                $issue_title = esc_html($series['name'] ?? 'Comic Series') . ' #' . esc_html($issue['number'] ?? 'N/A');
                                ?>
                                <span><?php echo $issue_title; ?></span>
                            </h1>
                        </header>
                        <div class="issue-details-header">
                            <div>
                                <p><?php echo esc_html($publisher['name'] ?? 'N/A'); ?>&nbsp;&nbsp;/&nbsp;
                                <?php echo esc_html($cover_date_display); ?>
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
                                                        type="button"
                                                        class="add-to-collection <?php echo $in_collection ? 'in-collection' : ''; ?>"
                                                        style="<?php echo $in_collection ? 'background-color: red; color: white;' : ''; ?>"
                                                        data-title="<?php echo esc_attr($series['name'] ?? ''); ?>"                                                  
                                                        data-description="<?php echo esc_attr(wp_strip_all_tags($description)); ?>"
                                                        data-issue-id="<?php echo esc_attr($issue_id); ?>"
                                                        data-cv-issue-id="<?php echo esc_attr($metron_cv_id ?: ''); ?>"
                                                        data-title-id="<?php echo esc_attr($title_id); ?>"
                                                        data-creators="<?php echo esc_attr($creator_info_string ?: ''); ?>"
                                                        data-date="<?php echo esc_attr($date_raw); ?>"
                                                        data-genres="<?php echo esc_attr($genre_string); ?>"
                                                        data-publisher="<?php echo esc_attr($publisher['name'] ?? 'Unknown'); ?>"
                                                        data-volume="<?php echo esc_attr($volume); ?>"
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
                                                    type="button"
                                                    class="add-to-wishlist"
                                                    data-type="post"
                                                    data-item-id="metron:issue:<?php echo esc_attr($issue_id); ?>"
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
                                            } else {
                                                echo '<p>No summary available.</p>';
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
    
                                    <?php if ($genre_string !== ''): ?>
                                        <div class="issue-section genre">
                                            <h3>Genres</h3>
                                            <p><?php echo esc_html($genre_string); ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($concept_string !== ''): ?>
                                        <div class="issue-section concepts">
                                            <h3>Concepts</h3>
                                            <p><?php echo esc_html($concept_string); ?></p>
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