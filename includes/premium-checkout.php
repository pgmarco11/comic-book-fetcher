<?php

defined('ABSPATH') || exit;

final class TCS_Premium_Checkout {
    private const PRICE_OPTION = 'tcs_stripe_test_price_id';
    private const ANNUAL_PRICE_OPTION = 'tcs_stripe_test_annual_price_id';
    private const SETTINGS_GROUP = 'tcs_stripe_checkout';
    private const ACTION = 'tcs_start_stripe_checkout';

    public static function register(): void {
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_menu', [self::class, 'register_menu']);
        add_shortcode('tcs_stripe_checkout', [self::class, 'render_shortcode']);
        add_action('wp_ajax_' . self::ACTION, [self::class, 'start_checkout']);
    }

    private static function secret(): string {
        if (wp_get_environment_type() !== 'local') {
            return '';
        }

        $secret = getenv('TCS_STRIPE_SECRET_KEY');

        return is_string($secret) && preg_match('/\A(?:sk|rk)_test_[A-Za-z0-9]+\z/', $secret)
            ? $secret
            : '';
    }

    public static function price_ids(): array {
        $prices = [];
    
        foreach ([
            'monthly' => self::PRICE_OPTION,
            'annual'  => self::ANNUAL_PRICE_OPTION,
        ] as $interval => $option) {
            $price_id = get_option($option, '');
    
            if (is_string($price_id) && self::valid_price_id($price_id)) {
                $prices[$interval] = $price_id;
            }
        }
    
        return $prices;
    }
    
    private static function price_id(string $interval = 'monthly'): string {
        return self::price_ids()[$interval] ?? '';
    }

    private static function publishable_key(): string {
        $key = getenv('TCS_STRIPE_PUBLISHABLE_KEY');

        return is_string($key) && preg_match('/\Apk_test_[A-Za-z0-9]+\z/', $key)
            ? $key
            : '';
    }

    private static function valid_price_id(string $price_id): bool {
        return preg_match('/\Aprice_[A-Za-z0-9]+\z/', $price_id) === 1;
    }

    public static function register_settings(): void {
        register_setting(self::SETTINGS_GROUP, self::PRICE_OPTION, [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => [self::class, 'sanitize_price_id'],
        ]);
    
        register_setting(self::SETTINGS_GROUP, self::ANNUAL_PRICE_OPTION, [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => [self::class, 'sanitize_annual_price_id'],
        ]);
    }

    public static function sanitize_annual_price_id($value): string {
        $price_id = is_string($value) ? trim($value) : null;
    
        if (
            $price_id !== null
            && ($price_id === '' || self::valid_price_id($price_id))
        ) {
            return $price_id;
        }
    
        add_settings_error(
            self::ANNUAL_PRICE_OPTION,
            'tcs_stripe_invalid_annual_price',
            __('Enter an annual Stripe Price ID that begins with price_.', 'comic-books-fetcher')
        );
    
        return self::price_id('annual');
    }

    public static function sanitize_price_id($value): string {
        $price_id = is_string($value) ? trim($value) : null;

        if ($price_id !== null && ($price_id === '' || self::valid_price_id($price_id))) {
            return $price_id;
        }

        add_settings_error(
            self::PRICE_OPTION,
            'tcs_stripe_invalid_price',
            __('Enter a Stripe Price ID that begins with price_.', 'comic-books-fetcher')
        );

        return self::price_id();
    }

    public static function register_menu(): void {
        add_options_page(
            __('Stripe Checkout', 'comic-books-fetcher'),
            __('Stripe Checkout', 'comic-books-fetcher'),
            'manage_options',
            'tcs-stripe-checkout',
            [self::class, 'render_settings']
        );
    }

