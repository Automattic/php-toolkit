<?php

namespace WordPress\ByteStream\Reader;

/**
 * A reader that allows seeking to an earlier offset up to the
 * buffer size.
 */
class BufferedReader implements ByteReader {
    private $upstream;
    
    private $buffer = '';
    private $current_chunk = '';
    private $buffer_size;

    private $buffer_offset = 0;
    private $is_closed = false;

    public function __construct(ByteReader $upstream, int $buffer_size=1024) {
        $this->upstream = $upstream;
        $this->buffer_size = $buffer_size;
    }

    public function next_bytes($max_bytes = 8096): bool {
        if ($this->is_closed) {
            return false;
        }

        $this->current_chunk = '';

        while ($this->buffer === '' || $this->buffer_offset >= strlen($this->buffer)) {
            // We don't have enough buffered data, let's fetch more from upstream
            if (!$this->upstream->next_bytes($max_bytes)) {
                return false;
            }
            $this->buffer .= $this->upstream->get_bytes();
        }

        $this->current_chunk = substr($this->buffer, $this->buffer_offset, $max_bytes);
        $this->buffer_offset += strlen($this->current_chunk);

        // Trim the buffer if we've accumulated too much data
        if ($this->buffer_offset > $this->buffer_size) {
            $overflow = $this->buffer_offset - $this->buffer_size;
            $this->buffer = substr($this->buffer, $overflow);
            $this->buffer_offset -= $overflow;
        }
        return true;
    }

    public function tell(): int {
        return $this->upstream->tell() - strlen($this->buffer) + $this->buffer_offset;
    }

    public function length(): ?int {
        return $this->upstream->length();
    }

    public function reached_end_of_data(): bool {
        return $this->upstream->reached_end_of_data();
    }

    public function get_bytes(): string {
        return $this->current_chunk;
    }

    public function seek(int $offset): void {
        $this->current_chunk = '';

        $target_offset = $offset;
        $current_offset = $this->tell();
        $diff = $target_offset - $current_offset;

        if($diff === 0) {
            return;
        } else if ($diff < 0 && abs($diff) <= strlen($this->buffer)) {
            // Seeking backwards within buffer range
            $this->buffer_offset += $diff;
        } else if ($diff > 0 && strlen($this->buffer) > $this->buffer_offset + $diff) {
            // Seeking forwards within buffer range
            $this->buffer_offset += $diff;
        } else {
            // Seeking outside of buffer range, let's fetch more from upstream
            $this->buffer = '';
            $this->buffer_offset = 0;
            $this->upstream->seek($target_offset);
        }
    }

    public function close(): void {
        $this->is_closed = true;
    }
}
