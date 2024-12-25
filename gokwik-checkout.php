<?php

/**
 * GoKwik Checkout For WooCommerce
 *
 * @wordpress-plugin
 * Plugin Name:           GoKwik Checkout
 * Plugin URI:            https://gokwik.co
 * Description:           Supercharge your business with the best checkout experience.
 * Version:               1.0.9
 * Author:                Team GoKwik
 * Author URI:            https://gokwik.co
 * Text Domain:           gokwik-checkout
 * Domain Path:           /languages/
 * Requires at least:     5.4
 * Requires PHP:          7.0
 * Requires Plugins:      woocommerce
 * WC requires at least:  4.3
 * WC tested up to:       9.3
 * WP tested up to:       6.6
 *
 * @package               Gokwik_Checkout
 * @link                  https://gokwik.co
 * @since                 1.0.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants.
define('GOKWIKCHECKOUT_FILE', __FILE__);
define('GOKWIKCHECKOUT_VERSION', '1.0.9');
define('GOKWIKCHECKOUT_DIR', plugin_dir_path(GOKWIKCHECKOUT_FILE));
define('GOKWIKCHECKOUT_BASENAME', plugin_basename(GOKWIKCHECKOUT_FILE));
define('GOKWIKCHECKOUT_PENDING_ORDER_FLOW', false);

// Check if WooCommerce is installed and active.
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>' . sprintf(esc_html__('GoKwik Checkout requires WooCommerce to be active and installed. You can download WooCommerce from %s.', 'gokwik-payment'), '<a href="https://woocommerce.com/" target="_blank">here</a>') . '</strong></p></div>';
    });
    return;
}

// Register plugin activation hook.
register_activation_hook(GOKWIKCHECKOUT_FILE, 'gc_plugin_activated');
function gc_plugin_activated()
{
    $options_to_update = [
        'woocommerce_registration_generate_password' => 'yes',
        'woocommerce_shipping_cost_requires_address' => 'no',
    ];

    foreach ($options_to_update as $option => $value) {
        if (get_option($option) !== $value) {
            update_option($option, $value);
        }
    }
}

// Declare support for HPOS.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', GOKWIKCHECKOUT_FILE, true);
    }
});

// Load utilities class.
include_once GOKWIKCHECKOUT_DIR . '/includes/class-gokwik-utilities.php';
use Gokwik_Checkout\Inc\GokwikUtilities;

class GokwikCheckout
{

    /**
     * @var object GokwikCheckout - The single instance of this class.
     *
     * @access protected
     * @static
     * @since 1.0.0
     */
    protected static $_instance = null;

    /**
     * The Main GokwikCheckout Instance
     *
     * This ensures that only one instance can be loaded.
     *
     * @access  public
     * @static
     * @since   1.0.0
     * @see     init_gokwik_checkout_plugin()
     * @return  GokwikCheckout - The main instance.
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Prevent object cloning.
     */
    public function __clone()
    {}

    /**
     * Prevent object unserialization.
     */
    public function __wakeup()
    {}

    /**
     * Load the plugin.
     *
     * @access  public
     * @since   1.0.0
     */
    public function __construct()
    {

        // Load the Settings class.
        require_once GOKWIKCHECKOUT_DIR . 'includes/class-gokwik-settings.php';
        \Gokwik_Checkout\Inc\GokwikSettings::init();

        // Check if Store Currency is INR.
        if (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_gokwik_supported_currencies', array('INR')), true)) {
            add_action('admin_notices', function () {?>
                <div class="notice notice-error">
                    <p>
                        Please set store currency to the Indian rupee (₹), GoKwik Checkout only supports Indian Rupee Currency.
                        <a href="admin.php?page=wc-settings&tab=general">Go to Settings</a>
                    </p>
                </div>
            <?php });
            return;
        }

        // Check if GoKwik mid is set.
        $gc_mid = get_option('wc_settings_gokwik_section_mid', null);
        if (empty($gc_mid)) {
            add_action('admin_notices', function () {?>
                <div class="notice notice-error">
                    <p>
                        GoKwik Checkout is almost ready. To get started, please add your Merchant ID in the
                        <a href="admin.php?page=wc-settings&tab=gokwik_checkout">settings page</a>.
                    </p>
                </div>
            <?php });
            update_option('wc_settings_gokwik_section_enable_checkout', 'no');
            return;
        }

        if (
            get_option('wc_settings_gokwik_section_enable_checkout') == 'yes' &&
            in_array('gokwik-woocommerce-payment/gokwik-gateway.php', apply_filters('active_plugins', get_option('active_plugins')))
        ) {
            add_action('admin_notices', function () {?>
                <div class="notice notice-error">
                    <p>
                        GoKwik Checkout may not work properly, please deactivate the old "GoKwik Payment Gateway" plugin.
                    </p>
                </div>
            <?php });
            return;
        }

        // Load the API class.
        include_once GOKWIKCHECKOUT_DIR . '/includes/api/class-gokwik-cart.php';

        // Load the GoKwik-Prepaid Payment Gateway class.
        $this->requireGokwikPrepaidGateway();
    }

    /**
     * Load the GoKwik Prepaid Payment Gateway.
     *
     * @access  public
     * @since   1.0.0
     */
    public function requireGokwikPrepaidGateway()
    {
        include_once GOKWIKCHECKOUT_DIR . '/includes/class-gokwik-prepaid-gateway.php';
        add_filter('woocommerce_payment_gateways', array($this, 'registerGokwikPrepaidGateway'));
    }

    /**
     * Register the GoKwik Prepaid Payment Gateway.
     *
     * @access  public
     * @since   1.0.0
     */
    public function registerGokwikPrepaidGateway($gateways)
    {
        $gateways[] = '\Gokwik_Checkout\Inc\GokwikPrepaidGateway';
        return $gateways;
    }

}

