<?php

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Filesystem\InMemoryFilesystem;

class FilesystemHelpersTest extends TestCase {
	
	protected Filesystem $fs;
	
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
	
	public function testWithTemporaryFile() {
		$callbackExecuted = false;
		$tempFilePath = null;
		
		// Test temporary file creation and cleanup
		$result = FilesystemHelpers::withTemporaryFile($this->fs, function($path) use (&$callbackExecuted, &$tempFilePath) {
			$callbackExecuted = true;
			$tempFilePath = $path;
			
			// Verify the temporary file exists within the callback
			$this->assertTrue($this->fs->exists($path));
			$this->assertTrue($this->fs->is_file($path));
			
			// Test writing to the temporary file
			$this->fs->put_contents($path, 'test content');
			$this->assertEquals('test content', $this->fs->get_contents($path));
			
			return 'callback result';
		});
		
		// Verify the callback was executed
		$this->assertTrue($callbackExecuted);
		
		// Verify the temporary file was cleaned up
		$this->assertFalse($this->fs->exists($tempFilePath));
		
		// Verify the callback result was returned
		$this->assertEquals('callback result', $result);
	}
	
	public function testWithTemporaryFileException() {
		$tempFilePath = null;
		
		// Test temporary file cleanup even when an exception is thrown
		try {
			FilesystemHelpers::withTemporaryFile($this->fs, function($path) use (&$tempFilePath) {
				$tempFilePath = $path;
				$this->assertTrue($this->fs->exists($path));
				
				throw new \Exception('Test exception');
			});
			
			$this->fail('Expected exception was not thrown');
		} catch (\Exception $e) {
			$this->assertEquals('Test exception', $e->getMessage());
		}
		
		// Verify the temporary file was cleaned up despite the exception
		$this->assertFalse($this->fs->exists($tempFilePath));
	}
	
	public function testWithTemporaryDirectory() {
		$callbackExecuted = false;
		$tempDirPath = null;
		
		// Test temporary directory creation and cleanup
		$result = FilesystemHelpers::withTemporaryDirectory($this->fs, function($path) use (&$callbackExecuted, &$tempDirPath) {
			$callbackExecuted = true;
			$tempDirPath = $path;
			
			// Verify the temporary directory exists within the callback
			$this->assertTrue($this->fs->exists($path));
			$this->assertTrue($this->fs->is_dir($path));
			
			// Test creating files and subdirectories in the temporary directory
			$this->fs->put_contents($path . '/test.txt', 'test content');
			$this->fs->mkdir($path . '/subdir');
			$this->fs->put_contents($path . '/subdir/test2.txt', 'more content');
			
			$this->assertTrue($this->fs->exists($path . '/test.txt'));
			$this->assertTrue($this->fs->exists($path . '/subdir'));
			$this->assertTrue($this->fs->exists($path . '/subdir/test2.txt'));
			
			return 'callback result';
		});
		
		// Verify the callback was executed
		$this->assertTrue($callbackExecuted);
		
		// Verify the temporary directory and its contents were cleaned up
		$this->assertFalse($this->fs->exists($tempDirPath));
		$this->assertFalse($this->fs->exists($tempDirPath . '/test.txt'));
		$this->assertFalse($this->fs->exists($tempDirPath . '/subdir'));
		
		// Verify the callback result was returned
		$this->assertEquals('callback result', $result);
	}
	
	public function testWithTemporaryDirectoryException() {
		$tempDirPath = null;
		
		// Test temporary directory cleanup even when an exception is thrown
		try {
			FilesystemHelpers::withTemporaryDirectory($this->fs, function($path) use (&$tempDirPath) {
				$tempDirPath = $path;
				
				// Create some content in the directory to verify it's all cleaned up
				$this->fs->put_contents($path . '/test.txt', 'test content');
				$this->fs->mkdir($path . '/subdir', ['recursive' => true]);
				
				throw new \Exception('Test exception');
			});
			
			$this->fail('Expected exception was not thrown');
		} catch (\Exception $e) {
			$this->assertEquals('Test exception', $e->getMessage());
		}
		
		// Verify the temporary directory was cleaned up despite the exception
		$this->assertFalse($this->fs->exists($tempDirPath));
	}
	
	public function testCreateTmpDirectoryIfNotExists() {
		// Test creating tmp directory if it doesn't exist
		$this->assertFalse($this->fs->exists('/tmp'));
		
		FilesystemHelpers::withTemporaryFile($this->fs, function($path) {
			// Just verify the tmp directory exists now
			$this->assertTrue($this->fs->exists('/tmp'));
			return null;
		});
		
		// Same test for withTemporaryDirectory
		$this->fs->rmdir('/tmp', ['recursive' => true]);
		$this->assertFalse($this->fs->exists('/tmp'));
		
		FilesystemHelpers::withTemporaryDirectory($this->fs, function($path) {
			$this->assertTrue($this->fs->exists('/tmp'));
			return null;
		});
	}
} 