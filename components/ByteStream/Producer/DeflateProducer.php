<?php

namespace WordPress\ByteStream\Producer;

use WordPress\ByteStream\ByteStreamException;

class DeflateProducer implements ByteProducer {

    protected $deflate_context;
    protected $deflated_chunk = '';
    protected $deflated_offset = 0;
    protected $buffered_next_bytes = '';
    protected $is_closed = false;

    /**
     * The offset of the underlying reader at the time of the first read
     * from the DeflateReader.
     */
    protected $delegate_offset_0;

    private $deflate_encoding = ZLIB_ENCODING_DEFLATE;
    private $upstream;

    public function __construct(ByteProducer $upstream, $encoding = ZLIB_ENCODING_DEFLATE) {
        $this->deflate_encoding = $encoding;
        $this->deflate_init();
        $this->upstream = $upstream;
    }

    public function next_bytes($max_bytes = 8096): bool {
        if ($this->is_closed) {
            return false;
        }

        if($this->upstream->reached_end_of_data()) {
            return false;
        }

        if ($this->buffered_next_bytes !== '') {
            $this->deflated_chunk = substr($this->buffered_next_bytes, 0, $max_bytes);
            $this->buffered_next_bytes = substr($this->buffered_next_bytes, $max_bytes);
            $this->deflated_offset += strlen($this->deflated_chunk);
            return true;
        }

        if(null === $this->delegate_offset_0) {
            $this->delegate_offset_0 = $this->upstream->tell();
        }

        do {
            if ($this->upstream->next_bytes($max_bytes)) {
                $bytes = $this->upstream->get_bytes($max_bytes);
                $deflated = deflate_add($this->deflate_context, $bytes);
            } else {
                $deflated = deflate_add($this->deflate_context, '', ZLIB_FINISH);
                if(!$deflated) {
                    return false;
                }
            }
            if ($deflated === false) {
                throw new ByteStreamException('Deflate error.');
            }
        } while ( $deflated === '' );

        if (strlen($deflated) > $max_bytes) {
            $this->deflated_chunk = substr($deflated, 0, $max_bytes);
            $this->buffered_next_bytes = substr($deflated, $max_bytes);
        } else {
            $this->deflated_chunk = $deflated;
            $this->buffered_next_bytes = '';
        }
        $this->deflated_offset += strlen($this->deflated_chunk);

        if(strlen($this->deflated_chunk) === 0) {
            return false;
        }

        return true;
    }

    public function length(): ?int {
        // The length of the deflated stream is unknown until the stream is closed.
        return null;
    }

    public function get_bytes(): ?string {
        return $this->deflated_chunk;
    }

    public function tell(): int {
        return $this->deflated_offset;
    }

    public function close(): void {
        $this->is_closed = true;
        $this->deflated_chunk = '';
        $this->buffered_next_bytes = '';
    }

    public function reached_end_of_data(): bool {
        return $this->is_closed || $this->upstream->reached_end_of_data();
    }

    /**
     * Seeks within the deflated stream.
     */
    public function seek($offset) {
        if($offset < 0) {
            throw new ByteStreamException('Cannot seek to a negative offset');
        }

        /**
         * We cannot go back in the stream. Without access to the internal state of the
         * gzip compressor, we're only able to move forward. The only way to seek
         * to an earlier offset is to re-deflate the stream from the beginning.
         */
        if($offset < $this->tell()) {
            $this->deflate_init();
            $this->deflated_offset = 0;
            $this->deflated_chunk = '';
            $this->buffered_next_bytes = '';
            $this->upstream->seek($this->delegate_offset_0 ?? 0);
        }

        while($offset > $this->tell()) {
            $remaining_bytes = $offset - $this->tell();

            // Get the next deflated chunk, but no more than 50KB at a time.
            $next_chunk_size = min(50 * 1024, $remaining_bytes);
            if(false === $this->next_bytes($next_chunk_size)) {
                throw new ByteStreamException('Requested offset ' . $offset . ' is beyond the end of the deflated stream');
            }
        }

        $this->deflated_chunk = substr($this->deflated_chunk, $offset - $this->tell());
        $this->deflated_offset = $offset;
    }

    private function deflate_init() {
        $this->deflate_context = deflate_init($this->deflate_encoding);
        if(!$this->deflate_context) {
            throw new \Exception('Failed to initialize deflate context');
        }
    }
}
