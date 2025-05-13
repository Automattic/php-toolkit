<?php

namespace WordPress\Blueprints\Tests\Unit\DataReference;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\DataReference\DataReferenceResolver;
use WordPress\Blueprints\DataReference\URLReference;
use WordPress\Blueprints\DataReference\WordPressOrgPlugin;
use WordPress\Blueprints\DataReference\WordPressOrgTheme;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\DataReference\InlineFile;
use WordPress\Blueprints\DataReference\InlineDirectory;
use WordPress\Blueprints\DataReference\GitPath;
use WordPress\Blueprints\DataReference\WordPressReference;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Filesystem\Filesystem;
use WordPress\HttpClient\Client;

class DataReferenceResolverTest extends TestCase
{
    /** @var Client&\PHPUnit\Framework\MockObject\MockObject */
    protected $client;
    protected $resolver;
    /** @var Filesystem&\PHPUnit\Framework\MockObject\MockObject */
    protected $executionContext;
    protected $tracker;

    protected function setUp(): void
    {
        // TODO: Mock Client and Filesystem as needed
        $this->client = $this->createMock(Client::class);
        $this->resolver = new DataReferenceResolver($this->client);
        $this->executionContext = $this->createMock(Filesystem::class);
        $this->tracker = $this->createMock(Tracker::class);
        $this->resolver->setExecutionContext($this->executionContext);
    }

    public function testResolveURLReference()
    {
        $url = 'https://example.com/file.zip';
        $reference = new URLReference($url);
        $dummyStream = $this->createMock(\WordPress\ByteStream\ReadStream\ByteReadStream::class);
        $this->client->expects($this->once())
            ->method('fetch')
            ->with($url, $this->arrayHasKey('progress_tracker'))
            ->willReturn($dummyStream);

        $result = $this->resolver->resolve($reference, $this->tracker);
        $this->assertInstanceOf(\WordPress\Blueprints\DataReference\File::class, $result);
        $this->assertSame($dummyStream, $result->stream);
        $this->assertEquals('file.zip', $result->filename);
    }

    public function testResolveWordPressOrgPlugin()
    {
        $reference = new WordPressOrgPlugin('akismet');
        $expectedUrl = 'https://downloads.wordpress.org/plugin/akismet.latest-stable.zip';
        $dummyStream = $this->createMock(\WordPress\ByteStream\ReadStream\ByteReadStream::class);
        $this->client->expects($this->once())
            ->method('fetch')
            ->with($expectedUrl, $this->arrayHasKey('progress_tracker'))
            ->willReturn($dummyStream);

        $result = $this->resolver->resolve($reference, $this->tracker);
        $this->assertInstanceOf(\WordPress\Blueprints\DataReference\File::class, $result);
        $this->assertSame($dummyStream, $result->stream);
        $this->assertEquals('akismet.latest-stable.zip', $result->filename);
    }

    public function testResolveWordPressOrgTheme()
    {
        $reference = new WordPressOrgTheme('twentytwentyfour');
        $expectedUrl = 'https://downloads.wordpress.org/theme/twentytwentyfour.latest-stable.zip';
        $dummyStream = $this->createMock(\WordPress\ByteStream\ReadStream\ByteReadStream::class);
        $this->client->expects($this->once())
            ->method('fetch')
            ->with($expectedUrl, $this->arrayHasKey('progress_tracker'))
            ->willReturn($dummyStream);

        $result = $this->resolver->resolve($reference, $this->tracker);
        $this->assertInstanceOf(\WordPress\Blueprints\DataReference\File::class, $result);
        $this->assertSame($dummyStream, $result->stream);
        $this->assertEquals('twentytwentyfour.latest-stable.zip', $result->filename);
    }

