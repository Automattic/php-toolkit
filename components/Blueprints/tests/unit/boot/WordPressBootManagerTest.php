<?php

namespace unit\boot;

use PHPUnitTestCase;
use WordPress\Blueprints\Runner\WordPressBoot\BootOptions;
use WordPress\Blueprints\Runner\WordPressBoot\WordPressBootManager;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_paths;

class WordPressBootManagerTest extends PHPUnitTestCase {

	/**
	 * @var string
	 */
	private $document_root;

	/**
	 * @before
	 */
	public function setUp(): void {
		$this->document_root = wp_join_paths( sys_get_temp_dir(), 'test_wp_boot_' . uniqid() );
		if ( ! is_dir( $this->document_root ) ) {
			mkdir( $this->document_root, 0777, true );
		}
	}

	/**
	 * @after
	 */
	public function tearDown(): void {
		// Clean up temp directory
		if ( is_dir( $this->document_root ) ) {
			$this->removeDirectory( $this->document_root );
		}
	}

	private function removeDirectory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( $object == "." || $object == ".." ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $object;
			if ( is_dir( $path ) ) {
				$this->removeDirectory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}


	public function testBootCreatesWordPressSite(): void {
		$options = BootOptions::parse( [
			'siteUrl'      => 'https://example.com',
			'documentRoot' => $this->document_root,
		] );

		// @TODO: Expose progress information
		WordPressBootManager::boot( $options );

		$wp_filesystem = LocalFilesystem::create( $this->document_root );
		$this->assertTrue( $wp_filesystem->exists( 'wp-load.php' ), 'WordPress core file should be extracted' );
		$this->assertTrue( $wp_filesystem->exists( 'wp-content/plugins/sqlite-database-integration/' ),
			'SQLite plugin file should be extracted' );
		$this->assertTrue( $wp_filesystem->exists( 'wp-content/db.php' ), 'SQLite db.php drop-in should be copied to wp-content' );

		$runtime      = new Runtime( $this->document_root );
		$installCheck = $runtime->evalPhpInSubProcess(
			<<<'PHP'
			<?php
			$wp_load = getenv('DOCROOT') . '/wp-load.php';
			if (!file_exists($wp_load)) {
				echo '0';
				exit;
			}
			require $wp_load;
			
			echo function_exists('is_blog_installed') && is_blog_installed() ? '1' : '0';
			PHP
		);
		$this->assertEquals( '1', $installCheck, 'WordPress is not correctly installed' );
	}
}
