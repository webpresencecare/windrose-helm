<?php
/**
 * Windrose\Common\Updater: GitHub releases, checksum and layout checks, no credentials sent, a full update.
 *
 * @package Windrose\Helm
 */

use Windrose\Common\Updater;
use Windrose\Helm\Modules\Updates;

require_once __DIR__ . '/includes/github-stand-in.php';

/**
 * Updater tests.
 */
class UpdaterTest extends WP_UnitTestCase {

	private const SLUG = 'windrose-test-dummy';

	private Windrose_Test_GitHub $github;

	/** @var list<string> */
	private array $temp_files = array();

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->github = new Windrose_Test_GitHub();
		$this->github->hook();
		delete_site_transient( Updater::CACHE_PREFIX . self::SLUG );
	}

	public function tear_down(): void {
		$this->github->unhook();
		remove_filter( 'pre_http_request', array( Updates::class, 'intercept' ), 30 );
		$this->remove_dummy();
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	private function zip( string $version, ?string $root = null, array $extra = array() ): string {
		$file               = Windrose_Test_GitHub::plugin_zip( self::SLUG, $version, $root, $extra );
		$this->temp_files[] = $file;
		return $file;
	}

	private function install_dummy( string $version ): void {
		$dir = WP_PLUGIN_DIR . '/' . self::SLUG;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir );
		}
		file_put_contents( $dir . '/' . self::SLUG . '.php', Windrose_Test_GitHub::plugin_header( self::SLUG, $version ) );
		wp_clean_plugins_cache();
	}

	private function remove_dummy(): void {
		$dir = WP_PLUGIN_DIR . '/' . self::SLUG;
		if ( is_dir( $dir ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
			}
			rmdir( $dir );
		}
		wp_clean_plugins_cache();
		delete_site_transient( 'update_plugins' );
	}

	private function file(): string {
		return self::SLUG . '/' . self::SLUG . '.php';
	}

	public function test_parse_release_accepts_a_complete_release(): void {
		$release = $this->github->add_release( self::SLUG, '0.2.0', $this->zip( '0.2.0' ), 11 );
		$parsed  = Updater::parse_release( self::SLUG, $release );
		$this->assertSame( 'ok', $parsed['status'] );
		$this->assertSame( '0.2.0', $parsed['version'] );
		$this->assertSame( 'https://api.github.com/repos/webpresencecare/' . self::SLUG . '/releases/assets/11', $parsed['package'] );
		$this->assertSame( 64, strlen( $parsed['digest'] ) );
	}

	/**
	 * @dataProvider provide_unusable_releases
	 */
	public function test_parse_release_refuses_unusable_releases( callable $spoil ): void {
		$release = $this->github->add_release( self::SLUG, '0.2.0', $this->zip( '0.2.0' ), 12 );
		$this->assertSame( 'invalid', Updater::parse_release( self::SLUG, $spoil( $release ) )['status'] );
	}

	public static function provide_unusable_releases(): array {
		return array(
			'draft'                          => array( static fn( $r ) => array_merge( $r, array( 'draft' => true ) ) ),
			'prerelease'                     => array( static fn( $r ) => array_merge( $r, array( 'prerelease' => true ) ) ),
			'tag not vX.Y.Z'                 => array( static fn( $r ) => array_merge( $r, array( 'tag_name' => '0.2.0' ) ) ),
			'asset wrong name'               => array(
				static function ( $r ) {
					$r['assets'][0]['name'] = 'windrose-test-dummy-main.zip';
					return $r;
				},
			),
			'asset not uploaded'             => array(
				static function ( $r ) {
					$r['assets'][0]['state'] = 'starter';
					return $r;
				},
			),
			'no digest'                      => array(
				static function ( $r ) {
					unset( $r['assets'][0]['digest'] );
					return $r;
				},
			),
			'sha1 digest'                    => array(
				static function ( $r ) {
					$r['assets'][0]['digest'] = 'sha1:' . str_repeat( 'a', 40 );
					return $r;
				},
			),
			'asset in another repository'    => array(
				static function ( $r ) {
					$r['assets'][0]['url'] = 'https://api.github.com/repos/someone/windrose-test-dummy/releases/assets/12';
					return $r;
				},
			),
			'download in another repository' => array(
				static function ( $r ) {
					$r['assets'][0]['browser_download_url'] = 'https://github.com/someone/windrose-test-dummy/releases/download/v0.2.0/windrose-test-dummy.zip';
					return $r;
				},
			),
		);
	}

	public function test_only_windrose_update_uris_are_answered(): void {
		$this->github->add_release( self::SLUG, '0.2.0', $this->zip( '0.2.0' ), 13 );
		$headers = array( 'UpdateURI' => 'https://github.com/webpresencecare/' . self::SLUG );
		$this->assertIsArray( Updater::filter_update( false, $headers, $this->file() ) );
		$this->assertFalse( Updater::filter_update( false, array( 'UpdateURI' => 'https://github.com/someone/' . self::SLUG ), $this->file() ) );
		$this->assertFalse( Updater::filter_update( false, $headers, 'other-folder/' . self::SLUG . '.php' ) );
		$this->assertFalse( Updater::filter_update( false, array( 'UpdateURI' => 'https://github.com/webpresencecare/akismet' ), 'akismet/akismet.php' ) );
	}

	public function test_release_check_sends_no_site_data_and_is_cached(): void {
		$this->github->add_release( self::SLUG, '0.2.0', $this->zip( '0.2.0' ), 14 );
		Updater::release( self::SLUG );
		Updater::release( self::SLUG );
		$calls = $this->github->requests_to( 'https://api.github.com/repos/webpresencecare/' . self::SLUG . '/releases/latest' );
		$this->assertCount( 1, $calls, 'The second check comes from the cache.' );
		$this->assertSame( 'Windrose', $calls[0]['args']['user-agent'] );
		$this->assertArrayNotHasKey( 'Authorization', $calls[0]['args']['headers'] );
		$this->assertStringNotContainsString( home_url(), (string) wp_json_encode( $calls[0]['args'] ) );
	}

	public function test_missing_release_is_cached_as_none(): void {
		$this->assertSame( 'none', Updater::release( self::SLUG )['status'] );
		$this->assertSame( 404, Updater::release( self::SLUG )['code'] );
	}

	public function test_no_credentials_are_sent(): void {

		$zip = $this->zip( '0.2.0' );
		$this->github->add_release( self::SLUG, '0.2.0', $zip, 15 );
		$release = Updater::release( self::SLUG, true );
		$file    = Updater::pre_download( false, $release['package'], null, array( 'plugin' => $this->file() ) );
		$this->assertIsString( $file );
		$this->temp_files[] = $file;

		foreach ( $this->github->requests as $request ) {
			$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'] ?? array(), $request['url'] );
		}
	}

	public function test_download_is_verified(): void {
		$zip     = $this->zip( '0.2.0' );
		$release = $this->github->add_release( self::SLUG, '0.2.0', $zip, 16 );
		$parsed  = Updater::release( self::SLUG, true );

		$file = Updater::pre_download( false, $parsed['package'], null, array( 'plugin' => $this->file() ) );
		$this->assertIsString( $file );
		$this->assertSame( hash_file( 'sha256', $zip ), hash_file( 'sha256', $file ) );
		$this->temp_files[] = $file;

		// Not ours: untouched.
		$this->assertFalse( Updater::pre_download( false, 'https://downloads.wordpress.org/plugin/akismet.zip' ) );
		// Another plugin's update cannot use this package.
		$this->assertWPError( Updater::pre_download( false, $parsed['package'], null, array( 'plugin' => 'akismet/akismet.php' ) ) );
	}

	public function test_tampered_package_is_refused(): void {
		$genuine = $this->zip( '0.2.0' );
		$this->github->add_release( self::SLUG, '0.2.0', $genuine, 17 );
		$parsed = Updater::release( self::SLUG, true );
		// Same release data, other bytes served.
		$this->github->assets[17] = $this->zip( '0.2.0', null, array( self::SLUG . '/evil.php' => '<?php // injected' ) );
		$result                   = Updater::pre_download( false, $parsed['package'], null, array( 'plugin' => $this->file() ) );
		$this->assertWPError( $result );
		$this->assertSame( 'windrose_common_checksum', $result->get_error_code() );
	}

	public function test_package_without_slug_folder_is_refused(): void {
		$this->github->add_release( self::SLUG, '0.2.0', $this->zip( '0.2.0', 'webpresencecare-windrose-test-dummy-abc123' ), 18 );
		$parsed = Updater::release( self::SLUG, true );
		$result = Updater::pre_download( false, $parsed['package'], null, array( 'plugin' => $this->file() ) );
		$this->assertWPError( $result );
		$this->assertSame( 'windrose_common_layout', $result->get_error_code() );
	}

	public function test_redirect_to_another_host_is_refused(): void {
		$this->github->add_release( self::SLUG, '0.2.0', $this->zip( '0.2.0' ), 19 );
		$this->github->redirect_host = 'example.com';
		$parsed                      = Updater::release( self::SLUG, true );
		$this->assertWPError( Updater::pre_download( false, $parsed['package'], null, array( 'plugin' => $this->file() ) ) );
		$this->assertCount( 0, $this->github->requests_to( 'https://example.com/' ) );
	}

	public function test_release_asset_is_downloaded_and_verified(): void {
		$this->github->add_release( self::SLUG, '0.3.0', $this->zip( '0.3.0' ), 30 );
		$pack = $this->zip( '0.3.0', 'pack', array( 'pack/fonts.txt' => str_repeat( 'font ', 100 ) ) );
		$this->github->add_asset( self::SLUG, 'windrose-test-dummy-fonts-arabic.zip', $pack, 31 );

		$file = Updater::release_asset( self::SLUG, 'v0.3.0', 'windrose-test-dummy-fonts-arabic.zip', 1048576 );
		$this->assertIsString( $file );
		$this->temp_files[] = $file;
		$this->assertSame( hash_file( 'sha256', $pack ), hash_file( 'sha256', $file ) );
		$this->assertCount( 1, $this->github->requests_to( 'https://api.github.com/repos/webpresencecare/' . self::SLUG . '/releases/tags/v0.3.0' ) );

		// The plugin zip is not offered as another file by mistake, and other tags or names are not found.
		$this->assertSame( 'windrose_common_asset_missing', Updater::release_asset( self::SLUG, 'v0.2.0', 'windrose-test-dummy-fonts-arabic.zip', 1048576 )->get_error_code() );
		$this->assertSame( 'windrose_common_asset_missing', Updater::release_asset( self::SLUG, 'v0.3.0', 'windrose-test-dummy-fonts-thai.zip', 1048576 )->get_error_code() );
		// Too large for the caller.
		$this->assertSame( 'windrose_common_asset_size', Updater::release_asset( self::SLUG, 'v0.3.0', 'windrose-test-dummy-fonts-arabic.zip', 10 )->get_error_code() );
		// Names, tags and repositories outside the rules are refused before any request.
		$before = count( $this->github->requests );
		foreach ( array( array( 'akismet', 'v0.3.0', 'a.zip' ), array( self::SLUG, 'main', 'a.zip' ), array( self::SLUG, 'v0.3.0', '../a.zip' ) ) as $bad ) {
			$this->assertSame( 'windrose_common_asset_name', Updater::release_asset( $bad[0], $bad[1], $bad[2], 1048576 )->get_error_code() );
		}
		$this->assertCount( $before, $this->github->requests );
		// A GitHub error (here the rate limit) is a failed download, not a missing file.
		$this->github->releases[ self::SLUG ] = 403;
		$this->assertSame( 'windrose_common_asset_failed', Updater::release_asset( self::SLUG, 'v0.3.0', 'windrose-test-dummy-fonts-arabic.zip', 1048576 )->get_error_code() );
	}

	public function test_tampered_release_asset_is_refused(): void {
		$this->github->add_release( self::SLUG, '0.3.0', $this->zip( '0.3.0' ), 32 );
		$this->github->add_asset( self::SLUG, 'pack.zip', $this->zip( '0.3.0', 'pack' ), 33 );
		$this->github->assets[33] = $this->zip( '0.3.0', 'pack', array( 'pack/evil.txt' => 'other bytes' ) );
		$this->assertSame( 'windrose_common_checksum', Updater::release_asset( self::SLUG, 'v0.3.0', 'pack.zip', 1048576 )->get_error_code() );

		$this->github->assets[33]    = $this->zip( '0.3.0', 'pack' );
		$this->github->redirect_host = 'example.com';
		$this->assertSame( 'windrose_common_asset_failed', Updater::release_asset( self::SLUG, 'v0.3.0', 'pack.zip', 1048576 )->get_error_code() );
		$this->assertCount( 0, $this->github->requests_to( 'https://example.com/' ) );
	}

	public function test_parse_asset_refuses_addresses_outside_the_repository(): void {
		$this->github->add_release( self::SLUG, '0.3.0', $this->zip( '0.3.0' ), 34 );
		$asset   = $this->github->add_asset( self::SLUG, 'pack.zip', $this->zip( '0.3.0', 'pack' ), 35 );
		$release = $this->github->releases[ self::SLUG ];
		$this->assertNotNull( Updater::parse_asset( self::SLUG, 'v0.3.0', 'pack.zip', $release ) );
		$spoilt = array(
			'url'                  => 'https://api.github.com/repos/someone/else/releases/assets/35',
			'browser_download_url' => 'https://github.com/someone/else/releases/download/v0.3.0/pack.zip',
			'digest'               => 'md5:abc',
			'state'                => 'starter',
			'size'                 => 0,
		);
		foreach ( $spoilt as $key => $value ) {
			$copy              = $release;
			$copy['assets'][1] = array_merge( $asset, array( $key => $value ) );
			$this->assertNull( Updater::parse_asset( self::SLUG, 'v0.3.0', 'pack.zip', $copy ), $key );
		}
		$this->assertNull( Updater::parse_asset( self::SLUG, 'v0.3.0', 'pack.zip', array_merge( $release, array( 'draft' => true ) ) ) );
	}

	public function test_working_copy_is_never_updated(): void {
		$this->install_dummy( '0.1.0' );
		mkdir( WP_PLUGIN_DIR . '/' . self::SLUG . '/.git' );
		$this->github->add_release( self::SLUG, '0.2.0', $this->zip( '0.2.0' ), 20 );
		$headers = array( 'UpdateURI' => 'https://github.com/webpresencecare/' . self::SLUG );
		$this->assertFalse( Updater::checks_enabled( self::SLUG ) );
		$this->assertFalse( Updater::filter_update( false, $headers, $this->file() ) );
		$this->assertCount( 0, $this->github->requests );
	}

	/**
	 * The end-to-end update test, against the GitHub stand-in: a Windrose plugin updates from 0.1.0 to 0.2.0 through
	 * WordPress's own update check and Plugin_Upgrader, with wordpress.org switched off.
	 */
	public function test_dummy_plugin_updates_from_a_github_release_with_wporg_off(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$this->install_dummy( '0.1.0' );
		$this->github->add_release( self::SLUG, '0.2.0', $this->zip( '0.2.0' ), 21 );
		add_filter( 'pre_http_request', array( Updates::class, 'intercept' ), 30, 3 );

		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$this->assertSame( '0.2.0', $updates->response[ $this->file() ]->new_version ?? null );

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$this->assertTrue( $upgrader->upgrade( $this->file() ) );
		wp_clean_plugins_cache();
		$this->assertSame( '0.2.0', get_plugin_data( WP_PLUGIN_DIR . '/' . $this->file(), false, false )['Version'] );
	}

	public function test_tampered_update_leaves_the_plugin_untouched(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$this->install_dummy( '0.1.0' );
		$this->github->add_release( self::SLUG, '0.2.0', $this->zip( '0.2.0' ), 22 );
		add_filter( 'pre_http_request', array( Updates::class, 'intercept' ), 30, 3 );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$this->github->assets[22] = $this->zip( '0.2.0', null, array( self::SLUG . '/evil.php' => '<?php // injected' ) );

		$level    = ob_get_level();
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $this->file() );
		// Core's skin leaves its output buffer open when an update fails.
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$this->assertNotTrue( $result );
		wp_clean_plugins_cache();
		$this->assertSame( '0.1.0', get_plugin_data( WP_PLUGIN_DIR . '/' . $this->file(), false, false )['Version'] );
		$this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/' . self::SLUG . '/evil.php' );
	}
}
