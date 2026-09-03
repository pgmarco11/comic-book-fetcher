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

// Normalize structure
$series    = $issue['series'] ?? [];
$publisher = $issue['publisher'] ?? [];   

$cv_issue = isset($cv_issue) && is_array($cv_issue)
    ? $cv_issue
    : [];    
    
$collection_status = $collection_status ?? [];

$issue_number = esc_html($issue['number'] ?? 'N/A');
$cover_date   = $issue['cover_date'] ?? '';
$publisher = $issue['publisher'] ?? [];   
$series    = $issue['series'] ?? [];

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


// Genres (Metron -> CV concepts)
if (!empty($series['genres'])) {
        $genre_sources = array_column($series['genres'], 'name');
} elseif (!empty($cv_issue['concept_credits'])) {
        $genre_sources = array_column($cv_issue['concept_credits'], 'name');
    } else {
        $genre_sources = [];
}

$genre_string = implode(', ', $genre_sources);   

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

$display_image_url = $metron_issue_image_url
    ?: ( $comic_vine_image_url ?: $placeholder_url );

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

$description = $comic_renderer->clean_cv_description( $raw_description );

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
                data-title="<?php echo esc_attr($issue_title); ?>"                                                    
                data-description="<?php echo esc_attr(wp_strip_all_tags($description)); ?>"
                data-issue-id="<?php echo esc_attr($issue_id); ?>"
                data-title-id="<?php echo esc_attr($title_id); ?>"
                data-creators="<?php echo esc_attr($creator_info_string ?: ''); ?>"
                data-date="<?php echo esc_attr($date_raw); ?>"
                data-genres="<?php echo esc_attr($genre_string); ?>"
                data-publisher="<?php echo esc_attr($publisher['name'] ?? 'Unknown'); ?>"
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

                <button 
                    type="button"
                    class="add-to-wishlist"
                    data-type="post"
                    data-item-id="metron:issue:<?php echo esc_attr($metron_id); ?>"
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