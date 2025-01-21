<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\Producer\ReaderUtils;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitObjectConsumer;
use WordPress\Git\GitRepository;

class GitObjectWriterTest extends TestCase {

    public function testWriteBlob() {
        $blob_content = file_get_contents(__DIR__ . '/fixtures/preface-to-pygmalion.txt');
        $repo = new GitRepository(InMemoryFilesystem::create());
        $writer = new GitObjectConsumer($repo, 'blob', strlen($blob_content));
        $writer->append_bytes($blob_content);
        $writer->close();

        $hash = $writer->get_hash();
        $this->assertEquals('c19c9dbac694fb04a30b0ed9741694ca0cfca0e6', $hash);
        $reader = $repo->read_object($hash);
        $read_content = ReaderUtils::read_all_remaining_bytes($reader);
        $this->assertEquals($blob_content, $read_content);
    }

}
