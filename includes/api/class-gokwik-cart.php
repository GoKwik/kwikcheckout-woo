<?php

namespace Gokwik_Checkout\Inc\Api;

use Gokwik_Checkout\Inc\GokwikUtilities;
use \WP_Error;
use \WP_REST_Request;
use \WP_REST_Response;
use \WP_REST_Server;

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
     * @var object GokwikCart - The single instance of this class.
     *
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
    {}

    /**
     * Prevent object unserialization.
     */
    public function __wakeup()
    {}

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
            ['route' => '/' . $this->rest_base, 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'getCart'],
            ['route' => '/' . $this->rest_base . '/get-coupons', 'methods' => WP_REST_Server::READABLE, 'callback' => 'getCoupons'],
            ['route' => '/' . $this->rest_base . '/apply-coupon', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'applyCoupon'],
            ['route' => '/' . $this->rest_base . '/remove-coupon', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'removeCoupon'],
            ['route' => '/' . $this->rest_base . '/set-address', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'setAddress'],
            ['route' => '/' . $this->rest_base . '/set-shipping-method', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'setShippingMethod'],
            ['route' => '/' . $this->rest_base . '/set-payment-method', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'setPaymentMethod'],
            ['route' => '/' . $this->rest_base . '/get-wallet-balance', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'getWalletBalance'],
            ['route' => '/' . $this->rest_base . '/deduct-wallet-balance', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'deductWalletBalance'],
            ['route' => '/' . $this->rest_base . '/place-order', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'placeOrder'],
            ['route' => '/' . $this->rest_base . '/check-order-exists', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'checkOrderExists'],
            ['route' => '/' . $this->rest_base . '/update-order-status', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'updateOrderStatus'],
        ];

        foreach ($routes as $route) {
            register_rest_route(
                $this->namespace,
                $route['route'],
                [
                    [
                        'methods' => $route['methods'],
                        'callback' => [$this, $route['callback']],
                        'permission_callback' => isset($route['permission_callback']) ? $route['permission_callback'] : [$this, 'validateRequest'],
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
     * @return boolean
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
     * @return array|WP_ERROR
     * @since 1.0.0
     */
    protected function loadCartSession($session_key)
    {
        if (empty($session_key)) {
            return new WP_Error(
                'gc_missing_session_key',
                'Session key is missing.',
                array(
                    'status' => 400,
                )
            );
        }

        $cache_key = \WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP) . $session_key;
        if (wp_cache_get($cache_key, WC_SESSION_CACHE_GROUP) !== false) {
            wp_cache_delete($cache_key, WC_SESSION_CACHE_GROUP);
        }

        $objProduct = new \WC_Session_Handler();
        $wc_session_data = $objProduct->get_session($session_key);

        if (empty($wc_session_data)) {
            return new WP_Error(
                'gc_cart_not_found',
                'Cart not found.',
                array(
                    'status' => 404,
                )
            );
        }

        add_filter('woo_wallet_partial_payment_amount', function ($amount) {
            return 0;
        }, PHP_INT_MAX);

        $userData = get_userdata($session_key);
        if ($userData !== false) {
            wp_set_current_user($session_key);
        } else {
            $customerEmail = maybe_unserialize($wc_session_data)['customer_email'] ?? null;
            if ($customerEmail) {
                $user = get_user_by('email', $customerEmail);
                if ($user) {
                    $userId = $user->ID;
                    wp_set_current_user($userId);
                }
            }
        }

        if (defined("WC_ABSPATH")) {
            include_once WC_ABSPATH . "includes/wc-cart-functions.php";
            include_once WC_ABSPATH . "includes/wc-notice-functions.php";
            include_once WC_ABSPATH . "includes/wc-template-hooks.php";
        }

        if (null === WC()->session) {
            $session_class = apply_filters(
                "woocommerce_session_handler",
                "WC_Session_Handler"
            );
            WC()->session = new $session_class();
            WC()->session->init();
        }

        $session = WC()->session;
        foreach ($wc_session_data as $key => $value) {
            $session->set($key, maybe_unserialize($value));
        }

        if (null === WC()->customer) {
            WC()->customer = new \WC_Customer(get_current_user_id(), true);
        }

        if (null === WC()->cart) {
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
     * @return WP_REST_Response|WP_ERROR
     * @since 1.0.0
     */
    public function getCart($request)
    {
        global $wpdb;

        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        } else {
            [$wc_session_data, $cartObj] = $cartSessionResponse;
        }

        $cart_customer = maybe_unserialize($wc_session_data['customer']) ?? [];
        
        $cart_shipping_method = maybe_unserialize($wc_session_data['chosen_shipping_methods'] ?? []);
        if (!empty($cart_shipping_method)) {
            $current_shipping_method = WC()->session->get('chosen_shipping_methods', []);
            if ($cart_shipping_method !== $current_shipping_method) {
                WC()->session->set('chosen_shipping_methods', $cart_shipping_method);
            }
        }

        $cart_payment_method = maybe_unserialize($wc_session_data['chosen_payment_method'] ?? "");
        if (!empty($cart_payment_method)) {
            $current_payment_method = WC()->session->get('chosen_payment_method', "");
            if ($cart_payment_method !== $current_payment_method) {
                WC()->session->set('chosen_payment_method', $cart_payment_method);
            }
        }

        $cart_applied_coupon = maybe_unserialize($wc_session_data['applied_coupons'] ?? []);
        if (!empty($cart_applied_coupon)) {
            $current_applied_coupons = WC()->session->get('applied_coupons', []);
            if ($cart_applied_coupon !== $current_applied_coupons) {
                WC()->session->set('applied_coupons', $cart_applied_coupon);
            }
        }

        $cart_data_items = [];
        $codBlock = false;
        if (!empty($cartObj->cart_contents)) {
            foreach ($cartObj->cart_contents as $item_value) {
                $item = maybe_unserialize($item_value);
                $cart_data_items[] = $item;
                if (get_option('wc_settings_gokwik_section_mid', null) == "19r5f9vbcqsx" && !empty($item['wcpa_data'])) {
                    foreach ($item['wcpa_data'] as $wcpa_section) {
                        if (empty($wcpa_section['fields'])) {
                            continue;
                        }
                        foreach ($wcpa_section['fields'] as $fields) {
                            foreach ($fields as $field) {
                                if (isset($field['elementId']) && strpos($field['elementId'], 'wcpa-text-') !== false && !empty($field['value'])) {
                                    $codBlock = true;
                                    break 3;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (empty($cart_data_items)) {
            return new WP_Error(
                'gc_cart_has_no_items',
                'Cart is empty.',
                array(
                    'status' => 400,
                )
            );
        }

        $cart_items = [];
        foreach ($cart_data_items as $item) {
            $cart_items[] = $this->prepareCartItem($item, $request);
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

            $country = $cart_customer['country'] ?? 'IN';
            $state = $cart_customer['state'] ?? '';
            $postcode = $cart_customer['postcode'] ?? '';

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
                $shipping_methods[] = [
                    'method_id' => $shipping_rate->get_id(),
                    'rate_id' => $shipping_rate->get_method_id(),
                    'instance_id' => $shipping_rate->get_instance_id(),
                    'method_name' => $shipping_rate->get_label(),
                    'charge' => $shipping_rate->get_cost(),
                    'tax_cost' => $shipping_rate->get_shipping_tax(),
                    'taxes' => $shipping_rate->get_taxes(),
                ];
            }
        }

        $prepaidFeesAndDiscount = GokwikUtilities::calculatePrepaidExtraFeesAndDiscount((WC()->cart->get_subtotal() ?? 0) + (WC()->cart->get_shipping_total() ?? 0));
        $finalPrepaidCharge = $prepaidFeesAndDiscount['fee'];
        $finalPrepaidDiscount = $prepaidFeesAndDiscount['discount'];

        $codFeesAndDiscount = GokwikUtilities::calculateCodExtraFeesAndDiscount((WC()->cart->get_subtotal() ?? 0) + (WC()->cart->get_shipping_total() ?? 0));
        $finalCodCharge = $codFeesAndDiscount['fee'];
        $finalCodDiscount = $codFeesAndDiscount['discount'];

        $customerEmail = maybe_unserialize($wc_session_data)['customer_email'] ?? null;
        if (!empty($cart_applied_coupon)) {
            foreach ($cart_applied_coupon as $coupon_code) {
                $coupon_code = wc_format_coupon_code($coupon_code);
                $coupon = new \WC_Coupon($coupon_code);
                $discounts = new \WC_Discounts($cartObj);
                $is_valid = $discounts->is_coupon_valid($coupon);
                if (is_wp_error($is_valid) || ($customerEmail && !$this->checkCouponUsage($coupon, $customerEmail))) {
                    $couponRemoved = $cartObj->remove_coupon($coupon_code);
                    if ($couponRemoved) {
                        $this->removeCouponFromSession($session_key, $coupon_code);
                    }
                }
            }
        }

        $payment_methods = [];
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
        if (isset($available_gateways['cod']) && GokwikUtilities::isCodAvailable($cartObj) && $codBlock === false) {
            $payment_methods[] = [
                "payment_method" => "cod",
                "amount" => $cartObj->total,
                "charge" => $finalCodCharge,
                "discount" => $finalCodDiscount,
            ];
        }
        $payment_methods[] = [
            "payment_method" => "gokwik_prepaid",
            "amount" => $cartObj->total,
            "charge" => $finalPrepaidCharge,
            "discount" => $finalPrepaidDiscount,
        ];

        $cartResponse = [
            'user_id' => get_current_user_id(),
            'customer_email' => $customerEmail,
            'customer' => $cart_customer,
            'items' => $cart_items,
            'coupon_applied' => array_values($cart_applied_coupon),
            'chosen_shipping_method' => $cart_shipping_method,
            'chosen_payment_method' => $cart_payment_method,
            'shipping_methods' => $shipping_methods,
            'payment_methods' => $payment_methods,
            'totals' => $cartObj->get_totals(),
        ];

        $isPendingOrderFlowEnabled = defined('GOKWIKCHECKOUT_PENDING_ORDER_FLOW') && GOKWIKCHECKOUT_PENDING_ORDER_FLOW;
        $hasValidPhone = !empty($cart_customer['phone']) && $cart_customer['phone'] !== '1234567890';
        $hasValidPaymentMethod = in_array(strtolower($cart_payment_method), ['cod', 'gokwik_prepaid', 'wallet'], true);

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

        $response = new WP_REST_Response(
            $cartResponse,
            ['status' => 200]
        );

        return $response;
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
            return new WP_REST_Response(
                ['coupons' => []],
                ['status' => 200]
            );
        }

        $session_key = $request->get_param('session_key');
        $customerEmail = null;
        $cartObj = null;
        $show_valid_only = false;

        if ($session_key) {
            $cartSessionResponse = $this->loadCartSession($session_key);
            if (is_wp_error($cartSessionResponse)) {
                return $cartSessionResponse;
            } else {
                [$wc_session_data, $cartObj] = $cartSessionResponse;
                $customerEmail = maybe_unserialize($wc_session_data)['customer_email'] ?? null;
                $show_valid_only = get_option('wc_settings_gokwik_show_valid_coupons_only') == 'yes';
            }
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
                if (is_wp_error($is_valid) || ($customerEmail && !$this->checkCouponUsage($coupon, $customerEmail))) {
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
                $coupon_data['amount'] = number_format((float) $discount_amount, 2, '.', '');
                $coupon_data['discount_value'] = $coupon->get_amount();
            }

            $coupons[] = $coupon_data;
        }

        $response = new WP_REST_Response(
            ['coupons' => $coupons],
            ['status' => 200]
        );

        return $response;
    }

    /**
     * Apply coupon to cart.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_ERROR
     * @since 1.0.0
     */
    public function applyCoupon($request)
    {
        global $wpdb;

        $coupon_code = $request->get_param('coupon');
        if (empty($coupon_code)) {
            return new WP_Error(
                'gc_cart_coupon_is_required',
                'Coupon code is required.',
                array(
                    'status' => 400,
                )
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
                array(
                    'status' => 200,
                )
            );
        }

        $discounts = new \WC_Discounts($cartObj);
        $coupon_validity = $discounts->is_coupon_valid($coupon);
        if (is_wp_error($coupon_validity)) {
            return new WP_Error(
                'gc_cart_coupon_invalid',
                'Coupon is not valid.',
                array(
                    'status' => 200,
                )
            );
        }

        $customerEmail = maybe_unserialize($wc_session_data)['customer_email'] ?? null;
        if ($customerEmail) {
            if (!$this->checkCouponUsage($coupon, $customerEmail)) {
                return new WP_Error(
                    'gc_cart_coupon_invalid_usage',
                    'Coupon usage limit reached.',
                    array(
                        'status' => 200,
                    )
                );
            }
        }

        if (!$cartObj->has_discount($coupon_code)) {
            $cartObj->apply_coupon($coupon_code);
            $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
            $applied_coupons = $cartObj->applied_coupons ?? [];
            $wc_session_data['applied_coupons'] = maybe_serialize($applied_coupons);
            $wpdb->update($sessions_table, array('session_value' => maybe_serialize($wc_session_data)), array('session_key' => $session_key));
        }

        return new WP_REST_Response(array(
            'message' => 'Coupon was successfully added to cart.',
        ), 200);
    }

    /**
     * Remove coupon from cart.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_ERROR
     * @since 1.0.0
     */
    public function removeCoupon($request)
    {
        global $wpdb;

        $coupon = $request->get_param('coupon');
        if (empty($coupon)) {
            return new WP_Error(
                'gc_cart_coupon_is_required',
                'Coupon is required.',
                array(
                    'status' => 400,
                )
            );
        }

        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        } else {
            [$wc_session_data, $cartObj] = $cartSessionResponse;
        }

        $coupon = wc_format_coupon_code(wc_clean(wp_unslash($coupon)));
        if ($cartObj->has_discount($coupon)) {
            $remove_coupon = $cartObj->remove_coupon($coupon);
            if ($remove_coupon) {
                $this->removeCouponFromSession($session_key, $coupon);
            }
        }

        return new WP_REST_Response(array(
            'message' => 'Coupon was successfully removed from cart.',
        ), 200);
    }

    /**
     * Save billing/shipping address.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_ERROR
     * @since 1.0.0
     */
    public function setAddress($request)
    {
        global $wpdb;

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
            //return new WP_REST_Response(['message' => "Address successfully updated."], 200);
        }

        if (!empty($request->get_param('customerEmail'))) {
            $customerEmail = wc_clean(wp_unslash($request->get_param('customerEmail')));
            $wc_session_data['customer_email'] = $customerEmail;
            $any_changes_requested = true;
        }
        
        $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
        $customer_details = maybe_unserialize($wc_session_data['customer']) ?? [];
        $updated_customer_details = array_merge($customer_details, $new_customer_details);
        $wc_session_data['customer'] = maybe_serialize($updated_customer_details);
        $serialized_session = maybe_serialize($wc_session_data);
        $wpdb->update($sessions_table, ['session_value' => $serialized_session], ['session_key' => $session_key]);

        $message = $any_changes_requested ? "Address successfully updated." : "No changes requested.";
        return new WP_REST_Response(['message' => $message], 200);
    }

    /**
     * Set shipping method.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_ERROR
     * @since 1.0.0
     */
    public function setShippingMethod($request)
    {
        global $wpdb;

        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        } else {
            [$wc_session_data, $cartObj] = $cartSessionResponse;
        }

        $sessions_table = $wpdb->prefix . 'woocommerce_sessions';

        $shipping_methods = $request->get_param('shipping_methods');
        if (!empty($shipping_methods)) {
            $shipping_method = wc_clean(wp_unslash($shipping_methods));
            $wc_session_data['chosen_shipping_methods'] = maybe_serialize($shipping_method);
            $serialized_session = maybe_serialize($wc_session_data);
            $wpdb->update($sessions_table, ['session_value' => $serialized_session], ['session_key' => $session_key]);
            return new WP_REST_Response(
                ['message' => "Shipping method successfully updated."],
                ['status' => 200]
            );
        }

        return new WP_REST_Response(
            ['message' => "No changes requested."],
            ['status' => 200]
        );
    }

    /**
     * Set payment method.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_ERROR
     * @since 1.0.0
     */
    public function setPaymentMethod($request)
    {
        global $wpdb;

        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        } else {
            [$wc_session_data, $cartObj] = $cartSessionResponse;
        }

        $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
        $payment_method = $request->get_param('payment_method');
        if (!empty($payment_method)) {
            $cleaned_payment_method = wc_clean(wp_unslash($payment_method));
            $wc_session_data['chosen_payment_method'] = maybe_serialize($cleaned_payment_method);
            WC()->session->set('chosen_payment_method', $cleaned_payment_method);
            $message = "Payment method successfully set.";
        } else {
            $wc_session_data['chosen_payment_method'] = maybe_serialize('');
            WC()->session->set('chosen_payment_method', '');
            $message = "Payment method successfully reset.";
        }
        $serialized_session = maybe_serialize($wc_session_data);
        $wpdb->update($sessions_table, ['session_value' => $serialized_session], ['session_key' => $session_key]);

        return new WP_REST_Response(
            ['message' => $message],
            ['status' => 200]
        );
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
            ['status' => 200]
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
                'plugin_inactive',
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
                'invalid_amount',
                'Amount must be greater than zero.',
                ['status' => 400]
            );
        }

        $current_balance = woo_wallet()->wallet->get_wallet_balance($user->ID, 'edit');
        if ($current_balance < $amount) {
            return new WP_Error(
                'insufficient_balance',
                'Insufficient wallet balance.',
                ['status' => 400]
            );
        }

        $transaction_id = woo_wallet()->wallet->debit($user->ID, $amount, $note);
        if (!$transaction_id) {
            return new WP_Error(
                'transaction_failed',
                'Transaction failed. Please try again.',
                ['status' => 500]
            );
        }

        return new WP_REST_Response(
            ['transaction_id' => $transaction_id],
            ['status' => 200]
        );
    }

    /**
     * Create or update a pending order based on the current cart session.
     *
     * @param array $wc_session_data
     * @param string $session_key
     *
     * @return array|WP_Error
     * @since 1.0.5
     */
    public function createOrUpdatePendingOrder($wc_session_data, $session_key)
    {
        $pendingOrder = GokwikUtilities::getPendingOrder($session_key);
        if ($pendingOrder) {
            $order = GokwikUtilities::updateOrderFromCart($pendingOrder, $wc_session_data);
        } else {
            $order = GokwikUtilities::createOrderFromCart($wc_session_data, $session_key);
        }
        GokwikUtilities::saveCartFlowsAbandonedCartData($wc_session_data, $session_key);
    }

    /**
     * Place order.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_ERROR
     * @since 1.0.2
     */
    public function placeOrder($request)
    {
        global $wpdb;

        $session_key = $request->get_param('session_key');
        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        } else {
            [$wc_session_data, $cartObj] = $cartSessionResponse;
        }

        $billing_address = wc_clean(wp_unslash($request->get_param('billing')));
        $shipping_address = wc_clean(wp_unslash($request->get_param('shipping')));
        $meta_data = wc_clean(wp_unslash($request->get_param('meta_data')));

        $billing_state = $billing_address['state'] ?? null;
        if (strlen($billing_state) !== 2) {
            $billing_state = GokwikUtilities::getStateCode($billing_state);
        }
        $shipping_state = $shipping_address['state'] ?? null;
        if (strlen($billing_state) !== 2) {
            $shipping_state = GokwikUtilities::getStateCode($shipping_state);
        }
        $status = wc_clean(wp_unslash($request->get_param('status')));
        $payment_method = wc_clean(wp_unslash($request->get_param('payment_method')));
        $applicablePaymentMethod = ['gokwik_prepaid', 'wallet', 'cod'];
        if (!in_array(strtolower($payment_method), $applicablePaymentMethod)) {
            return new WP_Error(
                'gc_cart_invalid_payment_method',
                'Payment method is invalid.',
                array(
                    'status' => 400,
                )
            );
        }

        $transaction_id = wc_clean(wp_unslash($request->get_param('transaction_id'))) ?? null;
        $set_paid = wc_clean(wp_unslash($request->get_param('set_paid')));

        $is_paid = false;
        if ((strtolower($payment_method) == 'gokwik_prepaid') || (strtolower($payment_method) == 'wallet')) {
            $is_paid = $set_paid;
        }

        $customer_ip = wc_clean(wp_unslash($request->get_param('customer_ip'))) ?? null;
        $customer_user_agent = wc_clean(wp_unslash($request->get_param('customer_user_agent'))) ?? null;

        $order_data = array_merge(
            array_fill_keys([
                'billing_company', 'shipping_company',
            ], null),
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

        $cart_total = WC()->cart->get_total('edit');
        $fee_lines = wc_clean(wp_unslash($request->get_param('fee_lines')));
        foreach ($fee_lines as $fee) {
            if ((($fee['name'] ?? null) === "Wallet Applied") || (($fee['discount_source'] ?? null) === "gkp")) {
                $cart_total += (float) $fee['total'];
            }
        }

        $order_total = (float) wc_clean(wp_unslash($request->get_param('order_total'))) ?? 0;
        if ($order_total != 0 && abs($cart_total - $order_total) > 0.01) {
            return new WP_Error(
                'gc_cart_total_mismatch',
                'Cart total does not match the order total.',
                array('status' => 400)
            );
        }

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
                array('status' => 400)
            );
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error(
                'gc_cart_place_order_error',
                'Unable to create order.',
                array('status' => 400)
            );
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
            }
        }

        $domainParts = explode('.', $_SERVER['HTTP_HOST']);
        if (stripos($domainParts[0], 'skullcandy') !== false || (count($domainParts) > 2 && stripos($domainParts[1], 'skullcandy') !== false)) {
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
        }

        $walletFeeApplied = false;
        $prepaidDiscountApplied = false;

        foreach ($fee_lines as $key => $fee) {
            if ((($fee['name'] ?? null) === "Wallet Applied") && !empty($fee['total'] ?? null)) {
                $walletFee = new \WC_Order_Item_Fee();
                $walletFee->set_name($fee['name']);
                $feeTotal = (float) $fee['total'];
                $walletFee->set_total($feeTotal < 0 ? $feeTotal : -1 * $feeTotal);
                $walletFee->set_amount($feeTotal < 0 ? $feeTotal : -1 * $feeTotal);
                $walletFee->set_total_tax(0);
                $walletFee->set_tax_class(false);
                $walletFee->set_tax_status('none');
                $walletFee->set_taxes(false);
                $walletFee->save();
                $order->add_item($walletFee);
                $walletFeeApplied = true;
            } elseif ((($fee['discount_source'] ?? null) === "gkp") && !empty($fee['total'] ?? null)) {
                $prepaidDiscount = new \WC_Order_Item_Fee();
                $prepaidDiscount->set_name($fee['name'] ?? 'Prepaid Discount');
                $feeTotal = (float) $fee['total'];
                $prepaidDiscount->set_total($feeTotal < 0 ? $feeTotal : -1 * $feeTotal);
                $prepaidDiscount->set_amount($feeTotal < 0 ? $feeTotal : -1 * $feeTotal);
                $prepaidDiscount->set_total_tax(0);
                $prepaidDiscount->set_tax_class(false);
                $prepaidDiscount->set_tax_status('none');
                $prepaidDiscount->set_taxes(false);
                $prepaidDiscount->save();
                $order->add_item($prepaidDiscount);
                $prepaidDiscountApplied = true;
            }
        }

        $order->calculate_totals(false);
        $order->save();

        $response = new WP_REST_Response(
            ['id' => $order->get_id()],
            ['status' => 200]
        );

        return $response;
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

        return new WP_REST_Response(
            ['message' => 'No order found.'],
            404
        );
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
            return new WP_REST_Response(
                ['message' => 'Order not found.'],
                404
            );
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
        global $wpdb;

        $cartSessionResponse = $this->loadCartSession($session_key);
        if (is_wp_error($cartSessionResponse)) {
            return $cartSessionResponse;
        } else {
            [$wc_session_data, $cartObj] = $cartSessionResponse;
        }

        $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
        $applied_coupons = maybe_unserialize(maybe_unserialize($wc_session_data)['applied_coupons']) ?? [];
        if (($key = array_search($coupon, $applied_coupons)) !== false) {
            unset($applied_coupons[$key]);
            $applied_coupons = array_values($applied_coupons);
        }
        $wc_session_data['applied_coupons'] = maybe_serialize($applied_coupons);
        $wpdb->update($sessions_table, array('session_value' => maybe_serialize($wc_session_data)), array('session_key' => $session_key));
    }

    /**
     * Check if a coupon has remaining usage for a specific email.
     *
     * @access protected
     * @param WC_Coupon $coupon The coupon object.
     * @param string $billing_email The billing email to check against.
     *
     * @return boolean True if the coupon can be used, false otherwise.
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
                        array(
                            $billing_email,
                            $current_user->user_email,
                        )
                    )
                )
            )
        );
        $restrictions = $coupon->get_email_restrictions();
        if (is_array($restrictions) && 0 < count($restrictions) && !WC()->cart->is_coupon_emails_allowed($check_emails, $restrictions)) {
            return false;
        }
        $coupon_usage_limit = $coupon->get_usage_limit_per_user();
        if (0 < $coupon_usage_limit) {
            $coupon_data_store = $coupon->get_data_store();
            foreach ($check_emails as $email) {
                if ($coupon_data_store && $coupon_data_store->get_usage_by_email($coupon, $email) >= $coupon_usage_limit) {
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
        $product = ($cart_item["variation_id"] == 0) ? wc_get_product($cart_item["product_id"]) : wc_get_product($cart_item["variation_id"]);

        $cart_item['product_data'] = [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'sku' => $product->get_sku(),
            'price' => isset($cart_item['_bogof_free_item']) ? $cart_item['line_subtotal'] : $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
        ];

        if ($product instanceof \WC_Product_Variation) {
            $parent_product_id = $product->get_parent_id();
            $cart_item['product_data']['id'] = $parent_product_id;
            $cart_item['product_data']['variation_id'] = $cart_item['variation_id'];
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
        foreach ($attachment_ids as $position => $attachment_id) {
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

}

GokwikCart::instance();
