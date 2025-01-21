<?php

namespace WordPress\ByteStream\Producer;

use WordPress\ByteStream\ByteStreamException;

class InflateProducer implements ByteProducer {

    protected $inflate_context;
    protected $inflated_chunk = '';
    protected $inflated_offset = 0;
    protected $buffered_next_bytes = '';
    protected $is_closed = false;

    /**
     * The offset of the underlying reader at the time of the first read
     * from the InflateReader.
     *
     * It's the only way to seek to an earlier offset when reading from
     * a git-like object that has a plaintext header and gzipped body:
     *
     * blob <size>\x00
     * <gzip-compressed-data>
     *
     * If we just seeked to offset=0, we'd start inflating the plaintext,
     * not the deflated body, and get an error.
     */
    protected $delegate_offset_0;

    private $inflate_encoding = ZLIB_ENCODING_DEFLATE;
    private $upstream;

    public function __construct(ByteProducer $upstream, $encoding = ZLIB_ENCODING_DEFLATE) {
        $this->inflate_encoding = $encoding;
        $this->inflate_init();
        $this->upstream = $upstream;
    }

    public function next_bytes($max_bytes = 8096): bool {
        if ($this->is_closed) {
            return false;
        }

        if ($this->buffered_next_bytes !== '') {
            $this->inflated_chunk = substr($this->buffered_next_bytes, 0, $max_bytes);
            $this->buffered_next_bytes = substr($this->buffered_next_bytes, $max_bytes);
            $this->inflated_offset += strlen($this->inflated_chunk);
            return true;
        }

        if(null === $this->delegate_offset_0) {
            $this->delegate_offset_0 = $this->upstream->tell();
        }

        do {
            if ($this->upstream->next_bytes($max_bytes)) {
                $bytes = $this->upstream->get_bytes($max_bytes);
                $inflated = inflate_add($this->inflate_context, $bytes, ZLIB_NO_FLUSH);
            } else {
                $inflated = inflate_add($this->inflate_context, '', ZLIB_FINISH);
                if(!$inflated) {
                    return false;
                }
            }
            if ($inflated === false) {
                throw new ByteStreamException('Inflate error: ' . $this->get_error_string());
            }
        } while ( $inflated === '' );

        if (strlen($inflated) > $max_bytes) {
            $this->inflated_chunk = substr($inflated, 0, $max_bytes);
            $this->buffered_next_bytes = substr($inflated, $max_bytes);
        } else {
            $this->inflated_chunk = $inflated;
            $this->buffered_next_bytes = '';
        }
        $this->inflated_offset += strlen($this->inflated_chunk);

        if(strlen($this->inflated_chunk) === 0) {
            return false;
        }

        return true;
    }

    public function length(): ?int {
        // The length of the inflated stream is unknown until the stream is closed.
        return null;
    }

    public function get_bytes(): ?string {
        return $this->inflated_chunk;
    }

    public function tell(): int {
        return $this->inflated_offset;
    }

    public function close(): void {
        $this->is_closed = true;
        $this->inflated_chunk = '';
        $this->buffered_next_bytes = '';
        $this->inflated_offset = 0;
    }

    public function reached_end_of_data(): bool {
        return $this->is_closed || $this->upstream->reached_end_of_data();
    }

    /**
     * Seeks within the inflated stream.
     */
    public function seek($offset) {
        if($offset < 0) {
            throw new ByteStreamException('Cannot seek to a negative offset');
        }

        /**
         * We cannot go back in the stream. Without access to the internal state of the
         * gzip decompressor, we're only able to move forward. The only way to seek
         * to an earlier offset is to re-inflate the stream from the beginning.
         */
        if($offset < $this->tell()) {
            $this->inflate_init();
            $this->inflated_offset = 0;
            $this->inflated_chunk = '';
            $this->buffered_next_bytes = '';
            $this->upstream->seek($this->delegate_offset_0 ?? 0);
        }

        while($offset > $this->tell()) {
            $remaining_bytes = $offset - $this->tell();

            // Get the next deflated chunk, but no more than 50KB at a time.
            $next_chunk_size = min(50 * 1024, $remaining_bytes);
            if(false === $this->next_bytes($next_chunk_size)) {
                throw new ByteStreamException('Requested offset ' . $offset . ' is beyond the end of the inflated stream');
            }
        }

        $this->inflated_chunk = substr($this->inflated_chunk, $offset - $this->tell());
        $this->inflated_offset = $offset;
    }

    protected function get_error_string() {
        $status = inflate_get_status($this->inflate_context);
        switch($status) {
            case ZLIB_OK:
                $error_string = 'ZLIB_OK';
                break;
            case ZLIB_STREAM_END:
                $error_string = 'ZLIB_STREAM_END';
                break;
            case ZLIB_NEED_DICT:
                $error_string = 'ZLIB_NEED_DICT';
                break;
            case ZLIB_ERRNO:
                $error_string = 'ZLIB_ERRNO';
                break;
            case ZLIB_STREAM_ERROR:
                $error_string = 'ZLIB_STREAM_ERROR';
                break;
            case ZLIB_DATA_ERROR:
                $error_string = 'ZLIB_DATA_ERROR';
                break;
            case ZLIB_BUF_ERROR:
                $error_string = 'ZLIB_BUF_ERROR';
                break;
            case ZLIB_MEM_ERROR:
                $error_string = 'ZLIB_MEM_ERROR';
                break;
            default:
                $error_string = 'Unknown error';
                break;
        }
        return "Error $status: $error_string";
    }

    private function inflate_init() {
        $this->inflate_context = inflate_init($this->inflate_encoding);
        if(!$this->inflate_context) {
            throw new \Exception('Failed to initialize inflate context');
        }
    }
}
