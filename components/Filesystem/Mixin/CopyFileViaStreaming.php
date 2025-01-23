<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\Filesystem\FilesystemException;

/**
 * Implements copy_recursive() using the read and write streams provided by the open_read_stream() and open_write_stream() methods.
 */
trait CopyFileViaStreaming {

    public function copy($from_path, $to_path, $options) {
        if(!$this->is_file($from_path)) {
            throw new FilesystemException( sprintf('Path is not a file: %s', $from_path) );
        }

        $to_fs = $options['to_fs'] ?? $this;
        $to_stream = $to_fs->open_write_stream($to_path);
        try {
            $from_stream = $this->open_read_stream($from_path);
            try {
                $chunks_written = 0;
                while(!$from_stream->reached_end_of_data()) {
                    $available = $from_stream->pull(8192);
                    $to_stream->append_bytes($from_stream->consume($available), $to_stream);
                    $chunks_written++;
                }
                if($chunks_written === 0) {
                    // Make sure the file receives at least one chunk
                    // so we can be sure it gets created in case the
                    // destination filesystem is lazy.
                    $to_stream->append_bytes('');
                }
            } finally {
                $from_stream->close_reading();
            }
        } finally {
            $to_stream->close_writing();
        }
	}

}
