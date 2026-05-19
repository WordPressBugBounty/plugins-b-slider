<?php
namespace B_SLIDER;

if ( ! defined( 'ABSPATH' ) ) exit;
if(!class_exists( __NAMESPACE__ . '\PostsAjax' )){
    class PostsAjax{
        public function __construct(){
            add_action( 'wp_ajax_bsbPosts', [$this, 'bsbPosts'] );
            add_action( 'wp_ajax_nopriv_bsbPosts', [$this, 'bsbPosts'] );
        }

        public function bsbPosts(){
            $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

            if( !wp_verify_nonce( $nonce, 'wp_ajax' )){
                wp_send_json_error( 'Invalid Request' );
            }

            $postsQuery = isset( $_POST['queryAttr'] ) ? Posts::sanitize_array( map_deep( wp_unslash( $_POST['queryAttr'] ), 'sanitize_text_field' ) ) : [];
            $pageNumber = isset( $_POST['pageNumber'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['pageNumber'] ) ) : 1;
            wp_send_json_success( Posts::getPosts( [ 'postsQuery' => $postsQuery ], $pageNumber ) );
        }
    }
    new PostsAjax();
}