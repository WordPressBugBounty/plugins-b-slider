<?php
/**
 * Freemius Lite — Admin logic + API client.
 *
 * Single file containing all server-side SDK functionality.
 *
 * @package BPlugins\FreemiusLite
 * @since   2.2.0
 */

if (!defined('ABSPATH')) {
	exit;
}

/* =========================================================================
   Freemius_Lite  —  Minimal API client (multi-instance, scoped auth)
   ========================================================================= */

if (!class_exists('Freemius_Lite')) {
	class Freemius_Lite
	{

		const SDK_VERSION = '2.2.0';
		const API_ENDPOINT = 'https://api.bplugins.com/wp-json/freemius/v1/middleware/';

		private string $endpoint;
		private array $headers = [];

		public function __construct(?string $scope = null, ?int $id = null, ?string $pub = null, ?string $sec = null)
		{
			$this->endpoint = self::API_ENDPOINT . time();
			if ($scope && $id && $pub) {
				$this->headers = [
					'path' => '',
					'scope' => $scope,
					'id' => $id,
					'public' => $pub,
					'secret' => $sec ?? '',
					'Content-Type' => 'application/json',
				];
			}
		}

		/** Authenticated request. */
		public function fs_api(string $path = '', string $method = 'GET', $body = []): object
		{
			$this->headers['path'] = $path;
			$r = wp_remote_request($this->endpoint, [
				'method' => $method,
				'headers' => $this->headers,
				'body' => $body,
				'timeout' => 30,
				'sslverify' => true,
			]);
			if (is_wp_error($r)) {
				return (object) ['success' => false, 'error' => $r->get_error_message()];
			}
			$d = json_decode(wp_remote_retrieve_body($r));
			return is_object($d) ? $d : (object) ['success' => false];
		}

		/** Raw request with custom headers. */
		public function api(string $method = 'GET', $body = [], array $headers = []): object
		{
			$r = wp_remote_request($this->endpoint, [
				'method' => $method,
				'headers' => $headers,
				'body' => $body,
				'timeout' => 30,
				'sslverify' => true,
			]);
			if (is_wp_error($r)) {
				return (object) ['success' => false, 'error' => $r->get_error_message()];
			}
			$d = json_decode(wp_remote_retrieve_body($r));
			return is_object($d) ? $d : (object) ['success' => false];
		}

		public function plugin_activated(string $path, string $uid, string $version): object
		{
			global $wp_version;
			return $this->fs_api($path, 'PUT', wp_json_encode([
				'sdk_version' => self::SDK_VERSION,
				'platform_version' => $wp_version,
				'programming_language_version' => phpversion(),
				'url' => site_url(),
				'language' => get_locale(),
				'title' => get_bloginfo('name'),
				'version' => $version,
				'is_premium' => false,
				'is_active' => true,
				'is_uninstalled' => false,
				'uid' => $uid,
			]));
		}

		public function plugin_deactivated(string $path, string $uid): object
		{
			return $this->fs_api($path, 'PUT', wp_json_encode(['is_active' => false, 'uid' => $uid]));
		}

		/** Update tracking permissions for a plugin. */
		public function permission_update(array $fs_accounts, object $config, ?array $params = null)
		{
			$site_raw = $fs_accounts['sites'][$config->slug] ?? null;
			$site = $site_raw ? (object) $site_raw : null;

			if (!$site || !$params || empty($site->public_key) || empty($site->secret_key) || empty($site->install_id)) {
				$fs_accounts['plugin_data'][$config->slug]['is_anonymous'] = ['is' => true, 'timestamp' => time()];
				update_option('fs_lite_accounts', $fs_accounts, false);
				return false;
			}

			$headers = [
				'path' => sprintf('/permissions.json?sdk_version=%s&url=%s', self::SDK_VERSION, site_url()),
				'scope' => 'install',
				'id' => (int) $site->install_id,
				'public' => $site->public_key,
				'secret' => $site->secret_key,
				'Content-Type' => 'application/json',
			];

			$result = $this->api('PUT', wp_json_encode($params), $headers);

			if (isset($result->data->error) || (isset($result->data->code) && in_array($result->data->code, ['rest_invalid_json', 'unauthorized_access'], true))) {
				return ['success' => false, 'message' => $result->data->message ?? 'Unknown error'];
			}

			if (isset($result->data->permissions)) {
				$p = $result->data->permissions;
				$fs_accounts['plugin_data'][$config->slug] = [
					'is_user_tracking_allowed' => $p->user,
					'is_site_tracking_allowed' => $p->site,
					'is_events_tracking_allowed' => $p->site,
					'is_extensions_tracking_allowed' => $p->extensions,
				];
				update_option('fs_lite_accounts', $fs_accounts, false);
				return ['success' => true, 'data' => $fs_accounts];
			}

			return false;
		}
	}
}

