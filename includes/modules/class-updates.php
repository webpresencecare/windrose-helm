<?php
/**
 * Module Updates: the wordpress.org switch.
 *
 * On (default): WordPress talks to wordpress.org as usual.
 *
 * Off: no request reaches wordpress.org or w.org. The three update checks (core, plugins, themes) get a valid empty
 * answer locally, because an error would stop WordPress before it asks the Windrose channel and log a PHP warning.
 * Every other request to those hosts is refused. Automatic core and translation updates are off, and the Updates,
 * Add Plugins and Add Themes screens and Site Health say that updates are manual.
 *
 * Windrose plugins keep updating from GitHub either way (Windrose\Common\Updater).
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm\Modules;

use Windrose\Helm\Request_Log;
use Windrose\Helm\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The wordpress.org switch.
 */
final class Updates {

	/** Update-check paths answered locally when wordpress.org is off, with their empty answers. */
	public const EMPTY_ANSWERS = array(
		'/core/version-check/'   => array(
			'offers'       => array(),
			'translations' => array(),
		),
		'/plugins/update-check/' => array(
			'plugins'      => array(),
			'translations' => array(),
			'no_update'    => array(),
		),
		'/themes/update-check/'  => array(
			'themes'       => array(),
			'translations' => array(),
			'no_update'    => array(),
		),
		// get_core_checksums() (Site Health) logs a PHP warning when this fails; empty checksums are skipped quietly.
		'/core/checksums/'       => array( 'checksums' => array() ),
	);

	/** Directory APIs refused before any request (they log a PHP warning when a request fails), with their endpoints. */
	public const REFUSED_APIS = array(
		'plugins_api'      => 'https://api.wordpress.org/plugins/info/1.2/',
		'themes_api'       => 'https://api.wordpress.org/themes/info/1.2/',
		'translations_api' => 'https://api.wordpress.org/translations/',
	);

	/**
	 * Hooks the switch when wordpress.org is off.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( Settings::wporg_on() ) {
			return;
		}
		add_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 30, 3 );
		foreach ( array_keys( self::REFUSED_APIS ) as $api ) {
			// After Windrose Common's own plugins_api answer (priority 10) for Windrose plugins.
			add_filter( $api, array( __CLASS__, 'refuse_api' ), 100, 3 );
		}
		add_filter( 'auto_update_core', '__return_false' );
		add_filter( 'auto_update_translation', '__return_false' );
		add_filter( 'async_update_translation', '__return_false' );
		add_action( 'network_admin_notices', array( __CLASS__, 'manual_updates_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'manual_updates_notice' ) );
		// Update data cached while wordpress.org was on would still show its entries (and load their icons from ps.w.org).
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'drop_wporg_updates' ) );
		add_filter( 'site_transient_update_themes', array( __CLASS__, 'drop_wporg_updates' ) );
	}

	/**
	 * Removes wordpress.org's entries from cached plugin and theme update data; Windrose updates from GitHub stay
	 * (`site_transient_update_plugins`, `site_transient_update_themes`).
	 *
	 * @param mixed $value Cached update data.
	 * @return mixed
	 */
	public static function drop_wporg_updates( $value ) {
		if ( ! is_object( $value ) ) {
			return $value;
		}
		foreach ( array( 'response', 'no_update' ) as $list ) {
			if ( isset( $value->$list ) && is_array( $value->$list ) ) {
				$value->$list = array_filter( $value->$list, array( __CLASS__, 'not_from_wporg' ) );
			}
		}
		if ( isset( $value->translations ) ) {
			$value->translations = array();
		}
		return $value;
	}