/**
 * Add Discount/Fees for Payment Gateways.
 */
add_action('woocommerce_cart_calculate_fees', 'gc_add_gateway_fees_discounts', PHP_INT_MAX);
function gc_add_gateway_fees_discounts($cart)
{
    if (is_admin()) {
        return;
    }

    if (!class_exists('WC_Payment_Gateways')) {
        return;
    }

    $payment_gateways = WC()->payment_gateways();
    if (is_null($payment_gateways)) {
        return;
    }

    $chosen_payment_method = WC()->session->get('chosen_payment_method');
    $feesAndDiscounts = [];
    $fee_label = null;
    $discount_label = null;

    switch ($chosen_payment_method) {
        case 'cod':
            $feesAndDiscounts = GokwikUtilities::calculateCodExtraFeesAndDiscount(($cart->get_subtotal() ?? 0) + ($cart->get_shipping_total() ?? 0));
            $fee_label = 'COD Fee';
            $discount_label = 'COD Discount';
            break;
        case 'gokwik_prepaid':
            $feesAndDiscounts = GokwikUtilities::calculatePrepaidExtraFeesAndDiscount(($cart->get_subtotal() ?? 0) + ($cart->get_shipping_total() ?? 0));
            $fee_label = 'Prepaid Fee';
            $discount_label = 'Prepaid Discount';
            break;
        default:
            return;
    }

    $existing_fees = $cart->get_fees();
    foreach ($existing_fees as $key => $fee) {
        if (($fee->name == $fee_label && $feesAndDiscounts['fee'] <= 0) ||
            ($fee->name == $discount_label && $feesAndDiscounts['discount'] <= 0)) {
            unset($existing_fees[$key]);
        }
    }
    $cart->fees_api()->set_fees($existing_fees);

    if (!empty($feesAndDiscounts)) {
        if ($feesAndDiscounts['fee'] > 0) {
            $cart->add_fee($fee_label, $feesAndDiscounts['fee'], false, '');
        }
        if ($feesAndDiscounts['discount'] > 0) {
            $cart->add_fee($discount_label, -$feesAndDiscounts['discount'], false, '');
        }
    }
}

/**
 * Hide GoKwik Prepaid payment method if GoKwik Checkout is not enabled.
 */
