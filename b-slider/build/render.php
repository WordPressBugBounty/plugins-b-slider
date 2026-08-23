<?php
if ( ! defined( 'ABSPATH' ) ) exit;

call_user_func( function( $attributes ) {

    $sliders = [];
    $posts_query = isset( $attributes['postsQuery'] ) ? $attributes['postsQuery'] : [];

    foreach ( ( isset( $attributes['sliders'] ) ? $attributes['sliders'] : [] ) as $index => $slider ) {
        $sliders[] = $slider;
        $sliders[ $index ]['title'] = isset( $slider['title'] ) ? wp_kses_post( $slider['title'] ) : '';
        $sliders[ $index ]['desc'] = isset( $slider['desc'] ) ? wp_kses_post( $slider['desc'] ) : '';
    }

    $attributes['sliders'] = $sliders;

    $query = \B_SLIDER\Posts::query( $attributes );

    $post_type = $query['post_type'];
    $fimg_size = isset( $posts_query['fImgSize'] ) ? $posts_query['fImgSize'] : 'full';
    $meta_date_format = isset( $posts_query['metaDateFormat'] ) ? $posts_query['metaDateFormat'] : 'M j, Y';
    $is_excerpt_from_content = isset( $posts_query['isExcerptFromContent'] ) ? $posts_query['isExcerptFromContent'] : true;
    $excerpt_length = isset( $posts_query['excerptLength'] ) ? $posts_query['excerptLength'] : 25;

    $selected_acf_fields = \B_SLIDER\Posts::acfFieldsToFetch( $posts_query, $post_type );

    $posts = \B_SLIDER\Posts::arrangedPosts( get_posts( $query ), $post_type, $fimg_size, $meta_date_format, $is_excerpt_from_content, $excerpt_length, $selected_acf_fields );

    ?>
    <div
        <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>
        id='bsbCarousel-<?php echo esc_attr( isset( $attributes['cId'] ) ? $attributes['cId'] : '' ); ?>'
        data-attributes='<?php echo esc_attr( wp_json_encode( $attributes ) ); ?>'
        data-nonce='<?php echo esc_attr( wp_json_encode( wp_create_nonce( \B_SLIDER\PostsAjax::NONCE_ACTION ) ) ); ?>'
        data-totalposts='<?php echo esc_attr( count( get_posts( array_merge( $query, [ 'posts_per_page' => -1, 'fields' => 'ids' ] ) ) ) ); ?>'
    >
        <pre id='posts' style='display: none;'>
            <?php echo esc_html( wp_json_encode( $posts ) ); ?>
        </pre>
    </div>
    <?php
}, $attributes ); 