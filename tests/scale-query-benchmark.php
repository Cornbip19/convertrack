<?php
/**
 * Isolated Convertrack scale/query benchmark.
 *
 * This CLI-only harness never reads from or writes to the installed plugin's
 * analytics tables. It switches wpdb to a unique temporary prefix, creates a
 * production-shaped fixture schema, records timings and EXPLAIN plans, then
 * drops every fixture table and restores the original prefix in a finally
 * block and a shutdown fallback.
 *
 * Examples:
 *   php -c /path/to/site/php.ini tests/scale-query-benchmark.php 1000
 *   php -c /path/to/site/php.ini tests/scale-query-benchmark.php --tier=1000,25000
 *   php -c /path/to/site/php.ini tests/scale-query-benchmark.php --tier=100000 --max-seconds=300
 *
 * Supported event tiers: 1,000 / 25,000 / 100,000 / 1,000,000.
 */

if ( 'cli' !== PHP_SAPI ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit( 1 );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "Unable to locate wp-load.php.\n" );
	exit( 1 );
}
require_once $wp_load;

/**
 * Throw when a wpdb mutation failed.
 *
 * @param mixed  $result  wpdb result.
 * @param string $context Operation label.
 * @return mixed
 */
function convertrack_benchmark_require_query( $result, $context ) {
	global $wpdb;
	if ( false === $result ) {
		$message = $wpdb->last_error ? $wpdb->last_error : 'unknown database error';
		throw new RuntimeException( $context . ': ' . $message );
	}
	return $result;
}

/**
 * Quote a trusted fixture string with wpdb's active connection escaping.
 *
 * @param string $value Value.
 * @return string
 */
function convertrack_benchmark_quote( $value ) {
	global $wpdb;
	return "'" . $wpdb->_real_escape( (string) $value ) . "'"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
}

/**
 * Validate and quote a generated identifier.
 *
 * @param string $identifier Identifier.
 * @return string
 */
function convertrack_benchmark_identifier( $identifier ) {
	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $identifier ) ) {
		throw new RuntimeException( 'Unsafe benchmark identifier.' );
	}
	return '`' . $identifier . '`';
}

/**
 * Percentile for a sorted numeric list.
 *
 * @param array $values Values.
 * @param float $percentile Percentile from 0 to 1.
 * @return float
 */
function convertrack_benchmark_percentile( array $values, $percentile ) {
	if ( empty( $values ) ) {
		return 0.0;
	}
	sort( $values, SORT_NUMERIC );
	$position = ( count( $values ) - 1 ) * max( 0, min( 1, (float) $percentile ) );
	$lower    = (int) floor( $position );
	$upper    = (int) ceil( $position );
	if ( $lower === $upper ) {
		return (float) $values[ $lower ];
	}
	$weight = $position - $lower;
	return (float) $values[ $lower ] * ( 1 - $weight ) + (float) $values[ $upper ] * $weight;
}

/**
 * Run and time one read query, then collect a classic EXPLAIN plan.
 *
 * The first execution is reported separately; median/min/max are based on the
 * requested warm executions. Query result payloads are intentionally not
 * printed because this is a performance and access-path test.
 *
 * @param string $label Label.
 * @param string $sql SQL.
 * @param int    $runs Warm measured runs.
 * @return array
 */
function convertrack_benchmark_query( $label, $sql, $runs ) {
	global $wpdb;

	$started = microtime( true );
	$rows    = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( null === $rows && $wpdb->last_error ) {
		throw new RuntimeException( $label . ': ' . $wpdb->last_error );
	}
	$first_ms = ( microtime( true ) - $started ) * 1000;

	$times = array();
	for ( $run = 0; $run < $runs; $run++ ) {
		$started = microtime( true );
		$next    = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( null === $next && $wpdb->last_error ) {
			throw new RuntimeException( $label . ': ' . $wpdb->last_error );
		}
		$times[] = ( microtime( true ) - $started ) * 1000;
	}

	$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( null === $plan && $wpdb->last_error ) {
		throw new RuntimeException( 'EXPLAIN ' . $label . ': ' . $wpdb->last_error );
	}

	$access = array();
	foreach ( (array) $plan as $step ) {
		$access[] = array(
			'select_type'   => isset( $step['select_type'] ) ? (string) $step['select_type'] : '',
			'table'         => isset( $step['table'] ) ? (string) $step['table'] : '',
			'access_type'   => isset( $step['type'] ) ? (string) $step['type'] : '',
			'possible_keys' => isset( $step['possible_keys'] ) ? (string) $step['possible_keys'] : '',
			'key'           => isset( $step['key'] ) ? (string) $step['key'] : '',
			'estimated_rows'=> isset( $step['rows'] ) ? (int) $step['rows'] : 0,
			'extra'         => isset( $step['Extra'] ) ? (string) $step['Extra'] : '',
		);
	}

	return array(
		'label'          => $label,
		'first_ms'       => round( $first_ms, 3 ),
		'warm_min_ms'    => round( min( $times ), 3 ),
		'warm_median_ms' => round( convertrack_benchmark_percentile( $times, 0.5 ), 3 ),
		'warm_p95_ms'    => round( convertrack_benchmark_percentile( $times, 0.95 ), 3 ),
		'warm_max_ms'    => round( max( $times ), 3 ),
		'result_rows'    => count( (array) $rows ),
		'explain'        => $access,
	);
}