add_filter('woocommerce_available_payment_gateways', 'gc_hide_payment_method');
function gc_hide_payment_method($gateways)
{
    if (is_admin()) {
        return $gateways;
    }

    if (isset($gateways['gokwik_prepaid']) && (get_option('wc_settings_gokwik_section_enable_checkout') != 'yes' || is_checkout() || is_wc_endpoint_url('order-pay'))) {
        unset($gateways['gokwik_prepaid']);
    }
    if (isset($gateways['cod']) && !GokwikUtilities::isCodAvailable(WC()->cart)) {
        unset($gateways['cod']);
    }
    return $gateways;
}

/**
 * Endpoint for clearing cart and returning thank-you page URL.
 */
if (get_option('wc_settings_gokwik_section_enable_checkout') == 'yes') {
    add_action('wp_ajax_gc_cart_clear_and_redirect', 'gc_cart_clear_and_redirect');
    add_action('wp_ajax_nopriv_gc_cart_clear_and_redirect', 'gc_cart_clear_and_redirect');
}
function gc_cart_clear_and_redirect()
{
    global $woocommerce;
    $response = ['status' => 'error'];

    if (isset($_POST['merchant_order_id'])) {
        $merchant_order_id = wc_clean(wp_unslash($_POST['merchant_order_id']));
        $order = wc_get_order($merchant_order_id);
        if ($order) {
            $redirectUrl = $order->get_checkout_order_received_url();
            $woocommerce->cart->empty_cart();
            if ($woocommerce->cart->is_empty()) {
                $response = [
                    'status' => 'success',
                    'url' => $redirectUrl,
                ];
            }
        }
    }

    wp_send_json($response);
    exit;
}

/**
 * Endpoint for fetching Session ID.
 */
