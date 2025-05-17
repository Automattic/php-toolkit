<?php

namespace WordPress\Blueprints;

class VersionConstraint {
	private ?string $type;
	private ?string $min;
	private ?string $max;
	private ?string $recommended;

	public function __construct(
		?string $min = null,
		?string $max = null,
		?string $recommended = null,
		?string $type = null // 'php' or 'wordpress'
	) {
		$this->min = $min;
		$this->max = $max;
		$this->recommended = $recommended;
		$this->type = $type;
	}

	public static function fromMixed(mixed $src, ?string $type = null): self {
		if (is_string($src)) {
			return new self(null, null, $src, $type);
		}
		if (is_array($src)) {
			return new self(
				$src['min'] ?? $src['minVersion'] ?? null,
				$src['max'] ?? $src['maxVersion'] ?? null,
				$src['recommended'] ?? $src['recommendedVersion'] ?? $src['preferredVersion'] ?? null,
				$type
			);
		}
		return new self(null, null, null, $type); // fallback to empty constraint
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

	public function getType(): ?string {
		return $this->type;
	}

	/**
	 * Validate the constraint for logical consistency.
	 * Returns an array of error messages (empty if valid).
	 */
	public function validate(): array {
		$errors = [];
		if ($this->min !== null && $this->max !== null) {
			if ($this->type === 'wordpress') {
				$minV = WordPressVersion::fromString($this->min);
				$maxV = WordPressVersion::fromString($this->max);
				if ($minV && $maxV && $minV->compareTo($maxV) > 0) {
					$errors[] = sprintf('min (%s) was larger than max (%s)', $this->min, $this->max);
				}
			} else {
				if (version_compare($this->min, $this->max, '>')) {
					$errors[] = sprintf('min (%s) was larger than max (%s)', $this->min, $this->max);
				}
			}
		}
		if ($this->recommended !== null) {
			if ($this->min !== null) {
				if ($this->type === 'wordpress') {
					$recV = WordPressVersion::fromString($this->recommended);
					$minV = WordPressVersion::fromString($this->min);
					if ($recV && $minV && $recV->compareTo($minV) < 0) {
						$errors[] = sprintf('recommended (%s) must be between min (%s) and max', $this->recommended, $this->min);
					}
				} else {
					if (version_compare($this->recommended, $this->min, '<')) {
						$errors[] = sprintf('recommended (%s) must be between min (%s) and max', $this->recommended, $this->min);
					}
				}
			}
			if ($this->max !== null) {
				if ($this->type === 'wordpress') {
					$recV = WordPressVersion::fromString($this->recommended);
					$maxV = WordPressVersion::fromString($this->max);
					if ($recV && $maxV && $recV->compareTo($maxV) > 0) {
						$errors[] = sprintf('recommended (%s) was not between min (%s) and max (%s)', $this->recommended, $this->min, $this->max);
					}
				} else {
					if (version_compare($this->recommended, $this->max, '>')) {
						$errors[] = sprintf('recommended (%s) was not between min (%s) and max (%s)', $this->recommended, $this->min, $this->max);
					}
				}
			}
		}
		return $errors;
	}

	/**
	 * Checks if a version string satisfies the constraint.
	 * For 'wordpress', uses WordPressVersion; for 'php', uses version_compare.
	 */
	public function satisfiedBy(string $version): bool {
		if ($this->type === 'wordpress') {
			$ver = WordPressVersion::fromString($version);
			if (!$ver) return false;
			if ($this->min !== null) {
				$minV = WordPressVersion::fromString($this->min);
				if ($minV && $ver->compareTo($minV) < 0) return false;
			}
			if ($this->max !== null) {
				$maxV = WordPressVersion::fromString($this->max);
				if ($maxV && $ver->compareTo($maxV) > 0) return false;
			}
			return true;
		}
		// Default: PHP version
		if ($this->min !== null) {
			if (version_compare($version, $this->min, '<')) {
				return false;
			}
		}
		if ($this->max !== null) {
			if (version_compare($version, $this->max, '>')) {
				return false;
			}
		}
		return true;
	}

	public function __toString(): string {
		$parts = [];
		if ($this->min !== null) {
			$parts[] = "min: {$this->min}";
		}
		if ($this->max !== null) {
			$parts[] = "max: {$this->max}";
		}
		if ($this->recommended !== null) {
			$parts[] = "recommended: {$this->recommended}";
		}
		if ($this->type !== null) {
			$parts[] = "type: {$this->type}";
		}
		return sprintf('VersionConstraint(%s)', implode(', ', $parts));
	}
}
