<?php
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

    return $wishlist;
}

/* ==================================================================
 *  WISHLIST FUNCTIONS
 * ================================================================== */
add_action('wp_ajax_check_wishlist_status_batch', 'check_wishlist_status_batch');
function check_wishlist_status_batch() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error();

    $user_id = get_current_user_id();
    $wishlist = mwp_get_user_wishlist($user_id);
    $request = wp_unslash($_POST);
    $item_ids = array_map(
        'sanitize_text_field',
        (array) ($request['item_ids'] ?? [])
    );

    $in_wishlist = array_intersect(array_column($wishlist, 'item_id'), $item_ids);
    wp_send_json_success(array_values($in_wishlist));
}

add_action('wp_ajax_add_to_wishlist', 'add_to_wishlist_ajax');
function add_to_wishlist_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error('Login required.');

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

    foreach ($wishlist as $item) {
        if ($item['type'] === $new['type'] && $item['item_id'] === $new['item_id']) {
            wp_send_json_error('Already in wishlist.');
        }
    }

    $wishlist[] = $new;
    update_user_meta($user_id, 'user_wishlist', $wishlist);
    wp_send_json_success();
}

add_action('wp_ajax_remove_from_wishlist', 'remove_from_wishlist_ajax');
function remove_from_wishlist_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error();

    $user_id = get_current_user_id();
    $item_id = sanitize_text_field($_POST['item_id']);
    $wishlist = mwp_get_user_wishlist($user_id);

    $wishlist = array_filter($wishlist, fn($i) => $i['item_id'] !== $item_id);
    update_user_meta($user_id, 'user_wishlist', array_values($wishlist));

    wp_send_json_success();
}

add_action('wp_ajax_check_wishlist_status', 'check_wishlist_status_ajax');
function check_wishlist_status_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_success(['in_wishlist' => false]);

    $item_id = sanitize_text_field($_POST['item_id']);
    $wishlist = mwp_get_user_wishlist(get_current_user_id());

    $in = in_array($item_id, array_column($wishlist, 'item_id'), true);
    wp_send_json_success(['in_wishlist' => $in]);
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
                    <?php elseif ($item['ebay_id']): ?>
                        <small><a href="https://thecollectiblespot.com/tools/?item_id=<?php echo urlencode($item['ebay_id']); ?>">eBay <?php echo esc_html($item['ebay_id']); ?></a></small>
                    <?php endif; ?>
                </div>
                <button type="button" class="remove-from-wishlist button" data-item-id="<?php echo esc_attr($item['item_id']); ?>">Remove</button>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
    return ob_get_clean();
}