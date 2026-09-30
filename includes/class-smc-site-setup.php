<?php
/**
 * Site Setup: turns a starter template into a single-location client site, in place.
 *
 *   - Intake: practice name, site title and the location's details in one go.
 *   - Starter swap: the starter's city, phone, address, email, names and booking link are
 *     replaced everywhere (pages, posts, Theme Builder templates, popups, menus, Yoast
 *     fields), using the same longest-first, digit-safe swap as Add Location.
 *   - Services: services the practice doesn't offer are set to draft and taken out of menus.
 *   - Doctors: created under Locations > Team, tied to the location.
 *
 * Nothing is cloned. Every change is backed up (post meta _smc_setup_backup and the
 * smc_site_setup_log option), so the whole setup can be undone. Running setup again keeps
 * the first backup, so Undo always returns to the starter. Finalize drops the backups.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Site_Setup {

	const TAX         = 'location_category';
	const LOG         = 'smc_site_setup_log';
	const INTAKE      = 'smc_site_setup_intake';
	const DONE        = 'smc_site_setup_done';
	const BACKUP_META = '_smc_setup_backup';

	/** Post types whose content is swapped. */
	const TYPES = [ 'page', 'post', 'elementor_library', 'nav_menu_item', 'e-landing-page' ];

	/** Post meta swapped as text (besides _elementor_data). */
	const META = [
		'_elementor_page_settings',
		'_menu_item_url',
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		'_yoast_wpseo_opengraph-title',
		'_yoast_wpseo_opengraph-description',
		'_yoast_wpseo_twitter-title',
		'_yoast_wpseo_twitter-description',
	];

	/** Intake social keys => location fields. */
	const SOCIAL = [
		'facebook'  => 'facebook_url',
		'instagram' => 'instagram_url',
		'youtube'   => 'youtube_url',
		'tiktok'    => 'tiktok_url',
		'google'    => 'google_business_url',
	];

	/** Parent pages that hold the services, checked in this order. */
	const SERVICE_PARENTS = [ 'services', 'our-services', 'dental-services' ];

	/** Manual items on the build checklist. */
	const MANUAL = [
		'forms'     => 'Contact and booking forms tested (submissions reach the practice)',
		'review'    => 'Desktop and mobile review of every page',
		'tracking'  => 'Analytics / Tag Manager installed',
		'legal'     => 'Privacy policy and accessibility pages reviewed',
		'domain'    => 'Domain, SSL and redirects from the old site ready',
	];

	private $intake;
	private $dry;
	private $term;
	private $from;
	private $map        = [];
	private $pattern    = '';
	private $protect_re = '';
	private $emails     = [];
	private $log;

	public $report = [
		'replacements' => [], // [ item, from, to ]
		'term'         => [], // [ field, from, to ]
		'options'      => [], // [ option, from, to ]
		'posts'        => [], // [ id, type, title, count, fields ]
		'drafted'      => [], // [ id, title, path ]
		'menu_items'   => [], // [ menu, title ]
		'team'         => [], // names
		'tagged'       => 0,
		'warnings'     => [],
		'leftovers'    => [],
	];

	/* ========== Lookups ========== */

	/** Location terms other than Corporate. */
	public static function locations() {
		if ( ! taxonomy_exists( self::TAX ) ) {
			return [];
		}
		$terms = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false ] );
		return array_values( array_filter( is_wp_error( $terms ) ? [] : $terms, fn( $t ) => ! SMC_Location_Fields::is_corporate( $t->term_id ) ) );
	}

	/** The site's one location, or null (none, or more than one). */
	public static function location() {
		$locs = self::locations();
		return 1 === count( $locs ) ? $locs[0] : null;
	}

	public static function get_log() {
		$log = get_option( self::LOG, [] );
		return is_array( $log ) ? $log : [];
	}

	/** Yoast's organization name, or ''. */
	private static function yoast_company() {
		$t = get_option( 'wpseo_titles', [] );
		return is_array( $t ) ? trim( (string) ( $t['company_name'] ?? '' ) ) : '';
	}

	/**
	 * The starter's current values: from the location's fields when it has them, otherwise
	 * guessed from the content (phone). Used as the "from" side of the swap.
	 */
	public static function detect() {
		$d = [
			'city'          => '',
			'city_state'    => '',
			'street'        => '',
			'csz'           => '',
			'zip'           => '',
			'phone'         => '',
			'email'         => '',
			'booking_link'  => '',
			'site_title'    => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'practice_name' => self::yoast_company(),
		];
		$term = self::location();
		if ( $term ) {
			$m                 = fn( $k ) => trim( (string) get_term_meta( $term->term_id, $k, true ) );
			$d['city']         = $term->name;
			$d['city_state']   = $m( 'city_state' );
			$addr              = preg_split( '#\s*<br\s*/?>\s*#i', $m( 'address' ) );
			$d['street']       = trim( $addr[0] ?? '' );
			$d['csz']          = trim( $addr[1] ?? '' );
			$d['phone']        = $m( 'phone_label' );
			$d['email']        = $m( 'email' );
			$d['booking_link'] = $m( 'booking_link' );
		}
		if ( preg_match( '/\b(\d{5})(?:-\d{4})?\s*$/', $d['csz'], $mm ) ) {
			$d['zip'] = $mm[1];
		}
		if ( '' === $d['phone'] ) {
			$d['phone'] = self::guess_phone();
		}
		return $d;
	}

	/** The phone number typed most often in Elementor content, for starters without location fields. */
	private static function guess_phone() {
		global $wpdb;
		$rows  = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE '%-%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = [];
		foreach ( $rows as $r ) {
			if ( preg_match_all( '/(?<!\d)\(?(\d{3})\)?[\s.\-]?(\d{3})[\s.\-](\d{4})(?!\d)/', stripslashes( $r ), $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $x ) {
					$k           = $x[1] . $x[2] . $x[3];
					$count[ $k ] = ( $count[ $k ] ?? 0 ) + 1;
				}
			}
		}
		if ( ! $count ) {
			return '';
		}
		arsort( $count );
		$d = (string) key( $count );
		return substr( $d, 0, 3 ) . '-' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 );
	}

	/** The services parent page and its descendants: [ root, [ [ post, depth, path ] ] ]. */
	public static function services() {
		foreach ( (array) apply_filters( 'smc_site_setup_service_parents', self::SERVICE_PARENTS ) as $slug ) {
			$root = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $root ) {
				continue;
			}
			$kids = get_pages(
				[
					'child_of'    => $root->ID,
					'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
					'sort_column' => 'menu_order,post_title',
				]
			) ?: [];
			$out = [];
			foreach ( $kids as $k ) {
				$out[] = [ $k, count( get_post_ancestors( $k ) ) - count( get_post_ancestors( $root ) ) - 1, get_page_uri( $k ) ];
			}
			return [ $root, $out ];
		}
		return [ null, [] ];
	}

	/* ========== Intake ========== */

	/** An empty intake, in the same shape as the JSON file. */
	public static function blank() {
		return [
			'site_title'    => '',
			'practice_name' => '',
			'tagline'       => '',
			'location'      => [
				'city'          => '',
				'state'         => '',
				'street'        => '',
				'zip'           => '',
				'phone'         => '',
				'email'         => '',
				'booking_label' => '',
				'booking_link'  => '',
				'form'          => '',
				'map'           => '',
				'hours'         => array_fill_keys( array_merge( array_keys( SMC_Location_Fields::DAYS ), [ 'note' ] ), '' ),
				'social'        => array_fill_keys( array_keys( self::SOCIAL ), '' ),
			],
			'doctors'       => [],
			'services'      => [ 'remove' => [] ],
			'replace'       => (object) [],
			'protect'       => [],
			'from'          => (object) [],
			'options'       => [ 'rename_urls' => true, 'tag_pages' => true ],
		];
	}

	/** Cleans an intake from JSON or the form into the canonical shape. */
	public static function normalize( array $in ) {
		$b   = self::blank();
		$s   = fn( $v ) => trim( sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ) );
		$loc = (array) ( $in['location'] ?? [] );

		$out = [
			'site_title'    => $s( $in['site_title'] ?? '' ),
			'practice_name' => $s( $in['practice_name'] ?? '' ),
			'tagline'       => $s( $in['tagline'] ?? '' ),
			'location'      => [],
			'doctors'       => [],
			'services'      => [ 'remove' => [] ],
			'replace'       => [],
			'protect'       => [],
			'from'          => [],
			'options'       => [],
		];
		foreach ( $b['location'] as $k => $def ) {
			if ( is_array( $def ) ) {
				foreach ( $def as $kk => $x ) {
					$out['location'][ $k ][ $kk ] = $s( $loc[ $k ][ $kk ] ?? '' );
				}
				continue;
			}
			// Map and form keep their embed code (validated later); everything else is plain text.
			$out['location'][ $k ] = in_array( $k, [ 'map', 'form' ], true ) ? trim( (string) ( $loc[ $k ] ?? '' ) ) : $s( $loc[ $k ] ?? '' );
		}
		$out['location']['state'] = strtoupper( $out['location']['state'] );

		foreach ( (array) ( $in['doctors'] ?? [] ) as $d ) {
			if ( is_string( $d ) ) {
				$p = array_map( 'trim', explode( '|', $d ) );
				$d = [ 'name' => $p[0] ?? '', 'credentials' => $p[1] ?? '', 'title' => $p[2] ?? '' ];
			}
			$d = (array) $d;
			if ( '' !== $s( $d['name'] ?? '' ) ) {
				$out['doctors'][] = [ 'name' => $s( $d['name'] ), 'credentials' => $s( $d['credentials'] ?? '' ), 'title' => $s( $d['title'] ?? '' ) ];
			}
		}
		foreach ( (array) ( $in['services']['remove'] ?? [] ) as $p ) {
			$p = trim( (string) $p, "/ \t" );
			if ( '' !== $p ) {
				$out['services']['remove'][] = sanitize_text_field( $p );
			}
		}
		foreach ( (array) ( $in['replace'] ?? [] ) as $k => $v ) {
			$k = trim( (string) $k );
			if ( '' !== $k && is_scalar( $v ) ) {
				$out['replace'][ $k ] = trim( (string) $v );
			}
		}
		foreach ( (array) ( $in['protect'] ?? [] ) as $p ) {
			if ( '' !== trim( (string) $p ) ) {
				$out['protect'][] = trim( (string) $p );
			}
		}
		foreach ( array_keys( self::detect() ) as $k ) {
			if ( isset( $in['from'][ $k ] ) ) {
				$out['from'][ $k ] = $s( $in['from'][ $k ] );
			}
		}
		$opt            = (array) ( $in['options'] ?? [] );
		$out['options'] = [
			'rename_urls' => ! array_key_exists( 'rename_urls', $opt ) || ! empty( $opt['rename_urls'] ),
			'tag_pages'   => ! array_key_exists( 'tag_pages', $opt ) || ! empty( $opt['tag_pages'] ),
		];
		return $out;
	}

	/** Parses "old => new" lines. */
	public static function parse_pairs( $text ) {
		$out = [];
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			if ( false !== strpos( $line, '=>' ) ) {
				list( $a, $b ) = array_map( 'trim', explode( '=>', $line, 2 ) );
				if ( '' !== $a ) {
					$out[ $a ] = $b;
				}
			}
		}
		return $out;
	}

	/* ========== Run ========== */

	public function __construct( array $intake, $dry ) {
		$this->intake = self::normalize( $intake );
		$this->dry    = (bool) $dry;
	}

	public function run() {
		$this->prepare();
		$this->swap_posts();
		$this->update_location();
		$this->update_options();
		$this->prune_services();
		$this->create_doctors();
		$this->tag_pages();
		if ( ! $this->dry ) {
			$this->save_log();
			update_option( self::INTAKE, $this->intake, false );
			foreach ( SMC_Location_Cloner::refresh_caches() as $w ) {
				$this->report['warnings'][] = $w;
			}
			$this->report['leftovers'] = self::leftovers();
		}
		return $this->report;
	}

	private function prepare() {
		if ( ! taxonomy_exists( self::TAX ) ) {
			throw new Exception( 'The location system isn\'t available on this site.' );
		}
		$locs = self::locations();
		if ( count( $locs ) > 1 ) {
			throw new Exception( 'This site has ' . count( $locs ) . ' locations. Site Setup is for single-location builds; use Add Location for multi-location sites.' );
		}
		$this->term = $locs[0] ?? null;

		$in  = $this->intake;
		$loc = $in['location'];
		if ( '' === $loc['city'] ) {
			throw new Exception( 'The city is required.' );
		}
		$digits = preg_replace( '/\D/', '', $loc['phone'] );
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		if ( '' !== $loc['phone'] && 10 !== strlen( $digits ) ) {
			throw new Exception( 'The phone number must have 10 digits.' );
		}
		if ( '' !== $loc['email'] && ! is_email( $loc['email'] ) ) {
			throw new Exception( 'That email address doesn\'t look right.' );
		}
		if ( '' !== $loc['map'] && ! SMC_Location_Fields::map_src( $loc['map'] ) ) {
			throw new Exception( 'The map must be Google Maps embed code (Share > Embed a map > Copy HTML) or its embed URL.' );
		}
		if ( '' !== $loc['form'] && ! SMC_Location_Fields::form_url( $loc['form'] ) ) {
			throw new Exception( 'The form must be a JotForm link, form ID, or embed code.' );
		}

		// First run: remember the starter's values, so later runs and the leftover check use them.
		$this->log  = self::get_log();
		$this->from = array_merge( self::detect(), array_filter( $in['from'], 'strlen' ) );
		$first_from = $this->log['from'] ?? $this->from;

		$f  = $this->from;
		$to = $this->to_values();

		$pair = function ( $label, $a, $b ) {
			$a = (string) $a;
			$b = (string) $b;
			if ( '' === $a || '' === $b || $a === $b ) {
				return;
			}
			$this->map[ $a ]                   = $b;
			$this->report['replacements'][] = [ $label, $a, $b ];
		};

		// Longer, more specific values first in the report; the regex sorts by length anyway.
		// The starter's name in body copy becomes the practice name (the site title can carry SEO words).
		$name = $in['practice_name'] ?: $in['site_title'];
		$pair( 'Practice name', $f['practice_name'], $name );
		if ( strcasecmp( $f['site_title'], $f['practice_name'] ) ) {
			$pair( 'Practice name (site title)', $f['site_title'], $name );
		}
		$pair( 'Booking link', $f['booking_link'], $loc['booking_link'] );
		$pair( 'City/state/zip', $f['csz'], $to['csz'] );
		$pair( 'Street', $f['street'], $loc['street'] );
		$pair( 'City/state', $f['city_state'], $to['city_state'] );
		$pair( 'Zip', $f['zip'], $loc['zip'] );
		if ( '' !== $f['city'] && $f['city'] !== $loc['city'] ) {
			foreach ( [ 'ucwords', 'strtolower', 'strtoupper' ] as $fn ) {
				$this->map[ $fn( $f['city'] ) ] = $fn( $loc['city'] );
			}
			if ( $in['options']['rename_urls'] ) {
				$this->map[ sanitize_title( $f['city'] ) ] = sanitize_title( $loc['city'] );
			}
			$this->report['replacements'][] = [ 'City', $f['city'], $loc['city'] ];
		}

		$pf = preg_replace( '/\D/', '', $f['phone'] );
		if ( 10 === strlen( $pf ) && 10 === strlen( $digits ) && $pf !== $digits ) {
			$vf = SMC_Location_Cloner::phone_variants( $pf );
			$vt = SMC_Location_Cloner::phone_variants( $digits );
			foreach ( $vf as $i => $v ) {
				$this->map[ $v ] = $vt[ $i ];
			}
			// URL-encoded tel: links, e.g. tel:(555)%20555-0100
			$this->map[ '(' . substr( $pf, 0, 3 ) . ')%20' . substr( $pf, 3, 3 ) . '-' . substr( $pf, 6 ) ] = '(' . substr( $digits, 0, 3 ) . ')%20' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
			$this->report['replacements'][] = [ 'Phone', $f['phone'], $loc['phone'] ];
		} elseif ( '' !== $loc['phone'] && 10 !== strlen( $pf ) ) {
			$this->report['warnings'][] = 'No starter phone number was found, so phone numbers were not swapped. Enter the starter\'s phone under Starter values.';
		}

		foreach ( $in['replace'] as $a => $b ) {
			$pair( 'Extra', $a, $b );
		}

		if ( '' !== $f['email'] && '' !== $loc['email'] && strtolower( $f['email'] ) !== strtolower( $loc['email'] ) ) {
			$this->emails[ $f['email'] ]    = $loc['email'];
			$this->report['replacements'][] = [ 'Email', $f['email'], $loc['email'] ];
		}

		foreach ( $in['protect'] as $p ) {
			foreach ( array_keys( $this->map ) as $k ) {
				if ( 0 === strcasecmp( $k, $p ) ) {
					unset( $this->map[ $k ] );
				}
			}
		}

		if ( $this->map ) {
			uksort( $this->map, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
			$this->pattern = '/' . implode(
				'|',
				array_map(
					fn( $k ) => ( ctype_digit( substr( $k, 0, 1 ) ) ? '(?<!\d)' : '' ) . preg_quote( $k, '/' ) . ( ctype_digit( substr( $k, -1 ) ) ? '(?!\d)' : '' ),
					array_keys( $this->map )
				)
			) . '/u';
		}
		// Never swap inside media paths or other email addresses. Protected strings are left whole.
		$protect          = array_merge( [ 'wp-content/uploads/[^\s"\'<>)]+', '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' ], array_map( fn( $p ) => preg_quote( $p, '#' ), $in['protect'] ) );
		$this->protect_re = '#' . implode( '|', $protect ) . '#u';

		if ( ! $this->map && ! $this->emails ) {
			$this->report['warnings'][] = 'Nothing to swap: the starter values and the new values are the same, or the starter values are empty.';
		}

		if ( ! $this->dry ) {
			if ( empty( $this->log ) ) {
				$this->log = [
					'created' => time(),
					'from'    => $first_from,
					'posts'   => [],
					'term'    => null,
					'options' => [],
					'drafted' => [],
					'menus'   => [],
					'parents' => [],
					'team'    => [],
					'tagged'  => [],
				];
			}
			$this->log['updated'] = time();
			$this->log['user']    = get_current_user_id();
			$this->log['from']    = $first_from;
			$this->save_log();
		}
	}

	/** New values derived from the intake. */
	private function to_values() {
		$l  = $this->intake['location'];
		$cs = $l['city'] . ( '' !== $l['state'] ? ', ' . $l['state'] : '' );
		return [
			'city_state' => $cs,
			'csz'        => trim( $cs . ( '' !== $l['zip'] ? ' ' . $l['zip'] : '' ) ),
		];
	}

	/* ========== Swap ========== */

	private function swap( $v, &$count ) {
		if ( is_string( $v ) ) {
			if ( $this->emails ) {
				foreach ( $this->emails as $a => $b ) {
					$v = str_ireplace( $a, $b, $v, $c );
					$count += $c;
				}
			}
			if ( '' === $this->pattern ) {
				return $v;
			}
			$masks = [];
			$v     = preg_replace_callback(
				$this->protect_re,
				function ( $m ) use ( &$masks ) {
					$k           = "\x00" . count( $masks ) . "\x00";
					$masks[ $k ] = $m[0];
					return $k;
				},
				$v
			);
			$map    = $this->map;
			$out    = preg_replace_callback( $this->pattern, fn( $m ) => $map[ $m[0] ], $v, -1, $c );
			$count += $c;
			return $masks ? strtr( $out, $masks ) : $out;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = $this->swap( $x, $count );
			}
		}
		return $v;
	}

	private static function post_ids() {
		return get_posts(
			[
				'post_type'        => array_values( array_filter( self::TYPES, 'post_type_exists' ) ),
				'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			]
		);
	}

	private function swap_posts() {
		if ( '' === $this->pattern && ! $this->emails ) {
			return;
		}
		$rename = $this->intake['options']['rename_urls'];
		$front  = (int) get_option( 'page_on_front' );

		self::redirect_hooks( false );
		foreach ( self::post_ids() as $id ) {
			$post   = get_post( $id );
			$n      = 0;
			$fields = [];
			$meta   = [];

			foreach ( [ 'post_title', 'post_content', 'post_excerpt' ] as $f ) {
				$new = $this->swap( $post->$f, $n );
				if ( $new !== $post->$f ) {
					$fields[ $f ] = $new;
				}
			}
			if ( $rename && in_array( $post->post_type, [ 'page', 'post', 'e-landing-page' ], true ) && $id !== $front ) {
				$new = $this->swap( $post->post_name, $n );
				if ( $new !== $post->post_name ) {
					$fields['post_name'] = sanitize_title( $new );
				}
			}

			$raw = get_post_meta( $id, '_elementor_data', true );
			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				$data = json_decode( $raw, true );
				if ( is_array( $data ) ) {
					$c   = 0;
					$new = $this->swap( $data, $c );
					if ( $c ) {
						$meta['_elementor_data'] = wp_json_encode( $new );
						$n                      += $c;
					}
				} else {
					$this->report['warnings'][] = "Could not read the Elementor data of \"{$post->post_title}\" ($id); it was left as is.";
				}
			}
			foreach ( self::META as $k ) {
				if ( ! metadata_exists( 'post', $id, $k ) ) {
					continue;
				}
				$val = get_post_meta( $id, $k, true );
				$c   = 0;
				$new = $this->swap( $val, $c );
				if ( $c ) {
					$meta[ $k ] = $new;
					$n         += $c;
				}
			}

			if ( ! $n ) {
				continue;
			}
			$this->report['posts'][] = [
				'id'     => $id,
				'type'   => $post->post_type,
				'title'  => $fields['post_title'] ?? $post->post_title,
				'count'  => $n,
				'fields' => array_merge( array_keys( $fields ), array_keys( $meta ) ),
				'slug'   => isset( $fields['post_name'] ) ? [ $post->post_name, $fields['post_name'] ] : null,
			];
			if ( $this->dry ) {
				continue;
			}

			// Back up the starter version once; later runs keep the first backup.
			if ( ! metadata_exists( 'post', $id, self::BACKUP_META ) ) {
				$backup = [ 'fields' => [], 'meta' => [] ];
				foreach ( [ 'post_title', 'post_content', 'post_excerpt', 'post_name' ] as $f ) {
					$backup['fields'][ $f ] = $post->$f;
				}
				foreach ( array_merge( [ '_elementor_data' ], self::META ) as $k ) {
					$backup['meta'][ $k ] = metadata_exists( 'post', $id, $k ) ? get_post_meta( $id, $k, true ) : null;
				}
				add_post_meta( $id, self::BACKUP_META, wp_slash( $backup ), true );
			}
			if ( $fields ) {
				wp_update_post( wp_slash( array_merge( [ 'ID' => $id ], $fields ) ) );
			}
			foreach ( $meta as $k => $v ) {
				update_post_meta( $id, $k, wp_slash( $v ) );
			}
			delete_post_meta( $id, '_elementor_css' );
			delete_post_meta( $id, '_elementor_element_cache' );
			$this->log['posts'][ $id ] = true;
		}
		self::redirect_hooks( true );
	}

	/** Turns the automatic 301s for changed page URLs off (a starter's old URLs never went live) and back on. */
	private static function redirect_hooks( $on ) {
		if ( ! class_exists( 'SMC_Location_Redirects' ) ) {
			return;
		}
		$fn = $on ? 'add_action' : 'remove_action';
		$fn( 'pre_post_update', [ 'SMC_Location_Redirects', 'remember_path' ], 10, 1 );
		$fn( 'post_updated', [ 'SMC_Location_Redirects', 'path_changed' ], 10, 3 );
	}

	/* ========== Location, options ========== */

	private function update_location() {
		$l   = $this->intake['location'];
		$to  = $this->to_values();
		$tel = '';
		$d   = preg_replace( '/\D/', '', $l['phone'] );
		if ( 11 === strlen( $d ) && '1' === $d[0] ) {
			$d = substr( $d, 1 );
		}
		if ( 10 === strlen( $d ) ) {
			$tel = 'tel:' . substr( $d, 0, 3 ) . '-' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 );
		}

		// Only fields the intake fills in; blank keeps the starter's value.
		$values = [
			'city_state'    => '' !== $l['state'] ? $to['city_state'] : '',
			'address'       => '' !== $l['street'] ? $l['street'] . '<br>' . $to['csz'] : '',
			'phone_label'   => $l['phone'],
			'phone_link'    => $tel,
			'email'         => $l['email'] ? sanitize_email( $l['email'] ) : '',
			'booking_label' => $l['booking_label'],
			'booking_link'  => $l['booking_link'] ? esc_url_raw( $l['booking_link'] ) : '',
			'form_embed'    => $l['form'] ? SMC_Location_Fields::form_url( $l['form'] ) : '',
			'map_embed'     => $l['map'] ? SMC_Location_Fields::map_src( $l['map'] ) : '',
		];
		foreach ( $l['hours'] as $day => $v ) {
			$values[ 'note' === $day ? 'hours_note' : "hours_$day" ] = $v;
		}
		foreach ( self::SOCIAL as $k => $field ) {
			$values[ $field ] = $l['social'][ $k ] ? esc_url_raw( $l['social'][ $k ] ) : '';
		}
		$values = array_filter( $values, 'strlen' );

		$term = $this->term;
		$name = $l['city'];
		$slug = sanitize_title( $name );

		if ( ! $term ) {
			$this->report['term'][] = [ 'Location', '(none)', "$name (created)" ];
			foreach ( $values as $k => $v ) {
				$this->report['term'][] = [ $k, '', $v ];
			}
			if ( $this->dry ) {
				return;
			}
			$res = wp_insert_term( $name, self::TAX, [ 'slug' => $slug ] );
			if ( is_wp_error( $res ) ) {
				throw new Exception( 'Could not create the location: ' . $res->get_error_message() );
			}
			$this->term              = get_term( (int) $res['term_id'], self::TAX );
			$this->log['term']       = [ 'id' => (int) $res['term_id'], 'created' => true, 'name' => '', 'slug' => '', 'meta' => [] ];
			$this->log['posts']      = $this->log['posts'] ?? [];
			$this->save_log();
		} else {
			if ( $name !== $term->name ) {
				$this->report['term'][] = [ 'Name', $term->name, $name ];
			}
			if ( empty( $this->log['term'] ) && ! $this->dry ) {
				$this->log['term'] = [ 'id' => (int) $term->term_id, 'created' => false, 'name' => $term->name, 'slug' => $term->slug, 'meta' => [] ];
			}
			foreach ( $values as $k => $v ) {
				$old = (string) get_term_meta( $term->term_id, $k, true );
				if ( $old !== $v ) {
					$this->report['term'][] = [ $k, $old, $v ];
				}
			}
			if ( $this->dry ) {
				return;
			}
			if ( $name !== $term->name || $slug !== $term->slug ) {
				$other = get_term_by( 'slug', $slug, self::TAX );
				$args  = [ 'name' => $name ];
				if ( ! $other || (int) $other->term_id === (int) $term->term_id ) {
					$args['slug'] = $slug;
				}
				wp_update_term( $term->term_id, self::TAX, $args );
			}
		}

		$tid = (int) $this->term->term_id;
		foreach ( $values as $k => $v ) {
			if ( ! $this->log['term']['created'] && ! array_key_exists( $k, $this->log['term']['meta'] ) ) {
				$this->log['term']['meta'][ $k ] = metadata_exists( 'term', $tid, $k ) ? get_term_meta( $tid, $k, true ) : null;
			}
			update_term_meta( $tid, $k, wp_slash( $v ) );
			$fk = SMC_Location_Fields::field_key( $k );
			if ( $fk && ! get_term_meta( $tid, "_$k", true ) ) {
				if ( ! $this->log['term']['created'] && ! array_key_exists( "_$k", $this->log['term']['meta'] ) ) {
					$this->log['term']['meta'][ "_$k" ] = null;
				}
				update_term_meta( $tid, "_$k", $fk );
			}
		}
	}

	private function update_options() {
		$in  = $this->intake;
		$set = [];
		if ( '' !== $in['site_title'] ) {
			$set['blogname'] = $in['site_title'];
		}
		if ( '' !== $in['tagline'] ) {
			$set['blogdescription'] = $in['tagline'];
		}
		foreach ( $set as $opt => $v ) {
			$old = (string) get_option( $opt );
			if ( $old === $v ) {
				continue;
			}
			$this->report['options'][] = [ 'blogname' === $opt ? 'Site title' : 'Tagline', html_entity_decode( $old, ENT_QUOTES ), $v ];
			if ( ! $this->dry ) {
				if ( ! array_key_exists( $opt, $this->log['options'] ) ) {
					$this->log['options'][ $opt ] = $old;
				}
				update_option( $opt, $v );
			}
		}

		$company = $in['practice_name'] ?: $in['site_title'];
		$titles  = get_option( 'wpseo_titles', null );
		if ( '' !== $company && is_array( $titles ) && ( $titles['company_name'] ?? '' ) !== $company ) {
			$this->report['options'][] = [ 'Yoast organization name', (string) ( $titles['company_name'] ?? '' ), $company ];
			if ( ! $this->dry ) {
				if ( ! array_key_exists( 'wpseo_company_name', $this->log['options'] ) ) {
					$this->log['options']['wpseo_company_name'] = (string) ( $titles['company_name'] ?? '' );
				}
				$titles['company_name'] = $company;
				update_option( 'wpseo_titles', $titles );
			}
		}
	}

	/* ========== Services ========== */

	private function prune_services() {
		$remove = $this->intake['services']['remove'];
		if ( ! $remove ) {
			return;
		}
		list( , $pages ) = self::services();
		$by_path          = [];
		foreach ( $pages as list( $p, , $path ) ) {
			$by_path[ $path ] = $p;
		}
		$ids = [];
		foreach ( $remove as $path ) {
			if ( ! isset( $by_path[ $path ] ) ) {
				$this->report['warnings'][] = "Service page /$path/ wasn't found; skipped.";
				continue;
			}
			$p = $by_path[ $path ];
			// Removing a service removes the pages under it too.
			foreach ( $by_path as $sub => $q ) {
				if ( $sub === $path || 0 === strpos( $sub, "$path/" ) ) {
					$ids[ $q->ID ] = $q;
				}
			}
		}

		foreach ( $ids as $id => $p ) {
			$this->report['drafted'][] = [ $id, $p->post_title, get_page_uri( $p ) ];
		}
		$items = $this->menu_items_for( array_keys( $ids ) );
		foreach ( $items as $it ) {
			$this->report['menu_items'][] = [ $it['menu_name'], $it['post']['post_title'] ?: get_the_title( $it['meta']['_menu_item_object_id'] ?? 0 ) ];
		}
		$this->link_warnings( $ids );

		if ( $this->dry ) {
			return;
		}
		self::redirect_hooks( false );
		foreach ( $ids as $id => $p ) {
			if ( 'draft' === $p->post_status ) {
				continue;
			}
			if ( ! isset( $this->log['drafted'][ $id ] ) ) {
				$this->log['drafted'][ $id ] = $p->post_status;
			}
			wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
		}
		self::redirect_hooks( true );

		$removed = wp_list_pluck( $items, 'id' );
		foreach ( $items as $it ) {
			// Sub-items that stay move up to the removed item's parent.
			foreach ( get_posts( [ 'post_type' => 'nav_menu_item', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => '_menu_item_menu_item_parent', 'meta_value' => (string) $it['id'] ] ) as $child ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				if ( in_array( $child, $removed, true ) ) {
					continue;
				}
				if ( ! isset( $this->log['parents'][ $child ] ) ) {
					$this->log['parents'][ $child ] = $it['id'];
				}
				update_post_meta( $child, '_menu_item_menu_item_parent', (string) ( $it['meta']['_menu_item_menu_item_parent'] ?? '0' ) );
			}
			$this->log['menus'][ $it['id'] ] = $it;
			wp_delete_post( $it['id'], true );
		}
	}

	/** Menu items that link to these pages, with everything needed to put them back. */
	private function menu_items_for( array $page_ids ) {
		if ( ! $page_ids ) {
			return [];
		}
		$out = [];
		$ids = get_posts(
			[
				'post_type'   => 'nav_menu_item',
				'numberposts' => -1,
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[ 'key' => '_menu_item_object_id', 'value' => array_map( 'strval', $page_ids ), 'compare' => 'IN' ],
					[ 'key' => '_menu_item_type', 'value' => 'post_type' ],
				],
			]
		);
		foreach ( $ids as $id ) {
			$p     = get_post( $id );
			$menus = wp_get_object_terms( $id, 'nav_menu' );
			$menu  = ( ! is_wp_error( $menus ) && $menus ) ? $menus[0] : null;
			$meta  = [];
			foreach ( get_post_meta( $id ) as $k => $v ) {
				if ( 0 === strpos( $k, '_menu_item_' ) ) {
					$meta[ $k ] = maybe_unserialize( $v[0] );
				}
			}
			$out[] = [
				'id'        => $id,
				'menu'      => $menu ? (int) $menu->term_id : 0,
				'menu_name' => $menu ? $menu->name : '',
				'post'      => [
					'post_title'   => $p->post_title,
					'post_content' => $p->post_content,
					'post_excerpt' => $p->post_excerpt,
					'menu_order'   => $p->menu_order,
					'post_status'  => $p->post_status,
				],
				'meta'      => $meta,
			];
		}
		return $out;
	}

	/** Warns about buttons and links on kept pages that point to pages being drafted. */
	private function link_warnings( array $pages ) {
		$paths = [];
		foreach ( $pages as $p ) {
			$paths[ '/' . get_page_uri( $p ) . '/' ] = $p->post_title;
		}
		$drafted = array_keys( $pages );
		foreach ( self::post_ids() as $id ) {
			if ( in_array( $id, $drafted, true ) ) {
				continue;
			}
			$raw = (string) get_post_meta( $id, '_elementor_data', true );
			if ( '' === $raw ) {
				continue;
			}
			$raw = str_replace( '\/', '/', $raw );
			foreach ( $paths as $path => $title ) {
				if ( false !== strpos( $raw, $path ) ) {
					$this->report['warnings'][] = sprintf( '"%s" links to %s, which is being set to draft. Remove the link or button after setup.', get_the_title( $id ), $path );
				}
			}
		}
	}

	/* ========== Doctors, page tags ========== */

	private function create_doctors() {
		if ( ! $this->intake['doctors'] || ! post_type_exists( 'smc_team' ) ) {
			return;
		}
		foreach ( $this->intake['doctors'] as $d ) {
			$exists = get_posts( [ 'post_type' => 'smc_team', 'title' => $d['name'], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
			if ( $exists ) {
				$this->report['warnings'][] = "{$d['name']} is already under Locations > Team; not added again.";
				continue;
			}
			$this->report['team'][] = trim( $d['name'] . ( $d['credentials'] ? ', ' . $d['credentials'] : '' ) );
			if ( $this->dry ) {
				continue;
			}
			$id = wp_insert_post( wp_slash( [ 'post_type' => 'smc_team', 'post_status' => 'publish', 'post_title' => $d['name'] ] ), true );
			if ( is_wp_error( $id ) ) {
				$this->report['warnings'][] = "Could not add {$d['name']}: " . $id->get_error_message();
				continue;
			}
			update_post_meta( $id, 'team_type', 'doctor' );
			update_post_meta( $id, 'credentials', $d['credentials'] );
			update_post_meta( $id, 'job_title', $d['title'] );
			wp_set_object_terms( $id, [ (int) $this->term->term_id ], self::TAX );
			$this->log['team'][] = $id;
		}
	}

	/** Gives pages with no location the site's location, so [location] shortcodes, schema and Yoast variables work. */
	private function tag_pages() {
		if ( ! $this->intake['options']['tag_pages'] ) {
			return;
		}
		$types = array_values( array_filter( [ 'page', 'e-landing-page' ], 'post_type_exists' ) );
		$ids   = get_posts(
			[
				'post_type'   => $types,
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'numberposts' => -1,
				'fields'      => 'ids',
				'tax_query'   => [ [ 'taxonomy' => self::TAX, 'operator' => 'NOT EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		$this->report['tagged'] = count( $ids );
		if ( $this->dry || ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			wp_set_object_terms( $id, [ (int) $this->term->term_id ], self::TAX, true );
			$this->log['tagged'][] = $id;
		}
	}

	private function save_log() {
		update_option( self::LOG, $this->log, false );
	}

	/* ========== Undo, finalize ========== */

	/** Puts the site back the way it was before the first setup run. Returns a summary. */
	public static function undo() {
		$log = self::get_log();
		if ( ! $log ) {
			throw new Exception( 'There is no setup to undo.' );
		}
		$n = [ 'posts' => 0, 'pages' => 0, 'menu_items' => 0, 'team' => 0 ];

		self::redirect_hooks( false );
		foreach ( array_keys( (array) $log['posts'] ) as $id ) {
			$b = get_post_meta( $id, self::BACKUP_META, true );
			if ( ! is_array( $b ) || ! get_post( $id ) ) {
				continue;
			}
			wp_update_post( wp_slash( array_merge( [ 'ID' => $id ], $b['fields'] ) ) );
			foreach ( $b['meta'] as $k => $v ) {
				if ( null === $v ) {
					delete_post_meta( $id, $k );
				} else {
					update_post_meta( $id, $k, wp_slash( $v ) );
				}
			}
			delete_post_meta( $id, self::BACKUP_META );
			delete_post_meta( $id, '_elementor_css' );
			delete_post_meta( $id, '_elementor_element_cache' );
			$n['posts']++;
		}
		foreach ( (array) $log['drafted'] as $id => $status ) {
			if ( get_post( $id ) ) {
				wp_update_post( [ 'ID' => $id, 'post_status' => $status ] );
				$n['pages']++;
			}
		}
		self::redirect_hooks( true );

		// Menu items: recreate, then point sub-items back at the new IDs.
		$new_ids = [];
		foreach ( (array) $log['menus'] as $old => $it ) {
			if ( ! $it['menu'] || ! term_exists( (int) $it['menu'], 'nav_menu' ) ) {
				continue;
			}
			$id = wp_insert_post( wp_slash( array_merge( $it['post'], [ 'post_type' => 'nav_menu_item' ] ) ), true );
			if ( is_wp_error( $id ) ) {
				continue;
			}
			foreach ( $it['meta'] as $k => $v ) {
				update_post_meta( $id, $k, wp_slash( $v ) );
			}
			wp_set_object_terms( $id, [ (int) $it['menu'] ], 'nav_menu' );
			$new_ids[ (int) $old ] = $id;
			$n['menu_items']++;
		}
		foreach ( $new_ids as $id ) {
			$parent = (int) get_post_meta( $id, '_menu_item_menu_item_parent', true );
			if ( isset( $new_ids[ $parent ] ) ) {
				update_post_meta( $id, '_menu_item_menu_item_parent', (string) $new_ids[ $parent ] );
			}
		}
		foreach ( (array) $log['parents'] as $child => $old_parent ) {
			if ( get_post( $child ) ) {
				update_post_meta( $child, '_menu_item_menu_item_parent', (string) ( $new_ids[ (int) $old_parent ] ?? $old_parent ) );
			}
		}

		foreach ( (array) $log['team'] as $id ) {
			if ( get_post( $id ) ) {
				wp_delete_post( $id, true );
				$n['team']++;
			}
		}

		$t = $log['term'] ?? null;
		foreach ( (array) $log['tagged'] as $id ) {
			if ( $t ) {
				wp_remove_object_terms( $id, (int) $t['id'], self::TAX );
			}
		}
		if ( $t && get_term( (int) $t['id'], self::TAX ) ) {
			if ( $t['created'] ) {
				wp_delete_term( (int) $t['id'], self::TAX );
			} else {
				wp_update_term( (int) $t['id'], self::TAX, [ 'name' => $t['name'], 'slug' => $t['slug'] ] );
				foreach ( $t['meta'] as $k => $v ) {
					if ( null === $v ) {
						delete_term_meta( (int) $t['id'], $k );
					} else {
						update_term_meta( (int) $t['id'], $k, wp_slash( $v ) );
					}
				}
			}
		}

		foreach ( (array) $log['options'] as $opt => $v ) {
			if ( 'wpseo_company_name' === $opt ) {
				$titles = get_option( 'wpseo_titles', [] );
				if ( is_array( $titles ) ) {
					$titles['company_name'] = $v;
					update_option( 'wpseo_titles', $titles );
				}
				continue;
			}
			update_option( $opt, $v );
		}

		delete_option( self::LOG );
		SMC_Location_Cloner::refresh_caches();
		return $n;
	}

	/** Keeps the setup and drops the backups. Undo is no longer possible afterwards. */
	public static function finalize() {
		delete_post_meta_by_key( self::BACKUP_META );
		delete_option( self::LOG );
	}

	/* ========== Checks ========== */

	/**
	 * Starter values that are still in the content after setup: [ value => [ titles ] ].
	 * Values equal to a new value (a shared zip, say) aren't reported.
	 */
	public static function leftovers() {
		$log  = self::get_log();
		$from = $log['from'] ?? [];
		if ( ! $from ) {
			return [];
		}
		$now     = self::detect();
		$intake  = get_option( self::INTAKE, [] );
		$protect = array_map( 'strtolower', (array) ( $intake['protect'] ?? [] ) );
		$needles = [];
		foreach ( [ 'city', 'street', 'zip', 'site_title', 'practice_name', 'email' ] as $k ) {
			$v = trim( (string) ( $from[ $k ] ?? '' ) );
			if ( strlen( $v ) >= 4 && ! in_array( strtolower( $v ), array_map( 'strtolower', array_filter( array_map( 'strval', $now ) ) ), true ) && ! in_array( strtolower( $v ), $protect, true ) ) {
				$needles[] = $v;
			}
		}
		$pd = preg_replace( '/\D/', '', (string) ( $from['phone'] ?? '' ) );
		if ( 10 === strlen( $pd ) && preg_replace( '/\D/', '', $now['phone'] ) !== $pd ) {
			$needles[] = substr( $pd, 3, 3 ) . '-' . substr( $pd, 6 );
		}
		if ( ! $needles ) {
			return [];
		}

		$found = [];
		foreach ( self::post_ids() as $id ) {
			$p    = get_post( $id );
			$text = $p->post_title . ' ' . get_post_meta( $id, '_elementor_data', true );
			foreach ( [ '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ] as $k ) {
				$text .= ' ' . get_post_meta( $id, $k, true );
			}
			$text = preg_replace( '#wp-content/uploads/[^\s"\'<>)]+#', '', str_replace( '\/', '/', $text ) );
			foreach ( $needles as $needle ) {
				$hit = ctype_digit( $needle ) ? preg_match( '/(?<!\d)' . $needle . '(?!\d)/', $text ) : false !== stripos( $text, $needle );
				if ( $hit ) {
					$found[ $needle ][ $id ] = $p->post_title ?: "#$id";
				}
			}
		}
		return $found;
	}

	/**
	 * The build checklist: [ items => [ group, key, label, status, detail, manual, done ], fails ].
	 * Status: ok, warn, fail, info.
	 */
	public static function checklist() {
		$items = [];
		$add   = function ( $group, $key, $label, $status, $detail = '' ) use ( &$items ) {
			$items[] = compact( 'group', 'key', 'label', 'status', 'detail' ) + [ 'manual' => false, 'done' => false ];
		};
		$log  = self::get_log();
		$term = self::location();
		$locs = self::locations();

		// Setup.
		if ( count( $locs ) > 1 ) {
			$add( 'setup', 'multi', 'Single location', 'info', 'This site has ' . count( $locs ) . ' locations. Use each location\'s launch checklist instead.' );
		}
		$add( 'setup', 'applied', 'Site Setup applied', $log ? 'ok' : 'fail', $log ? 'Last run ' . human_time_diff( (int) ( $log['updated'] ?? $log['created'] ) ) . ' ago. Undo is available until you finalize.' : 'Fill in the setup form below and apply it.' );
		$left = $log ? self::leftovers() : [];
		if ( $log ) {
			if ( $left ) {
				$list = '';
				foreach ( $left as $v => $posts ) {
					$list .= '<li><code>' . esc_html( $v ) . '</code> in ' . esc_html( implode( ', ', array_slice( $posts, 0, 6 ) ) ) . ( count( $posts ) > 6 ? ' and ' . ( count( $posts ) - 6 ) . ' more' : '' ) . '</li>';
				}
				$add( 'setup', 'leftovers', 'Starter values left', 'warn', 'Still in the content. Add them under Extra replacements and run setup again, or fix them by hand.<ul>' . $list . '</ul>' );
			} else {
				$add( 'setup', 'leftovers', 'Starter values left', 'ok', 'None found.' );
			}
		}

		// Location.
		if ( ! $term ) {
			$add( 'location', 'term', 'Location', count( $locs ) ? 'info' : 'fail', count( $locs ) ? '' : 'Created by Site Setup.' );
		} else {
			$m    = fn( $k ) => trim( (string) get_term_meta( $term->term_id, $k, true ) );
			$from = $log['from'] ?? [];
			$edit = esc_url( SMC_Location_Manager::url( [ 'action' => 'edit', 'term' => $term->term_id ] ) );
			foreach ( [ 'address' => 'Address', 'phone_label' => 'Phone', 'city_state' => 'City and state', 'booking_link' => 'Booking link', 'map_embed' => 'Google Map' ] as $k => $label ) {
				$v = $m( $k );
				if ( '' === $v ) {
					$add( 'location', $k, $label, 'fail', "Missing. <a href=\"$edit\">Edit the location</a>" );
				} elseif ( 'phone_label' === $k && ! empty( $from['phone'] ) && preg_replace( '/\D/', '', $v ) === preg_replace( '/\D/', '', $from['phone'] ) ) {
					$add( 'location', $k, $label, 'fail', 'Still the starter\'s number.' );
				} elseif ( 'booking_link' === $k && ! empty( $from['booking_link'] ) && $v === $from['booking_link'] ) {
					$add( 'location', $k, $label, 'fail', 'Still the starter\'s booking form.' );
				} else {
					$add( 'location', $k, $label, 'ok', 'map_embed' === $k ? '' : esc_html( wp_strip_all_tags( str_replace( '<br>', ', ', $v ) ) ) );
				}
			}
			$days = count( array_filter( array_keys( SMC_Location_Fields::DAYS ), fn( $d ) => '' !== $m( "hours_$d" ) ) );
			$add( 'location', 'hours', 'Hours', $days ? 'ok' : 'fail', $days ? "$days days set." : "Missing. <a href=\"$edit\">Edit the location</a>" );
			$add( 'location', 'email', 'Email', '' !== $m( 'email' ) ? 'ok' : 'warn', esc_html( $m( 'email' ) ) );
			$add( 'location', 'gbp', 'Google Business Profile link', '' !== $m( 'google_business_url' ) ? 'ok' : 'warn' );
			$untagged = get_posts(
				[
					'post_type'   => 'page',
					'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
					'numberposts' => -1,
					'fields'      => 'ids',
					'tax_query'   => [ [ 'taxonomy' => self::TAX, 'operator' => 'NOT EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
				]
			);
			$add( 'location', 'tagged', 'Pages tied to the location', $untagged ? 'warn' : 'ok', $untagged ? count( $untagged ) . ' page(s) have no location, so location shortcodes on them are empty. Tick "Tie every page to the location" and run setup again.' : '' );
		}

		// Content.
		list( $root, $services ) = self::services();
		if ( $root ) {
			$live = count( array_filter( $services, fn( $s ) => 'publish' === $s[0]->post_status ) );
			$add( 'content', 'services', 'Services', 'info', sprintf( '%d of %d service pages published.', $live, count( $services ) ) );
		}
		$scan = get_transient( 'smc_site_setup_scan' );
		if ( false === $scan && class_exists( 'SMC_Location_Scanner' ) && $term ) {
			$scan = count( SMC_Location_Scanner::run() );
			set_transient( 'smc_site_setup_scan', $scan, 10 * MINUTE_IN_SECONDS );
		}
		if ( false !== $scan ) {
			$url = esc_url( admin_url( 'admin.php?page=smc-location-scan' ) );
			$add( 'content', 'scan', 'Typed-in details', $scan ? 'warn' : 'ok', $scan ? "$scan page(s) or template(s) have details typed in instead of shortcodes. <a href=\"$url\">Open Scan</a>" : 'Nothing typed in.' );
		}

		// Brand and people.
		$brand = esc_url( admin_url( 'admin.php?page=smc-brand' ) );
		$add( 'brand', 'logo', 'Logo', get_theme_mod( 'custom_logo' ) ? 'ok' : 'fail', get_theme_mod( 'custom_logo' ) ? '' : "Not set. <a href=\"$brand\">Brand</a>" );
		$add( 'brand', 'favicon', 'Favicon', get_option( 'site_icon' ) ? 'ok' : 'warn', get_option( 'site_icon' ) ? '' : "Not set. <a href=\"$brand\">Brand</a>" );
		$brand_log = get_option( 'smc_brand_history', [] );
		$add( 'brand', 'colors', 'Brand colors and fonts', $brand_log ? 'ok' : 'warn', $brand_log ? '' : "Not saved on the Brand screen yet. <a href=\"$brand\">Brand</a>" );
		if ( $term && post_type_exists( 'smc_team' ) ) {
			$docs = get_posts( [ 'post_type' => 'smc_team', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'tax_query' => [ [ 'taxonomy' => self::TAX, 'terms' => $term->term_id ] ], 'meta_key' => 'team_type', 'meta_value' => 'doctor' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$team = esc_url( admin_url( 'edit.php?post_type=smc_team' ) );
			$nophoto = array_filter( $docs, fn( $id ) => ! has_post_thumbnail( $id ) );
			$add( 'brand', 'doctors', 'Doctors', $docs ? ( $nophoto ? 'warn' : 'ok' ) : 'fail', $docs ? count( $docs ) . ' published' . ( $nophoto ? ', ' . count( $nophoto ) . ' without a photo' : '' ) . ". <a href=\"$team\">Team</a>" : "None yet. <a href=\"$team\">Team</a>" );
		}
		if ( post_type_exists( 'smc_review' ) ) {
			$rev = (int) wp_count_posts( 'smc_review' )->publish;
			$add( 'brand', 'reviews', 'Reviews', $rev ? 'ok' : 'warn', $rev ? "$rev published." : 'None yet. <a href="' . esc_url( admin_url( 'admin.php?page=smc-review-import' ) ) . '">Import Reviews</a>' );
		}

		// SEO.
		$add( 'seo', 'yoast', 'Yoast organization name', self::yoast_company() ? 'ok' : 'warn', esc_html( self::yoast_company() ) );
		$add( 'seo', 'visibility', 'Search engine visibility', 'info', get_option( 'blog_public' ) ? 'Visible to search engines.' : 'Hidden from search engines (right for staging; turn it on at launch under Settings &gt; Reading).' );

		// Manual.
		$done = (array) get_option( self::DONE, [] );
		foreach ( self::MANUAL as $k => $label ) {
			$items[] = [ 'group' => 'manual', 'key' => $k, 'label' => $label, 'status' => in_array( $k, $done, true ) ? 'ok' : 'warn', 'detail' => '', 'manual' => true, 'done' => in_array( $k, $done, true ) ];
		}

		return [
			'items' => $items,
			'fails' => count( array_filter( $items, fn( $i ) => 'fail' === $i['status'] ) ),
			'warns' => count( array_filter( $items, fn( $i ) => 'warn' === $i['status'] ) ),
		];
	}
}
