<?php
/**
 * REST routes of the Windrose Helm screen (namespace windrose-helm/v1). Network administrators only.
 *
 * | Route                   | Methods      | Permission              |
 * |-------------------------|--------------|-------------------------|
 * | /settings               | GET, POST    | manage_network_options  |
 * | /request-log            | GET, DELETE  | manage_network_options  |
 * | /updates                | GET          | manage_network_options  |
 * | /updates/check          | POST         | manage_network_options  |
 * | /recommended            | GET, POST, DELETE | manage_network_plugins |
 * | /recommended/lookup     | POST         | manage_network_plugins  |
 *
 * /settings also holds the blocked hosts list (`blocked_hosts`, checked by Modules\Blocked_Hosts).
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

use Windrose\Common\Updater;
use Windrose\Helm\Modules\Blocked_Hosts;
use Windrose\Helm\Modules\Recommended;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes.
 */
final class Rest {

	public const NAMESPACE = 'windrose-helm/v1';

	/**
	 * Hooks the routes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$network  = array( __CLASS__, 'can_manage_network' );
		$switches = static fn( array $ids ): array => array(
			'type'                 => 'object',
			'properties'           => array_fill_keys( $ids, array( 'type' => 'boolean' ) ),
			'additionalProperties' => false,
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => $network,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_settings' ),
					'permission_callback' => $network,
					'args'                => array(
						'modules'       => $switches( Settings::MODULE_IDS ),
						'defaults'      => $switches( Settings::DEFAULT_IDS ),
						'privacy'       => $switches( Settings::PRIVACY_IDS ),
						'wporg'         => array( 'type' => 'boolean' ),
						'blocked_hosts' => array(
							'type'     => 'array',
							'maxItems' => Blocked_Hosts::MAX,
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 2048,
							),
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/request-log',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_request_log' ),
					'permission_callback' => $network,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'clear_request_log' ),
					'permission_callback' => $network,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/updates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_updates' ),
				'permission_callback' => $network,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/updates/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'check_updates' ),
				'permission_callback' => $network,
			)
		);
		// The Recommended plugins routes answer only while that module is on.
		$plugins = static fn(): bool => is_multisite() && current_user_can( 'manage_network_plugins' ) && Settings::module_on( 'recommended' );
		register_rest_route(
			self::NAMESPACE,
			'/recommended',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_recommended' ),
					'permission_callback' => $plugins,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'save_recommended' ),
					'permission_callback' => $plugins,
					'args'                => array(
						'items' => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => Recommended::MAX_ENTRIES,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'slug'     => array( 'type' => 'string' ),
									'name'     => array( 'type' => 'string' ),
									'category' => array(
										'type' => 'string',
										'enum' => array_keys( Recommended::categories() ),
									),
									'why'      => array( 'type' => 'string' ),
									'network'  => array( 'type' => 'boolean' ),
								),
							),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'reset_recommended' ),
					'permission_callback' => $plugins,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/recommended/lookup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'lookup_recommended' ),
				'permission_callback' => $plugins,
				'args'                => array(
					'slug' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Network administrators only.
	 *
	 * @return bool
	 */
	public static function can_manage_network(): bool {
		return is_multisite() && current_user_can( 'manage_network_options' );
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings(): WP_REST_Response {
		return new WP_REST_Response( Settings::all() );
	}

	/**
	 * POST /settings: saves the switches given (partial updates allowed) and the blocked hosts list. A list that is
	 * refused saves nothing.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( WP_REST_Request $request ) {
		$hosts = null;
		if ( $request->has_param( 'blocked_hosts' ) ) {
			$given = $request->get_param( 'blocked_hosts' );
			$hosts = Blocked_Hosts::sanitize( is_array( $given ) ? $given : array() );
			if ( is_wp_error( $hosts ) ) {
				return $hosts;
			}
		}
		$input = array();
		foreach ( array( 'modules', 'defaults', 'privacy', 'wporg' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}
		Settings::update( $input );
		if ( null !== $hosts ) {
			Blocked_Hosts::save( $hosts );
		}
		return new WP_REST_Response( Settings::all() );
	}

	/**
	 * GET /request-log.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_request_log(): WP_REST_Response {
		$names   = array();
		$entries = array();
		foreach ( Request_Log::entries() as $index => $entry ) {
			$site = $entry['site'];
			if ( ! isset( $names[ $site ] ) ) {
				$details        = get_site( $site );
				$names[ $site ] = $details ? (string) $details->blogname : (string) $site;
			}
			$entries[] = $entry + array(
				'id'        => $index + 1,
				'site_name' => $names[ $site ],
				'date'      => gmdate( 'c', $entry['time'] ),
			);
		}
		return new WP_REST_Response(
			array(
				'enabled' => Request_Log::enabled(),
				'limit'   => Request_Log::LIMIT,
				'entries' => $entries,
			)
		);
	}

	/**
	 * DELETE /request-log.
	 *
	 * @return WP_REST_Response
	 */
	public static function clear_request_log(): WP_REST_Response {
		Request_Log::clear();
		return self::get_request_log();
	}

	/**
	 * GET /updates.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_updates(): WP_REST_Response {
		return new WP_REST_Response( self::updates_state( false ) );
	}

	/**
	 * POST /updates/check: asks GitHub again and lets WordPress check again on the next admin page.
	 *
	 * @return WP_REST_Response
	 */
	public static function check_updates(): WP_REST_Response {
		delete_site_transient( 'update_plugins' );
		return new WP_REST_Response( self::updates_state( true ) );
	}

	/**
	 * GET /recommended.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_recommended(): WP_REST_Response {
		$categories = array();
		foreach ( Recommended::categories() as $value => $label ) {
			$categories[] = array(
				'value' => $value,
				'label' => $label,
			);
		}
		return new WP_REST_Response(
			array(
				'wporg'      => Settings::wporg_on(),
				'canInstall' => Recommended::can_install(),
				'customized' => Recommended::is_customized(),
				'categories' => $categories,
				'items'      => Recommended::items(),
			)
		);
	}

	/**
	 * POST /recommended: replaces the list.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_recommended( WP_REST_Request $request ) {
		$items = $request->get_param( 'items' );
		$saved = Recommended::save( is_array( $items ) ? $items : array() );
		return is_wp_error( $saved ) ? $saved : self::get_recommended();
	}

	/**
	 * DELETE /recommended: back to the default list.
	 *
	 * @return WP_REST_Response
	 */
	public static function reset_recommended(): WP_REST_Response {
		Recommended::reset();
		return self::get_recommended();
	}

	/**
	 * POST /recommended/lookup: a plugin's name and summary on wordpress.org.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function lookup_recommended( WP_REST_Request $request ) {
		$found = Recommended::lookup( strtolower( trim( (string) $request->get_param( 'slug' ) ) ) );
		return is_wp_error( $found ) ? $found : new WP_REST_Response( $found );
	}

	/**
	 * State of the Updates tab.
	 *
	 * @param bool $force Ask GitHub again.
	 * @return array<string, mixed>
	 */
	private static function updates_state( bool $force ): array {
		$plugins = array();
		foreach ( Updater::status( $force ) as $row ) {
			$plugins[] = $row + array(
				'id'          => $row['slug'],
				'state_label' => Admin::state_label( $row['state'], $row['code'] ),
				'checked_at'  => $row['checked'] > 0 ? gmdate( 'c', $row['checked'] ) : '',
			);
		}
		return array(
			'wporg'          => Settings::wporg_on(),
			'common_version' => defined( 'WINDROSE_COMMON_VERSION' ) ? WINDROSE_COMMON_VERSION : '',
			'plugins'        => $plugins,
		);
	}
}
