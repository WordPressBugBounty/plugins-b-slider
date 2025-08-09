<?php
if (!defined('ABSPATH')) {exit;}
if(!class_exists('bsbAdminMenu')) {

    class bsbAdminMenu {

        public function __construct() {
            add_action( 'admin_enqueue_scripts', [$this, 'adminEnqueueScripts'] );
            add_action( 'admin_menu', [$this, 'adminMenu'] );

            add_action( 'wp_ajax_get_popular_plugins', [$this, 'get_popular_plugins'] );
            add_action( 'wp_ajax_get_active_plugins', [$this, 'get_active_plugins'] );
            add_action( 'admin_notices', [$this, 'display_activation_notice'] );
            add_action( 'wp_ajax_activated_plugin', [$this, 'activated_plugin'] );
        }

        public function adminEnqueueScripts($hook) {
            if ('toplevel_page_b-slider-dashboard' === $hook) {
                wp_enqueue_style( 'bsb-admin-style', BSB_DIR . 'build/admin.css', false, BSB_PLUGIN_VERSION );
                wp_enqueue_script( 'bsb-admin-script', BSB_DIR . 'build/admin.js', ['react', 'react-dom', 'wp-data', "wp-api", "wp-util", "wp-i18n"], BSB_PLUGIN_VERSION, true );

                wp_localize_script('bsb-admin-script', 'pluginAction', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wp_rest'),
                ]);
            }
        }

        public function adminMenu(){
            $menuIcon = "<svg xmlns='http://www.w3.org/2000/svg' width='24px' height='24px' id='bsbSlider' viewBox='0 0 24 24' fill='none' ><path d='M8.5 9.5L6 12L8.5 14.5' stroke='#fff' strokeWidth='1.5' strokeLinecap='round' strokeLinejoin='round' /><path d='M15.5 9.5L18 12L15.5 14.5' stroke='#fff' strokeWidth='1.5' strokeLinecap='round' strokeLinejoin='round' /><path d='M2 15V9C2 6.79086 3.79086 5 6 5H18C20.2091 5 22 6.79086 22 9V15C22 17.2091 20.2091 19 18 19H6C3.79086 19 2 17.2091 2 15Z' stroke='#fff' strokeWidth='1.5' /></svg>";

            add_menu_page(
                __('B Slider', 'slider'),
                __('B Slider', 'slider'),
                'manage_options',
                'b-slider-dashboard',
                [$this, 'bsbHelpPage'],
                'data:image/svg+xml;base64,' . base64_encode($menuIcon),
                6
            );

            if(  BSB_IS_PRO && bsbIsPremium() ){
                add_submenu_page(
                    'b-slider-dashboard',
                    __('ShortCode', 'slider'),
                    __('ShortCode', 'slider'),
                    'manage_options',
                    'edit.php?post_type=bsb'
                );   
            }
        }

        public function bsbHelpPage(){
            ?>
                <div id='bsbAdminHelpPage' class="bpluginsDashboad"></div>
            <?php 
        }

        public function get_popular_plugins () {

            if (!function_exists('plugins_api')) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }
             
            $cached_plugins = plugins_api('query_plugins', array(
                    'author' => 'bplugins',
                    'per_page' => 100
                ));

             wp_send_json_success($cached_plugins->plugins); 
        }

        public function get_active_plugins() {
            if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field($_GET['nonce']), 'wp_rest')) {
                wp_send_json_error(['message' => 'Invalid nonce or request.'], 400);
            }
        
            // Get the list of all installed plugins
            if (!function_exists('get_plugins')) {
                include_once ABSPATH . '/wp-admin/includes/plugin.php';
            }
        
            $installed_plugins = get_plugins();
        
            // Return the plugin basenames as an array
            $installed_plugin_slugs = array_keys($installed_plugins);
        
            wp_send_json_success($installed_plugin_slugs);
        }

        public function display_activation_notice() {
            // Check if transient is set
            $plugin_slug = get_transient('bblocks_show_activation_notice');

            $first_part = explode("/", $plugin_slug)[0];
            $cleaned_string = str_replace("-", " ", $first_part);
             

            if ($plugin_slug) {
                // Remove transient after displaying the notice
                delete_transient('bblocks_show_activation_notice');
        
                // Generate activation URL
                $activation_url = wp_nonce_url(
                    admin_url('plugins.php?action=activate&plugin=' . $plugin_slug),
                    'activate-plugin_' . $plugin_slug
                );
        
                // Display notice with activation button
                ?>
                <div class="notice notice-success is-dismissible bblocks-notice">
                    <p><?php esc_html_e(" $cleaned_string plugin was successfully installed.", 'bblocks-admin-bar'); ?></p>
                    <p>
                        <a href="<?php echo esc_url($activation_url); ?>" class="button button-primary">
                            <?php esc_html_e('Activate Plugin', 'bblocks-admin-bar'); ?>
                        </a>
                    </p>
                </div>
                <?php
            }
        }

        public function activated_plugin() {

            if ( ! current_user_can( 'activate_plugins' ) ) {
                wp_send_json_error( [ 'message' => 'You are not allowed to perform this action.' ], 403 );
            }

            // Verify nonce
            if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field($_GET['nonce']), 'wp_rest')) {
                wp_send_json_error(['message' => 'Invalid nonce or request.'], 400);
            }

            $plugin_name = sanitize_text_field($_GET['plugin_name']) ?? false;
        
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        
            try {
                // Fetch plugin information
                $api = plugins_api('plugin_information', ['slug' => $plugin_name, 'fields' => ['sections' => false]]);
                if (is_wp_error($api)) {
                    wp_send_json_error(['message' => 'Failed to fetch plugin information.']);
                }
        
                // Suppress unexpected output
                ob_start();
                $upgrader = new Plugin_Upgrader();
                $result = $upgrader->install($api->download_link);
                ob_end_clean();
            
                $plugin_slug = $plugin_name.'/'.$plugin_name.'.php';

                if ($result) {
                    // Set transient to show notice
                    set_transient('bblocks_show_activation_notice', $plugin_slug, 1000000); // Valid for 60 seconds
                    $redirect_url = admin_url('plugins.php?plugin_status=all');
                    wp_send_json_success(['message' => 'Plugin installed successfully.', 'redirectUrl' => $redirect_url]);

                } else {
                    wp_send_json_error(['message' => 'Plugin installation failed.']);
                }
            } catch (Exception $e) {
                wp_send_json_error(['message' => 'An unexpected error occurred: ' . $e->getMessage()]);
            }
        }
    }
    new bsbAdminMenu();
}