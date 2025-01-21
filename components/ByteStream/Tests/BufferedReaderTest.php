<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\Reader\BufferedReader;
use WordPress\ByteStream\Reader\ReaderUtils;

class BufferedReaderTest extends TestCase {
    private $text;
    private $stringReader;

    public function setUp(): void {
        parent::setUp();
        $this->text = file_get_contents(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $this->stringReader = new class($this->text) extends MemoryPipe {
            public function seek($offset) {
                throw new ByteStreamException('Cannot seek() the upstream reader');
            }
        };
    }

    public function test_can_seek_back_and_forth() {
        $this->text = <<<GIT
        0032ef9fae98ba6dd17140b45bc657659b6c41a4ad10 HEAD
        003def9fae98ba6dd17140b45bc657659b6c41a4ad10 refs/heads/main
        0000
        GIT;
        $this->stringReader = new class($this->text) extends MemoryPipe {
            public function seek($offset) {
                throw new ByteStreamException('Cannot seek() the upstream reader');
            }
        };
        $reader = new BufferedReader($this->stringReader, 300);
        $this->assertEquals(
            '0032',
            ReaderUtils::read_exactly_n_bytes($reader, 4)
        );
        $this->assertEquals(
            'e',
            ReaderUtils::peek_n_bytes($reader, 1)
        );
        $this->assertEquals(
            "ef9fae98ba6dd17140b45bc657659b6c41a4ad10 HEAD\n",
            ReaderUtils::read_exactly_n_bytes($reader, 46)
        );
    }

    public function test_can_seek_within_the_buffer() {
        $reader = new BufferedReader($this->stringReader, 300);
        $reader->next_bytes(300);

        // Seek backwards within the buffer
        $reader->seek(100);
        $reader->next_bytes(100);
        $this->assertEquals( substr($this->text, 100, 100), $reader->get_bytes() );

        // Seek forwards within the buffer
        $reader->seek(200);
        $reader->next_bytes(100);
        $this->assertEquals( substr($this->text, 200, 100), $reader->get_bytes() );
    }

    public function test_read_chunk_then_move_upsteram_forward_then_seek() {
        $reader = new BufferedReader($this->stringReader, 300);
        $reader->next_bytes(10);
        $this->assertEquals( substr($this->text, 0, 10), $reader->get_bytes() );
        $reader->next_bytes(1);

        $reader->seek(0);
        $reader->next_bytes(10);
        $this->assertEquals( substr($this->text, 0, 10), $reader->get_bytes() );

        $reader->seek(0);
        $reader->next_bytes(10);
        $this->assertEquals( substr($this->text, 0, 10), $reader->get_bytes() );
    }

    public function test_seek_clears_the_current_chunk() {
        $reader = new BufferedReader($this->stringReader, 300);
        $reader->next_bytes(300);
        $reader->seek(100);
        $this->assertEquals( '', $reader->get_bytes());
    }

    public function test_tell_returns_the_correct_position() {
        $reader = new BufferedReader($this->stringReader, 300);
        $reader->next_bytes(2);
        $reader->next_bytes(2);
        $this->assertEquals( 4, $reader->tell() );
    }

}
