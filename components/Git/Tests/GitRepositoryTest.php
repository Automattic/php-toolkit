<?php

namespace WordPress\Git\Tests;

use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\Tree;
use WordPress\Git\Model\TreeEntry;
use WordPress\Git\Protocol\GitProtocolGenerator;

use function WordPress\Git\get_all_descendant_oids_in_tree;

class GitRepositoryTest extends \PHPUnit\Framework\TestCase {

    public function test_add_new_object() {
        $blob_data = 'Hello, world!';
        $repo = new GitRepository(InMemoryFilesystem::create());
        $writer = $repo->new_object_open_write_stream('blob', strlen($blob_data));
        $writer->append_bytes($blob_data);
        $writer->close();

        $oid = $writer->get_hash();
        $this->assertEquals('5dd01c177f5d7d1be5346a5bc18a569a7410c2ef', $oid);

        $reader = $repo->read_object($oid);
        $reader->next_bytes();
        $this->assertEquals($blob_data, $reader->get_bytes());
    }

    public function test_seek() {
        $blob_data = 'Hello, world!';
        $repo = new GitRepository(InMemoryFilesystem::create());
        $writer = $repo->new_object_open_write_stream('blob', strlen($blob_data));
        $writer->append_bytes($blob_data);
        $writer->close();

        $oid = $writer->get_hash();
        $reader = $repo->read_object($oid);
        $reader->next_bytes();
        $this->assertEquals($blob_data, $reader->get_bytes());

        $reader->seek(5);
        $reader->next_bytes(7);
        $this->assertEquals(', world', $reader->get_bytes());

        $reader->seek(7);
        $reader->next_bytes(5);
        $this->assertEquals('world', $reader->get_bytes());

        $reader->seek(0);
        $reader->next_bytes(10);
        $this->assertEquals('Hello, ', $reader->get_bytes());
    }

    public function test_find_hash_by_path() {
        $repo = new GitRepository(InMemoryFilesystem::create());
        $blob_oid = $repo->add_object('blob', 'Hello, world!');
        $tree_oid = $repo->add_object('tree', GitProtocolGenerator::encode_tree_bytes(new Tree(array(
            new TreeEntry(array(
                'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
                'name' => 'hello-world.txt',
                'hash' => $blob_oid,
            )),
        ))));
        $root_tree_oid = $repo->add_object('tree', GitProtocolGenerator::encode_tree_bytes(new Tree(array(
            new TreeEntry(array(
                'mode' => TreeEntry::FILE_MODE_DIRECTORY,
                'name' => 'subdirectory',
                'hash' => $tree_oid,
            )),
        ))));
        $this->assertEquals($blob_oid, $repo->find_hash_by_path('/subdirectory/hello-world.txt', $root_tree_oid));
    }

    public function test_read_object_by_path() {
        $repo = new GitRepository(InMemoryFilesystem::create());
        $blob_oid = $repo->add_object('blob', 'Hello, world!');
        $tree_oid = $repo->add_object('tree', GitProtocolGenerator::encode_tree_bytes(new Tree(array(
            new TreeEntry(array(
                'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
                'name' => 'hello-world.txt',
                'hash' => $blob_oid,
            )),
        ))));
        $root_tree_oid = $repo->add_object('tree', GitProtocolGenerator::encode_tree_bytes(new Tree(array(
            new TreeEntry(array(
                'mode' => TreeEntry::FILE_MODE_DIRECTORY,
                'name' => 'subdirectory',
                'hash' => $tree_oid,
            )),
        ))));
        $this->assertEquals('Hello, world!', $repo->read_object_by_path('/subdirectory/hello-world.txt', $root_tree_oid)->read_entire_object_contents());
    }

    public function test_get_ref_head() {
        $repo = new GitRepository(InMemoryFilesystem::create());
        $repo->set_ref_head('refs/heads/master', '6a59200c7c2330ebacd7830ea59e63e7c37f9287');
        $repo->set_ref_head('HEAD', 'ref: refs/heads/master');
        $this->assertEquals('6a59200c7c2330ebacd7830ea59e63e7c37f9287', $repo->get_ref_head());
    }

    public function test_commit() {
        $repo = new GitRepository(InMemoryFilesystem::create());
        $repo->set_ref_head('refs/heads/trunk', Commit::NULL_HASH);
        $repo->set_ref_head('HEAD', 'ref: refs/heads/trunk');
        $commit_oid = $repo->commit(array(
            'updates' => array(
                'hello-world.txt' => 'Hello, world!',
            ),
        ));
        $this->assertEquals($commit_oid, $repo->get_ref_head());
    }

    public function test_find_path_descendants() {
        $repo = new GitRepository(InMemoryFilesystem::create());
        $repo->set_ref_head('refs/heads/trunk', Commit::NULL_HASH);
        $repo->set_ref_head('HEAD', 'ref: refs/heads/trunk');
        $commit_oid = $repo->commit(array(
            'updates' => array(
                'subdirectory/hello-world.txt' => 'Hello, world!',
                'subdirectory/README.md' => '# README file',
            ),
        ));
        $tree_oid = $repo->find_hash_by_path('/subdirectory');
        $descendants = get_all_descendant_oids_in_tree($repo, $tree_oid);
        $this->assertCount(2, $descendants);
    }

    public function test_has_object() {
        $repo = new GitRepository(InMemoryFilesystem::create());
        $blob_oid = $repo->add_object('blob', 'Hello, world!');

        $this->assertTrue($repo->has_object($blob_oid));
        $this->assertFalse($repo->has_object('nonexistent'));
    }

    public function test_find_objects_added_in() {
        $repo = new GitRepository(InMemoryFilesystem::create());

        // Create first commit
        $blob1_oid = $repo->add_object('blob', 'First file');
        $tree1_oid = $repo->add_object('tree', GitProtocolGenerator::encode_tree_bytes(new Tree(array(
            new TreeEntry(array(
                'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
                'name' => 'file1.txt',
                'hash' => $blob1_oid,
            )),
        ))));
        $commit1_oid = $repo->add_object('commit', "tree $tree1_oid\n\nFirst commit");

        // Create second commit
        $blob2_oid = $repo->add_object('blob', 'Second file');
        $tree2_oid = $repo->add_object('tree', GitProtocolGenerator::encode_tree_bytes(new Tree(array(
            new TreeEntry(array(
                'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
                'name' => 'file1.txt',
                'hash' => $blob1_oid,
            )),
            new TreeEntry(array(
                'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
                'name' => 'file2.txt',
                'hash' => $blob2_oid,
            )),
        ))));
        $commit2_oid = $repo->add_object('commit', "tree $tree2_oid\nparent $commit1_oid\n\nSecond commit");

        // Test finding new objects between commits
        $new_objects = $repo->find_objects_added_in($commit2_oid, $commit1_oid);
        $this->assertCount(3, $new_objects);
        $this->assertContains($commit2_oid, $new_objects);
        $this->assertContains($tree2_oid, $new_objects);
        $this->assertContains($blob2_oid, $new_objects);
    }

}
