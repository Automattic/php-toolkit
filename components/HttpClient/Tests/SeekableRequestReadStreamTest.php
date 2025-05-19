<?php

use PHPUnit\Framework\TestCase;
use WordPress\HttpClient\ByteStream\SeekableRequestReadStream;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

class SeekableRequestReadStreamTest extends TestCase {
	const SEEKABLE_TEST_URL = 'https://raw.githubusercontent.com/WordPress/wordpress-playground/6acbe9bc14d10509319128ef2d31fe53f837355a/packages/playground/website/demos/wordpress.zip';
	static $fixtureContent;
	private $client;

	protected function setUp(): void {
		if ( ! self::$fixtureContent ) {
			self::$fixtureContent = file_get_contents( self::SEEKABLE_TEST_URL );
		}
		$this->client = new Client();
	}

	private function createStream(): SeekableRequestReadStream {
		// Use a file:// URL to avoid network dependency
		$request = new Request( self::SEEKABLE_TEST_URL );

		return new SeekableRequestReadStream( $request, [ 'client' => $this->client ] );
	}

	public function testLength() {
		$stream = $this->createStream();
		$this->assertEquals( strlen( self::$fixtureContent ), $stream->length() );
	}

	public function testTellAndSeek() {
		$stream = $this->createStream();
		$this->assertEquals( 0, $stream->tell() );
		$stream->seek( 10 );
		$this->assertEquals( 10, $stream->tell() );
		$stream->seek( 0 );
		$this->assertEquals( 0, $stream->tell() );
	}

	public function testPullPeekConsume() {
		$stream = $this->createStream();
		$stream->pull( 20 );
		$peeked = $stream->peek( 20 );
		$this->assertEquals( substr( self::$fixtureContent, 0, 20 ), $peeked );
		$consumed = $stream->consume( 20 );
		$this->assertEquals( $peeked, $consumed );
		$this->assertEquals( 20, $stream->tell() );
	}

	public function testConsumeAll() {
		$stream = $this->createStream();
		$all    = $stream->consume_all();
		$this->assertEquals( self::$fixtureContent, $all );
		$this->assertTrue( $stream->reached_end_of_data() );
	}

	public function testReachedEndOfData() {
		$stream = $this->createStream();
		$this->assertFalse( $stream->reached_end_of_data() );
		$stream->consume_all();
		$this->assertTrue( $stream->reached_end_of_data() );
	}

	public function testCloseReading() {
		$stream = $this->createStream();
		$stream->pull( 10 );
		$stream->close_reading();
		$this->expectNotToPerformAssertions(); // No exception means pass
	}
}
