<?php

namespace Gokwik_Checkout\Inc;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class GokwikUpdater
{

    private $plugin_slug;
    private $version;
    private $cache_key;
    private $cache_allowed;

    /**
     * @var GokwikUpdater - The single instance of this class.
     */
    protected static $_instance = null;

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
     * The Main GokwikUpdater Instance
     *
     * This ensures that only one instance can be loaded.
     *
     * @return GokwikUpdater - The main instance.
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Initialize the updater by adding necessary actions and filters.
     */
    public function __construct()
    {
        $this->plugin_slug = dirname(GOKWIKCHECKOUT_BASENAME);
        $this->version = GOKWIKCHECKOUT_VERSION;
        $this->cache_key = 'gokwik_checkout_updater';
        $this->cache_allowed = true;

        $this->add_hooks();
    }

    /**
     * Add necessary hooks for the updater.
     */
    private function add_hooks()
    {
        add_filter('plugins_api', [$this, 'get_plugin_info'], 20, 3);
        add_filter('site_transient_update_plugins', [$this, 'check_for_updates']);
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
    }

    /**
     * Fetch remote data for plugin updates.
     *
     * @return mixed JSON-decoded data or false on failure.
     */
    private function fetch_remote_data()
    {
        $remote = get_transient($this->cache_key);

        if (false === $remote || !$this->cache_allowed) {
            $remote = wp_remote_get('https://pdp.gokwik.co/v4/kwikcheckout-woo/plugin-manifest.json', [
                'timeout' => 5,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            if ($this->is_remote_request_failed($remote)) {
                return false;
            }

            set_transient($this->cache_key, $remote, DAY_IN_SECONDS);
        }

        return json_decode(wp_remote_retrieve_body($remote));
    }

    /**
     * Check if the remote request failed.
     *
     * @param mixed $remote The remote request response.
     * @return bool True if the request failed, false otherwise.
     */
    private function is_remote_request_failed($remote)
    {
        return is_wp_error($remote) || 200 !== wp_remote_retrieve_response_code($remote) || empty(wp_remote_retrieve_body($remote));
    }

    /**
     * Get plugin information for the updater.
     *
     * @param mixed $response The current response.
     * @param string $action The action being performed.
     * @param object $args Additional arguments.
     * @return mixed Modified response or original response.
     */
    public function get_plugin_info($response, $action, $args)
    {
        if ('plugin_information' !== $action || empty($args->slug) || $this->plugin_slug !== $args->slug) {
            return $response;
        }

        $remote = $this->fetch_remote_data();

        if (!$remote) {
            return $response;
        }

        return $this->build_plugin_info_response($remote);
    }

    /**
     * Build the plugin information response.
     *
     * @param object $remote The remote data object.
     * @return \stdClass The plugin information response.
     */
    private function build_plugin_info_response($remote)
    {
        $response = new \stdClass();
        $response->name = $remote->name;
        $response->slug = $remote->slug;
        $response->version = $remote->version;
        $response->tested = $remote->tested;
        $response->requires = $remote->requires;
        $response->author = $remote->author;
        $response->author_profile = $remote->author_profile;
        $response->homepage = $remote->homepage;
        $response->download_link = $remote->download_url;
        $response->trunk = $remote->download_url;
        $response->requires_php = $remote->requires_php;
        $response->last_updated = $remote->last_updated;

        $response->sections = [
            'description' => $remote->sections->description,
            'installation' => $remote->sections->installation,
            'changelog' => $remote->sections->changelog,
        ];

        if (!empty($remote->banners)) {
            $response->banners = [
                'low' => $remote->banners->low,
                'high' => $remote->banners->high,
            ];
        }

        return $response;
    }

    /**
     * Check for plugin updates.
     *
     * @param object $transient The transient object containing update information.
     * @return object Modified transient object.
     */
    public function check_for_updates($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->fetch_remote_data();

        if ($this->is_update_available($remote)) {
            $response = $this->build_update_response($remote);
            $transient->response[$response->plugin] = $response;
        }

        return $transient;
    }

    /**
     * Determine if an update is available.
     *
     * @param object $remote The remote data object.
     * @return bool True if an update is available, false otherwise.
     */
    private function is_update_available($remote)
    {
        return $remote && version_compare($this->version, $remote->version, '<') && version_compare($remote->requires, get_bloginfo('version'), '<=') && version_compare($remote->requires_php, PHP_VERSION, '<');
    }

    /**
     * Build the update response.
     *
     * @param object $remote The remote data object.
     * @return \stdClass The update response.
     */
    private function build_update_response($remote)
    {
        $response = new \stdClass();
        $response->slug = $this->plugin_slug;
        $response->plugin = "{$this->plugin_slug}/{$this->plugin_slug}.php";
        $response->new_version = $remote->version;
        $response->tested = $remote->tested;
        $response->package = $remote->download_url;

        return $response;
    }

    /**
     * Clear the cache after plugin update.
     *
     * @param object $upgrader The upgrader object.
     * @param array $options The options array.
     */
    public function clear_cache($upgrader, $options)
    {
        if ($this->cache_allowed && 'update' === $options['action'] && 'plugin' === $options['type']) {
            delete_transient($this->cache_key);
        }
    }
}
