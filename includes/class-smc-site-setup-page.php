<?php
/**
 * Locations > Site Setup
 *
 * Build checklist, then the setup form: Intake -> Preview (dry run) -> Apply -> Undo / Finalize.
 * Uses the same engine as "wp smc site".
 */

defined( 'ABSPATH' ) || exit;

class SMC_Site_Setup_Page {

	const SLUG = 'smc-site-setup';
	const CAP  = 'manage_options';

	private $state = [];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ], 9 );
	}

	public function menu() {
		$hook = add_submenu_page( SMC_Location_Manager::SLUG, 'Site Setup', 'Site Setup', self::CAP, self::SLUG, [ $this, 'page' ] );
		add_action( "load-$hook", [ $this, 'handle' ] );
	}

	public static function url( $args = [] ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/* ========== Requests ========== */

	public function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}

		if ( isset( $_GET['download'] ) && 'intake' === $_GET['download'] ) {
			check_admin_referer( 'smc_setup_download' );
			$this->download();
		}

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['smc_setup_action'] ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'admin' );
		$action = sanitize_key( $_POST['smc_setup_action'] );

		switch ( $action ) {
			case 'checklist':
				check_admin_referer( 'smc_setup_checklist' );
				update_option( SMC_Site_Setup::DONE, array_values( array_intersect( array_map( 'sanitize_key', (array) ( $_POST['done'] ?? [] ) ), array_keys( SMC_Site_Setup::MANUAL ) ) ), false );
				if ( ! empty( $_POST['rescan'] ) ) {
					delete_transient( 'smc_site_setup_scan' );
				}
				wp_safe_redirect( self::url() . '#checklist' );
				exit;

			case 'import':
				check_admin_referer( 'smc_setup_import' );
				$file = $_FILES['intake_file']['tmp_name'] ?? '';
				$json = $file && is_uploaded_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( ! is_array( $json ) ) {
					$this->state['error'] = 'That file isn\'t a valid intake JSON file.';
					return;
				}
				$this->state['form']   = SMC_Site_Setup::normalize( $json );
				$this->state['notice'] = 'Intake loaded. Check the form, then click Preview.';
				return;

			case 'preview':
			case 'edit':
				check_admin_referer( 'smc_setup_form' );
				$intake              = 'edit' === $action ? $this->decode() : $this->intake_from_post();
				$this->state['form'] = $intake;
				if ( 'preview' === $action ) {
					try {
						$run                   = new SMC_Site_Setup( $intake, true );
						$this->state['report'] = $run->run();
						$this->state['mode']   = 'preview';
					} catch ( Exception $e ) {
						$this->state['error'] = $e->getMessage();
					}
				}
				return;

			case 'apply':
				check_admin_referer( 'smc_setup_apply' );
				$intake = $this->decode();
				if ( ! $intake ) {
					$this->state['error'] = 'The preview expired. Preview again.';
					return;
				}
				try {
					$run    = new SMC_Site_Setup( $intake, false );
					$report = $run->run();
					delete_transient( 'smc_site_setup_scan' );
					set_transient( 'smc_setup_report_' . get_current_user_id(), $report, HOUR_IN_SECONDS );
					wp_safe_redirect( self::url( [ 'msg' => 'applied' ] ) );
					exit;
				} catch ( Exception $e ) {
					$this->state['error'] = $e->getMessage() . ( SMC_Site_Setup::get_log() ? ' Anything changed before the error can be undone below.' : '' );
					$this->state['form']  = SMC_Site_Setup::normalize( $intake );
				}
				return;

			case 'undo':
				check_admin_referer( 'smc_setup_undo' );
				try {
					$n = SMC_Site_Setup::undo();
					delete_transient( 'smc_site_setup_scan' );
					wp_safe_redirect( self::url( [ 'msg' => 'undone', 'posts' => $n['posts'], 'pages' => $n['pages'], 'items' => $n['menu_items'], 'team' => $n['team'] ] ) );
					exit;
				} catch ( Exception $e ) {
					$this->state['error'] = $e->getMessage();
				}
				return;

			case 'finalize':
				check_admin_referer( 'smc_setup_finalize' );
				SMC_Site_Setup::finalize();
				wp_safe_redirect( self::url( [ 'msg' => 'finalized' ] ) );
				exit;
		}
	}

	private function decode() {
		$v = json_decode( base64_decode( wp_unslash( $_POST['smc_setup_intake'] ?? '' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		return is_array( $v ) ? SMC_Site_Setup::normalize( $v ) : null;
	}

	private function intake_from_post() {
		$p    = (array) wp_unslash( $_POST['intake'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$all  = (array) ( $p['services_all'] ?? [] );
		$keep = (array) ( $p['services_keep'] ?? [] );
		$in   = [
			'site_title'    => $p['site_title'] ?? '',
			'practice_name' => $p['practice_name'] ?? '',
			'tagline'       => $p['tagline'] ?? '',
			'location'      => (array) ( $p['location'] ?? [] ),
			'doctors'       => preg_split( '/\R/', (string) ( $p['doctors'] ?? '' ) ),
			'services'      => [ 'remove' => array_values( array_diff( $all, $keep ) ) ],
			'replace'       => SMC_Site_Setup::parse_pairs( $p['replace'] ?? '' ),
			'protect'       => preg_split( '/\R/', (string) ( $p['protect'] ?? '' ) ),
			'from'          => (array) ( $p['from'] ?? [] ),
			'options'       => [
				'rename_urls' => ! empty( $p['options']['rename_urls'] ),
				'tag_pages'   => ! empty( $p['options']['tag_pages'] ),
			],
		];
		return SMC_Site_Setup::normalize( $in );
	}

	/** Intake template: blank fields, the starter's current values, and the site's service pages. */
	private function download() {
		$out           = SMC_Site_Setup::blank();
		$out['from']   = SMC_Site_Setup::detect();
		$out['doctors'] = [ [ 'name' => '', 'credentials' => '', 'title' => '' ] ];
		list( , $services ) = SMC_Site_Setup::services();
		$out['_services_available'] = array_map( fn( $s ) => $s[2], $services );
		$out['_help']  = 'Fill in the blank fields. "from" holds the starter\'s current values (change them if they\'re wrong). List services the practice does NOT offer under services.remove, using paths from _services_available. Keys starting with _ are ignored.';
		$host          = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="site-setup-' . $host . '.json"' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ========== Page ========== */

	public function page() {
		$locs = SMC_Site_Setup::locations();
		$log  = SMC_Site_Setup::get_log();
		?>
		<div class="wrap smc-loc smc-setup">
			<h1>Site Setup</h1>
			<p class="description" style="max-width:900px">Turns a starter template into a single-location client site: fills in the practice and location details, swaps the starter's city, phone, address and names everywhere, trims the services, and adds the doctors. Preview first; everything can be undone.</p>
			<?php
			$this->notices();
			SMC_Location_Launch::styles();
			$this->styles();

			if ( count( $locs ) > 1 ) {
				echo '<div class="notice notice-info inline"><p>This site has ' . count( $locs ) . ' locations. Site Setup is for single-location builds. Use <a href="' . esc_url( admin_url( 'admin.php?page=' . SMC_Location_Admin::SLUG ) ) . '">Add Location</a> and each location\'s launch checklist instead.</p></div>';
			}

			$this->render_checklist();

			if ( 'preview' === ( $this->state['mode'] ?? '' ) ) {
				$this->render_preview();
			} elseif ( count( $locs ) <= 1 ) {
				$this->render_form();
			}

			if ( $log ) {
				$this->render_undo( $log );
			}
			?>
		</div>
		<?php
	}

	private function notices() {
		if ( ! empty( $this->state['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $this->state['error'] ) . '</p></div>';
		}
		if ( ! empty( $this->state['notice'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $this->state['notice'] ) . '</p></div>';
		}
		$msg = sanitize_key( $_GET['msg'] ?? '' );
		if ( 'applied' === $msg ) {
			$r = get_transient( 'smc_setup_report_' . get_current_user_id() );
			echo '<div class="notice notice-success"><p><strong>Setup applied.</strong> Work through the checklist below. Everything can still be undone at the bottom of this page.</p></div>';
			if ( is_array( $r ) ) {
				$this->render_report( $r, true );
			}
		} elseif ( 'undone' === $msg ) {
			printf(
				'<div class="notice notice-success"><p>Setup undone: %d item(s) restored, %d service page(s) put back, %d menu item(s) restored, %d doctor(s) removed.</p></div>',
				absint( $_GET['posts'] ?? 0 ),
				absint( $_GET['pages'] ?? 0 ),
				absint( $_GET['items'] ?? 0 ),
				absint( $_GET['team'] ?? 0 )
			);
		} elseif ( 'finalized' === $msg ) {
			echo '<div class="notice notice-success"><p>Setup finalized. The backups were removed.</p></div>';
		}
	}

	/* ========== Checklist ========== */

	private function render_checklist() {
		$c      = SMC_Site_Setup::checklist();
		$icon   = [ 'ok' => 'yes-alt', 'warn' => 'warning', 'fail' => 'dismiss', 'info' => 'info-outline' ];
		$groups = [ 'setup' => 'Setup', 'location' => 'Location', 'content' => 'Content', 'brand' => 'Brand and people', 'seo' => 'SEO', 'manual' => 'Before launch' ];
		$sum    = $c['fails'] ? sprintf( '%d to do, %d to check.', $c['fails'], $c['warns'] ) : ( $c['warns'] ? sprintf( 'Nothing blocking. %d to check.', $c['warns'] ) : 'Ready to launch.' );
		?>
		<details class="smc-launch" id="checklist" <?php echo SMC_Site_Setup::get_log() || isset( $_GET['msg'] ) ? 'open' : ''; ?>>
			<summary><span class="smc-launch-title">Build checklist</span> <span class="smc-launch-sum <?php echo $c['fails'] ? 'is-fail' : 'is-ok'; ?>"><?php echo esc_html( $sum ); ?></span></summary>
			<form method="post">
				<?php wp_nonce_field( 'smc_setup_checklist' ); ?>
				<input type="hidden" name="smc_setup_action" value="checklist">
				<?php foreach ( $groups as $g => $label ) : ?>
					<?php $rows = array_filter( $c['items'], fn( $i ) => $g === $i['group'] ); ?>
					<?php if ( ! $rows ) { continue; } ?>
					<h3><?php echo esc_html( $label ); ?></h3>
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
					<button class="button" name="rescan" value="1">Refresh checks</button>
					<noscript><?php submit_button( 'Save checklist', 'secondary', 'submit', false ); ?></noscript>
				</div>
			</form>
		</details>
		<?php
	}

	/* ========== Form ========== */

	private function render_form() {
		$in   = $this->state['form'] ?? get_option( SMC_Site_Setup::INTAKE, null );
		$in   = is_array( $in ) ? SMC_Site_Setup::normalize( $in ) : SMC_Site_Setup::normalize( [] );
		$det  = SMC_Site_Setup::detect();
		$from = array_merge( $det, array_filter( (array) $in['from'], 'strlen' ) );
		$l    = $in['location'];
		$n    = fn( $k ) => 'intake[location][' . $k . ']';
		$ph   = fn( $v ) => '' !== (string) $v ? ' placeholder="' . esc_attr( 'Starter: ' . $v ) . '"' : '';
		$term = SMC_Site_Setup::location();
		$tm   = fn( $k ) => $term ? (string) get_term_meta( $term->term_id, $k, true ) : '';
		list( $root, $services ) = SMC_Site_Setup::services();
		$download = wp_nonce_url( self::url( [ 'download' => 'intake' ] ), 'smc_setup_download' );
		?>
		<div class="smc-setup-import">
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'smc_setup_import' ); ?>
				<input type="hidden" name="smc_setup_action" value="import">
				<strong>Have an intake file?</strong>
				<input type="file" name="intake_file" accept=".json,application/json" required>
				<button class="button">Load</button>
				<a class="button-link" href="<?php echo esc_url( $download ); ?>">Download a blank intake file</a> (JSON, with this starter's values and service pages filled in)
			</form>
		</div>

		<form method="post" class="smc-setup-form">
			<?php wp_nonce_field( 'smc_setup_form' ); ?>

			<h2>1. Practice</h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="smc-st">Site title</label></th><td><input id="smc-st" class="regular-text" name="intake[site_title]" value="<?php echo esc_attr( $in['site_title'] ); ?>"<?php echo $ph( $from['site_title'] ); // phpcs:ignore ?>><p class="description">Settings &gt; General. Shown by <code>[site_name]</code>.</p></td></tr>
				<tr><th><label for="smc-pn">Practice name</label></th><td><input id="smc-pn" class="regular-text" name="intake[practice_name]" value="<?php echo esc_attr( $in['practice_name'] ); ?>"<?php echo $ph( $from['practice_name'] ); // phpcs:ignore ?>><p class="description">Yoast's organization name, used by <code>[brand_name]</code> and the schema. Blank uses the site title.</p></td></tr>
				<tr><th><label for="smc-tl">Tagline</label></th><td><input id="smc-tl" class="regular-text" name="intake[tagline]" value="<?php echo esc_attr( $in['tagline'] ); ?>"> <span class="description">Optional</span></td></tr>
			</table>

			<h2>2. Location</h2>
			<p class="description">Blank fields keep the starter's value (shown in grey).</p>
			<table class="form-table" role="presentation">
				<tr><th>City and state</th><td>
					<input class="regular-text" name="<?php echo esc_attr( $n( 'city' ) ); ?>" value="<?php echo esc_attr( $l['city'] ); ?>" required<?php echo $ph( $from['city'] ); // phpcs:ignore ?>>
					<input class="small-text" name="<?php echo esc_attr( $n( 'state' ) ); ?>" value="<?php echo esc_attr( $l['state'] ); ?>" maxlength="2" placeholder="ST">
				</td></tr>
				<tr><th>Street</th><td><input class="regular-text" name="<?php echo esc_attr( $n( 'street' ) ); ?>" value="<?php echo esc_attr( $l['street'] ); ?>"<?php echo $ph( $from['street'] ); // phpcs:ignore ?>> Zip <input class="small-text" style="width:80px" name="<?php echo esc_attr( $n( 'zip' ) ); ?>" value="<?php echo esc_attr( $l['zip'] ); ?>"<?php echo $ph( $from['zip'] ); // phpcs:ignore ?>></td></tr>
				<tr><th>Phone</th><td><input class="regular-text" name="<?php echo esc_attr( $n( 'phone' ) ); ?>" value="<?php echo esc_attr( $l['phone'] ); ?>"<?php echo $ph( $from['phone'] ); // phpcs:ignore ?>></td></tr>
				<tr><th>Email</th><td><input type="email" class="regular-text" name="<?php echo esc_attr( $n( 'email' ) ); ?>" value="<?php echo esc_attr( $l['email'] ); ?>"<?php echo $ph( $from['email'] ); // phpcs:ignore ?>></td></tr>
				<tr><th>Booking button</th><td>
					<input class="regular-text" name="<?php echo esc_attr( $n( 'booking_link' ) ); ?>" value="<?php echo esc_attr( $l['booking_link'] ); ?>"<?php echo $ph( $from['booking_link'] ); // phpcs:ignore ?>>
					<input name="<?php echo esc_attr( $n( 'booking_label' ) ); ?>" value="<?php echo esc_attr( $l['booking_label'] ); ?>"<?php echo $ph( $tm( 'booking_label' ) ); // phpcs:ignore ?>>
					<p class="description">JotForm link, then the button text.</p>
				</td></tr>
				<tr><th>Embedded form</th><td><textarea class="large-text" rows="2" name="<?php echo esc_attr( $n( 'form' ) ); ?>" placeholder="JotForm link, ID or embed code (for [location_form])"><?php echo esc_textarea( $l['form'] ); ?></textarea></td></tr>
				<tr><th>Google Map</th><td><textarea class="large-text" rows="2" name="<?php echo esc_attr( $n( 'map' ) ); ?>" placeholder="Share &gt; Embed a map &gt; Copy HTML"><?php echo esc_textarea( $l['map'] ); ?></textarea></td></tr>
				<tr><th>Hours</th><td class="smc-setup-hours">
					<?php foreach ( SMC_Location_Fields::DAYS as $d => $label ) : ?>
						<label><span><?php echo esc_html( $label ); ?></span><input name="<?php echo esc_attr( $n( "hours][$d" ) ); ?>" value="<?php echo esc_attr( $l['hours'][ $d ] ); ?>"<?php echo $ph( $tm( "hours_$d" ) ); // phpcs:ignore ?>></label>
					<?php endforeach; ?>
					<label><span>Note</span><input name="<?php echo esc_attr( $n( 'hours][note' ) ); ?>" value="<?php echo esc_attr( $l['hours']['note'] ); ?>"<?php echo $ph( $tm( 'hours_note' ) ); // phpcs:ignore ?>></label>
				</td></tr>
				<tr><th>Social</th><td class="smc-setup-hours">
					<?php foreach ( SMC_Site_Setup::SOCIAL as $k => $field ) : ?>
						<label><span><?php echo esc_html( SMC_Location_Fields::SOCIAL[ $field ] ); ?></span><input type="url" name="<?php echo esc_attr( $n( "social][$k" ) ); ?>" value="<?php echo esc_attr( $l['social'][ $k ] ); ?>"<?php echo $ph( $tm( $field ) ); // phpcs:ignore ?>></label>
					<?php endforeach; ?>
				</td></tr>
			</table>

			<h2>3. Doctors</h2>
			<p class="description">One per line: <code>Name | Credentials | Job title</code>, e.g. <code>Dr. Jane Lee | DDS | General Dentist</code>. They're added under Locations &gt; Team; add photos and bios there.</p>
			<textarea class="large-text code" rows="3" name="intake[doctors]"><?php echo esc_textarea( implode( "\n", array_map( fn( $d ) => rtrim( implode( ' | ', [ $d['name'], $d['credentials'], $d['title'] ] ), ' |' ), $in['doctors'] ) ) ); ?></textarea>

			<h2>4. Services</h2>
			<?php if ( ! $root ) : ?>
				<p class="description">No services page found (looked for /<?php echo esc_html( implode( '/, /', SMC_Site_Setup::SERVICE_PARENTS ) ); ?>/).</p>
			<?php else : ?>
				<p class="description">Untick services the practice doesn't offer. They're set to draft (with the pages under them) and taken out of menus. <a href="#" onclick="document.querySelectorAll('.smc-setup-services input[type=checkbox]').forEach(c=>c.checked=true);return false;">Tick all</a></p>
				<ul class="smc-setup-services">
					<?php foreach ( $services as list( $p, $depth, $path ) ) : ?>
						<li style="margin-left:<?php echo (int) $depth * 22; ?>px">
							<input type="hidden" name="intake[services_all][]" value="<?php echo esc_attr( $path ); ?>">
							<label><input type="checkbox" name="intake[services_keep][]" value="<?php echo esc_attr( $path ); ?>" <?php checked( ! in_array( $path, $in['services']['remove'], true ) ); ?> data-path="<?php echo esc_attr( $path ); ?>"> <?php echo esc_html( $p->post_title ); ?></label>
							<?php if ( 'publish' !== $p->post_status ) : ?><span class="description">(<?php echo esc_html( $p->post_status ); ?>)</span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2>5. Starter values</h2>
			<p class="description">What the starter template uses now, read from its location and content. These are swapped for the new values everywhere. Correct any that are wrong; leave one blank to skip it.</p>
			<table class="form-table smc-setup-from" role="presentation">
				<?php
				$labels = [ 'city' => 'City', 'city_state' => 'City and state', 'street' => 'Street', 'csz' => 'City, state and zip', 'zip' => 'Zip', 'phone' => 'Phone', 'email' => 'Email', 'booking_link' => 'Booking link', 'site_title' => 'Site title', 'practice_name' => 'Practice name' ];
				foreach ( $labels as $k => $label ) :
					?>
					<tr><th><?php echo esc_html( $label ); ?></th><td><input class="regular-text" name="intake[from][<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $from[ $k ] ); ?>"></td></tr>
				<?php endforeach; ?>
			</table>

			<h2>6. Extra replacements</h2>
			<p class="description">Anything else the starter has typed in, one per line: <code>old =&gt; new</code>. For example a placeholder doctor (<code>Dr. John Smith =&gt; Dr. Jane Lee</code>), a neighborhood or a landmark.</p>
			<textarea class="large-text code" rows="4" name="intake[replace]"><?php echo esc_textarea( implode( "\n", array_map( fn( $a, $b ) => "$a => $b", array_keys( (array) $in['replace'] ), (array) $in['replace'] ) ) ); ?></textarea>
			<p><label>Never change (one per line, e.g. a street that shares the starter's city name):<br><textarea class="large-text code" rows="2" name="intake[protect]"><?php echo esc_textarea( implode( "\n", $in['protect'] ) ); ?></textarea></label></p>

			<h2>7. Options</h2>
			<p><label><input type="checkbox" name="intake[options][rename_urls]" value="1" <?php checked( $in['options']['rename_urls'] ); ?>> Rename page URLs that contain the starter's city (e.g. <code>/dentist-springfield/</code>), and the links to them</label></p>
			<p><label><input type="checkbox" name="intake[options][tag_pages]" value="1" <?php checked( $in['options']['tag_pages'] ); ?>> Tie every page to the location, so <code>[location]</code> shortcodes, schema and Yoast variables work on all of them</label></p>

			<p class="submit"><button class="button button-primary button-hero" name="smc_setup_action" value="preview">Preview</button> <span class="description">Nothing changes until you apply the preview.</span></p>
		</form>
		<script>
		// Unticking a service unticks the services under it.
		document.querySelectorAll('.smc-setup-services input[type=checkbox]').forEach(function (c) {
			c.addEventListener('change', function () {
				if (c.checked) { return; }
				document.querySelectorAll('.smc-setup-services input[data-path^="' + c.dataset.path + '/"]').forEach(function (x) { x.checked = false; });
			});
		});
		</script>
		<?php
	}

	/* ========== Preview and report ========== */

	private function render_preview() {
		$r      = $this->state['report'];
		$intake = $this->state['form'];
		$enc    = base64_encode( wp_json_encode( $intake ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		echo '<h2>Preview</h2><p>Nothing has changed yet. Check the plan, then apply it.</p>';
		$this->render_report( $r, false );
		?>
		<form method="post" style="margin:20px 0 30px">
			<input type="hidden" name="smc_setup_intake" value="<?php echo esc_attr( $enc ); ?>">
			<?php wp_nonce_field( 'smc_setup_apply' ); ?>
			<button class="button button-primary button-hero" name="smc_setup_action" value="apply" onclick="return confirm('Apply the setup to this site? It can be undone afterwards.')">Apply setup</button>
		</form>
		<form method="post">
			<input type="hidden" name="smc_setup_intake" value="<?php echo esc_attr( $enc ); ?>">
			<?php wp_nonce_field( 'smc_setup_form' ); ?>
			<button class="button" name="smc_setup_action" value="edit">Back to the form</button>
		</form>
		<?php
	}

	private function render_report( array $r, $done ) {
		$table = function ( $title, $head, $rows ) {
			if ( ! $rows ) {
				return;
			}
			echo '<h3>' . esc_html( $title ) . '</h3><table class="widefat striped smc-setup-table"><thead><tr>';
			foreach ( $head as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr>';
				foreach ( $row as $cell ) {
					echo '<td>' . esc_html( wp_strip_all_tags( str_replace( '<br>', ' / ', (string) $cell ) ) ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		};

		echo '<div class="smc-setup-report">';
		$table( 'Replacements', [ 'Item', 'Starter', 'New' ], $r['replacements'] );
		$table( 'Location details', [ 'Field', 'Now', 'New' ], array_map( fn( $x ) => [ $x[0], 'map_embed' === $x[0] && $x[1] ? '(map)' : $x[1], 'map_embed' === $x[0] ? '(new map)' : $x[2] ], $r['term'] ) );
		$table( 'Site settings', [ 'Setting', 'Now', 'New' ], $r['options'] );

		$types = [ 'page' => 'Page', 'post' => 'Post', 'elementor_library' => 'Template', 'nav_menu_item' => 'Menu item', 'e-landing-page' => 'Landing page' ];
		$rows  = array_map(
			fn( $p ) => [ $p['title'], $types[ $p['type'] ] ?? $p['type'], $p['count'], $p['slug'] ? "/{$p['slug'][0]}/ to /{$p['slug'][1]}/" : '' ],
			$r['posts']
		);
		$total = array_sum( wp_list_pluck( $r['posts'], 'count' ) );
		$table( sprintf( 'Content: %d replacement(s) in %d item(s)', $total, count( $r['posts'] ) ), [ 'Title', 'Type', 'Replacements', 'URL' ], $rows );

		$table( 'Set to draft (services not offered)', [ 'Page', 'URL' ], array_map( fn( $d ) => [ $d[1], '/' . $d[2] . '/' ], $r['drafted'] ) );
		$table( 'Menu items removed', [ 'Menu', 'Item' ], $r['menu_items'] );
		if ( $r['team'] ) {
			echo '<h3>Doctors ' . ( $done ? 'added' : 'to add' ) . '</h3><p>' . esc_html( implode( ', ', $r['team'] ) ) . '</p>';
		}
		if ( $r['tagged'] ) {
			echo '<p>' . (int) $r['tagged'] . ' page(s) ' . ( $done ? 'were' : 'will be' ) . ' tied to the location.</p>';
		}
		if ( $r['warnings'] ) {
			echo '<h3>Check these</h3><ul class="smc-setup-warn">';
			foreach ( array_unique( $r['warnings'] ) as $w ) {
				echo '<li>' . esc_html( $w ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $done && $r['leftovers'] ) {
			echo '<h3>Starter values still in the content</h3><ul class="smc-setup-warn">';
			foreach ( $r['leftovers'] as $v => $posts ) {
				echo '<li><code>' . esc_html( $v ) . '</code> in ' . esc_html( implode( ', ', $posts ) ) . '</li>';
			}
			echo '</ul><p class="description">Some may be on purpose. Otherwise add them under Extra replacements and run setup again.</p>';
		}
		echo '</div>';
	}

	private function render_undo( array $log ) {
		?>
		<div class="smc-setup-undo">
			<h2>Undo</h2>
			<p>Setup was applied <?php echo esc_html( human_time_diff( (int) ( $log['updated'] ?? $log['created'] ) ) ); ?> ago<?php echo count( (array) $log['posts'] ) ? ' (' . count( (array) $log['posts'] ) . ' item(s) changed)' : ''; ?>. Running it again keeps the original starter backup, so Undo always returns to the starter.</p>
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'smc_setup_undo' ); ?>
				<button class="button" name="smc_setup_action" value="undo" onclick="return confirm('Put the site back to the starter template? Edits made to changed pages since setup are lost.')">Undo setup</button>
			</form>
			<form method="post" style="display:inline;margin-left:8px">
				<?php wp_nonce_field( 'smc_setup_finalize' ); ?>
				<button class="button-link" name="smc_setup_action" value="finalize" onclick="return confirm('Keep the setup and delete the backups? Undo won\'t be possible afterwards.')">Finalize (remove backups)</button>
			</form>
		</div>
		<?php
	}

	private function styles() {
		?>
		<style>
			.smc-setup .smc-setup-import { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px; max-width: 868px; margin: 16px 0; }
			.smc-setup .smc-setup-import form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
			.smc-setup .smc-setup-form h2 { margin-top: 32px; padding-top: 16px; border-top: 1px solid #dcdcde; max-width: 900px; }
			.smc-setup .smc-setup-form textarea { max-width: 900px; }
			.smc-setup .smc-setup-hours { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 6px 16px; max-width: 720px; }
			.smc-setup .smc-setup-hours label { display: flex; align-items: center; gap: 8px; }
			.smc-setup .smc-setup-hours span { width: 80px; }
			.smc-setup .smc-setup-hours input { flex: 1; }
			.smc-setup .smc-setup-services { columns: 2; max-width: 900px; }
			.smc-setup .smc-setup-services li { break-inside: avoid; }
			.smc-setup .smc-setup-from th { padding: 8px 10px 8px 0; }
			.smc-setup .smc-setup-from td { padding: 4px 10px; }
			.smc-setup .smc-setup-table { max-width: 900px; margin-bottom: 8px; }
			.smc-setup .smc-setup-report h3 { margin: 22px 0 8px; }
			.smc-setup .smc-setup-warn { list-style: disc; margin-left: 20px; max-width: 900px; }
			.smc-setup .smc-setup-undo { margin-top: 36px; padding-top: 12px; border-top: 1px solid #dcdcde; max-width: 900px; }
		</style>
		<?php
	}
}
