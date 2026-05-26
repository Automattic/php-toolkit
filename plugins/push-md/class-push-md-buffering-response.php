<?php

use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\HttpServer\Response\ResponseWriteStream;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Push_MD_Buffering_Response implements ResponseWriteStream {
	const MARKER_HEADER = 'X-Push-MD-Git-Response';

	private $http_code = 200;
	private $headers   = array();
	private $body      = '';

	public function send_http_code( $code ) {
		$this->http_code = $code;
	}

	public function send_header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}

	public function append_bytes( $body ): void {
		$this->body .= $body;
	}

	public function append_progress_messages( $messages ) {
		if ( empty( $messages ) ) {
			return;
		}

		$progress = '';
		foreach ( $messages as $message ) {
			$progress .= GitProtocolEncoderPipe::encode_packet_line(
				rtrim( $message ) . "\n",
				"\x02"
			);
		}

		if ( '0000' === substr( $this->body, -4 ) ) {
			$this->body = substr( $this->body, 0, -4 ) . $progress . '0000';
			return;
		}

		$this->body .= $progress;
	}

	public function close_writing(): void {
	}

	public function to_rest_response() {
		$response = new WP_REST_Response( $this->body, $this->http_code );
		$response->header( self::MARKER_HEADER, '1' );
		foreach ( $this->headers as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}
}
