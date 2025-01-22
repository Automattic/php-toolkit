<?php

namespace WordPress\Git;

use WordPress\ByteStream\Producer\BaseByteProducer;
use WordPress\ByteStream\Producer\ByteProducer;
use WordPress\ByteStream\Producer\InflateProducer;
use WordPress\Git\Protocol\Parser\CommitParser;
use WordPress\Git\Protocol\Parser\TreeParser;

class GitObjectProducer extends BaseByteProducer {

    private $object_header;
    private $object_type_name;
    private $uncompressed_length;

    /**
     * @var ByteProducer
     */
    private $upstream;

    /**
     * @var InflateProducer
     */
    private $inflated_body_reader;

    protected $inflate_encoding = ZLIB_ENCODING_DEFLATE;

    public function __construct(ByteProducer $upstream) {
        $this->upstream = $upstream;
        $this->inflated_body_reader = new InflateProducer($upstream);
    }

    public function get_object_type_name() {
        if(!$this->object_header) {
            return false;
        }
        return $this->object_type_name;
    }

    public function get_uncompressed_size() {
        if(!$this->object_header) {
            return false;
        }
        return $this->uncompressed_length;
    }

    protected function internal_pull($n): string {
        $this->ensure_object_header();
        $this->inflated_body_reader->pull($n);
        return $this->inflated_body_reader->peek($n);
    }

    public function as_commit() {
        if ( $this->get_object_type_name() !== 'commit' ) {
            throw new GitException( sprintf( 'Object was %s and not a commit in as_commit', $this->get_object_type_name() ) );
        }
        return CommitParser::parse($this->consume_all());
    }

    public function as_tree() {
        if ( $this->get_object_type_name() !== 'tree' ) {
            throw new GitException( sprintf( 'Object was %s and not a tree in as_tree', $this->get_object_type_name() ) );
        }
        return TreeParser::parse_entire_tree($this->consume_all());
    }

    public function read_header() {
        if($this->object_header) {
            return;
        }
        $this->ensure_object_header();
    }

    private function ensure_object_header() {
        if(null !== $this->object_header) {
            return;
        }
		// Read the object header and initialize the internal state
		// for the specific get_* methods below.
		$header  = '';
        $byte = '';
		while ( $this->upstream->pull(1) ) {
			$byte = $this->upstream->consume(1);
            $header .= $byte;
            if("\x00" === $byte) {
                break;
            }
		}

		if ( false === strpos($header, "\x00") ) {
			throw new GitException('Failed to read the object header');
		}

        $this->object_header = $header;

        $type_length = strpos( $header, ' ' );
		$this->object_type_name = substr( $header, 0, $type_length );

        $length_as_string = substr($header, $type_length + 1);
        $this->uncompressed_length = intval($length_as_string);
        $this->expected_length = $this->uncompressed_length;
    }

    protected function seek_outside_of_buffer(int $target_offset): void {
        $this->ensure_object_header();
        $this->inflated_body_reader->seek($target_offset);
        
	    $this->buffer = '';
		$this->offset_in_current_buffer = 0;
		$this->bytes_already_forgotten = $target_offset;
    }

    protected function internal_reached_end_of_data(): bool {
        return $this->inflated_body_reader->reached_end_of_data();
    }

    protected function internal_close(): void {
        $this->inflated_body_reader->close();
    }

}
