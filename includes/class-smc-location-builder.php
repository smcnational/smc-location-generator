<?php
/**
 * New Build: turns a template site's demo location into the client's practice, in place.
 *
 * A template site ships with one demo location ("Springfield", 555-555-0100, 123 Main St...)
 * and a demo practice name. A build:
 *  1. swaps every demo value (practice name, domain, city, phone, address, zip, email and
 *     any extra pairs) for the client's across all pages, posts, Elementor templates,
 *     headers, footers, menus and their Yoast fields, page slugs included;
 *  2. fills the location's details (address, phone, hours, socials, map, forms...) and
 *     renames it, so every [location] shortcode shows the client's details;
 *  3. sets the site title and Yoast organization name;
 *  4. optionally replaces the demo doctors with the client's (demo ones are drafted).
 *
 * Preview first (nothing changes), then run. Every change is backed up and one Undo puts the
 * site back as it was. Backups sit on each item (meta _smc_build_backup) so the log stays small.
 * Config keys match the clone config (city, phone, street, city_state_zip, hours, social...),
 * plus practice, practice_from, domain, domain_from, doctors and slug.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Builder {

	const LOG    = 'smc_location_build_log';
	const BACKUP = '_smc_build_backup';
	const TAX    = 'location_category';

	/** Post types a template's content lives in. */
	const TYPES = [ 'page', 'post', 'elementor_library', 'nav_menu_item', 'wp_block', 'e-landing-page', 'e-floating-buttons' ];

	const SKIP_META = [ '_edit_lock', '_edit_last', '_elementor_css', '_elementor_element_cache', '_elementor_conditions', '_wp_old_slug', '_wp_old_date', '_wp_attached_file', '_wp_attachment_metadata', '_thumbnail_id', self::BACKUP ];

	private $cfg;
	private $dry;
	private $term;
	private $map     = [];
	private $pattern = '';
	private $protect = '';
	private $from    = [];
	private $to      = [];

	public $report = [ 'summary' => [], 'items' => [], 'terms' => [], 'fields' => [], 'team' => [], 'warnings' => [], 'leftovers' => [], 'changed' => 0 ];

	public function __construct( array $cfg, $dry = true ) {
		$this->cfg = $cfg;
		$this->dry = (bool) $dry;
	}

	public static function last() {
		$l = get_option( self::LOG, [] );
		return is_array( $l ) && ! empty( $l['time'] ) ? $l : null;
	}

	/** The demo location: the one set, or the site's only (non-Corporate) location. */
	public static function demo_term( $which = '' ) {
		$locs = SMC_Location_Manager::locations();
		if ( '' !== (string) $which ) {
			foreach ( $locs as $t ) {
				if ( (string) $t->term_id === (string) $which || $t->slug === $which ) {
					return $t;
				}
			}
			return null;
		}
		return 1 === count( $locs ) ? $locs[0] : null;
	}

	/** The template's domain, guessed from the demo location's email (not a staging address). */
	public static function guess_domain( WP_Term $t ) {
		$email = (string) get_term_meta( $t->term_id, 'email', true );
		$at    = strrchr( $email, '@' );
		$dom   = false === $at ? '' : strtolower( substr( $at, 1 ) );
		return $dom && false === strpos( $dom, 'smcnational' ) ? $dom : '';
	}

	/* ========== Run ========== */

	public function run() {
		if ( ! $this->dry && self::last() ) {
			throw new Exception( 'A build was already run on this site. Undo it first (Locations > New Build).' );
		}
		$this->prepare();
		$posts = $this->posts();
		$log   = [ 'time' => time(), 'user' => get_current_user_id(), 'practice' => $this->to['practice'], 'city' => $this->to['city'], 'posts' => [], 'terms' => [], 'options' => [], 'created' => [] ];

		if ( ! $this->dry ) {
			// Moving a page's URL during a build isn't a move visitors need redirecting from.
			remove_action( 'post_updated', [ 'SMC_Location_Redirects', 'path_changed' ], 10 );
		}

		foreach ( $posts as $p ) {
			$n       = 0;
			$fields  = [];
			$backup  = [ 'post' => [], 'meta' => [] ];
			foreach ( [ 'post_title', 'post_content', 'post_excerpt' ] as $f ) {
				$new = $this->swap( $p->$f, $n );
				if ( $new !== $p->$f ) {
					$fields[ $f ]           = $new;
					$backup['post'][ $f ] = $p->$f;
				}
			}
			if ( in_array( $p->post_type, [ 'page', 'post' ], true ) ) {
				$slug = $this->swap( $p->post_name, $n );
				if ( $slug !== $p->post_name ) {
					$fields['post_name']         = sanitize_title( $slug );
					$backup['post']['post_name'] = $p->post_name;
				}
			}
			$meta = [];
			foreach ( get_post_meta( $p->ID ) as $key => $vals ) {
				if ( in_array( $key, self::SKIP_META, true ) || 1 !== count( $vals ) ) {
					continue;
				}
				$raw = $vals[0];
				if ( '_elementor_data' === $key ) {
					$data = json_decode( $raw, true );
					if ( ! is_array( $data ) ) {
						continue;
					}
					$c   = 0;
					$new = $this->swap( $data, $c );
					if ( $c ) {
						$n                    += $c;
						$meta[ $key ]          = wp_slash( wp_json_encode( $new ) );
						$backup['meta'][ $key ] = $raw;
					}
					continue;
				}
				$val = maybe_unserialize( $raw );
				$c   = 0;
				$new = $this->swap( $val, $c );
				if ( $c ) {
					$n                     += $c;
					$meta[ $key ]           = wp_slash( $new );
					$backup['meta'][ $key ] = $raw;
				}
			}
			if ( ! $n ) {
				continue;
			}
			$this->report['items'][] = [ 'id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'new_title' => $fields['post_title'] ?? $p->post_title, 'changes' => $n, 'slug' => isset( $fields['post_name'] ) ? $p->post_name . ' > ' . $fields['post_name'] : '' ];
			$this->report['changed']++;
			if ( $this->dry ) {
				continue;
			}
			update_post_meta( $p->ID, self::BACKUP, wp_slash( $backup ) );
			if ( $fields ) {
				global $wpdb;
				$wpdb->update( $wpdb->posts, $fields, [ 'ID' => $p->ID ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			foreach ( $meta as $key => $v ) {
				update_post_meta( $p->ID, $key, $v );
			}
			clean_post_cache( $p->ID );
			$log['posts'][] = $p->ID;
		}

		$this->update_terms( $log );
		$this->update_options( $log );
		$this->update_team( $log );

		if ( ! $this->dry ) {
			update_option( self::LOG, $log, false );
			foreach ( $log['posts'] as $id ) {
				if ( class_exists( 'SMC_Location_Yoast' ) ) {
					SMC_Location_Yoast::rebuild_indexable( $id );
				}
			}
			foreach ( SMC_Location_Cloner::refresh_caches() as $w ) {
				$this->report['warnings'][] = $w;
			}
			$this->report['leftovers'] = self::leftovers( $this->leftover_values() );
		}
		return $this->report;
	}

	/* ========== Setup ========== */

	private function prepare() {
		$c          = $this->cfg;
		$this->term = self::demo_term( (string) ( $c['location'] ?? '' ) );
		if ( ! $this->term ) {
			throw new Exception( 'Pick the template\'s demo location.' );
		}
		foreach ( [ 'practice' => 'practice name', 'city' => 'city', 'phone' => 'phone', 'street' => 'street address', 'city_state_zip' => 'city, state and zip' ] as $k => $label ) {
			if ( '' === trim( (string) ( $c[ $k ] ?? '' ) ) ) {
				throw new Exception( "Missing the $label." );
			}
		}
		$tid  = $this->term->term_id;
		$addr = preg_split( '#\s*<br\s*/?>\s*#i', (string) get_term_meta( $tid, 'address', true ) );
		$cs   = (string) get_term_meta( $tid, 'city_state', true );

		$f = [
			'practice' => trim( (string) ( $c['practice_from'] ?? '' ) ) ?: html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'domain'   => strtolower( trim( (string) ( $c['domain_from'] ?? '' ) ) ) ?: self::guess_domain( $this->term ),
			'city'     => $this->term->name,
			'phone'    => (string) get_term_meta( $tid, 'phone_label', true ),
			'street'   => trim( $addr[0] ?? '' ),
			'csz'      => trim( $addr[1] ?? '' ),
			'cs'       => $cs,
			'email'    => (string) get_term_meta( $tid, 'email', true ),
		];
		$f['zip']   = preg_match( '/\b(\d{5})(?:-\d{4})?\s*$/', $f['csz'], $m ) ? $m[1] : '';
		$f['state'] = preg_match( '/,\s*([A-Z]{2})\b/', $cs ?: $f['csz'], $m ) ? $m[1] : '';

		$state = strtoupper( trim( (string) ( $c['state'] ?? '' ) ) ) ?: $f['state'];
		$t     = [
			'practice' => trim( sanitize_text_field( $c['practice'] ) ),
			'domain'   => strtolower( preg_replace( '#^(https?://)?(www\.)?#i', '', trim( (string) ( $c['domain'] ?? '' ), " /\t" ) ) ),
			'city'     => trim( sanitize_text_field( $c['city'] ) ),
			'phone'    => trim( sanitize_text_field( $c['phone'] ) ),
			'street'   => trim( sanitize_text_field( $c['street'] ) ),
			'csz'      => trim( sanitize_text_field( $c['city_state_zip'] ) ),
			'state'    => $state,
			'email'    => sanitize_email( (string) ( $c['email'] ?? '' ) ),
		];
		$t['cs']  = $t['city'] . ( $state ? ", $state" : '' );
		$t['zip'] = preg_match( '/\b(\d{5})(?:-\d{4})?\s*$/', $t['csz'], $m ) ? $m[1] : '';
		if ( 10 !== strlen( preg_replace( '/\D/', '', $t['phone'] ) ) ) {
			throw new Exception( 'The phone must be a 10-digit number.' );
		}
		if ( '' !== (string) ( $c['email'] ?? '' ) && ! is_email( $t['email'] ) ) {
			throw new Exception( 'The email address isn\'t valid.' );
		}
		$this->from = $f;
		$this->to   = $t;

		// Longest first, so "Springfield, ST 12345" wins over "Springfield".
		$add = function ( $a, $b ) {
			$a = (string) $a;
			if ( strlen( $a ) >= 2 && '' !== (string) $b && $a !== $b ) {
				$this->map[ $a ] = (string) $b;
			}
		};
		$add( $f['practice'], $t['practice'] );
		$add( strtoupper( $f['practice'] ), strtoupper( $t['practice'] ) );
		if ( $f['domain'] && $t['domain'] ) {
			$add( $f['domain'], $t['domain'] );
			$add( ucfirst( $f['domain'] ), ucfirst( $t['domain'] ) );
		}
		if ( $f['email'] && $t['email'] ) {
			$add( $f['email'], $t['email'] );
		}
		$add( $f['csz'], $t['csz'] );
		$add( $f['street'], $t['street'] );
		$add( $f['cs'], $t['cs'] );
		foreach ( [ 'ucwords', 'strtolower', 'strtoupper' ] as $fn ) {
			$add( $fn( $f['city'] ), $fn( $t['city'] ) );
		}
		$add( sanitize_title( $f['city'] ), sanitize_title( $t['city'] ) );
		$pf = preg_replace( '/\D/', '', $f['phone'] );
		$pt = preg_replace( '/\D/', '', $t['phone'] );
		if ( 10 === strlen( $pf ) ) {
			foreach ( SMC_Location_Cloner::phone_variants( $pf ) as $i => $v ) {
				$add( $v, SMC_Location_Cloner::phone_variants( $pt )[ $i ] );
			}
		} else {
			$this->report['warnings'][] = "The demo location's phone \"{$f['phone']}\" isn't a 10-digit number, so phone numbers typed into pages weren't swapped. Add them to Extra replacements.";
		}
		if ( $f['zip'] && $t['zip'] ) {
			$add( $f['zip'], $t['zip'] );
		}
		foreach ( (array) ( $c['replace'] ?? [] ) as $a => $b ) {
			$add( $a, $b );
		}
		uksort( $this->map, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
		$this->pattern = '/' . implode(
			'|',
			array_map(
				fn( $k ) => ( ctype_digit( substr( $k, 0, 1 ) ) ? '(?<!\d)' : ( preg_match( '/^\w/', $k ) ? '(?<![\w])' : '' ) ) . preg_quote( $k, '/' ) . ( ctype_digit( substr( $k, -1 ) ) ? '(?!\d)' : ( preg_match( '/\w$/', $k ) ? '(?![\w])' : '' ) ),
				array_keys( $this->map )
			)
		) . '/u';
		// Never inside media file paths.
		$this->protect = '#wp-content/uploads/[^\s"\'<>)]+#u';

		if ( ! $f['domain'] && $t['domain'] ) {
			$this->report['warnings'][] = 'The template\'s domain wasn\'t found, so the domain wasn\'t swapped. Fill in "Template domain" if its pages mention one.';
		}
		$this->report['summary'] = [
			[ 'Practice name', $f['practice'], $t['practice'] ],
			[ 'Domain', $f['domain'], $t['domain'] ],
			[ 'City', $f['city'], $t['city'] ],
			[ 'City and state', $f['cs'], $t['cs'] ],
			[ 'Street', $f['street'], $t['street'] ],
			[ 'City, state, zip', $f['csz'], $t['csz'] ],
			[ 'Phone', $f['phone'], $t['phone'] ],
			[ 'Email', $f['email'], $t['email'] ?: '(unchanged)' ],
		];
	}

	private function posts() {
		return get_posts(
			[
				'post_type'        => array_values( array_filter( self::TYPES, 'post_type_exists' ) ),
				'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'numberposts'      => -1,
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
			]
		);
	}

	private function swap( $v, &$count ) {
		if ( is_string( $v ) ) {
			if ( '' === $v || ! $this->map ) {
				return $v;
			}
			$masks = [];
			$v     = preg_replace_callback(
				$this->protect,
				function ( $m ) use ( &$masks ) {
					$k           = "\x00" . count( $masks ) . "\x00";
					$masks[ $k ] = $m[0];
					return $k;
				},
				$v
			);
			$map    = $this->map;
			$out    = preg_replace_callback( $this->pattern, fn( $m ) => $map[ $m[0] ], $v, -1, $c );
			$count += (int) $c;
			return $masks ? strtr( $out, $masks ) : $out;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = $this->swap( $x, $count );
			}
		}
		return $v;
	}

	/* ========== Location, options, team ========== */

	/** Fields for the location, from the intake. Blank keeps the demo value, "none" clears it. */
	private function fields() {
		$c   = $this->cfg;
		$val = fn( $v ) => 0 === strcasecmp( trim( (string) $v ), 'none' ) ? '' : trim( (string) $v );
		$o   = [
			'phone_label' => $this->to['phone'],
			'phone_link'  => 'tel:' . preg_replace( '/\D/', '', $this->to['phone'] ),
			'address'     => $this->to['street'] . '<br>' . $this->to['csz'],
			'city_state'  => $this->to['cs'],
		];
		if ( $this->to['email'] ) {
			$o['email'] = $this->to['email'];
		}
		foreach ( [ 'booking_link' => 'esc_url_raw', 'email_label' => 'sanitize_text_field' ] as $k => $fn ) {
			if ( '' !== trim( (string) ( $c[ $k ] ?? '' ) ) ) {
				$o[ $k ] = $fn( $val( $c[ $k ] ) );
			}
		}
		foreach ( (array) ( $c['hours'] ?? [] ) as $day => $v ) {
			$day = strtolower( trim( (string) $day ) );
			if ( '' === trim( (string) $v ) || ( 'note' !== $day && ! isset( SMC_Location_Fields::DAYS[ $day ] ) ) ) {
				continue;
			}
			$o[ 'note' === $day ? 'hours_note' : "hours_$day" ] = sanitize_text_field( $val( $v ) );
		}
		foreach ( (array) ( $c['social'] ?? [] ) as $k => $v ) {
			$k = preg_replace( '/_url$/', '', strtolower( trim( (string) $k ) ) ) . '_url';
			$k = in_array( $k, [ 'google_url', 'gbp_url', 'google_business_profile_url' ], true ) ? 'google_business_url' : $k;
			if ( '' !== trim( (string) $v ) && isset( SMC_Location_Fields::SOCIAL[ $k ] ) ) {
				$o[ $k ] = '' === $val( $v ) ? '' : esc_url_raw( $val( $v ) );
			}
		}
		if ( '' !== trim( (string) ( $c['form'] ?? '' ) ) ) {
			$url = SMC_Location_Fields::form_url( $c['form'] );
			if ( ! $url ) {
				throw new Exception( 'The embedded form must be a JotForm link, form ID or embed code.' );
			}
			$o['form_embed'] = $url;
		}
		if ( '' !== trim( (string) ( $c['map'] ?? '' ) ) ) {
			$src = SMC_Location_Fields::map_src( $c['map'] );
			if ( ! $src ) {
				throw new Exception( 'The map must be Google Maps embed code (Share > Embed a map > Copy HTML).' );
			}
			$o['map_embed'] = $src;
		} else {
			$this->report['warnings'][] = 'No map set. The demo location\'s map stays until the office\'s map is added on its edit screen.';
		}
		if ( empty( $o['booking_link'] ) ) {
			$this->report['warnings'][] = 'No booking link set. The demo booking form stays until it\'s changed on the location\'s edit screen.';
		}
		return $o;
	}

	private function update_terms( array &$log ) {
		$tid    = $this->term->term_id;
		$fields = $this->fields();
		$slug   = sanitize_title( (string) ( $this->cfg['slug'] ?? '' ) ) ?: sanitize_title( $this->to['city'] );
		foreach ( $fields as $k => $v ) {
			$old = (string) get_term_meta( $tid, $k, true );
			if ( $old !== (string) $v ) {
				$this->report['fields'][] = [ $k, $old, $v ];
			}
		}
		$this->report['terms'][] = [ 'Location', $this->term->name . ' (' . $this->term->slug . ')', $this->to['city'] . " ($slug)" ];

		// Other terms named after the demo city ("Springfield Services").
		$others = [];
		foreach ( [ 'page_type' ] as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			foreach ( get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] ) as $t ) {
				$n    = 0;
				$name = $this->swap( $t->name, $n );
				$tsl  = $this->swap( $t->slug, $n );
				if ( $n ) {
					$others[]                = [ $t, $name, sanitize_title( $tsl ) ];
					$this->report['terms'][] = [ $tax, $t->name, $name ];
				}
			}
		}
		if ( $this->dry ) {
			return;
		}
		$meta = [];
		foreach ( $fields as $k => $v ) {
			$meta[ $k ] = get_term_meta( $tid, $k, true );
			update_term_meta( $tid, $k, $v );
		}
		$log['terms'][] = [ 'id' => $tid, 'tax' => self::TAX, 'name' => $this->term->name, 'slug' => $this->term->slug, 'meta' => $meta ];
		wp_update_term( $tid, self::TAX, [ 'name' => $this->to['city'], 'slug' => $slug ] );
		foreach ( $others as list( $t, $name, $tsl ) ) {
			$log['terms'][] = [ 'id' => $t->term_id, 'tax' => $t->taxonomy, 'name' => $t->name, 'slug' => $t->slug, 'meta' => [] ];
			wp_update_term( $t->term_id, $t->taxonomy, [ 'name' => $name, 'slug' => $tsl ] );
		}
	}

	private function update_options( array &$log ) {
		$yoast = get_option( 'wpseo_titles' );
		$this->report['fields'][] = [ 'Site title', get_bloginfo( 'name' ), $this->to['practice'] ];
		if ( $this->dry ) {
			return;
		}
		$log['options']['blogname']        = get_option( 'blogname' );
		$log['options']['blogdescription'] = get_option( 'blogdescription' );
		update_option( 'blogname', $this->to['practice'] );
		$n = 0;
		update_option( 'blogdescription', $this->swap( (string) get_option( 'blogdescription' ), $n ) );
		if ( is_array( $yoast ) ) {
			$log['options']['wpseo_titles'] = $yoast;
			$yoast['company_name']          = $this->to['practice'];
			$n                              = 0;
			update_option( 'wpseo_titles', $this->swap( $yoast, $n ) ); // Title templates and the like that mention the demo practice.
		}
	}

	/** Doctors from the intake ("Dr. Jane Lee, DDS", one per line). The demo location's team is drafted. */
	private function update_team( array &$log ) {
		$lines = array_filter( array_map( 'trim', is_array( $this->cfg['doctors'] ?? null ) ? $this->cfg['doctors'] : preg_split( '/\r?\n/', (string) ( $this->cfg['doctors'] ?? '' ) ) ) );
		if ( ! $lines || ! class_exists( 'SMC_Location_Team' ) ) {
			return;
		}
		$demo = get_posts( [ 'post_type' => SMC_Location_Team::TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'tax_query' => [ [ 'taxonomy' => self::TAX, 'terms' => (int) $this->term->term_id ] ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $demo as $p ) {
			$this->report['team'][] = [ 'Draft demo team member', $p->post_title ];
		}
		foreach ( $lines as $i => $line ) {
			$parts                  = array_map( 'trim', explode( ',', $line, 2 ) );
			$this->report['team'][] = [ 'Add doctor', $parts[0] . ( ! empty( $parts[1] ) ? ", {$parts[1]}" : '' ) ];
		}
		if ( $this->dry ) {
			return;
		}
		foreach ( $demo as $p ) {
			wp_update_post( [ 'ID' => $p->ID, 'post_status' => 'draft' ] );
			$log['drafted'][] = $p->ID;
		}
		foreach ( $lines as $i => $line ) {
			$parts = array_map( 'trim', explode( ',', $line, 2 ) );
			$id    = SMC_Location_Team::create( [ 'name' => $parts[0], 'credentials' => $parts[1] ?? '', 'type' => 'doctor', 'order' => $i ], [ $this->term->term_id ] );
			if ( is_int( $id ) && $id > 0 ) {
				$log['created'][] = $id;
			}
		}
	}

	/* ========== After the build ========== */

	private function leftover_values() {
		$f    = $this->from;
		$vals = [ $f['practice'], $f['city'], $f['street'], $f['domain'] ];
		$pf   = preg_replace( '/\D/', '', $f['phone'] );
		if ( 10 === strlen( $pf ) ) {
			$vals[] = substr( $pf, 3, 3 ) . '-' . substr( $pf, 6 );
		}
		return array_values( array_filter( array_unique( $vals ), fn( $v ) => strlen( (string) $v ) >= 4 ) );
	}

	/** Items that still contain any of the demo values, with a snippet. */
	public static function leftovers( array $values ) {
		if ( ! $values ) {
			return [];
		}
		$re  = '/(?<![\w])(' . implode( '|', array_map( fn( $v ) => preg_quote( $v, '/' ), $values ) ) . ')(?![\w])/iu';
		$out = [];
		foreach ( get_posts( [ 'post_type' => array_values( array_filter( self::TYPES, 'post_type_exists' ) ), 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'numberposts' => -1 ] ) as $p ) {
			$text = $p->post_title . ' ' . $p->post_content . ' ' . (string) get_post_meta( $p->ID, '_elementor_data', true );
			foreach ( [ 'title', 'metadesc' ] as $y ) {
				$text .= ' ' . (string) get_post_meta( $p->ID, "_yoast_wpseo_$y", true );
			}
			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( stripslashes( str_replace( [ '\n', '\r', '\t' ], ' ', $text ) ) ) ) );
			$text = preg_replace( '#\S*wp-content/uploads/\S*#', ' ', $text );
			if ( preg_match( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$out[] = [ 'id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'value' => $m[1][0], 'snippet' => mb_strcut( $text, max( 0, $m[0][1] - 40 ), 110 ) ];
			}
		}
		return $out;
	}

	/* ========== Undo ========== */

	/** Puts back everything the last build changed. Returns the number of items restored. */
	public static function undo() {
		$log = self::last();
		if ( ! $log ) {
			throw new Exception( 'There\'s no build to undo.' );
		}
		global $wpdb;
		remove_action( 'post_updated', [ 'SMC_Location_Redirects', 'path_changed' ], 10 );
		$n = 0;
		foreach ( (array) $log['posts'] as $id ) {
			$b = get_post_meta( $id, self::BACKUP, true );
			if ( ! is_array( $b ) ) {
				continue;
			}
			if ( ! empty( $b['post'] ) ) {
				$wpdb->update( $wpdb->posts, $b['post'], [ 'ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			foreach ( (array) $b['meta'] as $key => $raw ) {
				// $raw is the value exactly as it was stored.
				$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $raw ], [ 'post_id' => $id, 'meta_key' => $key ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			delete_post_meta( $id, self::BACKUP );
			wp_cache_delete( $id, 'post_meta' );
			clean_post_cache( $id );
			if ( class_exists( 'SMC_Location_Yoast' ) ) {
				SMC_Location_Yoast::rebuild_indexable( $id );
			}
			$n++;
		}
		foreach ( (array) $log['terms'] as $t ) {
			wp_update_term( $t['id'], $t['tax'], [ 'name' => $t['name'], 'slug' => $t['slug'] ] );
			foreach ( (array) $t['meta'] as $k => $v ) {
				update_term_meta( $t['id'], $k, $v );
			}
		}
		foreach ( (array) $log['options'] as $k => $v ) {
			update_option( $k, $v );
		}
		foreach ( (array) ( $log['created'] ?? [] ) as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( (array) ( $log['drafted'] ?? [] ) as $id ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		}
		delete_option( self::LOG );
		SMC_Location_Cloner::refresh_caches();
		return $n;
	}
}
