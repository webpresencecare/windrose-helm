<?php
/**
 * REST routes: permissions and validation.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Request_Log;
use Windrose\Helm\Settings;

/**
 * REST tests.
 */
class RestTest extends WP_UnitTestCase {

	private static int $super_admin;
	private static int $site_admin;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$super_admin = $factory->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( self::$super_admin );
		self::$site_admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down(): void {
		Request_Log::clear();
		parent::tear_down();
	}

	private function request( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/windrose-helm/v1' . $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @dataProvider provide_routes
	 */
	public function test_site_admins_and_visitors_are_refused( string $method, string $route ): void {
		// Valid parameters, so the permission check is what answers (core validates parameters first).
		$bodies = array(
			'/recommended'        => array( 'items' => array() ),
			'/recommended/lookup' => array( 'slug' => 'stream' ),
		);
		$body   = 'POST' === $method ? ( $bodies[ $route ] ?? array() ) : array();
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( $method, $route, $body )->get_status() );
		wp_set_current_user( self::$site_admin );
		$this->assertSame( 403, $this->request( $method, $route, $body )->get_status() );
	}

	public static function provide_routes(): array {
		return array(
			array( 'GET', '/settings' ),
			array( 'POST', '/settings' ),
			array( 'GET', '/request-log' ),
			array( 'DELETE', '/request-log' ),
			array( 'GET', '/updates' ),
			array( 'POST', '/updates/check' ),
			array( 'GET', '/recommended' ),
			array( 'POST', '/recommended' ),
			array( 'DELETE', '/recommended' ),
			array( 'POST', '/recommended/lookup' ),
		);
	}

	public function test_settings_round_trip(): void {
		wp_set_current_user( self::$super_admin );
		$response = $this->request(
			'POST',
			'/settings',
			array(
				'privacy' => array( 'emoji' => false ),
				'wporg'   => false,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['privacy']['emoji'] );
		$this->assertFalse( $response->get_data()['wporg'] );
		$this->assertFalse( $this->request( 'GET', '/settings' )->get_data()['privacy']['emoji'] );
	}

	public function test_settings_reject_unknown_switches_and_non_booleans(): void {
		wp_set_current_user( self::$super_admin );
		$this->assertSame( 400, $this->request( 'POST', '/settings', array( 'privacy' => array( 'nope' => true ) ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/settings', array( 'privacy' => array( 'emoji' => 'off' ) ) )->get_status() );
		$this->assertTrue( $this->request( 'GET', '/settings' )->get_data()['privacy']['emoji'] );
	}

	public function test_request_log_route(): void {
		wp_set_current_user( self::$super_admin );
		Request_Log::record( 'https://example.com/a', array(), 'sent', 200 );
		Request_Log::flush();
		$data = $this->request( 'GET', '/request-log' )->get_data();
		$this->assertTrue( $data['enabled'] );
		$this->assertSame( 'example.com', $data['entries'][0]['host'] );
		$this->assertArrayHasKey( 'site_name', $data['entries'][0] );
		$this->assertSame( array(), $this->request( 'DELETE', '/request-log' )->get_data()['entries'] );
	}

	public function test_recommended_routes_answer_only_while_the_module_is_on(): void {
		wp_set_current_user( self::$super_admin );
		update_site_option( Settings::MODULES, array( 'recommended' => false ) );
		$this->assertSame( 403, $this->request( 'GET', '/recommended' )->get_status() );
		delete_site_option( Settings::MODULES );
		$this->assertSame( 200, $this->request( 'GET', '/recommended' )->get_status() );
	}

	public function test_recommended_list_can_be_edited_and_restored(): void {
		wp_set_current_user( self::$super_admin );
		$data = $this->request( 'GET', '/recommended' )->get_data();
		$this->assertSame( 'multisite-language-switcher', $data['items'][0]['slug'] );
		$this->assertFalse( $data['customized'] );
		$this->assertCount( 7, $data['items'] );

		$items   = array_map( static fn( $i ) => array_intersect_key( $i, array_flip( array( 'slug', 'name', 'category', 'why', 'network' ) ) ), $data['items'] );
		$items[] = array(
			'slug'     => 'query-monitor',
			'name'     => 'Query Monitor',
			'category' => 'performance',
			'why'      => 'Developer tools.',
			'network'  => true,
		);
		$saved   = $this->request( 'POST', '/recommended', array( 'items' => $items ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertTrue( $saved->get_data()['customized'] );
		$this->assertSame( 'query-monitor', end( $saved->get_data()['items'] )['slug'] );

		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/recommended',
				array(
					'items' => array(
						array(
							'slug'     => 'x',
							'category' => 'nope',
						),
					),
				)
			)->get_status()
		);
		$this->assertSame( 400, $this->request( 'POST', '/recommended', array( 'items' => array( array( 'slug' => 'Not A Slug' ) ) ) )->get_status() );

		$reset = $this->request( 'DELETE', '/recommended' )->get_data();
		$this->assertFalse( $reset['customized'] );
		$this->assertCount( 7, $reset['items'] );
	}
}
