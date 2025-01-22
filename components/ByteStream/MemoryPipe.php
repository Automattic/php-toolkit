<?php

namespace WordPress\ByteStream;

use WordPress\ByteStream\Producer\BaseByteProducer;
use WordPress\ByteStream\Writer\ByteConsumer;

class MemoryPipe extends BaseByteProducer implements ByteConsumer {

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
		if(null !== $this->length() && $this->tell() + strlen($new_bytes) > $this->length()) {
			throw new ByteStreamException('Appending bytes to the stream would exceed the expected length.');
		}
		$this->buffer .= $new_bytes;
	}

	protected function internal_pull($n): string {
        return '';
	}

	protected function seek_outside_of_buffer(int $target_offset): void {
        throw new ByteStreamException('Cannot seek past the available data. Call append_bytes() first.');
	}

	public function length(): ?int {
		return $this->expected_length;
	}

}
