<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\Producer\ResourceProducer;
use WordPress\DataLiberation\EntityReader\EPubEntityReader;
use WordPress\Zip\ZipFilesystem;

class EPubEntityReaderTest extends TestCase {

    /**
     * @dataProvider epub_byte_reader_data_provider
     */
    public function test_entity_reader( $reader ) {
        $zip = ZipFilesystem::create( $reader );
        $reader = new EPubEntityReader( $zip );
        $entities = [];
        while ( $reader->next_entity() ) {
            $data = $reader->get_entity()->get_data();
            $entities[] = [
                'type' => $reader->get_entity()->get_type(),
                'data' => $data,
            ];
        }
        $this->assertEquals( 3, count($entities) );
        $this->assertGreaterThan( 100, strlen($entities[0]['data']['content']) );
        $this->assertGreaterThan( 1000, strlen($entities[1]['data']['content']) );
        $this->assertGreaterThan( 1000, strlen($entities[2]['data']['content']) );
    }

    static public function epub_byte_reader_data_provider() {
        return [
            'Local file' => [
                ResourceProducer::from_local_file( __DIR__ . '/fixtures/epub-entity-reader/childrens-literature.epub' )
            ],
            // github.com does not support range requests
            // 'Remote file' => [
            //     RemoteFileRangedReader::create( 'https://github.com/IDPF/epub3-samples/releases/download/20230704/childrens-literature.epub' )
            // ],
        ];
    }

}
