<?php

namespace Gokwik_Checkout\Inc;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class GokwikUpdater
{

    /**
     * Base64-encoded Ed25519 public key used to verify update packages.
     *
     * RELEASE OWNER: generate a signing keypair (see RELEASING.md) and paste the
     * base64 public key here. Keep the private key out of this repository.
     * While this is empty, automatic updates are refused (fail closed).
     */
    const GOKWIK_UPDATER_PUBLIC_KEY = '';

    /**
     * Allowed URL prefixes for update packages. Any manifest download_url that
     * does not start with one of these prefixes is ignored.
     */
    const ALLOWED_PACKAGE_URL_PREFIXES = [
        'https://github.com/GoKwik/kwikcheckout-woo/releases/download/',
    ];

    /**
     * Transient key used to surface the last updater error to administrators.
     */
    const NOTICE_KEY = 'gokwik_checkout_updater_notice';

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
        add_filter('upgrader_pre_download', [$this, 'verify_package_download'], 10, 4);
        add_action('admin_notices', [$this, 'show_admin_notice']);
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
        $package = $this->get_verified_package_url($remote);
        $response->download_link = $package;
        $response->trunk = $package;
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

        if ($this->is_update_available($remote) && $this->get_verified_package_url($remote)) {
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
        $response->package = $this->get_verified_package_url($remote);

        return $response;
    }

    /**
     * Return the manifest package URL if the manifest carries everything needed
     * to verify the package, or an empty string otherwise.
     *
     * @param object $remote The remote data object.
     * @return string The package URL or an empty string.
     */
    private function get_verified_package_url($remote)
    {
        $error = self::validate_manifest($remote, self::GOKWIK_UPDATER_PUBLIC_KEY);

        if (true !== $error) {
            $this->record_error($error);
            return '';
        }

        return $remote->download_url;
    }

    /**
     * Validate that a manifest names an allowed package and carries integrity data.
     *
     * @param mixed $remote The remote data object.
     * @param string $public_key Base64-encoded Ed25519 public key.
     * @return true|string True when valid, otherwise an error message.
     */
    public static function validate_manifest($remote, $public_key)
    {
        if ('' === (string) $public_key) {
            return 'Automatic updates are disabled because no update signing key is configured in this plugin version.';
        }

        if (!is_object($remote) || empty($remote->download_url) || empty($remote->version)) {
            return 'The update manifest is incomplete.';
        }

        if (!self::is_allowed_package_url($remote->download_url)) {
            return 'The update manifest names a package location that is not allowed.';
        }

        if (empty($remote->sha256) || !is_string($remote->sha256) || !preg_match('/^[a-f0-9]{64}$/i', $remote->sha256)) {
            return 'The update manifest has no valid sha256 checksum.';
        }

        if (empty($remote->signature) || !is_string($remote->signature)) {
            return 'The update manifest has no package signature.';
        }

        return true;
    }

