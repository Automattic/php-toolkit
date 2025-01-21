<?php

namespace WordPress\ByteStream\Reader;

use WordPress\ByteStream\NotEnoughDataException;
use WordPress\ByteStream\Reader\ByteReader;

class ReaderUtils {

    static public function read_exactly_n_bytes(ByteReader $reader, int $n): string|false {
        $start = $reader->tell();

        $buffer = '';
        while(strlen($buffer) < $n) {
            $remaining = $n - strlen($buffer);
            $next_chunk_size = min(8192, $remaining);
            if(false === $reader->next_bytes($next_chunk_size)) {
                $reader->seek($start);
                throw new NotEnoughDataException(sprintf(
                    'Could not read %d bytes',
                    $n
                ));
            }
            $buffer .= $reader->get_bytes();
        }
        return $buffer;
    }

    static public function peek_n_bytes(ByteReader $reader, int $n): string|false {
        $start = $reader->tell();
        $reader->next_bytes($n);
        $bytes = $reader->get_bytes();
        $reader->seek($start);
        return $bytes;
    }

    static public function read_all_remaining_bytes(ByteReader $reader): string|false {
        $buffer = '';
        while(false !== $reader->next_bytes(8192)) {
            $buffer .= $reader->get_bytes();
        }
        return $buffer;
    }

}
