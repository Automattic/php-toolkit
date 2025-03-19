<?php

namespace WordPress\Sync\Tests;

use WordPress\Sync\RecursiveDirectorySeeker;

/**
 * Test class for RecursiveDirectorySeeker
 */
class RecursiveDirectorySeekerTest extends \PHPUnit\Framework\TestCase {
	private $fixturesPath;
	
	/**
	 * Set up test environment
	 */
	protected function setUp(): void {
		parent::setUp();
		
		// Set up fixtures path
		$this->fixturesPath = __DIR__ . '/fixtures/test-directory';
	}
	
	/**
	 * Test the next_path method to ensure it correctly traverses all files and directories.
	 */
	public function testNextPath() {
		$seeker = new RecursiveDirectorySeeker($this->fixturesPath);
		
		// Collect all paths found by the seeker
		$foundPaths = [];
		while ($seeker->next_path()) {
			$foundPaths[] = $seeker->get_current_path();
		}
		
		// We should have found 9 paths (4 directories and 5 files)
		$this->assertCount(9, $foundPaths);
		
		// Check that all expected paths are found
		$expectedPaths = [
			$this->fixturesPath . '/file1.txt',
			$this->fixturesPath . '/level1',
			$this->fixturesPath . '/level1/file2.txt',
			$this->fixturesPath . '/level1/level2',
			$this->fixturesPath . '/level1/level2/file3.txt',
			$this->fixturesPath . '/level1/level2/level3',
			$this->fixturesPath . '/level1/level2/level3/file4.txt',
			$this->fixturesPath . '/level1/level2b',
			$this->fixturesPath . '/level1/level2b/file5.txt',
		];
		
		// The order might be different, so we sort both arrays
		sort($expectedPaths);
		sort($foundPaths);
		
		// Assert that all expected paths are found
		$this->assertEquals($expectedPaths, $foundPaths);
	}
	
	/**
	 * Test the seek_to_closest_matching_prefix method.
	 */
	public function testSeekToClosestMatchingPrefix() {
		$seeker = new RecursiveDirectorySeeker($this->fixturesPath);
		
		// Seek to an existing file
		$seeker->seek_to_closest_matching_prefix('level1/level2/file3.txt');
		$this->assertEquals($this->fixturesPath . '/level1/level2/file3.txt', $seeker->get_current_path());
		
		// Seek to a directory
		$seeker->seek_to_closest_matching_prefix('level1/level2b');
		$this->assertEquals($this->fixturesPath . '/level1/level2b', $seeker->get_current_path());
		
		// Seek to a non-existent path (should find closest ancestor)
		$seeker->seek_to_closest_matching_prefix('level1/nonexistent/path');
		$this->assertEquals($this->fixturesPath . '/level1', $seeker->get_current_path());
		
		// Try a completely nonexistent path
		$seeker->seek_to_closest_matching_prefix('completely/nonexistent/path');
		$this->assertEquals($this->fixturesPath, $seeker->get_current_path());

		// Seek to the root
		$seeker->seek_to_closest_matching_prefix('');
		$this->assertEquals($this->fixturesPath, $seeker->get_current_path());
	}
	
	/**
	 * Test the seek_to_closest_matching_prefix method.
	 */
	public function testSeekAndNext() {
		$seeker = new RecursiveDirectorySeeker($this->fixturesPath);
		// Seek to an existing file
		$seeker->seek_to_closest_matching_prefix('level1/level2/level3');
		$this->assertEquals($this->fixturesPath . '/level1/level2/level3', $seeker->get_current_path());

		$seeker->next_path();
		$this->assertEquals($this->fixturesPath . '/level1/level2/level3/file4.txt', $seeker->get_current_path());

		$seeker->next_path();
		$this->assertEquals($this->fixturesPath . '/level1/level2b', $seeker->get_current_path());

		$seeker->next_path();
		$this->assertEquals($this->fixturesPath . '/level1/level2b/file5.txt', $seeker->get_current_path());

		$seeker->next_path();
		$this->assertEquals($this->fixturesPath . '/file1.txt', $seeker->get_current_path());
	}
	
	/**
	 * Test the RecursiveDirectorySeeker with static fixtures
	 */
	public function testWithFixtures() {
		// Ensure fixtures directory exists
		
		$seeker = new RecursiveDirectorySeeker($this->fixturesPath);
		
		// Test if we can find the deepest file
		$seeker->seek_to_closest_matching_prefix('level1/level2/level3/file4.txt');
		$this->assertEquals($this->fixturesPath . '/level1/level2/level3/file4.txt', $seeker->get_current_path());
		
		// Reset and traverse the entire directory
		$seeker = new RecursiveDirectorySeeker($this->fixturesPath);
		
		// Count the number of files and directories traversed
		$fileCount = 0;
		$dirCount = 0;
		
		while ($seeker->next_path()) {
			$path = $seeker->get_current_path();
			if (is_file($path)) {
				$fileCount++;
			} elseif (is_dir($path)) {
				$dirCount++;
			}
		}
		
		// We should have 5 files and 4 directories (same as the dynamic test structure)
		$this->assertEquals(5, $fileCount, 'Incorrect number of files found');
		$this->assertEquals(4, $dirCount, 'Incorrect number of directories found');
	}
} 