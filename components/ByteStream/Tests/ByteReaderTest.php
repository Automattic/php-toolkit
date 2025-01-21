<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\Reader\BufferedReader;
use WordPress\ByteStream\Reader\ByteReader;
use WordPress\ByteStream\Reader\DeflateReader;
use WordPress\ByteStream\Reader\ResourceReader;
use WordPress\ByteStream\Reader\InflateReader;

class ByteReaderTest extends TestCase {

    /**
     * Data provider for ByteReader implementations.
     */
    public function byteReaderProvider() {
        $text = file_get_contents(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $compressedData = gzdeflate($text, -1, ZLIB_ENCODING_DEFLATE);

        return [
            'ResourceReader' => [ResourceReader::from_local_file( dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt'), strlen($text)],
            'StringReader' => [new MemoryPipe($text), strlen($text)],
            'BufferedReader' => [new BufferedReader(new MemoryPipe($text), 1024), strlen($text)],
            'InflateReader' => [new InflateReader(new MemoryPipe($compressedData)), strlen($text)],
            'DeflateReader' => [new DeflateReader(new MemoryPipe($text)), strlen($text)],
        ];
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testLength(ByteReader $reader, int $length) {
        if($reader instanceof InflateReader || $reader instanceof DeflateReader) {
            $this->assertNull($reader->length());
        } else {
            $this->assertEquals($length, $reader->length());
        }
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testTell(ByteReader $reader) {
        $this->assertEquals(0, $reader->tell());
        $reader->next_bytes(10);
        $this->assertGreaterThan(0, $reader->tell());
        $this->assertLessThanOrEqual(10, $reader->tell());
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testSeek(ByteReader $reader) {
        $reader->seek(10);
        $this->assertEquals(10, $reader->tell());
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testNextBytesWithMaxBytes(ByteReader $reader) {
        if($reader instanceof DeflateReader) {
            $reader = new InflateReader($reader);
        }

        $this->assertTrue($reader->next_bytes(10));
        $this->assertStringStartsWith('PREFAC', $reader->get_bytes());
        $this->assertLessThanOrEqual(10, strlen($reader->get_bytes()));

        $reader->seek(10);
        $this->assertTrue($reader->next_bytes(10));
        $this->assertStringStartsWith(' PYGMALIO', $reader->get_bytes());
        $this->assertLessThanOrEqual(10, strlen($reader->get_bytes()));
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testNextBytesWithSeek(ByteReader $reader) {
        if($reader instanceof DeflateReader) {
            $reader = new InflateReader($reader);
        }
        $reader->seek(998);
        $this->assertTrue($reader->next_bytes(40));
        $this->assertEquals('apologize to public meetings in a very c', $reader->get_bytes());

        $reader->seek(0);
        $this->assertTrue($reader->next_bytes(21));
        // Note the argument 21 means $max_bytes, not $exactly_bytes. Some readers,
        // like the InflateReader, often return less data than requested.
        $this->assertStringStartsWith('PREFACE TO PYGMALI', $reader->get_bytes());
        $this->assertLessThanOrEqual(21, strlen($reader->get_bytes()));

        $reader->seek(200);
        $this->assertTrue($reader->next_bytes(10));
        $this->assertEquals('language, ', $reader->get_bytes());
        $this->assertLessThanOrEqual(10, strlen($reader->get_bytes()));

        $reader->seek(10);
        $this->assertTrue($reader->next_bytes(10));
        $this->assertStringStartsWith(' PYGMALIO', $reader->get_bytes());
        $this->assertLessThanOrEqual(10, strlen($reader->get_bytes()));
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testClose(ByteReader $reader) {
        $reader->next_bytes();
        $reader->close();
        $this->assertFalse($reader->next_bytes());
    }

    /**
     * @dataProvider byteReaderProvider
     */
    public function testIsFinished(ByteReader $reader) {
        $this->assertFalse($reader->reached_end_of_data());

        while($reader->next_bytes()) {
            // Read until end
        }
        $this->assertTrue($reader->reached_end_of_data());
    }

}
