<?php

namespace WordPress\HttpClient\ByteStream;

use WordPress\ByteStream\FileReadWriteStream;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\HttpClient\CacheEntry;
use WordPress\HttpClient\CachePolicy;
use WordPress\HttpClient\FilesystemCache;
use WordPress\HttpClient\Request;

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

	private $request;
	/** @var RequestReadStream|null */
	private $remote;
	/** @var FileReadWriteStream */
	private $cacheStream;          // local working file (either cached body or tmp during download)

	/** @var FilesystemCache */
	private $store;
	/** @var CacheEntry|null */
	private $cacheEntry = null;
	/** @var bool */
	private $cacheHit = false;
	/** @var bool */
	private $stored = false;  // did we write the cache already?

	private $length_resolved = false;

	public function __construct( $request, array $options = array() ) {
		if ( is_string( $request ) ) {
			$request = new Request( $request );
		}

		$this->request = $request;
		$url           = $request->url;
		$cache_dir     = $options['cache_dir'] ?? sys_get_temp_dir() . '/wp_http_cache2';
		$this->store   = new FilesystemCache( $cache_dir );

		// Cache lookup
		$hit = $this->store->lookup( $url );
		if ( $hit && CachePolicy::is_fresh( $hit ) && $hit->status === 200 ) {
			// Cache hit – serve from cache
			$this->cacheHit    = true;
			$this->cacheEntry  = $hit;
			$this->cacheStream = FileReadWriteStream::from_path( $this->store->get_body_path( $url ) );

			return;
		}

		// Cache miss – fetch remotely
		$this->remote = new RequestReadStream( $request, $options );
	}

	/* ------------------------------------------------ internal helpers */

	private function pipe_until( int $offset ): void {
		if ( $this->cacheHit ) {
			return; // data already fully available locally
		}
		while ( !$this->cacheStream || $this->cacheStream->length() === null || $this->cacheStream->length() < $offset ) {
			$pulled = $this->pull_remote();
			if ( 0 === $pulled ) {
				break;
			}
			$this->cacheStream->append_bytes( $this->remote->consume( $pulled ) );
		}
	}

	private function pull_remote() {
		$pulled = $this->remote->pull( BaseByteReadStream::CHUNK_SIZE );
		/**
		 * Wait for the first response bytes from the remote server to
		 * ensure we're caching the terminal response after all the
		 * redirects have been followed.
		 */
		if ( $pulled > 0 && !$this->cacheStream ) {
			$latest_request = $this->request->latest_redirect();
			$this->cacheStream = FileReadWriteStream::from_path( 
				$this->store->get_body_path( $latest_request->url ),
				true
			);
		}
		return $pulled;
	}

	/* ------------------------------------------------ interface ByteReadStream */

	public function length(): ?int {
		if ( ! $this->cacheStream ) {
			return $this->remote->length();
		}

		if ( $this->cacheHit ) {
			return $this->cacheStream->length();
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
		if ( ! $this->cacheStream ) {
			return $this->remote->tell();
		}
		return $this->cacheStream->tell();
	}

	public function seek( int $offset ) {
		$this->pipe_until( $offset );
		$this->cacheStream->seek( $offset );
	}

	public function reached_end_of_data(): bool {
		if ( $this->cacheHit ) {
			return $this->cacheStream->reached_end_of_data();
		}

		$ended = $this->remote->reached_end_of_data() && $this->cacheStream->reached_end_of_data();
		if ( $ended ) {
			$this->maybe_store_cache();
		}

		return $ended;
	}

	public function pull( $n, $mode = self::PULL_NO_MORE_THAN ): int {
		$this->pipe_until( $this->tell() + $n );

		return $this->cacheStream->pull( $n, $mode );
	}

	public function peek( $n ): string {
		$this->pipe_until( $this->tell() + $n );

		return $this->cacheStream->peek( $n );
	}

	public function consume( $n ): string {
		return $this->cacheStream->consume( $n );
	}

	public function consume_all(): string {
		if ( ! $this->cacheHit ) {
			while ( ! $this->remote->reached_end_of_data() ) {
				$pulled = $this->remote->pull( BaseByteReadStream::CHUNK_SIZE );
				if ( 0 === $pulled ) {
					break;
				}
				$this->cacheStream->append_bytes( $this->remote->consume( $pulled ) );
			}
			$this->cacheStream->close_writing();
			$this->maybe_store_cache();
		}

		return $this->cacheStream->consume_all();
	}

	/**
	 * Returns the HTTP response associated with this stream.
	 * For cache hits a synthetic Response object is created from the stored metadata.
	 */
	public function await_response() {
		if ( $this->cacheHit && $this->cacheEntry ) {
			return $this->cacheEntry->to_response( $this->request );
		}

		return $this->remote->await_response();
	}

	public function close_reading(): void {
		$this->maybe_store_cache();
		if ( $this->remote ) {
			$this->remote->close_reading();
		}
		$this->cacheStream->close_reading();
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

		$this->store->commit( $response );
		$this->stored = true;
	}

}
