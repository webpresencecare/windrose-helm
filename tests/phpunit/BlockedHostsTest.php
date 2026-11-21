<?php
/**
 * Module Privacy: blocked hosts.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Modules\Blocked_Hosts;
use Windrose\Helm\Request_Log;
use Windrose\Helm\Rest;
use Windrose\Helm\Settings;

require_once __DIR__ . '/includes/redirecting-transport.php';

/**
 * Blocked hosts tests.
 */
class BlockedHostsTest extends WP_UnitTestCase {

	/** Answer of the stand-in for the real network, after the block had its chance. */
	private const PASSED = 299;

	public function set_up(): void {
		parent::set_up();
		// Requests that are not blocked never leave the test: they get this answer instead.
		add_filter(
			'pre_http_request',
			static fn( $pre ) => false === $pre ? array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => self::PASSED,
					'message' => 'Passed',
				),
				'cookies'  => array(),
				'filename' => null,
			) : $pre,
			50
		);
	}

	public function tear_down(): void {
		delete_site_option( Blocked_Hosts::OPTION );
		Request_Log::clear();
		Windrose_Helm_Redirecting_Transport::$asked = array();
		parent::tear_down();
	}

	public function test_nothing_blocked_by_default(): void {
		$this->assertSame( array(), Blocked_Hosts::hosts() );
		$this->assertSame( array(), Settings::all()['blocked_hosts'] );
		$this->assertSame( self::PASSED, wp_remote_retrieve_response_code( wp_remote_get( 'https://llm.automatorplugin.com/api/credits/allocation-facts' ) ) );
	}

	public function test_entries_become_hosts(): void {
		$cases = array(
			'llm.automatorplugin.com'             => 'llm.automatorplugin.com',
			'  LLM.AutomatorPlugin.com.  '        => 'llm.automatorplugin.com',
			'https://llm.automatorplugin.com/api/credits/allocation-facts?key=1' => 'llm.automatorplugin.com',
			'llm.automatorplugin.com/api/credits' => 'llm.automatorplugin.com',
			'llm.automatorplugin.com:443'         => 'llm.automatorplugin.com',
			'*.automatorplugin.com'               => 'automatorplugin.com',
			'203.0.113.7'                         => '203.0.113.7',
			'[2001:db8::1]'                       => '2001:db8::1',
			'localhost'                           => '',
			'com'                                 => '',
			'bad_host.example.com'                => '',
			'-bad.example.com'                    => '',
			'two words.example.com'               => '',
			''                                    => '',
		);
		foreach ( $cases as $entry => $host ) {
			$this->assertSame( $host, Blocked_Hosts::normalize( (string) $entry ), (string) $entry );
		}
	}

	public function test_a_list_is_cleaned_or_refused(): void {
		$this->assertSame(
			array( 'llm.automatorplugin.com', 'tracker.example.net' ),
			Blocked_Hosts::sanitize( array( 'llm.automatorplugin.com', '', 'https://LLM.automatorplugin.com/x', 'tracker.example.net' ) )
		);

		$invalid = Blocked_Hosts::sanitize( array( 'llm.automatorplugin.com', 'not a host' ) );
		$this->assertWPError( $invalid );
		$this->assertSame( 400, $invalid->get_error_data()['status'] );
		$this->assertStringContainsString( 'not a host', $invalid->get_error_message() );

		$this->assertWPError( Blocked_Hosts::sanitize( array( 42 ) ) );

		$too_many = array();
		for ( $i = 0; $i <= Blocked_Hosts::MAX; $i++ ) {
			$too_many[] = "host{$i}.example.net";
		}
		$this->assertWPError( Blocked_Hosts::sanitize( $too_many ) );
		$this->assertCount( Blocked_Hosts::MAX, Blocked_Hosts::sanitize( array_slice( $too_many, 0, Blocked_Hosts::MAX ) ) );
	}

	public function test_wordpress_org_is_left_to_the_updates_tab(): void {
		foreach ( array( 'api.wordpress.org', 'wordpress.org', 's.w.org', 'downloads.wp.org' ) as $host ) {
			$refused = Blocked_Hosts::sanitize( array( $host ) );
			$this->assertWPError( $refused, $host );
			$this->assertStringContainsString( 'Updates tab', $refused->get_error_message() );
		}
	}

	public function test_this_networks_addresses_cannot_be_blocked(): void {
		$own = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		$this->assertWPError( Blocked_Hosts::sanitize( array( $own ) ) );

		// A parent domain of one of the network's sites would cover it.
		self::factory()->blog->create(
			array(
				'domain' => 'shop.windrose-test.net',
				'path'   => '/',
			)
		);
		$refused = Blocked_Hosts::sanitize( array( 'windrose-test.net' ) );
		$this->assertWPError( $refused );
		$this->assertStringContainsString( 'shop.windrose-test.net', $refused->get_error_message() );
		$this->assertSame( array( 'other.windrose-test.net' ), Blocked_Hosts::sanitize( array( 'other.windrose-test.net' ) ) );
	}

	public function test_requests_to_a_blocked_host_and_its_subdomains_get_a_local_error(): void {
		$this->assertTrue( Blocked_Hosts::save( array( 'llm.automatorplugin.com' ) ) );

		foreach ( array( 'https://llm.automatorplugin.com/api/credits/allocation-facts', 'http://eu.llm.automatorplugin.com/', 'https://LLM.automatorplugin.com./x' ) as $url ) {
			$response = wp_remote_post( $url, array( 'body' => array( 'license' => 'secret' ) ) );
			$this->assertWPError( $response, $url );
			$this->assertSame( 'windrose_helm_blocked_host', $response->get_error_code() );
			$this->assertStringContainsString( 'Windrose Helm, Privacy', $response->get_error_message() );
		}
		$this->assertWPError( wp_safe_remote_get( 'https://llm.automatorplugin.com/' ) );

		// The parent domain and look-alikes are not blocked.
		foreach ( array( 'https://automatorplugin.com/', 'https://api.automatorplugin.com/', 'https://notllm.automatorplugin.com/', 'https://llm.automatorplugin.com.example.net/' ) as $url ) {
			$this->assertSame( self::PASSED, wp_remote_retrieve_response_code( wp_remote_get( $url ) ), $url );
		}
	}

	public function test_a_blocked_request_is_logged_without_its_query_string(): void {
		Blocked_Hosts::save( array( 'llm.automatorplugin.com' ) );
		wp_remote_post( 'https://llm.automatorplugin.com/api/credits/allocation-facts?key=secret' );
		Request_Log::flush();
		$entry = Request_Log::entries()[0];
		$this->assertSame( 'llm.automatorplugin.com', $entry['host'] );
		$this->assertSame( '/api/credits/allocation-facts', $entry['path'] );
		$this->assertSame( 'blocked', $entry['outcome'] );
		$this->assertSame( 'POST', $entry['method'] );
	}

	public function test_this_networks_addresses_are_never_blocked_even_when_stored_by_hand(): void {
		$own = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		update_site_option( Blocked_Hosts::OPTION, array( $own ) );
		$this->assertSame( self::PASSED, wp_remote_retrieve_response_code( wp_remote_get( home_url( '/wp-cron.php' ) ) ) );
	}

	public function test_a_redirect_into_a_blocked_host_is_stopped(): void {
		Blocked_Hosts::save( array( 'llm.automatorplugin.com' ) );
		// Through core's own HTTP code, with a transport that answers locally instead of the network.
		remove_all_filters( 'pre_http_request', 50 );
		$transport = static function ( $url, $headers, $data, $type, &$options ): void {
			$options['transport'] = Windrose_Helm_Redirecting_Transport::class;
		};
		add_action( 'requests-requests.before_request', $transport, 10, 5 );

		Windrose_Helm_Redirecting_Transport::$location = 'https://llm.automatorplugin.com/api/credits/allocation-facts';
		$response                                      = wp_remote_get( 'https://redirector.example.net/start' );
		$this->assertWPError( $response );
		$this->assertSame( 'http_request_failed', $response->get_error_code() );
		$this->assertStringContainsString( 'llm.automatorplugin.com', $response->get_error_message() );
		$this->assertSame( array( 'https://redirector.example.net/start' ), Windrose_Helm_Redirecting_Transport::$asked, 'The blocked address was never asked for.' );
		Request_Log::flush();
		$outcomes = array_map( static fn( array $e ): string => $e['host'] . ' ' . $e['outcome'], Request_Log::entries() );
		$this->assertContains( 'llm.automatorplugin.com blocked', $outcomes );

		// A redirect elsewhere is followed.
		Windrose_Helm_Redirecting_Transport::$asked    = array();
		Windrose_Helm_Redirecting_Transport::$location = 'https://allowed.example.net/end';
		$response                                      = wp_remote_get( 'https://redirector.example.net/start' );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( array( 'https://redirector.example.net/start', 'https://allowed.example.net/end' ), Windrose_Helm_Redirecting_Transport::$asked );

		remove_action( 'requests-requests.before_request', $transport, 10 );
	}

	public function test_rest_saves_the_list_and_refuses_a_bad_one_without_saving_anything(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		grant_super_admin( get_current_user_id() );

		$request = new WP_REST_Request( 'POST', '/' . Rest::NAMESPACE . '/settings' );
		$request->set_body_params( array( 'blocked_hosts' => array( 'https://llm.automatorplugin.com/api', 'tracker.example.net' ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'llm.automatorplugin.com', 'tracker.example.net' ), $response->get_data()['blocked_hosts'] );

		$request = new WP_REST_Request( 'POST', '/' . Rest::NAMESPACE . '/settings' );
		$request->set_body_params(
			array(
				'privacy'       => array( 'emoji' => false ),
				'blocked_hosts' => array( 'api.wordpress.org' ),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'windrose_helm_blocked_host_invalid', $response->get_data()['code'] );
		$this->assertTrue( Settings::privacy()['emoji'], 'Nothing saved with a refused list.' );
		$this->assertSame( array( 'llm.automatorplugin.com', 'tracker.example.net' ), Blocked_Hosts::hosts() );

		// An empty list removes the option.
		$request = new WP_REST_Request( 'POST', '/' . Rest::NAMESPACE . '/settings' );
		$request->set_body_params( array( 'blocked_hosts' => array() ) );
		$this->assertSame( array(), rest_do_request( $request )->get_data()['blocked_hosts'] );
		$this->assertFalse( get_site_option( Blocked_Hosts::OPTION, false ) );

		// Site administrators may not change it.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', '/' . Rest::NAMESPACE . '/settings' );
		$request->set_body_params( array( 'blocked_hosts' => array( 'tracker.example.net' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}
}