    public static function render_settings(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Stripe Checkout', 'comic-books-fetcher'); ?></h1>
            <p>
                <?php echo esc_html(self::secret() !== ''
                    ? __('Stripe sandbox key detected.', 'comic-books-fetcher')
                    : __('Stripe sandbox key unavailable. Check the local WordPress environment and Apache configuration.', 'comic-books-fetcher')); ?>
            </p>
            <p><?php echo esc_html(self::publishable_key() !== ''
                ? __('Stripe sandbox publishable key detected.', 'comic-books-fetcher')
                : __('Set TCS_STRIPE_PUBLISHABLE_KEY to your sandbox publishable key.', 'comic-books-fetcher')); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <p>
                    <label for="tcs-stripe-price-id">
                        <?php esc_html_e('Sandbox monthly Price ID', 'comic-books-fetcher'); ?>
                    </label>
                    <input
                        id="tcs-stripe-price-id"
                        class="regular-text"
                        name="<?php echo esc_attr(self::PRICE_OPTION); ?>"
                        type="text"
                        value="<?php echo esc_attr(self::price_id()); ?>"
                        placeholder="price_..."
                    >
                </p>
                <p>
                    <label for="tcs-stripe-annual-price-id">
                        <?php esc_html_e('Sandbox annual Price ID', 'comic-books-fetcher'); ?>
                    </label>

                    <input
                        id="tcs-stripe-annual-price-id"
                        class="regular-text"
                        name="<?php echo esc_attr(self::ANNUAL_PRICE_OPTION); ?>"
                        type="text"
                        value="<?php echo esc_attr(self::price_id('annual')); ?>"
                        placeholder="price_..."
                    >
                </p>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private static function can_checkout(int $user_id): bool {
        return $user_id > 0 && !get_user_meta($user_id, '_tcs_email_pending', true);
    }

    public static function render_shortcode(): string {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return sprintf(
                '<p><a href="%s">%s</a></p>',
                esc_url(wp_login_url(home_url('/premium-checkout/'))),
                esc_html__('Sign in to continue', 'comic-books-fetcher')
            );
        }

        if (!self::can_checkout($user_id)) {
            return '<p>' . esc_html__('Verify your email address before starting checkout.', 'comic-books-fetcher') . '</p>';
        }

        $publishable_key = self::publishable_key();

        $interval = $_GET['billing_interval'] ?? 'monthly';

        if (
            !is_string($interval)
            || !in_array($interval, ['monthly', 'annual'], true)
        ) {
            return '<p>Choose Monthly or Annually on the checkout page.</p>';
        }

        if (self::secret() === '' || self::price_id($interval) === '' || $publishable_key === '') {
            return '<p>' . esc_html__('Premium checkout is currently unavailable.', 'comic-books-fetcher') . '</p>';
        }

        $attempt = wp_generate_uuid4();
        $script_path = 'js/premium-checkout.js';
        wp_enqueue_script('tcs-stripe-js', 'https://js.stripe.com/endive/stripe.js', [], null, true);
        wp_enqueue_script(
            'tcs-premium-checkout',
            COMICBOOKS_PLUGIN_URL . $script_path,
            ['tcs-stripe-js'],
            (string) filemtime(COMICBOOKS_PLUGIN_DIR . $script_path),
            true
        );

        ob_start();
        ?>
        <nav class="mb-3" aria-label="Premium billing period">
            <p class="fw-semibold mb-2">Billing period</p>

            <div class="btn-group" role="group" aria-label="Choose billing period">
                <?php foreach (self::price_ids() as $option => $price) : ?>
                    <a
                        class="btn <?php echo $option === $interval
                            ? 'btn-primary'
                            : 'btn-outline-primary'; ?>"
                        href="<?php echo esc_url(add_query_arg(
                            'billing_interval',
                            $option,
                            home_url('/premium-checkout/')
                        )); ?>"
                        <?php if ($option === $interval) echo 'aria-current="page"'; ?>
                    >
                        <?php echo esc_html(
                            $option === 'annual' ? 'Annually' : 'Monthly'
                        ); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <p class="small mt-2">
                Choose your billing period before entering payment details.
                Changing it reloads checkout.
            </p>
        </nav>
        <form
            class="tcs-premium-payment"
            method="post"
            action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-publishable-key="<?php echo esc_attr($publishable_key); ?>"
            data-return-url="<?php echo esc_url(home_url('/my-collection/')); ?>"
        >
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="checkout_attempt" value="<?php echo esc_attr($attempt); ?>">
            <input
                type="hidden"
                name="billing_interval"
                value="<?php echo esc_attr($interval); ?>"
            >
            <?php wp_nonce_field(self::ACTION . ':' . $attempt, '_ajax_nonce', false); ?>
            <p class="tcs-premium-payment__status" role="status">
                <?php esc_html_e('Loading payment details…', 'comic-books-fetcher'); ?>
            </p>
            <div class="tcs-premium-payment__fields"></div>
            <p class="tcs-premium-payment__error mt-2 text-danger" role="alert"></p>
            <p class="tcs-premium-payment__total fw-bold" hidden>
                <?php esc_html_e('Due today', 'comic-books-fetcher'); ?>
                <span></span>
            </p>
            <button type="submit" class="btn btn-primary w-100" disabled>
                <?php esc_html_e('Subscribe to Premium', 'comic-books-fetcher'); ?>
            </button>
            <noscript><?php esc_html_e('Enable JavaScript to pay securely with Stripe.', 'comic-books-fetcher'); ?></noscript>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public static function start_checkout(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(['message' => __('Open the checkout page and try again.', 'comic-books-fetcher')], 405);
        }

        $attempt = $_POST['checkout_attempt'] ?? '';

        if (!is_string($attempt) || !wp_is_uuid($attempt, 4)) {
            wp_send_json_error(['message' => __('Open the checkout page and try again.', 'comic-books-fetcher')], 400);
        }

        if (!check_ajax_referer(self::ACTION . ':' . $attempt, '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Refresh the checkout page and try again.', 'comic-books-fetcher')], 403);
        }

        $user_id = get_current_user_id();

        if (!self::can_checkout($user_id)) {
            wp_send_json_error(['message' => __('Sign in with a verified account to continue.', 'comic-books-fetcher')], 403);
        }

        $interval = $_POST['billing_interval'] ?? 'monthly';

        if (
            !is_string($interval)
            || !in_array($interval, ['monthly', 'annual'], true)
        ) {
            wp_send_json_error([
                'message' => __('Choose Monthly or Annually.', 'comic-books-fetcher'),
            ], 400);
        
            return;
        }
        
        $secret = self::secret();
        $price_id = self::price_id($interval);

        if ($secret === '' || $price_id === '' || self::publishable_key() === '') {
            wp_send_json_error(['message' => __('Premium checkout is currently unavailable.', 'comic-books-fetcher')], 503);
        }

        try {
            $has_subscription = TCS_Subscription_Management::has_subscription(
                $user_id
            );
        } catch (\Throwable $error) {
            wp_send_json_error([
                'message' => __(
                    'Your subscription could not be checked. Refresh and try again.',
                    'comic-books-fetcher'
                ),
            ], 503);
            return;
        }
        
        if ($has_subscription) {
            wp_send_json_error([
                'message' => __(
                    'You already have a subscription. Open Manage Membership to manage billing or cancellation.',
                    'comic-books-fetcher'
                ),
            ], 409);
            return;
        }

        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Stripe-Version' => '2026-10-28.endive',
                'Idempotency-Key' => 'tcs-elements-' . $user_id . '-' . $attempt . '-' . $interval,
            ],
            'body' => [
                'mode' => 'subscription',
                'ui_mode' => 'elements',
                'line_items[0][price]' => $price_id,
                'line_items[0][quantity]' => '1',
                'client_reference_id' => (string) $user_id,
                'customer_email' => wp_get_current_user()->user_email,
                'subscription_data[metadata][wp_user_id]' => (string) $user_id,
                'subscription_data[metadata][tcs_site_id]' => TCS_Stripe_Membership::site_id(),
                'return_url' => home_url('/my-collection/'),
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            wp_send_json_error(['message' => __('Stripe checkout could not be started. Please try again.', 'comic-books-fetcher')], 502);
        }

        $session = json_decode(wp_remote_retrieve_body($response), true);
        $client_secret = $session['client_secret'] ?? '';

        if (
            ($session['livemode'] ?? null) !== false
            || ($session['ui_mode'] ?? null) !== 'elements'
            || !is_string($client_secret)
            || $client_secret === ''
        ) {
            wp_send_json_error(['message' => __('Stripe returned an invalid checkout session.', 'comic-books-fetcher')], 502);
        }

        nocache_headers();
        wp_send_json_success(['clientSecret' => $client_secret]);
    }
}

TCS_Premium_Checkout::register();
