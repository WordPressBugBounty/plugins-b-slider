<?php

$this_sdk_version = '2.1.3';
if ( !class_exists( 'BPluginsFSLite' ) ) {
    require_once dirname( __FILE__ ) . '/require.php';
    class BPluginsFSLite {
        protected $file = null;

        public $prefix = '';

        protected $config = null;

        protected $__FILE__ = __FILE__;

        private $lc = null;

        function __construct( $config = [] ) {
            $this->__FILE__ = $config['__FILE__'];
            $this->config = (object) $config;
            $this->prefix = $this->config->prefix ?? $this->config->slug;
            if ( \is_admin() ) {
                new FSActivate($this->config, $this->__FILE__);
            }
        }

        public function can_use_premium_feature() {
            return $this->is_premium();
        }

        public function is_premium() {
            return $this->lc->isPipe ?? false;
        }

        public function uninstall_plugin() {
            deactivate_plugins( plugin_basename( $this->__FILE__ ) );
        }

        function can_use_premium_code() {
            return $this->is_premium();
        }

        function set_basename( $is_premium, $__FILE__ ) {
            $basename = basename( $__FILE__ );
            if ( is_plugin_active( $this->config->slug . '/' . $basename ) ) {
                deactivate_plugins( $this->config->slug . '/' . $basename );
            }
            if ( is_plugin_active( $this->config->slug . '-pro/' . $basename ) ) {
                deactivate_plugins( $this->config->slug . '-pro/' . $basename );
            }
        }

    }

}
if ( !function_exists( 'fs_lite_dynamic_init' ) ) {
    function fs_lite_dynamic_init(  $module  ) {
        try {
            if ( function_exists( 'fs_dynamic_init' ) ) {
                return fs_dynamic_init( $module );
            }
            $caller = debug_backtrace();
            if ( isset( $caller[0]['file'] ) ) {
                $module['__FILE__'] = $caller[0]['file'];
            }
            if ( !isset( $module['__FILE__'] ) ) {
                throw new Error("No __FILE__");
            }
            $fs = new BPluginsFSLite($module);
            return $fs;
        } catch ( \Throwable $th ) {
            throw $th->getMessage();
        }
    }

}