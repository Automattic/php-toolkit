<?php

namespace WordPress\Blueprints\Model\DataClass;

class RmDirStep implements StepDefinitionInterface {

	const DISCRIMINATOR = 'rmdir';

	/** @var Progress */
	public $progress;

	/** @var bool */
	public $continueOnError = false;

	/** @var string */
	public $step = 'rmdir';

	/**
	 * The path to remove
	 *
	 * @var string
	 */
	public $path;

	/**
	 * Whether to remove the directory recursively.
	 * Defaults to false.
	 *
	 * @var bool
	 */
	public $recursive = false;


	/**
	 * @param \WordPress\Blueprints\Model\DataClass\Progress $progress
	 */
	public function setProgress( $progress ) {
		$this->progress = $progress;
		return $this;
	}


	/**
	 * @param bool $continueOnError
	 */
	public function setContinueOnError( $continueOnError ) {
		$this->continueOnError = $continueOnError;
		return $this;
	}


	/**
	 * @param string $step
	 */
	public function setStep( $step ) {
		$this->step = $step;
		return $this;
	}


	/**
	 * @param string $path
	 */
	public function setPath( $path ) {
		$this->path = $path;
		return $this;
	}

	/**
	 * @param bool $recursive
	 */
	public function setRecursive( $recursive ) {
		$this->recursive = $recursive;
		return $this;
	}
}
