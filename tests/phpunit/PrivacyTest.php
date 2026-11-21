<?php
/**
 * Module Privacy (all switches on, as on a fresh network).
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Modules\Privacy;
use Windrose\Helm\Request_Log;

/**
 * Privacy tests.
 */
class PrivacyTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Request_Log::clear();
		parent::tear_down();
	}

	public function test_version_check_keeps_only_versions_and_locale(): void {
		$query = array(
			'version'            => '7.2',
			'php'                => '8.5.12',
			'locale'             => 'fr_FR',
			'mysql'              => '11.8',
			'local_package'      => '',
			'blogs'              => 4,
			'users'              => 1,
			'multisite_enabled'  => 1,
			'initial_db_version' => 60000,
			'extensions'         => array( 'curl' => '8.5.12' ),
			'platform_flags'     => array( 'os' => 'Linux' ),
			'image_support'      => array( 'webp' => 1 ),
		);
		$this->assertSame(
			array(
				'version' => '7.2',
				'php'     => '8.5.12',
				'locale'  => 'fr_FR',
			),
			apply_filters( 'core_version_check_query_args', $query )
		);
	}

	public function test_wporg_requests_carry_no_site_address(): void {
		$args     = array(
			'user-agent' => 'WordPress/7.2; ' . home_url( '/' ),
			'headers'    => array(
				'wp_install' => network_site_url(),
				'wp_blog'    => home_url( '/' ),
				'Accept'     => 'application/json',
			),
		);
		$filtered = apply_filters( 'http_request_args', $args, 'https://api.wordpress.org/core/version-check/1.7/' );
		$this->assertSame( 'WordPress/' . wp_get_wp_version(), $filtered['user-agent'] );
		$this->assertSame( array( 'Accept' => 'application/json' ), $filtered['headers'] );

		$other = apply_filters( 'http_request_args', $args, 'https://api.github.com/' );
		$this->assertSame( $args['headers'], $other['headers'], 'Other hosts keep their headers.' );
		$this->assertSame( 'WordPress/7.2', $other['user-agent'], 'The site address goes from every User-Agent (site_address switch).' );
	}

	public function test_no_site_address_in_any_user_agent(): void {
		$default = array( 'user-agent' => 'WordPress/7.2; ' . home_url( '/' ) );
		foreach ( array( 'https://api.github.com/x', 'https://rest.akismet.com/1.1/comment-check', home_url( '/wp-cron.php' ) ) as $url ) {
			$this->assertSame( 'WordPress/7.2', apply_filters( 'http_request_args', $default, $url )['user-agent'], $url );
		}
		$own = array( 'user-agent' => 'Windrose' );
		$this->assertSame( 'Windrose', apply_filters( 'http_request_args', $own, 'https://api.github.com/' )['user-agent'] );
	}

	public function test_visitors_get_no_usernames_from_the_rest_api(): void {
		$author = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_login'   => 'secretlogin',
				'display_name' => 'Ada Author',
			)
		);
		self::factory()->post->create( array( 'post_author' => $author ) );
		$get = static function ( string $route, array $params = array() ): WP_REST_Response {
			$request = new WP_REST_Request( 'GET', $route );
			$request->set_query_params( $params );
			return rest_get_server()->dispatch( $request );
		};

		wp_set_current_user( 0 );
		$list = $get( '/wp/v2/users' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertStringNotContainsString( 'secretlogin', (string) wp_json_encode( $list->get_data() ) );
		$this->assertContains( 'Ada Author', array_column( $list->get_data(), 'name' ) );
		$one = $get( "/wp/v2/users/$author" );
		$this->assertArrayNotHasKey( 'slug', $one->get_data() );
		$this->assertArrayNotHasKey( 'link', $one->get_data() );
		$this->assertSame( 401, $get( '/wp/v2/users', array( 'slug' => 'secretlogin' ) )->get_status() );
		$this->assertSame( 401, $get( '/wp/v2/users', array( 'search' => 'secret' ) )->get_status() );
		$this->assertSame( 401, $get( '/wp/v2/USERS', array( 'slug' => 'secretlogin' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'secretlogin', $get( "/wp/v2/users/$author" )->get_data()['slug'] );
		$this->assertSame( 200, $get( '/wp/v2/users', array( 'search' => 'Ada' ) )->get_status() );
	}

	public function test_happy_checks_are_not_sent(): void {
		$sent = array();
		$spy  = static function ( $pre, $args, $url ) use ( &$sent ) {
			if ( false === $pre ) {
				$sent[] = $url;
				return new WP_Error( 'test', 'stopped' );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $spy, 100, 3 );
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
		$this->assertFalse( wp_check_browser_version() );
		$this->assertFalse( wp_check_php_version() );
		remove_filter( 'pre_http_request', $spy, 100 );
		$this->assertSame( array(), $sent );
		Request_Log::flush();
		$this->assertSame( array( '/core/serve-happy/1.0/', '/core/browse-happy/1.1/' ), array_column( Request_Log::entries(), 'path' ) );
	}

	public function test_emoji_removed(): void {
		$this->assertFalse( has_action( 'wp_head', 'print_emoji_detection_script' ) );
		// Admin hooks are added after plugins load, so they are removed again on admin_init.
		$this->assertSame( 0, has_action( 'admin_init', array( Privacy::class, 'remove_emoji' ) ) );
		require_once ABSPATH . 'wp-admin/includes/admin-filters.php';
		Privacy::remove_emoji();
		$this->assertFalse( has_action( 'admin_print_scripts', 'print_emoji_detection_script' ) );
		$this->assertFalse( has_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' ) );
		$this->assertFalse( apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/' ) );
	}

	public function test_avatars_are_local(): void {
		$user = self::factory()->user->create( array( 'user_email' => 'someone@example.com' ) );
		$url  = get_avatar_url( $user );
		$this->assertStringContainsString( 'assets/avatar.svg', $url );
		$this->assertStringNotContainsString( 'gravatar', get_avatar( $user ) );
		$this->assertStringNotContainsString( md5( 'someone@example.com' ), get_avatar( $user ) );
	}

	public function test_another_local_avatar_is_kept(): void {
		$local = static function ( $args ) {
			$args['url'] = 'https://example.org/me.png';
			return $args;
		};
		add_filter( 'pre_get_avatar_data', $local, 10 );
		$this->assertSame( 'https://example.org/me.png', get_avatar_url( 1 ) );
		remove_filter( 'pre_get_avatar_data', $local, 10 );
	}

	public function test_editor_sources_off(): void {
		$settings = get_block_editor_settings( array(), new WP_Block_Editor_Context() );
		$this->assertFalse( $settings['enableOpenverseMediaCategory'] );
		$this->assertFalse( apply_filters( 'should_load_remote_block_patterns', true ) );
		$this->assertFalse( has_action( 'enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets' ) );
		$this->assertFalse( apply_filters( 'login_display_language_dropdown', true ) );
	}

	public function test_google_fonts_collection_removed(): void {
		$this->assertNull( WP_Font_Library::get_instance()->get_font_collection( 'google-fonts' ) );
	}

	public function test_welcome_guides_closed_until_someone_changes_preferences(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$key  = $wpdb->get_blog_prefix() . 'persisted_preferences';
		$this->assertFalse( get_user_meta( $user, $key, true )['core/edit-post']['welcomeGuide'] );
		$this->assertFalse( get_user_meta( $user, $key, true )['core/edit-site']['welcomeGuideStyles'] );

		$own = array( 'core/edit-post' => array( 'welcomeGuide' => true ) );
		update_user_meta( $user, $key, $own );
		$this->assertSame( $own, get_user_meta( $user, $key, true ) );
		$this->assertSame( '', get_user_meta( $user, 'some_other_key', true ) );
	}

	public function test_events_and_news_widget_removed(): void {
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_dashboard_setup();
		global $wp_meta_boxes;
		$this->assertEmpty( $wp_meta_boxes['dashboard']['side']['core']['dashboard_primary'] ?? null );
		$this->assertArrayHasKey( 'dashboard_quick_press', $wp_meta_boxes['dashboard']['side']['core'] );
	}
}
