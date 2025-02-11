<?php

namespace Gokwik_Checkout\Inc;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class GokwikPrepaidGateway extends \WC_Payment_Gateway
{
    /**
     * Constructor for the gateway.
     * Initializes the gateway settings and hooks.
     */
    public function __construct()
    {
        $this->id = 'gokwik_prepaid';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'GoKwik Prepaid';
        $this->method_description = 'Payment gateway for processing GoKwik Prepaid orders.';
        $this->supports = array(
            'products',
            'refunds',
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Initialize form fields for the gateway settings.
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default' => 'GoKwik Prepaid',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'This controls the description which the user sees during checkout.',
                'default' => 'Pay via our super-cool payment gateway.',
            ),
            'enabled' => array(
                'title' => 'Enable/Disable',
                'label' => 'Enable GoKwik Prepaid Gateway',
                'type' => 'checkbox',
                'description' => '',
                'default' => 'yes',
            ),
        );
    }

    /**
     * Validate payment fields on the checkout page.
     * 
     * @return bool Always returns true as no fields are required.
     */
    public function validate_fields()
    {
        return true;
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id The order ID.
     * @return array|void The result of the payment processing.
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order) {
            WC()->cart->empty_cart();
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        wc_add_notice('Please try again.', 'error');
        return;
    }

    /**
     * Process a refund for an order.
     *
     * @param int $order_id The order ID.
     * @param float|null $amount The amount to refund.
     * @param string $reason The reason for the refund.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('invalid_order', 'Invalid order.');
        }

        if (!$order->is_paid()) {
            return new \WP_Error('order_not_paid', 'Order has not been paid.');
        }

        $order_total = $order->get_total();
        if ($amount <= 0 || $amount > $order_total) {
            return new \WP_Error('invalid_amount', sprintf('Invalid refund amount. Must be between 0 and %s.', $order_total));
        }

        $app_id = get_option('wc_settings_gokwik_section_app_id');
        $app_secret = get_option('wc_settings_gokwik_section_app_secret');

        if (empty($app_id) || empty($app_secret)) {
            return new \WP_Error('missing_credentials', 'GoKwik credentials are not set.');
        }

        if (is_plugin_active('custom-order-numbers-for-woocommerce/custom-order-numbers-for-woocommerce.php')) {
            $order_query = new \WC_Order_Query([
                'meta_key'   => '_alg_wc_full_custom_order_number',
                'meta_value' => $order_id,
                'limit'      => 1,
            ]);
            $orders = $order_query->get_orders();
            if ($orders) {
                $order_id = reset($orders)->get_id();
            }
        }

        $refund_data = [
            'merchant_order_id' => (string) $order_id,
            'amount' => (float) $amount,
            'tracking_id' => uniqid('refund_', true),
            'metadata' => [
                'refund_reason' => sanitize_text_field($reason),
            ],
        ];

        $response = wp_remote_post(GOKWIKCHECKOUT_API_BASE_URL . 'v3/refunds', [
            'method' => 'POST',
            'body' => wp_json_encode($refund_data),
            'headers' => [
                'Content-Type' => 'application/json',
                'gk-app-id' => $app_id,
                'gk-app-secret' => $app_secret,
            ],
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('refund_error', 'Refund failed. Please try again.');
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code !== 200) {
            return new \WP_Error('refund_error', 'Refund failed. Please try again.');
        }

        return true;
    }
}
