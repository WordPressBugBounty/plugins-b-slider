<?php
class FS_Lite
{
    protected const FS_VERSION = '2.5.12';
    protected const ACCOUNTS_KEY = 'fs_lite_accounts';
    protected const UNIQUE_ID_KEY = 'unique_id';

    protected $prefix = '';
    protected $config = null;
    protected $base_name = null;
    protected $plugin_name = '';
    protected $product = '';
    protected $__FILE__ = null;
    protected $_upgraded = false;
    protected $version = false;
    protected $dir;
    protected $path = null;

    public function __construct($config, $__FILE__)
    {
        $this->config = $config;
        $this->prefix = $this->config->slug;
        $this->__FILE__ = $__FILE__;
        $this->base_name = plugin_basename($this->__FILE__);
        $this->dir = __DIR__;
        $this->path = $this->config->slug . '/' . basename($this->__FILE__);

        $this->loadDependencies();
        add_action('init', [$this, 'init']);
    }

    protected function loadDependencies(): void
    {
        if (!class_exists('Freemius_Lite') && file_exists($this->dir . '/Freemius_Lite.php')) {
            require_once($this->dir . '/Freemius_Lite.php');
        }

        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
    }

    public function init(): void
    {
        $plugin_data = \get_plugin_data($this->__FILE__);
        $this->plugin_name = $plugin_data['Name'];
        $this->version = $this->isLocalhost() ? time() : $plugin_data['Version'];
    }

    protected function isLocalhost(): bool
    {
        return isset($_SERVER['HTTP_HOST']) &&
            sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) === 'localhost';
    }

    public function get_anonymous_id(?int $blog_id = null): string
    {
        $unique_id = get_option(self::UNIQUE_ID_KEY, null, $blog_id);

        if (empty($unique_id) || !is_string($unique_id)) {
            $unique_id = $this->generateUniqueId();
            update_option(self::UNIQUE_ID_KEY, $unique_id);
        }

        return $unique_id;
    }

    protected function generateUniqueId(): string
    {
        $key = $this->fs_strip_url_protocol(site_url());
        $secure_auth = $this->getSecureAuth();
        return md5($key . $secure_auth);
    }

    protected function getSecureAuth(): string
    {
        $secure_auth = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '';

        if (
            empty($secure_auth) ||
            false !== strpos($secure_auth, ' ') ||
            'put your unique phrase here' === $secure_auth
        ) {
            return md5(microtime());
        }

        return $secure_auth;
    }

    public function get_data(string $type = 'sites')
    {
        $fs_accounts = $this->get_fs_accounts();
        return $fs_accounts[$type][$this->config->slug] ?? false;
    }

    public function update_store(string $key, $value): void
    {
        $fs_accounts = $this->get_fs_accounts();
        $fs_accounts[$key][$this->config->slug] = $value;
        update_option(self::ACCOUNTS_KEY, $fs_accounts);
    }

    protected function fs_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    public function fs_strip_url_protocol(string $url): string
    {
        if (!$this->fs_starts_with($url, 'http')) {
            return $url;
        }

        $protocol_pos = strpos($url, '://');
        return $protocol_pos > 5 ? $url : substr($url, $protocol_pos + 3);
    }

    public function get_fs_accounts(?int $user_id = null, array $user_data = [], $site = null): array
    {
        $fs_accounts = $this->getInitializedAccounts();

        if (!$this->path) {
            return $fs_accounts;
        }

        if (!isset($fs_accounts['plugin_data'][$this->config->slug]) || $user_id) {
            $fs_accounts['plugin_data'][$this->config->slug] = $this->get_plugin_data($user_id);
        }

        if ($user_id) {
            $fs_accounts['users'][$user_id] = $user_data;
        }
        if ($site) {
            $fs_accounts['sites'][$this->config->slug] = $site;
        }

        return $fs_accounts;
    }

    protected function getInitializedAccounts(): array
    {
        $fs_accounts = get_option(self::ACCOUNTS_KEY, []);
        if (!is_array($fs_accounts)) {
            $fs_accounts = [];
        }

        $required_keys = ['plugin_data', 'users', 'sites'];
        foreach ($required_keys as $key) {
            if (!array_key_exists($key, $fs_accounts)) {
                $fs_accounts[$key] = [];
            }
        }

        return $fs_accounts;
    }

    public function get_plugin_data(?int $user_id = null): array
    {
        $data = [
            'plugin_main_file' => (object)['path' => $this->path],
        ];

        // if ($user_id) {
        return wp_parse_args([
            'is_diagnostic_tracking_allowed' => $user_id !== null,
            'is_extensions_tracking_allowed' => $user_id !== null,
            'is_user_tracking_allowed' => $user_id !== null,
            'is_site_tracking_allowed' => $user_id !== null,
            'is_events_tracking_allowed' => $user_id !== null,
        ], $data);
        // }

        return $data;
    }
}
