<?php
/**
 * Set up: the steps the Windrose Suite installer leaves for after installation.
 *
 * Windrose Shiplaunch's installer drop-in creates the network and stores the network option windrose_helm_setup with
 * status "pending". While it is pending, network administrators get Windrose Helm, Set up (addresses, languages,
 * plugins, privacy and wordpress.org), a notice on admin screens and, after logging in, a redirect there. Finishing
 * stores status "done": the screen, the notice and every route below are gone for good. Networks not installed from
 * the zip have no such option and never see any of it.
 *
 * | Route (windrose-helm/v1) | Does                                                        | Also needs          |
 * |--------------------------|-------------------------------------------------------------|---------------------|
 * | GET  /setup              | The state of the network for the steps                      |                     |
 * | POST /setup/addresses    | Subdomains instead of subdirectories (one site only)        |                     |
 * | POST /setup/language     | Downloads a language pack from wordpress.org                | install_languages   |
 * | POST /setup/main-language| Language (and title) of the main site; network default      |                     |
 * | POST /setup/site         | One site for one language, its default content in it       | create_sites        |
 * | POST /setup/group        | Windrose Sabir language group; default content linked      | Windrose Sabir      |
 * | POST /setup/media        | Windrose Anchors: allows the given sites                    | Windrose Anchors    |
 * | POST /setup/finish       | Privacy and wordpress.org switches, then status "done"      |                     |
 *
 * Every route needs manage_network_options and a pending set-up. Plugins are installed and activated through core's
 * own wp/v2/plugins routes, with core's checks.
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Set up screen and its routes.
 */
final class Setup {

	public const OPTION = 'windrose_helm_setup';
	public const SLUG   = 'windrose-helm-setup';

	/** Site paths (and subdomains) the screen accepts: lowercase letters, digits, single hyphens. */
	private const PATH_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/** MSLS and Windrose Sabir main files (the wp/v2/plugins IDs are these without ".php"). */
	public const MSLS  = 'multisite-language-switcher/MultisiteLanguageSwitcher.php';
	public const SABIR = 'windrose-sabir/windrose-sabir.php';

	/** Windrose Anchors main file. */
	public const ANCHORS = 'windrose-anchors/windrose-anchors.php';

