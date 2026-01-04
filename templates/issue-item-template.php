<?php
/**
 * Template part for rendering a single comic issue item
 * Used in comic-book-issues.php and ajax_load_issues
 */
if (!defined('ABSPATH')) {
    exit;
}

// Force require critical context variables from parent scope
if (!isset($series) || !is_array($series) || empty($series['name'])) {
    error_log("issue-item-template: Missing or invalid \$series");
    return;
}
if (!isset($title_id) || !$title_id) {
    error_log("issue-item-template: Missing \$title_id");
    return;
}
if (!isset($issue) || !is_array($issue) || empty($issue['id'])) {
    error_log("issue-item-template: Missing or invalid \$issue");
    return;
}

// Optional: make cv_info_batch & collection_status available too
$cv_info_batch     = $cv_info_batch ?? [];
$collection_status = $collection_status ?? [];

if (empty($issue) || empty($series) || !$title_id) {
    error_log("issue-item-template: Missing critical data");
    return;
}

$metron_id = $issue['id'] ?? 0;
if (!$metron_id) {
    error_log("issue-item-template: Missing issue ID");
    return;
}

static $rendered_issue_ids = [];
if (isset($rendered_issue_ids[$metron_id])) return;
$rendered_issue_ids[$metron_id] = true;

$issue_title = esc_html($series['name'] ?? 'Unknown') . ' #' . esc_html($issue['number'] ?? 'N/A');
$date = $issue['cover_date'] ?? '';
$formatted_date = (!empty($date) && strtotime($date)) ? date('F Y', strtotime($date)) : 'N/A';
$image_url = !empty($issue['image']) ? esc_url($issue['image']) : (defined('PUBLISHER_PLACEHOLDER_IMAGE_URL') ? esc_url(PUBLISHER_PLACEHOLDER_IMAGE_URL) : '#');

error_log("issue-item-template: Rendering issue ID=$metron_id");

// **NEW: Use pre-fetched CV batch - NO individual API calls**
$cv_data = $cv_info_batch[$metron_id] ?? null;
$metron_cv_id = $cv_data['id'] ?? '';
$description = $cv_data['description'] ?? ($issue['desc'] ?? 'No description available.');
$highlights = $cv_data['_highlights'] ?? [];

$creators = $issue['credits'] ?? [];
$creator_infos = [];
foreach ($creators as $person) {
    $name = $person['name'] ?? $person['creator'] ?? 'Unknown';
    $role = is_array($person['role']) ? implode(', ', array_column($person['role'], 'name')) : ($person['role'] ?? 'N/A');
    $creator_infos[] = $name . ' – ' . $role;
}
$creator_info_string = implode('; ', $creator_infos);

$genres_origin = 'metron';
$genre_sources = !empty($series['genres']) && is_array($series['genres']) ? array_column($series['genres'], 'name') : [];
$genre_string = implode(', ', $genre_sources);

// **NEW: Use pre-fetched collection status**
$in_collection = false;
$collection_post_id = 0;

if (
    is_user_logged_in()
    && !empty($collection_status[$metron_id]['owned'])
) {
    $in_collection = true;
    $collection_post_id = $collection_status[$metron_id]['post_id'];
}
?>
<!-- **ORIGINAL HTML STRUCTURE - UNCHANGED** -->
<li class="issue-item" data-title-id="<?php echo esc_attr($title_id); ?>" data-issue-id="<?php echo esc_attr($metron_id); ?>" data-cv-id="<?php echo esc_attr($metron_cv_id); ?>">
    <a href="<?php echo esc_url(add_query_arg(['issue_id' => $metron_id, 'title_id' => $title_id], home_url('/comic-books/issue/'))); ?>" class="issue-link">
        <?php if ($image_url): ?>
            <img src="<?php echo $image_url; ?>" alt="<?php echo esc_attr($issue_title); ?>" class="issue-image" loading="lazy" data-loaded="true">
        <?php else: ?>
            <img src="<?php echo esc_url(defined('PUBLISHER_PLACEHOLDER_IMAGE_URL') ? PUBLISHER_PLACEHOLDER_IMAGE_URL : ''); ?>" alt="Placeholder" class="issue-image" loading="lazy" data-loaded="true">
        <?php endif; ?>
        <div class="issue-info">
            <h5>#<?php echo esc_html($issue['number'] ?? 'N/A') . ' — ' . esc_html($series['name'] ?? 'Unknown'); ?></h5>
            <h6><?php echo esc_html($formatted_date); ?></h6>
            
            <!-- **NEW: Server-rendered CV highlights (from batch) - No AJAX** -->
            <?php if (!empty($highlights)): ?>
                <div class="cv-highlights">
                    <?php foreach ($highlights as $highlight): ?>
                        <p class="cv-note"><?php echo esc_html($highlight); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </a>
    <?php if (is_user_logged_in()): ?>
        <div class="d-flex flex-nowrap align-items-end gap-3">
            <div class="text-center">
                <button 
                    class="add-to-collection <?php echo $in_collection ? 'in-collection' : ''; ?>" 
                    style="<?php echo $in_collection ? 'background-color: red; color: white;' : ''; ?>"
                    data-title="<?php echo esc_attr($issue_title); ?>"
                    data-genres="<?php echo esc_attr($genre_string); ?>"
                    data-genre-origin="<?php echo esc_attr($genres_origin); ?>"
                    data-description="<?php echo esc_attr($description); ?>"
                    data-issue-id="<?php echo esc_attr($metron_id); ?>"
                    data-title-id="<?php echo esc_attr($title_id); ?>"
                    data-publisher="<?php echo esc_attr($series['publisher']['name'] ?? 'Unknown'); ?>"
                    data-creators="<?php echo esc_attr($creator_info_string); ?>"
                    data-date="<?php echo esc_attr($date); ?>"
                    data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
                    data-issue-number="<?php echo esc_attr($issue['number'] ?? 'N/A'); ?>"
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
            <button 
                class="add-to-wishlist"
                data-type="post"
                data-item-id="<?php echo esc_attr($metron_cv_id); ?>"
                data-title="<?php echo esc_attr($issue_title); ?>"
                data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
                data-item-url="<?php echo esc_url(add_query_arg(['issue_id' => $metron_id, 'title_id' => $title_id], home_url('/comic-books/issue/'))); ?>"
                data-image-url="<?php echo esc_url($image_url); ?>">
                Add to Wishlist
            </button>
        </div>
    <?php endif; ?>
</li>
<!-- **REMOVED: Original inline <script> - No more per-issue AJAX** -->