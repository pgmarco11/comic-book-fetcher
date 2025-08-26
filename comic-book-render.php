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
    
        // Step 1: Remove <em> tags if they wrap the entire description
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
    
        // Step 6: Format with paragraph tags for non-<li> content
        $desc = wpautop($desc);
    
        // Step 7: Ensure no double <p> tags around <li> or <ol>
        $desc = preg_replace('/<p>(<(ol|ul|li)>.*?<\/\2>)<\/p>/is', '$1', $desc);
    
        // Step 8: Remove "Sidebar Location" column from tables
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

        $selected_publisher = isset($_GET['publisher_id']) ? intval($_GET['publisher_id']) : null;
        $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
        $letter = isset($_GET['letter']) && $_GET['letter'] !== '' ? sanitize_text_field($_GET['letter']) : 'all';
        $per_page = get_option('posts_per_page', 10);
    
        // Detect current page
        $page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        if ($page < 1) {
            $page = 1;
        }
    
        // Fetch publishers for dropdown
        $dropdown_publishers = $this->get_publishers('', 1, 1000, true)['items'] ?? [];
    
        $items = [];
        $total = 0;
        $type = 'publishers';
        $publisher_info = null;
    
        if ($selected_publisher) {
            $publisher_info = $this->get_publisher_info($selected_publisher);
            $series_data = $this->get_series($selected_publisher, $page, $per_page, '', $letter);
            $items = $series_data['items'] ?? [];
            $total = $series_data['total'] ?? 0;
            $type = 'books';
        } else {
            $publisher_data = $this->get_publishers('', $page, $per_page, false, $letter);
            $items = $publisher_data['items'] ?? [];
            $total = $publisher_data['total'] ?? 0;
        }
    
        // Hydrate into JS
        wp_localize_script('comicbooks-script', 'comicbooks_initial_data', [
            'items'        => $items,
            'type'         => $type,
            'total'        => $total,
            'per_page'     => $per_page,
            'page'         => $page,
            'letter'       => $letter,
            'publisher_id' => $selected_publisher,
        ]);
    
        if ($is_ajax) return; // Don't print full template on AJAX    
    
        ?>
        <div class="d-flex flex-column flex-md-row w-100">
            <main class="site-main flex-fill">
                <section id="body-content" class="page-section text-center">
                    <header class="page-header">
                        <nav class="category-breadcrumbs">
                            <a href="<?php echo esc_url(get_permalink()); ?>">Publishers</a>
                            <?php if (!empty($publisher_info) && !empty($publisher_info['name'])): ?>
                                <span class="separator">➤</span>
                                <span class="current-category"><?php echo esc_html($publisher_info['name']); ?></span>
                            <?php endif; ?>
                        </nav>
                        <h1 class="page-title"><span><?php the_title(); ?></span></h1>
                    </header>
    
                    <div class="page-filters">
                        <select name="publisher_id" id="publisher-select" aria-label="Select Publisher">
                            <option value="">Select a publisher</option>
                            <?php
                            if (empty($dropdown_publishers)) {
                                error_log("No publishers available for dropdown");
                                ?>
                                <option value="" disabled>No publishers available</option>
                                <?php
                            } else {
                                foreach ($dropdown_publishers as $publisher) {
                                    if (!is_array($publisher) || !isset($publisher['id'], $publisher['name'])) {
                                        error_log("Invalid publisher in dropdown: " . print_r($publisher, true));
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr($publisher['id']); ?>" <?php selected($selected_publisher, $publisher['id']); ?>>
                                        <?php echo esc_html($publisher['name']); ?>
                                    </option>
                                <?php } ?>
                            <?php } ?>
                        </select>
    
                        <div class="search-wrapper">
                            <input type="text" id="comic-search" placeholder="Search publishers..." aria-label="Search publishers">
                        </div>
                    </div>

                    <?php if (!empty($selected_publisher) && !empty($publisher_info)): ?>
    
                    <div class="publisher-info" style="display:flex;"> 
                            <div class="publisher-details">
                                <?php if (!empty($publisher_info['image'])): ?>
                                    <img src="<?php echo esc_url($publisher_info['image']); ?>" alt="<?php echo esc_attr($publisher_info['name']); ?> Logo" class="publisher-image" loading="lazy">
                                <?php endif; ?>
                                <div class="publisher-description">
                                    <h2><?php echo esc_html($publisher_info['name']); ?></h2>
                                    <p><strong>Founded:</strong> <?php echo !empty($publisher_info['founded']) ? esc_html($publisher_info['founded']) : 'N/A'; ?></p>
                                    <p><strong>Description:</strong> <?php echo !empty($publisher_info['desc']) ? esc_html($publisher_info['desc']) : 'No description available.'; ?></p>
                                </div>
                            </div>                  
                    </div>

                    <?php endif; ?>
    
                    <div id="letter-buttons" class="filters letter-filter">
                        <button type="button" class="letter-btn <?php echo ($letter === 'all') ? 'active' : ''; ?>" data-letter="all">All</button>
                        <?php foreach (range('A', 'Z') as $l): ?>
                            <button type="button" class="letter-btn <?php echo ($letter === $l) ? 'active' : ''; ?>" data-letter="<?php echo esc_attr($l); ?>"><?php echo $l; ?></button>
                        <?php endforeach; ?>
                        <button type="button" class="letter-btn <?php echo ($letter === '#') ? 'active' : ''; ?>" data-letter="#">#</button>
                    </div>
                      
                    <div id="book-container">
             
                        <?php 
                        $total_pages = ceil($total / $per_page); 
                        $current_page = $page;
                        
                        if (!empty($initial_data['items'])): ?>
                            <div class="<?php echo $initial_data['type'] === 'publishers' ? 'publishers' : 'book'; ?>-wrapper">
                                <p>Showing <?php echo esc_html(count($initial_data['items'])); ?> of <?php echo esc_html($total); ?> <?php echo $initial_data['type'] === 'publishers' ? 'publishers' : 'results'; ?><?php echo $letter && $letter !== 'all' ? ' starting with "' . esc_html($letter) . '"' : ''; ?></p>
                                <?php
                                if (!empty($initial_data['items']) && is_array($initial_data['items'])):
                                    foreach ($initial_data['items'] as $item):
                                        if ($initial_data['type'] === 'publishers'):
                                            $publisher_info = $this->get_publisher_info($item['id']);
                                            if (!is_array($publisher_info) || !isset($publisher_info['id'], $publisher_info['name'])) {
                                                error_log("Invalid publisher info for ID {$item['id']}: " . print_r($publisher_info, true));
                                                continue;
                                            }
                                            ?>
                                            <div class="publisher-item" data-publisher-id="<?php echo esc_attr($publisher_info['id']); ?>">
                                                <div class="publisher-image">
                                                    <img src="<?php echo esc_url(!empty($publisher_info['image']) ? $publisher_info['image'] : PUBLISHER_PLACEHOLDER_IMAGE_URL); ?>" alt="<?php echo esc_attr($publisher_info['name']); ?>" loading="lazy">
                                                </div>
                                                <div class="publisher-info" style="<?php echo !empty($publisher_info) ? 'display:block' : 'display:none'; ?>">
                                                    <h3><?php echo esc_html($publisher_info['name']); ?></h3>
                                                    <p><strong>Founded:</strong> <?php echo esc_html(!empty($publisher_info['founded']) ? $publisher_info['founded'] : 'N/A'); ?></p>
                                                    <p><?php echo esc_html($publisher_info['desc'] ?? 'No description available.'); ?></p>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="comic-title" data-series-id="<?php echo esc_attr($item['series_id']); ?>">
                                                <div class="comic-image">
                                                    <img src="<?php echo esc_url(!empty($item['first_issue_image']) ? $item['first_issue_image'] : PUBLISHER_PLACEHOLDER_IMAGE_URL); ?>" alt="<?php echo esc_attr($item['name']); ?>" data-loaded="false" loading="lazy">
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
                                    <?php endforeach; ?>
                                <?php endif; ?>
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
                        <?php elseif ($selected_publisher): ?>
                            <p>No series found for this publisher.</p>
                        <?php else: ?>
                            <p>Loading publishers...</p>
                        <?php endif; ?>
                    </div>
                </section>
            </main>
        </div>
        <?php      
    }

    public function render_issues_template($title_id, $header_only = false) {
        if (!$title_id) {
            error_log(date('c') . " render_issues_template: No title_id provided");
            echo '<p>No series selected. Please go back and select a series.</p>';
            return;
        }  
    
        $current_page = isset($_GET['issue_page']) ? max(1, intval($_GET['issue_page'])) : 1;
        $search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
        error_log(date('c') . " render_issues_template: title_id=$title_id, current_page=$current_page, search='$search'");
    
        // Fetch series and issue list data
        $data = $this->get_series_issues($title_id, $current_page, $search);
        
        if (!$data || !$data['series'] || !$data['issue_list'] || empty($data['issue_list']['results'])) {
            error_log(date('c') . " render_issues_template: Failed to fetch data or empty results for title_id=$title_id");
            echo '<p>Series or issues not found.</p>';
            return;
        }
    
        $series = $data['series'];
        $issue_list_data = $data['issue_list'];
        $issues = $issue_list_data['results'];
        $total_issues = $issue_list_data['count'];
        $per_page = 10;
        $total_pages = ceil($total_issues / $per_page);
        error_log(date('c') . " render_issues_template: total_issues=$total_issues, total_pages=$total_pages, results_count=" . count($issues));
      
    
        // Render header and series info
        ?>
        <header class="page-header">
            <nav class="category-breadcrumbs">  
                <a href="<?php echo esc_url(home_url('/comic-books')); ?>">Publishers</a>                           
                <span class="separator">&#10148;</span> 
                <span class="category"><a href="<?php echo esc_url(home_url('/comic-books/?publisher_id=' . $series['publisher']['id'])); ?>">
                    <?php echo esc_html($series['publisher']['name']); ?>
                </a></span>
                <span class="separator">&#10148;</span> 
                <span class="current-category"><?php echo esc_html($series['name'] ?? 'Comic Series'); ?></span>
            </nav>
            <h1 class="page-title"><span><?php echo esc_html($series['name'] ?? 'Comic Series'); ?></span></h1>
        </header>
        <div class="comic-series-container">
            <ul style="display: flex;gap: .5rem;justify-content: flex-start;">
                <li><?php echo esc_html($series['publisher']['name'] ?? 'N/A'); ?> &nbsp;/ </li>
                <li><?php echo esc_html($series['year_began'] ?? 'N/A'); ?> &mdash; <?php echo esc_html($series['year_end'] ?? 'Ongoing'); ?> &nbsp;/ </li>            
                <li>Volume <?php echo esc_html($series['volume'] ?? 'N/A'); ?> &nbsp;/ </li>
                <li><?php echo esc_html($series['issue_count'] ? $series['issue_count'] . ' issues' : 'N/A'); ?> </li>                             
            </ul>
            <ul>                          
                <li><strong>Series Type:</strong> <?php echo esc_html($series['series_type']['name'] ?? 'N/A'); ?></li>
                <?php 
                if (!empty($series['genres']) && is_array($series['genres'])) {
                    $genre_names = array_column($series['genres'], 'name');
                    echo '<li><strong>Genres:</strong> ' . esc_html(implode(', ', $genre_names)) . '</li>';
                }
                ?> 
            </ul> 
        </div>
        <?php
    
        if ($header_only) {
            return;
        }

        // Batch fetch images
        $series_ids = array_column($issues, 'series_id');
        $images = $this->get_series_images($series_ids);

        ?>
        <div class="comic-issues-container">
            <div class="issues-header">
                <h2>Issues</h2>
                <div class="search-wrapper">
                    <input type="text" id="issue-search" value="<?php echo esc_attr($search); ?>" placeholder="Search issues..." aria-label="Search issues">
                </div>
            </div>
            <div id="loading-spinner" style="display: none;">Loading...</div>
            <div id="issues-list">
                <ul class="issues-list">
                    <?php foreach ($issues as $issue): ?>
                        <li class="issue-item">
                            <a href="<?php echo esc_url(add_query_arg(['issue_id' => $issue['id'], 'title_id' => $title_id], home_url('/comic-books/issue/'))); ?>" class="issue-link">
                                <?php if (!empty($issue['image'])): ?>
                                    <img src="<?php echo esc_url($issue['image']); ?>" alt="<?php echo esc_attr($issue['issue'] ?? 'Issue Cover'); ?>" class="issue-image" loading="lazy">
                                <?php else: ?>
                                    <img src="<?php echo esc_url(PUBLISHER_PLACEHOLDER_IMAGE_URL); ?>" alt="Placeholder" class="issue-image" loading="lazy">
                                <?php endif; ?>
                                <div class="issue-info">
                                    <h5>#<?php echo esc_html($issue['number'] ?? 'N/A') . ' &mdash; ' . esc_html($issue['series']['name'] ?? 'Unknown'); ?></h5>
                                    <?php
                                    $date = $issue['cover_date'];
                                    $formatted = (!empty($date) && strtotime($date)) ? date('F Y', strtotime($date)) : 'N/A';
                                    ?>
                                    <h6><?php echo esc_html($formatted); ?></h6>
                                    <?php
                                    $metron_id = $issue['id'];
                                    $cv_info = null;
                                    $metron_cv_id = $this->get_metron_cv_id($metron_id);
    
                                    if (!empty($metron_cv_id)) {
                                        $cv_info = $this->get_comicvine_issue_info($metron_cv_id);
                                    }  

                                    $description = '';
                                    if (!empty($cv_info['description'])) {
                                        $description = $this->clean_cv_description($cv_info['description']);
                                    } elseif (!empty($issue['desc'])) {
                                        $description = $this->clean_cv_description($issue['desc']);
                                    }
                                    if (!empty($description)) {
                                        $description = wpautop($description);
                                    }
    
                                    $creators = !empty($cv_info['person_credits']) ? $cv_info['person_credits'] : ($issue['credits'] ?? []);
                                    $creator_infos = [];
                                    foreach ($creators as $person) {
                                        $name = $person['name'] ?? $person['creator'] ?? 'Unknown';
                                        $role = is_array($person['role']) ? implode(', ', array_column($person['role'], 'name')) : ($person['role'] ?? 'N/A');
                                        $creator_infos[] = $name . ' – ' . $role;
                                    }
                                    $creator_info_string = implode('; ', $creator_infos);
    
                                    $genre_sources = [];
                                    $genres_origin = 'metron';
                                    if (!empty($series['genres']) && is_array($series['genres'])) {
                                        $genre_sources = array_column($series['genres'], 'name');
                                    } elseif (!empty($cv_info['concept_credits']) && is_array($cv_info['concept_credits'])) {
                                        $genre_sources = array_column($cv_info['concept_credits'], 'name');
                                        $genres_origin = 'cv';
                                    }
                                    $genre_string = implode(', ', $genre_sources);
    
                                    if (!empty($cv_info['_highlights'])) {
                                        echo '<div class="cv-highlights">';
                                        foreach ($cv_info['_highlights'] as $note) {
                                            echo '<p class="cv-note">' . esc_html($note) . '</p>';
                                        }
                                        echo '</div>';
                                    }
                                    ?>
                                </div>
                            </a>
                            <?php
                            $in_collection = false;
                            $collection_post_id = 0;
                            $issue_id = $metron_id;
                            $issue_title = $issue['series']['name'] . ' #' . $issue['number'];
                            $date_raw = $cv_info['cover_date'] ?? $issue['cover_date'] ?? '';
    
                            if (is_user_logged_in()) {
                                $existing_posts = get_posts([
                                    'post_type' => 'post',
                                    'author' => get_current_user_id(),
                                    'posts_per_page' => 1,
                                    'meta_query' => [
                                        [
                                            'key' => 'issue_id',
                                            'value' => $issue_id,
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
                                <div class="d-flex flex-nowrap align-items-end gap-3">
                                    <div class="text-center">
                                        <button 
                                            class="add-to-collection <?php echo $in_collection ? 'in-collection' : ''; ?>" 
                                            style="<?php echo $in_collection ? 'background-color: red; color: white;' : ''; ?>"
                                            data-title="<?php echo esc_attr($issue_title); ?>"
                                            data-genres="<?php echo esc_attr($genre_string); ?>"
                                            data-genre-origin="<?php echo $genres_origin; ?>"
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
                                    <button 
                                        class="add-to-wishlist"
                                        data-type="post"
                                        data-item-id="<?php echo esc_attr($metron_cv_id); ?>"
                                        data-title="<?php echo esc_attr($issue_title); ?>"
                                        data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
                                        data-item-url="<?php echo esc_url(add_query_arg(['issue_id' => $issue_id, 'title_id' => $title_id], site_url('/comic-books/issue/'))); ?>"
                                        data-image-url="<?php echo esc_url($issue['image']); ?>">
                                        Add to Wishlist
                                    </button>
                                </div>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                </ul>
            </div>
            <div id="pagination-wrapper">
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <p>Page <?php echo esc_html($current_page); ?> of <?php echo esc_html($total_pages); ?></p>
                        <?php if ($current_page > 1): ?>
                            <button type="button" class="page-btn" data-page="<?php echo esc_attr($current_page - 1); ?>">Previous</button>
                        <?php endif; ?>
                        <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                            <button type="button" class="page-btn <?php echo $i === $current_page ? 'active' : ''; ?>" data-page="<?php echo esc_attr($i); ?>">
                                <?php echo esc_html($i); ?>
                            </button>
                        <?php endfor; ?>
                        <?php if ($current_page < $total_pages): ?>
                            <button type="button" class="page-btn" data-page="<?php echo esc_attr($current_page + 1); ?>">Next</button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p>Debug: No pagination needed (totalPages=<?php echo esc_html($total_pages); ?>)</p>
                <?php endif; ?>
            </div>
        <?php
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
            error_log(date('c') . " ERROR: Failed to fetch issue for issue_id=$issue_id");
            echo '<p>Issue not found.</p>';
            return;
        }
    
        // Fetch series data for context
        $series_url = "{$this->api_base}series/{$title_id}/";
        $series = $this->api_get($series_url);
    
        if (!$series || empty($series['name'])) {
            error_log(date('c') . " ERROR: Failed to fetch series for title_id=$title_id");
            $series = ['name' => 'Unknown Series', 'publisher' => ['name' => 'N/A']];
        }
    
        // Fetch ComicVine info
        $metron_cv_id = $this->get_metron_cv_id($issue_id);
        $cv_issue = !empty($metron_cv_id) ? $this->get_comicvine_issue_info($metron_cv_id) : [];

        $cleaned_description = !empty($cv_issue['description']) ? 
        $this->clean_cv_description($cv_issue['description']) : (!empty($issue['desc']) ? 
        $this->clean_cv_description($issue['desc']) : '');

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

        if(!empty($cleaned_description)){
            // Remove extra quotes around <em>/<strong> in <li>
            $description = preg_replace('/<li>"?(<[^>]+>.*?<\/[^>]+>)"?/', '<li>$1', $cleaned_description);

            // Optionally wrap long text in <p> if needed
            $description = wpautop($description); 
        }

    
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
                            <?php if (!empty($issue['image'])): ?>
                                <img src="<?php echo esc_url($issue['image']); ?>" alt="<?php echo esc_attr($issue['issue'] ?? 'Issue Cover'); ?>" loading="lazy">
                            <?php else: ?>
                                <img src="<?php echo esc_url(PUBLISHER_PLACEHOLDER_IMAGE_URL); ?>" alt="Placeholder" class="issue-image" loading="lazy">
                            <?php endif; ?>

                            <!-- <pre>
                                        <span>$cv_issue</span>
                            <?php //print_r($cv_issue); ?>
                            <span>$issue</span>
                                        <?php //print_r($issue); ?>
                                    </pre> -->

                            

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
                                                        data-genre-origin="<?php echo empty($series['genres']) ? 'cv' : 'metron';  ?>"
                                                        data-description="<?php echo esc_html($description ?? $cleaned_description); ?>"
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
                                            if (!empty($cv_issue['description'])) {                                          

                                                echo wp_kses_post($description);
                                            
                                            } else {
                                                echo '<p>' . esc_html($issue['desc']) . '</p>';
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