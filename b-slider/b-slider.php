<?php
/**
 * Plugin Name: bSlider – Create Responsive Image, Post, Product, and Video Sliders
 * Description: Simple slider with bootstrap.
 * Version: 2.0.12
 * Author: bPlugins
 * Author URI: http://bplugins.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: b-slider
 */
 
    // ABS PATH
    if (!defined('ABSPATH')) {exit;}

    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        define('B_SLIDER_PLUGIN_VERSION', time());
    } else {
        define('B_SLIDER_PLUGIN_VERSION', '2.0.12');
    }
    define('B_SLIDER_DIR', plugin_dir_url(__FILE__));
    define('B_SLIDER_DIR_PATH', plugin_dir_path(__FILE__));
    define('B_SLIDER_ASSETS_DIR', plugin_dir_url(__FILE__) . 'assets/');

    if(!function_exists('b_slider_fs')) {
        
        function b_slider_fs() 
        {
            global $b_slider_fs;

            if ( !isset( $b_slider_fs ) ) {
                require_once dirname(__FILE__) . '/vendor/freemius-lite/start.php'; 

                $bs_fs = fs_lite_dynamic_init([
                    'id'                  => '19318',
                    'slug'                => 'b-slider',
                    'type'                => 'plugin',
                    'public_key'          => 'pk_b24b0b3f21a9dbfaff418c0c40fc1',
                    'is_premium'          => false, 
                    'menu' => array(
                        'slug'           => 'edit.php?post_type=bsb',
                        'first-path'     => 'edit.php?post_type=bsb&page=b-slider#/pricing',
                        'support'     => false,
                    )
                ]);
            }
            return $b_slider_fs;
        }
        b_slider_fs();
        do_action('b_slider_fs_loaded'); 
    }

    require_once plugin_dir_path(__FILE__) . '/includes/Posts.php';
    require_once plugin_dir_path(__FILE__) . '/includes/PostsAjax.php';

    class B_Slider{

        private static $instance;

        private function __construct(){

            $this->load_classes();
            add_action('enqueue_block_assets', [$this, 'enqueueBlockAssets']);
            add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
            add_action('init', [$this, 'onInit']);
            add_filter( 'plugin_action_links', [$this, 'plugin_action_links'], 10, 2 );
            add_filter('plugin_row_meta', array($this, 'insert_plugin_row_meta'), 10, 2);
        }

        // Check instance 
        public static function get_instance() {
            if ( self::$instance ){
                return self::$instance;
            }

            self::$instance = new self();
            return self::$instance;
        }

        //Class loaded
        public function load_classes () {
            require_once plugin_dir_path(__FILE__) . '/includes/admin-menu.php'; 
            require_once plugin_dir_path(__FILE__) . '/custom-post.php';
            new B_SLIDER\CustomPost();
        }

        public function plugin_action_links($links, $file) {
            
            if( plugin_basename( __FILE__ ) == $file ) {

                $dashboardLink = admin_url( 'edit.php?post_type=bsb&page=b-slider' );

                 
                $links['go_pro'] = sprintf( '<a href="%s" style="%s" target="__blank">%s</a>', 'https://bplugins.com/products/b-slider/pricing', 'color:#4527a4;font-weight:bold', __( 'Go Pro!', 'b-slider' ) );
            

                $links['dashboard'] = sprintf( '<a href="%s" style="%s" target="__blank">%s</a>', $dashboardLink, 'color:#4527a4;font-weight:bold', __( 'Dashboard!', 'b-slider' ) );
            }
 
            return $links;
        }

        // Extending row meta 
        public function insert_plugin_row_meta($links, $file){

            $demosLine = admin_url( 'edit.php?post_type=bsb&page=b-slider#/demos' );
    
            if ($file == 'b-slider/b-slider.php') {
                // docs & faq
                $links[] = sprintf('<a href="https://bplugins.com/docs/b-slider/" target="_blank">' . __('Docs & FAQs', 'b-slider') . '</a>');

                // Demos
                $links[] = sprintf('<a href="%s" target="_blank">' . __('Demos', 'b-slider') . '</a>', $demosLine);
            }
            return $links;
        }

        // Enqueue Block assets 
        public function enqueueBlockAssets(){
            wp_register_style('b-slider-style', B_SLIDER_ASSETS_DIR . 'css/bootstrap.min.css', [], B_SLIDER_PLUGIN_VERSION);
            wp_register_style('b-slider-plyr-style', B_SLIDER_ASSETS_DIR . 'css/plyr.min.css', [], B_SLIDER_PLUGIN_VERSION);

            wp_register_script('bootstrap', B_SLIDER_ASSETS_DIR . 'js/bootstrap.min.js', [], B_SLIDER_PLUGIN_VERSION, true);
            wp_register_script('lazyLoad', B_SLIDER_ASSETS_DIR . 'js/lazyLoad.js', [], B_SLIDER_PLUGIN_VERSION, true);
            wp_register_script('b-slider-plyr-script', B_SLIDER_ASSETS_DIR . 'js/plyr.min.js', [], B_SLIDER_PLUGIN_VERSION, true);

             
        }

        // Short code style
        public function adminEnqueueScripts($hook){
            if ('edit.php' === $hook || 'post.php' === $hook) {
                wp_enqueue_style('b-slider-admin', B_SLIDER_ASSETS_DIR . 'css/admin.css', [], B_SLIDER_PLUGIN_VERSION);
                wp_enqueue_script('b-slider-admin', B_SLIDER_ASSETS_DIR . 'js/admin.js', ['wp-i18n'], B_SLIDER_PLUGIN_VERSION, true);
            }
        }

        public function onInit(){
            register_block_type( __DIR__ . '/build' );
        }
    }
    B_Slider::get_instance();


 