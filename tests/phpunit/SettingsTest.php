<?php
/**
 * Settings.
 *
 * @package Windrose\Helm
 */

use Windrose\Helm\Settings;

/**
 * Settings tests.
 */
class SettingsTest extends WP_UnitTestCase {

	public function test_everything_on_by_default(): void {
		$all = Settings::all();
		$this->assertNotContains( false, $all['modules'] );
		$this->assertNotContains( false, $all['privacy'] );
		$this->assertCount( 15, $all['privacy'] );
		$this->assertTrue( $all['wporg'] );
		$this->assertTrue( Settings::wporg_on() );
	}

	public function test_update_accepts_known_booleans_only(): void {
		Settings::update(
			array(
				'privacy' => array(
					'gravatar' => false,
					'emoji'    => 'no',
					'unknown'  => false,
				),
				'wporg'   => false,
				'other'   => array( 'x' => true ),
			)
		);
		$privacy = Settings::privacy();
		$this->assertFalse( $privacy['gravatar'] );
		$this->assertTrue( $privacy['emoji'] );
		$this->assertArrayNotHasKey( 'unknown', $privacy );
		$this->assertFalse( Settings::wporg_on() );
		$this->assertNull( get_site_option( 'other', null ) );
	}

	public function test_wporg_always_on_when_updates_module_off(): void {
		Settings::update(
			array(
				'wporg'   => false,
				'modules' => array( 'updates' => false ),
			)
		);
		$this->assertTrue( Settings::wporg_on() );
		$this->assertFalse( Settings::all()['wporg'], 'The stored choice is kept for when the module comes back.' );
	}

	public function test_privacy_items_off_when_module_off(): void {
		Settings::update( array( 'modules' => array( 'privacy' => false ) ) );
		$this->assertFalse( Settings::privacy_on( 'gravatar' ) );
		$this->assertTrue( Settings::privacy()['gravatar'] );
	}

	public function test_network_wide(): void {
		Settings::update( array( 'privacy' => array( 'emoji' => false ) ) );
		switch_to_blog( self::factory()->blog->create() );
		$this->assertFalse( Settings::privacy()['emoji'] );
		restore_current_blog();
	}
}
