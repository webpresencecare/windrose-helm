<?php
/**
 * PHPUnit bootstrap: the WordPress test library (multisite), with Windrose Helm loaded as a network plugin.
 *
 * Environment:
 * - WP_TESTS_DIR: the test library (tests/phpunit of wordpress-develop), for example as bin/install-wp-tests.sh
 *   installs it. Default: /var/www/html/tools/wp-tests/tests/phpunit.
 * - WP_TESTS_CONFIG_FILE_PATH: its wp-tests-config.php (optional; default next to the test library).
 *
 * The tests use their own WordPress copy and test database, never a site's data. On GitHub (ci.yml),
 * bin/install-wp-tests.sh installs them.
 *
 * @package Windrose\Helm
 */

$windrose_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/var/www/html/tools/wp-tests/tests/phpunit';

if ( getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) );
}
foreach ( array( dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills', '/var/www/html/tools/vendor/yoast/phpunit-polyfills' ) as $windrose_polyfills ) {
	if ( is_dir( $windrose_polyfills ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $windrose_polyfills );
		break;
	}
}

require_once $windrose_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/windrose-helm.php';
	}
);

require $windrose_tests_dir . '/includes/bootstrap.php';
