<?php

namespace WordPress\HttpClient\Client;

use WordPress\DataLiberation\URL\WPURL;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Connection;
use WordPress\HttpClient\HttpClientException;
use WordPress\HttpClient\HttpError;
use WordPress\HttpClient\Request;

abstract class Client {

	const EVENT_GOT_HEADERS = 'EVENT_GOT_HEADERS';
	const EVENT_BODY_CHUNK_AVAILABLE = 'EVENT_BODY_CHUNK_AVAILABLE';
	const EVENT_REDIRECT = 'EVENT_REDIRECT';
	const EVENT_FAILED = 'EVENT_FAILED';
	const EVENT_FINISHED = 'EVENT_FINISHED';

	/**
	 * Microsecond is 1 millionth of a second.
	 *
	 * @var int
	 */
	const MICROSECONDS_TO_SECONDS = 1000000;

	/**
	 * 5/100th of a second
	 */
	const NONBLOCKING_TIMEOUT_MICROSECONDS = 0.05 * self::MICROSECONDS_TO_SECONDS;

	/**
	 * The maximum number of concurrent connections allowed.
	 *
	 * This is as a safeguard against:
	 * * Spreading our network bandwidth too thin and not making any real progress on any
	 *   request.
	 * * Overwhelming the server with too many requests.
	 *
	 * @var int
	 */
	protected $concurrency;

	/**
	 * The maximum number of redirects to follow for a single request.
	 *
	 * This prevents infinite redirect loops and provides a degree of control over the client's behavior.
	 * Setting it too high might lead to unexpected navigation paths.
	 *
	 * @var int
	 */
	protected $max_redirects;

	/**
	 * All the HTTP requests ever enqueued with this Client.
	 *
	 * Each Request may have a different state, and this Client will manage them
	 * asynchronously, moving them through the various states as the network
	 * operations progress.
	 *
	 * @since Next Release
	 * @var Request[]
	 */
	protected $requests = [];

	/**
	 * Network connection details managed privately by this Client.
	 *
	 * Each Request has a corresponding Connection object that contains
	 * the connection handle, response buffer, and other details.
	 *
	 * These are internal, will change without warning, and should not be
	 * exposed to the outside world.
	 *
	 * @var array
	 */
	protected $connections = [];
	protected $events = [];
	protected $event = null;
	protected $request = null;
	protected $response_body_chunk = null;
	protected $request_timeout_ms = null;

	/**
	 * Creates a new HTTP client instance best suited to your platform.
	 * CurlClient is the default. If cURL is not available, it falls back to
	 * SocketClient.
	 */
	static public function create( $options = array() ) {
		if ( ! extension_loaded( 'curl' ) ) {
			return new SocketClient( $options );
		}

		return new CurlClient( $options );
	}

	public function __construct( $options = array() ) {
		$this->concurrency        = $options['concurrency'] ?? 10;
		$this->max_redirects      = $options['max_redirects'] ?? 3;
		$this->request_timeout_ms = $options['timeout_ms'] ?? 30000;
	}

	/**
	 * Returns a RemoteFileReader that streams the response body of the
	 * given request.
	 *
	 * @param  Request  $request  The request to stream.
	 *
	 * @return RequestReadStream
	 */
	public function fetch( $request, $options = array() ) {
		return new RequestReadStream(
			$request,
			array_merge( [ 'client' => $this ],
				is_array( $options ) ? $options : iterator_to_array( $options ) )
		);
	}

	/**
	 * Returns an array of RemoteFileReader instances that stream the response bodies
	 * of the given requests.
	 *
	 * @param  Request[]  $requests  The requests to stream.
	 *
	 * @return RequestReadStream[]
	 */
	public function fetch_many( array $requests, $options = array() ) {
		$streams = array();

		foreach ( $requests as $request ) {
			$streams[] = $this->fetch( $request, $options );
		}

		return $streams;
	}

