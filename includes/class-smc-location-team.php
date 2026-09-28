<?php
/**
 * Team and doctor profiles by location.
 *
 * Each person is a Team entry (Locations > Team): name, photo (featured image), bio,
 * doctor or team member, job title, credentials, display order, and one or more
 * locations. Show them with:
 *
 *   [location_team type="doctors"]    the page location's doctors
 *   [location_team type="team"]       everyone else on the team
 *   [location_team]                   everyone
 *   Options: columns="3" bio="short|full|none" words="40" location="kenton" (or "all")
 *
 * Or design them in Elementor: a Loop Grid / Loop Carousel with Query ID
 *   location_team      everyone          location_doctors   doctors only
 *   location_staff     team members only
 * and [team field="..."] or dynamic tags in the Loop Item.
 *
 * Every profile has a menu anchor (e.g. #dr-jane-lee), so a menu item or button can link
 * straight to it: /kenton/meet-the-doctors/#dr-jane-lee
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Team {

	const TYPE  = 'smc_team';
	const TAX   = 'location_category';
	const KINDS = [ 'doctor' => 'Doctor', 'team' => 'Team member' ];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ], 99 );
		add_action( 'after_setup_theme', [ __CLASS__, 'thumbnails' ], 20 );
		add_action( 'acf/init', [ __CLASS__, 'register_fields' ] );
		add_filter( 'acf/prepare_field/key=field_smc_team_anchor', [ __CLASS__, 'anchor_placeholder' ] );
		add_action( 'init', [ __CLASS__, 'register_shortcodes' ], 20 );
		foreach ( [ 'location_team', 'location_doctors', 'location_staff' ] as $qid ) {
			add_action( "elementor/query/$qid", [ __CLASS__, 'elementor_query' ] );
		}

		if ( is_admin() ) {
			add_filter( 'enter_title_here', [ __CLASS__, 'title_placeholder' ], 10, 2 );
			add_action( 'add_meta_boxes_' . self::TYPE, [ __CLASS__, 'meta_boxes' ] );
			add_action( 'save_post_' . self::TYPE, [ __CLASS__, 'save_box' ], 10, 2 );
			add_filter( 'manage_' . self::TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
			add_action( 'manage_' . self::TYPE . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
			add_action( 'restrict_manage_posts', [ __CLASS__, 'filters' ] );
			add_action( 'pre_get_posts', [ __CLASS__, 'apply_list_filters' ] );
		}
	}

	/* ========== Registration ========== */

	public static function register() {
		register_post_type(
			self::TYPE,
			[
				'labels'              => [
					'name'               => 'Team',
					'singular_name'      => 'Team Member',
					'add_new'            => 'Add Team Member',
					'add_new_item'       => 'Add Team Member',
					'edit_item'          => 'Edit Team Member',
					'search_items'       => 'Search Team',
					'not_found'          => 'No team members yet.',
					'all_items'          => 'Team',
					'featured_image'     => 'Photo',
					'set_featured_image' => 'Set photo',
					'remove_featured_image' => 'Remove photo',
					'use_featured_image' => 'Use as photo',
				],
				// Listed as an Elementor Loop source and previewable in Loop Items, but no public URLs.
				'public'              => true,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_in_nav_menus'   => true,
				'rewrite'             => false,
				'query_var'           => false,
				'has_archive'         => false,
				'show_ui'             => true,
				'show_in_menu'        => class_exists( 'SMC_Location_Manager' ) ? SMC_Location_Manager::SLUG : true,
				'show_in_rest'        => false,
				'supports'            => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
				'map_meta_cap'        => true,
			]
		);
		if ( taxonomy_exists( self::TAX ) ) {
			register_taxonomy_for_object_type( self::TAX, self::TYPE );
		}
	}

	public static function thumbnails() {
		add_theme_support( 'post-thumbnails', [ self::TYPE ] );
	}

	public static function register_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		acf_add_local_field_group(
			[
				'key'      => 'group_smc_team',
				'title'    => 'Team member details',
				'fields'   => [
					[
						'key'           => 'field_smc_team_type',
						'label'         => 'Shows as',
						'name'          => 'team_type',
						'type'          => 'button_group',
						'choices'       => self::KINDS,
						'default_value' => 'team',
						'wrapper'       => [ 'width' => '30' ],
					],
					[
						'key'          => 'field_smc_team_title',
						'label'        => 'Job title',
						'name'         => 'job_title',
						'type'         => 'text',
						'placeholder'  => 'e.g. General Dentist, Office Manager',
						'wrapper'      => [ 'width' => '40' ],
					],
					[
						'key'          => 'field_smc_team_credentials',
						'label'        => 'Credentials',
						'name'         => 'credentials',
						'type'         => 'text',
						'placeholder'  => 'e.g. DDS',
						'instructions' => 'Shown after the name: "Jane Lee, DDS".',
						'wrapper'      => [ 'width' => '30' ],
					],
					[
						'key'          => 'field_smc_team_anchor',
						'label'        => 'Menu anchor',
						'name'         => 'anchor',
						'type'         => 'text',
						'prepend'      => '#',
						'instructions' => 'Link straight to this profile by adding this to the page URL, e.g. /springfield/meet-the-doctors/#dr-jane-lee. Leave blank to use the name.',
					],
				],
				'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => self::TYPE ] ] ],
				'position' => 'acf_after_title',
			]
		);
	}

	/* ========== Edit screen ========== */

	public static function title_placeholder( $text, $post ) {
		return self::TYPE === $post->post_type ? 'Full name, e.g. Dr. Jane Lee' : $text;
	}

	public static function meta_boxes() {
		remove_meta_box( self::TAX . 'div', self::TYPE, 'side' );
		remove_meta_box( 'tagsdiv-' . self::TAX, self::TYPE, 'side' );
		add_meta_box( 'smc_team_locations', 'Locations', [ __CLASS__, 'locations_box' ], self::TYPE, 'side', 'high' );
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			add_meta_box( 'smc_team_details', 'Team member details', [ __CLASS__, 'details_box' ], self::TYPE, 'normal', 'high' );
		}
	}

	public static function locations_box( $post ) {
		wp_nonce_field( 'smc_team_box', 'smc_team_nonce' );
		$set = wp_get_object_terms( $post->ID, self::TAX, [ 'fields' => 'ids' ] );
		$set = is_wp_error( $set ) ? [] : array_map( 'intval', $set );
		if ( 'auto-draft' === $post->post_status && isset( $_GET['location'] ) ) {
			$set = [ absint( $_GET['location'] ) ];
		}
		echo '<p class="description" style="margin-top:0">Where this person is shown. Pick more than one if they work at several offices.</p>';
		foreach ( SMC_Location_Manager::locations() as $t ) {
			printf(
				'<label style="display:block;margin:4px 0"><input type="checkbox" name="smc_team_locations[]" value="%d" %s> %s</label>',
				(int) $t->term_id,
				checked( in_array( (int) $t->term_id, $set, true ), true, false ),
				esc_html( $t->name )
			);
		}
		echo '<p class="description">Order: set <strong>Order</strong> under Page Attributes; lower numbers show first.</p>';
	}

	/** Fallback fields when ACF isn't installed. */
	public static function details_box( $post ) {
		$type = get_post_meta( $post->ID, 'team_type', true ) ?: 'team';
		echo '<p>';
		foreach ( self::KINDS as $k => $label ) {
			printf( '<label style="margin-right:16px"><input type="radio" name="smc_team[team_type]" value="%s" %s> %s</label>', esc_attr( $k ), checked( $type, $k, false ), esc_html( $label ) );
		}
		echo '</p>';
		foreach ( [ 'job_title' => 'Job title', 'credentials' => 'Credentials', 'anchor' => 'Menu anchor (leave blank to use the name)' ] as $k => $label ) {
			printf( '<p><label>%s<br><input name="smc_team[%s]" class="regular-text" value="%s"></label></p>', esc_html( $label ), esc_attr( $k ), esc_attr( get_post_meta( $post->ID, $k, true ) ) );
		}
	}

	public static function save_box( $post_id ) {
		if ( ! isset( $_POST['smc_team_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['smc_team_nonce'] ), 'smc_team_box' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		wp_set_object_terms( $post_id, array_map( 'absint', (array) ( $_POST['smc_team_locations'] ?? [] ) ), self::TAX );
		if ( isset( $_POST['smc_team'] ) && is_array( $_POST['smc_team'] ) ) {
			$in = wp_unslash( $_POST['smc_team'] );
			update_post_meta( $post_id, 'team_type', isset( self::KINDS[ $in['team_type'] ?? '' ] ) ? $in['team_type'] : 'team' );
			update_post_meta( $post_id, 'job_title', sanitize_text_field( $in['job_title'] ?? '' ) );
			update_post_meta( $post_id, 'credentials', sanitize_text_field( $in['credentials'] ?? '' ) );
			update_post_meta( $post_id, 'anchor', sanitize_title( $in['anchor'] ?? '' ) );
		}
	}

	/* ========== List screen ========== */

	public static function columns( $cols ) {
		unset( $cols[ 'taxonomy-' . self::TAX ] );
		$out = [ 'cb' => $cols['cb'] ?? '', 'smc_photo' => '' ];
		foreach ( $cols as $k => $v ) {
			if ( 'cb' === $k ) {
				continue;
			}
			if ( 'title' === $k ) {
				$out['title']     = 'Name';
				$out['smc_type']  = 'Shows as';
				$out['smc_title'] = 'Job title';
				$out['smc_loc']   = 'Locations';
				$out['smc_order'] = 'Order';
			} elseif ( 'date' !== $k ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	public static function column( $col, $post_id ) {
		if ( 'smc_photo' === $col ) {
			echo get_the_post_thumbnail( $post_id, [ 48, 48 ], [ 'style' => 'width:48px;height:48px;object-fit:cover;border-radius:50%' ] ) ?: '<span style="display:inline-block;width:48px;height:48px;border-radius:50%;background:#dcdcde"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'smc_type' === $col ) {
			echo esc_html( self::KINDS[ get_post_meta( $post_id, 'team_type', true ) ] ?? 'Team member' );
		} elseif ( 'smc_title' === $col ) {
			echo esc_html( trim( get_post_meta( $post_id, 'job_title', true ) . ( get_post_meta( $post_id, 'credentials', true ) ? ' (' . get_post_meta( $post_id, 'credentials', true ) . ')' : '' ) ) );
			echo '<br><code class="smc-copy" title="Menu anchor. Click to copy">#' . esc_html( self::anchor( $post_id ) ) . '</code>';
		} elseif ( 'smc_loc' === $col ) {
			$terms = wp_get_object_terms( $post_id, self::TAX );
			if ( is_wp_error( $terms ) || ! $terms ) {
				echo '<span style="color:#b32d2e">None</span>';
				return;
			}
			$links = [];
			foreach ( $terms as $t ) {
				$links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::TYPE . '&smc_location=' . $t->term_id ) ) . '">' . esc_html( $t->name ) . '</a>';
			}
			echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'smc_order' === $col ) {
			echo (int) get_post_field( 'menu_order', $post_id );
		}
	}

	public static function filters( $post_type ) {
		if ( self::TYPE !== $post_type ) {
			return;
		}
		$loc  = absint( $_GET['smc_location'] ?? 0 );
		$kind = sanitize_key( $_GET['smc_kind'] ?? '' );
		echo '<select name="smc_location"><option value="">All locations</option>';
		foreach ( SMC_Location_Manager::locations() as $t ) {
			printf( '<option value="%d" %s>%s</option>', (int) $t->term_id, selected( $loc, (int) $t->term_id, false ), esc_html( $t->name ) );
		}
		echo '</select><select name="smc_kind"><option value="">Doctors and team</option>';
		foreach ( self::KINDS as $k => $label ) {
			printf( '<option value="%s" %s>%ss</option>', esc_attr( $k ), selected( $kind, $k, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public static function apply_list_filters( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || self::TYPE !== $q->get( 'post_type' ) ) {
			return;
		}
		if ( ! empty( $_GET['smc_location'] ) ) {
			$q->set( 'tax_query', [ [ 'taxonomy' => self::TAX, 'terms' => absint( $_GET['smc_location'] ) ] ] );
		}
		if ( ! empty( $_GET['smc_kind'] ) ) {
			$q->set( 'meta_query', self::kind_query( sanitize_key( $_GET['smc_kind'] ) ) );
		}
		if ( ! $q->get( 'orderby' ) ) {
			$q->set( 'orderby', [ 'menu_order' => 'ASC', 'title' => 'ASC' ] );
		}
	}

	/* ========== Queries ========== */

	/** Meta query for doctors or team members ("team" also matches entries with no type set). */
	private static function kind_query( $kind ) {
		if ( 'doctor' === $kind ) {
			return [ [ 'key' => 'team_type', 'value' => 'doctor' ] ];
		}
		return [
			'relation' => 'OR',
			[ 'key' => 'team_type', 'value' => 'doctor', 'compare' => '!=' ],
			[ 'key' => 'team_type', 'compare' => 'NOT EXISTS' ],
		];
	}

	private static function normalize_kind( $kind ) {
		$kind = strtolower( trim( (string) $kind ) );
		if ( in_array( $kind, [ 'doctor', 'doctors', 'dentist', 'dentists', 'provider', 'providers' ], true ) ) {
			return 'doctor';
		}
		if ( in_array( $kind, [ 'team', 'staff', 'team member', 'team members' ], true ) ) {
			return 'team';
		}
		return '';
	}

	public static function count( $term_id ) {
		$q = new WP_Query(
			[
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => [ [ 'taxonomy' => self::TAX, 'terms' => (int) $term_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		return (int) $q->found_posts;
	}

	public static function elementor_query( $query ) {
		$query->set( 'post_type', self::TYPE );
		$query->set( 'post_status', 'publish' );
		$query->set( 'orderby', [ 'menu_order' => 'ASC', 'title' => 'ASC' ] );
		$tid = SMC_Location_Fields::listing_location_id();
		if ( $tid ) {
			$query->set( 'tax_query', [ [ 'taxonomy' => self::TAX, 'terms' => $tid ] ] );
		}
		$qid = current_filter();
		if ( 'elementor/query/location_doctors' === $qid ) {
			$query->set( 'meta_query', self::kind_query( 'doctor' ) );
		} elseif ( 'elementor/query/location_staff' === $qid ) {
			$query->set( 'meta_query', self::kind_query( 'team' ) );
		}
	}

	/* ========== Shortcodes ========== */

	public static function register_shortcodes() {
		if ( ! shortcode_exists( 'location_team' ) ) {
			add_shortcode( 'location_team', [ __CLASS__, 'shortcode' ] );
		}
		if ( ! shortcode_exists( 'team' ) ) {
			add_shortcode( 'team', [ __CLASS__, 'field_shortcode' ] );
		}
	}

	public static function shortcode( $atts ) {
		$a = shortcode_atts(
			[
				'type'     => 'all',
				'location' => '',
				'columns'  => 3,
				'limit'    => 0,
				'bio'      => 'short',
				'words'    => 40,
				'photo'    => 'medium_large',
				'shape'    => 'square',
				'offset'   => '',
				'show_location' => 'auto',
			],
			$atts,
			'location_team'
		);

		$args = [
			'post_type'      => self::TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $a['limit'] > 0 ? (int) $a['limit'] : -1,
			'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'no_found_rows'  => true,
		];
		$tid = SMC_Location_Fields::listing_location_id( $a['location'] );
		if ( $tid ) {
			$args['tax_query'] = [ [ 'taxonomy' => self::TAX, 'terms' => $tid ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		// Which office each person is at: shown by default when listing every location (e.g. Corporate).
		$show_loc = 'auto' === strtolower( $a['show_location'] ) ? ! $tid : in_array( strtolower( $a['show_location'] ), [ 'yes', 'true', '1', 'on' ], true );
		$kind = self::normalize_kind( $a['type'] );
		if ( $kind ) {
			$args['meta_query'] = self::kind_query( $kind ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$people = get_posts( $args );
		if ( ! $people ) {
			return '';
		}
		$cols  = max( 1, min( 4, (int) $a['columns'] ) );
		$round = 'circle' === $a['shape'] ? ' smc-team-circle' : '';
		$offset = preg_match( '/^\d{1,3}$/', trim( (string) $a['offset'] ) ) ? ';--smc-anchor-offset:' . trim( $a['offset'] ) . 'px' : '';
		$out    = self::css() . '<div class="smc-team' . $round . '" style="--smc-team-cols:' . $cols . $offset . '">';

		foreach ( $people as $p ) {
			$out .= '<div class="smc-team-member" id="' . esc_attr( self::anchor( $p->ID ) ) . '">';
			if ( has_post_thumbnail( $p ) ) {
				$out .= '<div class="smc-team-photo">' . get_the_post_thumbnail( $p, sanitize_key( $a['photo'] ) ?: 'medium_large', [ 'alt' => $p->post_title ] ) . '</div>';
			}
			$out .= '<div class="smc-team-name">' . esc_html( self::name_with_credentials( $p->ID ) ) . '</div>';
			$title = (string) get_post_meta( $p->ID, 'job_title', true );
			if ( '' !== $title ) {
				$out .= '<div class="smc-team-title">' . esc_html( $title ) . '</div>';
			}
			$locs = $show_loc ? SMC_Location_Fields::location_names( $p->ID, true ) : '';
			if ( '' !== $locs ) {
				$out .= '<div class="smc-team-locations">' . $locs . '</div>';
			}
			if ( 'none' !== $a['bio'] && '' !== trim( $p->post_content ) ) {
				$bio  = 'full' === $a['bio'] ? apply_filters( 'the_content', $p->post_content ) : wpautop( esc_html( wp_trim_words( wp_strip_all_tags( $p->post_content ), max( 5, (int) $a['words'] ) ) ) );
				$out .= '<div class="smc-team-bio">' . $bio . '</div>';
			}
			$out .= '</div>';
		}
		return $out . '</div>';
	}

	/** The profile's menu anchor: the Menu anchor field, or the name as a slug ("dr-jane-lee"). */
	public static function anchor( $post_id ) {
		$set = sanitize_title( (string) get_post_meta( $post_id, 'anchor', true ) );
		return '' !== $set ? $set : sanitize_title( get_the_title( $post_id ) );
	}

	/** Shows the automatic anchor as the field's placeholder. */
	public static function anchor_placeholder( $field ) {
		global $post;
		if ( $post && self::TYPE === $post->post_type && $post->post_title ) {
			$field['placeholder'] = sanitize_title( $post->post_title );
		} else {
			$field['placeholder'] = 'dr-jane-lee';
		}
		return $field;
	}

	public static function name_with_credentials( $post_id ) {
		$cred = trim( (string) get_post_meta( $post_id, 'credentials', true ) );
		return get_the_title( $post_id ) . ( '' !== $cred ? ", $cred" : '' );
	}

	/**
	 * [team field="..."] - one part of the current team member, for Elementor Loop Items.
	 *   name, name_credentials, credentials, title, type, bio (words="40" to shorten), photo, photo_url,
	 *   locations (the offices they work at; link="yes" links each to its location page)
	 */
	public static function field_shortcode( $atts ) {
		$a  = shortcode_atts( [ 'field' => 'name', 'words' => 0, 'size' => 'medium_large', 'offset' => '', 'link' => '' ], $atts, 'team' );
		$id = get_the_ID();
		if ( ! $id || self::TYPE !== get_post_type( $id ) ) {
			return '';
		}
		switch ( strtolower( $a['field'] ) ) {
			case 'name_credentials':
				return esc_html( self::name_with_credentials( $id ) );
			case 'credentials':
				return esc_html( (string) get_post_meta( $id, 'credentials', true ) );
			case 'anchor':
				// An invisible anchor to put at the top of a Loop Item.
				$style = preg_match( '/^\d{1,3}$/', trim( (string) $a['offset'] ) ) ? ' style="--smc-anchor-offset:' . trim( $a['offset'] ) . 'px"' : '';
				return self::css() . '<span class="smc-team-anchor" id="' . esc_attr( self::anchor( $id ) ) . '"' . $style . '></span>';
			case 'anchor_id':
				return esc_attr( self::anchor( $id ) );
			case 'title':
				return esc_html( (string) get_post_meta( $id, 'job_title', true ) );
			case 'locations':
			case 'location':
				return SMC_Location_Fields::location_names( $id, in_array( strtolower( (string) $a['link'] ), [ 'yes', 'true', '1' ], true ) );
			case 'type':
				return esc_html( self::KINDS[ get_post_meta( $id, 'team_type', true ) ] ?? 'Team member' );
			case 'photo':
				return get_the_post_thumbnail( $id, sanitize_key( $a['size'] ) ?: 'medium_large' );
			case 'photo_url':
				return esc_url( (string) get_the_post_thumbnail_url( $id, sanitize_key( $a['size'] ) ?: 'medium_large' ) );
			case 'bio':
				$text = wp_strip_all_tags( get_post_field( 'post_content', $id ) );
				return (int) $a['words'] > 0 ? esc_html( wp_trim_words( $text, (int) $a['words'] ) ) : wpautop( esc_html( $text ) );
			default:
				return esc_html( get_the_title( $id ) );
		}
	}

	private static function css() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style id="smc-team-css">'
			. '.smc-team{display:grid;gap:32px;grid-template-columns:repeat(var(--smc-team-cols,3),minmax(0,1fr))}'
			. '@media (max-width:1024px){.smc-team:not([style*="cols:1"]){grid-template-columns:repeat(2,minmax(0,1fr))}}'
			. '@media (max-width:767px){.smc-team{grid-template-columns:1fr}}'
			. '.smc-team-photo img{width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;display:block}'
			. '.smc-team-circle .smc-team-photo img{border-radius:50%}'
			. '.smc-team-name{font-weight:700;margin-top:14px;font-size:1.15em}'
			. '.smc-team-title{opacity:.8;margin-top:2px}'
			. '.smc-team-locations{font-size:.9em;opacity:.8;margin-top:2px}'
			. '.smc-team-bio{margin-top:10px}.smc-team-bio p:last-child{margin-bottom:0}'
			. '.smc-team-member,.smc-team-anchor{scroll-margin-top:var(--smc-anchor-offset,120px)}'
			. '.smc-team-anchor{display:block;height:0;overflow:hidden}'
			. '</style>';
	}

	/* ========== Import (used by Export / Import) ========== */

	/**
	 * Creates a team member, or adds locations to an existing one with the same name.
	 * Returns the new ID, 0 if they already existed, or a WP_Error.
	 */
	public static function create( array $m, array $locations, $photo_id = 0 ) {
		$name = trim( sanitize_text_field( $m['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'empty', 'Missing name.' );
		}
		$existing = get_posts( [ 'post_type' => self::TYPE, 'post_status' => 'any', 'title' => $name, 'fields' => 'ids', 'numberposts' => 1 ] );
		if ( $existing ) {
			if ( $locations ) {
				wp_set_object_terms( $existing[0], array_map( 'intval', $locations ), self::TAX, true );
			}
			if ( $photo_id && ! has_post_thumbnail( $existing[0] ) ) {
				set_post_thumbnail( $existing[0], $photo_id );
			}
			return 0;
		}
		$id = wp_insert_post(
			[
				'post_type'    => self::TYPE,
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_content' => wp_kses_post( $m['bio'] ?? '' ),
				'menu_order'   => (int) ( $m['order'] ?? 0 ),
				'meta_input'   => [
					'team_type'    => isset( self::KINDS[ $m['type'] ?? '' ] ) ? $m['type'] : 'team',
					'_team_type'   => 'field_smc_team_type',
					'job_title'    => sanitize_text_field( $m['title'] ?? '' ),
					'_job_title'   => 'field_smc_team_title',
					'credentials'  => sanitize_text_field( $m['credentials'] ?? '' ),
					'_credentials' => 'field_smc_team_credentials',
					'anchor'       => sanitize_title( $m['anchor'] ?? '' ),
					'_anchor'      => 'field_smc_team_anchor',
				],
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( $locations ) {
			wp_set_object_terms( $id, array_map( 'intval', $locations ), self::TAX );
		}
		if ( $photo_id ) {
			set_post_thumbnail( $id, $photo_id );
		}
		return $id;
	}
}
