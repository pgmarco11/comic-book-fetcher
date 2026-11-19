<?php

defined('ABSPATH') || exit;

final class TCS_Subscription_Management {
    public static function register(): void {
        add_shortcode(
            'tcs_manage_membership',
            [self::class, 'render']
        );

        add_action(
            'admin_post_tcs_manage_billing',
            [self::class, 'open_portal']
        );

        add_action(
            'template_redirect',
            [self::class, 'protect_page']
        );
    }

    public static function protect_page(): void {
        if (!is_page('manage-membership')) {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();

        if (!is_user_logged_in()) {
            wp_safe_redirect(
                wp_login_url(home_url('/manage-membership/'))
            );
            exit;
        }
    }

    private static function client(): \Stripe\StripeClient {
        $key = getenv('TCS_STRIPE_SECRET_KEY');

        if (
            wp_get_environment_type() !== 'local'
            || !class_exists(\Stripe\StripeClient::class)
            || !is_string($key)
            || !preg_match('/\A(?:sk|rk)_test_[A-Za-z0-9]+\z/', $key)
        ) {
            throw new RuntimeException(
                'Sandbox billing is unavailable.'
            );
        }

        return new \Stripe\StripeClient([
            'api_key' => $key,
            'stripe_version' => '2026-10-28.endive',
            'max_network_retries' => 1,
        ]);
    }

    private static function subscriptions(int $user_id): array {
        $saved = get_user_meta(
            $user_id,
            '_tcs_stripe_test_subscriptions',
            true
        );

        if (!is_array($saved) || !$saved) {
            return [];
        }

        $stripe = self::client();
        $subscriptions = [];

        foreach (array_keys($saved) as $id) {
            $subscription = $stripe->subscriptions->retrieve(
                $id,
                []
            );

            if (
                $subscription->livemode !== false
                || (string) (
                    $subscription->metadata->wp_user_id ?? ''
                ) !== (string) $user_id
                || (string) (
                    $subscription->metadata->tcs_site_id ?? ''
                ) !== TCS_Stripe_Membership::site_id()
            ) {
                throw new RuntimeException(
                    'Billing ownership could not be verified.'
                );
            }

            $subscriptions[$id] = $subscription;
        }

        return $subscriptions;
    }

    public static function has_subscription(int $user_id): bool {
        foreach (self::subscriptions($user_id) as $subscription) {
            if (
                !in_array(
                    $subscription->status,
                    ['canceled', 'incomplete_expired'],
                    true
                )
            ) {
                return true;
            }
        }

        return false;
    }

    public static function render(): string {
        if (!is_user_logged_in()) {
            return '<p>Please sign in to manage your membership.</p>';
        }

        $user_id = get_current_user_id();
        $premium = tcs_user_has_premium($user_id);

        try {
            $subscriptions = self::subscriptions($user_id);
        } catch (\Throwable $error) {
            return '<p role="alert">Billing details could not be loaded. Please refresh to try again.</p>';
        }

        $can_upgrade = !$premium;

        foreach ($subscriptions as $subscription) {
            if (
                !in_array(
                    $subscription->status,
                    ['canceled', 'incomplete_expired'],
                    true
                )
            ) {
                $can_upgrade = false;
            }
        }

        ob_start();
        ?>
        <div class="tcs-manage-membership">
            <p><strong>Current plan</strong></p>

            <h2>
                <?php echo esc_html($premium ? 'Premium' : 'Free'); ?>
            </h2>

            <p>
                <?php
                echo esc_html(
                    $premium
                        ? 'Unlimited comic issues.'
                        : 'Save up to 50 distinct comic issues.'
                );
                ?>
            </p>

            <?php foreach ($subscriptions as $id => $subscription) : ?>
                <div class="mt-4">
                    <p>
                        <strong>Subscription status</strong>
                        <?php
                        echo esc_html(
                            ucwords(
                                str_replace(
                                    '_',
                                    ' ',
                                    $subscription->status
                                )
                            )
                        );
                        ?>
                    </p>

                    <?php if ($subscription->cancel_at_period_end) : ?>
                        <p>
                            Your subscription is scheduled to end
                            at the end of its billing period.
                        </p>
                    <?php endif; ?>

                    <form
                        method="post"
                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                    >
                        <input
                            type="hidden"
                            name="action"
                            value="tcs_manage_billing"
                        >

                        <input
                            type="hidden"
                            name="subscription_id"
                            value="<?php echo esc_attr($id); ?>"
                        >

                        <?php wp_nonce_field('tcs_manage_billing'); ?>

                        <button
                            type="submit"
                            class="btn btn-primary mt-2"
                        >
                            Manage billing and subscription
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>

            <?php if ($can_upgrade) : ?>
                <p class="mt-4">
                    <a
                        class="btn btn-primary"
                        href="<?php echo esc_url(home_url('/premium-checkout/')); ?>"
                    >
                        Upgrade to Premium
                    </a>
                </p>
            <?php endif; ?>

            <p class="mt-4">
                <a href="<?php echo esc_url(tcs_inventory_url()); ?>">
                    Back to My Collection
                </a>
            </p>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public static function portal_url(
        int $user_id,
        string $id
    ): string {
        $subscriptions = self::subscriptions($user_id);
        $subscription = $subscriptions[$id] ?? null;

        if (!$subscription) {
            throw new RuntimeException(
                'This subscription is unavailable.'
            );
        }

        $session = self::client()->billingPortal->sessions->create([
            'customer' => $subscription->customer,
            'return_url' => home_url('/manage-membership/'),
        ]);

        $url = (string) $session->url;

        if (
            wp_parse_url($url, PHP_URL_SCHEME) !== 'https'
            || wp_parse_url($url, PHP_URL_HOST) !== 'billing.stripe.com'
        ) {
            throw new RuntimeException(
                'The billing portal URL is invalid.'
            );
        }

        return $url;
    }

    public static function open_portal(): void {
        if (
            !is_user_logged_in()
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        ) {
            wp_die(
                'Please sign in and open Manage Membership again.',
                '',
                ['response' => 403]
            );
        }

        check_admin_referer('tcs_manage_billing');

        $id = $_POST['subscription_id'] ?? '';

        if (
            !is_string($id)
            || !preg_match('/\Asub_[A-Za-z0-9]+\z/', $id)
        ) {
            wp_die(
                'The subscription is invalid.',
                '',
                ['response' => 400]
            );
        }

        try {
            $url = self::portal_url(get_current_user_id(), $id);
        } catch (\Throwable $error) {
            wp_die(
                'Billing could not be opened. Return to Manage Membership and try again.',
                '',
                ['response' => 503, 'back_link' => true]
            );
        }

        nocache_headers();
        wp_redirect($url, 303);
        exit;
    }
}

TCS_Subscription_Management::register();