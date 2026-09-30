<?php
/**
 * Dentist (or other LocalBusiness) schema for each location, built from its details.
 *
 * Every page of a location gets the location's business in its structured data: name,
 * address, phone, email, coordinates, opening hours, Google Business Profile and social
 * links, and its doctors. All pages of a location share one @id (the main page URL +
 * "#localbusiness"), so search engines see one business per office.
 *
 * With Yoast SEO it's added to Yoast's own schema graph, linked to the site's Organization
 * and set as what the page is about. Without Yoast it's printed as its own JSON-LD.
 *
 * Settings: Locations > Settings > Schema. Corporate pages get Corporate's details only if
 * Corporate has an address.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Schema {

	const TAX   = 'location_category';
	const TYPES = [
		'Dentist'         => 'Dentist',
		'MedicalClinic'   => 'Medical clinic',
		'MedicalBusiness' => 'Medical business',
		'LocalBusiness'   => 'Local business (any other kind)',
	];
	const DAYS  = [ 'monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday' ];

	public static function init() {
		add_filter( 'wpseo_schema_graph_pieces', [ __CLASS__, 'yoast_piece' ], 11, 2 );
		add_filter( 'wpseo_schema_webpage', [ __CLASS__, 'yoast_webpage' ], 10, 2 );
		add_action( 'wp_head', [ __CLASS__, 'fallback' ], 20 );
	}

	public static function enabled() {
		return (bool) SMC_Location_Settings::get( 'schema_on' );
	}

	/* ========== Which location ========== */

	/** Location term the page being viewed belongs to, if it should get schema. */
	public static function current_term() {
		if ( ! self::enabled() || ! is_singular() ) {
			return null;
		}
		$ids = wp_get_post_terms( get_queried_object_id(), self::TAX, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $ids ) || ! $ids ) {
			$ids = array_filter( [ SMC_Location_Fields::only_location_id() ] ); // Single-location site: every page.
		}
		if ( ! $ids ) {
			return null;
		}
		$t = get_term( (int) $ids[0], self::TAX );
		if ( ! $t || is_wp_error( $t ) ) {
			return null;
		}
		if ( SMC_Location_Fields::is_corporate( $t->term_id ) && '' === trim( (string) get_term_meta( $t->term_id, 'address', true ) ) ) {
			return null;
		}
		return $t;
	}

	/** The location's canonical URL: its main page, or the homepage for Corporate. */
	public static function url( WP_Term $t ) {
		if ( SMC_Location_Fields::is_corporate( $t->term_id ) ) {
			return home_url( '/' );
		}
		$page = SMC_Location_Manager::location_page( $t );
		return $page && 'publish' === $page->post_status ? get_permalink( $page ) : home_url( '/' );
	}

	public static function id( WP_Term $t ) {
		return self::url( $t ) . '#localbusiness';
	}

	/* ========== The schema ========== */

	/** The business name: the pattern from Settings, e.g. "{brand} - {city}". */
	public static function name( WP_Term $t ) {
		$v    = SMC_Location_Yoast::values( $t->term_id );
		$name = strtr(
			(string) SMC_Location_Settings::get( 'schema_name' ),
			[
				'{brand}'      => self::brand(),
				'{city}'       => $v['location_city'],
				'{location}'   => $t->name,
				'{city_state}' => $v['location_city_state'],
			]
		);
		$name = trim( preg_replace( '/\s+/', ' ', $name ), " -|,\t" );
		return '' !== $name ? $name : self::brand();
	}

	/** Yoast's organization name if set, otherwise the site title. */
	public static function brand() {
		$yoast = class_exists( 'WPSEO_Options' ) ? trim( (string) WPSEO_Options::get( 'company_name', '' ) ) : '';
		return '' !== $yoast ? $yoast : html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * The LocalBusiness node for a location.
	 *
	 * @param WP_Term $t
	 * @param string  $org_id Organization @id to link as parentOrganization, if any.
	 */
	public static function node( WP_Term $t, $org_id = '' ) {
		$tid = (int) $t->term_id;
		$m   = fn( $k ) => trim( (string) get_term_meta( $tid, $k, true ) );
		$v   = SMC_Location_Yoast::values( $tid );
		$url = self::url( $t );

		$node = [
			'@type' => self::type(),
			'@id'   => self::id( $t ),
			'name'  => self::name( $t ),
			'url'   => $url,
		];

		$phone = self::phone( $m( 'phone_label' ) );
		if ( $phone ) {
			$node['telephone'] = $phone;
		}
		if ( is_email( $m( 'email' ) ) ) {
			$node['email'] = $m( 'email' );
		}

		$addr = array_filter(
			[
				'@type'           => 'PostalAddress',
				'streetAddress'   => $v['location_street'],
				'addressLocality' => $v['location_city'],
				'addressRegion'   => $v['location_state'],
				'postalCode'      => $v['location_zip'],
				'addressCountry'  => 'US',
			]
		);
		if ( ! empty( $addr['streetAddress'] ) ) {
			$node['address'] = $addr;
		}

		$ll = class_exists( 'SMC_Location_List' ) ? SMC_Location_List::coords( $tid ) : null;
		if ( $ll ) {
			$node['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => round( $ll[0], 6 ), 'longitude' => round( $ll[1], 6 ) ];
		}

		$hours   = self::hours( $tid );
		$special = class_exists( 'SMC_Location_Holidays' ) ? SMC_Location_Holidays::schema_specs( $tid ) : [];
		if ( $hours['specs'] || $special ) {
			// Regular weekly hours, then holiday closures and hours (validFrom / validThrough).
			$node['openingHoursSpecification'] = array_merge( $hours['specs'], $special );
		}

		$gbp = $m( 'google_business_url' );
		if ( $gbp ) {
			$node['hasMap'] = esc_url_raw( $gbp );
		}
		$same = [];
		foreach ( array_keys( SMC_Location_Fields::SOCIAL ) as $k ) {
			$u = $m( $k );
			if ( $u && 'none' !== strtolower( $u ) && wp_http_validate_url( $u ) ) {
				$same[] = esc_url_raw( $u );
			}
		}
		if ( $same ) {
			$node['sameAs'] = array_values( array_unique( $same ) );
		}

		$logo = (int) get_theme_mod( 'custom_logo' );
		$img  = $logo ? wp_get_attachment_image_url( $logo, 'full' ) : '';
		$page = SMC_Location_Fields::is_corporate( $tid ) ? null : SMC_Location_Manager::location_page( $t );
		$feat = $page ? get_the_post_thumbnail_url( $page, 'full' ) : '';
		if ( $feat || $img ) {
			$node['image'] = $feat ?: $img;
		}
		if ( $img ) {
			$node['logo'] = $img;
		}

		$price = trim( (string) SMC_Location_Settings::get( 'schema_price' ) );
		if ( '' !== $price ) {
			$node['priceRange'] = $price;
		}
		if ( '' !== $v['location_city'] ) {
			$node['areaServed'] = [ '@type' => 'City', 'name' => $v['location_city'] . ( $v['location_state'] ? ', ' . $v['location_state'] : '' ) ];
		}
		if ( $org_id ) {
			$node['parentOrganization'] = [ '@id' => $org_id ];
		}

		$people = self::doctors( $t );
		if ( $people ) {
			$node['employee'] = $people;
		}
		return apply_filters( 'smc_location_schema', $node, $t );
	}

	public static function type() {
		$type = (string) SMC_Location_Settings::get( 'schema_type' );
		return isset( self::TYPES[ $type ] ) ? $type : 'Dentist';
	}

	/** "(555) 555-0100" -> "+1-555-555-0100"; other formats as typed. */
	public static function phone( $label ) {
		$d = preg_replace( '/\D/', '', (string) $label );
		if ( 11 === strlen( $d ) && '1' === $d[0] ) {
			$d = substr( $d, 1 );
		}
		if ( 10 === strlen( $d ) ) {
			return '+1-' . substr( $d, 0, 3 ) . '-' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 );
		}
		return trim( (string) $label );
	}

	/** Published doctors at this location as Person nodes. */
	private static function doctors( WP_Term $t ) {
		if ( ! class_exists( 'SMC_Location_Team' ) || SMC_Location_Fields::is_corporate( $t->term_id ) ) {
			return [];
		}
		$out = [];
		$q   = get_posts(
			[
				'post_type'   => SMC_Location_Team::TYPE,
				'post_status' => 'publish',
				'numberposts' => 30,
				'orderby'     => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
				'tax_query'   => [ [ 'taxonomy' => self::TAX, 'terms' => (int) $t->term_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_query'  => [ [ 'key' => 'team_type', 'value' => 'doctor' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		foreach ( $q as $p ) {
			$person = [ '@type' => 'Person', 'name' => wp_strip_all_tags( SMC_Location_Team::name_with_credentials( $p->ID ) ) ];
			$job    = trim( (string) get_post_meta( $p->ID, 'job_title', true ) );
			if ( $job ) {
				$person['jobTitle'] = $job;
			}
			$url = SMC_Location_Team::profile_url( $p->ID );
			if ( $url ) {
				$person['url'] = $url;
			}
			$photo = get_the_post_thumbnail_url( $p, 'medium_large' );
			if ( $photo ) {
				$person['image'] = $photo;
			}
			$out[] = $person;
		}
		return $out;
	}

	/* ========== Hours ========== */

	/**
	 * Turns the hours fields into openingHoursSpecification, one entry per set of days with
	 * the same hours. Returns [ specs, unreadable => day names that couldn't be read ].
	 * Understands "9:00 AM - 5:00 PM", "8am-5pm", "7:30 - 4 PM", "09:00-17:00",
	 * "8 AM - 12 PM, 1 PM - 5 PM", and "Closed". Anything else ("By appointment") is left out.
	 */
	public static function hours( $tid ) {
		$by  = [];
		$bad = [];
		foreach ( self::DAYS as $d => $label ) {
			$raw = self::clean( (string) get_term_meta( $tid, "hours_$d", true ) );
			if ( '' === $raw || preg_match( '/^(closed|none)$/i', $raw ) ) {
				continue;
			}
			$ranges = self::parse_ranges( $raw );
			if ( ! $ranges ) {
				$bad[] = $label;
				continue;
			}
			foreach ( $ranges as $r ) {
				$by[ $r[0] . '|' . $r[1] ][] = $label;
			}
		}
		$specs = [];
		foreach ( $by as $k => $days ) {
			[ $opens, $closes ] = explode( '|', $k );
			$specs[] = [ '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days, 'opens' => $opens, 'closes' => $closes ];
		}
		return [ 'specs' => $specs, 'unreadable' => $bad ];
	}

	/**
	 * Hours text without what doesn't change the times: footnote marks ("9 AM - 2 PM*", used
	 * with the hours note), notes in brackets ("(admin only)"), non-breaking and other odd
	 * spaces, and <br> or other tags.
	 */
	public static function clean( $raw ) {
		$raw = html_entity_decode( wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', ', ', (string) $raw ) ), ENT_QUOTES, 'UTF-8' );
		$raw = preg_replace( '/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', ' ', $raw );
		$raw = preg_replace( '/\([^)]*\)|\[[^\]]*\]/', ' ', $raw );
		$raw = str_replace( [ '*', '†', '‡' ], '', $raw );
		return trim( preg_replace( '/\s+/', ' ', $raw ) );
	}

	/** "8 AM - 12 PM, 1 PM - 5 PM" -> [ [ "08:00", "12:00" ], [ "13:00", "17:00" ] ], or [] if unreadable. */
	public static function parse_ranges( $raw ) {
		$raw = self::clean( $raw );
		$raw = str_replace( [ '–', '—', '&ndash;', '&mdash;', ' to ' ], '-', strtolower( html_entity_decode( $raw ) ) );
		$raw = str_replace( [ 'a.m.', 'p.m.', 'noon' ], [ 'am', 'pm', '12pm' ], $raw );
		$out = [];
		foreach ( preg_split( '/\s*[,;&\/]\s*|\s+and\s+/', $raw ) as $part ) {
			if ( ! preg_match( '/^\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*-\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*$/', $part, $m ) ) {
				return [];
			}
			$h1 = (int) $m[1];
			$m1 = (int) ( $m[2] ?? 0 );
			$a1 = $m[3] ?? '';
			$h2 = (int) $m[4];
			$m2 = (int) ( $m[5] ?? 0 );
			$a2 = $m[6] ?? '';
			if ( '' === $a1 && '' !== $a2 ) {
				// "7:30 - 4 PM": the start takes the end's AM/PM unless that would put it after the end.
				$a1 = $a2;
				if ( self::to24( $h1, $a1 ) * 60 + $m1 > self::to24( $h2, $a2 ) * 60 + $m2 ) {
					$a1 = 'pm' === $a2 ? 'am' : 'pm';
				}
			}
			$s = self::to24( $h1, $a1 ) * 60 + $m1;
			$e = self::to24( $h2, $a2 ) * 60 + $m2;
			if ( '' === $a1 && '' === $a2 && $e <= $s && $h2 < 12 ) {
				$e += 12 * 60; // "8-5" with no AM/PM.
			}
			if ( $s >= 24 * 60 || $e > 24 * 60 || $e <= $s || $m1 > 59 || $m2 > 59 ) {
				return [];
			}
			$out[] = [ sprintf( '%02d:%02d', intdiv( $s, 60 ), $s % 60 ), sprintf( '%02d:%02d', intdiv( $e, 60 ), $e % 60 ) ];
		}
		return $out;
	}

	private static function to24( $h, $ampm ) {
		if ( 'am' === $ampm ) {
			return 12 === $h ? 0 : $h;
		}
		if ( 'pm' === $ampm ) {
			return 12 === $h ? 12 : $h + 12;
		}
		return $h;
	}

	/* ========== Output ========== */

	/** Adds the location's business to Yoast's schema graph. */
	public static function yoast_piece( $pieces, $context ) {
		if ( ! self::current_term() || ! class_exists( '\Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece' ) ) {
			return $pieces;
		}
		require_once __DIR__ . '/class-smc-location-schema-piece.php';
		$pieces[] = new SMC_Location_Schema_Piece( $context );
		return $pieces;
	}

	/** Marks the page as being about the location's business. */
	public static function yoast_webpage( $data, $context = null ) {
		$t = self::current_term();
		if ( $t && ! isset( $data['about'] ) ) {
			$data['about'] = [ '@id' => self::id( $t ) ];
		}
		return $data;
	}

	/** Without Yoast (or with its schema turned off), prints the business as its own JSON-LD. */
	public static function fallback() {
		if ( defined( 'WPSEO_VERSION' ) && apply_filters( 'wpseo_json_ld_output', true ) ) {
			return;
		}
		$t = self::current_term();
		if ( ! $t ) {
			return;
		}
		$node = [ '@context' => 'https://schema.org' ] + self::node( $t );
		echo "\n<script type=\"application/ld+json\" class=\"smc-location-schema\">" . wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
