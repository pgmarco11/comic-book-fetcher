<?php
/** Owner-scoped collection inventory. No Metron or Comic Vine requests. */
defined('ABSPATH') || exit;

require_once __DIR__ . '/class-tcs-collection-inventory-repository.php';
require_once __DIR__ . '/class-tcs-collection-inventory-view.php';
require_once __DIR__ . '/class-tcs-collection-inventory-controller.php';

function tcs_inventory_repository(): TCS_Collection_Inventory_Repository {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Collection_Inventory_Repository();
    }
    return $instance;
}

function tcs_inventory_view(): TCS_Collection_Inventory_View {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Collection_Inventory_View(tcs_inventory_repository());
    }
    return $instance;
}

function tcs_inventory_controller(): TCS_Collection_Inventory_Controller {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Collection_Inventory_Controller(tcs_inventory_repository());
    }
    return $instance;
}


function tcs_inventory_condition_options(): array {
    return [
        '' => 'Not set',
        'Ungraded - Normal wear' => 'Ungraded - Normal wear',
        '10.0 (GEM MINT)' => '10.0 (GEM MINT)',
        '9.8 (NEAR MINT / MINT)' => '9.8 (NEAR MINT / MINT)',
        '9.4 (NEAR MINT)' => '9.4 (NEAR MINT)',
        '9.0 (VERY FINE / NEAR MINT)' => '9.0 (VERY FINE / NEAR MINT)',
        '8.5 (VERY FINE+)' => '8.5 (VERY FINE+)',
        '8.0 (VERY FINE)' => '8.0 (VERY FINE)',
        '7.5 (VERY FINE-)' => '7.5 (VERY FINE-)',
        '7.0 (FINE / VERY FINE)' => '7.0 (FINE / VERY FINE)',
        '6.0 (FINE)' => '6.0 (FINE)',
        '5.0 (VERY GOOD / FINE)' => '5.0 (VERY GOOD / FINE)',
        '4.0 (VERY GOOD)' => '4.0 (VERY GOOD)',
        '2.0 (GOOD)' => '2.0 (GOOD)',
    ];
}

// Keep existing frontend URLs; remove collection records from public discovery.
add_filter('register_post_type_args', function ($args, $post_type) {
    if ($post_type === 'collection') {
        $args['exclude_from_search'] = true;
        $args['show_in_rest'] = false;
    }
    return $args;
}, 10, 2);
add_filter('wp_sitemaps_post_types', function ($types) {
    unset($types['collection']);
    return $types;
});

add_filter('wp_sitemaps_taxonomies', function ($taxonomies) {
    unset(
        $taxonomies['publisher'],
        $taxonomies['comic_series'],
        $taxonomies['comic_genre']
    );
    return $taxonomies;
});
add_filter('oembed_response_data', function ($data, $post) {
    return $post->post_type === 'collection' ? false : $data;
}, 10, 2);
add_action('pre_get_posts', function ($query) {
    if (is_admin()) return;
    $types = (array) $query->get('post_type');
    if (in_array('collection', $types, true) ||
        (
            $query->is_main_query() && 
            ($query->is_post_type_archive('collection') || $query->is_tax([
                'publisher',
                'comic_series',
                'comic_genre',
            ]))
        ) 
        ){            
            $query->set('post_type', 'collection');
            if (is_user_logged_in()) {
                $query->set('author', get_current_user_id());
                $query->set('author__in', [get_current_user_id()]);
            } else {
                $query->set('post__in', [0]);
            }
        }
});
add_action('template_redirect', function () {
    if (is_singular('collection') || is_post_type_archive('collection') || is_tax([
        'publisher',
        'comic_series',
        'comic_genre',
    ])) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
    }
    if (is_singular('collection')) {
        $post = get_queried_object();
        if (!is_user_logged_in() || (int) $post->post_author !== get_current_user_id()) {
            status_header(404);
            wp_die('This collection entry is unavailable.', 'Collection entry unavailable', ['response' => 404]);
        }
    }
}, 0);

function tcs_inventory_text($value): string {
    return is_scalar($value) ? sanitize_text_field((string) $value) : '';
}

/**
 * Load collection-management assets on:
 *
 * 1. The collection archive.
 * 2. The public issue-details page.
 */
function tcs_inventory_asset_setup(): void
{
    tcs_inventory_controller()->asset_setup();
}
add_action(
    'wp_enqueue_scripts',
    'tcs_inventory_asset_setup',
    30
);


function tcs_inventory_record(int $id): array {
    return tcs_inventory_repository()->record($id);
}

function tcs_inventory_sort_records(
    array &$records
): void {
    tcs_inventory_repository()->sort_records($records);
}

function tcs_inventory_series_options(
    int $user_id,
    int $publisher_id = 0
): array {
    return tcs_inventory_repository()->series_options($user_id, $publisher_id);
}

/**
 * Return the term IDs that should be used for a selected series.
 *
 * Series terms can be imported separately for different publishers. When no
 * publisher is selected, treat terms with the same name as the same series so
 * the filter searches the owner's whole collection.
 */
function tcs_inventory_matching_series_ids(
    int $series_term_id,
    int $publisher_id = 0
): array {
    return tcs_inventory_repository()->matching_series_ids($series_term_id, $publisher_id);
}

/** Facets and totals include only the current owner's published collection. */
function tcs_inventory_overview(
    int $user_id,
    int $publisher_id = 0
): array {
    return tcs_inventory_repository()->overview($user_id, $publisher_id);
}

/**
 * Sort recorded prices numerically, with missing prices last.
 */
function tcs_inventory_sort_by_price(
    array &$records,
    bool $descending = false
): void {
    tcs_inventory_repository()->sort_by_price($records, $descending);
}

function tcs_inventory_results(array $input): array {
    return tcs_inventory_repository()->results($input);
}

function tcs_inventory_items_html(array $records): string {
    return tcs_inventory_view()->items_html($records);
}

function tcs_inventory_authorize(): void {
    tcs_inventory_controller()->authorize();
}

function tcs_inventory_owned_post(int $id, array $statuses = ['publish']): WP_Post {
    return tcs_inventory_controller()->owned_post($id, $statuses);
}

add_action('wp_ajax_tcs_inventory_list', [tcs_inventory_controller(), 'list_items']);

add_action('wp_ajax_tcs_inventory_save', [tcs_inventory_controller(), 'save_item']);

function tcs_inventory_cleanup_orphaned_terms(
    array $terms_by_taxonomy
): void {
    tcs_inventory_repository()->cleanup_orphaned_terms($terms_by_taxonomy);
}

add_action(
    'wp_ajax_tcs_inventory_bulk',
    [tcs_inventory_controller(), 'bulk_action']
);

function tcs_inventory_taxonomy_tree(
    int $user_id,
    int $publisher_id = 0,
    int $series_term_id = 0
): array {
    return tcs_inventory_repository()->taxonomy_tree($user_id, $publisher_id, $series_term_id);
}
function tcs_inventory_taxonomy_html(
    array $publishers
): string {
    return tcs_inventory_view()->taxonomy_html($publishers);
}

function tcs_inventory_render_app(): void {
    tcs_inventory_view()->render_app();
}
