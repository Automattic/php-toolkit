<?php

namespace WordPress\Blueprints;

class VersionConstraint {
	public function __construct(
		private ?string $min = null,
		private ?string $max = null,
		private ?string $recommended = null
	) {
	}

	public static function fromMixed( mixed $src ): self {
		if ( is_string( $src ) ) {
			return new self( null, null, $src );
		}
		if ( is_array( $src ) ) {
			return new self( $src['min'] ?? null, $src['max'] ?? null, $src['recommended'] ?? null );
		}
		throw new \InvalidArgumentException( 'Invalid version constraint' );
	}

	public function getMin(): ?string {
		return $this->min;
	}

	public function getMax(): ?string {
		return $this->max;
	}

	public function getRecommended(): ?string {
		return $this->recommended;
	}

	public function satisfiedBy( string $version ): bool {
		if ( $this->min !== null ) {
			if ( version_compare( $version, $this->min, '<' ) ) {
				return false;
			}
		}
		if ( $this->max !== null ) {
			// If max is set to 6.4 and the actual version is 6.4.1, we should still return true.
			// To limit the minor version number, the user must specify it explicitly.
			// @TODO: Don't append .999999. That's a hack. Consider all possible
			//        WordPress and PHP version number strings and find a way of
			//        meaningfully comparing them (or throw a warning if no meaningful
			//        comparison can be made).
			$max = $this->max;
			if ( preg_match( '/^[0-9]+\.[0-9]+$/', $max ) ) {
				$max = $max . '.999999';
			}
			if ( version_compare( $version, $max, '>' ) ) {
				return false;
			}
		}

		return true;
	}

	public function __toString(): string {
		$parts = [];
		if ( $this->min !== null ) {
			$parts[] = "min: {$this->min}";
		}
		if ( $this->max !== null ) {
			$parts[] = "max: {$this->max}";
		}
		if ( $this->recommended !== null ) {
			$parts[] = "recommended: {$this->recommended}";
		}

		return sprintf( 'VersionConstraint(%s)', implode( ', ', $parts ) );
	}
}
