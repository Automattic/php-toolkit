<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ByteStreamException;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

class RequestReadStreamTest extends TestCase {
    private $test_url = 'https://raw.githubusercontent.com/WordPress/gutenberg/0fa123b/schemas/json/font-collection.json';
    
    public function testConstructWithString() {
        $stream = new RequestReadStream($this->test_url);
        $this->assertInstanceOf(RequestReadStream::class, $stream);
        $this->assertInstanceOf(Request::class, $stream->get_request());
        $this->assertEquals($this->test_url, $stream->get_request()->url);
    }
    
    public function testConstructWithRequest() {
        $request = new Request($this->test_url);
        $stream = new RequestReadStream($request);
        $this->assertInstanceOf(RequestReadStream::class, $stream);
        $this->assertSame($request, $stream->get_request());
    }
    
    public function testConstructWithCustomClient() {
        $client = new Client();
        $stream = new RequestReadStream($this->test_url, ['client' => $client]);
        $this->assertInstanceOf(RequestReadStream::class, $stream);
        
        // Cannot directly test that the custom client is used as it's a private property
        // But we can verify the request works
        $response = $stream->get_response();
        $this->assertInstanceOf(Response::class, $response);
    }
    
    public function testGetResponse() {
        $stream = new RequestReadStream($this->test_url);
        $response = $stream->get_response();
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->status_code);
        $this->assertStringContainsString('text/plain', $response->get_header('Content-Type'));
    }
    
    public function testAwaitResponse() {
        $stream = new RequestReadStream($this->test_url);
        $response = $stream->await_response();
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->status_code);
    }
    
    public function testLength() {
        $stream = new RequestReadStream($this->test_url);
        $length = $stream->length();
        
        $this->assertIsInt($length);
        $this->assertGreaterThan(0, $length);
    }
    
    public function testReadingContent() {
        $stream = new RequestReadStream($this->test_url);
        
        $nb_bytes_pulled = $stream->pull(1024);
        $this->assertGreaterThan(0, $nb_bytes_pulled);
        
        $data = $stream->consume($nb_bytes_pulled);
        $this->assertNotEmpty($data);
        $this->assertStringContainsString('{', $data); // JSON should start with {
        
        // Pull more data if available
        if (!$stream->reached_end_of_data()) {
            $nb_bytes_pulled = $stream->pull(1024);
            $this->assertIsInt($nb_bytes_pulled);
            $this->assertGreaterThan(0, $nb_bytes_pulled);
        }
        
        // Test reading to the end
        $stream = new RequestReadStream($this->test_url);
        $all_content = $stream->consume_all();
        $this->assertNotEmpty($all_content);
        $this->assertStringContainsString('font_families', $all_content); // Known content from the JSON
        
        // Verify it's valid JSON
        $json_data = json_decode($all_content, true);
        $this->assertIsArray($json_data);
    }
    
    public function testTell() {
        $stream = new RequestReadStream($this->test_url);
        
        // Pull some data first to initialize the request
        $stream->pull(10);
        $stream->seek(100);
        $this->assertEquals(100, $stream->tell());
    }
    
    public function testReachedEndOfData() {
        $stream = new RequestReadStream($this->test_url);
        
        $this->assertFalse($stream->reached_end_of_data());
        
        // Read all the data
        while (!$stream->reached_end_of_data()) {
            $chunk = $stream->pull(4096);
            $stream->consume(strlen($chunk));
        }
        
        $this->assertTrue($stream->reached_end_of_data());
    }
    
    public function testCloseReading() {
        $stream = new RequestReadStream($this->test_url);
        
        // Read some data to initialize the request
        $stream->pull(10);
        
        // Read everything to finish the request
        while (!$stream->reached_end_of_data()) {
            $chunk = $stream->pull(4096);
            $stream->consume(strlen($chunk));
        }
        
        // Now we can close it without exception
        $stream->close_reading();
        
        // Trying to read after close should throw an exception
        $this->expectException(ByteStreamException::class);
        $stream->pull(10);
    }
} 