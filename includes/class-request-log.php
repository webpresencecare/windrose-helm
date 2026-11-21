<?php
/**
 * Request log: the last 200 server-side HTTP requests of the network.
 *
 * Each entry keeps the time, site, context (web, cron, rest, ajax, cli), method, host, path, outcome (sent, failed,
 * blocked, answered), HTTP status and source (core, plugin, theme...). Never query strings, headers, bodies or keys;
 * path segments that look like tokens are masked. Stored in the network option `windrose_helm_request_log`, written
 * once at the end of each request.
 *
 * Requests answered by another plugin's `pre_http_request` filter are not seen (WordPress skips `http_api_debug`).
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects and stores the request log.
 *
 * @phpstan-type Entry array{time: int, site: int, context: string, method: string, host: string, path: string, outcome: string, code: int, source: string}
 */
final class Request_Log {

	public const OPTION = 'windrose_helm_request_log';

	/** Entries kept. */
	public const LIMIT = 200;

	/**
	 * Entries of this request, not stored yet.
	 *
	 * @var list<array<string, int|string>>
	 * @phpstan-var list<Entry>
	 */
	private static array $pending = array();

	/**
	 * Whether the shutdown writer is hooked.
	 *
	 * @var bool
	 */
	private static bool $writer_hooked = false;

