<?php
/**
 * Plugin Name: B Slider
 * Description: Simple slider with bootstrap.
 * Version: 1.1.25
 * Author: bPlugins
 * Author URI: http://bplugins.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: slider
 */
 
// ABS PATH
if (!defined('ABSPATH')) {exit;}

if (!function_exists('bsb_init')) {
    function bsb_init()
    {
        global $bsb_bs;
        require_once plugin_dir_path(__FILE__) . 'bplugins_sdk/init.php';
        $bsb_bs = new BPlugins_SDK(__FILE__);
    }
    bsb_init();
} else {
    $bsb_bs->uninstall_plugin(__FILE__);
}


class BSB_Slider{

    private static $instance;

    private function __construct(){

        global $bsb_bs;
        $this->define_constants();
        $this->load_classes();

        add_action('enqueue_block_assets', [$this, 'enqueueBlockAssets']);
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
        add_action('init', [$this, 'onInit']);
        // check premium 
        if(!$bsb_bs->can_use_premium_feature()){
            add_filter( 'plugin_action_links', [$this, 'plugin_action_links'], 10, 2 ); 
        }

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

    // define constant 
    public function define_constants () {
        define( 'BSB_PLUGIN_VERSION', isset( $_SERVER['HTTP_HOST'] ) && 'localhost' === $_SERVER['HTTP_HOST'] ? time() : '1.1.25' );
        define('BSB_DIR', plugin_dir_url(__FILE__));
        define('BSB_ASSETS_DIR', plugin_dir_url(__FILE__) . 'assets/');
    }

    //Class loaded
    public function load_classes () {
        global $bsb_bs;
        require_once plugin_dir_path(__FILE__) . '/custom-post.php';
        // check premium 
        if($bsb_bs->can_use_premium_feature()){
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
    public function insert_plugin_row_meta($links, $file)
    {
        if (plugin_basename( __FILE__ ) == $file) {
            // docs & faq
            $links[] = sprintf('<a href="https://bplugins.com/docs/b-slider/" target="_blank">' . __('Docs & FAQs', 'slider') . '</a>');

            // Demos
            $links[] = sprintf('<a href="https://bplugins.com/products/b-slider/#demos" target="_blank">' . __('Demos', 'slider') . '</a>');
        }

        return $links;
    }

    // Enqueue Block assets 
    public function enqueueBlockAssets()
    {
        wp_register_style('bsb-style', BSB_ASSETS_DIR . 'css/bootstrap.min.css', [], BSB_PLUGIN_VERSION);
        wp_register_script('bootstrap', BSB_ASSETS_DIR . 'js/bootstrap.min.js', [], BSB_PLUGIN_VERSION);
        wp_register_script('lazyLoad', BSB_ASSETS_DIR . 'js/lazyLoad.js', [], BSB_PLUGIN_VERSION);

        wp_register_script('bsb-slider-script', plugins_url('dist/script.js', __FILE__), ['react', 'react-dom', 'jquery', 'bootstrap', 'lazyLoad' ], BSB_PLUGIN_VERSION);

        wp_register_style('bsb-slider-style', plugins_url('dist/style.css', __FILE__), ['bsb-style'], BSB_PLUGIN_VERSION); // Style

        wp_localize_script('bsb-slider-editor-script', 'bsbInfo', [
            'patternsImagePath' => BSB_DIR . 'assets/images/patterns/',
        ]);
    }

    // Short code style
    public function adminEnqueueScripts($hook)
    {
        if ('edit.php' === $hook || 'post.php' === $hook) {
            wp_enqueue_style('bsbAdmin', BSB_ASSETS_DIR . 'css/admin.css', [], BSB_PLUGIN_VERSION);
            wp_enqueue_script('bsbAdmin', BSB_ASSETS_DIR . 'js/admin.js', ['wp-i18n'], BSB_PLUGIN_VERSION, true);
        }
    }

    public function onInit()
    {
        wp_register_style('bsb-slider-editor-style', plugins_url('dist/editor.css', __FILE__), ['bsb-slider-style'], BSB_PLUGIN_VERSION); // Backend Style

        register_block_type(__DIR__, [
            'editor_style' => 'bsb-slider-editor-style',
            'render_callback' => [$this, 'render'],
        ]); // Register Block

        wp_set_script_translations('bsb-slider-editor-script', 'slider', plugin_dir_path(__FILE__) . 'languages'); // Translate
    }

    public function render($attributes)
    {
        extract($attributes);

        $className = $className ?? '';
        $bsbBlockClassName = 'wp-block-bsb-slider ' . $className . ' align' . $align;

        wp_enqueue_style('bsb-slider-style');
        wp_enqueue_script('bsb-slider-script');
        ob_start();

        $sliders = [];

        foreach ($attributes['sliders'] as $index => $slider) {
            $sliders[] = $slider;
            $sliders[$index]['title'] = wp_kses_post($slider['title']);
            $sliders[$index]['desc'] = wp_kses_post($slider['desc']);
            $sliders[$index]['btnLabel'] = isset( $slider['btnLabel'] ) ? wp_kses_post( $slider['btnLabel'] ) : '';
        }

        $attributes['sliders'] = $sliders;

        ?>
		<div class='<?php echo esc_attr($bsbBlockClassName); ?>' id='bsbCarousel-<?php echo esc_attr($cId) ?>' data-attributes='<?php echo esc_attr(wp_json_encode($attributes)); ?>'></div>

		<?php return ob_get_clean();
    } // Render
}
BSB_Slider::get_instance();

