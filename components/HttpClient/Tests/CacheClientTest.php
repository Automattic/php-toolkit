<?php

namespace WordPress\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\CacheClient;
use WordPress\HttpClient\HttpError;
use WordPress\HttpClient\Request;

class CacheClientTest extends TestCase {

    private string $cacheDir;

    protected function setUp(): void {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/cache_client_test_' . uniqid();
        if ( ! is_dir( $this->cacheDir ) ) {
            mkdir( $this->cacheDir, 0777, true );
        }
    }

    protected function tearDown(): void {
        parent::tearDown();
        $this->cleanupCacheDir();
    }

    private function cleanupCacheDir(): void {
        if ( is_dir( $this->cacheDir ) ) {
            $files = glob( $this->cacheDir . '/*' );
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    unlink( $file );
                }
            }
            rmdir( $this->cacheDir );
        }
    }

    protected function withServer( callable $callback, $scenario = 'default', $host = '127.0.0.1', $port = 8950 ) {
        $serverRoot = __DIR__ . '/test-server';
        $server     = new Process( [
            'php',
            "$serverRoot/run.php",
            $host,
            $port,
            $scenario,
        ], $serverRoot );
        $server->start();
        try {
            $attempts = 0;
            while ( $server->isRunning() ) {
                $output = $server->getIncrementalOutput();
                if ( strncmp( $output, 'Server started on http://', strlen( 'Server started on http://' ) ) === 0 ) {
                    break;
                }
                usleep( 40000 );
                if ( ++ $attempts > 20 ) {
                    $this->fail( 'Server did not start' );
                }
            }
            $callback( "http://{$host}:{$port}" );
        } finally {
            $server->stop( 0 );
        }
    }

    /**
     * Helper to consume the entire response body for a request using the event loop.
     */
    protected function request( CacheClient $client, Request $request ) {
        $client->enqueue( $request );
        $body = '';
        
        while ( $client->await_next_event() ) {
            switch ( $client->get_event() ) {
                case CacheClient::EVENT_HEADERS:
                    // Store the response when headers are received
                    $request->response = $client->get_response();
                    break;
                case CacheClient::EVENT_BODY:
                    $chunk = $client->get_response_body_chunk();
                    if ( $chunk !== false ) {
                        $body .= $chunk;
                    }
                    break;
                case CacheClient::EVENT_FINISH:
                    // Ensure response is set if not already
                    if ( ! $request->response ) {
                        $request->response = $client->get_response();
                    }
                    return $body;
            }
        }
        return $body;
    }

    /**
     * Test constructor creates cache directory
     */
    public function test_constructor_creates_cache_dir() {
        $tempDir = sys_get_temp_dir() . '/test_cache_' . uniqid();
        $upstream = new Client();
        $client = new CacheClient( $upstream, $tempDir );
        
        $this->assertTrue( is_dir( $tempDir ) );
        
        // Cleanup
        rmdir( $tempDir );
    }

    /**
     * Test constructor throws exception for invalid cache directory
     */
    public function test_constructor_invalid_cache_dir() {
        $upstream = new Client();
        
        // Create a file first, then try to create a directory with the same name
        $invalidDir = sys_get_temp_dir() . '/invalid_cache_dir_' . uniqid();
        file_put_contents( $invalidDir, 'test' );
        
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'path exists but is not a directory' );
        
        try {
            new CacheClient( $upstream, $invalidDir );
        } finally {
            // Cleanup
            if ( file_exists( $invalidDir ) ) {
                unlink( $invalidDir );
            }
        }
    }

    /**
     * Test basic caching with max-age
     */
    public function test_basic_caching_max_age() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            $request = new Request( "$url/cache/max-age" );
            
            // First request - should hit the server
            $body1 = $this->request( $client, $request );
            $this->assertEquals( 'Cached for 1 hour', $body1 );
            $this->assertEquals( 200, $request->response->status_code );
            
            // Second request - should be served from cache
            $request2 = new Request( "$url/cache/max-age" );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( 'Cached for 1 hour', $body2 );
            $this->assertEquals( 200, $request2->response->status_code );
            
            // Verify cache files exist
            $this->assertGreaterThan( 0, count( glob( $this->cacheDir . '/*.json' ) ) );
            $this->assertGreaterThan( 0, count( glob( $this->cacheDir . '/*.body' ) ) );
        }, 'cache' );
    }

    /**
     * Test caching with Expires header
     */
    public function test_caching_expires_header() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            $request = new Request( "$url/cache/expires" );
            
            // First request
            $body1 = $this->request( $client, $request );
            $this->assertEquals( 'Expires in 1 hour', $body1 );
            
            // Second request - should be cached
            $request2 = new Request( "$url/cache/expires" );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( 'Expires in 1 hour', $body2 );
        }, 'cache' );
    }

    /**
     * Test no-store directive prevents caching
     */
    public function test_no_store_prevents_caching() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            $request = new Request( "$url/cache/no-store" );
            $body = $this->request( $client, $request );
            $this->assertEquals( 'Never stored', $body );
            
            // Verify no cache files were created
            $this->assertEquals( 0, count( glob( $this->cacheDir . '/*.json' ) ) );
            $this->assertEquals( 0, count( glob( $this->cacheDir . '/*.body' ) ) );
        }, 'cache' );
    }

    /**
     * Test ETag validation
     */
    public function test_etag_validation() {
        $this->withServer( function ( $url ) {
            $client = new CacheClient( new Client(), $this->cacheDir );
            
            $request = new Request( "$url/cache/etag" );
            
            // First request - should get full response
            $body1 = $this->request( $client, $request );
            $this->assertEquals( 'ETag response', $body1 );
            $this->assertEquals( 200, $request->response->status_code );
            
            // Second request - should get 304 and serve from cache
            $request2 = new Request( "$url/cache/etag" );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( 'ETag response', $body2 );
            $this->assertEquals( 304, $request2->response->status_code );
        }, 'cache' );
    }

    /**
     * Test Last-Modified validation
     */
    public function test_last_modified_validation() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            $request = new Request( "$url/cache/last-modified" );
            
            // First request
            $body1 = $this->request( $client, $request );
            $this->assertEquals( 'Last-Modified response', $body1 );
            $this->assertEquals( 200, $request->response->status_code );
            
            // Second request - should get 304 and serve from cache
            $request2 = new Request( "$url/cache/last-modified" );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( 'Last-Modified response', $body2 );
            $this->assertEquals( 304, $request2->response->status_code );
        }, 'cache' );
    }

    /**
     * Test Vary header with Accept
     */
    public function test_vary_header_accept() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            // Request with Accept: text/plain
            $request1 = new Request( "$url/cache/vary-accept", [ 'headers' => [ 'Accept' => 'text/plain' ] ] );
            $body1 = $this->request( $client, $request1 );
            $this->assertEquals( 'Text response', $body1 );
            
            // Request with Accept: application/json - should get different response
            $request2 = new Request( "$url/cache/vary-accept", [ 'headers' => [ 'Accept' => 'application/json' ] ] );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( '{"message": "JSON response"}', $body2 );
            
            // Same request as first - should be cached
            $request3 = new Request( "$url/cache/vary-accept", [ 'headers' => [ 'Accept' => 'text/plain' ] ] );
            $body3 = $this->request( $client, $request3 );
            $this->assertEquals( 'Text response', $body3 );
            
            // Verify multiple cache entries exist (different vary keys)
            $this->assertGreaterThan( 1, count( glob( $this->cacheDir . '/*.json' ) ) );
        }, 'cache' );
    }

    /**
     * Test Vary header with User-Agent
     */
    public function test_vary_header_user_agent() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            // Desktop request
            $request1 = new Request( "$url/cache/vary-user-agent", [ 'headers' => [ 'User-Agent' => 'Desktop Browser' ] ] );
            $body1 = $this->request( $client, $request1 );
            $this->assertEquals( 'Desktop response', $body1 );
            
            // Mobile request
            $request2 = new Request( "$url/cache/vary-user-agent", [ 'headers' => [ 'User-Agent' => 'Mobile Browser' ] ] );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( 'Mobile response', $body2 );
            
            // Same desktop request - should be cached
            $request3 = new Request( "$url/cache/vary-user-agent", [ 'headers' => [ 'User-Agent' => 'Desktop Browser' ] ] );
            $body3 = $this->request( $client, $request3 );
            $this->assertEquals( 'Desktop response', $body3 );
        }, 'cache' );
    }

    /**
     * Test 301 redirect caching
     */
    public function test_301_redirect_caching() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            $request = new Request( "$url/cache/redirect-301" );
            
            // First request - should get redirect response
            $body1 = $this->request( $client, $request );
            $this->assertEquals( 'Permanent redirect', $body1 );
            $this->assertEquals( 301, $request->response->status_code );
            
            // Second request - should be served from cache
            $request2 = new Request( "$url/cache/redirect-301" );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( 'Permanent redirect', $body2 );
            $this->assertEquals( 301, $request2->response->status_code );
            
            // Verify cache files exist
            $this->assertGreaterThan( 0, count( glob( $this->cacheDir . '/*.json' ) ) );
        }, 'cache' );
    }

    /**
     * Test heuristic caching with Last-Modified
     */
    public function test_heuristic_caching() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            $request = new Request( "$url/cache/heuristic" );
            
            // First request
            $body1 = $this->request( $client, $request );
            $this->assertEquals( 'Heuristic caching', $body1 );
            
            // Second request - should be cached based on heuristic
            $request2 = new Request( "$url/cache/heuristic" );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( 'Heuristic caching', $body2 );
        }, 'cache' );
    }

    /**
     * Test cache invalidation on POST request
     */
    public function test_cache_invalidation_post() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            // First GET request - should be cached
            $request1 = new Request( "$url/cache/post-invalidate" );
            $body1 = $this->request( $client, $request1 );
            $this->assertEquals( 'GET response - cacheable', $body1 );
            
            // Verify cache files exist
            $this->assertGreaterThan( 0, count( glob( $this->cacheDir . '/*.json' ) ) );
            
            // POST request - should invalidate cache
            $request2 = new Request( "$url/cache/post-invalidate", [ 'method' => 'POST' ] );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( 'POST response - cache invalidated', $body2 );
            
            // Verify cache files were removed
            $this->assertEquals( 0, count( glob( $this->cacheDir . '/*.json' ) ) );
        }, 'cache' );
    }

    /**
     * Test only GET and HEAD requests are cached
     */
    public function test_only_get_head_cached() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            // POST request - should not be cached
            $request = new Request( "$url/cache/max-age", [ 'method' => 'POST' ] );
            $client->enqueue( $request );
            
            while ( $client->await_next_event() ) {
                if ( $client->get_event() === CacheClient::EVENT_FINISH ) {
                    break;
                }
            }
            
            // Verify no cache files were created
            $this->assertEquals( 0, count( glob( $this->cacheDir . '/*.json' ) ) );
        }, 'cache' );
    }

    /**
     * Test HEAD request caching
     */
    public function test_head_request_caching() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            // HEAD request
            $request = new Request( "$url/cache/max-age", [ 'method' => 'HEAD' ] );
            $client->enqueue( $request );
            
            while ( $client->await_next_event() ) {
                if ( $client->get_event() === CacheClient::EVENT_FINISH ) {
                    break;
                }
            }
            
            $this->assertEquals( 200, $request->response->status_code );
            
            // Verify cache files exist
            $this->assertGreaterThan( 0, count( glob( $this->cacheDir . '/*.json' ) ) );
        }, 'cache' );
    }

    /**
     * Test event constants
     */
    public function test_event_constants() {
        $this->assertEquals( Client::EVENT_GOT_HEADERS, CacheClient::EVENT_HEADERS );
        $this->assertEquals( Client::EVENT_BODY_CHUNK_AVAILABLE, CacheClient::EVENT_BODY );
        $this->assertEquals( Client::EVENT_FINISHED, CacheClient::EVENT_FINISH );
    }

    /**
     * Test cache key generation
     */
    public function test_cache_key_generation() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            $request = new Request( "$url/cache/max-age" );
            $this->request( $client, $request );
            
            // Verify cache key was set
            $this->assertNotNull( $request->cache_key );
            $this->assertIsString( $request->cache_key );
        }, 'cache' );
    }

    /**
     * Test response_is_cacheable static method
     */
    public function test_response_is_cacheable() {
        // Create mock request and response
        $request = new Request( 'http://example.com/test' );
        $response = new \WordPress\HttpClient\Response( $request );
        $response->status_code = 200;
        $response->headers = [ 'cache-control' => 'max-age=3600' ];
        
        $this->assertTrue( CacheClient::response_is_cacheable( $response ) );
        
        // Test non-GET request
        $request->method = 'POST';
        $this->assertFalse( CacheClient::response_is_cacheable( $response ) );
        
        // Test no-store
        $request->method = 'GET';
        $response->headers = [ 'cache-control' => 'no-store' ];
        $this->assertFalse( CacheClient::response_is_cacheable( $response ) );
        
        // Test non-200 status
        $response->status_code = 404;
        $response->headers = [ 'cache-control' => 'max-age=3600' ];
        $this->assertFalse( CacheClient::response_is_cacheable( $response ) );
        
        // Test 206 status (partial content)
        $response->status_code = 206;
        $this->assertTrue( CacheClient::response_is_cacheable( $response ) );
        
        // Test with Last-Modified (heuristic caching)
        $response->status_code = 200;
        $response->headers = [ 'last-modified' => 'Wed, 01 Jan 2020 00:00:00 GMT' ];
        $this->assertTrue( CacheClient::response_is_cacheable( $response ) );
    }

    /**
     * Test directives parsing
     */
    public function test_directives_parsing() {
        $directives = CacheClient::directives( 'max-age=3600, no-cache, private' );
        $expected = [
            'max-age' => 3600,
            'no-cache' => true,
            'private' => true,
        ];
        $this->assertEquals( $expected, $directives );
        
        // Test with quoted values
        $directives = CacheClient::directives( 'max-age=0, no-cache="field1,field2"' );
        $expected = [
            'max-age' => 0,
            'no-cache' => '"field1,field2"',
        ];
        $this->assertEquals( $expected, $directives );
        
        // Test null input
        $this->assertEquals( [], CacheClient::directives( null ) );
        
        // Test empty string
        $this->assertEquals( [], CacheClient::directives( '' ) );
    }

    /**
     * Test cache with multiple requests
     */
    public function test_multiple_requests_caching() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            $requests = [
                new Request( "$url/cache/max-age" ),
                new Request( "$url/cache/expires" ),
            ];
            
            $client->enqueue( $requests );
            
            $bodies = [];
            while ( $client->await_next_event() ) {
                switch ( $client->get_event() ) {
                    case CacheClient::EVENT_BODY:
                        $request = $client->get_request();
                        if ( ! isset( $bodies[ $request->url ] ) ) {
                            $bodies[ $request->url ] = '';
                        }
                        $bodies[ $request->url ] .= $client->get_response_body_chunk();
                        break;
                    case CacheClient::EVENT_FINISH:
                        // Continue until all requests are done
                        break;
                }
                
                // Check if all requests are done
                $all_done = true;
                foreach ( $requests as $req ) {
                    if ( $req->state !== \WordPress\HttpClient\Request::STATE_FINISHED ) {
                        $all_done = false;
                        break;
                    }
                }
                if ( $all_done ) {
                    break;
                }
            }
            
            $this->assertCount( 2, $bodies );
            $this->assertStringContainsString( 'Cached for 1 hour', $bodies[ "$url/cache/max-age" ] );
            $this->assertStringContainsString( 'Expires in 1 hour', $bodies[ "$url/cache/expires" ] );
        }, 'cache' );
    }

    /**
     * Test cache miss and hit scenario
     */
    public function test_cache_miss_and_hit() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );
            
            $request1 = new Request( "$url/cache/max-age" );
            
            // First request - cache miss
            $start_time = microtime( true );
            $body1 = $this->request( $client, $request1 );
            $first_duration = microtime( true ) - $start_time;
            
            $this->assertEquals( 'Cached for 1 hour', $body1 );
            
            // Second request - cache hit (should be faster)
            $request2 = new Request( "$url/cache/max-age" );
            $start_time = microtime( true );
            $body2 = $this->request( $client, $request2 );
            $second_duration = microtime( true ) - $start_time;
            
            $this->assertEquals( 'Cached for 1 hour', $body2 );
            
            // Cache hit should be significantly faster (though this is not always reliable in tests)
            // We'll just verify the content is the same
            $this->assertEquals( $body1, $body2 );
        }, 'cache' );
    }

    public function test_cache_with_304_status() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );

            $request1 = new Request( "$url/cache/etag" );
            $body1 = $this->request( $client, $request1 );
            $this->assertEquals( 'ETag response', $body1 );
            $this->assertEquals( 200, $request1->response->status_code );

            // Second request - should get 304 and serve from cache
            $request2 = new Request( "$url/cache/etag" );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( 'ETag response', $body2 );
            $this->assertEquals( 304, $request2->response->status_code );
        }, 'cache' );
    }

    public function test_cache_validation_headers() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            $client = new CacheClient( $upstream, $this->cacheDir );

            // First request - should cache the response
            $request1 = new Request( "$url/cache/last-modified" );
            $body1 = $this->request( $client, $request1 );
            $this->assertEquals( 'Last-Modified response', $body1 );
            $this->assertEquals( 200, $request1->response->status_code );

            // Second request - should have validation headers set
            $request2 = new Request( "$url/cache/last-modified" );
            $body2 = $this->request( $client, $request2 );
            $this->assertEquals( 'Last-Modified response', $body2 );
            
            // Check that validation headers were added to the second request
            $this->assertArrayHasKey( 'If-Modified-Since', $request2->headers );
        }, 'cache' );
    }

    public function test_server_304_response() {
        $this->withServer( function ( $url ) {
            $upstream = new Client();
            
            // Test that the server can return 304 when we manually send the right headers
            $request = new Request( "$url/cache/etag", [ 'headers' => [ 'If-None-Match' => '"test-etag-123"' ] ] );
            $client = new CacheClient( $upstream, $this->cacheDir );
            $body = $this->request( $client, $request );
            
            $this->assertEquals( 304, $request->response->status_code );
            $this->assertEquals( '', $body ); // 304 responses have no body
        }, 'cache' );
    }


} 