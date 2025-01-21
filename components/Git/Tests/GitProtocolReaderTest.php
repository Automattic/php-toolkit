<?php

namespace WordPress\Git\Tests;

use WordPress\ByteStream\Reader\ResourceReader;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Protocol\Parser\GitProtocolReader;

class GitProtocolReaderTest extends \PHPUnit\Framework\TestCase {

    public function test_protocol_reader_wordpress_develop() {
        $repo = new GitRepository(InMemoryFilesystem::create());
        
        $upstream = ResourceReader::from_local_file(__DIR__ . '/fixtures/wordpress-develop-response-no-blobs.bin');
        $reader = new GitProtocolReader(
            $upstream,
            ['write_to_repository' => $repo]
        );

        while($reader->next_token()) {
            $reader->get_token_type();
        }
        
        $upstream->close();

        // We just want to see there are no exceptions thrown
        $this->assertTrue(true);
    }
}
