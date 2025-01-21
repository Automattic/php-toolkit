<?php

namespace WordPress\ByteStream\Producer;

use WordPress\ByteStream\ByteStreamException;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

/**
 * Streams bytes from a remote file.
 */
class RemoteFileProducer implements ByteProducer {

	/**
	 * @var \WordPress\HttpClient\Client
	 */
	private $client;
    /**
     * @var \WordPress\HttpClient\Request
     */
	private $request;
    /**
     * @var \WordPress\HttpClient\Response
     */
    private $response;
    /**
     * @var string
     */
	private $current_chunk;
    /**
     * @var string
     */
	private $last_error;
    /**
     * @var bool
     */
	private $is_enqueued = false;
    /**
     * @var int
     */
	private $bytes_already_read = 0;
    /**
     * @var int
     */
	private $remote_file_length;
	private $skip_bytes = 0;
	private $buffered_bytes = '';

	public function __construct( $request, $options = [] ) {
        if(is_string($request)) {
            $request = new Request($request);
        }
		$this->client = $options['client'] ?? new \WordPress\HttpClient\Client();
		$this->request = $request;
	}

	public function tell(): int {
		return $this->bytes_already_read + $this->skip_bytes;
	}

	public function seek( $offset_in_file ) {
		if ( $this->request ) {
			throw new ByteStreamException(
				'Cannot seek() a RemoteFileReader instance once the request was initialized. ' .
				'Use RemoteFileRangedReader to seek() using range requests instead.'
			);
		}
		$this->skip_bytes = $offset_in_file;
	}

	public function next_bytes($max_bytes = 8096): bool {
        return $this->pull_until_event([
            'max_bytes' => $max_bytes,
            'event' => \WordPress\HttpClient\Client::EVENT_BODY_CHUNK_AVAILABLE,
        ]);
    }

	private function pull_until_event($options = []): bool {
        $max_bytes = $options['max_bytes'] ?? 8096;
        $stop_at_event = $options['event'] ?? \WordPress\HttpClient\Client::EVENT_BODY_CHUNK_AVAILABLE;
		if ($this->buffered_bytes !== '') {
			$this->current_chunk = substr($this->buffered_bytes, 0, $max_bytes);
            $this->bytes_already_read += strlen($this->current_chunk);
			$this->buffered_bytes = substr($this->buffered_bytes, $max_bytes);
			return true;
		}

		if ( ! $this->is_enqueued ) {
			$this->client->enqueue( $this->request );
			$this->is_enqueued = true;
		}

		$this->after_chunk();

		while ( $this->client->await_next_event([
            'requests' => [ $this->request ]
        ] ) ) {
			$request = $this->client->get_request();
			if ( ! $request ) {
				continue;
			}
			$response = $request->response;
			if ( false === $response ) {
				continue;
			}
			if ( $request->redirected_to ) {
				continue;
			}
			switch ( $this->client->get_event() ) {
				case \WordPress\HttpClient\Client::EVENT_GOT_HEADERS:
                    $this->response = $response;
					if(null === $this->remote_file_length) {
                        $content_length = $response->get_header( 'Content-Length' );
                        if ( false !== $content_length ) {
                            $this->remote_file_length = (int) $content_length;
                        }
                    }
                    if($stop_at_event === \WordPress\HttpClient\Client::EVENT_GOT_HEADERS) {
                        return true;
                    }
					break;
				case \WordPress\HttpClient\Client::EVENT_BODY_CHUNK_AVAILABLE:
					$chunk = $this->client->get_response_body_chunk();
					if ( ! is_string( $chunk ) ) {
						// TODO: Think through error handling
						return false;
					}

					/**
					 * Naive seek() implementation – redownload the file from the start
					 * and ignore bytes until we reach the desired offset.
					 *
					 * @TODO: Use the range requests instead when the server supports them.
					 */
					if ( $this->skip_bytes > 0 ) {
						if ( $this->skip_bytes < strlen( $chunk ) ) {
							$chunk = substr( $chunk, $this->skip_bytes );
							$this->bytes_already_read += $this->skip_bytes;
							$this->skip_bytes = 0;
						} else {
							$this->skip_bytes -= strlen( $chunk );
							continue 2;
						}
					}

					if (strlen($chunk) > $max_bytes) {
						$this->current_chunk = substr($chunk, 0, $max_bytes);
						$this->buffered_bytes = substr($chunk, $max_bytes);
					} else {
						$this->current_chunk = $chunk;
					}
                    $this->bytes_already_read += strlen($this->current_chunk);
                    if($stop_at_event === \WordPress\HttpClient\Client::EVENT_BODY_CHUNK_AVAILABLE) {
                        return true;
                    }
				case \WordPress\HttpClient\Client::EVENT_FAILED:
					// TODO: Think through error handling. Errors are expected when working with
					//       the network. Should we auto retry? Make it easy for the caller to retry?
					//       Something else?
                    throw new ByteStreamException('HTTP request failed: ' . $this->client->get_request()->error);
			}
		}

        return false;
	}

	public function length(): ?int {
		if ( null !== $this->remote_file_length ) {
			return $this->remote_file_length;
		}

        $response = $this->await_response();
        $content_length = $response->get_header( 'Content-Length' );
        if ( false === $content_length ) {
            return false;
        }
        $this->remote_file_length = (int) $content_length;
		return $this->remote_file_length;
	}

    public function await_response() {
		if ( ! $this->response ) {
            $this->pull_until_event([
                'event' => \WordPress\HttpClient\Client::EVENT_GOT_HEADERS,
            ]);
        }
        return $this->response;
    }

	private function after_chunk() {
		if ( $this->current_chunk ) {
			$this->bytes_already_read += strlen( $this->current_chunk );
		}
		$this->current_chunk = null;
	}

    public function get_request() {
        return $this->request;
    }

	public function get_last_error(): ?string {
		return $this->last_error;
	}

	public function get_bytes(): ?string {
		return $this->current_chunk;
	}

	public function reached_end_of_data(): bool {
		return (
            Request::STATE_FINISHED === $this->request->latest_redirect()->state &&
            ! $this->client->has_pending_event($this->request, \WordPress\HttpClient\Client::EVENT_BODY_CHUNK_AVAILABLE) &&
            ! $this->buffered_bytes &&
            ! $this->current_chunk
        );
	}

	public function close(): void {
        throw new ByteStreamException('Not implemented yet');
	}
}
