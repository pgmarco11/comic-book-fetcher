<?php
require_once __DIR__ . '/classes/class-tcs-collection-import-lock.php';

function mwp_update_user_wishlist(callable $change): void {
    $user_id = get_current_user_id();

    if ($user_id <= 0) {
        wp_send_json_error('Please sign in and try again.', 401);
        return;
    }

    $lock = new TCS_Collection_Import_Lock('wishlist:' . $user_id);

    try {
        $result = $lock->run(
            static function (TCS_Collection_Import_Lock $lock) use ($user_id, $change) {
                wp_cache_delete($user_id, 'user_meta');

                $wishlist = mwp_get_user_wishlist($user_id);
                $previous = $wishlist;
                $result = $change($wishlist);

                if (is_wp_error($result)) {
                    return $result;
                }

                if ($wishlist !== $previous) {
                    $lock->assert_owned();

                    update_user_meta(
                        $user_id,
                        'user_wishlist',
                        wp_slash($wishlist)
                    );

                    wp_cache_delete($user_id, 'user_meta');

                    if (
                        get_user_meta($user_id, 'user_wishlist', true)
                        !== $wishlist
                    ) {
                        return new WP_Error(
                            'wishlist_save_failed',
                            'Your wishlist could not be saved. Refresh and try again.',
                            ['status' => 500]
                        );
                    }
                }

                return $result;
            }
        );
    } catch (Throwable $error) {
        $busy = $error->getCode() === 409;

        if (!$busy) {
            error_log(
                'TCS wishlist update failed: '
                . $error->getMessage()
            );
        }

        wp_send_json_error(
            $busy
                ? 'Another wishlist update is running. Please try again shortly.'
                : 'Your wishlist could not be updated. Please retry.',
            $busy ? 409 : 503
        );
        return;
    }

    if (is_wp_error($result)) {
        $data = $result->get_error_data();
        $status = is_array($data)
            ? (int) ($data['status'] ?? 500)
            : 500;

        wp_send_json_error($result->get_error_message(), $status);
        return;
    }

    wp_send_json_success($result);
}

function mwp_wishlist_ebay_id(array $item) {
    $id = isset($item['ebay_id']) && is_scalar($item['ebay_id']) ? trim((string) $item['ebay_id']) : '';
    if (preg_match('/\A[0-9]{9,20}\z/', $id)) return $id;
    if (preg_match('/\Av1\|([0-9]{9,20})\|[0-9]+\z/', $id, $m)) return $m[1];
    $url = isset($item['item_url']) && is_string($item['item_url']) ? wp_parse_url($item['item_url']) : false;
    if (!is_array($url) || !preg_match('/(?:^|\.)ebay\.[a-z.]+\z/', strtolower($url['host'] ?? ''))) return '';
    return preg_match('~^/itm/(?:[^/]+/)?([0-9]{9,20})/?$~', $url['path'] ?? '', $m) ? $m[1] : '';
}

function mwp_wishlist_request_item(array $request) {
    $item = [];
    foreach (['item_id', 'type', 'ebay_id', 'item_url'] as $key) {
        $item[$key] = isset($request[$key]) && is_scalar($request[$key]) ? sanitize_text_field((string) $request[$key]) : '';
    }
    return $item;
}

function mwp_wishlist_items_match(array $saved, array $candidate) {
    $ebay = mwp_wishlist_ebay_id($candidate);
    if ($ebay !== '') return mwp_wishlist_ebay_id($saved) === $ebay;
    return (string) ($saved['item_id'] ?? '') === (string) ($candidate['item_id'] ?? '')
        && (($candidate['type'] ?? '') === '' || ($saved['type'] ?? '') === $candidate['type']);
}

