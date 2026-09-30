<?php
/**
 * Location details as Yoast SEO variables.
 *
 *   %%location_city%%        Springfield
 *   %%location_state%%       ST
 *   %%location_city_state%%  Springfield, ST
 *   %%location_name%%        the location's name
 *   %%location_phone%%       555-555-0100
 *   %%location_address%%     123 Main St, Springfield, ST 12345
 *   %%location_street%%      123 Main St
 *   %%location_zip%%         12345
 *   %%location_email%%       the location's email
 *
 * Use them in SEO titles, meta descriptions and social titles. Each page shows its own
 * location's details, so cloned pages need no swapping and edits to a location show up
 * everywhere. Also shown in Yoast's snippet preview and "Insert variable" list.
 *
 * wp smc location yoast-vars swaps the typed-in details already in Yoast fields for these.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Yoast {

	const TAX = 'location_category';

	/** Yoast fields the converter touches. Focus keyphrases are left alone (Yoast doesn't replace variables there). */
	const FIELDS = [ 'title', 'metadesc', 'opengraph-title', 'opengraph-description', 'twitter-title', 'twitter-description' ];

	public static function vars() {
		return apply_filters(
			'smc_location_yoast_vars',
			[
				'location_city'       => 'Location city',
				'location_state'      => 'Location state',
				'location_city_state' => 'Location city and state',
				'location_name'       => 'Location name',
				'location_phone'      => 'Location phone',
				'location_address'    => 'Location address',
				'location_street'     => 'Location street',
				'location_zip'        => 'Location zip',
				'location_email'      => 'Location email',
			]
		);
	}

	public static function init() {
		add_action( 'wpseo_register_extra_replacements', [ __CLASS__, 'register' ] );
		add_action( 'admin_footer-post.php', [ __CLASS__, 'editor_script' ] );
		add_action( 'admin_footer-post-new.php', [ __CLASS__, 'editor_script' ] );
		add_action( 'elementor/editor/footer', [ __CLASS__, 'editor_script' ] );
	}

	public static function register() {
		if ( ! function_exists( 'wpseo_register_var_replacement' ) ) {
			return;
		}
		foreach ( self::vars() as $var => $label ) {
			wpseo_register_var_replacement(
				$var,
				function ( $name, $args ) use ( $var ) {
					$post_id = is_object( $args ) && ! empty( $args->ID ) ? (int) $args->ID : 0;
					return self::values( self::term_for( $post_id ) )[ $var ] ?? '';
				},
				'advanced',
				"$label, from the page's location (Locations > All Locations)"
			);
		}
	}

	/** Location term for a post, or for the page being viewed. */
	public static function term_for( $post_id ) {
		if ( $post_id ) {
			$ids = wp_get_post_terms( $post_id, self::TAX, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $ids ) && $ids ) {
				return (int) $ids[0];
			}
			return SMC_Location_Fields::only_location_id();
		}
		return SMC_Location_Fields::current_location_id();
	}

	/** Every variable's value for a location term. */
	public static function values( $tid ) {
		static $cache = [];
		$tid = (int) $tid;
		if ( isset( $cache[ $tid ] ) ) {
			return $cache[ $tid ];
		}
		$out = array_fill_keys( array_keys( self::vars() ), '' );
		$t   = $tid ? get_term( $tid, self::TAX ) : null;
		if ( $t && ! is_wp_error( $t ) ) {
			$m    = fn( $k ) => trim( (string) get_term_meta( $tid, $k, true ) );
			$cs   = $m( 'city_state' );
			$addr = array_map( 'trim', preg_split( '#\s*<br\s*/?>\s*#i', $m( 'address' ) ) );
			$csz  = $addr[1] ?? '';

			$out['location_name']       = $t->name;
			$out['location_city_state'] = $cs;
			$out['location_city']       = '' !== $cs ? trim( explode( ',', $cs )[0] ) : $t->name;
			$out['location_state']      = preg_match( '/,\s*([A-Z]{2})\b/', $cs ?: $csz, $s ) ? $s[1] : '';
			$out['location_phone']      = $m( 'phone_label' );
			$out['location_street']     = $addr[0] ?? '';
			$out['location_zip']        = preg_match( '/\b(\d{5}(?:-\d{4})?)\s*$/', $csz, $z ) ? $z[1] : '';
			$out['location_address']    = implode( ', ', array_filter( [ $addr[0] ?? '', $csz ] ) );
			$out['location_email']      = $m( 'email' );
		}
		$cache[ $tid ] = apply_filters( 'smc_location_yoast_values', $out, $tid );
		return $cache[ $tid ];
	}

	/** Fills the variables into Yoast's snippet preview and "Insert variable" list in the editors. */
	public static function editor_script() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}
		$post_id = 0;
		if ( 'elementor/editor/footer' === current_action() && class_exists( '\Elementor\Plugin' ) ) {
			$post_id = (int) \Elementor\Plugin::$instance->editor->get_post_id();
		} elseif ( ! empty( $GLOBALS['post']->ID ) ) {
			$post_id = (int) $GLOBALS['post']->ID;
		}
		$values = self::values( self::term_for( $post_id ) );
		$data   = [];
		foreach ( self::vars() as $var => $label ) {
			$data[ $var ] = [ 'value' => $values[ $var ] ?? '', 'label' => $label ];
		}
		?>
		<script>
		( function ( vars ) {
			var names = Object.keys( vars );
			function replace( text ) {
				if ( 'string' !== typeof text ) { return text; }
				names.forEach( function ( k ) { text = text.split( '%%' + k + '%%' ).join( vars[ k ].value ); } );
				return text;
			}
			// Yoast 14+: the editor store (preview and "Insert variable" list).
			function viaStore() {
				try {
					if ( ! window.wp || ! wp.data || ! wp.data.select( 'yoast-seo/editor' ) ) { return false; }
					var d = wp.data.dispatch( 'yoast-seo/editor' );
					if ( ! d || ! d.updateReplacementVariable ) { return false; }
					names.forEach( function ( k ) { d.updateReplacementVariable( k, vars[ k ].value, vars[ k ].label ); } );
					return true;
				} catch ( e ) { return false; }
			}
			// Older Yoast: snippet preview modifications.
			function viaApp() {
				if ( ! window.YoastSEO || ! YoastSEO.app || ! YoastSEO.app.registerModification ) { return false; }
				YoastSEO.app.registerPlugin( 'smcLocation', { status: 'ready' } );
				[ 'data_page_title', 'data_meta_desc' ].forEach( function ( m ) { YoastSEO.app.registerModification( m, replace, 'smcLocation', 10 ); } );
				return true;
			}
			var tries = 0;
			( function go() {
				if ( viaStore() || tries++ > 40 ) { return; }
				setTimeout( go, 500 );
			} )();
			if ( window.jQuery ) { jQuery( window ).on( 'YoastSEO:ready', viaApp ); }
		} )( <?php echo wp_json_encode( $data ); ?> );
		</script>
		<?php
	}

	/* ========== Converter: typed-in details to variables ========== */

	/**
	 * Proposed changes to Yoast fields on a location's pages: typed-in city, phone, address
	 * and so on swapped for variables. Longest values first, so "Springfield, ST" becomes
	 * %%location_city_state%% rather than "%%location_city%%, ST".
	 *
	 * @return array list of [ id, path, field, old, new ]
	 */
	public static function conversions( WP_Term $term ) {
		$vals = self::values( $term->term_id );
		$map  = [];
		foreach ( [ 'location_address', 'location_city_state', 'location_street', 'location_email', 'location_city' ] as $k ) {
			if ( strlen( $vals[ $k ] ) >= 3 ) {
				$map[ $vals[ $k ] ] = "%%$k%%";
			}
		}
		$phone_re = '';
		$digits   = preg_replace( '/\D/', '', $vals['location_phone'] );
		if ( 10 === strlen( $digits ) ) {
			// 555-555-0100, (555) 555-0100, 555.555.0100, 5555550100
			$phone_re = '/\(?' . substr( $digits, 0, 3 ) . '\)?[\s.\-]?' . substr( $digits, 3, 3 ) . '[\s.\-]?' . substr( $digits, 6 ) . '\b/';
		}
		uksort( $map, fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$rows = [];
		foreach ( SMC_Location_Manager::tree( $term ) as $p ) {
			foreach ( self::FIELDS as $f ) {
				$old = (string) get_post_meta( $p->ID, "_yoast_wpseo_$f", true );
				if ( '' === trim( $old ) ) {
					continue;
				}
				$new = $old;
				if ( $phone_re ) {
					$new = preg_replace( $phone_re, '%%location_phone%%', $new );
				}
				foreach ( $map as $from => $to ) {
					// Whole words only, and never inside a variable that's already there.
					$new = preg_replace( '/(?<![\w%])' . preg_quote( $from, '/' ) . '(?![\w%])/', $to, $new );
				}
				if ( $new !== $old ) {
					$rows[] = [ 'id' => $p->ID, 'path' => '/' . get_page_uri( $p ) . '/', 'field' => $f, 'old' => $old, 'new' => $new ];
				}
			}
		}
		return $rows;
	}

	public static function apply( array $rows ) {
		$ids = [];
		foreach ( $rows as $r ) {
			update_post_meta( $r['id'], "_yoast_wpseo_{$r['field']}", wp_slash( $r['new'] ) );
			$ids[ $r['id'] ] = true;
		}
		foreach ( array_keys( $ids ) as $id ) {
			self::rebuild_indexable( $id );
		}
		return count( $rows );
	}

	/** Yoast keeps its own copy of each page's SEO fields (indexables), so rebuild it after a direct meta change. */
	public static function rebuild_indexable( $id ) {
		try {
			if ( function_exists( 'YoastSEO' ) && class_exists( '\Yoast\WP\SEO\Builders\Indexable_Builder' ) ) {
				$repo      = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
				$builder   = YoastSEO()->classes->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class );
				$indexable = $repo->find_by_id_and_type( $id, 'post', false );
				$builder->build_for_id_and_type( $id, 'post', $indexable ?: false );
				return;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Fall through to a plain save, which Yoast also listens for.
		}
		wp_update_post( [ 'ID' => $id ] );
	}
}