	/**
	 * Whether a cached update entry does not come from wordpress.org (its ID, package or page).
	 *
	 * @param mixed $item Entry (object for plugins, array for themes).
	 * @return bool
	 */
	public static function not_from_wporg( $item ): bool {
		$item = (array) $item;
		if ( str_starts_with( is_string( $item['id'] ?? null ) ? $item['id'] : '', 'w.org/' ) ) {
			return false;
		}
		foreach ( array( 'package', 'url' ) as $key ) {
			if ( is_string( $item[ $key ] ?? null ) && self::is_wporg_host( (string) wp_parse_url( $item[ $key ], PHP_URL_HOST ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a host belongs to wordpress.org (wordpress.org, w.org, wp.org and their subdomains).
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	public static function is_wporg_host( string $host ): bool {
		$host = strtolower( rtrim( $host, '.' ) );
		foreach ( array( 'wordpress.org', 'w.org', 'wp.org' ) as $domain ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Answers update checks with empty results and refuses every other wordpress.org request (`pre_http_request`).
	 *
	 * @param mixed $pre  False, or an earlier answer.
	 * @param mixed $args Request arguments.
	 * @param mixed $url  Request address.
	 * @return mixed
	 */
	public static function intercept( $pre, $args, $url ) {
		if ( false !== $pre || ! is_string( $url ) ) {
			return $pre;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! self::is_wporg_host( $host ) ) {
			return $pre;
		}
		$args = is_array( $args ) ? $args : array();
		if ( 'api.wordpress.org' === strtolower( $host ) ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			foreach ( self::EMPTY_ANSWERS as $prefix => $answer ) {
				if ( str_starts_with( $path, $prefix ) ) {
					Request_Log::record( $url, $args, 'answered', 200 );
					return array(
						'headers'  => array(),
						'body'     => (string) wp_json_encode( $answer ),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}
			}
		}
		Request_Log::record( $url, $args, 'blocked' );
		return new WP_Error( 'windrose_helm_wporg_off', __( 'wordpress.org is switched off in Windrose Helm.', 'windrose-helm' ) );
	}

	/**
	 * Answers wordpress.org directory API calls (`plugins_api`, `themes_api`, `translations_api`) with an error before
	 * any request, so WordPress shows the message on screen instead of logging a PHP warning.
	 *
	 * @param mixed $result False, or an earlier answer.
	 * @param mixed $action The API action or translation type.
	 * @param mixed $args   API arguments.
	 * @return mixed
	 */
	public static function refuse_api( $result, $action = '', $args = null ) {
		unset( $action, $args );
		if ( false !== $result ) {
			return $result;
		}
		Request_Log::record( self::REFUSED_APIS[ current_filter() ] ?? 'https://api.wordpress.org/', array(), 'blocked' );
		return new WP_Error( 'windrose_helm_wporg_off', self::manual_updates_message() );
	}

	/**
	 * The "updates are manual" message.
	 *
	 * @return string
	 */
	public static function manual_updates_message(): string {
		return __( 'Updates are manual: wordpress.org is switched off in Windrose Helm. WordPress, plugins, themes and translations from wordpress.org are not checked or updated; upload new versions by hand. Windrose plugins still update from GitHub.', 'windrose-helm' );
	}

	/**
	 * Shows the "updates are manual" notice on the Updates, Add Plugins and Add Themes screens.
	 *
	 * @return void
	 */
	public static function manual_updates_notice(): void {
		$screen = get_current_screen();
		if ( null === $screen || ( ! current_user_can( 'update_core' ) && ! current_user_can( 'install_plugins' ) ) ) {
			return;
		}
		$screens = array( 'update-core-network', 'plugin-install-network', 'theme-install-network', 'update-core', 'plugin-install', 'theme-install' );
		if ( ! in_array( $screen->id, $screens, true ) ) {
			return;
		}
		wp_admin_notice(
			sprintf(
				'%1$s <a href="%2$s">%3$s</a>',
				esc_html( self::manual_updates_message() ),
				esc_url( network_admin_url( 'admin.php?page=windrose-helm&tab=updates' ) ),
				esc_html__( 'Windrose Helm, Updates', 'windrose-helm' )
			),
			array(
				'type'               => 'warning',
				'additional_classes' => array( 'windrose-helm-manual-updates' ),
			)
		);
	}
}
