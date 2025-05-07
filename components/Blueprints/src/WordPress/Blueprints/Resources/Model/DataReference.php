<?php

namespace WordPress\Blueprints\Resources\Model;

class DataReference {

	public int $id;
	private static int $instanceCounter = 0;

	public function __construct() {
		$this->id = self::$instanceCounter++;
	}

	static public function create( $reference, array $additional_reference_classes = [] ) {
		$classes = array(
			URLReference::class,
			GitPath::class,
			InlineDirectory::class,
			InlineFile::class,
			ExecutionContextPath::class,
			...$additional_reference_classes,
		);
		foreach( $classes as $class ) {
			if( $class::is_valid( $reference ) ) {
				if (method_exists($class, 'from_blueprint_data')) {
					return $class::from_blueprint_data($reference);
				} else {
					return new $class($reference);
				}
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
