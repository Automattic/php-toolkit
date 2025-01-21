<?php

namespace WordPress\Git\Protocol\Parser;

use WordPress\ByteStream\NotEnoughDataException;
use WordPress\ByteStream\Reader\BufferedReader;
use WordPress\ByteStream\Reader\ByteReader;
use WordPress\ByteStream\Reader\ReaderUtils;
use WordPress\Git\GitException;

class ProtocolDemultiplexer {


    const STREAM_CODE_SIDE_BAND = 'side_band';
    const STREAM_CODE_PROGRESS = 'progress';
    const STREAM_CODE_FATAL = 'fatal';
    const STREAM_CODE_UNKNOWN = 'unknown';

    const STREAM_CODE_MAP = [
        0x01 => self::STREAM_CODE_SIDE_BAND,
        0x02 => self::STREAM_CODE_PROGRESS,
        0x03 => self::STREAM_CODE_FATAL,
    ];

    /**
     * @var ByteReader
     */
    protected $upstream = '';
    protected $is_paused_at_incomplete_input = false;

    protected $chunk;
    protected $stream_code;
    protected $seen_unmultiplexed_pack = false;

    public function __construct(ByteReader $upstream) {
        $this->upstream = new BufferedReader($upstream, 1024);
    }

    public function next_chunk() {
        $this->is_paused_at_incomplete_input = false;
        $at = $this->upstream->tell();
        try {
            while(true) {
                if($this->is_finished()) {
                    return false;
                }

                $this->parse_chunk();
                return true;
            }
        } catch(NotEnoughDataException $e) {
            $this->upstream->seek($at);
            $this->is_paused_at_incomplete_input = true;
            return false;
        }
    }

    private function parse_chunk() {
        $this->chunk = '';
        $this->stream_code = 'unknown';

        $length_hex = ReaderUtils::read_exactly_n_bytes($this->upstream, 4);
        if($length_hex === 'PACK') {
            $this->seen_unmultiplexed_pack = true;
        }
        /**
         * If we found an unmultiplexed packfile packet, let's assume it continues
         * until the end of the stream.
         */
        if($this->seen_unmultiplexed_pack) {
            if(false === $this->upstream->next_bytes()) {
                throw new NotEnoughDataException('Could not read PACK packet at ' . $this->upstream->tell());
            }
            $this->chunk = $length_hex . $this->upstream->get_bytes();
            return;
        }

        $length = hexdec($length_hex);

        $stream_code = 'unknown';
        // Peek the next byte to determine the stream code.
        $stream_code_byte = ReaderUtils::peek_n_bytes($this->upstream, 1);
        $potential_stream_code = ord($stream_code_byte);
        if(isset(self::STREAM_CODE_MAP[$potential_stream_code])) {
            $stream_code = self::STREAM_CODE_MAP[$potential_stream_code];
            // Skip over the stream code byte.
            $this->upstream->next_bytes(1);
            $length -= 1;
        }

        if($length_hex === '0000' || $length_hex === '0001' || $length_hex === '0002') {
            $this->chunk = $length_hex;
            $this->stream_code = $stream_code;
            return;
        }

        if(0 === $length) {
            throw new GitException('Demultiplexer error: Received a zero-length chunk ' . $length_hex . ' at ' . $this->upstream->tell());
        }

        // Buffer the multiplexed chunk and yield it to the consumer.
        $length -= 4;
        $chunk = ReaderUtils::read_exactly_n_bytes($this->upstream, $length);
        $this->stream_code = $stream_code;
        if('unknown' === $this->stream_code) {
            // $chunk is not actually multiplexed so we need to relay
            // all the data we've read so far to the consumer.
            $this->chunk = $length_hex . $chunk;
        } else {
            // $chunk is multiplexed and the downstream consumer
            // only expects the wrapped data.
            $this->chunk = $chunk;
        }
    }

    public function get_stream_code() {
        return $this->stream_code;
    }

    public function get_chunk() {
        return $this->chunk;
    }

	public function is_paused_at_incomplete_input(): bool {
		return $this->is_paused_at_incomplete_input;
	}

    public function is_finished(): bool {
        return $this->upstream->reached_end_of_data();
    }
}
