<?php

namespace WordPress\ByteStream\Producer;

/**
 * A reader that limits the number of bytes that can be read.
 */
class LimitProducer implements ByteProducer {
    private $upstream;
    private $limit;
    private $initial_offset;

    public function __construct(ByteProducer $upstream, int $limit) {
        $this->upstream = $upstream;
        $this->limit = $limit;
        $this->initial_offset = $upstream->tell();
    }

    public function next_bytes($max_bytes = 8096): bool {
        $max_bytes = min(
            $max_bytes,
            $this->limit - $this->tell()
        );
        if($max_bytes <= 0) {
            return false;
        }
        return $this->upstream->next_bytes($max_bytes);
    }

    public function tell(): int {
        return $this->upstream->tell() - $this->initial_offset;
    }

    public function length(): int {
        return $this->limit;
    }

    public function reached_end_of_data(): bool {
        return $this->tell() >= $this->limit || $this->upstream->reached_end_of_data();
    }

    public function get_bytes(): string {
        return $this->upstream->get_bytes();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {
        $this->upstream->seek($offset, $whence);
    }

    public function close(): void {}
}
