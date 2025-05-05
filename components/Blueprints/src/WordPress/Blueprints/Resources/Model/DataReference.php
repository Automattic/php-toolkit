<?php

namespace WordPress\Blueprints\Resources\Model;

class DataReference {

	static public function create( $reference ) {
		$classes = array(
			URLReference::class,
			GitPath::class,
			InlineDirectory::class,
			InlineFile::class,
			ExecutionContextPath::class,
		);
		foreach( $classes as $class ) {
			if( $class::is_valid( $reference ) ) {
				return new $class( $reference );
			}
		}
		throw new \InvalidArgumentException(
			sprintf(
				'Invalid data reference: %s',
				is_string( $reference ) ? $reference : json_encode( $reference )
			)
		);
	}

}
