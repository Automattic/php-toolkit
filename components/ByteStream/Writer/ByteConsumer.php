<?php

namespace WordPress\ByteStream\Writer;

interface ByteConsumer {

	/**
	 * Append bytes to the stream.
	 *
	 * @param  string  $bytes
	 * @return void
	 */
	public function append_bytes(string $bytes): void;

    /**
     * Closes the stream resources.
     *
     * @return void
     */
    public function close(): void;

}
