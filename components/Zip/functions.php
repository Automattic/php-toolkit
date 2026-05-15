<?php

namespace WordPress\Zip;

use WordPress\ByteStream\NotEnoughDataException;
use WordPress\ByteStream\ReadStream\ByteReadStream;

if ( ! function_exists( __NAMESPACE__ . '\\is_zip_file_stream' ) ) {
	function is_zip_file_stream( ByteReadStream $stream ) {
		if ( $stream->length() < 4 ) {
			return false;
		}

		try {
			$stream->pull( 4, ByteReadStream::PULL_EXACTLY );
		} catch ( NotEnoughDataException $e ) {
			return false;
		}

		return "PK\x03\x04" === $stream->peek( 4 );
	}
}
