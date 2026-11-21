<?php
/**
 * Site Health tests (Tools, Site Health on each site).
 *
 * | Test                          | Module   | Reports                                                        |
 * |-------------------------------|----------|----------------------------------------------------------------|
 * | windrose_helm_block_theme     | Defaults | The site uses a classic theme                                   |
 * | windrose_helm_classic_editor  | Defaults | Classic Editor, Classic Widgets or Disable Gutenberg is active  |
 * | windrose_helm_page_builder    | Defaults | A known page builder is active                                  |
 * | windrose_helm_wporg           | Updates  | wordpress.org is off: updates are manual (replaces core's "Communication with WordPress.org") |
 * | windrose_helm_php_version     | Privacy  | Replaces core's PHP test when Serve Happy is off                |
 * | windrose_helm_windrose_updates | (always) | Windrose plugins that cannot be checked on GitHub, or have an update |
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

use Windrose\Common\Updater;
use Windrose\Helm\Modules\Defaults;
use Windrose\Helm\Modules\Updates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Health tests.
 *
 * @phpstan-type Result array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string}
 */
final class Health {

	/**
	 * Hooks the tests.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'register' ) );
	}

	/**
	 * Adds the tests of the modules that are on (`site_status_tests`).
	 *
	 * @param mixed $tests Tests by type (direct, async).
	 * @return mixed
	 */
	public static function register( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$direct = array();
		if ( Settings::module_on( 'defaults' ) ) {
			$direct['windrose_helm_block_theme']    = array(
				'label' => __( 'Block theme', 'windrose-helm' ),
				'test'  => array( __CLASS__, 'test_block_theme' ),
			);
			$direct['windrose_helm_classic_editor'] = array(
				'label' => __( 'Classic editing plugins', 'windrose-helm' ),
				'test'  => array( __CLASS__, 'test_classic_editor' ),
			);
			$direct['windrose_helm_page_builder']   = array(
				'label' => __( 'Page builders', 'windrose-helm' ),
				'test'  => array( __CLASS__, 'test_page_builder' ),
			);
		}
		if ( ! Settings::wporg_on() ) {
			unset( $tests['async']['dotorg_communication'] );
			$direct['windrose_helm_wporg'] = array(
				'label' => __( 'wordpress.org switch', 'windrose-helm' ),
				'test'  => array( __CLASS__, 'test_wporg' ),
			);
		}
		if ( Settings::privacy_on( 'happy_checks' ) && isset( $tests['direct']['php_version'] ) ) {
			$tests['direct']['php_version'] = array(
				'label' => __( 'PHP Version', 'windrose-helm' ),
				'test'  => array( __CLASS__, 'test_php_version' ),
			);
		}
		if ( class_exists( Updater::class ) ) {
			$direct['windrose_helm_windrose_updates'] = array(
				'label' => __( 'Windrose updates', 'windrose-helm' ),
				'test'  => array( __CLASS__, 'test_windrose_updates' ),
			);
		}
		$tests['direct'] = array_merge( is_array( $tests['direct'] ?? null ) ? $tests['direct'] : array(), $direct );
		return $tests;
	}