if (get_option('wc_settings_gokwik_section_enable_checkout') == 'yes') {
    add_action('wp_ajax_gc_fetch_user_session_id', 'gc_fetch_user_session_id');
    add_action('wp_ajax_nopriv_gc_fetch_user_session_id', 'gc_fetch_user_session_id');
}
function gc_fetch_user_session_id()
{
    $response = [
        'status' => 'error',
        'session_id' => '',
    ];

    if (isset(WC()->session)) {
        if (!WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        $session_cookie = WC()->session->get_session_cookie();
        if ($session_cookie) {
            $response = [
                'status' => 'success',
                'session_id' => $session_cookie[0],
            ];
        }
    }

    wp_send_json($response);
    exit;
}

/**
 * Endpoint for checking if cart is empty.
 */
if (get_option('wc_settings_gokwik_section_enable_checkout') == 'yes') {
    add_action('wp_ajax_gc_check_cart_status', 'gc_check_cart_status');
    add_action('wp_ajax_nopriv_gc_check_cart_status', 'gc_check_cart_status');
}
function gc_check_cart_status()
{
    $response = [
        'status' => 'success',
        'cart_empty' => true,
    ];

    if (isset($_COOKIE['woocommerce_items_in_cart'])) {
        $response['cart_empty'] = ($_COOKIE['woocommerce_items_in_cart'] == 0);
    } else {
        $cart = WC()->cart;
        if ($cart) {
            $response['cart_empty'] = $cart->is_empty();
        }
    }

    wp_send_json($response);
    exit;
}

/**
 * Enqueue required scripts.
 */
if (get_option('wc_settings_gokwik_section_enable_checkout') == 'yes') {
    add_action('wp_enqueue_scripts', 'gc_enqueue_gokwik_script');
}
function gc_enqueue_gokwik_script()
{
    if (is_checkout() && !empty(is_wc_endpoint_url('order-received'))) {
        return;
    }

    $sandbox_mode = get_option('wc_settings_gokwik_section_sandbox_mode') === 'yes';
    $gcScriptUrl = $sandbox_mode ?
    "https://sandbox.pdp.gokwik.co/v4/build/gokwik.js" :
    "https://pdp.gokwik.co/v4/build/gokwik.js";

    wp_enqueue_script('gcScript', $gcScriptUrl, [], null, true);
}

/**
 * Enqueue custom js in footer.
 */
if (get_option('wc_settings_gokwik_section_enable_checkout') == 'yes') {
    add_action('wp_enqueue_scripts', 'gc_enqueue_custom_script');
}
function gc_enqueue_custom_script()
{
    if (is_checkout() && is_wc_endpoint_url('order-received')) {
        return;
    }
    $custom_script_url = plugin_dir_url(GOKWIKCHECKOUT_FILE) . 'assets/js/frontend/gokwik-custom.js';
    $dependencies = ['jquery', 'gcScript'];
    wp_enqueue_script('gcCustomScript', $custom_script_url, $dependencies, time(), true);
    $overwrite_native_checkout = get_option('wc_settings_gokwik_section_overwrite_native_checkout_page');

    $script_data = [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'environment' => get_option('wc_settings_gokwik_section_sandbox_mode') == 'yes' ? 'sandbox' : 'production',
        'mid' => get_option('wc_settings_gokwik_section_mid'),
        'is_checkout_page' => is_checkout(),
        'is_order_received' => is_wc_endpoint_url('order-received'),
        'is_cart_empty' => WC()->cart->is_empty(),
        'cart_url' => wc_get_cart_url(),
        'checkout_url' => wc_get_checkout_url(),
        'session_id' => WC()->session->get_session_cookie()[0] ?? null,
        'overwrite_native_checkout' => empty($overwrite_native_checkout) ? true : $overwrite_native_checkout == 'yes',
        'gokwik_buy_now_enabled' => get_option('wc_settings_gokwik_section_enable_buy_now_button') == 'yes',
        'is_international_user' => GokwikUtilities::isInternationalUser(),
        'enable_gokwik_checkout_on_side_cart' => get_option('wc_settings_gokwik_section_enable_checkout_from_side_cart', 'yes') == 'yes',
        'enable_gokwik_checkout_on_cart_page' => get_option('wc_settings_gokwik_section_enable_checkout_from_cart_page', 'yes') == 'yes'
    ];
    wp_localize_script('gcCustomScript', 'gc_data', $script_data);
}

/**
 * Enqueue custom CSS.
 */
if (get_option('wc_settings_gokwik_section_enable_checkout') == 'yes') {
    add_action('wp_enqueue_scripts', 'gc_enqueue_custom_css');
}
function gc_enqueue_custom_css()
{
    if (is_checkout() && is_wc_endpoint_url('order-received')) {
        return;
    }
    $custom_css_url = plugin_dir_url(GOKWIKCHECKOUT_FILE) . 'assets/css/frontend/gokwik-custom.css';
    wp_enqueue_style('gcCustomCSS', $custom_css_url, [], time());
    if (is_checkout() && !WC()->cart->is_empty() && get_option('wc_settings_gokwik_section_overwrite_native_checkout_page') != 'no' && !GokwikUtilities::isInternationalUser()) {
        $inline_css = '.woocommerce form.woocommerce-checkout { display: none !important; }';
        wp_add_inline_style('gcCustomCSS', $inline_css);
    }
}

// Make Thank-You page accessible without logging in.
add_filter('woocommerce_order_received_verify_known_shoppers', '__return_false');

// Display PPCOD details in orders table.
add_action('woocommerce_admin_order_totals_after_tax', 'gc_display_ppcod_data_order_table_tr', 20, 1);
function gc_display_ppcod_data_order_table_tr($order_id)
{
    $order = wc_get_order($order_id);
    $ppcod_data = GokwikUtilities::getPpCodFormattedAmounts($order);

    if ($ppcod_data['is_ppcod']) {
        ?>
        <tr>
            <td class="label" style="color:#2f982f;"><?php echo esc_html('Advance Paid'); ?>:</td>
            <td width="1%"></td>
            <td class="total" style="color:#2f982f;">
                <?php echo $ppcod_data['formatted_advance_amount']; ?>
            </td>
        </tr>
        <tr>
            <td class="label" style="color:#f00;"><?php echo esc_html('Due Amount'); ?>:</td>
            <td width="1%"></td>
            <td class="total" style="color:#f00;">
                <?php echo $ppcod_data['formatted_due_amount']; ?>
            </td>
        </tr>
        <?php
}
}

// Display PPCOD details in the WooCommerce admin order details page.
add_action('woocommerce_admin_order_data_after_payment_info', 'gc_display_ppcod_data_order_details', 10, 1);
function gc_display_ppcod_data_order_details($order)
{
    $ppcod_data = GokwikUtilities::getPpCodFormattedAmounts($order);

    if ($ppcod_data['is_ppcod']) {
        echo sprintf(
            '<p style="padding: 0; margin: 0;">
                <strong>Prepaid COD Order</strong> - Advance Paid:
                <span style="color:#2f982f;">%s</span>, Due Amount:
                <span style="color:#f00;">%s</span>
            </p>',
            $ppcod_data['formatted_advance_amount'],
            $ppcod_data['formatted_due_amount']
        );
    }
}

// Display PPCOD details in Thank-you page.
add_filter('woocommerce_get_order_item_totals', 'gc_display_ppcod_data_thank_you_page', 10, 2);
function gc_display_ppcod_data_thank_you_page($total_rows, $order)
{
    $ppcod_data = GokwikUtilities::getPpCodFormattedAmounts($order);

    if ($ppcod_data['is_ppcod']) {
        $total_rows['paid_amt'] = [
            'label' => 'Advance Paid:',
            'value' => $ppcod_data['formatted_advance_amount'],
        ];
        $total_rows['balance_amt'] = [
            'label' => 'Due Amount:',
            'value' => $ppcod_data['formatted_due_amount'],
        ];
    }

    return $total_rows;
}

// Add "Prepaid COD Order" tag to order name in WooCommerce admin.
add_filter('woocommerce_admin_order_preview_get_order_details', 'gc_add_prepaid_cod_tag_to_order_name', 10, 2);
function gc_add_prepaid_cod_tag_to_order_name($order_details, $order)
{
    $order_id = $order->get_id();
    $is_ppcod = GokwikUtilities::isHposEnabled()
    ? $order->get_meta('is_ppcod', true)
    : get_post_meta($order_id, 'is_ppcod', true);

    if ($is_ppcod) {
        $order_details['order_number'] .= ' (Prepaid COD Order)';
    }

    return $order_details;
}

// Adjusts the fee taxes in the WooCommerce cart totals.
add_action('woocommerce_cart_totals_get_fees_from_cart_taxes', 'gc_adjust_fee_taxes', 10, 2);
function gc_adjust_fee_taxes($taxes, $fee)
{
    if ($fee->object->amount < 0 && !$fee->taxable) {
        return [];
    }

    return $taxes;
}

// Add "Buy Now" button after the "Add to Cart" button on product pages
if (get_option('wc_settings_gokwik_section_enable_buy_now_button') == 'yes') {
    add_action('woocommerce_after_add_to_cart_button', 'gc_add_buy_now_button');
}
function gc_add_buy_now_button()
{
    global $product;

    if (!$product || !$product->is_purchasable() || !$product->is_in_stock() || !$product->is_type(['simple', 'variable'])) {
        return;
    }

    $is_international_user = GokwikUtilities::isInternationalUser();
    if (!$is_international_user) {
        echo '<button type="button" class="gk-buy-now-btn single_add_to_cart_button button alt">Buy now</button>';
    }
}

// Handle AJAX add to cart action for GoKwik checkout
if (get_option('wc_settings_gokwik_section_enable_buy_now_button') == 'yes') {
    add_action('wc_ajax_gc_add_to_cart', 'gc_add_to_cart');
}
function gc_add_to_cart()
{
    if (!isset($_POST['add-to-cart'])) {
        return;
    }
    do_action('woocommerce_ajax_added_to_cart', intval($_POST['add-to-cart']));
    WC_AJAX::get_refreshed_fragments();
}

// All good, let's initizalize.
add_action('plugins_loaded', 'init_gokwik_checkout_plugin');
function init_gokwik_checkout_plugin()
{
    GokwikCheckout::instance();
}