	/**
	 * Enqueues one or multiple HTTP requests for asynchronous processing.
	 * It does not open the network sockets, only adds the Request objects to
	 * an internal queue. Network transmission is delayed until one of the returned
	 * streams is read from.
	 *
	 * @param  Request|Request[]  $requests  The HTTP request(s) to enqueue. Can be a single request or an array of requests.
	 */
	public function enqueue( $requests ) {
		if ( ! is_array( $requests ) ) {
			$requests = array( $requests );
		}

		foreach ( $requests as $request ) {
			if ( is_string( $request ) ) {
				$request = new Request( $request );
			}
			if ( array_key_exists( $request->id, $this->connections ) ) {
				throw new HttpClientException( "Request {$request->id} is already enqueued." );
			}

			if ( $request->state !== Request::STATE_CREATED ) {
				throw new HttpClientException( "Request {$request->id} is not in the created state." );
			}

			$request->state                    = Request::STATE_ENQUEUED;
			$this->requests[]                  = apply_filters( 'wp_http_client_request', $request );
			$this->events[ $request->id ]      = array();
			$this->connections[ $request->id ] = new Connection( $request );

			$parsed = WPURL::parse( $request->url );
			if ( false === $parsed ) {
				$this->set_error( $request, new HttpError( sprintf( 'Invalid URL: %s', $request->url ) ) );
				continue;
			}
			if ( $parsed->protocol !== 'http:' && $parsed->protocol !== 'https:' ) {
				$this->set_error( $request,
					new HttpError( sprintf( 'Invalid URL – only HTTP and HTTPS URLs are supported: %s', $parsed->toString() ) ) );
				continue;
			}
		}
	}

	/**
	 * Returns the next event related to any of the HTTP
	 * requests enqueued in this client.
	 *
	 * ## Events
	 *
	 * The returned event is a ClientEvent with $event->name
	 * being one of the following:
	 *
	 * * `Client::EVENT_GOT_HEADERS`
	 * * `Client::EVENT_BODY_CHUNK_AVAILABLE`
	 * * `Client::EVENT_REDIRECT`
	 * * `Client::EVENT_FAILED`
	 * * `Client::EVENT_FINISHED`
	 *
	 * See the ClientEvent class for details on each event.
	 *
	 * Once an event is consumed, it is removed from the
	 * event queue and will not be returned again.
	 *
	 * When there are no events available, this function
	 * blocks and waits for the next one. If all requests
	 * have already finished, and we are not waiting for
	 * any more events, it returns false.
	 *
	 * ## Filtering
	 *
	 * The $query parameter can be used to filter the events
	 * that are returned. It can contain the following keys:
	 *
	 * * `request_id` – The ID of the request to consider.
	 *
	 * For example, to only consider the next `EVENT_GOT_HEADERS`
	 * event for a specific request, you can use:
	 *
	 * ```php
	 * $request = new Request( "https://w.org" );
	 *
	 * $client = new HttpClientClient();
	 * $client->enqueue( $request );
	 * $event = $client->await_next_event( [
	 *    'request_id' => $request->id,
	 * ] );
	 * ```
	 *
	 * Importantly, filtering does not consume unrelated events.
	 * You can await all the events for a request #2, and
	 * then await the next event for request #1 even if the
	 * request #1 has finished before you started awaiting
	 * events for request #2.
	 *
	 * @param $query
	 *
	 * @return bool
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
			: $this->request_timeout_ms * 1.1;

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

					$this->events[ $request_id ][ $considered_event ] = false;
					$this->event                                      = $considered_event;
					$this->request                                    = $this->get_request_by_id( $request_id );
					switch ( $this->event ) {
						case self::EVENT_BODY_CHUNK_AVAILABLE:
							$this->response_body_chunk = $this->consume_buffered_response_body( $request_id );
							break;
						case self::EVENT_FAILED:
						case self::EVENT_FINISHED:
							// We don't need the response buffer anymore. It's
							// safe to clean up the connection object now. The
							// HTTP resource have been closed by now via the
							// close_connection() method.
							unset( $this->connections[ $request_id ] );
							break;
					}

					return true;
				}
			}

			// After we've checked for any available events, see if we've run out of time.
			// This way, we always return any events that were ready before worrying about the timeout.
			// If we checked the timeout first, we might miss events that were already waiting for us
			// when the timeout is set to zero.
			$time_elapsed_ms = ( microtime( true ) - $start_time ) * 1000;
			if ( $timeout_ms && $time_elapsed_ms >= $timeout_ms ) {
				return false;
			}
		} while ( $this->event_loop_tick() );

		return false;
	}


	/**
	 * Consumes $length bytes received in response to a given request.
	 *
	 * @return string
	 */
	protected function consume_buffered_response_body( $request_id ) {
		$request = $this->get_request_by_id( $request_id );
		if ( null === $request ) {
			return false;
		}
		$connection = $this->connections[ $request->id ];
		if (
			$request->state === Request::STATE_RECEIVING_BODY ||
			$request->state === Request::STATE_FINISHED
		) {
			return $connection->consume_buffer();
		}

		$end_of_data = $request->state === Request::STATE_FINISHED && (
				! is_resource( $this->connections[ $request->id ]->http_socket ) ||
				$this->connections[ $request->id ]->decoded_response_stream->reached_end_of_data()
			);
		if ( $end_of_data ) {
			return false;
		}

		return '';
	}

