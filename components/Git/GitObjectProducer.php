<?php

namespace WordPress\Git;

use WordPress\ByteStream\Producer\ByteProducer;
use WordPress\ByteStream\Producer\InflateProducer;
use WordPress\Git\Protocol\Parser\CommitParser;
use WordPress\Git\Protocol\Parser\TreeParser;

class GitObjectProducer implements ByteProducer {

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

    public function next_bytes($max_bytes=8096): bool {
        $this->ensure_object_header();
        return $this->inflated_body_reader->next_bytes($max_bytes);
    }

    public function get_bytes(): ?string {
        return $this->inflated_body_reader->get_bytes();
    }

    public function seek($offset) {
        $this->ensure_object_header();
        $this->inflated_body_reader->seek($offset);
    }

    public function read_entire_object_contents() {
        $buffer = '';
        while($this->next_bytes()) {
            $buffer .= $this->get_bytes();
        }
        return $buffer;
    }

    public function as_commit() {
        if ( $this->get_object_type_name() !== 'commit' ) {
            throw new GitException( sprintf( 'Object was %s and not a commit in as_commit', $this->get_object_type_name() ) );
        }
        return CommitParser::parse($this->read_entire_object_contents());
    }

    public function as_tree() {
        if ( $this->get_object_type_name() !== 'tree' ) {
            throw new GitException( sprintf( 'Object was %s and not a tree in as_tree', $this->get_object_type_name() ) );
        }
        return TreeParser::parse_entire_tree($this->read_entire_object_contents());
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
		while ( $this->upstream->next_bytes(1) ) {
			$byte = $this->upstream->get_bytes();
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
    }

    public function length(): int {
        $this->ensure_object_header();
        return $this->uncompressed_length;
    }

    public function tell(): int {
        $this->ensure_object_header();
        return $this->inflated_body_reader->tell();
    }

    public function reached_end_of_data(): bool {
        return $this->inflated_body_reader->reached_end_of_data();
    }

    public function close(): void {
        $this->inflated_body_reader->close();
    }

}
