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
            $plainText = trim( wp_strip_all_tags( $content ?: '' ) );

            return apply_filters( 'b_slider_posts_excerpt_filter', $plainText, $content );
        }

        /** Query keys naming an ACF field that fills one of the item's built-in slots. */
        const ACF_ROLE_KEYS = [ 'imageField', 'buttonTextField', 'buttonLinkField', 'titleField', 'descField' ];

        /**
         * The post types a slider can query without a Pro licence.
         */
        const FREE_POST_TYPES = [ 'post', 'page', 'product' ];

        /** The free list, after `bsb_free_post_types` has had a say. */
        static function freePostTypes() {
            $types = apply_filters( 'bsb_free_post_types', self::FREE_POST_TYPES );

            return is_array( $types ) ? $types : self::FREE_POST_TYPES;
        }

        /** Whether this licence may query `$post_type` at all. */
        static function isPostTypeAllowed( $post_type ) {
            if ( function_exists( 'bsbIsPremium' ) && bsbIsPremium() ) {
                return true;
            }

            return in_array( $post_type, self::freePostTypes(), true );
        }

        /**
         * How many picked fields a slider displays. Mirrored in AcfFields.js as
         * `FREE_ACF_FIELD_LIMIT`, which is what the editor caps its own preview by.
         *
         * The cap covers the picker only. Fields assigned to the image, title, description or
         * button slot are a separate setting and are not counted against it.
         */
        const FREE_ACF_FIELD_LIMIT = 3;

        /**
         * The ACF fields to pull for each post: the ones picked for display, plus any field
         * assigned to the image, title, description or button slot.
         *
         * Slot fields are fetched even when they are not on display, otherwise the user would
         * have to also list them under "Select ACF Fields" just to make the slot resolve — and
         * image fields are never listed there at all.
         *
         * This is where the limit is enforced for real: every path that renders a slider comes
         * through here, so a selection carried over from Pro — or written straight into the block
         * markup — still comes back trimmed. The editor applies the same cap, but only so the
         * preview agrees with the site; it is not what holds the line.
         */
        static function acfFieldsToFetch( $postsQuery = [] ) {
            $fields = $postsQuery['selectedAcfFields'] ?? [];
            $fields = is_array( $fields ) ? array_values( $fields ) : [];
            $fields = array_slice( $fields, 0, self::FREE_ACF_FIELD_LIMIT );

            foreach ( self::ACF_ROLE_KEYS as $key ) {
                $name = trim( (string) ( $postsQuery[ $key ] ?? '' ) );

                if ( '' !== $name && ! in_array( $name, $fields, true ) ) {
                    $fields[] = $name;
                }
            }

            return $fields;
        }

        /**
         * The IDs among `$terms` that really are terms of `$taxonomy` on `$post_type`.
         *
         * `selectedCategories` and `selectedTags` are one pair of keys shared by every post type, so
         * a slider moved from Posts over to products or a CPT still carries whatever was picked for
         * the old one. Those IDs belong to `category` and `post_tag`: a `tax_query` built from them
         * matches nothing, and naming a taxonomy the post type does not even have makes WP_Query
         * put `0 = 1` in the SQL and return no rows at all. Either way a slider that worked before
         * an update would come back empty, so terms that cannot apply are dropped and the filter
         * disappears with them.
         *
         * An unknown post type filters by nothing, which is what this query has always done for a
         * slider saved without one.
         */
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
            // Every post query setting lives inside the `postsQuery` object attribute,
            // the top level keys are only kept as a fallback for older saved blocks.
            $post_type            = $postsQuery['post_type'] ?? $attributes['post_type'] ?? 'post';
            if ( $post_type && ! self::isPostTypeAllowed( $post_type ) ) {
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
            $selectedCategories   = $postsQuery['selectedCategories'] ?? [];

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

            $defaultPostQuery = [];
            if ( 'post' === $post_type ) {
                $defaultPostQuery = [
                    'category__in'	=> $selectedCategories,
                    'tag__in'		=> $postsQuery['selectedTags'] ?? []
                ];
            } else {
                // Products and custom post types keep their terms in the same two keys, so they go
                // through `tax_query` against whichever taxonomy the post type actually uses.
                $taxFilters = [
                    ( 'product' === $post_type ? 'product_cat' : 'category' ) => $selectedCategories,
                    ( 'product' === $post_type ? 'product_tag' : 'post_tag' ) => $postsQuery['selectedTags'] ?? []
                ];

                foreach ( $taxFilters as $taxonomy => $terms ) {
                    $terms = self::termsOfTaxonomy( $terms, $taxonomy, $post_type );

                    if ( ! empty( $terms ) ) {
                        // `$termsQuery` already opens with `relation => AND`, so appending is enough.
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

            // `-1` means show all posts. WP_Query ignores `offset` while `nopaging` is on.
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

            // Paging only makes sense with a positive per page value, `-1` returns every post.
            if ( $per_page > 0 ) {
                $newArgs['offset'] = ( $per_page * ( $pageNumber - 1 ) ) + $offset;
            }

            $posts = self::arrangedPosts(
                get_posts( $newArgs ),
                $newArgs['post_type'],
                $fImgSize,
                $metaDateFormat,
                // $isExcerptFromContent || 'true' === $isExcerptFromContent,
                $isExcerptFromContent,
                $excerptLength,
                self::acfFieldsToFetch( $postsQuery )
            );


            return $posts;

        }
    }
}