<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\ByteStream\Writer\ByteConsumer;
use WordPress\ByteStream\MemoryPipe;

/**
 * Implements open_write_stream() as a buffered write stream that, upon closing,
 * writes the contents to the filesystem using the put_contents() method.
 */
trait BufferedWriteStreamViaPutContents {

    public function open_write_stream($path): ByteConsumer {
        $fs = $this;
        return new class($fs, $path) extends MemoryPipe {
            private $fs;
            private $path;

            public function __construct($fs, $path) {
                $this->fs = $fs;
                $this->path = $path;
            }

            public function close(): void {
                $pipe_contents = $this->consume_all();
                parent::close();
                $this->fs->put_contents($this->path, $pipe_contents);
            }
        };
    }

}
