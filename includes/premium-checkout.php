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

        if (($_GET['checkout_return'] ?? '') === '1') {
            return TCS_Premium_Activation::render();
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
            ['tcs-stripe-js', 'tcs-premium-activation'],
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
            data-activation-url="<?php echo esc_url(
                add_query_arg(
                    'checkout_return',
                    '1',
                    home_url('/premium-checkout/')
                )
            ); ?>"
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
                <?php echo TCS_Premium_Activation::render(true); ?>
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
            $result = TCS_Premium_Checkout_Sessions::start(
                $user_id,
                $interval,
                $price_id
            );
        } catch (\Throwable $error) {
            error_log(
                'TCS checkout failed. Type: ' . get_class($error)
            );
        
            $status = (
                $error instanceof \RuntimeException
                && $error->getCode() === 409
            ) ? 409 : 503;
        
            wp_send_json_error([
                'message' => $error instanceof \RuntimeException
                    ? $error->getMessage()
                    : 'Checkout could not be confirmed. Please retry. Your saved attempt will be reused.',
            ], $status);
        
            return;
        }
        
        nocache_headers();
        wp_send_json_success($result);
    }
}

TCS_Premium_Checkout::register();
