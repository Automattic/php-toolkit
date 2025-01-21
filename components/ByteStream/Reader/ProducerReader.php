<?php

namespace WordPress\ByteStream\Reader;

use WordPress\ByteStream\ByteProducer;
use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\MemoryPipe;

/**
 * Class for streaming, seekable byte readers using MemoryPipe.
 *
 * This class pulls bytes from a ByteProducer and exposes them as a reader.
 */
class ProducerReader implements ByteReader {

    private $producer;
    private $memoryPipe;

    public function __construct(ByteProducer $producer) {
        $this->producer = $producer;
        $this->memoryPipe = new MemoryPipe();
    }

    /**
     * Get the total length of the data stream.
     *
     * @return int|null The length of the data stream, or null if the length is unknown.
     */
    public function length(): ?int {
        return null;
    }

    /**
     * Get the current position in the data stream.
     *
     * @return int The current byte offset in the data stream.
     */
    public function tell(): int {
        return $this->memoryPipe->tell();
    }

    /**
     * Seek to a specific position in the data stream.
     *
     * @param int $offset The byte offset to seek to.
     * @return void
     * @throws ByteStreamException If the offset is invalid.
     */
    public function seek(int $offset) {
        if ($offset < 0 || $offset > $this->length()) {
            throw new ByteStreamException("Invalid offset: $offset");
        }
        $this->memoryPipe->seek($offset);
    }

    /**
     * Check if the end of the data stream has been reached.
     * At this point, next_bytes() will always return false until
     * seek() is called.
     *
     * @return bool Whether the end of the data stream has been reached.
     */
    public function reached_end_of_data(): bool {
        return $this->producer->reached_end_of_data() && !$this->get_bytes();
    }

    /**
     * Read the next chunk of bytes from the data stream.
     *
     * @return bool Whether bytes were successfully read.
     */
    public function next_bytes($max_bytes = 8192): bool {
        if($this->memoryPipe->next_bytes($max_bytes)) {
            return true;
        }

        if ($this->reached_end_of_data()) {
            return false;
        }

        if ($this->producer->next_bytes()) {
            $this->memoryPipe->append_bytes($this->producer->get_bytes());
            // Even if memoryPipe->next_bytes() returns false, we know we still
            // have more bytes to read from the producer.
            $this->memoryPipe->next_bytes($max_bytes);
			return true;
        }

        return false;
    }

    /**
     * Get the bytes read in the last operation.
     *
     * @return string|null The bytes read, or null if no bytes were read.
     */
    public function get_bytes(): ?string {
        return $this->memoryPipe->get_bytes();
    }

    /**
     * Close the data stream.
     *
     * @return void
     */
    public function close(): void {
        $this->memoryPipe->close();
    }
}
