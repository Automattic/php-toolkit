<?php

namespace WordPress\HttpClient\ByteStream;

use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\HttpClient\Request;

/**
 * Streams bytes from a remote file. Supports seeking to a specific offset and
 * requesting sub-ranges of the file.
 *
 * @TODO: Abort in-progress requests when seeking to a new offset.
 */
class SeekableRequestReadStream extends BaseByteReadStream {

	// const CONTEXT_SIZE_MIN = 0;
	// const CONTEXT_SIZE_MAX = 0;

	private $url;
	private $remote_file_length;
	private $current_reader;
	private $offset_in_remote_file       = 0;
	private $default_expected_chunk_size = 10 * 1024; // 10 KB
	private $expected_chunk_size         = 10 * 1024; // 10 KB
	private $stop_after_chunk            = false;

	/**
	 * Creates a seekable reader for the remote file.
	 * Detects support for range requests and falls back to saving the entire
	 * file to disk when the remote server does not support range requests.
	 */
	public static function create( $url ) {
		$remote_file_reader = new self( $url );

		if ( false === $remote_file_reader->length() ) {
			return self::save_to_disk( $url );
		}

		$remote_file_reader->seek_to_chunk( 0, 2 );
		if ( false === $remote_file_reader->pull( 2 ) ) {
			return self::save_to_disk( $url );
		}

		$bytes = $remote_file_reader->peek( 2 );
		if ( strlen( $bytes ) !== 2 ) {
			return self::redirect_output_to_disk( $remote_file_reader );
		}

		$remote_file_reader->seek( 0 );
		return $remote_file_reader;
	}

	private static function save_to_disk( $url ) {
		$remote_file_reader = new RequestReadStream( $url );
		return self::redirect_output_to_disk( $remote_file_reader );
	}

	private static function redirect_output_to_disk( ByteReadStream $reader ) {
		$file_path = tempnam( sys_get_temp_dir(), 'wp-remote-file-reader-' ) . '.epub';
		$file      = fopen( $file_path, 'w' );
		if ( false === $file ) {
			throw new ByteStreamException( 'Failed to open file for writing' );
		}

		if ( $bytes = $reader->peek( 8096 ) ) {
			if ( false === fwrite( $file, $bytes ) ) {
				throw new ByteStreamException( 'Failed to write bytes to file' );
			}
		}

		while ( $reader->pull( 8096 ) ) {
			if ( false === fwrite( $file, $reader->peek( 8096 ) ) ) {
				throw new ByteStreamException( 'Failed to write bytes to file' );
			}
		}

		if ( false === fclose( $file ) ) {
			throw new ByteStreamException( 'Failed to close file' );
		}
		return FileReadStream::from_path( $file_path );
	}

	public function __construct( $url ) {
		$this->url = $url;
	}

	protected function internal_pull( $n ): string {
		if ( null === $this->current_reader ) {
			$this->create_reader();
		}

		if ( $this->current_reader->get_bytes() ) {
			$this->offset_in_remote_file += strlen( $this->current_reader->get_bytes() );
		}

		if ( $this->offset_in_remote_file >= $this->length() - 1 ) {
			$this->is_closed = true;
			return '';
		}

		if ( false === $this->current_reader->pull( $n ) ) {
			if ( $this->stop_after_chunk ) {
				$this->is_closed = true;
				return '';
			}
			$this->current_reader = null;
			return $this->internal_pull( $n );
		}

		return $this->current_reader->get_bytes();
	}

	public function length(): ?int {
		$this->ensure_content_length();
		return $this->remote_file_length;
	}

	private function create_reader() {
		$this->current_reader = new RequestReadStream(
			new Request(
				$this->url,
				array(
					'headers' => array(
						'Range' => 'bytes=' . $this->offset_in_remote_file . '-' . (
							$this->offset_in_remote_file + $this->expected_chunk_size - 1
						),
					),
				)
			)
		);
	}

	public function seek_to_chunk( $offset, $length ) {
		$this->seek( $offset );
		$this->expected_chunk_size = $length;
		$this->stop_after_chunk    = true;
	}

	public function seek( int $offset ): void {
		$this->offset_in_remote_file    = $offset;
		$this->current_reader           = null;
		$this->expected_chunk_size      = $this->default_expected_chunk_size;
		$this->stop_after_chunk         = false;
		$this->buffer                   = '';
		$this->offset_in_current_buffer = 0;
	}

	private function ensure_content_length() {
		if ( null !== $this->remote_file_length ) {
			return;
		}
		if ( null === $this->current_reader ) {
			$this->current_reader = new RequestReadStream( $this->url );
		}
		$this->remote_file_length = $this->current_reader->length();
	}

	public function close_reading(): void {
		if ( null !== $this->current_reader ) {
			$this->current_reader->close();
			$this->current_reader = null;
		}
		$this->is_closed = true;
	}
}
