<?php

namespace WordPress\Filesystem\Mixin\Interfaces;

use WordPress\Filesystem\FilesystemException;

interface InternalizedReadStream {

	/**
	 * Get the next chunk of a file.
	 *
	 * @param int $stream_id The stream identifier.
	 * @return bool True if the next chunk was retrieved, false otherwise.
	 * @throws FilesystemException If the next chunk cannot be retrieved.
	 */
	public function read_stream_next_bytes( int $stream_id, int $max_bytes = 8192 ): bool;

	/**
	 * Get the current chunk of a file.
	 *
	 * @return string The current chunk of the file or false if no chunk is available.
	 */
	public function read_stream_get_bytes( int $stream_id ): ?string;

	/**
	 * Get the length of the streamed file.
	 *
	 * @return int|false The length of the file or false if the file is not streamed.
	 * @throws FilesystemException If the file length cannot be retrieved.
	 */
	public function read_stream_length( int $stream_id ): int;

	/**
	 * Seek to a specific position in the file.
	 *
	 * @param int $stream_id The stream identifier.
	 * @param int $offset The offset to seek to.
	 * @throws FilesystemException If the seek operation fails.
	 */
	public function read_stream_seek( int $stream_id, int $offset ): void;

	/**
	 * Check if the read stream is finished.
	 *
	 * @param int $stream_id The stream identifier.
	 * @return bool True if the read stream is finished, false otherwise.
	 */
	public function read_stream_is_finished( int $stream_id ): bool;

	/**
	 * Close the file reader.
	 *
	 * @throws FilesystemException If the stream cannot be closed.
	 */
	public function read_stream_close( int $stream_id ): void;
}