/**
 * Assert that a tier remains inside its wall-clock safety budget.
 *
 * @param float  $tier_started Start timestamp.
 * @param int    $max_seconds Budget.
 * @param string $phase Phase label.
 * @return void
 */
function convertrack_benchmark_check_budget( $tier_started, $max_seconds, $phase ) {
	if ( microtime( true ) - $tier_started > $max_seconds ) {
		throw new RuntimeException( sprintf( 'Safety budget of %d seconds exceeded during %s.', $max_seconds, $phase ) );
	}
}

/**
 * Build query fixtures in batches.
 *
 * @param array $tables Table names.
 * @param int   $event_count Event row target.
 * @param int   $batch_size INSERT batch size.
 * @param float $tier_started Tier start.
 * @param int   $max_seconds Budget.
 * @return array Seed metrics.
 */
function convertrack_benchmark_seed( array $tables, $event_count, $batch_size, $tier_started, $max_seconds ) {
	global $wpdb;

	$pages       = max( 50, min( 2000, (int) ceil( $event_count / 100 ) ) );
	$target_page = min( 42, $pages );
	$events      = convertrack_benchmark_identifier( $tables['events'] );
	$posts       = convertrack_benchmark_identifier( $tables['posts'] );
	$daily       = convertrack_benchmark_identifier( $tables['daily'] );
	$visitors    = convertrack_benchmark_identifier( $tables['visitor_days'] );
	$sessions    = convertrack_benchmark_identifier( $tables['session_days'] );
	$today_ts    = strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' );
	$sources     = array( 'Direct', 'Organic search', 'Paid search', 'Referral', 'Email' );
	$devices     = array( 'desktop', 'mobile', 'tablet' );
	$types       = array( 'pageview', 'click', 'scroll', 'heatmap_click', 'click' );

	$started = microtime( true );
	convertrack_benchmark_require_query( $wpdb->query( 'START TRANSACTION' ), 'Start fixture transaction' );
	try {
		for ( $offset = 1; $offset <= $pages; $offset += $batch_size ) {
			$last   = min( $pages, $offset + $batch_size - 1 );
			$values = array();
			for ( $post_id = $offset; $post_id <= $last; $post_id++ ) {
				$values[] = '(' . $post_id . ',' . convertrack_benchmark_quote( 'Benchmark Page ' . $post_id ) . ',' . convertrack_benchmark_quote( 'benchmark-page-' . $post_id ) . ',' . convertrack_benchmark_quote( 'https://benchmark.invalid/page-' . $post_id . '/' ) . ')';
			}
			$sql = "INSERT INTO $posts (ID,post_title,post_name,guid) VALUES " . implode( ',', $values );
			convertrack_benchmark_require_query( $wpdb->query( $sql ), 'Seed posts' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		for ( $offset = 0; $offset < $event_count; $offset += $batch_size ) {
			convertrack_benchmark_check_budget( $tier_started, $max_seconds, 'event fixture generation' );
			$last   = min( $event_count, $offset + $batch_size );
			$values = array();
			for ( $i = $offset; $i < $last; $i++ ) {
				$session_number = (int) floor( $i / 5 );
				$slot           = $i % 5;
				$visitor_number = (int) floor( $session_number / 2 );
				$page_id        = 0 === $session_number % 5 ? $target_page : 1 + ( ( $session_number * 3 + min( $slot, 3 ) ) % $pages );
				$day_index      = $session_number % 30;
				$seconds        = ( $session_number * 37 + $slot * 3 ) % 86400;
				$created_at     = gmdate( 'Y-m-d H:i:s', $today_ts - ( $day_index * DAY_IN_SECONDS ) + $seconds );
				$event_type     = $types[ $slot ];
				$is_conversion  = ( 0 === $session_number % 97 && 4 === $slot ) ? 1 : 0;
				$selector       = in_array( $event_type, array( 'click', 'heatmap_click' ), true ) ? ( 3 === $slot ? '.hero-cta' : '.button-' . ( $session_number % 12 ) ) : '';
				$pos_x          = in_array( $event_type, array( 'click', 'heatmap_click' ), true ) ? 1 + ( ( $session_number * 17 + $slot * 41 ) % 999 ) : 0;
				$pos_y          = in_array( $event_type, array( 'click', 'heatmap_click' ), true ) ? 1 + ( ( $session_number * 29 + $slot * 53 ) % 999 ) : 0;
				$scroll_depth   = 'scroll' === $event_type ? 10 * ( 1 + ( $session_number % 10 ) ) : 0;
				$event_id       = sprintf( '10000000-0000-4000-8000-%012d', $i + 1 );
				$visitor_id     = sprintf( '20000000-0000-4000-8000-%012d', $visitor_number + 1 );
				$session_id     = sprintf( '30000000-0000-4000-8000-%012d', $session_number + 1 );
				$page_key       = 'post:' . $page_id;
				$page_url       = '/benchmark/page-' . $page_id . '/';
				$page_title     = 'Benchmark Page ' . $page_id;
				$source         = $sources[ $session_number % count( $sources ) ];
				$campaign       = 'campaign-' . ( $session_number % 20 );
				$device         = $devices[ $session_number % count( $devices ) ];
				$element_text   = '' !== $selector ? 'CTA ' . ( $session_number % 12 ) : '';
				$values[]       = '(' . implode(
					',',
					array(
						convertrack_benchmark_quote( $event_id ),
						convertrack_benchmark_quote( $visitor_id ),
						convertrack_benchmark_quote( $session_id ),
						convertrack_benchmark_quote( $event_type ),
						$page_id,
						convertrack_benchmark_quote( $page_key ),
						convertrack_benchmark_quote( $page_url ),
						convertrack_benchmark_quote( $page_title ),
						convertrack_benchmark_quote( $element_text ),
						convertrack_benchmark_quote( $selector ),
						$is_conversion,
						convertrack_benchmark_quote( $device ),
						convertrack_benchmark_quote( $source ),
						convertrack_benchmark_quote( $campaign ),
						convertrack_benchmark_quote( $selector ),
						$pos_x,
						$pos_y,
						$pos_x,
						$pos_y,
						1440,
						900,
						1440,
						5000,
						0,
						( $scroll_depth * 40 ),
						$scroll_depth,
						convertrack_benchmark_quote( $created_at ),
						convertrack_benchmark_quote( $created_at ),
					)
				) . ')';
			}
			$sql = "INSERT INTO $events
				(event_id,visitor_id,session_id,event_type,post_id,page_key,page_url,page_title,element_text,element_selector,is_conversion,device_type,source,utm_campaign,heatmap_selector,pos_x,pos_y,rel_x,rel_y,viewport_w,viewport_h,document_w,document_h,scroll_x,scroll_y,scroll_depth,occurred_at_utc,created_at)
				VALUES " . implode( ',', $values );
			convertrack_benchmark_require_query( $wpdb->query( $sql ), 'Seed events' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		convertrack_benchmark_require_query( $wpdb->query( 'COMMIT' ), 'Commit fixture transaction' );
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' );
		throw $error;
	}
	$event_insert_seconds = microtime( true ) - $started;
	convertrack_benchmark_check_budget( $tier_started, $max_seconds, 'event fixture inserts' );

	$today = gmdate( 'Y-m-d', $today_ts );
	$started = microtime( true );
	$daily_sql = "INSERT INTO $daily
		(bucket_hash,stat_date,post_id,page_key,element_selector,element_text,clicks,conversions,conversion_events,pageviews,unique_visitors)
		SELECT MD5(CONCAT(DATE(created_at),'|',page_key,'|',element_selector)),DATE(created_at),MAX(post_id),page_key,element_selector,MAX(element_text),
		SUM(event_type='click'),COUNT(DISTINCT CASE WHEN is_conversion=1 THEN session_id END),SUM(is_conversion=1),SUM(event_type='pageview'),COUNT(DISTINCT visitor_id)
		FROM $events WHERE created_at < " . convertrack_benchmark_quote( $today . ' 00:00:00' ) . '
		GROUP BY DATE(created_at),page_key,element_selector';
	convertrack_benchmark_require_query( $wpdb->query( $daily_sql ), 'Build daily rollups' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$daily_seconds = microtime( true ) - $started;
	convertrack_benchmark_check_budget( $tier_started, $max_seconds, 'daily rollup fixtures' );

	$started = microtime( true );
	$visitor_sql = "INSERT INTO $visitors (bucket_hash,stat_date,visitor_hash,converted,conversion_events,pageviews,clicks)
		SELECT MD5(CONCAT(DATE(created_at),'|',visitor_id)),DATE(created_at),SHA2(CONCAT(visitor_id,'|benchmark'),256),MAX(is_conversion),SUM(is_conversion=1),SUM(event_type='pageview'),SUM(event_type='click')
		FROM $events WHERE created_at < " . convertrack_benchmark_quote( $today . ' 00:00:00' ) . " AND visitor_id<>''
		GROUP BY DATE(created_at),visitor_id";
	convertrack_benchmark_require_query( $wpdb->query( $visitor_sql ), 'Build visitor-day fixtures' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$visitor_seconds = microtime( true ) - $started;
	convertrack_benchmark_check_budget( $tier_started, $max_seconds, 'visitor-day fixtures' );

	$started = microtime( true );
	$session_sql = "INSERT INTO $sessions (bucket_hash,stat_date,session_hash,visitor_hash,converted,conversion_events,pageviews,clicks)
		SELECT MD5(CONCAT(DATE(created_at),'|',session_id)),DATE(created_at),SHA2(CONCAT(session_id,'|benchmark'),256),SHA2(CONCAT(MAX(visitor_id),'|benchmark'),256),MAX(is_conversion),SUM(is_conversion=1),SUM(event_type='pageview'),SUM(event_type='click')
		FROM $events WHERE created_at < " . convertrack_benchmark_quote( $today . ' 00:00:00' ) . " AND session_id<>''
		GROUP BY DATE(created_at),session_id";
	convertrack_benchmark_require_query( $wpdb->query( $session_sql ), 'Build session-day fixtures' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$session_seconds = microtime( true ) - $started;
	convertrack_benchmark_check_budget( $tier_started, $max_seconds, 'session-day fixtures' );

	return array(
		'pages'                  => $pages,
		'target_page'            => $target_page,
		'event_insert_seconds'   => round( $event_insert_seconds, 3 ),
		'event_rows_per_second'  => $event_insert_seconds > 0 ? (int) round( $event_count / $event_insert_seconds ) : 0,
		'daily_rollup_seconds'   => round( $daily_seconds, 3 ),
		'visitor_rollup_seconds' => round( $visitor_seconds, 3 ),
		'session_rollup_seconds' => round( $session_seconds, 3 ),
	);
}

/**
 * Create the isolated production-shaped tables.
 *
 * @param array  $tables Table names.
 * @param string $charset_collate wpdb charset/collation suffix.
 * @param array  $created_tables Successfully created table names, for cleanup.
 * @return void
 */
function convertrack_benchmark_create_schema( array $tables, $charset_collate, array &$created_tables ) {
	global $wpdb;
	$events   = convertrack_benchmark_identifier( $tables['events'] );
	$daily    = convertrack_benchmark_identifier( $tables['daily'] );
	$visitors = convertrack_benchmark_identifier( $tables['visitor_days'] );
	$sessions = convertrack_benchmark_identifier( $tables['session_days'] );
	$posts    = convertrack_benchmark_identifier( $tables['posts'] );

	$sql = array(
		"CREATE TABLE $events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,event_id char(36) DEFAULT NULL,visitor_id char(36) NOT NULL DEFAULT '',session_id char(36) NOT NULL DEFAULT '',event_type varchar(20) NOT NULL DEFAULT 'click',post_id bigint(20) unsigned NOT NULL DEFAULT 0,page_key varchar(191) NOT NULL DEFAULT '',object_type varchar(40) NOT NULL DEFAULT '',object_id bigint(20) unsigned NOT NULL DEFAULT 0,page_url varchar(255) NOT NULL DEFAULT '',page_title varchar(255) NOT NULL DEFAULT '',element_tag varchar(20) NOT NULL DEFAULT '',element_id varchar(191) NOT NULL DEFAULT '',element_classes varchar(255) NOT NULL DEFAULT '',element_text varchar(255) NOT NULL DEFAULT '',element_selector varchar(255) NOT NULL DEFAULT '',element_href varchar(255) NOT NULL DEFAULT '',is_conversion tinyint(1) NOT NULL DEFAULT 0,device_type varchar(10) NOT NULL DEFAULT '',country char(2) NOT NULL DEFAULT '',source varchar(100) NOT NULL DEFAULT '',referrer_host varchar(191) NOT NULL DEFAULT '',utm_source varchar(100) NOT NULL DEFAULT '',utm_medium varchar(100) NOT NULL DEFAULT '',utm_campaign varchar(150) NOT NULL DEFAULT '',utm_term varchar(150) NOT NULL DEFAULT '',search_keyword varchar(191) NOT NULL DEFAULT '',search_source varchar(50) NOT NULL DEFAULT '',heatmap_selector varchar(255) NOT NULL DEFAULT '',pos_x smallint(5) unsigned NOT NULL DEFAULT 0,pos_y smallint(5) unsigned NOT NULL DEFAULT 0,rel_x smallint(5) unsigned NOT NULL DEFAULT 0,rel_y smallint(5) unsigned NOT NULL DEFAULT 0,viewport_w int(10) unsigned NOT NULL DEFAULT 0,viewport_h int(10) unsigned NOT NULL DEFAULT 0,document_w int(10) unsigned NOT NULL DEFAULT 0,document_h int(10) unsigned NOT NULL DEFAULT 0,scroll_x int(10) unsigned NOT NULL DEFAULT 0,scroll_y int(10) unsigned NOT NULL DEFAULT 0,scroll_depth tinyint(3) unsigned NOT NULL DEFAULT 0,occurred_at_utc datetime DEFAULT NULL,created_at datetime NOT NULL,
			PRIMARY KEY (id),UNIQUE KEY event_id (event_id),KEY created_at (created_at),KEY page_key_created (page_key,created_at),KEY post_created (post_id,created_at),KEY type_created (event_type,created_at),KEY heatmap_device (post_id,event_type,device_type,created_at),KEY search_keyword (search_keyword),KEY visitor_id (visitor_id),KEY session_id (session_id)
		) ENGINE=InnoDB $charset_collate",
		"CREATE TABLE $daily (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,bucket_hash char(32) NOT NULL DEFAULT '',stat_date date NOT NULL,post_id bigint(20) unsigned NOT NULL DEFAULT 0,page_key varchar(191) NOT NULL DEFAULT '',element_selector varchar(255) NOT NULL DEFAULT '',element_text varchar(255) NOT NULL DEFAULT '',clicks int(10) unsigned NOT NULL DEFAULT 0,conversions int(10) unsigned NOT NULL DEFAULT 0,conversion_events int(10) unsigned NOT NULL DEFAULT 0,pageviews int(10) unsigned NOT NULL DEFAULT 0,unique_visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (id),UNIQUE KEY bucket_hash (bucket_hash),KEY stat_date (stat_date),KEY post_date (post_id,stat_date),KEY page_date (page_key,stat_date)
		) ENGINE=InnoDB $charset_collate",
		"CREATE TABLE $visitors (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,bucket_hash char(32) NOT NULL,stat_date date NOT NULL,visitor_hash char(64) NOT NULL,converted tinyint(1) unsigned NOT NULL DEFAULT 0,conversion_events int(10) unsigned NOT NULL DEFAULT 0,pageviews int(10) unsigned NOT NULL DEFAULT 0,clicks int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (id),UNIQUE KEY bucket_hash (bucket_hash),KEY date_visitor (stat_date,visitor_hash),KEY visitor_hash (visitor_hash)
		) ENGINE=InnoDB $charset_collate",
		"CREATE TABLE $sessions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,bucket_hash char(32) NOT NULL,stat_date date NOT NULL,session_hash char(64) NOT NULL,visitor_hash char(64) NOT NULL DEFAULT '',converted tinyint(1) unsigned NOT NULL DEFAULT 0,conversion_events int(10) unsigned NOT NULL DEFAULT 0,pageviews int(10) unsigned NOT NULL DEFAULT 0,clicks int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (id),UNIQUE KEY bucket_hash (bucket_hash),KEY date_session (stat_date,session_hash),KEY session_hash (session_hash),KEY visitor_hash (visitor_hash)
		) ENGINE=InnoDB $charset_collate",
		"CREATE TABLE $posts (
			ID bigint(20) unsigned NOT NULL,post_title text NOT NULL,post_name varchar(200) NOT NULL DEFAULT '',guid varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY (ID),KEY post_name (post_name)
		) ENGINE=InnoDB $charset_collate",
	);

	$create_order = array( $tables['events'], $tables['daily'], $tables['visitor_days'], $tables['session_days'], $tables['posts'] );
	foreach ( $sql as $index => $statement ) {
		convertrack_benchmark_require_query( $wpdb->query( $statement ), 'Create benchmark table ' . ( $index + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$created_tables[] = $create_order[ $index ];
	}
}

/**
 * Production-shaped query catalog for one fixture tier.
 *
 * @param array $tables Table names.
 * @param int   $target_page Hot fixture page.
 * @return array label => SQL.
 */
function convertrack_benchmark_queries( array $tables, $target_page ) {
	$events   = convertrack_benchmark_identifier( $tables['events'] );
	$daily    = convertrack_benchmark_identifier( $tables['daily'] );
	$visitors = convertrack_benchmark_identifier( $tables['visitor_days'] );
	$sessions = convertrack_benchmark_identifier( $tables['session_days'] );
	$posts    = convertrack_benchmark_identifier( $tables['posts'] );
	$today    = gmdate( 'Y-m-d' );
	$start    = gmdate( 'Y-m-d', strtotime( '-29 days 00:00:00 UTC' ) );
	$start_dt = $start . ' 00:00:00';
	$today_dt = $today . ' 00:00:00';
	$page_key = 'post:' . (int) $target_page;

	$daily_page_key = "CASE WHEN page_key<>'' THEN page_key WHEN post_id>0 THEN CONCAT('legacy-post:',post_id) ELSE 'legacy-global:0' END";
	$event_page_key = "CASE WHEN page_key<>'' THEN page_key WHEN post_id>0 THEN CONCAT('legacy-post:',post_id) WHEN page_url<>'' THEN CONCAT('legacy-url:',LEFT(SHA2(page_url,256),40)) ELSE 'legacy-global:0' END";
	$metrics = "SELECT combined.page_key,MAX(combined.post_id) post_id,MAX(combined.page_url) page_url,MAX(combined.page_title) page_title,SUM(combined.clicks) clicks,SUM(combined.pageviews) pageviews,SUM(combined.conversions) conversions
		FROM (
			SELECT $daily_page_key page_key,MAX(post_id) post_id,'' page_url,'' page_title,SUM(clicks) clicks,SUM(pageviews) pageviews,SUM(conversions) conversions
			FROM $daily WHERE stat_date>=" . convertrack_benchmark_quote( $start ) . ' AND stat_date<' . convertrack_benchmark_quote( $today ) . " GROUP BY $daily_page_key
			UNION ALL
			SELECT $event_page_key page_key,MAX(post_id) post_id,SUBSTRING_INDEX(MAX(CONCAT(created_at,'|||',page_url)),'|||',-1) page_url,SUBSTRING_INDEX(MAX(CONCAT(created_at,'|||',page_title)),'|||',-1) page_title,SUM(event_type='click') clicks,SUM(event_type='pageview') pageviews,SUM(is_conversion=1) conversions
			FROM $events WHERE created_at>=" . convertrack_benchmark_quote( $today_dt ) . " GROUP BY $event_page_key
		) combined GROUP BY combined.page_key";
	$title = "CASE WHEN COALESCE(posts.post_title,'')<>'' THEN posts.post_title WHEN COALESCE(metrics.page_title,'')<>'' THEN metrics.page_title WHEN metrics.page_key='legacy-global:0' THEN '(unknown / global)' ELSE metrics.page_key END";

	return array(
		'dashboard.rollup_totals' => "SELECT COALESCE(SUM(clicks),0) clicks,COALESCE(SUM(conversion_events),0) conversion_events,COALESCE(SUM(pageviews),0) pageviews FROM $daily WHERE stat_date>=" . convertrack_benchmark_quote( $start ) . ' AND stat_date<' . convertrack_benchmark_quote( $today ),
		'dashboard.unique_visitors' => "SELECT COUNT(DISTINCT visitor_hash) unique_visitors FROM $visitors WHERE stat_date>=" . convertrack_benchmark_quote( $start ) . ' AND stat_date<' . convertrack_benchmark_quote( $today ),
		'dashboard.session_dimensions' => "SELECT COUNT(DISTINCT session_hash) sessions,COUNT(DISTINCT CASE WHEN converted=1 THEN session_hash END) converting_sessions,COALESCE(SUM(conversion_events),0) conversion_events FROM $sessions WHERE stat_date>=" . convertrack_benchmark_quote( $start ) . ' AND stat_date<' . convertrack_benchmark_quote( $today ),
		'dashboard.trend' => "SELECT stat_date,SUM(clicks) clicks,SUM(pageviews) pageviews,SUM(conversions) conversions FROM $daily WHERE stat_date>=" . convertrack_benchmark_quote( $start ) . ' GROUP BY stat_date',
		'content.count' => "SELECT COUNT(*) FROM ($metrics) metrics LEFT JOIN $posts posts ON posts.ID=metrics.post_id",
		'content.page_1' => "SELECT metrics.page_key,metrics.post_id,metrics.page_url,metrics.page_title,metrics.clicks,metrics.pageviews,metrics.conversions,$title sort_title FROM ($metrics) metrics LEFT JOIN $posts posts ON posts.ID=metrics.post_id ORDER BY metrics.pageviews DESC,sort_title ASC,metrics.page_key ASC LIMIT 25 OFFSET 0",
		'content.search' => "SELECT metrics.page_key,metrics.post_id,metrics.page_url,metrics.page_title,metrics.clicks,metrics.pageviews,metrics.conversions,$title sort_title FROM ($metrics) metrics LEFT JOIN $posts posts ON posts.ID=metrics.post_id WHERE ($title LIKE '%Benchmark Page 42%' OR posts.post_name LIKE '%Benchmark Page 42%' OR posts.guid LIKE '%Benchmark Page 42%' OR metrics.page_key LIKE '%Benchmark Page 42%' OR metrics.page_url LIKE '%Benchmark Page 42%') ORDER BY metrics.pageviews DESC,sort_title ASC,metrics.page_key ASC LIMIT 25",
		'heatmap.click_grid' => "SELECT COALESCE(NULLIF(heatmap_selector,''),element_selector) sel,ROUND(pos_x/10) gx,ROUND(pos_y/10) gy,ROUND(rel_x/10) erx,ROUND(rel_y/10) ery,AVG(pos_x) px,AVG(pos_y) py,COUNT(*) w FROM $events WHERE event_type IN ('click','heatmap_click') AND (page_key=" . convertrack_benchmark_quote( $page_key ) . " OR (page_key='' AND post_id=" . (int) $target_page . ')) AND created_at>=' . convertrack_benchmark_quote( $start_dt ) . ' AND (pos_x>0 OR pos_y>0) GROUP BY sel,gx,gy,erx,ery ORDER BY w DESC LIMIT 1500',
		'heatmap.scroll_depth' => "SELECT scroll_depth d,COUNT(*) c FROM $events WHERE event_type='scroll' AND (page_key=" . convertrack_benchmark_quote( $page_key ) . " OR (page_key='' AND post_id=" . (int) $target_page . ')) AND created_at>=' . convertrack_benchmark_quote( $start_dt ) . ' GROUP BY scroll_depth',
		'heatmap.pageviews' => "SELECT COUNT(*) FROM $events WHERE event_type='pageview' AND (page_key=" . convertrack_benchmark_quote( $page_key ) . " OR (page_key='' AND post_id=" . (int) $target_page . ')) AND created_at>=' . convertrack_benchmark_quote( $start_dt ),
		'journeys.session_totals' => "SELECT COUNT(DISTINCT session_id) sessions,COUNT(DISTINCT CASE WHEN is_conversion=1 THEN session_id END) converting_sessions,SUM(is_conversion=1) conversions FROM $events WHERE created_at>=" . convertrack_benchmark_quote( $start_dt ),
		'journeys.converting_session_queue' => "SELECT session_id,MIN(created_at) converted_at FROM $events WHERE created_at>=" . convertrack_benchmark_quote( $start_dt ) . ' AND is_conversion=1 GROUP BY session_id ORDER BY converted_at DESC LIMIT 1000',
		'journeys.dropoffs' => "SELECT last.page_url url,MAX(last.page_title) title,COUNT(DISTINCT last.session_id) sessions FROM (SELECT e.session_id,e.page_url,e.page_title FROM $events e INNER JOIN (SELECT session_id,MAX(created_at) last_seen FROM $events WHERE created_at>=" . convertrack_benchmark_quote( $start_dt ) . " AND page_url<>'' GROUP BY session_id) m ON e.session_id=m.session_id AND e.created_at=m.last_seen LEFT JOIN (SELECT DISTINCT session_id FROM $events WHERE created_at>=" . convertrack_benchmark_quote( $start_dt ) . " AND is_conversion=1) c ON c.session_id=e.session_id WHERE c.session_id IS NULL AND e.page_url<>'') last GROUP BY last.page_url ORDER BY sessions DESC LIMIT 10",
		'journeys.preconversion_buttons' => "SELECT e.element_selector,SUBSTRING_INDEX(MAX(CONCAT(e.created_at,'|||',e.element_text)),'|||',-1) element_text,COUNT(*) clicks,COUNT(DISTINCT e.session_id) sessions FROM $events e INNER JOIN (SELECT session_id,MIN(created_at) converted_at FROM $events WHERE created_at>=" . convertrack_benchmark_quote( $start_dt ) . " AND is_conversion=1 GROUP BY session_id) c ON e.session_id=c.session_id AND e.created_at<=c.converted_at WHERE e.created_at>=" . convertrack_benchmark_quote( $start_dt ) . " AND e.event_type='click' AND e.element_selector<>'' GROUP BY e.element_selector ORDER BY clicks DESC LIMIT 10",
	);
}

/**
 * Run one isolated event tier.
 *
 * @param int $event_count Tier size.
 * @param int $batch_size Batch size.
 * @param int $runs Warm query runs.
 * @param int $max_seconds Safety budget.
 * @return array
 */
function convertrack_benchmark_run_tier( $event_count, $batch_size, $runs, $max_seconds ) {
	global $wpdb, $wp_version;

	$original_prefix      = $wpdb->prefix;
	$original_base_prefix = $wpdb->base_prefix;
	$original_blog_id     = isset( $wpdb->blogid ) ? (int) $wpdb->blogid : 0;
	$original_suppression = $wpdb->suppress_errors( true );
	$token                = substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 10 );
	$requested_prefix     = 'ctbench_' . $token . '_';
	$tables               = array();
	$created_tables       = array();
	$scope_prefix         = '';
	$cleaned              = false;
	$tier_started         = microtime( true );

	$cleanup = function () use ( &$cleaned, &$created_tables, &$scope_prefix, $original_prefix, $original_base_prefix, $original_blog_id, $original_suppression, &$wpdb ) {
		if ( $cleaned ) {
			return;
		}
		$cleaned = true;
		foreach ( array_reverse( $created_tables ) as $table ) {
			if ( '' === $scope_prefix || 0 !== strpos( $table, $scope_prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}
			$wpdb->query( 'DROP TABLE IF EXISTS ' . convertrack_benchmark_identifier( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->set_prefix( $original_base_prefix, true );
		if ( method_exists( $wpdb, 'set_blog_id' ) && $original_blog_id > 0 ) {
			$wpdb->set_blog_id( $original_blog_id );
		}
		$wpdb->suppress_errors( $original_suppression );
		if ( $wpdb->prefix !== $original_prefix ) {
			fwrite( STDERR, "CRITICAL: wpdb prefix restoration mismatch.\n" );
		}
	};
	register_shutdown_function( $cleanup );

	try {
		$prefix_result = $wpdb->set_prefix( $requested_prefix, true );
		if ( is_wp_error( $prefix_result ) ) {
			throw new RuntimeException( 'Unable to set isolated wpdb prefix: ' . $prefix_result->get_error_message() );
		}
		$scope_prefix = $wpdb->prefix;
		if ( $scope_prefix === $original_prefix || false === strpos( $scope_prefix, $token ) ) {
			throw new RuntimeException( 'wpdb did not enter the unique benchmark prefix.' );
		}
		$preexisting = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $scope_prefix ) . '%' ) );
		if ( ! empty( $preexisting ) ) {
			throw new RuntimeException( 'The generated benchmark prefix already exists; no tables were changed.' );
		}

		$tables = array(
			'events'       => $scope_prefix . 'convertrack_events',
			'daily'        => $scope_prefix . 'convertrack_daily',
			'visitor_days' => $scope_prefix . 'convertrack_visitor_days',
			'session_days' => $scope_prefix . 'convertrack_session_days',
			'posts'        => $scope_prefix . 'posts',
		);
		foreach ( $tables as $table ) {
			if ( strlen( $table ) > 64 || 0 !== strpos( $table, $scope_prefix ) ) {
				throw new RuntimeException( 'Unsafe or overlong fixture table name.' );
			}
		}

		$charset_collate = $wpdb->get_charset_collate();
		$schema_started  = microtime( true );
		convertrack_benchmark_create_schema( $tables, $charset_collate, $created_tables );
		$schema_seconds = microtime( true ) - $schema_started;
		convertrack_benchmark_check_budget( $tier_started, $max_seconds, 'schema creation' );

		$seed = convertrack_benchmark_seed( $tables, $event_count, $batch_size, $tier_started, $max_seconds );

		foreach ( $tables as $table ) {
			convertrack_benchmark_require_query( $wpdb->query( 'ANALYZE TABLE ' . convertrack_benchmark_identifier( $table ) ), 'Analyze ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$row_counts = array();
		foreach ( $tables as $name => $table ) {
			$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . convertrack_benchmark_identifier( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( null === $count && $wpdb->last_error ) {
				throw new RuntimeException( 'Count ' . $name . ': ' . $wpdb->last_error );
			}
			$row_counts[ $name ] = (int) $count;
		}

		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$storage_sql  = $wpdb->prepare(
			"SELECT table_name AS benchmark_table_name,engine AS benchmark_engine,table_rows AS benchmark_table_rows,data_length AS benchmark_data_length,index_length AS benchmark_index_length FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($placeholders) ORDER BY table_name",
			array_values( $tables )
		);
		$storage_rows = $wpdb->get_results( $storage_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$storage      = array();
		$total_bytes  = 0;
		foreach ( (array) $storage_rows as $row ) {
			$bytes       = (int) $row['benchmark_data_length'] + (int) $row['benchmark_index_length'];
			$total_bytes += $bytes;
			$storage[]   = array(
				'table'       => (string) $row['benchmark_table_name'],
				'engine'      => (string) $row['benchmark_engine'],
				'rows_estimate'=> (int) $row['benchmark_table_rows'],
				'data_mb'     => round( (int) $row['benchmark_data_length'] / 1048576, 3 ),
				'index_mb'    => round( (int) $row['benchmark_index_length'] / 1048576, 3 ),
			);
		}

		$query_results = array();
		foreach ( convertrack_benchmark_queries( $tables, $seed['target_page'] ) as $label => $sql ) {
			convertrack_benchmark_check_budget( $tier_started, $max_seconds, 'query ' . $label );
			$query_results[] = convertrack_benchmark_query( $label, $sql, $runs );
		}

		$result = array(
			'status'             => 'pass',
			'event_tier'         => $event_count,
			'fixture_prefix'     => $scope_prefix,
			'wordpress_version'  => isset( $wp_version ) ? (string) $wp_version : '',
			'php_version'        => PHP_VERSION,
			'database_version'   => (string) $wpdb->db_version(),
			'database_server'    => method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '',
			'schema_seconds'     => round( $schema_seconds, 3 ),
			'seed'               => $seed,
			'row_counts'         => $row_counts,
			'storage_total_mb'   => round( $total_bytes / 1048576, 3 ),
			'storage'            => $storage,
			'query_runs'         => $runs,
			'queries'            => $query_results,
			'total_seconds'      => round( microtime( true ) - $tier_started, 3 ),
			'cleanup_contract'   => 'Temporary tables are dropped and wpdb prefix is restored after result capture.',
		);
	} finally {
		$cleanup();
	}

	if ( $wpdb->prefix !== $original_prefix ) {
		throw new RuntimeException( 'wpdb prefix was not restored after benchmark cleanup.' );
	}
	$leftovers = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $scope_prefix ) . '%' ) );
	if ( ! empty( $leftovers ) ) {
		throw new RuntimeException( 'Benchmark cleanup left temporary tables behind: ' . implode( ', ', $leftovers ) );
	}
	$result['cleanup_verified'] = true;
	return $result;
}

$options     = array();
$positionals = array();
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( '--help' === $argument ) {
		$options['help'] = true;
		continue;
	}
	if ( preg_match( '/^--(tier|runs|batch-size|max-seconds)=(.+)$/', $argument, $match ) ) {
		$options[ $match[1] ] = $match[2];
		continue;
	}
	if ( 0 === strpos( $argument, '--' ) ) {
		fwrite( STDERR, 'Unknown or incomplete option: ' . $argument . "\n" );
		exit( 2 );
	}
	$positionals[] = $argument;
}
if ( isset( $options['help'] ) ) {
	echo "Usage: php tests/scale-query-benchmark.php [tier] [--tier=1000,25000] [--runs=3] [--batch-size=500] [--max-seconds=300]\n";
	exit( 0 );
}

$tier_input = isset( $options['tier'] ) ? (string) $options['tier'] : ( isset( $positionals[0] ) ? (string) $positionals[0] : '1000' );
$allowed    = array( 1000, 25000, 100000, 1000000 );
$tiers      = 'all' === strtolower( trim( $tier_input ) ) ? $allowed : array_map( 'intval', preg_split( '/\s*,\s*/', trim( $tier_input ) ) );
$tiers      = array_values( array_unique( $tiers ) );
foreach ( $tiers as $tier ) {
	if ( ! in_array( $tier, $allowed, true ) ) {
		fwrite( STDERR, "Unsupported tier: $tier. Allowed: 1000, 25000, 100000, 1000000.\n" );
		exit( 2 );
	}
}

$runs        = isset( $options['runs'] ) ? max( 1, min( 10, (int) $options['runs'] ) ) : 3;
$batch_size  = isset( $options['batch-size'] ) ? max( 100, min( 1000, (int) $options['batch-size'] ) ) : 500;
$max_seconds = isset( $options['max-seconds'] ) ? max( 30, min( 3600, (int) $options['max-seconds'] ) ) : 300;
$all_results = array();

echo "Convertrack isolated scale/query benchmark\n";
echo 'Tiers: ' . implode( ', ', $tiers ) . "; runs: $runs; batch: $batch_size; safety budget: {$max_seconds}s/tier\n";

try {
	foreach ( $tiers as $tier ) {
		echo "Running $tier event fixtures...\n";
		$result        = convertrack_benchmark_run_tier( $tier, $batch_size, $runs, $max_seconds );
		$all_results[] = $result;
		echo sprintf(
			"PASS %d: %.3fs total, %.3f MB, %d event rows/s, cleanup verified\n",
			$tier,
			$result['total_seconds'],
			$result['storage_total_mb'],
			$result['seed']['event_rows_per_second']
		);
	}
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}

echo "BENCHMARK_JSON_BEGIN\n";
echo wp_json_encode( $all_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
echo "BENCHMARK_JSON_END\n";
