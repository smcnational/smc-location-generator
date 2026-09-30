<?php
/**
 * Sets up a single-location site from a starter template.
 *
 *     wp smc site init                     # writes site-setup.json with the starter's values
 *     nano site-setup.json
 *     wp smc site setup site-setup.json    # shows the plan, then asks
 *     wp smc site checklist
 *     wp smc site undo                     # back to the starter
 */

defined( 'ABSPATH' ) || exit;

class SMC_Site_Command {

	public function __construct() {
		// Run as an admin so content isn't filtered by kses (iframes, scripts in HTML widgets).
		if ( ! get_current_user_id() ) {
			$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ] );
			if ( $admins ) {
				wp_set_current_user( $admins[0]->ID );
			}
		}
	}

	/**
	 * Writes an intake file with the starter's current values and service pages.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Where to write it. Default: site-setup.json
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc site init
	 */
	public function init( $args, $assoc ) {
		$file = $assoc['file'] ?? 'site-setup.json';
		if ( file_exists( $file ) ) {
			WP_CLI::error( "$file already exists." );
		}
		$out            = SMC_Site_Setup::blank();
		$out['from']    = SMC_Site_Setup::detect();
		$out['doctors'] = [ [ 'name' => '', 'credentials' => '', 'title' => '' ] ];
		list( , $services )         = SMC_Site_Setup::services();
		$out['_services_available'] = array_map( fn( $s ) => $s[2], $services );
		file_put_contents( $file, wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		WP_CLI::success( "Wrote $file. Fill in the blank fields, list services to remove under services.remove, then run: wp smc site setup $file" );
	}

	/**
	 * Applies an intake file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The intake JSON.
	 *
	 * [--dry-run]
	 * : Only show the plan.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc site setup site-setup.json --dry-run
	 */
	public function setup( $args, $assoc ) {
		$json = json_decode( (string) @file_get_contents( $args[0] ), true ); // phpcs:ignore
		if ( ! is_array( $json ) ) {
			WP_CLI::error( "Could not read {$args[0]}. Check the JSON with: python3 -m json.tool {$args[0]}" );
		}
		try {
			$plan = ( new SMC_Site_Setup( $json, true ) )->run();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$this->print_report( $plan );
		if ( ! empty( $assoc['dry-run'] ) ) {
			return;
		}
		WP_CLI::confirm( 'Apply this setup?', $assoc );
		try {
			$r = ( new SMC_Site_Setup( $json, false ) )->run();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() . ' Anything changed before the error can be undone with: wp smc site undo' );
		}
		delete_transient( 'smc_site_setup_scan' );
		foreach ( $r['leftovers'] as $v => $posts ) {
			WP_CLI::warning( "Starter value \"$v\" is still in: " . implode( ', ', $posts ) );
		}
		WP_CLI::success( sprintf( 'Setup applied to %d item(s). Next: wp smc site checklist', count( $r['posts'] ) ) );
	}

	/**
	 * Shows the build checklist.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc site checklist
	 */
	public function checklist( $args, $assoc ) {
		$c    = SMC_Site_Setup::checklist();
		$mark = [ 'ok' => 'OK', 'warn' => 'CHECK', 'fail' => 'TODO', 'info' => 'INFO' ];
		$rows = array_map(
			fn( $i ) => [
				'group'  => $i['group'],
				'status' => $mark[ $i['status'] ],
				'item'   => $i['label'],
				'detail' => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( str_replace( '</li>', '; ', $i['detail'] ) ) ) ) ),
			],
			$c['items']
		);
		WP_CLI\Utils\format_items( 'table', $rows, [ 'group', 'status', 'item', 'detail' ] );
		WP_CLI::log( sprintf( '%d to do, %d to check. Tick the manual items under Locations > Site Setup.', $c['fails'], $c['warns'] ) );
	}

	/**
	 * Puts the site back to the starter template.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 */
	public function undo( $args, $assoc ) {
		WP_CLI::confirm( 'Undo the site setup? Edits made to changed pages since setup are lost.', $assoc );
		try {
			$n = SMC_Site_Setup::undo();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( 'Restored %d item(s) and %d service page(s), %d menu item(s); removed %d doctor(s).', $n['posts'], $n['pages'], $n['menu_items'], $n['team'] ) );
	}

	/**
	 * Keeps the setup and deletes the backups (no undo afterwards).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 */
	public function finalize( $args, $assoc ) {
		WP_CLI::confirm( 'Delete the setup backups? Undo won\'t be possible afterwards.', $assoc );
		SMC_Site_Setup::finalize();
		WP_CLI::success( 'Finalized.' );
	}

	private function print_report( array $r ) {
		$pairs = function ( $title, $rows, $keys ) {
			if ( ! $rows ) {
				return;
			}
			WP_CLI::log( WP_CLI::colorize( "%G$title%n" ) );
			WP_CLI\Utils\format_items( 'table', array_map( fn( $x ) => array_combine( $keys, array_map( fn( $c ) => str_replace( '<br>', ' / ', (string) $c ), array_slice( $x, 0, count( $keys ) ) ) ), $rows ), $keys );
		};
		$pairs( 'Replacements', $r['replacements'], [ 'item', 'starter', 'new' ] );
		$pairs( 'Location details', array_map( fn( $x ) => 'map_embed' === $x[0] ? [ $x[0], $x[1] ? '(map)' : '', '(new map)' ] : $x, $r['term'] ), [ 'field', 'now', 'new' ] );
		$pairs( 'Site settings', $r['options'], [ 'setting', 'now', 'new' ] );
		$pairs( 'Content', array_map( fn( $p ) => [ $p['id'], $p['type'], $p['title'], $p['count'], $p['slug'] ? "/{$p['slug'][0]}/ -> /{$p['slug'][1]}/" : '' ], $r['posts'] ), [ 'id', 'type', 'title', 'replacements', 'url' ] );
		$pairs( 'Set to draft', array_map( fn( $d ) => [ $d[0], $d[1], '/' . $d[2] . '/' ], $r['drafted'] ), [ 'id', 'title', 'path' ] );
		$pairs( 'Menu items removed', $r['menu_items'], [ 'menu', 'item' ] );
		if ( $r['team'] ) {
			WP_CLI::log( 'Doctors to add: ' . implode( ', ', $r['team'] ) );
		}
		if ( $r['tagged'] ) {
			WP_CLI::log( "Pages to tie to the location: {$r['tagged']}" );
		}
		foreach ( array_unique( $r['warnings'] ) as $w ) {
			WP_CLI::warning( $w );
		}
	}
}
