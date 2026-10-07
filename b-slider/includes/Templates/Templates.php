<?php

namespace B_SLIDER\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once B_SLIDER_DIR_PATH . '/includes/Templates/Image.php';

class Templates {

	const PLUGIN_SLUG = 'b-slider';

	const API = 'https://templates.bplugins.com/wp-json/gutenberg-templates/v1';

	const TIMEOUT = 8;

	public function __construct() {
		add_action( 'wp_ajax_bsb_templates_main', [ $this, 'templatesMain' ] );
		add_action( 'wp_ajax_bsb_templates', [ $this, 'templates' ] );
		add_action( 'wp_ajax_bsb_template_import', [ $this, 'templateImport' ] );
		add_action( 'wp_ajax_bsb_template_counts', [ $this, 'templateCounts' ] );
		add_action( 'wp_ajax_bsb_template_favorites', [ $this, 'templateFavorites' ] );
	}

	private static function guard( $verified ) {
		if ( ! $verified ) {
			wp_send_json_error( 'Invalid Request' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient Permissions' );
		}
	}

	private static function type( $type ) {
		return 'pages' === $type ? 'pages' : 'patterns';
	}

	public function templatesMain() {
		self::guard( check_ajax_referer( 'bsb_template', '_wpnonce', false ) );

		$type       = self::type( sanitize_text_field( wp_unslash( $_POST['type'] ?? 'patterns' ) ) );
		$typeFilter = 'patterns' === $type ? 'patterns-category' : 'pages-category';

		$response = wp_remote_get( self::API . "/taxonomy/taxonomies/plugin,type,{$typeFilter}", [ 'timeout' => self::TIMEOUT ] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $body && isset( $body->{$typeFilter} ) ) {
			$mine = $this->pluginCategories( $type );

			$body->{$typeFilter} = array_values( array_filter( $body->{$typeFilter}, function ( $cat ) use ( $mine ) {
				return in_array( $cat->name, $mine, true );
			} ) );
		}

		wp_send_json_success( $body );
	}

	private function pluginCategories( $type ) {
		$plugin   = self::PLUGIN_SLUG;
		$response = wp_remote_get(
			self::API . "/blocks?type={$type}&start=0&end=1&plugin={$plugin}&fields=category&limit=1000&end=10000",
			[ 'timeout' => self::TIMEOUT ]
		);

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! $body || ! isset( $body->patterns ) ) {
			return [];
		}

		$categories = [];

		foreach ( $body->patterns as $pattern ) {
			if ( isset( $pattern->category ) && is_array( $pattern->category ) ) {
				$categories = array_merge( $categories, $pattern->category );
			}
		}

		return array_unique( $categories );
	}

	public function templates() {
		self::guard( check_ajax_referer( 'bsb_template', '_wpnonce', false ) );

		$type       = self::type( sanitize_text_field( wp_unslash( $_POST['type'] ?? 'patterns' ) ) );
		$category   = sanitize_text_field( wp_unslash( $_POST['category'] ?? 'all' ) );
		$pageNumber = max( 1, absint( wp_unslash( $_POST['pageNumber'] ?? 1 ) ) );
		$perPage    = max( 1, absint( wp_unslash( $_POST['perPage'] ?? 9 ) ) );
		$search     = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$start      = $pageNumber - 1;
		$plugin     = self::PLUGIN_SLUG;

		$url = add_query_arg(
			[
				'type'     => $type,
				'start'    => $start,
				'end'      => $pageNumber,
				'limit'    => $perPage,
				'plugin'   => $plugin,
				'category' => $category,
				'keywords' => $search,
				'fields'   => 'ID,category,keywords,original_content,thumbnail,title,type,url,preview_url',
			],
			self::API . '/blocks'
		);

		$response = wp_remote_get( $url, [ 'timeout' => self::TIMEOUT ] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		wp_send_json_success( json_decode( wp_remote_retrieve_body( $response ) ) );
	}

	public function templateImport() {
		self::guard( check_ajax_referer( 'bsb_template', '_wpnonce', false ) );

		try {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- serialized block markup: kses strips HTML comments, which are the block delimiters, so sanitizing here would break every template. Gated by the nonce and capability checks above.
			$content = wp_unslash( $_POST['original_content'] ?? '' );

			$data = current_user_can( 'upload_files' ) ? Image::instance()->maybe_import_images( $content ) : $content;

			wp_send_json_success( $data );
		} catch ( \Throwable $th ) {
			wp_send_json_error( $th->getMessage() );
		}
	}

	public function templateCounts() {
		self::guard( check_ajax_referer( 'bsb_template', '_wpnonce', false ) );

		$type   = self::type( sanitize_text_field( wp_unslash( $_POST['type'] ?? 'patterns' ) ) );
		$plugin = self::PLUGIN_SLUG;

		try {
			$response = wp_remote_get(
				self::API . "/blocks?type={$type}&plugin={$plugin}&fields=ID,category&limit=10000&start=0&end=10000",
				[ 'timeout' => self::TIMEOUT ]
			);

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( 'Failed to fetch templates' );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! $body || ! isset( $body->patterns ) ) {
				wp_send_json_success( [
					'all'        => 0,
					'free'       => 0,
					'pro'        => 0,
					'categories' => [],
				] );
			}

			$counts = [
				'all'        => 0,
				'free'       => 0,
				'pro'        => 0,
				'categories' => [],
			];

			foreach ( $body->patterns as $template ) {
				$cats  = isset( $template->category ) && is_array( $template->category ) ? $template->category : [];
				$isPro = in_array( 'pro', $cats, true );

				$counts['all']++;
				$counts[ $isPro ? 'pro' : 'free' ]++;

				foreach ( $cats as $cat ) {
					if ( 'free' === $cat || 'pro' === $cat ) {
						continue;
					}

					if ( ! isset( $counts['categories'][ $cat ] ) ) {
						$counts['categories'][ $cat ] = [ 'total' => 0, 'free' => 0, 'pro' => 0 ];
					}

					$counts['categories'][ $cat ]['total']++;
					$counts['categories'][ $cat ][ $isPro ? 'pro' : 'free' ]++;
				}
			}

			wp_send_json_success( $counts );
		} catch ( \Throwable $th ) {
			wp_send_json_error( $th->getMessage() );
		}
	}

	public function templateFavorites() {
		self::guard( check_ajax_referer( 'bsb_template', '_wpnonce', false ) );

		$prefix    = sanitize_key( wp_unslash( $_POST['prefix'] ?? 'bsb' ) );
		$optionKey = $prefix . 'FavoritesTemplates';

		if ( isset( $_POST['favorites'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a JSON string, and every decoded value is hard-cast through absint() below.
			$raw = json_decode( wp_unslash( $_POST['favorites'] ), true );

			$favorites = [
				'patterns' => array_values( array_unique( array_map( 'absint', (array) ( $raw['patterns'] ?? [] ) ) ) ),
				'pages'    => array_values( array_unique( array_map( 'absint', (array) ( $raw['pages'] ?? [] ) ) ) ),
			];

			update_option( $optionKey, $favorites, false );
		}

		wp_send_json_success( get_option( $optionKey, [ 'patterns' => [], 'pages' => [] ] ) );
	}
}

new Templates();
