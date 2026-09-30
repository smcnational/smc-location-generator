<?php
/**
 * Locations > New Build: the intake screen for single-location builds.
 * Fill in (or load a JSON file), Preview, then Build. Undo puts the template back.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Build_Page {

	const SLUG = 'smc-location-build';
	const CAP  = 'manage_options';

	private static $cfg    = [];
	private static $report = null;
	private static $error  = '';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 14 );
	}

	public static function menu() {
		$hook = add_submenu_page( SMC_Location_Manager::SLUG, 'New Build', 'New Build', self::CAP, self::SLUG, [ __CLASS__, 'page' ] );
		add_action( "load-$hook", [ __CLASS__, 'handle' ] );
	}

	public static function url( $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	/** The form's values as a build config. */
	private static function from_post() {
		$p   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$cfg = [];
		foreach ( [ 'location', 'practice', 'practice_from', 'domain', 'domain_from', 'city', 'state', 'phone', 'street', 'city_state_zip', 'email', 'email_label', 'booking_link', 'slug' ] as $k ) {
			$cfg[ $k ] = trim( sanitize_text_field( $p[ $k ] ?? '' ) );
		}
		foreach ( [ 'form', 'map' ] as $k ) {
			$cfg[ $k ] = trim( (string) ( $p[ $k ] ?? '' ) ); // Embed code; validated by the builder.
		}
		$cfg['doctors'] = trim( sanitize_textarea_field( $p['doctors'] ?? '' ) );
		foreach ( array_merge( array_keys( SMC_Location_Fields::DAYS ), [ 'note' ] ) as $d ) {
			$cfg['hours'][ $d ] = trim( sanitize_text_field( $p['hours'][ $d ] ?? '' ) );
		}
		foreach ( array_keys( SMC_Location_Fields::SOCIAL ) as $k ) {
			$cfg['social'][ $k ] = trim( sanitize_text_field( $p['social'][ $k ] ?? '' ) );
		}
		$cfg['replace'] = [];
		foreach ( preg_split( '/\r?\n/', (string) ( $p['replace'] ?? '' ) ) as $line ) {
			if ( false !== strpos( $line, '=>' ) ) {
				[ $a, $b ]                    = array_map( 'trim', explode( '=>', $line, 2 ) );
				$cfg['replace'][ sanitize_text_field( $a ) ] = sanitize_text_field( $b );
			}
		}
		return $cfg;
	}

	/** A JSON file (same keys as the form, or a clone config) as a build config. */
	private static function from_json( $json ) {
		$d = json_decode( $json, true );
		if ( ! is_array( $d ) ) {
			throw new Exception( 'That file isn\'t valid JSON.' );
		}
		if ( isset( $d['doctors'] ) && is_array( $d['doctors'] ) ) {
			$d['doctors'] = implode(
				"\n",
				array_map( fn( $x ) => is_array( $x ) ? trim( ( $x['name'] ?? '' ) . ( ! empty( $x['credentials'] ) ? ', ' . $x['credentials'] : '' ) ) : (string) $x, $d['doctors'] )
			);
		}
		return $d;
	}

	public static function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		check_admin_referer( 'smc_build' );
		$do = sanitize_key( $_POST['smc_do'] ?? '' );
		@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		try {
			if ( 'undo' === $do ) {
				$n = SMC_Location_Builder::undo();
				wp_safe_redirect( self::url( [ 'msg' => 'undone', 'n' => $n ] ) );
				exit;
			}
			if ( 'load' === $do ) {
				if ( empty( $_FILES['json']['tmp_name'] ) ) {
					throw new Exception( 'Choose a JSON file first.' );
				}
				self::$cfg = self::from_json( (string) file_get_contents( $_FILES['json']['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return;
			}
			if ( 'run' === $do ) {
				self::$cfg = self::from_json( wp_unslash( $_POST['cfg'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$report    = ( new SMC_Location_Builder( self::$cfg, false ) )->run();
				set_transient( 'smc_build_report_' . get_current_user_id(), $report, HOUR_IN_SECONDS );
				wp_safe_redirect( self::url( [ 'msg' => 'built' ] ) );
				exit;
			}
			// Preview.
			self::$cfg    = self::from_post();
			self::$report = ( new SMC_Location_Builder( self::$cfg, true ) )->run();
		} catch ( Exception $e ) {
			self::$error = $e->getMessage();
			if ( ! self::$cfg ) {
				self::$cfg = self::from_post();
			}
		}
	}

	/* ========== Screen ========== */

	public static function page() {
		$last = SMC_Location_Builder::last();
		$msg  = sanitize_key( $_GET['msg'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap smc-build">
			<h1>New Build</h1>
			<?php if ( self::$error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( self::$error ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'undone' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>Build undone. <?php echo (int) ( $_GET['n'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification ?> item(s) put back to the template.</p></div>
			<?php endif; ?>

			<?php
			if ( 'built' === $msg ) {
				self::render_result( get_transient( 'smc_build_report_' . get_current_user_id() ) );
			}
			if ( $last ) {
				self::render_last( $last );
				echo '</div>';
				return;
			}
			if ( self::$report ) {
				self::render_preview( self::$report );
			}
			self::render_form();
			?>
		</div>
		<?php
		self::styles();
	}

	private static function render_last( array $last ) {
		?>
		<div class="card smc-build-last">
			<h2>This site was built for <?php echo esc_html( $last['practice'] ); ?></h2>
			<p>On <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ) ); ?>, <?php echo esc_html( get_the_author_meta( 'display_name', (int) $last['user'] ) ); ?> converted the template's demo location into <?php echo esc_html( $last['city'] ); ?> (<?php echo count( (array) $last['posts'] ); ?> items changed).</p>
			<p>Next: set the logo, colors and fonts on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=smc-brand' ) ); ?>">Brand</a> screen, run <a href="<?php echo esc_url( admin_url( 'admin.php?page=smc-location-scan' ) ); ?>">Scan</a>, and work through the location's launch checklist under <a href="<?php echo esc_url( SMC_Location_Manager::url() ); ?>">All Locations</a>.</p>
			<form method="post" onsubmit="return confirm('Put every page, template, menu, the location and the site title back the way the template had them? Changes made by hand since the build are lost on those items.')">
				<?php wp_nonce_field( 'smc_build' ); ?>
				<input type="hidden" name="smc_do" value="undo">
				<?php submit_button( 'Undo the build', 'secondary', 'submit', false ); ?>
			</form>
			<p class="description">Only one build can be run on a site. Undo puts it back to the template so it can be run again.</p>
		</div>
		<?php
	}

	private static function render_result( $r ) {
		if ( ! is_array( $r ) ) {
			return;
		}
		echo '<div class="notice notice-success"><p><strong>Build finished.</strong> ' . (int) $r['changed'] . ' item(s) updated.</p></div>';
		foreach ( (array) $r['warnings'] as $w ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $w ) . '</p></div>';
		}
		if ( $r['leftovers'] ) {
			echo '<h2>Still showing demo text</h2><p>These items still contain a demo value. Fix them by hand, or Undo, add the text to Extra replacements and build again.</p>';
			echo '<table class="widefat striped"><thead><tr><th>Item</th><th>Type</th><th>Found</th><th>Where</th></tr></thead><tbody>';
			foreach ( $r['leftovers'] as $l ) {
				printf(
					'<tr><td><a href="%s">%s</a></td><td>%s</td><td><code>%s</code></td><td class="description">&hellip;%s&hellip;</td></tr>',
					esc_url( 'nav_menu_item' === $l['type'] ? admin_url( 'nav-menus.php' ) : admin_url( 'post.php?post=' . (int) $l['id'] . '&action=elementor' ) ),
					esc_html( $l['title'] ?: '#' . $l['id'] ),
					esc_html( $l['type'] ),
					esc_html( $l['value'] ),
					esc_html( $l['snippet'] )
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p><strong>No demo text left</strong> in pages, templates or menus.</p>';
		}
	}

	private static function render_preview( array $r ) {
		?>
		<div class="card smc-build-preview">
			<h2>Preview: nothing has changed yet</h2>
			<table class="widefat striped"><thead><tr><th></th><th>Template</th><th>Becomes</th></tr></thead><tbody>
				<?php foreach ( $r['summary'] as $row ) : ?>
					<tr><th><?php echo esc_html( $row[0] ); ?></th><td><?php echo esc_html( $row[1] ?: '(none)' ); ?></td><td><?php echo esc_html( $row[2] ?: '(none)' ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<?php foreach ( (array) $r['warnings'] as $w ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $w ); ?></p></div>
			<?php endforeach; ?>
			<p><strong><?php echo (int) $r['changed']; ?></strong> pages, templates, menus and other items will be updated.</p>
			<details><summary>Show them</summary>
				<table class="widefat striped"><thead><tr><th>Item</th><th>Type</th><th>Changes</th><th>URL</th></tr></thead><tbody>
					<?php foreach ( $r['items'] as $i ) : ?>
						<tr><td><?php echo esc_html( $i['new_title'] !== $i['title'] ? $i['title'] . ' > ' . $i['new_title'] : $i['title'] ); ?></td><td><?php echo esc_html( $i['type'] ); ?></td><td><?php echo (int) $i['changes']; ?></td><td><code><?php echo esc_html( $i['slug'] ); ?></code></td></tr>
					<?php endforeach; ?>
				</tbody></table>
			</details>
			<?php if ( $r['terms'] || $r['team'] ) : ?>
				<details><summary>Location, terms and team</summary><ul class="ul-disc">
					<?php foreach ( $r['terms'] as $t ) : ?><li><?php echo esc_html( "$t[0]: $t[1] > $t[2]" ); ?></li><?php endforeach; ?>
					<?php foreach ( $r['team'] as $t ) : ?><li><?php echo esc_html( "$t[0]: $t[1]" ); ?></li><?php endforeach; ?>
				</ul></details>
			<?php endif; ?>
			<form method="post" onsubmit="return confirm('Run the build? Undo is available afterwards.')">
				<?php wp_nonce_field( 'smc_build' ); ?>
				<input type="hidden" name="smc_do" value="run">
				<input type="hidden" name="cfg" value="<?php echo esc_attr( wp_json_encode( self::$cfg ) ); ?>">
				<?php submit_button( 'Build the site', 'primary', 'submit', false ); ?>
				<span class="description">Or change the details below and preview again.</span>
			</form>
		</div>
		<?php
	}

	private static function render_form() {
		$c     = self::$cfg;
		$v     = fn( $k ) => esc_attr( is_scalar( $c[ $k ] ?? '' ) ? (string) ( $c[ $k ] ?? '' ) : '' );
		$locs  = SMC_Location_Manager::locations();
		$demo  = SMC_Location_Builder::demo_term( (string) ( $c['location'] ?? '' ) ) ?: ( $locs[0] ?? null );
		$m     = fn( $k ) => $demo ? (string) get_term_meta( $demo->term_id, $k, true ) : '';
		$repl  = '';
		foreach ( (array) ( $c['replace'] ?? [] ) as $a => $b ) {
			$repl .= "$a => $b\n";
		}
		?>
		<p>Turns the template's demo location into the client's practice: every page, template, header, footer and menu gets the practice's name, city, phone, address and email, the location gets its details, and the site title changes. <strong>Preview</strong> first; nothing changes until you click <strong>Build the site</strong>, and it can be undone. For practices with more than one office, build the first one here, then use <a href="<?php echo esc_url( admin_url( 'admin.php?page=smc-add-location' ) ); ?>">Add Location</a> for the others.</p>

		<form method="post" enctype="multipart/form-data" class="smc-build-load">
			<?php wp_nonce_field( 'smc_build' ); ?>
			<input type="hidden" name="smc_do" value="load">
			<strong>Load from a file:</strong> <input type="file" name="json" accept=".json,application/json">
			<?php submit_button( 'Load', 'secondary', 'submit', false ); ?>
			<span class="description">A JSON file with the same fields (a clone config works too). It fills in the form below.</span>
		</form>

		<?php if ( ! $locs ) : ?>
			<div class="notice notice-error inline"><p>This site has no location yet. The template needs one demo location (Locations &gt; All Locations) with its details filled in.</p></div>
			<?php return; ?>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'smc_build' ); ?>
			<input type="hidden" name="smc_do" value="preview">
			<h2>Template</h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="location">Demo location</label></th><td><select name="location" id="location">
					<?php foreach ( $locs as $t ) : ?>
						<option value="<?php echo (int) $t->term_id; ?>" <?php selected( $demo && $demo->term_id === $t->term_id ); ?>><?php echo esc_html( $t->name . ' (' . get_term_meta( $t->term_id, 'phone_label', true ) . ')' ); ?></option>
					<?php endforeach; ?>
				</select><p class="description">Its city, phone, address and email are what gets swapped out.</p></td></tr>
				<tr><th><label for="practice_from">Template practice name</label></th><td><input name="practice_from" id="practice_from" class="regular-text" value="<?php echo $v( 'practice_from' ); ?>" placeholder="<?php echo esc_attr( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>"><p class="description">Leave blank to use the current site title.</p></td></tr>
				<tr><th><label for="domain_from">Template domain</label></th><td><input name="domain_from" id="domain_from" class="regular-text code" value="<?php echo $v( 'domain_from' ); ?>" placeholder="<?php echo esc_attr( $demo ? SMC_Location_Builder::guess_domain( $demo ) : '' ); ?>"><p class="description">The demo domain used in email addresses and links. Leave blank to use the demo location's email domain.</p></td></tr>
			</table>

			<h2>Practice</h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="practice">Practice name</label></th><td><input name="practice" id="practice" class="regular-text" value="<?php echo $v( 'practice' ); ?>" required></td></tr>
				<tr><th><label for="domain">Domain</label></th><td><input name="domain" id="domain" class="regular-text code" value="<?php echo $v( 'domain' ); ?>" placeholder="clientpractice.com"></td></tr>
				<tr><th><label for="doctors">Doctors</label></th><td><textarea name="doctors" id="doctors" rows="4" class="large-text" placeholder="Dr. Jane Lee, DDS&#10;Dr. Sam Patel, DMD"><?php echo esc_textarea( (string) ( $c['doctors'] ?? '' ) ); ?></textarea>
					<p class="description">One per line: name, then credentials after a comma. They're added to Locations &gt; Team as doctors at this location, and the demo location's team is set to draft. Leave blank to keep the demo team for now. Photos, titles and bios are added under Team afterwards.</p></td></tr>
			</table>

			<h2>Office</h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="city">City</label></th><td><input name="city" id="city" class="regular-text" value="<?php echo $v( 'city' ); ?>" required></td></tr>
				<tr><th><label for="state">State</label></th><td><input name="state" id="state" class="small-text" maxlength="2" value="<?php echo $v( 'state' ); ?>" placeholder="ST"></td></tr>
				<tr><th><label for="phone">Phone</label></th><td><input name="phone" id="phone" class="regular-text" value="<?php echo $v( 'phone' ); ?>" placeholder="555-555-0100" required></td></tr>
				<tr><th><label for="street">Street address</label></th><td><input name="street" id="street" class="regular-text" value="<?php echo $v( 'street' ); ?>" placeholder="123 Main St" required></td></tr>
				<tr><th><label for="city_state_zip">City, state and zip</label></th><td><input name="city_state_zip" id="city_state_zip" class="regular-text" value="<?php echo $v( 'city_state_zip' ); ?>" placeholder="Springfield, ST 12345" required></td></tr>
				<tr><th><label for="email">Email</label></th><td><input name="email" id="email" class="regular-text" value="<?php echo $v( 'email' ); ?>" placeholder="<?php echo esc_attr( $m( 'email' ) ); ?>"></td></tr>
				<tr><th><label for="booking_link">Booking form link</label></th><td><input name="booking_link" id="booking_link" class="large-text code" value="<?php echo $v( 'booking_link' ); ?>" placeholder="https://form.jotform.com/..."></td></tr>
				<tr><th><label for="form">Embedded form</label></th><td><input name="form" id="form" class="large-text code" value="<?php echo esc_attr( (string) ( $c['form'] ?? '' ) ); ?>" placeholder="JotForm link, form ID or embed code"></td></tr>
				<tr><th><label for="map">Google Map</label></th><td><textarea name="map" id="map" rows="2" class="large-text code" placeholder='&lt;iframe src="https://www.google.com/maps/embed?pb=..."&gt;&lt;/iframe&gt;'><?php echo esc_textarea( (string) ( $c['map'] ?? '' ) ); ?></textarea></td></tr>
				<tr><th><label for="slug">URL slug</label></th><td><input name="slug" id="slug" class="regular-text code" value="<?php echo $v( 'slug' ); ?>" placeholder="city name"><p class="description">For the location's pages, e.g. <code>/springfield/</code>. Leave blank to use the city.</p></td></tr>
			</table>

			<h2>Hours</h2>
			<p class="description">Leave a day blank to keep the demo hours. <code>Closed</code> for closed days.</p>
			<table class="form-table" role="presentation">
				<?php foreach ( array_merge( SMC_Location_Fields::DAYS, [ 'note' => 'Note' ] ) as $d => $label ) : ?>
					<tr><th><label for="hours_<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input name="hours[<?php echo esc_attr( $d ); ?>]" id="hours_<?php echo esc_attr( $d ); ?>" class="regular-text" value="<?php echo esc_attr( (string) ( $c['hours'][ $d ] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $m( 'note' === $d ? 'hours_note' : "hours_$d" ) ); ?>"></td></tr>
				<?php endforeach; ?>
			</table>

			<h2>Social links</h2>
			<p class="description">Leave blank to keep the demo link, or type <code>none</code> to remove it.</p>
			<table class="form-table" role="presentation">
				<?php foreach ( SMC_Location_Fields::SOCIAL as $k => $label ) : ?>
					<tr><th><label for="social_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input name="social[<?php echo esc_attr( $k ); ?>]" id="social_<?php echo esc_attr( $k ); ?>" class="large-text code" value="<?php echo esc_attr( (string) ( $c['social'][ $k ] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $m( $k ) ); ?>"></td></tr>
				<?php endforeach; ?>
			</table>

			<h2>Extra replacements</h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="replace">Also swap</label></th><td><textarea name="replace" id="replace" rows="4" class="large-text code" placeholder="Downtown Springfield => Downtown Shelbyville"><?php echo esc_textarea( $repl ); ?></textarea>
					<p class="description">One per line as <code>old =&gt; new</code>, for other demo text in the template (a neighborhood, a demo doctor's name mentioned in page copy...).</p></td></tr>
			</table>
			<?php submit_button( 'Preview' ); ?>
		</form>
		<?php
	}

	private static function styles() {
		?>
		<style>
			.smc-build .card { max-width: 1000px; }
			.smc-build .smc-build-preview table, .smc-build .smc-build-preview details { margin: 10px 0; }
			.smc-build .smc-build-load { margin: 12px 0 4px; padding: 12px 14px; background: #fff; border: 1px solid #dcdcde; max-width: 1000px; }
		</style>
		<?php
	}
}
