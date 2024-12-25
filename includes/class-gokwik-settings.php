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
            'cod' => 'COD Settings',
            'prepaid' => 'Prepaid Settings',
        ];

        $admin_urls = [];
        $active_classes = [];
        foreach ($sections as $section => $label) {
            $admin_urls[$section] = admin_url("admin.php?page=wc-settings&tab=gokwik_checkout&section=$section");
            $active_classes[$section] = $current_section === $section ? 'gokwik-nav-tab-active nav-tab-active' : '';
        }
        ?>
        <div class="gokwik-settings-page">
            <img src="<?php echo esc_url(plugins_url('assets/images/gokwik-logo.png', GOKWIKCHECKOUT_FILE)); ?>" alt="GoKwik Logo" class="gokwik-logo">
            <p style="font-size: 1.1em;">GoKwik helps increase conversion rates and reduce RTO. Need help? <a href="https://www.gokwik.co/contact" target="_blank" style="text-decoration: none;">Contact Us</a></p>
            <h2 class="nav-tab-wrapper gokwik-nav-tab-wrapper">
                <?php foreach ($sections as $section => $label): ?>
                    <a href="<?php echo esc_url($admin_urls[$section]); ?>" class="nav-tab gokwik-nav-tab <?php echo esc_attr($active_classes[$section]); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach;?>
            </h2>
        <?php

        if ($current_section === 'cod') {
            $available_gateways = WC()->payment_gateways()->payment_gateways();
            $cod_gateway_enabled = $available_gateways['cod']->enabled ?? 'no';
            if ($cod_gateway_enabled === 'no') {
                $manage_payments_url = admin_url('admin.php?page=wc-settings&tab=checkout');
                echo sprintf(
                    '<div class="notice notice-warning inline"><p><strong>%s <a href="%s">%s</a></strong></p></div>',
                    esc_html('GoKwik COD settings won\'t have any effect as COD is disabled in WooCommerce.'),
                    esc_url($manage_payments_url),
                    esc_html('Manage Payment Methods')
                );
            }
        }

        $settings_methods = [
            'general' => 'get_general_settings',
            'cod' => 'get_cod_settings',
            'prepaid' => 'get_prepaid_settings',
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
        } elseif ($current_section === 'cod') {
            woocommerce_update_options(self::get_cod_settings());
        } elseif ($current_section === 'prepaid') {
            woocommerce_update_options(self::get_prepaid_settings());
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
            'section_end_general' => [
                'type' => 'sectionend',
                'id' => 'wc_settings_gokwik_section_end_general',
            ],
        ];

        return apply_filters('wc_settings_gokwik_general', $settings);
    }

    /**
     * Get the COD (Cash on Delivery) settings for GoKwik Checkout.
     *
     * @return array COD settings.
     */
    public static function get_cod_settings()
    {
        $settings = [
            'cod_availability_settings_title' => [
                'name' => 'COD Availability Settings',
                'type' => 'title',
                'desc' => 'Configure the availability of Cash on Delivery (COD) payments.',
                'id' => 'wc_settings_gokwik_cod_availability_section_title',
            ],
            'gokwik_cod_min_cart_value_enable' => [
                'name' => 'Min Cart Value for COD',
                'type' => 'number',
                'desc' => 'Minimum cart value to enable COD payment option.',
                'id' => 'wc_settings_gokwik_cod_min_cart_value_enable',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Specify the minimum cart value to allow COD payment.',
            ],
            'gokwik_cod_max_cart_value_enable' => [
                'name' => 'Max Cart Value for COD',
                'type' => 'number',
                'desc' => 'Maximum cart value to enable COD payment option.',
                'id' => 'wc_settings_gokwik_cod_max_cart_value_enable',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Specify the maximum cart value to allow COD payment.',
            ],
            'gokwik_cod_enable_disable' => [
                'name' => 'COD Availability for Specific Products/Categories',
                'type' => 'select',
                'desc' => 'Manage COD availability for selected products or categories.',
                'id' => 'wc_settings_gokwik_cod_enable_disable',
                'options' => [
                    'enable' => 'Enable COD for selected items',
                    'disable' => 'Disable COD for selected items',
                ],
                'class' => 'wc-enhanced-select',
                'desc_tip' => 'Choose to enable or disable COD based on specific products.',
            ],
            'gokwik_cod_restriction_mode' => [
                'name' => 'COD Restriction Mode',
                'type' => 'select',
                'desc' => 'Specify if any or all items must meet criteria for COD.',
                'id' => 'wc_settings_gokwik_cod_restriction_mode',
                'options' => [
                    'any' => 'At least one cart item must meet the criteria',
                    'all' => 'All cart items must meet the criteria',
                ],
                'class' => 'wc-enhanced-select',
                'default' => 'any',
                'desc_tip' => 'Select the restriction mode for COD availability.',
            ],
            'gokwik_cod_categories' => [
                'name' => 'COD Categories',
                'type' => 'multiselect',
                'desc' => 'Select categories for COD based on the above setting.',
                'id' => 'wc_settings_gokwik_cod_categories',
                'options' => self::get_product_categories(),
                'class' => 'wc-enhanced-select',
                'desc_tip' => 'Select categories for COD.',
            ],
            'gokwik_cod_products' => [
                'name' => 'COD Products',
                'type' => 'multiselect',
                'desc' => 'Select products for COD based on the above setting.',
                'id' => 'wc_settings_gokwik_cod_products',
                'desc_tip' => 'Select products for COD.',
            ],
            'section_end_cod_availability' => [
                'type' => 'sectionend',
                'id' => 'wc_settings_gokwik_section_end_cod_availability',
            ],
            'cod_extra_fees_settings_title' => [
                'name' => 'COD Extra Fees Configuration',
                'type' => 'title',
                'desc' => 'Configure additional fees for Cash on Delivery (COD) payments.',
                'id' => 'wc_settings_gokwik_cod_extra_fees_section_title',
            ],
            'gokwik_enable_cod_extra_fees' => [
                'name' => 'Enable COD Extra Fees',
                'type' => 'checkbox',
                'desc' => '<span class="slider" title="Toggle to enable or disable extra fees for COD"></span>',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_enable_cod_extra_fees',
            ],
            'gokwik_cod_extra_fees' => [
                'name' => 'COD Extra Fees',
                'type' => 'number',
                'desc' => 'Extra fees for COD.',
                'id' => 'wc_settings_gokwik_cod_extra_fees',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Specify extra fees for COD.',
            ],
            'gokwik_cod_extra_fees_type' => [
                'name' => 'COD Extra Fees Type',
                'type' => 'select',
                'desc' => 'Type of extra fees for COD.',
                'id' => 'wc_settings_gokwik_cod_extra_fees_type',
                'options' => [
                    'fixed' => 'Fixed',
                    'percentage' => 'Percentage',
                ],
                'desc_tip' => 'Fixed or percentage-based fees.',
            ],
            'gokwik_cod_min_cart_value_fees' => [
                'name' => 'Min Cart Value for COD Fees',
                'type' => 'number',
                'desc' => 'Minimum cart value for COD fees.',
                'id' => 'wc_settings_gokwik_cod_min_cart_value_fees',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Minimum cart value to apply COD fees.',
            ],
            'gokwik_cod_max_cart_value_fees' => [
                'name' => 'Max Cart Value for COD Fees',
                'type' => 'number',
                'desc' => 'Maximum cart value for COD fees.',
                'id' => 'wc_settings_gokwik_cod_max_cart_value_fees',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Maximum cart value to apply COD fees.',
            ],
            'section_end_cod_extra_fees' => [
                'type' => 'sectionend',
                'id' => 'wc_settings_gokwik_section_end_cod_extra_fees',
            ],
            'cod_discount_settings_title' => [
                'name' => 'COD Discount Configuration',
                'type' => 'title',
                'desc' => 'Configure your COD discount options.',
                'id' => 'wc_settings_gokwik_cod_discount_section_title',
            ],
            'gokwik_enable_cod_discount' => [
                'name' => 'Enable COD Discount',
                'type' => 'checkbox',
                'desc' => '<span class="slider" title="Enable or disable COD discount"></span>',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_enable_cod_discount',
            ],
            'gokwik_cod_discount' => [
                'name' => 'COD Discount Amount',
                'type' => 'number',
                'desc' => 'Discount amount for COD.',
                'id' => 'wc_settings_gokwik_cod_discount',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Specify discount amount for COD.',
            ],
            'gokwik_cod_discount_type' => [
                'name' => 'COD Discount Type',
                'type' => 'select',
                'desc' => 'Type of discount for COD.',
                'id' => 'wc_settings_gokwik_cod_discount_type',
                'options' => [
                    'fixed' => 'Fixed',
                    'percentage' => 'Percentage',
                ],
                'desc_tip' => 'Fixed or percentage-based discount.',
            ],
            'gokwik_cod_min_cart_value_discount' => [
                'name' => 'Min Cart Value for COD Discount',
                'type' => 'number',
                'desc' => 'Minimum cart value for COD discount.',
                'id' => 'wc_settings_gokwik_cod_min_cart_value_discount',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Minimum cart value to apply COD discount.',
            ],
            'gokwik_cod_max_cart_value_discount' => [
                'name' => 'Max Cart Value for COD Discount',
                'type' => 'number',
                'desc' => 'Maximum cart value for COD discount.',
                'id' => 'wc_settings_gokwik_cod_max_cart_value_discount',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Maximum cart value to apply COD discount.',
            ],
            'section_end_cod_discount' => [
                'type' => 'sectionend',
                'id' => 'wc_settings_gokwik_section_end_cod_discount',
            ],
        ];

        return apply_filters('wc_settings_gokwik_cod', $settings);
    }

    /**
     * Get the product categories for the settings.
     *
     * @return array Product categories.
     */
    private static function get_product_categories()
    {
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);

        $category_options = [];
        foreach ($categories as $category) {
            if ($category->parent != 0) {
                $parent_category = get_term($category->parent, 'product_cat');
                $category_name = esc_html($parent_category->name) . ' -> ' . esc_html($category->name);
            } else {
                $category_name = esc_html($category->name);
            }
            $category_options[$category->term_id] = $category_name;
        }

        asort($category_options);
        return $category_options;
    }

    /**
     * Get the prepaid settings for GoKwik Checkout.
     *
     * @return array Prepaid settings.
     */
    public static function get_prepaid_settings()
    {
        $settings = [
            'prepaid_discount_settings_title' => [
                'name' => 'Prepaid Discount Configuration',
                'type' => 'title',
                'desc' => 'Configure your Prepaid discount options.',
                'id' => 'wc_settings_gokwik_prepaid_discount_section_title',
            ],
            'gokwik_enable_prepaid_discount' => [
                'name' => 'Enable Prepaid Discount',
                'type' => 'checkbox',
                'desc' => '<span class="slider" title="Toggle to enable or disable prepaid discount"></span>',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_enable_prepaid_discount',
            ],
            'gokwik_prepaid_discount' => [
                'name' => 'Prepaid Discount Amount',
                'type' => 'number',
                'desc' => 'Discount amount for Prepaid.',
                'id' => 'wc_settings_gokwik_prepaid_discount',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Specify discount amount for Prepaid.',
            ],
            'gokwik_prepaid_discount_type' => [
                'name' => 'Prepaid Discount Type',
                'type' => 'select',
                'desc' => 'Type of discount for Prepaid.',
                'id' => 'wc_settings_gokwik_prepaid_discount_type',
                'options' => [
                    'fixed' => 'Fixed',
                    'percentage' => 'Percentage',
                ],
                'desc_tip' => 'Fixed or percentage-based discount.',
            ],
            'gokwik_prepaid_min_cart_value' => [
                'name' => 'Min Cart Value for Prepaid Discount',
                'type' => 'number',
                'desc' => 'Minimum cart value for Prepaid discount.',
                'id' => 'wc_settings_gokwik_prepaid_min_cart_value_discount',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Minimum cart value to apply Prepaid discount.',
            ],
            'gokwik_prepaid_max_cart_value' => [
                'name' => 'Max Cart Value for Prepaid Discount',
                'type' => 'number',
                'desc' => 'Maximum cart value for Prepaid discount.',
                'id' => 'wc_settings_gokwik_prepaid_max_cart_value_discount',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Maximum cart value to apply Prepaid discount.',
            ],
            'section_end_prepaid_discount' => [
                'type' => 'sectionend',
                'id' => 'wc_settings_gokwik_section_end_prepaid_discount',
            ],
            'prepaid_extra_fees_settings_title' => [
                'name' => 'Prepaid Extra Fees Configuration',
                'type' => 'title',
                'desc' => 'Configure additional fees for Prepaid payments.',
                'id' => 'wc_settings_gokwik_prepaid_extra_fees_section_title',
            ],
            'gokwik_enable_prepaid_extra_fees' => [
                'name' => 'Enable Prepaid Extra Fees',
                'type' => 'checkbox',
                'desc' => '<span class="slider" title="Toggle to enable or disable extra fees for Prepaid"></span>',
                'default' => 'no',
                'id' => 'wc_settings_gokwik_enable_prepaid_extra_fees',
            ],
            'gokwik_prepaid_extra_fees' => [
                'name' => 'Prepaid Extra Fees Amount',
                'type' => 'number',
                'desc' => 'Extra fees amount for Prepaid.',
                'id' => 'wc_settings_gokwik_prepaid_extra_fees',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Specify extra fees amount for Prepaid.',
            ],
            'gokwik_prepaid_extra_fees_type' => [
                'name' => 'Prepaid Extra Fees Type',
                'type' => 'select',
                'desc' => 'Type of extra fees for Prepaid.',
                'id' => 'wc_settings_gokwik_prepaid_extra_fees_type',
                'options' => [
                    'fixed' => 'Fixed',
                    'percentage' => 'Percentage',
                ],
                'desc_tip' => 'Fixed or percentage-based fees.',
            ],
            'gokwik_prepaid_min_cart_value_fees' => [
                'name' => 'Min Cart Value for Prepaid Fees',
                'type' => 'number',
                'desc' => 'Minimum cart value for Prepaid fees.',
                'id' => 'wc_settings_gokwik_prepaid_min_cart_value_fees',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Minimum cart value to apply Prepaid fees.',
            ],
            'gokwik_prepaid_max_cart_value_fees' => [
                'name' => 'Max Cart Value for Prepaid Fees',
                'type' => 'number',
                'desc' => 'Maximum cart value for Prepaid fees.',
                'id' => 'wc_settings_gokwik_prepaid_max_cart_value_fees',
                'custom_attributes' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
                'desc_tip' => 'Maximum cart value to apply Prepaid fees.',
            ],
            'section_end_prepaid_extra_fees' => [
                'type' => 'sectionend',
                'id' => 'wc_settings_gokwik_section_end_prepaid_extra_fees',
            ],
        ];

        return apply_filters('wc_settings_gokwik_prepaid', $settings);
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
        $script_url = plugin_dir_url(GOKWIKCHECKOUT_FILE) . 'assets/js/admin/gokwik-settings.js';
        wp_enqueue_script('gokwik-settings-js', $script_url, ['jquery', 'select2'], time(), true);

        wp_localize_script('gokwik-settings-js', 'gokwikAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('search-coupons'),
            'product_nonce' => wp_create_nonce('search-products'),
            'selectedCoupons' => self::get_selected_coupons(),
            'selectedProducts' => self::get_selected_products(),
        ]);
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
     * Handle AJAX request to search for products.
     */
    public static function search_products()
    {
        if (!check_ajax_referer('search-products', 'security', false)) {
            wp_send_json_error('Invalid security token', 403);
        }

        $query = sanitize_text_field($_GET['q'] ?? '');
        if (strlen($query) < 3) {
            wp_send_json_error('Query too short', 400);
        }

        $args = [
            'posts_per_page' => 30,
            's' => $query,
            'post_type' => ['product', 'product_variation'],
            'post_status' => 'publish',
        ];

        $products = get_posts($args);
        if (empty($products)) {
            wp_send_json_success([], 200);
        }

        $results = array_map(function ($product_post) {
            $product = wc_get_product($product_post->ID);
            if ($product) {
                return [
                    'id' => $product->get_id(),
                    'text' => $product->get_name() . ' (#' . $product->get_id() . ')',
                ];
            }
        }, $products);

        wp_send_json_success($results);
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

    /**
     * Get selected products from the options.
     *
     * @return array List of selected products.
     */
    private static function get_selected_products()
    {
        $selected_products = get_option('wc_settings_gokwik_cod_products', []);
        return array_map(function ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                return [
                    'id' => $product->get_id(),
                    'text' => $product->get_name() . ' (#' . $product->get_id() . ')',
                ];
            }
        }, $selected_products);
    }
}