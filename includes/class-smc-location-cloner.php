<?php
/**
 * Clones an SMC location: page tree, location terms, Theme Builder templates and menu.
 *
 * "From" values (city, phone, street, city/state/zip, zip) are read from the source
 * location's term fields, so a config only needs the new office's details.
 * Every object created is recorded in an undo log (option smc_location_clone_log).
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Cloner {

	const LOG_OPTION = 'smc_location_clone_log';
	const LOC_TAX    = 'location_category';

	private $cfg;
	private $dry;

	private $map       = [];
	private $pattern   = '';
	private $protect_re = '';
	private $leftovers = [];
	private $skip_meta = [ '_edit_lock', '_edit_last', '_elementor_css', '_elementor_element_cache', '_elementor_conditions', '_wp_old_slug', '_wp_old_date' ];

	private $city_from;
	private $city_to;
	private $state;
	private $source;
	private $source_uri;
	private $target_slug;
	private $target_path;
	private $src_term;

	private $tree_ids   = [];
	private $clone_ids  = [];
	private $excluded   = [];
	private $term_map   = []; // taxonomy => [ old => new ] (identity in dry runs)
	private $term_label = []; // taxonomy => [ old => new slug ]
	private $id_map     = []; // old page ID => new page ID
	private $tpl_map    = []; // old template ID => new template ID

	public $report = [
		'summary'   => [],
		'terms'     => [],
		'fields'    => [],
		'templates' => [],
		'menus'     => [],
		'pages'     => [],
		'excluded'  => [],
		'links'     => 0,
		'tpl_refs'  => 0,
		'warnings'  => [],
		'notes'     => [],
	];

	/* ========== Config ========== */

	/**
	 * SMC defaults. Override per site with the smc_location_cloner_defaults filter.
	 * Any key set in a config replaces the default (lists are not merged).
	 */
	public static function defaults() {
		return apply_filters(
			'smc_location_cloner_defaults',
			[
				'status'         => 'draft',
				'exclude'        => [ 'lp', 'services/dentist-*' ],
				'taxonomies'     => [ 'location_category', 'page_type' ],
				'replace'        => [],
				'protect'        => [],
				'leftover_check' => [],
				'fields'         => [],
				'yoast'          => [],
			]
		);
	}

	public function __construct( array $cfg, $dry ) {
		$this->cfg = array_merge( self::defaults(), $cfg );
		$this->dry = (bool) $dry;
	}

	/* ========== Public entry point ========== */

	public function run() {
		$this->prepare();
		$this->collect_tree();
		$this->clone_terms();
		$this->clone_page( $this->source, $this->source->post_parent, 0, $this->parent_path() );
		$this->clone_templates();
		$this->relink();
		$this->clone_menus();
		if ( ! $this->dry ) {
			foreach ( self::refresh_caches() as $w ) {
				$this->warn( $w );
			}
		}
		return $this->report;
	}

	public function target_slug() {
		return $this->target_slug;
	}

	/* ========== Setup ========== */

	private function prepare() {
		$c = $this->cfg;

		foreach ( [ 'source', 'city', 'phone', 'street', 'city_state_zip' ] as $k ) {
			if ( empty( $c[ $k ] ) ) {
				throw new Exception( "Config is missing \"$k\"." );
			}
		}
		$flat = wp_json_encode( $c );
		if ( false !== strpos( $flat, 'CHANGE_ME' ) ) {
			throw new Exception( 'Config still has CHANGE_ME placeholders. Fill them in first.' );
		}
		if ( ! taxonomy_exists( self::LOC_TAX ) ) {
			throw new Exception( 'This site does not use the SMC location system (no location_category taxonomy).' );
		}

		$this->source = is_numeric( $c['source'] )
			? get_post( (int) $c['source'] )
			: get_page_by_path( trim( $c['source'], '/' ), OBJECT, 'page' );
		if ( ! $this->source ) {
			throw new Exception( "Source page not found: {$c['source']}" );
		}

		$terms = wp_get_post_terms( $this->source->ID, self::LOC_TAX );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			throw new Exception( "Source page /{$c['source']}/ has no location term." );
		}
		$this->src_term = $terms[0];

		// "From" values straight from the source location's fields.
		$tid         = $this->src_term->term_id;
		$phone_from  = (string) get_term_meta( $tid, 'phone_label', true );
		$cs_from     = (string) get_term_meta( $tid, 'city_state', true );
		$addr        = preg_split( '#\s*<br\s*/?>\s*#i', (string) get_term_meta( $tid, 'address', true ) );
		$street_from = trim( $addr[0] ?? '' );
		$csz_from    = trim( $addr[1] ?? '' );
		$zip_from    = preg_match( '/\b(\d{5})(?:-\d{4})?\s*$/', $csz_from, $m ) ? $m[1] : '';

		$this->city_from = $this->src_term->name;
		$this->city_to   = $c['city'];
		$this->state     = $c['state'] ?? ( preg_match( '/,\s*([A-Z]{2})\b/', $cs_from, $m ) ? $m[1] : '' );
		$cs_to           = $this->city_to . ( $this->state ? ", {$this->state}" : '' );
		$zip_to          = preg_match( '/\b(\d{5})(?:-\d{4})?\s*$/', $c['city_state_zip'], $m ) ? $m[1] : '';

		// Replacement map.
		foreach ( [ 'ucwords', 'strtolower', 'strtoupper' ] as $fn ) {
			$this->map[ $fn( $this->city_from ) ] = $fn( $this->city_to );
		}
		$this->map[ sanitize_title( $this->city_from ) ] = sanitize_title( $this->city_to );

		$pf = preg_replace( '/\D/', '', $phone_from );
		$pt = preg_replace( '/\D/', '', $c['phone'] );
		if ( strlen( $pt ) !== 10 ) {
			throw new Exception( 'New phone must be 10 digits.' );
		}
		if ( strlen( $pf ) === 10 ) {
			$vf = self::phone_variants( $pf );
			$vt = self::phone_variants( $pt );
			foreach ( $vf as $i => $v ) {
				$this->map[ $v ] = $vt[ $i ];
			}
		} else {
			$this->warn( "Source phone_label \"$phone_from\" is not a 10-digit number; phone numbers were not auto-swapped. Add them to \"replace\"." );
		}

		if ( $street_from ) {
			$this->map[ $street_from ] = $c['street'];
		}
		if ( $csz_from ) {
			$this->map[ $csz_from ] = $c['city_state_zip'];
		}
		if ( $cs_from ) {
			$this->map[ $cs_from ] = $cs_to;
		}
		if ( $zip_from && $zip_to ) {
			$this->map[ $zip_from ] = $zip_to;
		}
		foreach ( (array) $c['replace'] as $k => $v ) {
			$this->map[ $k ] = $v;
		}

		uksort( $this->map, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
		// Keys that start or end with a digit get digit boundaries, so zip 12345 never matches inside 1512345678.
		$this->pattern = '/' . implode(
			'|',
			array_map(
				fn( $k ) => ( ctype_digit( substr( $k, 0, 1 ) ) ? '(?<!\d)' : '' ) . preg_quote( $k, '/' ) . ( ctype_digit( substr( $k, -1 ) ) ? '(?!\d)' : '' ),
				array_keys( $this->map )
			)
		) . '/u';

		// Never swap inside media paths or email addresses (oldtown@ must not become a made-up testville@).
		$protect          = array_merge( [ 'wp-content/uploads/[^\s"\'<>)]+', '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' ], array_map( fn( $p ) => preg_quote( $p, '#' ), (array) $c['protect'] ) );
		$this->protect_re = '#' . implode( '|', $protect ) . '#u';

		$this->leftovers = array_values(
			array_filter(
				array_unique(
					array_merge(
						[ $this->city_from, $street_from, $zip_from, strlen( $pf ) === 10 ? substr( $pf, 3, 3 ) . '-' . substr( $pf, 6 ) : '' ],
						(array) $c['leftover_check']
					)
				)
			)
		);

		// Target.
		$this->source_uri  = get_page_uri( $this->source );
		$this->target_slug = $c['slug'] ?? sanitize_title( $this->city_to );
		$this->target_path = ltrim( $this->parent_path() . '/' . $this->target_slug, '/' );

		if ( get_page_by_path( $this->target_path, OBJECT, $this->source->post_type ) ) {
			throw new Exception( "/{$this->target_path}/ already exists." );
		}
		$log = get_option( self::LOG_OPTION, [] );
		if ( isset( $log[ $this->target_slug ] ) ) {
			throw new Exception( "A clone named \"{$this->target_slug}\" is already in the undo log. Undo it first or pick another slug." );
		}

		if ( empty( $c['booking_link'] ) ) {
			$this->warn( "No booking_link set; the new location will use {$this->city_from}'s booking form." );
		}

		$this->report['summary'] = [
			[ 'item' => 'Source', 'from' => "/{$this->source_uri}/ ({$this->city_from})", 'to' => "/{$this->target_path}/ ({$this->city_to})" ],
			[ 'item' => 'Phone', 'from' => $phone_from, 'to' => $c['phone'] ],
			[ 'item' => 'Street', 'from' => $street_from, 'to' => $c['street'] ],
			[ 'item' => 'City/state/zip', 'from' => $csz_from, 'to' => $c['city_state_zip'] ],
			[ 'item' => 'City/state', 'from' => $cs_from, 'to' => $cs_to ],
		];

		if ( ! $this->dry ) {
			$log[ $this->target_slug ] = [
				'created'   => time(),
				'user'      => get_current_user_id(),
				'source'    => $this->source_uri,
				'city'      => $this->city_to,
				'path'      => $this->target_path,
				'pages'     => [],
				'templates' => [],
				'menus'     => [],
				'terms'     => [],
			];
			update_option( self::LOG_OPTION, $log, false );
		}
	}

	private function parent_path() {
		return $this->source->post_parent ? get_page_uri( $this->source->post_parent ) : '';
	}

	private function collect_tree() {
		$descendants    = get_pages(
			[
				'child_of'    => $this->source->ID,
				'post_type'   => $this->source->post_type,
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			]
		) ?: [];
		$this->tree_ids = array_merge( [ (int) $this->source->ID ], array_map( fn( $p ) => (int) $p->ID, $descendants ) );

		$excluded_ids = [];
		foreach ( $descendants as $desc ) {
			$rel = $this->rel_path( $desc );
			$hit = $this->is_excluded( $desc );
			foreach ( $this->excluded as $ex ) {
				if ( 0 === strpos( $rel, "$ex/" ) ) {
					$hit = true;
				}
			}
			if ( $hit ) {
				$this->excluded[] = $rel;
				$excluded_ids[]   = (int) $desc->ID;
			}
		}
		$this->clone_ids          = array_values( array_diff( $this->tree_ids, $excluded_ids ) );
		$this->report['excluded'] = $this->excluded;
	}

	/* ========== Terms ========== */

	private function clone_terms() {
		foreach ( (array) $this->cfg['taxonomies'] as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$used = wp_get_object_terms( $this->clone_ids, $tax );
			if ( is_wp_error( $used ) ) {
				continue;
			}
			foreach ( $used as $t ) {
				$tid = (int) $t->term_id;
				if ( isset( $this->term_map[ $tax ][ $tid ] ) ) {
					continue;
				}
				$is_loc = ( self::LOC_TAX === $tax && $tid === (int) $this->src_term->term_id );
				$named  = false !== stripos( $t->name, $this->city_from ) || false !== strpos( $t->slug, sanitize_title( $this->city_from ) );
				$inside = ! array_diff( array_map( 'intval', (array) get_objects_in_term( $tid, $tax ) ), $this->tree_ids );
				if ( ! $is_loc && ! $named && ! $inside ) {
					continue;
				}
				$this->clone_term( $t, $tax, $is_loc, $is_loc ? 'location term' : ( $named ? 'named after city' : 'only used in tree' ) );
			}
		}
	}

	private function clone_term( WP_Term $t, $tax, $is_loc, $why ) {
		$tid  = (int) $t->term_id;
		$n    = 0;
		$name = $is_loc ? $this->city_to : $this->swap( $t->name, $n );
		if ( $name === $t->name ) {
			$name .= " {$this->city_to}";
		}
		$slug = sanitize_title( $name );
		if ( term_exists( $slug, $tax ) ) {
			throw new Exception( "$tax term \"$slug\" already exists." );
		}

		// Copy term meta; swap values, leave ACF reference keys (_field) alone.
		$tmeta = [];
		foreach ( get_term_meta( $tid ) as $k => $vals ) {
			$v           = maybe_unserialize( $vals[0] );
			$tmeta[ $k ] = ( '_' === $k[0] ) ? $v : $this->swap( $v, $n );
		}
		if ( $is_loc ) {
			foreach ( $this->location_overrides() as $k => $v ) {
				$tmeta[ $k ] = $v;
				// Make sure ACF knows which field a value belongs to, even if the copied term never had one.
				$fk = class_exists( 'SMC_Location_Fields' ) ? SMC_Location_Fields::field_key( $k ) : null;
				if ( $fk && ! isset( $tmeta[ "_$k" ] ) ) {
					$tmeta[ "_$k" ] = $fk;
				}
			}
			foreach ( $tmeta as $k => $v ) {
				if ( '_' === $k[0] ) {
					continue;
				}
				$old                      = get_term_meta( $tid, $k, true );
				$this->report['fields'][] = [ 'field' => $k, 'from' => is_scalar( $old ) ? $old : wp_json_encode( $old ), 'to' => is_scalar( $v ) ? $v : wp_json_encode( $v ) ];
				if ( $old === $v && in_array( $k, [ 'address', 'phone_label', 'phone_link', 'city_state' ], true ) ) {
					$this->warn( "Location field \"$k\" came out unchanged. Set it under \"fields\" in the config." );
				}
			}
		}
		$this->scan( "Term $tax/$slug", $name . ' ' . wp_json_encode( $tmeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$new_tid = $tid;
		if ( ! $this->dry ) {
			$res = wp_insert_term( $name, $tax, [ 'slug' => $slug, 'description' => $this->swap( $t->description, $n ), 'parent' => $t->parent ] );
			if ( is_wp_error( $res ) ) {
				throw new Exception( "Term insert failed ($tax/$slug): " . $res->get_error_message() );
			}
			$new_tid = (int) $res['term_id'];
			$this->log_add( 'terms', $new_tid, $tax );
			foreach ( $tmeta as $k => $v ) {
				update_term_meta( $new_tid, $k, wp_slash( $v ) );
			}
		}

		$this->term_map[ $tax ][ $tid ]   = $new_tid;
		$this->term_label[ $tax ][ $tid ] = $slug;
		$this->report['terms'][]          = [
			'taxonomy' => $tax,
			'from'     => "{$t->name} ($tid)",
			'to'       => $this->dry ? "$name ($slug)" : "$name ($slug) #$new_tid",
			'why'      => $why,
		];
	}

	/**
	 * New values for the location term: "fields", booking_link, hours, social and map from the config.
	 * Hours and social links not given are copied from the source location. The map is never
	 * copied, since a map of the wrong office is worse than none.
	 */
	private function location_overrides() {
		$o = (array) $this->cfg['fields'];
		if ( ! empty( $this->cfg['booking_link'] ) ) {
			$o['booking_link'] = $this->cfg['booking_link'];
		}
		if ( ! class_exists( 'SMC_Location_Fields' ) ) {
			return $o;
		}

		// Blank keeps the copied value; "none" clears it.
		$value = function ( $v ) {
			$v = trim( (string) $v );
			return 0 === strcasecmp( $v, 'none' ) ? '' : $v;
		};

		foreach ( (array) ( $this->cfg['hours'] ?? [] ) as $day => $v ) {
			if ( '' === trim( (string) $v ) ) {
				continue;
			}
			$v   = $value( $v );
			$day = strtolower( trim( (string) $day ) );
			if ( 'note' === $day ) {
				$o['hours_note'] = $v;
				continue;
			}
			if ( ! isset( SMC_Location_Fields::DAYS[ $day ] ) ) {
				throw new Exception( "Unknown day \"$day\" in hours. Use monday through sunday, or note." );
			}
			$o[ "hours_$day" ] = $v;
		}

		$aliases = [ 'google_url' => 'google_business_url', 'gbp_url' => 'google_business_url', 'google_business_profile_url' => 'google_business_url' ];
		foreach ( (array) ( $this->cfg['social'] ?? [] ) as $k => $v ) {
			if ( '' === trim( (string) $v ) ) {
				continue;
			}
			$v = $value( $v );
			$k = preg_replace( '/_url$/', '', strtolower( trim( (string) $k ) ) ) . '_url';
			$k = $aliases[ $k ] ?? $k;
			if ( ! isset( SMC_Location_Fields::SOCIAL[ $k ] ) ) {
				throw new Exception( "Unknown social link \"$k\". Use " . implode( ', ', array_keys( SMC_Location_Fields::SOCIAL ) ) . '.' );
			}
			$o[ $k ] = '' === $v ? '' : esc_url_raw( $v );
		}

		$email_label = trim( (string) ( $this->cfg['email_label'] ?? '' ) );
		if ( '' !== $email_label ) {
			$o['email_label'] = 0 === strcasecmp( $email_label, 'none' ) ? '' : $email_label;
		}

		$email = trim( (string) ( $this->cfg['email'] ?? '' ) );
		if ( '' !== $email ) {
			if ( ! is_email( $email ) ) {
				throw new Exception( "\"$email\" isn't a valid email address." );
			}
			$o['email'] = sanitize_email( $email );
		} else {
			$src_email = (string) get_term_meta( $this->src_term->term_id, 'email', true );
			if ( '' !== $src_email ) {
				$this->warn( "No email set. {$this->city_to} will use {$this->city_from}'s email ($src_email) until you change it." );
			}
		}

		$form = trim( (string) ( $this->cfg['form'] ?? '' ) );
		if ( '' !== $form ) {
			$url = SMC_Location_Fields::form_url( $form );
			if ( ! $url ) {
				throw new Exception( 'The embedded form must be a JotForm link, form ID, or embed code.' );
			}
			$o['form_embed'] = $url;
		} elseif ( '' !== (string) get_term_meta( $this->src_term->term_id, 'form_embed', true ) ) {
			$this->warn( "No embedded form set. {$this->city_to} will embed {$this->city_from}'s form until you change it." );
		}

		$map = trim( (string) ( $this->cfg['map'] ?? '' ) );
		if ( '' !== $map ) {
			$src = SMC_Location_Fields::map_src( $map );
			if ( ! $src ) {
				throw new Exception( 'The map must be Google Maps embed code (Share > Embed a map > Copy HTML) or its embed URL.' );
			}
			$o['map_embed'] = $src;
		} else {
			$o['map_embed'] = '';
			$this->warn( "No map set. {$this->city_from}'s map is not copied, so [location_map] will be empty until you add the new office's map on its location edit screen." );
		}
		return $o;
	}

	/* ========== Pages ========== */

	private function clone_page( WP_Post $src, $new_parent, $depth, $parent_path ) {
		$n     = 0;
		$title = $this->swap( $src->post_title, $n );
		$slug  = 0 === $depth ? $this->target_slug : $this->swap( $src->post_name, $n );
		$path  = ltrim( $parent_path . '/' . $slug, '/' );

		$meta = $this->copy_meta( $src->ID, $n );

		// Optional Yoast overrides, keyed by source slug ("_parent" for the top page).
		$yoast = $this->cfg['yoast'][ 0 === $depth ? '_parent' : $src->post_name ] ?? null;
		if ( $yoast ) {
			$vars = [ '{city}' => $this->city_to, '{state}' => $this->state ];
			foreach ( [ 'title', 'metadesc', 'focuskw' ] as $f ) {
				if ( isset( $yoast[ $f ] ) ) {
					$meta[ "_yoast_wpseo_$f" ] = strtr( $yoast[ $f ], $vars );
				}
			}
		}

		$content = $this->swap( $src->post_content, $n );
		$this->scan( "/$path/", $title . ' ' . $content . ' ' . wp_json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$terms     = $this->mapped_terms( $src->ID, $src->post_type );
		$loc_label = '-';
		if ( isset( $terms[ self::LOC_TAX ] ) ) {
			$labels = [];
			foreach ( wp_get_object_terms( $src->ID, self::LOC_TAX, [ 'fields' => 'ids' ] ) as $t ) {
				$labels[] = $this->term_label[ self::LOC_TAX ][ (int) $t ] ?? "kept:$t";
			}
			$loc_label = implode( ',', $labels );
		} else {
			$this->warn( "/$path/ has no location term, so [location] shortcodes on it will render empty." );
		}

		$new_id = 0;
		if ( ! $this->dry ) {
			$new_id = wp_insert_post(
				wp_slash(
					[
						'post_type'      => $src->post_type,
						'post_status'    => $this->cfg['status'],
						'post_title'     => $title,
						'post_name'      => $slug,
						'post_content'   => $content,
						'post_excerpt'   => $src->post_excerpt,
						'post_parent'    => $new_parent,
						'menu_order'     => $src->menu_order,
						'post_author'    => $src->post_author,
						'comment_status' => $src->comment_status,
						'meta_input'     => $meta,
					]
				),
				true
			);
			if ( is_wp_error( $new_id ) ) {
				throw new Exception( "Page insert failed for /$path/: " . $new_id->get_error_message() );
			}
			$this->log_add( 'pages', $new_id );
			foreach ( $terms as $tax => $ids ) {
				wp_set_object_terms( $new_id, array_map( 'intval', $ids ), $tax );
			}
		}

		$this->id_map[ $src->ID ] = $new_id ?: $src->ID;
		$this->report['pages'][]  = [
			'id'           => $new_id ?: '(dry)',
			'path'         => "/$path/",
			'source'       => $src->ID,
			'replacements' => $n,
			'location'     => $loc_label,
		];

		$children = get_posts(
			[
				'post_type'   => $src->post_type,
				'post_parent' => $src->ID,
				'post_status' => 'any',
				'numberposts' => -1,
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
			]
		);
		foreach ( $children as $child ) {
			if ( ! $this->is_excluded( $child ) ) {
				$this->clone_page( $child, $new_id, $depth + 1, $path );
			}
		}
	}

	/* ========== Templates ========== */

	private function clone_templates() {
		$templates = get_posts(
			[
				'post_type'   => 'elementor_library',
				'numberposts' => -1,
				'post_status' => 'any',
			]
		);

		foreach ( $templates as $tpl ) {
			$conds     = (array) ( get_post_meta( $tpl->ID, '_elementor_conditions', true ) ?: [] );
			$new_conds = [];
			$display   = [];

			foreach ( $conds as $cond ) {
				$parts = explode( '/', $cond );
				$last  = count( $parts ) - 1;
				if ( $last < 3 || ! ctype_digit( $parts[ $last ] ) ) {
					continue;
				}
				$id  = (int) $parts[ $last ];
				$sub = $parts[2];
				if ( 0 === strpos( $sub, 'in_' ) && isset( $this->term_map[ substr( $sub, 3 ) ][ $id ] ) ) {
					$tax            = substr( $sub, 3 );
					$parts[ $last ] = $this->term_map[ $tax ][ $id ];
					$display[]      = "$sub -> " . $this->term_label[ $tax ][ $id ];
				} elseif ( 0 !== strpos( $sub, 'in_' ) && isset( $this->id_map[ $id ] ) ) {
					$parts[ $last ] = $this->id_map[ $id ];
					$display[]      = "$sub -> cloned page";
				} else {
					continue;
				}
				$new_conds[] = implode( '/', $parts );
			}

			$named = false !== stripos( $tpl->post_title, $this->city_from );
			if ( ! $new_conds && ! $named ) {
				continue;
			}
			if ( ! $new_conds && $conds ) {
				$this->warn( "Template {$tpl->ID} \"{$tpl->post_title}\" matched by name only; its display conditions were not copied." );
			}

			$n     = 0;
			$title = $this->swap( $tpl->post_title, $n );
			if ( $title === $tpl->post_title ) {
				$title .= " ({$this->city_to})";
			}
			$meta = $this->copy_meta( $tpl->ID, $n );
			if ( $new_conds ) {
				$meta['_elementor_conditions'] = $new_conds;
			}
			$content = $this->swap( $tpl->post_content, $n );
			$this->scan( "Template \"$title\"", $title . ' ' . $content . ' ' . wp_json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			$terms = $this->mapped_terms( $tpl->ID, 'elementor_library' );

			$new_id = 0;
			if ( ! $this->dry ) {
				$new_id = wp_insert_post(
					wp_slash(
						[
							'post_type'    => 'elementor_library',
							'post_status'  => $tpl->post_status,
							'post_title'   => $title,
							'post_content' => $content,
							'post_excerpt' => $tpl->post_excerpt,
							'post_author'  => $tpl->post_author,
							'meta_input'   => $meta,
						]
					),
					true
				);
				if ( is_wp_error( $new_id ) ) {
					throw new Exception( "Template insert failed for \"$title\": " . $new_id->get_error_message() );
				}
				$this->log_add( 'templates', $new_id );
				foreach ( $terms as $tax => $ids ) {
					wp_set_object_terms( $new_id, array_map( 'intval', $ids ), $tax );
				}
			}

			$this->tpl_map[ $tpl->ID ]   = $new_id ?: $tpl->ID;
			$this->report['templates'][] = [
				'id'           => $new_id ?: '(dry)',
				'template'     => $title,
				'source'       => $tpl->ID,
				'type'         => get_post_meta( $tpl->ID, '_elementor_template_type', true ),
				'conditions'   => $display ? implode( ', ', $display ) : '-',
				'replacements' => $n,
			];
		}
	}

	/* ========== Relink: page IDs and template IDs inside cloned Elementor data ========== */

	private function relink() {
		foreach ( array_unique( array_merge( array_values( $this->id_map ), array_values( $this->tpl_map ) ) ) as $pid ) {
			$raw  = get_post_meta( $pid, '_elementor_data', true );
			$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
			if ( ! is_array( $data ) ) {
				continue;
			}
			$b1   = $this->report['links'];
			$b2   = $this->report['tpl_refs'];
			$data = self::remap_ids( $data, $this->id_map, $this->report['links'] );
			$data = self::remap_templates( $data, $this->tpl_map, $this->report['tpl_refs'] );
			if ( ! $this->dry && ( $this->report['links'] > $b1 || $this->report['tpl_refs'] > $b2 ) ) {
				update_post_meta( $pid, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			}
		}
	}

	/* ========== Menus ========== */

	private function clone_menus() {
		foreach ( wp_get_nav_menus() as $menu ) {
			if ( false === stripos( $menu->name, $this->city_from ) && false === strpos( $menu->slug, sanitize_title( $this->city_from ) ) ) {
				continue;
			}
			$mn       = 0;
			$new_name = $this->swap( $menu->name, $mn );
			if ( $new_name === $menu->name ) {
				$new_name .= " {$this->city_to}";
			}
			if ( wp_get_nav_menu_object( $new_name ) || wp_get_nav_menu_object( sanitize_title( $new_name ) ) ) {
				throw new Exception( "Menu \"$new_name\" already exists." );
			}

			$items    = wp_get_nav_menu_items( $menu->term_id ) ?: [];
			$remapped = 0;
			foreach ( $items as $it ) {
				if ( 'post_type' !== $it->type ) {
					continue;
				}
				if ( isset( $this->id_map[ (int) $it->object_id ] ) ) {
					$remapped++;
				} elseif ( in_array( (int) $it->object_id, $this->tree_ids, true ) ) {
					$this->warn( "Menu item \"{$it->title}\" points to an excluded page; the copy will still link to the {$this->city_from} page." );
				}
			}

			if ( ! $this->dry ) {
				$new_menu_id = wp_create_nav_menu( $new_name );
				if ( is_wp_error( $new_menu_id ) ) {
					throw new Exception( 'Menu create failed: ' . $new_menu_id->get_error_message() );
				}
				$this->log_add( 'menus', $new_menu_id );
				$item_map = [];
				$parents  = [];
				foreach ( $items as $it ) {
					$oid = (int) $it->object_id;
					if ( 'post_type' === $it->type && isset( $this->id_map[ $oid ] ) ) {
						$oid = $this->id_map[ $oid ];
					} elseif ( 'taxonomy' === $it->type && isset( $this->term_map[ $it->object ][ $oid ] ) ) {
						$oid = $this->term_map[ $it->object ][ $oid ];
					}
					$c        = 0;
					$new_item = wp_update_nav_menu_item(
						$new_menu_id,
						0,
						[
							'menu-item-object-id'   => $oid,
							'menu-item-object'      => $it->object,
							'menu-item-type'        => $it->type,
							'menu-item-title'       => $this->swap( $it->post_title, $c ),
							'menu-item-url'         => 'custom' === $it->type ? $this->swap( $it->url, $c ) : '',
							'menu-item-description' => $it->description,
							'menu-item-attr-title'  => $it->attr_title,
							'menu-item-target'      => $it->target,
							'menu-item-classes'     => implode( ' ', array_filter( (array) $it->classes ) ),
							'menu-item-xfn'         => $it->xfn,
							'menu-item-position'    => $it->menu_order,
							'menu-item-status'      => 'publish',
						]
					);
					if ( is_wp_error( $new_item ) ) {
						$this->warn( "Menu item \"{$it->title}\" failed: " . $new_item->get_error_message() );
						continue;
					}
					$item_map[ $it->ID ] = $new_item;
					if ( $it->menu_item_parent ) {
						$parents[ $new_item ] = (int) $it->menu_item_parent;
					}
				}
				foreach ( $parents as $new_item => $old_parent ) {
					if ( isset( $item_map[ $old_parent ] ) ) {
						update_post_meta( $new_item, '_menu_item_menu_item_parent', (string) $item_map[ $old_parent ] );
					}
				}
			}

			$this->report['menus'][] = [
				'menu'           => "{$menu->name} ({$menu->slug})",
				'new menu'       => $new_name . ' (' . sanitize_title( $new_name ) . ')',
				'items'          => count( $items ),
				'pages remapped' => $remapped,
			];
		}
	}

	/* ========== Undo ========== */

	public static function get_log() {
		return get_option( self::LOG_OPTION, [] );
	}

	/** Pages and templates edited after the clone was created (publishing counts as an edit). */
	public static function modified_since_clone( $slug ) {
		$log = self::get_log();
		if ( ! isset( $log[ $slug ] ) ) {
			return [];
		}
		$e   = $log[ $slug ];
		$out = [];
		foreach ( array_merge( $e['pages'], $e['templates'] ) as $id ) {
			$p = get_post( $id );
			if ( $p && strtotime( $p->post_modified_gmt . ' UTC' ) > $e['created'] + 120 ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	public static function undo( $slug ) {
		$log = self::get_log();
		if ( ! isset( $log[ $slug ] ) ) {
			throw new Exception( "No clone named \"$slug\" in the undo log." );
		}
		$e      = $log[ $slug ];
		$counts = [ 'pages' => 0, 'templates' => 0, 'menus' => 0, 'terms' => 0 ];

		// Children were created after parents, so delete newest first.
		foreach ( array_reverse( $e['pages'] ) as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				$counts['pages']++;
			}
		}
		foreach ( $e['templates'] as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				$counts['templates']++;
			}
		}
		foreach ( $e['menus'] as $id ) {
			if ( true === wp_delete_nav_menu( $id ) ) {
				$counts['menus']++;
			}
		}
		foreach ( $e['terms'] as $tax => $ids ) {
			foreach ( $ids as $tid ) {
				if ( true === wp_delete_term( $tid, $tax ) ) {
					$counts['terms']++;
				}
			}
		}

		unset( $log[ $slug ] );
		update_option( self::LOG_OPTION, $log, false );
		$counts['warnings'] = self::refresh_caches();
		return $counts;
	}

	private function log_add( $type, $id, $tax = null ) {
		$log = self::get_log();
		if ( ! isset( $log[ $this->target_slug ] ) ) {
			return;
		}
		if ( 'terms' === $type ) {
			$log[ $this->target_slug ]['terms'][ $tax ][] = $id;
		} else {
			$log[ $this->target_slug ][ $type ][] = $id;
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	/* ========== Caches ========== */

	/** Rebuilds Elementor Pro's conditions cache and clears Elementor CSS. Returns any warnings. */
	public static function refresh_caches() {
		$warnings = [];
		if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			try {
				\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache()->regenerate();
			} catch ( \Throwable $e ) {
				$warnings[] = 'Could not regenerate Theme Builder conditions (' . $e->getMessage() . '). Open Templates > Theme Builder and re-save any template\'s conditions.';
			}
		}
		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		return $warnings;
	}

	/* ========== Helpers ========== */

	private function warn( $msg ) {
		$this->report['warnings'][] = $msg;
	}

	public static function phone_variants( $d ) {
		$a = substr( $d, 0, 3 );
		$b = substr( $d, 3, 3 );
		$c = substr( $d, 6 );
		return [ "($a) $b-$c", "+1-$a-$b-$c", "1-$a-$b-$c", "$a-$b-$c", "$a.$b.$c", "$a $b $c", "+1$d", $d ];
	}

	private function swap( $v, &$count ) {
		if ( is_string( $v ) ) {
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

	private function copy_meta( $post_id, &$n ) {
		$meta = [];
		foreach ( get_post_meta( $post_id ) as $key => $vals ) {
			if ( in_array( $key, $this->skip_meta, true ) ) {
				continue;
			}
			$val = maybe_unserialize( $vals[0] );
			if ( '_elementor_data' === $key ) {
				if ( '' === trim( (string) $val ) ) {
					$meta[ $key ] = $val;
					continue;
				}
				$data = json_decode( $val, true );
				if ( ! is_array( $data ) ) {
					$this->warn( "Could not decode _elementor_data on $post_id; copied as-is." );
					$meta[ $key ] = $val;
					continue;
				}
				$meta[ $key ] = wp_json_encode( $this->swap( $data, $n ) );
				continue;
			}
			$meta[ $key ] = $this->swap( $val, $n );
		}
		return $meta;
	}

	private function mapped_terms( $post_id, $post_type ) {
		$out = [];
		foreach ( get_object_taxonomies( $post_type ) as $tax ) {
			$ids = wp_get_object_terms( $post_id, $tax, [ 'fields' => 'ids' ] );
			if ( is_wp_error( $ids ) || ! $ids ) {
				continue;
			}
			$out[ $tax ] = array_map( fn( $t ) => $this->term_map[ $tax ][ (int) $t ] ?? (int) $t, $ids );
		}
		return $out;
	}

	private static function remap_ids( $v, $id_map, &$count ) {
		if ( is_string( $v ) ) {
			return preg_replace_callback(
				'/(post_id%22%3A%22)(\d+)(%22)/',
				function ( $m ) use ( $id_map, &$count ) {
					$old = (int) $m[2];
					if ( ! isset( $id_map[ $old ] ) ) {
						return $m[0];
					}
					$count++;
					return $m[1] . $id_map[ $old ] . $m[3];
				},
				$v
			);
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::remap_ids( $x, $id_map, $count );
			}
		}
		return $v;
	}

	private static function remap_templates( $v, $tpl_map, &$count ) {
		if ( is_string( $v ) ) {
			return preg_replace_callback(
				'/(elementor-template\s+id=\\\\?["\']?)(\d+)/',
				function ( $m ) use ( $tpl_map, &$count ) {
					$old = (int) $m[2];
					if ( ! isset( $tpl_map[ $old ] ) ) {
						return $m[0];
					}
					$count++;
					return $m[1] . $tpl_map[ $old ];
				},
				$v
			);
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				if ( in_array( $k, [ 'template_id', 'templateID' ], true ) && is_scalar( $x ) && isset( $tpl_map[ (int) $x ] ) ) {
					$v[ $k ] = (string) $tpl_map[ (int) $x ];
					$count++;
					continue;
				}
				$v[ $k ] = self::remap_templates( $x, $tpl_map, $count );
			}
		}
		return $v;
	}

	private function rel_path( $post ) {
		return ltrim( substr( get_page_uri( $post ), strlen( $this->source_uri ) ), '/' );
	}

	private function is_excluded( $post ) {
		$rel = $this->rel_path( $post );
		foreach ( (array) $this->cfg['exclude'] as $p ) {
			$p = trim( $p, '/' );
			if ( fnmatch( $p, $rel ) || fnmatch( $p, $post->post_name ) ) {
				return true;
			}
		}
		return false;
	}

	private function scan( $label, $text ) {
		$text = preg_replace( '#\\\\+/#', '/', $text );
		$text = preg_replace( $this->protect_re, '', $text );
		foreach ( $this->leftovers as $l ) {
			if ( ctype_digit( $l ) ) {
				$pos = preg_match( '/(?<!\d)' . $l . '(?!\d)/', $text, $mm, PREG_OFFSET_CAPTURE ) ? $mm[0][1] : false;
			} else {
				$pos = stripos( $text, $l );
			}
			if ( false !== $pos ) {
				$snip = str_replace( [ "\n", "\r", "\t" ], ' ', substr( $text, max( 0, $pos - 50 ), strlen( $l ) + 100 ) );
				$this->warn( "$label still contains \"$l\": ...$snip..." );
			}
		}
		foreach ( $this->excluded as $rel ) {
			if ( false !== stripos( $text, "/{$this->target_path}/$rel/" ) ) {
				$this->warn( "$label links to excluded page /{$this->target_path}/$rel/ (will 404)" );
			}
		}
	}
}
