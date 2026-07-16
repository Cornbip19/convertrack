<?php
/**
 * Rollback-safe LocalWP smoke coverage for lifecycle manifests and secrets.
 *
 * This test temporarily replaces only the two settings rows it exercises and
 * restores their exact serialized values/autoload flags in a finally block.
 */

use Convertrack\Database;
use Convertrack\Ingestion_Guard;
use Convertrack\Lifecycle;
use Convertrack\Manifest;
use Convertrack\Settings;
use Convertrack\Updater;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php was not found.\n" );
	exit( 1 );
}
require_once $wp_load;

/** Assert a lifecycle smoke-test condition. */
function cvtrk_lifecycle_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** Restore one option row exactly as it existed before the smoke test. */
function cvtrk_lifecycle_restore_option( $name, $row ) {
	global $wpdb;
	delete_option( $name );
	if ( is_array( $row ) ) {
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $row['option_value'],
				'autoload'     => $row['autoload'],
			),
			array( '%s', '%s', '%s' )
		);
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}

global $wpdb;

$settings_row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name=%s", Settings::OPTION ), ARRAY_A );
$secret_row   = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name=%s", Settings::SECRET_OPTION ), ARRAY_A );
$release_row  = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name=%s", '_transient_convertrack_gh_release' ), ARRAY_A );
$release_timeout_row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name=%s", '_transient_timeout_convertrack_gh_release' ), ARRAY_A );
$analytics_option_rows = array();
foreach ( Manifest::analytics_operational_options() as $option_name ) {
	$analytics_option_rows[ $option_name ] = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name=%s", $option_name ), ARRAY_A );
}
$token        = 'github_pat_cvtrk_smoke_' . strtolower( wp_generate_password( 24, false, false ) );

