<?php

namespace WordPress\ByteStream\Producer;

use WordPress\ByteStream\ByteStreamException;

class ResourceProducer implements ByteProducer {

	protected $file_pointer;
	protected $output_bytes	= '';
	protected $is_closed = false;

    static public function from_local_file( $file_path ) {
        if(!file_exists($file_path)) {
            throw new ByteStreamException(sprintf( 'File %s does not exist', $file_path ));
        }
        if(!is_file($file_path)) {
            throw new ByteStreamException(sprintf( '%s is not a file', $file_path ));
        }
        $handle = fopen($file_path, 'r');
        if(!$handle) {
            throw new ByteStreamException(sprintf( 'Failed to open file %s', $file_path ));
        }
        return self::from_resource_handle($handle);
    }

    static public function from_resource_handle( $handle ) {
        if (!is_resource($handle)) {
            throw new ByteStreamException('Invalid file pointer provided');
        }
        return new self($handle);
    }

    public function get_upstream_reader(): ByteProducer {
        return $this;
    }

    public function __construct( $fp ) {
        $this->file_pointer = $fp;
    }

	public function length(): ?int {
        $stats = fstat($this->file_pointer);
        if(!$stats) {
            throw new ByteStreamException('Failed to get file stats');
        }
        return $stats['size'];
	}

	public function tell(): int {
		return ftell($this->file_pointer);
	}

	public function seek( $offset_in_file ) {
        if ( $this->is_closed ) {
            throw new ByteStreamException('Cannot seek on a closed reader');
        }
		if ( ! is_int( $offset_in_file ) ) {
            throw new ByteStreamException('Cannot set a file reader cursor to a non-integer offset');
		}
		$this->output_bytes	= '';
        if ( false === fseek( $this->file_pointer, $offset_in_file ) ) {
            throw new ByteStreamException('Failed to seek to offset');
        }
	}

	public function close(): void {
        if ( $this->is_closed ) {
            return;
        }
        $this->is_closed = true;
        $this->output_bytes	= '';
        if(!fclose($this->file_pointer)) {
            throw new ByteStreamException('Failed to close file pointer');
        }
        $this->file_pointer = null;
	}

	public function reached_end_of_data(): bool {
		return feof( $this->file_pointer );
	}

	public function get_bytes(): string {
		return $this->output_bytes;
	}

	public function next_bytes($max_bytes = 8096): bool {
		$this->output_bytes	= '';
        if ( $this->is_closed ) {
            return false;
        }
		if ( $this->reached_end_of_data() ) {
			return false;
		}
		$bytes = fread( $this->file_pointer, $max_bytes );
        if(false === $bytes) {
            throw new ByteStreamException('Failed to read from file');
        }
        if(strlen($bytes) === 0) {
            return false;
        }
		$this->output_bytes = $bytes;
		return true;
	}
}
