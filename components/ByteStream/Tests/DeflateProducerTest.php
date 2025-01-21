<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\Producer\DeflateProducer;
use WordPress\ByteStream\Producer\InflateProducer;

class DeflateProducerTest extends TestCase {

    public function testDeflateReaderNextBytesWithSeek() {
        $text = file_get_contents(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $stringReader = new MemoryPipe($text);
        $deflateReader = new DeflateProducer($stringReader, ZLIB_ENCODING_DEFLATE);

        $inflateReader = new InflateProducer($deflateReader);

        $inflateReader->seek(998);
        $this->assertTrue($inflateReader->next_bytes(40));
        $this->assertEquals('apologize to public meetings in a very c', $inflateReader->get_bytes());

        $inflateReader->seek(0);
        $this->assertTrue($inflateReader->next_bytes(21));
        $this->assertStringStartsWith('PREFACE TO PYGMALI', $inflateReader->get_bytes());

        $inflateReader->seek(200);
        $this->assertTrue($inflateReader->next_bytes(10));
        $this->assertEquals('language, ', $inflateReader->get_bytes());

        $inflateReader->seek(10);
        $this->assertTrue($inflateReader->next_bytes(10));
        $this->assertEquals(' PYGMALIO', $inflateReader->get_bytes());
    }

    public function testDeflateReaderEndOfData() {
        $pygmalionText = file_get_contents(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $stringReader = new MemoryPipe($pygmalionText);
        $deflateReader = new DeflateProducer($stringReader, ZLIB_ENCODING_DEFLATE);
        $inflateReader = new InflateProducer($deflateReader);

        $text = '';
        while ($inflateReader->next_bytes()) {
            $text .= $inflateReader->get_bytes();
        }

        $this->assertEquals($pygmalionText, $text);
        $this->assertTrue($deflateReader->reached_end_of_data());
    }

    public function testDeflateReaderClose() {
        $text = file_get_contents(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $stringReader = new MemoryPipe($text);
        $deflateReader = new DeflateProducer($stringReader);

        $deflateReader->next_bytes();
        $deflateReader->close();
        $this->assertFalse($deflateReader->next_bytes());
    }
}
