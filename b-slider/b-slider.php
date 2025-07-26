<?php
/**
 * Plugin Name: B Slider
 * Description: Simple slider with bootstrap.
 * Version: 1.1.30
 * Author: bPlugins
 * Author URI: http://bplugins.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: slider
  * @fs_free_only, bsdk_config.json
 */
 
// ABS PATH
if (!defined('ABSPATH')) {exit;}

 
if (function_exists('bs_fs')) {
    register_activation_hook(__FILE__, function () {
        if (is_plugin_active('b-slider/b-slider.php')) {
            deactivate_plugins('b-slider/b-slider.php');
        }
        if (is_plugin_active('b-slider-pro/b-slider.php')) {
            deactivate_plugins('b-slider-pro/b-slider.php');
        }
    });

} else {

    // Create a helper function for easy SDK access.
    function bs_fs()
    {
        global $bs_fs;

        if ( !isset( $bs_fs ) ) {
            // Include Freemius SDK.
            if (file_exists(dirname(__FILE__) . '/bplugins_sdk/init.php')) {
                require_once dirname(__FILE__) . '/bplugins_sdk/init.php';
            }
            if (file_exists(dirname(__FILE__) . '/freemius/start.php')) {
                require_once dirname(__FILE__) . '/freemius/start.php';
            }

            $bs_fs = fs_lite_dynamic_init(array(
                'id'                  => '19318',
                'slug'                => 'b-slider',
                'premium_slug'        => 'b-slider-pro',
                'type'                => 'plugin',
                'public_key'          => 'pk_b24b0b3f21a9dbfaff418c0c40fc1',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                // If your plugin is a serviceware, set this option to false.
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'trial'               => array(
                    'days'               => 7,
                    'is_require_payment' => false,
                ),
                'menu' => array(
                    'slug'           => 'b-slider-dashboard',
                    'first-path'     => 'admin.php?page=b-slider-dashboard#/welcome',
                    'support'        => false,
                ),
            ));
        }
        return $bs_fs;
    }
    // Init Freemius.
    bs_fs();
    // Signal that SDK was initiated.
    do_action('bs_fs_loaded');

    require_once plugin_dir_path(__FILE__) . '/includes/Posts.php';
    require_once plugin_dir_path(__FILE__) . '/includes/PostsAjax.php';

    function bsbIsPremium() {
        return BSB_IS_PRO ? bs_fs()->can_use_premium_code() : false;
    }

    class BSB_Slider{

        private static $instance;

        private function __construct(){

            $this->define_constants();
            $this->load_classes();

            add_action('enqueue_block_assets', [$this, 'enqueueBlockAssets']);
            add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
            add_action('init', [$this, 'onInit']);
            // check premium 
            if( BSB_IS_FREE ){
                add_filter( 'plugin_action_links', [$this, 'plugin_action_links'], 10, 2 ); 
            }

            add_filter('plugin_row_meta', array($this, 'insert_plugin_row_meta'), 10, 2);

            add_action('wp_ajax_bsbPipeChecker', [$this, 'bsbPipeChecker']);
            add_action('wp_ajax_nopriv_bsbPipeChecker', [$this, 'bsbPipeChecker']);
            add_action('admin_init', [$this, 'registerSettings']);
            add_action('rest_api_init', [$this, 'registerSettings']);
        }


        // Check instance 
        public static function get_instance() {
            if ( self::$instance ){
                return self::$instance;
            }

            self::$instance = new self();
            return self::$instance;
        }

        // define constant 
        public function define_constants () {
            define( 'BSB_PLUGIN_VERSION', isset( $_SERVER['HTTP_HOST'] ) && 'localhost' === $_SERVER['HTTP_HOST'] ? time() : '1.1.30' );
            define('BSB_DIR', plugin_dir_url(__FILE__));
            define('BSB_DIR_PATH', plugin_dir_path(__FILE__));
            define('BSB_ASSETS_DIR', plugin_dir_url(__FILE__) . 'assets/');
            define('BSB_IS_FREE', 'b-slider/b-slider.php' === plugin_basename(__FILE__));
            define('BSB_IS_PRO', 'b-slider-pro/b-slider.php' === plugin_basename(__FILE__));
        }

        //Class loaded
        public function load_classes () {
             
            // check premium 
            require_once BSB_DIR_PATH . 'adminMenu.php';
            if ( BSB_IS_PRO && bsbIsPremium()) {
                require_once plugin_dir_path(__FILE__) . '/custom-post.php';
                new BSB_SLIDER\LPBCustomPost();
            }
        }

        public function plugin_action_links($links, $file) {
            
            if( plugin_basename( __FILE__ ) == $file ) {
                $links['go_pro'] = sprintf( '<a href="%s" style="%s" target="__blank">%s</a>', 'https://bplugins.com/products/b-slider/#pricing', 'color:#4527a4;font-weight:bold', __( 'Go Pro!', 'slider' ) );
            }

            return $links;
        }

        // Extending row meta 
        public function insert_plugin_row_meta($links, $file){
            if (plugin_basename( __FILE__ ) == $file) {
                // docs & faq
                $links[] = sprintf('<a href="https://bplugins.com/docs/b-slider/" target="_blank">' . __('Docs & FAQs', 'slider') . '</a>');

                // Demos
                $links[] = sprintf('<a href="https://bplugins.com/products/b-slider/#demos" target="_blank">' . __('Demos', 'slider') . '</a>');
            }

            return $links;
        }

        // Enqueue Block assets 
        public function enqueueBlockAssets(){
            wp_register_style('bsb-style', BSB_ASSETS_DIR . 'css/bootstrap.min.css', [], BSB_PLUGIN_VERSION);
            wp_register_style('lbb-plyr-style', BSB_ASSETS_DIR . 'css/plyr.min.css', [], BSB_PLUGIN_VERSION);

            wp_register_script('bootstrap', BSB_ASSETS_DIR . 'js/bootstrap.min.js', [], BSB_PLUGIN_VERSION);
            wp_register_script('lazyLoad', BSB_ASSETS_DIR . 'js/lazyLoad.js', [], BSB_PLUGIN_VERSION);
            wp_register_script('lbb-plyr-script', BSB_ASSETS_DIR . 'js/plyr.min.js', [], BSB_PLUGIN_VERSION);

            wp_localize_script('bsb-slider-editor-script', 'bsbInfo', [
                'patternsImagePath' => BSB_DIR . 'assets/images/patterns/',
            ]);

            wp_localize_script('bootstrap', 'bsbWoocommerce', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp_rest'),
            ]);
        }

        // Short code style
        public function adminEnqueueScripts($hook){
            if ('edit.php' === $hook || 'post.php' === $hook) {
                wp_enqueue_style('bsbAdmin', BSB_ASSETS_DIR . 'css/admin.css', [], BSB_PLUGIN_VERSION);
                wp_enqueue_script('bsbAdmin', BSB_ASSETS_DIR . 'js/admin.js', ['wp-i18n'], BSB_PLUGIN_VERSION, true);
            }
        }

        public function bsbPipeChecker() {
    		// Get and sanitize the nonce
    		$nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

    		// Verify the nonce for security
			if (!wp_verify_nonce($nonce, 'wp_ajax')) {
				wp_send_json_error(__('Invalid Request', 'slider'));
			}

			// Prepare the response data
			wp_send_json_success([
				'isPipe' => bsbIsPremium(),
			]);
		}

        public function registerSettings() {
            register_setting('bsbUtils', 'bsbUtils', [
                'show_in_rest' => [
                    'name' => 'bsbUtils',
                    'schema' => ['type' => 'string'],
                ],
                'type' => 'string',
                'default' => wp_json_encode(['nonce' => wp_create_nonce('wp_ajax')]),
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }

        public function onInit(){
            register_block_type( __DIR__ . '/build' );
        }

    }
    BSB_Slider::get_instance();


    if( !BSB_IS_PRO ){
		require_once BSB_DIR_PATH . '/includes/UpgradePage.php';
	}
}