function mwp_get_user_wishlist($user_id) {
    $wishlist = get_user_meta($user_id, 'user_wishlist', true);

    if (!is_array($wishlist)) {
        return [];
    }

    $issue_path = untrailingslashit(
        (string) wp_parse_url(
            home_url('/comic-database/issue/'),
            PHP_URL_PATH
        )
    );

    foreach ($wishlist as &$item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'post') {
            continue;
        }

        $url = wp_parse_url((string) ($item['item_url'] ?? ''));

        if (
            !is_array($url) ||
            untrailingslashit($url['path'] ?? '') !== $issue_path
        ) {
            continue;
        }

        parse_str($url['query'] ?? '', $query);
        $issue_id = $query['issue_id'] ?? '';

        if (
            is_scalar($issue_id) &&
            ctype_digit((string) $issue_id) &&
            (int) $issue_id > 0
        ) {
            $item['item_id'] = 'metron:issue:' . (int) $issue_id;
            $item['item_url'] = esc_url_raw(
                add_query_arg(
                    $query,
                    home_url('/comic-database/issue/')
                )
            );
        }
    }

    unset($item);

    foreach ($wishlist as &$item) {
        if (!is_array($item)) continue;
        $id = mwp_wishlist_ebay_id($item);
        if ($id !== '') {
            $item['ebay_id'] = $id;
            $item['item_url'] = 'https://www.ebay.com/itm/' . $id;
        }
    }
    unset($item);
    return $wishlist;
}

/* ==================================================================
 *  WISHLIST FUNCTIONS
 * ================================================================== */
add_action('wp_ajax_check_wishlist_status_batch', 'check_wishlist_status_batch');

function check_wishlist_status_batch() {
    if (
        !check_ajax_referer(
            'wishlist_nonce',
            'nonce',
            false
        )
    ) {
        wp_send_json_error(
            [
                'code'    => 'invalid_nonce',
                'message' => 'Your session has expired. Please refresh the page and try again.',
            ],
            403
        );
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(
            [
                'code'    => 'not_logged_in',
                'message' => 'Please sign in and try again.',
            ],
            401
        );
    }

    $user_id = get_current_user_id();

    $wishlist = mwp_get_user_wishlist($user_id);
    $request = wp_unslash($_POST);
    $items = isset($request['items']) && is_array($request['items']) ? $request['items'] : [];
    if (!$items && isset($request['item_ids']) && is_array($request['item_ids'])) {
        foreach ($request['item_ids'] as $id) {
            if (is_scalar($id)) $items[] = ['item_id' => (string) $id];
        }
    }
    $matched = [];
    foreach ($items as $candidate) {
        if (!is_array($candidate)) continue;
        $candidate = mwp_wishlist_request_item($candidate);
        if ($candidate['item_id'] === '') continue;
        foreach ($wishlist as $saved) {
            if (is_array($saved) && mwp_wishlist_items_match($saved, $candidate)) {
                $matched[] = $candidate['item_id'];
                break;
            }
        }
    }
    wp_send_json_success(array_values(array_unique($matched)));
}

add_action('wp_ajax_add_to_wishlist', 'add_to_wishlist_ajax');