try {
	$expected_tables = array(
		'convertrack_events',
		'convertrack_sessions',
		'convertrack_daily',
		'convertrack_sources',
		'convertrack_geo',
		'convertrack_search_terms',
		'convertrack_rollup_state',
		'convertrack_rollup_stage',
		'convertrack_visitor_days',
		'convertrack_session_days',
		'convertrack_ingestion_limits',
		'convertrack_ingestion_metrics',
		'convertrack_gsc_index_queue',
		'convertrack_gsc_logs',
		'convertrack_gsc_keywords',
		'convertrack_gsc_keyword_pages',
		'convertrack_gsc_keywords_stage',
		'convertrack_404_events',
		'convertrack_404_redirects',
		'convertrack_404_valid_urls',
		'convertrack_404_logs',
		'convertrack_404_hit_buckets',
		'convertrack_404_rate_limits',
	);
	$manifest_tables = Manifest::table_suffixes();
	sort( $expected_tables );
	sort( $manifest_tables );
	cvtrk_lifecycle_assert( $expected_tables === $manifest_tables, 'The lifecycle table manifest drifted from the installed schemas.' );
	cvtrk_lifecycle_assert( count( Manifest::hooks() ) === count( array_unique( Manifest::hooks() ) ), 'The lifecycle hook manifest contains duplicate entries.' );
	cvtrk_lifecycle_assert( in_array( \Convertrack\GSC\Cron::SCAN_NOW, Manifest::hooks(), true ), 'The GSC sitemap continuation is absent from the hook manifest.' );
	cvtrk_lifecycle_assert( in_array( \Convertrack\NotFound\Cron::REFRESH_NOW, Manifest::hooks(), true ), 'The 404 sitemap continuation is absent from the hook manifest.' );
	cvtrk_lifecycle_assert( ! in_array( Settings::OPTION, Manifest::operational_options(), true ), 'Delete operational data would delete core configuration.' );
	cvtrk_lifecycle_assert( ! in_array( Settings::SECRET_OPTION, Manifest::operational_options(), true ), 'Delete operational data would delete the updater secret.' );
	cvtrk_lifecycle_assert( ! in_array( Database::DB_VERSION_OPTION, Manifest::operational_options(), true ), 'Delete operational data would delete the core schema version.' );
	cvtrk_lifecycle_assert( ! in_array( Ingestion_Guard::DB_VERSION_OPTION, Manifest::operational_options(), true ), 'Delete operational data would delete the ingestion schema version.' );
	cvtrk_lifecycle_assert( true === Lifecycle::network_activate( false ), 'Single-site lifecycle activation should be a no-op success.' );
	update_option( 'convertrack_rollup_last_error', 'lifecycle-smoke', false );
	cvtrk_lifecycle_assert( array() === Manifest::delete_analytics_options(), 'The analytics option manifest could not be deleted.' );
	cvtrk_lifecycle_assert( false === get_option( 'convertrack_rollup_last_error', false ), 'The analytics option deletion left worker state behind.' );

	delete_option( Settings::SECRET_OPTION );
	Settings::sanitize_registered(
		array(
			'github_token' => $token,
		)
	);
	cvtrk_lifecycle_assert( $token === get_option( Settings::SECRET_OPTION ), 'The registered settings callback discarded the GitHub token.' );
	$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", Settings::SECRET_OPTION ) );
	cvtrk_lifecycle_assert( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ), 'The GitHub token option is autoloaded.' );
	$public = Settings::sanitize_registered( array( 'github_token' => $token ) );
	cvtrk_lifecycle_assert( ! array_key_exists( 'github_token', $public ), 'The public settings array contains the updater token.' );

	$updater = new Updater( CONVERTRACK_FILE, CONVERTRACK_GITHUB_OWNER, CONVERTRACK_GITHUB_REPO, CONVERTRACK_SLUG );
	$foreign = $updater->authorize_request( array( 'headers' => array() ), 'https://api.github.com/repos/example/other/releases/latest' );
	cvtrk_lifecycle_assert( empty( $foreign['headers']['Authorization'] ), 'The updater sent its token to another repository.' );
	$traversal = $updater->authorize_request( array( 'headers' => array() ), 'https://api.github.com/repos/' . CONVERTRACK_GITHUB_OWNER . '/' . CONVERTRACK_GITHUB_REPO . '/../../example/other' );
	cvtrk_lifecycle_assert( empty( $traversal['headers']['Authorization'] ), 'The updater authorized a repository path containing traversal segments.' );
	$browser = $updater->authorize_request( array( 'headers' => array() ), 'https://github.com/' . CONVERTRACK_GITHUB_OWNER . '/' . CONVERTRACK_GITHUB_REPO . '/releases/download/v1/convertrack.zip' );
	cvtrk_lifecycle_assert( empty( $browser['headers']['Authorization'] ), 'The updater attached its token to a redirecting browser-download URL.' );
	$asset = $updater->authorize_request( array( 'headers' => array() ), 'https://api.github.com/repos/' . CONVERTRACK_GITHUB_OWNER . '/' . CONVERTRACK_GITHUB_REPO . '/releases/assets/123' );
	cvtrk_lifecycle_assert( ! empty( $asset['headers']['Authorization'] ), 'The updater omitted authentication from its exact asset API path.' );
	cvtrk_lifecycle_assert( 'application/octet-stream' === $asset['headers']['Accept'], 'The updater asset request omitted the binary Accept header.' );
	set_transient( 'convertrack_gh_release', array( 'version' => 'smoke-marker' ), MINUTE_IN_SECONDS );
	$updater->clear_cache( null, array( 'plugins' => array( 'example/example.php' ) ) );
	cvtrk_lifecycle_assert( is_array( get_transient( 'convertrack_gh_release' ) ), 'An unrelated bulk upgrade cleared the Convertrack release cache.' );
	$updater->clear_cache( null, array( 'plugins' => array( CONVERTRACK_BASENAME ) ) );
	cvtrk_lifecycle_assert( false === get_transient( 'convertrack_gh_release' ), 'A Convertrack bulk upgrade did not clear its release cache.' );

	$legacy = is_array( get_option( Settings::OPTION, array() ) ) ? get_option( Settings::OPTION, array() ) : Settings::defaults();
	$legacy['github_token'] = $token;
	update_option( Settings::OPTION, $legacy );
	delete_option( Settings::SECRET_OPTION );
	cvtrk_lifecycle_assert( true === Settings::migrate_secret(), 'The legacy token migration failed.' );
	$migrated = get_option( Settings::OPTION, array() );
	cvtrk_lifecycle_assert( is_array( $migrated ) && ! array_key_exists( 'github_token', $migrated ), 'The legacy token remained in the public settings option.' );
	cvtrk_lifecycle_assert( $token === get_option( Settings::SECRET_OPTION ), 'The legacy token was not moved to the secret option.' );

	Settings::sanitize_registered( array( 'github_token_clear' => 1 ) );
	cvtrk_lifecycle_assert( false === get_option( Settings::SECRET_OPTION, false ), 'The registered clear-token intent was discarded.' );

	fwrite( STDOUT, "Lifecycle/secret smoke passed: manifest tables/hooks, preserved options, non-autoloaded token, migration, scoped auth.\n" );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Lifecycle/secret smoke failed: ' . $error->getMessage() . "\n" );
	$exit = 1;
} finally {
	cvtrk_lifecycle_restore_option( Settings::OPTION, $settings_row );
	cvtrk_lifecycle_restore_option( Settings::SECRET_OPTION, $secret_row );
	cvtrk_lifecycle_restore_option( '_transient_convertrack_gh_release', $release_row );
	cvtrk_lifecycle_restore_option( '_transient_timeout_convertrack_gh_release', $release_timeout_row );
	foreach ( $analytics_option_rows as $option_name => $option_row ) {
		cvtrk_lifecycle_restore_option( $option_name, $option_row );
	}
	Settings::flush_cache();
}

exit( isset( $exit ) ? $exit : 0 );
