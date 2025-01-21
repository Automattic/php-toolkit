<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\Producer\InflateProducer;

class InflateProducerTest extends TestCase {

    public function testInflateReaderNextBytesWithSeek() {
        $text = file_get_contents(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $compressedData = gzdeflate($text, -1, ZLIB_ENCODING_DEFLATE);
        $stringReader = new MemoryPipe($compressedData);
        $inflateReader = new InflateProducer($stringReader);

        $inflateReader->seek(998);
        $this->assertTrue($inflateReader->next_bytes(40));
        $this->assertEquals('apologize to public meetings in a very c', $inflateReader->get_bytes());

        $inflateReader->seek(0);
        $this->assertTrue($inflateReader->next_bytes(21));
        $this->assertEquals('PREFACE TO PYGMALIO', $inflateReader->get_bytes());

        $inflateReader->seek(200);
        $this->assertTrue($inflateReader->next_bytes(10));
        $this->assertEquals('language, ', $inflateReader->get_bytes());

        $inflateReader->seek(10);
        $this->assertTrue($inflateReader->next_bytes(10));
        $this->assertEquals(' PYGMALIO', $inflateReader->get_bytes());
    }

    public function testPartialInflateWithSeek() {
        $text = file_get_contents(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $header = "blob " . strlen($text) . "\x00";
        $object = $header . gzdeflate($text, -1, ZLIB_ENCODING_DEFLATE);

        $stringReader = new MemoryPipe($object);
        $inflateReader = new InflateProducer($stringReader);

        $this->assertTrue($stringReader->next_bytes(strlen($header)));
        $this->assertEquals($header, $stringReader->get_bytes());

        $this->assertTrue($inflateReader->next_bytes(21));
        // This is only 19 bytes. Note next_bytes() returns **at most** $max_bytes,
        // not **exactly** $max_bytes. This is fine.
        $this->assertEquals('PREFACE TO PYGMALIO', $inflateReader->get_bytes());

        $this->assertTrue($inflateReader->next_bytes(21));
        $this->assertEquals("N.\n\nA Professor of Ph", $inflateReader->get_bytes());

        // Now let's seek back to offset 0 and confirm we get the same result.
        $inflateReader->seek(0);
        $this->assertTrue($inflateReader->next_bytes(21));
        $this->assertEquals('PREFACE TO PYGMALIO', $inflateReader->get_bytes());
    }

}
