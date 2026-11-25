<?php
/**
 * Plugin Name:       Windrose Helm
 * Plugin URI:        https://www.webpresencecare.com/plugins
 * Description:       The base of the Windrose Suite: block-only defaults, privacy switches, blocked hosts and a log of outgoing requests, Bunny Fonts in the Font Library, a wordpress.org switch, updates for Windrose plugins, recommended plugins and health checks.
 * Version:           0.6.4
 * Requires at least: 7.2
 * Requires PHP:      8.4
 * Author:            Patricia BT
 * Author URI:        https://patriciabt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       windrose-helm
 * Domain Path:       /languages
 * Network:           true
 * Update URI:        https://github.com/webpresencecare/windrose-helm
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WINDROSE_HELM_VERSION', '0.6.4' );
define( 'WINDROSE_HELM_FILE', __FILE__ );
define( 'WINDROSE_HELM_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$parts  = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name   = (string) array_pop( $parts );
		$folder = array() === $parts ? '' : strtolower( implode( '/', $parts ) ) . '/';
		$file   = __DIR__ . '/includes/' . $folder . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/common/bootstrap.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
