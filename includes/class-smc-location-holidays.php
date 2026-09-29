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
	 */
	public static function notice_shortcode( $atts ) {
		$a     = self::atts( $atts, 'location_closure_notice', [ 'days' => 14 ] );
		$tid   = SMC_Location_Fields::listing_location_id( $a['location'] );
		$items = self::items( self::upcoming( $tid ), self::who( $tid, $a ), $a );
		if ( ! $items ) {
			return '';
		}
		$html = '<div class="smc-closure-notice" role="status" data-days="' . (int) $a['days'] . '">';
		foreach ( $items as $i ) {
			$html .= '<p data-from="' . esc_attr( $i['from'] ) . '" data-to="' . esc_attr( $i['to'] ) . '" hidden>' . esc_html( $i['text'] ) . '</p>';
		}
		return $html . '</div>' . self::script();
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
<style>.smc-closure-notice{padding:12px 16px;border-left:4px solid var(--e-global-color-primary,#2271b1);background:rgba(0,0,0,.04);margin:0 0 16px}.smc-closure-notice p{margin:0}.smc-closure-notice p+p{margin-top:6px}.smc-closure-notice.is-empty{display:none}.smc-holidays-list{margin:0;padding-left:1.2em}</style>
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
