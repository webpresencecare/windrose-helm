<?php
/**
 * Old post addresses of a site Windrose Helm made a network with "keep the current structure, with /blog in front"
 * (Network_Maker): /%postname%/ became /blog/%postname%/. WordPress's own guess for missing addresses does not find
 * those posts, so on the main site a missing address whose /blog/ form is a post is redirected there (301).
 *
 * Main site option windrose_helm_old_addresses: 1 when the conversion added the prefix (removed on uninstall).
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects old post addresses.
 */
final class Old_Addresses {

	public const OPTION = 'windrose_helm_old_addresses';

	/**
	 * Hooks the redirect on the main site, when the conversion added the prefix.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( is_main_site() && '1' === (string) get_option( self::OPTION, '' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 9 );
		}
	}

	/**
	 * The new address of an old one, or '' (the path relative to the site's address, without the query string).
	 *
	 * @param string $path Path relative to the home address, for example "welcome/".
	 * @return string
	 */
	public static function target( string $path ): string {
		$path = ltrim( $path, '/' );
		if ( '' === $path || str_starts_with( $path, 'blog/' ) || str_starts_with( $path, 'wp-' ) ) {
			return '';
		}
		$url = home_url( '/blog/' . $path );
		return url_to_postid( $url ) > 0 ? $url : '';
	}

	/**
	 * On a missing address, redirects to its /blog/ form when that is a post.
	 *
	 * @return void
	 */
	public static function redirect(): void {
		if ( ! is_404() ) {
			return;
		}
		$request = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path    = (string) wp_parse_url( $request, PHP_URL_PATH );
		$home    = (string) wp_parse_url( trailingslashit( home_url() ), PHP_URL_PATH );
		if ( '' === $path || ! str_starts_with( $path, $home ) ) {
			return;
		}
		$target = self::target( substr( $path, strlen( $home ) ) );
		if ( '' === $target ) {
			return;
		}
		$query = (string) wp_parse_url( $request, PHP_URL_QUERY );
		wp_safe_redirect( '' === $query ? $target : $target . '?' . $query, 301 );
		exit;
	}
}