	/**
	 * Hook suffix of the screen.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Hooks the routes, and while the set-up is pending the screen, the notice and the login redirect.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		if ( ! self::pending() ) {
			return;
		}
		add_action( 'network_admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	/**
	 * The stored state (empty on networks not installed from the zip).
	 *
	 * @return array<string, mixed>
	 */
	public static function stored(): array {
		$stored = get_site_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether the installer left a set-up to finish.
	 *
	 * @return bool
	 */
	public static function pending(): bool {
		return is_multisite() && 'pending' === ( self::stored()['status'] ?? '' );
	}

	/**
	 * Address of the screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return network_admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Adds Set up as the first item of the Windrose Helm menu.
	 *
	 * @return void
	 */
	public static function menu(): void {
		// With a submenu, WordPress links the top-level item to the first submenu item: Set up while it is pending.
		// The Windrose Helm screen itself stays as the second item.
		add_submenu_page( Admin::SLUG, __( 'Windrose Helm', 'windrose-helm' ), __( 'Windrose Helm', 'windrose-helm' ), 'manage_network_options', Admin::SLUG );
		$hook       = add_submenu_page(
			Admin::SLUG,
			__( 'Set up the network', 'windrose-helm' ),
			__( 'Set up', 'windrose-helm' ),
			'manage_network_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			0
		);
		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Prints the screen container; the app renders into it.
	 *
	 * @return void
	 */
	public static function render(): void {
		printf(
			'<div class="wrap windrose-helm windrose-helm-setup"><h1>%1$s</h1><div id="windrose-helm-setup-app"><p>%2$s</p></div><noscript><p>%3$s</p></noscript></div>',
			esc_html__( 'Set up the network', 'windrose-helm' ),
			esc_html__( 'Loading…', 'windrose-helm' ),
			esc_html__( 'This screen needs JavaScript.', 'windrose-helm' )
		);
	}

	/**
	 * Loads the app on the screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( '' === self::$hook || self::$hook !== $hook_suffix ) {
			return;
		}
		$asset_file = WINDROSE_HELM_DIR . '/build/setup/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_HELM_VERSION;

		wp_enqueue_script( 'windrose-helm-setup', plugins_url( 'build/setup/index.js', WINDROSE_HELM_FILE ), $deps, $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( 'windrose-helm-setup', 'windrose-helm', WINDROSE_HELM_DIR . '/languages' );
		$labels = Admin::script_data();
		$data   = array(
			'privacy'    => $labels['privacy'],
			'requestLog' => $labels['requestLog'],
			'urls'       => array(
				'done'    => network_admin_url( 'admin.php?page=' . Admin::SLUG ),
				'sites'   => network_admin_url( 'sites.php' ),
				'plugins' => network_admin_url( 'plugins.php' ),
			),
		);
		wp_add_inline_script( 'windrose-helm-setup', 'window.windroseHelmSetup = ' . wp_json_encode( $data ) . ';', 'before' );
		wp_enqueue_style( 'windrose-helm-setup', plugins_url( 'build/setup/index.css', WINDROSE_HELM_FILE ), array( 'wp-components' ), $ver );
		wp_style_add_data( 'windrose-helm-setup', 'rtl', 'replace' );
	}

	/**
	 * Reminds network administrators, on every admin screen but the Set up screen.
	 *
	 * @return void
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null !== $screen && '' !== self::$hook && self::$hook . '-network' === $screen->id ) {
			return;
		}
		wp_admin_notice(
			sprintf(
				'%1$s <a href="%2$s">%3$s</a>',
				esc_html__( 'The Windrose Suite installed this network. A few choices are left: addresses, languages, plugins, privacy.', 'windrose-helm' ),
				esc_url( self::url() ),
				esc_html__( 'Set up the network', 'windrose-helm' )
			),
			array( 'type' => 'info' )
		);
	}

	/**
	 * After logging in, network administrators land on the Set up screen (unless they asked for another page).
	 *
	 * @param string           $redirect_to Where core sends the user.
	 * @param string           $requested   The redirect the login form asked for.
	 * @param WP_User|WP_Error $user        The user, or the login error.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || ! user_can( $user, 'manage_network_options' ) ) {
			return $redirect_to;
		}
		$requested = (string) $requested;
		if ( '' !== $requested && untrailingslashit( $requested ) !== untrailingslashit( admin_url() ) ) {
			return $redirect_to;
		}
		return self::url();
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$can       = static fn() => self::can_set_up();
		$namespace = Rest::NAMESPACE;
		$ids       = array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		);
		register_rest_route(
			$namespace,
			'/setup',
			array(
				'methods'             => 'GET',
				'callback'            => static fn(): WP_REST_Response => rest_ensure_response( self::state() ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			$namespace,
			'/setup/addresses',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_addresses' ),
				'permission_callback' => $can,
				'args'                => array(
					'type' => array(
						'type'     => 'string',
						'enum'     => array( 'subdirectory', 'subdomain' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/setup/language',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_language' ),
				'permission_callback' => static fn() => self::can_set_up( 'install_languages' ),
				'args'                => array(
					'locale' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/setup/main-language',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_main_language' ),
				'permission_callback' => $can,
				'args'                => array(
					'locale' => array(
						'type'     => 'string',
						'required' => true,
					),
					'title'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/setup/site',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_site' ),
				'permission_callback' => static fn() => self::can_set_up( 'create_sites' ),
				'args'                => array(
					'locale' => array(
						'type'     => 'string',
						'required' => true,
					),
					'path'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'title'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/setup/group',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_group' ),
				'permission_callback' => $can,
				'args'                => array(
					'sites' => array_merge( $ids, array( 'required' => true ) ),
					'main'  => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/setup/media',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_media' ),
				'permission_callback' => $can,
				'args'                => array(
					'sites' => array_merge( $ids, array( 'required' => true ) ),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/setup/finish',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_finish' ),
				'permission_callback' => $can,
				'args'                => array(
					'privacy' => array(
						'type'                 => 'object',
						'properties'           => array_fill_keys( Settings::PRIVACY_IDS, array( 'type' => 'boolean' ) ),
						'additionalProperties' => false,
					),
					'wporg'   => array( 'type' => 'boolean' ),
				),
			)
		);
	}

	/**
	 * Permission: a pending set-up, network administrators only, plus one more capability when given.
	 *
	 * @param string $also Another capability the route needs, or ''.
	 * @return true|WP_Error
	 */
	public static function can_set_up( string $also = '' ) {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) || ( '' !== $also && ! current_user_can( $also ) ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to set up this network.', 'windrose-helm' ), array( 'status' => rest_authorization_required_code() ) );
		}
		if ( ! self::pending() ) {
			return new WP_Error( 'windrose_helm_setup_done', __( 'This network is set up: the Set up screen is closed.', 'windrose-helm' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Loads core's admin helpers (file system, upgrader, translations): REST requests do not load them.
	 *
	 * @return void
	 */
	private static function load_admin(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
	}

	/**
	 * Everything the steps need.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		self::load_admin();
		$stored = self::stored();
		$manual = is_array( $stored['manual'] ?? null ) ? $stored['manual'] : array();
		return array(
			'status'    => (string) ( $stored['status'] ?? '' ),
			'version'   => (string) ( $stored['version'] ?? '' ),
			'manual'    => array(
				'rules'  => is_string( $manual['rules'] ?? null ) ? $manual['rules'] : '',
				'server' => is_string( $manual['server'] ?? null ) ? $manual['server'] : '',
			),
			'addresses' => self::addresses(),
			'languages' => self::languages(),
			'plugins'   => self::plugins(),
			'privacy'   => Settings::privacy(),
			'wporg'     => Settings::wporg_on(),
			'network'   => array(
				'name'      => (string) get_network_option( null, 'site_name', '' ),
				'mainId'    => get_main_site_id(),
				'mainTitle' => (string) get_blog_option( get_main_site_id(), 'blogname' ),
			),
		);
	}

	/**
	 * The address type and whether it can still change.
	 *
	 * @return array{type: string, domain: string, path: string, canSwitch: bool, problem: string}
	 */
	public static function addresses(): array {
		$network   = get_network();
		$subdomain = is_subdomain_install();
		$problem   = $subdomain ? '' : self::subdomain_problem();
		return array(
			'type'      => $subdomain ? 'subdomain' : 'subdirectory',
			'domain'    => null !== $network ? $network->domain : '',
			'path'      => null !== $network ? $network->path : '/',
			'canSwitch' => ! $subdomain && '' === $problem,
			'problem'   => $problem,
		);
	}

	/**
	 * Why the network cannot switch to subdomains here, or ''.
	 *
	 * @return string
	 */
	public static function subdomain_problem(): string {
		$network = get_network();
		if ( null === $network ) {
			return __( 'The network could not be read.', 'windrose-helm' );
		}
		$count = get_sites(
			array(
				'network_id' => $network->id,
				'count'      => true,
			)
		);
		if ( $count > 1 ) {
			return __( 'The network already has more than one site: changing the address type now would change their addresses.', 'windrose-helm' );
		}
		if ( 'localhost' === $network->domain || false !== filter_var( $network->domain, FILTER_VALIDATE_IP ) ) {
			return __( 'Subdomains need a domain name; this network’s address is “localhost” or an IP address.', 'windrose-helm' );
		}
		$file = self::config_path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Plain check of the local configuration file, as core's Network Setup does.
		if ( ! is_file( $file ) || ! is_writable( $file ) ) {
			return __( 'WordPress cannot change wp-config.php here. To use subdomains, change SUBDOMAIN_INSTALL to true in wp-config.php yourself and replace the rewrite rules (see the documentation).', 'windrose-helm' );
		}
		$contents = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( 1 !== preg_match_all( self::subdomain_line_pattern(), $contents ) ) {
			return __( 'wp-config.php does not hold the line the installer wrote for SUBDOMAIN_INSTALL, so WordPress does not change it. Change it yourself if you want subdomains (see the documentation).', 'windrose-helm' );
		}
		return '';
	}

	/**
	 * Where wp-config.php is (next to WordPress, or one folder above, as core allows).
	 *
	 * @return string
	 */
	public static function config_path(): string {
		$path = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $path ) && file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			$path = dirname( ABSPATH ) . '/wp-config.php';
		}
		/**
		 * Filters which wp-config.php the Set up screen changes for subdomains (tests use a temporary copy).
		 *
		 * @param string $path Path of wp-config.php.
		 */
		return (string) apply_filters( 'windrose_helm_setup_config_path', $path );
	}

	/**
	 * The line the installer wrote.
	 *
	 * @return string
	 */
	private static function subdomain_line_pattern(): string {
		return "/^define\\(\\s*'SUBDOMAIN_INSTALL',\\s*false\\s*\\);\\s*$/m";
	}

	/**
	 * Apache rules for a network (core's Network Setup rules, without the old ms-files lines).
	 *
	 * @param string $base      Network path, slashes at both ends.
	 * @param bool   $subdomain Subdomain network.
	 * @return string
	 */
	public static function htaccess_rules( string $base, bool $subdomain ): string {
		$match = $subdomain ? '' : '([_0-9a-zA-Z-]+/)?';
		$first = $subdomain ? '' : '$1';
		$then  = $subdomain ? '$1' : '$2';
		return implode(
			"\n",
			array(
				'RewriteEngine On',
				'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
				'RewriteBase ' . $base,
				'RewriteRule ^index\.php$ - [L]',
				'',
				'# add a trailing slash to /wp-admin',
				'RewriteRule ^' . $match . 'wp-admin$ ' . $first . 'wp-admin/ [R=301,L]',
				'',
				'RewriteCond %{REQUEST_FILENAME} -f [OR]',
				'RewriteCond %{REQUEST_FILENAME} -d',
				'RewriteRule ^ - [L]',
				'RewriteRule ^' . $match . '(wp-(content|admin|includes).*) ' . $then . ' [L]',
				'RewriteRule ^' . $match . '(.*\.php)$ ' . $then . ' [L]',
				'RewriteRule . index.php [L]',
			)
		);
	}

	/**
	 * POST /setup/addresses: keeps subdirectories, or switches the one-site network to subdomains.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_addresses( WP_REST_Request $request ) {
		self::load_admin();
		$type = (string) $request->get_param( 'type' );
		if ( 'subdomain' !== $type || is_subdomain_install() ) {
			return rest_ensure_response(
				array(
					'addresses' => self::addresses(),
					'rules'     => '',
				)
			);
		}
		$result = self::switch_to_subdomains();
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		$addresses              = self::addresses();
		$addresses['type']      = 'subdomain';
		$addresses['canSwitch'] = false;
		return rest_ensure_response(
			array(
				'addresses' => $addresses,
				'rules'     => $result,
			)
		);
	}

	/**
	 * Switches to subdomains: wp-config.php, the network meta, the main site's permalinks (subdomain networks have
	 * no "/blog" prefix), and the rewrite rules.
	 *
	 * @return string|WP_Error The rules to add by hand ('' when .htaccess was written), or an error.
	 */
	public static function switch_to_subdomains() {
		$problem = self::subdomain_problem();
		if ( '' !== $problem ) {
			return new WP_Error( 'windrose_helm_setup_subdomains', $problem, array( 'status' => 409 ) );
		}
		$file     = self::config_path();
		$contents = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$changed  = (string) preg_replace( self::subdomain_line_pattern(), "define( 'SUBDOMAIN_INSTALL', true );", $contents, 1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same file core's Network Setup asks to edit.
		if ( false === file_put_contents( $file, $changed, LOCK_EX ) ) {
			return new WP_Error( 'windrose_helm_setup_subdomains', __( 'WordPress could not write wp-config.php.', 'windrose-helm' ), array( 'status' => 500 ) );
		}
		update_site_option( 'subdomain_install', 1 );

		$main = get_main_site_id();
		switch_to_blog( $main );
		$structure = (string) get_option( 'permalink_structure', '' );
		if ( str_starts_with( $structure, '/blog/' ) ) {
			update_option( 'permalink_structure', substr( $structure, 5 ) );
		}
		delete_option( 'rewrite_rules' );
		restore_current_blog();

		$network = get_network();
		$rules   = self::htaccess_rules( null !== $network ? $network->path : '/', true );
		global $is_apache;
		self::load_admin();
		$htaccess = get_home_path() . '.htaccess';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Same check as core's save_mod_rewrite_rules().
		if ( ! empty( $is_apache ) && is_file( $htaccess ) && is_writable( $htaccess ) && insert_with_markers( $htaccess, 'WordPress', explode( "\n", $rules ) ) ) {
			return '';
		}
		return $rules;
	}

	/**
	 * The languages: main site's, installed, available on wordpress.org, MSLS and Windrose Sabir.
	 *
	 * @return array<string, mixed>
	 */
	public static function languages(): array {
		self::load_admin();
		$installed = get_available_languages();
		$list      = array(
			'en_US' => array(
				'locale'    => 'en_US',
				'name'      => 'English (United States)',
				'native'    => 'English (United States)',
				'installed' => true,
			),
		);
		$available = Settings::wporg_on() ? wp_get_available_translations() : array();
		foreach ( $available as $locale => $translation ) {
			$list[ (string) $locale ] = array(
				'locale'    => (string) $locale,
				'name'      => (string) ( $translation['english_name'] ?? $locale ),
				'native'    => (string) ( $translation['native_name'] ?? $locale ),
				'installed' => in_array( (string) $locale, $installed, true ),
			);
		}
		foreach ( $installed as $locale ) {
			if ( ! isset( $list[ $locale ] ) ) {
				$list[ $locale ] = array(
					'locale'    => $locale,
					'name'      => $locale,
					'native'    => $locale,
					'installed' => true,
				);
			}
		}
		$sites = array();
		foreach ( get_sites( array( 'network_id' => get_current_network_id() ) ) as $site ) {
			$sites[] = array(
				'id'     => (int) $site->blog_id,
				'url'    => get_home_url( (int) $site->blog_id, '/' ),
				'path'   => $site->path,
				'locale' => self::site_locale( (int) $site->blog_id ),
			);
		}
		return array(
			'main'       => self::site_locale( get_main_site_id() ),
			'list'       => array_values( $list ),
			'canInstall' => wp_can_install_language_pack() && current_user_can( 'install_languages' ) && Settings::wporg_on(),
			'sites'      => $sites,
			'msls'       => self::plugin_state( self::MSLS ),
			'sabir'      => self::plugin_state( self::SABIR ),
		);
	}

	/**
	 * The plugins step: Windrose Anchors and the recommended plugins.
	 *
	 * @return array<string, mixed>
	 */
	public static function plugins(): array {
		return array(
			'anchors'     => self::plugin_state( self::ANCHORS ),
			'recommended' => array_values(
				array_filter(
					Modules\Recommended::items(),
					static fn( array $item ): bool => 'multisite-language-switcher' !== $item['slug']
				)
			),
			'canInstall'  => Modules\Recommended::can_install(),
		);
	}

	/**
	 * Whether a plugin is installed and network-active, with its wp/v2/plugins ID.
	 *
	 * @param string $file Main file.
	 * @return array{installed: bool, active: bool, plugin: string, version: string}
	 */
	public static function plugin_state( string $file ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		return array(
			'installed' => isset( $installed[ $file ] ),
			'active'    => is_plugin_active_for_network( $file ),
			'plugin'    => substr( $file, 0, -4 ),
			'version'   => (string) ( $installed[ $file ]['Version'] ?? '' ),
		);
	}

	/**
	 * A site's language (en_US when none is set).
	 *
	 * @param int $blog_id Site ID.
	 * @return string
	 */
	public static function site_locale( int $blog_id ): string {
		$locale = (string) get_blog_option( $blog_id, 'WPLANG', '' );
		return '' === $locale ? 'en_US' : $locale;
	}

	/**
	 * Checks a locale code against the installed and the available languages.
	 *
	 * @param string $locale    Locale code.
	 * @param bool   $installed Whether it must already be installed.
	 * @return string|WP_Error The locale.
	 */
	private static function valid_locale( string $locale, bool $installed ) {
		$locale = sanitize_locale_name( $locale );
		if ( 'en_US' === $locale ) {
			return $locale;
		}
		$known = array_column( self::languages()['list'], null, 'locale' );
		if ( '' === $locale || ! isset( $known[ $locale ] ) ) {
			return new WP_Error( 'windrose_helm_setup_locale', __( 'This language is not available.', 'windrose-helm' ), array( 'status' => 400 ) );
		}
		if ( $installed && ! $known[ $locale ]['installed'] ) {
			/* translators: %s: language name. */
			return new WP_Error( 'windrose_helm_setup_locale', sprintf( __( '%s is not installed yet.', 'windrose-helm' ), $known[ $locale ]['native'] ), array( 'status' => 409 ) );
		}
		return $locale;
	}

	/**
	 * POST /setup/language: downloads a language pack (core, from wordpress.org).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_language( WP_REST_Request $request ) {
		self::load_admin();
		$locale = self::valid_locale( (string) $request->get_param( 'locale' ), false );
		if ( $locale instanceof WP_Error ) {
			return $locale;
		}
		if ( 'en_US' !== $locale && ! in_array( $locale, get_available_languages(), true ) ) {
			if ( ! Settings::wporg_on() || ! wp_can_install_language_pack() ) {
				return new WP_Error( 'windrose_helm_setup_language', __( 'Languages cannot be downloaded here: wordpress.org is switched off, or WordPress may not write files.', 'windrose-helm' ), array( 'status' => 409 ) );
			}
			if ( false === wp_download_language_pack( $locale ) ) {
				return new WP_Error( 'windrose_helm_setup_language', __( 'The language could not be downloaded from wordpress.org.', 'windrose-helm' ), array( 'status' => 502 ) );
			}
		}
		return rest_ensure_response(
			array(
				'locale'    => $locale,
				'installed' => true,
			)
		);
	}

	/**
	 * POST /setup/main-language: the main site's language, also the network default for new sites, and its title
	 * when one is given (multilingual networks: "<name> - <LANGUAGE CODE>", proposed by the screen).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_main_language( WP_REST_Request $request ) {
		self::load_admin();
		$locale = self::valid_locale( (string) $request->get_param( 'locale' ), true );
		if ( $locale instanceof WP_Error ) {
			return $locale;
		}
		$value = 'en_US' === $locale ? '' : $locale;
		update_blog_option( get_main_site_id(), 'WPLANG', $value );
		update_site_option( 'WPLANG', $value );
		$title = trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) );
		if ( '' !== $title ) {
			update_blog_option( get_main_site_id(), 'blogname', $title );
		}
		return rest_ensure_response(
			array(
				'locale' => $locale,
				'title'  => (string) get_blog_option( get_main_site_id(), 'blogname' ),
			)
		);
	}

	/**
	 * POST /setup/site: one site for one language, with core's default content written in that language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_site( WP_REST_Request $request ) {
		self::load_admin();
		$locale = self::valid_locale( (string) $request->get_param( 'locale' ), true );
		if ( $locale instanceof WP_Error ) {
			return $locale;
		}
		$path = strtolower( trim( (string) $request->get_param( 'path' ), '/ ' ) );
		if ( ! preg_match( self::PATH_PATTERN, $path ) ) {
			return new WP_Error( 'windrose_helm_setup_path', __( 'Site addresses can only use lowercase letters (a-z), numbers and hyphens.', 'windrose-helm' ), array( 'status' => 400 ) );
		}
		$network = get_network();
		if ( null === $network ) {
			return new WP_Error( 'windrose_helm_setup_path', __( 'The network could not be read.', 'windrose-helm' ), array( 'status' => 500 ) );
		}
		if ( ! is_subdomain_install() && in_array( $path, get_subdirectory_reserved_names(), true ) ) {
			/* translators: %s: site address part. */
			return new WP_Error( 'windrose_helm_setup_path', sprintf( __( '“%s” is reserved by WordPress; choose another address.', 'windrose-helm' ), $path ), array( 'status' => 400 ) );
		}
		$domain   = is_subdomain_install() ? $path . '.' . preg_replace( '/^www\./', '', $network->domain ) : $network->domain;
		$sitepath = is_subdomain_install() ? $network->path : $network->path . $path . '/';
		if ( domain_exists( $domain, $sitepath, $network->id ) ) {
			/* translators: %s: site address. */
			return new WP_Error( 'windrose_helm_setup_path', sprintf( __( 'A site already uses the address %s.', 'windrose-helm' ), $domain . $sitepath ), array( 'status' => 409 ) );
		}
		$title = trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) );
		if ( '' === $title ) {
			$title = self::site_title( (string) get_network_option( $network->id, 'site_name', '' ), $locale );
		}

		// Core's default post, page and comment are written in the current locale: switch to the site's language.
		// Their strings are in core's admin translations, which REST requests do not load (core's Add Site is an
		// admin screen), so those are loaded too.
		$switched = switch_to_locale( $locale );
		if ( 'en_US' !== $locale ) {
			load_textdomain( 'default', WP_LANG_DIR . '/admin-' . $locale . '.mo', $locale );
		}
		// WordPress stored the first post's text once, in the installer's language, for every new site of the network
		// (network option first_post). While it is still that default, the site gets its own language's text instead
		// (in blocks); a text the owner changed in Network Settings is kept.
		$own_first_post = self::first_post_is_default();
		if ( $own_first_post ) {
			add_filter( 'pre_site_option_first_post', '__return_empty_string' );
		}
		$site_id = wpmu_create_blog(
			$domain,
			$sitepath,
			$title,
			get_current_user_id(),
			array(
				'WPLANG'      => 'en_US' === $locale ? '' : $locale,
				'blog_public' => (int) get_blog_option( get_main_site_id(), 'blog_public', 1 ),
			),
			$network->id
		);
		if ( $own_first_post ) {
			remove_filter( 'pre_site_option_first_post', '__return_empty_string' );
		}
		if ( $switched ) {
			restore_previous_locale();
		}
		if ( $site_id instanceof WP_Error ) {
			return $site_id;
		}
		return rest_ensure_response(
			array(
				'id'     => $site_id,
				'url'    => get_home_url( $site_id, '/' ),
				'locale' => $locale,
			)
		);
	}

