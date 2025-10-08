<?php
/**
 * Template part for rendering a single comic issue item
 * Used in comic-book-issues.php and ajax_load_issues
 *
 * @param array $issue Issue data from the API
 * @param array $series Series data from the API
 * @param int $title_id Series ID
 * @param ComicRenderer $comic_renderer Instance of ComicRenderer class
 * @param array $cv_info_batch Cached ComicVine data for issues (optional)
 * @param array $collection_status Cached collection status for issues (optional)
 */

if (!defined('ABSPATH')) {
    exit;
}

$issue = $issue ?? [];
$series = $series ?? [];
$title_id = $title_id ?? 0;
$comic_renderer = $comic_renderer ?? null;
$cv_info_batch = $cv_info_batch ?? [];
$collection_status = $collection_status ?? [];

if (empty($issue) || empty($series) || !$title_id || !$comic_renderer) {
    error_log("issue-item-template: Missing required data - issue=" . print_r($issue, true) . ", series=" . print_r($series, true) . ", title_id=$title_id");
    return;
}

$metron_id = $issue['id'] ?? 0;
$issue_title = esc_html($series['name'] ?? 'Unknown') . ' #' . esc_html($issue['number'] ?? 'N/A');
$date = $issue['cover_date'] ?? '';
$formatted_date = (!empty($date) && strtotime($date)) ? date('F Y', strtotime($date)) : 'N/A';

// Log image URL for debugging
error_log("issue-item-template: Rendering issue ID=$metron_id, image=" . ($issue['image'] ?? 'N/A'));

// ComicVine integration
$cv_info = null;
$description = '';
$creators = [];
$genre_string = '';
$metron_cv_id = method_exists($comic_renderer, 'get_metron_cv_id') ? $comic_renderer->get_metron_cv_id($metron_id) : '';

if (!empty($metron_cv_id)) {
    $cv_info = $cv_info_batch[$metron_id] ?? (method_exists($comic_renderer, 'get_comicvine_issue_info') ? $comic_renderer->get_comicvine_issue_info($metron_cv_id) : null);
    if (!empty($cv_info['description']) && method_exists($comic_renderer, 'clean_cv_description')) {
        $description = $comic_renderer->clean_cv_description($cv_info['description']);
    }
    $creators = !empty($cv_info['person_credits']) ? $cv_info['person_credits'] : ($issue['credits'] ?? []);
} else {
    $description = !empty($issue['desc']) && method_exists($comic_renderer, 'clean_cv_description') 
        ? $comic_renderer->clean_cv_description($issue['desc']) 
        : ($issue['desc'] ?? '');
    $creators = $issue['credits'] ?? [];
}

// Creator credits
$creator_infos = [];
foreach ($creators as $person) {
    $name = $person['name'] ?? $person['creator'] ?? 'Unknown';
    $role = is_array($person['role']) ? implode(', ', array_column($person['role'], 'name')) : ($person['role'] ?? 'N/A');
    $creator_infos[] = $name . ' – ' . $role;
}
$creator_info_string = implode('; ', $creator_infos);

// Genres
$genres_origin = 'metron';
$genre_sources = [];
if (!empty($series['genres']) && is_array($series['genres'])) {
    $genre_sources = array_column($series['genres'], 'name');
} elseif (!empty($cv_info['concept_credits']) && is_array($cv_info['concept_credits'])) {
    $genre_sources = array_column($cv_info['concept_credits'], 'name');
    $genres_origin = 'cv';
}
$genre_string = implode(', ', $genre_sources);

// Collection status
$in_collection = false;
$collection_post_id = 0;
$issue_id = $metron_id;
$date_raw = $cv_info['cover_date'] ?? $issue['cover_date'] ?? '';
if (is_user_logged_in()) {
    $in_collection = isset($collection_status[$issue_id]);
    $collection_post_id = $collection_status[$issue_id] ?? 0;
}

?>

<li class="issue-item" data-title-id="<?php echo esc_attr($title_id); ?>">
    <a href="<?php echo esc_url(add_query_arg(['issue_id' => $issue['id'], 'title_id' => $title_id], home_url('/comic-books/issue/'))); ?>" class="issue-link">
        <?php if (!empty($issue['image'])):    
            ?>
            <img src="<?php echo esc_url($issue['image']); ?>" alt="<?php echo esc_attr($issue_title); ?>" class="issue-image" loading="lazy" data-loaded="true">
        <?php else: 
            ?>
            <img src="<?php echo esc_url(defined('PUBLISHER_PLACEHOLDER_IMAGE_URL') ? PUBLISHER_PLACEHOLDER_IMAGE_URL : ''); ?>" alt="Placeholder" class="issue-image" loading="lazy" data-loaded="true">
        <?php endif; ?>
        <div class="issue-info">
            <h5>#<?php echo esc_html($issue['number'] ?? 'N/A') . ' — ' . esc_html($series['name'] ?? 'Unknown'); ?></h5>
            <h6><?php echo esc_html($formatted_date); ?></h6>
            <?php if (!empty($cv_info['_highlights'])): ?>
                <div class="cv-highlights">
                    <?php foreach ($cv_info['_highlights'] as $note): ?>
                        <p class="cv-note"><?php echo esc_html($note); ?></p>
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
                    data-issue-id="<?php echo esc_attr($issue_id); ?>"
                    data-title-id="<?php echo esc_attr($title_id); ?>"
                    data-publisher="<?php echo esc_attr($series['publisher']['name'] ?? 'Unknown'); ?>"
                    data-creators="<?php echo esc_attr($creator_info_string); ?>"
                    data-date="<?php echo esc_attr($date_raw); ?>"
                    data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
                    data-issue-number="<?php echo esc_attr($issue['number'] ?? 'N/A'); ?>"
                    data-image-url="<?php echo esc_url($issue['image'] ?? ''); ?>"
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
                data-item-url="<?php echo esc_url(add_query_arg(['issue_id' => $issue_id, 'title_id' => $title_id], home_url('/comic-books/issue/'))); ?>"
                data-image-url="<?php echo esc_url($issue['image'] ?? ''); ?>">
                Add to Wishlist
            </button>
        </div>
    <?php endif; ?>
</li>