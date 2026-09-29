<?php
/**
 * [location_list] - every location as cards, with an optional map, search and "near me".
 * Replaces a store locator plugin on the Our Locations page: a location shows up as soon as
 * its main page is published, and always with its current details.
 *
 * Options:
 *   map="yes|no"            map with a pin per location (OpenStreetMap, no API key). Default yes.
 *   search="yes|no"         search box and "Use my location". Default yes.
 *   hours="today|full|none" today's hours (default), the full week, or nothing.
 *   columns="3"             cards per row when there's no map (1 to 4).
 *   button="View Location"  main button text.
 *   directions="Directions" Google Maps directions link; "" hides it.
 *   book="Book Online"      booking link (if the location has one); "" hides it.
 *   state="OH"              only locations in these states (comma-separated).
 *   corporate="no|yes"      include the Corporate location. Default no.
 *
 * Pins come from each location's Google Map embed, so there's nothing extra to fill in.
 * Search matches a location's name, city, zip or street first; anything else (a zip or town
 * that isn't one of the locations) is looked up and the locations are sorted by distance.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_List {

	const TAX     = 'location_category';
	const LEAFLET = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/';

	public static function init() {
		add_action( 'init', function () {
			if ( ! shortcode_exists( 'location_list' ) ) {
				add_shortcode( 'location_list', [ __CLASS__, 'shortcode' ] );
			}
		} );
	}

	/** [ lat, lng ] from a location's Google Map embed, or null. */
	public static function coords( $tid ) {
		$src = SMC_Location_Fields::map_src( get_term_meta( $tid, 'map_embed', true ) );
		$src = rawurldecode( $src );
		if ( preg_match( '/!2d(-?\d+(?:\.\d+)?)!3d(-?\d+(?:\.\d+)?)/', $src, $m ) ) { // pb=...!2d<lng>!3d<lat>
			$ll = [ (float) $m[2], (float) $m[1] ];
		} elseif ( preg_match( '/[?&](?:q|ll|center)=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/', $src, $m ) ) {
			$ll = [ (float) $m[1], (float) $m[2] ];
		} else {
			$ll = null;
		}
		return apply_filters( 'smc_location_coords', $ll, $tid );
	}

	/** Whether a published page or template uses [location_list]. */
	public static function in_use() {
		static $used = null;
		if ( null === $used ) {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( '[location_list' ) . '%';
			$used = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
					WHERE p.post_status = 'publish' AND ( p.post_content LIKE %s OR m.meta_value LIKE %s ) LIMIT 1",
					$like,
					$like
				)
			);
		}
		return $used;
	}

	/** Locations to list: those with a published main page (Corporate only if asked for). */
	public static function items( $corporate = false, $states = [] ) {
		$terms = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false, 'orderby' => 'name' ] );
		$out   = [];
		foreach ( is_wp_error( $terms ) ? [] : $terms as $t ) {
			$is_corp = SMC_Location_Fields::is_corporate( $t->term_id );
			if ( $is_corp && ! $corporate ) {
				continue;
			}
			$page = $is_corp ? null : SMC_Location_Manager::location_page( $t );
			if ( ! $is_corp && ( ! $page || 'publish' !== $page->post_status ) ) {
				continue; // Not launched yet.
			}
			$m     = fn( $k ) => trim( (string) get_term_meta( $t->term_id, $k, true ) );
			$lines = array_values( array_filter( array_map( 'trim', preg_split( '#\s*<br\s*/?>\s*#i', $m( 'address' ) ) ) ) );
			$state = preg_match( '/,\s*([A-Z]{2})\b/', $m( 'city_state' ) ?: ( $lines[1] ?? '' ), $sm ) ? $sm[1] : '';
			if ( $states && ! in_array( $state, $states, true ) ) {
				continue;
			}
			$hours = [];
			foreach ( array_keys( SMC_Location_Fields::DAYS ) as $d ) {
				$hours[] = $m( "hours_$d" );
			}
			$phone = $m( 'phone_label' );
			$out[] = [
				'id'      => $t->term_id,
				'name'    => $t->name,
				'url'     => $page ? get_permalink( $page ) : home_url( '/' ),
				'lines'   => $lines,
				'state'   => $state,
				'phone'   => $phone,
				'tel'     => $m( 'phone_link' ) ?: ( '' !== $phone ? 'tel:' . preg_replace( '/[^\d+]/', '', $phone ) : '' ),
				'booking' => $m( 'booking_link' ),
				'hours'   => $hours,
				'll'      => self::coords( $t->term_id ),
			];
		}
		return apply_filters( 'smc_location_list_items', $out );
	}

	public static function shortcode( $atts ) {
		$a = shortcode_atts(
			[
				'map'        => 'yes',
				'search'     => 'yes',
				'hours'      => 'today',
				'columns'    => 3,
				'button'     => 'View Location',
				'directions' => 'Directions',
				'book'       => 'Book Online',
				'state'      => '',
				'corporate'  => 'no',
			],
			$atts,
			'location_list'
		);
		$yes    = fn( $v ) => ! in_array( strtolower( trim( (string) $v ) ), [ 'no', 'false', '0', 'off', '' ], true );
		$states = array_filter( array_map( 'strtoupper', array_map( 'trim', explode( ',', $a['state'] ) ) ) );
		$items  = self::items( $yes( $a['corporate'] ), $states );
		if ( ! $items ) {
			return '';
		}
		$pins    = array_filter( $items, fn( $i ) => $i['ll'] );
		$has_map = $yes( $a['map'] ) && $pins;
		$id      = 'smc-loclist-' . wp_unique_id();
		$cols    = max( 1, min( 4, (int) $a['columns'] ) );
		$days    = array_values( SMC_Location_Fields::DAYS );

		if ( $has_map ) {
			wp_enqueue_style( 'leaflet', self::LEAFLET . 'leaflet.min.css', [], '1.9.4' );
			wp_enqueue_script( 'leaflet', self::LEAFLET . 'leaflet.min.js', [], '1.9.4', true );
		}
		wp_enqueue_script( 'smc-location-list', false, $has_map ? [ 'leaflet' ] : [], null, true );

		ob_start();
		?>
		<div class="smc-loclist<?php echo $has_map ? ' has-map' : ''; ?>" id="<?php echo esc_attr( $id ); ?>" style="--smc-cols:<?php echo (int) $cols; ?>">
			<?php if ( $yes( $a['search'] ) ) : ?>
				<form class="smc-loclist-search" role="search">
					<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-q">Search by city or zip</label>
					<input type="search" id="<?php echo esc_attr( $id ); ?>-q" placeholder="City or zip code" autocomplete="postal-code">
					<button type="submit" class="elementor-button">Search</button>
					<button type="button" class="smc-loclist-near">Use my location</button>
					<button type="button" class="smc-loclist-reset" hidden>Show all</button>
				</form>
				<p class="smc-loclist-status" aria-live="polite"></p>
			<?php endif; ?>
			<div class="smc-loclist-body">
				<?php if ( $has_map ) : ?>
					<div class="smc-loclist-map" aria-hidden="true"></div>
				<?php endif; ?>
				<div class="smc-loclist-items">
					<?php foreach ( $items as $i ) : ?>
						<?php
						$search = strtolower( implode( ' ', array_merge( [ $i['name'] ], $i['lines'] ) ) );
						$dir    = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( implode( ', ', $i['lines'] ) ?: $i['name'] );
						?>
						<article class="smc-loclist-item" data-id="<?php echo (int) $i['id']; ?>" data-search="<?php echo esc_attr( $search ); ?>"
							<?php if ( $i['ll'] ) : ?>data-lat="<?php echo esc_attr( $i['ll'][0] ); ?>" data-lng="<?php echo esc_attr( $i['ll'][1] ); ?>"<?php endif; ?>
							data-hours="<?php echo esc_attr( wp_json_encode( $i['hours'] ) ); ?>">
							<h3 class="smc-loclist-name"><a href="<?php echo esc_url( $i['url'] ); ?>"><?php echo esc_html( $i['name'] ); ?></a> <span class="smc-loclist-distance"></span></h3>
							<?php if ( $i['lines'] ) : ?>
								<div class="smc-loclist-address"><?php echo implode( '<br>', array_map( 'esc_html', $i['lines'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
							<?php endif; ?>
							<?php if ( '' !== $i['phone'] ) : ?>
								<div class="smc-loclist-phone"><a href="<?php echo esc_url( $i['tel'], [ 'tel' ] ); ?>"><?php echo esc_html( $i['phone'] ); ?></a></div>
							<?php endif; ?>
							<?php if ( 'today' === $a['hours'] ) : ?>
								<div class="smc-loclist-today"></div>
							<?php elseif ( 'full' === $a['hours'] ) : ?>
								<?php echo SMC_Location_Fields::hours( [ 'location' => get_term( $i['id'] )->slug ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
							<div class="smc-loclist-actions">
								<?php if ( '' !== $a['button'] ) : ?><a class="elementor-button smc-loclist-view" href="<?php echo esc_url( $i['url'] ); ?>"><?php echo esc_html( $a['button'] ); ?></a><?php endif; ?>
								<?php if ( '' !== $a['directions'] && $i['lines'] ) : ?><a class="smc-loclist-link" href="<?php echo esc_url( $dir ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $a['directions'] ); ?></a><?php endif; ?>
								<?php if ( '' !== $a['book'] && '' !== $i['booking'] ) : ?><a class="smc-loclist-link" href="<?php echo esc_url( $i['booking'] ); ?>"><?php echo esc_html( $a['book'] ); ?></a><?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
		$html = ob_get_clean();
		wp_add_inline_script( 'smc-location-list', self::script( $id, $days ) );
		static $css = false;
		if ( ! $css ) {
			$css   = true;
			$html .= '<style>' . self::css() . '</style>';
		}
		return $html;
	}

	private static function script( $id, $days ) {
		$tiles = apply_filters(
			'smc_location_list_tiles',
			[
				'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
			]
		);
		$cfg = wp_json_encode( [ 'id' => $id, 'days' => $days, 'tiles' => $tiles ] );
		return <<<JS
( function ( cfg ) {
	var root = document.getElementById( cfg.id );
	if ( ! root ) { return; }
	var items = Array.prototype.slice.call( root.querySelectorAll( '.smc-loclist-item' ) );
	var list = root.querySelector( '.smc-loclist-items' );
	var status = root.querySelector( '.smc-loclist-status' );
	var reset = root.querySelector( '.smc-loclist-reset' );
	var order = items.slice();

	// Today's hours, in the visitor's own week (pages may be cached, so this runs in the browser).
	var today = ( new Date().getDay() + 6 ) % 7; // Monday = 0
	items.forEach( function ( el ) {
		var box = el.querySelector( '.smc-loclist-today' );
		if ( ! box ) { return; }
		var h = [];
		try { h = JSON.parse( el.getAttribute( 'data-hours' ) || '[]' ); } catch ( e ) {}
		var t = ( h[ today ] || '' ).trim();
		if ( ! t || 'none' === t.toLowerCase() ) { return; }
		box.innerHTML = '<strong>' + ( /^closed$/i.test( t ) ? 'Closed today' : 'Today:' ) + '</strong>' + ( /^closed$/i.test( t ) ? '' : ' ' + t.replace( /</g, '&lt;' ) );
	} );

	// Map.
	var map = null, markers = {};
	var mapEl = root.querySelector( '.smc-loclist-map' );
	function initMap() {
		if ( ! mapEl || ! window.L ) { return; }
		var color = getComputedStyle( document.documentElement ).getPropertyValue( '--e-global-color-primary' ).trim() || '#2271b1';
		map = L.map( mapEl, { scrollWheelZoom: false } );
		L.tileLayer( cfg.tiles.url, { attribution: cfg.tiles.attribution, maxZoom: 18 } ).addTo( map );
		var bounds = [];
		items.forEach( function ( el ) {
			var lat = parseFloat( el.getAttribute( 'data-lat' ) ), lng = parseFloat( el.getAttribute( 'data-lng' ) );
			if ( isNaN( lat ) || isNaN( lng ) ) { return; }
			var a = el.querySelector( '.smc-loclist-name a' );
			var m = L.circleMarker( [ lat, lng ], { radius: 9, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1 } ).addTo( map );
			m.bindPopup( '<strong><a href="' + a.href + '">' + a.textContent + '</a></strong><br>' + ( el.querySelector( '.smc-loclist-address' ) || { innerHTML: '' } ).innerHTML );
			m.on( 'click', function () { highlight( el, true ); } );
			markers[ el.getAttribute( 'data-id' ) ] = m;
			bounds.push( [ lat, lng ] );
		} );
		if ( 1 === bounds.length ) { map.setView( bounds[0], 12 ); } else { map.fitBounds( bounds, { padding: [ 30, 30 ] } ); }
	}
	function highlight( el, scroll ) {
		items.forEach( function ( i ) { i.classList.toggle( 'is-active', i === el ); } );
		if ( scroll ) { el.scrollIntoView( { behavior: 'smooth', block: 'nearest' } ); }
	}
	items.forEach( function ( el ) {
		el.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( 'a' ) ) { return; }
			var m = markers[ el.getAttribute( 'data-id' ) ];
			highlight( el, false );
			if ( m && map ) { map.setView( m.getLatLng(), Math.max( map.getZoom(), 11 ) ); m.openPopup(); }
		} );
	} );

	// Search and "near me".
	function miles( a, b, c, d ) {
		var r = Math.PI / 180, x = Math.sin( ( c - a ) * r / 2 ), y = Math.sin( ( d - b ) * r / 2 );
		return 7917.5 * Math.asin( Math.sqrt( x * x + Math.cos( a * r ) * Math.cos( c * r ) * y * y ) );
	}
	function show( list2, msg ) {
		items.forEach( function ( el ) { el.hidden = list2.indexOf( el ) < 0; } );
		list2.forEach( function ( el ) { list.appendChild( el ); } );
		if ( status ) { status.textContent = msg || ''; }
		if ( reset ) { reset.hidden = ! msg; }
		if ( map ) {
			var b = list2.map( function ( el ) { var m = markers[ el.getAttribute( 'data-id' ) ]; return m && m.getLatLng(); } ).filter( Boolean );
			if ( b.length ) { map.fitBounds( b, { padding: [ 30, 30 ], maxZoom: 12 } ); }
		}
	}
	function sortFrom( lat, lng, label ) {
		var sorted = items.slice().sort( function ( a, b ) {
			var da = a.hasAttribute( 'data-lat' ) ? miles( lat, lng, +a.getAttribute( 'data-lat' ), +a.getAttribute( 'data-lng' ) ) : 1e9;
			var db = b.hasAttribute( 'data-lat' ) ? miles( lat, lng, +b.getAttribute( 'data-lat' ), +b.getAttribute( 'data-lng' ) ) : 1e9;
			a._d = da; b._d = db;
			return da - db;
		} );
		items.forEach( function ( el ) {
			var d = el.querySelector( '.smc-loclist-distance' );
			d.textContent = el._d < 1e9 ? ( el._d < 10 ? el._d.toFixed( 1 ) : Math.round( el._d ) ) + ' mi' : '';
		} );
		show( sorted, 'Closest to ' + label + ' first.' );
		if ( sorted[0] ) { highlight( sorted[0], false ); }
	}
	function clearDistances() { items.forEach( function ( el ) { el.querySelector( '.smc-loclist-distance' ).textContent = ''; el.classList.remove( 'is-active' ); } ); }

	var form = root.querySelector( '.smc-loclist-search' );
	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var q = form.querySelector( 'input' ).value.trim();
			if ( ! q ) { clearDistances(); show( order ); return; }
			var ql = q.toLowerCase();
			var hits = items.filter( function ( el ) { return el.getAttribute( 'data-search' ).indexOf( ql ) > -1; } );
			if ( hits.length ) { clearDistances(); show( hits, hits.length + ' location' + ( 1 === hits.length ? '' : 's' ) + ' matching "' + q + '".' ); return; }
			if ( status ) { status.textContent = 'Looking up ' + q + '…'; }
			var params = /^\d{5}$/.test( q ) ? 'postalcode=' + q + '&countrycodes=us' : 'q=' + encodeURIComponent( q ) + '&countrycodes=us';
			fetch( 'https://nominatim.openstreetmap.org/search?format=json&limit=1&' + params, { headers: { 'Accept': 'application/json' } } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( r ) {
					if ( r && r[0] ) { sortFrom( parseFloat( r[0].lat ), parseFloat( r[0].lon ), q ); }
					else { show( [], 'No locations found for "' + q + '".' ); }
				} )
				.catch( function () { show( [], 'No locations found for "' + q + '".' ); } );
		} );
		root.querySelector( '.smc-loclist-near' ).addEventListener( 'click', function () {
			if ( ! navigator.geolocation ) { return; }
			if ( status ) { status.textContent = 'Finding your location…'; }
			navigator.geolocation.getCurrentPosition(
				function ( p ) { sortFrom( p.coords.latitude, p.coords.longitude, 'you' ); },
				function () { if ( status ) { status.textContent = 'Couldn\'t get your location. Try a city or zip code.'; } },
				{ timeout: 10000 }
			);
		} );
		reset.addEventListener( 'click', function () { form.querySelector( 'input' ).value = ''; clearDistances(); show( order ); } );
	}

	if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', initMap ); } else { initMap(); }
} )( $cfg );
JS;
	}

	private static function css() {
		return '.smc-loclist-search{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 8px}'
			. '.smc-loclist-search input{flex:1 1 220px;min-width:0}'
			. '.smc-loclist-search button{cursor:pointer}'
			. '.smc-loclist-near,.smc-loclist-reset{background:none;border:0;padding:0 6px;text-decoration:underline;color:inherit}'
			. '.smc-loclist-status{margin:0 0 12px;min-height:1.2em;font-size:.9em;opacity:.8}'
			. '.smc-loclist-body{display:grid;gap:24px}'
			. '.smc-loclist-items{display:grid;gap:16px;grid-template-columns:repeat(var(--smc-cols,3),minmax(0,1fr))}'
			. '.smc-loclist.has-map .smc-loclist-body{grid-template-columns:minmax(0,1.3fr) minmax(0,1fr)}'
			. '.smc-loclist.has-map .smc-loclist-items{grid-template-columns:1fr;max-height:560px;overflow:auto;padding-right:4px}'
			. '.smc-loclist-map{height:560px;border-radius:8px;z-index:0}'
			. '.smc-loclist-item{border:1px solid rgba(0,0,0,.12);border-radius:8px;padding:18px;cursor:pointer;transition:border-color .15s,box-shadow .15s}'
			. '.smc-loclist-item.is-active{border-color:var(--e-global-color-primary,#2271b1);box-shadow:0 0 0 1px var(--e-global-color-primary,#2271b1)}'
			. '.smc-loclist-name{margin:0 0 6px;font-size:1.2em}'
			. '.smc-loclist-name a{text-decoration:none;color:inherit}'
			. '.smc-loclist-distance{font-size:.7em;font-weight:400;opacity:.7;margin-left:6px}'
			. '.smc-loclist-address,.smc-loclist-phone,.smc-loclist-today,.smc-loclist-item .smc-location-hours{margin-bottom:6px}'
			. '.smc-loclist-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;margin-top:12px}'
			. '.smc-loclist-link{text-decoration:underline}'
			. '@media (max-width:1024px){.smc-loclist-items{grid-template-columns:repeat(min(var(--smc-cols,3),2),minmax(0,1fr))}}'
			. '@media (max-width:767px){.smc-loclist.has-map .smc-loclist-body{grid-template-columns:1fr}.smc-loclist-map{height:320px}.smc-loclist.has-map .smc-loclist-items{max-height:none;overflow:visible}.smc-loclist-items{grid-template-columns:1fr}}';
	}
}
