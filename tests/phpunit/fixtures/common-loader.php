<?php
/**
 * Runs Windrose Common bootstraps outside WordPress, with the few hooks they use stubbed.
 * Usage: php common-loader.php <common dir> [<common dir> ...]   (included in that order)
 * Prints the loaded version and folder, and how many times windrose_common_loaded fired.
 *
 * @package Windrose\Helm
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['windrose_test_hooks'] = array();
$GLOBALS['windrose_test_done']  = array();

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['windrose_test_hooks'][ $hook ][ $priority ][] = $callback;
	return true;
}
function add_filter( $hook, $callback, $priority = 10 ) {
	return add_action( $hook, $callback, $priority );
}
function did_action( $hook ) {
	return $GLOBALS['windrose_test_done'][ $hook ] ?? 0;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['windrose_test_done'][ $hook ] = ( $GLOBALS['windrose_test_done'][ $hook ] ?? 0 ) + 1;
	$hooks = $GLOBALS['windrose_test_hooks'][ $hook ] ?? array();
	ksort( $hooks );
	foreach ( $hooks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$callback( ...$args );
		}
	}
}

foreach ( array_slice( $argv, 1 ) as $dir ) {
	require $dir . '/bootstrap.php';
}
do_action( 'plugins_loaded' );

echo WINDROSE_COMMON_VERSION, "\n", WINDROSE_COMMON_DIR, "\n", did_action( 'windrose_common_loaded' ), "\n";
