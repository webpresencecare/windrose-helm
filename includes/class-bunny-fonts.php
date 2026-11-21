<?php
/**
 * Bunny Fonts in core's Font Library.
 *
 * While the Privacy module is on and "Block the Bunny Fonts collection" is off, Install Fonts offers a "Bunny Fonts"
 * collection next to Google Fonts. Three pieces:
 *
 * - The collection: `data/bunny-fonts.json`, built from https://fonts.bunny.net/list by `bin/bunny-fonts.py` before a
 *   release (sites never download the list). One face per weight and style; its `src` lists the family's script
 *   files (Bunny splits each weight into one file per script).
 * - The files: core's editor downloads previews and files in the browser. Helm rewrites each address to its own route
 *   on the site (`windrose-helm/v1/bunny-fonts/<check>/<family>/<file>`), and the server fetches the file from
 *   fonts.bunny.net through WordPress's HTTP API (so Blocked hosts and the request log apply). No browser contacts
 *   Bunny. The address ends in the file name because the editor names the upload after it; the check (a nonce) is in
 *   the path because a query string would become part of the file name.
 * - The page: a face holding several script files would be printed as one `src` with fallbacks (only the first file
 *   used). Helm splits such faces into one face per script with Bunny's unicode range (`wp_theme_json_data_user`), so
 *   the page, the editor and Site Editor previews printed by WordPress load only the scripts a text needs. This part
 *   runs whatever the switch says, for fonts already installed. Without Helm, such a face shows its first script.
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

use WP_Error;
use WP_Font_Collection;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Theme_JSON_Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Bunny Fonts collection, its file route and the split of script files.
 *
 * @phpstan-type Family array{subsets: list<string>, faces: list<string>, ranges?: array<string, string>}
 * @phpstan-type Index array{ranges: array<string, string>, families: array<string, Family>}
 */
final class Bunny_Fonts {

	/** Collection slug. */
	public const SLUG = 'bunny-fonts';

	/** Route under Rest::NAMESPACE. */
	public const ROUTE = '/bunny-fonts';

	/** Nonce action of the file addresses. */
	public const NONCE = 'windrose_helm_bunny_fonts';

	/** Where the files come from. */
	public const ORIGIN = 'https://fonts.bunny.net/';

	/** Largest file accepted (the largest Bunny script file is far smaller). */
	public const MAX_BYTES = 5 * MB_IN_BYTES;

	/** How long a browser may keep a fetched file. */
	public const BROWSER_CACHE = WEEK_IN_SECONDS;

	/**
	 * The index, once read.
	 *
	 * @var Index|null
	 */
	private static ?array $index = null;

	/**
	 * Hooks the split of script files (always, for fonts already installed).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_theme_json_data_user', array( __CLASS__, 'split_script_files' ) );
	}

	/**
	 * Whether the collection is offered: Privacy module on and its Bunny Fonts switch off.
	 *
	 * @return bool
	 */
	public static function offered(): bool {
		return Settings::module_on( 'privacy' ) && ! ( Settings::privacy()['bunny_fonts'] ?? true );
	}

