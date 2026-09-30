<?php
/**
 * Hours, social links and Google Map for SMC locations.
 *
 * Adds fields to every location_category term (ACF, registered in code so every
 * site gets the same fields), and shortcodes that render them for the current
 * page's location, the same way [location field="..."] does:
 *
 *   [location_hours]                 hours, formatted per Locations > Settings
 *   [location_map]                   Google Maps iframe; height is set in Locations > Settings
 *   [location_social]                list of social links
 *
 * Every shortcode takes location="springfield" to show a specific location instead
 * (useful on corporate or "Our Locations" pages). Single values also work with
 * the existing shortcode, e.g. [location field="hours_monday"] or
 * [location field="facebook_url"] for a button or icon link.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Fields {

	const TAX = 'location_category';

	const DAYS = [
		'monday'    => 'Monday',
		'tuesday'   => 'Tuesday',
		'wednesday' => 'Wednesday',
		'thursday'  => 'Thursday',
		'friday'    => 'Friday',
		'saturday'  => 'Saturday',
		'sunday'    => 'Sunday',
	];

	const SOCIAL = [
		'facebook_url'        => 'Facebook',
		'instagram_url'       => 'Instagram',
		'youtube_url'         => 'YouTube',
		'tiktok_url'          => 'TikTok',
		'google_business_url' => 'Google',
	];

	public static function init() {
		add_action( 'acf/init', [ __CLASS__, 'register_fields' ] );
		add_action( 'init', [ __CLASS__, 'register_shortcodes' ], 20 );
		add_filter( 'elementor/widget/render_content', [ __CLASS__, 'hide_empty_social' ], 10, 2 );
		// Shortcode help under each location field (ACF category editor + copy on click).
		add_filter( 'acf/prepare_field', [ __CLASS__, 'acf_help' ] );
		add_action( 'admin_footer', [ __CLASS__, 'copy_script' ] );

		add_filter( 'elementor/widget/render_content', [ __CLASS__, 'fix_email_links' ], 5 );
		add_filter( 'the_content', [ __CLASS__, 'fix_email_links' ], 20 );
		add_filter( 'elementor/widget/render_content', [ __CLASS__, 'hide_empty_button' ], 10, 2 );

		// Address: two lines on its own, one line inside a sentence.
		add_filter( 'do_shortcode_tag', [ __CLASS__, 'address_output' ], 10, 3 );
		add_filter( 'elementor/widget/render_content', [ __CLASS__, 'address_context' ], 20 );
		add_filter( 'the_content', [ __CLASS__, 'address_context' ], 20 );
		add_filter( 'widget_text', [ __CLASS__, 'address_context' ], 20 );
	}

	/**
	 * Turns an email address used as a link into a mailto: link.
	 *
	 * When a plain address (e.g. from [location field="email"]) lands in an Elementor link
	 * field, Elementor treats it as a web address and outputs
	 * href="http://sandiego@example.com", which opens the website instead of an email.
	 */
	public static function fix_email_links( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, '@' ) ) {
			return $html;
		}
		return preg_replace(
			'#\bhref=(["\'])(?:https?:)?(?://)?([A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,})/?\1#i',
			'href=$1mailto:$2$1',
			$html
		);
	}

	/**
	 * Removes an Elementor Button whose link comes from a [location ...] or [team ...] shortcode
	 * that's empty here, e.g. an "Email Us" button at a location with no email, or a "Read Bio"
	 * button when the location has no Meet the Doctors page.
	 * In the Elementor editor the button is dimmed instead.
	 */
	public static function hide_empty_button( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'button' !== $widget->get_name() ) {
			return $content;
		}
		if ( class_exists( 'SMC_Location_Settings' ) && ! SMC_Location_Settings::get( 'hide_empty_buttons' ) ) {
			return $content;
		}
		$dynamic = (array) $widget->get_settings( '__dynamic__' );
		if ( empty( $dynamic['link'] ) || ! preg_match( '/\[(location|team)\b/i', rawurldecode( (string) $dynamic['link'] ) ) ) {
			return $content;
		}
		if ( preg_match( '/<a\b[^>]*\shref\s*=\s*(["\'])(.*?)\1/is', $content, $h ) && ! in_array( strtolower( trim( $h[2] ) ), [ '', '#', 'mailto:', 'tel:' ], true ) ) {
			return $content;
		}
		$el = class_exists( '\\Elementor\\Plugin' ) ? \Elementor\Plugin::$instance : null;
		if ( $el && ( ( $el->editor && $el->editor->is_edit_mode() ) || ( $el->preview && $el->preview->is_preview_mode() ) ) ) {
			return '<div style="opacity:.35" title="No link for this location. Hidden on the live site.">' . $content . '</div>';
		}
		return '';
	}

	/**
	 * [location field="address"] output.
	 *
	 * format="lines"  always two lines
	 * format="inline" always one line: "123 Main St, Springfield, ST 12345"
	 * (default)       decided by address_context(): two lines when the address is on its
	 *                 own, one line when it sits in a paragraph with other text.
	 */
	public static function address_output( $output, $tag, $attr ) {
		if ( 'location' !== $tag || ! is_array( $attr ) ) {
			return $output;
		}
		$field = $attr['field'] ?? '';

		// [location field="email_label"] -> falls back to the site default when the location has none.
		if ( 'email_label' === $field ) {
			$label = is_string( $output ) ? trim( $output ) : '';
			if ( '' === $label && class_exists( 'SMC_Location_Settings' ) ) {
				$label = (string) SMC_Location_Settings::get( 'email_label_default' );
			}
			return '' !== $label ? esc_html( $label ) : 'Email Us';
		}

		// [location field="email_link"] -> mailto: link built from the location's email.
		if ( 'email_link' === $field ) {
			$tid   = self::term_id( [] );
			$email = $tid ? sanitize_email( (string) get_term_meta( $tid, 'email', true ) ) : '';
			return $email ? 'mailto:' . $email : '';
		}

		if ( 'address' !== $field || ! is_string( $output ) || '' === $output ) {
			return $output;
		}
		$parts  = preg_split( '#\s*<br\s*/?>\s*#i', trim( $output ) );
		$format = strtolower( (string) ( $attr['format'] ?? '' ) );
		if ( 'inline' === $format ) {
			return implode( ', ', $parts );
		}
		if ( 'lines' === $format || count( $parts ) < 2 ) {
			return $output;
		}
		return '<span class="smc-location-address">' . implode( '<br>', $parts ) . '</span>';
	}

	/**
	 * Collapses an address to one line when its paragraph (or list item, heading, table cell)
	 * has other text in it. An address on its own keeps its line break.
	 */
	public static function address_context( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, 'smc-location-address' ) ) {
			return $html;
		}
		$span = '#<span class="smc-location-address">(.*?)</span>#is';
		return preg_replace_callback(
			'#<(p|li|td|th|dd|h[1-6]|figcaption|blockquote)\b[^>]*>.*?</\1>#is',
			function ( $block ) use ( $span ) {
				if ( false === strpos( $block[0], 'smc-location-address' ) ) {
					return $block[0];
				}
				$rest = wp_strip_all_tags( preg_replace( $span, '', $block[0] ) );
				$rest = trim( html_entity_decode( $rest, ENT_QUOTES, 'UTF-8' ), " \t\n\r\0\x0B\xC2\xA0" );
				if ( '' === $rest ) {
					return $block[0];
				}
				return preg_replace_callback(
					$span,
					fn( $a ) => '<span class="smc-location-address smc-address-inline">' . preg_replace( '#\s*<br\s*/?>\s*#i', ', ', $a[1] ) . '</span>',
					$block[0]
				);
			},
			$html
		);
	}

	/**
	 * Removes icons with no link from Elementor's Social Icons widget, so an icon whose
	 * link comes from [location field="facebook_url"] disappears for locations without one.
	 * In the Elementor editor the icon is dimmed instead, so it can still be edited.
	 */
	public static function hide_empty_social( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'social-icons' !== $widget->get_name() ) {
			return $content;
		}
		if ( class_exists( 'SMC_Location_Settings' ) && ! SMC_Location_Settings::get( 'hide_empty_social' ) ) {
			return $content;
		}
		$editing = false;
		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			$el      = \Elementor\Plugin::$instance;
			$editing = ( $el->editor && $el->editor->is_edit_mode() ) || ( $el->preview && $el->preview->is_preview_mode() );
		}

		return preg_replace_callback(
			'#(<(span|div)\b[^>]*class="[^"]*\belementor-grid-item\b[^"]*"[^>]*>)(\s*<a\b([^>]*)>.*?</a>\s*)(</\2>)#s',
			function ( $m ) use ( $editing ) {
				if ( preg_match( '/\shref\s*=\s*(["\'])(.*?)\1/i', $m[4], $h ) && ! in_array( trim( $h[2] ), [ '', '#' ], true ) ) {
					return $m[0];
				}
				if ( ! $editing ) {
					return '';
				}
				$open = preg_replace( '/^<(span|div)\b/', '<$1 style="opacity:.35" title="No link for this location. Hidden on the live site."', $m[1] );
				return $open . $m[3] . $m[5];
			},
			$content
		);
	}

	/* ========== Field definitions ========== */

	/** All field names this class adds. */
	public static function names() {
		$names = [ 'email', 'email_label' ];
		foreach ( array_keys( self::DAYS ) as $d ) {
			$names[] = "hours_$d";
		}
		$names[] = 'hours_note';
		$names   = array_merge( $names, array_keys( self::SOCIAL ) );
		$names[] = 'map_embed';
		$names[] = 'form_embed';
		return $names;
	}

	/** ACF field key for one of our field names, or null. */
	public static function field_key( $name ) {
		return in_array( $name, self::names(), true ) ? 'field_smc_loc_' . $name : null;
	}

	public static function register_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		$f = [];

		$f[] = [ 'key' => 'field_smc_loc_tab_contact', 'label' => 'Email', 'type' => 'tab' ];
		$f[] = [
			'key'          => self::field_key( 'email' ),
			'label'        => 'Email address',
			'name'         => 'email',
			'type'         => 'email',
			'instructions' => 'The office\'s email address.',
			'wrapper'      => [ 'width' => '50' ],
		];
		$f[] = [
			'key'          => self::field_key( 'email_label' ),
			'label'        => 'Email button text',
			'name'         => 'email_label',
			'type'         => 'text',
			'placeholder'  => 'Email Us',
			'instructions' => 'Leave blank to use the site default from Locations > Settings.',
			'wrapper'      => [ 'width' => '50' ],
		];

		$f[] = [ 'key' => 'field_smc_loc_tab_hours', 'label' => 'Hours', 'type' => 'tab' ];
		foreach ( self::DAYS as $d => $label ) {
			$f[] = [
				'key'         => self::field_key( "hours_$d" ),
				'label'       => $label,
				'name'        => "hours_$d",
				'type'        => 'text',
				'placeholder' => in_array( $d, [ 'saturday', 'sunday' ], true ) ? 'Closed' : '8:00 AM - 5:00 PM',
				'wrapper'     => [ 'width' => '25' ],
			];
		}
		$f[] = [
			'key'          => self::field_key( 'hours_note' ),
			'label'        => 'Hours note',
			'name'         => 'hours_note',
			'type'         => 'text',
			'instructions' => 'Optional line shown under the hours, e.g. "Evening appointments available on request."',
		];

		$f[] = [ 'key' => 'field_smc_loc_tab_social', 'label' => 'Social', 'type' => 'tab' ];
		foreach ( self::SOCIAL as $name => $label ) {
			$f[] = [
				'key'     => self::field_key( $name ),
				'label'   => 'google_business_url' === $name ? 'Google Business Profile' : $label,
				'name'    => $name,
				'type'    => 'url',
				'wrapper' => [ 'width' => '50' ],
			];
		}

		$f[] = [ 'key' => 'field_smc_loc_tab_form', 'label' => 'Form', 'type' => 'tab' ];
		$f[] = [
			'key'          => self::field_key( 'form_embed' ),
			'label'        => 'Embedded form',
			'name'         => 'form_embed',
			'type'         => 'textarea',
			'rows'         => 3,
			'new_lines'    => '',
			'instructions' => 'The JotForm for contact pages, popups, etc. Paste the form link or its embed code (Publish > Embed). Leave blank to embed the booking form.',
		];

		$f[] = [ 'key' => 'field_smc_loc_tab_map', 'label' => 'Map', 'type' => 'tab' ];
		$f[] = [
			'key'          => self::field_key( 'map_embed' ),
			'label'        => 'Google Map',
			'name'         => 'map_embed',
			'type'         => 'textarea',
			'rows'         => 3,
			'new_lines'    => '',
			'instructions' => 'In Google Maps, find the office, click Share > Embed a map > Copy HTML, and paste it here. The embed link on its own also works.',
		];

		acf_add_local_field_group(
			[
				'key'        => 'group_smc_location_details',
				'title'      => 'Email, Hours, Social & Map',
				'fields'     => $f,
				'location'   => [ [ [ 'param' => 'taxonomy', 'operator' => '==', 'value' => self::TAX ] ] ],
				'menu_order' => 20,
			]
		);
	}

	/* ========== Helpers ========== */

	/**
	 * Returns the embed URL from pasted Google Maps embed code or a bare embed URL.
	 * Anything that isn't a google.com/maps/embed URL returns ''.
	 */
	public static function map_src( $raw ) {
		$raw = trim( html_entity_decode( (string) $raw, ENT_QUOTES ) );
		if ( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $raw, $m ) ) {
			$raw = $m[1];
		}
		$url  = esc_url_raw( $raw );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match( '/(^|\.)google\.[a-z.]+$/i', $host ) || 0 !== strpos( $path, '/maps/embed' ) ) {
			return '';
		}
		return $url;
	}

	/** Location term for a shortcode: location="slug" if given, else the current page's location. */
	/* ========== Shortcode help ========== */

	/**
	 * Which shortcode shows each field. Each entry: [ [ label, shortcode ], ... ].
	 * "street" and "city_state_zip" are the Edit screen's two halves of "address".
	 */
	public static function help_map() {
		$map = [
			'name'            => [ [ '', '[location field="name"]' ] ],
			'city_state'      => [ [ '', '[location field="city_state"]' ] ],
			'address'         => [ [ '', '[location field="address"]' ], [ 'always one line', '[location field="address" format="inline"]' ] ],
			'street'          => [ [ 'full address', '[location field="address"]' ], [ 'one line', '[location field="address" format="inline"]' ] ],
			'city_state_zip'  => [ [ 'full address', '[location field="address"]' ] ],
			'phone_label'     => [ [ 'text', '[location field="phone_label"]' ], [ 'tap-to-call link', '[location field="phone_link"]' ] ],
			'phone'           => [ [ 'text', '[location field="phone_label"]' ], [ 'tap-to-call link', '[location field="phone_link"]' ] ],
			'phone_link'      => [ [ 'link', '[location field="phone_link"]' ] ],
			'email'           => [ [ 'address', '[location field="email"]' ], [ 'button link', '[location field="email_link"]' ] ],
			'email_label'     => [ [ 'button text', '[location field="email_label"]' ] ],
			'booking_label'   => [ [ 'button text', '[location field="booking_label"]' ] ],
			'booking_link'    => [ [ 'button link', '[location field="booking_link"]' ] ],
			'booking_classes' => [ [ 'in the button\'s CSS Classes (Advanced tab)', '[location field="booking_classes"]' ] ],
			'hours_note'      => [ [ 'shown under', '[location_hours]' ], [ 'on its own', '[location field="hours_note"]' ] ],
			'map_embed'       => [ [ '', '[location_map]' ] ],
			'map'             => [ [ '', '[location_map]' ] ],
			'form_embed'      => [ [ '', '[location_form]' ] ],
		];
		foreach ( array_keys( self::DAYS ) as $d ) {
			$map[ "hours_$d" ] = [ [ 'all hours', '[location_hours]' ], [ 'this day', "[location field=\"hours_$d\"]" ] ];
		}
		foreach ( array_keys( self::SOCIAL ) as $k ) {
			$map[ $k ] = [ [ 'Social Icons link', "[location field=\"$k\"]" ], [ 'all links', '[location_social]' ] ];
		}
		return $map;
	}

	/** "Shortcode: [..] (text) · [..] (link)" with click-to-copy codes, or ''. */
	public static function help( $name, $tag = 'p' ) {
		$map = self::help_map();
		if ( empty( $map[ $name ] ) ) {
			return '';
		}
		$parts = [];
		foreach ( $map[ $name ] as list( $label, $code ) ) {
			$parts[] = '<code class="smc-copy" title="Click to copy">' . esc_html( $code ) . '</code>' . ( $label ? ' <span class="smc-sc-label">' . esc_html( $label ) . '</span>' : '' );
		}
		$html = '<span class="smc-sc-title">' . ( count( $parts ) > 1 ? 'Shortcodes:' : 'Shortcode:' ) . '</span> ' . implode( ' &nbsp;&middot;&nbsp; ', $parts );
		return $tag ? "<$tag class=\"description smc-sc\">$html</$tag>" : $html;
	}

	/** Adds the shortcode line to location fields in the category editor. */
	public static function acf_help( $field ) {
		if ( ! is_array( $field ) || empty( $field['name'] ) || ! is_admin() ) {
			return $field;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'smc_review' === ( $screen->post_type ?? '' ) ) {
			// Review fields: the [review] shortcode for Loop Item templates.
			$review = [ 'rating' => [ '[review field="stars"]', '[review field="rating"]' ], 'review_date' => [ '[review field="date"]' ], 'source' => [ '[review field="source"]' ], 'review_link' => [ '[review field="link"]' ] ];
			if ( isset( $review[ $field['name'] ] ) ) {
				$codes                 = array_map( fn( $c ) => '<code class="smc-copy" title="Click to copy">' . esc_html( $c ) . '</code>', $review[ $field['name'] ] );
				$field['instructions'] = trim( $field['instructions'] . ( $field['instructions'] ? '<br>' : '' ) . '<span class="smc-sc-title">In a Loop Item template:</span> ' . implode( ' &nbsp;&middot;&nbsp; ', $codes ) );
			}
			return $field;
		}
		if ( ! $screen || self::TAX !== ( $screen->taxonomy ?? '' ) ) {
			return $field;
		}
		$help = self::help( $field['name'], '' );
		if ( $help && false === strpos( (string) $field['instructions'], '[location' ) ) {
			$field['instructions'] = trim( $field['instructions'] . ( $field['instructions'] ? '<br>' : '' ) . $help );
		}
		return $field;
	}

	/** Click a shortcode to copy it (location screens only). */
	public static function copy_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( self::TAX !== ( $screen->taxonomy ?? '' ) && ! in_array( $screen->post_type ?? '', [ 'smc_review', 'smc_team' ], true ) && false === strpos( (string) $screen->id, 'smc-' ) && false === strpos( (string) $screen->id, 'locations' ) ) ) {
			return;
		}
		?>
		<style>
			code.smc-copy { cursor: pointer; }
			code.smc-copy:hover { background: #dcdcde; }
			code.smc-copy.smc-copied { background: #d1f0d6; }
			.smc-sc { margin-top: 4px; }
			.smc-sc-title { font-weight: 600; }
			.smc-sc-label { color: #646970; }
		</style>
		<script>
		document.addEventListener( 'click', function ( e ) {
			var el = e.target.closest ? e.target.closest( 'code.smc-copy' ) : null;
			if ( ! el ) { return; }
			var text = el.textContent, done = function () {
				el.classList.add( 'smc-copied' ); el.setAttribute( 'title', 'Copied' );
				setTimeout( function () { el.classList.remove( 'smc-copied' ); el.setAttribute( 'title', 'Click to copy' ); }, 1200 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( done );
			} else {
				var t = document.createElement( 'textarea' ); t.value = text; document.body.appendChild( t ); t.select();
				try { document.execCommand( 'copy' ); done(); } catch ( err ) {}
				document.body.removeChild( t );
			}
		} );
		</script>
		<?php
	}

	/** Whether a location term is the main (corporate) location: named or slugged "corporate". */
	public static function is_corporate( $tid ) {
		$t  = $tid ? get_term( (int) $tid, self::TAX ) : null;
		$is = $t && ! is_wp_error( $t ) && ( 'corporate' === strtolower( $t->name ) || 'corporate' === $t->slug );
		return (bool) apply_filters( 'smc_location_is_corporate', $is, $t );
	}

	/**
	 * Location to show team and reviews for, or 0 for every location.
	 * 0 on Corporate pages, pages with no location, and with location="all" or location="corporate".
	 */
	public static function listing_location_id( $location = '' ) {
		$location = strtolower( trim( (string) $location ) );
		if ( 'all' === $location ) {
			return 0;
		}
		$tid = '' !== $location ? (int) ( get_term_by( 'slug', sanitize_title( $location ), self::TAX )->term_id ?? 0 ) : self::current_location_id();
		return $tid && ! self::is_corporate( $tid ) ? $tid : 0;
	}

	/** Names of a post's locations, without Corporate. With $link, each links to the location's main page. */
	public static function location_names( $post_id, $link = false ) {
		$terms = wp_get_post_terms( $post_id, self::TAX );
		$out   = [];
		foreach ( is_wp_error( $terms ) ? [] : $terms as $t ) {
			if ( self::is_corporate( $t->term_id ) ) {
				continue;
			}
			$page  = $link && class_exists( 'SMC_Location_Manager' ) ? SMC_Location_Manager::location_page( $t ) : null;
			$out[] = $page && 'publish' === $page->post_status ? '<a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( $t->name ) . '</a>' : esc_html( $t->name );
		}
		return implode( ', ', $out );
	}

	/** Location term ID for the page being viewed, or 0. */
	public static function current_location_id() {
		return self::term_id( [] );
	}

	private static function term_id( $atts ) {
		if ( ! empty( $atts['location'] ) ) {
			$t = get_term_by( 'slug', sanitize_title( $atts['location'] ), self::TAX );
			return $t ? (int) $t->term_id : 0;
		}
		// The page being viewed, so popups and Theme Builder templates use the page's location.
		$post_id = function_exists( 'is_singular' ) && is_singular() ? get_queried_object_id() : get_the_ID();
		$ids     = wp_get_post_terms( $post_id, self::TAX, [ 'fields' => 'ids' ] );
		if ( ( is_wp_error( $ids ) || ! $ids ) && get_the_ID() && get_the_ID() !== $post_id ) {
			$ids = wp_get_post_terms( get_the_ID(), self::TAX, [ 'fields' => 'ids' ] );
		}
		return ( ! is_wp_error( $ids ) && $ids ) ? (int) $ids[0] : self::only_location_id();
	}

	/**
	 * On a single-location site, its one location (not counting Corporate), so pages without
	 * a location (services, blog...) still show the practice's details. 0 otherwise.
	 */
	public static function only_location_id() {
		static $id = null;
		if ( null === $id ) {
			$id    = 0;
			$terms = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false, 'fields' => 'id=>name' ] );
			if ( ! is_wp_error( $terms ) ) {
				$terms = array_filter( $terms, fn( $name, $tid ) => ! self::is_corporate( $tid ), ARRAY_FILTER_USE_BOTH );
				$id    = 1 === count( $terms ) ? (int) array_key_first( $terms ) : 0;
			}
		}
		return $id;
	}

	/**
	 * Returns the JotForm URL from a pasted form link, form ID, iframe embed code or script
	 * embed code. Works with form.jotform.com, hipaa.jotform.com, eu.jotform.com and other
	 * JotForm subdomains. Anything else returns ''.
	 */
	public static function form_url( $raw ) {
		$raw = trim( html_entity_decode( (string) $raw, ENT_QUOTES ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( ctype_digit( $raw ) && strlen( $raw ) >= 10 ) {
			return 'https://form.jotform.com/' . $raw;
		}
		if ( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $raw, $m ) ) {
			$raw = $m[1];
		}
		$url  = esc_url_raw( $raw );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match( '/(^|\.)jotform\.(com|eu|me)$/', $host ) ) {
			return '';
		}
		// Script embeds use /jsform/<id>; links and iframes use /<id>.
		if ( ! preg_match( '#^/(?:jsform/)?(\d{10,})#', $path, $m ) ) {
			return '';
		}
		return 'https://' . $host . '/' . $m[1];
	}

	/* ========== Shortcodes ========== */

	public static function register_shortcodes() {
		foreach ( [ 'location_hours' => 'hours', 'location_map' => 'map', 'location_social' => 'social', 'location_form' => 'form', 'location_url' => 'url' ] as $tag => $fn ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, [ __CLASS__, $fn ] );
			}
		}
	}

	/**
	 * [location_hours]
	 *
	 * Layout, grouping, day names, closed days and bold come from Locations > Settings.
	 * Any of them can be overridden on one shortcode:
	 *   style="lines|table|list"  group="yes|no"  days="short|full"  show_closed="yes|no"
	 */
	public static function hours( $atts ) {
		$s = class_exists( 'SMC_Location_Settings' ) ? SMC_Location_Settings::hours_options() : [
			'style'        => 'lines',
			'group'        => true,
			'day_names'    => 'short',
			'short_labels' => [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ],
			'range_sep'    => ' - ',
			'show_closed'  => true,
			'bold'         => true,
		];
		$atts = shortcode_atts( [ 'location' => '', 'style' => '', 'group' => '', 'days' => '', 'show_closed' => '' ], $atts, 'location_hours' );
		$yes  = fn( $v, $default ) => '' === $v ? $default : ! in_array( strtolower( $v ), [ 'no', 'false', '0', 'off' ], true );

		$style       = in_array( $atts['style'], [ 'lines', 'table', 'list' ], true ) ? $atts['style'] : $s['style'];
		$group       = $yes( $atts['group'], $s['group'] );
		$names       = in_array( $atts['days'], [ 'short', 'full' ], true ) ? $atts['days'] : $s['day_names'];
		$show_closed = $yes( $atts['show_closed'], $s['show_closed'] );

		$tid = self::term_id( $atts );
		if ( ! $tid ) {
			return '';
		}

		$keys   = array_keys( self::DAYS );
		$labels = 'full' === $names ? array_values( self::DAYS ) : array_values( $s['short_labels'] );

		// Build rows; back-to-back days with the same hours share a row when grouping is on.
		$rows = [];
		$prev = null;
		foreach ( $keys as $i => $d ) {
			$v = trim( (string) get_term_meta( $tid, "hours_$d", true ) );
			if ( '' === $v || ( ! $show_closed && 0 === strcasecmp( $v, 'closed' ) ) ) {
				$prev = null;
				continue;
			}
			$norm = strtolower( preg_replace( '/\s+/', ' ', $v ) );
			if ( $group && null !== $prev && $norm === $prev ) {
				$rows[ count( $rows ) - 1 ]['last'] = $labels[ $i ] ?? self::DAYS[ $d ];
				continue;
			}
			$rows[] = [ 'first' => $labels[ $i ] ?? self::DAYS[ $d ], 'last' => '', 'time' => $v ];
			$prev   = $norm;
		}
		if ( ! $rows ) {
			return '';
		}

		$day_tag = $s['bold'] ? 'strong' : 'span';
		$out     = '<div class="smc-location-hours smc-hours-' . esc_attr( $style ) . '">';

		if ( 'table' === $style ) {
			$out .= '<table><tbody>';
		} elseif ( 'list' === $style ) {
			$out .= '<ul>';
		}

		foreach ( $rows as $r ) {
			$day  = esc_html( $r['first'] . ( $r['last'] ? $s['range_sep'] . $r['last'] : '' ) );
			$time = esc_html( $r['time'] );
			if ( 'table' === $style ) {
				$out .= "<tr><th scope=\"row\"><$day_tag class=\"smc-hours-day\">$day</$day_tag></th><td class=\"smc-hours-time\">$time</td></tr>";
			} elseif ( 'list' === $style ) {
				$out .= "<li><$day_tag class=\"smc-hours-day\">$day:</$day_tag> <span class=\"smc-hours-time\">$time</span></li>";
			} else {
				$out .= "<div class=\"smc-hours-row\"><$day_tag class=\"smc-hours-day\">$day:</$day_tag> <span class=\"smc-hours-time\">$time</span></div>";
			}
		}

		if ( 'table' === $style ) {
			$out .= '</tbody></table>';
		} elseif ( 'list' === $style ) {
			$out .= '</ul>';
		}

		$note = trim( (string) get_term_meta( $tid, 'hours_note', true ) );
		if ( '' !== $note ) {
			$out .= '<div class="smc-location-hours-note">' . esc_html( $note ) . '</div>';
		}
		return $out . '</div>';
	}

	public static function map( $atts ) {
		$atts = shortcode_atts( [ 'location' => '', 'height' => '' ], $atts, 'location_map' );
		$tid  = self::term_id( $atts );
		if ( ! $tid ) {
			return '';
		}
		$src = self::map_src( get_term_meta( $tid, 'map_embed', true ) );
		if ( ! $src ) {
			return '';
		}

		// Height comes from Locations > Settings. height="" on the shortcode overrides it for one map.
		$style = 'border:0;display:block;width:100%;';
		$one   = class_exists( 'SMC_Location_Settings' ) ? SMC_Location_Settings::css_height( $atts['height'] ) : '';
		if ( $one ) {
			$style .= "height:$one;";
		}

		$term = get_term( $tid );
		return self::map_css() . sprintf(
			'<iframe class="smc-location-map" src="%s" style="%s" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="%s"></iframe>',
			esc_url( $src ),
			esc_attr( $style ),
			esc_attr( 'Map of ' . ( $term && ! is_wp_error( $term ) ? $term->name : 'our' ) . ' office' )
		);
	}

	/** Site-wide map height CSS, printed once per page before the first map. */
	private static function map_css() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done   = true;
		$height = class_exists( 'SMC_Location_Settings' ) ? SMC_Location_Settings::get( 'map_height' ) : '450px';
		$mobile = class_exists( 'SMC_Location_Settings' ) ? SMC_Location_Settings::get( 'map_height_mobile' ) : '';
		$css    = 'iframe.smc-location-map{height:' . ( $height ?: '450px' ) . '}';
		if ( $mobile ) {
			$css .= '@media (max-width:767px){iframe.smc-location-map{height:' . $mobile . '}}';
		}
		return '<style id="smc-location-map-css">' . $css . '</style>';
	}

	/**
	 * [location_form]
	 *
	 * Embeds the location's JotForm with JotForm's auto-resize script, so the frame grows
	 * with the form. Uses the "Embedded form" field, or the booking link if that's empty.
	 *   height="700"    starting height before the form resizes (default from Locations > Settings)
	 *   fallback="no"   don't fall back to the booking form
	 */
	public static function form( $atts ) {
		$atts = shortcode_atts( [ 'location' => '', 'height' => '', 'fallback' => 'yes' ], $atts, 'location_form' );
		$tid  = self::term_id( $atts );
		if ( ! $tid ) {
			return '';
		}
		$url = self::form_url( get_term_meta( $tid, 'form_embed', true ) );
		if ( ! $url && 'no' !== strtolower( $atts['fallback'] ) ) {
			$url = self::form_url( get_term_meta( $tid, 'booking_link', true ) );
		}
		if ( ! $url ) {
			return '';
		}

		$id     = basename( wp_parse_url( $url, PHP_URL_PATH ) );
		$base   = 'https://' . wp_parse_url( $url, PHP_URL_HOST ) . '/';
		$height = class_exists( 'SMC_Location_Settings' ) ? SMC_Location_Settings::css_height( $atts['height'] ) : '';
		if ( ! $height ) {
			$height = class_exists( 'SMC_Location_Settings' ) ? SMC_Location_Settings::get( 'form_height' ) : '600px';
		}
		$term = get_term( $tid );
		$name = $term && ! is_wp_error( $term ) ? $term->name : '';

		$out  = sprintf(
			'<iframe id="JotFormIFrame-%1$s" class="smc-location-form" title="%2$s" src="%3$s" allowtransparency="true" allow="geolocation; microphone; camera; fullscreen" style="min-width:100%%;max-width:100%%;height:%4$s;border:none;display:block" scrolling="no"></iframe>',
			esc_attr( $id ),
			esc_attr( trim( "$name contact form" ) ),
			esc_url( $url ),
			esc_attr( $height )
		);
		$out .= self::form_script( $id, $base );
		return $out;
	}

	/** JotForm's embed handler (loaded once) plus the call that wires up one form. */
	private static function form_script( $id, $base ) {
		static $loaded = false;
		$out = '';
		if ( ! $loaded ) {
			$out   .= '<script src="https://cdn.jotfor.ms/s/umd/latest/for-form-embed-handler.js"></script>';
			$loaded = true;
		}
		$out .= sprintf(
			'<script>window.jotformEmbedHandler && window.jotformEmbedHandler(%s, %s);</script>',
			wp_json_encode( "iframe[id='JotFormIFrame-$id']" ),
			wp_json_encode( $base )
		);
		return $out;
	}

	/**
	 * [location_url] - link to the current location's main page, e.g. https://site.com/springfield/.
	 * On pages with no location (or Corporate), the homepage. Good for the Site Logo link.
	 *   path="services/"   a page under the location, e.g. /springfield/services/
	 */
	public static function url( $atts ) {
		$atts = shortcode_atts( [ 'location' => '', 'path' => '' ], $atts, 'location_url' );
		$tid  = self::term_id( $atts );
		$term = $tid ? get_term( $tid, self::TAX ) : null;
		$url  = home_url( '/' );
		if ( $term && ! is_wp_error( $term ) && 'corporate' !== strtolower( $term->name ) && class_exists( 'SMC_Location_Manager' ) ) {
			$page = SMC_Location_Manager::location_page( $term );
			if ( $page ) {
				$url = get_permalink( $page );
			}
		}
		$path = trim( (string) $atts['path'], '/' );
		if ( '' !== $path ) {
			$url = trailingslashit( $url ) . $path . '/';
		}
		return esc_url( $url );
	}

	public static function social( $atts ) {
		$atts = shortcode_atts( [ 'location' => '' ], $atts, 'location_social' );
		$tid  = self::term_id( $atts );
		if ( ! $tid ) {
			return '';
		}
		$out = '';
		foreach ( self::SOCIAL as $name => $label ) {
			$url = trim( (string) get_term_meta( $tid, $name, true ) );
			if ( '' !== $url ) {
				$out .= sprintf(
					'<li><a class="smc-social-%s" href="%s" target="_blank" rel="noopener">%s</a></li>',
					esc_attr( str_replace( '_url', '', $name ) ),
					esc_url( $url ),
					esc_html( $label )
				);
			}
		}
		return $out ? '<ul class="smc-location-social">' . $out . '</ul>' : '';
	}
}
