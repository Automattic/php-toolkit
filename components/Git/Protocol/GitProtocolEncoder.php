<?php

namespace WordPress\Git\Protocol;

use WordPress\Git\Protocol\PackfileEncoder;
use WordPress\ByteStream\Producer\BaseByteProducer;
use WordPress\Git\Model\Tree;

class GitProtocolEncoder extends BaseByteProducer {

    private $packfile_pipe;
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
                if (!$this->packfile_pipe) {
                    $this->packfile_pipe = PackfileEncoder::create($operation['repository'], $operation['pack_objects']);
                } else if($this->packfile_pipe->reached_end_of_data()) {
                    $this->packfile_pipe->close();
                    $this->packfile_pipe = null;
                    $this->operation = null;
                    return '';
                }
                $available = $this->packfile_pipe->pull(8096);
                return $this->packfile_pipe->consume($available);
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

}
