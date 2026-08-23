<?php
namespace B_SLIDER;

if ( ! defined( 'ABSPATH' ) ) exit;
if(!class_exists( __NAMESPACE__ . '\PostsAjax' )){
    class PostsAjax{
        const MAX_PER_PAGE = 100;

        const NONCE_ACTION = 'bsb_posts';

        public function __construct(){
            add_action( 'wp_ajax_bsbPosts', [$this, 'bsbPosts'] );
            add_action( 'wp_ajax_nopriv_bsbPosts', [$this, 'bsbPosts'] );
        }

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

            $postsQuery = isset( $_POST['queryAttr'] ) ? Posts::sanitize_array( map_deep( wp_unslash( $_POST['queryAttr'] ), 'sanitize_text_field' ) ) : [];
            $postsQuery = is_array( $postsQuery ) ? $postsQuery : [];
            $pageNumber = isset( $_POST['pageNumber'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['pageNumber'] ) ) : 1;
            $pageNumber = max( 1, $pageNumber );

            wp_send_json_success( Posts::getPosts( [ 'postsQuery' => $this->cappedQuery( $postsQuery ) ], $pageNumber ) );
        }
    }
    new PostsAjax();
}