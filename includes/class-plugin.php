<?php
/**
 * Windrose Helm: start-up.
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the modules that are switched on.
 */
final class Plugin {

	/**
	 * Hooks everything (on plugins_loaded). Windrose Helm only works on a multisite network.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! is_multisite() ) {
			// On a single site: only the Make a network screen (Tools) and its notice.
			add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
			Network_Maker::init();
			return;
		}
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );

		if ( Settings::module_on( 'defaults' ) ) {
			Modules\Defaults::init();
		}
		if ( Settings::module_on( 'privacy' ) ) {
			Modules\Privacy::init();
		}
		if ( Settings::module_on( 'updates' ) ) {
			Modules\Updates::init();
		}
		Bunny_Fonts::init();
		Health::init();
		Rest::init();
		Admin::init();
		Setup::init();
		Old_Addresses::init();
	}

	/**
	 * Network activation: stores the defaults that suit this network.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! is_multisite() ) {
			return;
		}
		Settings::defaults();
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'windrose-helm', false, dirname( plugin_basename( WINDROSE_HELM_FILE ) ) . '/languages' );
	}

	/**
	 * Suggested text for each site's privacy policy (Settings, Privacy, Policy guide).
	 *
	 * @return void
	 */
	public static function privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'Windrose Helm collects nothing about visitors and sets no cookies. It reduces what this website sends to other services: for example, avatars can be shown from this website instead of Gravatar, and emoji images are not loaded from WordPress.org.', 'windrose-helm' ) . '</p>'
			. '<p>' . esc_html__( 'Network administrators can keep a log of the requests this website makes to other servers. The log holds the address of the server and the path only, never query strings, content or personal data, and keeps the last 200 requests.', 'windrose-helm' ) . '</p>'
			. '<p>' . esc_html__( 'If network administrators offer the Bunny Fonts collection in the Font Library, this website’s server downloads the fonts an administrator previews or installs from Bunny Fonts (fonts.bunny.net). Visitors’ browsers never contact Bunny Fonts: installed fonts are served from this website.', 'windrose-helm' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Windrose Helm', 'windrose-helm' ), wp_kses_post( $text ) );
	}
}
