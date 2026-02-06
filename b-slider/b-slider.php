<?php

/**
 * Plugin Name: bSlider
 * Description: Simple slider with bootstrap.
 * Version: 2.0.8
 * Author: bPlugins
 * Author URI: http://bplugins.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: slider
 * @fs_free_only, bsdk_config.json, /includes/admin-menu-free.php, /freemius-lite
 */
// ABS PATH
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( function_exists( 'bs_fs' ) ) {
    register_activation_hook( __FILE__, function () {
        if ( is_plugin_active( 'b-slider/b-slider.php' ) ) {
            deactivate_plugins( 'b-slider/b-slider.php' );
        }
        if ( is_plugin_active( 'b-slider-pro/b-slider.php' ) ) {
            deactivate_plugins( 'b-slider-pro/b-slider.php' );
        }
    } );
} else {
    define( 'BSB_PLUGIN_VERSION', ( isset( $_SERVER['HTTP_HOST'] ) && 'localhost' === $_SERVER['HTTP_HOST'] ? time() : '2.0.8' ) );
    define( 'BSB_DIR', plugin_dir_url( __FILE__ ) );
    define( 'BSB_DIR_PATH', plugin_dir_path( __FILE__ ) );
    define( 'BSB_ASSETS_DIR', plugin_dir_url( __FILE__ ) . 'assets/' );
    define( 'BSB_IS_FREE', 'b-slider/b-slider.php' === plugin_basename( __FILE__ ) );
    define( 'BSB_IS_PRO', file_exists( dirname( __FILE__ ) . '/freemius/start.php' ) );
    // Create a helper function for easy SDK access.
    function bs_fs() {
        global $bs_fs;
        if ( !isset( $bs_fs ) ) {
            // Include Freemius SDK.
            if ( BSB_IS_PRO ) {
                require_once dirname( __FILE__ ) . '/freemius/start.php';
            } else {
                require_once dirname( __FILE__ ) . '/freemius-lite/start.php';
            }
            $bsbConfig = array(
                'id'                  => '19318',
                'slug'                => 'b-slider',
                'premium_slug'        => 'b-slider-pro',
                'type'                => 'plugin',
                'public_key'          => 'pk_b24b0b3f21a9dbfaff418c0c40fc1',
                'is_premium'          => false,
                'premium_suffix'      => 'Pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'trial'               => array(
                    'days'               => 7,
                    'is_require_payment' => false,
                ),
                'menu'                => ( BSB_IS_PRO ? array(
                    'slug'    => 'b-slider-dashboard',
                    'support' => false,
                ) : array(
                    'slug'       => 'b-slider-dashboard',
                    'first-path' => 'tools.php?page=b-slider-dashboard#/pricing',
                    'support'    => false,
                    'parent'     => array(
                        'slug' => 'tools.php',
                    ),
                ) ),
            );
            $bs_fs = ( BSB_IS_PRO ? fs_dynamic_init( $bsbConfig ) : fs_lite_dynamic_init( $bsbConfig ) );
        }
        return $bs_fs;
    }

    // Init Freemius.
    bs_fs();
    // Signal that SDK was initiated.
    do_action( 'bs_fs_loaded' );
    require_once plugin_dir_path( __FILE__ ) . '/includes/Posts.php';
    require_once plugin_dir_path( __FILE__ ) . '/includes/PostsAjax.php';
    function bsbIsPremium() {
        return ( BSB_IS_PRO ? bs_fs()->can_use_premium_code() : false );
    }

    class BSB_Slider {
        private static $instance;

        private function __construct() {
            $this->load_classes();
            add_action( 'enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets'] );
            add_action( 'enqueue_block_assets', [$this, 'enqueueBlockAssets'] );
            add_action( 'admin_enqueue_scripts', [$this, 'adminEnqueueScripts'] );
            add_action( 'init', [$this, 'onInit'] );
            // check premium
            if ( BSB_IS_FREE ) {
                add_filter(
                    'plugin_action_links',
                    [$this, 'plugin_action_links'],
                    10,
                    2
                );
            }
            add_filter(
                'plugin_row_meta',
                array($this, 'insert_plugin_row_meta'),
                10,
                2
            );
        }

        // Check instance
        public static function get_instance() {
            if ( self::$instance ) {
                return self::$instance;
            }
            self::$instance = new self();
            return self::$instance;
        }

        //Class loaded
        public function load_classes() {
            // check premium
            if ( BSB_IS_PRO ) {
                require_once plugin_dir_path( __FILE__ ) . '/includes/admin-menu-pro.php';
            } else {
                require_once plugin_dir_path( __FILE__ ) . '/includes/admin-menu-free.php';
            }
            if ( BSB_IS_PRO && bsbIsPremium() ) {
                require_once plugin_dir_path( __FILE__ ) . '/custom-post.php';
                new BSB_SLIDER\LPBCustomPost();
            }
        }

        public function plugin_action_links( $links, $file ) {
            if ( plugin_basename( __FILE__ ) == $file ) {
                $links['go_pro'] = sprintf(
                    '<a href="%s" style="%s" target="__blank">%s</a>',
                    'https://bplugins.com/products/b-slider/pricing',
                    'color:#4527a4;font-weight:bold',
                    __( 'Go Pro!', 'slider' )
                );
            }
            return $links;
        }

        // Extending row meta
        public function insert_plugin_row_meta( $links, $file ) {
            if ( $file == 'b-slider/b-slider.php' ) {
                // docs & faq
                $links[] = sprintf( '<a href="https://bplugins.com/docs/b-slider/" target="_blank">' . __( 'Docs & FAQs', 'slider' ) . '</a>' );
                // Demos
                $links[] = sprintf( '<a href="https://bplugins.com/products/b-slider/#demos" target="_blank">' . __( 'Demos', 'slider' ) . '</a>' );
                if ( time() < strtotime( '2025-12-06' ) ) {
                    $links[] = "<a href='https://bplugins.com/coupons/?from=plugins.php&plugin=b-slider' target='_blank' style='font-weight: 600; color: #146ef5;'>🎉 Black Friday Sale - Get up to 40% OFF Now!</a>";
                }
            }
            return $links;
        }

        // Enqueue Block assets
        public function enqueueBlockAssets() {
            wp_register_style(
                'bsb-style',
                BSB_ASSETS_DIR . 'css/bootstrap.min.css',
                [],
                BSB_PLUGIN_VERSION
            );
            wp_register_style(
                'lbb-plyr-style',
                BSB_ASSETS_DIR . 'css/plyr.min.css',
                [],
                BSB_PLUGIN_VERSION
            );
            wp_register_script(
                'bootstrap',
                BSB_ASSETS_DIR . 'js/bootstrap.min.js',
                [],
                BSB_PLUGIN_VERSION
            );
            wp_register_script(
                'lazyLoad',
                BSB_ASSETS_DIR . 'js/lazyLoad.js',
                [],
                BSB_PLUGIN_VERSION
            );
            wp_register_script(
                'lbb-plyr-script',
                BSB_ASSETS_DIR . 'js/plyr.min.js',
                [],
                BSB_PLUGIN_VERSION
            );
            wp_localize_script( 'bsb-slider-editor-script', 'bsbInfo', [
                'patternsImagePath' => BSB_DIR . 'assets/images/patterns/',
            ] );
        }

        // Short code style
        public function adminEnqueueScripts( $hook ) {
            if ( 'edit.php' === $hook || 'post.php' === $hook ) {
                wp_enqueue_style(
                    'bsbAdmin',
                    BSB_ASSETS_DIR . 'css/admin.css',
                    [],
                    BSB_PLUGIN_VERSION
                );
                wp_enqueue_script(
                    'bsbAdmin',
                    BSB_ASSETS_DIR . 'js/admin.js',
                    ['wp-i18n'],
                    BSB_PLUGIN_VERSION,
                    true
                );
            }
        }

        public function enqueueBlockEditorAssets() {
            wp_add_inline_script( 'bsb-slider-editor-script', "const bsbpipecheck=" . wp_json_encode( bsbIsPremium() ) . ';', 'before' );
        }

        public function onInit() {
            register_block_type( __DIR__ . '/build' );
        }

    }

    BSB_Slider::get_instance();
}