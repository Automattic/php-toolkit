<?php

namespace WordPress\ByteStream;

class MemoryPipe implements BytePipe {

	protected $buffer='';
	protected $offset_in_current_buffer = 0;
    protected $bytes_already_forgotten = 0;
	protected $output_chunk = '';
	protected $is_closed = false;
	protected $expected_length = null;

	public function __construct(string $string='', $expected_length = null) {
        if(strlen($string) > 0 && null !== $expected_length) {
            throw new ByteStreamException('A MemoryPipe accepts either a non-empty string representing the entire data, or an expected length when the data is not available yet. It does not accept both arguments.');
        }
        if(strlen($string) > 0) {
            $this->buffer = $string;
            $this->expected_length = strlen($string);
        } else if(null !== $expected_length) {
            $this->expected_length = $expected_length;
        }
	}

	public function append_bytes(string $new_bytes): void {
		if($this->is_closed) {
			throw new ByteStreamException('Cannot append bytes to a closed stream.');
		}
        $bytes_to_forget                = $this->offset_in_current_buffer;
        $this->bytes_already_forgotten += $bytes_to_forget;
        $this->buffer                   = substr($this->buffer, $bytes_to_forget);
		$this->buffer                  .= $new_bytes;
        $this->offset_in_current_buffer = 0;
        if(strlen($this->buffer) > $this->length()) {
            throw new ByteStreamException('Appending bytes to the stream would exceed the expected length.');
        }
	}

	public function length(): ?int {
		return $this->expected_length ?? strlen($this->buffer);
	}

	public function tell(): int {
		return $this->offset_in_current_buffer + $this->bytes_already_forgotten;
	}

	public function seek($offset) {
		if($this->is_closed) {
			throw new ByteStreamException('Cannot seek a closed stream.');
		}
		if (!is_int($offset)) {
			throw new ByteStreamException('Cannot set cursor to a non-integer offset.');
		}
		if ($offset < $this->bytes_already_forgotten ) {
			throw new ByteStreamException('Cannot seek to an offset that was already cleaned up from memory.');
		}
		if ($this->expected_length !== null && $offset > $this->expected_length) {
			throw new ByteStreamException('Cannot seek past the end of the stream.');
		}
		if($offset > $this->bytes_already_forgotten + strlen($this->buffer)) {
			throw new ByteStreamException('Cannot seek past the available buffer. Call append_bytes() data first.');
		}
		$this->offset_in_current_buffer = $offset - $this->bytes_already_forgotten;
		$this->output_chunk = '';
	}

	public function close(): void {
		$this->is_closed = true;
		$this->output_chunk = '';
	}

	public function reached_end_of_data(): bool {
        if($this->is_closed) {
            return true;
        }
        if(null !== $this->expected_length) {
            return $this->tell() >= $this->length();
        }
        /**
         * If we don't know the length, we must assume more data is coming until
         * the pipe gets closed.
         */
        return false;
	}

	public function get_bytes(): string {
		return $this->output_chunk;
	}

	public function next_bytes($max_bytes = 8096): bool {
        $this->output_chunk = '';
        
		if($this->is_closed) {
			return false;
		}

		if ($this->reached_end_of_data()) {
			return false;
		}

		if ( $this->offset_in_current_buffer >= strlen($this->buffer)) {
			return false;
		}

		$bytes                           = substr($this->buffer, $this->offset_in_current_buffer, $max_bytes);
		$this->offset_in_current_buffer += strlen($bytes);
		$this->output_chunk              = $bytes;

		return true;
	}
}
