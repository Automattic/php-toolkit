<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\InMemoryFilesystem as FilesystemInMemoryFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;

class GitFilesystemTest extends TestCase {

	private $repo;
	private $fs;
	private $head_oid;

	public function setUp(): void {
		parent::setUp();
		$this->repo = new GitRepository( FilesystemInMemoryFilesystem::create() );
		$this->repo->set_ref_head( 'refs/heads/trunk', Commit::NULL_HASH );
		$this->repo->set_ref_head( 'HEAD', 'ref: refs/heads/trunk' );
		$this->head_oid = $this->repo->commit(
			array(
				'updates' => array(
					'README.md' => 'Hello, world!',
					'subdirectory/hello-world.txt' => 'Hello, world!',
					'subdirectory/script.js' => 'console.log("Hello, world!");',
				),
			)
		);
		$this->fs       = GitFilesystem::create( $this->repo );
	}

	public function test_ls_root() {
		$this->assertEquals(
			array(
				'README.md',
				'subdirectory',
			),
			$this->fs->ls( '/' )
		);
	}

	public function test_ls_subdirectory() {
		$this->assertEquals(
			array(
				'hello-world.txt',
				'script.js',
			),
			$this->fs->ls( '/subdirectory' )
		);
	}
}
