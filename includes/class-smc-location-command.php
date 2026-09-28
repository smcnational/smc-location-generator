<?php
/**
 * Adds a location to an SMC multi-location site by cloning an existing one.
 *
 * Typical flow:
 *
 *     wp smc location sources
 *     wp smc location init kenton Marion
 *     nano marion.json
 *     wp smc location clone marion.json
 *     wp smc location undo marion        # if you need to back it out
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Command {

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
	 * Lists the locations on this site that can be cloned.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc location sources
	 */
	public function sources( $args, $assoc ) {
		if ( ! taxonomy_exists( SMC_Location_Cloner::LOC_TAX ) ) {
			WP_CLI::error( 'This site does not use the SMC location system (no location_category taxonomy).' );
		}
		$rows = [];
		foreach ( get_terms( [ 'taxonomy' => SMC_Location_Cloner::LOC_TAX, 'hide_empty' => false ] ) as $t ) {
			if ( 'corporate' === strtolower( $t->name ) ) {
				continue;
			}
			$page   = get_page_by_path( $t->slug, OBJECT, 'page' );
			$rows[] = [
				'location' => $t->name,
				'source'   => $page ? $t->slug : '-',
				'phone'    => get_term_meta( $t->term_id, 'phone_label', true ),
				'address'  => str_replace( [ '<br>', '<br/>', '<br />' ], ', ', (string) get_term_meta( $t->term_id, 'address', true ) ),
				'pages'    => $page ? 1 + count( get_pages( [ 'child_of' => $page->ID, 'post_status' => [ 'publish', 'draft', 'private' ] ] ) ?: [] ) : 0,
			];
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'location', 'source', 'phone', 'address', 'pages' ] );
	}

	/**
	 * Writes a starter config for a new location.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Slug of the location to clone, from `wp smc location sources`.
	 *
	 * <city>
	 * : The new location's city, e.g. "Marion".
	 *
	 * [--file=<path>]
	 * : Where to write the config. Defaults to <city-slug>.json in the current folder.
	 *
	 * [--force]
	 * : Overwrite an existing file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc location init kenton Marion
	 */
	public function init( $args, $assoc ) {
		list( $source, $city ) = $args;
		$page                  = get_page_by_path( trim( $source, '/' ), OBJECT, 'page' );
		if ( ! $page ) {
			WP_CLI::error( "Source page /$source/ not found. Run `wp smc location sources`." );
		}
		$terms = wp_get_post_terms( $page->ID, SMC_Location_Cloner::LOC_TAX );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			WP_CLI::error( "/$source/ has no location term." );
		}
		$cs    = (string) get_term_meta( $terms[0]->term_id, 'city_state', true );
		$state = preg_match( '/,\s*([A-Z]{2})\b/', $cs, $m ) ? $m[1] : 'CHANGE_ME';
		$file  = $assoc['file'] ?? sanitize_title( $city ) . '.json';

		if ( file_exists( $file ) && empty( $assoc['force'] ) ) {
			WP_CLI::error( "$file already exists. Use --force to overwrite." );
		}

		$cfg = [
			'source'         => trim( $source, '/' ),
			'city'           => $city,
			'state'          => $state,
			'phone'          => 'CHANGE_ME',
			'street'         => 'CHANGE_ME',
			'city_state_zip' => "$city, $state CHANGE_ME",
			'booking_link'   => 'CHANGE_ME',
			'email'          => '',
			'email_label'    => '',
			'form'           => '',
			'map'            => '',
			'hours'          => [
				'monday'    => '',
				'tuesday'   => '',
				'wednesday' => '',
				'thursday'  => '',
				'friday'    => '',
				'saturday'  => '',
				'sunday'    => '',
			],
			'social'         => new stdClass(),
			'replace'        => new stdClass(),
		];
		file_put_contents( $file, wp_json_encode( $cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		WP_CLI::success( "Wrote $file. Fill in every CHANGE_ME, then run: wp smc location clone $file" );
	}

	/**
	 * Clones a location. Shows the full plan, then asks before creating anything.
	 *
	 * ## OPTIONS
	 *
	 * <config>
	 * : Path to the location's JSON config (see `wp smc location init`).
	 *
	 * [--dry-run]
	 * : Show the plan and stop.
	 *
	 * [--yes]
	 * : Create without asking for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc location clone marion.json --dry-run
	 *     wp smc location clone marion.json
	 *
	 * @subcommand clone
	 */
	public function clone_location( $args, $assoc ) {
		$cfg = json_decode( (string) @file_get_contents( $args[0] ), true );
		if ( ! is_array( $cfg ) ) {
			WP_CLI::error( "Could not read {$args[0]}: " . json_last_error_msg() . '. Check it with: python3 -m json.tool ' . $args[0] );
		}

		// Plan.
		try {
			$plan   = new SMC_Location_Cloner( $cfg, true );
			$report = $plan->run();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$this->print_report( $report, true );

		if ( ! empty( $assoc['dry-run'] ) ) {
			WP_CLI::success( 'Dry run only. Nothing was created.' );
			return;
		}

		WP_CLI::confirm(
			sprintf(
				'Create %d page(s), %d template(s), %d menu(s) and %d term(s)%s?',
				count( $report['pages'] ),
				count( $report['templates'] ),
				count( $report['menus'] ),
				count( $report['terms'] ),
				$report['warnings'] ? ' despite ' . count( $report['warnings'] ) . ' warning(s)' : ''
			),
			$assoc
		);

		// Create.
		$runner = new SMC_Location_Cloner( $cfg, false );
		try {
			$result = $runner->run();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() . "\nAnything created before the error is logged. Remove it with: wp smc location undo " . $plan->target_slug() );
		}
		$result['warnings'] = array_values( array_diff( $result['warnings'], $report['warnings'] ) );
		$this->print_report( $result, false );

		WP_CLI::success(
			sprintf(
				'Created %d page(s) as %s, %d template(s), %d menu(s), %d term(s).',
				count( $result['pages'] ),
				$cfg['status'] ?? SMC_Location_Cloner::defaults()['status'],
				count( $result['templates'] ),
				count( $result['menus'] ),
				count( $result['terms'] )
			)
		);
		WP_CLI::log( 'To remove everything this created: wp smc location undo ' . $runner->target_slug() );
	}

	/**
	 * Removes everything a clone created: pages, templates, menu and terms.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The clone's slug, from `wp smc location list`.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc location undo marion
	 */
	public function undo( $args, $assoc ) {
		$slug = $args[0];
		$log  = SMC_Location_Cloner::get_log();
		if ( ! isset( $log[ $slug ] ) ) {
			WP_CLI::error( "No clone named \"$slug\". Run `wp smc location list`." );
		}
		$e = $log[ $slug ];

		$edited = SMC_Location_Cloner::modified_since_clone( $slug );
		if ( $edited ) {
			WP_CLI::warning( count( $edited ) . ' item(s) were edited or published after the clone. Undo deletes them anyway:' );
			foreach ( array_slice( $edited, 0, 15 ) as $p ) {
				WP_CLI::log( "  #{$p->ID} {$p->post_title} (modified {$p->post_modified})" );
			}
		}

		$terms = array_sum( array_map( 'count', $e['terms'] ) );
		WP_CLI::confirm(
			sprintf(
				'Permanently delete /%s/ (%d page(s)), %d template(s), %d menu(s) and %d term(s)?',
				$e['path'],
				count( $e['pages'] ),
				count( $e['templates'] ),
				count( $e['menus'] ),
				$terms
			),
			$assoc
		);

		try {
			$counts = SMC_Location_Cloner::undo( $slug );
		} catch ( Exception $ex ) {
			WP_CLI::error( $ex->getMessage() );
		}
		foreach ( $counts['warnings'] as $w ) {
			WP_CLI::warning( $w );
		}
		WP_CLI::success( sprintf( 'Deleted %d page(s), %d template(s), %d menu(s), %d term(s).', $counts['pages'], $counts['templates'], $counts['menus'], $counts['terms'] ) );
	}

	/**
	 * Lists clones that can be undone.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc location list
	 *
	 * @subcommand list
	 */
	public function list_clones( $args, $assoc ) {
		$rows = [];
		foreach ( SMC_Location_Cloner::get_log() as $slug => $e ) {
			$user   = get_userdata( $e['user'] );
			$rows[] = [
				'slug'      => $slug,
				'path'      => "/{$e['path']}/",
				'source'    => "/{$e['source']}/",
				'created'   => wp_date( 'Y-m-d H:i', $e['created'] ),
				'by'        => $user ? $user->user_login : '-',
				'pages'     => count( $e['pages'] ),
				'templates' => count( $e['templates'] ),
			];
		}
		if ( ! $rows ) {
			WP_CLI::log( 'No clones in the undo log.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'slug', 'path', 'source', 'created', 'by', 'pages', 'templates' ] );
	}

	/**
	 * Shows a location's launch checklist.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The location's slug, e.g. kenton.
	 *
	 * [--format=<format>]
	 * : table, csv or json. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc location checklist kenton
	 */
	public function checklist( $args, $assoc ) {
		$term = $this->launch_term( $args[0] );
		$c    = SMC_Location_Launch::checks( $term );
		$rows = [];
		foreach ( $c['items'] as $i ) {
			$rows[] = [
				'status' => strtoupper( 'fail' === $i['status'] ? 'todo' : $i['status'] ),
				'group'  => SMC_Location_Launch::GROUPS[ $i['group'] ],
				'check'  => $i['label'],
				'detail' => trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( preg_replace( '#<details.*</details>#s', '', $i['detail'] ) ) ) ) ),
			];
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, [ 'status', 'group', 'check', 'detail' ] );
		if ( $c['live'] ) {
			WP_CLI::success( "{$term->name} is live." );
		} elseif ( $c['fails'] ) {
			WP_CLI::warning( "{$c['fails']} to do before {$term->name} can be published. Manual checks are ticked on the location's edit screen." );
		} else {
			WP_CLI::success( sprintf( 'Ready. Publish with: wp smc location publish %s (%d page(s), %d template(s))', $term->slug, count( $c['publish']['pages'] ), count( $c['publish']['templates'] ) ) );
		}
	}

	/**
	 * Publishes a location's draft pages and templates, if its launch checklist has nothing left to do.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The location's slug, e.g. kenton.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc location publish kenton
	 */
	public function publish( $args, $assoc ) {
		$term = $this->launch_term( $args[0] );
		$c    = SMC_Location_Launch::checks( $term );
		if ( $c['fails'] ) {
			WP_CLI::error( "{$c['fails']} item(s) still to do. Run: wp smc location checklist {$term->slug}" );
		}
		foreach ( array_merge( $c['publish']['templates'], $c['publish']['pages'] ) as $p ) {
			WP_CLI::log( "  #{$p->ID} {$p->post_title} ({$p->post_status})" );
		}
		WP_CLI::confirm( "Publish these for {$term->name}?", $assoc );
		$res = SMC_Location_Launch::publish( $term );
		if ( is_string( $res ) ) {
			WP_CLI::error( $res );
		}
		foreach ( $res['warnings'] as $w ) {
			WP_CLI::warning( $w );
		}
		WP_CLI::success( "Published {$res['pages']} page(s) and {$res['templates']} template(s). Undo from the location's edit screen within " . SMC_Location_Launch::UNDO_DAYS . ' days.' );
	}

	private function launch_term( $slug ) {
		$term = get_term_by( 'slug', sanitize_title( $slug ), 'location_category' );
		if ( ! $term ) {
			WP_CLI::error( "No location with the slug \"$slug\"." );
		}
		return $term;
	}

	/**
	 * Finds location details typed into templates and pages instead of coming from the location shortcodes.
	 *
	 * ## OPTIONS
	 *
	 * [--templates-only]
	 * : Only scan Theme Builder templates.
	 *
	 * [--pages-only]
	 * : Only scan pages.
	 *
	 * [--format=<format>]
	 * : table, csv or json. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc location scan
	 *     wp smc location scan --templates-only
	 *     wp smc location scan --format=csv > typed-in.csv
	 */
	public function scan( $args, $assoc ) {
		$results = SMC_Location_Scanner::run(
			[
				'templates' => empty( $assoc['pages-only'] ),
				'pages'     => empty( $assoc['templates-only'] ),
			]
		);
		if ( ! $results ) {
			WP_CLI::success( 'Nothing found. All location details come from the location shortcodes.' );
			return;
		}
		$rows = [];
		foreach ( $results as $r ) {
			foreach ( $r['findings'] as $f ) {
				$rows[] = [
					'id'       => $r['id'],
					'item'     => $r['title'],
					'type'     => $r['type'],
					'shows_on' => $r['location'],
					'what'     => $f['kind'],
					'found'    => ( strlen( $f['snippet'] ) > 70 ? substr( $f['snippet'], 0, 67 ) . '...' : $f['snippet'] ),
					'widget'   => $f['widget'],
					'fix'      => $f['fix'],
				];
			}
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, [ 'id', 'item', 'type', 'shows_on', 'what', 'found', 'widget', 'fix' ] );
		WP_CLI::log( count( $rows ) . ' item(s) in ' . count( $results ) . ' template(s)/page(s). Edit: wp-admin/post.php?post=<id>&action=elementor' );
	}

	/**
	 * Exports locations, reviews, brand and settings to one JSON file.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Where to write it. Default smc-locations-<site>-<date>.json in the current folder.
	 *
	 * [--sections=<list>]
	 * : Comma-separated: locations,team,reviews,brand,settings. Default all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc location export
	 *     wp smc location export --sections=locations,reviews --file=kenton.json
	 */
	public function export( $args, $assoc ) {
		$sections = isset( $assoc['sections'] ) ? array_map( 'trim', explode( ',', $assoc['sections'] ) ) : SMC_Location_Transfer::SECTIONS;
		$file     = $assoc['file'] ?? SMC_Location_Transfer::filename();
		$data     = SMC_Location_Transfer::export( $sections );
		if ( false === file_put_contents( $file, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) {
			WP_CLI::error( "Could not write $file." );
		}
		$counts = [];
		foreach ( $data['sections'] as $k => $v ) {
			$counts[] = in_array( $k, [ 'locations', 'team', 'reviews' ], true ) ? count( $v ) . " $k" : $k;
		}
		WP_CLI::success( "Exported " . implode( ', ', $counts ) . " to $file." );
	}

	/**
	 * Imports an export file. Shows what it will do, then asks.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The export file.
	 *
	 * [--sections=<list>]
	 * : Comma-separated: locations,team,reviews,brand,settings. Default everything in the file.
	 *
	 * [--keep-existing]
	 * : Don't update locations this site already has; only add new ones.
	 *
	 * [--dry-run]
	 * : Show what would happen and stop.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smc location import smc-locations-staging-2026-09-28.json
	 *     wp smc location import site.json --sections=brand --yes
	 */
	public function import( $args, $assoc ) {
		$data = SMC_Location_Transfer::parse( (string) @file_get_contents( $args[0] ) );
		if ( is_wp_error( $data ) ) {
			WP_CLI::error( $data->get_error_message() );
		}
		$sections = isset( $assoc['sections'] ) ? array_map( 'trim', explode( ',', $assoc['sections'] ) ) : array_keys( $data['sections'] );
		$p        = SMC_Location_Transfer::preview( $data );

		WP_CLI::log( 'From ' . ( $data['name'] ?? '' ) . ' (' . ( $data['site'] ?? '' ) . '), exported ' . ( $data['exported'] ?? '' ) );
		if ( in_array( 'locations', $sections, true ) && isset( $p['locations'] ) ) {
			WP_CLI::log( 'Locations: new: ' . ( implode( ', ', $p['locations']['new'] ) ?: 'none' ) . '; existing (' . ( empty( $assoc['keep-existing'] ) ? 'will update' : 'kept as is' ) . '): ' . ( implode( ', ', $p['locations']['existing'] ) ?: 'none' ) );
		}
		if ( in_array( 'team', $sections, true ) && isset( $p['team'] ) ) {
			WP_CLI::log( 'Team: new: ' . ( implode( ', ', $p['team']['new'] ) ?: 'none' ) . '; already here: ' . ( implode( ', ', $p['team']['existing'] ) ?: 'none' ) );
		}
		if ( in_array( 'reviews', $sections, true ) && isset( $p['reviews'] ) ) {
			WP_CLI::log( "Reviews: {$p['reviews']['new']} new, {$p['reviews']['existing']} already here" );
		}
		if ( in_array( 'brand', $sections, true ) && isset( $p['brand'] ) ) {
			WP_CLI::log( 'Brand: replaces logo, favicon, colors and fonts (current brand kept under Brand > Restore)' );
		}
		if ( in_array( 'settings', $sections, true ) && isset( $p['settings'] ) ) {
			WP_CLI::log( 'Settings: replaces Locations > Settings display options' );
		}
		if ( ! empty( $assoc['dry-run'] ) ) {
			WP_CLI::success( 'Dry run only. Nothing was changed.' );
			return;
		}
		WP_CLI::confirm( 'Import?', $assoc );

		$res = SMC_Location_Transfer::import( $data, $sections, empty( $assoc['keep-existing'] ) );
		foreach ( $res['done'] as $line ) {
			WP_CLI::log( $line );
		}
		foreach ( $res['warnings'] as $w ) {
			WP_CLI::warning( $w );
		}
		WP_CLI::success( 'Import finished.' );
	}

	/* ---------- Output ---------- */

	private function print_report( $r, $is_plan ) {
		$h = fn( $t ) => WP_CLI::log( "\n" . WP_CLI::colorize( "%G$t%n" ) );

		if ( $is_plan ) {
			$h( 'Location' );
			WP_CLI\Utils\format_items( 'table', $r['summary'], [ 'item', 'from', 'to' ] );
			if ( $r['excluded'] ) {
				WP_CLI::log( 'Excluded (not cloned): ' . implode( ', ', $r['excluded'] ) );
			}
		}
		if ( $r['terms'] ) {
			$h( 'Terms' );
			WP_CLI\Utils\format_items( 'table', $r['terms'], [ 'taxonomy', 'from', 'to', 'why' ] );
		}
		if ( $is_plan && $r['fields'] ) {
			$h( 'Location fields' );
			WP_CLI\Utils\format_items( 'table', $r['fields'], [ 'field', 'from', 'to' ] );
		}
		if ( $r['templates'] ) {
			$h( 'Templates' );
			WP_CLI\Utils\format_items( 'table', $r['templates'], [ 'id', 'template', 'source', 'type', 'conditions', 'replacements' ] );
		}
		if ( $r['menus'] ) {
			$h( 'Menus' );
			WP_CLI\Utils\format_items( 'table', $r['menus'], [ 'menu', 'new menu', 'items', 'pages remapped' ] );
		}
		$h( 'Pages' );
		WP_CLI\Utils\format_items( 'table', $r['pages'], [ 'id', 'path', 'source', 'replacements', 'location' ] );
		WP_CLI::log( 'Internal page links ' . ( $is_plan ? 'to remap' : 'remapped' ) . ": {$r['links']}" );
		WP_CLI::log( 'Template references ' . ( $is_plan ? 'to remap' : 'remapped' ) . ": {$r['tpl_refs']}" );

		foreach ( $r['warnings'] as $w ) {
			WP_CLI::warning( $w );
		}
	}
}
