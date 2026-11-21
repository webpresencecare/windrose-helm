<?php
/**
 * Recommended plugins: default list, editing, validation, state on the network.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Modules\Recommended;
use Windrose\Helm\Modules\Updates;

/**
 * Recommended plugins tests.
 */
class RecommendedTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Recommended::reset();
		$dir = WP_PLUGIN_DIR . '/redirection';
		if ( is_dir( $dir ) ) {
			unlink( $dir . '/redirection.php' );
			rmdir( $dir );
		}
		wp_clean_plugins_cache();
		parent::tear_down();
	}

	public function test_default_list(): void {
		$this->assertSame(
			array( 'multisite-language-switcher', 'multisyde', 'accessibility-checker', 'accessibility-new-window-warnings', 'fluent-smtp', 'redirection', 'stream' ),
			array_column( Recommended::list(), 'slug' )
		);
		$this->assertFalse( Recommended::is_customized() );
		foreach ( Recommended::list() as $entry ) {
			$this->assertArrayHasKey( $entry['category'], Recommended::categories() );
			$this->assertNotSame( '', $entry['why'] );
		}
	}

	public function test_default_texts_are_stored_empty_so_they_stay_translated(): void {
		$list            = Recommended::list();
		$list[0]['why']  = 'My own reason.';
		$list[1]['name'] = 'Renamed';
		$this->assertTrue( Recommended::save( $list ) );
		$stored = get_site_option( Recommended::OPTION );
		$this->assertSame( 'My own reason.', $stored[0]['why'] );
		$this->assertSame( '', $stored[2]['why'], 'Unchanged default text is not stored.' );
		$this->assertSame( 'Renamed', Recommended::list()[1]['name'] );
		$this->assertSame( Recommended::defaults()[2]['why'], Recommended::list()[2]['why'] );
	}

	public function test_invalid_entries_are_refused(): void {
		$this->assertWPError( Recommended::save( array( array( 'slug' => '../evil' ) ) ) );
		$this->assertWPError( Recommended::save( array( array( 'slug' => 'stream' ), array( 'slug' => 'stream' ) ) ) );
		$this->assertWPError( Recommended::save( array_fill( 0, Recommended::MAX_ENTRIES + 1, array( 'slug' => 'stream' ) ) ) );
		$this->assertFalse( Recommended::is_customized() );
	}

	public function test_texts_are_sanitized(): void {
		Recommended::save(
			array(
				array(
					'slug'     => 'stream',
					'name'     => '<script>alert(1)</script>Stream',
					'category' => 'unknown',
					'why'      => '<b>Logs</b> everything',
				),
			)
		);
		$entry = Recommended::list()[0];
		$this->assertStringNotContainsString( '<', $entry['name'] . $entry['why'] );
		$this->assertSame( 'other', $entry['category'] );
		$this->assertFalse( $entry['network'] );
	}

	public function test_empty_list_is_kept(): void {
		$this->assertTrue( Recommended::save( array() ) );
		$this->assertSame( array(), Recommended::list() );
		Recommended::reset();
		$this->assertCount( 7, Recommended::list() );
	}

	public function test_state_on_the_network(): void {
		wp_mkdir_p( WP_PLUGIN_DIR . '/redirection' );
		file_put_contents( WP_PLUGIN_DIR . '/redirection/redirection.php', "<?php\n/**\n * Plugin Name: Redirection\n * Version: 5.10.1\n */\n" );
		wp_clean_plugins_cache();
		$state = static fn(): array => array_column( Recommended::items(), null, 'slug' )['redirection'];

		$this->assertSame( 'inactive', $state()['status'] );
		$this->assertSame( 'redirection/redirection', $state()['plugin'] );
		$this->assertSame( '5.10.1', $state()['version'] );

		$site = self::factory()->blog->create();
		update_blog_option( $site, 'active_plugins', array( 'redirection/redirection.php' ) );
		$this->assertSame( 'site-active', $state()['status'] );
		$this->assertSame( 1, $state()['sites'] );

		update_site_option( 'active_sitewide_plugins', array( 'redirection/redirection.php' => time() ) );
		$this->assertSame( 'network-active', $state()['status'] );
		delete_site_option( 'active_sitewide_plugins' );

		$this->assertSame( 'not-installed', array_column( Recommended::items(), null, 'slug' )['stream']['status'] );
	}

	public function test_lookup_refuses_bad_slugs_and_respects_the_wporg_switch(): void {
		$this->assertWPError( Recommended::lookup( 'Not a slug' ) );
		add_filter( 'plugins_api', array( Updates::class, 'refuse_api' ), 100, 3 );
		$result = Recommended::lookup( 'stream' );
		remove_filter( 'plugins_api', array( Updates::class, 'refuse_api' ), 100 );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Updates are manual', $result->get_error_message() );
	}

	public function test_lookup_reads_wporg_answer(): void {
		$answer = static fn( $res, $action ) => 'plugin_information' === $action ? (object) array(
			'name'              => 'Stream &#8211; Activity Log',
			'short_description' => 'Real-time <b>activity</b> log.',
		) : $res;
		add_filter( 'plugins_api', $answer, 5, 2 );
		$found  = Recommended::lookup( 'stream' );
		remove_filter( 'plugins_api', $answer, 5 );
		$this->assertSame(
			array(
				'slug'        => 'stream',
				'name'        => 'Stream – Activity Log',
				'description' => 'Real-time activity log.',
			),
			$found
		);
	}
}
