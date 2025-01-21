<?php

namespace WordPress\HttpClient\Filter;

use WordPress\ByteStream\Filter\ByteFilter;

class ChunkedEncoderFilter implements ByteFilter {

    /**
     * Encodes $bytes using chunked encoding as:
     * 
     * <chunk-size><CRLF><chunk-data><CRLF>
     *
     * @param string $bytes The bytes to encode.
     */
    public function filter_bytes( $bytes ): string {
        $chunk_size = str_pad(dechex(strlen($bytes)), 2, '0', STR_PAD_LEFT);
        return $chunk_size . "\r\n" . $bytes . "\r\n";
    }

    public function flush(): string {
        return "0\r\n\r\n";
    }

}