    public function testResolveExecutionContextPathFile()
    {
        $reference = new ExecutionContextPath('./foo.txt');
        $this->executionContext->method('exists')->with('./foo.txt')->willReturn(true);
        $this->executionContext->method('is_file')->with('./foo.txt')->willReturn(true);
        $this->executionContext->method('is_dir')->with('./foo.txt')->willReturn(false);
        $dummyStream = $this->createMock(\WordPress\ByteStream\ReadStream\ByteReadStream::class);
        $this->executionContext->method('open_read_stream')->with('./foo.txt')->willReturn($dummyStream);

        $result = $this->resolver->resolve($reference, $this->tracker);
        $this->assertInstanceOf(\WordPress\Blueprints\DataReference\File::class, $result);
        $this->assertEquals('foo.txt', $result->filename);
    }

    public function testResolveExecutionContextPathDirectory()
    {
        $reference = new ExecutionContextPath('./bar');
        $this->executionContext->method('exists')->with('./bar')->willReturn(true);
        $this->executionContext->method('is_file')->with('./bar')->willReturn(false);
        $this->executionContext->method('is_dir')->with('./bar')->willReturn(true);
        // ChrootLayer is used, but we just check Directory is returned
        $result = $this->resolver->resolve($reference, $this->tracker);
        $this->assertInstanceOf(\WordPress\Blueprints\DataReference\Directory::class, $result);
        $this->assertEquals('bar', $result->dirname);
    }

    public function testResolveInlineFile()
    {
        $reference = new InlineFile('baz.txt', 'hello world');
        $result = $this->resolver->resolve($reference, $this->tracker);
        $this->assertInstanceOf(\WordPress\Blueprints\DataReference\File::class, $result);
        $this->assertEquals('baz.txt', $result->filename);
        $this->assertInstanceOf(\WordPress\ByteStream\MemoryPipe::class, $result->stream);
        $result->stream->seek(0);
        $this->assertEquals('hello world', $result->stream->consume_all());
    }

    public function testResolveInlineDirectory()
    {
        $children = [new InlineFile('child.txt', 'child content')];
        $reference = new InlineDirectory('dir', $children);
        $result = $this->resolver->resolve($reference, $this->tracker);
        $this->assertInstanceOf(\WordPress\Blueprints\DataReference\Directory::class, $result);
        $fs = $result->filesystem;
        $this->assertTrue($fs->is_file('child.txt'));
        $stream = $fs->open_read_stream('child.txt');
        $this->assertEquals('child content', $stream->consume_all());
    }

	/**
	 * @TODO: Don't rely on the Playground repository for this test. Either
	 *        create a dedicated test repository on GitHub or start one locally
	 *        just for this test.
	 */
    public function testResolveGitPath()
    {
        // This test will be limited, as GitRepository and GitFilesystem are not easily mockable here.
        // We'll just check that Directory is returned and the dirname is as expected.
        $reference = new GitPath(
			'https://github.com/WordPress/wordpress-playground.git',
			// @TODO: Support an exact commit hash here
			'refs/heads/trunk',
			'tools/scripts'
		);
        $result = $this->resolver->resolve($reference, $this->tracker);
        $this->assertInstanceOf(\WordPress\Blueprints\DataReference\Directory::class, $result);
        $this->assertEquals('scripts', $result->dirname);
		$this->assertEquals(
			[
				'publish.mjs'
			],
			$result->filesystem->ls('/')
		);
    }

    public function testResolveMissingExecutionContextFileThrows()
    {
        $reference = new ExecutionContextPath('./missing.txt');
        $this->executionContext->method('exists')->with('./missing.txt')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        $this->resolver->resolve($reference, $this->tracker);
    }

    public function testResolveUnsupportedReferenceTypeThrows()
    {
        $reference = $this->getMockForAbstractClass(DataReference::class);
        $this->expectException(\Exception::class);
        $this->resolver->resolve($reference, $this->tracker);
    }

    public function testResolveURLReferenceFetchFailureThrows()
    {
        $url = 'https://example.com/fail.zip';
        $reference = new URLReference($url);
        $this->client->expects($this->once())
            ->method('fetch')
            ->with($url, $this->arrayHasKey('progress_tracker'))
            ->will($this->throwException(new \RuntimeException('Fetch failed')));
        $this->expectException(\RuntimeException::class);
        $this->resolver->resolve($reference, $this->tracker);
    }
} 