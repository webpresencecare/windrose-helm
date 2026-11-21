<?php
/**
 * Module Defaults: a block-only network.
 *
 * | Switch          | What it does                                                                              |
 * |-----------------|-------------------------------------------------------------------------------------------|
 * | block_themes    | Classic themes hidden from the Themes screens and the theme installer, and not allowed    |
 * | classic_screens | Customizer and Widgets screens removed for block themes; direct visits go to the Site Editor |
 * | classic_editor  | Classic Editor, Classic Widgets and Disable Gutenberg hidden from the plugin installer and not activatable |
 * | classic_block   | The Classic block is not offered in the inserter; existing classic content still works   |
 *
 * On a network that already uses a classic theme or the Classic Editor, the first three start switched off (Site
 * Health reports instead); see initial_values().
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm\Modules;

use Windrose\Helm\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block-only defaults.
 */
final class Defaults {

	/** Plugins that bring back the classic editing experience (folder names). */
	public const CLASSIC_PLUGINS = array( 'classic-editor', 'classic-widgets', 'disable-gutenberg' );

	/** Known page builders (plugin folder name => name), reported by Site Health. */
	public const PAGE_BUILDERS = array(
		'elementor'                   => 'Elementor',
		'elementor-pro'               => 'Elementor Pro',
		'beaver-builder-lite-version' => 'Beaver Builder',
		'bb-plugin'                   => 'Beaver Builder',
		'js_composer'                 => 'WPBakery Page Builder',
		'visualcomposer'              => 'Visual Composer',
		'divi-builder'                => 'Divi Builder',
		'siteorigin-panels'           => 'SiteOrigin Page Builder',
		'brizy'                       => 'Brizy',
		'oxygen'                      => 'Oxygen',
		'breakdance'                  => 'Breakdance',
		'thrive-visual-editor'        => 'Thrive Architect',
		'live-composer-page-builder'  => 'Live Composer',
		'kingcomposer'                => 'KingComposer',
		'fusion-builder'              => 'Avada Builder',
		'cornerstone'                 => 'Cornerstone',
		'zionbuilder'                 => 'Zion Builder',
	);

	/**
	 * Block-theme results, per theme, for this request.
	 *
	 * @var array<string, bool>
	 */
	private static array $block_theme = array();

	/**
	 * Hooks the defaults that are switched on.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( Settings::default_on( 'block_themes' ) ) {
			add_filter( 'network_allowed_themes', array( __CLASS__, 'filter_allowed_themes' ) );
			add_filter( 'site_allowed_themes', array( __CLASS__, 'filter_allowed_themes' ) );
			add_filter( 'all_themes', array( __CLASS__, 'filter_theme_objects' ) );
			add_filter( 'wp_prepare_themes_for_js', array( __CLASS__, 'filter_prepared_themes' ) );
			add_filter( 'themes_api_args', array( __CLASS__, 'block_themes_in_installer' ), 10, 2 );
		}
		if ( Settings::default_on( 'classic_screens' ) ) {
			add_action( 'admin_menu', array( __CLASS__, 'remove_classic_screens' ), 999 );
			add_action( 'admin_bar_menu', array( __CLASS__, 'remove_classic_nodes' ), 999 );
			add_action( 'load-customize.php', array( __CLASS__, 'redirect_classic_screen' ) );
			add_action( 'load-widgets.php', array( __CLASS__, 'redirect_classic_screen' ) );
		}
		if ( Settings::default_on( 'classic_editor' ) ) {
			add_filter( 'plugins_api_result', array( __CLASS__, 'hide_classic_plugins' ), 10, 2 );
			add_filter( 'plugin_action_links', array( __CLASS__, 'remove_activate_link' ), 10, 2 );
			add_filter( 'network_admin_plugin_action_links', array( __CLASS__, 'remove_activate_link' ), 10, 2 );
			add_action( 'activate_plugin', array( __CLASS__, 'refuse_classic_activation' ) );
		}
		if ( Settings::default_on( 'classic_block' ) ) {
			add_filter( 'register_block_type_args', array( __CLASS__, 'hide_classic_block' ), 10, 2 );
		}
	}

	/**
	 * Default switches for this network: everything on for a network that is already block-only, otherwise only
	 * the Classic block switch (the others would change how existing sites work).
	 *
	 * @return array<string, bool>
	 */
	public static function initial_values(): array {
		$block_only = ! self::classic_theme_in_use() && array() === self::active_plugins_among( self::CLASSIC_PLUGINS );
		return array(
			'block_themes'    => $block_only,
			'classic_screens' => $block_only,
			'classic_editor'  => $block_only,
			'classic_block'   => true,
		);
	}

