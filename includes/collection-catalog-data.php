<?php
/** Catalog metadata for a selected collection issue. Loaded by comic-collection.php. */
defined('ABSPATH') || exit;

function tcs_catalog_text($value): string {
    return is_scalar($value) ? trim(sanitize_text_field((string) $value)) : '';
}

function tcs_catalog_names($values): array {
    $names = [];
    foreach (is_array($values) ? $values : [] as $value) {
        $name = tcs_catalog_text(is_array($value) ? ($value['name'] ?? '') : $value);
        if ($name !== '') $names[] = $name;
    }
    return array_values(array_unique($names));
}

function tcs_catalog_description(array $record): string {
    foreach (['description', 'desc', 'deck'] as $key) {
        $value = isset($record[$key]) && is_string($record[$key]) ? trim($record[$key]) : '';
        $plain = trim(wp_strip_all_tags($value));
        if ($plain !== '' && !in_array(strtolower($plain), ['no description available.', 'no description available', 'n/a'], true)) return $value;
    }
    return '';
}

/** Never associate Comic Vine metadata by an issue number alone. */
function tcs_catalog_cv_matches(array $cv, int $cv_id, string $number, int $cv_volume_id = 0): bool {
    return $cv_id > 0
        && (int) ($cv['id'] ?? 0) === $cv_id
        && array_key_exists('issue_number', $cv)
        && trim((string) $cv['issue_number']) === $number
        && (!$cv_volume_id || (int) ($cv['volume']['id'] ?? 0) === $cv_volume_id);
}

/** One cached series lookup; never enumerate its issues to obtain publisher/genres. */
function tcs_collection_series(int $title_id) {
    $key = "metron:series:{$title_id}";
    $series = get_transient($key);
    if (is_array($series) && !isset($series['error']) && (int) ($series['id'] ?? 0) === $title_id && !empty($series['name'])) return $series;
    $client = new MetronClient();
    $series = $client->api_get($client->api_base . "series/{$title_id}/");
    if (!is_array($series) || isset($series['error']) || (int) ($series['id'] ?? 0) !== $title_id || empty($series['name'])) {
        return new WP_Error('collection_series_unavailable', 'Could not load this comic series. Please retry.');
    }
    set_transient($key, $series, 14 * DAY_IN_SECONDS);
    return $series;
}

