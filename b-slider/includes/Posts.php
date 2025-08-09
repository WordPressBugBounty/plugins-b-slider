<?php

namespace BSB\Posts;

if ( ! defined( 'ABSPATH' ) ) exit;

if(!class_exists( 'Posts' )){
    class Posts{
        static function sanitize_array($array){
            if( !is_array( $array ) ) {
                return false;
            }

            foreach( $array as $key => $value ) {
                if( strpos( $key, 'secret_key' ) !== false && strlen( $value ) == 32 ) {
                    $value = sanitize_text_field( str_replace( '<', '&lt;', $value ) ); 
                    $value = sanitize_text_field( $value );
                    $array[$key] = str_replace( ['&lt;', '&gt;', '&amp;'], [ '<', '>', '&'], $value );
                }else {
                    if( is_array( $value ) ) {
                        $array[$key] = self::sanitize_array( $value );
                    }else {
                        $array[$key] =$value == 'true' ? true : ( $value == 'false' ? false :  sanitize_text_field( $value ) );
                    }
                }
            }
            return $array;
        }

        static function filterNaN( $array ) {
            return array_filter( $array, function( $id ) {
                return $id && is_numeric( $id );
            });
        }

        static function wordCount( $content ) {
            return $content ? count( preg_split( 
                '/[\s]+/',
                preg_replace( '/(<([^>]+)>)/i', '', $content )
            ) ) : 0;
        }

        static function applyBSBFilter( $rawContent ){
            // remove script and style tag
            // $rawContent = preg_replace( '/<script\b[^>]*>(.*?)<\/script>|<style\b[^>]*>(.*?)<\/style>/is', '', $rawContent );
        
            $textAllowedHTML = [ 'a' => [ 'href' => [], 'title' => [] ], 'br' => [], 'em' => [], 'strong' => [] ];
            $innerAllowedHTML = array_merge( [ 'span' => [ $textAllowedHTML ] ], $textAllowedHTML );
            $allowedHTML = array_merge( [ 'p' => [ $innerAllowedHTML ] ], $innerAllowedHTML );
            $content = wp_kses( $rawContent, $allowedHTML );
            $plainText = trim( wp_strip_all_tags( $content ?? '' ) );

            return apply_filters( 'bsb_posts_excerpt_filter', $plainText, $content );
        }

        static function arrangedPosts( $posts, $post_type='post', $fImgSize = 'full', $metaDateFormat = 'M j, Y', $isExcerptFromContent=true, $excerptLength = 25 ) {
        
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
                        $terms[$index]->link = $link;
        
                        $links .= "<a href='$link' rel='$slug'>$t->name</a>";
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

                // $contentOrExcerptArr = [ 'content' => $post->post_content];
        
                $arranged[] = array_merge( [
                    'id' => $id,
                    'link' => get_permalink( $post ),
                    'name' => isset( $post->post_name ) ? sanitize_text_field($post->post_name) : '',
                    'thumbnail' => $thumbnail,
                    'title' => isset($post->post_title) ? sanitize_text_field($post->post_title):'',
                    'author' => [
                        'name' => get_the_author_meta( 'display_name', isset($post->post_author) ? sanitize_text_field($post->post_author) : '' ),
                        'link' => get_author_posts_url( isset( $post->post_author ) ? sanitize_text_field( $post->post_author ):'' )
                    ],
                    'date' => isset($post->post_date) ? sanitize_text_field($post->post_date) : '',
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
            extract( $attributes );
            extract( $postsQuery );

            $selectedTaxonomies = $selectedTaxonomies ?? [];
            $selectedCategories = $postsQuery['selectedCategories'] ?? [];

            $termsQuery = ['relation' => 'AND'];
            foreach ( $selectedTaxonomies as $taxonomy => $terms ){
                if( count( $terms ) ){
                    $termsQuery[] = [
                        'taxonomy'	=> $taxonomy,
                        'field'		=> 'term_id',
                        'terms'		=> $terms,
                    ];
                }
            }

            $defaultPostQuery = 'post' === $post_type ? [
                'category__in'	=> $selectedCategories,
                'tag__in'		=> $postsQuery['selectedTags'] ?? []
            ] : [];

            $postsInclude = self::filterNaN( $include ?? [] );
            $post__in = !empty( $postsInclude ) ? [ 'post__in' => $postsInclude ] : [];
            $postsExclude = self::filterNaN( $postsQuery['exclude'] ?? [] );

            $query = array_merge( [
                'post_type'			=> $post_type,
                'posts_per_page'	=> $per_page,
                'orderby'			=> $orderby,
                'order'				=> $order,
                'tax_query'			=> $termsQuery,
                'offset'			=> $offset,
                'post__not_in'		=> $isExcludeCurrent ? array_merge( [ get_the_ID() ], $postsExclude ) : $postsExclude,
                'has_password'		=> false,
                'post_status'		=> 'publish'
            ], $post__in, $defaultPostQuery );

            return $query;
        }

        static function getPosts( $attributes = [], $pageNumber = 1 ){            
            extract( $attributes );
            extract( $postsQuery );
             
            $isExcludeCurrent = $isExcludeCurrent || 'true' === $isExcludeCurrent;
            $newArgs = wp_parse_args( [ 'offset' => ( $per_page * ( $pageNumber - 1 ) ) + $offset ], self::query( $attributes ) );
            $posts = self::arrangedPosts(
                get_posts( $newArgs ),
                $post_type,
                $fImgSize,
                $metaDateFormat,
                // $isExcerptFromContent || 'true' === $isExcerptFromContent,
                $isExcerptFromContent,
                $excerptLength
            );


            return $posts;

        }
    }
}