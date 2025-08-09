<?php 
if ( ! defined( 'ABSPATH' ) ) exit;
extract($attributes);
extract($postsQuery);

$sliders = [];

foreach ($attributes['sliders'] as $index => $slider) {
    $sliders[] = $slider;
    $sliders[$index]['title'] = isset($slider['title'] ) ? wp_kses_post($slider['title']) : '';
    $sliders[$index]['desc'] = isset($slider['desc']) ? wp_kses_post($slider['desc']) : '';
    $sliders[$index]['btnLabel'] = isset( $slider['btnLabel'] ) ? wp_kses_post( $slider['btnLabel'] ) : '';
}

$attributes['sliders'] = $sliders;

$posts = \BSB\Posts\Posts::arrangedPosts( get_posts( \BSB\Posts\Posts::query( $attributes ) ), $post_type,$fImgSize, $metaDateFormat, $isExcerptFromContent, $excerptLength );

?>
<div
    <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>
    id='bsbCarousel-<?php echo esc_attr($cId) ?>' 
    data-attributes='<?php echo esc_attr(wp_json_encode($attributes)); ?>'
    data-nonce='<?php echo esc_attr( wp_json_encode( wp_create_nonce( 'wp_ajax' ) ) ); ?>'
    data-totalposts=<?php echo esc_attr( count( get_posts( array_merge( \BSB\Posts\Posts::query( $attributes ), [ 'posts_per_page' => -1 ] ) ) ) ); ?>
>
    <pre id='posts' style='display: none;'>
        <?php echo esc_html( wp_json_encode( $posts ) ) ?>
    </pre>
</div>

 