/** Return validated catalog fields or an error before a collection post is created. */
function tcs_collection_catalog_data(int $title_id, int $issue_id) {
    $renderer = new ComicRenderer();

    $issue = $renderer->get_single_issue($title_id, $issue_id);
    if (!is_array($issue) || isset($issue['error']) || (int) ($issue['id'] ?? 0) !== $issue_id || (int) ($issue['series']['id'] ?? 0) !== $title_id) {
        return new WP_Error('collection_issue_unavailable', 'Could not load the selected issue in this series. Please retry.');
    }
    // Older cached detail records may predate the cv_id field. Refresh only this issue.
    if (!array_key_exists('cv_id', $issue)) {
        $client = new MetronClient();
        $fresh = $client->api_get($client->api_base . "issue/{$issue_id}/");
        if (!is_array($fresh) || isset($fresh['error']) || (int) ($fresh['id'] ?? 0) !== $issue_id || (int) ($fresh['series']['id'] ?? 0) !== $title_id) {
            return new WP_Error('collection_mapping_unavailable', 'Could not verify the issue metadata. Please retry.');
        }
        $issue = array_replace($issue, $fresh);
        set_transient("metron:issue:{$title_id}_{$issue_id}", $issue, 2 * WEEK_IN_SECONDS);
    }
    $series = tcs_collection_series($title_id);
    if (is_wp_error($series)) return $series;

    // The full series name is already a title: do not strip legitimate numbers or years.
    $title = tcs_catalog_text($series['name'] ?? '');
    $number = tcs_catalog_text($issue['number'] ?? '');
    $publisher = '';
    foreach ([$issue['publisher']['name'] ?? '', $series['publisher']['name'] ?? '', $issue['series']['publisher']['name'] ?? ''] as $candidate) {
        $candidate = tcs_catalog_text($candidate);
        if ($candidate !== '' && !in_array(strtolower($candidate), ['unknown', 'unknown publisher', 'n/a'], true)) {
            $publisher = $candidate;
            break;
        }
    }
    if ($title === '' || $number === '' || $publisher === '') {
        return new WP_Error('collection_identity_incomplete', 'The series title, issue number, or publisher is missing from the catalog. No collection entry was changed.');
    }

    // Use Comic Vine fields only when the issue mapping matches.
   // Use Comic Vine fields only when the issue mapping matches.
$cv_id = absint($issue['cv_id'] ?? 0);
$cv = [];
$catalog_warnings = [];

if ($cv_id) {
    $candidate = tcs_get_comicvine_issue_basic($cv_id);

    if (is_wp_error($candidate)) {
        return $candidate;
    }

    $expected_volume = absint($series['cv_id'] ?? 0);

    if (
        tcs_catalog_cv_matches(
            $candidate,
            $cv_id,
            $number,
            $expected_volume
        )
    ) {
        $cv = $candidate;
    } else {
        $catalog_warnings[] = sprintf(
            'Comic Vine enrichment skipped for Metron issue %d. ' .
            'Expected CV issue %d, number %s, volume %s; ' .
            'received CV issue %d, number %s, volume %s.',
            $issue_id,
            $cv_id,
            wp_json_encode($number),
            $expected_volume
                ? (string) $expected_volume
                : '(not checked)',
            (int) ($candidate['id'] ?? 0),
            wp_json_encode($candidate['issue_number'] ?? null),
            wp_json_encode($candidate['volume']['id'] ?? null)
        );

        // Keep Metron metadata; exclude the rejected Comic Vine ID.
        $cv_id = 0;
    }
}
    $description = tcs_catalog_description($issue);

    if ($description === '') $description = tcs_catalog_description($cv);

    $creators = tcs_format_creator_credits(is_array($issue['credits'] ?? null) ? $issue['credits'] : []);
    if ($creators === '') $creators = tcs_format_creator_credits(is_array($cv['person_credits'] ?? null) ? $cv['person_credits'] : []);
    $characters = tcs_catalog_names($issue['characters'] ?? []);
    if (!$characters) $characters = tcs_catalog_names($cv['character_credits'] ?? []);
    $genres = tcs_catalog_names($series['genres'] ?? []);
    $concepts = tcs_catalog_names($cv['concept_credits'] ?? []);
    $image = is_string($issue['image'] ?? null) ? esc_url_raw($issue['image']) : '';
    if ($image === '') $image = tcs_get_cv_image_url($cv);

    return [
        'title' => $title, 
        'publisher' => $publisher, 
        'genres' => $genres,
        'description' => $description !== '' ? $renderer->clean_cv_description($description) : '',
        'warnings' => $catalog_warnings,
        'meta' => [
            'issue_id' => $issue_id, 'title_id' => $title_id, 'cv_issue_id' => $cv_id,
            'issue_number' => $number, 'volume' => tcs_catalog_text($series['volume'] ?? $issue['series']['volume'] ?? ''),
            'date_published' => tcs_catalog_text($issue['cover_date'] ?? ''),
            'creators' => $creators, 'genres' => implode(', ', $genres),
            'concepts' => implode("\n", $concepts), 'characters' => implode("\n", $characters),
            'cover_image_url' => esc_url_raw($image),
        ],
    ];
}

/** Replace only catalog fields on one owned entry. Personal inventory metadata is untouched. */
function tcs_store_collection_catalog(int $post_id, array $catalog) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'collection' || (int) $post->post_author !== get_current_user_id() || !in_array($post->post_status, ['publish', 'draft'], true)) {
        return new WP_Error('collection_owner', 'This collection entry is unavailable.');
    }
    $existing_issue = (int) get_post_meta($post_id, 'issue_id', true);
    if ($existing_issue && $existing_issue !== (int) $catalog['meta']['issue_id']) {
        return new WP_Error('collection_identity', 'The collection entry belongs to a different issue.');
    }
    $terms = ensure_publisher_terms($catalog['publisher']);
    if (!$terms) return new WP_Error('collection_publisher', 'Could not save the publisher.');
    $publisher_id = (int) $terms['publisher_id'];
    $title_term_id = ensure_title_term($catalog['title'], $publisher_id);
    if (!$title_term_id) return new WP_Error('collection_series_term', 'Could not save the series title.');
    $updated = wp_update_post(wp_slash(['ID' => $post_id, 'post_title' => $catalog['title'], 'post_content' => $catalog['description']]), true);
    if (is_wp_error($updated) || !$updated) return new WP_Error('collection_save', 'Could not save the collection entry.');
    foreach ($catalog['meta'] as $key => $value) {
        update_post_meta($post_id, $key, wp_slash((string) $value));
        if ((string) get_post_meta($post_id, $key, true) !== (string) $value) return new WP_Error('collection_meta', 'Some catalog fields could not be saved. Retry the metadata refresh.');
    }
    $assigned = wp_set_object_terms($post_id, [$publisher_id, (int) $title_term_id], 'publisher', false);
    if (is_wp_error($assigned)) return $assigned;
    $assigned = wp_set_object_terms($post_id, $catalog['genres'], 'comic_genre', false);
    if (is_wp_error($assigned)) return $assigned;
    $date = $catalog['meta']['date_published'];
    if ($date !== '' && strtotime($date) !== false) {
        $year = (int) date('Y', strtotime($date));
        update_post_meta($post_id, 'year', $year);
        update_post_meta($post_id, 'era', get_comic_era($year));
    }
    clean_post_cache($post_id);
    return true;
}