function add_to_wishlist_ajax() {
    if (
        !check_ajax_referer(
            'wishlist_nonce',
            'nonce',
            false
        )
    ) {
        wp_send_json_error(
            [
                'code'    => 'invalid_nonce',
                'message' => 'Your session has expired. Please refresh the page and try again.',
            ],
            403
        );
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(
            [
                'code'    => 'not_logged_in',
                'message' => 'Please sign in and try again.',
            ],
            401
        );
    }



    $request = wp_unslash($_POST);

    $new = [
        'type'      => sanitize_text_field($request['type'] ?? ''),
        'item_id'   => sanitize_text_field($request['item_id'] ?? ''),
        'title'     => sanitize_text_field($request['title'] ?? ''),
        'publisher' => isset($request['publisher']) && is_string($request['publisher'])
            ? sanitize_text_field($request['publisher'])
            : '',
        'publication_year' => isset($request['publication_year'])
            && is_string($request['publication_year'])
            && preg_match('/^\d{4}$/D', $request['publication_year'])
                ? $request['publication_year']
                : '',
        'item_url'  => esc_url_raw($request['item_url'] ?? ''),
        'image_url' => esc_url_raw($request['image_url'] ?? ''),
        'volume'    => sanitize_text_field($request['volume'] ?? ''),
        'ebay_id'   => sanitize_text_field($request['ebay_id'] ?? ''),
        'added_at'  => current_time('mysql'),
    ];

    if ($new['item_id'] === '' || $new['type'] === '') {
        wp_send_json_error(
            'Missing wishlist item information. Refresh the page and try again.'
        );
    }

    $ebay = mwp_wishlist_ebay_id($new);
    if ($ebay !== '') {
        $new['ebay_id'] = $ebay;
        $new['item_url'] = 'https://www.ebay.com/itm/' . $ebay;
    }
    mwp_update_user_wishlist(
        static function (array &$wishlist) use ($new) {
            foreach ($wishlist as $item) {
                if (
                    is_array($item)
                    && mwp_wishlist_items_match($item, $new)
                ) {
                    return new WP_Error(
                        'wishlist_duplicate',
                        'Already in wishlist.',
                        ['status' => 409]
                    );
                }
            }
    
            $wishlist[] = $new;
    
            return null;
        }
    );
}

add_action('wp_ajax_remove_from_wishlist', 'remove_from_wishlist_ajax');

function remove_from_wishlist_ajax() {
    if (
        !check_ajax_referer(
            'wishlist_nonce',
            'nonce',
            false
        )
    ) {
        wp_send_json_error(
            [
                'code'    => 'invalid_nonce',
                'message' => 'Your session has expired. Please refresh the page and try again.',
            ],
            403
        );
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(
            [
                'code'    => 'not_logged_in',
                'message' => 'Please sign in and try again.',
            ],
            401
        );
    }

    $request = wp_unslash($_POST);

    $item_id = sanitize_text_field(
        $request['item_id'] ?? ''
    );

    if ($item_id === '') {
        wp_send_json_success([
            'in_wishlist' => false,
        ]);
    }

    mwp_update_user_wishlist(
        static function (array &$wishlist) use ($request, $item_id) {
            $candidate = mwp_wishlist_request_item($request);
    
            if (mwp_wishlist_ebay_id($candidate) === '') {
                foreach ($wishlist as $saved) {
                    if (
                        is_array($saved)
                        && (string) ($saved['item_id'] ?? '') === $item_id
                    ) {
                        $candidate = $saved;
                        break;
                    }
                }
            }
    
            $wishlist = array_values(array_filter(
                $wishlist,
                static function ($saved) use ($candidate) {
                    return !is_array($saved)
                        || !mwp_wishlist_items_match($saved, $candidate);
                }
            ));
    
            return null;
        }
    );
}

add_action('wp_ajax_remove_wishlist_items', 'remove_wishlist_items_ajax');

function remove_wishlist_items_ajax() {
    if (!check_ajax_referer('wishlist_nonce', 'nonce', false)) {
        wp_send_json_error(
            'Your session has expired. Refresh the page and try again.',
            403
        );
    }

    if (!is_user_logged_in()) {
        wp_send_json_error('Please sign in and try again.', 401);
    }

    $request = wp_unslash($_POST);
    $items = $request['items'] ?? null;

    if (!is_array($items) || count($items) < 1 || count($items) > 100) {
        wp_send_json_error('Select between 1 and 100 items.', 400);
    }

    $candidates = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            wp_send_json_error('Invalid wishlist selection.', 400);
        }

        $candidate = mwp_wishlist_request_item($item);

        if ($candidate['item_id'] === '' || $candidate['type'] === '') {
            wp_send_json_error('Invalid wishlist selection.', 400);
        }

        $candidates[] = $candidate;
    }

    mwp_update_user_wishlist(
        static function (array &$wishlist) use ($candidates) {
            $wishlist = array_values(array_filter(
                $wishlist,
                static function ($saved) use ($candidates) {
                    if (!is_array($saved)) {
                        return true;
                    }
    
                    foreach ($candidates as $candidate) {
                        if (mwp_wishlist_items_match($saved, $candidate)) {
                            return false;
                        }
                    }
    
                    return true;
                }
            ));
    
            return ['remaining_count' => count($wishlist)];
        }
    );
}


