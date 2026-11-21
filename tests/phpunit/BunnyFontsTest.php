<?php
/**
 * Bunny Fonts in core's Font Library: collection, file route, split of script files.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Bunny_Fonts;
use Windrose\Helm\Rest;
use Windrose\Helm\Settings;

require_once __DIR__ . '/includes/recording-server.php';

/**
 * Bunny Fonts tests.
 */
class BunnyFontsTest extends WP_UnitTestCase {

	/**
	 * Addresses the stand-in network was asked for.
	 *
	 * @var list<string>
	 */
	private array $asked = array();

	/**
	 * What the stand-in network answers.
	 *
	 * @var array{code: int, body: string}
	 */
	private array $answer = array(
		'code' => 200,
		'body' => "wOF2\x00\x01fake font",
	);

	public function set_up(): void {
		parent::set_up();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->asked[] = $url;
				return array(
					'headers'  => array(),
					'body'     => $this->answer['body'],
					'response' => array(
						'code'    => $this->answer['code'],
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			50,
			3
		);
	}

	public function tear_down(): void {
		delete_site_option( Settings::PRIVACY );
		if ( WP_Font_Library::get_instance()->get_font_collection( Bunny_Fonts::SLUG ) ) {
			wp_unregister_font_collection( Bunny_Fonts::SLUG );
		}
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	public function test_blocked_by_default(): void {
		$this->assertTrue( Settings::privacy()['bunny_fonts'] );
		$this->assertFalse( Bunny_Fonts::offered() );
		$this->assertNull( WP_Font_Library::get_instance()->get_font_collection( Bunny_Fonts::SLUG ) );
	}

	public function test_offered_only_with_the_switch_off_and_the_privacy_module_on(): void {
		$this->offer();
		$this->assertTrue( Bunny_Fonts::offered() );
		update_site_option( Settings::MODULES, array( 'privacy' => false ) );
		$this->assertFalse( Bunny_Fonts::offered() );
		delete_site_option( Settings::MODULES );
	}

	public function test_the_php_index_holds_the_json_index(): void {
		$json = wp_json_file_decode( WINDROSE_HELM_DIR . '/data/bunny-fonts-index.json', array( 'associative' => true ) );
		$this->assertIsArray( $json );
		$this->assertSame( $json, require WINDROSE_HELM_DIR . '/data/bunny-fonts-index.php', 'Run python3 -I bin/bunny-fonts.py reformat' );
		$this->assertSame( $json['families'], Bunny_Fonts::index()['families'] );
	}

	public function test_collection_reads_the_shipped_file(): void {
		Bunny_Fonts::register_collection();
		$data = WP_Font_Library::get_instance()->get_font_collection( Bunny_Fonts::SLUG )->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'Bunny Fonts', $data['name'] );
		$this->assertSame( array( 'sans-serif', 'display', 'serif', 'handwriting', 'monospace' ), wp_list_pluck( $data['categories'], 'slug' ) );
		$this->assertGreaterThan( 1500, count( $data['font_families'] ) );

		$cairo = $this->family( $data, 'cairo' );
		$this->assertSame( 'Cairo', $cairo['font_family_settings']['name'] );
		$regular = $this->face( $cairo, '400', 'normal' );
		$this->assertContains( 'https://fonts.bunny.net/cairo/files/cairo-arabic-400-normal.woff2', $regular['src'] );
		$this->assertStringContainsString( '-latin-400-normal.woff2', $regular['src'][0], 'The default script comes first (previews use it).' );

		foreach ( $data['font_families'] as $family ) {
			foreach ( $family['font_family_settings']['fontFace'] as $face ) {
				$this->assertLessThanOrEqual( 20, count( (array) $face['src'] ) );
			}
		}
		$this->assertNull( $this->family( $data, 'noto-sans-jp' ), 'Families cut into numbered slices are left out.' );
	}

	public function test_rest_answer_points_files_at_this_site(): void {
		$this->offer();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'init' );
		$response = rest_do_request( '/wp/v2/font-collections/' . Bunny_Fonts::SLUG );
		$this->assertSame( 200, $response->get_status() );
		$regular = $this->face( $this->family( $response->get_data(), 'cairo' ), '400', 'normal' );
		$prefix  = rest_url( Rest::NAMESPACE . Bunny_Fonts::ROUTE . '/' . wp_create_nonce( Bunny_Fonts::NONCE ) . '/cairo/' );
		foreach ( $regular['src'] as $src ) {
			$this->assertStringStartsWith( $prefix, $src );
			$this->assertMatchesRegularExpression( '#/cairo-[a-z-]+-400-normal\.woff2$#', $src, 'The address ends in the file name.' );
		}
		$this->assertSame( array(), $this->asked, 'Listing the collection contacts nobody.' );
	}

