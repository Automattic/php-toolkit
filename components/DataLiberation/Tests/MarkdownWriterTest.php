<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\EntityWriter\MarkdownWriter;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\DataLiberation\ImportEntity;

class MarkdownWriterTest extends TestCase {

    private $markdown_writer;
    private $filesystem;

    protected function setUp(): void {
        $this->filesystem = InMemoryFilesystem::create();
    }

    public function testAppendEntityPostWithDateScheme() {
        $this->markdown_writer = new MarkdownWriter($this->filesystem, MarkdownWriter::SCHEME_DATE);

        $entity = new ImportEntity('post', [
            'post_title' => 'Test Post',
            'post_date' => '2023-10-01',
            'content' => '<!-- wp:paragraph -->Test Content<!-- /wp:paragraph -->',
            'post_id' => '1'
        ]);
        $this->markdown_writer->append_entity($entity);
        $this->markdown_writer->close_writing();

        $expected_path = '/2023/10/01/test-post.md';
        $this->assertTrue($this->filesystem->is_file($expected_path));
        $this->assertStringContainsString('Test Content', $this->filesystem->get_contents($expected_path));
    }

    public function testAppendEntityPostWithParentTrailScheme() {
        $this->markdown_writer = new MarkdownWriter($this->filesystem, MarkdownWriter::SCHEME_PARENT_TRAIL);

        $entity = new ImportEntity('post', [
            'post_title' => 'Test Post',
            'post_date' => '2023-10-01',
            'content' => '<!-- wp:paragraph -->Test Content<!-- /wp:paragraph -->',
            'post_id' => '1'
        ]);
        $this->markdown_writer->append_entity($entity);
        $this->markdown_writer->close_writing();

        $expected_path = '/test-post.md';
        $this->assertTrue($this->filesystem->is_file($expected_path));
        $this->assertStringContainsString('Test Content', $this->filesystem->get_contents($expected_path));
    }

    public function testAppendEntityPostWithMetaAndDateScheme() {
        $this->markdown_writer = new MarkdownWriter($this->filesystem, MarkdownWriter::SCHEME_DATE);

        $post = new ImportEntity('post', [
            'post_title' => 'Test Post',
            'post_date' => '2023-10-01',
            'content' => '<!-- wp:paragraph -->Test Content<!-- /wp:paragraph -->',
            'post_id' => '1'
        ]);
        $this->markdown_writer->append_entity($post);

        $post_meta = new ImportEntity('post_meta', ['meta_key' => 'key', 'meta_value' => 'value']);
        $this->markdown_writer->append_entity($post_meta);

        $this->markdown_writer->close_writing();

        $expected_path = '/2023/10/01/test-post.md';
        $this->assertTrue($this->filesystem->is_file($expected_path));
        $file_contents = $this->filesystem->get_contents($expected_path);
        $this->assertStringContainsString('Test Content', $file_contents);
        $this->assertStringContainsString('key: "value"', $file_contents);
    }

    public function testAppendMultiplePostsWithMetadataAndDateScheme() {
        $this->markdown_writer = new MarkdownWriter($this->filesystem, MarkdownWriter::SCHEME_PARENT_TRAIL);

        $structure = [
            ['post_id' => 1, 'post_parent' => 0],
            ['post_id' => 2, 'post_parent' => 1],
            ['post_id' => 3, 'post_parent' => 2],
            ['post_id' => 4, 'post_parent' => 2],
            ['post_id' => 5, 'post_parent' => 4],
            ['post_id' => 6, 'post_parent' => 4],
            ['post_id' => 7, 'post_parent' => 0],
        ];

        foreach($structure as $post) {
            $entity = new ImportEntity('post', [
                'post_title' => 'Post ' . $post['post_id'],
                'post_date' => '2023-10-01',
                'content' => '<!-- wp:paragraph -->Content ' . $post['post_id'] . '<!-- /wp:paragraph -->',
                'post_id' => $post['post_id'],
                'post_parent' => $post['post_parent']
            ]);

            $this->markdown_writer->append_entity($entity);
        }

        $this->markdown_writer->close_writing();

        $this->assertTrue($this->filesystem->is_file('/post-1/index.md'));
        $this->assertTrue($this->filesystem->is_file('/post-1/post-2/index.md'));
        $this->assertTrue($this->filesystem->is_file('/post-1/post-2/post-3.md'));
        $this->assertTrue($this->filesystem->is_file('/post-1/post-2/post-4/post-5.md'));
        $this->assertTrue($this->filesystem->is_file('/post-1/post-2/post-4/post-6.md'));
        $this->assertTrue($this->filesystem->is_file('/post-7.md'));

        $this->assertStringContainsString('Content 1', $this->filesystem->get_contents('/post-1/index.md'));
        $this->assertStringContainsString('Content 2', $this->filesystem->get_contents('/post-1/post-2/index.md'));
        $this->assertStringContainsString('Content 3', $this->filesystem->get_contents('/post-1/post-2/post-3.md'));
        $this->assertStringContainsString('Content 5', $this->filesystem->get_contents('/post-1/post-2/post-4/post-5.md'));
        $this->assertStringContainsString('Content 6', $this->filesystem->get_contents('/post-1/post-2/post-4/post-6.md'));
        $this->assertStringContainsString('Content 7', $this->filesystem->get_contents('/post-7.md'));
    }
}
