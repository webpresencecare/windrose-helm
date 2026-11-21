<?php
/**
 * Site Health tests.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Health;
use Windrose\Helm\Settings;

/**
 * Health tests.
 */
class HealthTest extends WP_UnitTestCase {

	private function tests(): array {
		return Health::register(
			array(
				'direct' => array(
					'php_version' => array(
						'label' => 'PHP',
						'test'  => 'core',
					),
				),
				'async'  => array( 'dotorg_communication' => array( 'label' => 'wp.org' ) ),
			)
		);
	}

	public function test_tests_registered(): void {
		$tests = $this->tests();
		foreach ( array( 'windrose_helm_block_theme', 'windrose_helm_classic_editor', 'windrose_helm_page_builder', 'windrose_helm_windrose_updates' ) as $id ) {
			$this->assertArrayHasKey( $id, $tests['direct'] );
		}
		$this->assertArrayNotHasKey( 'windrose_helm_wporg', $tests['direct'] );
		$this->assertArrayHasKey( 'dotorg_communication', $tests['async'] );
		$this->assertSame( array( Health::class, 'test_php_version' ), $tests['direct']['php_version']['test'], 'Serve Happy is off, so the PHP test is replaced.' );
	}

	public function test_wporg_off_says_updates_are_manual(): void {
		Settings::update( array( 'wporg' => false ) );
		$tests = $this->tests();
		$this->assertArrayNotHasKey( 'dotorg_communication', $tests['async'] );
		$result = Health::test_wporg();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'Updates are manual', $result['label'] );
	}

	public function test_block_theme_check(): void {
		switch_theme( 'twentytwentyfive' );
		$this->assertSame( 'good', Health::test_block_theme()['status'] );
		switch_theme( 'twentytwentyone' );
		$this->assertSame( 'recommended', Health::test_block_theme()['status'] );
	}

	public function test_page_builder_and_classic_editor_checks(): void {
		$this->assertSame( 'good', Health::test_page_builder()['status'] );
		update_option( 'active_plugins', array( 'elementor/elementor.php', 'classic-editor/classic-editor.php' ) );
		$this->assertSame( 'recommended', Health::test_page_builder()['status'] );
		$this->assertStringContainsString( 'Elementor', Health::test_page_builder()['description'] );
		$this->assertSame( 'recommended', Health::test_classic_editor()['status'] );
	}

	public function test_php_version_without_serve_happy(): void {
		$result = Health::test_php_version();
		$this->assertSame( 'php_version', $result['test'] );
		$this->assertSame( 'good', $result['status'] );
	}

	public function test_results_have_core_shape(): void {
		foreach ( array( Health::test_block_theme(), Health::test_page_builder(), Health::test_classic_editor(), Health::test_windrose_updates() ) as $result ) {
			$this->assertSame( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ), array_keys( $result ) );
			$this->assertContains( $result['status'], array( 'good', 'recommended', 'critical' ) );
		}
	}
}
