<?php

namespace WordPress\HttpClient;

use WordPress\DataLiberation\URL\WPURL;

/**
 * An asynchronous HTTP client using curl_multi for parallel requests.
 * 
 * This CurlClient class uses an internal event queue (array-based) and a cursor
 * to track the next event, instead of using an SplQueue. It emits events 
 * one at a time via await_next_event(), supporting EVENT_GOT_HEADERS, 
 * EVENT_BODY_CHUNK_AVAILABLE, and EVENT_FINISHED.
 */
class CurlClient {
    /** Event constants matching those in the Client class */
    const EVENT_GOT_HEADERS         = 'EVENT_GOT_HEADERS';
    const EVENT_BODY_CHUNK_AVAILABLE = 'EVENT_BODY_CHUNK_AVAILABLE';
    const EVENT_REDIRECT            = 'EVENT_REDIRECT';
    const EVENT_FAILED              = 'EVENT_FAILED';
    const EVENT_FINISHED            = 'EVENT_FINISHED';

    /** @var int Maximum number of concurrent connections allowed */
    protected $concurrency;
    /** @var int Maximum number of redirects to follow for a single request */
    protected $max_redirects = 3;
    /** @var int Request timeout in milliseconds */
    protected $request_timeout_ms;
    /** @var array All enqueued Request objects, keyed by request ID */
    protected $requests = array();
    /** @var \CurlMultiHandle cURL multi-handle managing parallel requests */
    protected $multi_handle;
    /** @var array Map of cURL handle resource IDs to request IDs (for callbacks) */
    protected $handleMap = array();

    /** @var array Internal event queue */
    private $events = array();

    /** @var string|null The last event type retrieved by await_next_event() */
    protected $event;
    /** @var Request|null The Request associated with the last event */
    protected $request;
    /** @var string|null The last chunk of response body data (for EVENT_BODY_CHUNK_AVAILABLE) */
    protected $response_body_chunk;

    /**
     * Initializes a new CurlClient with optional settings.
     *
     * @param array $options Optional config: 'concurrency', 'max_redirects', 'timeout_ms'.
     */
    public function __construct( $options = array() ) {
        $this->concurrency       = $options['concurrency']  ?? 10;
        $this->max_redirects     = $options['max_redirects'] ?? 3;
        $this->request_timeout_ms = $options['timeout_ms']   ?? 30000;
        $this->multi_handle      = curl_multi_init();

		curl_multi_setopt( $this->multi_handle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX );
		curl_multi_setopt( $this->multi_handle, CURLMOPT_MAX_TOTAL_CONNECTIONS, $this->concurrency );
		curl_multi_setopt( $this->multi_handle, CURLMOPT_MAX_HOST_CONNECTIONS, $this->concurrency );
    }

    /**
     * Destructor to clean up the curl_multi handle.
     */
    public function __destruct() {
        if ( $this->multi_handle ) {
            curl_multi_close( $this->multi_handle );
        }
    }

    /**
     * Enqueues one or more HTTP requests to be processed asynchronously via curl_multi.
     *
     * @param Request|Request[] $requests The request(s) to enqueue.
     * @throws HttpClientException If a request is already enqueued or not in the created state.
     */
    public function enqueue( $requests ) {
        if ( ! is_array( $requests ) ) {
            $requests = array( $requests );
        }
        foreach ( $requests as $request ) {
			if(is_string($request)) {
				$request = new Request($request);
			}
            if ( isset( $this->requests[ $request->id ] ) ) {
                throw new HttpClientException( "Request {$request->id} is already enqueued." );
            }
            if ( $request->state !== Request::STATE_CREATED ) {
                throw new HttpClientException( "Request {$request->id} is not in the created state." );
            }
            // Mark request as enqueued and store it
            $request->state = Request::STATE_ENQUEUED;
            $this->requests[ $request->id ] = $request;

            // Initialize and add the curl handle for this request
            $ch = $this->init_curl_handle( $request );
            if ( ! $ch ) {
                // If initialization fails, immediately mark this request as failed
                $this->events[$request->id][self::EVENT_FAILED] = true;
				$this->requests[ $request->id ]->error = new HttpError('Failed to initialize cURL handle');
                continue;
            }
            curl_multi_add_handle( $this->multi_handle, $ch );
            $this->handleMap[ (int) $ch ] = $request->id;
        }
    }