add_action('wp_ajax_check_wishlist_status', 'check_wishlist_status_ajax');

function check_wishlist_status_ajax() {
    if (
        !check_ajax_referer(
            'wishlist_nonce',
            'nonce',
            false
        )
    ) {
        wp_send_json_error(
            [
                'code'    => 'invalid_nonce',
                'message' => 'Your session has expired. Please refresh the page and try again.',
            ],
            403
        );
    }

    if (!is_user_logged_in()) {
        wp_send_json_success([
            'in_wishlist' => false,
        ]);
    }

    $request = wp_unslash($_POST);

    $item_id = sanitize_text_field(
        $request['item_id'] ?? ''
    );

    if ($item_id === '') {
        wp_send_json_error(
            [
                'code'    => 'missing_item_id',
                'message' => 'Missing wishlist item information.',
            ],
            400
        );
    }
    $wishlist = mwp_get_user_wishlist(get_current_user_id());

    $in = false;
    $candidate = mwp_wishlist_request_item($request);
    foreach ($wishlist as $saved) {
        if (is_array($saved) && mwp_wishlist_items_match($saved, $candidate)) {
            $in = true;
            break;
        }
    }
    wp_send_json_success(['in_wishlist' => $in]);
}

/** Resolve a local WordPress category; never mistake a Metron ID for a post ID. */
function mwp_wishlist_category_term(array $item) {
    $id = (string) ($item['item_id'] ?? '');
    $url = wp_parse_url((string) ($item['item_url'] ?? ''));
    $comic_path = untrailingslashit((string) wp_parse_url(home_url('/comic-database/issue/'), PHP_URL_PATH));
    $is_comic = strpos($id, 'metron:issue:') === 0
        || (is_array($url) && untrailingslashit($url['path'] ?? '') === $comic_path);
    if ($is_comic) {
        $term = get_category_by_slug('comic-books-memorabilia');
        return $term instanceof WP_Term ? $term : null;
    }
    // Discogs items should search within records/music.
    $host = is_array($url)
        ? strtolower($url['host'] ?? '')
        : '';

    if (
        $host === 'discogs.com' ||
        substr($host, -12) === '.discogs.com'
    ) {
        foreach (['vinyl-records', 'records', 'music'] as $slug) {
            $term = get_category_by_slug($slug);

            if ($term instanceof WP_Term) {
                return $term;
            }
        }
    }

    if (($item['type'] ?? '') !== 'post' || !ctype_digit($id)) return null;
    $terms = get_the_terms((int) $id, 'category');
    if (!is_array($terms)) return null;
    $best = null;
    $depth = -1;
    foreach ($terms as $term) {
        if (!$term instanceof WP_Term || $term->slug === 'uncategorized') continue;
        $candidate_depth = count(get_ancestors($term->term_id, 'category', 'taxonomy'));
        if ($candidate_depth > $depth) {
            $best = $term;
            $depth = $candidate_depth;
        }
    }
    return $best;
}

