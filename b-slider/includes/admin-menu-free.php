<?php
if (!defined('ABSPATH')) {exit;}
if(!class_exists('bsbAdminMenu')) {

    class bsbAdminMenu {

        public function __construct() {
            add_action( 'admin_enqueue_scripts', [$this, 'adminEnqueueScripts'] );
            add_action( 'admin_menu', [$this, 'adminMenu'] );
        }

        public function adminEnqueueScripts($hook) {
            if( strpos( $hook, 'b-slider-dashboard' ) ){
                wp_enqueue_style( 'bsb-admin-dashboard', BSB_DIR . 'build/admin-dashboard.css', [], BSB_PLUGIN_VERSION );
                wp_enqueue_script( 'bsb-admin-dashboard', BSB_DIR . 'build/admin-dashboard.js', [ 'react', 'react-dom',], BSB_PLUGIN_VERSION, true );
                wp_set_script_translations( 'bsb-admin-dashboard', 'slider', BSB_DIR_PATH . 'languages' );   
            }
        }

        public function adminMenu(){

            add_submenu_page(
                'tools.php',
                __('bSlider', 'slider'),
                __('bSlider', 'slider'),
                'manage_options',
                'b-slider-dashboard',
                [$this, 'bsbHelpPage']
            ); 
        }

        public function bsbHelpPage()
        {?>
            <div
                id='bsbDashboard'
                data-info='<?php echo esc_attr( wp_json_encode( [
                    'version' => BSB_PLUGIN_VERSION,
                    'isPremium' => bsbIsPremium(),
                    'hasPro' => BSB_IS_PRO
                ] ) ); ?>'
            >
            </div>
        <?php } 
    }
    new bsbAdminMenu();
}