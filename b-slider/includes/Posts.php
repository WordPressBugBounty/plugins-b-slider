<?php
namespace B_SLIDER;

if ( ! defined( 'ABSPATH' ) ) exit;

if(!class_exists( __NAMESPACE__ . '\Posts' )){
    class Posts{
        static function sanitize_array($array){
            if( !is_array( $array ) ) {
                return false;
            }

            foreach( $array as $key => $value ) {
                if( is_array( $value ) ) {
                    $array[$key] = self::sanitize_array( $value );
                }else {
                    $array[$key] =$value == 'true' ? true : ( $value == 'false' ? false :  sanitize_text_field( $value ) );
                }
            }
            return $array;
        }

        static function filterNaN( $array ) {
            if ( ! is_array( $array ) ) {
                return [];
            }

            return array_values( array_map( 'intval', array_filter( $array, function( $id ) {
                return $id && is_numeric( $id );
            } ) ) );
        }

        static function wordCount( $content ) {
            return $content ? count( preg_split( 
                '/[\s]+/',
                preg_replace( '/(<([^>]+)>)/i', '', $content )
            ) ) : 0;
        }

        static function applyBSBFilter( $rawContent ){
        
            $textAllowedHTML = [ 'a' => [ 'href' => [], 'title' => [] ], 'br' => [], 'em' => [], 'strong' => [] ];
            $innerAllowedHTML = array_merge( [ 'span' => [ $textAllowedHTML ] ], $textAllowedHTML );
            $allowedHTML = array_merge( [ 'p' => [ $innerAllowedHTML ] ], $innerAllowedHTML );
            $content = wp_kses( $rawContent, $allowedHTML );
            $plainText = trim( wp_strip_all_tags( $content ?: '' ) );

            return apply_filters( 'b_slider_posts_excerpt_filter', $plainText, $content );
        }

        const ACF_ROLE_KEYS = [ 'imageField', 'buttonTextField', 'buttonLinkField', 'titleField', 'descField' ];

        const FREE_POST_TYPES = [ 'post', 'page', 'product' ];

        static function freePostTypes() {
            $types = apply_filters( 'b_slider_free_post_types', self::FREE_POST_TYPES );

            return is_array( $types ) ? $types : self::FREE_POST_TYPES;
        }

        static function isPostTypeAllowed( $post_type ) {
            if ( function_exists( 'bsbIsPremium' ) && bsbIsPremium() ) {
                return true;
            }

            return in_array( $post_type, self::freePostTypes(), true );
        }

        static function isPostTypePublic( $post_type ) {
            $object = get_post_type_object( $post_type );

            return $object && ! empty( $object->public );
        }

        
        static function acfFieldsToFetch( $postsQuery = [], $post_type = null ) {
            $post_type = $post_type ?: ( $postsQuery['post_type'] ?? 'post' );

            $fields = $postsQuery['selectedAcfFields'] ?? [];
            $fields = is_array( $fields ) ? array_values( $fields ) : [];

            $fields = self::allowedAcfFields( $fields, $post_type );

            foreach ( self::ACF_ROLE_KEYS as $key ) {
                $name = trim( (string) ( $postsQuery[ $key ] ?? '' ) );

                if ( '' !== $name && ! in_array( $name, $fields, true ) && ! empty( self::allowedAcfFields( [ $name ], $post_type ) ) ) {
                    $fields[] = $name;
                }
            }

            return $fields;
        }

        static function allowedAcfFields( $fields, $post_type ) {
            if ( ! class_exists( __NAMESPACE__ . '\AcfFields' ) ) {
                return [];
            }

            return AcfFields::allowedFields( $fields, $post_type );
        }

         
        static function termsOfTaxonomy( $terms, $taxonomy, $post_type ) {
            if ( empty( $terms ) || ! is_array( $terms ) || ! taxonomy_exists( $taxonomy ) ) {
                return [];
            }

            if ( ! $post_type || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
                return [];
            }

            return array_values( array_filter( array_map( 'intval', $terms ), function( $id ) use ( $taxonomy ) {
                $term = get_term( $id );

                return $term && ! is_wp_error( $term ) && $taxonomy === $term->taxonomy;
            } ) );
        }

        static function arrangedPosts( $posts, $post_type='post', $fImgSize = 'full', $metaDateFormat = 'M j, Y', $isExcerptFromContent=true, $excerptLength = 25, $selectedAcfFields = [] ) {
        
            $arranged = [];
            $excerptLength = (int) $excerptLength;
            $taxOfPostType = array_diff( get_object_taxonomies( $post_type ), array( 'post_format', 'category' ) );

            foreach( $posts as $post ){
                $id = isset( $post->ID ) ? sanitize_text_field( $post->ID ) :'';
                $content = preg_replace( '/(<([^>]+)>)/i', '', $post->post_content );
                $post_excerpt = isset($post->post_excerpt) ? sanitize_text_field($post->post_excerpt) : '';
                $contentWords = self::wordCount( $content );
        
                $thumbnail = [
                    'url' => get_the_post_thumbnail_url( $post, $fImgSize ),
                    'alt' => get_post_meta( get_post_thumbnail_id( $id ), '_wp_attachment_image_alt', true )
                ];
        
                $taxonomies = [];
                foreach ( $taxOfPostType as $key => $slug ) {
                    $terms = wp_get_post_terms( $id, $slug );
        
                    $links = '';
                    foreach( $terms as $index => $t ){
                        $link = get_term_link( $t->slug, $slug );
                        $link = is_wp_error( $link ) ? '' : $link;
                        $terms[$index]->link = $link;

                        $links .= sprintf(
                            "<a href='%s' rel='%s'>%s</a>",
                            esc_url( $link ),
                            esc_attr( $slug ),
                            esc_html( $t->name )
                        );
                    };
                    $taxonomies[$slug] = $links;
                }
        
                $contentOrExcerptArr = $isExcerptFromContent ? [
                    'content' => $excerptLength > -1 ?
                        wp_trim_words( self::applyBSBFilter( $post->post_content ), $excerptLength, '' ) :
                        self::applyBSBFilter( $post->post_content )
                ] : [
                    'excerpt' => self::applyBSBFilter( $post->post_excerpt )
                ];

                $priceSale = ( isset( $post->post_type ) && 'product' === $post->post_type && class_exists( __NAMESPACE__ . '\AcfFields' ) )
                    ? AcfFields::product_price_sale_for( $id )
                    : [ 'price' => '', 'sale' => '', 'sale_percent' => '' ];
                $price = $priceSale['price'];
                $sale = $priceSale['sale'];
                $sale_percent = $priceSale['sale_percent'];


                $arranged[] = array_merge( [
                    'id' => $id,
                    'price' => $price,
                    'sale' => $sale,
                    'sale_percent' => $sale_percent,
                    'link' => get_permalink( $post ),
                    'name' => isset( $post->post_name ) ? sanitize_text_field($post->post_name) : '',
                    'thumbnail' => $thumbnail,
                    'title' => isset($post->post_title) ? sanitize_text_field($post->post_title):'',
                    'author' => [
                        'name' => get_the_author_meta( 'display_name', isset($post->post_author) ? sanitize_text_field($post->post_author) : '' ),
                        'link' => get_author_posts_url( isset( $post->post_author ) ? sanitize_text_field( $post->post_author ):'' )
                    ],
                    'date' => get_the_date( $metaDateFormat, $id ),
                    'dateGMT' => isset($post->post_date_gmt) ? sanitize_text_field($post->post_date_gmt):'',
                    'modifiedDate' => isset($post->post_modified) ? sanitize_text_field($post->post_modified):'',
                    'modifiedDateGMT' => isset($post->post_modified_gmt) ? sanitize_text_field($post->post_modified_gmt):'',
                    'commentCount' => isset($post->comment_count) ? sanitize_text_field($post->comment_count):'',
                    'commentStatus' => isset($post->comment_status) ? sanitize_text_field($post->comment_status):'',
                    'categories' => [
                        'coma' => get_the_category_list( ', ', '', $id ),
                        'space' => get_the_category_list( ' ', '', $id )
                    ],
                    'taxonomies' => $taxonomies,
                    'acf_fields' => class_exists( __NAMESPACE__ . '\AcfFields' )
                        ? AcfFields::get_fields_for_post( $selectedAcfFields, $id )
                        : [],
                    'readTime' => [
                        'min' => floor( $contentWords / 200 ),
                        'sec' => floor( $contentWords % 200 / ( 200 / 60 ) )
                    ],
                    'status' => isset($post->post_status) ? sanitize_text_field($post->post_status):''
                ], $contentOrExcerptArr );
            }
        
            return $arranged;
        }

        static function query( $attributes ){
            $postsQuery           = $attributes['postsQuery'] ?? [];
            $post_type            = $postsQuery['post_type'] ?? $attributes['post_type'] ?? 'post';
            if ( $post_type && ! self::isPostTypeAllowed( $post_type ) ) {
                $post_type = 'post';
            }
            if ( ! self::isPostTypePublic( $post_type ) ) {
                $post_type = 'post';
            }
            $per_page             = (int) ( $postsQuery['per_page'] ?? $attributes['per_page'] ?? 10 );
            $orderby              = $postsQuery['orderby'] ?? $attributes['orderby'] ?? 'date';
            $order                = $postsQuery['order'] ?? $attributes['order'] ?? 'DESC';
            $offset               = (int) ( $postsQuery['offset'] ?? $attributes['offset'] ?? 0 );
            $isExcludeCurrent     = $postsQuery['isExcludeCurrent'] ?? $attributes['isExcludeCurrent'] ?? false;
            $isExcludeCurrent     = filter_var( $isExcludeCurrent, FILTER_VALIDATE_BOOLEAN );
            $include              = $postsQuery['include'] ?? $attributes['include'] ?? [];

            $selectedTaxonomies   = $postsQuery['selectedTaxonomies'] ?? [];
            $selectedTaxonomies   = is_array( $selectedTaxonomies ) ? $selectedTaxonomies : [];
            $selectedCategories   = self::filterNaN( $postsQuery['selectedCategories'] ?? [] );
            $selectedTags         = self::filterNaN( $postsQuery['selectedTags'] ?? [] );

            $termsQuery = ['relation' => 'AND'];
            foreach ( $selectedTaxonomies as $taxonomy => $terms ){
                $terms = self::termsOfTaxonomy( $terms, (string) $taxonomy, $post_type );

                if( ! empty( $terms ) ){
                    $termsQuery[] = [
                        'taxonomy'	=> $taxonomy,
                        'field'		=> 'term_id',
                        'terms'		=> $terms,
                    ];
                }
            }

            $defaultPostQuery = [];
            if ( 'post' === $post_type ) {
                $defaultPostQuery = [
                    'category__in'	=> $selectedCategories,
                    'tag__in'		=> $selectedTags
                ];
            } else {
                $taxFilters = [
                    ( 'product' === $post_type ? 'product_cat' : 'category' ) => $selectedCategories,
                    ( 'product' === $post_type ? 'product_tag' : 'post_tag' ) => $selectedTags
                ];

                foreach ( $taxFilters as $taxonomy => $terms ) {
                    $terms = self::termsOfTaxonomy( $terms, $taxonomy, $post_type );

                    if ( ! empty( $terms ) ) {
                        $termsQuery[] = [
                            'taxonomy' => $taxonomy,
                            'field'    => 'term_id',
                            'terms'    => $terms,
                        ];
                    }
                }
            }

            $postsInclude = self::filterNaN( $include ?? [] );
            $post__in = !empty( $postsInclude ) ? [ 'post__in' => $postsInclude ] : [];
            $postsExclude = self::filterNaN( $postsQuery['exclude'] ?? [] );

            $query = array_merge( [
                'post_type'			=> $post_type,
                'posts_per_page'	=> $per_page,
                'orderby'			=> $orderby,
                'order'				=> $order,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                'tax_query'			=> $termsQuery,
                'offset'			=> $offset,
                // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
                'post__not_in'		=> $isExcludeCurrent ? array_merge( [ get_the_ID() ], $postsExclude ) : $postsExclude,
                'has_password'		=> false,
                'post_status'		=> 'publish'
            ], $post__in, $defaultPostQuery );

            if ( $per_page < 0 ) {
                $query['posts_per_page'] = -1;
                $query['nopaging']       = true;
                unset( $query['offset'] );
            }

            return $query;
        }

        static function getPosts( $attributes = [], $pageNumber = 1 ){
            $postsQuery           = $attributes['postsQuery'] ?? [];
            $post_type            = $postsQuery['post_type'] ?? $attributes['post_type'] ?? 'post';
            $per_page             = (int) ( $postsQuery['per_page'] ?? $attributes['per_page'] ?? 10 );
            $offset               = (int) ( $postsQuery['offset'] ?? $attributes['offset'] ?? 0 );
            $fImgSize             = $postsQuery['fImgSize'] ?? $attributes['fImgSize'] ?? 'full';
            $metaDateFormat       = $postsQuery['metaDateFormat'] ?? $attributes['metaDateFormat'] ?? 'M j, Y';
            $isExcerptFromContent = $postsQuery['isExcerptFromContent'] ?? $attributes['isExcerptFromContent'] ?? true;
            $excerptLength        = $postsQuery['excerptLength'] ?? $attributes['excerptLength'] ?? 25;
            $isExcludeCurrent     = $postsQuery['isExcludeCurrent'] ?? $attributes['isExcludeCurrent'] ?? false;
            $isExcludeCurrent     = $isExcludeCurrent || 'true' === $isExcludeCurrent;
            $newArgs = self::query( $attributes );

            if ( $per_page > 0 ) {
                $newArgs['offset'] = ( $per_page * ( $pageNumber - 1 ) ) + $offset;
            }

            $posts = self::arrangedPosts(
                get_posts( $newArgs ),
                $newArgs['post_type'],
                $fImgSize,
                $metaDateFormat,
                $isExcerptFromContent,
                $excerptLength,
                self::acfFieldsToFetch( $postsQuery, $newArgs['post_type'] )
            );


            return $posts;

        }
    }
}