<?php
/**
 * The network admin screen "Windrose Helm" (tabs Overview, Defaults, Privacy, Updates, Recommended plugins).
 *
 * The screen is a small React app (src/admin, built into build/admin) using WordPress components, DataForm and
 * DataViews. Switch labels and descriptions are written here, in PHP, so all translations live in one place.
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network admin screen.
 */
final class Admin {

	public const SLUG = 'windrose-helm';

	/**
	 * Hooks the menu and assets.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'network_admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Adds the menu item.
	 *
	 * @return void
	 */
	public static function menu(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Menu icon data URI.
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 0l2.2 7.8L20 10l-7.8 2.2L10 20l-2.2-7.8L0 10l7.8-2.2z"/></svg>'
		);
		add_menu_page(
			__( 'Windrose Helm', 'windrose-helm' ),
			__( 'Windrose Helm', 'windrose-helm' ),
			'manage_network_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			$icon
		);
	}

	/**
	 * Prints the screen container; the app renders into it.
	 *
	 * @return void
	 */
	public static function render(): void {
		printf(
			'<div class="wrap windrose-helm"><h1>%1$s</h1><div id="windrose-helm-app"><p>%2$s</p></div><noscript><p>%3$s</p></noscript></div>',
			esc_html__( 'Windrose Helm', 'windrose-helm' ),
			esc_html__( 'Loading…', 'windrose-helm' ),
			esc_html__( 'This screen needs JavaScript.', 'windrose-helm' )
		);
	}

	/**
	 * Loads the app on its own screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook_suffix || ! is_network_admin() ) {
			return;
		}
		$asset_file = WINDROSE_HELM_DIR . '/build/admin/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_HELM_VERSION;

		wp_enqueue_script( 'windrose-helm-admin', plugins_url( 'build/admin/index.js', WINDROSE_HELM_FILE ), $deps, $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( 'windrose-helm-admin', 'windrose-helm', WINDROSE_HELM_DIR . '/languages' );
		wp_add_inline_script( 'windrose-helm-admin', 'window.windroseHelm = ' . wp_json_encode( self::script_data() ) . ';', 'before' );

		wp_enqueue_style( 'windrose-helm-admin', plugins_url( 'build/admin/index.css', WINDROSE_HELM_FILE ), array( 'wp-components' ), $ver );
		wp_style_add_data( 'windrose-helm-admin', 'rtl', 'replace' );
	}

	/**
	 * Labels and links for the app.
	 *
	 * @return array<string, mixed>
	 */
	public static function script_data(): array {
		return array(
			'modules'    => array(
				'defaults'    => array( __( 'Defaults', 'windrose-helm' ), __( 'Block themes and the Site Editor only.', 'windrose-helm' ) ),
				'privacy'     => array( __( 'Privacy', 'windrose-helm' ), __( 'Blocks requests to other services, and keeps a log of what is still sent.', 'windrose-helm' ) ),
				'updates'     => array( __( 'Updates', 'windrose-helm' ), __( 'The wordpress.org switch.', 'windrose-helm' ) ),
				'recommended' => array( __( 'Recommended plugins', 'windrose-helm' ), __( 'A short list of plugins that suit the Windrose Suite.', 'windrose-helm' ) ),
			),
			'defaults'   => array(
				'block_themes'    => array( __( 'Block themes only', 'windrose-helm' ), __( 'Classic themes are hidden from the Themes screens and the theme installer, and cannot be activated.', 'windrose-helm' ) ),
				'classic_screens' => array( __( 'No Customizer or Widgets screens', 'windrose-helm' ), __( 'For block themes, the Customizer and Widgets screens are removed; visits go to the Site Editor.', 'windrose-helm' ) ),
				'classic_editor'  => array( __( 'No classic editing plugins', 'windrose-helm' ), __( 'Classic Editor, Classic Widgets and Disable Gutenberg are hidden from the plugin installer and cannot be activated.', 'windrose-helm' ) ),
				'classic_block'   => array( __( 'No Classic block in the inserter', 'windrose-helm' ), __( 'Existing classic content still displays and can be edited or converted to blocks.', 'windrose-helm' ) ),
			),
			// Each label says what is blocked: on means blocked, for every switch.
			'privacy'    => array(
				'update_data'     => array( __( 'Block extra data in update checks', 'windrose-helm' ), __( 'Version checks send only the WordPress and PHP versions and the language, not the number of sites and users, the PHP extensions or the server. Requests to wordpress.org do not include this network’s address.', 'windrose-helm' ) ),
				'site_address'    => array( __( 'Block the site address in requests to other servers', 'windrose-helm' ), __( 'WordPress tells every server it contacts the address of this network. Blocked, it only says “WordPress” and its version. If a service stops working because it identified sites by their address, turn this switch off.', 'windrose-helm' ) ),
				'dashboard_news'  => array( __( 'Block Events and News on the dashboard', 'windrose-helm' ), __( 'The dashboard widget sends your approximate location and time zone to wordpress.org and loads news feeds.', 'windrose-helm' ) ),
				'happy_checks'    => array( __( 'Block the browser and PHP checks', 'windrose-helm' ), __( 'The dashboard sends your browser’s identity (Browse Happy) and the PHP version (Serve Happy) to wordpress.org. Blocked, Site Health checks PHP against the Windrose minimum instead.', 'windrose-helm' ) ),
				'emoji'           => array( __( 'Block emoji images from s.w.org', 'windrose-helm' ), __( 'The emoji script and its images from s.w.org are not loaded; emoji are shown by the browser.', 'windrose-helm' ) ),
				'gravatar'        => array( __( 'Block Gravatar', 'windrose-helm' ), __( 'Blocked, everyone gets the same local avatar and no email hash is sent to Gravatar. Turn this switch off to show the profile pictures people have on Gravatar.', 'windrose-helm' ) ),
				'openverse'       => array( __( 'Block Openverse in the editor', 'windrose-helm' ), __( 'Openverse is not offered as a media source in the block inserter.', 'windrose-helm' ) ),
				'remote_patterns' => array( __( 'Block patterns from the Pattern Directory', 'windrose-helm' ), __( 'The editor only offers patterns from the theme and plugins installed here.', 'windrose-helm' ) ),
				'block_directory' => array( __( 'Block the Block Directory search', 'windrose-helm' ), __( 'Searching the inserter does not send the search term to wordpress.org.', 'windrose-helm' ) ),
				'google_fonts'    => array( __( 'Block the Google Fonts collection', 'windrose-helm' ), __( 'The Font Library does not offer Google Fonts; fonts can still be uploaded.', 'windrose-helm' ) ),
				'bunny_fonts'     => array( __( 'Block the Bunny Fonts collection', 'windrose-helm' ), __( 'The Font Library does not offer Bunny Fonts. Turned off, Install Fonts offers them: this network’s server downloads the fonts someone previews or installs from fonts.bunny.net (browsers never contact Bunny Fonts), and installed fonts are served from the site.', 'windrose-helm' ) ),
				'login_languages' => array( __( 'Block the language menu on the login screen', 'windrose-helm' ), __( 'The login screen does not offer a language choice.', 'windrose-helm' ) ),
				'welcome_guide'   => array( __( 'Block the editor Welcome Guides', 'windrose-helm' ), __( 'The guides load their pictures from s.w.org. Blocked, they stay closed unless someone opens them from the editor menu.', 'windrose-helm' ) ),
				'rest_users'      => array( __( 'Block usernames in the REST API for visitors', 'windrose-helm' ), __( 'Visitors who are not logged in no longer see usernames in the REST API list of authors, and cannot look users up by username or search them. Logged-in people are not affected. Display names are still public: give each author a display name that is not their username.', 'windrose-helm' ) ),
			),
			// Not a block: it records. Shown with the request log.
			'requestLog' => array( __( 'Log outgoing requests', 'windrose-helm' ), __( 'Keeps the last 200 requests this network makes to other servers: server and path only.', 'windrose-helm' ) ),
			'states'     => array(
				'current'      => self::state_label( 'current', 0 ),
				'update'       => self::state_label( 'update', 0 ),
				'none'         => self::state_label( 'none', 0 ),
				'invalid'      => self::state_label( 'invalid', 0 ),
				'error'        => self::state_label( 'error', 0 ),
				'working_copy' => self::state_label( 'working_copy', 0 ),
			),
			'siteHealth' => admin_url( 'site-health.php' ),
			'updateCore' => network_admin_url( 'update-core.php' ),
			'pluginsUrl' => network_admin_url( 'plugins.php' ),
		);
	}

	/**
	 * Text for a Windrose plugin's update state.
	 *
	 * @param string $state current, update, none, invalid, error or working_copy.
	 * @param int    $code  HTTP status of the last GitHub answer.
	 * @return string
	 */
	public static function state_label( string $state, int $code ): string {
		return match ( $state ) {
			'current'      => __( 'Up to date', 'windrose-helm' ),
			'update'       => __( 'Update available', 'windrose-helm' ),
			'none'         => __( 'No release found on GitHub.', 'windrose-helm' ),
			'invalid'      => __( 'The latest release has no valid package (a zip named after the plugin, with a checksum).', 'windrose-helm' ),
			'working_copy' => __( 'Not checked: this plugin folder is a git working copy.', 'windrose-helm' ),
			/* translators: %d: HTTP status code, 0 when GitHub could not be reached. */
			default        => $code > 0 ? sprintf( __( 'GitHub answered with an error (%d).', 'windrose-helm' ), $code ) : __( 'GitHub could not be reached.', 'windrose-helm' ),
		};
	}
}
