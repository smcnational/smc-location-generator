<?php
/**
 * 301 redirects for deleted and moved location pages.
 *
 * A redirect covers a whole section of the site: /oldtown/ and everything under it. With
 * "same page" on, /oldtown/services/implants/ goes to /springfield/services/implants/ when that page
 * exists, otherwise to the closest page above it, and finally /springfield/. Query strings are kept.
 *
 * Redirects only kick in when the URL would otherwise be a 404, so a real page at the same
 * address always wins (e.g. if a location is added back later).
 *
 * Created automatically:
 *  - when a location is deleted (Locations > All Locations > Delete), to the location or page picked there;
 *  - when a published location page's URL changes (its slug or parent is edited), from the old URL to the new one.
 *
 * Managed under Locations > Redirects. Also: wp smc location redirects.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Redirects {

	const OPTION = 'smc_location_redirects';
	const HITS   = 'smc_location_redirect_hits';
	const SLUG   = 'smc-location-redirects';
	const TAX    = 'location_category';
	const CAP    = 'manage_options';

	private static $old_paths = [];

	public static function init() {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect' ], 0 );
		add_action( 'pre_post_update', [ __CLASS__, 'remember_path' ], 10, 1 );
		add_action( 'post_updated', [ __CLASS__, 'path_changed' ], 10, 3 );
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 17 );
	}

	/* ========== Storage ========== */

	/** @return array from => [ to, same, reason, created ] with paths like "/springfield" and "/" for the homepage. */
	public static function all() {
		$r = get_option( self::OPTION, [] );
		return is_array( $r ) ? $r : [];
	}

	/** "/Springfield/services/" or a full URL on this site -> "/springfield/services". "" if it's not a path on this site. */
	public static function normalize( $path ) {
		$path = trim( (string) $path );
		if ( preg_match( '#^https?://#i', $path ) ) {
			$home = wp_parse_url( home_url() );
			$url  = wp_parse_url( $path );
			if ( strtolower( $url['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) ) {
				return '';
			}
			$path = $url['path'] ?? '/';
		}
		$path = strtolower( '/' . trim( (string) strtok( $path, '?#' ), '/' ) );
		return preg_replace( '#/+#', '/', $path );
	}

	/**
	 * Adds or replaces a redirect. Keeps things tidy: a redirect back to where something
	 * came from removes the old one, and redirects pointing at $from now point at $to.
	 */
	public static function add( $from, $to, $same = true, $reason = '' ) {
		$from = self::normalize( $from );
		$to   = self::normalize( $to );
		if ( '' === $from || '/' === $from || '' === $to || $from === $to ) {
			return false;
		}
		$all = self::all();
		unset( $all[ $to ] ); // Moving back: /springfield was redirected to /oldtown, now /oldtown goes to /springfield.
		foreach ( $all as $f => $r ) {
			if ( $r['to'] === $from || 0 === strpos( $r['to'], $from . '/' ) ) {
				$all[ $f ]['to'] = $to . substr( $r['to'], strlen( $from ) );
			}
			if ( $all[ $f ]['to'] === $f ) {
				unset( $all[ $f ] );
			}
		}
		$all[ $from ] = [ 'to' => $to, 'same' => (bool) $same, 'reason' => (string) $reason, 'created' => time() ];
		ksort( $all );
		update_option( self::OPTION, $all, false );
		return true;
	}

	public static function remove( $from ) {
		$all = self::all();
		unset( $all[ self::normalize( $from ) ] );
		update_option( self::OPTION, $all, false );
	}

	/* ========== Redirecting ========== */

	public static function maybe_redirect() {
		if ( ! is_404() ) {
			return;
		}
		$all = self::all();
		if ( ! $all ) {
			return;
		}
		$path = self::normalize( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// Home might live in a subfolder (example.com/site/).
		$base = rtrim( self::normalize( home_url( '/' ) ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $base ) ), '/' );
		}
		$dest = self::destination( $path, $all );
		if ( null === $dest ) {
			return;
		}
		$url = home_url( user_trailingslashit( $dest ) );
		if ( '/' === $dest ) {
			$url = home_url( '/' );
		}
		$query = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $query ) {
			$url .= '?' . $query;
		}
		self::count_hit( $dest );
		wp_safe_redirect( $url, 301, 'SMC Locations' );
		exit;
	}

	/** Where a path should go, or null if no redirect covers it. The most specific redirect wins. */
	public static function destination( $path, array $all = null ) {
		$all  = $all ?? self::all();
		$from = null;
		foreach ( array_keys( $all ) as $f ) {
			if ( ( $path === $f || 0 === strpos( $path, $f . '/' ) ) && ( null === $from || strlen( $f ) > strlen( $from ) ) ) {
				$from = $f;
			}
		}
		if ( null === $from ) {
			return null;
		}
		$r    = $all[ $from ];
		$rest = trim( substr( $path, strlen( $from ) ), '/' );
		$to   = $r['to'];
		if ( $r['same'] && '' !== $rest ) {
			// Same page at the new address, or the closest page above it.
			$parts = explode( '/', $rest );
			while ( $parts ) {
				$try = rtrim( $to, '/' ) . '/' . implode( '/', $parts );
				$p   = get_page_by_path( ltrim( $try, '/' ), OBJECT, 'page' );
				if ( $p && 'publish' === $p->post_status ) {
					return $try;
				}
				array_pop( $parts );
			}
		}
		return $to;
	}

	private static function count_hit( $dest ) {
		$path = self::normalize( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$from = null;
		foreach ( array_keys( self::all() ) as $f ) {
			if ( ( $path === $f || 0 === strpos( $path, $f . '/' ) ) && ( null === $from || strlen( $f ) > strlen( $from ) ) ) {
				$from = $f;
			}
		}
		if ( null === $from ) {
			return;
		}
		$hits          = get_option( self::HITS, [] );
		$hits          = is_array( $hits ) ? $hits : [];
		$hits[ $from ] = [ 'n' => (int) ( $hits[ $from ]['n'] ?? 0 ) + 1, 'last' => time() ];
		update_option( self::HITS, $hits, false );
	}

	/* ========== Automatic: URL changes ========== */

	/** Before a page is saved, notes its URL if it's a published location page. */
	public static function remember_path( $post_id ) {
		$p = get_post( $post_id );
		if ( $p && 'page' === $p->post_type && 'publish' === $p->post_status && has_term( '', self::TAX, $p ) ) {
			self::$old_paths[ $post_id ] = '/' . get_page_uri( $p );
		}
	}

	/** After it's saved, adds a redirect if its URL changed (slug or parent). Covers its subpages too. */
	public static function path_changed( $post_id, $after, $before ) {
		if ( ! isset( self::$old_paths[ $post_id ] ) || 'publish' !== $after->post_status ) {
			return;
		}
		$old = self::$old_paths[ $post_id ];
		unset( self::$old_paths[ $post_id ] );
		clean_post_cache( $post_id );
		$new = '/' . get_page_uri( $post_id );
		if ( self::normalize( $old ) !== self::normalize( $new ) ) {
			self::add( $old, $new, true, 'URL changed' );
		}
	}

	/* ========== Delete screen ========== */

	/** The "Redirect its URLs" section of the delete screen. */
	public static function render_delete_choice( WP_Term $term, $path ) {
		$others = array_filter( SMC_Location_Manager::locations(), fn( $t ) => (int) $t->term_id !== (int) $term->term_id );
		?>
		<h2><label><input type="checkbox" name="redirect" value="1" checked onchange="document.getElementById('smc-redirect-opts').style.display=this.checked?'':'none'"> Redirect its URLs</label></h2>
		<p class="description">Adds a 301 redirect from <code><?php echo esc_html( $path ); ?>/</code> and every page under it, so links and search results keep working. Change or remove it later under Locations &gt; Redirects.</p>
		<div id="smc-redirect-opts">
			<p>
				<label for="redirect_to">Send visitors to</label><br>
				<select name="redirect_to" id="redirect_to" onchange="document.getElementById('smc-redirect-custom').style.display='custom'===this.value?'':'none'">
					<?php foreach ( $others as $t ) : ?>
						<?php $pg = SMC_Location_Manager::location_page( $t ); ?>
						<?php if ( $pg ) : ?>
							<option value="<?php echo esc_attr( '/' . get_page_uri( $pg ) ); ?>"><?php echo esc_html( $t->name . ' (/' . get_page_uri( $pg ) . '/)' ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
					<option value="/">Homepage</option>
					<option value="custom">Another page&hellip;</option>
				</select>
				<input name="redirect_custom" id="smc-redirect-custom" class="regular-text code" placeholder="/our-locations/" style="display:none">
			</p>
			<p><label><input type="checkbox" name="redirect_same" value="1" checked> Send each page to the same page at the new location when it exists</label><br>
				<span class="description">e.g. <code><?php echo esc_html( $path ); ?>/services/implants/</code> to the other location's Implants page. Pages it doesn't have go to its main page.</span></p>
		</div>
		<?php
	}

	/** Creates the redirect picked on the delete screen. */
	public static function from_delete_form( $path, $name ) {
		if ( empty( $_POST['redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		$to = sanitize_text_field( wp_unslash( $_POST['redirect_to'] ?? '/' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'custom' === $to ) {
			$to = sanitize_text_field( wp_unslash( $_POST['redirect_custom'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$to = self::normalize( $to );
		if ( '' === $to ) {
			$to = '/';
		}
		return self::add( $path, $to, '/' !== $to && ! empty( $_POST['redirect_same'] ), "Location deleted: $name" ) ? $to : false; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/* ========== Locations > Redirects ========== */

	public static function menu() {
		$hook = add_submenu_page( SMC_Location_Manager::SLUG, 'Location Redirects', 'Redirects', self::CAP, self::SLUG, [ __CLASS__, 'page' ] );
		add_action( "load-$hook", [ __CLASS__, 'handle' ] );
	}

	public static function url( $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	public static function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		check_admin_referer( 'smc_redirects' );
		if ( isset( $_POST['remove'] ) ) {
			self::remove( sanitize_text_field( wp_unslash( $_POST['remove'] ) ) );
			wp_safe_redirect( self::url( [ 'msg' => 'removed' ] ) );
			exit;
		}
		$from = self::normalize( sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ) );
		$to   = self::normalize( sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ) );
		$ok   = self::add( $from, $to, ! empty( $_POST['same'] ), 'Added by hand' );
		wp_safe_redirect( self::url( [ 'msg' => $ok ? 'added' : 'invalid' ] ) );
		exit;
	}

	public static function page() {
		$all  = self::all();
		$hits = get_option( self::HITS, [] );
		$msg  = sanitize_key( $_GET['msg'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$msgs = [
			'added'   => [ 'success', 'Redirect saved.' ],
			'removed' => [ 'success', 'Redirect removed.' ],
			'invalid' => [ 'error', 'Enter two different paths on this site, e.g. /oldtown/ and /springfield/. The homepage can\'t be redirected.' ],
		];
		?>
		<div class="wrap">
			<h1>Location Redirects</h1>
			<?php if ( isset( $msgs[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msgs[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $msgs[ $msg ][1] ); ?></p></div>
			<?php endif; ?>
			<p>301 redirects for deleted and moved location pages. Each one covers the page and everything under it. They're added automatically when a location is deleted or a location page's URL changes, and only apply when the old address has no page, so a real page there always wins.</p>

			<form method="post">
				<?php wp_nonce_field( 'smc_redirects' ); ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th>From</th><th>To</th><th>Same page</th><th>Why</th><th>Used</th><th></th></tr></thead>
					<tbody>
					<?php if ( ! $all ) : ?>
						<tr><td colspan="6">No redirects yet.</td></tr>
					<?php endif; ?>
					<?php foreach ( $all as $from => $r ) : ?>
						<?php
						$live = get_page_by_path( ltrim( $from, '/' ), OBJECT, 'page' );
						$h    = $hits[ $from ] ?? null;
						?>
						<tr>
							<td><code><?php echo esc_html( $from ); ?>/</code>
								<?php if ( $live && 'publish' === $live->post_status ) : ?><br><span class="description">Not in use: a published page is at this address now.</span><?php endif; ?></td>
							<td><a href="<?php echo esc_url( home_url( user_trailingslashit( $r['to'] ) ) ); ?>" target="_blank"><code><?php echo esc_html( '/' === $r['to'] ? '/' : $r['to'] . '/' ); ?></code></a></td>
							<td><?php echo $r['same'] ? 'Yes' : 'No'; ?></td>
							<td><?php echo esc_html( $r['reason'] ); ?><br><span class="description"><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $r['created'] ) ); ?></span></td>
							<td><?php echo $h ? esc_html( sprintf( '%d time(s), last %s ago', $h['n'], human_time_diff( (int) $h['last'] ) ) ) : 'Not yet'; ?></td>
							<td><button type="submit" name="remove" value="<?php echo esc_attr( $from ); ?>" class="button-link-delete" onclick="return confirm('Remove this redirect? Old links to <?php echo esc_js( $from ); ?>/ will show a 404.')">Remove</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</form>

			<h2>Add a redirect</h2>
			<form method="post">
				<?php wp_nonce_field( 'smc_redirects' ); ?>
				<p>
					<input name="from" class="regular-text code" placeholder="/oldtown/" required> &rarr;
					<input name="to" class="regular-text code" placeholder="/springfield/ or / for the homepage" required>
				</p>
				<p><label><input type="checkbox" name="same" value="1" checked> Send each page to the same page at the new address when it exists</label></p>
				<?php submit_button( 'Add redirect', 'secondary', 'submit', false ); ?>
			</form>
			<p class="description" style="margin-top:20px">If the site also uses the Redirection plugin or Yoast Premium redirects, those run first; these only catch what they don't.</p>
		</div>
		<?php
	}
}
