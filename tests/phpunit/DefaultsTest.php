<?php
/**
 * Module Defaults.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Modules\Defaults;
use Windrose\Helm\Settings;

/**
 * Defaults tests.
 */
class DefaultsTest extends WP_UnitTestCase {

	public function test_block_only_network_gets_every_default(): void {
		// The test suite's own default theme is classic.
		switch_theme( 'twentytwentyfive' );
		$this->assertSame(
			array(
				'block_themes'    => true,
				'classic_screens' => true,
				'classic_editor'  => true,
				'classic_block'   => true,
			),
			Defaults::initial_values()
		);
	}

	public function test_network_with_a_classic_theme_only_gets_the_classic_block_default(): void {
		$site = self::factory()->blog->create();
		update_blog_option( $site, 'stylesheet', 'twentytwentyone' );
		update_blog_option( $site, 'template', 'twentytwentyone' );
		$values = Defaults::initial_values();
		$this->assertFalse( $values['block_themes'] );
		$this->assertFalse( $values['classic_screens'] );
		$this->assertFalse( $values['classic_editor'] );
		$this->assertTrue( $values['classic_block'] );
	}

	public function test_classic_themes_are_not_allowed(): void {
		$allowed = Defaults::filter_allowed_themes(
			array(
				'twentytwentyfive' => true,
				'twentytwentyone'  => true,
				'ipsum'            => true,
			)
		);
		$this->assertSame( array( 'twentytwentyfive', 'ipsum' ), array_keys( $allowed ) );
	}

	public function test_classic_themes_hidden_from_lists(): void {
		$themes = Defaults::filter_theme_objects(
			array(
				'twentytwentyfive' => wp_get_theme( 'twentytwentyfive' ),
				'twentytwentyone'  => wp_get_theme( 'twentytwentyone' ),
			)
		);
		$this->assertSame( array( 'twentytwentyfive' ), array_keys( $themes ) );
	}

	public function test_theme_installer_asks_for_block_themes(): void {
		$args = Defaults::block_themes_in_installer( (object) array( 'browse' => 'popular' ), 'query_themes' );
		$this->assertSame( array( 'full-site-editing' ), $args->tag );
		$args = Defaults::block_themes_in_installer( (object) array( 'tag' => array( 'blog' ) ), 'query_themes' );
		$this->assertSame( array( 'blog', 'full-site-editing' ), $args->tag );
		$info = (object) array( 'slug' => 'x' );
		$this->assertSame( $info, Defaults::block_themes_in_installer( $info, 'theme_information' ) );
	}

	public function test_classic_block_registered_but_not_in_inserter(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'core/freeform' );
		$this->assertNotNull( $block );
		$this->assertFalse( $block->supports['inserter'] );
		$this->assertStringContainsString( 'Old content', do_blocks( '<!-- wp:freeform --><p>Old content</p><!-- /wp:freeform -->' ) );
	}

	public function test_classic_editing_plugins_hidden_from_installer_and_activation(): void {
		$result   = (object) array(
			'plugins' => array(
				array( 'slug' => 'classic-editor' ),
				(object) array( 'slug' => 'classic-widgets' ),
				array( 'slug' => 'multisite-language-switcher' ),
			),
		);
		$filtered = Defaults::hide_classic_plugins( $result, 'query_plugins' );
		$this->assertSame( array( array( 'slug' => 'multisite-language-switcher' ) ), $filtered->plugins );

		$links = Defaults::remove_activate_link(
			array(
				'activate' => 'a',
				'delete'   => 'd',
			),
			'classic-editor/classic-editor.php'
		);
		$this->assertSame( array( 'delete' => 'd' ), $links );
		$links = Defaults::remove_activate_link( array( 'activate' => 'a' ), 'akismet/akismet.php' );
		$this->assertSame( array( 'activate' => 'a' ), $links );
	}

	public function test_classic_editing_plugins_cannot_be_activated(): void {
		Defaults::refuse_classic_activation( 'akismet/akismet.php' );
		$this->expectException( WPDieException::class );
		Defaults::refuse_classic_activation( 'classic-editor/classic-editor.php' );
	}

	public function test_page_builders_detected(): void {
		update_option( 'active_plugins', array( 'elementor/elementor.php', 'akismet/akismet.php' ) );
		$this->assertSame( array( 'elementor' ), Defaults::active_plugins_among( Defaults::PAGE_BUILDERS ) );
	}

	public function test_defaults_off_when_module_off(): void {
		update_site_option( Settings::DEFAULTS, array( 'block_themes' => true ) );
		update_site_option( Settings::MODULES, array( 'defaults' => false ) );
		$this->assertFalse( Settings::default_on( 'block_themes' ) );
		delete_site_option( Settings::MODULES );
		$this->assertTrue( Settings::default_on( 'block_themes' ) );
	}
}
