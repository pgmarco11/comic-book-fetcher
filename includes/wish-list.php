<?php
/* ==================================================================
 *  WISHLIST FUNCTIONS
 * ================================================================== */
add_action('wp_ajax_check_wishlist_status_batch', 'check_wishlist_status_batch');
function check_wishlist_status_batch() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error();

    $user_id = get_current_user_id();
    $wishlist = get_user_meta($user_id, 'user_wishlist', true) ?: [];
    $item_ids = array_map('sanitize_text_field', (array)($_POST['item_ids'] ?? []));

    $in_wishlist = array_intersect(array_column($wishlist, 'item_id'), $item_ids);
    wp_send_json_success(array_values($in_wishlist));
}

add_action('wp_ajax_add_to_wishlist', 'add_to_wishlist_ajax');
function add_to_wishlist_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error('Login required.');

    $user_id = get_current_user_id();
    $wishlist = get_user_meta($user_id, 'user_wishlist', true) ?: [];

    $new = [
        'type'      => sanitize_text_field($_POST['type']),
        'item_id'   => sanitize_text_field($_POST['item_id']),
        'title'     => sanitize_text_field($_POST['title']),
        'item_url'  => esc_url_raw($_POST['item_url']),
        'image_url' => esc_url_raw($_POST['image_url']),
        'volume'    => sanitize_text_field($_POST['volume'] ?? ''),
        'ebay_id'   => sanitize_text_field($_POST['ebay_id'] ?? ''),
        'added_at'  => current_time('mysql'),
    ];

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
    $wishlist = get_user_meta($user_id, 'user_wishlist', true) ?: [];

    $wishlist = array_filter($wishlist, fn($i) => $i['item_id'] !== $item_id);
    update_user_meta($user_id, 'user_wishlist', array_values($wishlist));

    wp_send_json_success();
}

add_action('wp_ajax_check_wishlist_status', 'check_wishlist_status_ajax');
function check_wishlist_status_ajax() {
    check_ajax_referer('wishlist_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_success(['in_wishlist' => false]);

    $item_id = sanitize_text_field($_POST['item_id']);
    $wishlist = get_user_meta(get_current_user_id(), 'user_wishlist', true) ?: [];

    $in = in_array($item_id, array_column($wishlist, 'item_id'), true);
    wp_send_json_success(['in_wishlist' => $in]);
}

add_shortcode('user_wishlist', 'mwp_display_user_wishlist');
function mwp_display_user_wishlist() {
    if (!is_user_logged_in()) {
        return '<p class="has-white-color">Please log in to view your wishlist.</p>';
    }

    $wishlist = get_user_meta(get_current_user_id(), 'user_wishlist', true) ?: [];
    if (empty($wishlist)) {
        return '<p class="has-white-color">Your wishlist is empty.</p>';
    }

    ob_start(); ?>
    <ul class="user-wishlist" style="list-style:none;padding:0;">
        <?php foreach ($wishlist as $item): ?>
            <li style="margin-bottom:15px;display:flex;align-items:center;gap:10px;">
                <img src="<?php echo esc_url($item['image_url']); ?>" alt="" style="width:50px;height:50px;object-fit:cover;">
                <div style="flex:1;">
                    <a href="<?php echo esc_url($item['item_url']); ?>" target="_blank"><strong><?php echo esc_html($item['title']); ?></strong></a>
                    <?php if ($item['volume']): ?>
                        <small>Vol <?php echo esc_html($item['volume']); ?></small>
                    <?php elseif ($item['ebay_id']): ?>
                        <small><a href="https://thecollectiblespot.com/tools/?item_id=<?php echo urlencode($item['ebay_id']); ?>">eBay <?php echo esc_html($item['ebay_id']); ?></a></small>
                    <?php endif; ?>
                </div>
                <button class="remove-from-wishlist button" data-item-id="<?php echo esc_attr($item['item_id']); ?>">Remove</button>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
    return ob_get_clean();
}