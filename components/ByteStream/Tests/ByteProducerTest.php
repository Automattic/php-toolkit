<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\Producer\ByteProducer;
use WordPress\ByteStream\Producer\DeflateProducer;
use WordPress\ByteStream\Producer\ResourceProducer;
use WordPress\ByteStream\Producer\InflateProducer;

class ByteProducerTest extends TestCase {

    /**
     * Data provider for ByteReader implementations.
     */
    public function byteReaderProvider() {
        $text = file_get_contents(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $compressedData = gzdeflate($text, -1, ZLIB_ENCODING_DEFLATE);

        return [
            'ResourceReader' => [ResourceProducer::from_local_file( dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt'), strlen($text)],
            'StringReader' => [new MemoryPipe($text), strlen($text)],
            'InflateReader' => [new InflateProducer(new MemoryPipe($compressedData)), strlen($text)],
            'DeflateReader' => [new DeflateProducer(new MemoryPipe($text)), strlen($text)],
        ];
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testLength(ByteProducer $reader, int $length) {
        if( $reader instanceof InflateProducer || $reader instanceof DeflateProducer) {
            $this->assertNull($reader->length());
        } else {
            $this->assertEquals($length, $reader->length());
        }
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testTell(ByteProducer $reader) {
        $this->assertEquals(0, $reader->tell());
        $reader->pull(10, ByteProducer::PULL_EXACTLY);
        $reader->consume(10);
        $this->assertGreaterThan(0, $reader->tell());
        $this->assertLessThanOrEqual(10, $reader->tell());
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testSeek(ByteProducer $reader) {
        $this->assertEquals(0, $reader->tell());
        $reader->seek(10);
        $this->assertEquals(10, $reader->tell());
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testNextBytesWithMaxBytes(ByteProducer $reader) {
        if( $reader instanceof DeflateProducer) {
            $reader = new InflateProducer($reader);
        }

        $this->assertEquals(0, $reader->tell());
        $reader->pull(10, ByteProducer::PULL_EXACTLY);
        $this->assertStringStartsWith('PREFAC', $reader->peek(10));
        $this->assertLessThanOrEqual(10, strlen($reader->peek(10)));

        $reader->seek(10);
        $this->assertEquals(10, $reader->tell());
        $reader->pull(10, ByteProducer::PULL_EXACTLY);
        $this->assertStringStartsWith(' PYGMALIO', $reader->peek(10));
        $this->assertLessThanOrEqual(10, strlen($reader->peek(10)));
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testNextBytesWithSeek(ByteProducer $reader) {
        if( $reader instanceof DeflateProducer) {
            $reader = new InflateProducer($reader);
        }

        $this->assertEquals(0, $reader->tell());
        $reader->seek(998);
        $this->assertEquals(998, $reader->tell());
        $reader->pull(40, ByteProducer::PULL_EXACTLY);
        $this->assertEquals('apologize to public meetings in a very c', $reader->peek(40));

        $reader->seek(0);
        $this->assertEquals(0, $reader->tell());
        $reader->pull(21, ByteProducer::PULL_EXACTLY);
        // Note the argument 21 means $max_bytes, not $exactly_bytes. Some readers,
        // like the InflateReader, often return less data than requested.
        $this->assertStringStartsWith('PREFACE TO PYGMALI', $reader->peek(21));
        $this->assertLessThanOrEqual(21, strlen($reader->peek(21)));

        $reader->seek(200);
        $this->assertEquals(200, $reader->tell());
        $reader->pull(10, ByteProducer::PULL_EXACTLY);
        $this->assertEquals('language, ', $reader->peek(10));
        $this->assertLessThanOrEqual(10, strlen($reader->peek(10)));

        $reader->seek(10);
        $this->assertEquals(10, $reader->tell());
        $reader->pull(10, ByteProducer::PULL_EXACTLY);
        $this->assertStringStartsWith(' PYGMALIO', $reader->peek(10));
        $this->assertLessThanOrEqual(10, strlen($reader->peek(10)));
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testClose(ByteProducer $reader) {
        $this->assertEquals(0, $reader->tell());
        $reader->pull(1024, ByteProducer::PULL_EXACTLY);
        $reader->close();

        $this->expectException(ByteStreamException::class);
        $reader->pull(1024);
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testIsFinished(ByteProducer $reader) {
        $this->assertEquals(0, $reader->tell());
        $this->assertFalse($reader->reached_end_of_data());

        while(!$reader->reached_end_of_data()) {
            $pulled = $reader->pull(8192);
            $reader->consume($pulled);
        }
        $this->assertTrue($reader->reached_end_of_data());
    }

}
