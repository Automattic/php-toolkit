<?php

namespace WordPress\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

class CacheMiddlewareIntegrationTest extends TestCase {

	private static $server_process;
	private static string $server_host = '127.0.0.1';
	private static int $server_port = 8951;
	private string $cache_dir;
	private Client $client;

	public static function setUpBeforeClass(): void {
		// Start test server
		$cmd = sprintf(
			'php %s %s %d cache > /dev/null 2>&1 &',
			escapeshellarg( __DIR__ . '/test-server/run.php' ),
			escapeshellarg( self::$server_host ),
			self::$server_port
		);
		exec( $cmd );
		
		// Wait for server to start
		$start_time = time();
		while ( time() - $start_time < 5 ) {
			$connection = @fsockopen( self::$server_host, self::$server_port, $errno, $errstr, 1 );
			if ( $connection ) {
				fclose( $connection );
				break;
			}
			usleep( 100000 ); // 100ms
		}
	}

	public static function tearDownAfterClass(): void {
		// Kill server
		exec( "pkill -f 'run.php.*cache'" );
	}

	protected function setUp(): void {
		$this->cache_dir = sys_get_temp_dir() . '/http_cache_integration_test_' . uniqid();
		mkdir( $this->cache_dir, 0777, true );
		
		// Client constructor automatically sets up CacheMiddleware when cache_dir is provided
		$this->client = new Client( [ 'cache_dir' => $this->cache_dir ] );
	}

	protected function tearDown(): void {
		$this->removeDirectory( $this->cache_dir );
	}