/* =========================================================================
   FreemiusLiteAdmin  —  Flat admin class (replaces FS_Lite + FSActivate)
   ========================================================================= */

if (!class_exists('FreemiusLiteAdmin')) {
	class FreemiusLiteAdmin
	{

		const FS_VERSION = '2.2.0';
		const ACCOUNTS_KEY = 'fs_lite_accounts';
		const NONCE = 'fs_lite_nonce';

		protected object $config;
		protected string $prefix;
		protected string $plugin_file;
		protected string $base_name;
		protected string $plugin_name = '';
		protected string $version = '';
		protected string $path = '';
		protected bool $status = false;
		protected bool $marketing = false;

		private ?array $cached_accounts = null;

		public function __construct(object $config, string $file)
		{
			$this->config = $config;
			$this->prefix = $config->slug;
			$this->plugin_file = $file;
			$this->base_name = plugin_basename($file);
			$this->path = $config->slug . '/' . basename($file);

			if (!function_exists('get_plugin_data')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			add_action('init', [$this, 'init']);
			$this->register_hooks();
		}

		public function init(): void
		{
			$data = get_plugin_data($this->plugin_file);
			$this->plugin_name = $data['Name'] ?? '';
			$this->version = $this->is_localhost() ? (string) time() : ($data['Version'] ?? '');
			$this->config->version = $this->version;
		}

		/* ----- hook registration ----- */

		private function register_hooks(): void
		{
			$this->status = (bool) get_option("{$this->prefix}-opt_in", false);
			$this->marketing = (bool) get_option("{$this->prefix}-marketing-allowed", false);

			// UI hooks — needed for the opt-in form itself (no remote calls).
			add_filter("plugin_action_links_{$this->base_name}", [$this, 'opt_in_link']);
			add_action('admin_init', [$this, 'maybe_redirect']);
			add_action('admin_enqueue_scripts', [$this, 'enqueue']);
			add_action('admin_footer', [$this, 'modal']);
			add_action('admin_footer', [$this, 'inline_init']);
			add_action('admin_notices', [$this, 'notice']);

			add_action('admin_menu', [$this, 'menu']);

			// AJAX handlers — process opt-in form submissions (user-initiated only).
			add_action('wp_ajax_bsdk_fetch_info_' . $this->config->id, [$this, 'ajax_fetch_info']);
			add_action('wp_ajax_fs_lite_init_' . $this->config->id, [$this, 'ajax_fs_init']);
			add_action('wp_ajax_fs_notice_dismiss_' . $this->config->slug, [$this, 'ajax_dismiss']);

			/*
			 * Lifecycle hooks are always registered so WordPress can fire them.
			 * Remote calls inside the callbacks are gated by has_opted_in().
			 * No data is ever sent to external servers before opt-in.
			 * Ref: WordPress.org Plugin Guidelines 7 & 9.
			 */
			register_activation_hook($this->plugin_file, [$this, 'on_activate']);
			register_deactivation_hook($this->plugin_file, [$this, 'on_deactivate']);
		}

		/* ----- admin notice ----- */

		public function notice(): void
		{
			$n = $this->accounts()['admin_notices'][$this->config->slug]['activation_pending'] ?? [];
			printf(
				'<div class="fs_notice_board" data-nonce="%s" data-slug="%s" data-notice="%s"></div>',
				esc_attr(wp_create_nonce(self::NONCE . '_' . $this->config->id)),
				esc_attr($this->config->slug),
				esc_attr(wp_json_encode($n))
			);
		}

		/* ----- AJAX handlers ----- */

		public function ajax_dismiss(): void
		{
			$this->verify_ajax();
			$a = $this->accounts();
			unset($a['admin_notices'][$this->config->slug]['activation_pending']);
			update_option(self::ACCOUNTS_KEY, $a, false);
			wp_send_json_success('dismissed');
		}

		public function ajax_fs_init(): void
		{
			$this->verify_ajax();
			try {
				$info = $this->post_array('info');
				$notice = $this->post_array('notice');
				$a = $this->accounts();

				if (($info['is_skip_activation'] ?? '') === 'true') {
					$site = $this->get_data();
					$has_keys = is_object($site) && !empty($site->secret_key) && !empty($site->public_key);
					if ($has_keys) {
						unset($a['plugin_data'][$this->config->slug]['is_anonymous']);
					} else {
						$a['plugin_data'][$this->config->slug]['is_anonymous'] = ['is' => true];
					}
				} else {
					if (!empty($info['user_id'])) {
						$api = new Freemius_Lite('user', (int) $info['user_id'], $info['user_public_key'] ?? '', $info['user_secret_key'] ?? '');
						$site = (object) $this->post_array('site');
						$a = $this->accounts((int) $info['user_id'], (array) $api->fs_api(''), $site);
						unset($a['admin_notices'][$this->config->slug]['activation_pending']);
					} elseif ($notice && isset($info['pending_activation'])) {
						if (isset($notice['activation_pending']['message'])) {
							$notice['activation_pending']['message'] = str_replace(
								['{name}', '{email}'],
								[$this->plugin_name, wp_get_current_user()->user_email],
								$notice['activation_pending']['message']
							);
						}
						$a['admin_notices'][$this->config->slug] = $notice;
					}
					unset($a['plugin_data'][$this->config->slug]['is_anonymous']);
				}

				update_option(self::ACCOUNTS_KEY, $a, false);
				$safe = clone $this->config;
				unset($safe->secret_key);
				wp_send_json_success(wp_parse_args(['config' => $safe, 'admin_url' => admin_url()], $a));
			} catch (\Throwable $e) {
				wp_send_json_error(esc_html($e->getMessage()));
			}
		}

		public function ajax_fetch_info(): void
		{
			$this->verify_ajax();

			if ($this->post_field('thread') === 'permission_update') {
				$api = new Freemius_Lite();
				$result = $api->permission_update(
					$this->accounts(),
					$this->config,
					['permissions' => $this->post_field('permissions'), 'is_enabled' => $this->post_field('is_enabled') === 'true']
				);
				// Invalidate cache — permission_update writes to DB directly.
				$this->cached_accounts = null;
				if (is_array($result) && !$result['success']) {
					wp_send_json_error(esc_html($result['message']));
				}
			}

			$safe = clone $this->config;
			unset($safe->secret_key);
			wp_send_json_success(wp_parse_args($this->extended_config(), (array) $safe));
		}

		/* ----- UI rendering ----- */

		public function inline_init(): void
		{
			?>
			<script>document.addEventListener('DOMContentLoaded', function () { if (typeof bsdkOptInFormHandler === 'function') bsdkOptInFormHandler(<?php echo wp_json_encode($this->prefix); ?>); });</script>
			<?php
		}

		public function opt_in_link(array $links): array
		{
			$tracking = $this->all_tracking_allowed();
			$this->marketing = (bool) $tracking;

			// Only enable the modal (via slug class) when the user has truly
			// opted in and has real install data.  If they skipped or never
			// completed opt-in, let the link navigate to the full opt-in page.
			$has_install = $this->has_install_data();
			$cls = 'optInBtn' . ($has_install && null !== $tracking ? ' ' . $this->config->slug : '');

			$url = admin_url('admin.php?page=' . dirname($this->base_name) . '-opt-in');
			$label = $this->marketing ? esc_html__('Opt Out', 'freemius-lite') : esc_html__('Opt In', 'freemius-lite');
			$status = $this->marketing ? 'agree' : 'not-allowed';
			array_unshift($links, sprintf(
				'<a href="%s" class="%s" id="%sOptInBtn" data-status="%s">%s</a>',
				esc_url($url),
				esc_attr($cls),
				esc_attr($this->prefix),
				esc_attr($status),
				$label
			));
			return $links;
		}

		public function menu(): void
		{
			add_submenu_page('welcome', $this->plugin_name, $this->plugin_name, 'manage_options', dirname($this->base_name) . '-opt-in', [$this, 'form']);
		}

		public function form(): void
		{
			update_option("{$this->prefix}-redirect", true, true);
			$a = $this->accounts();
			update_option(self::ACCOUNTS_KEY, $a, false);
			printf(
				'<div data-nonce="%s" data-plugin-id="%s" data-slug="%s" id="%sOptInForm"></div>',
				esc_attr(wp_create_nonce(self::NONCE . '_' . $this->config->id)),
				esc_attr($this->config->id),
				esc_attr($this->config->slug),
				esc_attr($this->prefix)
			);
		}

		public function modal(): void
		{
			$s = get_current_screen();
			if (!$s || 'plugins' !== $s->base) {
				return;
			}
			printf(
				'<div id="%sOptInModal" data-slug="%s" data-nonce="%s" data-plugin-id="%s"></div>',
				esc_attr($this->prefix),
				esc_attr($this->config->slug),
				esc_attr(wp_create_nonce(self::NONCE . '_' . $this->config->id)),
				esc_attr($this->config->id)
			);
		}

		/* ----- redirect ----- */

		public function maybe_redirect(): void
		{
			if (!current_user_can('activate_plugins')) {
				return;
			}
			if (get_option("{$this->prefix}-redirect", false)) {
				return;
			}
			$uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
			if (false !== strpos($uri, 'post.php') || false !== strpos($uri, 'post-new.php')) {
				return;
			}
			update_option("{$this->prefix}-redirect", true, true);
			if (wp_safe_redirect(admin_url('admin.php?page=' . dirname($this->base_name) . '-opt-in'))) {
				exit;
			}
		}

		/* ----- enqueue ----- */

		public function enqueue(string $hook): void
		{
			$url = plugin_dir_url(__DIR__);

			// Admin notice script — only loaded when there is an active notice.
			if ($this->has_pending_notice()) {
				wp_enqueue_script('bsdk-admin-notice', $url . 'build/admin-notice.js', ['react', 'react-dom', 'wp-util'], $this->version, true);
			}

			$page = 'admin_page_' . dirname($this->base_name) . '-opt-in';
			if ('plugins.php' !== $hook && $page !== $hook) {
				return;
			}
			wp_enqueue_script('bsdk-opt-in', $url . 'build/opt-in-form.js', ['react', 'react-dom', 'wp-util'], $this->version, true);
			wp_enqueue_style('bsdk-opt-in', $url . 'build/opt-in-form.css', [], $this->version);
			wp_enqueue_style('bsdk-opt-in-extra', $url . 'build/style-opt-in-form.css', [], $this->version);
		}

		/* ----- lifecycle hooks (only fire after explicit opt-in) ----- */

		public function on_activate(): void
		{
			// Double-guard: no remote calls without explicit consent.
			if (!$this->has_opted_in()) {
				return;
			}
			$api = $this->event_api(true);
			if ($api) {
				$api->plugin_activated('', $this->anonymous_id(), $this->version);
			}
		}

		public function on_deactivate(): void
		{
			// Double-guard: no remote calls without explicit consent.
			if (!$this->has_opted_in()) {
				return;
			}
			$api = $this->event_api();
			if (!$api) {
				return;
			}
			$api->plugin_deactivated('?sdk_ver=' . self::FS_VERSION . '&url=' . site_url(), $this->anonymous_id());
		}

		/* ===== private helpers ===== */

		private function verify_ajax(): void
		{
			if (!wp_verify_nonce($this->post_field('nonce'), self::NONCE . '_' . $this->config->id) || !current_user_can('manage_options')) {
				wp_send_json_error('Unauthorized', 403);
			}
		}

		private function post_field(string $k, string $d = ''): string
		{
			return isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : $d;
		}

		private function post_array(string $k): array
		{
			return (isset($_POST[$k]) && is_array($_POST[$k])) ? map_deep(wp_unslash($_POST[$k]), 'sanitize_text_field') : [];
		}

		private function is_localhost(): bool
		{
			return isset($_SERVER['HTTP_HOST']) && 'localhost' === sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
		}

		/* ----- anonymous id ----- */

		public function anonymous_id(): string
		{
			$id = get_option('fs_lite_unique_id', '');
			if (empty($id) || !is_string($id)) {
				// Migrate from legacy option name if present.
				$id = get_option('unique_id', '');
				if (!empty($id) && is_string($id)) {
					update_option('fs_lite_unique_id', $id, true);
					delete_option('unique_id');
					return $id;
				}
				$key = preg_replace('#^https?://#', '', site_url());
				$auth = defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY && false === strpos(SECURE_AUTH_KEY, ' ') && SECURE_AUTH_KEY !== 'put your unique phrase here'
					? SECURE_AUTH_KEY : md5((string) microtime(true));
				$id = md5($key . $auth);
				update_option('fs_lite_unique_id', $id, true);
			}
			return $id;
		}

		/* ----- account data ----- */

		public function get_data(string $type = 'sites')
		{
			return $this->accounts()[$type][$this->config->slug] ?? false;
		}

		public function update_store(string $key, $value): void
		{
			$a = $this->accounts();
			$a[$key][$this->config->slug] = $value;
			update_option(self::ACCOUNTS_KEY, $a, false);
			$this->cached_accounts = null;
		}

		public function accounts(?int $uid = null, array $udata = [], ?object $site = null): array
		{
			if (null === $uid && null === $site && null !== $this->cached_accounts) {
				$a = $this->cached_accounts;
			} else {
				$a = get_option(self::ACCOUNTS_KEY, []);
				if (!is_array($a)) {
					$a = [];
				}
				foreach (['plugin_data', 'users', 'sites'] as $k) {
					$a[$k] = $a[$k] ?? [];
				}
			}
			if (!$this->path) {
				return $a;
			}
			$slug = $this->config->slug;
			if (!isset($a['plugin_data'][$slug]) || $uid) {
				$has = null !== $uid;
				$a['plugin_data'][$slug] = [
					'plugin_main_file' => (object) ['path' => $this->path],
					'is_diagnostic_tracking_allowed' => $has,
					'is_extensions_tracking_allowed' => $has,
					'is_user_tracking_allowed' => $has,
					'is_site_tracking_allowed' => $has,
					'is_events_tracking_allowed' => $has,
				];
			}
			if ($uid) {
				$a['users'][$uid] = $udata;
			}
			if ($site) {
				$a['sites'][$slug] = $site;
			}
			$this->cached_accounts = $a;
			return $a;
		}

		/* ----- extended config for JS ----- */

		private function extended_config(): array
		{
			if (!$this->version) {
				return [];
			}
			global $wp_version;
			$u = wp_get_current_user();
			$type = $this->post_field('type', 'form');
			$pd = [];
			$site = [];

			if ('modal' === $type) {
				$a = get_option(self::ACCOUNTS_KEY, []);
				$pd = is_array($a) ? ($a['plugin_data'][$this->config->slug] ?? []) : [];
				if (is_array($a) && isset($a['sites'][$this->config->slug])) {
					$s = $a['sites'][$this->config->slug];
					$site = ['scope' => 'install', 'id' => $s->install_id ?? '', 'public_key' => $s->public_key ?? ''];
				}
			}

			return [
				'freemius_form_action' => 'https://wp.freemius.com/action/service/user/install/',
				'uid' => $this->anonymous_id(),
				'platform_version' => $wp_version,
				'programming_language_version' => phpversion(),
				'user_email' => $u->user_email,
				'plugin_version' => $this->version,
				'site_name' => get_bloginfo('name'),
				'admin_url' => admin_url(),
				'site_url' => site_url(),
				'nonce' => wp_create_nonce($this->config->slug . '_activate_new'),
				'is_marketing_allowed' => $this->marketing,
				'user_first_name' => $u->user_firstname,
				'user_last_name' => $u->user_lastname,
				'plugin_name' => $this->plugin_name,
				'data' => $pd,
				'site' => $site,
			];
		}

		/* ----- consent & tracking checks ----- */

		/**
		 * Whether the user has explicitly opted in to site tracking.
		 * Returns true ONLY after the user submits the opt-in consent form.
		 * This is the primary gate for ALL remote API calls.
		 */
		private function has_opted_in(): bool
		{
			$a = $this->accounts();
			$pd = $a['plugin_data'][$this->config->slug] ?? [];

			// Tracking is OFF by default. Only enabled via explicit opt-in form.
			return !empty($pd['is_site_tracking_allowed']);
		}

		/**
		 * Whether there is a pending admin notice to display.
		 */
		private function has_pending_notice(): bool
		{
			$a = $this->accounts();
			return !empty($a['admin_notices'][$this->config->slug]['activation_pending']);
		}

		private function all_tracking_allowed(): ?bool
		{
			$a = $this->accounts();
			$pd = $a['plugin_data'][$this->config->slug] ?? null;
			if (!is_array($pd)) {
				return null;
			}
			$pd = (object) $pd;
			if (($pd->is_anonymous['is'] ?? false) || isset($a['admin_notices'][$this->config->slug]['activation_pending'])) {
				return null;
			}
			return ($pd->is_user_tracking_allowed ?? false) && ($pd->is_site_tracking_allowed ?? false)
				&& ($pd->is_events_tracking_allowed ?? false) && ($pd->is_extensions_tracking_allowed ?? false);
		}

		/**
		 * Whether the plugin has real install data (keys from a completed opt-in).
		 * Returns false for users who skipped or never completed the opt-in flow.
		 */
		private function has_install_data(): bool
		{
			$site = $this->get_data();
			return is_object($site) && !empty($site->public_key) && !empty($site->secret_key);
		}

		/* ----- event api ----- */


		private function event_api(bool $active = false)
		{
			$site = $this->get_data();
			$pd = $this->get_data('plugin_data');
			if (!$site || !is_object($site) || $site instanceof \__PHP_Incomplete_Class) {
				return false;
			}
			$site->is_active = $active;
			$this->update_store('sites', $site);
			if (!is_array($pd) || empty($pd['is_site_tracking_allowed'])) {
				return false;
			}
			return new Freemius_Lite('install', (int) $site->id, $site->public_key, $site->secret_key);
		}
	}
} // end class_exists('FreemiusLiteAdmin')
