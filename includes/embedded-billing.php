<?php
/** Local sandbox billing page and card-update endpoints. */
defined('ABSPATH') || exit();

final class TCS_Embedded_Billing
{
    public static function register(): void
    {
        add_shortcode('tcs_manage_billing', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'protect_page']);
        add_action('wp_ajax_tcs_embedded_billing', [self::class, 'ajax']);
    }

    public static function protect_page(): void
    {
        $post = get_post();
        if (!is_page() || !$post || !has_shortcode($post->post_content, 'tcs_manage_billing')) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(get_permalink($post)));
            exit();
        }
    }

    private static function id($value): string
    {
        return is_string($value) ? $value : (string) ($value->id ?? '');
    }

    private static function context(int $user_id, string $id): array
    {
        if ($user_id < 1) {
            throw new DomainException('Sign in to access billing.');
        }
        $subscriptions = TCS_Subscription_Management::subscriptions($user_id);
        $subscription = $subscriptions[$id] ?? null;
        if (!$subscription) {
            throw new DomainException('This subscription is unavailable.');
        }
        return [
            TCS_Subscription_Management::client(),
            $subscription,
            self::id($subscription->customer),
        ];
    }

    private static function save_pending(int $user_id, string $id, array $value): void
    {
        $key = '_tcs_billing_setup_' . $id;
        update_user_meta($user_id, $key, $value);
        if (get_user_meta($user_id, $key, true) !== $value) {
            throw new RuntimeException('The payment update could not be recorded.');
        }
    }

    public static function operate(int $user_id, string $id, string $operation, array $input): array
    {
        $lock = new TCS_Collection_Import_Lock('stripe-membership:' . $user_id);
        return $lock->run(static function (TCS_Collection_Import_Lock $lock) use (
            $user_id,
            $id,
            $operation,
            $input,
        ): array {
            [$stripe, $subscription, $customer] = self::context($user_id, $id);
            if ($operation === 'details') {
                $name = sanitize_text_field($input['name'] ?? '');
                $email = sanitize_email($input['email'] ?? '');
                if (
                    $name === '' ||
                    strlen($name) > 100 ||
                    !is_email($email) ||
                    strlen($email) > 254
                ) {
                    throw new InvalidArgumentException('Enter a name and a valid billing email.');
                }
                $updated = $stripe->customers->update($customer, [
                    'name' => $name,
                    'email' => $email,
                ]);
                if ($updated->name !== $name || $updated->email !== $email) {
                    throw new RuntimeException('The billing details could not be confirmed.');
                }
                return ['message' => 'Billing details saved.'];
            }
            if (in_array($subscription->status, ['canceled', 'incomplete_expired'], true)) {
                throw new InvalidArgumentException('This subscription has ended.');
            }
            if ($operation === 'setup') {
                $intent = $stripe->setupIntents->create([
                    'customer' => $customer,
                    'allowed_payment_method_types' => ['card'],
                    'usage' => 'off_session',
                    'metadata' => [
                        'wp_user_id' => (string) $user_id,
                        'tcs_site_id' => TCS_Stripe_Membership::site_id(),
                        'subscription_id' => $id,
                    ],
                ]);
                self::save_pending($user_id, $id, [
                    'id' => $intent->id,
                    'approved' => false,
                    'applied' => false,
                ]);
                return ['clientSecret' => $intent->client_secret, 'setupId' => $intent->id];
            }
            $pending = get_user_meta($user_id, '_tcs_billing_setup_' . $id, true);
            if (!is_array($pending) || ($pending['id'] ?? '') !== ($input['setup_id'] ?? '')) {
                return [
                    'superseded' => true,
                    'message' => 'A newer payment update was started. Reload this page.',
                ];
            }
            if ($operation === 'approve') {
                if (($input['consent'] ?? '') !== '1') {
                    throw new InvalidArgumentException(
                        'Confirm that this card may be used for membership renewals.',
                    );
                }
                $pending['approved'] = true;
                self::save_pending($user_id, $id, $pending);
                return ['message' => 'Payment update authorized.'];
            }
            if ($operation !== 'finish') {
                throw new InvalidArgumentException('Invalid billing operation.');
            }
            if (!($pending['approved'] ?? false)) {
                throw new InvalidArgumentException('Payment update authorization is missing.');
            }
            if ($pending['applied'] ?? false) {
                return ['message' => 'Payment method saved.'];
            }
            $intent = $stripe->setupIntents->retrieve($pending['id'], []);
            if (
                $intent->livemode !== false ||
                $intent->status !== 'succeeded' ||
                self::id($intent->customer) !== $customer ||
                (string) ($intent->metadata->wp_user_id ?? '') !== (string) $user_id ||
                (string) ($intent->metadata->tcs_site_id ?? '') !==
                    TCS_Stripe_Membership::site_id() ||
                (string) ($intent->metadata->subscription_id ?? '') !== $id
            ) {
                throw new InvalidArgumentException('Stripe has not confirmed this payment update.');
            }
            $method_id = self::id($intent->payment_method);
            $method = $stripe->paymentMethods->retrieve($method_id, []);
            if (
                $method->livemode !== false ||
                self::id($method->customer) !== $customer ||
                $method->type !== 'card'
            ) {
                throw new DomainException('Payment method ownership could not be verified.');
            }
            $lock->assert_owned();
            $updated = $stripe->subscriptions->update($id, [
                'default_payment_method' => $method_id,
            ]);
            if (self::id($updated->default_payment_method) !== $method_id) {
                throw new RuntimeException('The membership payment method could not be confirmed.');
            }
            $pending['applied'] = true;
            self::save_pending($user_id, $id, $pending);
            return ['message' => 'Payment method saved.'];
        });
    }

    public static function ajax(): void
    {
        if (!is_user_logged_in() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(['message' => 'Please sign in again.'], 403);
            return;
        }
        if (!check_ajax_referer('tcs_embedded_billing', 'nonce', false)) {
            wp_send_json_error(['message' => 'Refresh the billing page and try again.'], 403);
            return;
        }
        $input = wp_unslash($_POST);
        $id = $input['subscription_id'] ?? '';
        $operation = $input['operation'] ?? '';
        if (
            !is_string($id) ||
            !preg_match('/\Asub_[A-Za-z0-9]+\z/', $id) ||
            !in_array($operation, ['setup', 'approve', 'finish', 'details'], true)
        ) {
            wp_send_json_error(['message' => 'Invalid billing request.'], 400);
            return;
        }
        foreach (['name', 'email', 'setup_id', 'consent'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) {
                wp_send_json_error(['message' => 'Invalid billing fields.'], 400);
                return;
            }
        }
        try {
            $result = self::operate(get_current_user_id(), $id, $operation, $input);
        } catch (DomainException $error) {
            wp_send_json_error(['message' => $error->getMessage()], 403);
            return;
        } catch (InvalidArgumentException $error) {
            wp_send_json_error(['message' => $error->getMessage()], 400);
            return;
        } catch (Throwable $error) {
            $details = [
                'operation' => $operation,
                'type'      => get_class($error),
            ];
            
            if ($error instanceof \Stripe\Exception\ApiErrorException) {
                $stripe_error = $error->getError();
            
                $details['http_status'] = $error->getHttpStatus();
                $details['stripe_code'] = $error->getStripeCode();
                $details['parameter']   = $stripe_error->param ?? '';
                $details['request_id']  = $error->getRequestId();
            } else {
                $details['message'] = $error->getMessage();
            }
            
            error_log(
                'TCS billing update failed: ' . wp_json_encode($details)
            );

            wp_send_json_error(
                ['message' => 'The billing update could not complete. Please try again.'],
                503,
            );
            return;
        }
        nocache_headers();
        wp_send_json_success($result);
    }

    /** Called only after the existing Stripe endpoint verifies the event signature. */
    public static function setup_webhook($intent)
    {
        if ((string) ($intent->metadata->tcs_site_id ?? '') !== TCS_Stripe_Membership::site_id()) {
            return new WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }
        $owner = (string) ($intent->metadata->wp_user_id ?? '');
        $id = (string) ($intent->metadata->subscription_id ?? '');
        if (
            !ctype_digit($owner) ||
            (int) $owner < 1 ||
            !get_userdata((int) $owner) ||
            !preg_match('/\Asub_[A-Za-z0-9]+\z/', $id)
        ) {
            return new WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }
        try {
            self::operate((int) $owner, $id, 'finish', ['setup_id' => $intent->id]);
        } catch (Throwable $error) {
            return new WP_Error(
                'tcs_billing_sync',
                'Payment method synchronization could not finish.',
                ['status' => 503],
            );
        }
        return new WP_REST_Response(['received' => true], 200);
    }

    public static function money(int $amount, string $currency): string
    {
        // The current membership checkout uses USD.
        return number_format_i18n($amount / 100, 2) . ' ' . strtoupper($currency);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Please sign in to manage billing.</p>';
        }
        try {
            $user_id = get_current_user_id();
            $subscriptions = TCS_Subscription_Management::subscriptions($user_id);
            $id = $_GET['subscription_id'] ?? '';
            if (!is_string($id)) {
                throw new DomainException('Invalid subscription selection.');
            }
            if ($id === '' && $subscriptions) {
                $current = TCS_Subscription_Management::current_subscription(
                    $subscriptions
                );
            
                $id = $current ? (string) $current->id : '';
            }
            if (!$subscriptions) {
                return '<p>No billing history is linked to this account. <a href="' .
                    esc_url(home_url('/manage-membership/')) .
                    '">Back to Manage Membership</a></p>';
            }
            $subscription = $subscriptions[$id] ?? null;
            if (!$subscription) {
                throw new DomainException('This subscription is unavailable.');
            }
            $stripe = TCS_Subscription_Management::client();
            $customer_id = self::id($subscription->customer);
            $customer = $stripe->customers->retrieve($customer_id, []);
            if (!empty($customer->deleted) || $customer->livemode !== false) {
                throw new DomainException('This billing account is unavailable.');
            }
            $method_id = self::id($subscription->default_payment_method);
            $legacy_source = self::id($subscription->default_source);
            if ($method_id === '' && $legacy_source === '') {
                $method_id = self::id($customer->invoice_settings->default_payment_method ?? '');
            }
            $method_label = 'No saved payment method';
            $method_expiry = '';
            if ($method_id !== '') {
                $method = $stripe->paymentMethods->retrieve($method_id, []);
                if (self::id($method->customer) !== $customer_id || $method->livemode !== false) {
                    throw new DomainException('Payment method ownership could not be verified.');
                }
                $method_label = ucwords(str_replace('_', ' ', $method->type));
                if ($method->type === 'card') {
                    $method_label =
                        ucfirst($method->card->brand) . ' ending in ' . $method->card->last4;
                    $method_expiry = sprintf(
                        'Expires %02d/%d',
                        $method->card->exp_month,
                        $method->card->exp_year,
                    );
                }
            } elseif ($legacy_source !== '' || self::id($customer->default_source ?? '') !== '') {
                $method_label = 'A saved legacy payment method is on file';
            }
            $invoice_after = $_GET['invoice_after'] ?? '';
            if (
                !is_string($invoice_after) ||
                ($invoice_after !== '' && !preg_match('/\Ain_[A-Za-z0-9]+\z/', $invoice_after))
            ) {
                throw new DomainException('Invalid invoice page.');
            }
            $parameters = ['customer' => $customer_id, 'subscription' => $id, 'limit' => 10];
            if ($invoice_after !== '') {
                $parameters['starting_after'] = $invoice_after;
            }
            $invoices = $stripe->invoices->all($parameters);
            foreach ($invoices->data as $invoice) {
                if ($invoice->livemode !== false || self::id($invoice->customer) !== $customer_id) {
                    throw new DomainException('Invoice ownership could not be verified.');
                }
            }
            $publishable_key = getenv('TCS_STRIPE_PUBLISHABLE_KEY');
            if (
                !is_string($publishable_key) ||
                !preg_match('/\Apk_test_[A-Za-z0-9]+\z/', $publishable_key)
            ) {
                throw new RuntimeException('The sandbox publishable key is unavailable.');
            }
            $can_change = !in_array(
                $subscription->status,
                ['canceled', 'incomplete_expired'],
                true,
            );
            $billing_url = add_query_arg(
                'subscription_id',
                $id,
                home_url('/manage-membership/billing/'),
            );
            wp_enqueue_style(
                'tcs-embedded-billing',
                COMICBOOKS_PLUGIN_URL . 'css/embedded-billing.css',
                [],
                filemtime(COMICBOOKS_PLUGIN_DIR . 'css/embedded-billing.css'),
            );
            wp_enqueue_script(
                'tcs-stripe-js',
                'https://js.stripe.com/endive/stripe.js',
                [],
                null,
                true,
            );
            wp_enqueue_script(
                'tcs-embedded-billing',
                COMICBOOKS_PLUGIN_URL . 'js/embedded-billing.js',
                ['tcs-stripe-js'],
                filemtime(COMICBOOKS_PLUGIN_DIR . 'js/embedded-billing.js'),
                true,
            );
            $template = COMICBOOKS_PLUGIN_DIR . 'templates/embedded-billing.php';
            ob_start();
            try {
                include $template;
                return (string) ob_get_clean();
            } catch (Throwable $error) {
                ob_end_clean();
                throw $error;
            }
        } catch (Throwable $error) {
            error_log('TCS billing page failed. Type: ' . get_class($error));
            return '<p role="alert">Billing details could not be loaded. Please refresh to try again.</p>';
        }
    }
}

TCS_Embedded_Billing::register();
