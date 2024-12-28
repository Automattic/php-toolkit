<?php

namespace WordPress\AsyncHttp;

class Request {

	const STATE_ENQUEUED = 'STATE_ENQUEUED';
	const STATE_WILL_ENABLE_CRYPTO = 'STATE_WILL_ENABLE_CRYPTO';
	const STATE_WILL_SEND_HEADERS = 'STATE_WILL_SEND_HEADERS';
	const STATE_WILL_SEND_BODY = 'STATE_WILL_SEND_BODY';
	const STATE_SENT = 'STATE_SENT';
	const STATE_RECEIVING_HEADERS = 'STATE_RECEIVING_HEADERS';
	const STATE_RECEIVING_BODY = 'STATE_RECEIVING_BODY';
	const STATE_RECEIVED = 'STATE_RECEIVED';
	const STATE_FAILED = 'STATE_FAILED';
	const STATE_FINISHED = 'STATE_FINISHED';

	static private $last_id;

	public $id;

	public $state = self::STATE_ENQUEUED;

	public $url;
	public $is_ssl;
	public $method;
	public $headers;
	public $http_version;
	/**
	 * @var WP_Byte_Reader
	 */
	public $upload_body_stream;
	public $redirected_from;
	public $redirected_to;

	public $error;
	/**
	 * @var Response
	 */
	public $response;

	/**
	 * @param  string  $url
	 */
	public function __construct( string $url, $request_info = array() ) {
		$request_info = array_merge( [
			'http_version'    => '1.1',
			'method'          => 'GET',
			'headers'         => [],
			'body_stream'     => null,
			'redirected_from' => null,
		], $request_info );

		$this->id     = ++ self::$last_id;
		$this->is_ssl = strpos( $url, 'https://' ) === 0;

		// Extract username/password from URL if present
		// @TODO: Use the WHATWG URL parser
		$url_parts = parse_url($url);
		if (!empty($url_parts['user'])) {
			$auth = $url_parts['user'];
			if (!empty($url_parts['pass'])) {
				$auth .= ':' . $url_parts['pass']; 
			}
			// Add basic auth header
			$request_info['headers']['authorization'] = 'Basic ' . base64_encode($auth);
			
			// Remove credentials from URL
			$url = 
				$url_parts['scheme'] . '://' .
				$url_parts['host'] .
				(!empty($url_parts['port']) ? ':' . $url_parts['port'] : '') .
				(!empty($url_parts['path']) ? $url_parts['path'] : '') .
				(!empty($url_parts['query']) ? '?' . $url_parts['query'] : '') .
				(!empty($url_parts['fragment']) ? '#' . $url_parts['fragment'] : '');
		}

		$this->url                = $url;
		$this->method             = $request_info['method'];
		$this->headers            = array_change_key_case($request_info['headers'], CASE_LOWER);
		$this->upload_body_stream = $request_info['body_stream'];
		$this->http_version       = $request_info['http_version'];
		$this->redirected_from    = $request_info['redirected_from'];
		if ( $this->redirected_from ) {
			$this->redirected_from->redirected_to = $this;
		}
	}

	public function latest_redirect() {
		$request = $this;
		while ( $request->redirected_to ) {
			$request = $request->redirected_to;
		}

		return $request;
	}

	public function original_request() {
		$request = $this;
		while ( $request->redirected_from ) {
			$request = $request->redirected_from;
		}

		return $request;
	}

	public function is_redirected() {
		return null !== $this->redirected_to;
	}

}
