<?php
/**
 * Freemius Lite SDK — Entry point.
 *
 * Consumer plugins call fs_lite_dynamic_init() to bootstrap.
 *
 * @package BPlugins\FreemiusLite
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BPluginsFSLite' ) ) {
	require_once __DIR__ . '/inc/FreemiusAdmin.php';

	class BPluginsFSLite {

		protected object  $config;
		public    string  $prefix = '';
		protected string  $plugin_file;
		private   ?object $lc = null;

		public function __construct( array $config = [] ) {
			foreach ( [ '__FILE__', 'slug', 'id' ] as $key ) {
				if ( empty( $config[ $key ] ) ) {
					throw new \InvalidArgumentException( "Freemius Lite: '$key' is required in config." );
				}
			}
			$this->plugin_file = $config['__FILE__'];
			$this->config      = (object) $config;
			$this->prefix      = $config['prefix'] ?? $config['slug'] ?? '';

			if ( is_admin() ) {
				new FreemiusLiteAdmin( $this->config, $this->plugin_file );
			}
		}

		public function is_premium(): bool {
			return (bool) ( $this->lc->isPipe ?? false );
		}

		public function can_use_premium_feature(): bool  { return $this->is_premium(); }
		public function can_use_premium_code(): bool      { return $this->is_premium(); }
		public function can_use_premium_code__premium_only(): bool { return $this->is_premium(); }
		public function is__premium_only(): bool          { return $this->is_premium(); }

		public function uninstall_plugin(): void {
			deactivate_plugins( plugin_basename( $this->plugin_file ) );
		}

		public function set_basename( bool $is_premium, string $file ): void {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$b = basename( $file );
			$s = $this->config->slug ?? '';
			if ( is_plugin_active( "$s/$b" ) )     { deactivate_plugins( "$s/$b" ); }
			if ( is_plugin_active( "$s-pro/$b" ) ) { deactivate_plugins( "$s-pro/$b" ); }
		}
	}
}

if ( ! function_exists( 'fs_lite_dynamic_init' ) ) {
	function fs_lite_dynamic_init( array $module ) {
		if ( function_exists( 'fs_dynamic_init' ) ) {
			return fs_dynamic_init( $module );
		}
		if ( empty( $module['__FILE__'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			$c = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 );
			$module['__FILE__'] = $c[0]['file'] ?? null;
			if ( empty( $module['__FILE__'] ) ) {
				throw new \Error( 'Freemius Lite: __FILE__ required.' );
			}
		}
		return new BPluginsFSLite( $module );
	}
}
