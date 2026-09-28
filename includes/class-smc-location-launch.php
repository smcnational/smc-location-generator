<?php
/**
 * Launch checklist and "Publish location".
 *
 * Shown at the top of each location's edit screen (Locations > All Locations > Edit). Checks
 * the location's details, team, reviews, pages, Theme Builder templates, menu and store
 * locator. Red items block publishing, amber items are worth a look but don't block.
 * When nothing is red, "Publish location" publishes every draft or pending page in the
 * location's page tree, plus its draft Theme Builder templates, in one step.
 *
 * Also: wp smc location checklist <slug> / wp smc location publish <slug>
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Launch {

	const TAX         = 'location_category';
	const MANUAL_META = 'smc_launch_done';
	const LAUNCH_META = 'smc_launch';
	const UNDO_DAYS   = 14;

	/** Checks that have to be ticked by hand. */
	const MANUAL = [
		'store_locator' => 'Added to the store locator on the Our Locations page',
		'count_text'    => 'Location count text updated (e.g. "3 Locations" to "4 Locations"), or not needed',
		'reviewed'      => 'Pages reviewed on desktop and mobile',
	];

	const GROUPS = [
		'details'   => 'Details',
		'content'   => 'Team and reviews',
		'pages'     => 'Pages',
		'templates' => 'Header, footer and menu',
		'launch'    => 'Before launch',
	];

	/* ========== Checks ========== */

	/**
	 * Runs every check for a location.
	 *
	 * @param bool $light Skip the slower checks (leftover text), for the All Locations list.
	 * @return array {
	 *   items:     list of [ group, key, label, status ok|warn|fail|info, detail (HTML), manual bool, done bool ]
	 *   fails:     number of red items
	 *   warns:     number of amber items
	 *   publish:   [ pages => WP_Post[], templates => WP_Post[] ] that "Publish location" would publish
	 *   page:      main page or null
	 *   live:      bool, main page published and nothing left to publish
	 * }
	 */
	public static function checks( WP_Term $term, $light = false ) {
		$tid    = (int) $term->term_id;
		$m      = fn( $k ) => trim( (string) get_term_meta( $tid, $k, true ) );
		$items  = [];
		$add    = function ( $group, $key, $label, $status, $detail = '' ) use ( &$items ) {
			$items[] = compact( 'group', 'key', 'label', 'status', 'detail' ) + [ 'manual' => false, 'done' => false ];
		};
		$source = self::source_term( $term );

		/* Details */
		$addr = array_filter( preg_split( '#\s*<br\s*/?>\s*#i', $m( 'address' ) ) );
		$add( 'details', 'address', 'Street address, city, state and zip', count( $addr ) >= 2 ? 'ok' : 'fail', count( $addr ) >= 2 ? '' : 'Missing. Fill in both address lines below.' );
		$add( 'details', 'city_state', 'City and state', '' !== $m( 'city_state' ) ? 'ok' : 'fail', '' !== $m( 'city_state' ) ? '' : 'Missing.' );

		$phone = preg_replace( '/\D/', '', $m( 'phone_label' ) );
		if ( '' === $phone ) {
			$add( 'details', 'phone', 'Phone', 'fail', 'Missing.' );
		} elseif ( $source && preg_replace( '/\D/', '', (string) get_term_meta( $source->term_id, 'phone_label', true ) ) === $phone ) {
			$add( 'details', 'phone', 'Phone', 'fail', esc_html( "Same number as {$source->name}, the location this was copied from." ) );
		} else {
			$add( 'details', 'phone', 'Phone', 'ok' );
		}

		$booking = $m( 'booking_link' );
		if ( '' === $booking ) {
			$add( 'details', 'booking', 'Booking link', 'fail', 'Missing. The booking buttons are hidden until it\'s set.' );
		} elseif ( $source && untrailingslashit( (string) get_term_meta( $source->term_id, 'booking_link', true ) ) === untrailingslashit( $booking ) ) {
			$add( 'details', 'booking', 'Booking link', 'fail', esc_html( "Still {$source->name}'s booking form. Set up this office's JotForm and paste its link." ) );
		} else {
			$add( 'details', 'booking', 'Booking link', 'ok' );
		}

		$days = 0;
		foreach ( array_keys( SMC_Location_Fields::DAYS ) as $d ) {
			$days += '' !== $m( "hours_$d" ) ? 1 : 0;
		}
		$add( 'details', 'hours', 'Hours', $days ? 'ok' : 'fail', $days ? '' : 'No days set.' );
		$add( 'details', 'map', 'Google Map', '' !== SMC_Location_Fields::map_src( $m( 'map_embed' ) ) ? 'ok' : 'fail', '' !== SMC_Location_Fields::map_src( $m( 'map_embed' ) ) ? '' : 'Missing. Paste the embed code below.' );
		$add( 'details', 'email', 'Email', '' !== $m( 'email' ) ? 'ok' : 'warn', '' !== $m( 'email' ) ? '' : 'Not set. Email buttons are hidden.' );
		$add( 'details', 'gbp', 'Google Business Profile link', '' !== $m( 'google_business_url' ) ? 'ok' : 'warn', '' !== $m( 'google_business_url' ) ? '' : 'Not set.' );

		/* Team and reviews */
		$doctors = self::count_posts( SMC_Location_Team::TYPE, $tid, [ [ 'key' => 'team_type', 'value' => 'doctor' ] ] );
		$add_doc = esc_url( admin_url( 'post-new.php?post_type=' . SMC_Location_Team::TYPE . '&location=' . $tid ) );
		$add( 'content', 'doctors', 'Doctors', $doctors ? 'ok' : 'fail', $doctors ? sprintf( '%d published', $doctors ) : '<a href="' . $add_doc . '">Add this office\'s doctors</a> under Locations &gt; Team.' );
		$staff = self::count_posts( SMC_Location_Team::TYPE, $tid ) - $doctors;
		$add( 'content', 'team', 'Team members', $staff > 0 ? 'ok' : 'warn', $staff > 0 ? sprintf( '%d published', $staff ) : 'None yet. Fine if the site has no Meet the Team page.' );
		$reviews = SMC_Location_Reviews::count( $tid );
		$add( 'content', 'reviews', 'Reviews', $reviews ? 'ok' : 'warn', $reviews ? sprintf( '%d published', $reviews ) : '<a href="' . esc_url( admin_url( 'admin.php?page=smc-review-import' ) ) . '">Import</a> or add this office\'s reviews. Review sections stay empty until then.' );

		/* Pages */
		$page      = SMC_Location_Manager::location_page( $term );
		$tree      = self::tree_by_depth( $term );
		$to_pub    = array_values( array_filter( $tree, fn( $p ) => in_array( $p->post_status, [ 'draft', 'pending' ], true ) ) );
		if ( ! $page ) {
			$add( 'pages', 'main', 'Location page', 'fail', 'No page has this location. Add the location with Locations &gt; Add Location, or give its main page the location category.' );
		} else {
			$uri = '/' . get_page_uri( $page ) . '/';
			$add( 'pages', 'main', 'Location page', 'ok', '<code>' . esc_html( $uri ) . '</code> ' . esc_html( 'publish' === $page->post_status ? '(published)' : "({$page->post_status})" ) );
			$published = count( array_filter( $tree, fn( $p ) => 'publish' === $p->post_status ) );
			if ( $to_pub ) {
				$list = '';
				foreach ( $to_pub as $p ) {
					$list .= '<li><a href="' . esc_url( get_preview_post_link( $p ) ) . '" target="_blank">/' . esc_html( get_page_uri( $p ) ) . '/</a> <span class="description">' . esc_html( $p->post_status ) . '</span></li>';
				}
				$add( 'pages', 'drafts', 'Pages to publish', 'info', sprintf( '%d of %d published. These %d will be published:', $published, count( $tree ), count( $to_pub ) ) . '<details><summary>Show pages</summary><ul class="ul-disc">' . $list . '</ul></details>' );
			} else {
				$add( 'pages', 'drafts', 'Pages', 'ok', sprintf( 'All %d published.', $published ) );
			}
		}

		/* Templates */
		$tpl        = self::templates( $term, $tree );
		$tpl_to_pub = array_values( array_filter( $tpl['mine'], fn( $t ) => in_array( $t->post_status, [ 'draft', 'pending' ], true ) ) );
		foreach ( [ 'header' => 'Header', 'footer' => 'Footer' ] as $type => $label ) {
			$own = array_values( array_filter( $tpl['mine'], fn( $t ) => $type === $t->smc_type ) );
			if ( $own ) {
				$link = '<a href="' . esc_url( admin_url( 'post.php?post=' . $own[0]->ID . '&action=elementor' ) ) . '">' . esc_html( $own[0]->post_title ) . '</a>';
				$add( 'templates', $type, "$label template", 'ok', $link . ( 'publish' !== $own[0]->post_status ? ' (' . esc_html( $own[0]->post_status ) . ', published with the location)' : '' ) );
			} elseif ( ! empty( $tpl['global'][ $type ] ) ) {
				$add( 'templates', $type, "$label template", 'warn', 'None for this location, so it uses the site-wide ' . strtolower( $label ) . ' (' . esc_html( $tpl['global'][ $type ] ) . '). Fine if that\'s intended.' );
			} else {
				$add( 'templates', $type, "$label template", 'fail', 'No published ' . strtolower( $label ) . ' shows on this location\'s pages. Check the conditions in Templates &gt; Theme Builder.' );
			}
		}
		if ( $tpl_to_pub ) {
			$add( 'templates', 'tpl_drafts', 'Templates to publish', 'info', esc_html( implode( ', ', wp_list_pluck( $tpl_to_pub, 'post_title' ) ) ) );
		}
		$menu = null;
		foreach ( wp_get_nav_menus() as $nm ) {
			if ( 0 === strcasecmp( $nm->name, $term->name ) || $nm->slug === $term->slug ) {
				$menu = $nm;
			}
		}
		$add( 'templates', 'menu', 'Menu', $menu ? 'ok' : 'warn', $menu ? esc_html( $menu->name ) . ( $menu->count ? " ({$menu->count} items)" : ' (empty)' ) : 'No menu named after this location. Fine if its header uses a shared menu.' );

		/* Leftover text from the location this was copied from */
		if ( ! $light && $source ) {
			$hits = self::leftovers( $source->name, array_merge( $tree, $tpl['mine'] ) );
			if ( $hits ) {
				$list = '';
				foreach ( $hits as $h ) {
					$list .= '<li><a href="' . esc_url( admin_url( 'post.php?post=' . $h['id'] . '&action=elementor' ) ) . '">' . esc_html( $h['title'] ) . '</a>: <span class="description">&hellip;' . esc_html( $h['snippet'] ) . '&hellip;</span></li>';
				}
				$add( 'pages', 'leftovers', 'Text from ' . $source->name, 'warn', sprintf( '"%s" still appears on %d page(s) or template(s). Fine if it\'s on purpose (e.g. "our other office in %s").', esc_html( $source->name ), count( $hits ), esc_html( $source->name ) ) . '<details><summary>Show where</summary><ul class="ul-disc">' . $list . '</ul></details>' );
			} else {
				$add( 'pages', 'leftovers', 'Text from ' . $source->name, 'ok', 'None left.' );
			}
		}

		/* Before launch */
		$done    = (array) get_term_meta( $tid, self::MANUAL_META, true );
		$locator = self::store_locator( $term );
		foreach ( self::MANUAL as $key => $label ) {
			if ( 'store_locator' === $key && null !== $locator ) {
				$add( 'launch', $key, 'Store locator', $locator['found'] ? 'ok' : 'fail', esc_html( $locator['detail'] ) );
				continue;
			}
			$is      = ! empty( $done[ $key ] );
			$items[] = [ 'group' => 'launch', 'key' => $key, 'label' => $label, 'status' => $is ? 'ok' : 'fail', 'detail' => '', 'manual' => true, 'done' => $is ];
		}

		$fails = count( array_filter( $items, fn( $i ) => 'fail' === $i['status'] ) );
		$warns = count( array_filter( $items, fn( $i ) => 'warn' === $i['status'] ) );
		return [
			'items'   => $items,
			'fails'   => $fails,
			'warns'   => $warns,
			'publish' => [ 'pages' => $to_pub, 'templates' => $tpl_to_pub ],
			'page'    => $page,
			'live'    => $page && 'publish' === $page->post_status && ! $to_pub && ! $tpl_to_pub,
		];
	}

	/** Location term of the page this location was cloned from, if it was cloned and still exists. */
	private static function source_term( WP_Term $term ) {
		$page = SMC_Location_Manager::location_page( $term );
		if ( ! $page ) {
			return null;
		}
		$path = get_page_uri( $page );
		foreach ( SMC_Location_Cloner::get_log() as $e ) {
			if ( ( $e['path'] ?? '' ) !== $path || empty( $e['source'] ) ) {
				continue;
			}
			$src = get_page_by_path( $e['source'], OBJECT, 'page' );
			$ids = $src ? wp_get_post_terms( $src->ID, self::TAX ) : [];
			foreach ( is_wp_error( $ids ) ? [] : $ids as $t ) {
				if ( (int) $t->term_id !== (int) $term->term_id ) {
					return $t;
				}
			}
		}
		return null;
	}

	private static function count_posts( $type, $tid, $meta = [] ) {
		$args = [
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => [ [ 'taxonomy' => self::TAX, 'terms' => (int) $tid ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
		];
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return (int) ( new WP_Query( $args ) )->found_posts;
	}

	/** The location's page tree, parents before children. */
	private static function tree_by_depth( WP_Term $term ) {
		$tree  = SMC_Location_Manager::tree( $term );
		$depth = function ( $p ) {
			return count( get_post_ancestors( $p ) );
		};
		usort( $tree, fn( $a, $b ) => $depth( $a ) <=> $depth( $b ) ?: (int) $a->menu_order <=> (int) $b->menu_order );
		return $tree;
	}

	/**
	 * Theme Builder templates with an include condition for this location (its location term,
	 * a page type used by its pages, or one of its pages), plus the site-wide header and footer.
	 */
	private static function templates( WP_Term $term, array $tree ) {
		$tree_ids = array_map( fn( $p ) => (int) $p->ID, $tree );
		$terms    = [ self::TAX => [ (int) $term->term_id ] ];
		if ( taxonomy_exists( 'page_type' ) && $tree_ids ) {
			$pt = wp_get_object_terms( $tree_ids, 'page_type', [ 'fields' => 'ids' ] );
			$terms['page_type'] = is_wp_error( $pt ) ? [] : array_map( 'intval', $pt );
		}
		$out = [ 'mine' => [], 'global' => [] ];
		foreach ( get_posts( [ 'post_type' => 'elementor_library', 'numberposts' => -1, 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ] ] ) as $t ) {
			$type  = (string) get_post_meta( $t->ID, '_elementor_template_type', true );
			$conds = array_filter( (array) get_post_meta( $t->ID, '_elementor_conditions', true ) );
			$mine  = false;
			foreach ( $conds as $c ) {
				$parts = explode( '/', $c );
				if ( 'include' !== $parts[0] ) {
					continue;
				}
				if ( 'general' === ( $parts[1] ?? '' ) && 1 === count( $parts ) - 1 && 'publish' === $t->post_status && in_array( $type, [ 'header', 'footer' ], true ) ) {
					$out['global'][ $type ] = $t->post_title;
				}
				$last = count( $parts ) - 1;
				if ( $last < 3 || ! ctype_digit( $parts[ $last ] ) ) {
					continue;
				}
				$id  = (int) $parts[ $last ];
				$sub = $parts[2];
				if ( 0 === strpos( $sub, 'in_' ) ) {
					$mine = $mine || in_array( $id, $terms[ substr( $sub, 3 ) ] ?? [], true );
				} else {
					$mine = $mine || in_array( $id, $tree_ids, true );
				}
			}
			if ( $mine ) {
				$t->smc_type   = $type;
				$out['mine'][] = $t;
			}
		}
		return $out;
	}

	/** Pages or templates that still contain the old location's name, with a snippet of each. */
	private static function leftovers( $name, array $posts ) {
		$re   = '/(?<![a-z])' . preg_quote( $name, '/' ) . '(?![a-z])/i';
		$hits = [];
		foreach ( $posts as $p ) {
			$text = $p->post_title . ' ' . $p->post_content . ' ' . (string) get_post_meta( $p->ID, '_elementor_data', true );
			$text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( stripslashes( str_replace( [ '\n', '\r', '\t' ], ' ', $text ) ) ) ) ) );
			if ( preg_match( $re, $text, $mm, PREG_OFFSET_CAPTURE ) ) {
				$start  = max( 0, $mm[0][1] - 40 );
				$hits[] = [ 'id' => $p->ID, 'title' => $p->post_title, 'snippet' => mb_strcut( $text, $start, 100 ) ];
			}
		}
		return $hits;
	}

	/**
	 * Looks for the location in WP Store Locator or Agile Store Locator.
	 * Returns null when neither is installed (then it's a manual check).
	 */
	private static function store_locator( WP_Term $term ) {
		global $wpdb;
		$street = trim( (string) ( preg_split( '#\s*<br\s*/?>\s*#i', (string) get_term_meta( $term->term_id, 'address', true ) )[0] ?? '' ) );
		$needles = array_filter( [ $term->name, $street ] );

		if ( post_type_exists( 'wpsl_stores' ) ) {
			foreach ( get_posts( [ 'post_type' => 'wpsl_stores', 'post_status' => 'publish', 'numberposts' => -1 ] ) as $s ) {
				$hay = $s->post_title . ' ' . get_post_meta( $s->ID, 'wpsl_address', true ) . ' ' . get_post_meta( $s->ID, 'wpsl_city', true );
				foreach ( $needles as $n ) {
					if ( false !== stripos( $hay, $n ) ) {
						return [ 'found' => true, 'detail' => "In WP Store Locator as \"{$s->post_title}\"." ];
					}
				}
			}
			return [ 'found' => false, 'detail' => 'Not found in WP Store Locator (Store Locator > Add Store).' ];
		}

		$table = $wpdb->prefix . 'asl_stores';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $needles as $n ) {
				$like  = '%' . $wpdb->esc_like( $n ) . '%';
				$title = $wpdb->get_var( $wpdb->prepare( "SELECT title FROM `$table` WHERE title LIKE %s OR street LIKE %s OR city LIKE %s LIMIT 1", $like, $like, $like ) ); // phpcs:ignore
				if ( $title ) {
					return [ 'found' => true, 'detail' => "In Agile Store Locator as \"$title\"." ];
				}
			}
			return [ 'found' => false, 'detail' => 'Not found in Agile Store Locator.' ];
		}
		return null;
	}

	/* ========== Actions ========== */

	/** Saves the ticked manual checks. */
	public static function save_manual( WP_Term $term, array $keys ) {
		$done = [];
		foreach ( $keys as $k ) {
			if ( isset( self::MANUAL[ $k ] ) ) {
				$done[ $k ] = time();
			}
		}
		update_term_meta( $term->term_id, self::MANUAL_META, $done );
	}

	/**
	 * Publishes the location's draft and pending pages and templates.
	 * Returns [ pages => n, templates => n, warnings => [] ] or an error string.
	 */
	public static function publish( WP_Term $term ) {
		$c = self::checks( $term );
		if ( $c['fails'] ) {
			return sprintf( '%d item(s) on the launch checklist still need attention.', $c['fails'] );
		}
		if ( ! $c['publish']['pages'] && ! $c['publish']['templates'] ) {
			return 'Nothing to publish. Every page and template is already published.';
		}
		$ids = [];
		$n   = [ 'pages' => 0, 'templates' => 0 ];
		foreach ( [ 'templates', 'pages' ] as $kind ) {
			foreach ( $c['publish'][ $kind ] as $p ) {
				$res = wp_update_post( [ 'ID' => $p->ID, 'post_status' => 'publish' ], true );
				if ( ! is_wp_error( $res ) ) {
					$ids[ $p->ID ] = $p->post_status;
					$n[ $kind ]++;
				}
			}
		}
		update_term_meta( $term->term_id, self::LAUNCH_META, [ 'time' => time(), 'user' => get_current_user_id(), 'ids' => $ids ] );
		$n['warnings'] = SMC_Location_Cloner::refresh_caches();
		return $n;
	}

	/** Items the last "Publish location" published that are still published, if it was recent enough to undo. */
	public static function undoable( WP_Term $term ) {
		$l = get_term_meta( $term->term_id, self::LAUNCH_META, true );
		if ( ! is_array( $l ) || empty( $l['ids'] ) || time() - (int) $l['time'] > self::UNDO_DAYS * DAY_IN_SECONDS ) {
			return [];
		}
		return array_filter( $l['ids'], fn( $status, $id ) => 'publish' === get_post_status( $id ), ARRAY_FILTER_USE_BOTH );
	}

	/** Puts everything the last "Publish location" published back to how it was. */
	public static function undo( WP_Term $term ) {
		$n = 0;
		foreach ( self::undoable( $term ) as $id => $status ) {
			if ( ! is_wp_error( wp_update_post( [ 'ID' => $id, 'post_status' => $status ], true ) ) ) {
				$n++;
			}
		}
		delete_term_meta( $term->term_id, self::LAUNCH_META );
		SMC_Location_Cloner::refresh_caches();
		return $n;
	}

	/* ========== Edit screen ========== */

	public static function render( WP_Term $term ) {
		$tid  = (int) $term->term_id;
		$c    = self::checks( $term );
		$undo = self::undoable( $term );
		$np   = count( $c['publish']['pages'] );
		$nt   = count( $c['publish']['templates'] );
		$icon = [ 'ok' => 'yes-alt', 'warn' => 'warning', 'fail' => 'dismiss', 'info' => 'info-outline' ];

		if ( $c['live'] ) {
			$summary = 'Live. Everything is published.';
		} elseif ( $c['fails'] ) {
			$summary = sprintf( '%d to do before this location can be published.', $c['fails'] );
		} else {
			$summary = 'Ready to publish.';
		}
		?>
		<details class="smc-launch" id="launch" <?php echo $c['live'] && ! $undo ? '' : 'open'; ?>>
			<summary><span class="smc-launch-title">Launch checklist</span> <span class="smc-launch-sum <?php echo $c['fails'] ? 'is-fail' : 'is-ok'; ?>"><?php echo esc_html( $summary ); ?></span></summary>
			<form method="post" class="smc-launch-form">
				<?php wp_nonce_field( "smc_launch_$tid" ); ?>
				<input type="hidden" name="smc_action" value="launch">
				<input type="hidden" name="term_id" value="<?php echo (int) $tid; ?>">
				<?php foreach ( self::GROUPS as $g => $glabel ) : ?>
					<?php $rows = array_filter( $c['items'], fn( $i ) => $g === $i['group'] ); ?>
					<?php if ( ! $rows ) { continue; } ?>
					<h3><?php echo esc_html( $glabel ); ?></h3>
					<ul class="smc-launch-list">
						<?php foreach ( $rows as $i ) : ?>
							<li class="is-<?php echo esc_attr( $i['status'] ); ?>">
								<span class="dashicons dashicons-<?php echo esc_attr( $icon[ $i['status'] ] ); ?>"></span>
								<?php if ( $i['manual'] ) : ?>
									<label><input type="checkbox" name="done[]" value="<?php echo esc_attr( $i['key'] ); ?>" <?php checked( $i['done'] ); ?> onchange="this.form.submit()"> <?php echo esc_html( $i['label'] ); ?></label>
								<?php else : ?>
									<strong><?php echo esc_html( $i['label'] ); ?></strong>
									<?php if ( $i['detail'] ) : ?><span class="smc-launch-detail"><?php echo wp_kses_post( $i['detail'] ); ?></span><?php endif; ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>

				<div class="smc-launch-actions">
					<?php if ( $np || $nt ) : ?>
						<?php
						$what = trim( ( $np ? "$np page" . ( 1 === $np ? '' : 's' ) : '' ) . ( $np && $nt ? ' and ' : '' ) . ( $nt ? "$nt template" . ( 1 === $nt ? '' : 's' ) : '' ) );
						?>
						<button type="submit" name="launch_do" value="publish" class="button button-primary button-hero" <?php disabled( (bool) $c['fails'] ); ?> onclick="return confirm(<?php echo esc_attr( wp_json_encode( "Publish $what for {$term->name}? They go live right away." ) ); ?>)">Publish location</button>
						<p class="description">
							<?php if ( $c['fails'] ) : ?>
								Clear the red items first. Amber items are worth a look but don't block publishing.
							<?php else : ?>
								Publishes <?php echo esc_html( $what ); ?> in one step and clears the Elementor cache.
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( $undo ) : ?>
						<button type="submit" name="launch_do" value="undo" class="button" onclick="return confirm('Put the <?php echo count( $undo ); ?> page(s) and template(s) that were published back to draft?')">Undo publish</button>
						<span class="description">Puts the <?php echo count( $undo ); ?> item(s) published <?php echo esc_html( human_time_diff( (int) get_term_meta( $tid, self::LAUNCH_META, true )['time'] ) ); ?> ago back to draft. Available for <?php echo (int) self::UNDO_DAYS; ?> days.</span>
					<?php endif; ?>
				</div>
				<noscript><?php submit_button( 'Save checklist', 'secondary', 'submit', false ); ?></noscript>
			</form>
		</details>
		<?php
	}

	/** Short status for the All Locations list. */
	public static function list_cell( WP_Term $term ) {
		$c    = self::checks( $term, true );
		$link = esc_url( SMC_Location_Manager::url( [ 'action' => 'edit', 'term' => $term->term_id ] ) . '#launch' );
		if ( $c['live'] ) {
			return '<span class="smc-live">Live</span>';
		}
		if ( $c['fails'] ) {
			return '<a class="smc-missing" href="' . $link . '">' . (int) $c['fails'] . ' to do</a>';
		}
		return '<a href="' . $link . '"><strong>Ready</strong></a>';
	}

	public static function styles() {
		?>
		<style>
			.smc-loc .smc-launch { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 0 20px; margin: 16px 0 24px; max-width: 900px; }
			.smc-loc .smc-launch > summary { cursor: pointer; padding: 14px 0; font-size: 15px; }
			.smc-loc .smc-launch-title { font-weight: 600; }
			.smc-loc .smc-launch-sum { margin-left: 8px; }
			.smc-loc .smc-launch-sum.is-fail { color: #b32d2e; }
			.smc-loc .smc-launch-sum.is-ok { color: #007017; }
			.smc-loc .smc-launch h3 { margin: 18px 0 6px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #50575e; }
			.smc-loc .smc-launch-list { margin: 0; }
			.smc-loc .smc-launch-list li { display: flex; gap: 8px; align-items: flex-start; margin: 0; padding: 6px 0; border-top: 1px solid #f0f0f1; flex-wrap: wrap; }
			.smc-loc .smc-launch-list li:first-child { border-top: 0; }
			.smc-loc .smc-launch-list strong { min-width: 220px; }
			.smc-loc .smc-launch-detail { flex: 1; min-width: 260px; color: #50575e; }
			.smc-loc .smc-launch-detail ul { margin: 6px 0 0 18px; }
			.smc-loc .smc-launch-list .is-ok .dashicons { color: #00a32a; }
			.smc-loc .smc-launch-list .is-warn .dashicons { color: #dba617; }
			.smc-loc .smc-launch-list .is-fail .dashicons { color: #d63638; }
			.smc-loc .smc-launch-list .is-info .dashicons { color: #2271b1; }
			.smc-loc .smc-launch-actions { padding: 18px 0 20px; border-top: 1px solid #dcdcde; margin-top: 12px; }
			.smc-loc .smc-launch-actions .description { margin: 8px 0; }
			.smc-loc .smc-live { color: #007017; font-weight: 600; }
		</style>
		<?php
	}
}
