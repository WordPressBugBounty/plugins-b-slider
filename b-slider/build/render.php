<?php
if ( ! defined( 'ABSPATH' ) ) exit;

call_user_func( function( $attributes, $content ) {

    // Both end up inside quoted SVG attributes in arrows.js, so only a number and a plain colour pass.
    if ( isset( $attributes['arrow'] ) && is_array( $attributes['arrow'] ) ) {
        if ( isset( $attributes['arrow']['size'] ) ) {
            $size = is_numeric( $attributes['arrow']['size'] ) ? abs( (float) $attributes['arrow']['size'] ) : 24;
            $attributes['arrow']['size'] = is_finite( $size ) ? $size : 24;
        }

        if ( isset( $attributes['arrow']['color'] ) && ( ! is_string( $attributes['arrow']['color'] ) || ! preg_match( '/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([\d\s.,%\/]+\)|var\(--[\w-]+\)|[a-z]+)$/i', trim( $attributes['arrow']['color'] ) ) ) ) {
            $attributes['arrow']['color'] = '#fff';
        }
    }

    if ( 'blocks' === ( isset( $attributes['sourceType'] ) ? $attributes['sourceType'] : '' ) ) {

        // The slides reach the browser as a string that JS drops in with innerHTML, so nothing
        // downstream expands shortcodes for us the way `the_content` would. A Shortcode block
        // inside a slide is saved as its literal text, and without this it renders as that text.
        $attributes['_blocksHtml'] = do_shortcode( $content );
        if ( preg_match_all( '~font-family:\s*(?:\'|&\#039;|&quot;|")([A-Za-z][A-Za-z \-]{0,50})(?:\'|&\#039;|&quot;|")~', $content, $matches ) ) {

            foreach ( array_unique( $matches[1] ) as $family ) {
                $handle = 'bsb-font-' . sanitize_title( $family );

                if ( ! wp_style_is( $handle, 'registered' ) ) {
                    // phpcs:disable WordPress.WP.EnqueuedResourceParameters.MissingVersion
                    wp_register_style(
                        $handle,
                        'https://fonts.googleapis.com/css2?family=' . str_replace( ' ', '+', $family ) . '&display=swap',
                        [],
                        null
                    );
                    // phpcs:enable WordPress.WP.EnqueuedResourceParameters.MissingVersion
                }

                wp_enqueue_style( $handle );
            }
        }
        ?>
        <div
            <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>
            id='bsbCarousel-<?php echo esc_attr( isset( $attributes['cId'] ) ? $attributes['cId'] : '' ); ?>'
            data-attributes-b64='<?php echo esc_attr( base64_encode( wp_json_encode( $attributes ) ) ); ?>'
            data-nonce='<?php echo esc_attr( wp_json_encode( wp_create_nonce( \B_SLIDER\PostsAjax::NONCE_ACTION ) ) ); ?>'
            data-totalposts='0'
        ></div>
        <?php
        return;
    }

    $sliders = [];
    $posts_query = isset( $attributes['postsQuery'] ) ? $attributes['postsQuery'] : [];

    // kses stores a non-admin's `&` as `&amp;`, which the hex-escaped JSON no longer lets the browser undo.
    // esc_url_raw strips the quotes and angle brackets the decode could bring back.
    $clean_url = function( $url ) {
        return esc_url_raw( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    };

    // Each slide is cleaned as a whole and then appended, so a list saved with out-of-order keys cannot
    // pair one slide's cleaned fields with another's raw ones.
    foreach ( ( isset( $attributes['sliders'] ) && is_array( $attributes['sliders'] ) ? array_values( $attributes['sliders'] ) : [] ) as $slider ) {
        if ( ! is_array( $slider ) ) {
            continue;
        }

        $slider['title'] = isset( $slider['title'] ) && is_string( $slider['title'] ) ? wp_kses_post( $slider['title'] ) : '';
        $slider['desc'] = isset( $slider['desc'] ) && is_string( $slider['desc'] ) ? wp_kses_post( $slider['desc'] ) : '';

        if ( isset( $slider['img'] ) && is_array( $slider['img'] ) ) {
            foreach ( [ 'caption', 'title' ] as $key ) {
                if ( isset( $slider['img'][ $key ] ) && is_string( $slider['img'][ $key ] ) ) {
                    $slider['img'][ $key ] = wp_kses_post( $slider['img'][ $key ] );
                }
            }

            if ( isset( $slider['img']['url'] ) && is_string( $slider['img']['url'] ) ) {
                $slider['img']['url'] = $clean_url( $slider['img']['url'] );
            }
        }

        // Fancybox pastes the video URL into its player markup as a string, so it has to be a clean URL.
        if ( isset( $slider['video']['url'] ) && is_string( $slider['video']['url'] ) ) {
            $slider['video']['url'] = $clean_url( $slider['video']['url'] );
        }

        $sliders[] = $slider;
    }

    $attributes['sliders'] = $sliders;

    if ( isset( $attributes['button']['text'] ) && is_string( $attributes['button']['text'] ) ) {
        $attributes['button']['text'] = wp_kses_post( $attributes['button']['text'] );
    }

    $query = \B_SLIDER\Posts::query( $attributes );

    $post_type = $query['post_type'];
    $fimg_size = isset( $posts_query['fImgSize'] ) ? $posts_query['fImgSize'] : 'full';
    $meta_date_format = isset( $posts_query['metaDateFormat'] ) ? $posts_query['metaDateFormat'] : 'M j, Y';
    $is_excerpt_from_content = isset( $posts_query['isExcerptFromContent'] ) ? $posts_query['isExcerptFromContent'] : true;
    $excerpt_length = isset( $posts_query['excerptLength'] ) ? $posts_query['excerptLength'] : 25;

    $selected_acf_fields = \B_SLIDER\Posts::acfFieldsToFetch( $posts_query, $post_type );

    // The AJAX pages send the field list back from the browser, so it is signed here and checked in PostsAjax.
    $acf_sig = \B_SLIDER\Posts::acfFieldsSignature( $selected_acf_fields, $post_type );
    if ( $acf_sig && is_array( $posts_query ) ) {
        $attributes['postsQuery'] = array_merge( $posts_query, [ 'acfSig' => $acf_sig ] );
    }

    $posts = \B_SLIDER\Posts::arrangedPosts( get_posts( $query ), $post_type, $fimg_size, $meta_date_format, $is_excerpt_from_content, $excerpt_length, $selected_acf_fields );

    ?>
    <div
        <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>
        id='bsbCarousel-<?php echo esc_attr( isset( $attributes['cId'] ) ? $attributes['cId'] : '' ); ?>'
        data-attributes='<?php echo esc_attr( wp_json_encode( $attributes, BSB_JSON_IN_HTML ) ); ?>'
        data-nonce='<?php echo esc_attr( wp_json_encode( wp_create_nonce( \B_SLIDER\PostsAjax::NONCE_ACTION ), BSB_JSON_IN_HTML ) ); ?>'
        data-totalposts='<?php echo esc_attr( count( get_posts( array_merge( $query, [ 'posts_per_page' => -1, 'fields' => 'ids' ] ) ) ) ); ?>'
    >
        <pre id='posts' style='display: none;'>
            <?php echo esc_html( wp_json_encode( $posts, BSB_JSON_IN_HTML ) ); ?>
        </pre>
    </div>
    <?php
}, $attributes, $content );
