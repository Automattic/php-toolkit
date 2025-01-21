<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\Producer\ReaderUtils;
use WordPress\ByteStream\Producer\ResourceProducer;
use WordPress\ByteStream\Producer\TransformedProducer;

class TransformedProducerTest extends TestCase {

    public function test_basic_data_streaming() {
        $reference = file_get_contents(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $reader = new ResourceProducer(fopen( dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt', 'r'));
        $stream = new TransformedProducer($reader);
        $accumulated = '';
        $stream->next_bytes(100);
        $accumulated .= $stream->get_bytes();
        $this->assertEquals(100, strlen($stream->get_bytes()));

        $stream->next_bytes(100);
        $accumulated .= $stream->get_bytes();
        $this->assertEquals(100, strlen($stream->get_bytes()));

        $accumulated .= ReaderUtils::read_all_remaining_bytes($stream);
        $this->assertEquals(8704, strlen($accumulated));

        $this->assertEquals($reference, $accumulated);
    }

}
