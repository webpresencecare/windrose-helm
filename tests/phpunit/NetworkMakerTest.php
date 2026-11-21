<?php
/**
 * Make a network: the rules it applies (the conversion itself runs on a single site: Windrose Shiplaunch's
 * tests/e2e/existing-single-helm.spec.mjs), and the redirect of old post addresses on the network.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Network_Maker;
use Windrose\Helm\Old_Addresses;

/**
 * Make a network tests.
 */
class NetworkMakerTest extends WP_UnitTestCase {

	public function test_post_addresses_keep_the_structure_with_blog_in_front(): void {
		$this->assertSame( '/blog/%postname%/', Network_Maker::structure( '/%postname%/', false, 'keep' ) );
		$this->assertSame( '/blog/%year%/%postname%/', Network_Maker::structure( '/%year%/%postname%/', false, 'keep' ) );
		$this->assertSame( '/blog/%postname%/', Network_Maker::structure( '%postname%/', false, 'keep' ) );
		$this->assertSame( '/blog/%postname%/', Network_Maker::structure( '/blog/%postname%/', false, 'keep' ) );
		// Subdomains: no prefix needed. Plain addresses stay plain. index.php structures: core's default.
		$this->assertSame( '/%postname%/', Network_Maker::structure( '/%postname%/', true, 'keep' ) );
		$this->assertSame( '', Network_Maker::structure( '', false, 'keep' ) );
		$this->assertNull( Network_Maker::structure( '/index.php/%postname%/', false, 'keep' ) );
		$this->assertNull( Network_Maker::structure( '/%postname%/', false, 'default' ) );
	}

	public function test_config_lines(): void {
		$lines = Network_Maker::config_lines( 'example.com', '/site/', false );
		$this->assertStringContainsString( "define( 'SUBDOMAIN_INSTALL', false );", $lines );
		$this->assertStringContainsString( "define( 'DOMAIN_CURRENT_SITE', 'example.com' );", $lines );
		$this->assertStringContainsString( "define( 'PATH_CURRENT_SITE', '/site/' );", $lines );
		$this->assertStringContainsString( "define( 'SUBDOMAIN_INSTALL', true );", Network_Maker::config_lines( 'example.com', '/', true ) );
		$this->assertStringContainsString( "define( 'DOMAIN_CURRENT_SITE', 'it\\'s.example' );", Network_Maker::config_lines( "it's.example", '/', false ) );
	}

	public function test_config_lines_go_above_the_stop_editing_line_once(): void {
		$sample   = "<?php\ndefine( 'DB_NAME', 'x' );\n/* That's all, stop editing! Happy publishing. */\nrequire_once ABSPATH . 'wp-settings.php';\n";
		$lines    = Network_Maker::config_lines( 'example.com', '/', false );
		$contents = Network_Maker::add_config( $sample, $lines );
		$this->assertIsString( $contents );
		$this->assertLessThan( strpos( $contents, "/* That's all" ), strpos( $contents, "define( 'MULTISITE', true );" ) );
		$this->assertNull( Network_Maker::add_config( $contents, $lines ) );
		$this->assertNull( Network_Maker::add_config( "<?php\n// no place\n", $lines ) );
	}

	public function test_nginx_rules(): void {
		$this->assertStringContainsString( 'rewrite ^/site/[_0-9a-zA-Z-]+(/wp-.*) /site$1 last;', Network_Maker::nginx_rules( '/site/', false ) );
		$this->assertStringNotContainsString( 'rewrite', Network_Maker::nginx_rules( '/', true ) );
	}

	public function test_old_post_addresses_find_their_blog_form(): void {
		$this->set_permalink_structure( '/blog/%postname%/' );
		self::factory()->post->create(
			array(
				'post_name'   => 'welcome',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( home_url( '/blog/welcome/' ), Old_Addresses::target( 'welcome/' ) );
		$this->assertSame( home_url( '/blog/welcome/' ), Old_Addresses::target( '/welcome/' ) );
		$this->assertSame( '', Old_Addresses::target( 'missing/' ) );
		$this->assertSame( '', Old_Addresses::target( 'blog/welcome/' ) );
		$this->assertSame( '', Old_Addresses::target( 'wp-admin/' ) );
		$this->assertSame( '', Old_Addresses::target( '' ) );
	}

	public function test_the_redirect_is_only_hooked_after_a_conversion_on_the_main_site(): void {
		remove_all_actions( 'template_redirect' );
		Old_Addresses::init();
		$this->assertFalse( has_action( 'template_redirect', array( Old_Addresses::class, 'redirect' ) ) );
		update_option( Old_Addresses::OPTION, '1' );
		Old_Addresses::init();
		$this->assertSame( 9, has_action( 'template_redirect', array( Old_Addresses::class, 'redirect' ) ) );
		remove_all_actions( 'template_redirect' );
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		update_option( Old_Addresses::OPTION, '1' );
		Old_Addresses::init();
		restore_current_blog();
		$this->assertFalse( has_action( 'template_redirect', array( Old_Addresses::class, 'redirect' ) ) );
	}

	public function test_backups_are_copies_next_to_the_file_and_never_overwritten(): void {
		$dir = get_temp_dir() . 'windrose-helm-backup-' . wp_generate_password( 8, false );
		mkdir( $dir );
		$file = $dir . '/wp-config.php';
		file_put_contents( $file, 'first' );
		$this->assertSame( $dir . '/wp-config.backup.php', Network_Maker::backup( $file, 'wp-config.backup', '.php' ) );
		file_put_contents( $file, 'second' );
		$second = Network_Maker::backup( $file, 'wp-config.backup', '.php' );
		$this->assertMatchesRegularExpression( '#/wp-config\.backup-\d{8}-\d{6}\.php$#', $second );
		$this->assertSame( 'first', file_get_contents( $dir . '/wp-config.backup.php' ) );
		$this->assertSame( 'second', file_get_contents( $second ) );
		$this->assertSame( '', Network_Maker::backup( $dir . '/missing', '.htaccess', '.backup' ) );
		array_map( 'unlink', glob( $dir . '/*' ) );
		rmdir( $dir );
	}

	public function test_make_refuses_on_a_network(): void {
		$result = Network_Maker::make( 'subdirectory', 'keep', 'Test' );
		$this->assertWPError( $result );
		$this->assertSame( 'This site is already part of a network.', $result->get_error_message() );
	}
}
