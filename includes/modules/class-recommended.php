<?php
/**
 * Module Recommended plugins: a curated list shown in the network admin with Install and Activate buttons.
 *
 * The default list lives in data/recommended.php (a PHP file, so the reasons can be translated). Network
 * administrators can change it: the changed list is stored in the network option `windrose_helm_recommended`. An entry
 * with an empty reason or name uses the default text for that plugin, so default entries stay translated.
 *
 * Installing and activating go through WordPress's own REST plugins endpoint (`wp/v2/plugins`), with its own
 * permission checks; this class only says what is recommended and its current state.
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm\Modules;

use Windrose\Helm\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommended plugins.
 *
 * @phpstan-type Entry array{slug: string, name: string, category: string, why: string, network: bool}
 */
final class Recommended {

	public const OPTION = 'windrose_helm_recommended';

	/** Longest list kept. */
	public const MAX_ENTRIES = 50;

	/**
	 * Categories: key => label.
	 *
	 * @return array<string, string>
	 */
	public static function categories(): array {
		return array(
			'multilingual'  => __( 'Multilingual', 'windrose-helm' ),
			'network'       => __( 'Network', 'windrose-helm' ),
			'accessibility' => __( 'Accessibility', 'windrose-helm' ),
			'email'         => __( 'Email', 'windrose-helm' ),
			'redirects'     => __( 'Redirects', 'windrose-helm' ),
			'activity'      => __( 'Activity log', 'windrose-helm' ),
			'security'      => __( 'Security', 'windrose-helm' ),
			'performance'   => __( 'Performance', 'windrose-helm' ),
			'other'         => __( 'Other', 'windrose-helm' ),
		);
	}

	/**
	 * The default list.
	 *
	 * @return list<array<string, bool|string>>
	 * @phpstan-return list<Entry>
	 */
	public static function defaults(): array {
		$list = require WINDROSE_HELM_DIR . '/data/recommended.php';
		return self::sanitize_list( is_array( $list ) ? $list : array() );
	}

