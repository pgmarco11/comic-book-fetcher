<?php
/**
 * Issue Item Template – Optimized
 * Uses data from issue_list cache + pre-fetched CV batch
 */

// Safety checks
if (empty($issue) || empty($issue['id']) || empty($series)) {
    return;
}

$metron_id = (int) ($issue['id'] ?? 0);
$title_id  = (int) ($title_id ?? 0);

if (!$metron_id || !$title_id) {
    return;
}

$cv_issue = isset($cv_issue) && is_array($cv_issue)
    ? $cv_issue
    : [];
    
$collection_status = $collection_status ?? [];

$issue_number = esc_html($issue['number'] ?? 'N/A');
$cover_date   = $issue['cover_date'] ?? '';

$placeholder_url = defined( 'PUBLISHER_PLACEHOLDER_IMAGE_URL' )
    ? PUBLISHER_PLACEHOLDER_IMAGE_URL
    : '/wp-content/plugins/comic-book-fetcher/images/placeholder.png';

$metron_issue_image_url = ! empty( $issue['image'] )
    ? $issue['image']
    : '';

$comic_vine_image_url = !empty($cv_issue['comic_vine_image'])
    ? $cv_issue['comic_vine_image']
    : '';

$metron_cv_id = $cv_issue['cv_id']
    ?? $issue['cv_id']
    ?? '';

$display_image_url = $comic_vine_image_url
    ?: ( $metron_issue_image_url ?: $placeholder_url );

$image_url = $display_image_url;

$formatted_date = (!empty($cover_date) && strtotime($cover_date))
    ? date('F Y', strtotime($cover_date))
    : 'N/A';

$series_name = esc_html($series['name'] ?? 'Unknown Series');
$issue_title = "#{$issue_number} — {$series_name}";

$highlights = $cv_issue['_highlights'] ?? [];

$volume = $series['volume'] ?? '';

$in_collection = false;
$collection_post_id = 0;

if (is_user_logged_in() && !empty($collection_status[$metron_id]['owned'])) {
    $in_collection = true;
    $collection_post_id = $collection_status[$metron_id]['post_id'] ?? 0;
}


?>

<li class="issue-item" 
    data-title-id="<?php echo esc_attr($title_id); ?>" 
    data-issue-id="<?php echo esc_attr($metron_id); ?>">

    <a href="<?php 
        echo esc_url( home_url( '/comic-catalog/issue/' ) . '?' . http_build_query([
            'issue_id' => $metron_id,
            'title_id' => $title_id
        ]));
    ?>" class="issue-link">

    <img
        src="<?php echo esc_url( $display_image_url ); ?>"
        alt="<?php echo esc_attr($issue_title); ?>"
        class="issue-image"
        width="91"
        height="140"
        loading="lazy"
        decoding="async"
        fetchpriority="low"
        data-loaded="true">

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
                data-issue-id="<?php echo esc_attr($metron_id); ?>"
                data-cv-issue-id="<?php echo esc_attr($metron_cv_id); ?>"
                data-title-id="<?php echo esc_attr($title_id); ?>"
                data-title="<?php echo esc_attr($issue_title); ?>"
                data-publisher="<?php echo esc_attr($series['publisher']['name'] ?? 'Unknown'); ?>"
                data-date="<?php echo esc_attr($cover_date); ?>"
                data-volume="<?php echo esc_attr($volume); ?>"
                data-issue-number="<?php echo esc_attr($issue_number); ?>"
                data-image-url="<?php echo esc_url($image_url); ?>"
                data-action="<?php echo $in_collection ? 'remove' : 'add'; ?>"
                <?php if ($in_collection): ?>
                    data-post-id="<?php echo esc_attr($collection_post_id); ?>"
                <?php endif; ?>>
                <?php echo $in_collection ? 'Remove from Collection' : 'Add to My Collection'; ?>
            </button>

                <button 
                    class="add-to-wishlist"
                    data-type="post"
                    data-issue-id="<?php echo esc_attr($metron_id); ?>"
                    data-cv-issue-id="<?php echo esc_attr($metron_cv_id); ?>"
                    data-title-id="<?php echo esc_attr($title_id); ?>"
                    data-title="<?php echo esc_attr($issue_title); ?>"
                    data-volume="<?php echo esc_attr($series['volume'] ?? ''); ?>"
                    data-item-url="<?php echo esc_url(add_query_arg(['issue_id' => $metron_id, 'title_id' => $title_id], home_url('/comic-catalog/issue/'))); ?>"
                    data-image-url="<?php echo esc_url($image_url); ?>">
                    Add to Wishlist
                </button>
            </div>
        <?php endif; ?>

</li>