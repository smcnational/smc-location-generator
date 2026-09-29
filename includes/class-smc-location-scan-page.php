<?php
/**
 * Locations > Scan
 *
 * Lists templates and pages where location details are typed in instead of coming
 * from the location shortcodes.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Scan_Page {

	const SLUG = 'smc-location-scan';
	const CAP  = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ], 11 );
	}

	public function menu() {
		add_submenu_page( SMC_Location_Manager::SLUG, 'Scan for Typed-In Details', 'Scan', self::CAP, self::SLUG, [ $this, 'page' ] );
	}

	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$ran  = isset( $_POST['smc_scan'] ) && check_admin_referer( 'smc_loc_scan' );
		$tpl  = $ran ? ! empty( $_POST['templates'] ) : true;
		$pgs  = $ran ? ! empty( $_POST['pages'] ) : true;
		?>
		<div class="wrap smc-scan">
			<h1>Scan for Typed-In Details</h1>
			<p>Finds details typed into Elementor templates and pages instead of coming from the plugin's shortcodes: phone numbers, addresses, hours, social links, maps, booking links, emails, forms, team profiles, reviews, the site name, the logo, holiday closures and copyright years, plus typed-in details in pages' Yoast SEO titles and descriptions. Swap each one for the suggested shortcode, and it will update automatically whenever the location's details change. New locations cloned from it will show their own details too.</p>
			<p class="description">Settings controlled by an Elementor dynamic tag are skipped, since they're already connected.</p>

			<form method="post">
				<?php wp_nonce_field( 'smc_loc_scan' ); ?>
				<input type="hidden" name="smc_scan" value="1">
				<p>
					<label><input type="checkbox" name="templates" value="1" <?php checked( $tpl ); ?>> Theme Builder templates (headers, footers, sections)</label><br>
					<label><input type="checkbox" name="pages" value="1" <?php checked( $pgs ); ?>> Pages</label>
				</p>
				<?php submit_button( $ran ? 'Scan again' : 'Run scan', 'primary', 'submit', false ); ?>
			</form>
			<?php
			if ( $ran ) {
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 300 );
				}
				wp_raise_memory_limit( 'admin' );
				$this->render_results( SMC_Location_Scanner::run( [ 'templates' => $tpl, 'pages' => $pgs ] ) );
			}
			?>
		</div>
		<style>
			.smc-scan .smc-result { background: #fff; border: 1px solid #dcdcde; margin: 16px 0; max-width: 1200px; }
			.smc-scan .smc-result-head { padding: 10px 14px; border-bottom: 1px solid #dcdcde; display: flex; flex-wrap: wrap; gap: 6px 16px; align-items: baseline; }
			.smc-scan .smc-result-head strong { font-size: 14px; }
			.smc-scan .smc-result table { border: 0; }
			.smc-scan .smc-kind { font-weight: 600; white-space: nowrap; }
			.smc-scan .smc-fix code { white-space: normal; }
			.smc-scan .smc-counts span { display: inline-block; margin-right: 14px; }
		</style>
		<?php
	}

	private function render_results( $results ) {
		if ( ! $results ) {
			echo '<div class="notice notice-success inline"><p><strong>Nothing found.</strong> Everything in the scanned items comes from the plugin\'s shortcodes.</p></div>';
			return;
		}

		$by_kind = [];
		$total   = 0;
		foreach ( $results as $r ) {
			foreach ( $r['findings'] as $f ) {
				$by_kind[ $f['kind'] ] = ( $by_kind[ $f['kind'] ] ?? 0 ) + 1;
				$total++;
			}
		}
		$templates = count( array_filter( $results, fn( $r ) => $r['is_template'] ) );

		printf(
			'<h2>%d item%s to fix in %d template%s and %d page%s</h2>',
			$total,
			1 === $total ? '' : 's',
			$templates,
			1 === $templates ? '' : 's',
			count( $results ) - $templates,
			1 === count( $results ) - $templates ? '' : 's'
		);
		echo '<p class="smc-counts">';
		foreach ( $by_kind as $k => $n ) {
			echo '<span>' . esc_html( "$k: $n" ) . '</span>';
		}
		echo '</p><p class="description">Start with templates: one header or footer fix covers every page that uses it.</p>';

		foreach ( $results as $r ) {
			?>
			<div class="smc-result">
				<div class="smc-result-head">
					<strong><a href="<?php echo esc_url( $r['edit'] ); ?>" target="_blank"><?php echo esc_html( $r['title'] ?: '(no title)' ); ?></a></strong>
					<span><?php echo esc_html( $r['type'] ); ?><?php echo 'publish' !== $r['status'] ? esc_html( " ({$r['status']})" ) : ''; ?></span>
					<span>Shows on: <?php echo esc_html( $r['location'] ); ?></span>
					<a href="<?php echo esc_url( $r['edit'] ); ?>" target="_blank">Edit with Elementor</a>
				</div>
				<table class="widefat striped">
					<thead><tr><th style="width:110px">What</th><th>Found</th><th style="width:130px">Widget</th><th style="width:34%">Replace with</th></tr></thead>
					<tbody>
					<?php foreach ( $r['findings'] as $f ) : ?>
						<tr>
							<td class="smc-kind"><?php echo esc_html( $f['kind'] ); ?></td>
							<td>
								<?php echo esc_html( $f['snippet'] ); ?>
								<?php if ( $f['count'] > 1 ) : ?><br><span class="description"><?php echo (int) $f['count']; ?> places in this item</span><?php endif; ?>
								<?php if ( $f['location'] ) : ?><br><span class="description"><?php echo esc_html( $f['location'] ); ?>'s <?php echo esc_html( strtolower( $f['kind'] ) ); ?></span><?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $f['widget'] ); ?></code></td>
							<td class="smc-fix"><code><?php echo esc_html( $f['fix'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		}
	}
}
