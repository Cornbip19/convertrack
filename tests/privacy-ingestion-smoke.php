<?php
/**
 * Focused privacy/ingestion regression smoke test.
 *
 * Run with the site's PHP runtime:
 * php -c /path/to/site/php.ini tests/privacy-ingestion-smoke.php
 */

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "Unable to locate wp-load.php.\n" );
	exit( 1 );
}
require $wp_load;

use Convertrack\Collector;
use Convertrack\Database;
use Convertrack\Frontend;
use Convertrack\Ingestion_Guard;
use Convertrack\Page_Identity;
use Convertrack\Settings;

$checks = 0;

/** Assert a strict value. */
function cvtrk_assert_same( $expected, $actual, $message ) {
	global $checks;
	$checks++;
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: $message\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

/** Invoke a private static method for a side-effect-free sanitizer test. */
function cvtrk_private_static( $class, $method, array $args ) {
	$reflection = new ReflectionMethod( $class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
}

add_filter(
	'convertrack_allowed_query_params',
	function () {
		return array( 'foo', 'utm_source', 'oauth_code', 'email', 'order_key', 'reset-key', 'api_key' );
	}
);

$safe_url = Collector::sanitize_relative_url( '/landing?utm_source=google&foo=bar&oauth_code=secret&email=jane%40example.com&order_key=wc_123&api_key=nope' );
cvtrk_assert_same( '/landing?foo=bar&utm_source=google', $safe_url, 'Only explicitly allowed, non-sensitive query keys survive.' );
cvtrk_assert_same( '/magic-login/redacted', Collector::sanitize_relative_url( '/magic-login/2f090b1d-1011-4b8a-8e11-e3df7906b804?reset-key=secret' ), 'Magic-login path tokens and reset keys are redacted.' );
cvtrk_assert_same( '', Collector::sanitize_relative_url( 'mailto:jane@example.com' ), 'Email href schemes are discarded.' );
cvtrk_assert_same( true, Collector::is_no_track_url( '/preview?convertrack_no_track=1&token=secret' ), 'Preview opt-out is detected before query stripping.' );
$original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
$_SERVER['REQUEST_URI'] = '/magic-login/2f090b1d-1011-4b8a-8e11-e3df7906b804?token=secret';
cvtrk_assert_same( '/magic-login/redacted', cvtrk_private_static( Page_Identity::class, 'current_path', array() ), 'Signed page identity redacts credential-like path segments.' );
if ( null === $original_request_uri ) {
	unset( $_SERVER['REQUEST_URI'] );
} else {
	$_SERVER['REQUEST_URI'] = $original_request_uri;
}

$vid = '00000000-0000-4000-8000-000000000001';
$sid = '00000000-0000-4000-8000-000000000002';
$now = current_time( 'mysql' );

$heatmap = cvtrk_private_static(
	Collector::class,
	'sanitize_event',
	array(
		array(
			't' => 'heatmap_click', 'url' => '/?email=jane@example.com', 'tag' => 'input',
			'txt' => 'hunter2', 'id' => 'email', 'cls' => 'field user-jane',
			'sel' => 'input[name=email]', 'hsel' => 'html>body>form>input:nth-of-type(1)',
			'href' => '/reset?reset_key=secret', 'conv' => 1, 'cx' => 100, 'cy' => 200,
		),
		$vid,
		$sid,
		$now,
	)
);
cvtrk_assert_same( '', $heatmap['element_text'], 'Heatmap-only events discard arbitrary element text.' );
cvtrk_assert_same( '', $heatmap['element_href'], 'Heatmap-only events discard hrefs.' );
cvtrk_assert_same( '', $heatmap['element_id'], 'Heatmap-only events discard element IDs.' );
cvtrk_assert_same( '', $heatmap['element_classes'], 'Heatmap-only events discard CSS classes.' );
cvtrk_assert_same( '', $heatmap['element_selector'], 'Heatmap-only events discard arbitrary selectors.' );
cvtrk_assert_same( 0, $heatmap['is_conversion'], 'Heatmap-only events cannot be conversions.' );

$input_click = cvtrk_private_static(
	Collector::class,
	'sanitize_event',
	array(
		array(
			't' => 'click', 'url' => '/checkout?order_key=secret&foo=report', 'tag' => 'input',
			'id' => 'email', 'cls' => 'field user-jane', 'txt' => 'jane@example.com',
			'sel' => 'input#email', 'hsel' => 'html>body>form>input',
			'href' => '/checkout?order_key=secret', 'conv' => 1, 'goal' => '.cvtrk-convert',
		),
		$vid,
		$sid,
		$now,
	)
);
cvtrk_assert_same( '', $input_click['element_text'], 'Input values/labels are never stored.' );
cvtrk_assert_same( '', $input_click['element_id'], 'Sensitive form identifiers are discarded.' );
cvtrk_assert_same( 'field', $input_click['element_classes'], 'User/account-like class tokens are discarded.' );
cvtrk_assert_same( '/checkout', $input_click['element_href'], 'Order keys are removed from click destinations.' );
cvtrk_assert_same( 0, $input_click['is_conversion'], 'A forged simple-selector goal without matching evidence is rejected.' );

$scroll = cvtrk_private_static(
	Collector::class,
	'sanitize_event',
	array( array( 't' => 'scroll', 'url' => '/', 'sd' => 75, 'conv' => 1 ), $vid, $sid, $now )
);
cvtrk_assert_same( 0, $scroll['is_conversion'], 'Scroll events cannot become conversions.' );
cvtrk_assert_same( '', $scroll['page_title'], 'Virtual/client titles are not trusted.' );

$settings_cache = new ReflectionProperty( Settings::class, 'cache' );
$settings_cache->setAccessible( true );
$conversion_settings = Settings::defaults();
$conversion_settings['conversion_urls'] = implode(
	"\n",
	array(
		'/thank-you/',
		'exact:/receipt/',
		'prefix:/orders/',
		'regex:^/downloads/[0-9]+/complete$',
	)
);
$settings_cache->setValue( null, $conversion_settings );
cvtrk_assert_same( true, cvtrk_private_static( Collector::class, 'matches_conversion_url', array( '/thank-you/' ) ), 'An unprefixed URL goal remains a valid exact rule.' );
cvtrk_assert_same( false, cvtrk_private_static( Collector::class, 'matches_conversion_url', array( '/shop/thank-you/receipt' ) ), 'An unprefixed URL goal does not retain broad substring semantics.' );
cvtrk_assert_same( false, cvtrk_private_static( Collector::class, 'matches_conversion_url', array( '/receipt/extra' ) ), 'An explicit exact URL goal does not match descendants.' );
cvtrk_assert_same( true, cvtrk_private_static( Collector::class, 'matches_conversion_url', array( '/orders/1234/complete' ) ), 'An explicit prefix URL goal matches descendants.' );
cvtrk_assert_same( true, cvtrk_private_static( Collector::class, 'matches_conversion_url', array( '/downloads/42/complete' ) ), 'A safe anchored regex URL goal is accepted.' );

$conversion_settings['conversion_urls'] = "regex:/downloads/[0-9]+/complete$\nregex:^(a+)+$\nregex:^([broken)$";
$settings_cache->setValue( null, $conversion_settings );
cvtrk_assert_same( false, cvtrk_private_static( Collector::class, 'matches_conversion_url', array( '/downloads/42/complete' ) ), 'Unanchored, nested-repeat, and invalid regex URL goals fail closed.' );

$token = Ingestion_Guard::issue_token();
cvtrk_assert_same( true, cvtrk_private_static( Ingestion_Guard::class, 'validate_token', array( $token ) ), 'A current site token validates.' );
cvtrk_assert_same( false, cvtrk_private_static( Ingestion_Guard::class, 'validate_token', array( $token . 'x' ) ), 'A modified site token is rejected.' );
$collect_quotas = cvtrk_private_static( Ingestion_Guard::class, 'quotas', array( 'collect', false ) );
cvtrk_assert_same( array( 2400, 3000, 8000000 ), $collect_quotas['site'], 'The site ingestion ceiling stays below hourly cleanup throughput.' );

$request = new WP_REST_Request( 'POST', '/convertrack/v1/heartbeat' );
$request->set_body( str_repeat( 'x', Ingestion_Guard::max_body_bytes( 'heartbeat' ) + 1 ) );
$controller = new Convertrack\Rest_Controller();
$method = new ReflectionMethod( $controller, 'validate_body_size' );
$method->setAccessible( true );
$size_error = $method->invoke( $controller, $request, 'heartbeat' );
cvtrk_assert_same( 413, (int) $size_error->get_error_data()['status'], 'Oversized heartbeat bodies return HTTP 413.' );

$unhealthy_version = function () {
	return '0.0.0';
};
add_filter( 'pre_option_' . Ingestion_Guard::DB_VERSION_OPTION, $unhealthy_version );
$schema_method = new ReflectionMethod( $controller, 'ingestion_schema_error' );
$schema_method->setAccessible( true );
$schema_error = $schema_method->invoke( $controller );
cvtrk_assert_same( 503, (int) $schema_error->get_error_data()['status'], 'Direct cached/legacy clients fail closed while ingestion schema is unhealthy.' );
remove_filter( 'pre_option_' . Ingestion_Guard::DB_VERSION_OPTION, $unhealthy_version );

$unhealthy_core_version = function () {
	return '0.0.0';
};
add_filter( 'pre_option_' . Database::DB_VERSION_OPTION, $unhealthy_core_version );
$schema_error = $schema_method->invoke( $controller );
cvtrk_assert_same( 503, (int) $schema_error->get_error_data()['status'], 'Direct cached/legacy clients fail closed while the core schema is unhealthy.' );
remove_filter( 'pre_option_' . Database::DB_VERSION_OPTION, $unhealthy_core_version );

// The browser tracker must not enqueue while either write-path schema is
// unhealthy. Seed the request-local settings cache so this remains independent
// of the site's saved tracking preference.
$settings_cache->setValue( null, Settings::defaults() );
$frontend = new Frontend();

wp_dequeue_script( 'convertrack' );
wp_deregister_script( 'convertrack' );
add_filter( 'pre_option_' . Database::DB_VERSION_OPTION, $unhealthy_core_version );
$frontend->enqueue();
cvtrk_assert_same( false, wp_script_is( 'convertrack', 'registered' ), 'The frontend tracker stays disabled while the core schema is unhealthy.' );
remove_filter( 'pre_option_' . Database::DB_VERSION_OPTION, $unhealthy_core_version );

wp_dequeue_script( 'convertrack' );
wp_deregister_script( 'convertrack' );
add_filter( 'pre_option_' . Ingestion_Guard::DB_VERSION_OPTION, $unhealthy_version );
$frontend->enqueue();
cvtrk_assert_same( false, wp_script_is( 'convertrack', 'registered' ), 'The frontend tracker stays disabled while the ingestion schema is unhealthy.' );
remove_filter( 'pre_option_' . Ingestion_Guard::DB_VERSION_OPTION, $unhealthy_version );

$old_gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? $_SERVER['HTTP_SEC_GPC'] : null;
$_SERVER['HTTP_SEC_GPC'] = '1';
cvtrk_assert_same( true, Ingestion_Guard::privacy_opted_out(), 'Sec-GPC: 1 is an enforced opt-out.' );
if ( null === $old_gpc ) {
	unset( $_SERVER['HTTP_SEC_GPC'] );
} else {
	$_SERVER['HTTP_SEC_GPC'] = $old_gpc;
}

echo 'PASS: ' . $checks . " privacy/ingestion checks.\n";
