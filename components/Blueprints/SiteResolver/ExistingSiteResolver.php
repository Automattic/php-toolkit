<?php

namespace WordPress\Blueprints\SiteResolver;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\Blueprints\VersionConstraint;

class ExistingSiteResolver {
	static public function resolve( Runtime $runtime, Tracker $targetResolutionStage ) {
		$stages = [
			'verify_installation' => $targetResolutionStage->stage( 0.3, 'Verifying WordPress installation' ),
			'check_compatibility' => $targetResolutionStage->stage( 0.3, 'Checking compatibility' ),
			'verify_database'     => $targetResolutionStage->stage( 0.4, 'Verifying database configuration' ),
		];

		$blueprint = $runtime->getBlueprint();
		$config    = $runtime->getConfiguration();
		$targetFs  = $runtime->getTargetFilesystem();

		// 1. Verify it's a valid WordPress installation
		$stages['verify_installation']->setCaption( 'Verifying WordPress installation' );
		if ( ! $targetFs->exists( 'wp-load.php' ) ) {
			throw new \RuntimeException(
				'The target site does not appear to be a valid WordPress installation (wp-load.php not found)'
			);
		}

		// Additional check to ensure we can actually load WordPress
		try {
			$result = $runtime->withTemporaryDirectory( function ( $temp_dir ) use ( $targetFs, $runtime ) {
				$output_file = $temp_dir . '/wp_installed.txt';
				$runtime->evalPhpInSubProcess(
					'<?php
                    require_once(getenv("DOCROOT") . "/wp-load.php");
                    $is_installed = function_exists("is_blog_installed") && is_blog_installed() ? "true" : "false";
                    file_put_contents(getenv("OUTPUT_FILE"), "WordPress is installed: " . $is_installed);
                    ',
					[ 'OUTPUT_FILE' => $output_file ]
				);

				return $targetFs->get_contents( $output_file );
			}, '' );

			if ( $result !== 'WordPress is installed: true' ) {
				throw new \RuntimeException(
					'The target site exists but WordPress is not properly installed or configured'
				);
			}
		} catch ( \Exception $e ) {
			throw new \RuntimeException(
				'Failed to load WordPress installation: ' . $e->getMessage()
			);
		}

		$stages['verify_installation']->finish();

		// 2. Check WordPress version compatibility
		$stages['check_compatibility']->setCaption( 'Checking WordPress version compatibility' );
		if ( isset( $blueprint['wordpressVersion'] ) ) {
			$wpVersionConstraint = VersionConstraint::fromMixed( $blueprint['wordpressVersion'] );

			// Get current WordPress version
			$currentWordPressVersion = $runtime->withTemporaryDirectory( function ( $temp_dir ) use ( $targetFs, $runtime ) {
				$output_file = $temp_dir . '/wp_version.txt';
				$runtime->evalPhpInSubProcess(
					'<?php
                    require_once(getenv("DOCROOT") . "/wp-includes/version.php");
                    file_put_contents(getenv("OUTPUT_FILE"), $wp_version);
                    ',
					[ 'OUTPUT_FILE' => $output_file ]
				);

				return $targetFs->get_contents( $output_file );
			}, '' );

			if ( ! $wpVersionConstraint->satisfiedBy( trim( $currentWordPressVersion ) ) ) {
				throw new \RuntimeException(
					sprintf(
						'WordPress version incompatible. Blueprint requires %s, but the site has version %s',
						$wpVersionConstraint->__toString(),
						trim( $currentWordPressVersion )
					)
				);
			}
		}

		// 3. Check PHP version compatibility (already verified at the Blueprint runner level)
		// See BlueprintRunner::validateBlueprint()

		$stages['check_compatibility']->finish();

		// 4. Verify database engine matches
		$stages['verify_database']->setCaption( 'Verifying database configuration' );
		$requiredEngine = $config->getDatabaseEngine();

		// Check if SQLite integration plugin is active when using SQLite
		if ( $requiredEngine === 'sqlite' ) {
			$sqliteActive = $runtime->withTemporaryDirectory( function ( $temp_dir ) use ( $targetFs, $runtime ) {
				$output_file = $temp_dir . '/sqlite_active.txt';
				$runtime->evalPhpInSubProcess(
					'<?php
                    require_once(getenv("DOCROOT") . "/wp-load.php");
                    
                    // Check if SQLite integration is active
                    $sqlite_plugin = WP_CONTENT_DIR . "/plugins/sqlite-database-integration/load.php";
                    $plugin_exists = file_exists($sqlite_plugin);
                    
                    // Also check for the db.php drop-in
                    $is_db_file = file_exists(WP_CONTENT_DIR . "/db.php");                    
                    file_put_contents(getenv("OUTPUT_FILE"), ($plugin_exists && $is_db_file) ? "true" : "false");
                    ',
					[ 'OUTPUT_FILE' => $output_file ]
				);

				return $targetFs->get_contents( $output_file );
			}, '' );

			if ( trim( $sqliteActive ) !== 'true' ) {
				throw new \RuntimeException(
					'The Blueprint requires SQLite database engine, but the site is not using SQLite integration'
				);
			}
		} elseif ( $requiredEngine === 'mysql' ) {
			// For MySQL, verify it's not using SQLite
			$usingMysql = $runtime->withTemporaryDirectory( function ( $temp_dir ) use ( $targetFs, $runtime ) {
				$output_file = $temp_dir . '/mysql_active.txt';
				$runtime->evalPhpInSubProcess(
					'<?php
                    require_once(getenv("DOCROOT") . "/wp-load.php");
                    
                    // Check if SQLite integration is NOT active
                    $active_plugins = get_option("active_plugins");
                    $sqlite_plugin = "sqlite-database-integration/load.php";
                    $is_sqlite_active = in_array($sqlite_plugin, $active_plugins);
                    
                    // Also check for the db.php drop-in
                    $is_sqlite_db_file = file_exists(WP_CONTENT_DIR . "/db.php") && 
                                        strpos(file_get_contents(WP_CONTENT_DIR . "/db.php"), "sqlite") !== false;
                    
                    // Using MySQL if NOT using SQLite
                    file_put_contents(getenv("OUTPUT_FILE"), (!$is_sqlite_active && !$is_sqlite_db_file) ? "true" : "false");
                    ',
					[ 'OUTPUT_FILE' => $output_file ]
				);

				return $targetFs->get_contents( $output_file );
			}, '' );

			if ( trim( $usingMysql ) !== 'true' ) {
				throw new \RuntimeException(
					'The Blueprint requires MySQL database engine, but the site appears to be using SQLite'
				);
			}
		}

		$stages['verify_database']->finish();
		$targetResolutionStage->finish();
	}
}
