<?php
/**
 * Request log.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Request_Log;

/**
 * Request log tests.
 */
class RequestLogTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Request_Log::clear();
		parent::tear_down();
	}

	public function test_entry_holds_host_and_path_only(): void {
		Request_Log::record( 'https://user:secret@api.example.com/v2/translate?auth_key=abc123&text=Hello#x', array( 'method' => 'post' ), 'sent', 200 );
		Request_Log::flush();
		$entry = Request_Log::entries()[0];
		$this->assertSame( 'api.example.com', $entry['host'] );
		$this->assertSame( '/v2/translate', $entry['path'] );
		$this->assertSame( 'POST', $entry['method'] );
		$this->assertSame( 'sent', $entry['outcome'] );
		$this->assertSame( get_current_blog_id(), $entry['site'] );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( Request_Log::entries() ) );
		$this->assertStringNotContainsString( 'abc123', (string) wp_json_encode( Request_Log::entries() ) );
	}

	/**
	 * @dataProvider provide_paths
	 */
	public function test_secret_looking_path_segments_are_masked( string $path, string $logged ): void {
		$this->assertSame( $logged, Request_Log::mask_path( $path ) );
	}

	public static function provide_paths(): array {
		return array(
			'normal'          => array( '/plugins/update-check/1.1/', '/plugins/update-check/1.1/' ),
			'translation zip' => array( '/translation/plugin/akismet/5.7.2/fr_FR.zip', '/translation/plugin/akismet/5.7.2/fr_FR.zip' ),
			'bot token'       => array( '/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage', '/***/sendMessage' ),
			'long key'        => array( '/v1/keys/sk0123456789abcdefABCDEF0123/items', '/v1/keys/***/items' ),
			'long word'       => array( '/images/core/emoji/averyveryverylongwordwithoutdigits/', '/images/core/emoji/averyveryverylongwordwithoutdigits/' ),
		);
	}

	public function test_keeps_the_last_200_newest_first(): void {
		for ( $i = 1; $i <= 205; $i++ ) {
			Request_Log::record( "https://example.com/$i", array(), 'sent', 200 );
		}
		Request_Log::flush();
		Request_Log::record( 'https://example.com/latest', array(), 'sent', 200 );
		Request_Log::flush();
		$entries = Request_Log::entries();
		$this->assertCount( 200, $entries );
		$this->assertSame( '/latest', $entries[0]['path'] );
		$this->assertSame( '/205', $entries[1]['path'] );
		$this->assertSame( '/7', $entries[199]['path'] );
	}

	public function test_sent_requests_are_recorded_with_context_and_source(): void {
		// Simulates a sent request: http_api_debug fires after a real transport.
		do_action( 'http_api_debug', array( 'response' => array( 'code' => 204 ) ), 'response', 'WpOrg\Requests\Requests', array( 'method' => 'GET' ), 'https://example.net/ping' );
		do_action( 'http_api_debug', new WP_Error( 'x', 'down' ), 'response', 'WpOrg\Requests\Requests', array( 'method' => 'GET' ), 'https://example.net/down' );
		Request_Log::flush();
		$entries = Request_Log::entries();
		$this->assertSame( array( 'failed', 'sent' ), array_column( $entries, 'outcome' ) );
		$this->assertSame( 204, $entries[1]['code'] );
		$this->assertSame( 'web', $entries[1]['context'] );
		$this->assertNotSame( '', $entries[1]['source'] );
	}

	public function test_source_names_a_symlinked_plugin(): void {
		global $wp_plugin_paths;
		$saved = $wp_plugin_paths;
		// This test file sits in the plugin's real folder, outside WP_PLUGIN_DIR, as with a symlinked plugin.
		$this->assertSame( 'unknown', Request_Log::source() );
		// As wp_register_plugin_realpath() records it: linked folder => real folder.
		$wp_plugin_paths[ WP_PLUGIN_DIR . '/windrose-helm' ] = WINDROSE_HELM_DIR;
		$source          = Request_Log::source();
		$wp_plugin_paths = $saved;
		$this->assertSame( 'plugin:windrose-helm', $source );
	}

	public function test_nothing_recorded_when_switched_off(): void {
		update_site_option( 'windrose_helm_privacy', array( 'request_log' => false ) );
		Request_Log::record( 'https://example.com/off', array(), 'sent', 200 );
		Request_Log::flush();
		delete_site_option( 'windrose_helm_privacy' );
		$this->assertSame( array(), Request_Log::entries() );
	}
}
