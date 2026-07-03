<?php
/**
 * Plugin Name:       Convertrack — Click & Conversion Analytics
 * Plugin URI:        https://github.com/Cornbip19/convertrack
 * Description:       Tracks clicks on every button and link across your site, measures page conversion, and shows how many visitors are on the site right now. Built to scale to large sites and to update itself from GitHub.
 * Version:           2.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Cornbip19
 * Author URI:        https://github.com/Cornbip19
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       convertrack-click-conversion-analytics
 * Domain Path:       /languages
 *
 * @package Convertrack
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'CONVERTRACK_VERSION' ) ) {
	return;
}

// Safety net: bail cleanly on cores older than the declared minimum instead of
// risking fatals from newer APIs. WordPress.org enforces "Requires at least" at
// install time; this also covers manually copied installs.
if ( isset( $GLOBALS['wp_version'] ) && version_compare( $GLOBALS['wp_version'], '5.8', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Convertrack requires WordPress 5.8 or newer. Please update WordPress to activate this plugin.', 'convertrack-click-conversion-analytics' ) .
				'</p></div>';
		}
	);
	return;
}

define( 'CONVERTRACK_VERSION', '2.0.2' );
define( 'CONVERTRACK_FILE', __FILE__ );
define( 'CONVERTRACK_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONVERTRACK_URL', plugin_dir_url( __FILE__ ) );
define( 'CONVERTRACK_BASENAME', plugin_basename( __FILE__ ) );
define( 'CONVERTRACK_SLUG', 'convertrack' );

// GitHub repository used for self-updating. Change these if you fork the plugin.
define( 'CONVERTRACK_GITHUB_OWNER', 'Cornbip19' );
define( 'CONVERTRACK_GITHUB_REPO', 'convertrack' );

require_once CONVERTRACK_DIR . 'includes/class-database.php';
require_once CONVERTRACK_DIR . 'includes/class-settings.php';
require_once CONVERTRACK_DIR . 'includes/class-activator.php';
require_once CONVERTRACK_DIR . 'includes/class-deactivator.php';
require_once CONVERTRACK_DIR . 'includes/class-geo.php';
require_once CONVERTRACK_DIR . 'includes/class-collector.php';
require_once CONVERTRACK_DIR . 'includes/class-presence.php';
require_once CONVERTRACK_DIR . 'includes/class-rest-controller.php';
require_once CONVERTRACK_DIR . 'includes/class-frontend.php';
require_once CONVERTRACK_DIR . 'includes/class-cron.php';
require_once CONVERTRACK_DIR . 'includes/class-admin.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-settings.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-credentials.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-database.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-logger.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-oauth.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-api.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-sitemap-scanner.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-processor.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-cron.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-rest-controller.php';
require_once CONVERTRACK_DIR . 'includes/gsc/class-gsc-admin.php';

// The GitHub self-updater ships only in the self-hosted build. The
// WordPress.org build omits this file so the directory handles all updates
// (per the plugin guidelines, directory plugins must not update from elsewhere).
if ( file_exists( CONVERTRACK_DIR . 'includes/class-updater.php' ) ) {
	require_once CONVERTRACK_DIR . 'includes/class-updater.php';
}

require_once CONVERTRACK_DIR . 'includes/class-convertrack.php';

register_activation_hook( __FILE__, array( '\\Convertrack\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Convertrack\\Deactivator', 'deactivate' ) );

/**
 * Boot the plugin once all other plugins are loaded.
 *
 * @return \Convertrack\Plugin
 */
function convertrack() {
	return \Convertrack\Plugin::instance();
}

add_action( 'plugins_loaded', 'convertrack', 5 );
