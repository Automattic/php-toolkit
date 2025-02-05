<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Filesystem\ByteStream\FilesystemReadStream;

/**
 * Implements open_read_stream() to return a stream that delegates all its
 * calls to the Filesystem class.
 *
 * Any class using this trait is required to implement InternalizedReadStream.
 *
 * @see InternalizedReadStream
 */
trait InternalizeReadStream {

	protected $read_streams = array();

	/**
	 * Start streaming a file.
	 *
	 * @param string $path The path to the file.
	 *
	 * @return ByteReadStream The stream.
	 * @throws FilesystemException If the stream cannot be opened.
	 * @example
	 *
	 * $fs->open_read_stream($path);
	 * while($fs->next_file_chunk()) {
	 *     $chunk = $fs->get_file_chunk();
	 *     // process $chunk
	 * }
	 * $fs->close_read_stream();
	 */
	public function open_read_stream( $path ): ByteReadStream {
		$stream_id                        = $this->read_stream_internal_open( $path );
		$this->read_streams[ $stream_id ] = new FilesystemReadStream( $this, $stream_id );
		return $this->read_streams[ $stream_id ];
	}

	abstract protected function read_stream_internal_open( string $path ): int;

	// The rest of the methods are covered by the InternalizedReadStream interface.
}
