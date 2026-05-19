<?php 
if ( ! defined( 'ABSPATH' ) ) exit;

// Wrap in a function to avoid global variable scope warnings.
call_user_func( function( $attributes ) {

    $sliders = [];
    $posts_query = $attributes['postsQuery'];

    foreach ( $attributes['sliders'] as $index => $slider ) {
        $sliders[] = $slider;
        $sliders[ $index ]['title'] = isset( $slider['title'] ) ? wp_kses_post( $slider['title'] ) : '';
        $sliders[ $index ]['desc'] = isset( $slider['desc'] ) ? wp_kses_post( $slider['desc'] ) : '';
    }

    $attributes['sliders'] = $sliders;

    $post_type = isset( $posts_query['post_type'] ) ? $posts_query['post_type'] : 'post';
    $fimg_size = isset( $posts_query['fImgSize'] ) ? $posts_query['fImgSize'] : 'full';
    $meta_date_format = isset( $posts_query['metaDateFormat'] ) ? $posts_query['metaDateFormat'] : 'M j, Y';
    $is_excerpt_from_content = isset( $posts_query['isExcerptFromContent'] ) ? $posts_query['isExcerptFromContent'] : true;
    $excerpt_length = isset( $posts_query['excerptLength'] ) ? $posts_query['excerptLength'] : 25;

    $posts = \B_SLIDER\Posts::arrangedPosts( get_posts( \B_SLIDER\Posts::query( $attributes ) ), $post_type, $fimg_size, $meta_date_format, $is_excerpt_from_content, $excerpt_length );

    ?>
    <div
        <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>
        id='bsbCarousel-<?php echo esc_attr( $attributes['cId'] ); ?>'
        data-attributes='<?php echo esc_attr( wp_json_encode( $attributes ) ); ?>'
        data-nonce='<?php echo esc_attr( wp_json_encode( wp_create_nonce( 'wp_ajax' ) ) ); ?>'
        data-totalposts=<?php echo esc_attr( count( get_posts( array_merge( \B_SLIDER\Posts::query( $attributes ), [ 'posts_per_page' => -1 ] ) ) ) ); ?>
    >
        <pre id='posts' style='display: none;'>
            <?php echo esc_html( wp_json_encode( $posts ) ); ?>
        </pre>
    </div>
    <?php
}, $attributes ); 