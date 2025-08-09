<?php
namespace BSB\PostsAjax;

if ( ! defined( 'ABSPATH' ) ) exit;
if(!class_exists( 'PostsAjax' )){
    class PostsAjax{
        public function __construct(){
            add_action( 'wp_ajax_bsbPosts', [$this, 'bsbPosts'] );
            add_action( 'wp_ajax_nopriv_bsbPosts', [$this, 'bsbPosts'] );
        }

        public function bsbPosts(){
            $nonce = sanitize_text_field( $_POST['_wpnonce'] ) ?? null;

            if( !wp_verify_nonce( $nonce, 'wp_ajax' )){
                wp_send_json_error( 'Invalid Request' );
            }

            $postsQuery = \BSB\Posts\Posts::sanitize_array( $_POST['queryAttr'] ) ?? [];
            $pageNumber = (int) sanitize_text_field( $_POST['pageNumber'] ) ?? 1;
            wp_send_json_success( \BSB\Posts\Posts::getPosts( [ 'postsQuery' => $postsQuery ], $pageNumber ) );
        }
    }
    new PostsAjax();
}