	/**
	 * Title of a language site: the network's name and the language code, for example "Windrose - DE" for de_CH.
	 *
	 * @param string $name   Network name.
	 * @param string $locale Locale code.
	 * @return string
	 */
	public static function site_title( string $name, string $locale ): string {
		$code = strtoupper( (string) strtok( $locale, '_' ) );
		return '' === trim( $name ) ? $code : $name . ' - ' . $code;
	}

	/**
	 * Whether the network's first post text (network option first_post) is still the default WordPress stored at
	 * installation, in English or in any installed language.
	 *
	 * @return bool
	 */
	public static function first_post_is_default(): bool {
		$stored = (string) get_site_option( 'first_post', '' );
		$text   = 'Welcome to %s. This is your first post. Edit or delete it, then start writing!';
		if ( '' === $stored || $text === $stored ) {
			return true;
		}
		foreach ( get_available_languages() as $locale ) {
			$file = WP_LANG_DIR . '/admin-' . $locale . '.mo';
			$mo   = new \MO();
			if ( is_readable( $file ) && $mo->import_from_file( $file ) && $mo->translate( $text ) === $stored ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * POST /setup/group: one Windrose Sabir language group of the given sites, the first as main site, and MSLS's
	 * settings on each site that has none (MSLS lists only sites with settings): language name, no flags.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_group( WP_REST_Request $request ) {
		self::load_admin();
		if ( ! class_exists( '\Windrose\Sabir\Groups' ) ) {
			return new WP_Error( 'windrose_helm_setup_sabir', __( 'Windrose Sabir is not active.', 'windrose-helm' ), array( 'status' => 409 ) );
		}
		$sites = array_values( array_unique( array_map( 'intval', (array) $request->get_param( 'sites' ) ) ) );
		foreach ( $sites as $site_id ) {
			$site = get_site( $site_id );
			if ( null === $site || get_current_network_id() !== (int) $site->network_id ) {
				return new WP_Error( 'windrose_helm_setup_sites', __( 'A site of this language group is not in this network.', 'windrose-helm' ), array( 'status' => 400 ) );
			}
		}
		$main = (int) $request->get_param( 'main' );
		$id   = \Windrose\Sabir\Groups::save( 0, __( 'Languages', 'windrose-helm' ), $sites, $main );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$names = Settings::wporg_on() ? wp_get_available_translations() : array();
		foreach ( $sites as $site_id ) {
			$locale = self::site_locale( $site_id );
			if ( is_array( get_blog_option( $site_id, 'msls' ) ) ) {
				continue;
			}
			$name = 'en_US' === $locale ? 'English' : (string) ( $names[ $locale ]['native_name'] ?? $locale );
			update_blog_option(
				$site_id,
				'msls',
				array(
					'description'         => $name,
					'output_current_blog' => '1',
					// No flags: "Description only" in the switcher, "Label" in the admin screens.
					'display'             => 1,
					'admin_display'       => 'label',
				)
			);
		}
		return rest_ensure_response(
			array(
				'group'  => $id,
				'sites'  => $sites,
				'linked' => self::link_default_content( $sites ),
			)
		);
	}

	/**
	 * Links WordPress's default content of the group's sites as translations of each other, through Windrose Sabir
	 * (MSLS's links): the first post (ID 1), the sample page (ID 2) and the privacy policy page. An item is linked only
	 * where it is still untouched (never modified since it was created; local dates, as drafts such as the privacy page
	 * have no GMT date) on at least two sites of different languages.
	 *
	 * @param array<int> $sites Site IDs of the group.
	 * @return array<string, int> Item => number of sites linked (first_post, sample_page, privacy_page).
	 */
	public static function link_default_content( array $sites ): array {
		if ( ! class_exists( '\Windrose\Sabir\Links' ) ) {
			return array();
		}
		$items  = array(
			'first_post'   => static fn(): int => 1,
			'sample_page'  => static fn(): int => 2,
			'privacy_page' => static fn(): int => (int) get_option( 'wp_page_for_privacy_policy' ),
		);
		$types  = array(
			'first_post'   => 'post',
			'sample_page'  => 'page',
			'privacy_page' => 'page',
		);
		$linked = array();
		foreach ( $items as $key => $find ) {
			$members = array();
			foreach ( $sites as $site_id ) {
				switch_to_blog( $site_id );
				$post = get_post( $find() );
				if ( null !== $post && $types[ $key ] === $post->post_type && 'trash' !== $post->post_status && $post->post_modified === $post->post_date ) {
					$members[ self::site_locale( $site_id ) ] = array(
						'site' => $site_id,
						'id'   => (int) $post->ID,
					);
				}
				restore_current_blog();
			}
			if ( count( $members ) >= 2 ) {
				\Windrose\Sabir\Links::link( 'post', $members );
			}
			$linked[ $key ] = count( $members ) >= 2 ? count( $members ) : 0;
		}
		return $linked;
	}

	/**
	 * POST /setup/media: Windrose Anchors allows the given sites to use network media.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_media( WP_REST_Request $request ) {
		if ( ! class_exists( '\Windrose\Anchors\Settings' ) ) {
			return new WP_Error( 'windrose_helm_setup_anchors', __( 'Windrose Anchors is not active.', 'windrose-helm' ), array( 'status' => 409 ) );
		}
		$sites = array_values( array_unique( array_map( 'intval', (array) $request->get_param( 'sites' ) ) ) );
		return rest_ensure_response( array( 'allowed' => \Windrose\Anchors\Settings::set_allowed( $sites, true ) ) );
	}

	/**
	 * POST /setup/finish: saves the privacy and wordpress.org choices and closes the Set up screen for good.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_finish( WP_REST_Request $request ): WP_REST_Response {
		$input = array();
		if ( is_array( $request->get_param( 'privacy' ) ) ) {
			$input['privacy'] = $request->get_param( 'privacy' );
		}
		if ( is_bool( $request->get_param( 'wporg' ) ) ) {
			$input['wporg'] = $request->get_param( 'wporg' );
		}
		Settings::update( $input );
		self::finish();
		return rest_ensure_response(
			array(
				'status' => 'done',
				'url'    => network_admin_url( 'admin.php?page=' . Admin::SLUG ),
			)
		);
	}

	/**
	 * Stores status "done": the screen, the notice and the routes are gone from the next request.
	 *
	 * @return void
	 */
	public static function finish(): void {
		$stored             = self::stored();
		$stored['status']   = 'done';
		$stored['finished'] = time();
		unset( $stored['manual'] );
		update_site_option( self::OPTION, $stored );
	}
}
