<?php

namespace B_SLIDER\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Image {
	const MAX_IMAGES = 30;

	private $already_imported_ids = array();

	private static $instance = null;

	public function __construct() {
	}

	public function maybe_import_images( $content ) {
		preg_match_all( '#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $content, $match );

		$all_links = array_unique( $match[0] );

		if ( empty( $all_links ) ) {
			return $content;
		}

		$link_mapping	= array();
		$image_links	= array();
		$other_links	= array();

		foreach ( $all_links as $key => $link ) {
			if ( $this->is_valid_image( $link ) ) {

				if (
					false === strpos( $link, '-150x' ) &&
					false === strpos( $link, '-300x' ) &&
					false === strpos( $link, '-1024x' )
				) {
					$image_links[] = $link;
				}
			} else {
				$other_links[] = $link;
			}
		}

		if ( ! empty( $image_links ) ) {
			foreach ( array_slice( $image_links, 0, self::MAX_IMAGES ) as $key => $image_url ) {
				$image = array(
					'url'	=> $image_url,
					'id'	=> 0,
				);
				$downloaded_image = $this->import( $image );

				$link_mapping[ $image_url ] = $downloaded_image['url'];
			}
		}

		foreach ( $link_mapping as $old_url => $new_url ) {
			$old_url = (string) $old_url;
			$content = str_replace( $old_url, $new_url, $content );

			$old_url = str_replace( '/', '/\\', $old_url );
			$new_url = str_replace( '/', '/\\', $new_url );
			$content = str_replace( $old_url, $new_url, $content );
		}
		return $content;
	}

	private function get_saved_image( $attachment ) {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runs once per image on import; no core API finds an attachment by this meta.
		$post_id = $wpdb->get_var( $wpdb->prepare( 'SELECT `post_id` FROM `' . $wpdb->postmeta . '` WHERE `meta_key` = \'_bsb_templates_image_hash\' AND `meta_value` = %s;', $this->get_hash_image( $attachment['url'] ) ) );

		if ( empty( $post_id ) ) {
			$filename = basename( (string) wp_parse_url( $attachment['url'], PHP_URL_PATH ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runs once per image on import; no core API finds an attachment by file name.
			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", '%/' . $wpdb->esc_like( $filename ) . '%' ) );
		}

		if ( $post_id ) {
			$new_attachment = array(
				'id'	=> $post_id,
				'url'	=> wp_get_attachment_url( $post_id ),
			);
			$this->already_imported_ids[] = $post_id;

			return array(
				'status'		=> true,
				'attachment'	=> $new_attachment,
			);
		}

		return array(
			'status'		=> false,
			'attachment'	=> $attachment,
		);
	}

	public function import( $attachment ) {

		$saved_image = $this->get_saved_image( $attachment );

		if ( $saved_image['status'] ) {
			return $saved_image['attachment'];
		}

		$filename = sanitize_file_name( basename( (string) wp_parse_url( $attachment['url'], PHP_URL_PATH ) ) );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $attachment['url'], 30 );

		if ( is_wp_error( $tmp ) ) {
			return $attachment;
		}

		$post_id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			0
		);

		if ( is_wp_error( $post_id ) ) {
			wp_delete_file( $tmp );
			return $attachment;
		}

		update_post_meta( $post_id, '_bsb_templates_image_hash', $this->get_hash_image( $attachment['url'] ) );

		$new_attachment = array(
			'id'	=> $post_id,
			'url'	=> wp_get_attachment_url( $post_id ),
		);

		$this->already_imported_ids[] = $post_id;

		return $new_attachment;
	}

	public function get_hash_image( $attachment_url ) {
		return sha1( $attachment_url );
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function is_valid_image( $link = '' ) {
		$host = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $link, PHP_URL_PATH );

		if ( 'bplugins.com' !== $host && '.bplugins.com' !== substr( $host, -13 ) ) {
			return false;
		}

		return (bool) preg_match( '/\.(jpe?g|png|gif)$/i', $path );
	}
}
new Image();
