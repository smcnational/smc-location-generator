<?php
/**
 * Locations > Export / Import
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Transfer_Page {

	const SLUG = 'smc-export-import';
	const CAP  = 'manage_options';

	private $notice   = [];
	private $warnings = [];
	private $error    = '';
	private $pending  = null; // Parsed upload awaiting confirmation.

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ], 16 );
	}

	public function menu() {
		$hook = add_submenu_page( SMC_Location_Manager::SLUG, 'Export / Import', 'Export / Import', self::CAP, self::SLUG, [ $this, 'page' ] );
		add_action( "load-$hook", [ $this, 'handle' ] );
	}

	private static function key() {
		return 'smc_import_' . get_current_user_id();
	}

	public function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['smc_action'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		$action = sanitize_key( $_POST['smc_action'] );

		if ( 'export' === $action ) {
			check_admin_referer( 'smc_export' );
			$sections = array_map( 'sanitize_key', (array) ( $_POST['sections'] ?? [] ) );
			if ( ! $sections ) {
				$this->error = 'Choose at least one thing to export.';
				return;
			}
			$json = wp_json_encode( SMC_Location_Transfer::export( $sections ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . SMC_Location_Transfer::filename() . '"' );
			header( 'Content-Length: ' . strlen( $json ) );
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		if ( 'upload' === $action ) {
			check_admin_referer( 'smc_import_upload' );
			if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
				$this->error = 'Choose an export file to upload.';
				return;
			}
			$data = SMC_Location_Transfer::parse( file_get_contents( $_FILES['file']['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_wp_error( $data ) ) {
				$this->error = $data->get_error_message();
				return;
			}
			set_transient( self::key(), $data, HOUR_IN_SECONDS );
			$this->pending = $data;
			return;
		}

		if ( 'cancel' === $action ) {
			check_admin_referer( 'smc_import_run' );
			delete_transient( self::key() );
			return;
		}

		if ( 'import' === $action ) {
			check_admin_referer( 'smc_import_run' );
			$data = get_transient( self::key() );
			if ( ! is_array( $data ) ) {
				$this->error = 'The uploaded file expired. Upload it again.';
				return;
			}
			$sections = array_map( 'sanitize_key', (array) ( $_POST['sections'] ?? [] ) );
			if ( ! $sections ) {
				$this->error   = 'Choose at least one thing to import.';
				$this->pending = $data;
				return;
			}
			$res = SMC_Location_Transfer::import( $data, $sections, 'keep' !== ( $_POST['existing'] ?? '' ) );
			delete_transient( self::key() );
			$this->notice   = $res['done'];
			$this->warnings = $res['warnings'];
		}
	}

	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap smc-transfer"><h1>Export / Import</h1>';
		if ( $this->error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $this->error ) . '</p></div>';
		}
		if ( $this->notice ) {
			echo '<div class="notice notice-success"><p><strong>Import finished.</strong></p><ul class="ul-disc">';
			foreach ( $this->notice as $n ) {
				echo '<li>' . esc_html( $n ) . '</li>';
			}
			echo '</ul></div>';
		}
		foreach ( $this->warnings as $w ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $w ) . '</p></div>';
		}

		if ( $this->pending ) {
			$this->render_preview( $this->pending );
		} else {
			$this->render_export();
			$this->render_upload();
		}
		echo '</div>';
		?>
		<style>
			.smc-transfer .smc-box { background: #fff; border: 1px solid #dcdcde; padding: 4px 20px 16px; max-width: 900px; margin: 16px 0; }
			.smc-transfer .smc-section { margin: 12px 0; }
			.smc-transfer .smc-section .description { margin: 2px 0 0 24px; }
			.smc-transfer .smc-swatch { display: inline-block; width: 18px; height: 18px; border-radius: 3px; border: 1px solid #ccc; margin-right: 3px; vertical-align: middle; }
		</style>
		<?php
	}

	private function render_export() {
		$locations = taxonomy_exists( SMC_Location_Transfer::TAX ) ? count( (array) get_terms( [ 'taxonomy' => SMC_Location_Transfer::TAX, 'hide_empty' => false, 'fields' => 'ids' ] ) ) : 0;
		$reviews   = (int) ( wp_count_posts( 'smc_review' )->publish ?? 0 );
		?>
		<div class="smc-box">
			<h2>Export</h2>
			<p>Downloads one file with this site's location data. Import it on another site with this plugin, for example to move staging to live, or to fill a new client site built from the starter template.</p>
			<form method="post">
				<?php wp_nonce_field( 'smc_export' ); ?>
				<input type="hidden" name="smc_action" value="export">
				<div class="smc-section"><label><input type="checkbox" name="sections[]" value="locations" checked> <strong>Locations</strong> (<?php echo (int) $locations; ?>)</label>
					<p class="description">Names and every detail: address, phone, email, hours, social links, map, forms, booking button.</p></div>
				<div class="smc-section"><label><input type="checkbox" name="sections[]" value="team" checked> <strong>Team</strong> (<?php echo (int) ( wp_count_posts( 'smc_team' )->publish ?? 0 ); ?>)</label>
					<p class="description">Doctors and team members with their photos (the image files are included), bios and locations.</p></div>
				<div class="smc-section"><label><input type="checkbox" name="sections[]" value="reviews" checked> <strong>Reviews</strong> (<?php echo (int) $reviews; ?>)</label>
					<p class="description">With their ratings, dates, sources and locations.</p></div>
				<div class="smc-section"><label><input type="checkbox" name="sections[]" value="brand" checked> <strong>Brand</strong></label>
					<p class="description">Logo, mobile logo and favicon (the image files are included), plus the global colors and fonts.</p></div>
				<div class="smc-section"><label><input type="checkbox" name="sections[]" value="settings" checked> <strong>Settings</strong></label>
					<p class="description">Locations &gt; Settings: hours format, map and form heights, button text and so on. Update settings aren't included.</p></div>
				<p class="description">Pages, Theme Builder templates and menus aren't included; they move with the site itself, or come from Add Location.</p>
				<?php submit_button( 'Download export file', 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private function render_upload() {
		?>
		<div class="smc-box">
			<h2>Import</h2>
			<p>Upload an export file. You'll see exactly what it contains and choose what to import before anything changes.</p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'smc_import_upload' ); ?>
				<input type="hidden" name="smc_action" value="upload">
				<p><input type="file" name="file" accept=".json,application/json" required></p>
				<?php submit_button( 'Upload and review', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private function render_preview( array $data ) {
		$p = SMC_Location_Transfer::preview( $data );
		?>
		<div class="smc-box">
			<h2>Review the import</h2>
			<p>From <strong><?php echo esc_html( $data['name'] ?? '' ); ?></strong> (<code><?php echo esc_html( $data['site'] ?? '' ); ?></code>), exported <?php echo esc_html( ! empty( $data['exported'] ) ? wp_date( 'M j, Y g:i a', strtotime( $data['exported'] ) ) : '' ); ?>.</p>
			<?php if ( ! empty( $data['site'] ) && untrailingslashit( $data['site'] ) === untrailingslashit( home_url() ) ) : ?>
				<div class="notice notice-info inline"><p>This file came from this same site.</p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'smc_import_run' ); ?>
				<input type="hidden" name="smc_action" value="import">

				<?php if ( isset( $p['locations'] ) ) : ?>
					<div class="smc-section">
						<label><input type="checkbox" name="sections[]" value="locations" <?php checked( $p['locations']['available'] ); ?> <?php disabled( ! $p['locations']['available'] ); ?>> <strong>Locations</strong></label>
						<?php if ( ! $p['locations']['available'] ) : ?>
							<p class="description" style="color:#b32d2e">This site doesn't have the SMC location system, so locations can't be imported.</p>
						<?php else : ?>
							<p class="description">
								<?php echo $p['locations']['new'] ? esc_html( 'New: ' . implode( ', ', $p['locations']['new'] ) ) . '<br>' : ''; ?>
								<?php echo $p['locations']['existing'] ? esc_html( 'Already on this site: ' . implode( ', ', $p['locations']['existing'] ) ) : ''; ?>
							</p>
							<?php if ( $p['locations']['existing'] ) : ?>
								<p class="description">
									<label><input type="radio" name="existing" value="update" checked> Update existing locations with the file's details</label><br>
									<label><input type="radio" name="existing" value="keep"> Leave existing locations as they are; only add new ones</label>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( isset( $p['team'] ) ) : ?>
					<div class="smc-section">
						<label><input type="checkbox" name="sections[]" value="team" checked> <strong>Team</strong></label>
						<p class="description">
							<?php echo $p['team']['new'] ? esc_html( 'New: ' . implode( ', ', $p['team']['new'] ) ) . '<br>' : ''; ?>
							<?php echo $p['team']['existing'] ? esc_html( 'Already on this site (not duplicated; their locations are combined): ' . implode( ', ', $p['team']['existing'] ) ) : ''; ?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( isset( $p['reviews'] ) ) : ?>
					<div class="smc-section">
						<label><input type="checkbox" name="sections[]" value="reviews" checked> <strong>Reviews</strong></label>
						<p class="description"><?php echo esc_html( $p['reviews']['new'] . ' new' . ( $p['reviews']['existing'] ? ', ' . $p['reviews']['existing'] . ' already on this site (not duplicated; their locations are combined)' : '' ) . '.' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( isset( $p['brand'] ) ) : ?>
					<div class="smc-section">
						<label><input type="checkbox" name="sections[]" value="brand" <?php checked( $p['brand']['available'] ); ?> <?php disabled( ! $p['brand']['available'] ); ?>> <strong>Brand</strong></label>
						<p class="description">
							<?php
							foreach ( $p['brand']['colors'] as $c ) {
								if ( ! empty( $c['color'] ) ) {
									echo '<span class="smc-swatch" title="' . esc_attr( $c['title'] ?? '' ) . '" style="background:' . esc_attr( $c['color'] ) . '"></span>';
								}
							}
							echo '<br>' . esc_html( ( $p['brand']['fonts'] ? 'Fonts: ' . implode( ', ', array_unique( $p['brand']['fonts'] ) ) . '. ' : '' ) . ( $p['brand']['images'] ? 'Images: ' . implode( ', ', array_keys( $p['brand']['images'] ) ) . '.' : '' ) );
							?>
							<br>Replaces this site's logo, favicon, colors and fonts. The current ones are saved under Locations &gt; Brand &gt; Restore.
						</p>
					</div>
				<?php endif; ?>

				<?php if ( isset( $p['settings'] ) ) : ?>
					<div class="smc-section">
						<label><input type="checkbox" name="sections[]" value="settings" checked> <strong>Settings</strong></label>
						<p class="description">Replaces this site's Locations &gt; Settings display options.</p>
					</div>
				<?php endif; ?>

				<p>
					<?php submit_button( 'Import', 'primary', 'submit', false ); ?>
					<button type="submit" name="smc_action" value="cancel" class="button" formnovalidate>Cancel</button>
				</p>
			</form>
		</div>
		<?php
	}
}
