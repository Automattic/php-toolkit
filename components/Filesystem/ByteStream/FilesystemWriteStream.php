<?php

namespace WordPress\Filesystem\ByteStream;

use WordPress\ByteStream\Writer\ByteConsumer;
use WordPress\Filesystem\Mixin\Interfaces\InternalizedWriteStream;

class FilesystemWriteStream implements ByteConsumer {

    /**
     * @var InternalizedWriteStream
     */
    protected $filesystem;

    /**
     * @var int
     */
    protected $stream_id;

    public function __construct(InternalizedWriteStream $filesystem, int $stream_id) {
        $this->filesystem = $filesystem;
        $this->stream_id = $stream_id;
    }

    public function append_bytes($data): void {
        $this->filesystem->write_stream_append_bytes($this->stream_id, $data);
    }

    public function close(): void {
        $this->filesystem->write_stream_close($this->stream_id);
    }

}
