<?php
namespace B_SLIDER;

if ( ! defined( 'ABSPATH' ) ) exit;
if(!class_exists( __NAMESPACE__ . '\PostsAjax' )){
    class PostsAjax{
        /**
         * The most posts one AJAX call will ever build.
         *
         * `per_page` comes from the request, `-1` there means every post on the site, and this
         * route answers logged out visitors — so it is capped rather than taken at its word. The
         * cap is far above any real page of a slider; only a crafted request ever meets it.
         */
        const MAX_PER_PAGE = 100;

        /**
         * The nonce action for this route, shared with `render.php`, which mints the token.
         *
         * Named for this plugin rather than the generic `wp_ajax` it used to be: a nonce is only
         * meaningful as a pair of an action and a user, and an action every plugin on the site might
         * pick is one any of them can satisfy on our behalf.
         */
        const NONCE_ACTION = 'bsb_posts';

        public function __construct(){
            add_action( 'wp_ajax_bsbPosts', [$this, 'bsbPosts'] );
            add_action( 'wp_ajax_nopriv_bsbPosts', [$this, 'bsbPosts'] );
        }

        /** The query with a page size this route is willing to run. */
        private function cappedQuery( $postsQuery ){
            $per_page = isset( $postsQuery['per_page'] ) ? (int) $postsQuery['per_page'] : 10;

            if ( $per_page < 1 || $per_page > self::MAX_PER_PAGE ) {
                $per_page = self::MAX_PER_PAGE;
            }

            $postsQuery['per_page'] = $per_page;

            return $postsQuery;
        }

        public function bsbPosts(){
            $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

            if( !wp_verify_nonce( $nonce, self::NONCE_ACTION )){
                wp_send_json_error( 'Invalid Request' );
            }

            // `sanitize_array()` answers `false` for anything that is not an array, and `queryAttr`
            // is whatever the request said it was — so a scalar there means no query settings rather
            // than a `false` handed on to code that goes looking for keys in it.
            $postsQuery = isset( $_POST['queryAttr'] ) ? Posts::sanitize_array( map_deep( wp_unslash( $_POST['queryAttr'] ), 'sanitize_text_field' ) ) : [];
            $postsQuery = is_array( $postsQuery ) ? $postsQuery : [];
            $pageNumber = isset( $_POST['pageNumber'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['pageNumber'] ) ) : 1;
            $pageNumber = max( 1, $pageNumber );

            wp_send_json_success( Posts::getPosts( [ 'postsQuery' => $this->cappedQuery( $postsQuery ) ], $pageNumber ) );
        }
    }
    new PostsAjax();
}