	/**
	 * Whether any site of the network uses a classic theme.
	 *
	 * @return bool
	 */
	public static function classic_theme_in_use(): bool {
		$sites = get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 1000,
				'network_id' => get_current_network_id(),
			)
		);
		foreach ( $sites as $site_id ) {
			$theme = wp_get_theme( (string) get_blog_option( (int) $site_id, 'stylesheet' ) );
			// A missing theme says nothing about the network; only an installed classic theme counts.
			if ( $theme->exists() && ! $theme->is_block_theme() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Which of the given plugins (folder names) are active on this site or network-wide.
	 *
	 * @param array<int|string, string> $folders Plugin folder names (values) or folder => label.
	 * @return list<string> Active folder names.
	 */
	public static function active_plugins_among( array $folders ): array {
		$active = array_merge(
			(array) get_option( 'active_plugins', array() ),
			array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
		);
		$found  = array();
		foreach ( $active as $file ) {
			$folder = dirname( (string) $file );
			if ( in_array( $folder, $folders, true ) || array_key_exists( $folder, $folders ) ) {
				$found[] = $folder;
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Whether a theme is a block theme (classic themes and missing themes are not).
	 *
	 * @param string $stylesheet Theme folder name.
	 * @return bool
	 */
	public static function is_block_theme( string $stylesheet ): bool {
		if ( ! isset( self::$block_theme[ $stylesheet ] ) ) {
			$theme                            = wp_get_theme( $stylesheet );
			self::$block_theme[ $stylesheet ] = $theme->exists() && $theme->is_block_theme();
		}
		return self::$block_theme[ $stylesheet ];
	}

	/**
	 * Keeps block themes only in the allowed themes (`network_allowed_themes`, `site_allowed_themes`).
	 *
	 * @param mixed $allowed Theme folder name => true.
	 * @return mixed
	 */
	public static function filter_allowed_themes( $allowed ) {
		if ( ! is_array( $allowed ) ) {
			return $allowed;
		}
		foreach ( array_keys( $allowed ) as $stylesheet ) {
			if ( ! self::is_block_theme( (string) $stylesheet ) ) {
				unset( $allowed[ $stylesheet ] );
			}
		}
		return $allowed;
	}

	/**
	 * Keeps block themes only in the network Themes list (`all_themes`).
	 *
	 * @param mixed $themes Theme folder name => WP_Theme.
	 * @return mixed
	 */
	public static function filter_theme_objects( $themes ) {
		if ( ! is_array( $themes ) ) {
			return $themes;
		}
		return array_filter( $themes, static fn( $theme ): bool => $theme instanceof \WP_Theme && self::is_block_theme( $theme->get_stylesheet() ) );
	}

	/**
	 * Keeps block themes (and the active theme) on a site's Themes screen (`wp_prepare_themes_for_js`).
	 *
	 * @param mixed $prepared Theme folder name => theme data.
	 * @return mixed
	 */
	public static function filter_prepared_themes( $prepared ) {
		if ( ! is_array( $prepared ) ) {
			return $prepared;
		}
		$active = get_stylesheet();
		foreach ( array_keys( $prepared ) as $stylesheet ) {
			if ( $active !== $stylesheet && ! self::is_block_theme( (string) $stylesheet ) ) {
				unset( $prepared[ $stylesheet ] );
			}
		}
		return $prepared;
	}

	/**
	 * Asks the theme directory for block themes only (`themes_api_args`).
	 *
	 * @param mixed $args   Request arguments.
	 * @param mixed $action The themes_api action.
	 * @return mixed
	 */
	public static function block_themes_in_installer( $args, $action ) {
		if ( 'query_themes' !== $action || ! $args instanceof \stdClass ) {
			return $args;
		}
		$tags = isset( $args->tag ) ? (array) $args->tag : array();
		if ( ! in_array( 'full-site-editing', $tags, true ) ) {
			$tags[]    = 'full-site-editing';
			$args->tag = $tags;
		}
		return $args;
	}

	/**
	 * Removes the Customizer and Widgets menu items when the theme is a block theme.
	 *
	 * @return void
	 */
	public static function remove_classic_screens(): void {
		global $submenu;
		if ( ! wp_is_block_theme() || ! isset( $submenu['themes.php'] ) || ! is_array( $submenu['themes.php'] ) ) {
			return;
		}
		foreach ( $submenu['themes.php'] as $index => $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( str_starts_with( $slug, 'customize.php' ) || 'widgets.php' === $slug ) {
				unset( $submenu['themes.php'][ $index ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Removing menu items is what this does.
			}
		}
	}

	/**
	 * Removes the Customize and Widgets toolbar items when the theme is a block theme.
	 *
	 * @param \WP_Admin_Bar $admin_bar The toolbar.
	 * @return void
	 */
	public static function remove_classic_nodes( \WP_Admin_Bar $admin_bar ): void {
		if ( wp_is_block_theme() ) {
			$admin_bar->remove_node( 'customize' );
			$admin_bar->remove_node( 'widgets' );
		}
	}

	/**
	 * Sends direct visits of the Customizer and Widgets screens to the Site Editor, for block themes. A Customizer
	 * preview of a classic theme still opens.
	 *
	 * @return void
	 */
	public static function redirect_classic_screen(): void {
		if ( ! wp_is_block_theme() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which theme the Customizer would preview.
		$preview = isset( $_GET['theme'] ) ? sanitize_text_field( wp_unslash( $_GET['theme'] ) ) : '';
		if ( '' !== $preview && 'load-customize.php' === current_action() && ! self::is_block_theme( $preview ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'site-editor.php' ) );
		exit;
	}

	/**
	 * Removes classic-editing plugins from plugin installer results (`plugins_api_result`).
	 *
	 * @param mixed $result The result.
	 * @param mixed $action The plugins_api action.
	 * @return mixed
	 */
	public static function hide_classic_plugins( $result, $action ) {
		if ( 'query_plugins' !== $action || ! is_object( $result ) || ! isset( $result->plugins ) || ! is_array( $result->plugins ) ) {
			return $result;
		}
		$result->plugins = array_values(
			array_filter(
				$result->plugins,
				static function ( $plugin ): bool {
					$slug = is_array( $plugin ) ? ( $plugin['slug'] ?? '' ) : ( is_object( $plugin ) ? ( $plugin->slug ?? '' ) : '' );
					return ! in_array( $slug, self::CLASSIC_PLUGINS, true );
				}
			)
		);
		return $result;
	}

	/**
	 * Removes the Activate links of classic-editing plugins (`plugin_action_links`, `network_admin_plugin_action_links`).
	 *
	 * @param mixed $actions     Action links.
	 * @param mixed $plugin_file Plugin file.
	 * @return mixed
	 */
	public static function remove_activate_link( $actions, $plugin_file ) {
		if ( is_array( $actions ) && is_string( $plugin_file ) && in_array( dirname( $plugin_file ), self::CLASSIC_PLUGINS, true ) ) {
			unset( $actions['activate'], $actions['network_activate'] );
		}
		return $actions;
	}

	/**
	 * Stops the activation of a classic-editing plugin (`activate_plugin`, which fires before core stores the plugin as
	 * active): bulk actions, a site's Plugins screen and core's plugins REST route have no Activate link to remove.
	 *
	 * @param mixed $plugin Plugin file.
	 * @return void
	 */
	public static function refuse_classic_activation( $plugin ): void {
		if ( is_string( $plugin ) && in_array( dirname( $plugin ), self::CLASSIC_PLUGINS, true ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: plugin folder name (classic-editor). */
						__( 'This network is block-only: %s, a classic editing plugin, cannot be activated. In a bulk action, the plugins after it were not activated either. A network administrator can change this in Windrose Helm, Defaults.', 'windrose-helm' ),
						dirname( $plugin )
					)
				),
				esc_html__( 'Plugin not activated', 'windrose-helm' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * Keeps the Classic block out of the inserter (`register_block_type_args`). It stays registered, so existing
	 * classic content still renders and can be edited or converted.
	 *
	 * @param mixed $args       Block type arguments.
	 * @param mixed $block_type Block name.
	 * @return mixed
	 */
	public static function hide_classic_block( $args, $block_type ) {
		if ( 'core/freeform' !== $block_type || ! is_array( $args ) ) {
			return $args;
		}
		$supports             = isset( $args['supports'] ) && is_array( $args['supports'] ) ? $args['supports'] : array();
		$supports['inserter'] = false;
		$args['supports']     = $supports;
		return $args;
	}
}
