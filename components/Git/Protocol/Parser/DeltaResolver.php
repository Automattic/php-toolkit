<?php

namespace WordPress\Git\Protocol\Parser;

use WordPress\ByteStream\Producer\ReaderUtils;
use WordPress\ByteStream\NotEnoughDataException;
use WordPress\ByteStream\Producer\ByteProducer;
use WordPress\Git\GitException;
use WordPress\Git\GitObjectProducer;

class DeltaResolver {

    /**
     * Source repository
     *
     * @var GitObjectProducer
     */
    private $base_object_reader;

    /**
     * Delta reader
     *
     * @var ByteProducer
     */
    private $delta_reader;

    private $base_length = null;
    private $target_length = null;
    private $resolved_chunk = '';
    private $is_paused_on_incomplete_input = false;

    public function __construct(GitObjectProducer $base_object_reader, ByteProducer $delta_reader) {
        $this->base_object_reader    = $base_object_reader;
        $this->delta_reader          = $delta_reader;
    }

    public function get_base_reader() {
        return $this->base_object_reader;
    }

    public function resolve_buffers_lengths() {
        if(null !== $this->base_length && null !== $this->target_length) {
            return false;
        }

        $this->base_object_reader->read_header();

        try {
            $revert_to = $this->delta_reader->tell();
            if(null === $this->base_length) {
                $this->base_length = $this->read_variable_length();
                if($this->base_length !== $this->base_object_reader->get_uncompressed_size()) {
                    throw new GitException(sprintf(
                        'Base length mismatch. Delta declared %d bytes, but base reader has %d bytes',
                        $this->base_length,
                        $this->base_object_reader->get_uncompressed_size()
                    ));
                }
                $revert_to = $this->delta_reader->tell();
            }

            if(null === $this->target_length) {
                $this->target_length = $this->read_variable_length();
            }

            return true;
        } catch (NotEnoughDataException $e) {
            $this->delta_reader->seek($revert_to);
            $this->is_paused_on_incomplete_input = true;
            return false;
        }
    }

	private function read_variable_length() {
		$result = 0;
		$shift  = 0;
		do {
			$byte = ord( ReaderUtils::read_exactly_n_bytes($this->delta_reader, 1) );
			$result |= ( $byte & 0x7F ) << $shift;
			$shift  += 7;
		} while ( $byte & 0x80 );
		return $result;
	}

    public function get_resolved_chunk() {
        return $this->resolved_chunk;
    }

    public function get_expected_target_length() {
        return $this->target_length;
    }

    public function resolve_next_chunk() {
        // Don't resolve body chunks until we know the source and target lengths
        if(null === $this->target_length) {
            $this->resolve_buffers_lengths();
            if(null === $this->target_length) {
                return false;
            }
        }

        $this->is_paused_on_incomplete_input = false;
        $start = $this->delta_reader->tell();
        try {
            $this->resolved_chunk = '';
            if($this->delta_reader->reached_end_of_data()) {
                return false;
            }
            $command_byte = ord( ReaderUtils::read_exactly_n_bytes($this->delta_reader, 1) );
            if ( $command_byte & 0b10000000 ) {
                $copyOffset = 0;
                $copySize   = 0;

                $needed_bytes = 0;
                for($i = 0; $i < 7; $i++) {
                    if ( $command_byte & (1 << $i) ) {
                        $needed_bytes++;
                    }
                }

                // @TODO: instead of failing, wait for $needed_bytes to be available with a timeout.
                $offset_bytes = ReaderUtils::read_exactly_n_bytes($this->delta_reader, $needed_bytes);
                $read_offset = 0;
                if ( $command_byte & 0b00000001 ) {
                    $copyOffset |= ord( $offset_bytes[$read_offset++] );
                }
                if ( $command_byte & 0b00000010 ) {
                    $copyOffset |= ord( $offset_bytes[$read_offset++] ) << 8;
                }
                if ( $command_byte & 0b00000100 ) {
                    $copyOffset |= ord( $offset_bytes[$read_offset++] ) << 16;
                }
                if ( $command_byte & 0b00001000 ) {
                    $copyOffset |= ord( $offset_bytes[$read_offset++] ) << 24;
                }
                if ( $command_byte & 0b00010000 ) {
                    $copySize |= ord( $offset_bytes[$read_offset++] );
                }
                if ( $command_byte & 0b00100000 ) {
                    $copySize |= ord( $offset_bytes[$read_offset++] ) << 8;
                }
                if ( $command_byte & 0b01000000 ) {
                    $copySize |= ord( $offset_bytes[$read_offset++] ) << 16;
                }
                if ( $copySize === 0 ) {
                    $copySize = 0x10000;
                }
                $this->base_object_reader->seek($copyOffset);
                $this->resolved_chunk = ReaderUtils::read_exactly_n_bytes($this->base_object_reader, $copySize);
            } else {
                $this->resolved_chunk = ReaderUtils::read_exactly_n_bytes($this->delta_reader, $command_byte);
            }
            return true;
        } catch (NotEnoughDataException $e) {
            $this->is_paused_on_incomplete_input = true;
            $this->resolved_chunk = '';
            $this->delta_reader->seek($start);
            return false;
        }
    }

    public function is_paused_on_incomplete_input() {
        return $this->is_paused_on_incomplete_input;
    }

}
