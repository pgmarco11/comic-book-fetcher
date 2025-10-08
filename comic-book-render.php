<?php
/**
 * ComicRenderer class for rendering comic book data in the Comic Books Fetcher plugin.
 *
 * This class extends MetronAPI to fetch and display publishers, series, issues, and issue details
 * using the Metron API & Comic Vine API. It handles template rendering for comic-related pages in a 
 * WordPress environment, supporting pagination, filtering, and AJAX requests. The class integrates 
 * with WordPress for header/footer rendering and uses API caching for performance optimization.
 *
 * @package ComicBooksFetcher
 * @since 1.0.0
 * @author Peter Giammarco
 */
class ComicRenderer extends MetronAPI {

    public function clean_cv_description($desc) {
        if (empty($desc)) {
            return '';
        }
    
        // Step 1: Remove <em> tags if they wrap the entire first <p> tag's content
        $desc = preg_replace('/<p>\s*<em>(.*?)<\/em>\s*<\/p>/is', '<p>$1</p>', $desc);

        // Step 1a: Remove <em> tags if they wrap the entire description
        $desc = preg_replace('/^<em>(.*?)<\/em>$/is', '$1', $desc);
    
        // Step 2: Remove all <a> tags, keeping their inner text
        $desc = preg_replace('/<a\s+[^>]*>(.*?)<\/a>/is', '$1', $desc);
    
        // Step 3: Clean all <li> entries by removing quotes around formatted titles
        $desc = preg_replace_callback('/<li>(.*?)<\/li>/is', function ($matches) {
            $item = $matches[1];
    
            // Remove quotes inside <b> or <strong> blocks
            $item = preg_replace('/^<b>\s*["\']\s*(<[^>]+>[^<]+<\/[^>]+>)\s*["\']\s*<\/b>/i', '<b>$1</b>', $item);
            $item = preg_replace('/<b>\s*["\']\s*(<em>[^<]+<\/em>)\s*["\']\s*<\/b>/i', '<b>$1</b>', $item);
            $item = preg_replace('/<b>\s*["\']([^<]+)["\']\s*<\/b>/i', '<b>$1</b>', $item);
            $item = preg_replace('/(<\/(?:em|strong|b)>)["\']/', '$1', $item);
    
            // Remove quotes directly wrapping inline tags like <em> or <strong>
            $item = preg_replace('/"(<(?:em|strong)[^>]*>.*?<\/(?:em|strong)>)"/i', '$1', $item);
    
            return '<li>' . $item . '</li>';
        }, $desc);
    
        // Step 4: Clean quotes around inline <em> or <strong> tags outside of <li>
        $desc = preg_replace('/"(<(?:em|strong)[^>]*>.*?<\/(?:em|strong)>)"/i', '$1', $desc);
    
        // Step 5: Remove any stray quotes at the start/end of the description
        $desc = preg_replace('/^["\']\s*|\s*["\']$/i', '', $desc);
    
        // Step 6: Remove "Sidebar Location" column from tables
        $desc = preg_replace_callback('/<table.*?>.*?<\/table>/is', function ($table_match) {
            $table_html = $table_match[0];
    
            // Remove <th>Sidebar Location</th> and get the index
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $table_html);
    
            $xpath = new DOMXPath($dom);
            $header_ths = $xpath->query('//th');
            $sidebar_index = -1;
            foreach ($header_ths as $i => $th) {
                if (trim($th->textContent) === 'Sidebar Location') {
                    $sidebar_index = $i;
                    $th->parentNode->removeChild($th);
                    break;
                }
            }
    
            // Remove the corresponding <td> in each row
            if ($sidebar_index > -1) {
                $rows = $xpath->query('//tr');
                foreach ($rows as $row) {
                    $tds = $row->getElementsByTagName('td');
                    if ($tds->length > $sidebar_index) {
                        $td = $tds->item($sidebar_index);
                        if ($td) {
                            $row->removeChild($td);
                        }
                    }
                }
            }
    
            // Extract updated HTML
            $body = $dom->getElementsByTagName('body')->item(0);
            $new_table = '';
            foreach ($body->childNodes as $child) {
                $new_table .= $dom->saveHTML($child);
            }
    
            return $new_table;
        }, $desc);
    
