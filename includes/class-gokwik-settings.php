<?php

namespace Gokwik_Checkout\Inc;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class GokwikSettings
{
    public function __construct()
    {}
    public function __clone()
    {}
    public function __wakeup()
    {}

    /**
     * Initialize the settings by adding necessary actions and filters.
     */
    public static function init()
    {
        add_filter('woocommerce_settings_tabs_array', [__CLASS__, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_gokwik_checkout', [__CLASS__, 'settings_tab']);
        add_action('woocommerce_update_options_gokwik_checkout', [__CLASS__, 'update_settings']);
        add_filter('plugin_action_links_' . GOKWIKCHECKOUT_BASENAME, [__CLASS__, 'add_settings_page_link']);
        add_filter('woocommerce_admin_settings_sanitize_option_wc_settings_gokwik_section_enable_checkout', [__CLASS__, 'validate_settings'], 10, 3);
        add_action('admin_head', [__CLASS__, 'add_custom_css']);
        add_action('admin_menu', [__CLASS__, 'add_gokwik_settings_link']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        add_action('wp_ajax_search_coupons', [__CLASS__, 'search_coupons']);
        add_action('wp_ajax_search_products', [__CLASS__, 'search_products']);
        add_action('wp_ajax_gokwik_sync_products_now', [__CLASS__, 'ajax_sync_products_now']);
        add_action('gokwik_product_sync_cron', [__CLASS__, 'handle_product_sync_cron']);
    }

    /**
     * Add a new settings tab for GoKwik Checkout.
     *
     * @param array $settings_tabs Existing WooCommerce settings tabs.
     * @return array Modified settings tabs.
     */
    public static function add_settings_tab($settings_tabs)
    {
        $settings_tabs['gokwik_checkout'] = 'GoKwik Checkout';
        return $settings_tabs;
    }

    /**
     * Display the settings tab content.
     */
    public static function settings_tab()
    {
        $current_section = sanitize_text_field($_GET['section'] ?? 'general');

        $sections = [
            'general' => 'General Settings',
            'coupons' => 'Coupon Settings',
        ];

        $admin_urls = [];
        $active_classes = [];
        foreach ($sections as $section => $label) {
            $admin_urls[$section] = admin_url("admin.php?page=wc-settings&tab=gokwik_checkout&section=$section");
            $active_classes[$section] = $current_section === $section ? 'gokwik-nav-tab-active nav-tab-active' : '';
        }

        $last_sync_time = get_option('gokwik_product_sync_last_time');
        $last_sync_status = get_option('gokwik_product_sync_last_notice');
        if ($last_sync_status === 'failed') {
            $last_sync_display = 'Failed';
        } elseif ($last_sync_time) {
            $human_sync = human_time_diff($last_sync_time, current_time('timestamp'));
            $last_sync_display = $human_sync ? $human_sync . ' ago' : date_i18n('Y-m-d H:i:s', $last_sync_time);
        } else {
            $last_sync_display = 'Never';
        }

        $is_syncing = get_transient(\Gokwik_Checkout\Inc\GokwikProductSync::SYNC_TRANSIENT) ? true : false;
        $is_scheduled = wp_next_scheduled('gokwik_product_sync_cron') ? true : false;
        if (isset($_GET['gokwik_sync_products_now']) && current_user_can('manage_woocommerce')) {
            if (!$is_syncing && !$is_scheduled) {
                update_option('gokwik_product_sync_last_notice', 'scheduled');
                wp_schedule_single_event(time() + 1, 'gokwik_product_sync_cron');
            }
            wp_redirect(remove_query_arg('gokwik_sync_products_now'));
            exit;
        }
        echo '<script>var gokwikSyncStatus = ' . json_encode(['isSyncing' => $is_syncing, 'isScheduled' => $is_scheduled]) . ';</script>';
        ?>
        <div class="gokwik-settings-page">
            <div class="gokwik-settings-header">
                <div class="gokwik-header-left">
                    <img src="<?php echo esc_url(plugins_url('assets/images/gokwik-logo.svg', GOKWIKCHECKOUT_FILE)); ?>" alt="GoKwik Logo" class="gokwik-logo" style="max-width:100px;height:auto;">
                    <div>
                        <div class="gokwik-header-title">GoKwik Checkout Settings</div>
                        <div class="gokwik-header-desc">Boost your conversions and reduce RTO with GoKwik's fast, reliable, and optimized checkout experience.</div>
                    </div>
                </div>
                <div class="gokwik-header-right">
                    <a href="<?php echo esc_url(add_query_arg('gokwik_sync_products_now', '1')); ?>" id="gokwik-sync-products-now" class="button button-primary">Sync Products Now</a>
                    <div class="gokwik-sync-timestamp">Last Sync: <?php echo esc_html($last_sync_display); ?></div>
                </div>
            </div>
            <div id="gokwik-sync-products-result"></div>
            <h2 class="nav-tab-wrapper gokwik-nav-tab-wrapper">
                <?php foreach ($sections as $section => $label): ?>
                    <a href="<?php echo esc_url($admin_urls[$section]); ?>" class="nav-tab gokwik-nav-tab <?php echo esc_attr($active_classes[$section]); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach;?>
            </h2>
        <?php

        $settings_methods = [
            'general' => 'get_general_settings',
            'coupons' => 'get_coupon_settings',
        ];

        if (isset($settings_methods[$current_section])) {
            woocommerce_admin_fields(self::{$settings_methods[$current_section]}());
        }

        echo '</div>';
    }

    /**
     * Update the settings based on the current section.
     */
    public static function update_settings()
    {
        $current_section = sanitize_text_field($_GET['section'] ?? 'general');
        if ($current_section === 'general') {
            woocommerce_update_options(self::get_general_settings());
        } elseif ($current_section === 'coupons') {
            woocommerce_update_options(self::get_coupon_settings());
        }
    }

    /**
     * Get the general settings for GoKwik Checkout.
     *
     * @return array General settings.
     */
    public static function get_general_settings()
    {
        $settings = [
            'section_title' => [
                'name' => 'GoKwik Checkout Configuration',
                'type' => 'title',
                'desc' => 'Configure the general settings for GoKwik Checkout.',
                'id' => 'wc_settings_gokwik_section_title',
            ],
            'gokwik_mid' => [
                'name' => 'Merchant ID',
                'type' => 'text',
                'desc' => 'Your GoKwik Merchant ID.',
                'id' => 'wc_settings_gokwik_section_mid',
                'desc_tip' => 'Unique Merchant ID from GoKwik.',
            ],
            'gokwik_app_id' => [
                'name' => 'App ID',
                'type' => 'text',
                'desc' => 'Your GoKwik App ID.',
                'id' => 'wc_settings_gokwik_section_app_id',
                'desc_tip' => 'Unique App ID from GoKwik.',
            ],
            'gokwik_app_secret' => [
                'name' => 'App Secret',
                'type' => 'password',
                'desc' => 'Your GoKwik App Secret.',
                'id' => 'wc_settings_gokwik_section_app_secret',
                'desc_tip' => 'Unique App Secret from GoKwik.',
            ],
            'gokwik_sandbox_mode' => [
                'name' => 'Sandbox Mode',
                'type' => 'checkbox',
                'desc' => 'Enable Sandbox Mode?',
                'default' => 'yes',
                'id' => 'wc_settings_gokwik_section_sandbox_mode',
                'desc_tip' => 'For testing purposes.',
            ],
            'gokwik_enable_checkout' => [
                'name' => 'Enable GoKwik Checkout',
                'type' => 'checkbox',
                'desc' => 'Enable GoKwik Checkout on your store.',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_section_enable_checkout',
                'desc_tip' => 'Turn on GoKwik Checkout for a better checkout experience.',
            ],
            'gokwik_enable_checkout_from_cart_page' => [
                'type' => 'checkbox',
                'desc' => 'Enable GoKwik checkout on the cart page.',
                'default' => 'yes',
                'id' => 'wc_settings_gokwik_section_enable_checkout_from_cart_page',
                'desc_tip' => 'This enables the GoKwik checkout button on the cart page.',
            ],
            'gokwik_enable_checkout_from_side_cart' => [
                'type' => 'checkbox',
                'desc' => 'Enable GoKwik checkout on the side-cart.',
                'default' => 'yes',
                'id' => 'wc_settings_gokwik_section_enable_checkout_from_side_cart',
                'desc_tip' => 'This enables the GoKwik checkout button on the side-cart drawer.',
            ],
            'gokwik_overwrite_native_checkout_page' => [
                'name' => 'Overwrite Native Checkout',
                'type' => 'checkbox',
                'desc' => 'Replace WooCommerce native checkout with GoKwik?',
                'default' => 'yes',
                'id' => 'wc_settings_gokwik_section_overwrite_native_checkout_page',
                'desc_tip' => 'Replaces the native WooCommerce checkout with GoKwik.',
            ],
            'gokwik_enable_buy_now_button' => [
                'name' => 'Enable GoKwik Buy Now',
                'type' => 'checkbox',
                'desc' => 'Add GoKwik Buy Now Button on Product Listing pages.',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_section_enable_buy_now_button',
                'desc_tip' => 'Enable to add a GoKwik Buy Now Button on Product page.',
            ],
            'gokwik_register_after_checkout' => [
                'name' => 'Auto Register Guests',
                'type' => 'checkbox',
                'desc' => 'Automatically create accounts for guest users after they place an order.',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_section_register_after_checkout',
                'desc_tip' => 'Enable to auto-register guest users post-checkout.',
            ],
            'gokwik_allow_pending_flow' => [
                'name' => 'Allow Pending Flow',
                'type' => 'checkbox',
                'desc' => 'Creates a payment-pending order when the user drops off at address or payments screen.',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_section_allow_pending_flow',
                'desc_tip' => 'Enable to create orders in payment pending state.',
            ],
			'gokwik_enable_ppcod_order_total_format' => [
			'name' => 'Enable PPCOD Order Total format including Advance Amount',
			'type' => 'checkbox',
			'desc' => 'PPCOD “Order Total” Calculation will include advance paid amount.',
			'default' => 'no',
			'id' => 'wc_settings_gokwik_section_enable_ppcod_order_total_format',
			'desc_tip' => 'Enable to show the new PPCOD order summary layout.',
			],
        ];

        $site_url = parse_url(get_site_url(), PHP_URL_HOST);
        if ($site_url && strpos($site_url, 'gokwik.co') !== false) {
            $settings['gokwik_test_order_retry'] = [
                'name' => 'Test Order Retry Flow',
                'type' => 'checkbox',
                'desc' => 'Intentionally fail placeOrder API for testing order retry flow.',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_section_test_order_retry',
                'desc_tip' => 'Enable this option to simulate placeOrder API failures for testing the order retry mechanism.',
            ];
        }

        $settings['section_end_general'] = [
            'type' => 'sectionend',
            'id' => 'wc_settings_gokwik_section_end_general',
        ];

        return apply_filters('wc_settings_gokwik_general', $settings);
    }

    /**
     * Get coupon settings for the GoKwik plugin.
     *
     * @return array Coupon settings.
     */
    public static function get_coupon_settings()
    {
        $settings = [
            'coupon_settings_title' => [
                'name' => 'Coupon Configuration',
                'type' => 'title',
                'desc' => 'Configure your coupon settings here.',
                'id' => 'wc_settings_gokwik_coupon_section_title',
            ],
            'gokwik_show_coupons_list' => [
                'name' => 'Show Coupons List',
                'type' => 'checkbox',
                'desc' => '<span class="slider" title="Toggle to show or hide the coupons list."></span>',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_section_show_coupons_list',
            ],
            'gokwik_select_coupons' => [
                'name' => 'Select Coupons to Display',
                'type' => 'multiselect',
                'desc' => 'Choose which coupons to show during checkout.',
                'id' => 'wc_settings_gokwik_selected_coupons',
                'desc_tip' => 'Select the coupons you want to display to customers during checkout.',
            ],
            'gokwik_show_user_specific_coupons' => [
                'name' => 'Show User-Specific Coupons',
                'type' => 'checkbox',
                'desc' => 'Display coupons that are specifically assigned to the user.',
                'default' => 'yes',
                'id' => 'wc_settings_gokwik_show_user_specific_coupons',
                'desc_tip' => 'Enable this to show coupons that are assigned to the user.',
            ],
            'gokwik_show_valid_coupons_only' => [
                'name' => 'Show Only Eligible Coupons',
                'type' => 'checkbox',
                'desc' => 'Display only the coupons that the customer is eligible to use.',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_show_valid_coupons_only',
                'desc_tip' => 'If enabled, only the coupons that the customer can use will be shown.',
            ],
            'section_end_coupon' => [
                'type' => 'sectionend',
                'id' => 'wc_settings_gokwik_section_end_coupon',
            ],
        ];

        return apply_filters('wc_settings_gokwik_coupon', $settings);
    }

    /**
     * Validate settings for the GoKwik plugin.
     *
     * @param string $value The value to validate.
     * @param string $option The option name.
     * @param string $raw_value The raw value.
     * @return string The validated value.
     */
    public static function validate_settings($value, $option, $raw_value)
    {
        if ($value === 'yes' && in_array('gokwik-woocommerce-payment/gokwik-gateway.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            \WC_Admin_Settings::add_error('Please deactivate the old "GoKwik Payment Gateway" plugin before enabling the new GoKwik Checkout.');
            return 'no';
        }

        return $value;
    }

    /**
     * Add settings page link for the GoKwik plugin.
     *
     * @param array $links Existing links.
     * @return array Modified links with settings page link.
     */
    public static function add_settings_page_link($links)
    {
        $url = add_query_arg(
            [
                'page' => 'wc-settings',
                'tab' => 'gokwik_checkout',
            ],
            get_admin_url() . 'admin.php'
        );

        $settings_link = [
            "<a href='" . esc_url($url) . "'>Settings</a>",
        ];

        return array_merge($settings_link, $links);
    }

    /**
     * Add custom CSS for the GoKwik plugin settings page.
     */
    public static function add_custom_css()
    {
        $css_url = plugin_dir_url(GOKWIKCHECKOUT_FILE) . 'assets/css/admin/gokwik-settings.css';
        wp_enqueue_style('gokwik-settings-css', $css_url, [], time());
    }

    /**
     * Add GoKwik settings link to the WooCommerce submenu.
     */
    public static function add_gokwik_settings_link()
    {
        add_submenu_page(
            'woocommerce',
            'GoKwik Settings',
            'GoKwik Settings',
            'manage_options',
            'admin.php?page=wc-settings&tab=gokwik_checkout',
            null
        );
    }

    /**
     * Enqueue scripts for the GoKwik plugin settings page.
     */
    public static function enqueue_scripts()
    {
        $screen = get_current_screen();
        if (
            (isset($_GET['page']) && $_GET['page'] === 'wc-settings' && isset($_GET['tab']) && $_GET['tab'] === 'gokwik_checkout') ||
            (method_exists($screen, 'id') && $screen->id === 'woocommerce_page_wc-settings')
        ) {
            wp_enqueue_script(
                'gokwik-settings-admin',
                plugins_url('../assets/js/admin/gokwik-settings.js', __FILE__),
                ['jquery'],
                filemtime(plugin_dir_path(__FILE__) . '../assets/js/admin/gokwik-settings.js'),
                true
            );
            wp_localize_script(
                'gokwik-settings-admin',
                'gokwikAdmin',
                [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce('search-coupons'),
                    'selectedCoupons' => method_exists(__CLASS__, 'get_selected_coupons') ? self::get_selected_coupons() : [],
                ]
            );
        }
    }

    /**
     * Handle AJAX request to search for coupons.
     */
    public static function search_coupons()
    {
        if (!check_ajax_referer('search-coupons', 'security', false)) {
            wp_send_json_error('Invalid security token', 403);
        }

        $query = sanitize_text_field($_GET['q'] ?? '');
        if (strlen($query) < 3) {
            wp_send_json_error('Query too short', 400);
        }

        $exact_match_result = [];
        $coupon = new \WC_Coupon($query);
        if ($coupon) {
            $exact_match_result[] = [
                'id' => $coupon->get_id(),
                'text' => $coupon->get_code(),
            ];
        }

        $args = [
            'posts_per_page' => 30,
            's' => $query,
            'post_type' => 'shop_coupon',
            'post_status' => 'publish',
        ];

        $coupons = get_posts($args);
        $results = array_map(function ($coupon_post) {
            $coupon = new \WC_Coupon($coupon_post->ID);
            if ($coupon) {
                return [
                    'id' => $coupon->get_id(),
                    'text' => $coupon->get_code(),
                ];
            }
        }, $coupons);

        $final_results = array_merge($exact_match_result, $results);
        $unique_results = array_unique($final_results, SORT_REGULAR);

        if (empty($unique_results)) {
            wp_send_json_success([], 200);
        } else {
            wp_send_json_success($unique_results);
        }
    }

    /**
     * AJAX handler to start product sync.
     */
    public static function ajax_sync_products_now() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }
        if (get_transient(\Gokwik_Checkout\Inc\GokwikProductSync::SYNC_TRANSIENT)) {
            wp_send_json_error(['message' => 'Sync already running.'], 409);
        }
        update_option('gokwik_product_sync_last_notice', 'started');
        $result = \Gokwik_Checkout\Inc\GokwikProductSync::sync_all_products(true);
        update_option('gokwik_product_sync_last_time', current_time('timestamp'));
        if (!empty($result[0])) {
            update_option('gokwik_product_sync_last_notice', 'completed');
            wp_send_json_success(['message' => $result[1], 'result' => $result]);
        } else {
            update_option('gokwik_product_sync_last_notice', 'failed');
            wp_send_json_error(['message' => $result[1], 'result' => $result]);
        }
    }

    /**
     * Handle the scheduled product sync cron event.
     */
    public static function handle_product_sync_cron() {
        if (get_transient(\Gokwik_Checkout\Inc\GokwikProductSync::SYNC_TRANSIENT)) {
            return;
        }
        update_option('gokwik_product_sync_last_notice', 'started');
        $result = \Gokwik_Checkout\Inc\GokwikProductSync::sync_all_products(true);
        update_option('gokwik_product_sync_last_time', current_time('timestamp'));
        if (!empty($result[0])) {
            update_option('gokwik_product_sync_last_notice', 'completed');
        } else {
            update_option('gokwik_product_sync_last_notice', 'failed');
        }
    }

    /**
     * Get selected coupons from the options.
     *
     * @return array List of selected coupons.
     */
    private static function get_selected_coupons()
    {
        $selected_coupons = get_option('wc_settings_gokwik_selected_coupons', []);
        return array_map(function ($coupon_id) {
            $coupon = new \WC_Coupon($coupon_id);
            if ($coupon) {
                return [
                    'id' => $coupon->get_id(),
                    'text' => $coupon->get_code(),
                ];
            }
        }, $selected_coupons);
    }

}