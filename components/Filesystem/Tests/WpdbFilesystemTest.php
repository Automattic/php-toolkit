<?php

use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\WpdbFilesystem;

require_once __DIR__ . '/FilesystemTestCase.php';
require_once __DIR__ . '/FakeWpdb.php';

class WpdbFilesystemTest extends FilesystemTestCase {

	/**
	 * @var FakeWpdb
	 */
	private $wpdb;

	protected function create_fs(): Filesystem {
		$this->wpdb = new FakeWpdb();

		return WpdbFilesystem::create( $this->wpdb, 'wp_origin_' );
	}

	public function testRoundTripsBinaryContents() {
		$binary = '';
		for ( $i = 0; $i < 256; $i++ ) {
			$binary .= chr( $i );
		}
		$binary .= "\x00\x01\x02ZLIB\x9c";

		$this->fs->put_contents( '/blob.bin', $binary );

		$this->assertSame( $binary, $this->fs->get_contents( '/blob.bin' ) );
	}

	public function testOpenWriteStreamRoundTripsBinaryContents() {
		$binary = '';
		for ( $i = 0; $i < 256; $i++ ) {
			$binary .= chr( $i );
		}

		$writer = $this->fs->open_write_stream( '/streamed.bin' );
		$writer->append_bytes( substr( $binary, 0, 128 ) );
		$writer->append_bytes( substr( $binary, 128 ) );
		$writer->close_writing();

		$this->assertSame( $binary, $this->fs->get_contents( '/streamed.bin' ) );
	}

	public function testReplacingFileContentsOverwritesBytes() {
		$this->fs->put_contents( '/object', 'first' );
		$this->fs->put_contents( '/object', 'second' );

		$this->assertSame( 'second', $this->fs->get_contents( '/object' ) );
	}

	public function testNestedDirectoriesPersistInTables() {
		$this->fs->mkdir( '/objects/ab', array( 'recursive' => true ) );
		$this->fs->put_contents( '/objects/ab/cdef', 'object-bytes' );

		$entries = $this->fs->ls( '/objects/ab' );
		sort( $entries );

		$this->assertSame( array( 'cdef' ), $entries );
		$this->assertSame( 'object-bytes', $this->fs->get_contents( '/objects/ab/cdef' ) );
	}

	public function testSecondInstanceReadsExistingTables() {
		$this->fs->put_contents( '/HEAD', 'ref: refs/heads/trunk' );

		// A second WpdbFilesystem pointed at the same wpdb tables must
		// see what the first one wrote — this is the property the plugin
		// relies on across requests.
		$reopened = WpdbFilesystem::create( $this->wpdb, 'wp_origin_' );

		$this->assertTrue( $reopened->is_file( '/HEAD' ) );
		$this->assertSame( 'ref: refs/heads/trunk', $reopened->get_contents( '/HEAD' ) );
	}

	public function testGetMetaExposesTableNames() {
		$meta = $this->fs->get_meta();

		$this->assertSame( 'wp_origin_files', $meta['files_table'] );
		$this->assertSame( 'wp_origin_directory_entries', $meta['entries_table'] );
	}
}
