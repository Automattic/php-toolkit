<?php

namespace WordPress\Git;

use WordPress\ByteStream\Transformer\ChecksumTransformer;
use WordPress\ByteStream\Transformer\DeflateTransformer;
use WordPress\ByteStream\Writer\ByteConsumer;
use WordPress\ByteStream\Writer\TransformedConsumer;

class GitObjectEncoder implements ByteConsumer {

    private $downstream;
    private $repository;

    public function __construct($repository, $object_type_name, $object_length) {
        switch($object_type_name) {
            case 'blob':
            case 'tree':
            case 'commit':
                break;
            default:
                throw new GitException(sprintf(
                    "Invalid object type passed to GitObjectWriter: %s. Only blob, tree and commit are supported.",
                    $object_type_name
                ));
        }

        $this->repository = $repository;

        $header = "$object_type_name $object_length\x00";
        $this->downstream = new TransformedConsumer(
            $repository->get_object_storage_filesystem()->open_write_stream('objects/.tmp'),
            [
                'checksum' => new ChecksumTransformer('sha1'),
            ]
        );
        $this->downstream->append_bytes($header);
        $this->downstream['deflate'] = new DeflateTransformer(ZLIB_ENCODING_DEFLATE);
    }

    public function append_bytes($data): void {
        $this->downstream->append_bytes($data);
    }

    public function get_hash(): string {
        return $this->downstream['checksum']->get_hash();
    }

    public function close(): void {
        $this->downstream->close();
        $hash = $this->downstream['checksum']->get_hash();
        $this->downstream->get_downstream_writer()->close();
        $target_path = $this->repository->get_storage_path($hash);
        $target_dir = dirname($target_path);
        $fs = $this->repository->get_object_storage_filesystem();
        if(!$fs->is_dir($target_dir)) {
            $fs->mkdir($target_dir, ['recursive' => true]);
        }
        $fs->rename('objects/.tmp', $target_path);
    }

}
