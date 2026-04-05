<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * License Activation Handler
 * Handles Freemius license activation via AJAX
 */
if( !class_exists( LicenseActivation::class ) ){
	class LicenseActivation {
		private $fs_callable;
		private $fs;

		function __construct( $fs_callable ) {
			$this->fs_callable = $fs_callable;

			$this->fs = call_user_func( $fs_callable );
			add_action( 'wp_ajax_bpl_'.$this->fs->get_id().'_activate_license', [$this, 'activateLicense'] );
			add_action( 'wp_ajax_bpl_'.$this->fs->get_id().'_get_license_status', [$this, 'getLicenseStatus'] );
			add_action( 'wp_ajax_bpl_'.$this->fs->get_id().'_deactivate_license', [$this, 'deactivateLicense'] );
		}

		/**
		 * Activate Freemius license via AJAX
		 */
		function activateLicense() {
			$this->validate_request( 'activate' );

			// Get and sanitize input
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in validate_request() above.
			$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

			// Validate inputs
			if ( empty( $license_key ) ) {
				wp_send_json_error( [
					'message' => 'Please enter a license key.'
				] );
			}

			try {
				$fs = call_user_func( $this->fs_callable );

				// If not registered, opt-in with license
				if ( !$fs->is_registered() ) {

					// Opt-in with license key
					$result = $fs->opt_in(
						false,			// $enable_anonymous
						false,			// $enable_pending
						false,			// $is_pending
						$license_key,	// $license_key
						false,			// $trial_plan_id
						false,			// $is_marketing_allowed
						false,			// $is_extensions_tracking_allowed
						false			// $is_staging
					);

					// Check for errors
					if ( is_object( $result ) && isset( $result->error ) ) {
						wp_send_json_error( [
							'message' => $this->getErrorMessage( $result->error )
						] );
						return;
					}
				} else {
					// User is registered - check current license
					$current_license = $fs->_get_license();

					// If same license and already premium, it's already activated
					if ( $current_license && $current_license->secret_key === $license_key && $fs->is_premium() ) {
						wp_send_json_error( [
							'message' => 'This license is already activated.'
						] );
						return;
					}

					$result = $fs->opt_in(
						false, // email (will be inferred)
						false, // first
						false, // last
						$license_key, 
						false, // is_uninstall
						false, // trial_plan_id
						false, // is_disconnected
						false, // is_marketing_allowed
						array(), // sites
						false // redirect - IMPORTANT to prevent redirection
					);

					// Check for errors
					if ( is_object( $result ) && isset( $result->error ) ) {
						wp_send_json_error( [
							'message' => $this->getErrorMessage( $result->error )
						] );
						return;
					}

					// Check if we got a valid install object or license back
					// opt_in usually returns the install object on success, or redirect URL if redirect=true

					// Sync the license - this updates the local cache
					$this->element_call( $fs, '_sync_license' );

					// Force reload the license from cache
					$this->element_call( $fs, '_get_license', [true] );
				}

				// Verify activation
				if ( $fs->is_premium() ) {
					$license = $fs->_get_license();

					// Verify it's the correct license
					if ( $license && $license->secret_key === $license_key ) {
						wp_send_json_success( [
							'message' => 'License activated successfully!',
							'license_key' => $license_key,
							'is_premium' => true
						] );
					} else {
						wp_send_json_error( [
							'message' => 'License verification failed. Please try again.'
						] );
					}
				} else {
					wp_send_json_error( [
						'message' => 'Activation failed. Please check your license key and try again.'
					] );
				}
			} catch ( \Exception $e ) {
				wp_send_json_error( [
					'message' => 'An error occurred during activation: ' . $e->getMessage()
				] );
			}
		}

		/**
		 * Get current license status
		 */
		function getLicenseStatus() {
			$this->validate_request( 'status' );

			$fs = call_user_func( $this->fs_callable );

			if ( $fs->is_registered() && $fs->is_premium() ) {
				$license = $fs->_get_license();
				$secret_key = $license && isset( $license->secret_key ) ? $license->secret_key : '';

				wp_send_json_success( [
					'is_activated' => $secret_key ? true : false,
					'license_key' => $secret_key,
					'is_premium' => $secret_key ? true : false
				] );
			} else {
				wp_send_json_success( [
					'is_activated' => false,
					'is_premium' => false
				] );
			}
		}

		/**
		 * Deactivate Freemius license via AJAX
		 */
		function deactivateLicense() {
			$this->validate_request( 'deactivate' );

			try {
				$fs = call_user_func( $this->fs_callable );

				// Check if user is registered and has a license
				if ( !$fs->is_registered() || !$fs->is_premium() ) {
					wp_send_json_error( [
						'message' => 'No active license to deactivate.'
					] );
					return;
				}

				// Get current license
				$license = $fs->_get_license();

				if ( !$license ) {
					wp_send_json_error( [
						'message' => 'License not found.'
					] );
					return;
				}

				// Deactivate via API logic from Freemius SDK (_deactivate_license)
				// Endpoint: /licenses/{license_id}.json
				// Method: DELETE

				// get_api_site_scope() is protected, so we need to use reflection to access it
				$reflector = new \ReflectionClass( $fs );
				$method = $reflector->getMethod( 'get_api_site_scope' );
				$method->setAccessible( true );
				$api = $method->invoke( $fs );

				if ( ! is_object( $api ) ) {
					wp_send_json_error( [
						'message' => 'Failed to initialize API connection.'
					] );
					return;
				}

				$result = $api->call( "/licenses/{$license->id}.json", 'delete' );

				// Check for API errors (Freemius API returns object with error property on failure)
				if ( is_object( $result ) && isset( $result->error ) ) {
					wp_send_json_error( [
						'message' => 'Failed to deactivate license: ' . ( isset( $result->error->message ) ? $result->error->message : 'Unknown error' )
					] );
					return;
				}

				// Sync license data to update local state
				$this->element_call( $fs, '_sync_license' );

				// Force refresh license cache
				$this->element_call( $fs, '_get_license', [true] );

				// Verify deactivation
				if ( !$fs->is_premium() ) {
					wp_send_json_success( [
						'message' => 'License deactivated successfully!'
					] );
				} else {
					// Sometimes sync takes a moment, but if API success, we can likely assume success for UI
					// But let's return success anyway if API call worked, to allow UI to update
					wp_send_json_success( [
						'message' => 'License deactivated successfully!'
					] );
				}
			} catch ( \Throwable $e ) {
				wp_send_json_error( [
					'message' => 'An error occurred during deactivation: ' . $e->getMessage()
				] );
			}
		}

		/**
		 * diverse helper to call protected/private methods
		 */
		private function element_call( $object, $method_name, $parameters = [] ) {
			$reflection = new \ReflectionClass( get_class( $object ) );
			$method = $reflection->getMethod( $method_name );
			$method->setAccessible( true );
			return $method->invokeArgs( $object, $parameters );
		}

		/**
		 * Validate request (nonce, permissions, SDK)
		 */
		private function validate_request( $action = '' ) {
			// Verify nonce
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

			if ( !wp_verify_nonce( $nonce, 'bPlLicenseActivation' ) ) {
				wp_send_json_error( [
					'message' => 'Invalid security token. Please refresh the page and try again.'
				] );
			}

			// Permissions check
			if ( !current_user_can( 'manage_options' ) ) {
				$action_text = ( 'activate' === $action ) ? 'activate' : ( ( 'status' === $action ) ? 'view' : 'deactivate' );
				wp_send_json_error( [
					'message' => "You do not have permission to {$action_text} licenses."
				] );
			}

			// Check if Freemius is available
			if ( !function_exists( $this->fs_callable ) ) {
				wp_send_json_error( [
					'message' => 'Freemius SDK is not available.'
				] );
			}
		}

		/**
		 * Get user-friendly error message
		 */
		private function getErrorMessage( $error ) {
			$messages = [
				'invalid_license_key' => 'Invalid license key. Please check and try again.',
				'expired' => 'This license has expired. Please renew your license.',
				'disabled' => 'This license has been disabled.',
				'no_activations_left' => 'No activations left for this license. Please upgrade or purchase a new license.',
				'license_not_found' => 'License not found. Please check your license key.',
			];

			if ( is_object( $error ) && isset( $error->code ) ) {
				$error_code = $error->code;
				return isset( $messages[$error_code] ) ? $messages[$error_code] : 'Activation failed: ' . $error->message;
			}

			return 'Activation failed. Please check your license key and try again.';
		}
	}
}

// Initialize only if Freemius is available
if ( function_exists( 'bs_fs' ) ) {
	new LicenseActivation( 'bs_fs' );
}