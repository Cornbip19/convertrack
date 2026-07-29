<?php
/**
 * Offline verdict checks for redirect conflict diagnosis.
 *
 * Conflicts::classify() is pure, so this needs no database, no WordPress
 * bootstrap and no HTTP. Run it directly:
 *
 *   php tests/notfound-conflicts.php
 *
 * @package Convertrack
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ );
if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub so the class loads standalone.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = null ) { // phpcs:ignore
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/404-monitor/class-notfound-conflicts.php';

use Convertrack\NotFound\Conflicts;

$failures = 0;

/**
 * Assert a context classifies to the expected verdict.
 *
 * @param array  $event   Event row.
 * @param array  $context Diagnosis context.
 * @param string $expect  Expected verdict.
 * @param string $label   Description.
 */
function cvtrk_verdict( array $event, array $context, $expect, $label ) {
	global $failures;
	$got = Conflicts::classify( $event, $context );
	if ( $got === $expect ) {
		printf( "PASS  %-58s -> %s\n", $label, $got );
		return;
	}
	$failures++;
	printf( "FAIL  %-58s -> %s (expected %s)\n", $label, $got, $expect );
}

/**
 * Generic assertion.
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

/** An ordinary active rule of ours. */
function cvtrk_rule( array $over = array() ) {
	return array_merge(
		array(
			'id'            => 42,
			'status'        => 'active',
			'source_url'    => '/old-page/',
			'destination_url' => 'https://x.test/new-page/',
			'health_status' => 'healthy',
		),
		$over
	);
}

$event = array( 'url' => '/old-page/', 'query_string' => '', 'last_detected_at' => '2026-07-28 10:00:00' );

echo "=== one case per verdict ===\n";
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule(), 'monitor_enabled' => false, 'probe_status' => 404 ),
	Conflicts::MONITOR_DISABLED,
	'monitoring off with our active rule'
);
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule( array( 'status' => 'paused' ) ), 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::RULE_PAUSED,
	'paused rule'
);
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule( array( 'status' => 'disabled' ) ), 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::RULE_PAUSED,
	'disabled rule counts as paused'
);
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule(), 'monitor_enabled' => true, 'probe_status' => 404, 'destination_probe' => 404 ),
	Conflicts::DESTINATION_IS_404,
	'destination itself returns 404'
);
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule( array( 'health_status' => 'unhealthy' ) ), 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::DESTINATION_UNHEALTHY,
	'health check marked the destination unhealthy'
);
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule(), 'monitor_enabled' => true, 'probe_status' => 404, 'loop' => true ),
	Conflicts::REDIRECT_LOOP,
	'chain loops back to the source'
);
cvtrk_verdict(
	array( 'url' => '/old-page/?ref=nl', 'query_string' => 'ref=nl' ),
	array( 'internal_rule' => cvtrk_rule(), 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::QUERY_STRING_MISMATCH,
	'traffic carries a query the rule does not'
);
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule(), 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::SERVER_INTERCEPTS,
	'rule is fine but the URL still 404s when tested'
);
cvtrk_verdict(
	$event,
	array( 'external_rule' => array( 'id' => 7 ), 'external_tool' => 'rank_math', 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::EXTERNAL_TOOL,
	'only a third-party tool owns the URL'
);
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule(), 'monitor_enabled' => true, 'probe_status' => 301 ),
	Conflicts::RESOLVED,
	'probe returns 301'
);
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule(), 'monitor_enabled' => true, 'probe_status' => 200 ),
	Conflicts::RESOLVED,
	'probe returns 200, the page exists again'
);
cvtrk_verdict(
	$event,
	array( 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::UNKNOWN,
	'no rule anywhere, so nothing to contradict'
);

echo "\n=== precedence ===\n";
// Ground truth beats every paper diagnosis: if the URL redirects, it works.
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule( array( 'status' => 'paused', 'health_status' => 'unhealthy' ) ), 'monitor_enabled' => false, 'probe_status' => 301, 'loop' => true ),
	Conflicts::RESOLVED,
	'a 301 probe overrides paused + unhealthy + monitoring off + loop'
);
// Site-wide beats per-rule: fixing one rule would change nothing.
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule( array( 'status' => 'paused' ) ), 'monitor_enabled' => false, 'probe_status' => 404 ),
	Conflicts::MONITOR_DISABLED,
	'monitoring off outranks a paused rule'
);
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule( array( 'health_status' => 'unhealthy' ) ), 'monitor_enabled' => false, 'probe_status' => 404 ),
	Conflicts::MONITOR_DISABLED,
	'monitoring off outranks an unhealthy destination'
);
// But monitoring off is irrelevant when the stalled rule is not ours.
cvtrk_verdict(
	$event,
	array( 'external_rule' => array( 'id' => 7 ), 'external_tool' => 'redirection', 'monitor_enabled' => false, 'probe_status' => 404 ),
	Conflicts::EXTERNAL_TOOL,
	'monitoring off is not blamed for another plugin\'s rule'
);
// Rule state before destination: a paused rule's destination is moot.
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule( array( 'status' => 'paused', 'health_status' => 'unhealthy' ) ), 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::RULE_PAUSED,
	'paused outranks unhealthy destination'
);
// A loop is a worse problem than a merely unreachable destination.
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule( array( 'health_status' => 'unhealthy' ) ), 'monitor_enabled' => true, 'probe_status' => 404, 'loop' => true ),
	Conflicts::REDIRECT_LOOP,
	'loop outranks unhealthy destination'
);
// Our rule takes the blame over a third-party one when both exist.
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule( array( 'status' => 'paused' ) ), 'external_rule' => array( 'id' => 7 ), 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::RULE_PAUSED,
	'our own rule is diagnosed before a third-party one'
);

