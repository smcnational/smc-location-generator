<?php
/**
 * Location reviews.
 *
 * Reviews are their own post type (Locations > Reviews): reviewer name, rating, review
 * text, date, source, and one or more locations. Show them with:
 *
 *   [location_reviews]                     reviews for the page's location, newest first
 *   [location_reviews limit="3" min_rating="5" order="random" columns="1"]
 *   [location_reviews location="kenton"]   a specific location ("all" for every location)
 *
 * Or design them in Elementor: use a Loop Grid or Loop Carousel and set its Query ID to
 *   location_reviews           newest first
 *   location_reviews_random    random order
 *   location_reviews_5star     5-star reviews only, newest first
 * The query switches to reviews for the page's location automatically.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Reviews {

	const TYPE    = 'smc_review';
	const TAX     = 'location_category';
	const SOURCES = [ 'Google', 'Facebook', 'Yelp', 'Healthgrades', 'Zocdoc', 'Website', 'Other' ];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ], 99 );
		add_action( 'acf/init', [ __CLASS__, 'register_fields' ] );
		add_action( 'init', [ __CLASS__, 'register_shortcode' ], 20 );

		// Elementor Loop Grid / Loop Carousel query IDs.
		foreach ( [ 'location_reviews', 'location_reviews_random', 'location_reviews_5star' ] as $qid ) {
			add_action( "elementor/query/$qid", [ __CLASS__, 'elementor_query' ] );
		}

		if ( is_admin() ) {
			add_filter( 'enter_title_here', [ __CLASS__, 'title_placeholder' ], 10, 2 );
			add_action( 'add_meta_boxes_' . self::TYPE, [ __CLASS__, 'meta_boxes' ] );
			add_action( 'save_post_' . self::TYPE, [ __CLASS__, 'save_locations' ], 10, 2 );
			add_action( 'acf/save_post', [ __CLASS__, 'sync_date' ], 20 );
			add_filter( 'manage_' . self::TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
			add_action( 'manage_' . self::TYPE . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
			add_action( 'restrict_manage_posts', [ __CLASS__, 'location_filter' ] );
			add_action( 'pre_get_posts', [ __CLASS__, 'apply_location_filter' ] );
		}
	}

	/* ========== Registration ========== */

	public static function register() {
		register_post_type(
			self::TYPE,
			[
				'labels'              => [
					'name'          => 'Reviews',
					'singular_name' => 'Review',
					'add_new'       => 'Add Review',
					'add_new_item'  => 'Add Review',
					'edit_item'     => 'Edit Review',
					'search_items'  => 'Search Reviews',
					'not_found'     => 'No reviews yet.',
					'all_items'     => 'Reviews',
				],
				// "public" + nav menus lets Elementor list Reviews as a Loop source and preview them
				// in Loop Item templates. Reviews still have no URLs, archive, search or sitemap entry.
				'public'              => true,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_in_nav_menus'   => true,
				'rewrite'             => false,
				'query_var'           => false,
				'has_archive'         => false,
				'show_ui'             => true,
				'show_in_menu'        => class_exists( 'SMC_Location_Manager' ) ? SMC_Location_Manager::SLUG : true,
				'show_in_rest'        => false,
				'supports'            => [ 'title', 'editor' ],
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			]
		);
		if ( taxonomy_exists( self::TAX ) ) {
			register_taxonomy_for_object_type( self::TAX, self::TYPE );
		}
	}

	public static function register_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		acf_add_local_field_group(
			[
				'key'      => 'group_smc_review',
				'title'    => 'Review details',
				'fields'   => [
					[
						'key'           => 'field_smc_review_rating',
						'label'         => 'Rating (1 to 5)',
						'name'          => 'rating',
						'type'          => 'number',
						'min'           => 1,
						'max'           => 5,
						'step'          => 1,
						'default_value' => 5,
						'wrapper'       => [ 'width' => '25' ],
					],
					[
						'key'            => 'field_smc_review_date',
						'label'          => 'Review date',
						'name'           => 'review_date',
						'type'           => 'date_picker',
						'display_format' => 'F j, Y',
						'return_format'  => 'F j, Y',
						'wrapper'        => [ 'width' => '25' ],
					],
					[
						'key'           => 'field_smc_review_source',
						'label'         => 'Source',
						'name'          => 'source',
						'type'          => 'select',
						'choices'       => array_combine( self::SOURCES, self::SOURCES ),
						'default_value' => 'Google',
						'allow_null'    => 1,
						'wrapper'       => [ 'width' => '25' ],
					],
					[
						'key'     => 'field_smc_review_link',
						'label'   => 'Link to review',
						'name'    => 'review_link',
						'type'    => 'url',
						'wrapper' => [ 'width' => '25' ],
					],
				],
				'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => self::TYPE ] ] ],
				'position' => 'acf_after_title',
			]
		);
	}

	/* ========== Edit screen ========== */

	public static function title_placeholder( $text, $post ) {
		return self::TYPE === $post->post_type ? 'Reviewer name, e.g. Jane D.' : $text;
	}

	public static function meta_boxes() {
		// Replace the default taxonomy box with plain checkboxes.
		remove_meta_box( self::TAX . 'div', self::TYPE, 'side' );
		remove_meta_box( 'tagsdiv-' . self::TAX, self::TYPE, 'side' );
		add_meta_box( 'smc_review_locations', 'Locations', [ __CLASS__, 'locations_box' ], self::TYPE, 'side', 'high' );
	}

	public static function locations_box( $post ) {
		wp_nonce_field( 'smc_review_locations', 'smc_review_locations_nonce' );
		$set = wp_get_object_terms( $post->ID, self::TAX, [ 'fields' => 'ids' ] );
		$set = is_wp_error( $set ) ? [] : array_map( 'intval', $set );
		if ( 'auto-draft' === $post->post_status && isset( $_GET['location'] ) ) {
			$set = [ absint( $_GET['location'] ) ];
		}
		echo '<p class="description" style="margin-top:0">Where this review shows. Pick more than one for practice-wide reviews.</p>';
		foreach ( self::locations() as $t ) {
			printf(
				'<label style="display:block;margin:4px 0"><input type="checkbox" name="smc_review_locations[]" value="%d" %s> %s</label>',
				(int) $t->term_id,
				checked( in_array( (int) $t->term_id, $set, true ), true, false ),
				esc_html( $t->name )
			);
		}
	}

	public static function save_locations( $post_id, $post ) {
		if ( ! isset( $_POST['smc_review_locations_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['smc_review_locations_nonce'] ), 'smc_review_locations' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$ids = array_map( 'absint', (array) ( $_POST['smc_review_locations'] ?? [] ) );
		wp_set_object_terms( $post_id, $ids, self::TAX );
	}

	/** Keeps the post date equal to the review date, so reviews sort by when they were written. */
	public static function sync_date( $post_id ) {
		if ( self::TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$ymd = (string) get_post_meta( $post_id, 'review_date', true );
		if ( ! preg_match( '/^\d{8}$/', $ymd ) ) {
			return;
		}
		$date = substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 ) . ' 12:00:00';
		if ( get_post_field( 'post_date', $post_id ) !== $date ) {
			remove_action( 'acf/save_post', [ __CLASS__, 'sync_date' ], 20 );
			wp_update_post( [ 'ID' => $post_id, 'post_date' => $date, 'post_date_gmt' => get_gmt_from_date( $date ) ] );
			add_action( 'acf/save_post', [ __CLASS__, 'sync_date' ], 20 );
		}
	}

	/* ========== List screen ========== */

	public static function columns( $cols ) {
		unset( $cols[ 'taxonomy-' . self::TAX ] );
		$out = [];
		foreach ( $cols as $k => $v ) {
			if ( 'title' === $k ) {
				$out['title']      = 'Reviewer';
				$out['smc_rating'] = 'Rating';
				$out['smc_text']   = 'Review';
				$out['smc_loc']    = 'Locations';
				$out['smc_source'] = 'Source';
			} elseif ( 'date' === $k ) {
				$out['date'] = 'Date';
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	public static function column( $col, $post_id ) {
		if ( 'smc_rating' === $col ) {
			echo '<span style="color:#f5a623;letter-spacing:1px">' . esc_html( self::stars( (int) get_post_meta( $post_id, 'rating', true ) ) ) . '</span>';
		} elseif ( 'smc_text' === $col ) {
			echo esc_html( wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 20 ) );
		} elseif ( 'smc_loc' === $col ) {
			$terms = wp_get_object_terms( $post_id, self::TAX );
			if ( is_wp_error( $terms ) || ! $terms ) {
				echo '<span style="color:#b32d2e">None</span>';
			} else {
				$links = [];
				foreach ( $terms as $t ) {
					$links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::TYPE . '&smc_location=' . $t->term_id ) ) . '">' . esc_html( $t->name ) . '</a>';
				}
				echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		} elseif ( 'smc_source' === $col ) {
			echo esc_html( (string) get_post_meta( $post_id, 'source', true ) ?: '-' );
		}
	}

	public static function location_filter( $post_type ) {
		if ( self::TYPE !== $post_type ) {
			return;
		}
		$cur = absint( $_GET['smc_location'] ?? 0 );
		echo '<select name="smc_location"><option value="">All locations</option>';
		foreach ( self::locations() as $t ) {
			printf( '<option value="%d" %s>%s</option>', (int) $t->term_id, selected( $cur, (int) $t->term_id, false ), esc_html( $t->name ) );
		}
		echo '</select>';
	}

	public static function apply_location_filter( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || self::TYPE !== $q->get( 'post_type' ) || empty( $_GET['smc_location'] ) ) {
			return;
		}
		$q->set( 'tax_query', [ [ 'taxonomy' => self::TAX, 'terms' => absint( $_GET['smc_location'] ) ] ] );
	}

	/* ========== Queries ========== */

	private static function locations() {
		if ( class_exists( 'SMC_Location_Manager' ) ) {
			return SMC_Location_Manager::locations();
		}
		$t = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false ] );
		return is_wp_error( $t ) ? [] : $t;
	}

	/** Number of published reviews for a location. */
	public static function count( $term_id ) {
		$q = new WP_Query(
			[
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'tax_query'      => [ [ 'taxonomy' => self::TAX, 'terms' => (int) $term_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		return (int) $q->found_posts;
	}

	/** Elementor Loop Grid / Loop Carousel with Query ID location_reviews[_random|_5star]. */
	public static function elementor_query( $query ) {
		$query->set( 'post_type', self::TYPE );
		$query->set( 'post_status', 'publish' );
		$tid = SMC_Location_Fields::listing_location_id();
		if ( $tid ) {
			$query->set( 'tax_query', [ [ 'taxonomy' => self::TAX, 'terms' => $tid ] ] );
		}
		$qid = current_filter();
		if ( 'elementor/query/location_reviews_random' === $qid ) {
			$query->set( 'orderby', 'rand' );
		} else {
			$query->set( 'orderby', 'date' );
			$query->set( 'order', 'DESC' );
		}
		if ( 'elementor/query/location_reviews_5star' === $qid ) {
			$query->set( 'meta_query', [ [ 'key' => 'rating', 'value' => 5, 'compare' => '>=', 'type' => 'NUMERIC' ] ] );
		}
	}

	/* ========== Shortcode ========== */

	public static function register_shortcode() {
		if ( ! shortcode_exists( 'location_reviews' ) ) {
			add_shortcode( 'location_reviews', [ __CLASS__, 'shortcode' ] );
		}
		if ( ! shortcode_exists( 'review' ) ) {
			add_shortcode( 'review', [ __CLASS__, 'review_shortcode' ] );
		}
	}

	/**
	 * [review field="..."] - one part of the current review, for Elementor Loop Item templates.
	 *
	 *   field="text"     review text (words="40" to shorten)
	 *   field="name"     reviewer name
	 *   field="stars"    ★★★★★ (color="#f5a623" by default; color="inherit" to use the widget's color)
	 *   field="rating"   the number, 1 to 5
	 *   field="source"   Google, Facebook...
	 *   field="date"     review date (format="F Y" by default, any PHP date format)
	 *   field="link"     URL of the original review
	 *   field="location" the office(s) the review is for
	 */
	public static function review_shortcode( $atts ) {
		$a  = shortcode_atts( [ 'field' => 'text', 'words' => 0, 'format' => 'F Y', 'color' => '#f5a623' ], $atts, 'review' );
		$id = get_the_ID();
		if ( ! $id || self::TYPE !== get_post_type( $id ) ) {
			return '';
		}
		$rating = max( 0, min( 5, (int) get_post_meta( $id, 'rating', true ) ) );

		switch ( strtolower( $a['field'] ) ) {
			case 'name':
				return esc_html( get_the_title( $id ) );
			case 'rating':
				return (string) $rating;
			case 'stars':
				$color = 'inherit' === $a['color'] ? '' : 'color:' . esc_attr( sanitize_text_field( $a['color'] ) ) . ';';
				return '<span class="smc-review-stars" role="img" aria-label="' . esc_attr( "Rated $rating out of 5" ) . '" style="' . $color . 'letter-spacing:2px">' . esc_html( self::stars( $rating ) ) . '</span>';
			case 'source':
				return esc_html( (string) get_post_meta( $id, 'source', true ) );
			case 'date':
				return esc_html( get_the_date( sanitize_text_field( $a['format'] ), $id ) );
			case 'link':
				return esc_url( (string) get_post_meta( $id, 'review_link', true ) );
			case 'location':
			case 'locations':
				return SMC_Location_Fields::location_names( $id );
			default:
				$text = wp_strip_all_tags( get_post_field( 'post_content', $id ) );
				if ( (int) $a['words'] > 0 ) {
					$text = wp_trim_words( $text, (int) $a['words'] );
				}
				return nl2br( esc_html( $text ) );
		}
	}

	public static function shortcode( $atts ) {
		$a = shortcode_atts(
			[
				'location'    => '',
				'limit'       => 6,
				'min_rating'  => 1,
				'order'       => 'newest',
				'columns'     => 3,
				'words'       => 0,
				'show_rating' => 'yes',
				'show_source' => 'yes',
				'show_location' => 'auto',
				'show_date'   => 'no',
			],
			$atts,
			'location_reviews'
		);

		$args = [
			'post_type'      => self::TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 100, (int) $a['limit'] ) ),
			'orderby'        => 'random' === $a['order'] ? 'rand' : 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];
		$tid = SMC_Location_Fields::listing_location_id( $a['location'] );
		if ( $tid ) {
			$args['tax_query'] = [ [ 'taxonomy' => self::TAX, 'terms' => $tid ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$show_loc = 'auto' === strtolower( (string) ( $a['show_location'] ?? 'auto' ) ) ? ! $tid : in_array( strtolower( (string) $a['show_location'] ), [ 'yes', 'true', '1', 'on' ], true );
		if ( (int) $a['min_rating'] > 1 ) {
			$args['meta_query'] = [ [ 'key' => 'rating', 'value' => (int) $a['min_rating'], 'compare' => '>=', 'type' => 'NUMERIC' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$posts = get_posts( $args );
		if ( ! $posts ) {
			return '';
		}

		$yes  = fn( $v ) => ! in_array( strtolower( (string) $v ), [ 'no', 'false', '0', 'off' ], true );
		$cols = max( 1, min( 4, (int) $a['columns'] ) );
		$out  = self::css() . '<div class="smc-reviews" style="--smc-review-cols:' . $cols . '">';

		foreach ( $posts as $p ) {
			$rating = (int) get_post_meta( $p->ID, 'rating', true );
			$text   = wp_strip_all_tags( $p->post_content );
			if ( (int) $a['words'] > 0 ) {
				$text = wp_trim_words( $text, (int) $a['words'] );
			}
			$source = (string) get_post_meta( $p->ID, 'source', true );
			$link   = (string) get_post_meta( $p->ID, 'review_link', true );

			$out .= '<div class="smc-review">';
			if ( $yes( $a['show_rating'] ) && $rating ) {
				$out .= '<div class="smc-review-stars" role="img" aria-label="' . esc_attr( "Rated $rating out of 5" ) . '">' . esc_html( self::stars( $rating ) ) . '</div>';
			}
			$out .= '<div class="smc-review-text">' . wpautop( esc_html( $text ) ) . '</div>';
			$out .= '<div class="smc-review-meta"><span class="smc-review-name">' . esc_html( $p->post_title ) . '</span>';
			if ( $yes( $a['show_source'] ) && $source ) {
				$label = esc_html( $source );
				$out  .= ' <span class="smc-review-source">' . ( $link ? '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . $label . '</a>' : $label ) . '</span>';
			}
			$locs = $show_loc ? SMC_Location_Fields::location_names( $p->ID ) : '';
			if ( '' !== $locs ) {
				$out .= ' <span class="smc-review-location">' . $locs . '</span>';
			}
			if ( $yes( $a['show_date'] ) ) {
				$out .= ' <span class="smc-review-date">' . esc_html( get_the_date( 'F Y', $p ) ) . '</span>';
			}
			$out .= '</div></div>';
		}
		return $out . '</div>';
	}

	public static function stars( $rating ) {
		$rating = max( 0, min( 5, (int) $rating ) );
		return str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating );
	}

	private static function css() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style id="smc-reviews-css">'
			. '.smc-reviews{display:grid;gap:24px;grid-template-columns:repeat(var(--smc-review-cols,3),minmax(0,1fr))}'
			. '@media (max-width:767px){.smc-reviews{grid-template-columns:1fr}}'
			. '.smc-review-stars{color:#f5a623;letter-spacing:2px;margin-bottom:8px}'
			. '.smc-review-text p:last-child{margin-bottom:0}'
			. '.smc-review-meta{margin-top:12px;font-weight:600}'
			. '.smc-review-source,.smc-review-date{font-weight:400;opacity:.75}'
			. '.smc-review-source:before,.smc-review-date:before{content:"\00b7";margin:0 6px}'
			. '</style>';
	}

	/* ========== Creating reviews (imports) ========== */

	/**
	 * Fingerprint used to spot duplicates: letters and numbers only, lowercased, so
	 * "DOT B." / “These people...” matches "Dot B." / "These people...".
	 */
	public static function hash( $name, $text ) {
		$norm = function ( $s ) {
			$s = strtolower( wp_strip_all_tags( self::strip_shortcodes_text( (string) $s ) ) );
			return preg_replace( '/[^\p{L}\p{N}]+/u', '', $s );
		};
		return md5( $norm( $name ) . '|' . $norm( $text ) );
	}

	/** Removes [shortcodes] (e.g. [elementor-template id="123"]) from text. */
	public static function strip_shortcodes_text( $s ) {
		return trim( preg_replace( '/\[\/?[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/i', '', (string) $s ) );
	}

	/** True when text has no actual words once shortcodes and tags are removed. */
	public static function is_empty_text( $s ) {
		return '' === preg_replace( '/[^\p{L}\p{N}]+/u', '', wp_strip_all_tags( self::strip_shortcodes_text( $s ) ) );
	}

	/** Trims wrapping quote marks from review text. */
	public static function clean_text( $s ) {
		$s = trim( (string) $s );
		$s = preg_replace( '/^[\s"\'“”‘’«»]+|[\s"\'“”‘’«»]+$/u', '', $s );
		return trim( $s );
	}

	/** "DOT B." -> "Dot B."; mixed-case names are left alone. Trailing colons removed. */
	public static function clean_name( $s ) {
		$s = trim( rtrim( trim( (string) $s ), ':' ) );
		if ( $s === strtoupper( $s ) && preg_match( '/[A-Z]{2}/', $s ) ) {
			$s = ucwords( strtolower( $s ) );
		}
		return $s;
	}

	/**
	 * Tidies existing reviews: trashes entries that are only shortcodes, merges duplicates
	 * (keeping the oldest and combining their locations), removes wrapping quotes and
	 * all-caps names, and refreshes duplicate fingerprints.
	 *
	 * @param bool $dry Count only.
	 * @return array Counts: not_reviews, duplicates, tidied.
	 */
	public static function cleanup( $dry ) {
		$counts = [ 'not_reviews' => 0, 'duplicates' => 0, 'tidied' => 0 ];
		$seen   = [];
		$posts  = get_posts( [ 'post_type' => self::TYPE, 'post_status' => [ 'publish', 'draft', 'pending', 'private' ], 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ] );

		foreach ( $posts as $p ) {
			if ( self::is_empty_text( $p->post_content ) ) {
				$counts['not_reviews']++;
				if ( ! $dry ) {
					wp_trash_post( $p->ID );
				}
				continue;
			}

			$name = self::clean_name( $p->post_title );
			$text = self::clean_text( $p->post_content );
			$hash = self::hash( $name, $text );

			if ( isset( $seen[ $hash ] ) ) {
				$counts['duplicates']++;
				if ( ! $dry ) {
					$locs = wp_get_object_terms( $p->ID, self::TAX, [ 'fields' => 'ids' ] );
					if ( ! is_wp_error( $locs ) && $locs ) {
						wp_set_object_terms( $seen[ $hash ], array_map( 'intval', $locs ), self::TAX, true );
					}
					wp_trash_post( $p->ID );
				}
				continue;
			}
			$seen[ $hash ] = $p->ID;

			if ( $name !== $p->post_title || $text !== $p->post_content ) {
				$counts['tidied']++;
				if ( ! $dry ) {
					wp_update_post( [ 'ID' => $p->ID, 'post_title' => $name, 'post_content' => $text ] );
				}
			}
			if ( ! $dry ) {
				update_post_meta( $p->ID, '_smc_review_hash', $hash );
			}
		}
		return $counts;
	}

	/**
	 * Creates one review. Returns the new ID, 0 if it's a duplicate, or a WP_Error.
	 *
	 * @param array $r name, text, rating, date (any strtotime format), source, link
	 * @param int[] $locations location term IDs
	 */
	public static function create( array $r, array $locations ) {
		$name = self::clean_name( sanitize_text_field( $r['name'] ?? '' ) );
		$text = self::clean_text( wp_kses_post( $r['text'] ?? '' ) );
		if ( self::is_empty_text( $text ) ) {
			return new WP_Error( 'empty', 'No review text (only a shortcode or blank).' );
		}
		if ( '' === $name ) {
			$name = 'Anonymous';
		}
		$hash     = self::hash( $name, $text );
		$existing = get_posts( [ 'post_type' => self::TYPE, 'post_status' => 'any', 'meta_key' => '_smc_review_hash', 'meta_value' => $hash, 'fields' => 'ids', 'numberposts' => 1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $existing ) {
			// Same review found on another location's page: add that location to it.
			if ( $locations ) {
				wp_set_object_terms( $existing[0], array_map( 'intval', $locations ), self::TAX, true );
			}
			return 0;
		}

		$rating = (int) round( (float) ( $r['rating'] ?? 5 ) );
		$rating = $rating >= 1 && $rating <= 5 ? $rating : 5;
		$ts     = ! empty( $r['date'] ) ? strtotime( (string) $r['date'] ) : false;
		$date   = $ts ? gmdate( 'Y-m-d 12:00:00', $ts ) : current_time( 'mysql' );

		$source = trim( sanitize_text_field( $r['source'] ?? '' ) );
		foreach ( self::SOURCES as $s ) {
			if ( '' !== $source && 0 === strcasecmp( $s, $source ) ) {
				$source = $s;
			}
		}

		$id = wp_insert_post(
			[
				'post_type'     => self::TYPE,
				'post_status'   => 'publish',
				'post_title'    => $name,
				'post_content'  => $text,
				'post_date'     => $date,
				'post_date_gmt' => get_gmt_from_date( $date ),
				'meta_input'    => [
					'rating'           => (string) $rating,
					'_rating'          => 'field_smc_review_rating',
					'review_date'      => gmdate( 'Ymd', strtotime( $date ) ),
					'_review_date'     => 'field_smc_review_date',
					'source'           => $source,
					'_source'          => 'field_smc_review_source',
					'review_link'      => esc_url_raw( (string) ( $r['link'] ?? '' ) ),
					'_review_link'     => 'field_smc_review_link',
					'_smc_review_hash' => $hash,
				],
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( $locations ) {
			wp_set_object_terms( $id, array_map( 'intval', $locations ), self::TAX );
		}
		return $id;
	}
}