    /**
     * Create and configure a curl handle for the given Request.
     *
     * @param Request $request The HTTP request to prepare.
     * @return resource|false Returns the configured cURL handle, or false on failure.
     */
    private function init_curl_handle( $request ) {
        $ch = curl_init();
        if ( ! $ch ) {
            return false;
        }
        // Basic curl settings for the request
        curl_setopt( $ch, CURLOPT_URL, $request->url );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
        // curl_setopt( $ch, CURLOPT_MAXREDIRS, $this->max_redirects );
        curl_setopt( $ch, CURLOPT_TIMEOUT_MS, $this->request_timeout_ms );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false ); // use callbacks for data
        curl_setopt( $ch, CURLOPT_HEADER, false );         // headers via callback
		curl_setopt($ch, CURLOPT_ENCODING, '');
        // Set HTTP method and body if needed
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $request->method );
		if ( ! empty( $request->body ) ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $request->body );
		}
        // Set headers if provided
        if ( ! empty( $request->headers ) ) {
            $header_lines = array();
            foreach ( $request->headers as $name => $value ) {
                $header_lines[] = "{$name}: {$value}";
            }
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $header_lines );
        }
        // Set callback functions for data and headers
        curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
			return $this->handle_body_data($ch, $data);
		});
        curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function($ch, $header) {
			return $this->handle_header_line($ch, $header);
		});
        // Disable signals (required for timeout in multi)
        curl_setopt( $ch, CURLOPT_NOSIGNAL, true );

        return $ch;
    }

	private $headers_buffer = [];

    /**
     * cURL callback to handle incoming header lines.
     * Triggers an EVENT_GOT_HEADERS event when the header section is complete.
     *
     * @param resource $ch         The cURL handle.
     * @param string   $header_line A line from the response headers.
     * @return int Number of bytes handled (required by cURL).
     */
    private function handle_header_line( $ch, $header_line ) {
		$request = $this->get_request_by_handle($ch);
		if(null === $request) {
			throw new HttpClientException('Received body data for an unknown request ' . $request->id);
        }
		if(!isset($this->headers_buffer[$request->id])) {
            $request->state = Request::STATE_RECEIVING_HEADERS;
			$this->headers_buffer[$request->id] = '';
		}

        // Check for the end of the header section
        if ( trim( $header_line ) === '' ) {
			$request->response = Response::from_http_headers(
				$this->headers_buffer[$request->id],
				$request
			);
			unset($this->headers_buffer[$request->id]);
			if(false === $request->response) {
				$request->response = new Response($request);
				$this->events[$request->id][self::EVENT_FAILED] = true;
				$this->requests[ $request->id ]->error = new HttpError('Failed to parse headers');
				return strlen( $header_line );
			}
            $this->events[$request->id][self::EVENT_GOT_HEADERS] = true;
            $request->state = Request::STATE_RECEIVING_BODY;
			return strlen( $header_line );
        }

		// Accumulate header lines in the request (for access if needed)
		$this->headers_buffer[$request->id] .= $header_line;
        return strlen( $header_line );
    }

    /**
     * cURL callback to handle chunks of response body data.
     * Triggers an EVENT_BODY_CHUNK_AVAILABLE event for each chunk received.
     *
     * @param resource $ch   The cURL handle.
     * @param string   $data The chunk of response body data.
     * @return int Number of bytes handled.
     */
    private function handle_body_data( $ch, $data ) {
		$request = $this->get_request_by_handle($ch);
		if(null === $request) {
			throw new HttpClientException('Received body data for an unknown request ' . $request->id);
        }
		// echo "$id ";
        // Enqueue a BODY_CHUNK_AVAILABLE event with the received data
		if(!isset($this->events[$request->id][self::EVENT_BODY_CHUNK_AVAILABLE])) {
			$this->events[$request->id][self::EVENT_BODY_CHUNK_AVAILABLE] = '';
		} else if(!is_string($this->events[$request->id][self::EVENT_BODY_CHUNK_AVAILABLE])) {
			$this->events[$request->id][self::EVENT_BODY_CHUNK_AVAILABLE] = '';
		}
        $this->events[$request->id][self::EVENT_BODY_CHUNK_AVAILABLE] .= $data;

        return strlen( $data );
    }

	private function get_request_by_handle( $handle ) {
		return $this->requests[ $this->handleMap[ (int) $handle ] ] ?? null;
	}

    /**
     * Processes the curl_multi stack and waits for the next event to occur.
     * Emits one event at a time.
     *
     * @param array $query Optional parameters (e.g. 'timeout_ms' for a custom wait timeout).
     * @return bool True if an event was emitted, or False if no events remain (or on timeout).
     */
    public function await_next_event( $query = array() ) {
		$ordered_events            = array(
			self::EVENT_GOT_HEADERS,
			self::EVENT_BODY_CHUNK_AVAILABLE,
			self::EVENT_REDIRECT,
			self::EVENT_FAILED,
			self::EVENT_FINISHED,
		);
		$this->event               = null;
		$this->request             = null;
		$this->response_body_chunk = null;

		$start_time = microtime( true );
		$timeout_ms = isset( $query['timeout_ms'] ) 
			? $query['timeout_ms']
			// Give the requests an opportunity to time out
			: $this->request_timeout_ms * 1.1
		;
        do {
			if ( empty( $query['requests'] ) ) {
				$events = array_keys( $this->events );
			} else {
				$events = array();
				foreach ( $query['requests'] as $query_request ) {
					$events[] = $query_request->id;
					while ( $query_request->redirected_to ) {
						$query_request = $query_request->redirected_to;
						$events[]      = $query_request->id;
					}
				}
			}

			foreach ( $events as $request_id ) {
				foreach ( $ordered_events as $considered_event ) {
					$needs_emitting = $this->events[ $request_id ][ $considered_event ] ?? false;
					if ( ! $needs_emitting ) {
						continue;
					}

					$this->event   = $considered_event;
					$this->request = $this->requests[ $request_id ];
					if ( $this->event === self::EVENT_BODY_CHUNK_AVAILABLE ) {
						$this->response_body_chunk = $this->events[ $request_id ][ $considered_event ];
					}
					$this->events[ $request_id ][ $considered_event ] = false;

					return true;
				}
			}
			
			// After we've checked for any available events, see if we've run out of time.
			// This way, we always return any events that were ready before worrying about the timeout.
			// If we checked the timeout first, we might miss events that were already waiting for us
			// when the timeout is set to zero.
			$time_elapsed_ms = (microtime( true ) - $start_time) * 1000;
			if ( $timeout_ms && $time_elapsed_ms >= $timeout_ms ) {
				return false;
			}
        } while ( $this->event_loop_tick() );
    }

	private function event_loop_tick() {
		if(count($this->handleMap) === 0) {
			return false;
		}
		// No queued event, proceed to drive curl_multi
		$running = 0;
		// Execute curl operations
		do {
			$mrc = curl_multi_exec( $this->multi_handle, $running );
		} while ( $mrc === CURLM_CALL_MULTI_PERFORM );

		// Handle any completed requests
		while ( $info = curl_multi_info_read( $this->multi_handle ) ) {
			if ( $info['msg'] === CURLMSG_DONE ) {
				$ch = $info['handle'];
				$id = $this->handleMap[ (int) $ch ] ?? null;
				if ( $id !== null ) {
					if ( $info['result'] !== CURLE_OK ) {
						// Request ended with an error
						$this->events[ $id ][ self::EVENT_FAILED ] = true;
						$this->requests[ $id ]->error = new HttpError(sprintf('cURL error %d: %s', $info['result'], curl_error( $ch )));
					} else {
						$request = $this->requests[ $id ];
						if(isset($request->response->headers['location'])) {
							$this->events[ $id ][ self::EVENT_REDIRECT ] = true;
							$this->handle_redirects( array( $request ) );
						}
						// Request completed successfully
						$this->events[ $id ][ self::EVENT_FINISHED ] = true;
					}
					// Remove and close the finished curl handle
					curl_multi_remove_handle( $this->multi_handle, $ch );
					curl_close( $ch );
					unset( $this->handleMap[ (int) $ch ] );
					// (If concurrency limits apply, we could start a new request here from a pending queue)
				}
			}
		}

		curl_multi_select( $this->multi_handle, 0.05 );
		return true;
	}

    /**
     * Checks if a specific event is pending for a given request.
     *
     * @param Request $request   The request to check.
     * @param string  $event_type One of the event type constants.
     * @return bool True if that event is queued for the request, false otherwise.
     */
    public function has_pending_event( $request, $event_type ) {
        return isset($this->events[$request->id][$event_type]);
    }

    /**
     * Returns the last event type emitted by await_next_event().
     *
     * @return string|false Event type constant, or false if none.
     */
    public function get_event() {
        return $this->event ?? false;
    }

    /**
     * Returns the Request object associated with the last emitted event.
     *
     * @return Request|null The Request for the last event, or null if none.
     */
    public function get_request() {
        return $this->request;
    }

    /**
     * Returns the most recent response body chunk (for an EVENT_BODY_CHUNK_AVAILABLE).
     *
     * @return string|false The body chunk data, or false if not applicable.
     */
    public function get_response_body_chunk() {
        return $this->response_body_chunk ?? false;
    }

	/**
	 * @param  array  $requests  An array of requests.
	 */
	protected function handle_redirects( $requests ) {
		foreach ( $requests as $request ) {
			$response = $request->response;
			if ( ! $response ) {
				continue;
			}
			$code = $response->status_code;
			// $this->mark_finished( $request );
			if ( ! ( $code >= 300 && $code < 400 ) ) {
				continue;
			}

			$location = $response->get_header( 'location' );
			if ( null === $location ) {
				continue;
			}

			$redirects_so_far = 0;
			$cause            = $request;
			while ( $cause->redirected_from ) {
				++ $redirects_so_far;
				$cause = $cause->redirected_from;
			}

			if ( $redirects_so_far >= $this->max_redirects ) {
				$this->set_error( $request, new HttpError( 'Too many redirects' ) );
				continue;
			}

			$redirect_url = $location;
			$parsed = WPURL::parse($redirect_url, $request->url);
			if(false === $parsed) {
				$this->set_error( $request, new HttpError( sprintf( 'Invalid redirect URL: %s', $redirect_url ) ) );
				continue;
			}
			$redirect_url = $parsed->toString();

			$this->events[ $request->id ][ self::EVENT_REDIRECT ] = true;
			$this->enqueue(
				new Request(
					$redirect_url,
					array(
						// Redirects are always GET requests
						'method'          => 'GET',
						'redirected_from' => $request,
					)
				)
			);
		}
	}

	protected function set_error( Request $request, $error ) {
		$request->error                                     = $error;
		$request->state                                     = Request::STATE_FAILED;
		$this->events[ $request->id ][ self::EVENT_FAILED ] = true;
	}
}
