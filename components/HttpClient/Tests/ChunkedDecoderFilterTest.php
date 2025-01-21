<?php

namespace WordPress\HttpClient\Tests;

use WordPress\ByteStream\Reader\ResourceReader;
use WordPress\HttpClient\Filter\ChunkedDecoderFilter;
use WordPress\HttpClient\Filter\ChunkedEncoderFilter;

class ChunkedDecoderFilterTest extends \PHPUnit\Framework\TestCase {

    private $pygmalion_reader;
    private $chunked_encoder;
    private $decoder;

    public function setUp(): void {
        $this->pygmalion_reader = ResourceReader::from_local_file( dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $this->chunked_encoder = new ChunkedEncoderFilter();
        $this->decoder = new ChunkedDecoderFilter();
    }

    public function test_decodes_two_consecutive_chunks() {
        $chunk1 = $this->get_next_chunk(21);
        $chunk2 = $this->get_next_chunk(100);

        $decoded1 = $this->decoder->filter_bytes($chunk1);
        $decoded2 = $this->decoder->filter_bytes($chunk2);

        $this->assertEquals("PREFACE TO PYGMALION.", $decoded1);
        $this->assertEquals("\n
A Professor of Phonetics.

As will be seen later on, Pygmalion needs, not a preface, but a sequel,", $decoded2);
    }

    public function test_tolerates_incomplete_chunks() {
        $chunk = $this->get_next_chunk(100);
        $split_at = 4;

        $decoded1 = $this->decoder->filter_bytes(substr($chunk, 0, $split_at));
        $this->assertEquals("", $decoded1);

        $decoded2 = $this->decoder->filter_bytes(substr($chunk, $split_at));
        $this->assertEquals("PREFACE TO PYGMALION.", substr($decoded2, 0, 21));
    }

    private function get_next_chunk($n) {
        $this->pygmalion_reader->next_bytes($n);
        return $this->chunked_encoder->filter_bytes($this->pygmalion_reader->get_bytes());
    }

}
