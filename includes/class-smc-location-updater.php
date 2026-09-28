<?php
/**
 * Updates from GitHub releases.
 *
 * Each site checks the plugin's GitHub repository for new releases and offers them on the
 * normal Plugins / Updates screens, like any plugin from WordPress.org. Private repositories
 * work with a read-only access token.
 *
 * Configure in wp-config.php (preferred) or under Locations > Settings > Updates:
 *   define( 'SMC_LOCATIONS_GITHUB_REPO', 'smcnational/smc-location-generator' );
 *   define( 'SMC_LOCATIONS_GITHUB_TOKEN', 'github_pat_...' );
 *
 * Releases: a tag like v1.15.0 is a normal release; v1.15.0-beta.1 is a pre-release, offered
 * only to sites on the Beta channel (use it for staging). If the release has a .zip attached,
 * that's installed; otherwise GitHub's source zip is used.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Updater {

	const OPTION       = 'smc_locations_updater';
	const CACHE        = 'smc_locations_release';
	const DEFAULT_REPO = 'smcnational/smc-location-generator';
	const FOLDER       = 'smc-location-generator';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject_update' ] );
		add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_pre_download', [ __CLASS__, 'download' ], 10, 4 );
		add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_folder' ], 10, 4 );
		add_filter( 'auto_update_plugin', [ __CLASS__, 'auto_update' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'after_update' ], 10, 2 );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ], 20 );
	}

	/* ========== Settings ========== */

	public static function options() {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [ 'repo' => '', 'token' => '', 'channel' => 'stable', 'auto' => 0 ] );
	}

	public static function repo() {
		if ( defined( 'SMC_LOCATIONS_GITHUB_REPO' ) && SMC_LOCATIONS_GITHUB_REPO ) {
			return trim( SMC_LOCATIONS_GITHUB_REPO, '/' );
		}
		return self::options()['repo'] ?: self::DEFAULT_REPO;
	}

	private static function token() {
		if ( defined( 'SMC_LOCATIONS_GITHUB_TOKEN' ) && SMC_LOCATIONS_GITHUB_TOKEN ) {
			return SMC_LOCATIONS_GITHUB_TOKEN;
		}
		return (string) self::options()['token'];
	}

	private static function basename() {
		return plugin_basename( SMC_LOCATION_FILE );
	}

	private static function slug() {
		return dirname( self::basename() );
	}

	public static function current_version() {
		$data = get_file_data( SMC_LOCATION_FILE, [ 'Version' => 'Version' ] );
		return $data['Version'];
	}

	/* ========== GitHub ========== */

	private static function headers( $accept = 'application/vnd.github+json' ) {
		$h = [
			'Accept'               => $accept,
			'User-Agent'           => 'SMC-Locations-Updater/' . self::current_version() . '; ' . home_url(),
			'X-GitHub-Api-Version' => '2022-11-28',
		];
		if ( self::token() ) {
			$h['Authorization'] = 'Bearer ' . self::token();
		}
		return $h;
	}

	/**
	 * Newest release on this site's channel, cached for 6 hours. Clicking "Check again" on
	 * Dashboard > Updates skips the cache.
	 *
	 * @return array|null [ version, tag, url, package, notes, published, prerelease ] or null.
	 */
	public static function latest( $force = false ) {
		$force = $force || ( is_admin() && ! empty( $_GET['force-check'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key   = self::CACHE . '_' . md5( self::repo() . self::options()['channel'] );
		if ( ! $force ) {
			$cached = get_site_transient( $key );
			if ( false !== $cached ) {
				return $cached ?: null;
			}
		}

		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases?per_page=20',
			[ 'headers' => self::headers(), 'timeout' => 15 ]
		);
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( is_wp_error( $res ) || 200 !== $code ) {
			$msg = is_wp_error( $res ) ? $res->get_error_message() : self::http_error( $code );
			update_option( self::OPTION . '_status', [ 'time' => time(), 'error' => $msg ], false );
			set_site_transient( $key, [], HOUR_IN_SECONDS ); // Back off for an hour.
			return null;
		}

		$beta    = 'beta' === self::options()['channel'];
		$release = null;
		foreach ( (array) json_decode( wp_remote_retrieve_body( $res ), true ) as $r ) {
			if ( ! empty( $r['draft'] ) || ( ! $beta && ! empty( $r['prerelease'] ) ) ) {
				continue;
			}
			$version = ltrim( (string) $r['tag_name'], 'vV' );
			if ( ! $release || version_compare( $version, $release['version'], '>' ) ) {
				$package = '';
				foreach ( (array) ( $r['assets'] ?? [] ) as $a ) {
					if ( '.zip' === substr( strtolower( $a['name'] ), -4 ) ) {
						// Private repos need the API URL (with the token); public ones can use the direct link.
						$package = self::token() ? $a['url'] : $a['browser_download_url'];
						break;
					}
				}
				$release = [
					'version'    => $version,
					'tag'        => $r['tag_name'],
					'url'        => $r['html_url'],
					'package'    => $package ?: $r['zipball_url'],
					'notes'      => (string) ( $r['body'] ?? '' ),
					'published'  => $r['published_at'] ?? '',
					'prerelease' => ! empty( $r['prerelease'] ),
				];
			}
		}

		update_option( self::OPTION . '_status', [ 'time' => time(), 'error' => $release ? '' : 'No releases found.' ], false );
		set_site_transient( $key, $release ?: [], 6 * HOUR_IN_SECONDS );
		return $release;
	}

	private static function http_error( $code ) {
		if ( 401 === $code ) {
			return 'GitHub rejected the access token (401). Check that it hasn\'t expired.';
		}
		if ( 404 === $code ) {
			return 'Repository ' . self::repo() . ' not found (404). If it\'s private, add an access token.';
		}
		if ( 403 === $code ) {
			return 'GitHub refused the request (403), usually the hourly limit for sites without a token. Adding a token fixes it.';
		}
		return "GitHub returned HTTP $code.";
	}

	/* ========== WordPress update hooks ========== */

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$r = self::latest();
		if ( ! $r ) {
			return $transient;
		}
		$item = (object) [
			'id'           => self::repo(),
			'slug'         => self::slug(),
			'plugin'       => self::basename(),
			'new_version'  => $r['version'],
			'url'          => $r['url'],
			'package'      => $r['package'],
			'requires_php' => '7.4',
			'icons'        => [],
			'banners'      => [],
		];
		if ( version_compare( $r['version'], self::current_version(), '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	/** "View details" popup on the Plugins screen. */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}
		$r = self::latest();
		return (object) [
			'name'          => 'SMC Locations',
			'slug'          => self::slug(),
			'version'       => $r['version'] ?? self::current_version(),
			'author'        => 'SMC National',
			'homepage'      => 'https://github.com/' . self::repo(),
			'requires_php'  => '7.4',
			'last_updated'  => $r['published'] ?? '',
			'download_link' => $r['package'] ?? '',
			'sections'      => [
				'changelog'   => $r ? self::notes_html( $r ) : '<p>Could not reach GitHub.</p>',
				'description' => '<p>Location tools for SMC multi-location sites: location details and shortcodes, Add Location (cloning), reviews, brand settings and scans.</p>',
			],
		];
	}

	/** Minimal Markdown for release notes: headings, bullets, bold, links. */
	private static function notes_html( $r ) {
		$lines = preg_split( '/\R/', esc_html( $r['notes'] ?: 'No release notes.' ) );
		$out   = '<h4>' . esc_html( $r['tag'] ) . '</h4>';
		$list  = false;
		foreach ( $lines as $l ) {
			$l = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $l );
			$l = preg_replace( '/\[([^\]]+)\]\((https?:[^)\s]+)\)/', '<a href="$2" target="_blank">$1</a>', $l );
			if ( preg_match( '/^\s*[-*]\s+(.*)$/', $l, $m ) ) {
				$out .= ( $list ? '' : '<ul>' ) . '<li>' . $m[1] . '</li>';
				$list = true;
				continue;
			}
			if ( $list ) {
				$out .= '</ul>';
				$list = false;
			}
			if ( preg_match( '/^#+\s*(.*)$/', $l, $m ) ) {
				$out .= '<h4>' . $m[1] . '</h4>';
			} elseif ( '' !== trim( $l ) ) {
				$out .= '<p>' . $l . '</p>';
			}
		}
		return $out . ( $list ? '</ul>' : '' );
	}

	/**
	 * Downloads our package with the GitHub token when needed. GitHub answers with a redirect
	 * to a temporary storage link, which must be fetched without the token.
	 */
	public static function download( $reply, $package, $upgrader, $hook_extra = [] ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, 'https://api.github.com/repos/' . self::repo() . '/' ) ) {
			return $reply;
		}
		$accept = false !== strpos( $package, '/releases/assets/' ) ? 'application/octet-stream' : 'application/vnd.github+json';
		$res    = wp_remote_get( $package, [ 'headers' => self::headers( $accept ), 'redirection' => 0, 'timeout' => 30 ] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, [ 301, 302, 303, 307, 308 ], true ) ) {
			$location = wp_remote_retrieve_header( $res, 'location' );
			return $location ? download_url( $location, 300 ) : new WP_Error( 'smc_update', 'GitHub sent an empty download link.' );
		}
		if ( 200 === $code ) {
			$tmp = wp_tempnam( 'smc-locations.zip' );
			file_put_contents( $tmp, wp_remote_retrieve_body( $res ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $tmp;
		}
		return new WP_Error( 'smc_update', 'Download failed: ' . self::http_error( $code ) );
	}

	/** GitHub's source zips unpack as owner-repo-abc123/; rename to the plugin's folder. */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( ( $hook_extra['plugin'] ?? '' ) !== self::basename() ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . self::slug() . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) {
			return $want;
		}
		return new WP_Error( 'smc_update', 'Could not rename the downloaded plugin folder.' );
	}

	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && self::basename() === $item->plugin && self::options()['auto'] ) {
			return true;
		}
		return $update;
	}

	public static function after_update( $upgrader, $extra ) {
		if ( 'plugin' === ( $extra['type'] ?? '' ) && in_array( self::basename(), (array) ( $extra['plugins'] ?? [] ), true ) ) {
			delete_site_transient( self::CACHE . '_' . md5( self::repo() . self::options()['channel'] ) );
		}
	}

	/* ========== Settings section (Locations > Settings) ========== */

	public static function register_settings() {
		if ( ! class_exists( 'SMC_Location_Settings' ) ) {
			return;
		}
		register_setting( SMC_Location_Settings::GROUP, self::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] );
		add_settings_section( 'updates', 'Updates', [ __CLASS__, 'status' ], SMC_Location_Settings::PAGE );

		$opt = self::OPTION;
		$o   = self::options();

		add_settings_field(
			'smc_updates_channel',
			'Updates to install',
			function () use ( $opt, $o ) {
				printf( '<label><input type="radio" name="%s[channel]" value="stable" %s> Stable releases</label><br>', esc_attr( $opt ), checked( $o['channel'], 'stable', false ) );
				printf( '<label><input type="radio" name="%s[channel]" value="beta" %s> Beta: stable releases plus pre-releases (use on staging sites)</label>', esc_attr( $opt ), checked( $o['channel'], 'beta', false ) );
			},
			SMC_Location_Settings::PAGE,
			'updates'
		);

		add_settings_field(
			'smc_updates_auto',
			'Automatic updates',
			function () use ( $opt, $o ) {
				printf( '<label><input type="checkbox" name="%s[auto]" value="1" %s> Install new versions automatically</label><p class="description">WordPress checks twice a day. Leave off to update from Dashboard &gt; Updates yourself.</p>', esc_attr( $opt ), checked( (bool) $o['auto'], true, false ) );
			},
			SMC_Location_Settings::PAGE,
			'updates'
		);

		add_settings_field(
			'smc_updates_token',
			'GitHub access token',
			function () use ( $opt ) {
				if ( defined( 'SMC_LOCATIONS_GITHUB_TOKEN' ) && SMC_LOCATIONS_GITHUB_TOKEN ) {
					echo '<p>Set in <code>wp-config.php</code>.</p>';
					return;
				}
				$has = '' !== (string) self::options()['token'];
				printf(
					'<input type="password" name="%s[token]" class="regular-text" autocomplete="new-password" placeholder="%s"> %s<p class="description">Needed if the repository is private. Use a fine-grained token with read-only <em>Contents</em> access to just this repository. It\'s saved in the database; <code>define( \'SMC_LOCATIONS_GITHUB_TOKEN\', \'...\' );</code> in wp-config.php is safer.</p>',
					esc_attr( $opt ),
					$has ? 'Saved (leave blank to keep)' : 'github_pat_...',
					$has ? sprintf( '<label><input type="checkbox" name="%s[clear_token]" value="1"> Remove</label>', esc_attr( $opt ) ) : ''
				);
			},
			SMC_Location_Settings::PAGE,
			'updates'
		);

		if ( ! defined( 'SMC_LOCATIONS_GITHUB_REPO' ) ) {
			add_settings_field(
				'smc_updates_repo',
				'Repository',
				function () use ( $opt, $o ) {
					printf( '<input name="%s[repo]" value="%s" class="regular-text code" placeholder="%s">', esc_attr( $opt ), esc_attr( $o['repo'] ), esc_attr( self::DEFAULT_REPO ) );
				},
				SMC_Location_Settings::PAGE,
				'updates'
			);
		}
	}

	public static function status() {
		$r      = self::latest();
		$status = (array) get_option( self::OPTION . '_status', [] );
		$cur    = self::current_version();
		echo '<table class="widefat striped" style="max-width:700px;margin-bottom:10px"><tbody>';
		echo '<tr><td style="width:180px">Installed</td><td>' . esc_html( $cur ) . '</td></tr>';
		echo '<tr><td>Newest on GitHub</td><td>';
		if ( $r ) {
			echo esc_html( $r['version'] ) . ( $r['prerelease'] ? ' (pre-release)' : '' );
			if ( version_compare( $r['version'], $cur, '>' ) ) {
				echo ' &nbsp;<a class="button button-small" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">Update now</a>';
			} else {
				echo ' &nbsp;<span style="color:#008a20">Up to date</span>';
			}
		} else {
			echo '<span style="color:#b32d2e">' . esc_html( $status['error'] ?? 'Not checked yet.' ) . '</span>';
		}
		echo '</td></tr>';
		echo '<tr><td>Repository</td><td><a href="' . esc_url( 'https://github.com/' . self::repo() . '/releases' ) . '" target="_blank"><code>' . esc_html( self::repo() ) . '</code></a></td></tr>';
		if ( ! empty( $status['time'] ) ) {
			echo '<tr><td>Last checked</td><td>' . esc_html( wp_date( 'M j, Y g:i a', $status['time'] ) ) . ' &nbsp;<a href="' . esc_url( admin_url( 'update-core.php?force-check=1' ) ) . '">Check now</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function sanitize( $in ) {
		$in  = (array) $in;
		$old = self::options();
		$out = [
			'repo'    => preg_match( '#^[\w.-]+/[\w.-]+$#', trim( (string) ( $in['repo'] ?? '' ) ) ) ? trim( $in['repo'] ) : '',
			'channel' => 'beta' === ( $in['channel'] ?? '' ) ? 'beta' : 'stable',
			'auto'    => empty( $in['auto'] ) ? 0 : 1,
			'token'   => $old['token'],
		];
		$token = trim( (string) ( $in['token'] ?? '' ) );
		if ( ! empty( $in['clear_token'] ) ) {
			$out['token'] = '';
		} elseif ( '' !== $token ) {
			$out['token'] = sanitize_text_field( $token );
		}
		// Settings changed: check again on next load.
		delete_site_transient( self::CACHE . '_' . md5( self::repo() . $old['channel'] ) );
		delete_site_transient( self::CACHE . '_' . md5( ( $out['repo'] ?: self::DEFAULT_REPO ) . $out['channel'] ) );
		return $out;
	}
}
