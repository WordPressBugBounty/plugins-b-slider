<?php
namespace B_SLIDER;

if ( ! defined( 'ABSPATH' ) ) exit;

if(!class_exists( __NAMESPACE__ . '\Posts' )){
    class Posts{
        /**
         * Sanitise a request array in place, leaving its shape alone.
         *
         * Returns `false` for anything that is not an array, so a caller handing this a scalar
         * straight off a request has to say what it wants done about that — see `PostsAjax`.
         */
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

        /**
         * The numeric IDs in `$array`, as integers.
         *
         * Everything reaching this comes from a request, where a key the editor writes as a list can
         * arrive as a scalar just as easily — so a non-array is no IDs rather than a `TypeError` out
         * of `array_filter()`. The values become `int` here because that is what they are used as:
         * `post__in` and `post__not_in` are ID lists, and a numeric string is only an ID by luck.
         */
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

        /** The free list, after `b_slider_free_post_types` has had a say. */
        static function freePostTypes() {
            $types = apply_filters( 'b_slider_free_post_types', self::FREE_POST_TYPES );

            return is_array( $types ) ? $types : self::FREE_POST_TYPES;
        }

        /** Whether this licence may query `$post_type` at all. */
        static function isPostTypeAllowed( $post_type ) {
            if ( function_exists( 'bsbIsPremium' ) && bsbIsPremium() ) {
                return true;
            }

            return in_array( $post_type, self::freePostTypes(), true );
        }

        /** Whether `$post_type` is one the site means the public to see at all. */
        static function isPostTypePublic( $post_type ) {
            $object = get_post_type_object( $post_type );

            return $object && ! empty( $object->public );
        }

        /**
         * The ACF fields to pull for each post: the ones picked for display, plus any field
         * assigned to the image, title, description or button slot.
         *
         * Slot fields are fetched even when they are not on display, otherwise the user would
         * have to also list them under "Select ACF Fields" just to make the slot resolve — and
         * image fields are never listed there at all.
         *
         * There is no cap on how many fields may be picked — every field the user selected is
         * fetched. What this still does is filter: every path that renders a slider comes through
         * here, so a name written straight into the block markup cannot reach `get_post_meta()`
         * unless ACF actually registered it for this post type.
         */
        static function acfFieldsToFetch( $postsQuery = [], $post_type = null ) {
            $post_type = $post_type ?: ( $postsQuery['post_type'] ?? 'post' );

            $fields = $postsQuery['selectedAcfFields'] ?? [];
            $fields = is_array( $fields ) ? array_values( $fields ) : [];

            // Names come in with the request and go out to `get_post_meta()`, so they are filtered
            // down to the fields ACF registered for this post type before anything else is done
            // with them — see AcfFields::allowedFieldNames().
            $fields = self::allowedAcfFields( $fields, $post_type );

            foreach ( self::ACF_ROLE_KEYS as $key ) {
                $name = trim( (string) ( $postsQuery[ $key ] ?? '' ) );

                if ( '' !== $name && ! in_array( $name, $fields, true ) && ! empty( self::allowedAcfFields( [ $name ], $post_type ) ) ) {
                    $fields[] = $name;
                }
            }

            return $fields;
        }

        /** `$fields` with anything this post type has no registered ACF field for dropped. */
        static function allowedAcfFields( $fields, $post_type ) {
            if ( ! class_exists( __NAMESPACE__ . '\AcfFields' ) ) {
                return [];
            }

            return AcfFields::allowedFields( $fields, $post_type );
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
                        // A term whose taxonomy has no permalinks comes back as an error object.
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

                // $contentOrExcerptArr = [ 'content' => $post->post_content];
                $price = '';
                $sale = '';
                $sale_percent = '';
                if ( isset( $post->post_type ) && 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
                    $product = wc_get_product( $id );
                    if ( $product ) {
                        $price_html = $product->get_price_html();
                        $price_html = preg_replace( '/<span class="screen-reader-text">.*?<\/span>/is', '', $price_html );
                        $price = html_entity_decode( $price_html, ENT_QUOTES, 'UTF-8' );
                        
                        if ( $product->is_on_sale() ) {
                            $sale = __( 'Sale!', 'b-slider' );
                            if ( $product->is_type( 'simple' ) || $product->is_type( 'external' ) ) {
                                $regular_price = (float) $product->get_regular_price();
                                $sale_price = (float) $product->get_sale_price();
                                if ( $regular_price > 0 && $sale_price > 0 ) {
                                    $percentage = round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 );
                                    $sale_percent = '-' . $percentage . '%';
                                }
                            } else if ( $product->is_type( 'variable' ) ) {
                                $prices = $product->get_variation_prices();
                                $max_percentage = 0;
                                foreach ( $prices['price'] as $key => $var_price ) {
                                    $regular_price = (float) $prices['regular_price'][$key];
                                    $sale_price = (float) $prices['sale_price'][$key];
                                    if ( $regular_price > 0 && $sale_price > 0 && $regular_price > $sale_price ) {
                                        $p = round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 );
                                        if ( $p > $max_percentage ) {
                                            $max_percentage = $p;
                                        }
                                    }
                                }
                                if ( $max_percentage > 0 ) {
                                    $sale_percent = '-' . $max_percentage . '%';
                                }
                            }
                            if ( empty( $sale_percent ) ) {
                                $sale_percent = __( 'Sale!', 'b-slider' );
                            }
                        }
                    }
                }


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
            // Every post query setting lives inside the `postsQuery` object attribute,
            // the top level keys are only kept as a fallback for older saved blocks.
            $post_type            = $postsQuery['post_type'] ?? $attributes['post_type'] ?? 'post';
            if ( $post_type && ! self::isPostTypeAllowed( $post_type ) ) {
                $post_type = 'post';
            }
            // A separate question from the licence one above: whether the public was ever meant to
            // see this post type. The AJAX route is open to logged out visitors and takes its post
            // type from the request, so a Pro licence must not turn that into a way to read an
            // internal post type that happens to hold published rows.
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

            // Both of these arrive with the request on the AJAX route, where nothing guarantees the
            // shape the editor saves them in: a key written as a list of term IDs can come back as a
            // bare string, and `count()` or `array_filter()` on that is a fatal error rather than an
            // empty filter. They are pinned to the shape the query needs before anything reads them.
            $selectedTaxonomies   = $postsQuery['selectedTaxonomies'] ?? [];
            $selectedTaxonomies   = is_array( $selectedTaxonomies ) ? $selectedTaxonomies : [];
            $selectedCategories   = self::filterNaN( $postsQuery['selectedCategories'] ?? [] );
            $selectedTags         = self::filterNaN( $postsQuery['selectedTags'] ?? [] );

            $termsQuery = ['relation' => 'AND'];
            foreach ( $selectedTaxonomies as $taxonomy => $terms ){
                // Held to terms that really are terms of this taxonomy on this post type, the same way
                // the product and CPT branch below does it — the taxonomy name and the IDs both come
                // from the request, and a slider is only ever meant to filter by what it was given.
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
                // Products and custom post types keep their terms in the same two keys, so they go
                // through `tax_query` against whichever taxonomy the post type actually uses.
                $taxFilters = [
                    ( 'product' === $post_type ? 'product_cat' : 'category' ) => $selectedCategories,
                    ( 'product' === $post_type ? 'product_tag' : 'post_tag' ) => $selectedTags
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
                // `query()` has the last word on which post type is really queried, so the allow
                // list is built from that rather than from what the request asked for.
                self::acfFieldsToFetch( $postsQuery, $newArgs['post_type'] )
            );


            return $posts;

        }
    }
}