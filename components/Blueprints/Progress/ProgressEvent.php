<?php

namespace WordPress\Blueprints\Progress;

use Symfony\Component\EventDispatcher\Event;

/**
 * Event class for tracking progress updates
 */
class ProgressEvent extends Event {
	public float $progress;
	public string $caption;

	/**
	 * Create a new progress event
	 *
	 * @param  float  $progress  The progress value (0-100)
	 * @param  string  $caption  The caption describing current progress
	 */
	public function __construct( float $progress, string $caption ) {
		$this->progress = $progress;
		$this->caption  = $caption;
	}

	/**
	 * Get the progress value
	 *
	 * @return float Progress value (0-100)
	 */
	public function getProgress(): float {
		return $this->progress;
	}

	/**
	 * Get the progress caption
	 *
	 * @return string Caption describing current progress
	 */
	public function getCaption(): string {
		return $this->caption;
	}
}
