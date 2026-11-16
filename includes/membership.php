<?php

defined('ABSPATH') || exit;

function tcs_get_membership_plan(int $user_id): string {
    if ($user_id <= 0) {
        return 'free';
    }

    if (wp_get_environment_type() === 'local') {
        $test_plan = get_user_meta($user_id, '_tcs_local_test_plan', true);

        if (in_array($test_plan, ['free', 'premium'], true)) {
            return $test_plan;
        }
    }

    $saved_plan = get_user_meta($user_id, '_tcs_membership_plan', true);

    return $saved_plan === 'premium' ? 'premium' : 'free';
}

function tcs_user_has_premium(int $user_id): bool {
    return tcs_get_membership_plan($user_id) === 'premium';
}

function tcs_can_edit_local_test_plan(int $user_id): bool {
    return wp_get_environment_type() === 'local'
        && current_user_can('manage_options')
        && current_user_can('edit_user', $user_id);
}

function tcs_render_local_test_plan(WP_User $user): void {
    if (!tcs_can_edit_local_test_plan($user->ID)) {
        return;
    }

    $test_plan = get_user_meta($user->ID, '_tcs_local_test_plan', true);
    ?>
    <h2><?php esc_html_e('Membership testing', 'comic-books-fetcher'); ?></h2>

    <table class="form-table" role="presentation">
        <tr>
            <th>
                <label for="tcs_local_test_plan">
                    <?php esc_html_e('Local test plan', 'comic-books-fetcher'); ?>
                </label>
            </th>
            <td>
                <?php wp_nonce_field('tcs_save_local_test_plan_' . $user->ID, 'tcs_local_test_plan_nonce'); ?>

                <select name="tcs_local_test_plan" id="tcs_local_test_plan">
                    <option value="" <?php selected($test_plan, ''); ?>>
                        <?php esc_html_e('Use saved plan', 'comic-books-fetcher'); ?>
                    </option>
                    <option value="free" <?php selected($test_plan, 'free'); ?>>
                        <?php esc_html_e('Free', 'comic-books-fetcher'); ?>
                    </option>
                    <option value="premium" <?php selected($test_plan, 'premium'); ?>>
                        <?php esc_html_e('Premium', 'comic-books-fetcher'); ?>
                    </option>
                </select>
            </td>
        </tr>
    </table>
    <?php
}

function tcs_save_local_test_plan(int $user_id): void {
    if (!tcs_can_edit_local_test_plan($user_id)) {
        return;
    }

    $nonce = $_POST['tcs_local_test_plan_nonce'] ?? '';

    if (
        !is_string($nonce)
        || !wp_verify_nonce(wp_unslash($nonce), 'tcs_save_local_test_plan_' . $user_id)
    ) {
        return;
    }

    $plan = $_POST['tcs_local_test_plan'] ?? null;

    if (!in_array($plan, ['', 'free', 'premium'], true)) {
        return;
    }

    if ($plan === '') {
        delete_user_meta($user_id, '_tcs_local_test_plan');
        return;
    }

    update_user_meta($user_id, '_tcs_local_test_plan', $plan);
}

add_action('show_user_profile', 'tcs_render_local_test_plan');
add_action('edit_user_profile', 'tcs_render_local_test_plan');
add_action('personal_options_update', 'tcs_save_local_test_plan');
add_action('edit_user_profile_update', 'tcs_save_local_test_plan');