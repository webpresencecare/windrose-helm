<?php
/**
 * Windrose Common: the newest bundled copy is loaded once, whatever the loading order.
 *
 * @package Windrose\Helm
 */

/**
 * Bootstrap tests (each run in its own PHP process, outside WordPress).
 */
class CommonBootstrapTest extends WP_UnitTestCase {

	/** @var list<string> */
	private array $dirs = array();

	public function tear_down(): void {
		foreach ( $this->dirs as $dir ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
			}
			rmdir( $dir );
		}
		parent::tear_down();
	}

	/**
	 * A copy of common/ with another version line.
	 */
	private function copy_with_version( string $version ): string {
		$dir = get_temp_dir() . 'windrose-common-' . wp_generate_password( 8, false ) . '/common';
		mkdir( $dir . '/includes', 0777, true );
		$this->dirs[] = dirname( $dir );
		$source       = dirname( __DIR__, 2 ) . '/common';
		copy( $source . '/load.php', $dir . '/load.php' );
		copy( $source . '/includes/class-updater.php', $dir . '/includes/class-updater.php' );
		$bootstrap = (string) file_get_contents( $source . '/bootstrap.php' );
		$changed   = preg_replace( "/(\\[ __DIR__ \\] = ')[0-9.]+(';)/", '${1}' . $version . '${2}', $bootstrap, -1, $count );
		$this->assertSame( 1, $count, 'The version line was found.' );
		file_put_contents( $dir . '/bootstrap.php', $changed );
		return $dir;
	}

	/**
	 * @return list<string> Version, folder, number of windrose_common_loaded actions.
	 */
	private function run_loader( string ...$dirs ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/common-loader.php' );
		foreach ( $dirs as $dir ) {
			$command .= ' ' . escapeshellarg( $dir );
		}
		exec( $command . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		return $output;
	}

	public function test_older_copy_loaded_first_newest_wins(): void {
		$old = $this->copy_with_version( '1.1.0' );
		$new = $this->copy_with_version( '1.2.0' );
		$this->assertSame( array( '1.2.0', $new, '1' ), $this->run_loader( $old, $new ) );
	}

	public function test_newer_copy_loaded_first_newest_wins(): void {
		$new = $this->copy_with_version( '1.10.0' );
		$old = $this->copy_with_version( '1.9.0' );
		$this->assertSame( array( '1.10.0', $new, '1' ), $this->run_loader( $new, $old ) );
	}

	public function test_single_copy_loads_itself(): void {
		$only = $this->copy_with_version( '1.0.0' );
		$this->assertSame( array( '1.0.0', $only, '1' ), $this->run_loader( $only ) );
	}

	public function test_loaded_in_this_wordpress(): void {
		$this->assertTrue( defined( 'WINDROSE_COMMON_VERSION' ) );
		$this->assertTrue( class_exists( Windrose\Common\Updater::class, false ) );
		$this->assertSame( 1, did_action( 'windrose_common_loaded' ) );
	}
}
