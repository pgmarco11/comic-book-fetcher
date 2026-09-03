<?php
/**
 * Issue Item Template – Optimized
 * Uses data from issue_list cache + pre-fetched CV batch
 */

// Safety checks
if (!is_array($issue ?? null) || empty($issue['id'])) {
    return;
}

$metron_id = (int) $issue['id'];
$title_id = (int) ($title_id ?? 0);

if (!$metron_id || !$title_id) {
    return;
}

// Preserve the full series passed into this repeated template.
$item_series = is_array($series ?? null) ? $series : [];
$item_issue = $issue;

$cached_issue = get_transient(
    "metron:issue:{$title_id}_{$metron_id}"
);

if (
    is_array($cached_issue) &&
    (int) ($cached_issue['id'] ?? 0) === $metron_id &&
    (int) ($cached_issue['series']['id'] ?? 0) === $title_id
) {
    $item_issue = array_replace($issue, $cached_issue);
}

$item_series = array_replace(
    is_array($item_issue['series'] ?? null)
        ? $item_issue['series']
        : [],
    $item_series
);

$publisher = !empty($item_issue['publisher']['name'])
    ? $item_issue['publisher']
    : ($item_series['publisher'] ?? []);

// Batch data contains covers/mappings, not complete issue details.
$cv_cover_info = is_array($cv_issue ?? null) ? $cv_issue : [];

$metron_cv_id = (int) (
    $item_issue['cv_id'] ?? $cv_cover_info['cv_id'] ?? 0
);

$raw_number = tcs_catalog_text($item_issue['number'] ?? '');
$cv_details = [];

if ($metron_cv_id > 0) {
    $cv_cache_keys = [
        "tcs:cv_issue_basic:v2:{$metron_cv_id}",
        "cv_issue_full_{$metron_cv_id}",
    ];

    foreach ($cv_cache_keys as $key) {
        $cached_cv = get_transient($key);

        if (
            is_array($cached_cv) &&
            tcs_catalog_cv_matches(
                $cached_cv,
                $metron_cv_id,
                $raw_number,
                absint($item_series['cv_id'] ?? 0)
            )
        ) {
            $cv_details = $cached_cv;
            break;
        }
    }
}

$raw_description = tcs_catalog_description($item_issue);

if ($raw_description === '') {
    $raw_description = tcs_catalog_description($cv_details);
}

$description = $raw_description !== ''
    ? $comic_renderer->clean_cv_description($raw_description)
    : '';

$creator_info_string = tcs_format_creator_credits(
    is_array($item_issue['credits'] ?? null)
        ? $item_issue['credits']
        : []
);

if ($creator_info_string === '') {
    $creator_info_string = tcs_format_creator_credits(
        is_array($cv_details['person_credits'] ?? null)
            ? $cv_details['person_credits']
            : []
    );
}

$genre_string = implode(
    ', ',
    tcs_catalog_names($item_series['genres'] ?? [])
);

$placeholder_url = defined('PUBLISHER_PLACEHOLDER_IMAGE_URL')
    ? PUBLISHER_PLACEHOLDER_IMAGE_URL
    : '/wp-content/plugins/comic-book-fetcher/images/placeholder.png';

$display_image_url = ($item_issue['image'] ?? '')
    ?: (($cv_cover_info['comic_vine_image'] ?? '') ?: $placeholder_url);

$image_url = $display_image_url;
$cover_date = $item_issue['cover_date'] ?? '';

$formatted_date = $cover_date !== '' && strtotime($cover_date) !== false
    ? date('F Y', strtotime($cover_date))
    : 'N/A';

$raw_series_name = tcs_catalog_text($item_series['name'] ?? '');
$series_name = esc_html($raw_series_name);
$issue_number = esc_html($raw_number);
$issue_title = "#{$raw_number} — {$raw_series_name}";
$volume = $item_series['volume'] ?? '';
$highlights = $cv_details['_highlights'] ?? [];

$collection_status = $collection_status ?? [];

$in_collection = is_user_logged_in()
    && !empty($collection_status[$metron_id]['owned']);

$collection_post_id = $in_collection
    ? (int) ($collection_status[$metron_id]['post_id'] ?? 0)
    : 0;

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
                type="button"
                class="add-to-collection <?php echo $in_collection ? 'in-collection' : ''; ?>"
                style="<?php echo $in_collection ? 'background-color: red; color: white;' : ''; ?>"
                data-title="<?php echo esc_attr($raw_series_name); ?>"
                data-description="<?php echo esc_attr(wp_strip_all_tags($description)); ?>"
                data-issue-id="<?php echo esc_attr($metron_id); ?>"
                data-cv-issue-id="<?php echo esc_attr($metron_cv_id ?: ''); ?>"
                data-title-id="<?php echo esc_attr($title_id); ?>"
                data-creators="<?php echo esc_attr($creator_info_string); ?>"
                data-date="<?php echo esc_attr($cover_date); ?>"
                data-genres="<?php echo esc_attr($genre_string); ?>"
                data-publisher="<?php echo esc_attr($publisher['name'] ?? ''); ?>"
                data-volume="<?php echo esc_attr($volume); ?>"
                data-issue-number="<?php echo esc_attr($raw_number); ?>"
                data-image-url="<?php echo esc_url($image_url); ?>"
                <?php if ($in_collection): ?>
                    data-post-id="<?php echo esc_attr($collection_post_id); ?>"
                    data-action="remove"
                <?php else: ?>
                    data-action="add"
                <?php endif; ?>
            >
                <?php
                echo $in_collection
                    ? 'Remove from Collection'
                    : 'Add to My Collection';
                ?>
            </button>

                <button 
                    type="button"
                    class="add-to-wishlist"
                    data-type="post"
                    data-item-id="metron:issue:<?php echo esc_attr($metron_id); ?>"
                    data-issue-id="<?php echo esc_attr($metron_id); ?>"
                    data-cv-issue-id="<?php echo esc_attr($metron_cv_id); ?>"
                    data-title-id="<?php echo esc_attr($title_id); ?>"
                    data-title="<?php echo esc_attr($issue_title); ?>"
                    data-volume="<?php echo esc_attr($item_series['volume'] ?? ''); ?>"
                    data-item-url="<?php echo esc_url(add_query_arg(['issue_id' => $metron_id, 'title_id' => $title_id], home_url('/comic-catalog/issue/'))); ?>"
                    data-image-url="<?php echo esc_url($image_url); ?>">
                    Add to Wishlist
                </button>
            </div>
        <?php endif; ?>

</li>