	public function test_addresses_of_other_hosts_stay(): void {
		$this->assertSame( 'https://example.org/a.woff2', Bunny_Fonts::local_address( 'https://example.org/a.woff2', 'https://site.test/r/' ) );
		$this->assertSame( 'https://fonts.bunny.net/css?family=inter', Bunny_Fonts::local_address( 'https://fonts.bunny.net/css?family=inter', 'https://site.test/r/' ) );
		$this->assertSame( 'https://site.test/r/inter/inter-latin-400-normal.woff2', Bunny_Fonts::local_address( 'https://fonts.bunny.net/inter/files/inter-latin-400-normal.woff2', 'https://site.test/r/' ) );
	}

	public function test_file_route_fetches_from_bunny_for_people_who_may_install_fonts(): void {
		$this->offer();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$check = $this->log_in( $admin );

		$response = $this->get_file( $check, 'cairo', 'cairo-arabic-400-normal.woff2' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->answer['body'], $response->get_data() );
		$this->assertSame( 'font/woff2', $response->get_headers()['Content-Type'] );
		$this->assertSame( array( 'https://fonts.bunny.net/cairo/files/cairo-arabic-400-normal.woff2' ), $this->asked );
	}

	public function test_file_route_refuses_without_login_check_or_rights(): void {
		$this->offer();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$check = $this->log_in( $admin );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$this->assertSame( 401, $this->get_file( $check, 'cairo', 'cairo-arabic-400-normal.woff2' )->get_status(), 'Not logged in.' );

		$this->log_in( $admin );
		$this->assertSame( 403, $this->get_file( 'abcdef0123', 'cairo', 'cairo-arabic-400-normal.woff2' )->get_status(), 'Wrong check.' );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->assertSame( 403, $this->get_file( $this->log_in( $author ), 'cairo', 'cairo-arabic-400-normal.woff2' )->get_status(), 'May not install fonts.' );
		$this->assertSame( array(), $this->asked );
	}

