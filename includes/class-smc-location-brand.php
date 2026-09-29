<?php
/**
 * Locations > Brand
 *
 * One screen for the site's logo, favicon, global colors and global fonts. It doesn't keep
 * its own copy: it edits Elementor's Site Settings (the active kit) and WordPress's site
 * logo and site icon directly, so Elementor and this screen always show the same values.
 * Every save keeps the previous values so they can be restored.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Brand {

	const SLUG    = 'smc-brand';
	const CAP     = 'manage_options';
	const HISTORY = 'smc_brand_history';
	const OPTION  = 'smc_brand';
	/** Elements styled under Typography & buttons: settings prefix => label. Same as Elementor > Site Settings > Typography / Buttons. */
	const TYPO = [ 'body' => 'Body text', 'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'button' => 'Buttons' ];
	const DEVICES   = [ '' => 'Desktop', '_tablet' => 'Tablet', '_mobile' => 'Mobile' ];
	const SIZE_UNITS = [ 'px', 'rem', 'em' ];
	const CASES     = [ '' => 'Default', 'uppercase' => 'UPPERCASE', 'capitalize' => 'Capitalize', 'lowercase' => 'lowercase', 'none' => 'Normal' ];
	const BORDERS   = [ '' => 'Default', 'none' => 'None', 'solid' => 'Solid', 'dashed' => 'Dashed', 'dotted' => 'Dotted', 'double' => 'Double' ];
	const WEIGHTS = [ '' => 'Default', '300' => '300 Light', '400' => '400 Regular', '500' => '500 Medium', '600' => '600 Semi-bold', '700' => '700 Bold', '800' => '800 Extra-bold', '900' => '900 Black' ];

	private $notice = '';
	private $error  = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ], 15 );
	}

	/* ========== Mobile logo (front end) ========== */

	/**
	 * Swaps in the mobile logo on small screens, in:
	 *   - Elementor's Site Logo widget
	 *   - any Elementor Image widget showing the site logo
	 *   - [brand_logo]
	 * It uses a <picture> element, so the browser loads only the logo it shows.
	 */
	public static function init_front() {
		add_filter( 'elementor/widget/render_content', [ __CLASS__, 'mobile_logo_widget' ], 10, 2 );
		add_action(
			'init',
			function () {
				if ( ! shortcode_exists( 'brand_logo' ) ) {
					add_shortcode( 'brand_logo', [ __CLASS__, 'logo_shortcode' ] );
				}
				// [site_name]: Settings > General > Site Title.
				if ( ! shortcode_exists( 'site_name' ) ) {
					add_shortcode( 'site_name', fn() => esc_html( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) );
				}
				// [current_year]: for copyright lines, so they never go stale.
				if ( ! shortcode_exists( 'current_year' ) ) {
					add_shortcode( 'current_year', fn() => esc_html( wp_date( 'Y' ) ) );
				}
				// [brand_name]: the organization name in Yoast SEO (the name the schema uses), or the Site Title.
				if ( ! shortcode_exists( 'brand_name' ) ) {
					add_shortcode(
						'brand_name',
						fn() => esc_html( class_exists( 'SMC_Location_Schema' ) ? SMC_Location_Schema::brand() : html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )
					);
				}
			},
			20
		);
	}

	public static function options() {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [ 'mobile_logo' => 0, 'mobile_logo_on' => 'mobile' ] );
	}

	/** Widest screen (px) that gets the mobile logo, from Elementor's breakpoints. */
	public static function mobile_max() {
		$on  = 'tablet' === self::options()['mobile_logo_on'] ? 'tablet' : 'mobile';
		$def = 'tablet' === $on ? 1024 : 767;
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->breakpoints ) ) {
			try {
				$bp = \Elementor\Plugin::$instance->breakpoints->get_active_breakpoints();
				if ( isset( $bp[ $on ] ) ) {
					return (int) $bp[ $on ]->get_value();
				}
			} catch ( \Throwable $e ) {
				return $def;
			}
		}
		return $def;
	}

	/** Wraps a logo <img> in a <picture> with the mobile logo as a small-screen source. */
	public static function picture( $img ) {
		$id  = (int) self::options()['mobile_logo'];
		$url = $id ? wp_get_attachment_image_url( $id, 'full' ) : '';
		if ( ! $url || false !== strpos( $img, 'smc-logo-picture' ) ) {
			return $img;
		}
		return '<picture class="smc-logo-picture" style="display:contents"><source media="(max-width: ' . self::mobile_max() . 'px)" srcset="' . esc_url( $url ) . '">' . $img . '</picture>';
	}

	public static function mobile_logo_widget( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || ! self::options()['mobile_logo'] || false !== strpos( $content, 'smc-logo-picture' ) ) {
			return $content;
		}
		$name = $widget->get_name();
		if ( 'image' === $name ) {
			$logo  = (int) get_theme_mod( 'custom_logo' );
			$image = (array) $widget->get_settings( 'image' );
			if ( ! $logo || (int) ( $image['id'] ?? 0 ) !== $logo ) {
				return $content;
			}
		} elseif ( 'theme-site-logo' !== $name ) {
			return $content;
		}
		return preg_replace_callback( '/<img\b[^>]*>/i', fn( $m ) => self::picture( $m[0] ), $content, 1 );
	}

	/**
	 * [brand_logo] - the site logo (with the mobile logo on small screens), linked to the homepage.
	 *   link="none"        no link
	 *   width="220px"      maximum width
	 */
	public static function logo_shortcode( $atts ) {
		$a    = shortcode_atts( [ 'link' => 'home', 'width' => '' ], $atts, 'brand_logo' );
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( ! $logo ) {
			return '';
		}
		$style = '';
		if ( '' !== $a['width'] && preg_match( '/^\d+(px|%|rem|em|vw)?$/', trim( $a['width'] ) ) ) {
			$style = 'max-width:' . ( ctype_digit( trim( $a['width'] ) ) ? trim( $a['width'] ) . 'px' : trim( $a['width'] ) ) . ';height:auto';
		}
		$img = wp_get_attachment_image( $logo, 'full', false, [ 'class' => 'smc-brand-logo', 'alt' => get_bloginfo( 'name' ), 'style' => $style ] );
		$out = self::picture( $img );
		return 'none' === $a['link'] ? $out : '<a href="' . esc_url( home_url( '/' ) ) . '" class="smc-brand-logo-link" rel="home">' . $out . '</a>';
	}

	public function menu() {
		$hook = add_submenu_page( SMC_Location_Manager::SLUG, 'Brand', 'Brand', self::CAP, self::SLUG, [ $this, 'page' ] );
		add_action( "load-$hook", [ $this, 'handle' ] );
		add_action( "admin_print_scripts-$hook", [ $this, 'assets' ] );
	}

	public function assets() {
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	/* ========== Elementor kit ========== */

	public static function kit_id() {
		$id = (int) get_option( 'elementor_active_kit' );
		return $id && get_post( $id ) ? $id : 0;
	}

	private static function kit_settings() {
		$id = self::kit_id();
		$s  = $id ? get_post_meta( $id, '_elementor_page_settings', true ) : [];
		return is_array( $s ) ? $s : [];
	}

	private static function default_system_colors() {
		return [
			[ '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ],
			[ '_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ],
			[ '_id' => 'text', 'title' => 'Text', 'color' => '#7A7A7A' ],
			[ '_id' => 'accent', 'title' => 'Accent', 'color' => '#61CE70' ],
		];
	}

	private static function default_system_typography() {
		return [
			[ '_id' => 'primary', 'title' => 'Primary' ],
			[ '_id' => 'secondary', 'title' => 'Secondary' ],
			[ '_id' => 'text', 'title' => 'Text' ],
			[ '_id' => 'accent', 'title' => 'Accent' ],
		];
	}

	/** The brand-related part of the site's settings, for display and history. */
	public static function snapshot() {
		$s = self::kit_settings();
		return [
			'time'              => time(),
			'user'              => get_current_user_id(),
			'logo'              => (int) get_theme_mod( 'custom_logo' ),
			'icon'              => (int) get_option( 'site_icon' ),
			'mobile_logo'       => (int) self::options()['mobile_logo'],
			'mobile_logo_on'    => self::options()['mobile_logo_on'],
			'system_colors'     => $s['system_colors'] ?? self::default_system_colors(),
			'custom_colors'     => $s['custom_colors'] ?? [],
			'system_typography' => $s['system_typography'] ?? self::default_system_typography(),
			'custom_typography' => $s['custom_typography'] ?? [],
			'theme_style'       => self::theme_style_from( $s ),
		];
	}

	/* ========== Typography & buttons (Elementor's Theme Style) ========== */

	/** Every Site Settings key this screen manages for typography, links and buttons. */
	public static function style_keys() {
		$keys = [ 'link_normal_color', 'link_hover_color' ];
		foreach ( array_keys( self::TYPO ) as $p ) {
			foreach ( [ 'typography', 'font_family', 'font_weight', 'line_height', 'text_transform' ] as $f ) {
				$keys[] = "{$p}_typography_$f";
			}
			foreach ( array_keys( self::DEVICES ) as $d ) {
				$keys[] = "{$p}_typography_font_size$d";
			}
			$keys[] = self::color_key( $p );
		}
		foreach ( [ 'button_background_background', 'button_background_color', 'button_hover_text_color', 'button_hover_background_background', 'button_hover_background_color', 'button_border_border', 'button_border_width', 'button_border_color', 'button_hover_border_color', 'button_border_radius', 'button_padding', 'button_padding_tablet', 'button_padding_mobile' ] as $k ) {
			$keys[] = $k;
		}
		return $keys;
	}

	/** Text color key for an element: body_color, h1_color, button_text_color. */
	private static function color_key( $p ) {
		return 'button' === $p ? 'button_text_color' : "{$p}_color";
	}

	/** The managed part of the kit settings: [ values => [ key => value ], globals => [ key => 'globals/colors?id=primary' ] ]. */
	private static function theme_style_from( array $s ) {
		$out = [ 'values' => [], 'globals' => [] ];
		foreach ( self::style_keys() as $k ) {
			if ( isset( $s[ $k ] ) && '' !== $s[ $k ] && [] !== $s[ $k ] ) {
				$out['values'][ $k ] = $s[ $k ];
			}
			if ( ! empty( $s['__globals__'][ $k ] ) ) {
				$out['globals'][ $k ] = $s['__globals__'][ $k ];
			}
		}
		return $out;
	}

	/** The id in "globals/colors?id=primary", or ''. */
	private static function global_id( $ref ) {
		return preg_match( '/[?&]id=([\w-]+)/', (string) $ref, $m ) ? $m[1] : '';
	}

	/** "12 24" + px -> Elementor dimensions. null if blank, false if it isn't 1 to 4 numbers. */
	private static function dims( $text, $unit ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		$n = preg_split( '/[\s,]+/', $text );
		if ( count( $n ) > 4 || array_filter( $n, fn( $v ) => ! is_numeric( $v ) ) ) {
			return false;
		}
		[ $t, $r, $b, $l ] = [ $n[0], $n[1] ?? $n[0], $n[2] ?? $n[0], $n[3] ?? ( $n[1] ?? $n[0] ) ];
		return [ 'unit' => in_array( $unit, [ 'px', 'em', 'rem', '%' ], true ) ? $unit : 'px', 'top' => (string) $t, 'right' => (string) $r, 'bottom' => (string) $b, 'left' => (string) $l, 'isLinked' => 1 === count( array_unique( [ $t, $r, $b, $l ] ) ) ];
	}

	/** Elementor dimensions -> "12 24" (shortest CSS shorthand). */
	private static function dims_text( $d ) {
		if ( ! is_array( $d ) || ! isset( $d['top'] ) || '' === (string) $d['top'] ) {
			return '';
		}
		$v = [ $d['top'], $d['right'] ?? $d['top'], $d['bottom'] ?? $d['top'], $d['left'] ?? $d['right'] ?? $d['top'] ];
		if ( $v[3] === $v[1] ) {
			array_pop( $v );
			if ( $v[2] === $v[0] ) {
				array_pop( $v );
				if ( $v[1] === $v[0] ) {
					array_pop( $v );
				}
			}
		}
		return implode( ' ', $v );
	}

	/** A number + unit -> Elementor slider value. null if blank, false if not a number. */
	private static function slider( $n, $unit, $units ) {
		$n = trim( sanitize_text_field( (string) $n ) );
		if ( '' === $n ) {
			return null;
		}
		if ( 'custom' === $unit ) {
			return [ 'unit' => 'custom', 'size' => $n, 'sizes' => [] ]; // e.g. clamp(2rem, 4vw, 3rem), set in Elementor.
		}
		if ( ! is_numeric( $n ) ) {
			return false;
		}
		$ok = array_merge( $units, [ 'vw', 'vh', '%' ] );
		return [ 'unit' => in_array( $unit, $ok, true ) ? $unit : $units[0], 'size' => (float) $n, 'sizes' => [] ];
	}

	/**
	 * Reads the Typography & buttons part of the form. Returns [ theme_style, bad field labels ].
	 * Colors can be a global color (kept linked, so it follows the Colors above) or a hex code.
	 */
	private static function read_theme_style( array $p ) {
		$in  = (array) ( $p['ts'] ?? [] );
		$out = [ 'values' => [], 'globals' => [] ];
		$bad = [];
		$set = function ( $k, $v ) use ( &$out ) {
			if ( null !== $v && '' !== $v ) {
				$out['values'][ $k ] = $v;
			}
		};
		$color = function ( $k, $field, $label ) use ( &$out, &$bad ) {
			$g = sanitize_key( $field['g'] ?? '' );
			if ( '' === $g ) {
				return;
			}
			if ( 'custom' === $g ) {
				$c = self::color( $field['hex'] ?? '' );
				if ( null === $c ) {
					$bad[] = $label;
				} elseif ( '' !== $c ) {
					$out['values'][ $k ] = $c;
				}
				return;
			}
			$out['globals'][ $k ]  = 'globals/colors?id=' . $g;
			$out['values'][ $k ]   = '';
		};
		$num = function ( $k, $v, $label ) use ( $set, &$bad ) {
			if ( false === $v ) {
				$bad[] = $label;
				return;
			}
			$set( $k, $v );
		};

		foreach ( self::TYPO as $pf => $label ) {
			$row   = (array) ( $in[ $pf ] ?? [] );
			$style = sanitize_text_field( $row['style'] ?? '' );
			$t     = "{$pf}_typography";
			if ( 0 === strpos( $style, 'global:' ) ) {
				// A global font style links the whole typography, like choosing it in Elementor.
				$out['globals'][ "{$t}_typography" ] = 'globals/typography?id=' . sanitize_key( substr( $style, 7 ) );
				$out['values'][ "{$t}_typography" ]  = '';
			} elseif ( 'custom' === $style ) {
				$fam    = trim( sanitize_text_field( $row['family'] ?? '' ) );
				$weight = sanitize_text_field( $row['weight'] ?? '' );
				$set( "{$t}_font_family", $fam );
				$set( "{$t}_font_weight", isset( self::WEIGHTS[ $weight ] ) ? $weight : '' );
				foreach ( array_keys( self::DEVICES ) as $d ) {
					$num( "{$t}_font_size$d", self::slider( $row['size'][ $d ?: 'd' ]['n'] ?? '', $row['size'][ $d ?: 'd' ]['u'] ?? 'px', self::SIZE_UNITS ), "$label size" );
				}
				$num( "{$t}_line_height", self::slider( $row['lh']['n'] ?? '', $row['lh']['u'] ?? 'em', [ 'em', 'px' ] ), "$label line height" );
				$case = sanitize_key( $row['case'] ?? '' );
				$set( "{$t}_text_transform", isset( self::CASES[ $case ] ) ? $case : '' );
				$has = array_intersect_key( $out['values'], array_flip( array_filter( self::style_keys(), fn( $k ) => 0 === strpos( $k, "{$t}_" ) ) ) );
				if ( $has ) {
					$out['values'][ "{$t}_typography" ] = 'custom';
				}
			}
			if ( 'button' !== $pf ) {
				$color( self::color_key( $pf ), (array) ( $row['color'] ?? [] ), "$label color" );
			}
		}

		$color( 'link_normal_color', (array) ( $in['link']['color'] ?? [] ), 'Link color' );
		$color( 'link_hover_color', (array) ( $in['link']['hover'] ?? [] ), 'Link hover color' );

		$b = (array) ( $in['btn'] ?? [] );
		$color( 'button_text_color', (array) ( $b['text'] ?? [] ), 'Button text color' );
		$color( 'button_background_color', (array) ( $b['bg'] ?? [] ), 'Button background' );
		$color( 'button_hover_text_color', (array) ( $b['hover_text'] ?? [] ), 'Button hover text color' );
		$color( 'button_hover_background_color', (array) ( $b['hover_bg'] ?? [] ), 'Button hover background' );
		$color( 'button_border_color', (array) ( $b['border_color'] ?? [] ), 'Button border color' );
		$color( 'button_hover_border_color', (array) ( $b['hover_border'] ?? [] ), 'Button hover border color' );
		foreach ( [ 'button_background_color' => 'button_background_background', 'button_hover_background_color' => 'button_hover_background_background' ] as $ck => $bk ) {
			if ( isset( $out['values'][ $ck ] ) ) {
				$out['values'][ $bk ] = 'classic';
			}
		}
		$border = sanitize_key( $b['border'] ?? '' );
		$set( 'button_border_border', isset( self::BORDERS[ $border ] ) ? $border : '' );
		$num( 'button_border_width', self::dims( $b['border_width'] ?? '', 'px' ), 'Button border width' );
		$num( 'button_border_radius', self::dims( $b['radius']['v'] ?? '', $b['radius']['u'] ?? 'px' ), 'Button corner radius' );
		foreach ( array_keys( self::DEVICES ) as $d ) {
			$num( "button_padding$d", self::dims( $b['padding'][ $d ?: 'd' ]['v'] ?? '', $b['padding'][ $d ?: 'd' ]['u'] ?? 'px' ), 'Button padding (' . self::DEVICES[ $d ] . ')' );
		}
		return [ $out, $bad ];
	}

	/** Writes a snapshot back to Elementor and WordPress. */
	public static function apply( array $b ) {
		$id = self::kit_id();
		$s  = self::kit_settings();
		foreach ( [ 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' ] as $k ) {
			$s[ $k ] = $b[ $k ];
		}
		// Typography & buttons. Versions saved before this existed don't have it, so they leave it alone.
		if ( isset( $b['theme_style'] ) ) {
			$g = isset( $s['__globals__'] ) && is_array( $s['__globals__'] ) ? $s['__globals__'] : [];
			foreach ( self::style_keys() as $k ) {
				unset( $s[ $k ], $g[ $k ] );
			}
			foreach ( (array) ( $b['theme_style']['values'] ?? [] ) as $k => $v ) {
				$s[ $k ] = $v;
			}
			foreach ( (array) ( $b['theme_style']['globals'] ?? [] ) as $k => $v ) {
				$g[ $k ] = $v;
			}
			$s['__globals__'] = $g;
		}
		$s['site_logo']    = $b['logo'] ? [ 'url' => (string) wp_get_attachment_image_url( $b['logo'], 'full' ), 'id' => $b['logo'] ] : [ 'url' => '', 'id' => '' ];
		$s['site_favicon'] = $b['icon'] ? [ 'url' => (string) wp_get_attachment_image_url( $b['icon'], 'full' ), 'id' => $b['icon'] ] : [ 'url' => '', 'id' => '' ];
		update_post_meta( $id, '_elementor_page_settings', $s );

		if ( $b['logo'] ) {
			set_theme_mod( 'custom_logo', $b['logo'] );
		} else {
			remove_theme_mod( 'custom_logo' );
		}
		update_option( 'site_icon', $b['icon'] );
		update_option(
			self::OPTION,
			array_merge(
				self::options(),
				[
					'mobile_logo'    => (int) ( $b['mobile_logo'] ?? 0 ),
					'mobile_logo_on' => 'tablet' === ( $b['mobile_logo_on'] ?? '' ) ? 'tablet' : 'mobile',
				]
			)
		);

		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	public static function push_history( array $snap ) {
		$h = (array) get_option( self::HISTORY, [] );
		array_unshift( $h, $snap );
		update_option( self::HISTORY, array_slice( $h, 0, 10 ), false );
	}

	/* ========== Save ========== */

	public function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['smc_action'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( ! self::kit_id() ) {
			$this->error = 'Elementor\'s Site Settings kit was not found. Open Elementor > Site Settings once, then try again.';
			return;
		}
		$action = sanitize_key( $_POST['smc_action'] );

		if ( 'restore' === $action ) {
			check_admin_referer( 'smc_brand_restore' );
			$i = absint( $_POST['index'] ?? -1 );
			$h = (array) get_option( self::HISTORY, [] );
			if ( ! isset( $h[ $i ] ) ) {
				$this->error = 'That saved version no longer exists.';
				return;
			}
			self::push_history( self::snapshot() );
			self::apply( $h[ $i ] );
			$this->notice = 'Restored the brand settings from ' . wp_date( 'M j, Y g:i a', $h[ $i ]['time'] ) . '.';
			return;
		}

		if ( 'save' !== $action ) {
			return;
		}
		check_admin_referer( 'smc_brand_save' );
		$p   = wp_unslash( $_POST );
		$old = self::snapshot();
		$new = $old;

		$new['logo'] = absint( $p['logo'] ?? 0 );
		$new['icon'] = absint( $p['icon'] ?? 0 );
		$new['mobile_logo']    = absint( $p['mobile_logo'] ?? 0 );
		$new['mobile_logo_on'] = 'tablet' === ( $p['mobile_logo_on'] ?? '' ) ? 'tablet' : 'mobile';

		// Colors: the four system colors keep their IDs; custom colors can be added and removed.
		$bad = [];
		foreach ( $new['system_colors'] as $i => $c ) {
			$row = $p['system_colors'][ $c['_id'] ] ?? null;
			if ( ! $row ) {
				continue;
			}
			$color = self::color( $row['color'] ?? '' );
			if ( null === $color ) {
				$bad[] = $c['title'];
				continue;
			}
			if ( '' !== $color ) {
				$new['system_colors'][ $i ]['color'] = $color;
			}
			$new['system_colors'][ $i ]['title'] = sanitize_text_field( $row['title'] ?? $c['title'] ) ?: $c['title'];
		}
		$custom = [];
		foreach ( (array) ( $p['custom_colors'] ?? [] ) as $row ) {
			if ( ! empty( $row['remove'] ) ) {
				continue;
			}
			$color = self::color( $row['color'] ?? '' );
			$title = sanitize_text_field( $row['title'] ?? '' );
			if ( null === $color || '' === $color ) {
				if ( '' !== $title ) {
					$bad[] = $title;
				}
				continue;
			}
			$custom[] = [
				'_id'   => preg_match( '/^[a-z0-9]{7}$/', $row['_id'] ?? '' ) ? $row['_id'] : substr( md5( uniqid( '', true ) ), 0, 7 ),
				'title' => '' !== $title ? $title : 'Custom color',
				'color' => $color,
			];
		}
		$new['custom_colors'] = $custom;

		// Fonts: only family and weight are changed; sizes and other settings are kept.
		foreach ( [ 'system_typography', 'custom_typography' ] as $group ) {
			foreach ( $new[ $group ] as $i => $t ) {
				$row = $p[ $group ][ $t['_id'] ] ?? null;
				if ( ! $row ) {
					continue;
				}
				$family = trim( sanitize_text_field( $row['family'] ?? '' ) );
				$weight = sanitize_text_field( $row['weight'] ?? '' );
				if ( '' !== $family ) {
					$new[ $group ][ $i ]['typography_typography']  = 'custom';
					$new[ $group ][ $i ]['typography_font_family'] = $family;
				} else {
					unset( $new[ $group ][ $i ]['typography_font_family'] );
				}
				if ( '' !== $weight && isset( self::WEIGHTS[ $weight ] ) ) {
					$new[ $group ][ $i ]['typography_typography'] = 'custom';
					$new[ $group ][ $i ]['typography_font_weight'] = $weight;
				} else {
					unset( $new[ $group ][ $i ]['typography_font_weight'] );
				}
			}
		}

		if ( $bad ) {
			$this->error = 'These colors weren\'t saved because the value isn\'t a color (use a hex code like #1A73E8): ' . implode( ', ', $bad ) . '.';
		}
		[ $ts, $ts_bad ] = self::read_theme_style( $p );
		if ( $ts_bad ) {
			// Keep the old typography & button settings rather than save half of them.
			$this->error = trim( $this->error . ' Typography & buttons weren\'t saved; check these (sizes are numbers, colors hex codes like #1A73E8, spacing like "12 24"): ' . implode( ', ', $ts_bad ) . '.' );
		} else {
			$new['theme_style'] = $ts;
		}
		$compare = fn( $b ) => wp_json_encode( array_diff_key( $b, [ 'time' => 1, 'user' => 1 ] ) );
		if ( $compare( $new ) === $compare( $old ) ) {
			$this->notice = $bad ? '' : 'No changes to save.';
			return;
		}
		self::push_history( $old );
		self::apply( $new );
		$this->notice = 'Brand saved. The whole site uses the new settings now; the previous version is under Restore below.';
	}

	/** Hex (#abc, #aabbcc, #aabbccdd) or rgb()/rgba(); '' for empty; null if invalid. */
	private static function color( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v, $m ) ) {
			return '#' . strtoupper( $m[1] );
		}
		if ( preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/i', $v ) ) {
			return strtolower( $v );
		}
		return null;
	}

	/* ========== Screen ========== */

	private static function fonts() {
		if ( class_exists( '\Elementor\Fonts' ) ) {
			$fonts = array_keys( (array) \Elementor\Fonts::get_fonts() );
			sort( $fonts );
			return $fonts;
		}
		return [ 'Arial', 'Georgia', 'Helvetica', 'Tahoma', 'Times New Roman', 'Verdana' ];
	}

	private static function image_field( $name, $id, $label, $help ) {
		$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		?>
		<div class="smc-image" data-name="<?php echo esc_attr( $name ); ?>">
			<div class="smc-image-preview"><?php echo $url ? '<img src="' . esc_url( $url ) . '" alt="">' : '<span>None</span>'; ?></div>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $id; ?>">
			<button type="button" class="button smc-image-pick"><?php echo esc_html( $url ? "Change $label" : "Choose $label" ); ?></button>
			<button type="button" class="button-link smc-image-remove" <?php echo $url ? '' : 'style="display:none"'; ?>>Remove</button>
			<p class="description"><?php echo esc_html( $help ); ?></p>
		</div>
		<?php
	}

	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$b     = self::snapshot();
		$fonts = self::fonts();
		?>
		<div class="wrap smc-brand">
			<h1>Brand</h1>
			<?php if ( ! class_exists( '\Elementor\Plugin' ) || ! self::kit_id() ) : ?>
				<div class="notice notice-error"><p>Elementor's Site Settings weren't found. This screen edits Elementor's global colors and fonts, so Elementor needs to be active.</p></div>
				<?php
				echo '</div>';
				return;
			endif;
			?>
			<?php if ( $this->error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $this->error ); ?></p></div><?php endif; ?>
			<?php if ( $this->notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $this->notice ); ?></p></div><?php endif; ?>

			<p>The site's logo, favicon, colors, fonts, text styles and buttons in one place. These are the same settings as <strong>Elementor &gt; Site Settings</strong>, so a change here shows up there too, and every widget set to a global color or font updates across the whole site.</p>

			<form method="post">
				<?php wp_nonce_field( 'smc_brand_save' ); ?>
				<input type="hidden" name="smc_action" value="save">

				<h2>Logo &amp; favicon</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Logo</th><td><?php self::image_field( 'logo', $b['logo'], 'logo', 'Shown by Elementor\'s Site Logo widget and the theme. Headers that use a plain Image widget for the logo need that widget changed by hand.' ); ?></td></tr>
					<tr><th scope="row">Mobile logo</th><td>
						<?php self::image_field( 'mobile_logo', $b['mobile_logo'], 'mobile logo', 'Optional. Replaces the logo on small screens, e.g. a shorter or icon-only version.' ); ?>
						<?php
						$bp_mobile = 767;
						$bp_tablet = 1024;
						if ( isset( \Elementor\Plugin::$instance->breakpoints ) ) {
							$active    = \Elementor\Plugin::$instance->breakpoints->get_active_breakpoints();
							$bp_mobile = isset( $active['mobile'] ) ? (int) $active['mobile']->get_value() : $bp_mobile;
							$bp_tablet = isset( $active['tablet'] ) ? (int) $active['tablet']->get_value() : $bp_tablet;
						}
						?>
						<p><label for="mobile_logo_on">Use it on</label>
							<select name="mobile_logo_on" id="mobile_logo_on">
								<option value="mobile" <?php selected( $b['mobile_logo_on'], 'mobile' ); ?>>Phones (screens up to <?php echo (int) $bp_mobile; ?>px)</option>
								<option value="tablet" <?php selected( $b['mobile_logo_on'], 'tablet' ); ?>>Phones and tablets (up to <?php echo (int) $bp_tablet; ?>px)</option>
							</select></p>
						<p class="description">Switches automatically in Elementor's Site Logo widget, in any Image widget showing the logo above, and in <code>[brand_logo]</code>. Only one logo is downloaded per visit. No need for two logo widgets with hide-on-mobile settings.</p>
					</td></tr>
					<tr><th scope="row">Favicon</th><td><?php self::image_field( 'icon', $b['icon'], 'favicon', 'The browser tab icon. Use a square image, at least 512 by 512 pixels.' ); ?></td></tr>
				</table>

				<h2>Colors</h2>
				<table class="widefat striped smc-colors">
					<thead><tr><th>Name</th><th>Color</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $b['system_colors'] as $c ) : ?>
						<tr>
							<td><input name="system_colors[<?php echo esc_attr( $c['_id'] ); ?>][title]" value="<?php echo esc_attr( $c['title'] ); ?>" class="regular-text"></td>
							<td><input name="system_colors[<?php echo esc_attr( $c['_id'] ); ?>][color]" value="<?php echo esc_attr( $c['color'] ?? '' ); ?>" class="smc-color"></td>
							<td class="description">Global color</td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( $b['custom_colors'] as $i => $c ) : ?>
						<tr>
							<td><input type="hidden" name="custom_colors[<?php echo (int) $i; ?>][_id]" value="<?php echo esc_attr( $c['_id'] ); ?>"><input name="custom_colors[<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( $c['title'] ); ?>" class="regular-text"></td>
							<td><input name="custom_colors[<?php echo (int) $i; ?>][color]" value="<?php echo esc_attr( $c['color'] ?? '' ); ?>" class="smc-color"></td>
							<td><label><input type="checkbox" name="custom_colors[<?php echo (int) $i; ?>][remove]" value="1"> Remove</label></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button smc-add-color">Add color</button></p>
				<p class="description">Removing a color that widgets use makes those widgets fall back to their own color, so check the site after removing one.</p>

				<h2>Fonts</h2>
				<datalist id="smc-fonts">
					<?php foreach ( $fonts as $f ) : ?>
						<option value="<?php echo esc_attr( $f ); ?>">
					<?php endforeach; ?>
				</datalist>
				<table class="widefat striped smc-fonts">
					<thead><tr><th>Name</th><th>Font</th><th>Weight</th><th>Preview</th></tr></thead>
					<tbody>
					<?php
					$google = [];
					foreach ( [ 'system_typography', 'custom_typography' ] as $group ) :
						foreach ( $b[ $group ] as $t ) :
							$family = $t['typography_font_family'] ?? '';
							$weight = (string) ( $t['typography_font_weight'] ?? '' );
							if ( $family ) {
								$google[] = $family;
							}
							?>
							<tr>
								<td><?php echo esc_html( $t['title'] ?? $t['_id'] ); ?></td>
								<td><input name="<?php echo esc_attr( $group ); ?>[<?php echo esc_attr( $t['_id'] ); ?>][family]" value="<?php echo esc_attr( $family ); ?>" list="smc-fonts" class="regular-text smc-font" placeholder="Theme default"></td>
								<td><select name="<?php echo esc_attr( $group ); ?>[<?php echo esc_attr( $t['_id'] ); ?>][weight]">
									<?php foreach ( self::WEIGHTS as $k => $label ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $weight, (string) $k ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select></td>
								<td class="smc-font-preview" style="font-family:'<?php echo esc_attr( $family ); ?>',sans-serif;font-weight:<?php echo esc_attr( $weight ?: '400' ); ?>;font-size:18px">Healthy smiles start here</td>
							</tr>
							<?php
						endforeach;
					endforeach;
					?>
					</tbody>
				</table>
				<p class="description">Start typing to pick from Elementor's fonts (Google Fonts, system fonts and any custom fonts). Only the font and weight change here; sizes and spacing stay as set in Elementor.</p>
				<?php if ( $google ) : ?>
					<link rel="stylesheet" href="<?php echo esc_url( 'https://fonts.googleapis.com/css2?' . implode( '&', array_map( fn( $f ) => 'family=' . rawurlencode( $f ) . ':wght@300;400;500;600;700;800;900', array_unique( $google ) ) ) . '&display=swap' ); ?>">
				<?php endif; ?>

				<?php $this->render_theme_style( $b ); ?>

				<?php submit_button( 'Save brand' ); ?>
			</form>

			<?php $this->render_history(); ?>
		</div>

		<style>
			.smc-brand .smc-image-preview { width: 220px; min-height: 60px; background: #f6f7f7 repeating-conic-gradient(#e7e8e9 0 25%, transparent 0 50%) 0 0 / 16px 16px; border: 1px solid #dcdcde; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; padding: 8px; }
			.smc-brand .smc-image-preview img { max-width: 100%; max-height: 120px; }
			.smc-brand .smc-image-preview span { color: #787c82; }
			.smc-brand .smc-colors, .smc-brand .smc-fonts { max-width: 900px; }
			.smc-brand .smc-colors td, .smc-brand .smc-fonts td { vertical-align: middle; }
			.smc-brand .smc-scroll { overflow-x: auto; max-width: 100%; }
			.smc-brand .smc-typo { min-width: 1420px; }
			.smc-brand .smc-typo td, .smc-brand .smc-typo th { vertical-align: middle; }
			.smc-brand .smc-typo, .smc-brand .smc-buttons, .smc-brand .smc-fonts, .smc-brand .smc-colors { --smc-h: 32px; }
			/* One height for every field, so rows line up. */
			.smc-brand .smc-typo input, .smc-brand .smc-typo select,
			.smc-brand .smc-buttons input:not([type=checkbox]), .smc-brand .smc-buttons select,
			.smc-brand .smc-fonts input, .smc-brand .smc-fonts select,
			.smc-brand .smc-colors input[type=text], .smc-brand .smc-colors input:not([type]) {
				height: var(--smc-h); min-height: var(--smc-h); line-height: 1.4; padding-top: 0; padding-bottom: 0; box-sizing: border-box; margin: 2px 0; vertical-align: middle; font-size: 14px;
			}
			.smc-brand .smc-typo input, .smc-brand .smc-buttons input:not([type=checkbox]) { padding-left: 8px; padding-right: 8px; }
			/* Widths by kind of field. */
			.smc-brand .smc-num { width: 64px; }
			.smc-brand .smc-unit { width: 64px; }
			.smc-brand .smc-dims { width: 96px; }
			.smc-brand .smc-font-in { width: 150px; }
			.smc-brand .smc-w-style { width: 190px; }
			.smc-brand .smc-w-md { width: 130px; }
			.smc-brand .smc-w-sm { width: 120px; }
			.smc-brand .smc-color-hex { width: 100px; }
			.smc-brand .smc-fonts .smc-font { width: 260px; }
			.smc-brand .smc-fonts select { width: 140px; }
			.smc-brand .smc-colors .regular-text { width: 260px; }
			.smc-brand .smc-typo input::placeholder { color: #a7aaad; }
			.smc-brand .smc-unit-label { margin: 0 8px 0 2px; color: #646970; }
			.smc-brand .smc-buttons td > .smc-color-choice { margin-right: 2px; }
			.smc-brand .smc-typo tr.is-off .smc-c { opacity: .35; pointer-events: none; }
			.smc-brand .smc-nowrap { white-space: nowrap; }
			.smc-brand .smc-size { display: inline-block; margin-right: 6px; }
			.smc-brand .smc-unit { min-width: 0; padding-right: 22px; margin-left: 2px; }
			.smc-brand .smc-color-choice { display: inline-flex; align-items: center; gap: 4px; }
			.smc-brand .smc-color-swatch { width: 20px; height: 20px; border-radius: 3px; border: 1px solid #c3c4c7; display: none; }
			.smc-brand .smc-sep { margin: 0 6px 0 12px; color: #646970; }
			.smc-brand .smc-pad { margin-right: 14px; display: inline-block; }
			.smc-brand .smc-btn-preview { display: inline-block; text-decoration: none; margin-right: 10px; transition: all .2s; }
		</style>
		<script>
		jQuery( function ( $ ) {
			$( '.smc-color' ).wpColorPicker();

			$( '.smc-add-color' ).on( 'click', function () {
				var i = Date.now();
				var row = $( '<tr><td><input name="custom_colors[' + i + '][title]" class="regular-text" placeholder="Color name"></td><td><input name="custom_colors[' + i + '][color]" class="smc-color"></td><td><button type="button" class="button-link smc-drop">Cancel</button></td></tr>' );
				$( '.smc-colors tbody' ).append( row );
				row.find( '.smc-color' ).wpColorPicker();
				row.find( 'input' ).first().focus();
			} );
			$( document ).on( 'click', '.smc-drop', function () { $( this ).closest( 'tr' ).remove(); } );

			$( '.smc-image-pick' ).on( 'click', function () {
				var box = $( this ).closest( '.smc-image' );
				var frame = wp.media( { title: 'Choose image', library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var src = ( a.sizes && a.sizes.medium ) ? a.sizes.medium.url : a.url;
					box.find( 'input' ).val( a.id );
					box.find( '.smc-image-preview' ).html( '<img src="' + src + '" alt="">' );
					box.find( '.smc-image-remove' ).show();
				} );
				frame.open();
			} );
			$( '.smc-image-remove' ).on( 'click', function () {
				var box = $( this ).closest( '.smc-image' );
				box.find( 'input' ).val( 0 );
				box.find( '.smc-image-preview' ).html( '<span>None</span>' );
				$( this ).hide();
			} );

			// Typography: Default and Global rows grey out the custom fields.
			function typoRow( row ) {
				row.toggleClass( 'is-off', 'custom' !== row.find( '.smc-typo-style' ).val() );
			}
			$( '.smc-typo-row' ).each( function () { typoRow( $( this ) ); } );
			$( '.smc-typo-style' ).on( 'change', function () { typoRow( $( this ).closest( 'tr' ) ); } );

			// Color choices: hex box only for Custom; swatch for globals.
			function colorChoice( box ) {
				var sel = box.find( '.smc-color-g' ), v = sel.val(), hex = box.find( '.smc-color-hex' ), sw = box.find( '.smc-color-swatch' );
				hex.toggle( 'custom' === v );
				var c = 'custom' === v ? hex.val() : sel.find( ':selected' ).data( 'color' );
				sw.css( 'background', c || 'transparent' ).toggle( !! c );
			}
			$( '.smc-color-choice' ).each( function () { colorChoice( $( this ) ); } );
			$( document ).on( 'change input', '.smc-color-g, .smc-color-hex', function () { colorChoice( $( this ).closest( '.smc-color-choice' ) ); preview(); } );

			// Button preview from the fields.
			function val( name ) {
				var box = $( '[name="ts[btn][' + name + '][g]"]' ).closest( '.smc-color-choice' ), sel = box.find( 'select' );
				return 'custom' === sel.val() ? box.find( 'input' ).val() : ( sel.find( ':selected' ).data( 'color' ) || '' );
			}
			function sides( v, u ) { return v ? v.trim().split( /[\s,]+/ ).map( function ( n ) { return n + u; } ).join( ' ' ) : ''; }
			function preview() {
				var p = $( '.smc-btn-preview' ), row = $( '.smc-typo-row' ).last(), custom = 'custom' === row.find( '.smc-typo-style' ).val();
				var size = row.find( '[name="ts[button][size][d][n]"]' ).val();
				p.css( {
					background: val( 'bg' ) || '#69727d', color: val( 'text' ) || '#fff',
					borderStyle: $( '[name="ts[btn][border]"]' ).val() || 'none', borderWidth: sides( $( '[name="ts[btn][border_width]"]' ).val(), 'px' ) || 0, borderColor: val( 'border_color' ) || 'transparent',
					borderRadius: sides( $( '[name="ts[btn][radius][v]"]' ).val(), $( '[name="ts[btn][radius][u]"]' ).val() ) || '3px',
					padding: sides( $( '[name="ts[btn][padding][d][v]"]' ).val(), $( '[name="ts[btn][padding][d][u]"]' ).val() ) || '12px 24px',
					fontFamily: custom && row.find( '.smc-font-in' ).val() ? "'" + row.find( '.smc-font-in' ).val() + "', sans-serif" : '',
					fontWeight: custom ? row.find( '[name="ts[button][weight]"]' ).val() : '',
					fontSize: custom && size ? size + row.find( '[name="ts[button][size][d][u]"]' ).val() : '',
					textTransform: custom ? row.find( '[name="ts[button][case]"]' ).val() : ''
				} );
				p.data( 'hover', { background: val( 'hover_bg' ), color: val( 'hover_text' ), borderColor: val( 'hover_border' ) } );
			}
			$( '.smc-btn-preview' ).on( 'mouseenter', function () {
				var h = $( this ).data( 'hover' ) || {}, css = {};
				$.each( h, function ( k, v ) { if ( v ) { css[ k ] = v; } } );
				$( this ).data( 'base', $( this ).attr( 'style' ) ).css( css );
			} ).on( 'mouseleave', function () { $( this ).attr( 'style', $( this ).data( 'base' ) || '' ); } );
			$( '.smc-buttons, .smc-typo' ).on( 'change input', 'input, select', preview );
			preview();

			// Live font preview (Google Fonts load on demand).
			$( '.smc-font, .smc-fonts select' ).on( 'change input', function () {
				var row = $( this ).closest( 'tr' ), fam = row.find( '.smc-font' ).val(), w = row.find( 'select' ).val() || '400';
				if ( fam ) {
					$( 'head' ).append( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' + encodeURIComponent( fam ) + ':wght@300;400;500;600;700;800;900&display=swap">' );
				}
				row.find( '.smc-font-preview' ).css( { fontFamily: "'" + fam + "', sans-serif", fontWeight: w } );
			} );
		} );
		</script>
		<?php
	}

	/** Color: not set, a global color (stays linked), or a custom hex. */
	private static function color_input( $name, $key, array $ts, array $colors ) {
		$gid = self::global_id( $ts['globals'][ $key ] ?? '' );
		$hex = '' === $gid ? (string) ( $ts['values'][ $key ] ?? '' ) : '';
		$sel = '' !== $gid ? $gid : ( '' !== $hex ? 'custom' : '' );
		$out = '<span class="smc-color-choice"><select name="' . esc_attr( $name ) . '[g]" class="smc-color-g smc-w-sm"><option value="">Default</option>';
		foreach ( $colors as $c ) {
			$out .= '<option value="' . esc_attr( $c['_id'] ) . '" ' . selected( $sel, $c['_id'], false ) . ' data-color="' . esc_attr( $c['color'] ?? '' ) . '">' . esc_html( $c['title'] ) . '</option>';
		}
		$out .= '<option value="custom" ' . selected( $sel, 'custom', false ) . '>Custom&hellip;</option></select>';
		$out .= '<span class="smc-color-swatch"></span>';
		$out .= '<input name="' . esc_attr( $name ) . '[hex]" value="' . esc_attr( $hex ) . '" class="smc-color-hex code" placeholder="#1A73E8"></span>';
		return $out;
	}

	private static function unit_select( $name, $current, $units ) {
		if ( '' !== (string) $current && ! in_array( $current, $units, true ) ) {
			$units[] = $current; // Keep a unit set in Elementor (vw, custom...) instead of switching it.
		}
		$out = '<select name="' . esc_attr( $name ) . '" class="smc-unit">';
		foreach ( $units as $u ) {
			$out .= '<option ' . selected( $current, $u, false ) . '>' . esc_html( $u ) . '</option>';
		}
		return $out . '</select>';
	}

	private function render_theme_style( array $b ) {
		$ts     = $b['theme_style'];
		$v      = $ts['values'];
		$colors = array_merge( $b['system_colors'], $b['custom_colors'] );
		$fonts  = array_merge( $b['system_typography'], $b['custom_typography'] );
		?>
		<h2>Typography</h2>
		<p class="description">Elementor's default text and heading styles (Site Settings &gt; Typography). Widgets that set their own font, size or color keep theirs. <strong>Global</strong> links an element to one of the fonts above, like picking it in Elementor; <strong>Custom</strong> sets it here. Leave sizes blank to keep the theme's. Tablet and mobile sizes fall back to the size above them.</p>
		<div class="smc-scroll"><table class="widefat striped smc-typo">
			<thead><tr><th>Element</th><th>Style</th><th>Font</th><th>Weight</th><th>Size: desktop / tablet / mobile</th><th>Line height</th><th>Case</th><th>Color</th></tr></thead>
			<tbody>
			<?php foreach ( self::TYPO as $pf => $label ) : ?>
				<?php
				$t      = "{$pf}_typography";
				$gid    = self::global_id( $ts['globals'][ "{$t}_typography" ] ?? '' );
				$custom = 'custom' === ( $v[ "{$t}_typography" ] ?? '' );
				$style  = '' !== $gid ? "global:$gid" : ( $custom ? 'custom' : '' );
				$n      = "ts[$pf]";
				?>
				<tr class="smc-typo-row">
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><select name="<?php echo esc_attr( $n ); ?>[style]" class="smc-typo-style smc-w-style">
						<option value="">Default</option>
						<?php foreach ( $fonts as $f ) : ?>
							<option value="global:<?php echo esc_attr( $f['_id'] ); ?>" <?php selected( $style, 'global:' . $f['_id'] ); ?>>Global: <?php echo esc_html( ( $f['title'] ?? $f['_id'] ) . ( ! empty( $f['typography_font_family'] ) ? ' (' . $f['typography_font_family'] . ')' : '' ) ); ?></option>
						<?php endforeach; ?>
						<option value="custom" <?php selected( $style, 'custom' ); ?>>Custom</option>
					</select></td>
					<td class="smc-c"><input name="<?php echo esc_attr( $n ); ?>[family]" value="<?php echo esc_attr( $v[ "{$t}_font_family" ] ?? '' ); ?>" list="smc-fonts" class="smc-font-in" placeholder="Default"></td>
					<td class="smc-c"><select name="<?php echo esc_attr( $n ); ?>[weight]" class="smc-w-md">
						<?php foreach ( self::WEIGHTS as $k => $wl ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) ( $v[ "{$t}_font_weight" ] ?? '' ), (string) $k ); ?>><?php echo esc_html( $wl ); ?></option>
						<?php endforeach; ?>
					</select></td>
					<td class="smc-c smc-nowrap">
						<?php foreach ( array_keys( self::DEVICES ) as $d ) : ?>
							<?php $sz = $v[ "{$t}_font_size$d" ] ?? []; ?>
							<span class="smc-size" title="<?php echo esc_attr( self::DEVICES[ $d ] ); ?>"><input type="text" inputmode="decimal" class="smc-num" name="<?php echo esc_attr( $n ); ?>[size][<?php echo esc_attr( $d ?: 'd' ); ?>][n]" value="<?php echo esc_attr( $sz['size'] ?? '' ); ?>" placeholder="<?php echo esc_attr( substr( self::DEVICES[ $d ], 0, 1 ) ); ?>"><?php echo self::unit_select( "{$n}[size][" . ( $d ?: 'd' ) . '][u]', $sz['unit'] ?? 'px', self::SIZE_UNITS ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<?php endforeach; ?>
					</td>
					<td class="smc-c smc-nowrap"><?php $lh = $v[ "{$t}_line_height" ] ?? []; ?><input type="text" inputmode="decimal" class="smc-num" name="<?php echo esc_attr( $n ); ?>[lh][n]" value="<?php echo esc_attr( $lh['size'] ?? '' ); ?>"><?php echo self::unit_select( "{$n}[lh][u]", $lh['unit'] ?? 'em', [ 'em', 'px' ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="smc-c"><select name="<?php echo esc_attr( $n ); ?>[case]" class="smc-w-md">
						<?php foreach ( self::CASES as $k => $cl ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) ( $v[ "{$t}_text_transform" ] ?? '' ), (string) $k ); ?>><?php echo esc_html( $cl ); ?></option>
						<?php endforeach; ?>
					</select></td>
					<td><?php echo 'button' === $pf ? '<span class="description">Under Buttons</span>' : self::color_input( "{$n}[color]", self::color_key( $pf ), $ts, $colors ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Link color</th><td><?php echo self::color_input( 'ts[link][color]', 'link_normal_color', $ts, $colors ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<tr><th scope="row">Link hover color</th><td><?php echo self::color_input( 'ts[link][hover]', 'link_hover_color', $ts, $colors ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
		</table>

		<h2>Buttons</h2>
		<p class="description">The default look of Elementor Button widgets (Site Settings &gt; Buttons), including <code>[location_team]</code> and <code>[location_list]</code> buttons. The font is set in the Buttons row above. Buttons with their own style in a widget keep it.</p>
		<?php $b_ = (array) ( $v['button_border_radius'] ?? [] ); ?>
		<table class="form-table smc-buttons" role="presentation">
			<tr><th scope="row">Background</th><td><?php echo self::color_input( 'ts[btn][bg]', 'button_background_color', $ts, $colors ); // phpcs:ignore ?> <span class="smc-sep">Hover</span> <?php echo self::color_input( 'ts[btn][hover_bg]', 'button_hover_background_color', $ts, $colors ); // phpcs:ignore ?></td></tr>
			<tr><th scope="row">Text color</th><td><?php echo self::color_input( 'ts[btn][text]', 'button_text_color', $ts, $colors ); // phpcs:ignore ?> <span class="smc-sep">Hover</span> <?php echo self::color_input( 'ts[btn][hover_text]', 'button_hover_text_color', $ts, $colors ); // phpcs:ignore ?></td></tr>
			<tr><th scope="row">Border</th><td>
				<select name="ts[btn][border]" class="smc-w-sm">
					<?php foreach ( self::BORDERS as $k => $bl ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) ( $v['button_border_border'] ?? '' ), (string) $k ); ?>><?php echo esc_html( $bl ); ?></option>
					<?php endforeach; ?>
				</select>
				<input name="ts[btn][border_width]" value="<?php echo esc_attr( self::dims_text( $v['button_border_width'] ?? [] ) ); ?>" class="smc-num code" placeholder="1"> <span class="smc-unit-label">px</span>
				<?php echo self::color_input( 'ts[btn][border_color]', 'button_border_color', $ts, $colors ); // phpcs:ignore ?> <span class="smc-sep">Hover</span> <?php echo self::color_input( 'ts[btn][hover_border]', 'button_hover_border_color', $ts, $colors ); // phpcs:ignore ?>
			</td></tr>
			<tr><th scope="row">Corner radius</th><td><input name="ts[btn][radius][v]" value="<?php echo esc_attr( self::dims_text( $b_ ) ); ?>" class="smc-num code" placeholder="6"><?php echo self::unit_select( 'ts[btn][radius][u]', $b_['unit'] ?? 'px', [ 'px', '%', 'em', 'rem' ] ); // phpcs:ignore ?>
				<p class="description">One number for all corners, or four for top-left, top-right, bottom-right, bottom-left. Use a large number like 50 for pill-shaped buttons.</p></td></tr>
			<tr><th scope="row">Padding</th><td>
				<?php foreach ( array_keys( self::DEVICES ) as $d ) : ?>
					<?php $pd = (array) ( $v[ "button_padding$d" ] ?? [] ); ?>
					<label class="smc-pad"><?php echo esc_html( self::DEVICES[ $d ] ); ?> <input name="ts[btn][padding][<?php echo esc_attr( $d ?: 'd' ); ?>][v]" value="<?php echo esc_attr( self::dims_text( $pd ) ); ?>" class="smc-dims code" placeholder="<?php echo '' === $d ? '14 28' : ''; ?>"><?php echo self::unit_select( "ts[btn][padding][" . ( $d ?: 'd' ) . '][u]', $pd['unit'] ?? 'px', [ 'px', 'em', 'rem' ] ); // phpcs:ignore ?></label>
				<?php endforeach; ?>
				<p class="description">Like CSS: <code>14 28</code> is 14 top and bottom, 28 left and right. Tablet and mobile fall back to the size above them.</p>
			</td></tr>
			<tr><th scope="row">Preview</th><td><a href="#" class="smc-btn-preview" onclick="return false">Book an Appointment</a> <span class="description">Approximate; the site's own button may differ slightly.</span></td></tr>
		</table>
		<?php
	}

	private function render_history() {
		$h = (array) get_option( self::HISTORY, [] );
		echo '<hr><h2>Restore</h2>';
		if ( ! $h ) {
			echo '<p class="description">Each time you save, the previous logo, favicon, colors, fonts, typography and buttons are kept here (the last 10) so you can go back. Versions saved before typography and buttons were added here restore only the logo, favicon, colors and fonts.</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Saved over on</th><th>By</th><th>Colors</th><th></th></tr></thead><tbody>';
		foreach ( $h as $i => $snap ) {
			$user   = get_userdata( $snap['user'] ?? 0 );
			$swatch = '';
			foreach ( array_merge( $snap['system_colors'] ?? [], $snap['custom_colors'] ?? [] ) as $c ) {
				if ( ! empty( $c['color'] ) ) {
					$swatch .= '<span title="' . esc_attr( $c['title'] ) . '" style="display:inline-block;width:18px;height:18px;border-radius:3px;margin-right:3px;border:1px solid #ccc;background:' . esc_attr( $c['color'] ) . '"></span>';
				}
			}
			echo '<tr><td>' . esc_html( wp_date( 'M j, Y g:i a', $snap['time'] ) ) . '</td><td>' . esc_html( $user ? $user->display_name : '-' ) . '</td><td>' . $swatch . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<form method="post" onsubmit="return confirm(\'Restore this version of the brand settings?\');">';
			wp_nonce_field( 'smc_brand_restore' );
			echo '<input type="hidden" name="smc_action" value="restore"><input type="hidden" name="index" value="' . (int) $i . '"><button class="button">Restore</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}
}
