<?php

namespace WordPress\Blueprints\Resources\Model;

class DataReference {

	static public function from_json( $reference ) {
		$classes = array(
			URLReference::class,
			ExecutionContextPath::class,
			File::class,
			GitPath::class,
			InlineDirectory::class,
			InlineFile::class,
		);
		foreach( $classes as $class ) {
			if( $class::is_valid( $reference ) ) {
				return new $class( $reference );
			}
		}
		throw new \InvalidArgumentException( 'Invalid data reference' );
	}

}