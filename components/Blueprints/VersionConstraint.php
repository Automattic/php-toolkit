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
			if ( version_compare( $version, $this->max, '>' ) ) {
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
