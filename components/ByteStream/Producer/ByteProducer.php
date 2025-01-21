<?php

namespace WordPress\ByteStream\Producer;

use WordPress\ByteStream\ByteSource;

/**
 * Interface for streaming, seekable byte readers.
 *
 * Implementations of this interface can be used to read data from
 * various sources, such as files, strings, network sockets, zip files,
 * parsers, etc.
 */
interface ByteProducer {

    /**
     * Get the total length of the data stream.
     *
     * @return int|null The length of the data stream, or null if the length is unknown.
     */
    public function length(): ?int;

    /**
     * Get the current position in the data stream.
     *
     * @return int The current byte offset in the data stream.
     */
    public function tell(): int;

    /**
     * Seek to a specific position in the data stream.
     *
     * @param int $offset The byte offset to seek to.
     * @return void
     * @throws ByteStreamException If the offset is invalid.
     */
    public function seek(int $offset);

    /**
     * Check if the end of the data stream has been reached.
     * At this point, next_bytes() will always return false until
     * seek() is called.
     *
     * @return bool Whether the end of the data stream has been reached.
     */
    public function reached_end_of_data(): bool;

    /**
     * Read the next chunk of bytes from the data stream.
     *
     * @return bool Whether bytes were successfully read.
     */
    public function next_bytes($max_bytes = 8192): bool;

    /**
     * Get the bytes read in the last operation.
     *
     * @return string|null The bytes read, or null if no bytes were read.
     */
    public function get_bytes(): ?string;

    /**
     * Close the data stream.
     *
     * @return void
     */
    public function close(): void;
}
