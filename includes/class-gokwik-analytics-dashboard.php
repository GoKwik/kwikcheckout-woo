<?php
/**
 * GoKwik Analytics Dashboard
 * Admin dashboard for viewing checkout analytics
 */

if (!defined('ABSPATH')) exit;

class GoKwik_Analytics_Dashboard {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_analytics_menu'), 60);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    public function add_analytics_menu() {
        add_submenu_page(
            'woocommerce',
            'GoKwik Analytics',
            'GoKwik Analytics',
            'manage_woocommerce',
            'gokwik-analytics',
            array($this, 'render_dashboard')
        );
    }
    
    public function enqueue_scripts($hook) {
        if ('woocommerce_page_gokwik-analytics' !== $hook) return;
        
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', array(), '3.9.1', true);
        wp_enqueue_style('gokwik-analytics-css', plugins_url('assets/css/admin/gokwik-analytics.css', dirname(__FILE__)), array(), '1.0.0');
    }
    
    public function render_dashboard() {
        $analytics = GoKwik_Analytics::get_instance();
        $days = isset($_GET['days']) ? intval($_GET['days']) : 7;
        $data = $analytics->get_analytics_data($days);
        
        ?>
        <div class="wrap gokwik-analytics-dashboard">
            <h1>GoKwik Checkout Analytics</h1>
            
            <div class="gokwik-analytics-filters">
                <select id="gokwik-days-filter" onchange="location.href='?page=gokwik-analytics&days='+this.value">
                    <option value="7" <?php selected($days, 7); ?>>Last 7 Days</option>
                    <option value="30" <?php selected($days, 30); ?>>Last 30 Days</option>
                    <option value="90" <?php selected($days, 90); ?>>Last 90 Days</option>
                </select>
            </div>
            
            <div class="gokwik-analytics-cards">
                <div class="analytics-card">
                    <h3>Conversion Rate</h3>
                    <div class="card-value"><?php echo esc_html($data['conversion_rate']); ?>%</div>
                </div>
                
                <div class="analytics-card">
                    <h3>Avg Checkout Time</h3>
                    <div class="card-value"><?php echo esc_html(gmdate('i:s', $data['avg_checkout_time'])); ?></div>
                </div>
                
                <div class="analytics-card">
                    <h3>Total Events</h3>
                    <div class="card-value"><?php echo esc_html(array_sum(wp_list_pluck($data['funnel'], 'count'))); ?></div>
                </div>
            </div>
            
            <div class="gokwik-analytics-chart">
                <h2>Checkout Funnel</h2>
                <canvas id="gokwik-funnel-chart"></canvas>
            </div>
            
            <script>
            const ctx = document.getElementById('gokwik-funnel-chart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(wp_list_pluck($data['funnel'], 'event_type')); ?>,
                    datasets: [{
                        label: 'Events Count',
                        data: <?php echo json_encode(wp_list_pluck($data['funnel'], 'count')); ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
            </script>
        </div>
        <?php
    }
}

new GoKwik_Analytics_Dashboard();