	private function removeDirectory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), [ '.', '..' ] );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->removeDirectory( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	private function getServerUrl( string $endpoint ): string {
		return sprintf( 'http://%s:%d/cache/%s', self::$server_host, self::$server_port, $endpoint );
	}

	private function makeRequest( string $url, string $method = 'GET', array $headers = [] ): array {
		$request = new Request( $url, [ 'method' => $method ] );
		$request->headers = array_merge( $request->headers, $headers );
		
		$this->client->enqueue( $request );
		
		$response_data = [
			'status_code' => null,
			'headers' => [],
			'body' => '',
		];
		
		// Process events
		while ( $this->client->await_next_event() ) {
			$event = $this->client->get_event();
			$current_request = $this->client->get_request();
			
			if ( $current_request->id !== $request->id ) {
				continue; // Not our request
			}
			
			if ( $event === Client::EVENT_GOT_HEADERS ) {
				$response = $this->client->get_response();
				$response_data['status_code'] = $response->status_code;
				$response_data['headers'] = $response->headers;
			} elseif ( $event === Client::EVENT_BODY_CHUNK_AVAILABLE ) {
				$chunk = $this->client->get_response_body_chunk();
				$response_data['body'] .= $chunk;
			} elseif ( $event === Client::EVENT_FINISHED ) {
				break;
			} elseif ( $event === Client::EVENT_FAILED ) {
				throw new \Exception( 'Request failed' );
			}
		}
		
		return $response_data;
	}

	private function resetCounter(): void {
		$this->makeRequest( $this->getServerUrl( 'reset-counter' ) );
	}

	public function test_max_age_caching(): void {
		$this->resetCounter();
		
		// First request should hit server
		$response1 = $this->makeRequest( $this->getServerUrl( 'counter' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'Request count: 1', $response1['body'] );
		
		// Second request should hit cache
		$response2 = $this->makeRequest( $this->getServerUrl( 'counter' ) );
		$this->assertEquals( 200, $response2['status_code'] );
		$this->assertEquals( 'Request count: 1', $response2['body'] ); // Same count = cache hit
		
		// Verify cache files exist
		$cache_files = glob( $this->cache_dir . '/*.json' );
		$this->assertNotEmpty( $cache_files );
	}

	public function test_no_store_not_cached(): void {
		// no-store responses should not be cached
		$response1 = $this->makeRequest( $this->getServerUrl( 'no-store' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'Never stored', $response1['body'] );
		
		// Verify no cache files created
		$cache_files = glob( $this->cache_dir . '/*.json' );
		$this->assertEmpty( $cache_files );
	}

	public function test_etag_validation(): void {
		// First request should cache the response
		$response1 = $this->makeRequest( $this->getServerUrl( 'etag' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'ETag response', $response1['body'] );
		
		// Second request should send If-None-Match and get cached content (middleware handles 304 internally)
		$response2 = $this->makeRequest( $this->getServerUrl( 'etag' ) );
		$this->assertEquals( 200, $response2['status_code'] );
		$this->assertEquals( 'ETag response', $response2['body'] );
	}

	public function test_last_modified_validation(): void {
		// First request should cache the response
		$response1 = $this->makeRequest( $this->getServerUrl( 'last-modified' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'Last-Modified response', $response1['body'] );
		
		// Second request should send If-Modified-Since and get cached content
		$response2 = $this->makeRequest( $this->getServerUrl( 'last-modified' ) );
		$this->assertEquals( 200, $response2['status_code'] );
		$this->assertEquals( 'Last-Modified response', $response2['body'] );
	}

	public function test_vary_header_different_responses(): void {
		// First request with JSON Accept header
		$response1 = $this->makeRequest( 
			$this->getServerUrl( 'vary-accept' ), 
			'GET', 
			[ 'Accept' => 'application/json' ] 
		);
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertStringContainsString( 'JSON response', $response1['body'] );
		
		// Second request with different Accept header should not hit cache
		$response2 = $this->makeRequest( 
			$this->getServerUrl( 'vary-accept' ), 
			'GET', 
			[ 'Accept' => 'text/html' ] 
		);
		$this->assertEquals( 200, $response2['status_code'] );
		$this->assertStringContainsString( 'Text response', $response2['body'] );
		
		// Third request with same Accept as first should hit cache
		$response3 = $this->makeRequest( 
			$this->getServerUrl( 'vary-accept' ), 
			'GET', 
			[ 'Accept' => 'application/json' ] 
		);
		$this->assertEquals( 200, $response3['status_code'] );
		$this->assertStringContainsString( 'JSON response', $response3['body'] );
	}

	public function test_large_body_caching(): void {
		// Test caching of large response body (>64KB)
		$response1 = $this->makeRequest( $this->getServerUrl( 'large-body' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$body1 = $response1['body'];
		$this->assertGreaterThan( 64 * 1024, strlen( $body1 ) ); // Should be >64KB
		
		// Second request should hit cache
		$response2 = $this->makeRequest( $this->getServerUrl( 'large-body' ) );
		$this->assertEquals( 200, $response2['status_code'] );
		$body2 = $response2['body'];
		
		// Bodies should be identical
		$this->assertEquals( $body1, $body2 );
		$this->assertEquals( strlen( $body1 ), strlen( $body2 ) );
	}

	public function test_s_maxage_caching(): void {
		// Test s-maxage directive
		$response1 = $this->makeRequest( $this->getServerUrl( 's-maxage' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'Shared cache for 2 hours, private cache for 1 hour', $response1['body'] );
		
		// Should be cached due to s-maxage
		$response2 = $this->makeRequest( $this->getServerUrl( 's-maxage' ) );
		$this->assertEquals( 200, $response2['status_code'] );
		$this->assertEquals( 'Shared cache for 2 hours, private cache for 1 hour', $response2['body'] );
		
		// Verify cache files exist
		$cache_files = glob( $this->cache_dir . '/*.json' );
		$this->assertNotEmpty( $cache_files );
	}

	public function test_must_revalidate_behavior(): void {
		// Test must-revalidate directive
		$response1 = $this->makeRequest( $this->getServerUrl( 'must-revalidate' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'Must revalidate when stale', $response1['body'] );
		
		// Should be cached while fresh
		$response2 = $this->makeRequest( $this->getServerUrl( 'must-revalidate' ) );
		$this->assertEquals( 200, $response2['status_code'] );
		$this->assertEquals( 'Must revalidate when stale', $response2['body'] );
	}

	public function test_multiple_vary_headers(): void {
		// Test response that varies on multiple headers
		$response1 = $this->makeRequest( 
			$this->getServerUrl( 'vary-multiple' ), 
			'GET', 
			[ 'Accept' => 'application/json', 'Accept-Encoding' => 'gzip' ] 
		);
		$this->assertEquals( 200, $response1['status_code'] );
		
		// Different Accept-Encoding should not hit cache
		$response2 = $this->makeRequest( 
			$this->getServerUrl( 'vary-multiple' ), 
			'GET', 
			[ 'Accept' => 'application/json', 'Accept-Encoding' => 'deflate' ] 
		);
		$this->assertEquals( 200, $response2['status_code'] );
		
		// Different responses due to different Accept-Encoding
		$this->assertNotEquals( $response1['body'], $response2['body'] );
	}

	public function test_post_invalidates_cache(): void {
		$this->resetCounter();
		$url = $this->getServerUrl( 'post-invalidate' );
		
		// First GET should cache
		$response1 = $this->makeRequest( $url );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'GET response - cacheable', $response1['body'] );
		
		// Second GET should hit cache
		$response2 = $this->makeRequest( $url );
		$this->assertEquals( 200, $response2['status_code'] );
		$this->assertEquals( 'GET response - cacheable', $response2['body'] );
		
		// POST should invalidate cache
		$response3 = $this->makeRequest( $url, 'POST' );
		$this->assertEquals( 200, $response3['status_code'] );
		$this->assertEquals( 'POST response - cache invalidated', $response3['body'] );
		
		// Verify cache was invalidated
		$cache_files = glob( $this->cache_dir . '/*.json' );
		$this->assertEmpty( $cache_files );
	}

	public function test_expired_response(): void {
		// Test already expired response
		$response1 = $this->makeRequest( $this->getServerUrl( 'expired' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'Already expired response', $response1['body'] );
		
		// Should not be cached due to being already expired
		$cache_files = glob( $this->cache_dir . '/*.json' );
		$this->assertEmpty( $cache_files );
	}

	public function test_zero_max_age(): void {
		// Test max-age=0 response
		$response1 = $this->makeRequest( $this->getServerUrl( 'zero-max-age' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'Zero max-age response', $response1['body'] );
		
		// Should not be cached due to max-age=0
		$cache_files = glob( $this->cache_dir . '/*.json' );
		$this->assertEmpty( $cache_files );
	}

	public function test_both_validators(): void {
		// Test response with both ETag and Last-Modified
		$response1 = $this->makeRequest( $this->getServerUrl( 'both-validators' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'Response with both ETag and Last-Modified', $response1['body'] );
		
		// Second request should use validation
		$response2 = $this->makeRequest( $this->getServerUrl( 'both-validators' ) );
		$this->assertEquals( 200, $response2['status_code'] );
		$this->assertEquals( 'Response with both ETag and Last-Modified', $response2['body'] );
	}

	public function test_heuristic_caching(): void {
		// Test heuristic caching with only Last-Modified
		$response1 = $this->makeRequest( $this->getServerUrl( 'no-explicit-cache' ) );
		$this->assertEquals( 200, $response1['status_code'] );
		$this->assertEquals( 'No explicit cache headers, only Last-Modified for heuristic caching', $response1['body'] );
		
		// Should be cached using heuristic rules
		$response2 = $this->makeRequest( $this->getServerUrl( 'no-explicit-cache' ) );
		$this->assertEquals( 200, $response2['status_code'] );
		$this->assertEquals( 'No explicit cache headers, only Last-Modified for heuristic caching', $response2['body'] );
		
		// Verify cache files exist
		$cache_files = glob( $this->cache_dir . '/*.json' );
		$this->assertNotEmpty( $cache_files );
	}
} 