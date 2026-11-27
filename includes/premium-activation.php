<?php
defined('ABSPATH') || exit;

final class TCS_Premium_Activation {
    public static function status(): void {
        nocache_headers();

        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_send_json_error([
                'message' => 'Your session expired. Sign in again to check your membership.',
            ], 401);
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error([
                'message' => 'Use the membership activation check.',
            ], 405);
            return;
        }

        if (!check_ajax_referer(
            'tcs_premium_activation',
            'security',
            false
        )) {
            wp_send_json_error([
                'message' => 'Refresh this page to check your membership again.',
            ], 403);
            return;
        }

        $user_id = get_current_user_id();

        wp_cache_delete($user_id, 'user_meta');

        // Only reads membership status. The webhook grants access.
        wp_send_json_success([
            'premium' => tcs_user_has_premium($user_id),
        ]);
    }

    public static function render(bool $hidden = false): string {
        $path = 'js/premium-activation.js';

        wp_enqueue_script(
            'tcs-premium-activation',
            COMICBOOKS_PLUGIN_URL . $path,
            [],
            (string) filemtime(COMICBOOKS_PLUGIN_DIR . $path),
            true
        );

        ob_start();
        ?>
        <section
            class="tcs-premium-activation"
            data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce('tcs_premium_activation')); ?>"
            data-return-url="<?php echo esc_url(home_url('/my-collection/')); ?>"
            <?php if ($hidden) echo 'hidden'; ?>
            aria-label="Membership activation"
        >
            <p data-activation-status role="status" aria-live="polite">
                Activating your membership…
            </p>

            <p data-activation-error role="alert"></p>

            <button
                type="button"
                class="btn btn-primary"
                data-activation-retry
                hidden
            >
                Check activation again
            </button>

            <p>
                If you completed checkout, do not submit another payment
                while activation is pending.
            </p>

            <a href="<?php echo esc_url(home_url('/manage-membership/')); ?>">
                Manage Membership
            </a>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}

add_action(
    'wp_ajax_tcs_premium_activation_status',
    [TCS_Premium_Activation::class, 'status']
);

add_action(
    'wp_ajax_nopriv_tcs_premium_activation_status',
    [TCS_Premium_Activation::class, 'status']
);