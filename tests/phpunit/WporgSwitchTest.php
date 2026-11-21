<?php
/**
 * Module Updates: with wordpress.org off, update checks get empty answers and everything else is refused.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Modules\Updates;
use Windrose\Helm\Request_Log;

/**
 * wordpress.org switch tests.
 */
class WporgSwitchTest extends WP_UnitTestCase {

	/** @var list<string> */
	private array $escaped = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', array( Updates::class, 'intercept' ), 30, 3 );
		// Anything that gets past the switch is recorded and stopped here, never sent.
		add_filter( 'pre_http_request', array( $this, 'catch_escapes' ), 100, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( Updates::class, 'intercept' ), 30 );
		remove_filter( 'pre_http_request', array( $this, 'catch_escapes' ), 100 );
		Request_Log::clear();
		parent::tear_down();
	}

	public function catch_escapes( $pre, $args, $url ) {
		if ( false === $pre ) {
			$this->escaped[] = $url;
			return new WP_Error( 'test_escape', 'escaped' );
		}
		return $pre;
	}

	public function test_cached_wporg_updates_are_dropped_and_windrose_ones_kept(): void {
		$windrose = (object) array(
			'id'      => 'https://github.com/webpresencecare/windrose-sabir',
			'package' => 'https://api.github.com/repos/webpresencecare/windrose-sabir/releases/assets/1',
		);
		$cached   = (object) array(
			'response'     => array(
				'plugin-check/plugin.php'           => (object) array(
					'id'      => 'w.org/plugins/plugin-check',
					'icons'   => array( 'svg' => 'https://ps.w.org/plugin-check/assets/icon.svg' ),
					'package' => 'https://downloads.wordpress.org/plugin/plugin-check.2.2.0.zip',
				),
				'windrose-sabir/windrose-sabir.php' => $windrose,
			),
			'translations' => array( array( 'package' => 'https://downloads.wordpress.org/translation/x.zip' ) ),
		);
		$this->assertSame( array( 'windrose-sabir/windrose-sabir.php' => $windrose ), Updates::drop_wporg_updates( $cached )->response );
		$this->assertSame( array(), $cached->translations );
		$themes = (object) array(
			'response' => array(
				'twentytwentyfive' => array(
					'theme'   => 'twentytwentyfive',
					'url'     => 'https://wordpress.org/themes/twentytwentyfive/',
					'package' => 'https://downloads.wordpress.org/theme/twentytwentyfive.1.5.zip',
				),
			),
		);
		$this->assertSame( array(), Updates::drop_wporg_updates( $themes )->response );
		$this->assertFalse( Updates::drop_wporg_updates( false ) );
	}

	/**
	 * @dataProvider provide_hosts
	 */
	public function test_wporg_hosts( string $host, bool $expected ): void {
		$this->assertSame( $expected, Updates::is_wporg_host( $host ) );
	}

	public static function provide_hosts(): array {
		return array(
			array( 'wordpress.org', true ),
			array( 'api.wordpress.org', true ),
			array( 'downloads.wordpress.org', true ),
			array( 'fr.wordpress.org', true ),
			array( 's.w.org', true ),
			array( 'ps.w.org', true ),
			array( 'w.org', true ),
			array( 'API.WordPress.org.', true ),
			array( 'evilwordpress.org', false ),
			array( 'wordpress.org.example.com', false ),
			array( 'api.github.com', false ),
			array( 'fluxplanet.wpfr.net', false ),
		);
	}

	/**
	 * @dataProvider provide_update_checks
	 */
	public function test_update_checks_get_an_empty_answer( string $url, array $keys ): void {
		$response = wp_remote_post( $url );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		foreach ( $keys as $key ) {
			$this->assertSame( array(), $body[ $key ] );
		}
		$this->assertSame( array(), $this->escaped );
	}

	public static function provide_update_checks(): array {
		return array(
			array( 'https://api.wordpress.org/core/version-check/1.7/?version=7.2', array( 'offers', 'translations' ) ),
			array( 'https://api.wordpress.org/plugins/update-check/1.1/', array( 'plugins', 'translations', 'no_update' ) ),
			array( 'http://api.wordpress.org/themes/update-check/1.1/', array( 'themes', 'translations', 'no_update' ) ),
		);
	}

	/**
	 * @dataProvider provide_other_requests
	 */
	public function test_other_wporg_requests_are_refused( string $url ): void {
		$response = wp_remote_get( $url );
		$this->assertWPError( $response );
		$this->assertSame( 'windrose_helm_wporg_off', $response->get_error_code() );
		$this->assertSame( array(), $this->escaped );
	}

	public static function provide_other_requests(): array {
		return array(
			array( 'https://api.wordpress.org/plugins/info/1.2/?action=query_plugins' ),
			array( 'https://api.wordpress.org/events/1.0/' ),
			array( 'https://api.wordpress.org/patterns/1.0/' ),
			array( 'https://downloads.wordpress.org/translation/plugin/akismet/5.7.2/fr_FR.zip' ),
			array( 'https://wordpress.org/news/feed/' ),
			array( 'https://s.w.org/images/fonts/wp-7.2/collections/google-fonts-with-preview.json' ),
		);
	}

	public function test_other_hosts_are_untouched(): void {
		wp_remote_get( 'https://example.com/' );
		$this->assertSame( array( 'https://example.com/' ), $this->escaped );
	}

	/**
	 * Core's own update functions run to the end without a warning (warnings fail tests here) and store empty
	 * results, so the Windrose channel is still asked.
	 */
	public function test_core_update_checks_finish_without_warning(): void {
		require_once ABSPATH . WPINC . '/update.php';
		$asked = 0;
		$spy   = static function ( $update ) use ( &$asked ) {
			++$asked;
			return $update;
		};
		add_filter( 'update_plugins_github.com', $spy, 1 );
		$file = WP_PLUGIN_DIR . '/windrose-switch-probe/windrose-switch-probe.php';
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, "<?php\n/**\n * Plugin Name: Probe\n * Version: 0.1.0\n * Update URI: https://github.com/webpresencecare/windrose-switch-probe\n */\n" );
		wp_clean_plugins_cache();

		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		wp_version_check( array(), true );
		wp_update_plugins();
		wp_update_themes();

		$core    = get_site_transient( 'update_core' );
		$plugins = get_site_transient( 'update_plugins' );
		unlink( $file );
		rmdir( dirname( $file ) );
		wp_clean_plugins_cache();
		remove_filter( 'update_plugins_github.com', $spy, 1 );

		$this->assertSame( array(), $core->updates );
		$this->assertSame( array(), $plugins->response );
		$this->assertSame( 1, $asked, 'The GitHub hook was asked about the probe plugin.' );
		$wporg = array_filter( $this->escaped, static fn( string $url ): bool => Updates::is_wporg_host( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		$this->assertSame( array(), $wporg, 'Nothing reached wordpress.org.' );
	}

	/**
	 * Core functions that log a PHP warning when wordpress.org fails get a clean answer instead (warnings fail tests
	 * here).
	 */
	public function test_directory_apis_and_checksums_end_without_warning(): void {
		foreach ( array_keys( Updates::REFUSED_APIS ) as $api ) {
			add_filter( $api, array( Updates::class, 'refuse_api' ), 100, 3 );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$plugins      = plugins_api( 'query_plugins', array( 'search' => 'forms' ) );
		$themes       = themes_api( 'query_themes', array( 'browse' => 'popular' ) );
		$translations = translations_api( 'core', array( 'version' => '7.2' ) );
		$checksums    = get_core_checksums( '7.2', 'en_US' );

		foreach ( array_keys( Updates::REFUSED_APIS ) as $api ) {
			remove_filter( $api, array( Updates::class, 'refuse_api' ), 100 );
		}
		$this->assertSame( 'windrose_helm_wporg_off', $plugins->get_error_code() );
		$this->assertSame( 'windrose_helm_wporg_off', $themes->get_error_code() );
		$this->assertSame( 'windrose_helm_wporg_off', $translations->get_error_code() );
		$this->assertSame( array(), $checksums );
		$this->assertSame( array(), $this->escaped );
		Request_Log::flush();
		$this->assertSame( array( 'answered', 'blocked', 'blocked', 'blocked' ), array_column( Request_Log::entries(), 'outcome' ) );
	}

	public function test_windrose_plugin_details_still_answered(): void {
		add_filter( 'plugins_api', array( Updates::class, 'refuse_api' ), 100, 3 );
		$file = WP_PLUGIN_DIR . '/windrose-details-probe/windrose-details-probe.php';
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, "<?php\n/**\n * Plugin Name: Details Probe\n * Version: 0.1.0\n * Update URI: https://github.com/webpresencecare/windrose-details-probe\n */\n" );
		wp_clean_plugins_cache();
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$info = plugins_api( 'plugin_information', array( 'slug' => 'windrose-details-probe' ) );
		unlink( $file );
		rmdir( dirname( $file ) );
		wp_clean_plugins_cache();
		remove_filter( 'plugins_api', array( Updates::class, 'refuse_api' ), 100 );
		$this->assertSame( 'Details Probe', $info->name );
		$this->assertSame( 'https://github.com/webpresencecare/windrose-details-probe', $info->homepage );
	}

	public function test_blocked_and_answered_requests_are_logged(): void {
		Request_Log::clear();
		wp_remote_post( 'https://api.wordpress.org/plugins/update-check/1.1/' );
		wp_remote_get( 'https://api.wordpress.org/events/1.0/?ip=1.2.3.4&locale=fr_FR' );
		Request_Log::flush();
		$entries = Request_Log::entries();
		$this->assertSame( array( 'blocked', 'answered' ), array_column( $entries, 'outcome' ) );
		$this->assertSame( '/events/1.0/', $entries[0]['path'], 'Query strings are never logged.' );
	}
}
