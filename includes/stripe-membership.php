<?php

defined('ABSPATH') || exit;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_readable($autoload)) {
    require_once $autoload;
}

final class TCS_Stripe_Membership {
    private const EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
    ];

    public static function site_id(): string {
        $name = 'tcs_stripe_test_site_id';
        $value = get_option($name, '');

        if (!is_string($value) || $value === '') {
            add_option($name, wp_generate_uuid4(), '', false);
            $value = get_option($name, '');
        }

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(
                'Stripe site identity could not be saved.'
            );
        }

        return $value;
    }

    public static function register_route(): void {
        register_rest_route('tcs/v1', '/stripe-webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function webhook(WP_REST_Request $request) {
        $secret = getenv('TCS_STRIPE_SECRET_KEY');
        $signing_secret = getenv('TCS_STRIPE_WEBHOOK_SECRET');
        $price = get_option('tcs_stripe_test_price_id', '');

        if (
            wp_get_environment_type() !== 'local'
            || !class_exists(\Stripe\Webhook::class)
            || !is_string($secret)
            || !preg_match('/\A(?:sk|rk)_test_[A-Za-z0-9]+\z/', $secret)
            || !is_string($signing_secret)
            || !str_starts_with($signing_secret, 'whsec_')
            || !is_string($price)
            || !preg_match('/\Aprice_[A-Za-z0-9]+\z/', $price)
        ) {
            return new WP_Error(
                'tcs_stripe_config',
                'Stripe webhook configuration is incomplete.',
                ['status' => 503]
            );
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $request->get_body(),
                $request->get_header('stripe-signature'),
                $signing_secret
            );
        } catch (
            \UnexpectedValueException
            | \Stripe\Exception\SignatureVerificationException $error
        ) {
            return new WP_Error(
                'tcs_stripe_signature',
                'Invalid Stripe webhook.',
                ['status' => 400]
            );
        }

        if ($event->livemode !== false) {
            return new WP_Error(
                'tcs_stripe_mode',
                'This endpoint accepts sandbox events.',
                ['status' => 400]
            );
        }

        if (!in_array($event->type, self::EVENTS, true)) {
            return new WP_REST_Response(['received' => true], 200);
        }

        try {
            $subscription = $event->data->object;
            $owner = (string) (
                $subscription->metadata->wp_user_id ?? ''
            );
            $site = (string) (
                $subscription->metadata->tcs_site_id ?? ''
            );

            if (
                $site !== self::site_id()
                || !ctype_digit($owner)
                || (int) $owner < 1
            ) {
                return new WP_REST_Response([
                    'received' => true,
                    'ignored' => true,
                ], 200);
            }

            $user_id = (int) $owner;

            if (!get_userdata($user_id)) {
                return new WP_REST_Response([
                    'received' => true,
                    'ignored' => true,
                ], 200);
            }

            $stripe = new \Stripe\StripeClient([
                'api_key' => $secret,
                'stripe_version' => '2026-10-28.endive',
                'max_network_retries' => 1,
            ]);

            $lock = new TCS_Collection_Import_Lock(
                'stripe-membership:' . $user_id
            );

            $lock->run(
                static function (
                    TCS_Collection_Import_Lock $lock
                ) use (
                    $stripe,
                    $subscription,
                    $user_id,
                    $price
                ): void {
                    wp_cache_delete($user_id, 'user_meta');

                    $key = '_tcs_stripe_test_subscriptions';
                    $subscriptions = get_user_meta(
                        $user_id,
                        $key,
                        true
                    );

                    if (!is_array($subscriptions)) {
                        $subscriptions = [];
                    }

                    $subscriptions[$subscription->id] = '';
                    $premium = false;

                    foreach (array_keys($subscriptions) as $id) {
                        $current = $stripe->subscriptions->retrieve(
                            $id,
                            []
                        );

                        if (
                            $current->livemode !== false
                            || (string) (
                                $current->metadata->wp_user_id ?? ''
                            ) !== (string) $user_id
                            || (string) (
                                $current->metadata->tcs_site_id ?? ''
                            ) !== self::site_id()
                        ) {
                            throw new RuntimeException(
                                'Stripe subscription ownership could not be verified.'
                            );
                        }

                        $subscriptions[$id] = $current->status;

                        foreach ($current->items->data as $item) {
                            if (
                                $current->status === 'active'
                                && $item->price->id === $price
                            ) {
                                $premium = true;
                            }
                        }
                    }

                    $lock->assert_owned();

                    self::save_meta(
                        $user_id,
                        $key,
                        $subscriptions
                    );

                    self::save_meta(
                        $user_id,
                        '_tcs_membership_plan',
                        $premium ? 'premium' : 'free'
                    );

                    if ($premium) {
                        delete_user_meta(
                            $user_id,
                            '_tcs_needs_plan_choice'
                        );

                        if (
                            get_user_meta(
                                $user_id,
                                '_tcs_needs_plan_choice',
                                true
                            ) !== ''
                        ) {
                            throw new RuntimeException(
                                'The plan-choice flag could not be cleared.'
                            );
                        }
                    }
                }
            );
        } catch (\Throwable $error) {
            error_log(
                'TCS Stripe membership sync failed. Type: '
                . get_class($error)
            );

            return new WP_Error(
                'tcs_stripe_sync',
                'Membership sync could not complete. Retry this event.',
                ['status' => 503]
            );
        }

        return new WP_REST_Response(['received' => true], 200);
    }

    private static function save_meta(
        int $user_id,
        string $key,
        $value
    ): void {
        update_user_meta($user_id, $key, $value);

        if (get_user_meta($user_id, $key, true) !== $value) {
            throw new RuntimeException(
                'Stripe membership data could not be saved.'
            );
        }
    }
}

add_action(
    'rest_api_init',
    [TCS_Stripe_Membership::class, 'register_route']
);