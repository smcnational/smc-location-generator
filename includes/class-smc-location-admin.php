<?php
/**
 * Locations > Add Location
 *
 * Form -> Preview (dry run) -> Create -> Undo, using the same engine as the
 * "wp smc location" commands.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Admin {

	const SLUG = 'smc-add-location';
	const CAP  = 'manage_options';

	/** @var array Result of the current request, rendered by page(). */
	private $state = [];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
	}

	public function menu() {
		$hook = add_submenu_page( SMC_Location_Manager::SLUG, 'Add Location', 'Add Location', self::CAP, self::SLUG, [ $this, 'page' ] );
		add_action( "load-$hook", [ $this, 'handle' ] );
	}

	/* ========== Request handling ========== */

	public function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['smc_loc_action'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		wp_raise_memory_limit( 'admin' );

		$action = sanitize_key( $_POST['smc_loc_action'] );

		if ( 'preview' === $action ) {
			check_admin_referer( 'smc_loc_preview' );
			$cfg = $this->config_from_form();
			$this->state['form'] = $cfg;
			try {
				$plan                  = new SMC_Location_Cloner( $cfg, true );
				$this->state['report'] = $plan->run();
				$this->state['cfg']    = $cfg;
				$this->state['mode']   = 'preview';
			} catch ( Exception $e ) {
				$this->state['error'] = $e->getMessage();
			}
			return;
		}

		if ( 'edit' === $action ) {
			check_admin_referer( 'smc_loc_preview' );
			$this->state['form'] = $this->config_from_form();
			return;
		}

		if ( 'create' === $action ) {
			check_admin_referer( 'smc_loc_create' );
			$cfg = json_decode( base64_decode( wp_unslash( $_POST['smc_loc_cfg'] ?? '' ) ), true );
			if ( ! is_array( $cfg ) ) {
				$this->state['error'] = 'The preview expired. Fill in the form and preview again.';
				return;
			}
			$runner = new SMC_Location_Cloner( $cfg, false );
			try {
				$this->state['report'] = $runner->run();
				$this->state['mode']   = 'created';
				$this->state['slug']   = $runner->target_slug();
			} catch ( Exception $e ) {
				$slug                 = $runner->target_slug();
				$this->state['error'] = $e->getMessage() . ( $slug && isset( SMC_Location_Cloner::get_log()[ $slug ] ) ? ' Anything created before the error is listed under Previous clones below and can be undone.' : '' );
				$this->state['form']  = $cfg;
			}
			return;
		}

		if ( 'undo' === $action ) {
			$slug = sanitize_title( wp_unslash( $_POST['smc_loc_slug'] ?? '' ) );
			check_admin_referer( 'smc_loc_undo_' . $slug );
			try {
				$counts               = SMC_Location_Cloner::undo( $slug );
				$this->state['notice'] = sprintf( 'Removed "%s": %d page(s), %d template(s), %d menu(s), %d term(s).', $slug, $counts['pages'], $counts['templates'], $counts['menus'], $counts['terms'] );
				foreach ( $counts['warnings'] as $w ) {
					$this->state['notice'] .= ' ' . $w;
				}
			} catch ( Exception $e ) {
				$this->state['error'] = $e->getMessage();
			}
		}
	}

	private function config_from_form() {
		$p   = wp_unslash( $_POST );
		$txt = fn( $k ) => sanitize_text_field( $p[ $k ] ?? '' );

		$cfg = [
			'source'         => sanitize_title( $p['source'] ?? '' ),
			'city'           => $txt( 'city' ),
			'phone'          => $txt( 'phone' ),
			'street'         => $txt( 'street' ),
			'city_state_zip' => $txt( 'city_state_zip' ),
			'booking_link'   => esc_url_raw( $p['booking_link'] ?? '' ),
			'email'          => sanitize_email( $p['email'] ?? '' ),
			'email_label'    => sanitize_text_field( $p['email_label'] ?? '' ),
			'form'           => trim( (string) ( $p['form'] ?? '' ) ),
			'status'         => in_array( $p['status'] ?? '', [ 'draft', 'pending', 'publish' ], true ) ? $p['status'] : 'draft',
		];
		if ( $txt( 'state' ) ) {
			$cfg['state'] = strtoupper( $txt( 'state' ) );
		}
		if ( $txt( 'slug' ) ) {
			$cfg['slug'] = sanitize_title( $txt( 'slug' ) );
		}
		if ( '' === $cfg['booking_link'] ) {
			unset( $cfg['booking_link'] );
		}
		if ( '' === $cfg['email'] ) {
			unset( $cfg['email'] );
		}
		if ( '' === $cfg['email_label'] ) {
			unset( $cfg['email_label'] );
		}
		if ( '' === $cfg['form'] ) {
			unset( $cfg['form'] );
		} else {
			$cfg['form'] = SMC_Location_Fields::form_url( $cfg['form'] ) ?: $cfg['form'];
		}

		// Hours and social links: blank keeps the copied value.
		$cfg['hours'] = [];
		foreach ( array_merge( array_keys( SMC_Location_Fields::DAYS ), [ 'note' ] ) as $d ) {
			$cfg['hours'][ $d ] = sanitize_text_field( $p['hours'][ $d ] ?? '' );
		}
		$cfg['social'] = [];
		foreach ( array_keys( SMC_Location_Fields::SOCIAL ) as $k ) {
			$cfg['social'][ $k ] = sanitize_text_field( $p['social'][ $k ] ?? '' );
		}
		$map        = trim( (string) ( $p['map'] ?? '' ) );
		$cfg['map'] = SMC_Location_Fields::map_src( $map ) ?: $map;

		// "old => new" per line.
		$replace = [];
		foreach ( preg_split( '/\R/', (string) ( $p['replace'] ?? '' ) ) as $line ) {
			if ( false !== strpos( $line, '=>' ) ) {
				list( $old, $new ) = array_map( 'trim', explode( '=>', $line, 2 ) );
				if ( '' !== $old ) {
					$replace[ $old ] = $new;
				}
			}
		}
		$cfg['replace'] = $replace;

		$lines = fn( $k ) => array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) ( $p[ $k ] ?? '' ) ) ) ) );
		$cfg['exclude'] = array_map( 'sanitize_text_field', $lines( 'exclude' ) );
		$cfg['protect'] = $lines( 'protect' );

		return $cfg;
	}

	/* ========== Page ========== */

	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = $this->state;
		echo '<div class="wrap smc-loc"><h1>Add Location</h1>';
		$this->styles();

		if ( ! taxonomy_exists( SMC_Location_Cloner::LOC_TAX ) ) {
			echo '<div class="notice notice-error"><p>This site does not use the SMC location system (no <code>location_category</code> taxonomy), so locations can\'t be cloned here.</p></div></div>';
			return;
		}

		if ( ! empty( $s['error'] ) ) {
			echo '<div class="notice notice-error"><p><strong>Could not continue:</strong> ' . esc_html( $s['error'] ) . '</p></div>';
		}
		if ( ! empty( $s['notice'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $s['notice'] ) . '</p></div>';
		}

		$mode = $s['mode'] ?? '';
		if ( 'preview' === $mode ) {
			$this->render_preview( $s['report'], $s['cfg'] );
		} elseif ( 'created' === $mode ) {
			$this->render_created( $s['report'], $s['slug'] );
		} else {
			$this->render_form( $s['form'] ?? [] );
		}

		$this->render_log();
		echo '</div>';
	}

	private function render_form( $v ) {
		$sources = $this->sources();
		$default = SMC_Location_Cloner::defaults();
		$val     = fn( $k, $d = '' ) => esc_attr( $v[ $k ] ?? $d );
		$replace = '';
		foreach ( $v['replace'] ?? [] as $o => $n ) {
			$replace .= "$o => $n\n";
		}
		?>
		<p>Creates a new location by copying an existing one: its pages, location details, header, footer, sub page template and menu. The city, phone and address are swapped automatically. Nothing is created until you review a preview.</p>
		<form method="post">
			<?php wp_nonce_field( 'smc_loc_preview' ); ?>
			<input type="hidden" name="smc_loc_action" value="preview">

			<h2>Copy from</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="source">Existing location</label></th>
					<td>
						<select name="source" id="source" required>
							<?php foreach ( $sources as $src ) : ?>
								<option value="<?php echo esc_attr( $src['slug'] ); ?>" data-current="<?php echo esc_attr( wp_json_encode( $src['current'] ) ); ?>" data-name="<?php echo esc_attr( $src['name'] ); ?>" <?php selected( $v['source'] ?? '', $src['slug'] ); ?>>
									<?php echo esc_html( "{$src['name']}  ({$src['pages']} pages, {$src['phone']})" ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">Pick the location most similar to the new one. Its current city, phone and address are swapped out automatically.</p>
					</td>
				</tr>
			</table>

			<h2>New location</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="city">City</label></th>
					<td><input name="city" id="city" class="regular-text" required value="<?php echo $val( 'city' ); ?>" placeholder="Springfield"></td></tr>
				<tr><th scope="row"><label for="state">State</label></th>
					<td><input name="state" id="state" class="small-text smc-copyable" data-field="state" maxlength="2" value="<?php echo $val( 'state' ); ?>" placeholder="ST">
					<p class="description">Leave blank to use the same state as the location you're copying.</p></td></tr>
				<tr><th scope="row"><label for="phone">Phone</label></th>
					<td><input name="phone" id="phone" class="regular-text" required value="<?php echo $val( 'phone' ); ?>" placeholder="555-555-0100"></td></tr>
				<tr><th scope="row"><label for="street">Street address</label></th>
					<td><input name="street" id="street" class="regular-text" required value="<?php echo $val( 'street' ); ?>" placeholder="123 Main St"></td></tr>
				<tr><th scope="row"><label for="city_state_zip">City, state and zip</label></th>
					<td><input name="city_state_zip" id="city_state_zip" class="regular-text" required value="<?php echo $val( 'city_state_zip' ); ?>" placeholder="Springfield, ST 12345"></td></tr>
				<tr><th scope="row"><label for="booking_link">Booking form link</label></th>
					<td><input name="booking_link" id="booking_link" type="url" class="large-text" value="<?php echo $val( 'booking_link' ); ?>" placeholder="https://form.jotform.com/...">
					<p class="description">The new office's JotForm. Leave blank to keep the copied location's form for now.</p></td></tr>
				<tr><th scope="row"><label for="email">Email</label></th>
					<td><input name="email" id="email" type="email" class="regular-text smc-copyable" data-field="email" value="<?php echo $val( 'email' ); ?>">
					<p class="description">Leave blank to keep the copied location's email (shown in grey) for now.</p></td></tr>
				<tr><th scope="row"><label for="email_label">Email button text</label></th>
					<td><input name="email_label" id="email_label" class="regular-text smc-copyable" data-field="email_label" value="<?php echo $val( 'email_label' ); ?>">
					<p class="description">Leave blank to keep the copied location's text (shown in grey). If that's blank too, the site default is used.</p></td></tr>
				<tr><th scope="row"><label for="form">Embedded form</label></th>
					<td><textarea name="form" id="form" rows="3" class="large-text code smc-copyable" data-field="form_embed"><?php echo esc_textarea( $v['form'] ?? '' ); ?></textarea>
					<p class="description">The JotForm for <code>[location_form]</code>: link, form ID or embed code. Leave blank to keep the copied location's form (shown in grey), or the booking form if it has none.</p></td></tr>
			</table>

			<h2>Hours</h2>
			<p class="description">Leave a day blank to keep the copied location's hours (shown in grey). Type <code>Closed</code> for closed days, or <code>none</code> to leave a day off entirely.</p>
			<table class="form-table smc-hours" role="presentation">
				<?php foreach ( SMC_Location_Fields::DAYS as $d => $label ) : ?>
					<tr><th scope="row"><label for="hours_<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input name="hours[<?php echo esc_attr( $d ); ?>]" id="hours_<?php echo esc_attr( $d ); ?>" class="regular-text smc-copyable" data-field="hours_<?php echo esc_attr( $d ); ?>" value="<?php echo esc_attr( $v['hours'][ $d ] ?? '' ); ?>"></td></tr>
				<?php endforeach; ?>
				<tr><th scope="row"><label for="hours_note">Note</label></th>
					<td><input name="hours[note]" id="hours_note" class="large-text smc-copyable" data-field="hours_note" value="<?php echo esc_attr( $v['hours']['note'] ?? '' ); ?>"></td></tr>
			</table>

			<h2>Social links</h2>
			<p class="description">Leave blank to keep the copied location's link (shown in grey), or type <code>none</code> to remove it.</p>
			<table class="form-table" role="presentation">
				<?php foreach ( SMC_Location_Fields::SOCIAL as $k => $label ) : ?>
					<tr><th scope="row"><label for="social_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( 'google_business_url' === $k ? 'Google Business Profile' : $label ); ?></label></th>
						<td><input name="social[<?php echo esc_attr( $k ); ?>]" id="social_<?php echo esc_attr( $k ); ?>" class="large-text smc-copyable" data-field="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v['social'][ $k ] ?? '' ); ?>"></td></tr>
				<?php endforeach; ?>
			</table>

			<h2>Google Map</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="map">Map embed code</label></th>
					<td><textarea name="map" id="map" rows="3" class="large-text code" placeholder="&lt;iframe src=&quot;https://www.google.com/maps/embed?pb=...&quot; ...&gt;&lt;/iframe&gt;"><?php echo esc_textarea( $v['map'] ?? '' ); ?></textarea>
					<p class="description">In Google Maps, find the new office, click <strong>Share &gt; Embed a map &gt; Copy HTML</strong>, and paste it here. The copied location's map is never carried over, so without this the map stays empty until it's added on the location's edit screen.</p></td></tr>
			</table>

			<details class="smc-advanced">
				<summary>More options</summary>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="replace">Extra replacements</label></th>
						<td><textarea name="replace" id="replace" rows="5" class="large-text code" placeholder="Downtown Oldtown => Downtown Springfield"><?php echo esc_textarea( $replace ); ?></textarea>
						<p class="description">One per line as <code>old =&gt; new</code>. For text typed into the copied pages that's specific to the location, like neighborhood names or a doctor mentioned in the page copy. Doctor and team profiles come from Locations &gt; Team and aren't copied.</p></td></tr>
					<tr><th scope="row"><label for="slug">URL slug</label></th>
						<td><input name="slug" id="slug" class="regular-text" value="<?php echo $val( 'slug' ); ?>" placeholder="springfield">
						<p class="description">Leave blank to use the city name.</p></td></tr>
					<tr><th scope="row"><label for="status">New pages are</label></th>
						<td><select name="status" id="status">
							<option value="draft" <?php selected( $v['status'] ?? 'draft', 'draft' ); ?>>Drafts (recommended)</option>
							<option value="pending" <?php selected( $v['status'] ?? '', 'pending' ); ?>>Pending review</option>
							<option value="publish" <?php selected( $v['status'] ?? '', 'publish' ); ?>>Published</option>
						</select></td></tr>
					<tr><th scope="row"><label for="exclude">Pages to skip</label></th>
						<td><textarea name="exclude" id="exclude" rows="3" class="regular-text code"><?php echo esc_textarea( implode( "\n", $v['exclude'] ?? $default['exclude'] ) ); ?></textarea>
						<p class="description">One per line, as a slug or a path under the location. <code>*</code> is a wildcard. The defaults skip landing pages and nearby-city pages.</p></td></tr>
					<tr><th scope="row"><label for="protect">Never change</label></th>
						<td><textarea name="protect" id="protect" rows="2" class="regular-text code"><?php echo esc_textarea( implode( "\n", $v['protect'] ?? [] ) ); ?></textarea>
						<p class="description">Text that must stay as is, one per line. For example, a street named after the copied city.</p></td></tr>
				</table>
			</details>

			<?php submit_button( 'Preview', 'primary large' ); ?>
		</form>
		<script>
		( function () {
			var sel = document.getElementById( 'source' ), city = document.getElementById( 'city' ),
				replace = document.getElementById( 'replace' ), slug = document.getElementById( 'slug' );
			function fill() {
				var opt = sel.options[ sel.selectedIndex ], cur = {};
				try { cur = JSON.parse( opt.getAttribute( 'data-current' ) || '{}' ); } catch ( e ) {}
				document.querySelectorAll( '.smc-copyable' ).forEach( function ( el ) {
					el.placeholder = cur[ el.getAttribute( 'data-field' ) ] || ( 'state' === el.getAttribute( 'data-field' ) ? 'ST' : '' );
				} );
				names();
			}
			// Example replacement and slug follow the copied location and the new city.
			function names() {
				var from = sel.options[ sel.selectedIndex ].getAttribute( 'data-name' ) || 'Oldtown',
					to = city.value.trim() || 'Springfield';
				if ( replace ) { replace.placeholder = 'Downtown ' + from + ' => Downtown ' + to; }
				if ( slug ) { slug.placeholder = to.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' ); }
			}
			sel.addEventListener( 'change', fill );
			city.addEventListener( 'input', names );
			fill();
		} )();
		</script>
		<?php
	}

	private function render_preview( $r, $cfg ) {
		$counts = sprintf(
			'%d pages, %d templates, %d menu, %d terms',
			count( $r['pages'] ),
			count( $r['templates'] ),
			count( $r['menus'] ),
			count( $r['terms'] )
		);
		echo '<div class="notice notice-info inline"><p><strong>Preview.</strong> Nothing has been created yet. Check everything below, then click <em>Create location</em> at the bottom.</p></div>';
		$this->render_warnings( $r['warnings'] );
		$this->render_report( $r, true );
		?>
		<div class="smc-actions">
			<form method="post" style="display:inline" onsubmit="var b=this.querySelector('[type=submit]');b.disabled=true;b.value='Creating, this can take a minute...';">
				<?php wp_nonce_field( 'smc_loc_create' ); ?>
				<input type="hidden" name="smc_loc_action" value="create">
				<input type="hidden" name="smc_loc_cfg" value="<?php echo esc_attr( base64_encode( wp_json_encode( $cfg ) ) ); ?>">
				<?php submit_button( "Create location ($counts)", 'primary large', 'submit', false ); ?>
			</form>
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'smc_loc_preview' ); ?>
				<input type="hidden" name="smc_loc_action" value="edit">
				<?php foreach ( $this->flatten_for_form( $cfg ) as $k => $v ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
				<?php endforeach; ?>
				<?php submit_button( 'Back to form', 'secondary large', 'back', false ); ?>
			</form>
		</div>
		<?php
	}

	private function render_created( $r, $slug ) {
		$log    = SMC_Location_Cloner::get_log()[ $slug ] ?? null;
		$parent = $log && $log['pages'] ? $log['pages'][0] : 0;
		echo '<div class="notice notice-success"><p><strong>Location created.</strong> ';
		if ( $parent ) {
			printf(
				'<a href="%s">Edit the main page</a> &middot; <a href="%s" target="_blank">Preview it</a>',
				esc_url( get_edit_post_link( $parent ) ),
				esc_url( get_preview_post_link( $parent ) )
			);
		}
		echo '</p><p>If something is wrong, use <em>Undo</em> under Previous clones below to remove everything this created.</p></div>';
		$this->render_warnings( $r['warnings'] );
		?>
		<h2>Finish by hand</h2>
		<ul class="ul-disc">
			<li>Add the new office's doctors and team under Locations &gt; Team (pages using <code>[location_team]</code> or a Team Loop show them automatically), and swap any local landmark photos.</li>
			<li>Add the location to the store locator on the Our Locations page.</li>
			<li>Update any "X Locations" text, like the homepage title.</li>
			<li>Set up the office's JotForm if the booking link still points to the copied location.</li>
			<li>Add the Google Map on the location's edit screen if you didn't paste one, and check the hours and social links.</li>
			<li>Write the location's nearby-city pages (these are skipped on purpose).</li>
			<li>Add the new office's reviews under Locations &gt; Reviews. Reviews and team members are never copied from another location.</li>
			<li>Work through the <strong>Launch checklist</strong> on the location's edit screen (Locations &gt; All Locations &gt; Edit), then click <strong>Publish location</strong> to publish every page and template at once.</li>
		</ul>
		<?php
		$this->render_report( $r, false );
		printf(
			'<p><a class="button" href="%s">Add another location</a> <a class="button" href="%s">All locations</a></p>',
			esc_url( menu_page_url( self::SLUG, false ) ),
			esc_url( SMC_Location_Manager::url() )
		);
	}

	private function render_report( $r, $is_plan ) {
		if ( $is_plan ) {
			echo '<h2>Location details</h2>';
			$this->table( $r['summary'], [ 'item' => '', 'from' => 'Copied location', 'to' => 'New location' ] );
			if ( $r['fields'] ) {
				echo '<h3>Location fields used by [location] shortcodes</h3>';
				$this->table( $r['fields'], [ 'field' => 'Field', 'from' => 'Copied location', 'to' => 'New location' ] );
			}
		}
		if ( $r['terms'] ) {
			echo '<h2>Categories</h2>';
			$this->table( $r['terms'], [ 'taxonomy' => 'Taxonomy', 'from' => 'Copied', 'to' => 'New', 'why' => 'Why' ] );
		}
		if ( $r['templates'] ) {
			echo '<h2>Theme Builder templates</h2>';
			$this->table( $r['templates'], [ 'template' => 'Template', 'type' => 'Type', 'conditions' => 'Shows on', 'source' => 'Copied from' ] );
		}
		if ( $r['menus'] ) {
			echo '<h2>Menu</h2>';
			$this->table( $r['menus'], [ 'menu' => 'Copied', 'new menu' => 'New', 'items' => 'Items', 'pages remapped' => 'Links pointed to new pages' ] );
		}

		echo '<h2>Pages (' . count( $r['pages'] ) . ')</h2>';
		$rows = [];
		foreach ( $r['pages'] as $p ) {
			$path   = esc_html( $p['path'] );
			$rows[] = [
				'path'         => is_int( $p['id'] ) ? '<a href="' . esc_url( get_edit_post_link( $p['id'] ) ) . '">' . $path . '</a>' : $path,
				'replacements' => (int) $p['replacements'],
				'location'     => esc_html( $p['location'] ),
			];
		}
		$this->table( $rows, [ 'path' => 'Page', 'replacements' => 'Changes', 'location' => 'Location' ], false );
		if ( $r['excluded'] ?? [] ) {
			echo '<p class="description">Skipped: ' . esc_html( implode( ', ', $r['excluded'] ) ) . '</p>';
		}
		printf(
			'<p class="description">%d internal links and %d template references %s to the new pages and templates.</p>',
			(int) $r['links'],
			(int) $r['tpl_refs'],
			$is_plan ? 'will be pointed' : 'were pointed'
		);
	}

	private function render_warnings( $warnings ) {
		if ( ! $warnings ) {
			return;
		}
		echo '<div class="notice notice-warning inline"><p><strong>' . count( $warnings ) . ' thing(s) to check:</strong></p><ul class="ul-disc">';
		foreach ( $warnings as $w ) {
			echo '<li>' . esc_html( $w ) . '</li>';
		}
		echo '</ul></div>';
	}

	private function render_log() {
		$log = SMC_Location_Cloner::get_log();
		echo '<hr><h2>Previous clones</h2>';
		if ( ! $log ) {
			echo '<p>None yet.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Location</th><th>Copied from</th><th>Created</th><th>By</th><th>Pages</th><th>Edited since</th><th></th></tr></thead><tbody>';
		foreach ( $log as $slug => $e ) {
			$user   = get_userdata( $e['user'] );
			$edited = count( SMC_Location_Cloner::modified_since_clone( $slug ) );
			$terms  = array_sum( array_map( 'count', $e['terms'] ) );
			$msg    = sprintf(
				'Permanently delete /%s/ with %d page(s), %d template(s), %d menu(s) and %d term(s)?%s',
				$e['path'],
				count( $e['pages'] ),
				count( $e['templates'] ),
				count( $e['menus'] ),
				$terms,
				$edited ? "\n\n$edited item(s) were edited or published since the clone and will be deleted too." : ''
			);
			echo '<tr>';
			echo '<td><strong>' . esc_html( $e['city'] ) . '</strong><br><code>/' . esc_html( $e['path'] ) . '/</code></td>';
			echo '<td><code>/' . esc_html( $e['source'] ) . '/</code></td>';
			echo '<td>' . esc_html( wp_date( 'M j, Y g:i a', $e['created'] ) ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '-' ) . '</td>';
			echo '<td>' . count( $e['pages'] ) . '</td>';
			echo '<td>' . ( $edited ? '<span class="smc-edited">' . $edited . '</span>' : '0' ) . '</td>';
			echo '<td><form method="post" onsubmit="return confirm(' . esc_attr( wp_json_encode( $msg ) ) . ');">';
			wp_nonce_field( 'smc_loc_undo_' . $slug );
			echo '<input type="hidden" name="smc_loc_action" value="undo"><input type="hidden" name="smc_loc_slug" value="' . esc_attr( $slug ) . '">';
			echo '<button class="button button-link-delete">Undo</button></form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Undo removes only what that clone created. Once a location is live and approved, it can stay in this list; there\'s no need to clear it.</p>';
	}

	/* ========== Helpers ========== */

	private function sources() {
		$out = [];
		foreach ( get_terms( [ 'taxonomy' => SMC_Location_Cloner::LOC_TAX, 'hide_empty' => false ] ) as $t ) {
			if ( 'corporate' === strtolower( $t->name ) ) {
				continue;
			}
			$page = get_page_by_path( $t->slug, OBJECT, 'page' );
			if ( ! $page ) {
				continue;
			}
			$current = [];
			foreach ( SMC_Location_Fields::names() as $name ) {
				if ( 'form_embed' === $name ) {
					$current[ $name ] = SMC_Location_Fields::form_url( get_term_meta( $t->term_id, $name, true ) );
				} elseif ( 'map_embed' !== $name ) {
					$current[ $name ] = (string) get_term_meta( $t->term_id, $name, true );
				}
			}
			$current['state'] = preg_match( '/,\s*([A-Z]{2})\b/', (string) get_term_meta( $t->term_id, 'city_state', true ), $sm ) ? $sm[1] : '';
			$out[] = [
				'current' => $current,
				'slug'  => $t->slug,
				'name'  => $t->name,
				'phone' => (string) get_term_meta( $t->term_id, 'phone_label', true ),
				'pages' => 1 + count( get_pages( [ 'child_of' => $page->ID, 'post_status' => [ 'publish', 'draft', 'private' ] ] ) ?: [] ),
			];
		}
		return $out;
	}

	/** Table from report rows. Pass $escape = false only when the caller already escaped the values. */
	private function table( $rows, $cols, $escape = true ) {
		echo '<table class="widefat striped smc-table"><thead><tr>';
		foreach ( $cols as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array_keys( $cols ) as $k ) {
				$v = $row[ $k ] ?? '';
				echo '<td>' . ( $escape ? wp_kses( (string) $v, [ 'br' => [] ] ) : $v ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/** Turns a config back into form fields for "Back to form". */
	private function flatten_for_form( $cfg ) {
		$out = [];
		foreach ( [ 'source', 'city', 'state', 'phone', 'street', 'city_state_zip', 'booking_link', 'email', 'email_label', 'form', 'slug', 'status' ] as $k ) {
			if ( isset( $cfg[ $k ] ) ) {
				$out[ $k ] = $cfg[ $k ];
			}
		}
		$replace = '';
		foreach ( $cfg['replace'] ?? [] as $o => $n ) {
			$replace .= "$o => $n\n";
		}
		$out['replace'] = $replace;
		foreach ( $cfg['hours'] ?? [] as $d => $val ) {
			$out[ "hours[$d]" ] = $val;
		}
		foreach ( $cfg['social'] ?? [] as $k => $val ) {
			$out[ "social[$k]" ] = $val;
		}
		$out['map']     = $cfg['map'] ?? '';
		$out['exclude'] = implode( "\n", $cfg['exclude'] ?? [] );
		$out['protect'] = implode( "\n", $cfg['protect'] ?? [] );
		return $out;
	}

	private function styles() {
		?>
		<style>
			.smc-loc .smc-table { max-width: 1100px; margin-bottom: 1.5em; }
			.smc-loc .smc-table td { vertical-align: top; word-break: break-word; }
			.smc-loc .smc-advanced { margin: 1em 0; }
			.smc-loc .smc-advanced summary { cursor: pointer; font-weight: 600; }
			.smc-loc .smc-actions { margin: 2em 0; display: flex; gap: 12px; }
			.smc-loc .smc-edited { color: #b32d2e; font-weight: 600; }
			.smc-loc .notice.inline { margin: 1em 0; }
			.smc-loc .smc-hours th { width: 120px; padding: 6px 10px 6px 0; }
			.smc-loc .smc-hours td { padding: 6px 10px; }
			/* Every single-line field on the location screens is the same height. */
			.smc-loc input[type="text"], .smc-loc input[type="email"], .smc-loc input[type="url"], .smc-loc input[type="number"], .smc-loc input[type="search"], .smc-loc input:not([type]), .smc-loc select {
				height: 36px; min-height: 36px; line-height: 1.4; padding-top: 4px; padding-bottom: 4px; box-sizing: border-box; vertical-align: middle;
			}
		</style>
		<?php
	}
}