	public function test_file_route_only_serves_files_of_the_collection(): void {
		$this->offer();
		$check = $this->log_in( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach (
			array(
				array( 'cairo', 'cairo-klingon-400-normal.woff2' ),
				array( 'inter', 'cairo-arabic-400-normal.woff2' ),
				array( 'cairo', 'cairo-arabic-450-normal.woff2' ),
				array( 'not-a-family', 'not-a-family-latin-400-normal.woff2' ),
			) as list( $family, $file )
		) {
			$this->assertSame( 404, $this->get_file( $check, $family, $file )->get_status(), $file );
		}
		$this->assertSame( 404, $this->get_file( $check, 'cairo', '..%2Fcairo-arabic-400-normal.woff2' )->get_status(), 'No route for other characters.' );
		$this->assertSame( array(), $this->asked, 'Nothing fetched for a refused name.' );

		update_site_option( Settings::PRIVACY, array( 'bunny_fonts' => true ) );
		$this->assertSame( 404, $this->get_file( $check, 'cairo', 'cairo-arabic-400-normal.woff2' )->get_status(), 'Blocked again: nothing fetched.' );
		$this->assertSame( array(), $this->asked );
	}

	public function test_file_route_refuses_bad_answers(): void {
		$this->offer();
		$check = $this->log_in( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach (
			array(
				array( 404, 'wOF2' ),
				array( 200, '<html>not a font</html>' ),
				array( 200, 'wOF2' . str_repeat( 'x', Bunny_Fonts::MAX_BYTES ) ),
			) as list( $code, $body )
		) {
			$this->answer = array(
				'code' => $code,
				'body' => $body,
			);
			$this->assertSame( 502, $this->get_file( $check, 'cairo', 'cairo-arabic-400-normal.woff2' )->get_status() );
		}
	}

	public function test_file_sent_as_it_is_and_kept_by_the_browser(): void {
		$this->offer();
		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_pre_serve_request', array( Bunny_Fonts::class, 'serve_file' ) ), 'After WordPress\'s own headers (CORS).' );
		$server                  = new Windrose_Helm_Recording_Server();
		$request                 = new WP_REST_Request( 'GET', '/' . Rest::NAMESPACE . Bunny_Fonts::ROUTE . '/abcdef0123/cairo/cairo-arabic-400-normal.woff2' );
		$response                = new WP_REST_Response( "wOF2\x00bytes", 200 );
		$server->sent['Expires'] = 'Wed, 11 Jan 1984 05:00:00 GMT';

		ob_start();
		$served = Bunny_Fonts::serve_file( false, $response, $request, $server );
		$output = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( "wOF2\x00bytes", $output );
		$this->assertSame( 'private, max-age=' . WEEK_IN_SECONDS, $server->sent['Cache-Control'] );
		$this->assertArrayNotHasKey( 'Expires', $server->sent );

		$other = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->assertFalse( Bunny_Fonts::serve_file( false, $response, $other, $server ), 'Other routes are left to WordPress.' );
		$this->assertFalse( Bunny_Fonts::serve_file( false, new WP_REST_Response( array( 'code' => 'x' ), 404 ), $request, $server ), 'Errors stay JSON.' );
	}

	public function test_file_names_read_against_the_index(): void {
		$index  = Bunny_Fonts::index();
		$parsed = Bunny_Fonts::parse_file( 'cairo-latin-ext-700-normal-1.woff2' );
		$this->assertSame( 'cairo', $parsed['family'] );
		$this->assertSame( 'latin-ext', $parsed['subset'] );
		$this->assertSame( '700', $parsed['weight'] );
		$this->assertSame( $index['families']['cairo']['ranges']['latin-ext'] ?? $index['ranges']['latin-ext'], $parsed['range'] );

		$this->assertSame( 'noto-sans-arabic', Bunny_Fonts::parse_file( 'noto-sans-arabic-arabic-400-normal.woff2' )['family'] );
		$this->assertNull( Bunny_Fonts::parse_file( 'cairo-latin-400-normal.woff' ) );
		$this->assertNull( Bunny_Fonts::parse_file( 'my-font-400-normal.woff2' ) );

		// A family whose range of a script differs from the usual one keeps its own.
		foreach ( $index['families'] as $id => $family ) {
			foreach ( $family['ranges'] ?? array() as $subset => $range ) {
				$this->assertSame( $range, Bunny_Fonts::parse_file( $id . '-' . $subset . '-' . strtok( $family['faces'][0], '-' ) . '-' . substr( $family['faces'][0], 4 ) . '.woff2' )['range'] );
				return;
			}
		}
	}

	public function test_faces_with_several_script_files_are_split_when_printed(): void {
		$url   = content_url( 'uploads/fonts/' );
		$faces = array(
			array(
				'fontFamily' => 'Cairo',
				'fontStyle'  => 'normal',
				'fontWeight' => '400',
				'src'        => array( $url . 'cairo-latin-400-normal.woff2', $url . 'cairo-latin-ext-400-normal.woff2', $url . 'cairo-arabic-400-normal-1.woff2' ),
			),
			array(
				'fontFamily' => 'Cairo',
				'fontStyle'  => 'normal',
				'fontWeight' => '700',
				'src'        => $url . 'cairo-latin-700-normal.woff2',
			),
			array(
				'fontFamily' => 'Cairo',
				'fontStyle'  => 'normal',
				'fontWeight' => '800',
				'src'        => array( $url . 'my-cairo-800.woff2', $url . 'my-cairo-800.woff' ),
			),
		);
		$this->save_styles( $faces );

		$printed = array();
		foreach ( WP_Font_Face_Resolver::get_fonts_from_theme_json() as $family ) {
			$printed = 'Cairo' === ( $family[0]['font-family'] ?? '' ) ? $family : $printed;
		}
		$this->assertCount( 5, $printed );
		$index = Bunny_Fonts::index();
		foreach ( array( 'latin', 'latin-ext', 'arabic' ) as $i => $subset ) {
			$this->assertStringContainsString( "cairo-$subset-400-normal", $printed[ $i ]['src'][0] );
			$this->assertSame( $index['families']['cairo']['ranges'][ $subset ] ?? $index['ranges'][ $subset ], $printed[ $i ]['unicode-range'] );
		}
		$this->assertArrayNotHasKey( 'unicode-range', $printed[3], 'A face with one file stays as it is.' );
		$this->assertCount( 2, $printed[4]['src'], 'Files of other origins stay as they are.' );

		ob_start();
		wp_print_font_faces();
		$css = (string) ob_get_clean();
		$this->assertSame( 3, substr_count( $css, 'unicode-range' ) );
	}

	public function test_mismatched_faces_are_not_split(): void {
		$url = content_url( 'uploads/fonts/' );
		$this->assertNull(
			Bunny_Fonts::split_face(
				array(
					'fontWeight' => '700',
					'fontStyle'  => 'normal',
					'src'        => array( $url . 'cairo-latin-400-normal.woff2', $url . 'cairo-arabic-400-normal.woff2' ),
				)
			),
			'Files of another weight.'
		);
		$this->assertNull(
			Bunny_Fonts::split_face(
				array(
					'fontWeight'   => '400',
					'src'          => array( $url . 'cairo-latin-400-normal.woff2', $url . 'cairo-arabic-400-normal.woff2' ),
					'unicodeRange' => 'U+0-FF',
				)
			),
			'A face that already has a range.'
		);
	}

	/**
	 * Switches the collection on and hooks it as Privacy does.
	 */
	private function offer(): void {
		update_site_option( Settings::PRIVACY, array( 'bunny_fonts' => false ) );
		Bunny_Fonts::offer();
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Gives the person a login cookie and returns their check for the file route; the current user is then nobody,
	 * as for a request without a REST nonce.
	 *
	 * @param int $user_id The person.
	 * @return string
	 */
	private function log_in( int $user_id ): string {
		$expiration                  = time() + HOUR_IN_SECONDS;
		$token                       = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
		wp_set_current_user( $user_id );
		$check = wp_create_nonce( Bunny_Fonts::NONCE );
		wp_set_current_user( 0 );
		return $check;
	}

	/**
	 * Asks the file route.
	 *
	 * @param string $check  Check.
	 * @param string $family Family.
	 * @param string $file   File name.
	 * @return WP_REST_Response
	 */
	private function get_file( string $check, string $family, string $file ): WP_REST_Response {
		wp_set_current_user( 0 );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . Rest::NAMESPACE . Bunny_Fonts::ROUTE . "/$check/$family/$file" ) );
		$current  = get_current_user_id();
		wp_set_current_user( 0 );
		if ( in_array( $response->get_status(), array( 401, 403 ), true ) ) {
			$this->assertSame( 0, $current, 'A refused person is not kept as the current user.' );
		}
		return $response;
	}

