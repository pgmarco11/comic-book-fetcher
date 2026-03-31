<?php
/**
 * Issue Item Template – Optimized
 * Uses data from issue_list cache + pre-fetched CV batch
 * 
 */

if (!defined('ABSPATH')) {
    exit;
}

// Safety checks
if (empty($issue) || empty($issue['id']) || empty($series)) {
    return;
}

$metron_id     = (int) ($issue['id'] ?? 0);
$title_id      = (int) ($title_id ?? 0);
//change issue data structure to match what the template expects for CV info (if available)    
$cv_issue      = $cv_issue ?? [];           // from batch
$collection_status = $collection_status ?? [];

if (!$metron_id || !$title_id) {
    return;
}

// Basic issue data (comes from cached /issue_list/)
$issue_number   = esc_html($issue['number'] ?? 'N/A');
$issue_name     = esc_html($issue['issue'] ?? '');
$cover_date     = $issue['cover_date'] ?? '';
$image_url      = !empty($issue['image']) 
    ? esc_url($issue['image']) 
    : (defined('PUBLISHER_PLACEHOLDER_IMAGE_URL') ? esc_url(PUBLISHER_PLACEHOLDER_IMAGE_URL) : '');

$formatted_date = (!empty($cover_date) && strtotime($cover_date)) 
    ? date('F Y', strtotime($cover_date)) 
    : 'N/A';

$series_name    = esc_html($series['name'] ?? 'Unknown Series');
$issue_title    = "#{$issue_number} — {$series_name}";

// CV-enriched data (from batch – may be empty on first load)
$description = $cv_issue['description'] 
    ?? $issue['description'] 
    ?? $issue['desc'] 
    ?? 'No description available.';

$highlights  = $cv_issue['_highlights'] ?? [];

// Creators (CV preferred)
$creators = $cv_issue['person_credits'] ?? $issue['credits'] ?? [];
$creator_infos = [];
foreach ($creators as $p) {
    $name = $p['name'] ?? $p['creator'] ?? 'Unknown';
    $role = is_array($p['role'] ?? null) 
        ? implode(', ', array_column($p['role'], 'name')) 
        : ($p['role'] ?? 'N/A');
    $creator_infos[] = "$name – $role";
}
$creator_info_string = implode("\n", $creator_infos);

// Genres
$genre_sources = [];
if (!empty($series['genres']) && is_array($series['genres'])) {
    $genre_sources = array_column($series['genres'], 'name');
} elseif (!empty($cv_issue['concept_credits'])) {
    $genre_sources = array_column($cv_issue['concept_credits'], 'name');
}
$genre_string = implode(', ', $genre_sources);

// Collection status (pre-fetched)
$in_collection = false;
$collection_post_id = 0;
if (is_user_logged_in() && !empty($collection_status[$metron_id]['owned'])) {
    $in_collection = true;
    $collection_post_id = $collection_status[$metron_id]['post_id'] ?? 0;
}

$metron_cv_id = $cv_issue['id'] ?? $issue['cv_id'] ?? '';
?>

<li class="issue-item" 
    data-title-id="<?php echo esc_attr($title_id); ?>" 
    data-issue-id="<?php echo esc_attr($metron_id); ?>" 
    data-cv-id="<?php echo esc_attr($metron_cv_id); ?>">

    <a href="<?php 
        echo esc_url( home_url( '/comic-catalog/issue/' ) . '?' . http_build_query([
            'issue_id' => $metron_id,
            'title_id' => $title_id
        ]));
    ?>" class="issue-link">

        <?php if ($image_url): ?>
            <img src="<?php echo $image_url; ?>" 
                 alt="<?php echo esc_attr($issue_title); ?>" 
                 class="issue-image" 
                 loading="lazy">
        <?php endif; ?>

        <div class="issue-info">
            <h3>#<?php echo $issue_number; ?> — <?php echo $series_name; ?></h3>
            <h4><?php echo esc_html($formatted_date); ?></h4>

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
            <button 
                class="add-to-collection <?php echo $in_collection ? 'in-collection' : ''; ?>" 
                style="<?php echo $in_collection ? 'background-color: red; color: white;' : ''; ?>"
                data-title="<?php echo esc_attr($issue_title); ?>"
                data-genres="<?php echo esc_attr($genre_string); ?>"
                data-description="<?php echo esc_attr(wp_strip_all_tags($description)); ?>"
                data-issue-id="<?php echo esc_attr($metron_id); ?>"
                data-title-id="<?php echo esc_attr($title_id); ?>"
                data-publisher="<?php echo esc_attr($series['publisher']['name'] ?? 'Unknown'); ?>"
                data-creators="<?php echo esc_attr($creator_info_string); ?>"
                data-date="<?php echo esc_attr($cover_date); ?>"
                data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
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

            <button 
                class="add-to-wishlist"
                data-type="post"
                data-item-id="<?php echo esc_attr($metron_cv_id); ?>"
                data-title="<?php echo esc_attr($issue_title); ?>"
                data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
                data-item-url="<?php echo esc_url(add_query_arg(['issue_id' => $metron_id, 'title_id' => $title_id], home_url('/comic-catalog/issue/'))); ?>"
                data-image-url="<?php echo esc_url($image_url); ?>">
                Add to Wishlist
            </button>
        </div>
    <?php endif; ?>
</li>