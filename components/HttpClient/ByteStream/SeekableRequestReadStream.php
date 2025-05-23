<?php

namespace WordPress\HttpClient\ByteStream;

use WordPress\ByteStream\FileReadWriteStream;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpClient\CacheEntry;
use WordPress\HttpClient\CachePolicy;
use WordPress\HttpClient\FilesystemCache;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

/**
 * HTTP reader that can seek() within the stream with transparent persistent caching.
 *
 * Behaviour changes compared to the original implementation:
 *  – Before issuing the HTTP request, it checks a on-disk cache (FilesystemCache).
 *    If a fresh 200 response is found (per CachePolicy) the body is replayed with
 *    zero network overhead.
 *  – After a successful complete download of a cache-able 200 response, the body
 *    and headers are written to the cache. Partial / cut-off downloads are never
 *    stored, preventing corrupted bodies from being reused in the future.
 */
class SeekableRequestReadStream implements ByteReadStream {

	/** @var RequestReadStream|null */
	private $remote;
	/** @var FileReadWriteStream */
	private $cache;          // local working file (either cached body or tmp during download)
	/** @var string */
	private $temp;           // absolute path to working file

	/** @var FilesystemCache */
	private $store;
	/** @var CacheEntry|null */
	private $cacheEntry = null;
	/** @var bool */
	private $cacheHit   = false;
	/** @var bool */
	private $stored     = false;  // did we write the cache already?

	private $length_resolved = false;

	public function __construct( $request, array $options = array() ) {
		if ( is_string( $request ) ) {
			$request = new Request( $request );
		}

		$url       = $request->url;
		$cache_dir = $options['cache_dir'] ?? sys_get_temp_dir() . '/wp_http_cache';
		$this->store = $options['cache_store'] ?? new FilesystemCache( LocalFilesystem::create( $cache_dir ) );

		// ------------------------------------------------------------------ cache lookup
		$hit = $this->store->lookup( $url );
		if ( $hit && CachePolicy::is_fresh( $hit ) && $hit->status === 200 ) {
			// Serve from cache – no network
			$this->cacheHit   = true;
			$this->cacheEntry = $hit;
			$this->temp       = $cache_dir . '/' . $this->store->get_body_path( $url );
			$this->cache      = FileReadWriteStream::from_path( $this->temp );
			// rewind (a+b opens at EOF)
			$this->cache->seek( 0 );

			return;
		}

		// ------------------------------------------------------------------ fetch remotely
		// Note: we purposely skip conditional GET for simplicity. Stale entries
		// fall through to a normal request and will be refreshed.
		$this->remote = new RequestReadStream( $request, $options );

		$this->temp  = $options['cache_path'] ?? tempnam( sys_get_temp_dir(), 'wp_http_tmp_' );
		$this->cache = FileReadWriteStream::from_path( $this->temp, true );
	}

	/* ------------------------------------------------ internal helpers */

	private function pipe_until( int $offset ): void {
		if ( $this->cacheHit ) {
			return; // data already fully available locally
		}
		while ( $this->cache->length() === null || $this->cache->length() < $offset ) {
			$pulled = $this->remote->pull( BaseByteReadStream::CHUNK_SIZE );
			if ( 0 === $pulled ) {
				break;
			}
			$this->cache->append_bytes( $this->remote->consume( $pulled ) );
		}
	}

	private function maybe_store_cache(): void {
		if ( $this->cacheHit || $this->stored || ! $this->remote ) {
			return; // nothing to do
		}
		if ( ! $this->remote->reached_end_of_data() ) {
			return; // not finished – do not store partial responses
		}

		$response = $this->remote->await_response();
		if ( $response->status_code !== 200 ) {
			return; // only store 200 OK bodies
		}
		if ( ! CachePolicy::response_is_cacheable( $response ) ) {
			return; // respect Cache-Control / Expires rules
		}

		// --- write body into cache storage --------------------------------
		$writer = $this->store->open_body_write_stream( $response->request->url );
		$src    = fopen( $this->temp, 'rb' );
		while ( ! feof( $src ) ) {
			$chunk = fread( $src, 8192 );
			if ( '' !== $chunk && $chunk !== false ) {
				$writer->append_bytes( $chunk );
			}
		}
		fclose( $src );
		$writer->close_writing();

		// --- meta ---------------------------------------------------------
		$entry              = new CacheEntry();
		$entry->url         = $response->request->url;
		$entry->status      = $response->status_code;
		$entry->headers     = $response->headers;
		$entry->stored_at   = time();
		$entry->etag        = $response->get_header( 'etag' );
		$entry->last_modified = $response->get_header( 'last-modified' );
		$this->store->store( $entry );

		$this->stored = true;
	}

	/* ------------------------------------------------ interface ByteReadStream */

	public function length(): ?int {
		if ( $this->cacheHit ) {
			return $this->cache->length();
		}

		if ( ! $this->length_resolved && null === $this->remote->length() ) {
			$this->remote->await_response();
			if ( null === $this->remote->length() ) {
				$pos = $this->tell();
				$this->consume_all();
				$this->seek( $pos );
			}
			$this->length_resolved = true;
		}
		return $this->remote->length();
	}

	public function tell(): int {
		return $this->cache->tell();
	}

	public function seek( int $offset ) {
		$this->pipe_until( $offset );
		$this->cache->seek( $offset );
	}

	public function reached_end_of_data(): bool {
		if ( $this->cacheHit ) {
			return $this->cache->reached_end_of_data();
		}

		$ended = $this->remote->reached_end_of_data() && $this->cache->reached_end_of_data();
		if ( $ended ) {
			$this->maybe_store_cache();
		}
		return $ended;
	}

	public function pull( $n, $mode = self::PULL_NO_MORE_THAN ): int {
		$this->pipe_until( $this->tell() + $n );
		return $this->cache->pull( $n, $mode );
	}

	public function peek( $n ): string {
		$this->pipe_until( $this->tell() + $n );
		return $this->cache->peek( $n );
	}

	public function consume( $n ): string {
		return $this->cache->consume( $n );
	}

	public function consume_all(): string {
		if ( ! $this->cacheHit ) {
			while ( ! $this->remote->reached_end_of_data() ) {
				$pulled = $this->remote->pull( BaseByteReadStream::CHUNK_SIZE );
				if ( 0 === $pulled ) {
					break;
				}
				$this->cache->append_bytes( $this->remote->consume( $pulled ) );
			}
			$this->cache->close_writing();
			$this->maybe_store_cache();
		}

		return $this->cache->consume_all();
	}

	/**
	 * Returns the HTTP response associated with this stream.
	 * For cache hits a synthetic Response object is created from the stored metadata.
	 */
	public function await_response() {
		if ( $this->cacheHit && $this->cacheEntry ) {
			$resp              = new Response();
			$resp->status_code = $this->cacheEntry->status;
			$resp->headers     = $this->cacheEntry->headers;
			$resp->request     = new Request( $this->cacheEntry->url );
			return $resp;
		}
		return $this->remote->await_response();
	}

	public function close_reading(): void {
		if ( $this->remote ) {
			$this->remote->close_reading();
		}
		$this->cache->close_reading();
		$this->maybe_store_cache();

		// Clean up temporary file when it was only a download buffer
		if ( ! $this->cacheHit && is_file( $this->temp ) ) {
			@unlink( $this->temp );
		}
	}
}