        return $desc;
    }
    public function render_template($initial_data = []) {
        // Ensure initial_data has required keys with defaults
        $initial_data = wp_parse_args($initial_data, [
            'items' => [],
            'total' => 0,
            'type' => 'publishers',
            'per_page' => get_option('posts_per_page', 10),
            'page' => 1,
            'letter' => 'all',
            'publisher_id' => null,
        ]);

            
        $selected_publisher = isset($_GET['publisher_id']) ? intval($_GET['publisher_id']) : $initial_data['publisher_id'];
        $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
        $letter = isset($_GET['letter']) && $_GET['letter'] !== '' ? sanitize_text_field($_GET['letter']) : $initial_data['letter'];
        $per_page = $initial_data['per_page'];
        $page = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : $initial_data['page']);
    
        // Ensure items are populated
        $items = $initial_data['items'];
        $total = $initial_data['total'];
        $type = $initial_data['type'];
    
        if ($selected_publisher && empty($items)) {
            $series_data = $this->get_series($selected_publisher, $page, $per_page, '', $letter);
            $items = $series_data['items'] ?? [];
            $total = $series_data['total'] ?? 0;
            $type = 'books';
        } elseif (!$selected_publisher && empty($items)) {
            $publisher_data = $this->get_publishers('', $page, $per_page, false, $letter);
            $items = $publisher_data['items'] ?? [];
            $total = $publisher_data['total'] ?? 0;
            $type = 'publishers';
        }
    
        // Hydrate into JS for AJAX pagination
        wp_localize_script('comicbookissues-script', 'comicbooks_fetchers_data', [
            'items' => $items,
            'type' => $type,
            'total' => $total,
            'per_page' => $per_page,
            'page' => $page,
            'letter' => $letter,
            'publisher_id' => $selected_publisher,
        ]);
    
        if ($is_ajax) {
            ob_start();
        }
    
        ?>
        <div id="book-container">
            <?php
            $total_pages = ceil($total / $per_page);
            $current_page = $page;
    
            if (!empty($items)): ?>
                <div class="<?php echo $type === 'publishers' ? 'publishers' : 'book'; ?>-wrapper">
                    <p>Showing <?php echo esc_html(count($items)); ?> of <?php echo esc_html($total); ?> <?php echo $type === 'publishers' ? 'publishers' : 'results'; ?><?php echo $letter && $letter !== 'all' ? ' starting with "' . esc_html($letter) . '"' : ''; ?></p>
                    <?php foreach ($items as $item): ?>
                        <?php if ($type === 'publishers' && $total > 0): ?>
                            <?php
                            $publisher_info = $this->get_publisher_info($item['id']);
                            if (!is_array($publisher_info) || !isset($publisher_info['id'], $publisher_info['name'])) {                     
                                continue;
                            }
                            ?>
                            <div class="publisher-item" data-publisher-id="<?php echo esc_attr($publisher_info['id']); ?>">
                                <div class="publisher-image">
                                    <img src="<?php echo esc_url(!empty($publisher_info['image']) ? $publisher_info['image'] : PUBLISHER_PLACEHOLDER_IMAGE_URL); ?>" alt="<?php echo esc_attr($publisher_info['name']); ?>" loading="lazy">
                                </div>
                                <div class="publisher-info">
                                    <h3><?php echo esc_html($publisher_info['name']); ?></h3>
                                    <p><strong>Founded:</strong> <?php echo esc_html(!empty($publisher_info['founded']) ? $publisher_info['founded'] : 'N/A'); ?></p>
                                    <p><?php echo esc_html($publisher_info['desc'] ?? 'No description available.'); ?></p>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php if ($total > 0): ?>
                                <div class="comic-title" data-series-id="<?php echo esc_attr($item['series_id']); ?>">
                                    <div class="comic-image">
                                        <img src="<?php echo esc_url(!empty($item['first_issue_image']) ? $item['first_issue_image'] : PUBLISHER_PLACEHOLDER_IMAGE_URL); ?>" alt="<?php echo esc_attr($item['name']); ?>" loading="lazy">
                                    </div>
                                    <div class="comic-info">
                                        <div class="comic-title-name"><?php echo esc_html($item['name']); ?></div>
                                        <div class="comic-title-meta">
                                            <p>Vol. <span><?php echo esc_html($item['volume']); ?></span></p>
                                            <p>Issues: <span><?php echo esc_html($item['issue_count']); ?></span></p>
                                            <p>Started: <span><?php echo esc_html($item['year_began']); ?></span></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <p>Page <?php echo esc_html($current_page); ?> of <?php echo esc_html($total_pages); ?></p>
                        <?php if ($current_page > 1): ?>
                            <button type="button" class="page-btn" data-page="<?php echo esc_attr($current_page - 1); ?>" data-letter="<?php echo esc_attr($letter); ?>">Previous</button>
                        <?php endif; ?>
                        <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                            <button type="button" class="page-btn <?php echo $i === $current_page ? 'active' : ''; ?>" data-page="<?php echo esc_attr($i); ?>" data-letter="<?php echo esc_attr($letter); ?>">
                                <?php echo esc_html($i); ?>
                            </button>
                        <?php endfor; ?>
                        <?php if ($current_page < $total_pages): ?>
                            <button type="button" class="page-btn" data-page="<?php echo esc_attr($current_page + 1); ?>" data-letter="<?php echo esc_attr($letter); ?>">Next</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p>No <?php echo $type === 'publishers' ? 'publishers' : 'series'; ?> found<?php echo $letter && $letter !== 'all' ? ' starting with "' . esc_html($letter) . '"' : ''; ?>.</p>
            <?php endif; ?>
        </div>
        <?php
    
        if ($is_ajax) {
            $html = ob_get_clean();
            wp_send_json_success([
                'html' => $html,
                'type' => $type,
                'total' => $total,
                'per_page' => $per_page,
                'page' => $page,
                'letter' => $letter,
                'publisher_id' => $selected_publisher,
            ]);
        }
    }
    
    public function render_issue_details() {
        $issue_id = isset($_GET['issue_id']) ? intval($_GET['issue_id']) : 0;
        $title_id = isset($_GET['title_id']) ? intval($_GET['title_id']) : 0;
    
        if (!$issue_id || !$title_id) {
            echo '<p>No issue or series selected. Please go back and select an issue.</p>';
            return;
        }
    
        // Fetch issue data from the Metron API
        $issue_url = "{$this->api_base}issue/{$issue_id}/";
        $issue = $this->api_get($issue_url);
    
        if (!$issue || empty($issue['number'])) {    
            echo '<p>Issue not found.</p>';
            return;
        }
    
        // Fetch series data for context
        $series_url = "{$this->api_base}series/{$title_id}/";
        $series = $this->api_get($series_url);
    
        if (!$series || empty($series['name'])) {        
            $series = ['name' => 'Unknown Series', 'publisher' => ['name' => 'N/A']];
        }
    
        // Fetch ComicVine info
        $metron_cv_id = $this->get_metron_cv_id($issue_id);
        $cv_issue = !empty($metron_cv_id) ? $this->get_comicvine_issue_info($metron_cv_id) : [];

        if (!empty($cv_issue['description'])) {    
            $description = $this->clean_cv_description($cv_issue['description']);
        } else {
            $description = (!empty($issue['desc']) ? $this->clean_cv_description($issue['desc']) : '');
        }
    
        $creators = !empty($cv_issue['person_credits']) ? $cv_issue['person_credits'] : $issue['credits'];
    
        $creator_infos = [];
    
        foreach ($creators as $person) {
            $name = $person['name'] ?? $person['creator'] ?? 'Unknown';
            $role = is_array($person['role']) ? implode(', ', array_column($person['role'], 'name')) : ($person['role'] ?? 'N/A');
            $creator_infos[] = $name . ' – ' . $role;
        }
    
        $creator_info_string = implode('; ', $creator_infos);
    
        // Determine the genre or concept fallback
        $genre_sources = [];
    
        if (!empty($series['genres']) && is_array($series['genres'])) {
            $genre_sources = array_column($series['genres'], 'name');
        } elseif (!empty($cv_issue['concept_credits']) && is_array($cv_issue['concept_credits'])) {
            $genre_sources = array_column($cv_issue['concept_credits'], 'name');
        }
    
        $genre_string = implode(', ', $genre_sources);
    

    
        ?>
        <div class="d-flex flex-column flex-md-row w-100">
            <main class="site-main flex-fill">
                <section id="body-content" class="page-section text-center">
                    <div class="comic-issue-details-container">
                        <header class="page-header">
                            <nav class="category-breadcrumbs">
                                <a href="<?php echo esc_url(home_url('/comic-books')); ?>">Publishers</a>
                                <span class="separator">&#10148;</span>
                                <span class="category"><a href="<?php echo esc_url(home_url('/comic-books/?publisher_id=' . $series['publisher']['id'])); ?>">
                                    <?php echo esc_html($series['publisher']['name']); ?>
                                </a></span>
                                <span class="separator">&#10148;</span>
                                <span class="category">
                                    <a href="<?php echo esc_url(home_url('/comic-books/issues/?title_id=' . $title_id)); ?>">
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
                                                    site_url('/comic-books/issue/')
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
    }

    public function render_issue_details_from_acf($post_id = null) {
        $post_id = $post_id ?: get_the_ID();
    
        // Get ACF fields
        $issue_id     = get_field('issue_id', $post_id);
        $title_id     = get_field('title_id', $post_id);
        $issue_date   = get_field('date_published', $post_id);
        $issue_number = get_field('issue_number', $post_id);
        $volume       = get_field('volume', $post_id);
        $image_url    = get_field('image_url', $post_id);        
        $creators     = get_field('creators', $post_id);        
        $genres       = get_field('genres', $post_id);
        $g_origin     = get_field('genre-origin', $post_id);
        $qty          = get_field('qty', $post_id);
    
        // Fallback if custom title or description needed
        $issue_title = get_the_title($post_id);
        $description = get_the_content(null, false, $post_id);
        ?>
        <header class="page-header">
            <nav class="category-breadcrumbs mb-4">          
                    <?php
                    $categories = get_the_category($post_id);
                    $breadcrumb_cats = [];
    
                    if ($categories && !is_wp_error($categories)) {
                        $deepest = null;
                        $max_depth = 0;
    
                        foreach ($categories as $cat) {
                            $depth = 0;
                            $parent = $cat->parent;
                            while ($parent != 0) {
                                $depth++;
                                $parent = get_category($parent)->parent;
                            }
                            if ($depth > $max_depth) {
                                $max_depth = $depth;
                                $deepest = $cat;
                            }
                        }
    
                        while ($deepest) {
                            $breadcrumb_cats[] = $deepest;
                            if ($deepest->parent == 0) break;
                            $deepest = get_category($deepest->parent);
                        }
    
                        $breadcrumb_cats = array_reverse($breadcrumb_cats);
                        $breadcrumb_cats = array_slice($breadcrumb_cats, 0, 3);
                        $breadcrumb_count = 0;
    
                        foreach ($breadcrumb_cats as $cat) { 
                            if($breadcrumb_count != 0):
                                echo '<span class="category">';
                            endif; 

                            echo '<a class="breadcrumb-item" href="' . esc_url(get_category_link($cat->term_id)) . '">' . esc_html($cat->name) . '</a>';
                            
                            if($breadcrumb_count != 0):
                                echo '</span>'; 
                            endif;   

                            echo '<span class="separator">&#10148;</span>';
                            $breadcrumb_count++;
                        }
                    }
                    ?>
                    <span class="current-category" aria-current="page"><?php echo esc_html($issue_title); ?></span>
            </nav>
            <h1 class="page-title">
                <span><?php echo esc_html($issue_title); ?></span>
            </h1>
        </header>
        <div class="comic-issue-details-container">    
            <div class="issue-details-header">
                <div>
                    <p>
                        <?php
                        $publisher = isset($breadcrumb_cats[1]) ? $breadcrumb_cats[1]->name : 'N/A';
                        echo esc_html($publisher); ?>&nbsp;&nbsp;/&nbsp;
                        <?php echo $issue_date ? esc_html(date('F Y', strtotime($issue_date))) : 'Unknown'; ?>
                    </p>
                </div>
            </div>
    
            <div class="issue-details-content">
                <?php if ($image_url): ?>
                    <img src="<?php echo esc_url($image_url); ?>" alt="Issue Cover" />
                <?php endif; ?>
    
                <div class="issue-notes-box">
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
                        if (is_user_logged_in()): ?>

                            <div class="d-flex flex-wrap align-items-end gap-3">
                                <!-- Collection Button -->
                                <div class="text-center">
                                    <button 
                                        class="add-to-collection <?php echo $in_collection ? 'in-collection' : ''; ?>" 
                                        style="<?php echo $in_collection ? 'background-color: red; color: white;' : ''; ?>"
                                        data-title="<?php echo esc_attr($issue_title); ?>"
                                        data-genres="<?php echo esc_attr($genres); ?>"
                                        data-genre-origin="<?php echo esc_attr($g_origin);  ?>"
                                        data-description="<?php echo esc_attr(wp_strip_all_tags($description)); ?>"
                                        data-issue-id="<?php echo esc_attr($issue_id); ?>"
                                        data-title-id="<?php echo esc_attr($title_id); ?>"
                                        data-publisher="<?php echo esc_attr($publisher); ?>"
                                        data-creators="<?php echo esc_attr($creators); ?>"
                                        data-date="<?php echo esc_attr($issue_date); ?>"
                                        data-volume="<?php echo esc_attr($volume); ?>"
                                        data-issue-number="<?php echo esc_attr($issue_number); ?>"
                                        data-image-url="<?php echo esc_url($image_url); ?>"
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
                                    site_url('/comic-books/issue/')
                                );
                                ?>
                                <button 
                                    class="add-to-wishlist"
                                    data-type="post"
                                    data-item-id="<?php echo esc_attr($issue_id); ?>"
                                    data-title="<?php echo esc_attr($issue_title); ?>"
                                    data-volume="<?php echo esc_attr($volume); ?>"
                                    data-item-url="<?php echo esc_url($url); ?>"
                                    data-image-url="<?php echo esc_url($image_url); ?>">
                                    Add to Wishlist
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
    
                    <div class="issue-section description-block">                        
                        <p><?php echo wp_kses_post($description); ?></p>
                    </div>

                    <?php if (!empty($genres)): ?>
                        <div class="issue-section genres">
                            <h4> 
                            <?php echo $g_origin === 'metron' ? 'Genres' : 'Concepts'; ?>
                            </h4>                      
                            <ul>
                                <?php 
                                    $genre_list = array_map('trim', explode(',', $genres));
                                    $last_index = count($genre_list) - 1;
                                    foreach ($genre_list as $index => $genre): 
                                        ?>
                                        <li>
                                            <?php echo esc_html($genre); ?>
                                            <?php if ($index !== $last_index): ?>,<?php endif; ?>
                                        </li>
                            <?php endforeach; ?>             
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($creators)): ?>
                        <div class="issue-section creators">
                            <h4>Creators</h4>                       
                            <ul>
                            <?php 
                                $creator_list = explode(';', $creators);
                                foreach ($creator_list as $creator):                                 
                                    ?>
                                    <li><?php 
                                    echo esc_html($creator); 
                                    ?></li>
                                <?php endforeach; ?>     
                            </ul>                        
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
}