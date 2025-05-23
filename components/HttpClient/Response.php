<?php

namespace WordPress\HttpClient;

use WordPress\HttpServer\StatusCode;

class Response {

	public $protocol;
	public $status_code;
	public $status_message;
	public $headers = array();
	public $request;

	public $received_bytes = 0;
	public $total_bytes = null;

	public function __construct( ?Request $request = null ) {
		$this->request = $request;
	}

	public function get_header( $name ) {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	public function get_reason_phrase() {
		return StatusCode::text( $this->status_code );
	}

	public function ok() {
		return $this->status_code >= 200 && $this->status_code < 400;
	}

	/**
	 * Parses an HTTP headers string into a new Response object.
	 *
	 * @param  string  $headers  The HTTP headers to parse.
	 *
	 * @return Response|false A new Response object, or false if the headers are invalid.
	 */
	static public function from_http_headers( $headers_raw, ?Request $request = null ) {
		$lines  = explode( "\r\n", $headers_raw );
		$status = array_shift( $lines );
		$status = explode( ' ', $status );
		if ( count( $status ) < 3 ) {
			return false;
		}
		$status  = array(
			'protocol' => $status[0],
			'code'     => (int) $status[1],
			'message'  => $status[2],
		);
		$headers_parsed = array();
		foreach ( $lines as $line ) {
			if ( strpos( $line, ': ' ) === false ) {
				// @TODO: Error, not a valid response
				continue;
			}
			$line = explode( ': ', $line );
			/**
			 * Headers names are case-insensitive.
			 *
			 * RFC 7230 states:
			 *
			 * > Each header field consists of a case-insensitive field name followed by a colon (":"),
			 * > optional leading whitespace, the field value, and optional trailing whitespace."
			 */
			$headers_parsed[ strtolower( $line[0] ) ] = $line[1];
		}

		$response = new Response($request);
		$response->status_code = $status['code'];
		$response->status_message = $status['message'];
		$response->protocol = $status['protocol'];
		$response->headers = $headers_parsed;
		return $response;
	}
}
