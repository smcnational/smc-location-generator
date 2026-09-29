<?php
/**
 * Locations (top-level admin menu)
 *
 *   Locations > All Locations   list of locations with edit, view and delete
 *   Locations > Add Location    clone an existing location (SMC_Location_Admin)
 *   Locations > Settings        display settings (SMC_Location_Settings)
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Manager {

	const SLUG = 'smc-locations';
	const CAP  = 'manage_options';
	const TAX  = 'location_category';

	private $error = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ], 9 );
	}

	public function menu() {
		$hook = add_menu_page( 'Locations', 'Locations', self::CAP, self::SLUG, [ $this, 'page' ], 'dashicons-location', 26 );
		add_submenu_page( self::SLUG, 'All Locations', 'All Locations', self::CAP, self::SLUG, [ $this, 'page' ] );
		add_action( "load-$hook", [ $this, 'handle' ] );
	}

	public static function url( $args = [] ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/* ========== Lookups ========== */

	/** Location terms, without Corporate. */
	public static function locations() {
		if ( ! taxonomy_exists( self::TAX ) ) {
			return [];
		}
		$terms = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false, 'orderby' => 'name' ] );
		return array_values( array_filter( is_wp_error( $terms ) ? [] : $terms, fn( $t ) => 'corporate' !== strtolower( $t->name ) ) );
	}

	/** The location's main page: the top page in its tree that has the location term. */
	public static function location_page( WP_Term $term ) {
		$page = get_page_by_path( $term->slug, OBJECT, 'page' );
		if ( $page && has_term( $term->term_id, self::TAX, $page ) ) {
			return $page;
		}
		$pages = get_posts(
			[
				'post_type'   => 'page',
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'numberposts' => -1,
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
				'tax_query'   => [ [ 'taxonomy' => self::TAX, 'terms' => $term->term_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		foreach ( $pages as $p ) {
			if ( ! $p->post_parent || ! has_term( $term->term_id, self::TAX, $p->post_parent ) ) {
				return $p;
			}
		}
		return null;
	}

	/** The location page plus all its descendants. */
	public static function tree( WP_Term $term ) {
		$page = self::location_page( $term );
		if ( ! $page ) {
			return [];
		}
		$kids = get_pages(
			[
				'child_of'    => $page->ID,
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			]
		) ?: [];
		return array_merge( [ $page ], $kids );
	}

	private static function meta( $tid, $k ) {
		return (string) get_term_meta( $tid, $k, true );
	}

	/* ========== Request handling ========== */

	public function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['smc_action'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		$action = sanitize_key( $_POST['smc_action'] );
		$tid    = absint( $_POST['term_id'] ?? 0 );
		$term   = get_term( $tid, self::TAX );
		if ( ! $term || is_wp_error( $term ) ) {
			$this->error = 'That location no longer exists.';
			return;
		}

		if ( 'save' === $action ) {
			check_admin_referer( "smc_loc_save_$tid" );
			$err = $this->save( $term );
			if ( $err ) {
				$this->error = $err;
				return;
			}
			wp_safe_redirect( self::url( [ 'action' => 'edit', 'term' => $tid, 'msg' => 'saved' ] ) );
			exit;
		}

		if ( 'launch' === $action ) {
			check_admin_referer( "smc_launch_$tid" );
			$do = sanitize_key( $_POST['launch_do'] ?? '' );
			SMC_Location_Launch::save_manual( $term, array_map( 'sanitize_key', (array) ( $_POST['done'] ?? [] ) ) );
			$args = [ 'action' => 'edit', 'term' => $tid ];
			if ( 'publish' === $do ) {
				$res = SMC_Location_Launch::publish( $term );
				if ( is_string( $res ) ) {
					$this->error = $res;
					return;
				}
				$args += [ 'msg' => 'launched', 'pages' => $res['pages'], 'templates' => $res['templates'] ];
				if ( $res['warnings'] ) {
					set_transient( 'smc_launch_warn_' . get_current_user_id(), $res['warnings'], 300 );
				}
			} elseif ( 'undo' === $do ) {
				$args += [ 'msg' => 'unlaunched', 'pages' => SMC_Location_Launch::undo( $term ) ];
			}
			wp_safe_redirect( self::url( $args ) . '#launch' );
			exit;
		}

		if ( 'delete' === $action ) {
			check_admin_referer( "smc_loc_delete_$tid" );
			$typed = trim( sanitize_text_field( wp_unslash( $_POST['confirm_name'] ?? '' ) ) );
			if ( 0 !== strcasecmp( $typed, $term->name ) ) {
				$this->error = "Type the location name (\"{$term->name}\") to confirm.";
				return;
			}
			$what   = array_map( 'sanitize_key', (array) ( $_POST['what'] ?? [] ) );
			if ( ! $what ) {
				$this->error = 'Nothing was selected to delete.';
				return;
			}
			$path   = self::location_path( $term );
			$counts = $this->delete( $term, $what );
			if ( is_string( $counts ) ) {
				$this->error = $counts;
				return;
			}
			$to = SMC_Location_Redirects::from_delete_form( $path, $term->name );
			if ( $to ) {
				$counts['redirect_from'] = $path;
				$counts['redirect_to']   = $to;
			}
			wp_safe_redirect( self::url( array_merge( [ 'msg' => 'deleted', 'name' => rawurlencode( $term->name ) ], $counts ) ) );
			exit;
		}
	}

	/* ========== Save ========== */

	private function save( WP_Term $term ) {
		$p   = wp_unslash( $_POST );
		$txt = fn( $k ) => trim( sanitize_text_field( $p[ $k ] ?? '' ) );
		$tid = $term->term_id;

		$name = $txt( 'name' );
		if ( '' === $name ) {
			return 'The location name is required.';
		}

		$map_raw = trim( (string) ( $p['map'] ?? '' ) );
		$map     = '';
		if ( '' !== $map_raw ) {
			$map = SMC_Location_Fields::map_src( $map_raw );
			if ( ! $map ) {
				return 'The map must be Google Maps embed code (Share > Embed a map > Copy HTML) or its embed URL. Share links like maps.app.goo.gl can\'t be embedded.';
			}
		}

		$email = sanitize_email( $txt( 'email' ) );
		if ( '' !== $txt( 'email' ) && ! is_email( $email ) ) {
			return 'That email address doesn\'t look right. Check it and save again.';
		}

		$form_raw = trim( (string) ( $p['form_embed'] ?? '' ) );
		$form     = '';
		if ( '' !== $form_raw ) {
			$form = SMC_Location_Fields::form_url( $form_raw );
			if ( ! $form ) {
				return 'The embedded form must be a JotForm link, form ID, or embed code from JotForm (Publish > Embed).';
			}
		}

		$phone  = $txt( 'phone' );
		$digits = preg_replace( '/\D/', '', $phone );
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		$tel = 10 === strlen( $digits ) ? substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 ) : $digits;

		$values = [
			'city_state'      => $txt( 'city_state' ),
			'address'         => implode( '<br>', array_filter( [ $txt( 'street' ), $txt( 'city_state_zip' ) ] ) ),
			'phone_label'     => $phone,
			'phone_link'      => $tel ? "tel:$tel" : '',
			'booking_label'   => $txt( 'booking_label' ),
			'booking_link'    => esc_url_raw( trim( (string) ( $p['booking_link'] ?? '' ) ) ),
			'booking_classes' => $txt( 'booking_classes' ),
			'email'           => $email,
			'email_label'     => $txt( 'email_label' ),
			'map_embed'       => $map,
			'form_embed'      => $form,
		];
		foreach ( SMC_Location_Fields::DAYS as $d => $label ) {
			$values[ "hours_$d" ] = trim( sanitize_text_field( $p['hours'][ $d ] ?? '' ) );
		}
		$values['hours_note'] = trim( sanitize_text_field( $p['hours']['note'] ?? '' ) );
		foreach ( array_keys( SMC_Location_Fields::SOCIAL ) as $k ) {
			$values[ $k ] = esc_url_raw( trim( (string) ( $p['social'][ $k ] ?? '' ) ) );
		}

		$slug = sanitize_title( $txt( 'slug' ) );
		if ( '' === $slug ) {
			$slug = $term->slug;
		}
		if ( $slug !== $term->slug ) {
			$other = get_term_by( 'slug', $slug, self::TAX );
			if ( $other && (int) $other->term_id !== (int) $tid ) {
				return "The slug \"$slug\" is already used by the {$other->name} location.";
			}
		}

		if ( $name !== $term->name || $slug !== $term->slug ) {
			$res = wp_update_term( $tid, self::TAX, [ 'name' => $name, 'slug' => $slug ] );
			if ( is_wp_error( $res ) ) {
				return 'Could not update the location: ' . $res->get_error_message();
			}
		}
		foreach ( $values as $k => $v ) {
			update_term_meta( $tid, $k, wp_slash( $v ) );
			$fk = SMC_Location_Fields::field_key( $k );
			if ( $fk && ! get_term_meta( $tid, "_$k", true ) ) {
				update_term_meta( $tid, "_$k", $fk );
			}
		}
		return '';
	}

	/* ========== Delete ========== */

	/**
	 * Everything that belongs to a location: its page tree, location and page-type terms,
	 * Theme Builder templates shown only for this location, and its menu.
	 */
	private function delete_plan( WP_Term $term ) {
		$plan = [ 'pages' => [], 'templates' => [], 'menus' => [], 'terms' => [], 'reviews' => [], 'team' => [], 'blocked' => '', 'shared' => [] ];

		// Team members who work only at this location. People at other offices too are kept.
		foreach ( get_posts( [ 'post_type' => SMC_Location_Team::TYPE, 'post_status' => 'any', 'numberposts' => -1, 'tax_query' => [ [ 'taxonomy' => self::TAX, 'terms' => $term->term_id ] ] ] ) as $m ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$locs = wp_get_object_terms( $m->ID, self::TAX, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $locs ) && 1 === count( $locs ) ) {
				$plan['team'][ $m->ID ] = SMC_Location_Team::name_with_credentials( $m->ID );
			}
		}

		// Reviews that belong only to this location. Reviews shared with other locations are kept.
		foreach ( get_posts( [ 'post_type' => SMC_Location_Reviews::TYPE, 'post_status' => 'any', 'numberposts' => -1, 'tax_query' => [ [ 'taxonomy' => self::TAX, 'terms' => $term->term_id ] ] ] ) as $r ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$locs = wp_get_object_terms( $r->ID, self::TAX, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $locs ) && 1 === count( $locs ) ) {
				$plan['reviews'][ $r->ID ] = $r->post_title . ': ' . wp_trim_words( wp_strip_all_tags( $r->post_content ), 10 );
			}
		}

		$tree     = self::tree( $term );
		$tree_ids = array_map( fn( $p ) => (int) $p->ID, $tree );
		foreach ( $tree as $p ) {
			$plan['pages'][ $p->ID ] = '/' . get_page_uri( $p ) . '/' . ( 'publish' !== $p->post_status ? " ({$p->post_status})" : '' );
		}
		if ( in_array( (int) get_option( 'page_on_front' ), $tree_ids, true ) ) {
			$plan['blocked'] = "The site's homepage is one of this location's pages. Set a different homepage under Settings > Reading first.";
		}

		// Terms: the location, plus page types used only by this location's pages.
		$plan['terms'][ self::TAX ][ $term->term_id ] = $term->name;
		if ( taxonomy_exists( 'page_type' ) && $tree_ids ) {
			$used = wp_get_object_terms( $tree_ids, 'page_type' );
			foreach ( is_wp_error( $used ) ? [] : $used as $pt ) {
				$objs = array_map( 'intval', (array) get_objects_in_term( $pt->term_id, 'page_type' ) );
				if ( ! array_diff( $objs, $tree_ids ) ) {
					$plan['terms']['page_type'][ $pt->term_id ] = $pt->name;
				}
			}
		}

		// Templates whose every display condition points at this location, or named after it with no conditions.
		$name_re = '/(?<![a-z])' . preg_quote( $term->name, '/' ) . '(?![a-z])/i';
		foreach ( get_posts( [ 'post_type' => 'elementor_library', 'numberposts' => -1, 'post_status' => 'any' ] ) as $tpl ) {
			$conds = array_filter( (array) get_post_meta( $tpl->ID, '_elementor_conditions', true ) );
			if ( $conds ) {
				$mine = 0;
				foreach ( $conds as $c ) {
					$parts = explode( '/', $c );
					$last  = count( $parts ) - 1;
					if ( $last >= 3 && ctype_digit( $parts[ $last ] ) ) {
						$id  = (int) $parts[ $last ];
						$sub = $parts[2];
						if ( ( 0 === strpos( $sub, 'in_' ) && isset( $plan['terms'][ substr( $sub, 3 ) ][ $id ] ) ) || ( 0 !== strpos( $sub, 'in_' ) && in_array( $id, $tree_ids, true ) ) ) {
							$mine++;
						}
					}
				}
				if ( $mine && $mine === count( $conds ) ) {
					$plan['templates'][ $tpl->ID ] = $tpl->post_title;
				} elseif ( $mine ) {
					$plan['shared'][ $tpl->ID ] = $tpl->post_title;
				}
			} elseif ( preg_match( $name_re, $tpl->post_title ) ) {
				$plan['templates'][ $tpl->ID ] = $tpl->post_title;
			}
		}

		foreach ( wp_get_nav_menus() as $menu ) {
			if ( 0 === strcasecmp( $menu->name, $term->name ) || $menu->slug === $term->slug ) {
				$plan['menus'][ $menu->term_id ] = $menu->name;
			}
		}
		return $plan;
	}

	/** The location's main page path, e.g. "/springfield", or "/<slug>" if it has no page. */
	public static function location_path( WP_Term $term ) {
		$page = self::location_page( $term );
		return '/' . ( $page ? get_page_uri( $page ) : $term->slug );
	}

	private function delete( WP_Term $term, $what ) {
		$plan = $this->delete_plan( $term );
		if ( $plan['blocked'] && in_array( 'pages', $what, true ) ) {
			return $plan['blocked'];
		}
		$counts = [ 'pages' => 0, 'templates' => 0, 'menus' => 0, 'terms' => 0, 'reviews' => 0, 'team' => 0 ];
		if ( in_array( 'team', $what, true ) ) {
			foreach ( array_keys( $plan['team'] ) as $id ) {
				if ( wp_trash_post( $id ) ) {
					$counts['team']++;
				}
			}
		}
		if ( in_array( 'reviews', $what, true ) ) {
			foreach ( array_keys( $plan['reviews'] ) as $id ) {
				if ( wp_trash_post( $id ) ) {
					$counts['reviews']++;
				}
			}
		}

		if ( in_array( 'pages', $what, true ) ) {
			// Children first so nothing gets re-parented on the way.
			foreach ( array_reverse( array_keys( $plan['pages'] ) ) as $id ) {
				if ( wp_trash_post( $id ) ) {
					$counts['pages']++;
				}
			}
		}
		if ( in_array( 'templates', $what, true ) ) {
			foreach ( array_keys( $plan['templates'] ) as $id ) {
				if ( wp_trash_post( $id ) ) {
					$counts['templates']++;
				}
			}
		}
		if ( in_array( 'menus', $what, true ) ) {
			foreach ( array_keys( $plan['menus'] ) as $id ) {
				if ( true === wp_delete_nav_menu( $id ) ) {
					$counts['menus']++;
				}
			}
		}
		if ( in_array( 'terms', $what, true ) ) {
			foreach ( $plan['terms'] as $tax => $ids ) {
				foreach ( array_keys( $ids ) as $id ) {
					if ( true === wp_delete_term( $id, $tax ) ) {
						$counts['terms']++;
					}
				}
			}
		}

		// Drop any clone-log entry for this location; there's nothing left to undo.
		$page = $plan['pages'] ? trim( strtok( reset( $plan['pages'] ), ' ' ), '/' ) : '';
		$log  = SMC_Location_Cloner::get_log();
		foreach ( $log as $slug => $e ) {
			if ( $e['path'] === $page || $slug === $term->slug ) {
				unset( $log[ $slug ] );
			}
		}
		update_option( SMC_Location_Cloner::LOG_OPTION, $log, false );

		SMC_Location_Cloner::refresh_caches();
		return $counts;
	}

	/* ========== Screens ========== */

	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap smc-loc">';
		$this->styles();

		if ( ! taxonomy_exists( self::TAX ) ) {
			echo '<h1>Locations</h1><div class="notice notice-error"><p>This site does not use the SMC location system (no <code>location_category</code> taxonomy).</p></div></div>';
			return;
		}

		if ( $this->error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $this->error ) . '</p></div>';
		}
		$msg = sanitize_key( $_GET['msg'] ?? '' );
		if ( 'saved' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>Location saved.</p></div>';
		} elseif ( 'launched' === $msg ) {
			printf( '<div class="notice notice-success is-dismissible"><p><strong>Location published.</strong> %d page(s) and %d template(s) are live.</p></div>', absint( $_GET['pages'] ?? 0 ), absint( $_GET['templates'] ?? 0 ) );
			foreach ( (array) get_transient( 'smc_launch_warn_' . get_current_user_id() ) as $w ) {
				if ( $w ) {
					echo '<div class="notice notice-warning"><p>' . esc_html( $w ) . '</p></div>';
				}
			}
			delete_transient( 'smc_launch_warn_' . get_current_user_id() );
		} elseif ( 'unlaunched' === $msg ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%d page(s) and template(s) put back to draft.</p></div>', absint( $_GET['pages'] ?? 0 ) );
		} elseif ( 'deleted' === $msg ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>Deleted %s: %d page(s), %d template(s), %d team member(s) and %d review(s) moved to the Trash, %d menu(s) and %d category term(s) deleted.</p></div>',
				esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['name'] ?? '' ) ) ) ),
				absint( $_GET['pages'] ?? 0 ),
				absint( $_GET['templates'] ?? 0 ),
				absint( $_GET['team'] ?? 0 ),
				absint( $_GET['reviews'] ?? 0 ),
				absint( $_GET['menus'] ?? 0 ),
				absint( $_GET['terms'] ?? 0 )
			);
			if ( ! empty( $_GET['redirect_to'] ) ) {
				$rt = sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) );
				printf(
					'<div class="notice notice-success is-dismissible"><p>Old URLs under <code>%s/</code> now redirect to <code>%s</code>. <a href="%s">Manage redirects</a></p></div>',
					esc_html( sanitize_text_field( wp_unslash( $_GET['redirect_from'] ?? '' ) ) ),
					esc_html( '/' === $rt ? '/' : $rt . '/' ),
					esc_url( SMC_Location_Redirects::url() )
				);
			}
		}

		$action = sanitize_key( $_GET['action'] ?? '' );
		$term   = isset( $_GET['term'] ) ? get_term( absint( $_GET['term'] ), self::TAX ) : null;

		if ( in_array( $action, [ 'edit', 'delete' ], true ) && ( ! $term || is_wp_error( $term ) ) ) {
			echo '<h1>Locations</h1><div class="notice notice-error"><p>That location no longer exists.</p></div>';
			$this->render_list();
		} elseif ( 'edit' === $action ) {
			$this->render_edit( $term );
		} elseif ( 'delete' === $action ) {
			$this->render_delete( $term );
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	private function render_list() {
		$locations = self::locations();
		$log       = SMC_Location_Cloner::get_log();
		?>
		<h1 class="wp-heading-inline">Locations</h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=smc-add-location' ) ); ?>" class="page-title-action">Add Location</a>
		<hr class="wp-header-end">

		<?php if ( ! $locations ) : ?>
			<p>No locations yet.</p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped smc-list">
			<thead><tr>
				<th>Location</th><th>Address</th><th>Phone &amp; email</th><th>Hours</th><th>Map</th><th>Social</th><th>Team</th><th>Reviews</th><th>Pages</th><th>Launch</th><th>Added</th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $locations as $t ) :
				$tid    = $t->term_id;
				$page   = self::location_page( $t );
				$count  = $page ? 1 + count( get_pages( [ 'child_of' => $page->ID, 'post_status' => [ 'publish', 'draft', 'pending', 'private' ] ] ) ?: [] ) : 0;
				$days   = 0;
				foreach ( array_keys( SMC_Location_Fields::DAYS ) as $d ) {
					$days += '' !== self::meta( $tid, "hours_$d" ) ? 1 : 0;
				}
				$social = 0;
				foreach ( array_keys( SMC_Location_Fields::SOCIAL ) as $k ) {
					$social += '' !== self::meta( $tid, $k ) ? 1 : 0;
				}
				$map   = '' !== SMC_Location_Fields::map_src( self::meta( $tid, 'map_embed' ) );
				$added = '';
				foreach ( $log as $e ) {
					if ( $page && get_page_uri( $page ) === $e['path'] ) {
						$added = wp_date( 'M j, Y', $e['created'] );
					}
				}
				$edit = self::url( [ 'action' => 'edit', 'term' => $tid ] );
				?>
				<tr>
					<td class="smc-name">
						<strong><a class="row-title" href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $t->name ); ?></a></strong>
						<?php if ( $page && 'publish' !== $page->post_status ) : ?>
							<span class="post-state"> &mdash; <?php echo esc_html( ucfirst( $page->post_status ) ); ?></span>
						<?php endif; ?>
						<div class="row-actions">
							<span><a href="<?php echo esc_url( $edit ); ?>">Edit</a> | </span>
							<?php if ( $page ) : ?>
								<span><a href="<?php echo esc_url( 'publish' === $page->post_status ? get_permalink( $page ) : get_preview_post_link( $page ) ); ?>" target="_blank">View</a> | </span>
								<span><a href="<?php echo esc_url( get_edit_post_link( $page->ID ) ); ?>">Edit page</a> | </span>
							<?php endif; ?>
							<span class="trash"><a href="<?php echo esc_url( self::url( [ 'action' => 'delete', 'term' => $tid ] ) ); ?>">Delete</a></span>
						</div>
					</td>
					<td><?php echo wp_kses( self::meta( $tid, 'address' ), [ 'br' => [] ] ) ?: '<span class="smc-missing">Missing</span>'; ?></td>
					<td><?php echo esc_html( self::meta( $tid, 'phone_label' ) ) ?: '<span class="smc-missing">Missing</span>'; ?><br><?php echo esc_html( self::meta( $tid, 'email' ) ) ?: '<span class="description">No email</span>'; ?></td>
					<td><?php echo $days ? esc_html( "$days of 7 days" ) : '<span class="smc-missing">Not set</span>'; ?></td>
					<td><?php echo $map ? 'Yes' : '<span class="smc-missing">Missing</span>'; ?></td>
					<td><?php echo $social ? esc_html( "$social link" . ( 1 === $social ? '' : 's' ) ) : '&mdash;'; ?></td>
					<?php $team = SMC_Location_Team::count( $tid ); ?>
					<td><?php echo $team ? '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . SMC_Location_Team::TYPE . '&smc_location=' . $tid ) ) . '">' . (int) $team . '</a>' : '<a class="smc-missing" href="' . esc_url( admin_url( 'post-new.php?post_type=' . SMC_Location_Team::TYPE . '&location=' . $tid ) ) . '">Add</a>'; ?></td>
					<?php $reviews = SMC_Location_Reviews::count( $tid ); ?>
					<td><?php echo $reviews ? '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . SMC_Location_Reviews::TYPE . '&smc_location=' . $tid ) ) . '">' . (int) $reviews . '</a>' : '<a class="smc-missing" href="' . esc_url( admin_url( 'post-new.php?post_type=' . SMC_Location_Reviews::TYPE . '&location=' . $tid ) ) . '">Add</a>'; ?></td>
					<td><?php echo $page ? '<code>/' . esc_html( get_page_uri( $page ) ) . '/</code> ' . (int) $count : '<span class="smc-missing">No page</span>'; ?></td>
					<td><?php echo SMC_Location_Launch::list_cell( $t ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo $added ? esc_html( $added ) : '&mdash;'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">Red items are missing and will show up empty wherever that location's shortcodes are used.</p>
		<?php
	}

	private function render_edit( WP_Term $term ) {
		$tid  = $term->term_id;
		$m    = fn( $k ) => self::meta( $tid, $k );
		$addr = preg_split( '#\s*<br\s*/?>\s*#i', $m( 'address' ) );
		$map  = SMC_Location_Fields::map_src( $m( 'map_embed' ) );
		$page = self::location_page( $term );
		// Re-show submitted values after a validation error.
		$post = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? wp_unslash( $_POST ) : null;
		$v    = function ( $k, $current ) use ( $post ) {
			return esc_attr( null !== $post && isset( $post[ $k ] ) && ! is_array( $post[ $k ] ) ? $post[ $k ] : $current );
		};
		$sub = function ( $group, $k, $current ) use ( $post ) {
			return esc_attr( null !== $post && isset( $post[ $group ][ $k ] ) ? $post[ $group ][ $k ] : $current );
		};
		?>
		<h1 class="wp-heading-inline">Edit <?php echo esc_html( $term->name ); ?></h1>
		<?php if ( $page ) : ?>
			<a href="<?php echo esc_url( 'publish' === $page->post_status ? get_permalink( $page ) : get_preview_post_link( $page ) ); ?>" class="page-title-action" target="_blank">View location</a>
		<?php endif; ?>
		<hr class="wp-header-end">
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; All locations</a></p>
		<div class="notice notice-info inline smc-sc-box"><p>
			Each field below shows the shortcode that displays it. Click a shortcode to copy it. Shortcodes show the details of the <em>page's</em> location, so the same shortcode works on every location's pages.
			Also: <code class="smc-copy" title="Click to copy">[location_url]</code> link to this location's main page &nbsp;&middot;&nbsp; <code class="smc-copy" title="Click to copy">[location_team type="doctors"]</code> its doctors &nbsp;&middot;&nbsp; <code class="smc-copy" title="Click to copy">[location_team type="team"]</code> its team &nbsp;&middot;&nbsp; <code class="smc-copy" title="Click to copy">[location_reviews]</code> its reviews.
		</p><p>
			In Yoast SEO titles and descriptions: <?php foreach ( array_keys( SMC_Location_Yoast::vars() ) as $yv ) : ?><code class="smc-copy" title="Click to copy">%%<?php echo esc_html( $yv ); ?>%%</code> <?php endforeach; ?>
		</p>
		<?php if ( SMC_Location_Schema::enabled() ) : ?>
			<?php $sp = self::location_page( $term ); ?>
			<details><summary>Schema for Google (built from the details below)</summary>
				<pre style="max-height:320px;overflow:auto;background:#f6f7f7;padding:10px;font-size:12px"><?php echo esc_html( wp_json_encode( SMC_Location_Schema::node( $term ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
				<?php if ( $sp && 'publish' === $sp->post_status ) : ?>
					<p><a href="<?php echo esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( get_permalink( $sp ) ) ); ?>" target="_blank" rel="noopener">Test the live page in Google's Rich Results Test</a></p>
				<?php endif; ?>
			</details>
		<?php endif; ?>
		</div>

		<?php SMC_Location_Launch::render( $term ); ?>

		<form method="post">
			<?php wp_nonce_field( "smc_loc_save_$tid" ); ?>
			<input type="hidden" name="smc_action" value="save">
			<input type="hidden" name="term_id" value="<?php echo (int) $tid; ?>">

			<h2>Location</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="name">Name</label></th>
					<td><input name="name" id="name" class="regular-text" required value="<?php echo $v( 'name', $term->name ); ?>">
					<p class="description">Renaming doesn't change page URLs or page content.</p><?php echo SMC_Location_Fields::help( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row"><label for="slug">Slug</label></th>
					<td><input name="slug" id="slug" class="regular-text code" value="<?php echo $v( 'slug', $term->slug ); ?>">
					<p class="description">The location's short name, used in <code>location="<?php echo esc_html( $term->slug ); ?>"</code> on shortcodes and to match locations when importing. Lowercase letters, numbers and hyphens. Changing it doesn't change page URLs; any shortcode already using <code>location="<?php echo esc_html( $term->slug ); ?>"</code> would need updating.</p></td></tr>
				<tr><th scope="row"><label for="street">Street address</label></th>
					<td><input name="street" id="street" class="regular-text" value="<?php echo $v( 'street', $addr[0] ?? '' ); ?>"><?php echo SMC_Location_Fields::help( 'street' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row"><label for="city_state_zip">City, state and zip</label></th>
					<td><input name="city_state_zip" id="city_state_zip" class="regular-text" value="<?php echo $v( 'city_state_zip', $addr[1] ?? '' ); ?>"><?php echo SMC_Location_Fields::help( 'city_state_zip' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row"><label for="city_state">City and state</label></th>
					<td><input name="city_state" id="city_state" class="regular-text" value="<?php echo $v( 'city_state', $m( 'city_state' ) ); ?>" placeholder="Springfield, ST"><?php echo SMC_Location_Fields::help( 'city_state' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row"><label for="phone">Phone</label></th>
					<td><input name="phone" id="phone" class="regular-text" value="<?php echo $v( 'phone', $m( 'phone_label' ) ); ?>">
					<p class="description">The tap-to-call link is updated to match.</p><?php echo SMC_Location_Fields::help( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row"><label for="email">Email</label></th>
					<td><input name="email" id="email" type="email" class="regular-text" value="<?php echo $v( 'email', $m( 'email' ) ); ?>">
					<?php echo SMC_Location_Fields::help( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row"><label for="email_label">Email button text</label></th>
					<td><input name="email_label" id="email_label" class="regular-text" value="<?php echo $v( 'email_label', $m( 'email_label' ) ); ?>" placeholder="<?php echo esc_attr( SMC_Location_Settings::get( 'email_label_default' ) ); ?>">
					<p class="description">Leave blank to use the site default (shown in grey).</p><?php echo SMC_Location_Fields::help( 'email_label' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			</table>

			<h2>Booking button</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="booking_label">Button text</label></th>
					<td><input name="booking_label" id="booking_label" class="regular-text" value="<?php echo $v( 'booking_label', $m( 'booking_label' ) ); ?>"><?php echo SMC_Location_Fields::help( 'booking_label' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row"><label for="booking_link">Booking link</label></th>
					<td><input name="booking_link" id="booking_link" type="url" class="large-text" value="<?php echo $v( 'booking_link', $m( 'booking_link' ) ); ?>"><?php echo SMC_Location_Fields::help( 'booking_link' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row"><label for="booking_classes">Button CSS classes</label></th>
					<td><input name="booking_classes" id="booking_classes" class="regular-text code" value="<?php echo $v( 'booking_classes', $m( 'booking_classes' ) ); ?>">
					<p class="description">Usually <code>jotformButton</code>, which opens the JotForm popup.</p><?php echo SMC_Location_Fields::help( 'booking_classes' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th scope="row"><label for="form_embed">Embedded form</label></th>
					<td><textarea name="form_embed" id="form_embed" rows="3" class="large-text code" placeholder="<?php echo esc_attr( $m( 'booking_link' ) ? 'Blank: embeds the booking form above' : 'https://form.jotform.com/...' ); ?>"><?php echo esc_textarea( null !== $post ? ( $post['form_embed'] ?? '' ) : SMC_Location_Fields::form_url( $m( 'form_embed' ) ) ); ?></textarea>
					<p class="description">The form for contact pages and popups. Paste the JotForm link, form ID, or embed code. Leave blank to embed the booking form.</p><?php echo SMC_Location_Fields::help( 'form_embed' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			</table>

			<h2>Hours</h2>
			<table class="form-table smc-hours" role="presentation">
				<?php foreach ( SMC_Location_Fields::DAYS as $d => $label ) : ?>
					<tr><th scope="row"><label for="hours_<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input name="hours[<?php echo esc_attr( $d ); ?>]" id="hours_<?php echo esc_attr( $d ); ?>" class="regular-text" value="<?php echo $sub( 'hours', $d, $m( "hours_$d" ) ); ?>" placeholder="<?php echo in_array( $d, [ 'saturday', 'sunday' ], true ) ? 'Closed' : '8:00 AM - 5:00 PM'; ?>"><?php echo SMC_Location_Fields::help( "hours_$d" ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<?php endforeach; ?>
				<tr><th scope="row"><label for="hours_note">Note</label></th>
					<td><input name="hours[note]" id="hours_note" class="large-text" value="<?php echo $sub( 'hours', 'note', $m( 'hours_note' ) ); ?>"><?php echo SMC_Location_Fields::help( 'hours_note' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			</table>
			<p class="description">Leave a day blank to leave it off the list, or type <code>Closed</code> to show it as closed.</p>

			<h2>Social links</h2>
			<table class="form-table" role="presentation">
				<?php foreach ( SMC_Location_Fields::SOCIAL as $k => $label ) : ?>
					<tr><th scope="row"><label for="social_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( 'google_business_url' === $k ? 'Google Business Profile' : $label ); ?></label></th>
						<td><input name="social[<?php echo esc_attr( $k ); ?>]" id="social_<?php echo esc_attr( $k ); ?>" type="url" class="large-text" value="<?php echo $sub( 'social', $k, $m( $k ) ); ?>"><?php echo SMC_Location_Fields::help( $k ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<?php endforeach; ?>
			</table>

			<h2>Google Map</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="map">Map embed code</label></th>
					<td><textarea name="map" id="map" rows="3" class="large-text code"><?php echo esc_textarea( null !== $post ? ( $post['map'] ?? '' ) : $map ); ?></textarea>
					<p class="description">In Google Maps, find the office, click <strong>Share &gt; Embed a map &gt; Copy HTML</strong>, and paste it here. Clear the box to remove the map. The height is set under <a href="<?php echo esc_url( SMC_Location_Settings::url() ); ?>">Locations &gt; Settings</a>.</p>
					<?php if ( $map ) : ?>
						<iframe src="<?php echo esc_url( $map ); ?>" class="smc-map-preview" loading="lazy" title="Current map"></iframe>
					<?php endif; ?>
					<?php echo SMC_Location_Fields::help( 'map' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			</table>

			<?php submit_button( 'Save location' ); ?>
		</form>

		<p class="smc-footer-links">
			<a href="<?php echo esc_url( get_edit_term_link( $tid, self::TAX, 'page' ) ); ?>">Open in the category editor</a> (for any other fields on this site)
			&nbsp;&middot;&nbsp;
			<a class="smc-delete-link" href="<?php echo esc_url( self::url( [ 'action' => 'delete', 'term' => $tid ] ) ); ?>">Delete this location</a>
		</p>
		<?php
	}

	private function render_delete( WP_Term $term ) {
		$tid  = $term->term_id;
		$plan = $this->delete_plan( $term );
		$list = function ( $items ) {
			if ( ! $items ) {
				return '<p class="description">None found.</p>';
			}
			$out = count( $items ) > 8 ? '<details><summary>Show all ' . count( $items ) . '</summary><ul class="ul-disc">' : '<ul class="ul-disc">';
			foreach ( $items as $label ) {
				$out .= '<li>' . esc_html( $label ) . '</li>';
			}
			return $out . ( count( $items ) > 8 ? '</ul></details>' : '</ul>' );
		};
		$term_labels = [];
		foreach ( $plan['terms'] as $tax => $ids ) {
			foreach ( $ids as $name ) {
				$term_labels[] = ( self::TAX === $tax ? 'Location: ' : 'Page type: ' ) . $name;
			}
		}
		?>
		<h1>Delete <?php echo esc_html( $term->name ); ?></h1>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; All locations</a></p>

		<?php if ( $plan['blocked'] ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $plan['blocked'] ); ?></p></div>
		<?php endif; ?>

		<div class="notice notice-warning inline">
			<p><strong>Before you delete:</strong> remove this location from the store locator and any other menus that link to it. Its page URLs are redirected below.</p>
		</div>

		<form method="post">
			<?php wp_nonce_field( "smc_loc_delete_$tid" ); ?>
			<input type="hidden" name="smc_action" value="delete">
			<input type="hidden" name="term_id" value="<?php echo (int) $tid; ?>">

			<h2><label><input type="checkbox" name="what[]" value="pages" <?php checked( ! $plan['blocked'] ); ?> <?php disabled( (bool) $plan['blocked'] ); ?>> Pages (<?php echo count( $plan['pages'] ); ?>)</label></h2>
			<p class="description">Moved to the Trash, so they can be restored from Pages &gt; Trash.</p>
			<?php echo $list( array_values( $plan['pages'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<h2><label><input type="checkbox" name="what[]" value="templates" checked> Theme Builder templates (<?php echo count( $plan['templates'] ); ?>)</label></h2>
			<p class="description">The header, footer, sub page and section templates used only by this location. Moved to the Trash.</p>
			<?php echo $list( array_values( $plan['templates'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php if ( $plan['shared'] ) : ?>
				<p class="description"><strong>Kept:</strong> these templates also show for other pages or locations, so remove this location from their conditions by hand: <?php echo esc_html( implode( ', ', $plan['shared'] ) ); ?>.</p>
			<?php endif; ?>

			<h2><label><input type="checkbox" name="what[]" value="team" checked> Team (<?php echo count( $plan['team'] ); ?>)</label></h2>
			<p class="description">Team members and doctors who work only at this location. Moved to the Trash. People who also work at other offices stay.</p>
			<?php echo $list( array_values( $plan['team'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<h2><label><input type="checkbox" name="what[]" value="reviews" checked> Reviews (<?php echo count( $plan['reviews'] ); ?>)</label></h2>
			<p class="description">Reviews assigned only to this location. Moved to the Trash. Reviews shared with other locations stay and just stop showing here.</p>
			<?php echo $list( array_values( $plan['reviews'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<h2><label><input type="checkbox" name="what[]" value="menus" checked> Menu (<?php echo count( $plan['menus'] ); ?>)</label></h2>
			<p class="description">Deleted permanently.</p>
			<?php echo $list( array_values( $plan['menus'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<h2><label><input type="checkbox" name="what[]" value="terms" checked> Location details (<?php echo count( $term_labels ); ?>)</label></h2>
			<p class="description">The location's address, phone, hours, social links and map, plus any page type used only by this location. Deleted permanently.</p>
			<?php echo $list( $term_labels ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<?php SMC_Location_Redirects::render_delete_choice( $term, self::location_path( $term ) ); ?>

			<h2>Confirm</h2>
			<p><label for="confirm_name">Type <strong><?php echo esc_html( $term->name ); ?></strong> to confirm:</label><br>
				<input name="confirm_name" id="confirm_name" class="regular-text" autocomplete="off" required></p>
			<?php submit_button( 'Delete location', 'delete button-primary smc-delete-btn', 'submit', true ); ?>
		</form>
		<?php
	}

	private function styles() {
		SMC_Location_Launch::styles();
		?>
		<style>
			.smc-loc .smc-list td { vertical-align: top; }
			.smc-loc .smc-list .smc-name { width: 22%; }
			.smc-loc .smc-missing { color: #b32d2e; }
			.smc-loc .smc-hours th { width: 120px; padding: 6px 10px 6px 0; }
			.smc-loc .smc-hours td { padding: 6px 10px; }
			.smc-loc .smc-map-preview { width: 100%; max-width: 600px; height: 250px; border: 0; margin-top: 10px; display: block; }
			.smc-loc .smc-delete-link, .smc-loc .smc-delete-link:hover { color: #b32d2e; }
			.smc-loc .smc-delete-btn { background: #b32d2e !important; border-color: #b32d2e !important; }
			.smc-loc .notice.inline { margin: 1em 0; }
			.smc-loc h2 label { font-size: inherit; }
			/* Every single-line field on the location screens is the same height. */
			.smc-loc input[type="text"], .smc-loc input[type="email"], .smc-loc input[type="url"], .smc-loc input[type="number"], .smc-loc input[type="search"], .smc-loc input:not([type]), .smc-loc select {
				height: 36px; min-height: 36px; line-height: 1.4; padding-top: 4px; padding-bottom: 4px; box-sizing: border-box; vertical-align: middle;
			}
		</style>
		<?php
	}
}
