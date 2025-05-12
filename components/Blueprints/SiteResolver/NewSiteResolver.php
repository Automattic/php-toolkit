<?php

namespace WordPress\Blueprints\SiteResolver;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\Blueprints\VersionConstraint;
use WordPress\HttpClient\Client;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\pipe_stream;
use function WordPress\Filesystem\wp_join_paths;

class NewSiteResolver {
	static public function resolve( Runtime $runtime, Tracker $targetResolutionStage ) {
		$stages = [
			'resolve_assets'    => $targetResolutionStage->stage( 0.66 ),
			'install_wordpress' => $targetResolutionStage->stage( 0.33, 'Installing WordPress' ),
		];

		$blueprint = $runtime->getBlueprint();

		// Ensure document root directory exists (LocalFilesystem::create creates it)
		$targetFs = $runtime->getTargetFilesystem();
		if ( count( $targetFs->ls( '/' ) ) > 0 ) {
			throw new \RuntimeException( 'The target site root directory must be empty in the create-new-site mode, but it wasn\'t.' );
		}

		// Unzip WordPress core into document root
		$wpVersionConstraint = isset( $blueprint['wordpressVersion'] )
			? VersionConstraint::fromMixed( $blueprint['wordpressVersion'] )
			: null;

		$wpZip = self::resolveWordPressZipUrl( $runtime->getHttpClient(), $wpVersionConstraint );

		$assets = [
			'wordpress' => DataReference::create( $wpZip ),
		];
		if ( $runtime->getConfiguration()->getDatabaseEngine() === 'sqlite' ) {
			// @TODO: configurable sqlite integration plugin zip URL
			$assets['sqlite-integration'] = DataReference::create( 'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip' );
		}
		$assets['wp-cli'] = DataReference::create( 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' );

		$runtime->getDataReferenceResolver()->startEagerResolution( $assets, $stages['resolve_assets'] );

		$stages['resolve_assets']->setCaption( 'Downloading WordPress' );

		$resolved = $runtime->resolve( $assets['wordpress'] );
		if ( ! $resolved instanceof File ) {
			throw new \InvalidArgumentException( 'Provided zip reference does not resolve to a file' );
		}
		$zipFs = ZipFilesystem::create( $resolved->stream );

		$path_in_zip = '/';
		if ( ! $zipFs->exists( '/wp-content' ) && $zipFs->exists( '/wordpress' ) ) {
			$path_in_zip = '/wordpress';
		}

		$stages['install_wordpress']->set( 0.2, 'Setting up WordPress files' );

		copy_between_filesystems( [
			'source_filesystem' => $zipFs,
			'source_path'       => $path_in_zip,
			'target_filesystem' => $targetFs,
			'target_path'       => '/',
			'recursive'         => true,
		] );

		$stages['install_wordpress']->set( 0.6, 'Installing WordPress' );

		// If SQLite integration zip provided, unzip into appropriate folder
		if ( $runtime->getConfiguration()->getDatabaseEngine() === 'sqlite' ) {
			$stages['resolve_assets']->setCaption( 'Downloading SQLite integration plugin' );
			$resolved = $runtime->resolve( $assets['sqlite-integration'] );
			if ( ! $resolved instanceof File ) {
				throw new \InvalidArgumentException( 'Provided zip reference does not resolve to a file' );
			}
			$zipFs = ZipFilesystem::create( $resolved->stream );

			$targetPath = '/wp-content/plugins/sqlite-database-integration';
			$sourcePath = '/';
			if ( $zipFs->exists( 'sqlite-database-integration' ) ) {
				$sourcePath = '/sqlite-database-integration';
			}
			copy_between_filesystems( [
				'source_filesystem' => $zipFs,
				'source_path'       => $sourcePath,
				'target_filesystem' => $targetFs,
				'target_path'       => $targetPath,
				'recursive'         => true,
			] );

			$targetFs->copy(
				wp_join_paths( $targetPath, 'db.copy' ),
				'/wp-content/db.php'
			);
		}

		// 3. Install WordPress if not installed yet.
		//    Technically, this is a "new site" resolver, but it's entirely possible
		//    the developer-provided WordPress zip already has a sqlite database with the
		//    a WordPress site installed..
		$installCheck = $runtime->evalPhpInSubProcess(
			<<<'PHP'
			$wp_load = getenv('DOCROOT') . '/wp-load.php';
			if (!file_exists($wp_load)) {
				echo '0';
				exit;
			}
			require $wp_load;

			echo function_exists('is_blog_installed') && is_blog_installed() ? '1' : '0';
			PHP
		);

		if ( trim( $installCheck ) !== '1' ) {
			$wp_cli_filename = 'wp-cli.phar';
			if ( ! $targetFs->exists( $wp_cli_filename ) ) {
				$stages['resolve_assets']->setCaption( 'Downloading wp-cli' );
				$resolved = $runtime->resolve( $assets['wp-cli'] );
				if ( ! $resolved instanceof File ) {
					throw new \InvalidArgumentException( 'Provided zip reference does not resolve to a file' );
				}
				$write_stream = $targetFs->open_write_stream( $wp_cli_filename );
				pipe_stream( $resolved->stream, $write_stream );
				$write_stream->close_writing();
			}

			if ( ! $targetFs->exists( '/wp-config.php' ) ) {
				if ( $targetFs->exists( 'wp-config-sample.php' ) ) {
					$targetFs->copy( 'wp-config-sample.php', 'wp-config.php' );
				} else {
					throw new \RuntimeException( 'Neither wp-config.php, nor wp-config-sample.php was found in the WordPress archive.' );
				}
			}

			// Perform installation using WP-CLI
			// @TODO: Remove the WP-CLI dependency to lower the download size for blueprints.phar.
			$stages['install_wordpress']->set( 0.7, 'Installing WordPress' );
			$wp_cli_path = wp_join_paths( $runtime->getConfiguration()->getTargetSiteRoot(), 'wp-cli.phar' );
			$runtime->runShellCommand( [
				'php',
				$wp_cli_path,
				'core',
				'install',
				'--url=' . $runtime->getConfiguration()->getTargetSiteUrl(),
				'--title=WordPress Site',
				'--admin_user=admin',
				'--admin_password=password',
				'--admin_email=admin@example.com',
				'--skip-email',
			] );
		}
		$targetResolutionStage->finish();
	}

	static private function resolveWordPressZipUrl( Client $client, ?VersionConstraint $constraint ): string {
		if ( $constraint === null ) {
			return 'https://wordpress.org/latest.zip';
		}

		$min         = $constraint->getMin();
		$max         = $constraint->getMax();
		$recommended = $constraint->getRecommended();

		$version_string = $recommended ?? $max ?? $min;

		if ( $version_string === 'latest' ) {
			return 'https://wordpress.org/latest.zip';
		}

		if (
			str_starts_with( $version_string, 'https://' ) ||
			str_starts_with( $version_string, 'http://' )
		) {
			return $version_string;
		}

		if ( $version_string === 'nightly' ) {
			return 'https://wordpress.org/nightly-builds/wordpress-latest.zip';
		}

		$latestVersions = $client->fetch( 'https://api.wordpress.org/core/version-check/1.7/?channel=beta' )->json();
		$latestVersions = array_filter( $latestVersions['offers'], function ( $v ) {
			return $v['response'] === 'autoupdate';
		} );

		foreach ( $latestVersions as $apiVersion ) {
			if ( $version_string === 'beta' && strpos( $apiVersion['version'], 'beta' ) !== false ) {
				return $apiVersion['download'];
			} elseif (
				$version_string === 'latest' &&
				strpos( $apiVersion['version'], 'beta' ) === false
			) {
				// The first non-beta item in the list is the latest version.
				return $apiVersion['download'];
			} elseif (
				substr( $apiVersion['version'], 0, strlen( $version_string ) ) ===
				$version_string
			) {
				return $apiVersion['download'];
			}
		}

		throw new \Exception( 'Invalid WordPress version constraint' );
	}
}
