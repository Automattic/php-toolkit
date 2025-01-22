<?php

namespace WordPress\ByteStream\Producer;

use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\Producer\BaseByteProducer;

class InflateProducer extends BaseByteProducer {

    protected $inflate_context;
    protected $inflate_encoding;
    protected $upstream;

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

    // Ensure properties are defined or inherited
    protected $is_closed = false;
    protected $buffer = '';
    protected $bytes_already_forgotten = 0;
    protected $offset_in_current_buffer = 0;
    protected $inflated_offset = 0;

    public function __construct(ByteProducer $upstream, $encoding = ZLIB_ENCODING_DEFLATE) {
        $this->inflate_encoding = $encoding;
        $this->inflate_init();
        $this->upstream = $upstream;
    }

    protected function internal_pull($n): string {
        if(null === $this->delegate_offset_0) {
            $this->delegate_offset_0 = $this->upstream->tell();
        }

        if(!$this->inflate_context) {
            return '';
        }

        if($this->upstream->reached_end_of_data()) {
            $bytes = inflate_add($this->inflate_context, '', ZLIB_FINISH);
            $this->inflate_context = null;
            return $bytes;
        }

        $n = max(200, $n);
        $deflated = $this->upstream->peek($n);
        if(!strlen($deflated)) {
            $this->upstream->pull($n);
            $deflated = $this->upstream->peek($n);
        }
        $this->upstream->consume(strlen($deflated));
        $inflated = inflate_add($this->inflate_context, $deflated);
        if(false === $inflated) {
            throw new ByteStreamException('Inflate error: ' . $this->get_error_string());
        }
        return $inflated;
    }

    public function length(): ?int {
        return null;
    }

    protected function internal_close(): void {
        $this->inflate_context = null;
    }

    protected function internal_reached_end_of_data(): bool {
        return $this->inflate_context === null && $this->upstream->reached_end_of_data();
    }

    protected function seek_outside_of_buffer($target_offset): void {
        if($target_offset < $this->tell()) {
            $this->buffer = '';
            $this->bytes_already_forgotten = 0;
            $this->offset_in_current_buffer = 0;

            $this->inflate_init();
            $this->upstream->seek($this->delegate_offset_0 ?? 0);
        }

        while($this->tell() < $target_offset) {
            $remaining_bytes = $target_offset - $this->tell();
            $next_chunk_size = min(50 * 1024, $remaining_bytes);
            $pulled = $this->pull($next_chunk_size);
            // Keep skipping bytes until we've consumed enough
            $this->consume(min($remaining_bytes, $pulled));
        }
    }

    private function inflate_init() {
        $this->inflate_context = inflate_init($this->inflate_encoding);
        if(!$this->inflate_context) {
            throw new \Exception('Failed to initialize inflate context');
        }
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

}
