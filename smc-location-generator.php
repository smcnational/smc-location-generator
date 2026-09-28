<?php
/**
 * Plugin Name: SMC Locations
 * Description: SMC multi-location tools. Adds hours, social links and a Google Map to each location, with [location_hours], [location_social] and [location_map] shortcodes. Manage locations from the Locations menu in wp-admin, or add them with "wp smc location".
 * Version:     1.20.1
 * Author:      SMC National
 * Requires PHP: 7.4
 * Update URI:  https://github.com/smcnational/smc-location-generator
 */

defined( 'ABSPATH' ) || exit;

define( 'SMC_LOCATION_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-smc-location-core.php';
require_once __DIR__ . '/includes/class-smc-location-settings.php';
require_once __DIR__ . '/includes/class-smc-location-fields.php';
require_once __DIR__ . '/includes/class-smc-location-cloner.php';
require_once __DIR__ . '/includes/class-smc-location-reviews.php';
require_once __DIR__ . '/includes/class-smc-location-team.php';
require_once __DIR__ . '/includes/class-smc-location-brand.php';
require_once __DIR__ . '/includes/class-smc-location-manager.php';
require_once __DIR__ . '/includes/class-smc-location-launch.php';
require_once __DIR__ . '/includes/class-smc-location-updater.php';
require_once __DIR__ . '/includes/class-smc-location-transfer.php';

SMC_Location_Core::init();
SMC_Location_Fields::init();
SMC_Location_Reviews::init();
SMC_Location_Team::init();
SMC_Location_Brand::init_front();
SMC_Location_Updater::init();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-smc-location-admin.php';
	new SMC_Location_Manager();
	new SMC_Location_Admin();
	require_once __DIR__ . '/includes/class-smc-location-scanner.php';
	require_once __DIR__ . '/includes/class-smc-location-scan-page.php';
	new SMC_Location_Scan_Page();
	require_once __DIR__ . '/includes/class-smc-location-reviews-import.php';
	new SMC_Location_Reviews_Import();
	new SMC_Location_Brand();
	require_once __DIR__ . '/includes/class-smc-location-transfer-page.php';
	new SMC_Location_Transfer_Page();
	SMC_Location_Settings::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-smc-location-scanner.php';
	require_once __DIR__ . '/includes/class-smc-location-command.php';
	WP_CLI::add_command( 'smc location', 'SMC_Location_Command' );
}
