<?php
/**
 * Locations > Brand
 *
 * One screen for the site's logo, favicon, global colors and global fonts. It doesn't keep
 * its own copy: it edits Elementor's Site Settings (the active kit) and WordPress's site
 * logo and site icon directly, so Elementor and this screen always show the same values.
 * Every save keeps the previous values so they can be restored.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Brand {

	const SLUG    = 'smc-brand';
	const CAP     = 'manage_options';
	const HISTORY = 'smc_brand_history';
	const OPTION  = 'smc_brand';
	const WEIGHTS = [ '' => 'Default', '300' => '300 Light', '400' => '400 Regular', '500' => '500 Medium', '600' => '600 Semi-bold', '700' => '700 Bold', '800' => '800 Extra-bold', '900' => '900 Black' ];

	private $notice = '';
	private $error  = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ], 15 );
	}

	/* ========== Mobile logo (front end) ========== */

	/**
	 * Swaps in the mobile logo on small screens, in:
	 *   - Elementor's Site Logo widget
	 *   - any Elementor Image widget showing the site logo
	 *   - [brand_logo]
	 * It uses a <picture> element, so the browser loads only the logo it shows.
	 */
	public static function init_front() {
		add_filter( 'elementor/widget/render_content', [ __CLASS__, 'mobile_logo_widget' ], 10, 2 );
		add_action(
			'init',
			function () {
				if ( ! shortcode_exists( 'brand_logo' ) ) {
					add_shortcode( 'brand_logo', [ __CLASS__, 'logo_shortcode' ] );
				}
			},
			20
		);
	}

	public static function options() {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [ 'mobile_logo' => 0, 'mobile_logo_on' => 'mobile' ] );
	}

	/** Widest screen (px) that gets the mobile logo, from Elementor's breakpoints. */
	public static function mobile_max() {
		$on  = 'tablet' === self::options()['mobile_logo_on'] ? 'tablet' : 'mobile';
		$def = 'tablet' === $on ? 1024 : 767;
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->breakpoints ) ) {
			try {
				$bp = \Elementor\Plugin::$instance->breakpoints->get_active_breakpoints();
				if ( isset( $bp[ $on ] ) ) {
					return (int) $bp[ $on ]->get_value();
				}
			} catch ( \Throwable $e ) {
				return $def;
			}
		}
		return $def;
	}

	/** Wraps a logo <img> in a <picture> with the mobile logo as a small-screen source. */
	public static function picture( $img ) {
		$id  = (int) self::options()['mobile_logo'];
		$url = $id ? wp_get_attachment_image_url( $id, 'full' ) : '';
		if ( ! $url || false !== strpos( $img, 'smc-logo-picture' ) ) {
			return $img;
		}
		return '<picture class="smc-logo-picture" style="display:contents"><source media="(max-width: ' . self::mobile_max() . 'px)" srcset="' . esc_url( $url ) . '">' . $img . '</picture>';
	}

	public static function mobile_logo_widget( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || ! self::options()['mobile_logo'] || false !== strpos( $content, 'smc-logo-picture' ) ) {
			return $content;
		}
		$name = $widget->get_name();
		if ( 'image' === $name ) {
			$logo  = (int) get_theme_mod( 'custom_logo' );
			$image = (array) $widget->get_settings( 'image' );
			if ( ! $logo || (int) ( $image['id'] ?? 0 ) !== $logo ) {
				return $content;
			}
		} elseif ( 'theme-site-logo' !== $name ) {
			return $content;
		}
		return preg_replace_callback( '/<img\b[^>]*>/i', fn( $m ) => self::picture( $m[0] ), $content, 1 );
	}

	/**
	 * [brand_logo] - the site logo (with the mobile logo on small screens), linked to the homepage.
	 *   link="none"        no link
	 *   width="220px"      maximum width
	 */
	public static function logo_shortcode( $atts ) {
		$a    = shortcode_atts( [ 'link' => 'home', 'width' => '' ], $atts, 'brand_logo' );
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( ! $logo ) {
			return '';
		}
		$style = '';
		if ( '' !== $a['width'] && preg_match( '/^\d+(px|%|rem|em|vw)?$/', trim( $a['width'] ) ) ) {
			$style = 'max-width:' . ( ctype_digit( trim( $a['width'] ) ) ? trim( $a['width'] ) . 'px' : trim( $a['width'] ) ) . ';height:auto';
		}
		$img = wp_get_attachment_image( $logo, 'full', false, [ 'class' => 'smc-brand-logo', 'alt' => get_bloginfo( 'name' ), 'style' => $style ] );
		$out = self::picture( $img );
		return 'none' === $a['link'] ? $out : '<a href="' . esc_url( home_url( '/' ) ) . '" class="smc-brand-logo-link" rel="home">' . $out . '</a>';
	}

	public function menu() {
		$hook = add_submenu_page( SMC_Location_Manager::SLUG, 'Brand', 'Brand', self::CAP, self::SLUG, [ $this, 'page' ] );
		add_action( "load-$hook", [ $this, 'handle' ] );
		add_action( "admin_print_scripts-$hook", [ $this, 'assets' ] );
	}

	public function assets() {
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	/* ========== Elementor kit ========== */

	private static function kit_id() {
		$id = (int) get_option( 'elementor_active_kit' );
		return $id && get_post( $id ) ? $id : 0;
	}

	private static function kit_settings() {
		$id = self::kit_id();
		$s  = $id ? get_post_meta( $id, '_elementor_page_settings', true ) : [];
		return is_array( $s ) ? $s : [];
	}

	private static function default_system_colors() {
		return [
			[ '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ],
			[ '_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ],
			[ '_id' => 'text', 'title' => 'Text', 'color' => '#7A7A7A' ],
			[ '_id' => 'accent', 'title' => 'Accent', 'color' => '#61CE70' ],
		];
	}

	private static function default_system_typography() {
		return [
			[ '_id' => 'primary', 'title' => 'Primary' ],
			[ '_id' => 'secondary', 'title' => 'Secondary' ],
			[ '_id' => 'text', 'title' => 'Text' ],
			[ '_id' => 'accent', 'title' => 'Accent' ],
		];
	}

	/** The brand-related part of the site's settings, for display and history. */
	private static function snapshot() {
		$s = self::kit_settings();
		return [
			'time'              => time(),
			'user'              => get_current_user_id(),
			'logo'              => (int) get_theme_mod( 'custom_logo' ),
			'icon'              => (int) get_option( 'site_icon' ),
			'mobile_logo'       => (int) self::options()['mobile_logo'],
			'mobile_logo_on'    => self::options()['mobile_logo_on'],
			'system_colors'     => $s['system_colors'] ?? self::default_system_colors(),
			'custom_colors'     => $s['custom_colors'] ?? [],
			'system_typography' => $s['system_typography'] ?? self::default_system_typography(),
			'custom_typography' => $s['custom_typography'] ?? [],
		];
	}

	/** Writes a snapshot back to Elementor and WordPress. */
	private static function apply( array $b ) {
		$id = self::kit_id();
		$s  = self::kit_settings();
		foreach ( [ 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' ] as $k ) {
			$s[ $k ] = $b[ $k ];
		}
		$s['site_logo']    = $b['logo'] ? [ 'url' => (string) wp_get_attachment_image_url( $b['logo'], 'full' ), 'id' => $b['logo'] ] : [ 'url' => '', 'id' => '' ];
		$s['site_favicon'] = $b['icon'] ? [ 'url' => (string) wp_get_attachment_image_url( $b['icon'], 'full' ), 'id' => $b['icon'] ] : [ 'url' => '', 'id' => '' ];
		update_post_meta( $id, '_elementor_page_settings', $s );

		if ( $b['logo'] ) {
			set_theme_mod( 'custom_logo', $b['logo'] );
		} else {
			remove_theme_mod( 'custom_logo' );
		}
		update_option( 'site_icon', $b['icon'] );
		update_option(
			self::OPTION,
			array_merge(
				self::options(),
				[
					'mobile_logo'    => (int) ( $b['mobile_logo'] ?? 0 ),
					'mobile_logo_on' => 'tablet' === ( $b['mobile_logo_on'] ?? '' ) ? 'tablet' : 'mobile',
				]
			)
		);

		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	private static function push_history( array $snap ) {
		$h = (array) get_option( self::HISTORY, [] );
		array_unshift( $h, $snap );
		update_option( self::HISTORY, array_slice( $h, 0, 10 ), false );
	}

	/* ========== Save ========== */

	public function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['smc_action'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( ! self::kit_id() ) {
			$this->error = 'Elementor\'s Site Settings kit was not found. Open Elementor > Site Settings once, then try again.';
			return;
		}
		$action = sanitize_key( $_POST['smc_action'] );

		if ( 'restore' === $action ) {
			check_admin_referer( 'smc_brand_restore' );
			$i = absint( $_POST['index'] ?? -1 );
			$h = (array) get_option( self::HISTORY, [] );
			if ( ! isset( $h[ $i ] ) ) {
				$this->error = 'That saved version no longer exists.';
				return;
			}
			self::push_history( self::snapshot() );
			self::apply( $h[ $i ] );
			$this->notice = 'Restored the brand settings from ' . wp_date( 'M j, Y g:i a', $h[ $i ]['time'] ) . '.';
			return;
		}

		if ( 'save' !== $action ) {
			return;
		}
		check_admin_referer( 'smc_brand_save' );
		$p   = wp_unslash( $_POST );
		$old = self::snapshot();
		$new = $old;

		$new['logo'] = absint( $p['logo'] ?? 0 );
		$new['icon'] = absint( $p['icon'] ?? 0 );
		$new['mobile_logo']    = absint( $p['mobile_logo'] ?? 0 );
		$new['mobile_logo_on'] = 'tablet' === ( $p['mobile_logo_on'] ?? '' ) ? 'tablet' : 'mobile';

		// Colors: the four system colors keep their IDs; custom colors can be added and removed.
		$bad = [];
		foreach ( $new['system_colors'] as $i => $c ) {
			$row = $p['system_colors'][ $c['_id'] ] ?? null;
			if ( ! $row ) {
				continue;
			}
			$color = self::color( $row['color'] ?? '' );
			if ( null === $color ) {
				$bad[] = $c['title'];
				continue;
			}
			if ( '' !== $color ) {
				$new['system_colors'][ $i ]['color'] = $color;
			}
			$new['system_colors'][ $i ]['title'] = sanitize_text_field( $row['title'] ?? $c['title'] ) ?: $c['title'];
		}
		$custom = [];
		foreach ( (array) ( $p['custom_colors'] ?? [] ) as $row ) {
			if ( ! empty( $row['remove'] ) ) {
				continue;
			}
			$color = self::color( $row['color'] ?? '' );
			$title = sanitize_text_field( $row['title'] ?? '' );
			if ( null === $color || '' === $color ) {
				if ( '' !== $title ) {
					$bad[] = $title;
				}
				continue;
			}
			$custom[] = [
				'_id'   => preg_match( '/^[a-z0-9]{7}$/', $row['_id'] ?? '' ) ? $row['_id'] : substr( md5( uniqid( '', true ) ), 0, 7 ),
				'title' => '' !== $title ? $title : 'Custom color',
				'color' => $color,
			];
		}
		$new['custom_colors'] = $custom;

		// Fonts: only family and weight are changed; sizes and other settings are kept.
		foreach ( [ 'system_typography', 'custom_typography' ] as $group ) {
			foreach ( $new[ $group ] as $i => $t ) {
				$row = $p[ $group ][ $t['_id'] ] ?? null;
				if ( ! $row ) {
					continue;
				}
				$family = trim( sanitize_text_field( $row['family'] ?? '' ) );
				$weight = sanitize_text_field( $row['weight'] ?? '' );
				if ( '' !== $family ) {
					$new[ $group ][ $i ]['typography_typography']  = 'custom';
					$new[ $group ][ $i ]['typography_font_family'] = $family;
				} else {
					unset( $new[ $group ][ $i ]['typography_font_family'] );
				}
				if ( '' !== $weight && isset( self::WEIGHTS[ $weight ] ) ) {
					$new[ $group ][ $i ]['typography_typography'] = 'custom';
					$new[ $group ][ $i ]['typography_font_weight'] = $weight;
				} else {
					unset( $new[ $group ][ $i ]['typography_font_weight'] );
				}
			}
		}

		if ( $bad ) {
			$this->error = 'These colors weren\'t saved because the value isn\'t a color (use a hex code like #1A73E8): ' . implode( ', ', $bad ) . '.';
		}
		$compare = fn( $b ) => wp_json_encode( array_diff_key( $b, [ 'time' => 1, 'user' => 1 ] ) );
		if ( $compare( $new ) === $compare( $old ) ) {
			$this->notice = $bad ? '' : 'No changes to save.';
			return;
		}
		self::push_history( $old );
		self::apply( $new );
		$this->notice = 'Brand saved. The whole site uses the new settings now; the previous version is under Restore below.';
	}

	/** Hex (#abc, #aabbcc, #aabbccdd) or rgb()/rgba(); '' for empty; null if invalid. */
	private static function color( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v, $m ) ) {
			return '#' . strtoupper( $m[1] );
		}
		if ( preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/i', $v ) ) {
			return strtolower( $v );
		}
		return null;
	}

	/* ========== Screen ========== */

	private static function fonts() {
		if ( class_exists( '\Elementor\Fonts' ) ) {
			$fonts = array_keys( (array) \Elementor\Fonts::get_fonts() );
			sort( $fonts );
			return $fonts;
		}
		return [ 'Arial', 'Georgia', 'Helvetica', 'Tahoma', 'Times New Roman', 'Verdana' ];
	}

	private static function image_field( $name, $id, $label, $help ) {
		$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		?>
		<div class="smc-image" data-name="<?php echo esc_attr( $name ); ?>">
			<div class="smc-image-preview"><?php echo $url ? '<img src="' . esc_url( $url ) . '" alt="">' : '<span>None</span>'; ?></div>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $id; ?>">
			<button type="button" class="button smc-image-pick"><?php echo esc_html( $url ? "Change $label" : "Choose $label" ); ?></button>
			<button type="button" class="button-link smc-image-remove" <?php echo $url ? '' : 'style="display:none"'; ?>>Remove</button>
			<p class="description"><?php echo esc_html( $help ); ?></p>
		</div>
		<?php
	}

	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$b     = self::snapshot();
		$fonts = self::fonts();
		?>
		<div class="wrap smc-brand">
			<h1>Brand</h1>
			<?php if ( ! class_exists( '\Elementor\Plugin' ) || ! self::kit_id() ) : ?>
				<div class="notice notice-error"><p>Elementor's Site Settings weren't found. This screen edits Elementor's global colors and fonts, so Elementor needs to be active.</p></div>
				<?php
				echo '</div>';
				return;
			endif;
			?>
			<?php if ( $this->error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $this->error ); ?></p></div><?php endif; ?>
			<?php if ( $this->notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $this->notice ); ?></p></div><?php endif; ?>

			<p>The site's logo, favicon, colors and fonts in one place. These are the same settings as <strong>Elementor &gt; Site Settings</strong>, so a change here shows up there too, and every widget set to a global color or font updates across the whole site.</p>

			<form method="post">
				<?php wp_nonce_field( 'smc_brand_save' ); ?>
				<input type="hidden" name="smc_action" value="save">

				<h2>Logo &amp; favicon</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Logo</th><td><?php self::image_field( 'logo', $b['logo'], 'logo', 'Shown by Elementor\'s Site Logo widget and the theme. Headers that use a plain Image widget for the logo need that widget changed by hand.' ); ?></td></tr>
					<tr><th scope="row">Mobile logo</th><td>
						<?php self::image_field( 'mobile_logo', $b['mobile_logo'], 'mobile logo', 'Optional. Replaces the logo on small screens, e.g. a shorter or icon-only version.' ); ?>
						<?php
						$bp_mobile = 767;
						$bp_tablet = 1024;
						if ( isset( \Elementor\Plugin::$instance->breakpoints ) ) {
							$active    = \Elementor\Plugin::$instance->breakpoints->get_active_breakpoints();
							$bp_mobile = isset( $active['mobile'] ) ? (int) $active['mobile']->get_value() : $bp_mobile;
							$bp_tablet = isset( $active['tablet'] ) ? (int) $active['tablet']->get_value() : $bp_tablet;
						}
						?>
						<p><label for="mobile_logo_on">Use it on</label>
							<select name="mobile_logo_on" id="mobile_logo_on">
								<option value="mobile" <?php selected( $b['mobile_logo_on'], 'mobile' ); ?>>Phones (screens up to <?php echo (int) $bp_mobile; ?>px)</option>
								<option value="tablet" <?php selected( $b['mobile_logo_on'], 'tablet' ); ?>>Phones and tablets (up to <?php echo (int) $bp_tablet; ?>px)</option>
							</select></p>
						<p class="description">Switches automatically in Elementor's Site Logo widget, in any Image widget showing the logo above, and in <code>[brand_logo]</code>. Only one logo is downloaded per visit. No need for two logo widgets with hide-on-mobile settings.</p>
					</td></tr>
					<tr><th scope="row">Favicon</th><td><?php self::image_field( 'icon', $b['icon'], 'favicon', 'The browser tab icon. Use a square image, at least 512 by 512 pixels.' ); ?></td></tr>
				</table>

				<h2>Colors</h2>
				<table class="widefat striped smc-colors">
					<thead><tr><th>Name</th><th>Color</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $b['system_colors'] as $c ) : ?>
						<tr>
							<td><input name="system_colors[<?php echo esc_attr( $c['_id'] ); ?>][title]" value="<?php echo esc_attr( $c['title'] ); ?>" class="regular-text"></td>
							<td><input name="system_colors[<?php echo esc_attr( $c['_id'] ); ?>][color]" value="<?php echo esc_attr( $c['color'] ?? '' ); ?>" class="smc-color"></td>
							<td class="description">Global color</td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( $b['custom_colors'] as $i => $c ) : ?>
						<tr>
							<td><input type="hidden" name="custom_colors[<?php echo (int) $i; ?>][_id]" value="<?php echo esc_attr( $c['_id'] ); ?>"><input name="custom_colors[<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( $c['title'] ); ?>" class="regular-text"></td>
							<td><input name="custom_colors[<?php echo (int) $i; ?>][color]" value="<?php echo esc_attr( $c['color'] ?? '' ); ?>" class="smc-color"></td>
							<td><label><input type="checkbox" name="custom_colors[<?php echo (int) $i; ?>][remove]" value="1"> Remove</label></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button smc-add-color">Add color</button></p>
				<p class="description">Removing a color that widgets use makes those widgets fall back to their own color, so check the site after removing one.</p>

				<h2>Fonts</h2>
				<datalist id="smc-fonts">
					<?php foreach ( $fonts as $f ) : ?>
						<option value="<?php echo esc_attr( $f ); ?>">
					<?php endforeach; ?>
				</datalist>
				<table class="widefat striped smc-fonts">
					<thead><tr><th>Name</th><th>Font</th><th>Weight</th><th>Preview</th></tr></thead>
					<tbody>
					<?php
					$google = [];
					foreach ( [ 'system_typography', 'custom_typography' ] as $group ) :
						foreach ( $b[ $group ] as $t ) :
							$family = $t['typography_font_family'] ?? '';
							$weight = (string) ( $t['typography_font_weight'] ?? '' );
							if ( $family ) {
								$google[] = $family;
							}
							?>
							<tr>
								<td><?php echo esc_html( $t['title'] ?? $t['_id'] ); ?></td>
								<td><input name="<?php echo esc_attr( $group ); ?>[<?php echo esc_attr( $t['_id'] ); ?>][family]" value="<?php echo esc_attr( $family ); ?>" list="smc-fonts" class="regular-text smc-font" placeholder="Theme default"></td>
								<td><select name="<?php echo esc_attr( $group ); ?>[<?php echo esc_attr( $t['_id'] ); ?>][weight]">
									<?php foreach ( self::WEIGHTS as $k => $label ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $weight, (string) $k ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select></td>
								<td class="smc-font-preview" style="font-family:'<?php echo esc_attr( $family ); ?>',sans-serif;font-weight:<?php echo esc_attr( $weight ?: '400' ); ?>;font-size:18px">Healthy smiles start here</td>
							</tr>
							<?php
						endforeach;
					endforeach;
					?>
					</tbody>
				</table>
				<p class="description">Start typing to pick from Elementor's fonts (Google Fonts, system fonts and any custom fonts). Only the font and weight change here; sizes and spacing stay as set in Elementor.</p>
				<?php if ( $google ) : ?>
					<link rel="stylesheet" href="<?php echo esc_url( 'https://fonts.googleapis.com/css2?' . implode( '&', array_map( fn( $f ) => 'family=' . rawurlencode( $f ) . ':wght@300;400;500;600;700;800;900', array_unique( $google ) ) ) . '&display=swap' ); ?>">
				<?php endif; ?>

				<?php submit_button( 'Save brand' ); ?>
			</form>

			<?php $this->render_history(); ?>
		</div>

		<style>
			.smc-brand .smc-image-preview { width: 220px; min-height: 60px; background: #f6f7f7 repeating-conic-gradient(#e7e8e9 0 25%, transparent 0 50%) 0 0 / 16px 16px; border: 1px solid #dcdcde; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; padding: 8px; }
			.smc-brand .smc-image-preview img { max-width: 100%; max-height: 120px; }
			.smc-brand .smc-image-preview span { color: #787c82; }
			.smc-brand .smc-colors, .smc-brand .smc-fonts { max-width: 900px; }
			.smc-brand .smc-colors td, .smc-brand .smc-fonts td { vertical-align: middle; }
		</style>
		<script>
		jQuery( function ( $ ) {
			$( '.smc-color' ).wpColorPicker();

			$( '.smc-add-color' ).on( 'click', function () {
				var i = Date.now();
				var row = $( '<tr><td><input name="custom_colors[' + i + '][title]" class="regular-text" placeholder="Color name"></td><td><input name="custom_colors[' + i + '][color]" class="smc-color"></td><td><button type="button" class="button-link smc-drop">Cancel</button></td></tr>' );
				$( '.smc-colors tbody' ).append( row );
				row.find( '.smc-color' ).wpColorPicker();
				row.find( 'input' ).first().focus();
			} );
			$( document ).on( 'click', '.smc-drop', function () { $( this ).closest( 'tr' ).remove(); } );

			$( '.smc-image-pick' ).on( 'click', function () {
				var box = $( this ).closest( '.smc-image' );
				var frame = wp.media( { title: 'Choose image', library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var src = ( a.sizes && a.sizes.medium ) ? a.sizes.medium.url : a.url;
					box.find( 'input' ).val( a.id );
					box.find( '.smc-image-preview' ).html( '<img src="' + src + '" alt="">' );
					box.find( '.smc-image-remove' ).show();
				} );
				frame.open();
			} );
			$( '.smc-image-remove' ).on( 'click', function () {
				var box = $( this ).closest( '.smc-image' );
				box.find( 'input' ).val( 0 );
				box.find( '.smc-image-preview' ).html( '<span>None</span>' );
				$( this ).hide();
			} );

			// Live font preview (Google Fonts load on demand).
			$( '.smc-font, .smc-fonts select' ).on( 'change input', function () {
				var row = $( this ).closest( 'tr' ), fam = row.find( '.smc-font' ).val(), w = row.find( 'select' ).val() || '400';
				if ( fam ) {
					$( 'head' ).append( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' + encodeURIComponent( fam ) + ':wght@300;400;500;600;700;800;900&display=swap">' );
				}
				row.find( '.smc-font-preview' ).css( { fontFamily: "'" + fam + "', sans-serif", fontWeight: w } );
			} );
		} );
		</script>
		<?php
	}

	private function render_history() {
		$h = (array) get_option( self::HISTORY, [] );
		echo '<hr><h2>Restore</h2>';
		if ( ! $h ) {
			echo '<p class="description">Each time you save, the previous logo, favicon, colors and fonts are kept here (the last 10) so you can go back.</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Saved over on</th><th>By</th><th>Colors</th><th></th></tr></thead><tbody>';
		foreach ( $h as $i => $snap ) {
			$user   = get_userdata( $snap['user'] ?? 0 );
			$swatch = '';
			foreach ( array_merge( $snap['system_colors'] ?? [], $snap['custom_colors'] ?? [] ) as $c ) {
				if ( ! empty( $c['color'] ) ) {
					$swatch .= '<span title="' . esc_attr( $c['title'] ) . '" style="display:inline-block;width:18px;height:18px;border-radius:3px;margin-right:3px;border:1px solid #ccc;background:' . esc_attr( $c['color'] ) . '"></span>';
				}
			}
			echo '<tr><td>' . esc_html( wp_date( 'M j, Y g:i a', $snap['time'] ) ) . '</td><td>' . esc_html( $user ? $user->display_name : '-' ) . '</td><td>' . $swatch . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<form method="post" onsubmit="return confirm(\'Restore this version of the logo, favicon, colors and fonts?\');">';
			wp_nonce_field( 'smc_brand_restore' );
			echo '<input type="hidden" name="smc_action" value="restore"><input type="hidden" name="index" value="' . (int) $i . '"><button class="button">Restore</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}
}
