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

        add_action(
            'admin_post_tcs_cancel_premium',
            [self::class, 'cancel_premium']
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
            <p class="tcs-membership-back">
                <a href="<?php echo esc_url(tcs_inventory_url()); ?>">
                    Back to My Collection
                </a>
            </p>
    
            <p class="tcs-membership-plan">
                Current plan
                <strong>
                    <?php echo esc_html($premium ? 'Premium' : 'Free'); ?>
                </strong>
            </p>
    
            <?php if (!$subscriptions) : ?>
                <p>
                    <?php
                    echo esc_html(
                        $premium
                            ? 'Unlimited comic issues.'
                            : 'Save up to 50 distinct comic issues.'
                    );
                    ?>
                </p>
            <?php endif; ?>
    
            <?php foreach ($subscriptions as $id => $subscription) : ?>
                <?php
                $pending = self::cancellation_pending($subscription);
    
                $date = $pending
                    ? (int) ($subscription->cancel_at ?? 0)
                    : 0;
    
                $date = $date ?: self::period_end($subscription);
                $date_label = $pending ? 'Ends on' : 'Next renewal';
    
                if ($subscription->status !== 'active' && !$pending) {
                    $date_label = 'Current period ends';
                }
    
                if (
                    in_array(
                        $subscription->status,
                        ['canceled', 'incomplete_expired'],
                        true
                    )
                ) {
                    $date = (int) ($subscription->ended_at ?? 0);
                    $date_label = 'Ended on';
                }
                ?>
    
                <div class="tcs-membership-record">
                    <section
                        class="tcs-membership-summary"
                        aria-label="Subscription summary"
                    >
                        <h2>Premium subscription</h2>
    
                        <p class="tcs-membership-status">
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
    
                            <?php if ($pending) : ?>
                                — Cancellation scheduled
                            <?php endif; ?>
                        </p>
    
                        <?php if ($date > 0) : ?>
                            <dl class="tcs-membership-date">
                                <dt>
                                    <?php echo esc_html($date_label); ?>
                                </dt>
                                <dd>
                                    <?php
                                    echo esc_html(
                                        wp_date(
                                            get_option('date_format'),
                                            $date
                                        )
                                    );
                                    ?>
                                </dd>
                            </dl>
                        <?php endif; ?>
                    </section>
    
                    <section
                        class="tcs-membership-section"
                        aria-label="Billing"
                    >
                        <h3>Billing</h3>
                        <p>Update your payment method or view invoices.</p>
    
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
    
                            <?php
                            wp_nonce_field(
                                'tcs_manage_billing',
                                '_wpnonce',
                                false
                            );
                            ?>
    
                            <button
                                type="submit"
                                class="btn tcs-membership-billing"
                            >
                                Manage billing
                            </button>
                        </form>
                    </section>
    
                    <?php if ($pending || self::can_cancel($subscription)) : ?>
                        <section
                            class="tcs-membership-section"
                            aria-label="Cancellation"
                        >
                            <h3>Cancellation</h3>
    
                            <?php if ($pending) : ?>
                                <p>
                                    Cancellation is scheduled for
                                    the date shown above.
                                </p>
                            <?php else : ?>
                                <p>
                                    If you cancel, your subscription ends
                                    at the end of its current billing period.
                                </p>
    
                                <details class="tcs-membership-confirm">
                                    <summary class="btn tcs-membership-cancel">
                                        Cancel Premium
                                    </summary>
    
                                    <div class="tcs-membership-confirm-body">
                                        <p>
                                            <strong>Confirm cancellation?</strong>
                                        </p>
    
                                        <p>
                                            This stops renewal at the end
                                            of the current billing period.
                                        </p>
    
                                        <form
                                            method="post"
                                            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                        >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="tcs_cancel_premium"
                                            >
    
                                            <input
                                                type="hidden"
                                                name="subscription_id"
                                                value="<?php echo esc_attr($id); ?>"
                                            >
    
                                            <?php
                                            wp_nonce_field(
                                                'tcs_cancel_premium:' . $id,
                                                '_wpnonce',
                                                false
                                            );
                                            ?>
    
                                            <button
                                                type="submit"
                                                class="btn tcs-membership-cancel"
                                            >
                                                Confirm cancellation
                                            </button>
    
                                            <a href="<?php echo esc_url(home_url('/manage-membership/')); ?>">
                                                Keep subscription
                                            </a>
                                        </form>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
    
            <?php if ($can_upgrade) : ?>
                <p class="tcs-membership-upgrade">
                    <a
                        class="btn tcs-membership-billing"
                        href="<?php echo esc_url(home_url('/premium-checkout/')); ?>"
                    >
                        Upgrade to Premium
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    
        return (string) ob_get_clean();
    }

    private static function period_end(
        \Stripe\Subscription $subscription
    ): int {
        $dates = [];
    
        foreach ($subscription->items->data ?? [] as $item) {
            $end = (int) ($item->current_period_end ?? 0);
    
            if ($end > 0) {
                $dates[] = $end;
            }
        }
    
        return $dates
            ? min($dates)
            : (int) ($subscription->current_period_end ?? 0);
    }
    
    private static function cancellation_pending(
        \Stripe\Subscription $subscription
    ): bool {
        return !in_array(
            $subscription->status,
            ['canceled', 'incomplete_expired'],
            true
        ) && (
            $subscription->cancel_at_period_end
            || (int) ($subscription->cancel_at ?? 0) > time()
        );
    }
    
    private static function can_cancel(
        \Stripe\Subscription $subscription
    ): bool {
        return !self::cancellation_pending($subscription)
            && in_array(
                $subscription->status,
                ['active', 'trialing', 'past_due', 'unpaid', 'paused'],
                true
            );
    }
    
    public static function schedule_cancellation(
        int $user_id,
        string $id
    ): void {
        $lock = new TCS_Collection_Import_Lock(
            'stripe-membership:' . $user_id
        );
    
        $lock->run(
            static function (
                TCS_Collection_Import_Lock $lock
            ) use ($user_id, $id): void {
                $subscriptions = self::subscriptions($user_id);
                $subscription = $subscriptions[$id] ?? null;
    
                if (!$subscription) {
                    throw new RuntimeException(
                        'This subscription is unavailable.'
                    );
                }
    
                if (self::cancellation_pending($subscription)) {
                    return;
                }
    
                if (!self::can_cancel($subscription)) {
                    throw new RuntimeException(
                        'This subscription cannot be scheduled for cancellation.'
                    );
                }
    
                $lock->assert_owned();
    
                $updated = self::client()->subscriptions->update(
                    $id,
                    ['cancel_at_period_end' => true]
                );
    
                if (!$updated->cancel_at_period_end) {
                    throw new RuntimeException(
                        'Cancellation could not be confirmed.'
                    );
                }
            }
        );
    }
    
    public static function cancel_premium(): void {
        if (
            !is_user_logged_in()
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        ) {
            wp_die(
                'Sign in and open Manage Membership again.',
                '',
                ['response' => 403]
            );
        }
    
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
    
        check_admin_referer('tcs_cancel_premium:' . $id);
    
        try {
            self::schedule_cancellation(
                get_current_user_id(),
                $id
            );
        } catch (\Throwable $error) {
            wp_die(
                'Cancellation could not be confirmed. Return to Manage Membership and check the subscription before trying again.',
                '',
                ['response' => 503, 'back_link' => true]
            );
        }
    
        wp_safe_redirect(home_url('/manage-membership/'), 303);
        exit;
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