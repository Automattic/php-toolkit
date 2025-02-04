<?php

namespace WordPress\Filesystem\Mixin;

use function WordPress\Filesystem\copy_between_filesystems;

/**
 * Implements copy() using the read and write streams provided by the open_read_stream() and open_write_stream() methods.
 */
trait CopyFileViaStreaming {

    public function copy($from_path, $to_path, $options=[]) {
        copy_between_filesystems([
            'source_filesystem' => $this,
            'source_path' => $from_path,
            'target_filesystem' => $this,
            'target_path' => $to_path,
        ]);
	}

}
