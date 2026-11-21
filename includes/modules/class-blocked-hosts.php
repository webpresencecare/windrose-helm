<?php
/**
 * Module Privacy, blocked hosts: servers WordPress never contacts.
 *
 * Network administrators list hosts (network option `windrose_helm_blocked_hosts`, at most 100). Every server-side
 * request WordPress's HTTP API would send to one of them, or to one of their subdomains, gets a local error at
 * `pre_http_request` instead, as if the server could not be reached; a redirect into a blocked host is stopped too.
 * Each stop is logged as "blocked" in the request log. First use: `llm.automatorplugin.com` (Uncanny Automator's AI
 * credits check, which some sites see refused with 403 on every admin page).
 *
 * Not covered: files a page loads in the browser, and code that sends requests without WordPress's HTTP API.
 *
 * Never blocked: this network's own addresses (loopback requests run scheduled tasks and Site Health) and
 * wordpress.org (switched off as a whole on the Updates tab, which also makes updates manual).
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm\Modules;

use Windrose\Helm\Request_Log;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The blocked hosts list.
 */
final class Blocked_Hosts {

	public const OPTION = 'windrose_helm_blocked_hosts';

	/** Hosts kept at most. */
	public const MAX = 100;

	/**
	 * Hooks the block (the Privacy module is on).
	 *
	 * @return void
	 */
	public static function init(): void {
		// Early, so a blocked host is never answered or sent by a later filter.
		add_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 1, 3 );
		// Core maps the Requests library's hooks to actions named "requests-<hook>" (WP_HTTP_Requests_Hooks).
		add_action( 'requests-requests.before_redirect', array( __CLASS__, 'check_redirect' ) );
	}

	/**
	 * The stored hosts, cleaned.
	 *
	 * @return list<string>
	 */
	public static function hosts(): array {
		$stored = get_site_option( self::OPTION, array() );
		$hosts  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
			$host = is_string( $entry ) ? self::normalize( $entry ) : '';
			if ( '' !== $host && ! in_array( $host, $hosts, true ) ) {
				$hosts[] = $host;
			}
		}
		return array_slice( $hosts, 0, self::MAX );
	}

	/**
	 * Checks a list typed by a network administrator: each entry becomes a host name or IP address (an address copied
	 * from the request log or a browser keeps only its host), duplicates and empty lines are dropped.
	 *
	 * @param array<mixed> $entries Hosts or addresses.
	 * @return list<string>|WP_Error The hosts, or why the list was refused (status 400).
	 */
	public static function sanitize( array $entries ) {
		$hosts = array();
		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) ) {
				return self::refusal( __( 'Each blocked host must be text.', 'windrose-helm' ) );
			}
			if ( '' === trim( $entry ) ) {
				continue;
			}
			$host = self::normalize( $entry );
			if ( '' === $host ) {
				return self::refusal(
					/* translators: %s: what was typed. */
					sprintf( __( '“%s” is not a host name or IP address. Enter a host such as llm.example.com.', 'windrose-helm' ), mb_substr( trim( $entry ), 0, 100 ) )
				);
			}
			if ( Updates::is_wporg_host( $host ) ) {
				return self::refusal(
					/* translators: %s: host name. */
					sprintf( __( '%s belongs to wordpress.org. To stop every request to wordpress.org, switch it off on the Updates tab: WordPress then also says that updates are manual.', 'windrose-helm' ), $host )
				);
			}
			$own = self::own_host_covered( $host );
			if ( '' !== $own ) {
				return self::refusal(
					/* translators: 1: host name typed, 2: an address of this network. */
					sprintf( __( 'Blocking %1$s would also block %2$s, an address of this network: WordPress calls its own address to run scheduled tasks and Site Health tests.', 'windrose-helm' ), $host, $own )
				);
			}
			$hosts[ $host ] = true;
		}//end foreach
		if ( count( $hosts ) > self::MAX ) {
			return self::refusal(
				/* translators: %d: maximum number of hosts. */
				sprintf( __( 'At most %d hosts can be blocked.', 'windrose-helm' ), self::MAX )
			);
		}
		return array_keys( $hosts );
	}

	/**
	 * Saves a list (checked first).
	 *
	 * @param array<mixed> $entries Hosts or addresses.
	 * @return true|WP_Error
	 */
	public static function save( array $entries ) {
		$hosts = self::sanitize( $entries );
		if ( is_wp_error( $hosts ) ) {
			return $hosts;
		}
		if ( array() === $hosts ) {
			delete_site_option( self::OPTION );
		} else {
			update_site_option( self::OPTION, $hosts );
		}
		return true;
	}

	/**
	 * A host name or IP address, lowercase, from a host or an address ("https://host:443/path" gives "host"; a
	 * leading "*." is dropped, subdomains are covered anyway). Empty when it is neither.
	 *
	 * @param string $entry What was typed or stored.
	 * @return string
	 */
	public static function normalize( string $entry ): string {
		$entry = strtolower( trim( $entry ) );
		if ( '' === $entry ) {
			return '';
		}
		if ( ! str_contains( $entry, '://' ) ) {
			$entry = '//' . (string) preg_replace( '#^\*\.#', '', ltrim( $entry, '/' ) );
		}
		$host = wp_parse_url( $entry, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}
		$host = rtrim( trim( $host, '[]' ), '.' );
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		if ( ! str_contains( $host, '.' ) || false === filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
			return '';
		}
		return $host;
	}

	/**
	 * Whether a blocked entry covers a host: the same host, or one of its subdomains.
	 *
	 * @param string $entry Blocked host.
	 * @param string $host  Host of a request.
	 * @return bool
	 */
	public static function covers( string $entry, string $host ): bool {
		return $host === $entry || str_ends_with( $host, '.' . $entry );
	}

	/**
	 * The blocked entry covering a request address, or '' when the request may go. This network's own addresses are
	 * never blocked, even when the option was written by hand.
	 *
	 * @param string $url Request address.
	 * @return string
	 */
	public static function blocking_entry( string $url ): string {
		$host = self::host_of( $url );
		if ( '' === $host ) {
			return '';
		}
		$hosts = self::hosts();
		if ( array() === $hosts || in_array( $host, self::current_hosts(), true ) ) {
			return '';
		}
		foreach ( $hosts as $entry ) {
			if ( self::covers( $entry, $host ) ) {
				return $entry;
			}
		}
		return '';
	}

	/**
	 * Answers requests to blocked hosts with an error (`pre_http_request`).
	 *
	 * @param mixed $pre  False, or an earlier answer.
	 * @param mixed $args Request arguments.
	 * @param mixed $url  Request address.
	 * @return mixed
	 */
	public static function intercept( $pre, $args, $url ) {
		if ( false !== $pre || ! is_string( $url ) || '' === self::blocking_entry( $url ) ) {
			return $pre;
		}
		Request_Log::record( $url, is_array( $args ) ? $args : array(), 'blocked' );
		return new WP_Error( 'windrose_helm_blocked_host', self::message( self::host_of( $url ) ) );
	}

	/**
	 * Stops a redirect into a blocked host (`requests-requests.before_redirect`). WordPress turns the exception into a
	 * WP_Error (`http_request_failed`) for the code that made the request.
	 *
	 * @param mixed $location Address the server redirects to.
	 * @return void
	 * @throws \WpOrg\Requests\Exception When the address is blocked.
	 */
	public static function check_redirect( $location ): void {
		if ( ! is_string( $location ) || '' === self::blocking_entry( $location ) ) {
			return;
		}
		Request_Log::record( $location, array(), 'blocked' );
		throw new \WpOrg\Requests\Exception( esc_html( self::message( self::host_of( $location ) ) ), 'windrose_helm_blocked_host' );
	}

	/**
	 * The error message.
	 *
	 * @param string $host Blocked host.
	 * @return string
	 */
	private static function message( string $host ): string {
		/* translators: %s: host name. */
		return sprintf( __( 'Requests to %s are blocked in Windrose Helm, Privacy.', 'windrose-helm' ), $host );
	}

	/**
	 * The host of an address, lowercase, without brackets or a final dot.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function host_of( string $url ): string {
		return rtrim( trim( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), '[]' ), '.' );
	}

	/**
	 * Hosts of this request's network and site (checked on every request, so kept cheap).
	 *
	 * @return list<string>
	 */
	private static function current_hosts(): array {
		$hosts = array( self::host_of( home_url() ), self::host_of( site_url() ) );
		$net   = get_network();
		if ( $net ) {
			$hosts[] = strtolower( $net->domain );
		}
		return array_values( array_unique( array_filter( $hosts ) ) );
	}

	/**
	 * The first address of this network an entry would block, or ''.
	 *
	 * @param string $entry Host to block.
	 * @return string
	 */
	private static function own_host_covered( string $entry ): string {
		$hosts = self::current_hosts();
		$sites = get_sites(
			array(
				'network_id' => get_current_network_id(),
				'number'     => 0,
			)
		);
		foreach ( $sites as $site ) {
			if ( $site instanceof \WP_Site ) {
				$hosts[] = strtolower( $site->domain );
			}
		}
		foreach ( array_unique( $hosts ) as $host ) {
			if ( self::covers( $entry, $host ) ) {
				return $host;
			}
		}
		return '';
	}

	/**
	 * A refused list.
	 *
	 * @param string $message Why.
	 * @return WP_Error
	 */
	private static function refusal( string $message ): WP_Error {
		return new WP_Error( 'windrose_helm_blocked_host_invalid', $message, array( 'status' => 400 ) );
	}
}
