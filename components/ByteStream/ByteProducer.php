<?php

namespace WordPress\ByteStream;

interface ByteProducer {
    /**
     * Read the next chunk of bytes from the data stream.
     *
     * @return bool Whether bytes were successfully read.
     */
    public function next_bytes(): bool;

    /**
     * Get the bytes read in the last operation.
     *
     * @return string|null The bytes read, or null if no bytes were read.
     */
    public function get_bytes(): ?string;

    public function reached_end_of_data(): bool;
}
