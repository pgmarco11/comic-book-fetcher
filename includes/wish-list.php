<?php

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
            home_url('/comic-catalog/issue/'),
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

    $user_id = get_current_user_id();
    $wishlist = mwp_get_user_wishlist($user_id);

    $request = wp_unslash($_POST);

    $new = [
        'type'      => sanitize_text_field($request['type'] ?? ''),
        'item_id'   => sanitize_text_field($request['item_id'] ?? ''),
        'title'     => sanitize_text_field($request['title'] ?? ''),
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
    foreach ($wishlist as $item) {
        if (is_array($item) && mwp_wishlist_items_match($item, $new)) {
            wp_send_json_error('Already in wishlist.');
        }
    }

    $wishlist[] = $new;
    update_user_meta($user_id, 'user_wishlist', $wishlist);
    wp_send_json_success();
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

    $user_id = get_current_user_id();

    $request = wp_unslash($_POST);

    $item_id = sanitize_text_field(
        $request['item_id'] ?? ''
    );

    if ($item_id === '') {
        wp_send_json_success([
            'in_wishlist' => false,
        ]);
    }

    $wishlist = mwp_get_user_wishlist(
        get_current_user_id()
    );

    $candidate = mwp_wishlist_request_item($request);
    if (mwp_wishlist_ebay_id($candidate) === '') {
        foreach ($wishlist as $saved) {
            if (is_array($saved) && (string) ($saved['item_id'] ?? '') === $item_id) {
                $candidate = $saved;
                break;
            }
        }
    }
    $wishlist = array_filter($wishlist, static function ($saved) use ($candidate) {
        return !is_array($saved) || !mwp_wishlist_items_match($saved, $candidate);
    });
    update_user_meta($user_id, 'user_wishlist', array_values($wishlist));

    wp_send_json_success();
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
    $comic_path = untrailingslashit((string) wp_parse_url(home_url('/comic-catalog/issue/'), PHP_URL_PATH));
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
    $args = ['search_query' => rawurlencode($title)];
    $term = mwp_wishlist_category_term($item);
    if ($term instanceof WP_Term) $args['category_term'] = $term->term_id;
    return add_query_arg($args, home_url('/tools/'));
}

add_shortcode('user_wishlist', 'mwp_display_user_wishlist');
function mwp_display_user_wishlist() {
    if (!is_user_logged_in()) {
        echo '<p class="has-white-color">Please <a class="text-white" href="' . esc_url(
            wp_login_url(
                home_url('/wish-list/')
            )
        ) . '">log in</a> to view your wishlist.</p>';
        return; 
    }

    $wishlist = mwp_get_user_wishlist(get_current_user_id());
    if (empty($wishlist)) {
        return '<p class="has-white-color">Your wishlist is empty.</p>';
    }

    ob_start(); ?>
    <ul class="user-wishlist" style="list-style:none;padding:0;">
        <?php foreach ($wishlist as $item): ?>
            <li style="margin-bottom:15px;">
                <img src="<?php echo esc_url($item['image_url']); ?>" alt="" style="object-fit:cover;">
                <div style="flex:1;">
                    <a href="<?php echo esc_url($item['item_url']); ?>" target="_blank"><strong><?php echo esc_html($item['title']); ?></strong></a>
                    <?php if ($item['volume']): ?>
                        <small>Vol <?php echo esc_html($item['volume']); ?></small>
                    <?php elseif (!empty($item['ebay_id'])): ?>
                        <small>eBay <?php echo esc_html($item['ebay_id']); ?></small>
                    <?php endif; ?>
                </div>
                <?php
                $tools_url = mwp_wishlist_tools_url($item);
                $tools_label = mwp_wishlist_ebay_id($item) !== ''
                    ? 'Check Item on Tools' : 'Search eBay';
                ?>
                <?php if ($tools_url !== ''): ?>
                    <a class="btn btn-secondary" href="<?php echo esc_url($tools_url); ?>">
                        <?php echo esc_html($tools_label); ?>
                    </a>
                <?php endif; ?>
                <button type="button" class="remove-from-wishlist button" data-item-id="<?php echo esc_attr($item['item_id']); ?>">Remove</button>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
    return ob_get_clean();
}

