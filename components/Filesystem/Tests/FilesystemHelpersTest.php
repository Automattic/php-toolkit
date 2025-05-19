<?php

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Filesystem\InMemoryFilesystem;

class FilesystemHelpersTest extends TestCase {
	
	/**
     * @var \WordPress\Filesystem\Filesystem
     */
    protected $fs;
	
	protected function setUp(): void {
		$this->fs = InMemoryFilesystem::create();
	}
	
	public function testTouch() {
		// Test creating a new file
		FilesystemHelpers::touch($this->fs, '/test-touch.txt');
		$this->assertTrue($this->fs->exists('/test-touch.txt'));
		$this->assertTrue($this->fs->is_file('/test-touch.txt'));
		$this->assertEquals('', $this->fs->get_contents('/test-touch.txt'));
		
		// Test touching an existing file
		$this->fs->put_contents('/existing-file.txt', 'original content');
		FilesystemHelpers::touch($this->fs, '/existing-file.txt');
		$this->assertTrue($this->fs->exists('/existing-file.txt'));
		$this->assertEquals('original content', $this->fs->get_contents('/existing-file.txt'));
		
		// Test touching an existing directory
		$this->fs->mkdir('/test-dir');
		FilesystemHelpers::touch($this->fs, '/test-dir');
		$this->assertTrue($this->fs->exists('/test-dir'));
		$this->assertTrue($this->fs->is_dir('/test-dir'));
	}
	
} 