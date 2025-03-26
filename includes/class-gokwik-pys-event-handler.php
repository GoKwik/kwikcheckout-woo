<?php

namespace Gokwik_Checkout\Inc;
use Gokwik_Checkout\Inc\GokwikUtilities;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Manages GoKwik and PixelYourSite integration for WooCommerce.
 */
class GokwikPysEventHandler
{
    private $purchaseEventMetaKey = '_pys_purchase_event_fired';
    private $recoveryActionHook = 'gokwik_purchase_event_recovery_action';
    private $eventDelayInSeconds = 150;

    /**
     * Sets up hooks for the plugin.
     */
    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'initializeClass']);
        add_action($this->recoveryActionHook, [$this, 'recoverPendingEvents']);
    }

    /**
     * Checks dependencies and sets up hooks.
     */
    public function initializeClass()
    {
        if (is_plugin_active('pixelyoursite-pro/pixelyoursite-pro.php')) {
            add_action(
                'woocommerce_new_order',
                [$this, 'scheduleOrderEvent'],
                10,
                1
            );
        }
    }

    /**
     * Schedules and recovers events for new orders.
     *
     * @param int $orderId Order ID.
     */
    public function scheduleOrderEvent($orderId)
    {
        if (!wp_next_scheduled($this->recoveryActionHook)) {
            wp_schedule_single_event(
                time() + $this->eventDelayInSeconds,
                $this->recoveryActionHook
            );
        }
        $this->recoverPendingEvents();
    }

    /**
     * Recovers unfired events for recent orders.
     */
    public function recoverPendingEvents()
    {
        if (!is_plugin_active('pixelyoursite-pro/pixelyoursite-pro.php')) {
            return;
        }

        $recentOrders = wc_get_orders([
            'date_created' => '>' . (time() - 180),
            'status' => ['wc-processing'],
        ]);

        foreach ($recentOrders as $order) {
            $isGokwikOrder = $order->get_meta('is_gokwik_order') === 'true';
            $eventNotTriggered = !in_array(
                $order->get_meta($this->purchaseEventMetaKey),
                ['1', 'true'],
                true
            );
            if ($isGokwikOrder && $eventNotTriggered) {
                $this->triggerPurchaseEvent($order->get_id());
            }
        }
    }

    /**
     * Triggers the purchase event for an order.
     *
     * @param int $orderId Order ID.
     * @return bool True if successful, false otherwise.
     */
    public function triggerPurchaseEvent($orderId)
    {
        $order = wc_get_order($orderId);
        if (!$order || !is_plugin_active('pixelyoursite-pro/pixelyoursite-pro.php')) {
            return false;
        }

        try {
            $this->executePurchaseEvent($orderId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Executes the purchase event for an order.
     *
     * @param int $orderId Order ID.
     */
    private function executePurchaseEvent($orderId)
    {
        if (!is_plugin_active('pixelyoursite-pro/pixelyoursite-pro.php')) {
            return;
        }

        $pysInstance = \PixelYourSite\PYS::instance();
        $order = wc_get_order($orderId);

        if (
            !$order ||
            !$pysInstance->getOption('woo_purchase_enabled') ||
            $order->get_meta($this->purchaseEventMetaKey, true)
        ) {
            return;
        }

        add_filter("pys_woo_checkout_order_id", fn() => $orderId);
        $purchaseEvent = \PixelYourSite\EventsWoo()->getEvent('woo_purchase');
        if ($purchaseEvent === null) {
            return;
        }

        $purchaseEvent->addParams(['advanced_purchase_tracking' => true]);
        $this->dispatchEvents($purchaseEvent);
        $this->updateOrderMetadata($order, $orderId);
    }

    /**
     * Sends purchase events to platforms.
     *
     * @param object $event Event object.
     */
    private function dispatchEvents($event)
    {
        try {
            if (\PixelYourSite\Facebook()->getOption('woo_purchase_enabled')) {
                $facebookEvents = \PixelYourSite\Facebook()->generateEvents($event);
                \PixelYourSite\FacebookServer()->sendEventsNow($facebookEvents);
            }
        } catch (\Exception $e) {
            // Handle error
        }

        try {
            if (\PixelYourSite\GA()->getOption('woo_purchase_enabled')) {
                $googleAnalyticsEvents = \PixelYourSite\GA()->generateEvents($event);
                (new \PixelYourSite\GaMeasurementProtocolAPI())->sendEventsNow($googleAnalyticsEvents);
            }
        } catch (\Exception $e) {
            // Handle error
        }
    }

    /**
     * Updates order metadata after event.
     *
     * @param \WC_Order $order Order object.
     * @param int $orderId Order ID.
     */
    private function updateOrderMetadata($order, $orderId)
    {
        if (GokwikUtilities::isHposEnabled()) {
            $order->update_meta_data($this->purchaseEventMetaKey, true);
            if (!$order->get_meta('tracking_type', true)) {
                $order->update_meta_data('tracking_type', 'Tag or API');
            }
            $order->save();
        } else {
            update_post_meta($orderId, $this->purchaseEventMetaKey, true);
            if (!get_post_meta($orderId, "tracking_type", true)) {
                update_post_meta($orderId, 'tracking_type', 'Tag or API');
            }
        }
    }
}

// Instantiate the event handler
new GokwikPysEventHandler();
