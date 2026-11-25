<?php
/**
 * Windrose Common's Files: read, write and copy through core's direct Filesystem API.
 *
 * @package Windrose\Helm
 */

use Windrose\Common\Files;

/**
 * Files tests.
 */
class FilesTest extends WP_UnitTestCase {

	/**
	 * Temporary folder of the test.
	 *
	 * @var string
	 */
	private string $dir = '';

	public function set_up(): void {
		parent::set_up();
		$this->dir = get_temp_dir() . 'windrose-common-files-' . wp_generate_password( 8, false );
		mkdir( $this->dir );
	}

	public function tear_down(): void {
		array_map( 'unlink', (array) glob( $this->dir . '/*' ) );
		rmdir( $this->dir );
		parent::tear_down();
	}

	public function test_write_read_and_copy(): void {
		$file = $this->dir . '/a.txt';
		$this->assertNull( Files::read( $file ) );
		$this->assertTrue( Files::write( $file, 'first' ) );
		$this->assertSame( 'first', Files::read( $file ) );
		$this->assertNull( Files::read( $this->dir ) );

		// An existing file keeps its permissions.
		chmod( $file, 0600 );
		$this->assertTrue( Files::write( $file, 'second' ) );
		clearstatcache();
		$this->assertSame( 0600, fileperms( $file ) & 0777 );
		$this->assertSame( 'second', Files::read( $file ) );

		// A copy never replaces a file unless asked (a temporary file from wp_tempnam()).
		$copy = $this->dir . '/b.txt';
		$this->assertTrue( Files::copy( $file, $copy ) );
		$this->assertSame( 'second', Files::read( $copy ) );
		Files::write( $file, 'third' );
		$this->assertFalse( Files::copy( $file, $copy ) );
		$this->assertSame( 'second', Files::read( $copy ) );
		$this->assertTrue( Files::copy( $file, $copy, true ) );
		$this->assertSame( 'third', Files::read( $copy ) );
	}
}
