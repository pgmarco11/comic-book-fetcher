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
                                        <h3>Summary</h3>
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
                                                        data-description="<?php echo esc_html($description); ?>"
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
                                            <h4>Key Characters</h4>
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
                                            <h4>Locations</h4>
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
                                            <h4><?php echo esc_html($genre_label); ?></h4>
                                            <ul>
                                                <li><?php echo esc_html($genre_string); ?></li>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
    
                                    <?php if (!empty($issue['reprints'])): ?>
                                        <div class="issue-section reprints">
                                            <h4>Reprints</h4>
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
                                            <h4>Creators</h4>
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