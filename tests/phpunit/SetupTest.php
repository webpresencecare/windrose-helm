<?php
/**
 * Set up: closed unless the installer left it pending, closed for good once finished, permissions, each step.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Settings;
use Windrose\Helm\Setup;

/**
 * Set up tests.
 */
class SetupTest extends WP_UnitTestCase {

	private static int $super_admin;
	private static int $site_admin;

	private string $config = '';

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$super_admin = $factory->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( self::$super_admin );
		self::$site_admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up(): void {
		parent::set_up();
		// No request to wordpress.org from the tests: the language list is then the installed languages only.
		update_site_option( Settings::WPORG, 0 );
		update_site_option(
			Setup::OPTION,
			array(
				'status'  => 'pending',
				'source'  => 'windrose-shiplaunch',
				'version' => '0.1.0',
				'manual'  => array(
					'rules'  => '',
					'server' => 'apache',
				),
			)
		);
	}

	public function tear_down(): void {
		if ( '' !== $this->config && file_exists( $this->config ) ) {
			unlink( $this->config );
		}
		remove_all_filters( 'windrose_helm_setup_config_path' );
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

	private function temporary_config( string $contents ): string {
		$this->config = get_temp_dir() . 'windrose-helm-config-' . wp_generate_password( 8, false ) . '.php';
		file_put_contents( $this->config, $contents );
		$file = $this->config;
		add_filter( 'windrose_helm_setup_config_path', static fn(): string => $file );
		return $file;
	}

	public static function provide_routes(): array {
		return array(
			array( 'GET', '/setup', array() ),
			array( 'POST', '/setup/addresses', array( 'type' => 'subdirectory' ) ),
			array( 'POST', '/setup/language', array( 'locale' => 'en_US' ) ),
			array( 'POST', '/setup/main-language', array( 'locale' => 'en_US' ) ),
			array(
				'POST',
				'/setup/site',
				array(
					'locale' => 'en_US',
					'path'   => 'refused',
				),
			),
			array( 'POST', '/setup/group', array( 'sites' => array( 1 ) ) ),
			array( 'POST', '/setup/media', array( 'sites' => array( 1 ) ) ),
			array( 'POST', '/setup/finish', array( 'wporg' => true ) ),
		);
	}

	/**
	 * @dataProvider provide_routes
	 */
	public function test_site_admins_and_visitors_are_refused( string $method, string $route, array $body ): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( $method, $route, $body )->get_status() );
		wp_set_current_user( self::$site_admin );
		$this->assertSame( 403, $this->request( $method, $route, $body )->get_status() );
		$this->assertSame( 'pending', Setup::stored()['status'] );
		$this->assertNull( domain_exists( get_network()->domain, get_network()->path . 'refused/' ) );
	}

	/**
	 * @dataProvider provide_routes
	 */
	public function test_every_route_is_closed_without_a_pending_set_up( string $method, string $route, array $body ): void {
		wp_set_current_user( self::$super_admin );
		foreach ( array( null, array( 'status' => 'done' ) ) as $stored ) {
			null === $stored ? delete_site_option( Setup::OPTION ) : update_site_option( Setup::OPTION, $stored );
			$response = $this->request( $method, $route, $body );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'windrose_helm_setup_done', $response->get_data()['code'] );
		}
		$this->assertNull( domain_exists( get_network()->domain, get_network()->path . 'refused/' ) );
	}

	public function test_only_a_pending_set_up_is_pending(): void {
		$this->assertTrue( Setup::pending() );
		update_site_option( Setup::OPTION, array( 'status' => 'done' ) );
		$this->assertFalse( Setup::pending() );
		update_site_option( Setup::OPTION, 'pending' );
		$this->assertFalse( Setup::pending() );
		delete_site_option( Setup::OPTION );
		$this->assertFalse( Setup::pending() );
	}

	public function test_network_administrators_land_on_set_up_after_logging_in(): void {
		$super = get_userdata( self::$super_admin );
		$admin = get_userdata( self::$site_admin );
		$this->assertSame( Setup::url(), Setup::login_redirect( admin_url(), '', $super ) );
		$this->assertSame( Setup::url(), Setup::login_redirect( admin_url(), admin_url(), $super ) );
		// Another page asked for, a site admin, a failed login: unchanged.
		$this->assertSame( home_url( '/x/' ), Setup::login_redirect( home_url( '/x/' ), home_url( '/x/' ), $super ) );
		$this->assertSame( admin_url(), Setup::login_redirect( admin_url(), '', $admin ) );
		$this->assertSame( admin_url(), Setup::login_redirect( admin_url(), '', new WP_Error( 'invalid' ) ) );
	}

	public function test_the_notice_is_for_network_administrators(): void {
		wp_set_current_user( self::$site_admin );
		ob_start();
		Setup::notice();
		$this->assertSame( '', ob_get_clean() );
		wp_set_current_user( self::$super_admin );
		ob_start();
		Setup::notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'page=windrose-helm-setup', $html );
		$this->assertStringContainsString( 'Set up the network', $html );
	}

	public function test_the_state_describes_the_network(): void {
		wp_set_current_user( self::$super_admin );
		$response = $this->request( 'GET', '/setup' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( 'subdirectory', $data['addresses']['type'] );
		$this->assertSame( 'en_US', $data['languages']['main'] );
		$this->assertContains( 'en_US', array_column( $data['languages']['list'], 'locale' ) );
		$this->assertFalse( $data['languages']['canInstall'] );
		$this->assertSame( 'multisite-language-switcher/MultisiteLanguageSwitcher', $data['languages']['msls']['plugin'] );
		$this->assertSame( 'windrose-sabir/windrose-sabir', $data['languages']['sabir']['plugin'] );
		$this->assertSame( 'windrose-anchors/windrose-anchors', $data['plugins']['anchors']['plugin'] );
		$this->assertNotContains( 'multisite-language-switcher', array_column( $data['plugins']['recommended'], 'slug' ) );
		$this->assertFalse( $data['wporg'] );
		$this->assertSame( get_main_site_id(), $data['network']['mainId'] );
		$this->assertSame( array_fill_keys( Settings::PRIVACY_IDS, true ), $data['privacy'] );
	}

	public function test_main_language(): void {
		wp_set_current_user( self::$super_admin );
		update_option( 'WPLANG', 'xx_XX' );
		$response = $this->request( 'POST', '/setup/main-language', array( 'locale' => 'en_US' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', get_blog_option( get_main_site_id(), 'WPLANG' ) );
		$this->assertSame( '', get_site_option( 'WPLANG' ) );
		// With a title (multilingual networks): the main site's title too; an empty title changes nothing.
		$response = $this->request(
			'POST',
			'/setup/main-language',
			array(
				'locale' => 'en_US',
				'title'  => '  Test - EN <b>',
			)
		);
		$this->assertSame( 'Test - EN', $response->get_data()['title'] );
		$this->assertSame( 'Test - EN', get_blog_option( get_main_site_id(), 'blogname' ) );
		$this->request(
			'POST',
			'/setup/main-language',
			array(
				'locale' => 'en_US',
				'title'  => '',
			)
		);
		$this->assertSame( 'Test - EN', get_blog_option( get_main_site_id(), 'blogname' ) );
		// A language neither installed nor offered by wordpress.org (switched off here).
		$this->assertSame( 400, $this->request( 'POST', '/setup/main-language', array( 'locale' => 'fr_FR' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/setup/language', array( 'locale' => '../../etc' ) )->get_status() );
	}

	public function test_a_language_site(): void {
		wp_set_current_user( self::$super_admin );
		update_site_option( 'site_name', 'Windrose Test' );
		update_blog_option( get_main_site_id(), 'blog_public', 0 );
		$response = $this->request(
			'POST',
			'/setup/site',
			array(
				'locale' => 'en_US',
				'path'   => 'en-gb',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$site = $response->get_data();
		$this->assertSame( get_home_url( $site['id'], '/' ), $site['url'] );
		$this->assertSame( get_network()->path . 'en-gb/', get_site( $site['id'] )->path );
		$this->assertSame( 'Windrose Test - EN', get_blog_option( $site['id'], 'blogname' ) );
		// WordPress's first post text stored at installation is the default: the site gets its own (in blocks).
		switch_to_blog( $site['id'] );
		$first = get_post( 1 );
		restore_current_blog();
		$this->assertStringStartsWith( "<!-- wp:paragraph -->\n<p>Welcome to ", $first->post_content );

		$this->assertSame( '', get_blog_option( $site['id'], 'WPLANG' ) );
		$this->assertSame( '0', (string) get_blog_option( $site['id'], 'blog_public' ) );
		$this->assertTrue( is_user_member_of_blog( self::$super_admin, $site['id'] ) );
		$this->assertSame( 'en_US', determine_locale() );

		// The same address again, an address WordPress reserves, an invalid address, no right to create sites.
		$this->assertSame(
			409,
			$this->request(
				'POST',
				'/setup/site',
				array(
					'locale' => 'en_US',
					'path'   => 'en-gb',
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/setup/site',
				array(
					'locale' => 'en_US',
					'path'   => 'blog',
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/setup/site',
				array(
					'locale' => 'en_US',
					'path'   => 'Not/This',
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/setup/site',
				array(
					'locale' => 'en_US',
					'path'   => '-x',
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/setup/site',
				array(
					'locale' => 'zz_ZZ',
					'path'   => 'zz',
				)
			)->get_status()
		);
		add_filter( 'map_meta_cap', array( $this, 'no_create_sites' ), 10, 2 );
		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/setup/site',
				array(
					'locale' => 'en_US',
					'path'   => 'other',
				)
			)->get_status()
		);
		remove_filter( 'map_meta_cap', array( $this, 'no_create_sites' ), 10 );
		$this->assertNull( domain_exists( get_network()->domain, get_network()->path . 'other/' ) );
	}

	public function test_site_titles_carry_the_language_code(): void {
		$this->assertSame( 'Test - DE', Setup::site_title( 'Test', 'de_CH' ) );
		$this->assertSame( 'Test - EO', Setup::site_title( 'Test', 'eo' ) );
		$this->assertSame( 'Test - DE', Setup::site_title( 'Test', 'de_DE_formal' ) );
		$this->assertSame( 'IT', Setup::site_title( ' ', 'it_IT' ) );
	}

	public function test_a_first_post_text_the_owner_changed_is_kept(): void {
		wp_set_current_user( self::$super_admin );
		update_site_option( 'first_post', 'Welcome to %s. This is your first post. Edit or delete it, then start writing!' );
		$this->assertTrue( Setup::first_post_is_default() );
		update_site_option( 'first_post', '' );
		$this->assertTrue( Setup::first_post_is_default() );
		update_site_option( 'first_post', 'Our own first post on %s.' );
		$this->assertFalse( Setup::first_post_is_default() );
		$response = $this->request(
			'POST',
			'/setup/site',
			array(
				'locale' => 'en_US',
				'path'   => 'own-text',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		switch_to_blog( $response->get_data()['id'] );
		$content = get_post( 1 )->post_content;
		restore_current_blog();
		$this->assertStringStartsWith( 'Our own first post on <a href=', $content );
		$this->assertFalse( has_filter( 'pre_site_option_first_post', '__return_empty_string' ) );
	}

	public function test_default_content_is_linked_only_with_windrose_sabir(): void {
		if ( class_exists( '\Windrose\Sabir\Links' ) ) {
			$this->markTestSkipped( 'Windrose Sabir is loaded: the installation test (Windrose Shiplaunch) covers the links.' );
		}
		$this->assertSame( array(), Setup::link_default_content( array( 1 ) ) );
		$this->assertFalse( get_option( 'msls_1' ) );
	}

	public function no_create_sites( array $caps, string $cap ): array {
		return 'create_sites' === $cap ? array( 'do_not_allow' ) : $caps;
	}

	public function test_group_and_media_need_their_plugins(): void {
		wp_set_current_user( self::$super_admin );
		$response = $this->request( 'POST', '/setup/group', array( 'sites' => array( 1 ) ) );
		$this->assertSame( class_exists( '\Windrose\Sabir\Groups' ) ? 400 : 409, $response->get_status() );
		$response = $this->request( 'POST', '/setup/media', array( 'sites' => array( 1 ) ) );
		$this->assertSame( class_exists( '\Windrose\Anchors\Settings' ) ? 200 : 409, $response->get_status() );
	}

	public function test_finish_saves_the_choices_and_closes_the_screen_for_good(): void {
		wp_set_current_user( self::$super_admin );
		$response = $this->request(
			'POST',
			'/setup/finish',
			array(
				'privacy' => array( 'gravatar' => false ),
				'wporg'   => true,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'done', $response->get_data()['status'] );
		$this->assertFalse( Settings::privacy()['gravatar'] );
		$this->assertTrue( Settings::privacy()['emoji'] );
		$this->assertSame( 1, (int) get_site_option( Settings::WPORG ) );
		$stored = Setup::stored();
		$this->assertSame( 'done', $stored['status'] );
		$this->assertArrayNotHasKey( 'manual', $stored );
		$this->assertIsInt( $stored['finished'] );
		$this->assertSame( 403, $this->request( 'GET', '/setup' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/setup/finish', array( 'wporg' => false ) )->get_status() );
		$this->assertSame( 1, (int) get_site_option( Settings::WPORG ) );
		// Unknown switches are refused by the route's schema.
		update_site_option( Setup::OPTION, array( 'status' => 'pending' ) );
		$this->assertSame( 400, $this->request( 'POST', '/setup/finish', array( 'privacy' => array( 'unknown' => true ) ) )->get_status() );
	}

	public function test_subdomains_are_refused_with_more_than_one_site_or_a_config_it_cannot_change(): void {
		$this->temporary_config( "<?php\ndefine( 'SUBDOMAIN_INSTALL', false );\n" );
		$this->assertSame( '', Setup::subdomain_problem() );
		$site = self::factory()->blog->create();
		$this->assertStringContainsString( 'more than one site', Setup::subdomain_problem() );
		wp_delete_site( $site );
		$this->assertSame( '', Setup::subdomain_problem() );
		file_put_contents( $this->config, "<?php\ndefine( 'SUBDOMAIN_INSTALL', 0 );\n" );
		$this->assertStringContainsString( 'does not hold the line', Setup::subdomain_problem() );
		file_put_contents( $this->config, "<?php\ndefine( 'SUBDOMAIN_INSTALL', false );\ndefine( 'SUBDOMAIN_INSTALL', false );\n" );
		$this->assertStringContainsString( 'does not hold the line', Setup::subdomain_problem() );
		unlink( $this->config );
		$this->assertStringContainsString( 'cannot change wp-config.php', Setup::subdomain_problem() );
	}

	public function test_switching_to_subdomains(): void {
		$file = $this->temporary_config( "<?php\ndefine( 'MULTISITE', true );\ndefine( 'SUBDOMAIN_INSTALL', false );\ndefine( 'DOMAIN_CURRENT_SITE', 'example.org' );\n" );
		update_option( 'permalink_structure', '/blog/%year%/%monthnum%/%day%/%postname%/' );
		wp_set_current_user( self::$super_admin );

		$response = $this->request( 'POST', '/setup/addresses', array( 'type' => 'subdomain' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'subdomain', $data['addresses']['type'] );
		// Not Apache (tests run on the command line): the rules come back to show.
		$this->assertStringContainsString( 'RewriteRule ^wp-admin$ wp-admin/ [R=301,L]', $data['rules'] );
		$this->assertSame( "<?php\ndefine( 'MULTISITE', true );\ndefine( 'SUBDOMAIN_INSTALL', true );\ndefine( 'DOMAIN_CURRENT_SITE', 'example.org' );\n", file_get_contents( $file ) );
		$this->assertSame( 1, (int) get_site_option( 'subdomain_install' ) );
		$this->assertSame( '/%year%/%monthnum%/%day%/%postname%/', get_option( 'permalink_structure' ) );
		// A second switch is refused: the line is gone.
		$this->assertSame( 409, $this->request( 'POST', '/setup/addresses', array( 'type' => 'subdomain' ) )->get_status() );
		update_site_option( 'subdomain_install', 0 );
	}

	public function test_keeping_subdirectories_changes_nothing(): void {
		$file = $this->temporary_config( "<?php\ndefine( 'SUBDOMAIN_INSTALL', false );\n" );
		wp_set_current_user( self::$super_admin );
		$response = $this->request( 'POST', '/setup/addresses', array( 'type' => 'subdirectory' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( "<?php\ndefine( 'SUBDOMAIN_INSTALL', false );\n", file_get_contents( $file ) );
		$this->assertSame( 400, $this->request( 'POST', '/setup/addresses', array( 'type' => 'other' ) )->get_status() );
	}

	public function test_rewrite_rules(): void {
		$subdirectories = Setup::htaccess_rules( '/wp/', false );
		$this->assertStringContainsString( 'RewriteBase /wp/', $subdirectories );
		$this->assertStringContainsString( 'RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]', $subdirectories );
		$this->assertStringContainsString( 'RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]', $subdirectories );
		$subdomains = Setup::htaccess_rules( '/', true );
		$this->assertStringContainsString( 'RewriteRule ^(wp-(content|admin|includes).*) $1 [L]', $subdomains );
		$this->assertStringContainsString( 'RewriteRule ^(.*\.php)$ $1 [L]', $subdomains );
	}
}
