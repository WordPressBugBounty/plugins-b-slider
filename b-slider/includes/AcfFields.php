<?php

namespace B_SLIDER;

if ( ! defined( 'ABSPATH' ) ) exit;

class AcfFields {

    const UNSUPPORTED_TYPES = [ 'repeater', 'group', 'flexible_content', 'clone', 'tab', 'accordion', 'message' ];

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( 'bsb/v1', '/post-types', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_all_post_types' ],
            'permission_callback' => [ __CLASS__, 'can_edit' ]
        ] );

        register_rest_route( 'bsb/v1', '/acf-fields', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_acf_fields' ],
            'permission_callback' => [ __CLASS__, 'can_edit' ]
        ] );

        register_rest_route( 'bsb/v1', '/post-acf-values', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_post_acf_values' ],
            'permission_callback' => [ __CLASS__, 'can_edit' ]
        ] );

        register_rest_field( 'product', 'price', [
            'get_callback' => function( $object ) {
                return self::product_price_sale_for( $object['id'] )['price'];
            },
            'schema' => null,
        ] );

        register_rest_field( 'product', 'sale', [
            'get_callback' => function( $object ) {
                return self::product_price_sale_for( $object['id'] )['sale'];
            },
            'schema' => null,
        ] );

        register_rest_field( 'product', 'sale_percent', [
            'get_callback' => function( $object ) {
                return self::product_price_sale_for( $object['id'] )['sale_percent'];
            },
            'schema' => null,
        ] );
    }

    public static function product_price_sale_for( $post_id ) {
        return self::product_price_sale( function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null );
    }

    public static function product_price_sale( $product ) {
        $out = [ 'price' => '', 'sale' => '', 'sale_percent' => '' ];

        if ( ! $product ) {
            return $out;
        }

        $price_html = $product->get_price_html();
        $price_html = preg_replace( '/<span class="screen-reader-text">.*?<\/span>/is', '', $price_html );
        $out['price'] = html_entity_decode( $price_html, ENT_QUOTES, 'UTF-8' );

        if ( ! $product->is_on_sale() ) {
            return $out;
        }

        $out['sale'] = __( 'Sale!', 'b-slider' );

        if ( $product->is_type( 'simple' ) || $product->is_type( 'external' ) ) {
            $regular_price = (float) $product->get_regular_price();
            $sale_price = (float) $product->get_sale_price();
            if ( $regular_price > 0 && $sale_price > 0 ) {
                $percentage = round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 );
                $out['sale_percent'] = '-' . $percentage . '%';
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
                $out['sale_percent'] = '-' . $max_percentage . '%';
            }
        }

        if ( empty( $out['sale_percent'] ) ) {
            $out['sale_percent'] = __( 'Sale!', 'b-slider' );
        }

        return $out;
    }

    public static function can_edit() {
        return current_user_can( 'edit_posts' );
    }

    public function get_post_acf_values( \WP_REST_Request $request ) {
        $post_ids_str = sanitize_text_field( $request->get_param( 'post_ids' ) ?: '' );
        $fields_str   = sanitize_text_field( $request->get_param( 'fields' ) ?: '' );

        $post_ids = array_filter( array_map( 'intval', explode( ',', $post_ids_str ) ) );
        $fields   = array_filter( array_map( 'trim', explode( ',', $fields_str ) ) );

        $results = [];

        foreach ( $post_ids as $id ) {
            if ( ! current_user_can( 'read_post', $id ) ) {
                continue;
            }

            $results[ $id ] = self::get_fields_for_post( self::allowedFields( $fields, get_post_type( $id ) ), $id );
        }

        return rest_ensure_response( $results );
    }

    public static function allowedFieldNames( $post_type ) {
        static $cache = [];

        $post_type = (string) $post_type;

        if ( ! isset( $cache[ $post_type ] ) ) {
            $names = wp_list_pluck( self::fields_for_post_type( $post_type ), 'value' );
            $names = apply_filters( 'b_slider_allowed_acf_fields', $names, $post_type );
            $names = is_array( $names ) ? array_map( 'strval', $names ) : [];

            $cache[ $post_type ] = array_flip( $names );
        }

        return $cache[ $post_type ];
    }

    public static function isFieldAllowed( $field_name, $post_type ) {
        $field_name = trim( (string) $field_name );

        if ( '' === $field_name || is_protected_meta( $field_name, 'post' ) ) {
            return false;
        }

        return isset( self::allowedFieldNames( $post_type )[ $field_name ] );
    }

    public static function allowedFields( $fields, $post_type ) {
        if ( ! is_array( $fields ) ) {
            return [];
        }

        return array_values( array_filter( $fields, function( $field ) use ( $post_type ) {
            $name = is_array( $field ) ? ( $field['name'] ?? '' ) : $field;

            return self::isFieldAllowed( $name, $post_type );
        } ) );
    }

    public static function get_fields_for_post( $fields, $post_id ) {
        $data = [];

        if ( empty( $fields ) || ! is_array( $fields ) ) {
            return $data;
        }

        foreach ( $fields as $field ) {
            $name = is_array( $field ) ? ( $field['name'] ?? '' ) : $field;
            $name = trim( (string) $name );

            if ( '' === $name || isset( $data[ $name ] ) ) {
                continue;
            }

            $resolved = self::get_field_data( $name, $post_id );
            if ( ! empty( $resolved ) ) {
                $data[ $name ] = $resolved;
            }
        }

        return $data;
    }

    public static function get_field_data( $field_name, $post_id ) {
        $field_name = trim( (string) $field_name );
        $post_id    = (int) $post_id;

        if ( '' === $field_name || $post_id <= 0 ) {
            return null;
        }

        if ( ! self::isFieldAllowed( $field_name, get_post_type( $post_id ) ) ) {
            return null;
        }

        $acfObj = function_exists( 'get_field_object' ) ? get_field_object( $field_name, $post_id ) : null;
        if ( empty( $acfObj ) && function_exists( 'acf_get_field' ) ) {
            $acfObj = acf_get_field( $field_name );
        }

        $type = ! empty( $acfObj['type'] ) ? $acfObj['type'] : 'text';

        if ( in_array( $type, self::UNSUPPORTED_TYPES, true ) ) {
            return null;
        }

        $raw = null;
        if ( function_exists( 'get_field' ) ) {
            $raw = get_field( $field_name, $post_id );
        }
        if ( self::is_blank( $raw ) ) {
            $raw = get_post_meta( $post_id, $field_name, true );
        }
        if ( self::is_blank( $raw ) && isset( $acfObj['default_value'] ) && ! self::is_blank( $acfObj['default_value'] ) ) {
            $raw = $acfObj['default_value'];
        }

        if ( self::is_blank( $raw ) ) {
            if ( 'true_false' !== $type || ! metadata_exists( 'post', $post_id, $field_name ) ) {
                return null;
            }
            $raw = 0;
        }

        $formatted = self::format_value( $raw, $type, $acfObj );

        if ( '' === $formatted['value'] ) {
            return null;
        }

        return array_merge( [
            'name'  => $field_name,
            'label' => self::field_label( $acfObj, $field_name ),
            'type'  => $type
        ], $formatted );
    }

    private static function field_label( $acfObj, $field_name ) {
        return ! empty( $acfObj['label'] )
            ? $acfObj['label']
            : ucwords( str_replace( [ '_', '-' ], ' ', $field_name ) );
    }

    public static function format_value( $raw, $type, $acfObj = null ) {
        $out = [ 'value' => '', 'url' => '' ];

        switch ( $type ) {
            case 'image':
                $img          = self::image_parts( $raw );
                $out['value'] = $img['url'];
                $out['url']   = $img['url'];
                $out['alt']   = $img['alt'];
                break;

            case 'gallery':
                $images = array_values( array_filter(
                    array_map( [ __CLASS__, 'image_parts' ], is_array( $raw ) ? array_values( $raw ) : [] ),
                    function( $img ) { return '' !== $img['url']; }
                ) );
                $out['value'] = $images ? $images[0]['url'] : '';
                $out['url']   = $out['value'];
                $out['alt']   = $images ? $images[0]['alt'] : '';
                $out['items'] = $images;
                $out['count'] = count( $images );
                break;

            case 'file':
                if ( is_array( $raw ) ) {
                    $out['url']   = (string) ( $raw['url'] ?? '' );
                    $out['value'] = (string) ( $raw['title'] ?? $raw['filename'] ?? $out['url'] );
                } elseif ( is_numeric( $raw ) ) {
                    $out['url']   = (string) wp_get_attachment_url( (int) $raw );
                    $out['value'] = get_the_title( (int) $raw ) ?: $out['url'];
                } else {
                    $out['value'] = (string) $raw;
                    $out['url']   = (string) $raw;
                }
                break;

            case 'link':
                if ( is_array( $raw ) ) {
                    $out['url']    = (string) ( $raw['url'] ?? '' );
                    $out['value']  = ! empty( $raw['title'] ) ? (string) $raw['title'] : $out['url'];
                    $out['target'] = (string) ( $raw['target'] ?? '' );
                } else {
                    $out['value'] = (string) $raw;
                    $out['url']   = (string) $raw;
                }
                break;

            case 'url':
                $out['value'] = (string) $raw;
                $out['url']   = (string) $raw;
                break;

            case 'email':
                $out['value'] = (string) $raw;
                $out['url']   = 'mailto:' . $raw;
                break;

            case 'date_picker':
                $out['value'] = self::format_date( $raw, get_option( 'date_format' ) );
                break;

            case 'date_time_picker':
                $out['value'] = self::format_date( $raw, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
                break;

            case 'time_picker':
                $out['value'] = self::format_date( $raw, get_option( 'time_format' ) );
                break;

            case 'true_false':
                $on  = ! empty( $acfObj['ui_on_text'] ) ? $acfObj['ui_on_text'] : __( 'Yes', 'b-slider' );
                $off = ! empty( $acfObj['ui_off_text'] ) ? $acfObj['ui_off_text'] : __( 'No', 'b-slider' );
                $out['value'] = ( $raw && '0' !== $raw ) ? $on : $off;
                break;

            case 'select':
            case 'radio':
            case 'checkbox':
            case 'button_group':
                $out['value'] = self::choice_labels( $raw, $acfObj );
                break;

            case 'post_object':
            case 'relationship':
            case 'page_link':
                $out['value'] = self::join( self::map( $raw, [ __CLASS__, 'post_title_of' ] ) );
                break;

            case 'taxonomy':
                $out['value'] = self::join( self::map( $raw, [ __CLASS__, 'term_name_of' ] ) );
                break;

            case 'user':
                $out['value'] = self::join( self::map( $raw, [ __CLASS__, 'user_name_of' ] ) );
                break;

            case 'google_map':
                $out['value'] = is_array( $raw ) ? (string) ( $raw['address'] ?? '' ) : (string) $raw;
                break;

            case 'wysiwyg':
            case 'textarea':
            case 'oembed':
                $out['value'] = wp_strip_all_tags( (string) $raw );
                break;

            default:
                $out['value'] = self::stringify( $raw );
        }

        $out['value'] = trim( (string) $out['value'] );

        return $out;
    }

    private static function is_blank( $val ) {
        return null === $val || false === $val || '' === $val || [] === $val;
    }

    private static function image_parts( $val ) {
        if ( is_array( $val ) ) {
            return [
                'url' => (string) ( $val['url'] ?? '' ),
                'alt' => (string) ( $val['alt'] ?? '' )
            ];
        }

        if ( is_numeric( $val ) ) {
            $id = (int) $val;
            return [
                'url' => (string) wp_get_attachment_url( $id ),
                'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true )
            ];
        }

        return [ 'url' => (string) $val, 'alt' => '' ];
    }

    private static function format_date( $val, $format ) {
        $val = is_array( $val ) ? '' : trim( (string) $val );

        if ( '' === $val ) {
            return '';
        }

        $timestamp = strtotime( $val );

        return $timestamp ? date_i18n( $format, $timestamp ) : $val;
    }

    private static function choice_labels( $val, $acfObj ) {
        $choices = ( isset( $acfObj['choices'] ) && is_array( $acfObj['choices'] ) ) ? $acfObj['choices'] : [];

        return self::join( self::map( $val, function( $item ) use ( $choices ) {
            if ( is_array( $item ) ) {
                return (string) ( $item['label'] ?? $item['value'] ?? '' );
            }

            $key = (string) $item;
            return isset( $choices[ $key ] ) ? (string) $choices[ $key ] : $key;
        } ) );
    }

    private static function map( $val, $callback ) {
        if ( is_array( $val ) ) {
            if ( empty( $val ) ) {
                return [];
            }
            $items = self::is_assoc( $val ) ? [ $val ] : array_values( $val );
        } else {
            $items = [ $val ];
        }

        return array_map( $callback, $items );
    }

    private static function is_assoc( array $arr ) {
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }

    private static function join( $parts ) {
        $parts = array_map( function( $p ) {
            return is_scalar( $p ) ? trim( (string) $p ) : '';
        }, (array) $parts );

        return implode( ', ', array_filter( $parts, 'strlen' ) );
    }

    private static function post_title_of( $item ) {
        if ( $item instanceof \WP_Post ) {
            return $item->post_title;
        }
        if ( is_numeric( $item ) ) {
            return (string) get_the_title( (int) $item );
        }
        if ( is_array( $item ) ) {
            return (string) ( $item['post_title'] ?? $item['title'] ?? '' );
        }

        return (string) $item;
    }

    private static function term_name_of( $item ) {
        if ( $item instanceof \WP_Term ) {
            return $item->name;
        }
        if ( is_array( $item ) ) {
            return (string) ( $item['name'] ?? '' );
        }
        if ( is_numeric( $item ) ) {
            $term = get_term( (int) $item );
            return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
        }

        return (string) $item;
    }

    private static function user_name_of( $item ) {
        if ( $item instanceof \WP_User ) {
            return $item->display_name;
        }
        if ( is_array( $item ) ) {
            return (string) ( $item['display_name'] ?? $item['user_nicename'] ?? '' );
        }
        if ( is_numeric( $item ) ) {
            $user = get_userdata( (int) $item );
            return $user ? $user->display_name : '';
        }

        return (string) $item;
    }

    private static function stringify( $val ) {
        if ( is_bool( $val ) ) {
            return $val ? '1' : '0';
        }
        if ( is_scalar( $val ) ) {
            return (string) $val;
        }
        if ( ! is_array( $val ) || empty( $val ) ) {
            return '';
        }

        if ( self::is_assoc( $val ) ) {
            foreach ( [ 'label', 'title', 'name', 'post_title', 'display_name', 'address', 'url' ] as $key ) {
                if ( ! empty( $val[ $key ] ) && is_scalar( $val[ $key ] ) ) {
                    return (string) $val[ $key ];
                }
            }
            return '';
        }

        return self::join( array_map( [ __CLASS__, 'stringify' ], $val ) );
    }

    public function get_all_post_types() {
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        $excluded   = [ 'attachment', 'bsb', 'apb', 'nav_menu_item', 'revision', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ];
        $list       = [];

        foreach ( $post_types as $slug => $pt ) {
            if ( in_array( $slug, $excluded, true ) || strpos( $slug, 'wp_' ) === 0 ) {
                continue;
            }
            $list[] = [
                'value' => $slug,
                'label' => ! empty( $pt->labels->name ) ? $pt->labels->name : ( ! empty( $pt->label ) ? $pt->label : ucfirst( $slug ) ),
                'singular' => ! empty( $pt->labels->singular_name ) ? $pt->labels->singular_name : ucfirst( $slug ),
                'locked' => ! Posts::isPostTypeAllowed( $slug )
            ];
        }

        return rest_ensure_response( $list );
    }

    private static function group_targets_post_type( $group, $post_type ) {
        $location = ( ! empty( $group['location'] ) && is_array( $group['location'] ) ) ? $group['location'] : [];

        if ( empty( $location ) ) {
            return true;
        }

        foreach ( $location as $rule_group ) {
            if ( ! is_array( $rule_group ) ) {
                continue;
            }

            $matched = true;

            foreach ( $rule_group as $rule ) {
                if ( ! is_array( $rule ) || ( $rule['param'] ?? '' ) !== 'post_type' ) {
                    continue;
                }

                $operator = $rule['operator'] ?? '==';
                $value    = (string) ( $rule['value'] ?? '' );

                if ( '==' === $operator && $value !== $post_type ) {
                    $matched = false;
                    break;
                }
                if ( '!=' === $operator && $value === $post_type ) {
                    $matched = false;
                    break;
                }
            }

            if ( $matched ) {
                return true;
            }
        }

        return false;
    }

    public function get_acf_fields( \WP_REST_Request $request ) {
        $post_type = sanitize_text_field( $request->get_param( 'post_type' ) ?: 'post' );

        return rest_ensure_response( [
            'isActive' => self::acf_is_active(),
            'fields'   => self::fields_for_post_type( $post_type )
        ] );
    }

    private static function acf_is_active() {
        return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' );
    }

    public static function fields_for_post_type( $post_type ) {
        if ( ! self::acf_is_active() ) {
            return [];
        }

        $field_groups = acf_get_field_groups( [ 'post_type' => $post_type ] );
        $fields_list  = [];
        $seen         = [];

        foreach ( (array) $field_groups as $group ) {
            if ( empty( $group['key'] ) || ! self::group_targets_post_type( $group, $post_type ) ) {
                continue;
            }

            $fields = acf_get_fields( $group['key'] );
            if ( ! empty( $fields ) && is_array( $fields ) ) {
                foreach ( $fields as $field ) {
                    if ( empty( $field['name'] ) || isset( $seen[ $field['name'] ] ) ) {
                        continue;
                    }
                    if ( in_array( $field['type'] ?? 'text', self::UNSUPPORTED_TYPES, true ) ) {
                        continue;
                    }
                    $seen[ $field['name'] ] = true;
                    $fields_list[] = [
                        'value' => $field['name'],
                        'label' => self::field_label( $field, $field['name'] ),
                        'type'  => $field['type'] ?? 'text'
                    ];
                }
            }
        }

        return $fields_list;
    }
}

new AcfFields();
