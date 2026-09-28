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
