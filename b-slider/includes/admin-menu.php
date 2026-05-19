<?php
namespace B_SLIDER;

if (!defined('ABSPATH')) {exit;}
if(!class_exists(__NAMESPACE__ . '\AdminMenu')) {

    class AdminMenu {

        public function __construct() {
            add_action( 'admin_enqueue_scripts', [$this, 'adminEnqueueScripts'] );
            add_action( 'admin_menu', [$this, 'adminMenu'] );
        }

        public function adminEnqueueScripts($hook) {
            if( strpos( $hook, 'b-slider' ) ){
                wp_enqueue_style( 'bsb-admin-dashboard', B_SLIDER_DIR . 'build/admin-dashboard.css', [], B_SLIDER_PLUGIN_VERSION );
                wp_enqueue_script( 'bsb-admin-dashboard', B_SLIDER_DIR . 'build/admin-dashboard.js', [ 'react', 'react-dom', 'wp-data', "wp-api", "wp-util", "wp-i18n" ], B_SLIDER_PLUGIN_VERSION, true );
                wp_set_script_translations( 'bsb-admin-dashboard', 'b-slider', B_SLIDER_DIR_PATH . 'languages' ); 
            }
        }

        public function adminMenu(){
             
            add_submenu_page(
                'edit.php?post_type=bsb',
                __('Demo & Help', 'b-slider'),
                __('Demo & Help', 'b-slider'),
                'manage_options',
                'b-slider',
                [$this, 'bsbHelpPage']
            );
        }

        public function bsbHelpPage()
        {?>
            <div
                id='bsbDashboard'
                data-info='<?php echo esc_attr( wp_json_encode( [
                    'version' => B_SLIDER_PLUGIN_VERSION,
                ] ) ); ?>'
            >
            </div>
        <?php }
    }
    new AdminMenu();
}