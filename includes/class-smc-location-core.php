<?php
/**
 * The core SMC location system, previously spread across CPT UI, a WPCode snippet and
 * an ACF field group on each site:
 *
 *   - location_category and page_type taxonomies (only registered if the site doesn't
 *     already register them, e.g. through CPT UI)
 *   - [location field="..."] and [current_slug]
 *   - the base location fields (city/state, address, phone, booking button), with the
 *     same ACF field keys SMC sites already use, so existing data carries over as is
 *
 * On an existing site nothing changes until you choose to: the plugin notices what the
 * site already has and shows it under Locations > Settings > Location system.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Core {

	const TAX        = 'location_category';
	const PAGE_TYPES = 'page_type';
	const BASE_GROUP = 'group_smc_location_base';

	/** SMC's standard base fields, with the field keys existing sites use. */
	const BASE_FIELDS = [
		'city_state'      => [ 'field_64d395506a948', 'City and state', 'text', 'e.g. Kenton, OH' ],
		'address'         => [ 'field_64d395776a949', 'Address', 'text', 'Street, then <br>, then city, state and zip. e.g. 965 E Columbus St<br>Kenton, OH 43326' ],
		'phone_label'     => [ 'field_64d395936a94a', 'Phone', 'text', 'As it should appear, e.g. 419-848-0722' ],
		'phone_link'      => [ 'field_64d395ea6a94b', 'Phone link', 'text', 'e.g. tel:419-848-0722 (the Edit screen under Locations fills this in from the phone automatically)' ],
		'booking_label'   => [ 'field_64d3964b53614', 'Booking button text', 'text', 'e.g. Book Appointment' ],
		'booking_link'    => [ 'field_64d3965753615', 'Booking link', 'text', 'The booking form URL, e.g. https://form.jotform.com/...' ],
		'booking_classes' => [ 'field_64d3966f53616', 'Booking button CSS classes', 'text', 'Usually jotformButton, which opens the JotForm popup' ],
	];

	public static function init() {
		// Priority 0: Elementor Pro builds its Theme Builder conditions ("In Location Category",
		// "In Page Type") during init, and only for taxonomies that already exist at that point.
		// CPT UI used to register them at priority 9, so the plugin has to be at least as early.
		add_action( 'init', [ __CLASS__, 'register_taxonomies' ], 0 );
		add_action( 'wp_loaded', [ __CLASS__, 'after_update' ] );
		add_action( 'init', [ __CLASS__, 'register_shortcodes' ], 30 ); // After WPCode snippets, so these win.
		add_action( 'acf/init', [ __CLASS__, 'register_base_fields' ], 20 );

		if ( is_admin() ) {
			add_action( 'admin_head', [ __CLASS__, 'term_screen_css' ] );
			add_action( 'admin_init', [ __CLASS__, 'handle_actions' ] );
		}
	}

	/* ========== Taxonomies ========== */

	/** Taxonomies the plugin registered this request. */
	private static $ours = [];

	/** True when CPT UI is active and still defines this taxonomy (then CPT UI registers it). */
	private static function cptui_has( $tax ) {
		$defs = (array) get_option( 'cptui_taxonomies', [] );
		return isset( $defs[ $tax ] ) && ( function_exists( 'cptui_create_custom_taxonomies' ) || defined( 'CPTUI_VERSION' ) );
	}

	public static function register_taxonomies() {
		if ( ! taxonomy_exists( self::TAX ) && ! self::cptui_has( self::TAX ) ) {
			self::$ours[ self::TAX ] = true;
			register_taxonomy(
				self::TAX,
				[ 'page' ],
				[
					'labels'             => [
						'name'          => 'Location Categories',
						'singular_name' => 'Location Category',
						'menu_name'     => 'Location Categories',
						'all_items'     => 'All Location Categories',
						'edit_item'     => 'Edit Location Category',
						'add_new_item'  => 'Add Location Category',
						'search_items'  => 'Search Location Categories',
					],
					'hierarchical'       => true,
					'public'             => true,  // Elementor Theme Builder conditions need a public taxonomy.
					'publicly_queryable' => true,
					'rewrite'            => false,
					'query_var'          => false,
					'show_ui'            => true,
					'show_in_menu'       => true,
					'show_in_nav_menus'  => true,
					'show_admin_column'  => true,
					'show_in_rest'       => true,
				]
			);
		}
		if ( ! taxonomy_exists( self::PAGE_TYPES ) && ! self::cptui_has( self::PAGE_TYPES ) ) {
			self::$ours[ self::PAGE_TYPES ] = true;
			register_taxonomy(
				self::PAGE_TYPES,
				[ 'page' ],
				[
					'labels'             => [
						'name'          => 'Page Types',
						'singular_name' => 'Page Type',
						'all_items'     => 'All Page Types',
						'edit_item'     => 'Edit Page Type',
						'add_new_item'  => 'Add Page Type',
					],
					'hierarchical'       => true,
					'public'             => true,
					'publicly_queryable' => true,
					'rewrite'            => false,
					'query_var'          => false,
					'show_ui'            => true,
					'show_in_nav_menus'  => true,
					'show_admin_column'  => true,
					'show_in_rest'       => true,
				]
			);
		}
	}

	/** "plugin", "CPT UI", or "other" for a taxonomy. */
	private static function taxonomy_source( $tax ) {
		if ( ! empty( self::$ours[ $tax ] ) ) {
			return 'plugin';
		}
		if ( self::cptui_has( $tax ) ) {
			return 'CPT UI';
		}
		return taxonomy_exists( $tax ) ? 'other' : '';
	}

	/**
	 * After the plugin updates, rebuild Elementor's conditions and CSS caches once, so
	 * templates pick up the taxonomies right away.
	 */
	public static function after_update() {
		$version = class_exists( 'SMC_Location_Updater' ) ? SMC_Location_Updater::current_version() : '';
		if ( $version && get_option( 'smc_core_version' ) !== $version ) {
			update_option( 'smc_core_version', $version, false );
			if ( class_exists( 'SMC_Location_Cloner' ) ) {
				SMC_Location_Cloner::refresh_caches();
			}
		}
	}

	/* ========== Shortcodes ========== */

	public static function register_shortcodes() {
		remove_shortcode( 'location' );
		remove_shortcode( 'current_slug' );
		add_shortcode( 'location', [ __CLASS__, 'location_shortcode' ] );
		add_shortcode( 'current_slug', [ __CLASS__, 'current_slug_shortcode' ] );
	}

	/**
	 * [location field="phone_label"]
	 *
	 * A detail of the page's location (the first Location Category on the page), or of
	 * location="slug". field="name" is the location's name. Output is then adjusted by
	 * SMC_Location_Fields (address one/two lines, email_link, email_label default).
	 */
	public static function location_shortcode( $atts ) {
		$atts  = shortcode_atts( [ 'field' => '', 'location' => '', 'format' => '' ], $atts, 'location' );
		$field = sanitize_key( $atts['field'] );
		if ( '' === $field ) {
			return '';
		}
		if ( '' !== $atts['location'] ) {
			$t   = get_term_by( 'slug', sanitize_title( $atts['location'] ), self::TAX );
			$tid = $t ? (int) $t->term_id : 0;
		} else {
			$tid = SMC_Location_Fields::current_location_id();
		}
		if ( ! $tid ) {
			return '';
		}
		if ( 'name' === $field ) {
			$t = get_term( $tid, self::TAX );
			return $t && ! is_wp_error( $t ) ? $t->name : '';
		}
		$value = function_exists( 'get_field' ) ? get_field( $field, self::TAX . '_' . $tid ) : get_term_meta( $tid, $field, true );
		if ( null === $value || false === $value || '' === $value ) {
			$value = get_term_meta( $tid, $field, true );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * [current_slug] - the page's location path for building links, e.g. "/kenton/".
	 * "/" on single-location sites (front page not in Corporate), on Corporate pages and
	 * on the front page. Same behavior as SMC's original snippet.
	 */
	public static function current_slug_shortcode() {
		$path  = (string) wp_parse_url( get_permalink(), PHP_URL_PATH );
		$parts = explode( '/', $path );
		if ( ! isset( $parts[1] ) || '' === $parts[1] ) {
			return '/';
		}
		$slug = '/' . $parts[1] . '/';

		$is_corporate = function ( $post_id ) {
			$names = wp_get_post_terms( $post_id, self::TAX, [ 'fields' => 'names' ] );
			return ! is_wp_error( $names ) && ( in_array( 'Corporate', $names, true ) || in_array( 'corporate', $names, true ) );
		};
		if ( ! $is_corporate( (int) get_option( 'page_on_front' ) ) ) {
			return '/'; // Single-location site.
		}
		if ( $is_corporate( get_the_ID() ) || is_front_page() ) {
			return '/';
		}
		return $slug;
	}

	/* ========== Base fields ========== */

	/** An active ACF field group, other than the plugin's, that already has the base fields. */
	public static function site_base_group() {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return null;
		}
		foreach ( acf_get_field_groups() as $g ) {
			if ( in_array( $g['key'], [ self::BASE_GROUP, 'group_smc_location_details' ], true ) || empty( $g['active'] ) ) {
				continue;
			}
			$names = wp_list_pluck( (array) acf_get_fields( $g ), 'name' );
			if ( in_array( 'phone_label', $names, true ) && in_array( 'address', $names, true ) ) {
				$g['field_names'] = $names;
				return $g;
			}
		}
		return null;
	}

	/** Registers the base fields, unless the site already has its own group for them. */
	public static function register_base_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		// If the site has its own ACF group for these fields, it stays in charge (no duplicate fields).
		// Checked in the admin and remembered, so the front end doesn't repeat the lookup.
		if ( is_admin() ) {
			$g   = self::site_base_group();
			$key = $g ? $g['key'] : '';
			if ( get_option( 'smc_core_site_group', null ) !== $key ) {
				update_option( 'smc_core_site_group', $key, false );
			}
		} else {
			$key = (string) get_option( 'smc_core_site_group', '' );
		}
		if ( $key ) {
			return;
		}
		$fields = [];
		foreach ( self::BASE_FIELDS as $name => list( $key, $label, $type, $help ) ) {
			$fields[] = [
				'key'          => $key,
				'label'        => $label,
				'name'         => $name,
				'type'         => $type,
				'instructions' => $help,
				'wrapper'      => [ 'width' => in_array( $name, [ 'address', 'booking_link' ], true ) ? '100' : '50' ],
			];
		}
		acf_add_local_field_group(
			[
				'key'        => self::BASE_GROUP,
				'title'      => 'Location details',
				'fields'     => $fields,
				'location'   => [ [ [ 'param' => 'taxonomy', 'operator' => '==', 'value' => self::TAX ] ] ],
				'menu_order' => 0,
			]
		);
	}

	/* ========== Admin ========== */

	/** Hides the description and slug on the location category editor (slug is on Locations > Edit). */
	public static function term_screen_css() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::TAX === ( $screen->taxonomy ?? '' ) ) {
			echo '<style>.term-description-wrap,.term-slug-wrap{display:none}</style>';
		}
	}

	public static function handle_actions() {
		if ( empty( $_POST['smc_core_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'builtin_fields' === sanitize_key( $_POST['smc_core_action'] ) ) {
			check_admin_referer( 'smc_builtin_fields' );
			$g = self::site_base_group();
			if ( $g && ! empty( $g['ID'] ) ) {
				wp_update_post( [ 'ID' => (int) $g['ID'], 'post_status' => 'acf-disabled' ] );
				set_transient( 'smc_core_notice_' . get_current_user_id(), "The ACF field group \"{$g['title']}\" was deactivated (not deleted), and the location fields now come from the plugin. To undo, activate the group again under ACF > Field Groups.", 60 );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=smc-location-settings#smc-system' ) );
			exit;
		}
	}

	/** Status panel for Locations > Settings. */
	public static function render_status() {
		$notice = get_transient( 'smc_core_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'smc_core_notice_' . get_current_user_id() );
			echo '<div class="notice notice-success inline"><p>' . esc_html( $notice ) . '</p></div>';
		}

		$ok   = '<span style="color:#008a20">&#10003;</span> ';
		$todo = '<span style="color:#dba617">&#9679;</span> ';
		$rows = [];

		foreach ( [ self::TAX => 'Location categories', self::PAGE_TYPES => 'Page types' ] as $tax => $label ) {
			$src = self::taxonomy_source( $tax );
			if ( 'plugin' === $src ) {
				$rows[] = [ $label, $ok . 'Provided by the plugin.' ];
			} elseif ( 'CPT UI' === $src ) {
				$rows[] = [ $label, $todo . 'Registered by CPT UI. Optional cleanup: delete it under CPT UI &gt; Add/Edit Taxonomies and the plugin takes over. Existing categories and their details are kept.' ];
			} else {
				$rows[] = [ $label, $ok . 'Registered by the theme or another plugin.' ];
			}
		}

		$snippet = function_exists( 'location_info_shortcode' ) || function_exists( 'get_current_location_slug' );
		$rows[]  = [
			'<code>[location]</code> and <code>[current_slug]</code>',
			$snippet
				? $todo . 'Provided by the plugin. The old WPCode snippet ("Shortcodes") is still active; the plugin\'s versions take priority, so it can be deactivated under Code Snippets.'
				: $ok . 'Provided by the plugin.',
		];

		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			$fields = $ok . 'ACF isn\'t installed. Edit location details under Locations &gt; All Locations &gt; Edit; everything works without it.';
			$button = '';
		} else {
			$g = self::site_base_group();
			if ( ! $g ) {
				$fields = $ok . 'Provided by the plugin.';
				$button = '';
			} else {
				$extra  = array_diff( $g['field_names'], array_keys( self::BASE_FIELDS ) );
				$fields = $todo . 'From this site\'s ACF field group "' . esc_html( $g['title'] ) . '". Optional: switch to the plugin\'s built-in fields. Saved details are kept; only the group is deactivated.';
				if ( $extra ) {
					$fields .= '<br><strong>Note:</strong> that group also has fields the plugin doesn\'t: <code>' . esc_html( implode( ', ', $extra ) ) . '</code>. Keep the group if the site uses them.';
				}
				$button = '<form method="post" style="margin-top:8px" onsubmit="return confirm(\'Deactivate the ACF group and use the plugin\\\'s built-in location fields?\');">'
					. wp_nonce_field( 'smc_builtin_fields', '_wpnonce', true, false )
					. '<input type="hidden" name="smc_core_action" value="builtin_fields"><button class="button">Use built-in fields</button></form>';
			}
		}
		$rows[] = [ 'Location fields', $fields . $button ];

		echo '<h2 id="smc-system">Location system</h2><p>Everything the location shortcodes and templates rely on. On a new site, the plugin provides all of it.</p>';
		echo '<table class="widefat striped" style="max-width:900px;margin-bottom:24px"><tbody>';
		foreach ( $rows as list( $label, $value ) ) {
			echo '<tr><td style="width:220px"><strong>' . wp_kses( $label, [ 'code' => [] ] ) . '</strong></td><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
	}
}
