<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\Writer\FileConsumer;

class FileConsumerTest extends TestCase {
    public function testExample() {
        // Example test case
        $this->assertTrue(true);
    }

    public function testCreateFileWriterFromPath() {
        $writer = FileConsumer::from_path('test.txt');
        $this->assertInstanceOf(FileConsumer::class, $writer);
    }

    public function testAppendBytesToFile() {
        $writer = FileConsumer::from_path('test.txt');
        $writer->append_bytes('Hello');
        $this->assertFileExists('test.txt');
    }

    public function testCloseFileWriter() {
        $writer = FileConsumer::from_path('test.txt');
        $writer->close();

        // We just want to see there are no exceptions thrown
        $this->assertTrue(true);
    }

}
