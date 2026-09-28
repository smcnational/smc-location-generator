<?php
/**
 * Locations > Settings
 *
 * Site-wide display settings for the location shortcodes.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Settings {

	const OPTION = 'smc_location_settings';
	const PAGE   = 'smc-location-settings';
	const GROUP  = 'smc_location';

	public static function defaults() {
		return [
			'map_height'         => '450px',
			'map_height_mobile'  => '',
			'hours_style'        => 'lines',
			'hours_group'        => 1,
			'hours_day_names'    => 'short',
			'hours_short_labels' => [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ],
			'hours_range_sep'    => ' - ',
			'hours_show_closed'  => 1,
			'hours_bold'         => 1,
			'hide_empty_social'  => 1,
			'hide_empty_buttons' => 1,
			'email_label_default' => 'Email Us',
			'form_height'        => '600px',
		];
	}

	/** Hours display options for [location_hours]. */
	public static function hours_options() {
		return [
			'style'        => self::get( 'hours_style' ),
			'group'        => (bool) self::get( 'hours_group' ),
			'day_names'    => self::get( 'hours_day_names' ),
			'short_labels' => array_values( (array) self::get( 'hours_short_labels' ) ),
			'range_sep'    => (string) self::get( 'hours_range_sep' ),
			'show_closed'  => (bool) self::get( 'hours_show_closed' ),
			'bold'         => (bool) self::get( 'hours_bold' ),
		];
	}

	public static function get( $key ) {
		$o = wp_parse_args( (array) get_option( self::OPTION, [] ), self::defaults() );
		return $o[ $key ] ?? null;
	}

	/** "450" -> "450px", "60vh" stays, anything else -> "". */
	public static function css_height( $v ) {
		$v = strtolower( preg_replace( '/\s+/', '', (string) $v ) );
		if ( ctype_digit( $v ) ) {
			$v .= 'px';
		}
		return preg_match( '/^\d{2,4}(px|vh)$/', $v ) ? $v : '';
	}

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( SMC_LOCATION_FILE ), [ __CLASS__, 'action_link' ] );
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Settings</a>' );
		return $links;
	}

	public static function menu() {
		add_submenu_page( 'smc-locations', 'Location Settings', 'Settings', 'manage_options', self::PAGE, [ __CLASS__, 'page' ] );
	}

	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);

		$opt = self::OPTION;

		add_settings_section(
			'hours',
			'Hours',
			function () {
				echo '<p>How <code>[location_hours]</code> shows hours everywhere on the site. A single shortcode can still override these, e.g. <code>[location_hours style="table"]</code>.</p>';
			},
			self::PAGE
		);

		add_settings_field(
			'hours_style',
			'Layout',
			function () use ( $opt ) {
				$cur = self::get( 'hours_style' );
				foreach ( [ 'lines' => 'Lines &nbsp;<code>Mon - Wed: 9AM - 5PM</code>', 'table' => 'Table (days and hours in columns)', 'list' => 'Bulleted list' ] as $k => $label ) {
					printf( '<label><input type="radio" name="%s[hours_style]" value="%s" %s> %s</label><br>', esc_attr( $opt ), esc_attr( $k ), checked( $cur, $k, false ), $label ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
			},
			self::PAGE,
			'hours'
		);

		add_settings_field(
			'hours_group',
			'Group days',
			function () use ( $opt ) {
				printf(
					'<label><input type="checkbox" name="%s[hours_group]" value="1" %s> Combine back-to-back days with the same hours (<code>Mon - Wed: 9AM - 5PM</code> instead of three lines)</label>',
					esc_attr( $opt ),
					checked( (bool) self::get( 'hours_group' ), true, false )
				);
			},
			self::PAGE,
			'hours'
		);

		add_settings_field(
			'hours_day_names',
			'Day names',
			function () use ( $opt ) {
				$cur    = self::get( 'hours_day_names' );
				$labels = array_values( (array) self::get( 'hours_short_labels' ) );
				printf( '<label><input type="radio" name="%s[hours_day_names]" value="full" %s> Full (Monday, Tuesday...)</label><br>', esc_attr( $opt ), checked( $cur, 'full', false ) );
				printf( '<label><input type="radio" name="%s[hours_day_names]" value="short" %s> Short:</label> ', esc_attr( $opt ), checked( $cur, 'short', false ) );
				foreach ( array_values( SMC_Location_Fields::DAYS ) as $i => $day ) {
					printf(
						'<input name="%s[hours_short_labels][]" value="%s" class="small-text" style="width:4.5em" title="%s" aria-label="Short name for %s"> ',
						esc_attr( $opt ),
						esc_attr( $labels[ $i ] ?? substr( $day, 0, 3 ) ),
						esc_attr( $day ),
						esc_attr( $day )
					);
				}
				echo '<p class="description">Edit the short names to match the site, e.g. <code>Thurs</code> or <code>Tues</code>.</p>';
			},
			self::PAGE,
			'hours'
		);

		add_settings_field(
			'hours_range_sep',
			'Between grouped days',
			function () use ( $opt ) {
				printf(
					'<input name="%s[hours_range_sep]" value="%s" class="small-text" style="width:5em"><p class="description">What goes between the first and last day of a group. Default is a hyphen with spaces; <code> to </code> also works.</p>',
					esc_attr( $opt ),
					esc_attr( self::get( 'hours_range_sep' ) )
				);
			},
			self::PAGE,
			'hours'
		);

		add_settings_field(
			'hours_extras',
			'Other',
			function () use ( $opt ) {
				printf(
					'<label><input type="checkbox" name="%s[hours_show_closed]" value="1" %s> Show days marked <code>Closed</code></label><br>',
					esc_attr( $opt ),
					checked( (bool) self::get( 'hours_show_closed' ), true, false )
				);
				printf(
					'<label><input type="checkbox" name="%s[hours_bold]" value="1" %s> Bold day names</label>',
					esc_attr( $opt ),
					checked( (bool) self::get( 'hours_bold' ), true, false )
				);
				echo '<p class="description">Days left blank on a location are always left off.</p>';
			},
			self::PAGE,
			'hours'
		);

		add_settings_section(
			'social',
			'Buttons &amp; links',
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'email_label_default',
			'Email button text',
			function () use ( $opt ) {
				printf(
					'<input name="%s[email_label_default]" id="email_label_default" class="regular-text" value="%s" placeholder="Email Us"><p class="description">Default for <code>[location field="email_label"]</code>. A location can set its own text on its edit screen.</p>',
					esc_attr( $opt ),
					esc_attr( self::get( 'email_label_default' ) )
				);
			},
			self::PAGE,
			'social',
			[ 'label_for' => 'email_label_default' ]
		);

		add_settings_field(
			'hide_empty_social',
			'Empty icons',
			function () use ( $opt ) {
				printf(
					'<label><input type="checkbox" name="%s[hide_empty_social]" value="1" %s> Hide icons with no link in Elementor\'s Social Icons widget</label><p class="description">Set each icon\'s link to a dynamic Shortcode tag, e.g. <code>[location field="facebook_url"]</code>. Locations without that link won\'t show the icon. In the Elementor editor, the icon is dimmed instead of hidden so you can still edit it.</p>',
					esc_attr( $opt ),
					checked( (bool) self::get( 'hide_empty_social' ), true, false )
				);
				printf(
					'<br><label><input type="checkbox" name="%s[hide_empty_buttons]" value="1" %s> Hide buttons whose link comes from a location shortcode that\'s empty</label><p class="description">For example, an "Email Us" button linked to <code>[location field="email_link"]</code> disappears at locations with no email. Dimmed in the Elementor editor.</p>',
					esc_attr( $opt ),
					checked( (bool) self::get( 'hide_empty_buttons' ), true, false )
				);
			},
			self::PAGE,
			'social'
		);

		add_settings_section(
			'form',
			'Embedded form',
			function () {
				echo '<p>Starting height of every <code>[location_form]</code>. JotForm then resizes the frame to fit the form, so this only matters while it loads.</p>';
			},
			self::PAGE
		);

		add_settings_field(
			'form_height',
			'Starting height',
			function () use ( $opt ) {
				printf(
					'<input name="%s[form_height]" id="form_height" class="small-text" value="%s" placeholder="600px">',
					esc_attr( $opt ),
					esc_attr( self::get( 'form_height' ) )
				);
			},
			self::PAGE,
			'form',
			[ 'label_for' => 'form_height' ]
		);

		add_settings_section(
			'map',
			'Google Map',
			function () {
				echo '<p>Height of every <code>[location_map]</code> on the site. Use a number of pixels (<code>450</code>) or a share of the screen height (<code>60vh</code>).</p>';
			},
			self::PAGE
		);

		add_settings_field(
			'map_height',
			'Map height',
			function () {
				printf(
					'<input name="%s[map_height]" id="map_height" class="small-text" value="%s" placeholder="450px">',
					esc_attr( self::OPTION ),
					esc_attr( self::get( 'map_height' ) )
				);
			},
			self::PAGE,
			'map',
			[ 'label_for' => 'map_height' ]
		);

		add_settings_field(
			'map_height_mobile',
			'Map height on phones',
			function () {
				printf(
					'<input name="%s[map_height_mobile]" id="map_height_mobile" class="small-text" value="%s" placeholder="Same"><p class="description">Screens 767px wide and under. Leave blank to use the same height as above.</p>',
					esc_attr( self::OPTION ),
					esc_attr( self::get( 'map_height_mobile' ) )
				);
			},
			self::PAGE,
			'map',
			[ 'label_for' => 'map_height_mobile' ]
		);
	}

	public static function sanitize( $in ) {
		$in  = (array) $in;
		$out = wp_parse_args( (array) get_option( self::OPTION, [] ), self::defaults() );

		// Hours display.
		$out['hours_style']       = in_array( $in['hours_style'] ?? '', [ 'lines', 'table', 'list' ], true ) ? $in['hours_style'] : 'lines';
		$out['hours_group']       = empty( $in['hours_group'] ) ? 0 : 1;
		$out['hours_day_names']   = 'full' === ( $in['hours_day_names'] ?? '' ) ? 'full' : 'short';
		$out['hours_show_closed'] = empty( $in['hours_show_closed'] ) ? 0 : 1;
		$out['hours_bold']        = empty( $in['hours_bold'] ) ? 0 : 1;
		$out['hide_empty_social'] = empty( $in['hide_empty_social'] ) ? 0 : 1;
		$out['hide_empty_buttons'] = empty( $in['hide_empty_buttons'] ) ? 0 : 1;
		$label                     = trim( sanitize_text_field( $in['email_label_default'] ?? '' ) );
		$out['email_label_default'] = '' === $label ? 'Email Us' : $label;
		$sep                      = (string) ( $in['hours_range_sep'] ?? '' );
		$out['hours_range_sep']   = '' === trim( $sep ) ? ' - ' : wp_strip_all_tags( $sep );
		$labels                   = array_values( (array) ( $in['hours_short_labels'] ?? [] ) );
		$defaults                 = self::defaults()['hours_short_labels'];
		foreach ( $defaults as $i => $d ) {
			$l                  = trim( sanitize_text_field( $labels[ $i ] ?? '' ) );
			$out['hours_short_labels'][ $i ] = '' === $l ? $d : $l;
		}

		foreach ( [ 'map_height' => 'Map height', 'map_height_mobile' => 'Map height on phones', 'form_height' => 'Form starting height' ] as $k => $label ) {
			$raw = trim( (string) ( $in[ $k ] ?? '' ) );
			if ( '' === $raw ) {
				$out[ $k ] = 'map_height_mobile' === $k ? '' : self::defaults()[ $k ];
				continue;
			}
			$h = self::css_height( $raw );
			if ( '' === $h ) {
				add_settings_error( self::OPTION, $k, "$label \"$raw\" wasn't saved. Use a number like 450 or a value like 60vh." );
				continue;
			}
			$out[ $k ] = $h;
		}
		return $out;
	}

	/** Hours preview using the first location that has hours. */
	private static function preview() {
		if ( ! class_exists( 'SMC_Location_Manager' ) ) {
			return;
		}
		foreach ( SMC_Location_Manager::locations() as $t ) {
			foreach ( array_keys( SMC_Location_Fields::DAYS ) as $d ) {
				if ( '' !== (string) get_term_meta( $t->term_id, "hours_$d", true ) ) {
					echo '<h2>Hours preview</h2><p class="description">' . esc_html( $t->name ) . "'s hours with the saved settings. The site's own fonts and colors will apply on the front end.</p>";
					echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px 20px;max-width:420px;font-size:15px;line-height:1.7">';
					echo SMC_Location_Fields::hours( [ 'location' => $t->slug ] ); // phpcs:ignore WordPress.Security.EscapeOutput
					echo '</div>';
					return;
				}
			}
		}
		echo '<h2>Hours preview</h2><p class="description">Add hours to a location under Locations &gt; All Locations to see a preview here.</p>';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Location Settings</h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<?php self::preview(); ?>

		</div>
		<?php
	}
}
