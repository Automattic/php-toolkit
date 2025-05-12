<?php

namespace WordPress\Blueprints;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\Steps\InvalidArgumentException;
use WordPress\Filesystem\Filesystem;

class RunnerConfiguration {
	private DataReference|array $blueprintRef;
	private string $mode = 'create-new-site';    // or apply-to-existing-site
	private string $rootDir = '';
	private string $siteUrl = '';
	private ?Filesystem $executionContext = null;
	private string $databaseEngine = 'mysql';
	private array $databaseCredentials = [];

	public function setBlueprint( DataReference|array $r ): self {
		$this->blueprintRef = $r;

		return $this;
	}

	public function getBlueprint(): DataReference|array {
		return $this->blueprintRef;
	}

	public function setExecutionMode( string $m ): self {
		$this->mode = $m;

		return $this;
	}

	public function getExecutionMode(): string {
		return $this->mode;
	}

	public function setTargetSiteRoot( string $d ): self {
		$this->rootDir = $d;

		return $this;
	}

	public function getTargetSiteRoot(): string {
		return $this->rootDir;
	}

	public function setTargetSiteUrl( string $u ): self {
		$this->siteUrl = $u;

		return $this;
	}

	public function getTargetSiteUrl(): string {
		return $this->siteUrl;
	}

	public function setExecutionContext( Filesystem $fs ): self {
		$this->executionContext = $fs;

		return $this;
	}

	public function getExecutionContext(): Filesystem|null {
		return $this->executionContext;
	}

	/**
	 * Sets the database engine.
	 *
	 * @param  string  $databaseEngine  Database engine to use ('mysql' or 'sqlite')
	 *
	 * @return self
	 * @throws InvalidArgumentException If the database engine is invalid
	 */
	public function setDatabaseEngine( string $databaseEngine ): self {
		if ( ! in_array( $databaseEngine, [ 'mysql', 'sqlite' ] ) ) {
			throw new InvalidArgumentException( "Invalid database engine: {$databaseEngine}" );
		}

		$this->databaseEngine = $databaseEngine;

		return $this;
	}

	public function getDatabaseEngine(): string {
		return $this->databaseEngine;
	}

	/**
	 * Sets the database credentials.
	 *
	 * @param  array  $databaseCredentials  Connection parameters for the database
	 *
	 * @return self
	 */
	public function setDatabaseCredentials( array $databaseCredentials ): self {
		$this->databaseCredentials = $databaseCredentials;

		return $this;
	}

	public function getDatabaseCredentials(): array {
		return $this->databaseCredentials;
	}
}
