<?php

namespace WordPress\HttpClient;

final class CacheEntry {
	/**
	 * @var string
	 */
	public $url;
	/**
	 * @var int
	 */
	public $status;
	/**
	 * @var mixed[]
	 */
	public $headers;
	/**
	 * @var int
	 */
	public $stored_at;
	/**
	 * @var int|null
	 */
	public $max_age;
	/**
	 * @var string|null
	 */
	public $etag;
	/**
	 * @var string|null
	 */
	public $last_modified;
	/**
	 * @var string|null Vary header value from the response that determined the cache entry variant.
	 */
	public $vary;
	/**
	 * @var array<string, string|null>|null Stores the original request header values for every header listed in `Vary`.
	 *           This allows later look-ups to verify the variant matches the current request.
	 */
	public $vary_headers;

	static public function from_response( Response $response ) {
		$entry = new self();
		$entry->url         = $response->request->url;
		$entry->status      = $response->status_code;
		$entry->headers     = $response->headers;
		$entry->stored_at   = time();
		$entry->etag        = $response->get_header( 'etag' );
		$entry->last_modified = $response->get_header( 'last-modified' );

		// Capture Vary header information to support correct cache variant matching.
		$vary_header = $response->get_header( 'vary' );
		$entry->vary = $vary_header;
		if ( null !== $vary_header && trim( $vary_header ) !== '*' ) {
			$vary_headers = [];
			foreach ( explode( ',', $vary_header ) as $h ) {
				$h = strtolower( trim( $h ) );
				if ( '' === $h ) {
					continue;
				}
				$vary_headers[ $h ] = $response->request->get_header( $h ) ?? null;
			}
			$entry->vary_headers = $vary_headers;
		}

		return $entry;
	}

	public function to_response( Request $request ) {
		$response = new Response( $request );
		$response->status_code = $this->status;
		$response->headers     = $this->headers;
		return $response;
	}
}