echo "\n=== query mismatch is narrow ===\n";
cvtrk_verdict(
	array( 'url' => '/old-page/', 'query_string' => '' ),
	array( 'internal_rule' => cvtrk_rule(), 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::SERVER_INTERCEPTS,
	'no query on the event -> not a query mismatch'
);
cvtrk_verdict(
	array( 'url' => '/old-page/?ref=nl', 'query_string' => 'ref=nl' ),
	array( 'internal_rule' => cvtrk_rule( array( 'source_url' => '/old-page/?ref=nl' ) ), 'monitor_enabled' => true, 'probe_status' => 404 ),
	Conflicts::SERVER_INTERCEPTS,
	'rule already carries the query -> not a mismatch'
);

echo "\n=== unprobed rows still get a usable verdict ===\n";
// The cheap pass flags suspects before any HTTP happens; a verdict must still be
// possible from stored data alone.
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule( array( 'status' => 'paused' ) ), 'monitor_enabled' => true, 'probe_status' => 0 ),
	Conflicts::RULE_PAUSED,
	'paused rule diagnosed with no probe'
);
cvtrk_verdict(
	$event,
	array( 'internal_rule' => cvtrk_rule(), 'monitor_enabled' => true, 'probe_status' => 0 ),
	Conflicts::UNKNOWN,
	'healthy-looking rule with no probe stays undetermined'
);

echo "\n=== descriptors ===\n";
$all = Conflicts::all();
cvtrk_true( count( $all ) >= 10, sprintf( 'verdict vocabulary is populated (%d codes)', count( $all ) ) );
foreach ( $all as $code => $d ) {
	$ok = ! empty( $d['label'] ) && ! empty( $d['summary'] )
		&& in_array( $d['severity'], array( 'critical', 'warning', 'info' ), true )
		&& array_key_exists( 'fix', $d )
		&& in_array( $d['scope'], array( 'site', 'rule', 'external', 'server', 'none' ), true );
	cvtrk_true( $ok, sprintf( '%-24s label/summary/severity/scope/fix all set', $code ) );
}

echo "\n=== only the safe verdicts offer a one-click fix ===\n";
$expected_fixes = array(
	Conflicts::RULE_PAUSED           => Conflicts::FIX_RESUME_RULE,
	Conflicts::DESTINATION_IS_404    => Conflicts::FIX_REPOINT_RULE,
	Conflicts::DESTINATION_UNHEALTHY => Conflicts::FIX_REPOINT_RULE,
	Conflicts::REDIRECT_LOOP         => Conflicts::FIX_REPOINT_RULE,
	Conflicts::QUERY_STRING_MISMATCH => Conflicts::FIX_EXACT_RULE,
	Conflicts::RESOLVED              => Conflicts::FIX_MARK_RESOLVED,
);
foreach ( $expected_fixes as $code => $fix ) {
	cvtrk_true( $fix === Conflicts::fix_for( $code ), sprintf( '%-24s offers %s', $code, $fix ) );
	cvtrk_true( '' !== Conflicts::fix_label( $fix ), sprintf( '%-24s fix has a label', $code ) );
}

echo "\n=== these must NEVER offer a one-click fix ===\n";
// Turning monitoring on is site-wide; the others belong to someone else.
foreach ( array( Conflicts::MONITOR_DISABLED, Conflicts::EXTERNAL_TOOL, Conflicts::SERVER_INTERCEPTS, Conflicts::UNKNOWN ) as $code ) {
	cvtrk_true( Conflicts::FIX_NONE === Conflicts::fix_for( $code ), sprintf( '%-24s has no fix button', $code ) );
}
cvtrk_true( 'site' === Conflicts::descriptor( Conflicts::MONITOR_DISABLED )['scope'], 'monitor_disabled is marked site-wide in scope' );

echo "\n=== owners and validity ===\n";
foreach ( array( 'internal' => 'Convertrack', 'redirection' => 'Redirection', 'rank_math' => 'Rank Math' ) as $key => $label ) {
	cvtrk_true( $label === Conflicts::owner_label( $key ), sprintf( 'owner %-12s -> %s', $key, $label ) );
}
cvtrk_true( '' !== Conflicts::owner_label( 'something_else' ), 'unknown owner still has a label' );
cvtrk_true( Conflicts::is_valid( Conflicts::RULE_PAUSED ), 'is_valid accepts a known verdict' );
cvtrk_true( ! Conflicts::is_valid( 'not_a_verdict' ), 'is_valid rejects an unknown verdict' );
cvtrk_true( ! empty( Conflicts::descriptor( 'not_a_verdict' )['label'] ), 'unknown verdict falls back to a usable descriptor' );
cvtrk_true( ! in_array( Conflicts::RESOLVED, Conflicts::unresolved_codes(), true ), 'unresolved_codes excludes resolved' );
cvtrk_true( in_array( Conflicts::RULE_PAUSED, Conflicts::unresolved_codes(), true ), 'unresolved_codes includes rule_paused' );

echo "\n" . ( $failures ? "FAILURES: $failures\n" : "ALL PASS\n" );
exit( $failures ? 1 : 0 );
