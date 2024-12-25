<?php

namespace Gokwik_Checkout\Inc;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class GokwikPrepaidGateway extends \WC_Payment_Gateway

{

    public function __construct()
    {
        $this->id = 'gokwik_prepaid';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'GoKwik Prepaid';
        $this->method_description = 'Payment gateway for processing GoKwik Prepaid orders.';
        $this->supports = array(
            'products',
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

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

    public function validate_fields()
    {
        return true;
    }

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

}
