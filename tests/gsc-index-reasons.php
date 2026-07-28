<?php
/**
 * Offline classifier checks for GSC index coverage reasons.
 *
 * Index_Reasons is deliberately pure, so this needs no database, no WordPress
 * bootstrap and no API quota. Run it directly:
 *
 *   php tests/gsc-index-reasons.php
 *
 * @package Convertrack
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ );
if ( ! function_exists( '__' ) ) {
	/**
	 * Minimal translation stub so the class can be loaded standalone.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = null ) { // phpcs:ignore
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/gsc/class-gsc-index-reasons.php';

use Convertrack\GSC\Index_Reasons;

$failures = 0;

/**
 * Assert a row classifies to the expected reason.
 *
 * @param array  $row    Inspection fields.
 * @param string $expect Expected reason code.
 * @param string $label  Description.
 */
function cvtrk_reason( array $row, $expect, $label ) {
	global $failures;
	$got = Index_Reasons::classify( $row );
	if ( $got === $expect ) {
		printf( "PASS  %-56s -> %s\n", $label, $got );
		return;
	}
	$failures++;
	printf( "FAIL  %-56s -> %s (expected %s)\n", $label, $got, $expect );
}

/**
 * Generic boolean assertion.
 *
 * @param bool   $condition Result.
 * @param string $label     Description.
 */
function cvtrk_true( $condition, $label ) {
	global $failures;
	if ( $condition ) {
		printf( "PASS  %s\n", $label );
		return;
	}
	$failures++;
	printf( "FAIL  %s\n", $label );
}

echo "=== pageFetchState drives the reason (the branches that were missing) ===\n";
$fetch_cases = array(
	'NOT_FOUND'            => Index_Reasons::NOT_FOUND_404,
	'SOFT_404'             => Index_Reasons::SOFT_404,
	'REDIRECT_ERROR'       => Index_Reasons::PAGE_WITH_REDIRECT,
	'ACCESS_FORBIDDEN'     => Index_Reasons::FORBIDDEN_403,
	'ACCESS_DENIED'        => Index_Reasons::UNAUTHORIZED_401,
	'SERVER_ERROR'         => Index_Reasons::SERVER_ERROR_5XX,
	'BLOCKED_4XX'          => Index_Reasons::BLOCKED_4XX,
	'BLOCKED_ROBOTS_TXT'   => Index_Reasons::BLOCKED_ROBOTS_TXT,
	'INTERNAL_CRAWL_ERROR' => Index_Reasons::CRAWL_ERROR,
	'INVALID_URL'          => Index_Reasons::CRAWL_ERROR,
);
foreach ( $fetch_cases as $state => $expect ) {
	cvtrk_reason( array( 'page_fetch_state' => $state ), $expect, 'pageFetchState=' . $state );
}

echo "\n=== coverage text drives the reason when the fetch succeeded ===\n";
$coverage_cases = array(
	'Not found (404)'                                       => Index_Reasons::NOT_FOUND_404,
	'Page with redirect'                                    => Index_Reasons::PAGE_WITH_REDIRECT,
	'Crawled - currently not indexed'                       => Index_Reasons::CRAWLED_NOT_INDEXED,
	'Discovered - currently not indexed'                    => Index_Reasons::DISCOVERED_NOT_INDEXED,
	'Alternate page with proper canonical tag'              => Index_Reasons::ALTERNATE_CANONICAL,
	'Duplicate without user-selected canonical'             => Index_Reasons::DUPLICATE_NO_CANONICAL,
	'Blocked due to access forbidden (403)'                 => Index_Reasons::FORBIDDEN_403,
	'Blocked due to unauthorized request (401)'             => Index_Reasons::UNAUTHORIZED_401,
	'Excluded by ‘noindex’ tag'                             => Index_Reasons::NOINDEX_TAG,
	'Submitted and indexed'                                 => Index_Reasons::INDEXED,
);
foreach ( $coverage_cases as $coverage => $expect ) {
	cvtrk_reason(
		array( 'page_fetch_state' => 'SUCCESSFUL', 'coverage_state' => $coverage ),
		$expect,
		'coverage="' . $coverage . '"'
	);
}

echo "\n=== precedence ===\n";
// The case that motivated fetch-state-first: Google reports a 404 URL as
// "Crawled - currently not indexed", but a 404 is a 404.
cvtrk_reason(
	array( 'page_fetch_state' => 'NOT_FOUND', 'coverage_state' => 'Crawled - currently not indexed' ),
	Index_Reasons::NOT_FOUND_404,
	'404 fetch beats "crawled - not indexed" coverage'
);
cvtrk_reason(
	array( 'robots_txt_state' => 'DISALLOWED', 'indexing_state' => 'BLOCKED_BY_META_TAG' ),
	Index_Reasons::BLOCKED_ROBOTS_TXT,
	'robots.txt beats noindex (a blocked URL is never fetched)'
);
cvtrk_reason(
	array( 'google_verdict' => 'PASS', 'coverage_state' => 'Crawled - currently not indexed' ),
	Index_Reasons::INDEXED,
	'verdict PASS wins outright'
);
cvtrk_reason(
	array( 'page_fetch_state' => 'SUCCESSFUL', 'indexing_state' => 'BLOCKED_BY_HTTP_HEADER' ),
	Index_Reasons::NOINDEX_TAG,
	'X-Robots-Tag header counts as noindex'
);
cvtrk_reason(
	array( 'coverage_state' => 'Alternate page with proper canonical tag', 'user_canonical' => 'https://x.test/a/', 'google_canonical' => 'https://x.test/b/' ),
	Index_Reasons::ALTERNATE_CANONICAL,
	'"alternate page" is not treated as a canonical conflict'
);

