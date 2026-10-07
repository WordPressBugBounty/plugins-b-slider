<?php
/**
 * Plugin Name: bSlider – Build Sliders That Bring Your Content to Life
 * Plugin URI: http://bplugins.com
 * Description: Simple slider with bootstrap.
 * Version: 2.2.2
 * Author: bPlugins
 * Author URI: http://bplugins.com
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: b-slider
 */

    if (!defined('ABSPATH')) {exit;}

    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        define('B_SLIDER_PLUGIN_VERSION', time());
    } else {
        define('B_SLIDER_PLUGIN_VERSION', '2.2.2');
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
                        'first-path'     => 'edit.php?post_type=bsb&page=b-slider#/welcome',
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
    require_once plugin_dir_path(__FILE__) . '/includes/AcfFields.php';

    // The editor's Template Library modal: AJAX proxy to templates.bplugins.com.
    require_once plugin_dir_path(__FILE__) . '/includes/Templates/Templates.php';

    class B_Slider{

        private static $instance;

        private function __construct(){

            $this->load_classes();
            add_action('enqueue_block_assets', [$this, 'enqueueBlockAssets']);
            add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
            add_action('enqueue_block_editor_assets', [$this, 'enqueueTemplateLibrary']);
            add_action('init', [$this, 'onInit']);
            add_filter( 'plugin_action_links', [$this, 'plugin_action_links'], 10, 2 );
            add_filter('plugin_row_meta', array($this, 'insert_plugin_row_meta'), 10, 2);
            add_filter( 'register_block_type_args', [$this, 'registerBlockTypeArgs'], 10, 2 );
        }

        public static function get_instance() {
            if ( self::$instance ){
                return self::$instance;
            }

            self::$instance = new self();
            return self::$instance;
        }

        public function load_classes () {
            require_once plugin_dir_path(__FILE__) . '/includes/admin-menu.php';
            require_once plugin_dir_path(__FILE__) . '/includes/custom-post.php';
            new B_SLIDER\CustomPost();
        }

        public function plugin_action_links($links, $file) {
            
            if( plugin_basename( __FILE__ ) == $file ) {

                $dashboardLink = admin_url( 'edit.php?post_type=bsb&page=b-slider' );

                 
                $links['go_pro'] = sprintf( '<a href="%s" style="%s" target="__blank">%s</a>', 'https://bplugins.com/products/b-slider/pricing', 'color:#f18500;font-weight:bold', __( 'Go Pro!', 'b-slider' ) );
            

                $links['dashboard'] = sprintf( '<a href="%s" style="%s" target="__blank">%s</a>', $dashboardLink, 'color:#f18500;font-weight:bold', __( 'Dashboard!', 'b-slider' ) );
            }
 
            return $links;
        }

        public function insert_plugin_row_meta($links, $file){

            $demosLine = admin_url( 'edit.php?post_type=bsb&page=b-slider#/demos' );

            if ($file == 'b-slider/b-slider.php') {
                $links[] = sprintf('<a href="https://bplugins.com/docs/b-slider/" target="_blank">' . __('Docs & FAQs', 'b-slider') . '</a>');

                $links[] = sprintf('<a href="%s" target="_blank">' . __('Demos', 'b-slider') . '</a>', $demosLine);
            }
            return $links;
        }

        public function enqueueBlockAssets(){
            wp_register_style('bootstrap', B_SLIDER_ASSETS_DIR . 'css/bootstrap.min.css', [], B_SLIDER_PLUGIN_VERSION);
            wp_register_style('b-slider-plyr-style', B_SLIDER_ASSETS_DIR . 'css/plyr.min.css', [], B_SLIDER_PLUGIN_VERSION);

            wp_register_script('bootstrap', B_SLIDER_ASSETS_DIR . 'js/bootstrap.min.js', [], B_SLIDER_PLUGIN_VERSION, true);
            wp_register_script('lazyLoad', B_SLIDER_ASSETS_DIR . 'js/lazyLoad.js', [], B_SLIDER_PLUGIN_VERSION, true);
            wp_register_script('b-slider-plyr-script', B_SLIDER_ASSETS_DIR . 'js/plyr.min.js', [], B_SLIDER_PLUGIN_VERSION, true);
 
             
        }

        public function enqueueTemplateLibrary() {
            if ( ! current_user_can( 'edit_posts' ) ) {
                return;
            }

            $assetFile = B_SLIDER_DIR_PATH . 'build/template-library.asset.php';

            if ( ! file_exists( $assetFile ) ) {
                return;
            }

            $asset = require $assetFile;

            // In the header, not the footer — the same as the reference implementation. The block
            // editor prints its own scripts before the footer of the admin page is reached, and a
            // footer-bound script enqueued from `enqueue_block_editor_assets` can miss that pass
            // entirely: WordPress goes on reporting it as enqueued while the tag is never printed.
            wp_enqueue_script(
                'bsb-template-library',
                B_SLIDER_DIR . 'build/template-library.js',
                array_merge( $asset['dependencies'], [ 'wp-util' ] ),
                $asset['version'],
                false
            );

            wp_enqueue_style(
                'bsb-template-library',
                B_SLIDER_DIR . 'build/template-library.css',
                [],
                $asset['version']
            );

            wp_set_script_translations( 'bsb-template-library', 'b-slider', B_SLIDER_DIR_PATH . 'languages' );

            // Only the nonce. The free plugin declares no `bsbpipecheck`, and `TemplateLibrary.js` reads
            // it through `typeof`, so Pro templates show as locked here. Pro declares it on `wp-blocks`;
            // declaring it here too would be a second `const` of the same name in global scope.
            wp_add_inline_script(
                'bsb-template-library',
                'const bsbtemplatenonce = "' . esc_js( wp_create_nonce( 'bsb_template' ) ) . '";',
                'before'
            );
        }

        public function adminEnqueueScripts($hook){
            if ('edit.php' === $hook || 'post.php' === $hook) {
                wp_enqueue_style('b-slider-admin', B_SLIDER_ASSETS_DIR . 'css/admin.css', [], B_SLIDER_PLUGIN_VERSION);
                wp_enqueue_script('b-slider-admin', B_SLIDER_ASSETS_DIR . 'js/admin.js', ['wp-i18n'], B_SLIDER_PLUGIN_VERSION, true);
            }
        }

        public function registerBlockTypeArgs( $args, $name ) {
            $incapable = [ 'core/shortcode', 'core/html', 'core/freeform', 'core/missing' ];
            if ( in_array( $name, $incapable, true ) ) {
                return $args;
            }

            if ( ! isset( $args['attributes'] ) ) {
                $args['attributes'] = [];
            }
            $args['attributes']['bsbLayer'] = [
                'type'    => 'object',
                'default' => [],
            ];
            return $args;
        }

        public function onInit(){
            register_block_type( __DIR__ . '/build' );
            register_block_type( __DIR__ . '/build/Blocks/Slide' );
        }
    }
    B_Slider::get_instance();


 