    /**
     * Check a package URL against the allow-list.
     *
     * @param mixed $url The package URL.
     * @return bool True if the URL is allowed.
     */
    public static function is_allowed_package_url($url)
    {
        if (!is_string($url) || '' === $url || preg_match('/[\s\\\\]/', $url)) {
            return false;
        }

        $parts = parse_url($url);

        if (empty($parts['scheme']) || 'https' !== $parts['scheme'] || empty($parts['host']) || empty($parts['path'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        if (false !== strpos($parts['path'], '..') || false !== strpos($parts['path'], '//') || false !== strpos($parts['path'], '%')) {
            return false;
        }

        $normalized = 'https://' . $parts['host'] . $parts['path'];

        if ($normalized !== $url) {
            return false;
        }

        foreach (self::ALLOWED_PACKAGE_URL_PREFIXES as $prefix) {
            if (0 === strpos($url, $prefix) && strlen($url) > strlen($prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the message that the release signature covers.
     *
     * @param string $slug The plugin slug.
     * @param string $version The release version.
     * @param string $sha256 Lowercase hex SHA-256 of the package zip.
     * @return string The signed message.
     */
    public static function build_signed_message($slug, $version, $sha256)
    {
        return $slug . ':' . $version . ':' . strtolower($sha256);
    }

    /**
     * Verify a downloaded package against the manifest checksum and signature.
     *
     * @param string $file Path to the downloaded package.
     * @param string $slug The plugin slug.
     * @param string $version The release version from the manifest.
     * @param string $sha256 Expected hex SHA-256 from the manifest.
     * @param string $signature Base64 Ed25519 detached signature from the manifest.
     * @param string $public_key Base64 Ed25519 public key.
     * @return true|string True when the package is valid, otherwise an error message.
     */
    public static function verify_package($file, $slug, $version, $sha256, $signature, $public_key)
    {
        if ('' === (string) $public_key) {
            return 'No update signing key is configured.';
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return 'Package signature verification is not available on this server.';
        }

        if (!is_string($file) || !is_readable($file)) {
            return 'The downloaded package could not be read.';
        }

        $actual = hash_file('sha256', $file);

        if (!is_string($sha256) || !$actual || !hash_equals(strtolower($sha256), $actual)) {
            return 'The downloaded package checksum does not match the manifest.';
        }

        $key = base64_decode((string) $public_key, true);
        $sig = base64_decode((string) $signature, true);

        if (false === $key || false === $sig || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen($key) || SODIUM_CRYPTO_SIGN_BYTES !== strlen($sig)) {
            return 'The package signature or signing key is malformed.';
        }

        try {
            $valid = sodium_crypto_sign_verify_detached($sig, self::build_signed_message($slug, $version, $actual), $key);
        } catch (\Throwable $e) {
            $valid = false;
        }

        return $valid ? true : 'The package signature is not valid.';
    }

    /**
     * Download and verify this plugin's update package before WordPress installs it.
     *
     * @param mixed $reply Whether to bail without returning the package. Default false.
     * @param string $package The package URL.
     * @param object $upgrader The upgrader instance.
     * @param array $hook_extra Extra arguments passed to hooked filters.
     * @return mixed Path to the verified package, a WP_Error, or the original reply.
     */
    public function verify_package_download($reply, $package, $upgrader, $hook_extra = [])
    {
        if (false !== $reply) {
            return $reply;
        }

        $plugin_file = "{$this->plugin_slug}/{$this->plugin_slug}.php";
        $is_ours = (is_array($hook_extra) && isset($hook_extra['plugin']) && $plugin_file === $hook_extra['plugin'])
            || self::is_allowed_package_url($package);

        if (!$is_ours) {
            return $reply;
        }

        $remote = $this->fetch_remote_data();
        $error = self::validate_manifest($remote, self::GOKWIK_UPDATER_PUBLIC_KEY);

        if (true === $error && $remote->download_url !== $package) {
            $error = 'The update package does not match the update manifest.';
        }

        if (true !== $error) {
            return $this->reject($error);
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = download_url($package, 300);

        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $result = self::verify_package($tmp, $this->plugin_slug, $remote->version, $remote->sha256, $remote->signature, self::GOKWIK_UPDATER_PUBLIC_KEY);

        if (true !== $result) {
            wp_delete_file($tmp);
            return $this->reject($result);
        }

        delete_transient(self::NOTICE_KEY);

        return $tmp;
    }

    /**
     * Record an updater error and return it as a WP_Error.
     *
     * @param string $message The error message.
     * @return \WP_Error The error.
     */
    private function reject($message)
    {
        $this->record_error($message);

        return new \WP_Error('gokwik_checkout_update_rejected', 'GoKwik Checkout update refused: ' . $message);
    }

    /**
     * Log an updater error and keep it for an admin notice.
     *
     * @param string $message The error message.
     */
    private function record_error($message)
    {
        if (get_transient(self::NOTICE_KEY) !== $message) {
            set_transient(self::NOTICE_KEY, $message, DAY_IN_SECONDS);
            error_log('GoKwik Checkout updater: ' . $message);
        }
    }

    /**
     * Show the last updater error on the plugins and updates screens.
     */
    public function show_admin_notice()
    {
        if (!current_user_can('update_plugins')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || !in_array($screen->id, ['plugins', 'update-core', 'plugins-network', 'update-core-network'], true)) {
            return;
        }

        $message = get_transient(self::NOTICE_KEY);

        if (!$message) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html('GoKwik Checkout: ' . $message . ' Please install updates manually from the official release page.')
        );
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
