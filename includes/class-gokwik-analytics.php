<?php
/**
 * GoKwik Checkout Analytics
 * Tracks checkout performance, conversion rates, and user behavior
 */

if (!defined('ABSPATH')) exit;

class GoKwik_Analytics {
    
    private static $instance = null;
    private $table_name;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'gokwik_analytics';
        add_action('init', array($this, 'init_hooks'));
    }
    
    public function init_hooks() {
        add_action('woocommerce_checkout_order_processed', array($this, 'track_order_success'), 10, 3);
        add_action('wp_ajax_gokwik_track_event', array($this, 'track_event_ajax'));
        add_action('wp_ajax_nopriv_gokwik_track_event', array($this, 'track_event_ajax'));
    }
    
    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(100) NOT NULL,
            event_type varchar(50) NOT NULL,
            event_data longtext,
            cart_value decimal(10,2),
            user_id bigint(20),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function track_event($event_type, $data = array()) {
        global $wpdb;
        
        $session_id = $this->get_session_id();
        $cart_value = WC()->cart ? WC()->cart->get_total('') : 0;
        
        $wpdb->insert($this->table_name, array(
            'session_id' => $session_id,
            'event_type' => sanitize_text_field($event_type),
            'event_data' => json_encode($data),
            'cart_value' => $cart_value,
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ));
    }
    
    public function track_event_ajax() {
        check_ajax_referer('gokwik_analytics', 'nonce');
        
        $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
        $data = isset($_POST['data']) ? json_decode(stripslashes($_POST['data']), true) : array();
        
        $this->track_event($event_type, $data);
        wp_send_json_success();
    }
    
    public function track_order_success($order_id, $posted_data, $order) {
        $this->track_event('order_completed', array(
            'order_id' => $order_id,
            'order_total' => $order->get_total(),
            'payment_method' => $order->get_payment_method()
        ));
    }
    
    public function get_analytics_data($days = 7) {
        global $wpdb;
        
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $funnel_data = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, COUNT(*) as count, AVG(cart_value) as avg_value
            FROM {$this->table_name}
            WHERE created_at >= %s
            GROUP BY event_type
            ORDER BY count DESC",
            $date_from
        ));
        
        $conversion_rate = $this->calculate_conversion_rate($days);
        $avg_time_to_checkout = $this->calculate_avg_checkout_time($days);
        
        return array(
            'funnel' => $funnel_data,
            'conversion_rate' => $conversion_rate,
            'avg_checkout_time' => $avg_time_to_checkout,
            'period_days' => $days
        );
    }
    
    private function calculate_conversion_rate($days) {
        global $wpdb;
        
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $started = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM {$this->table_name}
            WHERE event_type = 'checkout_started' AND created_at >= %s",
            $date_from
        ));
        
        $completed = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM {$this->table_name}
            WHERE event_type = 'order_completed' AND created_at >= %s",
            $date_from
        ));
        
        return $started > 0 ? round(($completed / $started) * 100, 2) : 0;
    }
    
    private function calculate_avg_checkout_time($days) {
        global $wpdb;
        
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $avg_time = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, t1.created_at, t2.created_at))
            FROM {$this->table_name} t1
            JOIN {$this->table_name} t2 ON t1.session_id = t2.session_id
            WHERE t1.event_type = 'checkout_started'
            AND t2.event_type = 'order_completed'
            AND t1.created_at >= %s
            AND t2.created_at > t1.created_at",
            $date_from
        ));
        
        return $avg_time ? round($avg_time) : 0;
    }
    
    private function get_session_id() {
        if (!session_id()) {
            session_start();
        }
        return session_id();
    }
}
