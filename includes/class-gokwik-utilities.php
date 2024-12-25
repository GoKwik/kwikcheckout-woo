<?php

namespace Gokwik_Checkout\Inc;

use Exception;
use WC_Geolocation;
use WC_Order;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class GokwikUtilities
{
    /**
     * Check if WooCommerce HPOS is enabled.
     *
     * @return boolean true if HPOS is enabled, false otherwise.
     * @since 1.0.4
     */
    public static function isHposEnabled()
    {
        return class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Format a phone number to include the country code.
     *
     * @access protected
     * @param string $phone_number The phone number to format.
     *
     * @return string The formatted phone number.
     * @since 1.0.4
     */
    public static function formatPhoneNumber($phone_number)
    {
        $phone_number = preg_replace('/[^0-9]/', '', $phone_number);
        $number_length = strlen($phone_number);
        if ($number_length === 12 && substr($phone_number, 0, 2) === '91') {
            return '+' . $phone_number;
        } elseif ($number_length === 10) {
            return '+91' . $phone_number;
        }
        return $phone_number;
    }

    /**
     * Get the state code for a given state name.
     *
     * @access protected
     * @param string $shipping_state The name of the state.
     *
     * @return string The state code if found, otherwise the original state name.
     * @since 1.0.2
     */
    public static function getStateCode($shipping_state)
    {
        static $indian_states = [
            'AP' => 'Andhra Pradesh',
            'AR' => 'Arunachal Pradesh',
            'AS' => 'Assam',
            'BR' => 'Bihar',
            'CT' => 'Chhattisgarh',
            'GA' => 'Goa',
            'GJ' => 'Gujarat',
            'HR' => 'Haryana',
            'HP' => 'Himachal Pradesh',
            'JK' => 'Jammu and Kashmir',
            'JK' => 'Jammu & Kashmir',
            'JH' => 'Jharkhand',
            'KA' => 'Karnataka',
            'KL' => 'Kerala',
            'MP' => 'Madhya Pradesh',
            'MH' => 'Maharashtra',
            'MN' => 'Manipur',
            'ML' => 'Meghalaya',
            'MZ' => 'Mizoram',
            'NL' => 'Nagaland',
            'OR' => 'Odisha',
            'OR' => 'Orissa',
            'PB' => 'Punjab',
            'RJ' => 'Rajasthan',
            'SK' => 'Sikkim',
            'TN' => 'Tamil Nadu',
            'TS' => 'Telangana',
            'TR' => 'Tripura',
            'UP' => 'Uttar Pradesh',
            'UT' => 'Uttarakhand',
            'WB' => 'West Bengal',
            'AN' => 'Andaman and Nicobar Islands',
            'AN' => 'Andaman & Nicobar Islands',
            'CH' => 'Chandigarh',
            'DN' => 'Dadra and Nagar Haveli',
            'DN' => 'Dadra & Nagar Haveli',
            'DD' => 'Daman and Diu',
            'DD' => 'Daman & Diu',
            'LD' => 'Lakshadweep',
            'DL' => 'Delhi',
            'PY' => 'Puducherry',
            'PY' => 'Pondicherry',
            'LA' => 'Ladakh',
        ];

        foreach ($indian_states as $state_code => $state_name) {
            if (strcasecmp($state_name, $shipping_state) === 0) {
                return $state_code;
            }
        }

        return $shipping_state;
    }

    /**
     * Calculate the extra fee or discount based on the given parameters.
     *
     * @param float $cartSubtotal The cart subtotal.
     * @param string $valueOption The option name for the fee or discount value.
     * @param string $typeOption The option name for the fee or discount type.
     * @param string $minCartValueOption The option name for the minimum cart value.
     * @param string $maxCartValueOption The option name for the maximum cart value.
     * @return float The calculated fee or discount.
     * @since 1.0.4
     */
    public static function calculateValue($cartSubtotal, $valueOption, $typeOption, $minCartValueOption, $maxCartValueOption)
    {
        $value = floatval(get_option($valueOption, 0));
        $type = get_option($typeOption, 'fixed');
        $minCartValue = get_option($minCartValueOption, '');
        $maxCartValue = get_option($maxCartValueOption, '');
        $minCartValue = ($minCartValue == '') ? 0 : floatval($minCartValue);
        $maxCartValue = ($maxCartValue == '') ? PHP_FLOAT_MAX : floatval($maxCartValue);

        if ($cartSubtotal >= $minCartValue && $cartSubtotal <= $maxCartValue) {
            return ($type === 'percentage') ? ($cartSubtotal * $value / 100) : $value;
        }

        return 0.00;
    }

    /**
     * Calculate the extra fees and discount for prepaid payments.
     *
     * @return array The calculated extra fees and discount.
     * @since 1.0.4
     */
    public static function calculatePrepaidExtraFeesAndDiscount($cartSubtotal)
    {
        $result = [
            'fee' => 0.00,
            'discount' => 0.00,
        ];

        if (get_option('wc_settings_gokwik_enable_prepaid_extra_fees', 'no') == 'yes') {
            $result['fee'] = self::calculateValue(
                $cartSubtotal,
                'wc_settings_gokwik_prepaid_extra_fees',
                'wc_settings_gokwik_prepaid_extra_fees_type',
                'wc_settings_gokwik_prepaid_min_cart_value_fees',
                'wc_settings_gokwik_prepaid_max_cart_value_fees'
            );
        }

        if (get_option('wc_settings_gokwik_enable_prepaid_discount', 'no') == 'yes') {
            $result['discount'] = self::calculateValue(
                $cartSubtotal,
                'wc_settings_gokwik_prepaid_discount',
                'wc_settings_gokwik_prepaid_discount_type',
                'wc_settings_gokwik_prepaid_min_cart_value_discount',
                'wc_settings_gokwik_prepaid_max_cart_value_discount'
            );
        }

        return $result;
    }

    /**
     * Calculate the extra fees and discount for Cash on Delivery (COD).
     *
     * @return array The calculated extra fees and discount.
     * @since 1.0.4
     */
    public static function calculateCodExtraFeesAndDiscount($cartSubtotal)
    {
        $result = [
            'fee' => 0.00,
            'discount' => 0.00,
        ];

        if (get_option('wc_settings_gokwik_enable_cod_extra_fees', 'no') == 'yes') {
            $result['fee'] = self::calculateValue(
                $cartSubtotal,
                'wc_settings_gokwik_cod_extra_fees',
                'wc_settings_gokwik_cod_extra_fees_type',
                'wc_settings_gokwik_cod_min_cart_value_fees',
                'wc_settings_gokwik_cod_max_cart_value_fees'
            );
        }

        if (get_option('wc_settings_gokwik_enable_cod_discount', 'no') == 'yes') {
            $result['discount'] = self::calculateValue(
                $cartSubtotal,
                'wc_settings_gokwik_cod_discount',
                'wc_settings_gokwik_cod_discount_type',
                'wc_settings_gokwik_cod_min_cart_value_discount',
                'wc_settings_gokwik_cod_max_cart_value_discount'
            );
        }

        return $result;
    }

    /**
     * Check if COD (Cash on Delivery) is available based on custom GoKwik settings.
     *
     * @return bool true if COD is available, false otherwise.
     */
    public static function isCodAvailable($wc_cart)
    {
        if (empty($wc_cart) || !is_object($wc_cart)) {
            return true;
        }

        $cartSubtotal = ($wc_cart->get_subtotal() ?? 0) + ($wc_cart->get_shipping_total() ?? 0);
        $minCartValue = floatval(get_option('wc_settings_gokwik_cod_min_cart_value_enable', 0) ?: 0);
        $maxCartValue = floatval(get_option('wc_settings_gokwik_cod_max_cart_value_enable', PHP_INT_MAX) ?: PHP_INT_MAX);

        if ($cartSubtotal < $minCartValue || $cartSubtotal > $maxCartValue) {
            return false;
        }

        $cartProducts = array_map(function ($cartItem) {
            return strval($cartItem['variation_id'] ? $cartItem['variation_id'] : $cartItem['product_id']);
        }, $wc_cart->get_cart());

        $cartCategories = [];
        foreach ($wc_cart->get_cart() as $cartItem) {
            $productCategories = wp_get_post_terms($cartItem['product_id'], 'product_cat', ['fields' => 'ids']);
            $cartCategories = array_merge($cartCategories, $productCategories);
        }
        $cartCategories = array_unique($cartCategories);

        $codCategories = get_option('wc_settings_gokwik_cod_categories', []);
        $codProducts = get_option('wc_settings_gokwik_cod_products', []);
        $codEnableDisable = get_option('wc_settings_gokwik_cod_enable_disable', 'enable');
        $codRestrictionMode = get_option('wc_settings_gokwik_cod_restriction_mode', 'any');

        if (!empty($codCategories)) {
            $expandedCodCategories = [];
            foreach ($codCategories as $categoryId) {
                $expandedCodCategories[] = $categoryId;
                $childCategories = get_term_children($categoryId, 'product_cat');
                if (!is_wp_error($childCategories)) {
                    $expandedCodCategories = array_merge($expandedCodCategories, $childCategories);
                }
            }
            $codCategories = array_unique($expandedCodCategories);
        }

        if (!empty($codProducts)) {
            $expandedCodProducts = [];
            foreach ($codProducts as $productId) {
                $expandedCodProducts[] = $productId;
                $productVariations = get_children([
                    'post_parent' => $productId,
                    'post_type' => 'product_variation',
                    'fields' => 'ids',
                ]);
                if (!is_wp_error($productVariations)) {
                    $expandedCodProducts = array_merge($expandedCodProducts, $productVariations);
                }
            }
            $codProducts = array_unique($expandedCodProducts);
        }

        $categoryMatch = !empty($codCategories) && array_intersect($cartCategories, $codCategories);
        $productMatch = !empty($codProducts) && array_intersect($cartProducts, $codProducts);

        if ($codEnableDisable === 'disable') {
            if (!empty($codCategories) || !empty($codProducts)) {
                return !($categoryMatch || $productMatch);
            }
        }

        if ($codEnableDisable === 'enable') {
            if (!empty($codCategories) || !empty($codProducts)) {
                if ($codRestrictionMode === 'all') {
                    if ((!empty($codCategories) && count(array_intersect($cartCategories, $codCategories)) !== count($cartCategories)) ||
                        (!empty($codProducts) && count(array_intersect($cartProducts, $codProducts)) !== count($cartProducts))) {
                        return false;
                    }
                } else {
                    if ((!empty($codCategories) && !$categoryMatch) || (!empty($codProducts) && !$productMatch)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Create an order from the current cart.
     *
     * @param array $wc_session_data
     * @param string $session_key
     * @throws Exception
     * @return WC_Order
     */
    public static function createOrderFromCart($wc_session_data, $session_key)
    {
        if (WC()->cart->is_empty()) {
            throw new Exception('The cart is empty.');
        }

        $order = new WC_Order();
        $order->set_status('pending');
        $order->set_created_via('checkout');
        $order->update_meta_data('_gk_session_key', $session_key);

        return self::updateOrderFromCart($order, $wc_session_data);
    }

    /**
     * Update an order using data from the current cart.
     *
     * @param WC_Order $order
     * @param array $wc_session_data
     * @return WC_Order
     */
    public static function updateOrderFromCart(WC_Order $order, $wc_session_data)
    {
        self::updateLineItemsFromCart($order);
        self::updateAddressesFromCart($order, $wc_session_data);

        $customer_id = apply_filters('woocommerce_checkout_customer_id', get_current_user_id());
        $order->set_customer_id($customer_id);

        $currency = get_woocommerce_currency();
        $order->set_currency($currency);

        $prices_include_tax = 'yes' === get_option('woocommerce_prices_include_tax');
        $order->set_prices_include_tax($prices_include_tax);

        $wc_session_data = maybe_unserialize($wc_session_data);
        $chosen_payment_method = maybe_unserialize($wc_session_data['chosen_payment_method']) ?? '';
        if ($chosen_payment_method) {
            $payment_gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($payment_gateways[$chosen_payment_method])) {
                $order->set_payment_method($payment_gateways[$chosen_payment_method]);
                $order->save();
            }
        }

        $is_vat_exempt = WC()->cart->get_customer()->get_is_vat_exempt() ? 'yes' : 'no';
        $order->update_meta_data('is_vat_exempt', $is_vat_exempt);

        $order->calculate_totals();
        $order->save();

        return $order;
    }

    /**
     * Update order line items from the cart.
     *
     * @param WC_Order $order
     */
    protected static function updateLineItemsFromCart(WC_Order $order)
    {
        $order->remove_order_items();
        WC()->checkout->set_data_from_cart($order, WC()->cart);
    }

    /**
     * Update address of an order using the cart and customer session data.
     *
     * @param WC_Order $order
     * @param array $wc_session_data
     */
    protected static function updateAddressesFromCart(WC_Order $order, $wc_session_data)
    {
        $user_id = get_current_user_id();
        if ($user_id != "0") {
            $customer_data = [
                'first_name' => get_user_meta($user_id, 'billing_first_name', true),
                'last_name' => get_user_meta($user_id, 'billing_last_name', true),
                'company' => get_user_meta($user_id, 'billing_company', true),
                'address_1' => get_user_meta($user_id, 'billing_address_1', true),
                'address_2' => get_user_meta($user_id, 'billing_address_2', true),
                'city' => get_user_meta($user_id, 'billing_city', true),
                'state' => get_user_meta($user_id, 'billing_state', true),
                'postcode' => get_user_meta($user_id, 'billing_postcode', true),
                'country' => get_user_meta($user_id, 'billing_country', true),
                'email' => get_user_meta($user_id, 'billing_email', true),
                'phone' => get_user_meta($user_id, 'billing_phone', true),
                'shipping_first_name' => get_user_meta($user_id, 'shipping_first_name', true),
                'shipping_last_name' => get_user_meta($user_id, 'shipping_last_name', true),
                'shipping_company' => get_user_meta($user_id, 'shipping_company', true),
                'shipping_address_1' => get_user_meta($user_id, 'shipping_address_1', true),
                'shipping_address_2' => get_user_meta($user_id, 'shipping_address_2', true),
                'shipping_city' => get_user_meta($user_id, 'shipping_city', true),
                'shipping_state' => get_user_meta($user_id, 'shipping_state', true),
                'shipping_postcode' => get_user_meta($user_id, 'shipping_postcode', true),
                'shipping_country' => get_user_meta($user_id, 'shipping_country', true),
                'shipping_phone' => get_user_meta($user_id, 'shipping_phone', true),
            ];
        } else {
            $customer_data = maybe_unserialize(maybe_unserialize($wc_session_data)['customer']) ?? [];
        }

        $order->set_props([
            'billing_first_name' => $customer_data['first_name'] ?? '',
            'billing_last_name' => $customer_data['last_name'] ?? '',
            'billing_company' => $customer_data['company'] ?? '',
            'billing_address_1' => $customer_data['address_1'] ?? '',
            'billing_address_2' => $customer_data['address_2'] ?? '',
            'billing_city' => $customer_data['city'] ?? '',
            'billing_state' => $customer_data['state'] ?? '',
            'billing_postcode' => $customer_data['postcode'] ?? '',
            'billing_country' => $customer_data['country'] ?? '',
            'billing_email' => $customer_data['email'] ?? '',
            'billing_phone' => $customer_data['phone'] ?? '',
            'shipping_first_name' => $customer_data['shipping_first_name'] ?? '',
            'shipping_last_name' => $customer_data['shipping_last_name'] ?? '',
            'shipping_company' => $customer_data['shipping_company'] ?? '',
            'shipping_address_1' => $customer_data['shipping_address_1'] ?? '',
            'shipping_address_2' => $customer_data['shipping_address_2'] ?? '',
            'shipping_city' => $customer_data['shipping_city'] ?? '',
            'shipping_state' => $customer_data['shipping_state'] ?? '',
            'shipping_postcode' => $customer_data['shipping_postcode'] ?? '',
            'shipping_country' => $customer_data['shipping_country'] ?? '',
            'shipping_phone' => $customer_data['shipping_phone'] ?? '',
        ]);
    }

    /**
     * Get a pending order by session key.
     *
     * @param string $session_ke
     *
     * @return WC_Order|null
     */
    public static function getPendingOrder($session_key)
    {
        $orders = wc_get_orders([
            'limit' => 1,
            'type' => 'shop_order',
            'status' => 'pending',
            'meta_key' => '_gk_session_key',
            'meta_value' => $session_key,
            'orderby' => 'date',
            'order' => 'DESC',
            'exclude' => ['trash'],
        ]);

        return $orders[0] ?? null;
    }

    /**
     * Get formatted amounts for PPCOD orders.
     *
     * @param WC_Order $order
     *
     * @return array
     */
    public static function getPpCodFormattedAmounts($order)
    {
        $is_ppcod = self::isHposEnabled()
        ? $order->get_meta('is_ppcod', true)
        : get_post_meta($order->get_id(), 'is_ppcod', true);

        if (!$is_ppcod) {
            return ['is_ppcod' => false];
        }

        $advance_amount = self::isHposEnabled()
        ? $order->get_meta('advance_paid', true)
        : get_post_meta($order->get_id(), 'advance_paid', true);
        $due_amount = self::isHposEnabled()
        ? $order->get_meta('due_amount', true)
        : get_post_meta($order->get_id(), 'due_amount', true);

        $formatted_advance_amount = wc_price($advance_amount ?: 0.00, ['currency' => $order->get_currency()]);
        $formatted_due_amount = wc_price($due_amount ?: 0.00, ['currency' => $order->get_currency()]);

        return [
            'is_ppcod' => true,
            'formatted_advance_amount' => $formatted_advance_amount,
            'formatted_due_amount' => $formatted_due_amount,
        ];
    }

    /**
     * Save the current state of the cart for WooCommerce Cart Abandonment Recovery Plugin.
     *
     * @return void
     */
    public static function saveCartFlowsAbandonedCartData($wc_session_data, $session_key)
    {
        global $wpdb;

        if (!is_plugin_active('woo-cart-abandonment-recovery/woo-cart-abandonment-recovery.php')) {
            return;
        }

        $cart_contents = WC()->cart->get_cart();
        $currentTime = current_time('Y-m-d H:i:s');
        $cartTotal = WC()->cart->total;

        $customer_data = maybe_unserialize(maybe_unserialize($wc_session_data)['customer']) ?? [];
        $otherFields = [
            'wcf_billing_company' => $customer_data['company'] ?? '',
            'wcf_billing_address_1' => $customer_data['address_1'] ?? '',
            'wcf_billing_address_2' => $customer_data['address_2'] ?? '',
            'wcf_billing_state' => $customer_data['state'] ?? '',
            'wcf_billing_postcode' => $customer_data['postcode'] ?? '',
            'wcf_shipping_first_name' => $customer_data['shipping_first_name'] ?? '',
            'wcf_shipping_last_name' => $customer_data['shipping_last_name'] ?? '',
            'wcf_shipping_company' => $customer_data['shipping_company'] ?? '',
            'wcf_shipping_country' => $customer_data['shipping_country'] ?? '',
            'wcf_shipping_address_1' => $customer_data['shipping_address_1'] ?? '',
            'wcf_shipping_address_2' => $customer_data['shipping_address_2'] ?? '',
            'wcf_shipping_city' => $customer_data['shipping_city'] ?? '',
            'wcf_shipping_state' => $customer_data['shipping_state'] ?? '',
            'wcf_shipping_postcode' => $customer_data['shipping_postcode'] ?? '',
            'wcf_order_comments' => $customer_data['order_comments'] ?? '',
            'wcf_first_name' => $customer_data['first_name'] ?? '',
            'wcf_last_name' => $customer_data['last_name'] ?? '',
            'wcf_phone_number' => $customer_data['phone'] ?? '',
            'wcf_location' => $customer_data['country'] ?? '',
        ];

        $checkoutDetails = [
            'email' => $customer_data['email'] ?? '',
            'cart_contents' => maybe_serialize($cart_contents),
            'cart_total' => $cartTotal,
            'time' => $currentTime,
            'other_fields' => maybe_serialize($otherFields),
            'checkout_id' => wc_get_page_id('cart'),
        ];

        $sessionId = WC()->session->get('wcf_session_id');
        $cartAbandonmentTable = $wpdb->prefix . "cartflows_ca_cart_abandonment";

        if (!empty($checkoutDetails)) {
            $result = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `$cartAbandonmentTable` WHERE session_id = %s AND order_status IN (%s, %s)",
                    $sessionId, 'normal', 'abandoned'
                )
            );

            if ($result) {
                $update_result = $wpdb->update(
                    $cartAbandonmentTable,
                    $checkoutDetails,
                    ['session_id' => $sessionId]
                );
                return $update_result !== false;
            } else {
                $sessionId = md5(uniqid(wp_rand(), true));
                $checkoutDetails['session_id'] = sanitize_text_field($sessionId);
                $insert_result = $wpdb->insert($cartAbandonmentTable, $checkoutDetails);
                if ($insert_result !== false) {
                    $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
                    $wc_session_data['wcf_session_id'] = $sessionId;
                    $serialized_session = maybe_serialize($wc_session_data);
                    $wpdb->update($sessions_table, ['session_value' => $serialized_session], ['session_key' => $session_key]);
                }
                return $insert_result !== false;
            }
        }
        return false;
    }

    /**
     * Determine if the current user is an international user based on their IP address.
     *
     * @return bool True if the user is international, false if the user is from India.
     */
    public static function isInternationalUser()
    {
        $ip_address = WC_Geolocation::get_ip_address();
        $cache_key = 'gk_user_country_code_' . md5($ip_address);
        $country_code = wp_cache_get($cache_key);

        if ($country_code === false) {
            $geolocation = WC_Geolocation::geolocate_ip();

            if (!empty($geolocation['country'])) {
                $country_code = $geolocation['country'];
            } elseif (function_exists('geoip_country_code_by_name')) {
                $country_code = geoip_country_code_by_name($ip_address);
            }

            wp_cache_set($cache_key, $country_code, '', 86400);
        }

        return $country_code !== 'IN';
    }
}
