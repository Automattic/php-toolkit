<?php

namespace WordPress\HttpClient\Filter;

use WordPress\ByteStream\Filter\ByteFilter;

class ChunkedDecoderFilter implements ByteFilter {

    private $state = self::SCAN_CHUNK_SIZE;
    const SCAN_CHUNK_SIZE = 'SCAN_CHUNK_SIZE';
    const SCAN_CHUNK_DATA = 'SCAN_CHUNK_DATA';
    const SCAN_CHUNK_TRAILER = 'SCAN_CHUNK_TRAILER';
    const SCAN_FINAL_CHUNK = 'SCAN_FINAL_CHUNK';

    private $raw_buffer = '';
    private $decoded_buffer = '';
    private $chunk_remaining_bytes = 0;
    private $is_feof = false;

    public function filter_bytes( $bytes ): string {
        $this->raw_buffer .= $bytes;
        $this->decoded_buffer .= $this->decode_chunks();
        $return_bytes = $this->decoded_buffer;
        $this->decoded_buffer = '';
        return $return_bytes;
    }

    private function decode_chunks() {
        if ( self::SCAN_FINAL_CHUNK === $this->state ) {
            return '';
        }

        $at = 0;
        $chunks = [];
        while ( $at < strlen( $this->raw_buffer ) ) {
            if ( $this->state === self::SCAN_CHUNK_SIZE ) {
                $chunk_bytes_nb = strspn( $this->raw_buffer, '0123456789abcdefABCDEF', $at );
                if ( $chunk_bytes_nb === 0 || strlen( $this->raw_buffer ) < $chunk_bytes_nb + 2 ) {
                    break;
                }

                $clrf_at = strpos( $this->raw_buffer, "\r\n", $at );
                if ( false === $clrf_at ) {
                    break;
                }

                $chunk_bytes = substr( $this->raw_buffer, $at, $chunk_bytes_nb );
                $at = $clrf_at + 2;

                $chunk_size = hexdec( $chunk_bytes );
                if ( 0 === $chunk_size ) {
                    $this->is_feof = true;
                    break;
                }

                $this->chunk_remaining_bytes = $chunk_size;
                if ( 0 === $this->chunk_remaining_bytes ) {
                    $this->state = self::SCAN_FINAL_CHUNK;
                    break;
                } else {
                    $this->state = self::SCAN_CHUNK_DATA;
                }
            } elseif ( $this->state === self::SCAN_CHUNK_DATA ) {
                $bytes_to_read = min(
                    $this->chunk_remaining_bytes,
                    strlen( $this->raw_buffer ) - $at
                );
                $data = substr( $this->raw_buffer, $at, $bytes_to_read );
                $chunks[] = $data;
                $at += $bytes_to_read;

                $this->chunk_remaining_bytes -= strlen( $data );
                if ( $this->chunk_remaining_bytes === 0 ) {
                    $this->state = self::SCAN_CHUNK_TRAILER;
                }
            } elseif ( $this->state === self::SCAN_CHUNK_TRAILER ) {
                if ( strlen( $this->raw_buffer ) - $at < 2 ) {
                    break;
                }
                if ( $this->raw_buffer[$at] !== "\r" || $this->raw_buffer[$at + 1] !== "\n" ) {
                    throw new \Exception( 'Expected CRLF after chunk data. Instead got bytes: ' . ord( $this->raw_buffer[$at] ) . ' ' . ord( $this->raw_buffer[$at + 1] ) );
                }
                $at += 2;
                $this->state = self::SCAN_CHUNK_SIZE;
            }
        }
        $this->raw_buffer = substr( $this->raw_buffer, $at );

        return implode( '', $chunks );
    }

    public function flush(): string {
        return $this->is_feof ? '' : $this->decoded_buffer;
    }

}