/** Build a Tools link from an existing wishlist entry. */
function mwp_wishlist_tools_url(array $item) {
    $id = mwp_wishlist_ebay_id($item);
    if ($id !== '') {
        return add_query_arg('item_id', $id, home_url('/tools/'));
    }
    $title = trim(wp_strip_all_tags((string) ($item['title'] ?? '')));
    if ($title === '') return '';

    if (strpos((string) ($item['item_id'] ?? ''), 'metron:issue:') === 0) {
        $title = html_entity_decode(
            $title,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    
        $title = str_replace('—', ' ', $title);
    
        $title = trim((string) preg_replace('/\s+/u', ' ', implode(' ', [
            $title,
            (string) ($item['publisher'] ?? ''),
            (string) ($item['publication_year'] ?? ''),
        ])));
    }
    
    $args = ['search_query' => rawurlencode($title)];
    $term = mwp_wishlist_category_term($item);
    if ($term instanceof WP_Term) $args['category_term'] = $term->term_id;
    return add_query_arg($args, home_url('/tools/'));
}
add_shortcode('user_wishlist', 'mwp_display_user_wishlist');

function mwp_display_user_wishlist() {
    if (!is_user_logged_in()) {
        return '<p class="has-white-color">Please <a class="text-white" href="' .
            esc_url(wp_login_url(home_url('/wish-list/'))) .
            '">log in</a> to view your wishlist.</p>';
    }

    $wishlist = mwp_get_user_wishlist(get_current_user_id());

    if (empty($wishlist)) {
        return '<p class="has-white-color">Your wishlist is empty.</p>';
    }

    $sort_options = [
        'default' => 'Default order',
        'publisher_asc' => 'Publisher, then title (A–Z)',
        'publisher_desc' => 'Publisher, then title (Z–A)',
        'year_asc' => 'Publication year (oldest first)',
        'year_desc' => 'Publication year (newest first)',
        'added_desc' => 'Recently added',
        'added_asc' => 'Oldest added',
    ];
    
    $requested_sort = isset($_GET['wishlist_sort']) && is_string($_GET['wishlist_sort'])
        ? wp_unslash($_GET['wishlist_sort'])
        : '';
    
    $sort = array_key_exists($requested_sort, $sort_options)
        ? $requested_sort
        : 'default';
    
    if ($sort !== 'default') {
        [$field, $direction] = explode('_', $sort, 2);
        $multiplier = $direction === 'desc' ? -1 : 1;
    
        $sort_field = [
            'publisher' => 'publisher',
            'year' => 'publication_year',
            'added' => 'added_at',
        ][$field];
    
        usort($wishlist, static function ($left, $right) use ($sort_field, $multiplier) {
            $left_value = trim((string) ($left[$sort_field] ?? ''));
            $right_value = trim((string) ($right[$sort_field] ?? ''));
    
            // Keep missing values last in both directions.
            if (($left_value === '') !== ($right_value === '')) {
                return $left_value === '' ? 1 : -1;
            }
    
            $comparison = strnatcasecmp($left_value, $right_value);
            if ($comparison !== 0) {
                return $multiplier * $comparison;
            }
    
            if ($sort_field === 'added_at') {
                return 0;
            }
    
            return $multiplier * strnatcasecmp(
                (string) ($left['title'] ?? ''),
                (string) ($right['title'] ?? '')
            );
        });
    }

    // Remember presentation only, never wishlist contents.
    $saved_view = isset($_COOKIE['tcs_wishlist_view'])
        && is_string($_COOKIE['tcs_wishlist_view'])
        ? wp_unslash($_COOKIE['tcs_wishlist_view'])
        : '';

    $view = in_array($saved_view, ['list', 'cards'], true)
        ? $saved_view
        : 'cards';

    ob_start();
    ?>
    <div class="tcs-wishlist" data-view="<?php echo esc_attr($view); ?>">

        <div class="wishlist-toolbar">
            <p class="wishlist-summary">
                <strong class="wishlist-count"><?php
                    echo esc_html(count($wishlist));
                ?></strong>
                <span>saved items</span>
            </p>
            <div class="wishlist-toolbar-controls"> 
                           
                <form method="get" class="wishlist-sort">                
                    <label>  
                        <span>Sort by:</span>                          
                        <select name="wishlist_sort">
                            <?php foreach ($sort_options as $value => $label): ?>
                                <option
                                    value="<?php echo esc_attr($value); ?>"
                                    <?php selected($sort, $value); ?>
                                >
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="btn btn-secondary wishlist-sort-submit">Apply</button>
                </form>

                <div
                    class="wishlist-view-controls"
                    role="group"
                    aria-label="Wishlist display"
                    hidden
                >
                    <span>View:</span>
                        <?php foreach (['list' => 'List', 'cards' => 'Cards'] as $key => $label): ?>
                            <button
                                type="button"
                                class="wishlist-view-button"
                                data-wishlist-view="<?php echo esc_attr($key); ?>"
                                aria-pressed="<?php echo $view === $key ? 'true' : 'false'; ?>"
                            >
                                <?php echo esc_html($label); ?>
                            </button>
                        <?php endforeach; ?>                
                </div>

                <button
                    type="button"
                    class="btn btn-secondary wishlist-selection-start"
                    aria-expanded="false"
                    hidden
                >
                    Select items
                </button>

            </div>
        </div><!-- .wishlist-toolbar -->

        <div class="wishlist-selection-bar" hidden>
            <span class="wishlist-selection-count" aria-live="polite">
                0 selected
            </span>

            <button type="button" class="btn btn-danger wishlist-selection-remove" disabled>
                Remove selected
            </button>

            <button type="button" class="btn btn-secondary wishlist-selection-cancel">
                Cancel
            </button>
        </div>

        <ul class="user-wishlist">
            <?php foreach ($wishlist as $item):
                $tools_url = mwp_wishlist_tools_url($item);
                $tools_label = mwp_wishlist_ebay_id($item) !== ''
                    ? 'Check Item on Tools'
                    : 'Search eBay';
            ?>
                <li>
                    <div class="wishlist-image">
                        <?php if (!empty($item['image_url'])): ?>
                            <img
                                src="<?php echo esc_url($item['image_url']); ?>"
                                alt=""
                                loading="lazy"
                                decoding="async"
                            >
                        <?php else: ?>
                            <span>No image</span>
                        <?php endif; ?>
                    </div>

                    <div class="wishlist-details">
                        <a
                            href="<?php echo esc_url($item['item_url']); ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <strong><?php echo esc_html($item['title']); ?></strong>
                        </a>
                        
                        <?php if (!empty($item['publication_year'])): ?>
                            <small><?= trim((string) ($item['publication_year'] ?? '')); ?> </small>
                        <?php endif; ?>

                        <?php if (strpos((string) ($item['item_id'] ?? ''), 'metron:issue:') === 0): ?>
                            <?php
                                $comic_details = array_filter([
                                    trim((string) ($item['publisher'] ?? '')),
                                    trim((string) ($item['volume'] ? 'Vol ' . $item['volume'] : '')),
                                ]);
                            ?>
                            <?php if ($comic_details): ?>
                                    <small class="wishlist-comic-details">
                                        <?php echo esc_html(implode(' · ', $comic_details)); ?>
                                    </small>
                            <?php endif; ?>

                        <?php endif; ?>      

                    </div>

                    <label class="wishlist-item-selection" hidden>
                        <input
                            type="checkbox"
                            class="wishlist-select-item"
                            data-item-id="<?php echo esc_attr($item['item_id'] ?? ''); ?>"
                            data-type="<?php echo esc_attr($item['type'] ?? ''); ?>"
                            data-ebay-id="<?php echo esc_attr($item['ebay_id'] ?? ''); ?>"
                            data-item-url="<?php echo esc_attr($item['item_url'] ?? ''); ?>"
                            aria-label="<?php echo esc_attr('Select ' . ($item['title'] ?? 'wishlist item')); ?>"
                        >
                    </label>

                    <div class="wishlist-actions">
                        <?php if ($tools_url !== ''): ?>
                            <a class="btn btn-secondary" href="<?php echo esc_url($tools_url); ?>">
                                <?php echo esc_html($tools_label); ?>
                            </a>
                        <?php endif; ?>

                        <button
                            type="button"
                            class="remove-from-wishlist button"
                            data-item-id="<?php echo esc_attr($item['item_id']); ?>"
                        >Remove</button>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php

    return ob_get_clean();
}