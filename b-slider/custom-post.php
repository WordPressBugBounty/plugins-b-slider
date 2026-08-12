<?php
namespace B_SLIDER;

if (!defined('ABSPATH')) {exit;}
 
class CustomPost{
	public $post_type = 'bsb';

	public function __construct(){
		add_action( 'init', [$this, 'onInit'], 20 );
		add_shortcode( 'bsb-slider', [$this, 'onAddShortcode'], 20 );
		add_filter( 'manage_bsb_posts_columns', [$this, 'manageLPBPostsColumns'], 10 );
		add_action( 'manage_bsb_posts_custom_column', [$this, 'manageBSBPostsCustomColumns'], 10, 2 );
		add_action( 'use_block_editor_for_post', [$this, 'useBlockEditorForPost'], 999, 2 );	
	}

	function onInit(){
		$menuIcon = "<svg width='24px' height='24px' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'><path d='M8.5 9.5L6 12L8.5 14.5' stroke='#fff' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/><path d='M15.5 9.5L18 12L15.5 14.5' stroke='#fff' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/><path d='M2 15V9C2 6.79086 3.79086 5 6 5H18C20.2091 5 22 6.79086 22 9V15C22 17.2091 20.2091 19 18 19H6C3.79086 19 2 17.2091 2 15Z' stroke='#fff' stroke-width='1.5'/></svg>";

		register_post_type( $this->post_type, [
			'labels'				=> [
				'name'			=> __( 'bSlider', 'b-slider' ),
				'singular_name'	=> __( 'bSlider', 'b-slider' ),
				'menu_name'     => __( 'bSlider', 'b-slider' ),
				'all_items'     => __( 'All Sliders', 'b-slider' ),
				'add_new'		=> __( 'Add New', 'b-slider' ),
				'add_new_item'	=> __( ' &#8627; Add New Slider', 'b-slider' ),
				'edit_item'		=> __( 'Edit', 'b-slider' ),
				'new_item'		=> __( 'New', 'b-slider' ),
				'view_item'		=> __( 'View', 'b-slider' ),
				'item_published' => __('Publish Slider', 'b-slider'),
				'item_updated'	=> __('Update Slider', 'b-slider'),
				'item_trashed'  => __('Slider trashed', 'b-slider'),
				'search_items'	=> __( 'Search', 'b-slider'),
				'not_found'		=> __( 'Sorry, we couldn\'t find the that you are looking for.', 'b-slider' )
			],
			'public'				=> false,
			'show_ui'				=> true, 		
			'show_in_rest'			=> true,							
			'publicly_queryable'	=> false,
			'exclude_from_search'	=> true,
			'menu_position'			=> 14,
			'menu_icon'				=> 'data:image/svg+xml;base64,' . base64_encode( $menuIcon ),		
			'has_archive'			=> false,
			'hierarchical'			=> false,
			'capability_type'		=> 'page',
			'rewrite'				=> [ 'slug' => 'bsb' ],
			'supports'				=> [ 'title', 'editor' ],
			'template'				=> [ ['bsb/slider'] ],
			'template_lock'			=> 'all',
		]); // Register Post Type
	}

	public function onAddShortcode( $atts ) {
        if ( empty( $atts['id'] ) ) {
            return '';
        }
        $post_id = (int) $atts['id'];
        $post = get_post( $post_id );
        if ( !$post ) {
            return '';
        }
        // The ID comes from whatever was typed into the shortcode, and this renders the first block of
        // whatever it names — so it is held to sliders. Pointed at an ordinary post it would render a
        // block out of its context, on a page that has nothing to do with it.
        if ( $this->post_type !== $post->post_type ) {
            return '';
        }
        if ( post_password_required( $post ) ) {
            return get_the_password_form( $post );
        }
        switch ( $post->post_status ) {
            case 'publish':
                return $this->displayContent( $post );
            case 'private':
                // `read_post` and not `read_private_posts`: this post type maps its capabilities onto
                // `page`, so the site-wide cap for private *posts* is not the one that governs it, and
                // it answers for this row rather than for the class of them.
                if ( current_user_can( 'read_post', $post_id ) ) {
                    return $this->displayContent( $post );
                }
                return '';
            case 'draft':
            case 'pending':
            case 'future':
                if ( current_user_can( 'edit_post', $post_id ) ) {
                    return $this->displayContent( $post );
                }
                return '';
            default:
                return '';
        }
    }
	
    public function displayContent( $post ){
        $blocks = parse_blocks( $post->post_content );

        // A slider saved with an empty editor parses to no blocks at all, and there is nothing to
        // render for it — reaching for the first one regardless is how that becomes a PHP error on
        // the visitor's page rather than an empty slot.
        if ( empty( $blocks[0] ) ) {
            return '';
        }

        return render_block( $blocks[0] );
    }

	function manageLPBPostsColumns( $defaults ) {
		unset( $defaults['date'] );
		$defaults['shortcode'] = 'ShortCode';
		$defaults['date'] = 'Date';
		return $defaults;
	}

	function manageBSBPostsCustomColumns( $column_name, $post_ID ) {
		if ( $column_name == 'shortcode' ) {
			?>
			<div class="bsbFrontShortcode" id="bsbFrontShortcode-<?php echo esc_attr( $post_ID ); ?>">
				<input value="[bsb-slider id=<?php echo esc_attr( $post_ID ); ?>]" onclick="bsbHandleShortcode( <?php echo intval( $post_ID ); ?> )">
				<span class="tooltip"><?php echo esc_html__( 'Copy To Clipboard', 'b-slider' ); ?></span>
			</div>
			<?php
		}
	}

	function useBlockEditorForPost($use, $post){
		if ($this->post_type === $post->post_type) {
			return true;
		}
		return $use;
	}
}