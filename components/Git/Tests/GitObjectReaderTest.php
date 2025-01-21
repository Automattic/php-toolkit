<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\Git\GitObjectProducer;

class GitObjectReaderTest extends TestCase {

    public function testReadHeader() {
        $header = "commit 123\x00";
        $content = $header . gzdeflate("Some commit content", -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));

        $reader->read_header();
        $this->assertEquals('commit', $reader->get_object_type_name());
        $this->assertEquals(123, $reader->get_uncompressed_size());
    }

    public function testNextBytes() {
        $uncompressed = "1234567890";
        $header = "blob " . strlen($uncompressed) . "\x00";
        $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));
        $this->assertTrue($reader->next_bytes(5));
        // Note "5" is max bytes to read, not the exact number of bytes to read
        $this->assertEquals('12', $reader->get_bytes());

        $this->assertTrue($reader->next_bytes(5));
        $this->assertEquals('34567', $reader->get_bytes());
    }

    public function testSeek() {
        $uncompressed = "1234567890";
        $header = "blob " . strlen($uncompressed) . "\x00";
        $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));

        $reader->seek(5);
        $this->assertTrue($reader->next_bytes(5));
        $this->assertEquals('67890', $reader->get_bytes());
    }

    public function testReadEntireObjectContents() {
        $uncompressed = "1234567890";
        $header = "blob " . strlen($uncompressed) . "\x00";
        $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));

        $reader->read_header();
        $this->assertEquals($uncompressed, $reader->read_entire_object_contents());
    }

    public function testAsCommit() {
        $uncompressed = "tree 1234567890\nauthor John Doe <john@example.com> 1234567890 +0000\ncommitter John Doe <john@example.com> 1234567890 +0000\n\nInitial commit";
        $header = "commit " . strlen($uncompressed) . "\x00";
        $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));

        $reader->read_header();
        $commit = $reader->as_commit();
        $this->assertEquals('1234567890', $commit->tree);
    }

    public function testAsTree() {
        $uncompressed = "100644 README.md\x00" . str_repeat('a', 20) . "100644 test.txt\x00" . str_repeat('b', 20);
        $header = "tree " . strlen($uncompressed) . "\x00";
        $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));

        $reader->read_header();
        $tree = $reader->as_tree();
        $entries = array_values($tree->entries);
        $this->assertCount(2, $entries);
        $this->assertEquals('README.md', $entries[0]->name);
        $this->assertEquals('test.txt', $entries[1]->name);
    }

    public function testAsBlob() {
        $uncompressed = "Hello World!";
        $header = "blob " . strlen($uncompressed) . "\x00";
        $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));

        $this->assertEquals($uncompressed, $reader->read_entire_object_contents());
    }

    public function testSeekToBeginning() {
        $uncompressed = "1234567890";
        $header = "blob " . strlen($uncompressed) . "\x00";
        $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));

        $reader->read_header();
        $reader->seek(0);
        $this->assertTrue($reader->next_bytes(5));
        // Note "5" is max bytes to read, not the exact number of bytes to read
        $this->assertEquals('12', $reader->get_bytes());
    }

    public function testSeekToMiddle() {
        $uncompressed = "1234567890";
        $header = "blob " . strlen($uncompressed) . "\x00";
        $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));

        $reader->read_header();
        $reader->seek(5);
        $this->assertTrue($reader->next_bytes(5));
        $this->assertEquals('67890', $reader->get_bytes());
    }

    public function testSeekBeyondEnd() {
        $this->expectException(WordPress\ByteStream\ByteStreamException::class);

        $uncompressed = "1234567890";
        $header = "blob " . strlen($uncompressed) . "\x00";
        $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));

        $reader->read_header();
        $reader->seek(20); // Beyond the end
    }

    public function testSeekBackwards() {
        $uncompressed = "1234567890";
        $header = "blob " . strlen($uncompressed) . "\x00";
        $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
        $reader = new GitObjectProducer(new MemoryPipe($content));

        $reader->read_header();
        $reader->seek(5);
        $this->assertTrue($reader->next_bytes(5));
        $this->assertEquals('67890', $reader->get_bytes());

        $reader->seek(2);
        $this->assertTrue($reader->next_bytes(3));
        $this->assertEquals('345', $reader->get_bytes());
    }

    // public function testLargeBlob() {
    //     $uncompressed = file_get_contents(__DIR__ . '/fixtures/preface-to-pygmalion.txt');
    //     $header = "blob " . strlen($uncompressed) . "\x00";
    //     $content = $header . gzdeflate($uncompressed, -1, ZLIB_ENCODING_DEFLATE);
    //     $reader = new GitObjectReader(new StringReader($content));

    //     $this->assertEquals($uncompressed, $reader->read_entire_object_contents());
    // }
}
