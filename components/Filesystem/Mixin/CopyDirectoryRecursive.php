<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\Filesystem\FilesystemException;

use function WordPress\Filesystem\wp_join_paths;

/**
 * Implements a recursive copy() using the read and write streams provided by the open_read_stream() and open_write_stream() methods.
 */
trait CopyDirectoryRecursive {

    public function copy($from_path, $to_path, $options=[]) {
		$to_fs = $options['to_fs'] ?? $this;
        if($this->is_file($from_path)) {
            return $this->copy_file($from_path, $to_path, $options);
        }

        if(!$this->exists($from_path)) {
            throw new FilesystemException( sprintf('Path does not exist: %s', $from_path) );
        }

		$recursive = $options['recursive'] ?? false;
		if(!$recursive) {
            throw new FilesystemException( 'Cannot copy a directory without recursive => true option' );
		}

		$stack = [[$from_path, $to_path]];
		while(!empty($stack)) {
			[$from_path, $to_path] = array_shift($stack);
			if($this->is_dir($from_path)) {
				if(!$to_fs->is_dir($to_path)) {
					$to_fs->mkdir($to_path);
				}
				foreach($this->ls($from_path) as $child) {
					$stack[] = [
						wp_join_paths($from_path, $child),
						wp_join_paths($to_path, $child)
					];
				}
			} else if($this->is_file($from_path)) {
                $this->copy_file($from_path, $to_path, $options);
			} else {
                throw new FilesystemException( sprintf('Path is of an unsupported type: %s', $from_path) );
			}
		}
		return true;
	}

    abstract public function copy_file($from_path, $to_path, $options);

}