	/**
	 * Records every request WordPress actually sends (when the log is on).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'http_api_debug', array( __CLASS__, 'record_sent' ), 10, 5 );
	}

	/**
	 * Whether the log is switched on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return Settings::privacy_on( 'request_log' );
	}

	/**
	 * Records a request WordPress sent (`http_api_debug`).
	 *
	 * @param mixed $response The response or WP_Error.
	 * @param mixed $context  Always "response".
	 * @param mixed $transport_class Transport class.
	 * @param mixed $args     Request arguments.
	 * @param mixed $url      Request address.
	 * @return void
	 */
	public static function record_sent( $response, $context, $transport_class, $args, $url ): void {
		unset( $context, $transport_class );
		if ( ! is_string( $url ) ) {
			return;
		}
		$failed = is_wp_error( $response );
		$code   = $failed || ! is_array( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		self::record( $url, is_array( $args ) ? $args : array(), $failed ? 'failed' : 'sent', $code );
	}

	/**
	 * Adds an entry.
	 *
	 * @param string               $url     Request address.
	 * @param array<string, mixed> $args    Request arguments (only the method is read).
	 * @param string               $outcome sent, failed, blocked or answered.
	 * @param int                  $code    HTTP status (0 when none).
	 * @return void
	 */
	public static function record( string $url, array $args, string $outcome, int $code = 0 ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return;
		}
		self::$pending[] = array(
			'time'    => time(),
			'site'    => get_current_blog_id(),
			'context' => self::context(),
			'method'  => strtoupper( is_string( $args['method'] ?? null ) ? $args['method'] : 'GET' ),
			'host'    => strtolower( $parts['host'] ),
			'path'    => self::mask_path( $parts['path'] ?? '/' ),
			'outcome' => $outcome,
			'code'    => $code,
			'source'  => self::source(),
		);
		if ( ! self::$writer_hooked ) {
			self::$writer_hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Stores the pending entries, newest first, keeping the last 200.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( array() === self::$pending ) {
			return;
		}
		$entries       = array_merge( array_reverse( self::$pending ), self::entries() );
		self::$pending = array();
		update_site_option( self::OPTION, array_slice( $entries, 0, self::LIMIT ) );
	}

	/**
	 * Stored entries, newest first.
	 *
	 * @return list<array<string, int|string>>
	 * @phpstan-return list<Entry>
	 */
	public static function entries(): array {
		$stored = get_site_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$entries = array();
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && isset( $entry['time'], $entry['host'] ) ) {
				$entries[] = array(
					'time'    => (int) $entry['time'],
					'site'    => (int) ( $entry['site'] ?? 0 ),
					'context' => (string) ( $entry['context'] ?? '' ),
					'method'  => (string) ( $entry['method'] ?? '' ),
					'host'    => (string) $entry['host'],
					'path'    => (string) ( $entry['path'] ?? '' ),
					'outcome' => (string) ( $entry['outcome'] ?? '' ),
					'code'    => (int) ( $entry['code'] ?? 0 ),
					'source'  => (string) ( $entry['source'] ?? '' ),
				);
			}
		}
		return $entries;
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::$pending = array();
		delete_site_option( self::OPTION );
	}

	/**
	 * Where the request comes from: cli, cron, rest, ajax or web.
	 *
	 * @return string
	 */
	public static function context(): string {
		return match ( true ) {
			defined( 'WP_CLI' ) && WP_CLI => 'cli',
			wp_doing_cron()               => 'cron',
			wp_is_serving_rest_request()  => 'rest',
			wp_doing_ajax()               => 'ajax',
			default                       => 'web',
		};
	}

	/**
	 * Masks path segments that look like keys or tokens (24 characters or more mixing letters and digits, or holding a
	 * colon), so the log never keeps a secret placed in a path.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	public static function mask_path( string $path ): string {
		$segments = explode( '/', $path );
		foreach ( $segments as $i => $segment ) {
			$looks_secret = str_contains( $segment, ':' )
				|| ( strlen( $segment ) >= 24 && preg_match( '/[A-Za-z]/', $segment ) && preg_match( '/\d/', $segment ) );
			if ( $looks_secret ) {
				$segments[ $i ] = '***';
			}
		}
		return mb_substr( implode( '/', $segments ), 0, 300 );
	}

	/**
	 * The code that made the request: core, wp-cli, plugin:<slug>, mu-plugin:<file>, theme:<slug> or unknown.
	 *
	 * @return string
	 */
	public static function source(): string {
		global $wp_plugin_paths;
		$skip        = array(
			'/wp-includes/class-wp-http.php',
			'/wp-includes/http.php',
			'/wp-includes/class-wp-hook.php',
			'/wp-includes/plugin.php',
			'/wp-includes/Requests/',
			'/wp-includes/class-requests.php',
			WINDROSE_HELM_DIR . '/includes/',
		);
		$plugin_dirs = array( wp_normalize_path( WP_PLUGIN_DIR ) => '' );
		// Symlinked plugins: WordPress maps each linked folder to its real folder (wp_register_plugin_realpath()).
		foreach ( (array) $wp_plugin_paths as $linked => $real ) {
			$plugin_dirs[ wp_normalize_path( (string) $real ) ] = basename( (string) $linked );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Reads file names only, to name the caller.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $frame ) {
			$file = wp_normalize_path( $frame['file'] ?? '' );
			if ( '' === $file || array_filter( $skip, static fn( string $part ): bool => str_contains( $file, wp_normalize_path( $part ) ) ) ) {
				continue;
			}
			foreach ( $plugin_dirs as $dir => $slug ) {
				if ( str_starts_with( $file, $dir . '/' ) ) {
					$rest = substr( $file, strlen( $dir ) + 1 );
					return 'plugin:' . ( '' === $slug ? strtok( $rest, '/' ) : $slug );
				}
			}
			if ( str_starts_with( $file, wp_normalize_path( WPMU_PLUGIN_DIR ) . '/' ) ) {
				return 'mu-plugin:' . strtok( substr( $file, strlen( wp_normalize_path( WPMU_PLUGIN_DIR ) ) + 1 ), '/' );
			}
			if ( str_starts_with( $file, wp_normalize_path( get_theme_root() ) . '/' ) ) {
				return 'theme:' . strtok( substr( $file, strlen( wp_normalize_path( get_theme_root() ) ) + 1 ), '/' );
			}
			if ( str_starts_with( $file, wp_normalize_path( ABSPATH ) ) ) {
				return 'core';
			}
			if ( str_contains( $file, 'wp-cli' ) || str_starts_with( $file, 'phar://' ) ) {
				return 'wp-cli';
			}
			return 'unknown';
		}//end foreach
		return 'unknown';
	}
}
