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
		$ran    = isset( $_POST['smc_scan'] ) && check_admin_referer( 'smc_loc_scan' );
		$tpl    = $ran ? ! empty( $_POST['templates'] ) : true;
		$pgs    = $ran ? ! empty( $_POST['pages'] ) : true;
		$notice = '';
		$do     = sanitize_key( $_POST['smc_fix_do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $do ) {
			check_admin_referer( 'smc_loc_fix' );
			$ran = true;
			$tpl = ! empty( $_POST['templates'] );
			$pgs = ! empty( $_POST['pages'] );
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$notice = $this->apply( $do, $tpl, $pgs );
		}
		$aud = $ran ? ! empty( $_POST['audit'] ) : true; // phpcs:ignore WordPress.Security.NonceVerification
		$ado = sanitize_key( $_POST['smc_audit_do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $ado ) {
			check_admin_referer( 'smc_loc_audit' );
			$ran    = true;
			$tpl    = ! empty( $_POST['templates'] );
			$pgs    = ! empty( $_POST['pages'] );
			$aud    = ! empty( $_POST['audit'] );
			$notice = $this->audit_action( $ado );
		}
		$changed = SMC_Location_Fixer::changed();
		?>
		<div class="wrap smc-scan">
			<h1>Scan for Typed-In Details</h1>
			<p>Finds details typed into Elementor templates and pages instead of coming from the plugin's shortcodes: phone numbers, addresses, city and state, hours, social links, maps, booking links, emails, forms, team profiles, reviews, the site name, the logo, holiday closures and copyright years, plus typed-in details in pages' Yoast SEO titles and descriptions. Swap each one for the suggested shortcode (many with one click below), and it will update automatically whenever the location's details change. New locations cloned from it will show their own details too.</p>
			<p class="description">Settings controlled by an Elementor dynamic tag are skipped, since they're already connected.</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( $changed ) : ?>
				<div class="notice notice-info smc-fix-undo"><p>
					<strong><?php echo count( $changed ); ?> item(s) changed by Scan.</strong> Check them on the site. The originals are kept until you choose:
					<?php $this->fix_button( 'undo', 'Undo all fixes', $tpl, $pgs, [], 'button', 'Put every item Scan changed back as it was?' ); ?>
					<?php $this->fix_button( 'keep', 'Keep them', $tpl, $pgs, [], 'button-link', '' ); ?>
				</p></div>
			<?php endif; ?>

			<?php $this->render_baseline( $tpl, $pgs, $aud ); ?>

			<form method="post">
				<?php wp_nonce_field( 'smc_loc_scan' ); ?>
				<input type="hidden" name="smc_scan" value="1">
				<p>
					<label><input type="checkbox" name="audit" value="1" <?php checked( $aud ); ?>> <strong>Launch audit:</strong> template leftovers and launch blockers (search engines, logo, forms, tracking, SEO...)</label><br>
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
				if ( $aud ) {
					$this->render_audit( SMC_Location_Audit::run(), $tpl, $pgs );
				}
				if ( $tpl || $pgs ) {
					echo '<h2 class="smc-section">Typed-in details</h2>';
					$this->render_results( SMC_Location_Scanner::run( [ 'templates' => $tpl, 'pages' => $pgs ] ), $tpl, $pgs );
				}
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
			.smc-scan .smc-fix-form { display: inline-block; margin: 0 6px 0 0; }
			.smc-scan .smc-baseline { background: #fff; border: 1px solid #dcdcde; padding: 2px 14px 10px; max-width: 1172px; margin: 12px 0; }
			.smc-scan .smc-section { margin-top: 28px; padding-top: 12px; border-top: 1px solid #dcdcde; max-width: 1200px; }
			.smc-scan .smc-audit { max-width: 1200px; margin-bottom: 16px; }
			.smc-scan .smc-audit td { vertical-align: top; }
			.smc-scan .smc-where { margin: 6px 0 0 16px; list-style: disc; }
			.smc-scan .smc-where li { margin: 0; }
			.smc-scan .smc-sev { display: inline-block; margin-right: 10px; padding: 3px 10px; border-radius: 12px; font-weight: 600; }
			.smc-scan .smc-sev-blocker { background: #fcf0f1; color: #b32d2e; }
			.smc-scan .smc-sev-should { background: #fcf9e8; color: #8a6d00; }
			.smc-scan .smc-sev-optional { background: #f0f6fc; color: #2271b1; }
			.smc-scan .smc-apply-all { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 4px 14px 14px; max-width: 1172px; margin: 12px 0 20px; }
		</style>
		<?php
	}

	/** A small form button for a fix action. */
	private function fix_button( $do, $label, $tpl, $pgs, array $fields, $class = 'button button-small', $confirm = '' ) {
		echo '<form method="post" class="smc-fix-form"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
		wp_nonce_field( 'smc_loc_fix' );
		echo '<input type="hidden" name="smc_fix_do" value="' . esc_attr( $do ) . '">';
		echo $tpl ? '<input type="hidden" name="templates" value="1">' : '';
		echo $pgs ? '<input type="hidden" name="pages" value="1">' : '';
		echo ! isset( $_POST['audit'] ) && isset( $_POST['smc_scan'] ) ? '' : '<input type="hidden" name="audit" value="1">'; // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Runs a fix action. Returns the message to show. */
	private function apply( $do, $tpl, $pgs ) {
		if ( 'undo' === $do ) {
			return sprintf( '%d item(s) put back as they were.', SMC_Location_Fixer::undo() );
		}
		if ( 'keep' === $do ) {
			SMC_Location_Fixer::keep();
			return 'Fixes kept.';
		}
		$jobs = [];
		if ( 'one' === $do ) {
			$jobs[] = [ (int) ( $_POST['post'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['kind'] ?? '' ) ), wp_unslash( $_POST['value'] ?? '' ), sanitize_text_field( wp_unslash( $_POST['loc'] ?? '' ) ) ]; // phpcs:ignore WordPress.Security
		} elseif ( 'all' === $do ) {
			foreach ( SMC_Location_Scanner::run( [ 'templates' => $tpl, 'pages' => $pgs ] ) as $r ) {
				foreach ( $r['findings'] as $f ) {
					if ( $f['auto'] ) {
						$jobs[] = [ $r['id'], $f['kind'], $f['value'], $f['location'] ];
					}
				}
			}
		}
		$places = 0;
		$items  = [];
		foreach ( $jobs as [ $id, $kind, $value, $loc ] ) {
			$n = SMC_Location_Fixer::fix( $id, $kind, $value, $loc );
			if ( $n ) {
				$places      += $n;
				$items[ $id ] = true;
			}
		}
		if ( $places ) {
			SMC_Location_Cloner::refresh_caches();
		}
		return $places
			? sprintf( 'Fixed %d place(s) in %d item(s). The list below is the fresh scan: what\'s left needs doing by hand.', $places, count( $items ) )
			: 'Nothing was changed. It may already be fixed, or it sits somewhere that has to be fixed in Elementor.';
	}

	/* ========== Launch audit ========== */

	private function audit_button( $do, $label, array $fields, $class = 'button-link', $confirm = '' ) {
		echo '<form method="post" class="smc-fix-form"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
		wp_nonce_field( 'smc_loc_audit' );
		echo '<input type="hidden" name="smc_audit_do" value="' . esc_attr( $do ) . '"><input type="hidden" name="templates" value="1"><input type="hidden" name="pages" value="1"><input type="hidden" name="audit" value="1">';
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private function audit_action( $do ) {
		switch ( $do ) {
			case 'baseline':
				$extra = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', sanitize_textarea_field( wp_unslash( $_POST['extra'] ?? '' ) ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
				$b     = SMC_Location_Audit::save_baseline( $extra );
				return sprintf( 'Template baseline saved: %s, %d photo(s), %d form(s). Sites cloned from this one will flag anything of it they still have.', esc_html( $b['practice'] ), count( $b['media'] ), count( $b['forms'] ) );
			case 'extra':
				$b = SMC_Location_Audit::baseline();
				if ( $b ) {
					$b['extra'] = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', sanitize_textarea_field( wp_unslash( $_POST['extra'] ?? '' ) ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
					update_option( SMC_Location_Audit::BASELINE, $b, false );
				}
				return 'Template text to look for saved.';
			case 'ignore':
				SMC_Location_Audit::ignore( sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
				return 'Ignored. It won\'t be listed again (Show ignored at the bottom of the audit brings it back).';
			case 'unignore':
				SMC_Location_Audit::unignore_all();
				return 'Ignored findings are listed again.';
			case 'keep':
				$id = (int) ( $_POST['item'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
				if ( $id && 'attachment' === get_post_type( $id ) ) {
					update_post_meta( $id, SMC_Location_Audit::KEEP, 1 );
				}
				return 'Marked as a generic image every client can keep. Set it on the template too, so new clones skip it.';
		}
		return '';
	}

	private function render_baseline( $tpl, $pgs, $aud ) {
		$b = SMC_Location_Audit::baseline();
		echo '<div class="smc-baseline">';
		if ( ! $b ) {
			echo '<p><strong>No template baseline yet.</strong> On the <em>template</em> site, save one: it records the template\'s demo details, logo, favicon, colors, forms and photos. Every site cloned from the template carries it, so the launch audit can find what\'s left of the template. (New Build saves one automatically when there isn\'t one.)</p>';
			$this->audit_button( 'baseline', 'Save this site as the template baseline', [], 'button', 'Save this site as the template baseline? Do this on the template, not a client\'s site.' );
		} else {
			$is = SMC_Location_Audit::is_template( $b );
			printf(
				'<p><strong>Template baseline:</strong> %s (%s, %s), %d photo(s), %d form(s), saved %s. %s</p>',
				esc_html( $b['practice'] ),
				esc_html( $b['city'] ),
				esc_html( $b['phone'] ),
				count( (array) $b['media'] ),
				count( (array) $b['forms'] ),
				esc_html( wp_date( get_option( 'date_format' ), (int) $b['saved'] ) ),
				$is ? '<em>This is the template, so leftover checks are skipped here; they run on its clones.</em>' : 'Leftover checks compare this site with it.'
			);
			echo '<details><summary>Other template text to look for</summary><form method="post">';
			wp_nonce_field( 'smc_loc_audit' );
			echo '<input type="hidden" name="smc_audit_do" value="extra"><input type="hidden" name="templates" value="1"><input type="hidden" name="pages" value="1"><input type="hidden" name="audit" value="1">';
			echo '<p><textarea name="extra" rows="4" class="large-text" placeholder="Downtown Springfield&#10;Springfield Elementary">' . esc_textarea( implode( "\n", (array) $b['extra'] ) ) . '</textarea></p><p class="description">One per line: anything else from the template that shouldn\'t be left on a client\'s site (a neighborhood, a demo testimonial\'s name...). The demo practice name, city, phone, address, email, domain and doctors are looked for already.</p>';
			submit_button( 'Save', 'secondary', 'submit', false );
			echo '</form>';
			if ( $is ) {
				echo '<p>';
				$this->audit_button( 'baseline', 'Update the baseline from this site', [], 'button', 'Replace the baseline with this site\'s current details, branding, forms and photos?' );
				echo '</p>';
			}
			echo '</details>';
		}
		echo '</div>';
	}

	private function render_audit( array $a, $tpl, $pgs ) {
		$labels = [ 'blocker' => 'Launch blockers', 'should' => 'Should fix', 'optional' => 'Optional' ];
		echo '<h2 class="smc-section">Launch audit</h2><p class="smc-audit-sum">';
		foreach ( $labels as $sev => $l ) {
			printf( '<span class="smc-sev smc-sev-%s">%s: %d</span>', esc_attr( $sev ), esc_html( $l ), count( $a[ $sev ] ) );
		}
		echo '</p>';
		if ( ! $a['blocker'] ) {
			echo '<div class="notice notice-success inline"><p><strong>No launch blockers.</strong> ' . ( $a['should'] ? 'Work through Should fix before launch if you can.' : '' ) . '</p></div>';
		}
		foreach ( $labels as $sev => $l ) {
			if ( ! $a[ $sev ] ) {
				continue;
			}
			echo '<h3>' . esc_html( $l ) . '</h3><table class="widefat striped smc-audit"><thead><tr><th style="width:180px">Check</th><th>Found</th><th style="width:28%">How to fix</th><th style="width:110px"></th></tr></thead><tbody>';
			foreach ( $a[ $sev ] as $f ) {
				echo '<tr><td class="smc-kind">' . esc_html( $f['check'] ) . '</td><td>' . esc_html( $f['detail'] );
				if ( $f['where'] ) {
					echo '<ul class="smc-where">';
					foreach ( $f['where'] as [ $title, $url ] ) {
						echo '<li><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $title ) . '</a></li>';
					}
					echo $f['more'] ? '<li class="description">and ' . (int) $f['more'] . ' more</li>' : '';
					echo '</ul>';
				}
				echo '</td><td>' . esc_html( $f['fix'] ) . ( $f['link'] ? ' <a href="' . esc_url( $f['link'] ) . '" target="_blank">Open</a>' : '' ) . '</td><td>';
				if ( 'Template photo' === $f['check'] && $f['item'] ) {
					$this->audit_button( 'keep', 'Keep for clients', [ 'item' => $f['item'] ] );
					echo '<br>';
				}
				$this->audit_button( 'ignore', 'Ignore', [ 'key' => $f['key'], 'label' => $f['check'] . ': ' . $f['detail'] ] );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( $a['ignored'] ) {
			echo '<p class="description">' . (int) $a['ignored'] . ' ignored finding(s) not shown. ';
			$this->audit_button( 'unignore', 'Show ignored', [] );
			echo '</p>';
		}
	}

	private function render_results( $results, $tpl = true, $pgs = true ) {
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

		$auto = 0;
		foreach ( $results as $r ) {
			$auto += count( array_filter( $r['findings'], fn( $f ) => $f['auto'] ) );
		}
		if ( $auto ) {
			echo '<div class="smc-apply-all"><p><strong>' . (int) $auto . ' of ' . (int) $total . ' can be fixed here</strong>, without opening Elementor. Phone numbers, emails, booking and social links, city and state, the site name, copyright years and Yoast fields get their shortcodes; text in headings and buttons gets Elementor\'s Shortcode dynamic tag. Everything can be undone.</p>';
			$this->fix_button( 'all', "Apply all $auto fixes", $tpl, $pgs, [], 'button button-primary', "Apply all $auto fixes? You can undo them afterwards." );
			echo '</div>';
		}

		foreach ( $results as $r ) {
			?>
			<div class="smc-result">
				<div class="smc-result-head">
					<strong><a href="<?php echo esc_url( $r['view'] ?: $r['edit'] ); ?>" target="_blank"><?php echo esc_html( $r['title'] ?: '(no title)' ); ?></a></strong>
					<span><?php echo esc_html( $r['type'] ); ?><?php echo 'publish' !== $r['status'] ? esc_html( " ({$r['status']})" ) : ''; ?></span>
					<span>Shows on: <?php echo esc_html( $r['location'] ); ?></span>
					<a href="<?php echo esc_url( $r['edit'] ); ?>" target="_blank">Edit with Elementor</a>
				</div>
				<table class="widefat striped">
					<thead><tr><th style="width:110px">What</th><th>Found</th><th style="width:130px">Widget</th><th style="width:30%">Replace with</th><th style="width:90px"></th></tr></thead>
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
							<td>
								<?php
								if ( $f['auto'] ) {
									$this->fix_button( 'one', 'Apply', $tpl, $pgs, [ 'post' => $r['id'], 'kind' => $f['kind'], 'value' => $f['value'], 'loc' => $f['location'] ] );
								} else {
									echo '<span class="description">In Elementor</span>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		}
	}
}