	/**
	 * Saves the site's styles with one Font Library family.
	 *
	 * @param list<array<string, mixed>> $faces Its faces.
	 */
	private function save_styles( array $faces ): void {
		switch_theme( 'twentytwentyfive' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin );
		wp_set_current_user( $admin );
		$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		wp_update_post(
			array(
				'ID'           => $post['ID'],
				'post_content' => wp_slash(
					wp_json_encode(
						array(
							'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
							'isGlobalStylesUserThemeJSON' => true,
							'settings'                    => array(
								'typography' => array(
									'fontFamilies' => array(
										'custom' => array(
											array(
												'name'     => 'Cairo',
												'slug'     => 'cairo',
												'fontFamily' => 'Cairo, sans-serif',
												'fontFace' => $faces,
											),
										),
									),
								),
							),
						)
					)
				),
			)
		);
		wp_set_current_user( 0 );
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
	}

	/**
	 * A family of collection data.
	 *
	 * @param array<string, mixed> $data Collection data.
	 * @param string               $slug Family slug.
	 * @return array<string, mixed>|null
	 */
	private function family( array $data, string $slug ): ?array {
		foreach ( $data['font_families'] as $family ) {
			if ( $slug === $family['font_family_settings']['slug'] ) {
				return $family;
			}
		}
		return null;
	}

	/**
	 * A face of a family.
	 *
	 * @param array<string, mixed>|null $family Family.
	 * @param string                    $weight Weight.
	 * @param string                    $style  Style.
	 * @return array<string, mixed>
	 */
	private function face( ?array $family, string $weight, string $style ): array {
		$this->assertNotNull( $family );
		foreach ( $family['font_family_settings']['fontFace'] as $face ) {
			if ( $weight === $face['fontWeight'] && $style === $face['fontStyle'] ) {
				return $face;
			}
		}
		$this->fail( "No $weight $style face." );
	}
}
