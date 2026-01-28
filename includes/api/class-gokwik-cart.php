<?php

namespace Gokwik_Checkout\Inc\Api;

use Gokwik_Checkout\Inc\GokwikUtilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class GokwikCart
{
    /**
     * API namespace.
     *
     * @var string
     */
    protected $namespace = 'gokwik/v1';

    /**
     * API endpoint namespace.
     *
     * @var string
     */
    protected $rest_base = 'cart';

    /**
     * The single instance of this class.
     *
     * @var GokwikCart
     * @access protected
     * @static
     * @since 1.0.0
     */
    protected static $_instance = null;

    /**
     * Constructor method.
     * Registers the REST API endpoints.
     */
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'registerRestEndpoints']);
    }

    /**
     * Prevent object cloning.
     */
    public function __clone()
    {
        // Cloning is not allowed.
    }

    /**
     * Prevent object unserialization.
     */
    public function __wakeup()
    {
        // Unserialization is not allowed.
    }

    /**
     * Returns a single instance of the class.
     *
     * @return GokwikCart
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Register REST API endpoints.
     */
    public function registerRestEndpoints()
    {
        $routes = [
            [
                'route' => '/' . $this->rest_base,
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'getCart'
            ],
            [
                'route' => '/' . $this->rest_base . '/get-coupons',
                'methods' => WP_REST_Server::READABLE,
                'callback' => 'getCoupons'
            ],
            [
                'route' => '/' . $this->rest_base . '/apply-coupon',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'applyCoupon'
            ],
            [
                'route' => '/' . $this->rest_base . '/remove-coupon',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'removeCoupon'
            ],
            [
                'route' => '/' . $this->rest_base . '/set-address',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'setAddress'
            ],
            [
                'route' => '/' . $this->rest_base . '/set-shipping-method',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'setShippingMethod'
            ],
            [
                'route' => '/' . $this->rest_base . '/get-wallet-balance',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'getWalletBalance'
            ],
            [
                'route' => '/' . $this->rest_base . '/deduct-wallet-balance',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'deductWalletBalance'
            ],
            [
                'route' => '/' . $this->rest_base . '/place-order',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'placeOrder'
            ],
            [
                'route' => '/' . $this->rest_base . '/check-order-exists',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'checkOrderExists'
            ],
            [
                'route' => '/' . $this->rest_base . '/update-order-status',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'updateOrderStatus'
            ],
            [
                'route' => '/' . $this->rest_base . '/process-refund',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'refundProcess',
                'permission_callback' => false
            ],
            [
                'route' => '/' . $this->rest_base . '/health-check',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'healthCheck'
            ],
            [
                'route' => '/' . $this->rest_base . '/remove-out-of-stock-items',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'removeOutOfStockItems'
            ],
			[
                'route' => '/' . $this->rest_base . '/fetch-customer-details',
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'fetchCustomerDetails'
            ]
        ];

        foreach ($routes as $route) {
            register_rest_route(
                $this->namespace,
                $route['route'],
                [
                    [
                        'methods' => $route['methods'],
                        'callback' => [$this, $route['callback']],
                        'permission_callback' => $route['permission_callback'] ?? [$this, 'validateRequest'],
                    ],
                ]
            );
        }
    }

    /**
     * Validate API request authentication headers.
     *
     * @param WP_REST_Request $request
     *
     * @return bool
     * @since 1.0.2
     */
    public function validateRequest($request)
    {
        $appIdHeader = $request->get_header('appid') ?: $request->get_header('app-id');
        $appSecretHeader = $request->get_header('appsecret') ?: $request->get_header('app-secret');

        if (empty($appIdHeader) || empty($appSecretHeader)) {
            return false;
        }

        $apiId = sanitize_text_field($appIdHeader);
        $apiSecret = sanitize_text_field($appSecretHeader);

        $storedAppId = get_option('wc_settings_gokwik_section_app_id', null);
        $storedAppSecret = get_option('wc_settings_gokwik_section_app_secret', null);

        return $apiId === $storedAppId && $apiSecret === $storedAppSecret;
    }

    /**
     * Load cart session based on the provided session_key.
     *
     * @access protected
     * @param string $session_key
     *
     * @return array|WP_Error
     * @since 1.0.0
     */
    protected function loadCartSession($session_key)
    {
        if (empty($session_key)) {
            return new WP_Error(
                'gc_missing_session_key',
                'Session key is missing.',
                ['status' => 400]
            );
        }

        $cache_key = \WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP) . $session_key;
        if (wp_cache_get($cache_key, WC_SESSION_CACHE_GROUP) !== false) {
            wp_cache_delete($cache_key, WC_SESSION_CACHE_GROUP);
        }

        $sessionHandler = new \WC_Session_Handler();
        $wc_session_data = $sessionHandler->get_session($session_key);

        if (empty($wc_session_data)) {
            return new WP_Error(
                'gc_cart_not_found',
                'Cart not found.',
                ['status' => 404]
            );
        }

        add_filter('woo_wallet_partial_payment_amount', '__return_zero', PHP_INT_MAX);

        $userData = get_userdata($session_key);
        if ($userData !== false) {
            wp_set_current_user($session_key);
        } else {
            $customerEmail = maybe_unserialize($wc_session_data)['customer_email'] ?? null;
            if ($customerEmail) {
                $user = get_user_by('email', $customerEmail);
                if ($user) {
                    wp_set_current_user($user->ID);
                }
            }
        }

        if (defined("WC_ABSPATH")) {
            include_once WC_ABSPATH . "includes/wc-cart-functions.php";
            include_once WC_ABSPATH . "includes/wc-notice-functions.php";
            include_once WC_ABSPATH . "includes/wc-template-hooks.php";
        }

        if (WC()->session === null) {
            $session_class = apply_filters("woocommerce_session_handler", "WC_Session_Handler");
            WC()->session = new $session_class();
            WC()->session->init();
        }

        $session = WC()->session;
        foreach ($wc_session_data as $key => $value) {
            $session->set($key, maybe_unserialize($value));
        }

        if (WC()->customer === null) {
            WC()->customer = new \WC_Customer(get_current_user_id(), true);
        }

        if (WC()->cart === null) {
            WC()->cart = new \WC_Cart();
        }

        WC()->cart->get_cart_from_session();

        if (WC()->cart->is_empty()) {
            $cart_contents = $session->get('cart', null);
            if (empty($cart_contents)) {
                $cart_contents = maybe_unserialize($wc_session_data['cart'] ?? '');
            }
            if (!empty($cart_contents)) {
                WC()->cart->set_cart_contents($cart_contents);
            }
        }

        WC()->cart->calculate_shipping();
        WC()->cart->calculate_fees();
        WC()->cart->calculate_totals();

        return [
            maybe_unserialize($wc_session_data),
            WC()->cart,
        ];
    }

    /**
     * Get cart data.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     * @since 1.0.0
     */
    public function getCart($request)
    {
        $session_key = $request->get_param('session_key');
        $cartData = $this->getCartData($session_key, $request);

        if (is_wp_error($cartData)) {
            return $cartData;
        }

        $cartResponse = $cartData['cartResponse'];
        $wc_session_data = $cartData['wc_session_data'];
        $cartObj = $cartData['cartObj'];
        $cart_customer = $cartData['cart_customer'];
        $cart_payment_method = $cartData['cart_payment_method'];

        $isPendingOrderFlowEnabled = defined('GOKWIKCHECKOUT_PENDING_ORDER_FLOW') && GOKWIKCHECKOUT_PENDING_ORDER_FLOW;
        $hasValidPhone = !empty($cart_customer['phone']) && $cart_customer['phone'] !== '1234567890';
        $hasValidPaymentMethod = in_array(
            strtolower($cart_payment_method),
            ['cod', 'gokwik_prepaid', 'wallet'],
            true
        );

        if ($isPendingOrderFlowEnabled) {
            if ($hasValidPhone || $hasValidPaymentMethod) {
                $this->createOrUpdatePendingOrder($wc_session_data, $session_key);
            }
        } elseif ($hasValidPhone) {
            GokwikUtilities::saveCartFlowsAbandonedCartData($wc_session_data, $session_key);
        }

        $userData = get_userdata($session_key);
        if ($userData === false) {
            $cartObj->empty_cart();
            WC()->session->destroy_session();
        } else {
            WC()->cart->persistent_cart_update($userData->ID);
        }
		
		$logger = wc_get_logger();
		$logger->info('Get Cart API : '.json_encode($cartResponse), array('source' => 'custom-log'));

        return new WP_REST_Response($cartResponse, 200);
    }

    /**
     * Retrieve cart data for a given session key.
     *
     * @param string $session_key
     * @param WP_REST_Request $request
     *
     * @return array|WP_Error
     * @since 1.1.4
     */
    public function getCartData($session_key, $request)
    {
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        }

        [$wc_session_data, $cartObj] = $cartSessionResponse;

        $cart_customer = maybe_unserialize($wc_session_data['customer']) ?? [];
        if (empty($cart_customer['country']) || empty($cart_customer['state']) || empty($cart_customer['postcode'])) {
            $cart_customer['country'] = 'IN';
            $cart_customer['state'] = 'MH';
            $cart_customer['postcode'] = '400001';
            WC()->customer->set_shipping_country($cart_customer['country']);
            WC()->customer->set_shipping_state($cart_customer['state']);
            WC()->customer->set_shipping_postcode($cart_customer['postcode']);
        }

        $cart_shipping_method = maybe_unserialize($wc_session_data['chosen_shipping_methods'] ?? []);
        if (!empty($cart_shipping_method)) {
            $current_shipping_method = WC()->session->get('chosen_shipping_methods', []);
            if ($cart_shipping_method != $current_shipping_method) {
                WC()->session->set('chosen_shipping_methods', $cart_shipping_method);
            }
        }

        $cart_payment_method = maybe_unserialize($wc_session_data['chosen_payment_method'] ?? "");
        if (!empty($cart_payment_method)) {
            $current_payment_method = WC()->session->get('chosen_payment_method', "");
            if ($cart_payment_method != $current_payment_method) {
                WC()->session->set('chosen_payment_method', $cart_payment_method);
            }
        }

        $cart_applied_coupon = maybe_unserialize($wc_session_data['applied_coupons'] ?? []);
        if (!empty($cart_applied_coupon)) {
            $current_applied_coupons = WC()->session->get('applied_coupons', []);
            if ($cart_applied_coupon != $current_applied_coupons) {
                WC()->session->set('applied_coupons', $cart_applied_coupon);
            }
        }

        $cart_data_items = array_map('maybe_unserialize', $cartObj->cart_contents ?? []);
        if (empty($cart_data_items)) {
            return new WP_Error(
                'gc_cart_has_no_items',
                'Cart is empty.',
                ['status' => 400]
            );
        }

        $cart_items = [];
        $has_virtual_product = false;
        foreach ($cart_data_items as $item) {
            $cart_items[] = $this->prepareCartItem($item, $request);
            if (isset($item['data']) && $item['data']->is_virtual()) {
                $has_virtual_product = true;
            }
        }

        $cartObj->calculate_shipping();
        $cartObj->calculate_fees();
        $cartObj->calculate_totals();

        unset(
            $cart_customer['meta_data'],
            $cart_customer['date_modified']
        );

        $shipping_methods = [];
        $shipping_packages = WC()->shipping->get_packages();

        foreach ($shipping_packages as $package_id => $package) {
            $shipping_session_key = 'shipping_for_package_' . $package_id;
            $session_data = WC()->session->get($shipping_session_key);
            $shipping_rates = $session_data['rates'] ?? $package['rates'];

            $country = $cart_customer['country'];
            $state = $cart_customer['state'];
            $postcode = $cart_customer['postcode'];

            if (is_numeric($session_key) && $session_key !== 0) {
                $user_data = get_userdata($session_key);
                if ($user_data) {
                    $country = get_user_meta($session_key, 'shipping_country', true);
                    $state = get_user_meta($session_key, 'shipping_state', true);
                    $postcode = get_user_meta($session_key, 'shipping_postcode', true);
                }
            }

            if (empty($shipping_rates)) {
                $package = [
                    'contents' => $cartObj->get_cart(),
                    'destination' => [
                        'country' => $country,
                        'state' => $state,
                        'postcode' => $postcode,
                    ],
                    'cart_subtotal' => $cartObj->get_displayed_subtotal(),
                ];
                $calculated_shipping = WC()->shipping->calculate_shipping_for_package($package);
                $shipping_rates = $calculated_shipping['rates'] ?? [];
            }

            foreach ($shipping_rates as $shipping_rate) {
                $tax_cost = $shipping_rate->get_shipping_tax();
                $taxes = $shipping_rate->get_taxes();
                $charge = $shipping_rate->get_cost();
                if (wc_prices_include_tax()) {
                    $charge += $tax_cost;
                }

                $shipping_methods[] = [
                    'method_id' => $shipping_rate->get_id(),
                    'rate_id' => $shipping_rate->get_method_id(),
                    'instance_id' => $shipping_rate->get_instance_id(),
                    'method_name' => $shipping_rate->get_label(),
                    'charge' => $charge,
                    'tax_cost' => $tax_cost,
                    'taxes' => $taxes,
                    'payment_mode' => ["cod", "prepaid"],
                ];
            }
        }

        if (empty($shipping_methods) && $has_virtual_product) {
            $shipping_methods[] = [
                'method_id' => 'free_shipping_virtual',
                'rate_id' => 'free_shipping',
                'instance_id' => 0,
                'method_name' => 'Free Shipping',
                'charge' => 0,
                'tax_cost' => 0,
                'taxes' => [],
                'payment_mode' => ["cod", "prepaid"],
            ];
        }

        $customerEmail = maybe_unserialize($wc_session_data)['customer_email'] ?? null;
        if (!empty($cart_applied_coupon)) {
            foreach ($cart_applied_coupon as $coupon_code) {
                $coupon_code = wc_format_coupon_code($coupon_code);
                $coupon = new \WC_Coupon($coupon_code);
                $discounts = new \WC_Discounts($cartObj);
                $is_valid = $discounts->is_coupon_valid($coupon);
                if (
                    is_wp_error($is_valid) ||
                    ($customerEmail && !$this->checkCouponUsage($coupon, $customerEmail))
                ) {
                    $couponRemoved = $cartObj->remove_coupon($coupon_code);
                    if ($couponRemoved) {
                        $this->removeCouponFromSession($session_key, $coupon_code);
                    }
                }
            }
        }

        $totals = $cartObj->get_totals();
        if (wc_prices_include_tax()) {
            $totals['subtotal'] += $totals['subtotal_tax'] ?? 0;
            $totals['shipping_total'] += $totals['shipping_tax'] ?? 0;
            $totals['cart_contents_total'] += $totals['cart_contents_tax'] ?? 0;
            $totals['fee_total'] += $totals['fee_tax'] ?? 0;
            $totals['discount_total'] += $totals['discount_tax'] ?? 0;
            $totals['subtotal_tax'] = 0;
            $totals['shipping_tax'] = 0;
            $totals['shipping_taxes'] = [];
            $totals['cart_contents_tax'] = 0;
            $totals['cart_contents_taxes'] = [];
            $totals['fee_tax'] = 0;
            $totals['fee_taxes'] = [];
            $totals['discount_tax'] = 0;
            $totals['discount_taxes'] = [];
            $totals['total_tax'] = 0;
        }

        $cartResponse = [
            'user_id' => get_current_user_id(),
            'customer_email' => $customerEmail,
            'customer' => $cart_customer,
            'items' => $cart_items,
            'coupon_applied' => array_values($cart_applied_coupon),
            'chosen_shipping_method' => $cart_shipping_method,
            'chosen_payment_method' => $cart_payment_method,
            'shipping_methods' => $shipping_methods,
            'totals' => $totals,
            'plugin_version' => GOKWIKCHECKOUT_VERSION,
        ];

        return [
            'cartResponse' => $cartResponse,
            'wc_session_data' => $wc_session_data,
            'cartObj' => $cartObj,
            'cart_customer' => $cart_customer,
            'cart_payment_method' => $cart_payment_method
        ];
    }

    /**
     * Return list of available coupons.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     * @since 1.0.0
     */
    public function getCoupons($request)
    {
        if (get_option('wc_settings_gokwik_section_show_coupons_list', 'no') == 'no') {
            return new WP_REST_Response(['coupons' => []], 200);
        }

        $session_key = $request->get_param('session_key');
        $customerEmail = null;
        $cartObj = null;
        $show_valid_only = false;

        if ($session_key) {
            $cartSessionResponse = $this->loadCartSession($session_key);
            if (is_wp_error($cartSessionResponse)) {
                return $cartSessionResponse;
            }

            [$wc_session_data, $cartObj] = $cartSessionResponse;
            $customerEmail = maybe_unserialize($wc_session_data)['customer_email'] ?? null;
            $show_valid_only = get_option('wc_settings_gokwik_show_valid_coupons_only') == 'yes';
        }

        $selected_coupons = maybe_unserialize(get_option('wc_settings_gokwik_selected_coupons', []));
        $coupons = [];

        if ($customerEmail && get_option('wc_settings_gokwik_show_user_specific_coupons') == 'yes') {
            $all_coupons = get_posts([
                'posts_per_page' => -1,
                'post_type' => 'shop_coupon',
                'post_status' => 'publish',
                'meta_query' => [
                    [
                        'key' => 'customer_email',
                        'value' => $customerEmail,
                        'compare' => 'LIKE',
                    ],
                ],
            ]);
            foreach ($all_coupons as $coupon_post) {
                $coupon_id = $coupon_post->ID;
                if (!in_array($coupon_id, $selected_coupons)) {
                    $selected_coupons[] = $coupon_id;
                }
            }
        }

        foreach ($selected_coupons as $coupon_id) {
            $coupon = new \WC_Coupon($coupon_id);
            if ($show_valid_only) {
                $discounts = new \WC_Discounts($cartObj);
                $is_valid = $discounts->is_coupon_valid($coupon);
                if (
                    is_wp_error($is_valid) ||
                    ($customerEmail && !$this->checkCouponUsage($coupon, $customerEmail))
                ) {
                    continue;
                }
            }

            $coupon_data = [
                'code' => $coupon->get_code(),
                'amount' => $coupon->get_amount(),
                'discount_type' => $coupon->get_discount_type(),
                'description' => $coupon->get_description(),
            ];

            if ($cartObj) {
                $discount_amount = $coupon->get_discount_type() === 'percent'
                    ? ($coupon->get_amount() / 100) * $cartObj->get_total('edit')
                    : $coupon->get_amount();

                if (is_plugin_active('wt-smart-coupons-for-woocommerce/wt-smart-coupon.php')) {
                    $max_discount = get_post_meta($coupon->get_id(), '_wt_max_discount', true);
                
                    if (!empty($max_discount) && floatval($max_discount) > 0) {
                        if ($discount_amount > floatval($max_discount)) {
                            $discount_amount = floatval($max_discount);
                        }
                    }
                }

                $coupon_data['amount'] = number_format((float) $discount_amount, 2, '.', '');
                $coupon_data['discount_value'] = $coupon->get_amount();
            }

            $coupons[] = $coupon_data;
        }

        return new WP_REST_Response(['coupons' => $coupons], 200);
    }

    /**
     * Apply coupon to cart.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     * @since 1.0.0
     */
    public function applyCoupon($request)
    {
        $coupon_code = $request->get_param('coupon');
        if (empty($coupon_code)) {
            return new WP_Error(
                'gc_cart_coupon_is_required',
                'Coupon code is required.',
                ['status' => 400]
            );
        }

        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        }

        [$wc_session_data, $cartObj] = $cartSessionResponse;

        $coupon_code = wc_format_coupon_code(wc_clean(wp_unslash($coupon_code)));
        $coupon = new \WC_Coupon($coupon_code);
        if (!$coupon->get_id()) {
            return new WP_Error(
                'gc_cart_coupon_does_not_exist',
                'Coupon does not exist.',
                ['status' => 200]
            );
        }

        $discounts = new \WC_Discounts($cartObj);
        $coupon_validity = $discounts->is_coupon_valid($coupon);
        if (is_wp_error($coupon_validity)) {
            return new WP_Error(
                'gc_cart_coupon_invalid',
                'Coupon is not valid.',
                ['status' => 200]
            );
        }

        $customerEmail = maybe_unserialize($wc_session_data)['customer_email'] ?? null;
        if ($customerEmail && !$this->checkCouponUsage($coupon, $customerEmail)) {
            return new WP_Error(
                'gc_cart_coupon_invalid_usage',
                'Coupon usage limit reached.',
                ['status' => 200]
            );
        }

        if (!$cartObj->has_discount($coupon_code)) {
            $cartObj->apply_coupon($coupon_code);
            $applied_coupons = $cartObj->applied_coupons ?? [];
            $wc_session_data['applied_coupons'] = maybe_serialize($applied_coupons);
            $this->updateSessionData($session_key, $wc_session_data);
        }

        $cartData = $this->getCartData($session_key, $request);
        if (is_wp_error($cartData)) {
            return $cartData;
        }

        return new WP_REST_Response(
            [
                'message' => 'Coupon was successfully added to cart.',
                'cart' => $cartData['cartResponse']
            ],
            200
        );
    }

    /**
     * Remove coupon from cart.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     * @since 1.0.0
     */
    public function removeCoupon($request)
    {
        $coupon = $request->get_param('coupon');
        if (empty($coupon)) {
            return new WP_Error(
                'gc_cart_coupon_is_required',
                'Coupon is required.',
                ['status' => 400]
            );
        }

        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        }

        [$wc_session_data, $cartObj] = $cartSessionResponse;

        $coupon = wc_format_coupon_code(wc_clean(wp_unslash($coupon)));
        if ($cartObj->has_discount($coupon)) {
            $remove_coupon = $cartObj->remove_coupon($coupon);
            if ($remove_coupon) {
                $this->removeCouponFromSession($session_key, $coupon);
            }
        }

        $cartData = $this->getCartData($session_key, $request);
        if (is_wp_error($cartData)) {
            return $cartData;
        }

        return new WP_REST_Response(
            [
                'message' => 'Coupon was successfully removed from cart.',
                'cart' => $cartData['cartResponse']
            ],
            200
        );
    }

    /**
     * Save billing/shipping address.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     * @since 1.0.0
     */
    public function setAddress($request)
    {
        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        }

        [$wc_session_data, $cartObj] = $cartSessionResponse;
        $new_customer_details = [];
        $fields = [
            'first_name' => ['first_name', 'shipping_first_name'],
            'last_name' => ['last_name', 'shipping_last_name'],
            'phone' => ['phone'],
            'email' => ['email'],
            'address_1' => ['address_1', 'address', 'shipping_address_1', 'shipping_address'],
            'address_2' => ['address_2', 'shipping_address_2'],
            'city' => ['city', 'shipping_city'],
            'state' => ['state', 'shipping_state'],
            'postcode' => ['postcode', 'shipping_postcode'],
            'country' => ['country', 'shipping_country'],
        ];

        $any_changes_requested = false;
        foreach ($fields as $param => $keys) {
            $value = $request->get_param($param);
            if (!empty($value)) {
                $clean_value = wc_clean(wp_unslash($value));
                if ($param === 'state' && strlen($clean_value) !== 2) {
                    $clean_value = GokwikUtilities::getStateCode($clean_value);
                }
                foreach ($keys as $key) {
                    $new_customer_details[$key] = $clean_value;
                }
                $any_changes_requested = true;
            }
        }

        if (is_numeric($session_key) && $session_key !== 0 && $any_changes_requested) {
            $user_id = $session_key;
            if (get_userdata($user_id)) {
                foreach ($new_customer_details as $key => $value) {
                    $meta_key = strpos($key, 'shipping_') === 0 ? $key : 'billing_' . $key;
                    update_user_meta($user_id, $meta_key, $value);
                }
            }
        }

        if (!empty($request->get_param('customerEmail'))) {
            $customerEmail = wc_clean(wp_unslash($request->get_param('customerEmail')));
            $wc_session_data['customer_email'] = $customerEmail;
            $any_changes_requested = true;
        }

        $customer_details = maybe_unserialize($wc_session_data['customer']) ?? [];
        $updated_customer_details = array_merge($customer_details, $new_customer_details);
        $wc_session_data['customer'] = maybe_serialize($updated_customer_details);
        $this->updateSessionData($session_key, $wc_session_data);

        $cartData = $this->getCartData($session_key, $request);
        if (is_wp_error($cartData)) {
            return $cartData;
        }

        $message = $any_changes_requested ? "Address successfully updated." : "No changes requested.";
        return new WP_REST_Response(
            [
                'message' => $message,
                'cart' => $cartData['cartResponse'],
                'has_past_orders' => $has_past_orders,
                'orders_count' => $orders_count,
            ],
            200
        );
    }

    /**
     * Set shipping method.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     * @since 1.0.0
     */
    public function setShippingMethod($request)
    {
        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        }

        [$wc_session_data, $cartObj] = $cartSessionResponse;

        $shipping_methods = $request->get_param('shipping_methods');
        if (!empty($shipping_methods)) {
            $shipping_method = wc_clean(wp_unslash($shipping_methods));
            $wc_session_data['chosen_shipping_methods'] = maybe_serialize($shipping_method);
            $this->updateSessionData($session_key, $wc_session_data);
            WC()->session->set('chosen_shipping_methods', $shipping_method);

            $cartData = $this->getCartData($session_key, $request);
            if (is_wp_error($cartData)) {
                return $cartData;
            }

            return new WP_REST_Response(
                [
                    'message' => "Shipping method successfully updated.",
                    'cart' => $cartData['cartResponse']
                ],
                200
            );
        }

        return new WP_REST_Response(['message' => "No changes requested."], 200);
    }

    /**
     * Return customer's terawallet balance.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     * @since 1.0.1
     */
    public function getWalletBalance($request)
    {
        $customer_email = wc_clean(wp_unslash($request->get_param('customer_email')));
        if (empty($customer_email)) {
            return new WP_Error(
                'gc_missing_customer_email',
                'Customer email is missing.',
                ['status' => 400]
            );
        }

        $user = get_user_by('email', $customer_email);
        if (!$user) {
            return new WP_Error(
                'gc_customer_not_found',
                'Customer not found.',
                ['status' => 200]
            );
        }

        if (!is_plugin_active('woo-wallet/woo-wallet.php')) {
            $balance = 0.00;
        } else {
            $balance = (float) woo_wallet()->wallet->get_wallet_balance($user->ID, 'edit');
            if ($balance < 0) {
                $balance = 0.00;
            }
        }

        return new WP_REST_Response(
            [
                'customer_id' => $user->ID,
                'wallet_balance' => number_format($balance, wc_get_price_decimals(), '.', ''),
            ],
            200
        );
    }

    /**
     * Deduct amount from customer's terawallet balance.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     * @since 1.0.1
     */
    public function deductWalletBalance($request)
    {
        if (!is_plugin_active('woo-wallet/woo-wallet.php')) {
            return new WP_Error(
                'gc_wallet_plugin_inactive',
                'Terawallet plugin is not active.',
                ['status' => 400]
            );
        }

        $customer_email = wc_clean(wp_unslash($request->get_param('email')));
        $amount = floatval(wc_clean($request->get_param('amount')));
        $note = wc_clean(wp_unslash($request->get_param('note', 'Balance used for your GoKwik order.')));

        if (empty($customer_email)) {
            return new WP_Error(
                'gc_missing_customer_email',
                'Customer email is missing.',
                ['status' => 400]
            );
        }

        $user = get_user_by('email', $customer_email);
        if (!$user) {
            return new WP_Error(
                'gc_customer_not_found',
                'Customer not found.',
                ['status' => 404]
            );
        }

        if ($amount <= 0) {
            return new WP_Error(
                'gc_wallet_invalid_amount',
                'Amount must be greater than zero.',
                ['status' => 400]
            );
        }

        $current_balance = woo_wallet()->wallet->get_wallet_balance($user->ID, 'edit');
        if ($current_balance < $amount) {
            return new WP_Error(
                'gc_wallet_insufficient_balance',
                'Insufficient wallet balance.',
                ['status' => 400]
            );
        }

        $transaction_id = woo_wallet()->wallet->debit($user->ID, $amount, $note);
        if (!$transaction_id) {
            return new WP_Error(
                'gc_wallet_transaction_failed',
                'Transaction failed. Please try again.',
                ['status' => 500]
            );
        }

        return new WP_REST_Response(['transaction_id' => $transaction_id], 200);
    }

    /**
     * Create or update a pending order based on the current cart session.
     *
     * @param array $wc_session_data
     * @param string $session_key
     *
     * @return void
     * @since 1.0.5
     */
    public function createOrUpdatePendingOrder($wc_session_data, $session_key)
    {
        $pendingOrder = GokwikUtilities::getPendingOrder($session_key);
        if ($pendingOrder) {
            GokwikUtilities::updateOrderFromCart($pendingOrder, $wc_session_data);
        } else {
            GokwikUtilities::createOrderFromCart($wc_session_data, $session_key);
        }
        GokwikUtilities::saveCartFlowsAbandonedCartData($wc_session_data, $session_key);
    }

    /**
     * Place order.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     * @since 1.0.2
     */
    public function placeOrder($request)
    {   
        global $wpdb;

        $test_order_retry = get_option('wc_settings_gokwik_section_test_order_retry', 'no');
        if ($test_order_retry === 'yes') {
            return new WP_Error(
                'gc_cart_test_order_retry',
                'This is a simulated failure for testing order retry flow.',
                ['status' => 500]
            );
        }
        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $context = array('source' => 'gokwik_cart_place_order');
            $logger->info('Request body: ' . wp_json_encode($request->get_params()), $context);
        }

        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        }

        [$wc_session_data, $cartObj] = $cartSessionResponse;

        $billing_address = wc_clean(wp_unslash($request->get_param('billing')));
        $shipping_address = wc_clean(wp_unslash($request->get_param('shipping')));
        $meta_data = wc_clean(wp_unslash($request->get_param('meta_data')));
        $meta_data = is_array($meta_data) ? $meta_data : [];

        $billing_state = $billing_address['state'] ?? null;
        if (strlen($billing_state) !== 2) {
            $billing_state = GokwikUtilities::getStateCode($billing_state);
        }
        $shipping_state = $shipping_address['state'] ?? null;
        if (strlen($shipping_state) !== 2) {
            $shipping_state = GokwikUtilities::getStateCode($shipping_state);
        }
        $status = wc_clean(wp_unslash($request->get_param('status')));
        $payment_method = wc_clean(wp_unslash($request->get_param('payment_method')));
        $applicablePaymentMethod = ['gokwik_prepaid', 'wallet', 'cod'];
        if (!in_array(strtolower($payment_method), $applicablePaymentMethod)) {
            return new WP_Error(
                'gc_cart_invalid_payment_method',
                'Payment method is invalid.',
                ['status' => 400]
            );
        }

        $transaction_id = wc_clean(wp_unslash($request->get_param('transaction_id'))) ?? null;
        $set_paid = wc_clean(wp_unslash($request->get_param('set_paid')));

        $is_paid = false;
        if (in_array(strtolower($payment_method), ['gokwik_prepaid', 'wallet'])) {
            $is_paid = (filter_var($set_paid, FILTER_VALIDATE_BOOLEAN) || in_array(strtolower((string) $set_paid), ['yes', '1'], true));
        }

        $customer_ip = wc_clean(wp_unslash($request->get_param('customer_ip'))) ?? null;
        $customer_user_agent = wc_clean(wp_unslash($request->get_param('customer_user_agent'))) ?? null;

        $order_data = array_merge(
            array_fill_keys(['billing_company', 'shipping_company'], null),
            [
                'billing_first_name' => $billing_address['first_name'] ?? null,
                'billing_last_name' => $billing_address['last_name'] ?? null,
                'billing_country' => $billing_address['country'] ?? null,
                'billing_address_1' => $billing_address['address_1'] ?? null,
                'billing_address_2' => $billing_address['address_2'] ?? null,
                'billing_city' => $billing_address['city'] ?? null,
                'billing_state' => $billing_state,
                'billing_postcode' => $billing_address['postcode'] ?? null,
                'billing_phone' => GokwikUtilities::formatPhoneNumber($billing_address['phone']) ?? null,
                'billing_email' => $billing_address['email'] ?? null,
                'shipping_first_name' => $shipping_address['first_name'] ?? null,
                'shipping_last_name' => $shipping_address['last_name'] ?? null,
                'shipping_country' => $shipping_address['country'] ?? null,
                'shipping_address_1' => $shipping_address['address_1'] ?? null,
                'shipping_address_2' => $shipping_address['address_2'] ?? null,
                'shipping_city' => $shipping_address['city'] ?? null,
                'shipping_state' => $shipping_state,
                'shipping_postcode' => $shipping_address['postcode'] ?? null,
            ]
        );

        $fee_lines = wc_clean(wp_unslash($request->get_param('fee_lines')));
        $fee_lines = is_array($fee_lines) ? $fee_lines : [];

        

        $isPendingOrderFlowEnabled = defined('GOKWIKCHECKOUT_PENDING_ORDER_FLOW') && GOKWIKCHECKOUT_PENDING_ORDER_FLOW;
        if ($isPendingOrderFlowEnabled) {
            $order = GokwikUtilities::getPendingOrder($session_key);
            if (!is_null($order)) {
                $order->delete(true);
            }
        }

        $order_id = WC()->checkout()->create_order($order_data);
        if (is_wp_error($order_id)) {
            return new WP_Error(
                'gc_cart_place_order_error',
                $order_id->get_error_message(),
                ['status' => 400]
            );
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error(
                'gc_cart_place_order_error',
                'Unable to create order.',
                ['status' => 400]
            );
        }

        $line_items = $request->get_param('line_items');
        if (is_array($line_items)) {
            $order_items = $order->get_items('line_item');
            $order_item_map = [];
            foreach ($order_items as $item_id => $item) {
                $pid = $item->get_product_id();
                $vid = $item->get_variation_id() ?: 0;
                $order_item_map[$pid . ':' . $vid] = $item;
            }
            foreach ($line_items as $item) {
                $product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;
                $quantity = isset($item['quantity']) ? intval($item['quantity']) : 1;
                $variation_id = isset($item['variation_id']) ? intval($item['variation_id']) : 0;
                $key = $product_id . ':' . $variation_id;
                if (isset($order_item_map[$key])) {
                    $order_item = $order_item_map[$key];
                    if ($order_item->get_quantity() != $quantity) {
                        $order_item->set_quantity($quantity);
                        $order_item->save();
                    }
                } else {
                    $product = $variation_id ? wc_get_product($variation_id) : wc_get_product($product_id);
                    if ($product) {
                        $order->add_product($product, $quantity);
                    }
                }
            }
        }

        foreach ($order->get_items('shipping') as $item_id => $item) {
            $order->remove_item($item_id);
        }

        $discount = $request->get_param('discount');
        $coupon_code = null;
        if (!empty($discount) && is_array($discount) && !empty($discount['code']) && isset($discount['amount'])) {
            $coupon_code = sanitize_text_field($discount['code']);
            $coupon_amount = floatval($discount['amount']);

            $is_duplicate_coupon = false;
            if (!empty($cartObj->applied_coupons)) {
                foreach ($cartObj->applied_coupons as $applied_coupon_code) {
                    if (strtolower($applied_coupon_code) === strtolower($coupon_code)) {
                        $is_duplicate_coupon = true;
                        break;
                    }
                }
            }

            if (!$is_duplicate_coupon) {
                $existing_coupon_codes = array_map('strtolower', $order->get_coupon_codes());
                if (!in_array(strtolower($coupon_code), $existing_coupon_codes, true)) {
                    $coupon_item = new \WC_Order_Item_Coupon();
                    $coupon_item->set_code($coupon_code);
                    $coupon_item->set_discount(abs($coupon_amount));
                    $coupon_item->set_discount_tax(0);
                    $coupon_item->save();
                    $order->add_item($coupon_item);
                    $gk_virtual_coupon_amount_applied = abs($coupon_amount);
                    $existing_coupon_id = function_exists('wc_get_coupon_id_by_code') ? wc_get_coupon_id_by_code($coupon_code) : 0;
                    if (empty($existing_coupon_id)) {
                        try {
                            $wc_coupon = new \WC_Coupon();
                            $wc_coupon->set_code($coupon_code);
                            $wc_coupon->set_amount(0);
                            $wc_coupon->set_discount_type('fixed_cart');
                            $wc_coupon->set_description(__('GoKwik virtual one-time coupon', 'gokwik'));
                            $wc_coupon->save();
                            $created_coupon_id = $wc_coupon->get_id();
                            if ($created_coupon_id) {
                                if (function_exists('update_post_meta')) {
                                    update_post_meta($created_coupon_id, '_gk_virtual_coupon', 'yes');
                                    update_post_meta($created_coupon_id, '_gk_virtual_dynamic_amount', 'yes');
                                }
                            }
                        } catch (\Throwable $e) {
                        }
                    }
                }

                if (GokwikUtilities::isHposEnabled()) {
                    $order->update_meta_data('_gk_virtual_coupon_code', $coupon_code);
                    $order->update_meta_data('_gk_virtual_coupon_amount', $coupon_amount);
                } else {
                    update_post_meta($order->get_id(), '_gk_virtual_coupon_code', $coupon_code);
                    update_post_meta($order->get_id(), '_gk_virtual_coupon_amount', $coupon_amount);
                }
            }
        }

        if ($session_key) {
            if (GokwikUtilities::isHposEnabled()) {
                $order->update_meta_data('_gk_session_key', $session_key);
            } else {
                update_post_meta($order->get_id(), '_gk_session_key', $session_key);
            }
        }

        $payment_gateways = WC()->payment_gateways()->payment_gateways();
        if (isset($payment_gateways[$payment_method])) {
            $order->set_payment_method($payment_gateways[$payment_method]);
            $order->save();
        }

        $customer_email = maybe_unserialize($wc_session_data)['customer_email'] ?? $billing_address['email'];
        $user = get_user_by('email', $customer_email);
        $register_after_checkout = get_option('wc_settings_gokwik_section_register_after_checkout', 'no');

        if (!$user && $register_after_checkout == 'yes') {
            $user_data = [
                'first_name' => ucfirst($billing_address['first_name'] ?? ''),
                'last_name' => ucfirst($billing_address['last_name'] ?? ''),
            ];

            $first_name = $billing_address['first_name'] ?? '';
            $last_name = $billing_address['last_name'] ?? '';
            $username_base = strtolower(trim(sanitize_user($first_name . (!empty($last_name) ? '.' . $last_name : ''))));
            $username = $username_base;

            $attempt = 1;
            while (username_exists($username)) {
                $username = $username_base . '_' . $attempt++;
            }

            $customer_id = wc_create_new_customer($customer_email, $username, '', $user_data);
            if (!is_wp_error($customer_id)) {
                $user_meta_data = [
                    'billing_first_name' => $order_data['billing_first_name'] ?? '',
                    'billing_last_name' => $order_data['billing_last_name'] ?? '',
                    'billing_company' => $order_data['billing_company'] ?? '',
                    'billing_country' => $order_data['billing_country'] ?? '',
                    'billing_address_1' => $order_data['billing_address_1'] ?? '',
                    'billing_address_2' => $order_data['billing_address_2'] ?? '',
                    'billing_city' => $order_data['billing_city'] ?? '',
                    'billing_state' => $order_data['billing_state'] ?? '',
                    'billing_postcode' => $order_data['billing_postcode'] ?? '',
                    'billing_phone' => $order_data['billing_phone'] ?? '',
                    'billing_email' => $order_data['billing_email'] ?? '',
                    'shipping_first_name' => $order_data['shipping_first_name'] ?? '',
                    'shipping_last_name' => $order_data['shipping_last_name'] ?? '',
                    'shipping_company' => $order_data['shipping_company'] ?? '',
                    'shipping_country' => $order_data['shipping_country'] ?? '',
                    'shipping_address_1' => $order_data['shipping_address_1'] ?? '',
                    'shipping_address_2' => $order_data['shipping_address_2'] ?? '',
                    'shipping_city' => $order_data['shipping_city'] ?? '',
                    'shipping_state' => $order_data['shipping_state'] ?? '',
                    'shipping_postcode' => $order_data['shipping_postcode'] ?? '',
                ];
                if (in_array('digits/digit.php', apply_filters('active_plugins', get_option('active_plugins')))) {
                    $user_meta_data['digt_countrycode'] = '+91';
                    $user_meta_data['digits_phone_no'] = ltrim($order_data['billing_phone'], '+91');
                    $user_meta_data['digits_phone'] = $order_data['billing_phone'];
                }
                foreach ($user_meta_data as $meta_key => $meta_value) {
                    update_user_meta($customer_id, $meta_key, $meta_value);
                }
            }
        }

        $order->set_customer_id($customer_id ?? get_current_user_id());
        $order->set_created_via('checkout');

        if ($customer_ip) {
            $order->set_customer_ip_address($customer_ip);
        }
        if ($customer_user_agent) {
            $order->set_customer_user_agent($customer_user_agent);
        }
        if ($customer_user_agent) {
            if (GokwikUtilities::isHposEnabled()) {
                $order->update_meta_data('_wc_order_attribution_user_agent', $customer_user_agent);
            } else {
                update_post_meta($order->get_id(), '_wc_order_attribution_user_agent', $customer_user_agent);
            }
        }

        $billingGstNo = null;
        $pys_data = array();
        $source_type = "typein";
        $cookie_data = isset($wc_session_data['cookies']) ? maybe_unserialize($wc_session_data['cookies']) : array();
        $referrer = isset($cookie_data['origin_referrer']) ? $cookie_data['origin_referrer'] : "";
        $ad_source = $cookie_data['ad_source'] ?? "";
        if ($ad_source != "") {
            if (strpos($ad_source, '?') === 0) {
                $ad_source = substr($ad_source, 1);
            }
            $cookie_utm_params = explode("&", $ad_source);
            foreach ($cookie_utm_params as $utm_data) {
                $utm_params = explode("=", $utm_data);
                $utm_key = $utm_params[0] ?? "";
                $utm_value = $utm_params[1] ?? "";
                if ($utm_key != "" && $utm_value != "") {
                    $pys_data[$utm_key] = $utm_value;
                    switch ($utm_key) {
                        case 'utm_source':
                            $meta_data[] = array(
                                'key' => "_wc_order_attribution_utm_source",
                                'value' => $utm_value
                            );
                            break;
                        case 'utm_medium':
                            $meta_data[] = array(
                                'key' => "_wc_order_attribution_utm_medium",
                                'value' => $utm_value
                            );
                            break;
                        case 'utm_campaign':
                            $meta_data[] = array(
                                'key' => "_wc_order_attribution_utm_campaign",
                                'value' => $utm_value
                            );
                            break;
                        case 'utm_term':
                            $meta_data[] = array(
                                'key' => "_wc_order_attribution_utm_term",
                                'value' => $utm_value
                            );
                            break;
                        case 'utm_content':
                            $meta_data[] = array(
                                'key' => "_wc_order_attribution_utm_content",
                                'value' => $utm_value
                            );
                            break;
                    }
                }
            }
        } else {
            if (isset($cookie_data['pys_utm_source'])) {
                $meta_data[] = array(
                    'key' => "_wc_order_attribution_utm_source",
                    'value' => $cookie_data['pys_utm_source']
                );
            }
            if (isset($cookie_data['pys_utm_medium'])) {
                $meta_data[] = array(
                    'key' => "_wc_order_attribution_utm_medium",
                    'value' => $cookie_data['pys_utm_medium']
                );
            }
            if (isset($cookie_data['pys_utm_campaign'])) {
                $meta_data[] = array(
                    'key' => "_wc_order_attribution_utm_campaign",
                    'value' => $cookie_data['pys_utm_campaign']
                );
            }
            if (isset($cookie_data['pys_utm_term'])) {
                $meta_data[] = array(
                    'key' => "_wc_order_attribution_utm_term",
                    'value' => $cookie_data['pys_utm_term']
                );
            }
            if (isset($cookie_data['pys_utm_content'])) {
                $meta_data[] = array(
                    'key' => "_wc_order_attribution_utm_content",
                    'value' => $cookie_data['pys_utm_content']
                );
            }
        }
        if ($referrer != "") {
            $keysToRemove = [
                "_wc_order_attribution_utm_source",
                "_wc_order_attribution_utm_medium",
                "_wc_order_attribution_utm_campaign",
                "_wc_order_attribution_utm_term",
                "_wc_order_attribution_utm_content"
            ];
            $meta_data = array_values(array_filter($meta_data, function ($item) use ($keysToRemove) {
                return !in_array($item["key"], $keysToRemove);
            }));
        }

        foreach ($meta_data as $meta_item) {
            $key = $meta_item['key'] ?? null;
            $value = $meta_item['value'] ?? null;

            if (!empty($value)) {
                if ($key == 'billing_gst_no') {
                    $billingGstNo = strtoupper($value);
                }
                if (GokwikUtilities::isHposEnabled()) {
                    $order->update_meta_data($key, $value);
                } else {
                    update_post_meta($order->get_id(), $key, $value);
                }
                if ($key == 'advance_paid') {
				$advanceAmount = abs((float) $value);

					if (GokwikUtilities::isHposEnabled()) {
						$order->update_meta_data('_gk_advance_paid_amount', $advanceAmount);
					} else {
						update_post_meta($order->get_id(), '_gk_advance_paid_amount', $advanceAmount);
					}
                    $fee_lines[] = [
                        'name' => __('Advance Payment', 'gokwik'),
                        'total' => -abs(floatval($value)),
                    ];
                }
            }

            switch ($key) {
                case '_wc_order_attribution_utm_source':
                    if (!isset($pys_data["utm_source"])) {
                        $pys_data["utm_source"] = $value;
                    }
                    break;
                case '_wc_order_attribution_utm_medium':
                    if (!isset($pys_data["utm_medium"])) {
                        $pys_data["utm_medium"] = $value;
                    }
                    break;
                case '_wc_order_attribution_utm_campaign':
                    if (!isset($pys_data["utm_campaign"])) {
                        $pys_data["utm_campaign"] = $value;
                    }
                    break;
                case '_wc_order_attribution_utm_term':
                    if (!isset($pys_data["utm_term"])) {
                        $pys_data["utm_term"] = $value;
                    }
                    break;
                case '_wc_order_attribution_utm_content':
                    if (!isset($pys_data["utm_content"])) {
                        $pys_data["utm_content"] = $value;
                    }
                    break;
            }
        }
        if (count($pys_data) > 0) {
            $source_type = "utm";
        }
        $is_search_engine = false;
        if ($source_type != "utm" && $referrer != "") {
            $search_engines = ['google', 'bing', 'yahoo', 'duckduckgo', 'baidu', 'yandex'];
            foreach ($search_engines as $engine) {
                if (stripos($referrer, $engine) !== false) {
                    $is_search_engine = true;
                    break;
                }
            }
            if ($is_search_engine) {
                $source_type = "organic";
            } else {
                $source_type = "referral";
            }
            $order->update_meta_data('_wc_order_attribution_utm_source', $referrer);
        }
        $order->update_meta_data('_wc_order_attribution_source_type', $source_type);
        
        if (class_exists('PixelYourSite\PYS')) {
            $pys_enrich_data = $order->get_meta('pys_enrich_data') ?: [];
            $pys_utm = isset($pys_enrich_data['pys_utm']) ? explode("|", $pys_enrich_data['pys_utm']) : [];
            $pys_utm_updated = array();
            $last_pys_utm_updated = array();
            $utm_source = $pys_data["utm_source"] ?? ($cookie_data['pys_utm_source'] ?? "undefined");
            $utm_medium = $pys_data["utm_medium"] ?? ($cookie_data['pys_utm_medium'] ?? "undefined");
            $utm_campaign = $pys_data["utm_campaign"] ?? ($cookie_data['pys_utm_campaign'] ?? "undefined");
            $utm_term = $pys_data["utm_term"] ?? ($cookie_data['pys_utm_term'] ?? "undefined");
            $utm_content = $pys_data["utm_content"] ?? ($cookie_data['pys_utm_content'] ?? "undefined");
            $pys_landing_page = $cookie_data["pys_landing_page"] ?? "";
            $pysTrafficSource = $cookie_data["pysTrafficSource"] ?? "";
            $last_pys_landing_page = $cookie_data["last_pys_landing_page"] ?? "";
            $last_pysTrafficSource = $cookie_data["last_pysTrafficSource"] ?? "";
            $utm_mapping = [
                "utm_source" => $utm_source,
                "utm_medium" => $utm_medium,
                "utm_campaign" => $utm_campaign,
                "utm_term" => $utm_term,
                "utm_content" => $utm_content,
            ];
            foreach ($pys_utm as $pys_utm_data) {
                $psy_utm_data_ex = explode(":", $pys_utm_data);
                if (count($psy_utm_data_ex) >= 1) {
                    $utm_key = $psy_utm_data_ex[0];
                    if (isset($utm_mapping[$utm_key])) {
                        $updated_value = $utm_key . ":" . $utm_mapping[$utm_key];
                        $pys_utm_updated[] = $updated_value;
                        $last_pys_utm_updated[] = $updated_value;
                    }
                }
            }

            $pys_enrich_data['pys_utm'] = implode("|", $pys_utm_updated);
            $pys_enrich_data['last_pys_utm'] = implode("|", $last_pys_utm_updated);
            $pys_enrich_data['pys_landing'] = $pys_landing_page;
            $pys_enrich_data['pys_source'] = $pysTrafficSource;
            $pys_enrich_data['last_pys_landing'] = $last_pys_landing_page;
            $pys_enrich_data['last_pys_source'] = $last_pysTrafficSource;

            $order->update_meta_data('pys_enrich_data', $pys_enrich_data);
        }

        $domainParts = explode('.', $_SERVER['HTTP_HOST']);
        if (
            stripos($domainParts[0], 'skullcandy') !== false ||
            (count($domainParts) > 2 && stripos($domainParts[1], 'skullcandy') !== false)
        ) {
            if ($billingGstNo) {
                $billingMetaData['billing'][] = [
                    'type' => 'text',
                    'meta_id' => false,
                    'name' => 'billing_gstin',
                    'label' => 'GSTIN (Use Capital Letter)',
                    'value' => $billingGstNo,
                    'priority' => 130,
                    'col' => 6,
                    'show_in_email' => true,
                    'show_in_order_page' => true,
                ];
                if (GokwikUtilities::isHposEnabled()) {
                    $order->update_meta_data('_awcfe_order_meta_key', $billingMetaData);
                } else {
                    update_post_meta($order->get_id(), '_awcfe_order_meta_key', $billingMetaData);
                }
            }
        }
		$prepaid_discount_amount = 0;

        foreach ($fee_lines as $fee) {
            $feeName = $fee['name'] ?? null;
            $feeTotal = (float) ($fee['total'] ?? 0);
            if (empty($feeName) || $feeTotal === 0) {
                continue;
            }
            $improvedFeeName = $feeName;
			if ($feeTotal < 0 && stripos($feeName, 'prepaid') !== false) {
				$prepaid_discount_amount += abs($feeTotal);
			}
            if ($feeTotal < 0) {
                if (stripos($feeName, 'discount') === false) {
                    $improvedFeeName = sprintf(__('Discount (%s)', 'gokwik'), $feeName);
                } else {
                    $improvedFeeName = $feeName;
                }
            } else {
                if (stripos($feeName, 'fee') === false) {
                    $improvedFeeName = sprintf(__('Fee (%s)', 'gokwik'), $feeName);
                } else {
                    $improvedFeeName = $feeName;
                }
            }

            $orderItemFee = new \WC_Order_Item_Fee();
            $orderItemFee->set_name($improvedFeeName);
            $orderItemFee->set_total($feeTotal);
            $orderItemFee->set_amount($feeTotal);
            $orderItemFee->set_total_tax(0);
            $orderItemFee->set_tax_class('');
            $orderItemFee->set_tax_status('none');
            $orderItemFee->set_taxes([]);
            $orderItemFee->save();
            $order->add_item($orderItemFee);
        }

        $shipping_lines = wc_clean(wp_unslash($request->get_param('shipping_lines')));
        if (!empty($shipping_lines) && is_array($shipping_lines)) {
            foreach ($shipping_lines as $shipping) {
                $method_id = $shipping['method_id'] ?? '';
                $method_title = $shipping['method_title'] ?? '';
                $total = isset($shipping['total']) ? (float) $shipping['total'] : 0;
                if (empty($method_id) || $total === 0) {
                    continue;
                }
                $shipping_item = new \WC_Order_Item_Shipping();
                $shipping_item->set_method_id($method_id);
                $shipping_item->set_method_title($method_title);
                $shipping_item->set_total($total);
                $shipping_item->set_taxes([]);
                $shipping_item->set_tax_status('none');
                $shipping_item->save();
                $order->add_item($shipping_item);
            }
        }

        $order->calculate_totals(false);
        if (isset($gk_virtual_coupon_amount_applied) && $gk_virtual_coupon_amount_applied > 0) {
            $current_discount_total = (float) $order->get_discount_total();
            $order->set_discount_total($current_discount_total + $gk_virtual_coupon_amount_applied);
            $current_total = (float) $order->get_total();
            $order->set_total(max(0, $current_total - $gk_virtual_coupon_amount_applied));
        }

        if ($prepaid_discount_amount > 0) {

            // Internal meta (used for calculations & logic)
            $order->update_meta_data(
                '_gk_prepaid_discount_amount',
                (float) $prepaid_discount_amount
            );

            // Public meta (visible in Admin → Order → Custom Fields)
            $order->update_meta_data(
                'prepaid_discount',
                (float) $prepaid_discount_amount
            );

        }
        $order->save();
        if (function_exists('wc_update_coupon_usage_counts')) {
            wc_update_coupon_usage_counts($order->get_id());
        }
        if (function_exists('wc_update_order_stats')) {
            wc_update_order_stats($order->get_id());
        }

        if ($transaction_id && !$is_paid) {
            $order->set_transaction_id($transaction_id);
            $meta_key = 'advance_payment_transaction_id';
            $meta_value = $transaction_id;
            if (GokwikUtilities::isHposEnabled()) {
                $order->update_meta_data($meta_key, $meta_value);
            } else {
                update_post_meta($order->get_id(), $meta_key, $meta_value);
            }
        }
        if ($is_paid) {
            $order->payment_complete($transaction_id ?? null);
            $order->save();
        }
        if ($status === 'processing') {
            $order->update_status($status);
            $order->save();
            if (function_exists('wc_update_order_stats')) {
                wc_update_order_stats($order->get_id());
            }
        }

        WC()->cart->empty_cart(true);
        $wpdb->delete("{$wpdb->prefix}woocommerce_sessions", ['session_key' => $session_key]);

        if (is_user_logged_in()) {
            delete_user_meta(
                get_current_user_id(),
                '_woocommerce_persistent_cart_' . get_current_blog_id()
            );
        }

        WC()->session->destroy_session();

        $orderId = $order->get_id();
        if (is_plugin_active('custom-order-numbers-for-woocommerce/custom-order-numbers-for-woocommerce.php')) {
            $orderId = $order->get_meta('_alg_wc_full_custom_order_number');
        }

        return new WP_REST_Response(['id' => $orderId], 200);
    }

    /**
     * Check if an order exists.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     * @since 1.0.9
     */
    public function checkOrderExists($request)
    {
        $session_key = wc_clean(wp_unslash($request->get_param('session_key')));
        $customer_email = wc_clean(wp_unslash($request->get_param('customer_email')));

        if (empty($session_key) || empty($customer_email)) {
            return new WP_Error(
                'gc_missing_required_parameters',
                'Missing required parameters.',
                ['status' => 400]
            );
        }

        $args = [
            'type' => 'shop_order',
            'status' => 'processing',
            'date_created' => '>' . (time() - HOUR_IN_SECONDS),
            'meta_query' => [
                [
                    'key' => '_gk_session_key',
                    'value' => $session_key,
                    'compare' => '=',
                ],
            ],
            'payment_method' => 'gokwik_prepaid',
            'billing_email' => $customer_email,
            'limit' => 1,
            'exclude' => ['trash'],
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $orders = wc_get_orders($args);

        if (!empty($orders)) {
            return new WP_REST_Response(
                [
                    'message' => 'Order exists.',
                    'order_id' => $orders[0]->get_id(),
                ],
                200
            );
        }

        return new WP_REST_Response(['message' => 'No order found.'], 404);
    }

    /**
     * Update the status of an order.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     * @since 1.0.9
     */
    public function updateOrderStatus($request)
    {
        $merchant_order_id = wc_clean(wp_unslash($request->get_param('merchant_order_id')));
        $order_status = strtolower(wc_clean(wp_unslash($request->get_param('order_status'))));

        if (empty($merchant_order_id) || empty($order_status)) {
            return new WP_Error(
                'gc_missing_required_parameters',
                'Missing required parameters.',
                ['status' => 400]
            );
        }

        $order = wc_get_order($merchant_order_id);

        if (!$order) {
            return new WP_REST_Response(['message' => 'Order not found.'], 404);
        }

        if ($order->get_meta('is_gokwik_order', true) != 'true') {
            return new WP_Error(
                'gc_invalid_order',
                'The order is not a GoKwik order.',
                ['status' => 400]
            );
        }

        $valid_statuses = wc_get_order_statuses();
        $formatted_status = 'wc-' . $order_status;
        if (!array_key_exists($formatted_status, $valid_statuses)) {
            return new WP_Error(
                'gc_invalid_order_status',
                'Invalid order status provided.',
                ['status' => 400]
            );
        }

        $old_status = $order->get_status();
        if ($old_status !== $order_status) {
            $order->update_status($order_status);
        }

        return new WP_REST_Response(
            [
                'message' => 'Order status updated successfully.',
                'order_id' => $order->get_id(),
                'old_status' => $old_status,
                'new_status' => $order->get_status(),
            ],
            200
        );
    }

    /**
     * Refund Process.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     * @since 1.1.2
     */
    public function refundProcess($request)
    {
        global $wpdb;

        $params = [
            'amount',
            'merchantId',
            'event',
            'refundId',
            'merchantReferenceId',
            'transactionPaymentId',
            'hmac'
        ];
        $request_data = $request->get_json_params();

        $cleaned_params = array_map(function ($param) use ($request_data) {
            return isset($request_data[$param])
                ? wc_clean(wp_unslash($request_data[$param]))
                : (isset($request_data['data'][$param])
                    ? wc_clean(wp_unslash($request_data['data'][$param]))
                    : null);
        }, $params);

        list(
            $refund_amount,
            $merchantId,
            $event,
            $refundId,
            $merchantReferenceId,
            $transactionPaymentId,
            $hmac
        ) = $cleaned_params;

        if (empty($refund_amount) || empty($merchantId)) {
            return new WP_Error(
                'gc_missing_required_parameters',
                'Required parameters are missing.',
                ['status' => 400]
            );
        }

        if (get_option('wc_settings_gokwik_section_mid') != $merchantId) {
            return new WP_Error(
                'gc_merchant_id_not_found',
                'Merchant ID not found.',
                ['status' => 404]
            );
        }

        if (!ctype_xdigit($hmac) || strlen($hmac) !== 128) {
            return new WP_Error(
                'gc_invalid_hmac',
                'Invalid hmac.',
                ['status' => 400]
            );
        }

        $args = [
            'limit' => 1,
            'meta_query' => [
                [
                    'key' => 'gokwik_order_id',
                    'value' => sanitize_text_field($merchantReferenceId),
                    'compare' => '='
                ]
            ],
        ];

        $order = wc_get_orders($args);
        $order = !empty($order) ? reset($order) : null;
        if (!$order) {
            return new WP_Error(
                'gc_order_not_found',
                'Order not found.',
                ['status' => 404]
            );
        }

        if ($order->get_payment_method() != "gokwik_prepaid") {
            return new WP_Error(
                'gc_invalid_payment_method',
                'Refunds are only for GoKwik prepaid orders.',
                ['status' => 400]
            );
        }

        if ($order->get_transaction_id() && $order->get_transaction_id() != $transactionPaymentId) {
            return new WP_Error(
                'gc_invalid_transaction',
                'Invalid Transaction.',
                ['status' => 400]
            );
        }
		
        $total_refund = 0;
        $is_woo_refund = false;
        foreach ($order->get_refunds() as $refund) {
            $custom_refund_id = $refund->get_meta('_gokwik_refund_id');
            if ($custom_refund_id == $refundId) {
                return new WP_REST_Response(['message' => 'Refund processed in WooCommerce.'], 200);
            }

            if ($event == "refund.successful" && empty($custom_refund_id)) {
                $refund->update_meta_data('_gokwik_refund_id', $refundId);
                $refund->set_reason(
                    $order->get_total() == ($total_refund + $refund->get_amount())
                    ? "Full refund via GoKwik"
                    : "Partial refund via GoKwik"
                );
                $refund->save();
                $is_woo_refund = true;
            }

            $total_refund += $refund->get_amount();
        }

        if ($refund_amount > $order->get_total()) {
            return new WP_Error(
                'gc_refund_exceeds_total',
                'Refund amount exceeds order total.',
                ['status' => 400]
            );
        }

        if ($event == "refund.successful") {
            if (!$is_woo_refund) {
                if ($order->get_total() == ($total_refund + $refund_amount)) {
                    $refunded_items = [];
                    $refund_amount = 0.00;
                    $refund_reason = "Full refund via GoKwik";
                    foreach ($order->get_items() as $item_id => $item) {
                        $qty = $item->get_quantity();
                        $total = $item->get_total();
                    
                        $refunded_items[$item_id] = [
                            'qty' => $qty,
                            'total' => $total,
                        ];
                        $refund_amount += $total;
                    }

                    $refund = wc_create_refund([
                        'amount' => $refund_amount,
                        'reason' => $refund_reason,
                        'order_id' => $order->get_id(),
                        'line_items' => $refunded_items,
                        'refund_payment' => false,
                        'restock_items' => true,
                    ]);
                    
                    if (is_wp_error($refund)) {
                        return $refund;
                    }
                    
                    $order->update_status("wc-refunded");
                } else {
                    $refund_reason = "Partial refund via GoKwik";
                    $refund = wc_create_refund([
                        'amount' => $refund_amount,
                        'reason' => $refund_reason,
                        'order_id' => $order->get_id(),
                    ]);

                    if (is_wp_error($refund)) {
                        return $refund;
                    }

                    $refund->update_meta_data('_gokwik_refund_id', $refundId);
                    $refund->set_reason($refund_reason);
                    $refund->save();
                }
            }

            $order->add_order_note(sprintf(
                "Refund of %s processed successfully via GoKwik. Refund ID: %s.",
                wc_price($refund_amount),
                $refundId
            ));
        } elseif ($event == "refund.initiated") {
            $order->add_order_note(sprintf(
                "Refund of %s initiated via GoKwik. Refund ID: %s.",
                wc_price($refund_amount),
                $refundId
            ));
        }

        return new WP_REST_Response(['message' => 'Refund processed in WooCommerce.'], 200);
    }

    /**
     * Perform a health check.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     * @since 1.1.2
     */
    public function healthCheck($request)
    {
        global $wp_version;

        $response_data = [
            'status' => 'success',
            'message' => 'API is operational.',
            'timestamp' => current_time('mysql'),
            'wordpress_version' => $wp_version,
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'N/A',
            'plugin_version' => GOKWIKCHECKOUT_VERSION,
            'php_version' => PHP_VERSION,
        ];

        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Remove a coupon from the WooCommerce session.
     *
     * @param string $session_key The session key.
     * @param string $coupon The coupon code to be removed.
     *
     * @return void|WP_Error Returns WP_Error if there is an error loading the cart session.
     * @since 1.0.2
     */
    protected function removeCouponFromSession($session_key, $coupon)
    {
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        }

        [$wc_session_data, $cartObj] = $cartSessionResponse;

        $applied_coupons = maybe_unserialize(maybe_unserialize($wc_session_data)['applied_coupons']) ?? [];
        if (($key = array_search($coupon, $applied_coupons)) !== false) {
            unset($applied_coupons[$key]);
            $applied_coupons = array_values($applied_coupons);
        }
        $wc_session_data['applied_coupons'] = maybe_serialize($applied_coupons);
        $this->updateSessionData($session_key, $wc_session_data);

        if ($cartObj->has_discount($coupon)) {
            $cartObj->remove_coupon($coupon);
            $cartObj->calculate_totals();
        }
    }

    /**
     * Check if a coupon has remaining usage for a specific email.
     *
     * @access protected
     * @param WC_Coupon $coupon The coupon object.
     * @param string $billing_email The billing email to check against.
     *
     * @return bool True if the coupon can be used, false otherwise.
     * @since 1.0.2
     */
    protected function checkCouponUsage($coupon, $billing_email)
    {
        $current_user = wp_get_current_user();
        $check_emails = array_unique(
            array_filter(
                array_map(
                    'strtolower',
                    array_map(
                        'sanitize_email',
                        [$billing_email, $current_user->user_email]
                    )
                )
            )
        );
        $restrictions = $coupon->get_email_restrictions();
        if (
            is_array($restrictions) &&
            count($restrictions) > 0 &&
            !WC()->cart->is_coupon_emails_allowed($check_emails, $restrictions)
        ) {
            return false;
        }
        $coupon_usage_limit = $coupon->get_usage_limit_per_user();
        if ($coupon_usage_limit > 0) {
            $coupon_data_store = $coupon->get_data_store();
            foreach ($check_emails as $email) {
                if (
                    $coupon_data_store &&
                    $coupon_data_store->get_usage_by_email($coupon, $email) >= $coupon_usage_limit
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Prepare a cart item for the response.
     *
     * @access protected
     * @param array $cart_item The cart item data.
     * @param WP_REST_Request $request The REST API request object.
     *
     * @return array The prepared cart item data.
     * @since 1.0.0
     */
    protected function prepareCartItem($cart_item, $request)
    {
        $product = ($cart_item["variation_id"] == 0)
            ? wc_get_product($cart_item["product_id"])
            : wc_get_product($cart_item["variation_id"]);

        $cart_item['product_data'] = [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'sku' => $product->get_sku(),
            'price' => isset($cart_item['_bogof_free_item'])
                ? $cart_item['line_subtotal']
                : $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'category_ids' => $product->get_category_ids() ? $product->get_category_ids() : [],
        ];

        if ($product->managing_stock()) {
            $cart_item['product_data']['salable_qty'] = $product->get_stock_quantity();
            $cart_item['product_data']['stock_status'] = in_array($product->get_stock_status(), ['instock', 'onbackorder']) ? "IN_STOCK" : "OUT_OF_STOCK";
        }

        if ($product instanceof \WC_Product_Variation) {
            $parent_product_id = $product->get_parent_id();
            $parent_product = wc_get_product($parent_product_id);

            $cart_item['product_data']['id'] = $parent_product_id;
            $cart_item['product_data']['variation_id'] = $cart_item['variation_id'];
            $cart_item['product_data']['category_ids'] = $parent_product ? $parent_product->get_category_ids() : [];
        }

        $cart_item['product_data']['images'] = $this->getProductImages($product);
        $cart_item['currency'] = html_entity_decode(get_woocommerce_currency_symbol());

        unset(
            $cart_item['data_hash'],
            $cart_item['variation'],
            $cart_item['line_tax_data']
        );

        return $cart_item;
    }

    /**
     * Get images for a product.
     *
     * @access protected
     * @param WC_Product|WC_Product_Variation $product The product object.
     *
     * @return array An array of product images.
     * @since 1.0.0
     */
    protected function getProductImages($product)
    {
        $images = [];
        $attachment_ids = [];

        if ($product->get_image_id()) {
            $attachment_ids[] = $product->get_image_id();
        }

        $attachment_ids = array_merge($attachment_ids, $product->get_gallery_image_ids());
        foreach ($attachment_ids as $attachment_id) {
            $attachment_post = get_post($attachment_id);
            if (is_null($attachment_post)) {
                continue;
            }

            $attachment = wp_get_attachment_image_src($attachment_id, 'full');
            if (!is_array($attachment)) {
                continue;
            }

            $images[] = [
                'id' => (int) $attachment_id,
                'src' => current($attachment),
                'name' => get_the_title($attachment_id),
                'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            ];
        }

        if (empty($images)) {
            $images[] = [
                'id' => 0,
                'src' => wc_placeholder_img_src(),
                'name' => 'Placeholder Image',
                'alt' => 'Placeholder Image',
            ];
        }

        return $images;
    }

    /**
     * Update session data in the database.
     *
     * @param string $session_key The session key.
     * @param array $wc_session_data The session data to update.
     *
     * @return void
     * @since 1.1.3
     */
    protected function updateSessionData($session_key, $wc_session_data)
    {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
        $serialized_session = maybe_serialize($wc_session_data);
        $wpdb->update(
            $sessions_table,
            ['session_value' => $serialized_session],
            ['session_key' => $session_key]
        );
    }

    /**
     * Remove out of stock items
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     * @since 1.1.3
     */
    public function removeOutOfStockItems($request)
    {
        global $wpdb;
        $manage_woocommerce_stock = get_option('woocommerce_manage_stock');
        if ($manage_woocommerce_stock == 'no') {
            return new WP_REST_Response(
                ['message' => "Stock management is disabled."],
                404
            );
        }
        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        $cart_updated_flag = 0;
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        } else {
            [$wc_session_data, $cartObj] = $cartSessionResponse;
        }
        $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
        $session_data = $wpdb->get_var($wpdb->prepare("SELECT session_value FROM $sessions_table WHERE session_key = %s", $session_key));
        if ($session_data) {
            $session_data_unserialized = maybe_unserialize($session_data);
            $cart = maybe_unserialize($session_data_unserialized['cart']);
            foreach ($cart as $cart_item_key => $cart_item) {
                $product_id = $cart_item['product_id'];
                $product_qty = $cart_item['quantity'];
                $stock = get_post_meta($product_id, '_stock', true);
                $managing_stock = get_post_meta($product_id, '_manage_stock', true) === 'yes';
                if ($managing_stock) {
                    if ($stock == 0) {
                        unset($cart[$cart_item_key]);
                        $cart_updated_flag = 1;
                    } elseif ($product_qty > $stock) {
                        $cart[$cart_item_key]['quantity'] = $stock;
                        $cart_updated_flag = 1;
                    }
                }
            }
            if ($cart_updated_flag == 1) {
                $session_data_unserialized['cart'] = maybe_serialize($cart);
                $this->updateSessionData($session_key, $session_data_unserialized);
            }
        }
        if (is_user_logged_in() && $cart_updated_flag == 1) {
            $session_user_data = maybe_unserialize($wc_session_data['customer']);
            $session_user_id = $session_user_data['id'];
            WC()->session->set_customer_session_cookie(true);
            WC()->session->set('cart', $cart);
            WC()->cart->persistent_cart_update($session_user_id);
        }
        
        $cartData = $this->getCartData($session_key, $request);
        if (is_wp_error($cartData)) {
            return $cartData;
        }

        if ($cart_updated_flag == 1) {
            $response_data = [
                'status' => 'success',
                'message' => 'Out-of-stock items have been removed from the cart',
                'cart' => $cartData['cartResponse']
            ];
        } else {
            $response_data = [
                'status' => 'success',
                'message' => 'No out-of-stock items in the cart',
                'cart' => $cartData['cartResponse']
            ];
        }
        return new WP_REST_Response($response_data, 200);
    }
	/**
     * get customer details
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response||WP_Error
     * @since 1.2.1
     */
    public function fetchCustomerDetails($request)
    {
        $response = [
            'customer_type' => "new_customer",
            'orders_count'  => 0,
        ];
        $customer_phone = $request->get_param('phone') ?? '';
        if (!empty($customer_phone)) {
            $plain_phone = preg_replace('/[^0-9]/', '', $customer_phone);
            if (strlen($plain_phone) < 10 || strlen($plain_phone) > 10) {
                return new WP_Error(
                   "Invalid Mobile Number",
                    ['status' => 400]
                );
            }
            $plain_phone_last10 = substr($plain_phone, -10);
            $phone_with_cc = $request->get_param('country_code') ?? "+91";
            $orders_without_cc = wc_get_orders([
                'type'     => 'shop_order',
                'limit'    => 1,
                'paginate' => true,
                'billing_phone' => $plain_phone_last10,
                'return'   => 'ids',
                'status'   => ['completed', 'processing', 'on-hold'],
                'exclude'  => ['trash'],
            ]);
            $orders_with_cc = wc_get_orders([
                'type'     => 'shop_order',
                'limit'    => 1,
                'paginate' => true,
                'billing_phone' => $phone_with_cc.$plain_phone_last10,
                'return'   => 'ids',
                'status'   => ['completed', 'processing', 'on-hold'],
                'exclude'  => ['trash'],
            ]);
            $response['orders_count'] = (int)($orders_without_cc->total ?? 0) + (int)($orders_with_cc->total ?? 0);
        }else{
            return new WP_Error(
                "Mobile Number is empty",
                 ['status' => 400]
             );
        }
        if ($response['orders_count'] > 0) {
            $response['customer_type'] = "existing_customer";
        }
        return new WP_REST_Response(
            [
                'message'       => "Customer Details Fetched Successfully",
                'orders_count'  => $response['orders_count'],
                'customer_type' => $response['customer_type'],
            ],
            200
        );
    }
}

GokwikCart::instance();
