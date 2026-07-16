<?php
/**
 * Verify that every backward-compatible admin slug is registered and gated.
 *
 * Run with the LocalWP PHP binary and site php.ini.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php was not found.\n" );
	exit( 1 );
}
require_once $wp_load;
require_once ABSPATH . 'wp-admin/includes/plugin.php';

function cvtrk_admin_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$administrators = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( empty( $administrators ) ) {
	fwrite( STDERR, "No administrator account is available for the smoke test.\n" );
	exit( 1 );
}

$slugs = array(
	'convertrack',
	'convertrack-pages',
	'convertrack-heatmaps',
	'convertrack-funnels',
	'convertrack-gsc',
	'convertrack-gsc-keywords',
	'convertrack-404-monitor',
	'convertrack-settings',
);

try {
	wp_set_current_user( (int) $administrators[0] );
	global $menu, $submenu, $admin_page_hooks, $_registered_pages, $_parent_pages, $_wp_menu_nopriv, $_wp_submenu_nopriv, $pagenow, $plugin_page;
	$menu              = array();
	$submenu           = array();
	$admin_page_hooks  = array();
	$_registered_pages = array();
	$_parent_pages     = array();
	$_wp_menu_nopriv   = array();
	$_wp_submenu_nopriv= array();
	$admin             = new Convertrack\Admin();
	$admin->add_menu();

	$pagenow = 'admin.php';
	foreach ( $slugs as $slug ) {
		$plugin_page = $slug;
		cvtrk_admin_assert( user_can_access_admin_page(), 'Administrator cannot access admin slug: ' . $slug );
	}

	wp_set_current_user( 0 );
	$menu               = array();
	$submenu            = array();
	$admin_page_hooks   = array();
	$_registered_pages  = array();
	$_parent_pages      = array();
	$_wp_menu_nopriv    = array();
	$_wp_submenu_nopriv = array();
	$admin->add_menu();
	foreach ( $slugs as $slug ) {
		$plugin_page = $slug;
		cvtrk_admin_assert( ! user_can_access_admin_page(), 'Unauthorized user can access admin slug: ' . $slug );
	}

	unset( $plugin_page );
	echo 'PASS: all 8 admin slugs are registered for administrators and denied to unauthorized users.' . "\n";
	exit( 0 );
} catch ( Throwable $error ) {
	unset( $plugin_page );
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
