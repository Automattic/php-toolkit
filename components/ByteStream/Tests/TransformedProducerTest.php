<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\Producer\ByteProducer;
use WordPress\ByteStream\Producer\ResourceProducer;
use WordPress\ByteStream\Producer\TransformedProducer;

class TransformedProducerTest extends TestCase {

    public function test_basic_data_streaming() {
        $reference = file_get_contents(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $reader = ResourceProducer::from_local_file(dirname(__FILE__) . '/fixtures/preface-to-pygmalion.txt');
        $stream = new TransformedProducer($reader);
        $accumulated = '';

        $this->assertEquals(100, $stream->pull(100, ByteProducer::PULL_EXACTLY));
        $accumulated .= $stream->consume(100);

        $this->assertEquals(100, $stream->pull(100, ByteProducer::PULL_EXACTLY));
        $accumulated .= $stream->consume(100);

        $remaining = strlen($reference) - $stream->tell();
        $this->assertEquals($remaining, $stream->pull($remaining, ByteProducer::PULL_EXACTLY));
        $accumulated .= $stream->consume($remaining);

        $this->assertEquals(8704, strlen($accumulated));
        $this->assertEquals($reference, $accumulated);
    }

}