	/**
	 * The current list: the stored one, or the default list. Empty names and reasons take the default text.
	 *
	 * @return list<array<string, bool|string>>
	 * @phpstan-return list<Entry>
	 */
	public static function list(): array {
		$stored = get_site_option( self::OPTION, false );
		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}
		$defaults = array_column( self::defaults(), null, 'slug' );
		$list     = array();
		foreach ( self::sanitize_list( $stored ) as $entry ) {
			$default = $defaults[ $entry['slug'] ] ?? null;
			if ( null !== $default ) {
				$entry['name'] = '' === $entry['name'] ? $default['name'] : $entry['name'];
				$entry['why']  = '' === $entry['why'] ? $default['why'] : $entry['why'];
			}
			$list[] = $entry;
		}
		return $list;
	}

	/**
	 * Whether the list was changed from the default.
	 *
	 * @return bool
	 */
	public static function is_customized(): bool {
		return is_array( get_site_option( self::OPTION, false ) );
	}

	/**
	 * Stores a new list. Texts equal to the default text are stored empty, so they stay translated.
	 *
	 * @param array<mixed> $entries Entries.
	 * @return true|WP_Error
	 */
	public static function save( array $entries ) {
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			/* translators: %d: maximum number of plugins. */
			return new WP_Error( 'windrose_helm_recommended_too_long', sprintf( __( 'The list can hold %d plugins at most.', 'windrose-helm' ), self::MAX_ENTRIES ), array( 'status' => 400 ) );
		}
		$clean = self::sanitize_list( $entries );
		if ( count( $clean ) !== count( $entries ) ) {
			return new WP_Error( 'windrose_helm_recommended_invalid', __( 'An entry is not valid: each plugin needs a wordpress.org slug (lowercase letters, digits and hyphens), and a slug can only appear once.', 'windrose-helm' ), array( 'status' => 400 ) );
		}
		$defaults = array_column( self::defaults(), null, 'slug' );
		foreach ( $clean as $i => $entry ) {
			$default = $defaults[ $entry['slug'] ] ?? null;
			if ( null !== $default ) {
				$clean[ $i ]['name'] = $entry['name'] === $default['name'] ? '' : $entry['name'];
				$clean[ $i ]['why']  = $entry['why'] === $default['why'] ? '' : $entry['why'];
			}
		}
		update_site_option( self::OPTION, $clean );
		return true;
	}

	/**
	 * Goes back to the default list.
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_site_option( self::OPTION );
	}

	/**
	 * Valid entries only, each slug once.
	 *
	 * @param array<mixed> $entries Entries.
	 * @return list<array<string, bool|string>>
	 * @phpstan-return list<Entry>
	 */
	public static function sanitize_list( array $entries ): array {
		$categories = self::categories();
		$clean      = array();
		$seen       = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$slug = is_string( $entry['slug'] ?? null ) ? $entry['slug'] : '';
			if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) || strlen( $slug ) > 100 || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$category      = is_string( $entry['category'] ?? null ) ? $entry['category'] : '';
			$clean[]       = array(
				'slug'     => $slug,
				'name'     => mb_substr( sanitize_text_field( is_string( $entry['name'] ?? null ) ? $entry['name'] : '' ), 0, 200 ),
				'category' => isset( $categories[ $category ] ) ? $category : 'other',
				'why'      => mb_substr( sanitize_textarea_field( is_string( $entry['why'] ?? null ) ? $entry['why'] : '' ), 0, 500 ),
				'network'  => ! empty( $entry['network'] ),
			);
		}
		return $clean;
	}

	/**
	 * The list with each plugin's state on this network.
	 *
	 * @return list<array<string, bool|int|string>>
	 */
	public static function items(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed  = get_plugins();
		$categories = self::categories();
		$defaults   = array_column( self::defaults(), null, 'slug' );
		$items      = array();
		foreach ( self::list() as $entry ) {
			$file   = self::plugin_file( $entry['slug'], $installed );
			$status = 'not-installed';
			$sites  = 0;
			if ( '' !== $file ) {
				$sites  = is_plugin_active_for_network( $file ) ? 0 : self::active_sites( $file );
				$status = is_plugin_active_for_network( $file ) ? 'network-active' : ( $sites > 0 ? 'site-active' : 'inactive' );
			}
			$items[] = array_merge(
				$entry,
				array(
					'name'           => '' !== $entry['name'] ? $entry['name'] : $entry['slug'],
					'category_label' => $categories[ $entry['category'] ],
					'is_default'     => isset( $defaults[ $entry['slug'] ] ),
					// The plugin ID used by the wp/v2/plugins endpoint: the main file without ".php".
					'plugin'         => '' !== $file ? substr( $file, 0, -4 ) : '',
					'status'         => $status,
					'sites'          => $sites,
					'version'        => '' !== $file ? (string) ( $installed[ $file ]['Version'] ?? '' ) : '',
					'url'            => 'https://wordpress.org/plugins/' . $entry['slug'] . '/',
				)
			);
		}//end foreach
		return $items;
	}

	/**
	 * Looks a plugin up on wordpress.org, to add it to the list.
	 *
	 * @param string $slug wordpress.org slug.
	 * @return array{slug: string, name: string, description: string}|WP_Error
	 */
	public static function lookup( string $slug ) {
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			return new WP_Error( 'windrose_helm_recommended_slug', __( 'A plugin slug has lowercase letters, digits and hyphens only, as in its wordpress.org address.', 'windrose-helm' ), array( 'status' => 400 ) );
		}
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'          => false,
					'short_description' => true,
				),
			)
		);
		if ( is_wp_error( $info ) || ! is_object( $info ) || empty( $info->name ) ) {
			$message = is_wp_error( $info ) && 'windrose_helm_wporg_off' === $info->get_error_code()
				? $info->get_error_message()
				: __( 'No plugin with this slug on wordpress.org.', 'windrose-helm' );
			return new WP_Error( 'windrose_helm_recommended_lookup', $message, array( 'status' => 404 ) );
		}
		return array(
			'slug'        => $slug,
			'name'        => html_entity_decode( wp_strip_all_tags( (string) $info->name ), ENT_QUOTES ),
			'description' => html_entity_decode( wp_strip_all_tags( (string) ( $info->short_description ?? '' ) ), ENT_QUOTES ),
		);
	}

	/**
	 * Whether plugins can be installed from wordpress.org right now.
	 *
	 * @return bool
	 */
	public static function can_install(): bool {
		return Settings::wporg_on() && current_user_can( 'install_plugins' ) && wp_is_file_mod_allowed( 'windrose_helm_recommended' );
	}

	/**
	 * The installed main file of a plugin folder ("<slug>/<slug>.php" first), or an empty string.
	 *
	 * @param string                              $slug      Plugin folder.
	 * @param array<string, array<string, mixed>> $installed get_plugins().
	 * @return string
	 */
	private static function plugin_file( string $slug, array $installed ): string {
		if ( isset( $installed[ $slug . '/' . $slug . '.php' ] ) ) {
			return $slug . '/' . $slug . '.php';
		}
		foreach ( array_keys( $installed ) as $file ) {
			if ( dirname( (string) $file ) === $slug ) {
				return (string) $file;
			}
		}
		return '';
	}

	/**
	 * On how many sites a plugin is active (first 1000 sites).
	 *
	 * @param string $file Plugin main file.
	 * @return int
	 */
	private static function active_sites( string $file ): int {
		$count = 0;
		foreach ( get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 1000,
				'network_id' => get_current_network_id(),
			)
		) as $site_id ) {
			if ( in_array( $file, (array) get_blog_option( (int) $site_id, 'active_plugins', array() ), true ) ) {
				++$count;
			}
		}
		return $count;
	}
}