echo "\n=== canonical comparison ===\n";
cvtrk_reason(
	array(
		'coverage_state'   => 'Duplicate, Google chose different canonical than user',
		'user_canonical'   => 'https://x.test/product/?variant=2',
		'google_canonical' => 'https://x.test/product/',
	),
	Index_Reasons::DUPLICATE_GOOGLE_CANONICAL,
	'canonicals disagree -> Google chose different'
);
cvtrk_reason(
	array(
		'coverage_state'   => 'Duplicate, submitted URL not selected as canonical',
		'user_canonical'   => 'https://x.test/product/',
		'google_canonical' => 'http://X.test/product',
	),
	Index_Reasons::DUPLICATE_NO_CANONICAL,
	'scheme/case/trailing-slash differences are not a real disagreement'
);

echo "\n=== empty and unknown input ===\n";
cvtrk_reason( array(), Index_Reasons::UNKNOWN, 'empty row -> not yet inspected' );
cvtrk_reason(
	array( 'google_verdict' => 'VERDICT_UNSPECIFIED', 'robots_txt_state' => 'ROBOTS_TXT_STATE_UNSPECIFIED' ),
	Index_Reasons::UNKNOWN,
	'all-unspecified row -> not yet inspected'
);
cvtrk_reason(
	array( 'google_verdict' => 'FAIL', 'coverage_state' => 'Something Google invented last week' ),
	Index_Reasons::NOT_INDEXED,
	'unrecognised coverage text -> generic not indexed'
);
cvtrk_reason(
	array( 'page_fetch_state' => 'SOME_NEW_STATE' ),
	Index_Reasons::CRAWL_ERROR,
	'unrecognised non-successful fetch state -> crawl error'
);

echo "\n=== descriptors ===\n";
$all = Index_Reasons::all();
cvtrk_true( count( $all ) >= 15, sprintf( 'reason vocabulary is populated (%d codes)', count( $all ) ) );
foreach ( $all as $code => $d ) {
	$ok = ! empty( $d['label'] ) && ! empty( $d['summary'] )
		&& in_array( $d['owner'], array( 'site', 'google', 'none' ), true )
		&& in_array( $d['severity'], array( 'critical', 'warning', 'info' ), true )
		&& array_key_exists( 'is_error', $d );
	cvtrk_true( $ok, sprintf( '%-30s label/summary/owner/severity/is_error all set', $code ) );
}

echo "\n=== these must NOT be reported as errors ===\n";
foreach ( array( Index_Reasons::ALTERNATE_CANONICAL, Index_Reasons::INDEXED, Index_Reasons::UNKNOWN ) as $code ) {
	$d = Index_Reasons::descriptor( $code );
	cvtrk_true( empty( $d['is_error'] ), sprintf( '%-30s is_error = false', $code ) );
}

echo "\n=== ownership matches Search Console's own attribution ===\n";
$owners = array(
	Index_Reasons::NOT_FOUND_404              => 'site',
	Index_Reasons::PAGE_WITH_REDIRECT         => 'site',
	Index_Reasons::BLOCKED_ROBOTS_TXT         => 'site',
	Index_Reasons::NOINDEX_TAG                => 'site',
	Index_Reasons::FORBIDDEN_403              => 'site',
	Index_Reasons::CRAWLED_NOT_INDEXED        => 'google',
	Index_Reasons::DISCOVERED_NOT_INDEXED     => 'google',
	Index_Reasons::DUPLICATE_GOOGLE_CANONICAL => 'google',
);
foreach ( $owners as $code => $owner ) {
	$d = Index_Reasons::descriptor( $code );
	cvtrk_true( $owner === $d['owner'], sprintf( '%-30s owner = %s', $code, $owner ) );
}

echo "\n=== descriptor() never returns null for junk ===\n";
$d = Index_Reasons::descriptor( 'not_a_real_reason' );
cvtrk_true( ! empty( $d['label'] ), 'unknown code falls back to a usable descriptor' );
cvtrk_true( ! Index_Reasons::is_valid( 'not_a_real_reason' ), 'is_valid() rejects an unknown code' );
cvtrk_true( Index_Reasons::is_valid( Index_Reasons::NOT_FOUND_404 ), 'is_valid() accepts a known code' );
cvtrk_true( in_array( Index_Reasons::NOT_FOUND_404, Index_Reasons::error_codes(), true ), 'error_codes() includes 404' );
cvtrk_true( ! in_array( Index_Reasons::ALTERNATE_CANONICAL, Index_Reasons::error_codes(), true ), 'error_codes() excludes alternate canonical' );

echo "\n" . ( $failures ? "FAILURES: $failures\n" : "ALL PASS\n" );
exit( $failures ? 1 : 0 );