	public function has_pending_event( $request, $event_type ) {
		return $this->events[ $request->id ][ $event_type ] ?? false;
	}

	/**
	 * Returns the next event found by await_next_event().
	 *
	 * @return string|bool The next event, or false if no event is set.
	 */
	public function get_event() {
		if ( null === $this->event ) {
			return false;
		}

		return $this->event;
	}

	/**
	 * Returns the request associated with the last event found
	 * by await_next_event().
	 *
	 * @return Request
	 */
	public function get_request() {
		if ( null === $this->request ) {
			return false;
		}

		return $this->request;
	}

	/**
	 * Returns the response body chunk associated with the EVENT_BODY_CHUNK_AVAILABLE
	 * event found by await_next_event().
	 *
	 * @return string|false
	 */
	public function get_response_body_chunk() {
		if ( null === $this->response_body_chunk ) {
			return false;
		}

		return $this->response_body_chunk;
	}

	/**
	 * Asynchronously moves the enqueued Request objects through the
	 * various states of the HTTP request-response lifecycle.
	 *
	 * @return bool Whether any active requests were processed.
	 */
	abstract protected function event_loop_tick();

	public function get_active_requests( $states = null ) {
		$processed_requests = $this->get_requests(
			array(
				Request::STATE_WILL_ENABLE_CRYPTO,
				Request::STATE_WILL_SEND_HEADERS,
				Request::STATE_WILL_SEND_BODY,
				Request::STATE_SENT,
				Request::STATE_RECEIVING_HEADERS,
				Request::STATE_RECEIVING_BODY,
				Request::STATE_RECEIVED,
			)
		);
		$available_slots    = $this->concurrency - count( $processed_requests );
		$enqueued_requests  = $this->get_requests( Request::STATE_ENQUEUED );
		for ( $i = 0; $i < $available_slots; $i ++ ) {
			if ( ! isset( $enqueued_requests[ $i ] ) ) {
				break;
			}
			$processed_requests[] = $enqueued_requests[ $i ];
		}
		if ( $states !== null ) {
			$processed_requests = static::filter_requests_by_state( $processed_requests, $states );
		}

		return $processed_requests;
	}

	protected function mark_finished( Request $request ) {
		$request->state                                       = Request::STATE_FINISHED;
		$this->events[ $request->id ][ self::EVENT_FINISHED ] = true;

		$this->close_connection( $request );
	}

	protected function set_error( Request $request, $error ) {
		$request->error                                     = $error;
		$request->state                                     = Request::STATE_FAILED;
		$this->events[ $request->id ][ self::EVENT_FAILED ] = true;
		$this->close_connection( $request );
	}

	abstract protected function close_connection( Request $request );

	public function get_failed_requests() {
		return $this->get_requests( Request::STATE_FAILED );
	}

	protected function get_requests( $states ) {
		if ( ! is_array( $states ) ) {
			$states = array( $states );
		}

		return static::filter_requests_by_state( $this->requests, $states );
	}

	static protected function filter_requests_by_state( array $requests, $states ) {
		if ( ! is_array( $states ) ) {
			$states = array( $states );
		}
		$results = array();
		foreach ( $requests as $request ) {
			if ( in_array( $request->state, $states ) ) {
				$results[] = $request;
			}
		}

		return $results;
	}

	protected function get_request_by_id( $request_id ) {
		foreach ( $this->requests as $request ) {
			if ( $request->id === $request_id ) {
				return $request;
			}
		}
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
			$parsed       = WPURL::parse( $redirect_url, $request->url );
			if ( false === $parsed ) {
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

	protected function finalize_requests( $requests ) {
		foreach ( $requests as $request ) {
			$this->mark_finished( $request );
		}
	}

}
