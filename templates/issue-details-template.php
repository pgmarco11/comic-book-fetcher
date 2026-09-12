<?php
/**
 * Issue Details Template – Optimized
 * 
 */

    if (!$comic_renderer) {
        echo '<p>Error: Comic renderer is not initialized.</p>';
        return;
    }

    /*
    * Caching is handled by ComicDataService.
    * The template should only request the complete issue record.
    */
    $issue = $comic_renderer->get_single_issue(
        (int) $title_id,
        (int) $issue_id
    );

    if (!is_array($issue)) {
        echo '<p>Issue information is temporarily unavailable. Please try again.</p>';
        return;
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
        is_array($cv_issue) &&
        (int) ($cv_issue['id'] ?? 0) === $cv_issue_id &&
        $metron_issue_number !== null &&
        trim((string) $cv_issue_number) ===
            trim((string) $metron_issue_number)
    );
    
    if ( ! $cv_data_is_valid ) {
        $cv_issue = [];
    }
    
    // Description: CV (only if valid match) → Metron → fallback
    $raw_description =
        ($cv_data_is_valid
            ? ($cv_issue['description'] ?? '')
            : '')
        ?: ($issue['description'] ?? '')
        ?: ($issue['desc'] ?? '')
        ?: 'No description available.';

    $description = $comic_renderer->clean_cv_description(
        $raw_description
    );

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

    /*
    * ---------------------------------------------------------
    * Current user's collection record
    * ---------------------------------------------------------
    */
    $collection_post_id = 0;
    $collection_record  = null;
    $in_collection      = false;

    if (is_user_logged_in()) {
        $collection_posts = get_posts([
            'post_type'      => 'collection',
            'post_status'    => 'publish',
            'author'         => get_current_user_id(),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => 'issue_id',
                    'value'   => (int) $issue_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        if (!empty($collection_posts)) {
            $collection_post_id =
                (int) $collection_posts[0];

            $in_collection = true;

            if (
                function_exists(
                    'tcs_inventory_record'
                )
            ) {
                $collection_record =
                    tcs_inventory_record(
                        $collection_post_id
                    );
            }
        }
    }

    /*
    * Only the known view values are allowed.
    */
    $requested_view =
        isset($_GET['view']) &&
        is_scalar($_GET['view'])
            ? sanitize_key(
                wp_unslash($_GET['view'])
            )
            : 'details';

    $active_view =
        $requested_view === 'collection' &&
        is_user_logged_in()
            ? 'collection'
            : 'details';

    $details_url = add_query_arg(
        [
            'issue_id' => (int) $issue_id,
            'title_id' => (int) $title_id,
        ],
        home_url('/comic-catalog/issue/')
    );

    $collection_url = add_query_arg(
        [
            'issue_id' => (int) $issue_id,
            'title_id' => (int) $title_id,
            'view'     => 'collection',
        ],
        home_url('/comic-catalog/issue/')
    );

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
                                    <span
                                        class="separator"
                                        aria-hidden="true"
                                    >➤</span>
                                    <span
                                        class="current-category"
                                        aria-current="page"
                                    >
                                        <?php
                                        echo '# ' . esc_html(
                                            $issue['number'] ?? 'N/A'
                                        );
                                        ?>
                                    </span>
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
                        <div
                            id="issue-collection-tabs"
                            class="issue-collection-tabs"
                            data-active-view="<?php
                                echo esc_attr($active_view);
                            ?>"
                        >
                            <div
                                class="issue-collection-tablist"
                                role="tablist"
                                aria-label="Comic issue sections"
                            >
                                <a
                                    id="issue-details-tab"
                                    class="issue-collection-tab"
                                    href="<?php echo esc_url($details_url); ?>"
                                    role="tab"
                                    aria-controls="issue-details-panel"
                                    aria-selected="<?php
                                        echo $active_view === 'details'
                                            ? 'true'
                                            : 'false';
                                    ?>"
                                    tabindex="<?php
                                        echo $active_view === 'details'
                                            ? '0'
                                            : '-1';
                                    ?>"
                                    data-issue-tab="details"
                                >
                                    Issue Details
                                </a>

                                <?php if (is_user_logged_in()) : ?>
                                    <a
                                        id="issue-collection-tab"
                                        class="issue-collection-tab"
                                        href="<?php
                                            echo esc_url($collection_url);
                                        ?>"
                                        role="tab"
                                        aria-controls="issue-collection-panel"
                                        aria-selected="<?php
                                            echo $active_view === 'collection'
                                                ? 'true'
                                                : 'false';
                                        ?>"
                                        tabindex="<?php
                                            echo $active_view === 'collection'
                                                ? '0'
                                                : '-1';
                                        ?>"
                                        data-issue-tab="collection"
                                    >
                                        My Collection

                                        <?php if ($in_collection) : ?>
                                            <span
                                                class="issue-collection-owned"
                                                aria-label="In your collection"
                                            >
                                                ✓
                                            </span>
                                        <?php endif; ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div
                            id="issue-details-panel"
                            class="issue-collection-panel"
                            role="tabpanel"
                            aria-labelledby="issue-details-tab"
                            <?php
                            if ($active_view !== 'details') {
                                echo 'hidden';
                            }
                            ?>
                        >
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
        
                                            if (is_user_logged_in()) { 
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
                        <?php if (is_user_logged_in()) : ?>
                            <div
                                id="issue-collection-panel"
                                class="issue-collection-panel"
                                role="tabpanel"
                                aria-labelledby="issue-collection-tab"
                                <?php
                                if ($active_view !== 'collection') {
                                    echo 'hidden';
                                }
                                
                                ?>
                            >
                                <div class="issue-collection-card">

                                    <?php
                                        $my_collection_url = home_url( '/my-collection/' );
                                    ?>
                                    
                                    <header class="issue-collection-heading">
                     
                                        <div>
                                            <p class="issue-collection-kicker">
                                                MY COLLECTION
                                            </p>

                                            <h2>
                                                <?php
                                                echo $in_collection
                                                    ? 'Edit your copy'
                                                    : 'Add this issue';
                                                ?>
                                            </h2>

                                            <p>
                                                <?php
                                                echo $in_collection
                                                    ? 'Update the quantity, condition, price, notes, and storage location for this issue.'
                                                    : 'This issue is not currently in your collection.';
                                                ?>
                                            </p>
                                        </div>


                                        <?php if ($in_collection) :  ?>

                                            <div class="issue-collection-heading">
                                                <a
                                                    id="back-to-collection"
                                                    class="issue-collection-back"
                                                    href="<?php echo esc_url( $my_collection_url ); ?>"
                                                >
                                                    <span aria-hidden="true">←</span>
                                                    <span>My Collection</span>
                                                </a>

                                                <span class="issue-collection-status">
                                                    In collection
                                                </span>

                                            </div> 
                                            
                                        <?php endif; ?>
                                    </header>

                                    <div
                                        id="issue-collection-feedback"
                                        class="issue-collection-feedback"
                                        role="status"
                                        aria-live="polite"
                                        aria-atomic="true"
                                    ></div>

                                    <?php if (
                                        $in_collection &&
                                        is_array($collection_record)
                                    ) : ?>
                                        <form
                                            id="issue-collection-form"
                                            class="issue-collection-form"
                                        >
                                            <input
                                                type="hidden"
                                                name="post_id"
                                                value="<?php
                                                    echo (int)
                                                        $collection_record['id'];
                                                ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="version"
                                                value="<?php
                                                    echo esc_attr(
                                                        $collection_record[
                                                            'version'
                                                        ]
                                                    );
                                                ?>"
                                            >

                                            <div class="issue-collection-form-grid">
                                                <div class="issue-collection-field">
                                                    <label for="issue-collection-qty">
                                                        Quantity
                                                    </label>

                                                    <input
                                                        id="issue-collection-qty"
                                                        name="qty"
                                                        type="number"
                                                        min="1"
                                                        max="9999"
                                                        step="1"
                                                        required
                                                        value="<?php
                                                            echo (int)
                                                                $collection_record['qty'];
                                                        ?>"
                                                    >
                                                </div>

                                                <div class="issue-collection-field">
                                                    <label for="issue-collection-price">
                                                        Recorded price
                                                    </label>

                                                    <input
                                                        id="issue-collection-price"
                                                        name="price"
                                                        type="text"
                                                        inputmode="decimal"
                                                        pattern="[0-9]{1,7}(\.[0-9]{1,2})?"
                                                        value="<?php
                                                            echo esc_attr(
                                                                $collection_record['price']
                                                            );
                                                        ?>"
                                                        aria-describedby="issue-collection-price-help"
                                                    >

                                                    <small id="issue-collection-price-help">
                                                        Your recorded price, not a market estimate.
                                                    </small>
                                                </div>

                                                <div
                                                    class="issue-collection-field
                                                        issue-collection-field-wide"
                                                >
                                                    <label for="issue-collection-condition">
                                                        Condition / grade
                                                    </label>

                                                    <input
                                                        id="issue-collection-condition"
                                                        name="condition"
                                                        type="text"
                                                        maxlength="120"
                                                        list="issue-condition-options"
                                                        value="<?php
                                                            echo esc_attr(
                                                                $collection_record[
                                                                    'condition'
                                                                ]
                                                            );
                                                        ?>"
                                                    >

                                                    <datalist id="issue-condition-options">
                                                        <option value="10.0 (GEM MINT)">
                                                        <option value="9.8 (NEAR MINT/MINT)">
                                                        <option value="9.6 (NEAR MINT+)">
                                                        <option value="9.4 (NEAR MINT)">
                                                        <option value="9.2 (NEAR MINT-)">
                                                        <option value="9.0 (VERY FINE/NEAR MINT)">
                                                        <option value="8.0 (VERY FINE)">
                                                        <option value="6.0 (FINE)">
                                                        <option value="4.0 (VERY GOOD)">
                                                        <option value="2.0 (GOOD)">
                                                        <option value="Ungraded">
                                                    </datalist>
                                                </div>

                                                <div
                                                    class="issue-collection-field
                                                        issue-collection-field-wide"
                                                >
                                                    <label for="issue-collection-location">
                                                        Storage location
                                                    </label>

                                                    <input
                                                        id="issue-collection-location"
                                                        name="storage_location"
                                                        type="text"
                                                        maxlength="120"
                                                        placeholder="Example: Box 3, shelf 2"
                                                        value="<?php
                                                            echo esc_attr(
                                                                $collection_record[
                                                                    'storage_location'
                                                                ]
                                                            );
                                                        ?>"
                                                    >
                                                </div>

                                                <div
                                                    class="issue-collection-field
                                                        issue-collection-field-wide"
                                                >
                                                    <label for="issue-collection-notes">
                                                        Notes
                                                    </label>

                                                    <textarea
                                                        id="issue-collection-notes"
                                                        name="notes"
                                                        rows="6"
                                                        maxlength="10000"
                                                        placeholder="Variant, purchase details, signatures, defects, or other notes."
                                                    ><?php
                                                        echo esc_textarea(
                                                            $collection_record['notes']
                                                        );
                                                    ?></textarea>
                                                </div>
                                            </div>

                                            <div class="issue-collection-actions">
                                                <button
                                                    type="button"
                                                    class="add-to-collection
                                                        in-collection
                                                        issue-collection-remove"
                                                    data-action="remove"
                                                    data-post-id="<?php
                                                        echo (int)
                                                            $collection_post_id;
                                                    ?>"
                                                    data-issue-id="<?php
                                                        echo (int) $issue_id;
                                                    ?>"
                                                    data-title-id="<?php
                                                        echo (int) $title_id;
                                                    ?>"
                                                >
                                                    Remove from Collection
                                                </button>

                                                <button
                                                    type="submit"
                                                    class="issue-collection-save"
                                                >
                                                    Save Changes
                                                </button>
                                            </div>
                                        </form>

                                    <?php else : ?>
                                        <div class="issue-collection-empty">
                                            <div aria-hidden="true">＋</div>

                                            <h3>Add this comic to your collection</h3>

                                            <p>
                                                A collection record will be created with
                                                a quantity of one. You can then enter its
                                                condition, price, notes, and location.
                                            </p>

                                            <button
                                                type="button"
                                                class="add-to-collection
                                                    issue-collection-add"
                                                data-action="add"
                                                data-issue-id="<?php
                                                    echo (int) $issue_id;
                                                ?>"
                                                data-title-id="<?php
                                                    echo (int) $title_id;
                                                ?>"
                                                data-title="<?php
                                                    echo esc_attr($series_name);
                                                ?>"
                                                data-issue-number="<?php
                                                    echo esc_attr($issue_number);
                                                ?>"
                                                data-description="<?php
                                                    echo esc_attr(
                                                        wp_strip_all_tags(
                                                            $description
                                                        )
                                                    );
                                                ?>"
                                                data-creators="<?php
                                                    echo esc_attr(
                                                        $creator_info_string
                                                    );
                                                ?>"
                                                data-date="<?php
                                                    echo esc_attr($date_raw);
                                                ?>"
                                                data-genres="<?php
                                                    echo esc_attr($genre_string);
                                                ?>"
                                                data-publisher="<?php
                                                    echo esc_attr(
                                                        $publisher['name'] ??
                                                        'Unknown'
                                                    );
                                                ?>"
                                                data-volume="<?php
                                                    echo esc_attr($volume);
                                                ?>"
                                                data-image-url="<?php
                                                    echo esc_url(
                                                        $issue['image'] ?? ''
                                                    );
                                                ?>"
                                            >
                                                Add to My Collection
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        </div>
                    </div> 
                </section>
            </main>
        </div>
        <?php