<?php

if (!class_exists('Freemius_Lite')) {
    class Freemius_Lite
    {
        private const SDK_VERSION = '2.5.12';
        private const API_ENDPOINT = 'https://api.bplugins.com/wp-json/freemius/v1/middleware/';

        private $api = null;
        private $_scope = null;
        private $headers = [];
        private $base_endpoint;

        public function __construct($scope = null, $id = null, $public_key = null, $secret_key = null)
        {
            $this->base_endpoint = self::API_ENDPOINT;
            $this->api_endpoint = $this->base_endpoint . time();
            if ($scope && $id && $public_key) {
                $this->headers = $this->generate_authorization_header('', $scope, $id, $public_key, $secret_key);
            }
        }

        public function get_site()
        {
            $result = $this->FS_Api(sprintf(
                '?sdk_version=%s&fields=site_id,plugin_id,user_id,title,url,version,language,platform_version,sdk_version,programming_language_version,plan_id,license_id,trial_plan_id,trial_ends,is_premium,is_disconnected,is_active,is_uninstalled,is_beta,public_key,secret_key,id,updated,created,_is_updated',
                self::SDK_VERSION
            ));

            return $result->success ? $result->data : false;
        }

        public function plugin_deactivated($path, $uid)
        {
            return $this->update_plugin_status($path, $uid, false);
        }

        public function plugin_uninstall($path, $uid)
        {
            return $this->update_plugin_status($path, $uid, false, true);
        }

        private function update_plugin_status($path, $uid, $is_active = false, $is_uninstalled = false)
        {
            $data = ['is_active' => $is_active, 'uid' => $uid];

            if ($is_uninstalled) {
                $data['is_uninstalled'] = true;
            }

            return $this->FS_Api($path, 'PUT', wp_json_encode($data));
        }

        public function plugin_activated($path, $uid, $version)
        {
            global $wp_version;
            $data = [
                'sdk_version' => self::SDK_VERSION,
                'platform_version' => $wp_version,
                'programming_language_version' => phpversion(),
                'url' => site_url(),
                'language' => 'en-US',
                'title' => get_bloginfo('name'),
                'version' => $version,
                'is_premium' => false,
                'is_active' => true,
                'is_uninstalled' => false,
                'uid' => $uid,
            ];

            return $this->FS_Api($path, 'PUT', wp_json_encode($data));
        }

        public function Api($method = 'GET', $params = [], $headers = [])
        {
            try {
                $response = wp_remote_request($this->api_endpoint, [
                    'method' => $method,
                    'headers' => $headers,
                    'body' => $params,
                    'timeout' => 30,
                    'sslverify' => true
                ]);

                if (is_wp_error($response)) {
                    throw new Exception($response->get_error_message());
                }

                return json_decode(wp_remote_retrieve_body($response));
            } catch (Exception $e) {
                return (object)['success' => false, 'error' => $e->getMessage()];
            }
        }

        public function FS_Api($path = '', $method = 'GET', $params = [])
        {
            $this->headers['path'] = $path;
            return $this->Api($method, $params, $this->headers);
        }

        public function permission_update($fs_accounts, $config, $params = null)
        {
            if (!$this->validate_site_data($fs_accounts, $config, $params)) {
                return $this->handle_anonymous_site($fs_accounts, $config);
            }

            $site = (object)$fs_accounts['sites'][$config->slug];
            $headers = $this->generate_authorization_header(
                sprintf('/permissions.json?sdk_version=%s&url=%s', self::SDK_VERSION, site_url()),
                'install',
                $site->install_id,
                $site->public_key,
                $site->secret_key
            );

            $result = $this->_permission_update($params, $headers);
            return $this->process_permission_result($result, $fs_accounts, $config);
        }

        private function validate_site_data($fs_accounts, $config, $params)
        {
            $site = isset($fs_accounts['sites'][$config->slug]) ? (object)$fs_accounts['sites'][$config->slug] : null;
            return $site && is_object($site) && isset($site->public_key) && $params &&
                $site->public_key && $site->secret_key && $site->install_id;
        }

        private function handle_anonymous_site($fs_accounts, $config)
        {
            $fs_accounts['plugin_data'][$config->slug]['is_anonymous'] = [
                'is' => true,
                'timestamp' => time()
            ];
            update_option('fs_lite_accounts', $fs_accounts);
            return false;
        }

        private function process_permission_result($result, $fs_accounts, $config)
        {
            if ($this->has_error($result)) {
                return [
                    'success' => false,
                    'message' => $result->data->message ?? 'Unknown error occurred'
                ];
            }

            if (isset($result->data->permissions)) {
                return $this->update_permissions($result->data->permissions, $fs_accounts, $config);
            }

            return false;
        }

        private function has_error($result)
        {
            return isset($result->data->error) ||
                (isset($result->data->code) && in_array($result->data->code, ['rest_invalid_json', 'unauthorized_access']));
        }

        private function update_permissions($permissions, $fs_accounts, $config)
        {
            $fs_accounts['plugin_data'][$config->slug] = [
                'is_user_tracking_allowed' => $permissions->user,
                'is_site_tracking_allowed' => $permissions->site,
                'is_events_tracking_allowed' => $permissions->site,
                'is_extensions_tracking_allowed' => $permissions->extensions
            ];

            update_option('fs_lite_accounts', $fs_accounts);
            return ['success' => true, 'data' => $fs_accounts];
        }

        private function _permission_update($params, $headers)
        {
            return $this->Api('PUT', wp_json_encode($params), $headers);
        }

        private function generate_authorization_header($path, $scope, $id, $public_key, $secret_key)
        {
            return [
                'path' => $path,
                'scope' => $scope,
                'id' => $id,
                'public' => $public_key,
                'secret' => $secret_key,
                'Content-Type' => 'application/json'
            ];
        }
    }
}
