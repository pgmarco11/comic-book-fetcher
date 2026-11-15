<?php

defined('ABSPATH') || exit;

final class TCS_Premium_Checkout {
    private const PRICE_OPTION = 'tcs_stripe_test_price_id';
    private const SETTINGS_GROUP = 'tcs_stripe_checkout';
    private const ACTION = 'tcs_start_stripe_checkout';

    public static function register(): void {
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_menu', [self::class, 'register_menu']);
        add_shortcode('tcs_stripe_checkout', [self::class, 'render_shortcode']);
        add_action('admin_post_' . self::ACTION, [self::class, 'start_checkout']);
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

    private static function price_id(): string {
        $price_id = get_option(self::PRICE_OPTION, '');

        return is_string($price_id) && self::valid_price_id($price_id) ? $price_id : '';
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
            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <p>
                    <label for="tcs-stripe-price-id">
                        <?php esc_html_e('Sandbox recurring Price ID', 'comic-books-fetcher'); ?>
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

        if (self::secret() === '' || self::price_id() === '') {
            return '<p>' . esc_html__('Premium checkout is currently unavailable.', 'comic-books-fetcher') . '</p>';
        }

        $attempt = wp_generate_uuid4();

        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="checkout_attempt" value="<?php echo esc_attr($attempt); ?>">
            <?php wp_nonce_field(self::ACTION . ':' . $attempt); ?>
            <button type="submit" class="btn btn-primary">
                <?php esc_html_e('Continue to Stripe Checkout', 'comic-books-fetcher'); ?>
            </button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public static function start_checkout(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Use the checkout button to start a session.', 'comic-books-fetcher'), '', ['response' => 405]);
        }

        $attempt = $_POST['checkout_attempt'] ?? '';

        if (!is_string($attempt) || !wp_is_uuid($attempt, 4)) {
            wp_die(esc_html__('Open the checkout page and try again.', 'comic-books-fetcher'), '', ['response' => 400]);
        }

        check_admin_referer(self::ACTION . ':' . $attempt);

        $user_id = get_current_user_id();

        if (!self::can_checkout($user_id)) {
            wp_die(esc_html__('Sign in with a verified account to continue.', 'comic-books-fetcher'), '', ['response' => 403]);
        }

        $secret = self::secret();
        $price_id = self::price_id();

        if ($secret === '' || $price_id === '') {
            wp_die(esc_html__('Premium checkout is currently unavailable.', 'comic-books-fetcher'), '', ['response' => 503]);
        }

        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Stripe-Version' => '2026-10-28.endive',
                'Idempotency-Key' => 'tcs-checkout-' . $user_id . '-' . $attempt,
            ],
            'body' => [
                'mode' => 'subscription',
                'line_items[0][price]' => $price_id,
                'line_items[0][quantity]' => '1',
                'client_reference_id' => (string) $user_id,
                'subscription_data[metadata][wp_user_id]' => (string) $user_id,
                'success_url' => home_url('/my-collection/'),
                'cancel_url' => home_url('/premium-checkout/'),
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            wp_die(esc_html__('Stripe checkout could not be started. Please try again.', 'comic-books-fetcher'), '', ['response' => 502]);
        }

        $session = json_decode(wp_remote_retrieve_body($response), true);
        $url = $session['url'] ?? '';
        $parts = is_string($url) ? wp_parse_url($url) : false;

        if (
            ($session['livemode'] ?? null) !== false
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'checkout.stripe.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || ($parts['port'] ?? 443) !== 443
        ) {
            wp_die(esc_html__('Stripe returned an invalid checkout URL.', 'comic-books-fetcher'), '', ['response' => 502]);
        }

        wp_redirect($url, 303);
        exit;
    }
}

TCS_Premium_Checkout::register();