	/**
	 * Hooks the collection and its file route (called by Modules\Privacy when offered).
	 *
	 * @return void
	 */
	public static function offer(): void {
		add_action( 'init', array( __CLASS__, 'register_collection' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_filter( 'rest_prepare_font_collection', array( __CLASS__, 'rest_response' ), 10, 3 );
		// Last: WordPress adds its CORS headers on this filter too, and nothing may send headers after the file.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_file' ), PHP_INT_MAX, 4 );
	}

	/**
	 * Registers the collection (core reads the JSON file only when the collection is asked for).
	 *
	 * @return void
	 */
	public static function register_collection(): void {
		if ( ! function_exists( 'wp_register_font_collection' ) || \WP_Font_Library::get_instance()->get_font_collection( self::SLUG ) ) {
			return;
		}
		wp_register_font_collection(
			self::SLUG,
			array(
				'name'          => _x( 'Bunny Fonts', 'font collection name', 'windrose-helm' ),
				'description'   => __( 'Install from Bunny Fonts. This website’s server downloads the fonts; they are copied to and served from your site.', 'windrose-helm' ),
				'font_families' => WINDROSE_HELM_DIR . '/data/bunny-fonts.json',
				'categories'    => array(
					array(
						'name' => _x( 'Sans Serif', 'font category', 'windrose-helm' ),
						'slug' => 'sans-serif',
					),
					array(
						'name' => _x( 'Display', 'font category', 'windrose-helm' ),
						'slug' => 'display',
					),
					array(
						'name' => _x( 'Serif', 'font category', 'windrose-helm' ),
						'slug' => 'serif',
					),
					array(
						'name' => _x( 'Handwriting', 'font category', 'windrose-helm' ),
						'slug' => 'handwriting',
					),
					array(
						'name' => _x( 'Monospace', 'font category', 'windrose-helm' ),
						'slug' => 'monospace',
					),
				),
			)
		);
	}

	/**
	 * Registers the file route.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			Rest::NAMESPACE,
			self::ROUTE . '/(?P<check>[0-9a-f]{10})/(?P<family>[a-z0-9-]{1,80})/(?P<file>[a-z0-9-]{1,160}\.woff2)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'fetch_file' ),
				'permission_callback' => array( __CLASS__, 'can_fetch' ),
				'args'                => array(
					'check'  => array(
						'type'     => 'string',
						'pattern'  => '^[0-9a-f]{10}$',
						'required' => true,
					),
					'family' => array(
						'type'     => 'string',
						'pattern'  => '^[a-z0-9-]{1,80}$',
						'required' => true,
					),
					'file'   => array(
						'type'     => 'string',
						'pattern'  => '^[a-z0-9-]{1,160}\.woff2$',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Points the collection's files at this site's route, with a check for the current person
	 * (`rest_prepare_font_collection`).
	 *
	 * @param mixed $response   The response.
	 * @param mixed $collection The collection.
	 * @param mixed $request    The request (unused).
	 * @return mixed
	 */
	public static function rest_response( $response, $collection, $request = null ) {
		unset( $request );
		if ( ! $response instanceof WP_REST_Response || ! $collection instanceof WP_Font_Collection || self::SLUG !== $collection->slug ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['font_families'] ) || ! is_array( $data['font_families'] ) ) {
			return $response;
		}
		$prefix = rest_url( Rest::NAMESPACE . self::ROUTE . '/' . wp_create_nonce( self::NONCE ) . '/' );
		foreach ( $data['font_families'] as $i => $family ) {
			$faces = $family['font_family_settings']['fontFace'] ?? null;
			if ( ! is_array( $faces ) ) {
				continue;
			}
			foreach ( $faces as $j => $face ) {
				if ( ! is_array( $face ) || ! isset( $face['src'] ) ) {
					continue;
				}
				$data['font_families'][ $i ]['font_family_settings']['fontFace'][ $j ]['src'] = is_array( $face['src'] )
					? array_map( static fn( $src ) => self::local_address( (string) $src, $prefix ), $face['src'] )
					: self::local_address( (string) $face['src'], $prefix );
			}
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * This site's address for a Bunny file address; anything else comes back unchanged.
	 *
	 * @param string $src    File address.
	 * @param string $prefix The route's address with the current person's check, ending in a slash.
	 * @return string
	 */
	public static function local_address( string $src, string $prefix ): string {
		if ( ! str_starts_with( $src, self::ORIGIN ) || ! preg_match( '#^[a-z0-9-]+/files/[a-z0-9-]+\.woff2$#', substr( $src, strlen( self::ORIGIN ) ) ) ) {
			return $src;
		}
		return $prefix . str_replace( '/files/', '/', substr( $src, strlen( self::ORIGIN ) ) );
	}

	/**
	 * Permission for the file route: someone logged in (WordPress's login cookie) who may install fonts on this site,
	 * with the check from their collection data. The editor's download sends cookies but no REST nonce header, so
	 * WordPress treats the request as anonymous; this restores the person only when the check in the path is theirs.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public static function can_fetch( WP_REST_Request $request ) {
		$user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'rest_forbidden', __( 'Log in to install fonts.', 'windrose-helm' ), array( 'status' => 401 ) );
		}
		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		if ( false === wp_verify_nonce( (string) $request['check'], self::NONCE ) || ! current_user_can( 'edit_theme_options' ) ) {
			wp_set_current_user( $previous );
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to install fonts on this site.', 'windrose-helm' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Fetches one file of the collection from Bunny Fonts.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function fetch_file( WP_REST_Request $request ) {
		$family = (string) $request['family'];
		$file   = (string) $request['file'];
		if ( ! self::offered() || null === self::parse_file( $file, $family ) ) {
			return new WP_Error( 'windrose_helm_bunny_unknown', __( 'This font file is not part of the Bunny Fonts collection.', 'windrose-helm' ), array( 'status' => 404 ) );
		}
		$response = wp_safe_remote_get(
			self::ORIGIN . $family . '/files/' . $file,
			array(
				'timeout'             => 20,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES + 1,
				'user-agent'          => 'WordPress/' . wp_get_wp_version(),
			)
		);
		$body     = wp_remote_retrieve_body( $response );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || strlen( $body ) > self::MAX_BYTES || ! str_starts_with( $body, 'wOF2' ) ) {
			return new WP_Error( 'windrose_helm_bunny_fetch', __( 'The font could not be downloaded from Bunny Fonts.', 'windrose-helm' ), array( 'status' => 502 ) );
		}
		return new WP_REST_Response(
			$body,
			200,
			array(
				'Content-Type'   => 'font/woff2',
				'Content-Length' => (string) strlen( $body ),
			)
		);
	}

	/**
	 * Sends a fetched file as it is instead of JSON (`rest_pre_serve_request`).
	 *
	 * @param mixed $served  Whether the request was served.
	 * @param mixed $result  The response.
	 * @param mixed $request The request.
	 * @param mixed $server  The REST server.
	 * @return mixed
	 */
	public static function serve_file( $served, $result, $request, $server ) {
		if ( $served || ! $result instanceof WP_REST_Response || ! $request instanceof WP_REST_Request || ! $server instanceof WP_REST_Server
			|| ! str_starts_with( $request->get_route(), '/' . Rest::NAMESPACE . self::ROUTE . '/' )
			|| 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return $served;
		}
		// WordPress sent its no-cache headers for logged-in people: this file may stay in this person's browser.
		$server->send_header( 'Cache-Control', 'private, max-age=' . self::BROWSER_CACHE );
		$server->remove_header( 'Expires' );
		$server->remove_header( 'Pragma' );
		if ( 'HEAD' !== $request->get_method() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A WOFF2 font file (checked by fetch_file()), sent as font/woff2 with nosniff.
			echo $result->get_data();
		}
		return true;
	}

	/**
	 * Splits each installed face that holds several Bunny script files into one face per script, with the script's
	 * unicode range (`wp_theme_json_data_user`). Faces of other origins stay as they are.
	 *
	 * @param mixed $theme_json The site's styles.
	 * @return mixed
	 */
	public static function split_script_files( $theme_json ) {
		if ( ! $theme_json instanceof WP_Theme_JSON_Data ) {
			return $theme_json;
		}
		$data     = $theme_json->get_data();
		$families = $data['settings']['typography']['fontFamilies']['custom'] ?? null;
		if ( ! is_array( $families ) ) {
			return $theme_json;
		}
		$changed = false;
		foreach ( $families as $i => $family ) {
			if ( ! is_array( $family ) || ! isset( $family['fontFace'] ) || ! is_array( $family['fontFace'] ) ) {
				continue;
			}
			$faces = array();
			foreach ( $family['fontFace'] as $face ) {
				$split = is_array( $face ) ? self::split_face( $face ) : null;
				if ( null === $split ) {
					$faces[] = $face;
					continue;
				}
				array_push( $faces, ...$split );
				$changed = true;
			}
			$families[ $i ]['fontFace'] = $faces;
		}
		if ( ! $changed ) {
			return $theme_json;
		}
		$data['settings']['typography']['fontFamilies']['custom'] = $families;
		return $theme_json->update_with( $data );
	}

	/**
	 * One face per script file, or null when the face is not a Bunny face with several script files.
	 *
	 * @param array<mixed> $face A face of the site's styles.
	 * @return list<array<mixed>>|null
	 */
	public static function split_face( array $face ): ?array {
		$src = $face['src'] ?? null;
		if ( ! is_array( $src ) || count( $src ) < 2 || isset( $face['unicodeRange'] ) ) {
			return null;
		}
		$weight = (string) ( $face['fontWeight'] ?? '400' );
		$style  = (string) ( $face['fontStyle'] ?? 'normal' );
		$split  = array();
		foreach ( $src as $url ) {
			$path   = is_string( $url ) ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
			$parsed = self::parse_file( wp_basename( $path ) );
			if ( null === $parsed || $parsed['weight'] !== $weight || $parsed['style'] !== $style ) {
				return null;
			}
			$split[] = array_merge(
				$face,
				array(
					'src'          => $url,
					'unicodeRange' => $parsed['range'],
				)
			);
		}
		return $split;
	}

	/**
	 * Reads a Bunny file name (`<family>-<script>-<weight>-<style>.woff2`, with the `-1` WordPress may add on upload).
	 *
	 * @param string      $file   File name.
	 * @param string|null $family The family it must belong to, if known.
	 * @return array{family: string, subset: string, weight: string, style: string, range: string}|null Null for any
	 *         name that is not a file of a family and script in the collection.
	 */
	public static function parse_file( string $file, ?string $family = null ): ?array {
		if ( ! preg_match( '/^([a-z0-9-]+)-([1-9]00)-(normal|italic)(?:-[0-9]+)?\.woff2$/', $file, $m ) ) {
			return null;
		}
		$index = self::index();
		$rest  = $m[1];
		$dash  = strpos( $rest, '-' );
		while ( false !== $dash ) {
			$id     = substr( $rest, 0, $dash );
			$subset = substr( $rest, $dash + 1 );
			$entry  = $index['families'][ $id ] ?? null;
			if ( null !== $entry && in_array( $subset, $entry['subsets'], true ) && in_array( $m[2] . '-' . $m[3], $entry['faces'], true )
				&& ( null === $family || $family === $id ) ) {
				$range = $entry['ranges'][ $subset ] ?? $index['ranges'][ $subset ] ?? '';
				return '' === $range ? null : array(
					'family' => $id,
					'subset' => $subset,
					'weight' => $m[2],
					'style'  => $m[3],
					'range'  => $range,
				);
			}
			$dash = strpos( $rest, '-', $dash + 1 );
		}
		return null;
	}

	/**
	 * The shipped index (families, their scripts and faces, unicode ranges).
	 *
	 * @return Index
	 */
	public static function index(): array {
		if ( null !== self::$index ) {
			return self::$index;
		}
		// A PHP file, so PHP's opcode cache keeps it in memory between requests (decoding the JSON costs time on every page).
		$index       = require WINDROSE_HELM_DIR . '/data/bunny-fonts-index.php';
		self::$index = array(
			'ranges'   => array(),
			'families' => array(),
		);
		if ( is_array( $index ) && is_array( $index['ranges'] ?? null ) && is_array( $index['families'] ?? null ) ) {
			/**
			 * Shape checked by bin/bunny-fonts.py check.
			 *
			 * @var Index $index
			 */
			self::$index = $index;
		}
		return self::$index;
	}
}
