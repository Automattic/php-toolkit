<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\Writer\FileConsumer;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipStreamReader;
use WordPress\Zip\ZipStreamWriter;

class ZipStreamWriterTest extends TestCase {

    private $tempDir = '';
    private $tempSourceFile = '';
    private $tempZipPath = '';

	/**
	 * @before
	 */
	public function before() {
        // Create a temporary directory and file for testing
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zip_test';
        if (!file_exists($this->tempDir)) {
            mkdir($this->tempDir);
        }
        $this->tempSourceFile = tempnam($this->tempDir, 'testfile');
    }

	/**
	 * @after
	 */
	public function after() {
        // Cleanup temporary files and directory
        if (file_exists($this->tempSourceFile)) {
            unlink($this->tempSourceFile);
        }
        if (file_exists($this->tempZipPath)) {
            unlink($this->tempZipPath);
        }
        if (is_dir($this->tempDir)) {
            $this->recursiveRemoveDir($this->tempDir);
        }
    }

    private function recursiveRemoveDir($dir) {
        foreach (scandir($dir) as $file) {
            if ('.' === $file || '..' === $file) continue;
            if (is_dir("$dir/$file")) $this->recursiveRemoveDir("$dir/$file");
            else unlink("$dir/$file");
        }
        rmdir($dir);
    }

    /**
     * @dataProvider shouldDeflateProvider
     */
    public function testWriteFile($should_deflate) {
        $this->tempZipPath = tempnam($this->tempDir, 'testzip');
        touch($this->tempZipPath);

        $pipe = FileConsumer::from_path($this->tempZipPath, 'truncate');
        $zipWriter = new ZipStreamWriter($pipe);
        $zipWriter->append_file(new FileEntry(array(
            'compressionMethod' => $should_deflate ? ZipStreamReader::COMPRESSION_DEFLATE : ZipStreamReader::COMPRESSION_NONE,
            'path' => 'file.txt',
            'body_reader' => new MemoryPipe('Hello')
        )));
        $zipWriter->close();
        $pipe->close();

        // Check that the ZIP file was created and is not empty
        $this->assertFileExists($this->tempZipPath);
        $this->assertGreaterThan(0, filesize($this->tempZipPath));

        // Open the ZIP file and verify its contents
        $zip = new \ZipArchive();
        $zip->open($this->tempZipPath);
        $this->assertTrue($zip->locateName('file.txt') !== false, "The file was not found in the ZIP");
        $fileContent = $zip->getFromName('file.txt');
        $this->assertEquals('Hello', $fileContent, "The file content does not match");
        $zip->close();
    }

    static public function shouldDeflateProvider() {
        return [
            [true],
            [false],
        ];
    }

}

