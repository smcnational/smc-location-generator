<?php
/**
 * Holiday closures and holiday hours.
 *
 * One list for the site (Locations > Holidays). Each entry has a date or date range, a name
 * ("Thanksgiving"), hours ("" = closed all day, or e.g. "9:00 AM - 12:00 PM") and the
 * locations it applies to (none ticked = all). Locations are stored by slug, so the list
 * survives Export / Import between sites.
 *
 * Shows up in:
 *  - [location_closure_notice]  a notice shown only in the days before a closure;
 *  - [location_holidays]        the upcoming closures for the page's location;
 *  - [location_list]            "Closed today (Thanksgiving)" instead of the usual hours;
 *  - the location schema        as special opening hours Google can show.
 * Both shortcodes decide what to show in the visitor's browser, so they stay right on cached pages.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Holidays {

	const OPTION = 'smc_location_holidays';
	const STYLE  = 'smc_location_holiday_notice';
	const SLUG   = 'smc-location-holidays';
	const CAP    = 'manage_options';
	const TAX    = 'location_category';

	/** Common US office holidays: key => [ label, checked by default ]. */
	const COMMON = [
		'new_year'       => [ "New Year's Day", true ],
		'memorial'       => [ 'Memorial Day', true ],
		'independence'   => [ 'Independence Day', true ],
		'labor'          => [ 'Labor Day', true ],
		'thanksgiving'   => [ 'Thanksgiving', true ],
		'thanksgiving_2' => [ 'Day after Thanksgiving', false ],
		'christmas_eve'  => [ 'Christmas Eve', false ],
		'christmas'      => [ 'Christmas Day', true ],
		'new_year_eve'   => [ "New Year's Eve", false ],
	];

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 16 );
		add_action(
			'init',
			function () {
				foreach ( [ 'location_holidays' => 'list_shortcode', 'location_closure_notice' => 'notice_shortcode' ] as $tag => $fn ) {
					if ( ! shortcode_exists( $tag ) ) {
						add_shortcode( $tag, [ __CLASS__, $fn ] );
					}
				}
			}
		);
	}

	/* ========== Notice style (Locations > Holidays > Closure notice) ========== */

	public static function style_defaults() {
		return [
			'days'         => 14,
			'align'        => '',
			'padding'      => '',
			'background'   => '',
			'color'        => '',
			'border'       => '',
			'border_side'  => 'left',
			'border_width' => '',
			'radius'       => '',
			'font'         => '',
			'family'       => '',
			'weight'       => '',
			'size'         => '',
			'closed'       => '{who} will be closed {date} for {label}.',
			'special'      => '{who} will be open {hours} on {date} for {label}.',
		];
	}

	/** Saved notice settings over the defaults. Shortcode options still override these. */
	public static function style() {
		$saved = get_option( self::STYLE, [] );
		return array_merge( self::style_defaults(), is_array( $saved ) ? array_intersect_key( $saved, self::style_defaults() ) : [] );
	}

	/** Elementor global colors: id => [ title, hex ]. */
	public static function global_colors() {
		$out = [];
		if ( class_exists( 'SMC_Location_Brand' ) && SMC_Location_Brand::kit_id() ) {
			$s = (array) get_post_meta( SMC_Location_Brand::kit_id(), '_elementor_page_settings', true );
			foreach ( array_merge( (array) ( $s['system_colors'] ?? [] ), (array) ( $s['custom_colors'] ?? [] ) ) as $c ) {
				if ( ! empty( $c['_id'] ) ) {
					$out[ $c['_id'] ] = [ $c['title'] ?? $c['_id'], $c['color'] ?? '' ];
				}
			}
		}
		return $out;
	}

	/** Elementor global fonts: id => [ title, family ]. */
	public static function global_fonts() {
		$out = [];
		if ( class_exists( 'SMC_Location_Brand' ) && SMC_Location_Brand::kit_id() ) {
			$s = (array) get_post_meta( SMC_Location_Brand::kit_id(), '_elementor_page_settings', true );
			foreach ( array_merge( (array) ( $s['system_typography'] ?? [] ), (array) ( $s['custom_typography'] ?? [] ) ) as $f ) {
				if ( ! empty( $f['_id'] ) ) {
					$out[ $f['_id'] ] = [ $f['title'] ?? $f['_id'], (string) ( $f['typography_font_family'] ?? '' ) ];
				}
			}
		}
		return $out;
	}

	/** A font family name that's safe to put in CSS, or "". */
	private static function clean_family( $f ) {
		$f = trim( (string) $f );
		return preg_match( '/^[A-Za-z0-9 \-]{2,60}$/', $f ) ? $f : '';
	}

	/**
	 * <link> for a custom font from Google Fonts, printed once, when Elementor would load it
	 * that way too (it's a Google font and Elementor's Google Fonts aren't turned off).
	 */
	private static function font_link( $family ) {
		static $done = [];
		if ( '' === $family || isset( $done[ $family ] ) || ! class_exists( '\Elementor\Fonts' ) ) {
			return '';
		}
		$done[ $family ] = true;
		$type = \Elementor\Fonts::get_font_type( $family );
		if ( ! in_array( $type, [ 'googlefonts', 'earlyaccess' ], true ) || '0' === (string) get_option( 'elementor_google_font', '1' ) ) {
			return '';
		}
		return '<link rel="stylesheet" href="' . esc_url( 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $family ) . ':wght@300;400;500;600;700;800;900&display=swap' ) . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources
	}

	/* ========== Data ========== */

	/** @return array list of [ from, to, label, hours, locations (slugs, [] = all) ], sorted by date. */
	public static function all() {
		$all = get_option( self::OPTION, [] );
		$all = is_array( $all ) ? array_values( array_filter( $all, fn( $e ) => ! empty( $e['from'] ) ) ) : [];
		usort( $all, fn( $a, $b ) => strcmp( $a['from'], $b['from'] ) );
		return $all;
	}

	public static function save( array $entries ) {
		usort( $entries, fn( $a, $b ) => strcmp( $a['from'], $b['from'] ) );
		update_option( self::OPTION, array_values( $entries ), false );
	}

	/** Today in the site's timezone, Y-m-d. */
	public static function today() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Entries for a location that haven't ended, within $days from today.
	 * $tid 0 = entries for every location only (Corporate and pages with no location).
	 */
	public static function upcoming( $tid, $days = 365 ) {
		$slug  = '';
		if ( $tid ) {
			$t    = get_term( (int) $tid, self::TAX );
			$slug = $t && ! is_wp_error( $t ) ? $t->slug : '';
		}
		$today = self::today();
		$until = wp_date( 'Y-m-d', strtotime( "+$days days", current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$out   = [];
		foreach ( self::all() as $e ) {
			$to = $e['to'] ?: $e['from'];
			if ( $to < $today || $e['from'] > $until ) {
				continue;
			}
			$locs = (array) ( $e['locations'] ?? [] );
			if ( $locs && ( '' === $slug || ! in_array( $slug, $locs, true ) ) ) {
				continue;
			}
			$out[] = $e;
		}
		return $out;
	}

	/** "Thursday, November 26" or "December 24 through 26" (no dashes, as it's site copy). */
	public static function date_text( array $e ) {
		$from = strtotime( $e['from'] . ' 12:00' );
		$to   = strtotime( ( $e['to'] ?: $e['from'] ) . ' 12:00' );
		if ( $from === $to ) {
			return date_i18n( 'l, F j', $from );
		}
		if ( gmdate( 'Ym', $from ) === gmdate( 'Ym', $to ) ) {
			return date_i18n( 'F j', $from ) . ' through ' . date_i18n( 'j', $to );
		}
		return date_i18n( 'F j', $from ) . ' through ' . date_i18n( 'F j', $to );
	}

	/* ========== Common holidays ========== */

	/** Date of a common holiday in a year, Y-m-d. */
	public static function common_date( $key, $year ) {
		$nth = function ( $n, $weekday, $month ) use ( $year ) {
			$names = [ '05' => 'May', '09' => 'September', '11' => 'November' ];
			return gmdate( 'Y-m-d', strtotime( "$n $weekday of {$names[ $month ]} $year" ) );
		};
		switch ( $key ) {
			case 'new_year':
				return "$year-01-01";
			case 'memorial':
				return $nth( 'last', 'monday', '05' );
			case 'independence':
				return "$year-07-04";
			case 'labor':
				return $nth( 'first', 'monday', '09' );
			case 'thanksgiving':
				return $nth( 'fourth', 'thursday', '11' );
			case 'thanksgiving_2':
				return gmdate( 'Y-m-d', strtotime( $nth( 'fourth', 'thursday', '11' ) . ' +1 day' ) );
			case 'christmas_eve':
				return "$year-12-24";
			case 'christmas':
				return "$year-12-25";
			case 'new_year_eve':
				return "$year-12-31";
		}
		return '';
	}

	/* ========== Admin: Locations > Holidays ========== */

	public static function menu() {
		$hook = add_submenu_page( SMC_Location_Manager::SLUG, 'Holiday Closures', 'Holidays', self::CAP, self::SLUG, [ __CLASS__, 'page' ] );
		add_action( "load-$hook", [ __CLASS__, 'handle' ] );
	}

	public static function url( $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	public static function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		check_admin_referer( 'smc_holidays' );
		$do = sanitize_key( $_POST['smc_do'] ?? 'save' );

		if ( 'common' === $do ) {
			$year  = (int) ( $_POST['year'] ?? 0 );
			$keys  = array_intersect( array_map( 'sanitize_key', (array) ( $_POST['common'] ?? [] ) ), array_keys( self::COMMON ) );
			$all   = self::all();
			$have  = array_map( fn( $e ) => $e['from'] . '|' . strtolower( $e['label'] ), $all );
			$added = 0;
			foreach ( $keys as $k ) {
				$date = self::common_date( $k, $year );
				if ( $date && ! in_array( $date . '|' . strtolower( self::COMMON[ $k ][0] ), $have, true ) ) {
					$all[] = [ 'from' => $date, 'to' => '', 'label' => self::COMMON[ $k ][0], 'hours' => '', 'locations' => [] ];
					$added++;
				}
			}
			self::save( $all );
			wp_safe_redirect( self::url( [ 'msg' => 'common', 'n' => $added ] ) );
			exit;
		}

		if ( 'style' === $do ) {
			$in  = wp_unslash( (array) ( $_POST['style'] ?? [] ) );
			$out = self::style_defaults();
			$bad = [];
			$out['days']  = max( 1, min( 60, (int) ( $in['days'] ?? 14 ) ) );
			$out['align'] = in_array( $in['align'] ?? '', [ 'left', 'center', 'right' ], true ) ? $in['align'] : '';
			foreach ( [ 'padding' => 'Padding', 'radius' => 'Corner radius' ] as $k => $label ) {
				$v = trim( sanitize_text_field( $in[ $k ] ?? '' ) );
				if ( '' !== $v && '' === self::css_sides( $v ) ) {
					$bad[] = $label;
					$v     = '';
				}
				$out[ $k ] = $v;
			}
			foreach ( [ 'background' => 'Background', 'color' => 'Text color', 'border' => 'Border color' ] as $k => $label ) {
				$g = sanitize_text_field( $in[ $k ]['g'] ?? '' );
				$v = 'custom' === $g ? trim( sanitize_text_field( $in[ $k ]['hex'] ?? '' ) ) : $g;
				if ( 'custom' === $g && '' !== $v && ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v ) ) {
					$bad[] = $label;
					$v     = '';
				}
				$out[ $k ] = $v;
			}
			$out['border_side']  = in_array( $in['border_side'] ?? '', [ 'left', 'top', 'bottom', 'all', 'none' ], true ) ? $in['border_side'] : 'left';
			$w                   = trim( (string) ( $in['border_width'] ?? '' ) );
			$out['border_width'] = is_numeric( $w ) ? (string) (float) $w : '';
			$font        = sanitize_text_field( $in['font'] ?? '' );
			$out['font'] = ( 'custom' === $font || ( 0 === strpos( $font, 'global:' ) && isset( self::global_fonts()[ substr( $font, 7 ) ] ) ) ) ? $font : '';
			$fam         = sanitize_text_field( $in['family'] ?? '' );
			if ( 'custom' === $out['font'] && '' !== trim( $fam ) && '' === self::clean_family( $fam ) ) {
				$bad[] = 'Font';
			}
			$out['family'] = 'custom' === $out['font'] ? self::clean_family( $fam ) : '';
			$out['weight'] = in_array( (string) ( $in['weight'] ?? '' ), [ '300', '400', '500', '600', '700', '800', '900' ], true ) ? (string) $in['weight'] : '';
			$size          = trim( (string) ( $in['size'] ?? '' ) );
			if ( '' !== $size && ( ! is_numeric( $size ) || (float) $size < 8 || (float) $size > 60 ) ) {
				$bad[] = 'Font size';
				$size  = '';
			}
			$out['size'] = '' === $size ? '' : (string) (float) $size;
			foreach ( [ 'closed', 'special' ] as $k ) {
				$t         = trim( sanitize_text_field( $in[ $k ] ?? '' ) );
				$out[ $k ] = '' === $t ? self::style_defaults()[ $k ] : $t;
			}
			update_option( self::STYLE, $out, false );
			wp_safe_redirect( self::url( [ 'msg' => 'style', 'bad' => rawurlencode( implode( ', ', $bad ) ) ] ) . '#notice' );
			exit;
		}

		if ( 'clear_past' === $do ) {
			$today = self::today();
			self::save( array_values( array_filter( self::all(), fn( $e ) => ( $e['to'] ?: $e['from'] ) >= $today ) ) );
			wp_safe_redirect( self::url( [ 'msg' => 'cleared' ] ) );
			exit;
		}

		$rows    = (array) ( $_POST['h'] ?? [] );
		$slugs   = wp_list_pluck( SMC_Location_Manager::locations(), 'slug' );
		$entries = [];
		$bad     = 0;
		foreach ( $rows as $r ) {
			$from  = sanitize_text_field( wp_unslash( $r['from'] ?? '' ) );
			$to    = sanitize_text_field( wp_unslash( $r['to'] ?? '' ) );
			$label = trim( sanitize_text_field( wp_unslash( $r['label'] ?? '' ) ) );
			$hours = trim( sanitize_text_field( wp_unslash( $r['hours'] ?? '' ) ) );
			if ( '' === $from && '' === $label ) {
				continue; // Empty row.
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ( '' !== $to && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) ) ) {
				$bad++;
				continue;
			}
			$locs      = array_values( array_intersect( array_map( 'sanitize_title', (array) ( $r['locations'] ?? [] ) ), $slugs ) );
			$entries[] = [
				'from'      => $from,
				'to'        => $to === $from ? '' : $to,
				'label'     => '' === $label ? 'Holiday' : $label,
				'hours'     => preg_match( '/^closed$/i', $hours ) ? '' : $hours,
				'locations' => count( $locs ) === count( $slugs ) ? [] : $locs,
			];
		}
		self::save( $entries );
		wp_safe_redirect( self::url( [ 'msg' => 'saved', 'bad' => $bad ] ) );
		exit;
	}

	public static function page() {
		$all   = self::all();
		$locs  = SMC_Location_Manager::locations();
		$today = self::today();
		$msg   = sanitize_key( $_GET['msg'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$year  = (int) wp_date( 'Y' ) + ( (int) wp_date( 'n' ) >= 10 ? 1 : 0 );
		?>
		<div class="wrap smc-holidays">
			<h1>Holiday Closures</h1>
			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>Holidays saved.
				<?php if ( ! empty( $_GET['bad'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
					<?php echo (int) $_GET['bad']; // phpcs:ignore WordPress.Security.NonceVerification ?> row(s) with a missing or invalid date (or an end date before the start) were left out.
				<?php endif; ?></p></div>
			<?php elseif ( 'common' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo (int) ( $_GET['n'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification ?> holiday(s) added. Check the dates and which locations they apply to below.</p></div>
			<?php elseif ( 'style' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>Closure notice saved.
				<?php if ( ! empty( $_GET['bad'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
					These weren't valid and were left at the default: <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['bad'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification ?>.
				<?php endif; ?></p></div>
			<?php elseif ( 'cleared' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>Past holidays removed.</p></div>
			<?php endif; ?>

			<p>Days the offices are closed or keep different hours. They show on the site through <code class="smc-copy">[location_closure_notice]</code> (a notice in the days before a closure) and <code class="smc-copy">[location_holidays]</code> (a list of upcoming closures), in <code>[location_list]</code> on the day itself, and in each location's schema so Google can show holiday hours. Leave <strong>Hours</strong> blank for closed all day. Leave every location unticked to apply to all of them.</p>

			<form method="post">
				<?php wp_nonce_field( 'smc_holidays' ); ?>
				<input type="hidden" name="smc_do" value="save">
				<table class="widefat striped smc-h-table">
					<thead><tr><th>Date</th><th>Through <span class="description">(optional)</span></th><th>Name</th><th>Hours <span class="description">(blank = closed)</span></th><th>Locations <span class="description">(none = all)</span></th><th></th></tr></thead>
					<tbody id="smc-h-rows">
						<?php foreach ( $all as $i => $e ) : ?>
							<?php self::row( $i, $e, $locs, ( $e['to'] ?: $e['from'] ) < $today ); ?>
						<?php endforeach; ?>
						<?php self::row( count( $all ), [ 'from' => '', 'to' => '', 'label' => '', 'hours' => '', 'locations' => [] ], $locs, false ); ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="smc-h-add">Add another</button></p>
				<?php submit_button( 'Save holidays' ); ?>
			</form>

			<?php self::render_style_form(); ?>

			<h2>Add common holidays</h2>
			<form method="post">
				<?php wp_nonce_field( 'smc_holidays' ); ?>
				<input type="hidden" name="smc_do" value="common">
				<p>
					<?php foreach ( self::COMMON as $k => [ $label, $on ] ) : ?>
						<label style="display:inline-block;margin:0 16px 6px 0"><input type="checkbox" name="common[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $on ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</p>
				<p>
					<label>Year <select name="year"><?php for ( $y = (int) wp_date( 'Y' ); $y <= (int) wp_date( 'Y' ) + 2; $y++ ) : ?><option <?php selected( $y, $year ); ?>><?php echo (int) $y; ?></option><?php endfor; ?></select></label>
					<?php submit_button( 'Add to the list', 'secondary', 'submit', false ); ?>
				</p>
				<p class="description">Adds them for every location, closed all day, on the actual date (Independence Day on a Saturday stays on Saturday). Ones already on the list are skipped. Change dates, hours or locations above afterwards.</p>
			</form>

			<?php if ( array_filter( $all, fn( $e ) => ( $e['to'] ?: $e['from'] ) < $today ) ) : ?>
				<form method="post" style="margin-top:20px">
					<?php wp_nonce_field( 'smc_holidays' ); ?>
					<input type="hidden" name="smc_do" value="clear_past">
					<?php submit_button( 'Remove past holidays', 'link-delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<style>
			.smc-holidays .smc-h-table { max-width: 1200px; }
			.smc-holidays .smc-h-table td { vertical-align: top; }
			.smc-holidays .smc-h-table input[type=date] { width: 150px; }
			.smc-holidays .smc-h-table .smc-h-label { width: 200px; }
			.smc-holidays .smc-h-table .smc-h-hours { width: 170px; }
			.smc-holidays .smc-h-locs { max-height: 90px; overflow: auto; min-width: 180px; }
			.smc-holidays .smc-h-locs label { display: block; }
			.smc-holidays tr.is-past { opacity: .5; }
		</style>
		<script>
		( function () {
			var tbody = document.getElementById( 'smc-h-rows' );
			document.getElementById( 'smc-h-add' ).addEventListener( 'click', function () {
				var last = tbody.lastElementChild, row = last.cloneNode( true ), n = tbody.children.length;
				row.classList.remove( 'is-past' );
				row.querySelectorAll( 'input' ).forEach( function ( el ) {
					el.name = el.name.replace( /h\[\d+\]/, 'h[' + n + ']' );
					if ( 'checkbox' === el.type ) { el.checked = false; } else { el.value = ''; }
				} );
				tbody.appendChild( row );
			} );
			tbody.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'smc-h-remove' ) ) {
					var row = e.target.closest( 'tr' );
					if ( tbody.children.length > 1 ) { row.remove(); } else { row.querySelectorAll( 'input' ).forEach( function ( el ) { if ( 'checkbox' === el.type ) { el.checked = false; } else { el.value = ''; } } ); }
				}
			} );
			// "Through" can't be before the date.
			tbody.addEventListener( 'change', function ( e ) {
				if ( 'date' === e.target.type && /\[from\]$/.test( e.target.name ) ) {
					var to = e.target.closest( 'tr' ).querySelector( 'input[name$="[to]"]' );
					to.min = e.target.value;
				}
			} );
		} )();
		</script>
		<?php
	}

	private static function color_field( $name, $value, array $colors, $allow_none = false ) {
		$is_global = isset( $colors[ $value ] );
		$sel       = $is_global ? $value : ( 'none' === $value && $allow_none ? 'none' : ( '' !== $value ? 'custom' : '' ) );
		$out       = '<span class="smc-n-color"><select name="style[' . esc_attr( $name ) . '][g]"><option value="">Default</option>';
		if ( $allow_none ) {
			$out .= '<option value="none" ' . selected( $sel, 'none', false ) . '>None</option>';
		}
		foreach ( $colors as $id => [ $title, $hex ] ) {
			$out .= '<option value="' . esc_attr( $id ) . '" data-color="' . esc_attr( $hex ) . '" ' . selected( $sel, $id, false ) . '>' . esc_html( $title ) . '</option>';
		}
		$out .= '<option value="custom" ' . selected( $sel, 'custom', false ) . '>Custom&hellip;</option></select>';
		$out .= ' <input name="style[' . esc_attr( $name ) . '][hex]" class="code smc-n-hex" size="9" placeholder="#1A73E8" value="' . esc_attr( 'custom' === $sel ? $value : '' ) . '"></span>';
		return $out;
	}

	private static function render_style_form() {
		$st     = self::style();
		$colors = self::global_colors();
		$sample = [ 'from' => wp_date( 'Y-m-d', strtotime( '+5 days' ) ), 'to' => '', 'label' => 'Thanksgiving', 'hours' => '' ];
		$first  = SMC_Location_Manager::locations()[0] ?? null;
		$who    = str_replace( '{location}', $first ? $first->name : 'Springfield', 'Our {location} office' );
		$text   = strtr( $st['closed'], [ '{who}' => $who, '{date}' => self::date_text( $sample ), '{label}' => 'Thanksgiving', '{hours}' => '' ] );
		$map    = array_map( fn( $c ) => $c[1], $colors );
		$fonts  = self::global_fonts();
		$fmap   = array_map( fn( $f ) => $f[1], $fonts );
		$all_fonts = class_exists( '\\Elementor\\Fonts' ) ? array_keys( (array) \Elementor\Fonts::get_fonts() ) : [];
		$pv_fam = 'custom' === $st['font'] ? $st['family'] : ( 0 === strpos( $st['font'], 'global:' ) ? ( $fmap[ substr( $st['font'], 7 ) ] ?? '' ) : '' );
		if ( $pv_fam ) {
			echo '<link rel="stylesheet" href="' . esc_url( 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $pv_fam ) . ':wght@300;400;500;600;700;800;900&display=swap' ) . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources
		}
		?>
		<h2 id="notice">Closure notice</h2>
		<p>How <code>[location_closure_notice]</code> looks and reads everywhere on the site. Put it in each location's header template (and Corporate's), below the navigation, in a container with no padding. Options on a single shortcode still override these.</p>
		<form method="post">
			<?php wp_nonce_field( 'smc_holidays' ); ?>
			<input type="hidden" name="smc_do" value="style">
			<table class="form-table smc-n-form" role="presentation">
				<tr><th scope="row">Show it</th><td><input type="number" name="style[days]" min="1" max="60" class="small-text" value="<?php echo (int) $st['days']; ?>"> days before a closure, and during it</td></tr>
				<tr><th scope="row">Alignment</th><td><select name="style[align]">
					<?php foreach ( [ '' => 'Default (left)', 'left' => 'Left', 'center' => 'Center', 'right' => 'Right' ] as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $st['align'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></td></tr>
				<tr><th scope="row">Background</th><td><?php echo self::color_field( 'background', $st['background'], $colors ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row">Text color</th><td><?php echo self::color_field( 'color', $st['color'], $colors ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row">Border</th><td>
					<?php echo self::color_field( 'border', $st['border'], $colors, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<select name="style[border_side]">
						<?php foreach ( [ 'left' => 'Left side', 'top' => 'Top', 'bottom' => 'Bottom', 'all' => 'All sides', 'none' => 'No border' ] as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $st['border_side'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
					<input name="style[border_width]" class="small-text" value="<?php echo esc_attr( $st['border_width'] ); ?>" placeholder="4"> px wide
				</td></tr>
				<tr><th scope="row">Padding</th><td><input name="style[padding]" class="code" size="10" value="<?php echo esc_attr( $st['padding'] ); ?>" placeholder="12 16"> px <span class="description">Like CSS: <code>12 16</code> is 12 top and bottom, 16 left and right.</span></td></tr>
				<tr><th scope="row">Corner radius</th><td><input name="style[radius]" class="small-text" value="<?php echo esc_attr( $st['radius'] ); ?>" placeholder="0"> px</td></tr>
				<tr><th scope="row">Font</th><td>
					<select name="style[font]" class="smc-n-font">
						<option value="">Default (the site's text)</option>
						<?php foreach ( $fonts as $id => [ $ftitle, $ffam ] ) : ?>
							<option value="global:<?php echo esc_attr( $id ); ?>" data-family="<?php echo esc_attr( $ffam ); ?>" <?php selected( $st['font'], "global:$id" ); ?>>Global: <?php echo esc_html( $ftitle . ( $ffam ? " ($ffam)" : '' ) ); ?></option>
						<?php endforeach; ?>
						<option value="custom" <?php selected( $st['font'], 'custom' ); ?>>Custom&hellip;</option>
					</select>
					<input name="style[family]" class="smc-n-family" list="smc-n-fonts" placeholder="Font name" value="<?php echo esc_attr( $st['family'] ); ?>">
					<datalist id="smc-n-fonts"><?php foreach ( $all_fonts as $f ) : ?><option value="<?php echo esc_attr( $f ); ?>"><?php endforeach; ?></datalist>
					<select name="style[weight]">
						<?php foreach ( [ '' => 'Default weight', '300' => '300 Light', '400' => '400 Regular', '500' => '500 Medium', '600' => '600 Semi-bold', '700' => '700 Bold', '800' => '800 Extra-bold', '900' => '900 Black' ] as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $st['weight'], (string) $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
					<input name="style[size]" class="small-text" value="<?php echo esc_attr( $st['size'] ); ?>" placeholder="16"> px
					<p class="description"><strong>Global</strong> uses one of the fonts on the Brand screen and follows it if it changes. <strong>Custom</strong> takes any Google font name (loaded only on pages with a notice) or a font already on the site.</p>
				</td></tr>
				<tr><th scope="row">Wording when closed</th><td><input name="style[closed]" class="large-text" value="<?php echo esc_attr( $st['closed'] ); ?>"></td></tr>
				<tr><th scope="row">Wording for holiday hours</th><td><input name="style[special]" class="large-text" value="<?php echo esc_attr( $st['special'] ); ?>">
					<p class="description"><code>{who}</code> is "Our Springfield office" on a location's pages and "Our offices" on Corporate pages. <code>{date}</code> "Thursday, November 26", <code>{label}</code> the holiday's name, <code>{hours}</code> its hours.</p></td></tr>
				<tr><th scope="row">Preview</th><td>
					<div class="smc-closure-notice smc-n-preview" style="<?php echo esc_attr( self::notice_style( $st, $map, $fmap ) ); ?>" data-who="<?php echo esc_attr( $who ); ?>" data-date="<?php echo esc_attr( self::date_text( $sample ) ); ?>"><p><?php echo esc_html( $text ); ?></p></div>
					<p class="description">Fonts come from the site, so the text looks slightly different here.</p>
				</td></tr>
			</table>
			<?php submit_button( 'Save closure notice', 'primary', 'submit', true ); ?>
		</form>
		<style>
			.smc-n-preview { max-width: 700px; padding: 12px 16px; border-left: 4px solid <?php echo esc_html( $map['primary'] ?? '#2271b1' ); ?>; background: rgba(0,0,0,.04); }
			.smc-n-preview { font-size: 15px; }
			.smc-n-preview p { margin: 0; color: inherit; font: inherit; }
		</style>
		<script>
		( function () {
			var form = document.querySelector( '.smc-n-form' ).closest( 'form' ), box = form.querySelector( '.smc-n-preview' );
			function color( name ) {
				var sel = form.querySelector( '[name="style[' + name + '][g]"]' ), hex = form.querySelector( '[name="style[' + name + '][hex]"]' );
				hex.style.display = 'custom' === sel.value ? '' : 'none';
				if ( 'custom' === sel.value ) { return hex.value; }
				if ( 'none' === sel.value ) { return 'none'; }
				var o = sel.options[ sel.selectedIndex ];
				return o.getAttribute( 'data-color' ) || '';
			}
			function sides( v ) { v = ( v || '' ).trim(); return v && /^[\d.\s,]+$/.test( v ) ? v.split( /[\s,]+/ ).map( function ( n ) { return n + 'px'; } ).join( ' ' ) : ''; }
			function val( n ) { return form.querySelector( '[name="style[' + n + ']"]' ).value; }
			var loaded = {};
			function update() {
				var s = box.style, bc = color( 'border' ), side = val( 'border_side' ), w = ( val( 'border_width' ) || '4' ) + 'px';
				s.cssText = '';
				if ( val( 'align' ) ) { s.textAlign = val( 'align' ); }
				if ( sides( val( 'padding' ) ) ) { s.padding = sides( val( 'padding' ) ); }
				if ( color( 'background' ) ) { s.background = color( 'background' ); }
				if ( color( 'color' ) ) { s.color = color( 'color' ); }
				if ( 'none' === bc || 'none' === side ) { s.border = '0'; }
				else {
					s.border = '0';
					var c = bc || '<?php echo esc_js( $map['primary'] ?? '#2271b1' ); ?>';
					( 'all' === side ? [ 'Top', 'Right', 'Bottom', 'Left' ] : [ side.charAt( 0 ).toUpperCase() + side.slice( 1 ) ] ).forEach( function ( sd ) { s[ 'border' + sd ] = w + ' solid ' + c; } );
				}
				if ( sides( val( 'radius' ) ) ) { s.borderRadius = sides( val( 'radius' ) ); }
				var fsel = form.querySelector( '.smc-n-font' ), fin = form.querySelector( '.smc-n-family' ), fam = '';
				fin.style.display = 'custom' === fsel.value ? '' : 'none';
				if ( 'custom' === fsel.value ) { fam = fin.value.trim(); }
				else if ( fsel.value ) { fam = fsel.options[ fsel.selectedIndex ].getAttribute( 'data-family' ) || ''; }
				if ( fam && /^[A-Za-z0-9 \-]+$/.test( fam ) ) {
					if ( ! loaded[ fam ] ) {
						loaded[ fam ] = true;
						var l = document.createElement( 'link' ); l.rel = 'stylesheet';
						l.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent( fam ) + ':wght@300;400;500;600;700;800;900&display=swap';
						document.head.appendChild( l );
					}
					s.fontFamily = "'" + fam + "', sans-serif";
				}
				if ( val( 'weight' ) ) { s.fontWeight = val( 'weight' ); }
				if ( val( 'size' ) && ! isNaN( parseFloat( val( 'size' ) ) ) ) { s.fontSize = parseFloat( val( 'size' ) ) + 'px'; }
				box.querySelector( 'p' ).textContent = val( 'closed' ).split( '{who}' ).join( box.getAttribute( 'data-who' ) ).split( '{date}' ).join( box.getAttribute( 'data-date' ) ).split( '{label}' ).join( 'Thanksgiving' ).split( '{hours}' ).join( '' );
			}
			form.addEventListener( 'input', update );
			form.addEventListener( 'change', update );
			update();
		} )();
		</script>
		<?php
	}

	private static function row( $i, array $e, array $locs, $past ) {
		$n = "h[$i]";
		?>
		<tr class="<?php echo $past ? 'is-past' : ''; ?>">
			<td><input type="date" name="<?php echo esc_attr( $n ); ?>[from]" value="<?php echo esc_attr( $e['from'] ); ?>"><?php echo $past ? '<br><span class="description">Past</span>' : ''; ?></td>
			<td><input type="date" name="<?php echo esc_attr( $n ); ?>[to]" value="<?php echo esc_attr( $e['to'] ); ?>" min="<?php echo esc_attr( $e['from'] ); ?>"></td>
			<td><input name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $e['label'] ); ?>" class="smc-h-label" placeholder="Thanksgiving"></td>
			<td><input name="<?php echo esc_attr( $n ); ?>[hours]" value="<?php echo esc_attr( $e['hours'] ); ?>" class="smc-h-hours" placeholder="Closed"></td>
			<td><div class="smc-h-locs">
				<?php foreach ( $locs as $t ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[locations][]" value="<?php echo esc_attr( $t->slug ); ?>" <?php checked( in_array( $t->slug, (array) $e['locations'], true ) ); ?>> <?php echo esc_html( $t->name ); ?></label>
				<?php endforeach; ?>
			</div></td>
			<td><button type="button" class="button-link-delete smc-h-remove">Remove</button></td>
		</tr>
		<?php
	}

	/* ========== Front end ========== */

	/** Data for the browser: [ from, to, label, hours, text ] per entry. */
	private static function items( array $entries, $who, array $a ) {
		$out = [];
		foreach ( $entries as $e ) {
			$tpl   = '' === $e['hours'] ? $a['closed'] : $a['special'];
			$text  = strtr( $tpl, [ '{who}' => $who, '{date}' => self::date_text( $e ), '{label}' => $e['label'], '{hours}' => $e['hours'] ] );
			$out[] = [ 'from' => $e['from'], 'to' => $e['to'] ?: $e['from'], 'text' => $text ];
		}
		return $out;
	}

	/** "Our Springfield office" on a location's pages, "Our offices" elsewhere. */
	private static function who( $tid, $a ) {
		if ( ! $tid ) {
			return $a['who_all'];
		}
		$t = get_term( $tid, self::TAX );
		return str_replace( '{location}', $t && ! is_wp_error( $t ) ? $t->name : '', $a['who'] );
	}

	private static function atts( $atts, $tag, array $extra ) {
		return shortcode_atts(
			array_merge(
				[
					'location' => '',
					'who'      => 'Our {location} office',
					'who_all'  => 'Our offices',
					'closed'   => '{who} will be closed {date} for {label}.',
					'special'  => '{who} will be open {hours} on {date} for {label}.',
				],
				$extra
			),
			$atts,
			$tag
		);
	}

	/**
	 * [location_closure_notice days="14"] - a notice for closures starting within 14 days
	 * (and during them). Shows nothing the rest of the year.
	 *
	 * Look (all optional):
	 *   align="left|center|right"
	 *   padding="12 16"          px, like CSS (1 to 4 numbers)
	 *   background="#fff4e5"     hex, or a global color: primary, secondary, text, accent or a custom color's ID
	 *   color="#1d2327"          text color, same choices
	 *   border="primary"         border color, same choices, or "none"
	 *   border_side="left"       left, top, bottom, all
	 *   border_width="4"         px
	 *   radius="6"               px
	 */
	public static function notice_shortcode( $atts ) {
		$a     = self::atts( $atts, 'location_closure_notice', self::style() );
		$tid   = SMC_Location_Fields::listing_location_id( $a['location'] );
		$items = self::items( self::upcoming( $tid ), self::who( $tid, $a ), $a );
		if ( ! $items ) {
			return '';
		}
		$style = self::notice_style( $a );
		$fam   = self::clean_family( $a['family'] ?? '' );
		$html  = ( '' !== $fam && 0 !== strpos( (string) $a['font'], 'global:' ) ? self::font_link( $fam ) : '' ) . '<div class="smc-closure-notice" role="status" data-days="' . (int) $a['days'] . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';
		foreach ( $items as $i ) {
			$html .= '<p data-from="' . esc_attr( $i['from'] ) . '" data-to="' . esc_attr( $i['to'] ) . '" hidden>' . esc_html( $i['text'] ) . '</p>';
		}
		return $html . '</div>' . self::script();
	}

	/** "#abc", "rgb(...)" or a global color ID ("primary") -> a CSS color. "" if it isn't one. */
	private static function css_color( $v, array $map = [] ) {
		$v = trim( (string) $v );
		if ( isset( $map[ $v ] ) && '' !== $map[ $v ] ) {
			return $map[ $v ]; // Admin preview: real color instead of Elementor's CSS variable.
		}
		$v = strtolower( $v );
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $v ) || preg_match( '/^(rgb|rgba|hsl|hsla)\([\d\s.,%]+\)$/', $v ) || in_array( $v, [ 'transparent', 'white', 'black' ], true ) ) {
			return $v;
		}
		if ( preg_match( '/^[a-z0-9_-]{2,40}$/', $v ) ) {
			return 'var(--e-global-color-' . $v . ')'; // Elementor global color by ID.
		}
		return '';
	}

	/** "12 16" -> "12px 16px". "" if it isn't 1 to 4 numbers. */
	private static function css_sides( $v ) {
		$n = preg_split( '/[\s,]+/', trim( (string) $v ) );
		if ( ! $n || count( $n ) > 4 || array_filter( $n, fn( $x ) => ! is_numeric( $x ) ) ) {
			return '';
		}
		return implode( ' ', array_map( fn( $x ) => (float) $x . 'px', $n ) );
	}

	/** Inline style for the notice from its look options. Only what was set; the rest keeps the default look. */
	private static function notice_style( array $a, array $map = [], array $fmap = [] ) {
		$css  = [];
		$font = (string) ( $a['font'] ?? '' );
		if ( 0 === strpos( $font, 'global:' ) ) {
			$id = sanitize_key( substr( $font, 7 ) );
			if ( isset( $fmap[ $id ] ) && '' !== $fmap[ $id ] ) {
				$css[] = "font-family:'" . self::clean_family( $fmap[ $id ] ) . "',sans-serif"; // Admin preview.
			} else {
				$css[] = "font-family:var(--e-global-typography-$id-font-family)";
				if ( '' === (string) ( $a['weight'] ?? '' ) ) {
					$css[] = "font-weight:var(--e-global-typography-$id-font-weight)";
				}
			}
		} elseif ( 'custom' === $font && '' !== self::clean_family( $a['family'] ?? '' ) ) {
			$css[] = "font-family:'" . self::clean_family( $a['family'] ) . "',sans-serif";
		} elseif ( '' !== self::clean_family( $a['family'] ?? '' ) && '' === $font ) {
			$css[] = "font-family:'" . self::clean_family( $a['family'] ) . "',sans-serif"; // family="..." on the shortcode.
		}
		if ( preg_match( '/^[1-9]00$/', (string) ( $a['weight'] ?? '' ) ) ) {
			$css[] = 'font-weight:' . $a['weight'];
		}
		if ( is_numeric( $a['size'] ?? '' ) && (float) $a['size'] >= 8 && (float) $a['size'] <= 60 ) {
			$css[] = 'font-size:' . (float) $a['size'] . 'px';
		}
		if ( in_array( $a['align'], [ 'left', 'center', 'right' ], true ) ) {
			$css[] = 'text-align:' . $a['align'];
		}
		if ( '' !== trim( $a['padding'] ) && ( $p = self::css_sides( $a['padding'] ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$css[] = 'padding:' . $p;
		}
		if ( '' !== trim( $a['background'] ) && ( $c = self::css_color( $a['background'], $map ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$css[] = 'background:' . $c;
		}
		if ( '' !== trim( $a['color'] ) && ( $c = self::css_color( $a['color'], $map ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$css[] = 'color:' . $c;
		}
		$border = strtolower( trim( $a['border'] ) );
		$side   = strtolower( trim( $a['border_side'] ) );
		$width  = is_numeric( $a['border_width'] ) ? (float) $a['border_width'] : null;
		if ( 'none' === $border || 'none' === $side ) {
			$css[] = 'border:0';
		} elseif ( '' !== $border || null !== $width || 'left' !== $side ) {
			$color = '' !== $border ? self::css_color( $a['border'], $map ) : '';
			$color = $color ?: 'var(--e-global-color-primary,#2271b1)';
			$w     = ( null !== $width ? $width : 4 ) . 'px';
			$css[] = 'border:0';
			$sides = 'all' === $side ? [ 'top', 'right', 'bottom', 'left' ] : [ in_array( $side, [ 'top', 'bottom', 'right' ], true ) ? $side : 'left' ];
			foreach ( $sides as $sd ) {
				$css[] = "border-$sd:$w solid $color";
			}
		}
		if ( '' !== trim( $a['radius'] ) && ( $r = self::css_sides( $a['radius'] ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$css[] = 'border-radius:' . $r;
		}
		return implode( ';', $css );
	}

	/**
	 * [location_holidays days="365" limit="10"] - upcoming closures for the page's location,
	 * one per line. Shows nothing when there are none.
	 */
	public static function list_shortcode( $atts ) {
		$a     = self::atts( $atts, 'location_holidays', [ 'days' => 365, 'limit' => 10, 'closed' => '{date}: closed for {label}', 'special' => '{date}: {hours} ({label})' ] );
		$tid   = SMC_Location_Fields::listing_location_id( $a['location'] );
		$items = self::items( self::upcoming( $tid, max( 1, (int) $a['days'] ) ), self::who( $tid, $a ), $a );
		if ( ! $items ) {
			return '';
		}
		$html = '<ul class="smc-holidays-list" data-limit="' . (int) $a['limit'] . '">';
		foreach ( $items as $i ) {
			$html .= '<li data-from="' . esc_attr( $i['from'] ) . '" data-to="' . esc_attr( $i['to'] ) . '">' . esc_html( $i['text'] ) . '</li>';
		}
		return $html . '</ul>' . self::script();
	}

	/** Shows and hides items by the visitor's date, so cached pages stay right. Printed once per page. */
	private static function script() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return <<<'HTML'
<style>.smc-closure-notice{padding:12px 16px;border-left:4px solid var(--e-global-color-primary,#2271b1);background:rgba(0,0,0,.04);margin:0 0 16px}.smc-closure-notice p{margin:0;color:inherit;font-family:inherit;font-size:inherit;font-weight:inherit;line-height:inherit}.smc-closure-notice p+p{margin-top:6px}.smc-closure-notice.is-empty{display:none}.smc-holidays-list{margin:0;padding-left:1.2em}</style>
<script>
( function () {
	function run() {
		var d = new Date(), today = d.getFullYear() + '-' + ( '0' + ( d.getMonth() + 1 ) ).slice( -2 ) + '-' + ( '0' + d.getDate() ).slice( -2 );
		function plus( ymd, days ) { var t = new Date( ymd + 'T12:00:00' ); t.setDate( t.getDate() + days ); return t.getFullYear() + '-' + ( '0' + ( t.getMonth() + 1 ) ).slice( -2 ) + '-' + ( '0' + t.getDate() ).slice( -2 ); }
		document.querySelectorAll( '.smc-closure-notice' ).forEach( function ( box ) {
			var days = parseInt( box.getAttribute( 'data-days' ), 10 ) || 14, any = false;
			box.querySelectorAll( 'p' ).forEach( function ( p ) {
				var show = p.getAttribute( 'data-to' ) >= today && p.getAttribute( 'data-from' ) <= plus( today, days );
				p.hidden = ! show; any = any || show;
			} );
			box.classList.toggle( 'is-empty', ! any );
		} );
		document.querySelectorAll( '.smc-holidays-list' ).forEach( function ( ul ) {
			var limit = parseInt( ul.getAttribute( 'data-limit' ), 10 ) || 0, shown = 0;
			ul.querySelectorAll( 'li' ).forEach( function ( li ) {
				var show = li.getAttribute( 'data-to' ) >= today && ( ! limit || shown < limit );
				li.hidden = ! show; if ( show ) { shown++; }
			} );
			ul.hidden = ! shown;
		} );
	}
	if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', run ); } else { run(); }
} )();
</script>
HTML;
	}

	/* ========== Schema ========== */

	/** Holiday closures and hours as OpeningHoursSpecification with validFrom / validThrough. */
	public static function schema_specs( $tid ) {
		$out = [];
		foreach ( self::upcoming( $tid ) as $e ) {
			$base = [ '@type' => 'OpeningHoursSpecification', 'validFrom' => $e['from'], 'validThrough' => $e['to'] ?: $e['from'] ];
			$ranges = '' === $e['hours'] ? [] : SMC_Location_Schema::parse_ranges( $e['hours'] );
			if ( '' !== $e['hours'] && ! $ranges ) {
				continue; // Hours we can't read ("by appointment"): leave it to the regular hours.
			}
			if ( ! $ranges ) {
				$out[] = $base + [ 'opens' => '00:00', 'closes' => '00:00' ]; // Closed all day, as Google expects.
			}
			foreach ( $ranges as $r ) {
				$out[] = $base + [ 'opens' => $r[0], 'closes' => $r[1] ];
			}
		}
		return $out;
	}

	/** Holidays for [location_list]'s "today" line: [ from, to, label, hours ]. */
	public static function for_list( $tid ) {
		return array_map( fn( $e ) => [ 'from' => $e['from'], 'to' => $e['to'] ?: $e['from'], 'label' => $e['label'], 'hours' => $e['hours'] ], self::upcoming( $tid, 400 ) );
	}
}
