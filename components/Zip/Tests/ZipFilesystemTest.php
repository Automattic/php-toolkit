<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\Zip\ZipFilesystem;

class ZipFilesystemTest extends TestCase {

	/**
	 * @var ZipFilesystem
	 */
	private $fs;

	protected function setUp(): void {
		$this->fs = ZipFilesystem::create( FileReadStream::from_path( __DIR__ . '/fixtures/childrens-literature.zip' ) );
	}

	public function testLs() {
		$this->assertEquals(
			array(
				'mimetype',
				'EPUB',
				'META-INF',
			),
			$this->fs->ls()
		);

		$this->assertEquals(
			array(
				'cover.xhtml',
				'css',
				'images',
				'nav.xhtml',
				'package.opf',
				's04.xhtml',
				'toc.ncx',
			),
			$this->fs->ls( '/EPUB' )
		);
	}

	public function testReadFile() {
		$this->assertEquals( 'application/epub+zip', $this->fs->get_contents( 'mimetype' ) );

		$cover = $this->fs->get_contents( 'EPUB/cover.xhtml' );
		$this->assertStringStartsWith( '<?xml version="1.0" encoding="UTF-8"?>', $cover );

		// Read the mimetype file again to ensure ZIPFilesystem can move back and forth between files.
		$mimetype = $this->fs->get_contents( 'mimetype' );
		$this->assertEquals( 'application/epub+zip', $mimetype );
	}
}
