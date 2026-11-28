<?php
defined('ABSPATH') || exit;

final class TCS_Premium_Checkout_Sessions {
    private const META = '_tcs_stripe_test_checkout';

    public static function start(
        int $user_id,
        string $interval,
        string $price_id
    ): array {
        $lock = new TCS_Collection_Import_Lock(
            'stripe-membership:' . $user_id
        );

        return $lock->run(
            static function (
                TCS_Collection_Import_Lock $lock
            ) use ($user_id, $interval, $price_id): array {
                $stripe = TCS_Subscription_Management::client();

                for ($pass = 0; $pass < 3; $pass++) {
                    wp_cache_delete($user_id, 'user_meta');

                    $saved = get_user_meta(
                        $user_id,
                        self::META,
                        true
                    );

                    if ($saved === '') {
                        self::assert_no_subscription($user_id);

                        $owner = [
                            'wp_user_id' => (string) $user_id,
                            'tcs_site_id' =>
                                TCS_Stripe_Membership::site_id(),
                        ];

                        $saved = [
                            'attempt' => wp_generate_uuid4(),
                            'created' => time(),
                            'interval' => $interval,
                            'price_id' => $price_id,
                            'session_id' => '',
                            'params' => [
                                'mode' => 'subscription',
                                'ui_mode' => 'elements',
                                'line_items' => [
                                    [
                                        'price' => $price_id,
                                        'quantity' => 1,
                                    ],
                                ],
                                'client_reference_id' =>
                                    (string) $user_id,
                                'customer_email' =>
                                    wp_get_current_user()->user_email,
                                'metadata' => $owner,
                                'subscription_data' => [
                                    'metadata' => $owner,
                                ],
                                'return_url' => add_query_arg(
                                    'checkout_return',
                                    '1',
                                    home_url('/premium-checkout/')
                                ),
                            ],
                        ];

                        // Persist the attempt before contacting Stripe.
                        self::save($user_id, $saved, $lock);
                    }

                    if (
                        !is_array($saved)
                        || !isset(
                            $saved['attempt'],
                            $saved['created'],
                            $saved['params'],
                            $saved['session_id']
                        )
                    ) {
                        throw new RuntimeException(
                            'The saved checkout needs administrator review.',
                            503
                        );
                    }

                    $lock->assert_owned();

                    if ($saved['session_id'] !== '') {
                        $session = $stripe->checkout->sessions->retrieve(
                            $saved['session_id'],
                            []
                        );
                    } else {
                        // Avoid replaying an unresolved request after
                        // Stripe's idempotency-retention window.
                        if (
                            time() - $saved['created']
                            >= 23 * HOUR_IN_SECONDS
                        ) {
                            throw new RuntimeException(
                                'Your earlier checkout needs administrator review. Please contact support before paying again.',
                                409
                            );
                        }

                        $session = $stripe->checkout->sessions->create(
                            $saved['params'],
                            [
                                'idempotency_key' =>
                                    'tcs-checkout-' . $saved['attempt'],
                            ]
                        );
                    }

                    self::validate($session, $user_id);

                    if ($saved['session_id'] === '') {
                        $saved['session_id'] = $session->id;
                        self::save($user_id, $saved, $lock);
                    }

                    if ($session->status === 'complete') {
                        // Payment may have completed before the webhook.
                        $subscription = $stripe->subscriptions->retrieve(
                            (string) $session->subscription,
                            []
                        );

                        self::validate_owner(
                            $subscription,
                            $user_id
                        );

                        if (
                            in_array(
                                $subscription->status,
                                ['canceled', 'incomplete_expired'],
                                true
                            )
                        ) {
                            self::clear($user_id, $lock);
                            continue;
                        }

                        return ['activating' => true];
                    }

                    if ($session->status === 'expired') {
                        self::clear($user_id, $lock);
                        continue;
                    }

                    if ($session->status !== 'open') {
                        throw new RuntimeException(
                            'Checkout status could not be confirmed. Please try again.',
                            503
                        );
                    }

                    self::assert_no_subscription($user_id);

                    if (
                        $saved['interval'] === $interval
                        && $saved['price_id'] === $price_id
                    ) {
                        if (
                            !is_string($session->client_secret)
                            || $session->client_secret === ''
                        ) {
                            throw new RuntimeException(
                                'Checkout could not be loaded. Please try again.',
                                503
                            );
                        }

                        return [
                            'clientSecret' => $session->client_secret,
                        ];
                    }

                    // A different billing period requires closing
                    // the old payable session first.
                    $lock->assert_owned();

                    try {
                        $expired = $stripe->checkout->sessions->expire(
                            $session->id,
                            []
                        );
                    } catch (\Stripe\Exception\ApiErrorException $error) {
                        // Payment or expiration may have succeeded
                        // while the response was being returned.
                        $expired = $stripe->checkout->sessions->retrieve(
                            $session->id,
                            []
                        );
                    }

                    self::validate($expired, $user_id);

                    if ($expired->status === 'expired') {
                        self::clear($user_id, $lock);
                        continue;
                    }

                    if ($expired->status === 'complete') {
                        continue;
                    }

                    throw new RuntimeException(
                        'Your previous checkout could not be closed. Please try again before changing plans.',
                        503
                    );
                }

                throw new RuntimeException(
                    'Checkout changed. Refresh the page and try again.',
                    409
                );
            }
        );
    }

    private static function assert_no_subscription(
        int $user_id
    ): void {
        if (
            TCS_Subscription_Management::has_subscription($user_id)
        ) {
            throw new RuntimeException(
                'You already have a subscription. Open Manage Membership to manage billing or cancellation.',
                409
            );
        }
    }

    private static function validate_owner(
        $object,
        int $user_id
    ): void {
        if (
            $object->livemode !== false
            || (string) (
                $object->metadata->wp_user_id ?? ''
            ) !== (string) $user_id
            || (string) (
                $object->metadata->tcs_site_id ?? ''
            ) !== TCS_Stripe_Membership::site_id()
        ) {
            throw new RuntimeException(
                'Checkout ownership could not be verified.',
                503
            );
        }
    }

    private static function validate(
        \Stripe\Checkout\Session $session,
        int $user_id
    ): void {
        self::validate_owner($session, $user_id);

        if (
            $session->mode !== 'subscription'
            || $session->ui_mode !== 'elements'
            || (string) $session->client_reference_id
                !== (string) $user_id
        ) {
            throw new RuntimeException(
                'Checkout details could not be verified.',
                503
            );
        }
    }

    private static function save(
        int $user_id,
        array $saved,
        TCS_Collection_Import_Lock $lock
    ): void {
        $lock->assert_owned();

        update_user_meta($user_id, self::META, $saved);
        wp_cache_delete($user_id, 'user_meta');

        if (
            get_user_meta($user_id, self::META, true)
            !== $saved
        ) {
            throw new RuntimeException(
                'Checkout could not be saved. Please try again.',
                503
            );
        }
    }

    private static function clear(
        int $user_id,
        TCS_Collection_Import_Lock $lock
    ): void {
        $lock->assert_owned();

        delete_user_meta($user_id, self::META);
        wp_cache_delete($user_id, 'user_meta');

        if (
            get_user_meta($user_id, self::META, true) !== ''
        ) {
            throw new RuntimeException(
                'The previous checkout could not be cleared.',
                503
            );
        }
    }
}