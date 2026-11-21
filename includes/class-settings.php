<?php
/**
 * Windrose Helm settings: network options holding switches.
 *
 * | Option                   | Holds                                                         |
 * |--------------------------|---------------------------------------------------------------|
 * | windrose_helm_modules    | One switch per module (Defaults, Privacy, Updates, Recommended) |
 * | windrose_helm_defaults   | One switch per default (block themes only, classic screens...) |
 * | windrose_helm_privacy    | One switch per privacy item, request log included             |
 * | windrose_helm_wporg      | 1 when wordpress.org is on (default), 0 when off              |
 *
 * The blocked hosts list (`windrose_helm_blocked_hosts`) is read and checked by Modules\Blocked_Hosts.
 *
 * Switches missing from a stored option take their default value, so new switches appear switched on.
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the switches.
 */
final class Settings {

	public const MODULES  = 'windrose_helm_modules';
	public const DEFAULTS = 'windrose_helm_defaults';
	public const PRIVACY  = 'windrose_helm_privacy';
	public const WPORG    = 'windrose_helm_wporg';

	/** Module switches. */
	public const MODULE_IDS = array( 'defaults', 'privacy', 'updates', 'recommended' );

	/** Default switches. */
	public const DEFAULT_IDS = array( 'block_themes', 'classic_screens', 'classic_editor', 'classic_block' );

	/** Privacy switches. */
	public const PRIVACY_IDS = array(
		'update_data',
		'site_address',
		'dashboard_news',
		'happy_checks',
		'emoji',
		'gravatar',
		'openverse',
		'remote_patterns',
		'block_directory',
		'google_fonts',
		'bunny_fonts',
		'login_languages',
		'welcome_guide',
		'rest_users',
		'request_log',
	);

	/**
	 * Module switches.
	 *
	 * @return array<string, bool>
	 */
	public static function modules(): array {
		return self::read( self::MODULES, self::MODULE_IDS, array() );
	}

	/**
	 * Privacy switches (all on unless switched off).
	 *
	 * @return array<string, bool>
	 */
	public static function privacy(): array {
		return self::read( self::PRIVACY, self::PRIVACY_IDS, array() );
	}

	/**
	 * Default switches. The first read stores the values that suit the network (see Modules\Defaults::initial_values()).
	 *
	 * @return array<string, bool>
	 */
	public static function defaults(): array {
		if ( false === get_site_option( self::DEFAULTS, false ) ) {
			update_site_option( self::DEFAULTS, Modules\Defaults::initial_values() );
		}
		return self::read( self::DEFAULTS, self::DEFAULT_IDS, array() );
	}

	/**
	 * Whether wordpress.org is switched on. Always true when the Updates module is off.
	 *
	 * @return bool
	 */
	public static function wporg_on(): bool {
		if ( ! self::module_on( 'updates' ) ) {
			return true;
		}
		return 0 !== (int) get_site_option( self::WPORG, 1 );
	}

	/**
	 * Whether a module is on.
	 *
	 * @param string $id Module ID.
	 * @return bool
	 */
	public static function module_on( string $id ): bool {
		return self::modules()[ $id ] ?? false;
	}

	/**
	 * Whether a privacy item is on (and the Privacy module too).
	 *
	 * @param string $id Privacy item ID.
	 * @return bool
	 */
	public static function privacy_on( string $id ): bool {
		return self::module_on( 'privacy' ) && ( self::privacy()[ $id ] ?? false );
	}

	/**
	 * Whether a default is on (and the Defaults module too).
	 *
	 * @param string $id Default ID.
	 * @return bool
	 */
	public static function default_on( string $id ): bool {
		return self::module_on( 'defaults' ) && ( self::defaults()[ $id ] ?? false );
	}

	/**
	 * Every setting, for the admin screen.
	 *
	 * @return array{modules: array<string, bool>, defaults: array<string, bool>, privacy: array<string, bool>, wporg: bool, blocked_hosts: list<string>}
	 */
	public static function all(): array {
		return array(
			'modules'       => self::modules(),
			'defaults'      => self::defaults(),
			'privacy'       => self::privacy(),
			'wporg'         => 0 !== (int) get_site_option( self::WPORG, 1 ),
			'blocked_hosts' => Modules\Blocked_Hosts::hosts(),
		);
	}

	/**
	 * Saves the given settings. Unknown keys are ignored; only booleans are accepted.
	 *
	 * @param array<string, mixed> $input Keys `modules`, `defaults`, `privacy` (switch ID => bool) and `wporg` (bool).
	 * @return void
	 */
	public static function update( array $input ): void {
		$groups = array(
			'modules'  => array( self::MODULES, self::MODULE_IDS, self::modules() ),
			'defaults' => array( self::DEFAULTS, self::DEFAULT_IDS, self::defaults() ),
			'privacy'  => array( self::PRIVACY, self::PRIVACY_IDS, self::privacy() ),
		);
		foreach ( $groups as $key => list( $option, $ids, $current ) ) {
			if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				if ( isset( $input[ $key ][ $id ] ) && is_bool( $input[ $key ][ $id ] ) ) {
					$current[ $id ] = $input[ $key ][ $id ];
				}
			}
			update_site_option( $option, $current );
		}
		if ( isset( $input['wporg'] ) && is_bool( $input['wporg'] ) ) {
			update_site_option( self::WPORG, $input['wporg'] ? 1 : 0 );
		}
	}

	/**
	 * Reads a switch option.
	 *
	 * @param string              $option   Option name.
	 * @param string[]            $ids      Known switch IDs.
	 * @param array<string, bool> $defaults Switches that default to off (others default to on).
	 * @return array<string, bool>
	 */
	private static function read( string $option, array $ids, array $defaults ): array {
		$stored = get_site_option( $option, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$values = array();
		foreach ( $ids as $id ) {
			$values[ $id ] = isset( $stored[ $id ] ) ? (bool) $stored[ $id ] : ( $defaults[ $id ] ?? true );
		}
		return $values;
	}
}