	/**
	 * Is the theme a block theme?
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	public static function test_block_theme(): array {
		$theme = wp_get_theme();
		$name  = $theme->get( 'Name' );
		$name  = is_string( $name ) ? $name : $theme->get_stylesheet();
		if ( wp_is_block_theme() ) {
			return self::result(
				'windrose_helm_block_theme',
				'good',
				/* translators: %s: theme name. */
				sprintf( __( 'This site uses a block theme (%s)', 'windrose-helm' ), $name ),
				__( 'Block themes are edited in the Site Editor, as the Windrose Suite expects.', 'windrose-helm' )
			);
		}
		return self::result(
			'windrose_helm_block_theme',
			'recommended',
			/* translators: %s: theme name. */
			sprintf( __( 'This site uses a classic theme (%s)', 'windrose-helm' ), $name ),
			__( 'The Windrose Suite is built for block themes and the Site Editor. Consider moving this site to a block theme.', 'windrose-helm' )
		);
	}

	/**
	 * Is a classic-editing plugin active?
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	public static function test_classic_editor(): array {
		$active = Defaults::active_plugins_among( Defaults::CLASSIC_PLUGINS );
		if ( array() === $active ) {
			return self::result( 'windrose_helm_classic_editor', 'good', __( 'No classic editing plugin is active', 'windrose-helm' ), __( 'The block editor is used for content and widgets.', 'windrose-helm' ) );
		}
		return self::result(
			'windrose_helm_classic_editor',
			'recommended',
			__( 'A classic editing plugin is active', 'windrose-helm' ),
			/* translators: %s: comma-separated plugin folder names. */
			sprintf( __( 'Active: %s. The Windrose Suite is built for the block editor; these plugins bring back the classic editor or widgets.', 'windrose-helm' ), implode( ', ', $active ) )
		);
	}

	/**
	 * Is a page builder active?
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	public static function test_page_builder(): array {
		$active = Defaults::active_plugins_among( Defaults::PAGE_BUILDERS );
		if ( array() === $active ) {
			return self::result( 'windrose_helm_page_builder', 'good', __( 'No page builder is active', 'windrose-helm' ), __( 'Pages are built with blocks and patterns.', 'windrose-helm' ) );
		}
		$names = array_map( static fn( string $folder ): string => Defaults::PAGE_BUILDERS[ $folder ] ?? $folder, $active );
		return self::result(
			'windrose_helm_page_builder',
			'recommended',
			__( 'A page builder is active', 'windrose-helm' ),
			/* translators: %s: comma-separated page builder names. */
			sprintf( __( 'Active: %s. The Windrose Suite is built for blocks and the Site Editor; content made with a page builder may not move to other sites or languages cleanly.', 'windrose-helm' ), implode( ', ', array_unique( $names ) ) )
		);
	}

	/**
	 * Says that updates are manual (wordpress.org off).
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	public static function test_wporg(): array {
		return self::result(
			'windrose_helm_wporg',
			'recommended',
			__( 'Updates are manual: wordpress.org is switched off', 'windrose-helm' ),
			Updates::manual_updates_message(),
			sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( network_admin_url( 'admin.php?page=windrose-helm&tab=updates' ) ),
				esc_html__( 'Windrose Helm, Updates', 'windrose-helm' )
			),
			__( 'Security', 'windrose-helm' )
		);
	}

	/**
	 * PHP version, without asking wordpress.org (Serve Happy is switched off).
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	public static function test_php_version(): array {
		$minimum = '8.4';
		$status  = version_compare( PHP_VERSION, $minimum, '>=' ) ? 'good' : 'critical';
		return self::result(
			'php_version',
			$status,
			/* translators: %s: PHP version. */
			sprintf( __( 'Your site is running PHP %s', 'windrose-helm' ), PHP_VERSION ),
			sprintf(
				/* translators: 1: minimum PHP version, 2: address of PHP's supported versions page. */
				__( 'The online PHP check (Serve Happy) is switched off in Windrose Helm, Privacy, so this test only compares with the minimum of the Windrose Suite (PHP %1$s). Check that your PHP version still receives security fixes: %2$s', 'windrose-helm' ),
				$minimum,
				'https://www.php.net/supported-versions.php'
			),
			'',
			__( 'Performance', 'windrose-helm' )
		);
	}

	/**
	 * Windrose plugins and their GitHub releases (from the cache).
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	public static function test_windrose_updates(): array {
		$problems = array();
		$updates  = array();
		foreach ( Updater::status() as $row ) {
			if ( 'update' === $row['state'] ) {
				/* translators: 1: plugin name, 2: new version. */
				$updates[] = sprintf( __( '%1$s %2$s is available.', 'windrose-helm' ), $row['name'], $row['latest'] );
			} elseif ( in_array( $row['state'], array( 'none', 'error', 'invalid' ), true ) ) {
				$problems[] = sprintf(
					/* translators: 1: plugin name, 2: explanation. */
					__( '%1$s: %2$s', 'windrose-helm' ),
					$row['name'],
					Admin::state_label( $row['state'], $row['code'] )
				);
			}
		}
		$link = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( network_admin_url( 'admin.php?page=windrose-helm&tab=updates' ) ),
			esc_html__( 'Windrose Helm, Updates', 'windrose-helm' )
		);
		if ( array() !== $problems ) {
			return self::result( 'windrose_helm_windrose_updates', 'recommended', __( 'Some Windrose plugins cannot be checked for updates', 'windrose-helm' ), implode( ' ', $problems ), $link, __( 'Security', 'windrose-helm' ) );
		}
		if ( array() !== $updates ) {
			return self::result( 'windrose_helm_windrose_updates', 'recommended', __( 'Windrose plugin updates are available', 'windrose-helm' ), implode( ' ', $updates ), $link, __( 'Security', 'windrose-helm' ) );
		}
		return self::result( 'windrose_helm_windrose_updates', 'good', __( 'Windrose plugins are checked for updates', 'windrose-helm' ), __( 'Windrose plugins update from their GitHub releases, with a checksum check.', 'windrose-helm' ), $link, __( 'Security', 'windrose-helm' ) );
	}

	/**
	 * A Site Health result.
	 *
	 * @param string $test        Test ID.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Title.
	 * @param string $description Plain-text explanation.
	 * @param string $actions     HTML actions (already escaped).
	 * @param string $badge       Badge label (default: Windrose).
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	private static function result( string $test, string $status, string $label, string $description, string $actions = '', string $badge = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => '' === $badge ? __( 'Windrose', 'windrose-helm' ) : $badge,
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => $test,
		);
	}
}
