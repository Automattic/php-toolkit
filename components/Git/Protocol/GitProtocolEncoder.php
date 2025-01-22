<?php

namespace WordPress\Git\Protocol;

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\Producer\BaseByteProducer;
use WordPress\ByteStream\Transformer\ChecksumTransformer;
use WordPress\ByteStream\Transformer\DeflateTransformer;
use WordPress\ByteStream\Writer\TransformedConsumer;
use WordPress\Git\Model\Tree;
use WordPress\Git\Protocol\Parser\PackParser;

class GitProtocolEncoder extends BaseByteProducer {

    private $object_reader;
    private $packfile_pipe;
    private $packfile_writer;
    private $packfile_object_body_writer;
    private $operation_queue = [];
    private $operation;

	public static function encode_packet_lines( array $payloads, $channel_code = '' ): string {
		$lines = array();
		foreach ( $payloads as $payload ) {
			$lines[] = self::encode_packet_line( $payload, $channel_code );
		}
		return implode( '', $lines );
	}

	public static function encode_packet_line( string $payload, $channel_code = '' ): string {
		if ( $payload === '0000' || $payload === '0001' || $payload === '0002' ) {
			return $channel_code . $payload;
		}

        if('' !== $channel_code) {
            $payload = $channel_code . $payload;
        }
        $length  = sprintf( '%04x', strlen( $payload ) + 4 );
        return $length . $payload;
	}

	static public function encode_variable_length( $number ) {
		$result = '';
		do {
			$byte = $number & 0x7F;
			$number >>= 7;
			if ( $number > 0 ) {
				$byte |= 0x80;
			}
			$result .= chr( $byte );
		} while ( $number > 0 );
		return $result;
	}

	static public function encode_tree_bytes( Tree $tree ) {
		$tree_bytes = '';
		foreach ( $tree->entries as $entry ) {
			$tree_bytes .= $entry->mode . ' ' . $entry->name . "\0" . hex2bin( $entry->hash );
		}
		return $tree_bytes;
	}

    protected function internal_pull($n): string {
        if(null === $this->operation) {
            $this->operation = array_shift($this->operation_queue);
        }

        $operation = $this->operation;
        switch ($operation['type']) {
            case 'progress':
            case 'error':
            case 'sideband':
            case 'packet-line':
                $this->operation = null;
                return self::encode_packet_line($operation['chunk'], $operation['channel_code']);

            case 'packet-lines':
                $this->operation = null;
                return self::encode_packet_lines($operation['chunk'], $operation['channel_code']);

            case 'packfile':
                if (!$this->append_packfile_data()) {
                    $this->operation = null;
                    return $this->pull($n);
                }
                $available = $this->packfile_pipe->pull(8096);
                $chunk = $this->packfile_pipe->consume($available);
                return $chunk;
            default:
                return '';
        }
    }

    public function reached_end_of_data(): bool {
        return empty($this->operation_queue) && !$this->operation;
    }

    public function close_writing(): void {}

    public function append_progress_chunk($chunk): void {
        $this->operation_queue[] = [
            'type' => 'progress',
            'chunk' => $chunk,
            'channel_code' => "\x02"
        ];
    }

    public function append_error_chunk($chunk): void {
        $this->operation_queue[] = [
            'type' => 'error',
            'chunk' => $chunk,
            'channel_code' => "\x03"
        ];
    }

    public function append_sideband_chunk($packet_line): void {
        $this->operation_queue[] = [
            'type' => 'sideband',
            'chunk' => $packet_line,
            'channel_code' => "\x01"
        ];
    }

    public function append_packet_line($line, $channel_code = ''): void {
        $this->operation_queue[] = [
            'type' => 'packet-line',
            'chunk' => $line,
            'channel_code' => $channel_code
        ];
    }

    public function append_packet_lines($lines, $channel_code = ''): void {
        $this->operation_queue[] = [
            'type' => 'packet-lines',
            'chunk' => $lines,
            'channel_code' => $channel_code
        ];
    }

    public function append_packfile($repository, $pack_objects): void {
        $this->operation_queue[] = [
            'type' => 'packfile',
            'repository' => $repository,
            'pack_objects' => $pack_objects,
            'object_index' => 0
        ];
    }

    private function append_packfile_data(): bool {
        $operation = &$this->operation;
        if ($operation['object_index'] >= count($operation['pack_objects'])) {
            $this->operation = null;
            return false;
        }

        if(!$this->packfile_writer) {
            $this->packfile_pipe = new MemoryPipe();
            $this->packfile_writer = new TransformedConsumer($this->packfile_pipe, [
                'checksum' => new ChecksumTransformer('sha1', [
                    'flush_hash' => true,
                    'binary_output' => true,
                ]),
            ]);
            $this->packfile_writer->append_bytes(
                $this->encode_packfile_header(count($operation['pack_objects']))
            );
        }

        if (!$this->object_reader) {
            $object = $operation['pack_objects'][$operation['object_index']];
            $this->object_reader = $operation['repository']->read_object($object);

            $this->packfile_writer->append_bytes(
                $this->encode_packfile_object_header(
                    $this->object_reader->get_object_type_name(),
                    $this->object_reader->get_uncompressed_size()
                )
            );
            $this->packfile_object_body_writer = new TransformedConsumer($this->packfile_writer, [
                'deflate' => new DeflateTransformer(ZLIB_ENCODING_DEFLATE),
            ]);
            return true;
        }

        $available = $this->object_reader->pull(8096);
        if ($available) {
            $this->packfile_object_body_writer->append_bytes(
                $this->object_reader->consume($available)
            );
            return true;
        }

        $this->object_reader->close();
        $this->object_reader = null;
        $operation['object_index']++;

        $this->packfile_object_body_writer->close();
        $this->packfile_object_body_writer = null;

        if ($operation['object_index'] >= count($operation['pack_objects'])) {
            $this->packfile_writer->close();
            $this->packfile_writer = null;
        }

        return true;
    }

    private function encode_packfile_header($number_of_objects) {
        return "PACK" . pack('N', 2) . pack('N', $number_of_objects);
    }

    private function encode_packfile_object_header( $object_type_name, $uncompressed_size ) {
        $types = array_flip(PackParser::OBJECT_NAMES);
        $object_type = $types[$object_type_name];

		// First byte: type in bits 4-6, size bits 0-3
		$firstByte  = $uncompressed_size & 0b1111;
		$firstByte |= ( $object_type & 0b111 ) << 4;

		// Continuation bit 7 if needed
		if ( $uncompressed_size > 15 ) {
			$firstByte |= 0b10000000;
		}

		// Get remaining size bits after first 4 bits
		$remainingSize = $uncompressed_size >> 4;

		// Build result starting with first byte
		$result = chr( $firstByte );
		// Add continuation bytes if needed
		while ( $remainingSize > 0 ) {
			// Set continuation bit if we have more bytes
			$byte            = $remainingSize & 0b01111111;
			$remainingSize >>= 7;
			if ( $remainingSize > 0 ) {
				$byte |= 0b10000000;
			}

			$result .= chr( $byte );
		}

        return $result;
    }

}
