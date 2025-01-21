<?php

namespace WordPress\Filesystem\ByteStream;

use WordPress\ByteStream\Producer\ByteProducer;
use WordPress\Filesystem\Mixin\Interfaces\InternalizedReadStream;

class FilesystemReadStream implements ByteProducer {

    /**
     * @var InternalizedReadStream
     */
    protected $filesystem;

    /**
     * @var int
     */
    protected $stream_id;

    public function __construct(InternalizedReadStream $filesystem, int $stream_id) {
        $this->filesystem = $filesystem;
        $this->stream_id = $stream_id;
    }

    public function length(): int {
        return $this->filesystem->read_stream_length($this->stream_id);
    }

    public function tell(): int {
        return $this->filesystem->read_stream_length($this->stream_id);
    }

    public function seek(int $offset): void {
        $this->filesystem->read_stream_seek($this->stream_id, $offset);
    }

    public function reached_end_of_data(): bool {
        return $this->filesystem->read_stream_is_finished($this->stream_id);
    }

    public function next_bytes($max_bytes = 8192): bool {
        return $this->filesystem->read_stream_next_bytes($this->stream_id, $max_bytes);
    }

    public function get_bytes(): string {
        return $this->filesystem->read_stream_get_bytes($this->stream_id);
    }

    public function close(): void {
        $this->filesystem->read_stream_close($this->stream_id);
    }

}
