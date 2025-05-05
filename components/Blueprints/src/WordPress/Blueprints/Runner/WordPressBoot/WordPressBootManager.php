<?php

namespace WordPress\Blueprints\Runner\WordPressBoot;

use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\HttpClient\Client;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\pipe_stream;
use function WordPress\Filesystem\wp_join_paths;

class WordPressBootManager {

	public static function boot(BootOptions $options): Runtime {
		// Initialize runtime for the given document root
		$runtime = new Runtime($options->documentRoot);

		// Ensure document root directory exists (LocalFilesystem::create creates it)
		$targetFs = $runtime->getTargetFilesystem();

		// Unzip WordPress core into document root
		$resolved = $runtime->resolveDataReference($options->wordPressZip);
		if (!$resolved instanceof File) {
			throw new \InvalidArgumentException('Provided zip reference does not resolve to a file');
		}
		$zipFs = ZipFilesystem::create($resolved->stream);

		$path_in_zip = '/';
		if(!$zipFs->exists('/wp-content') && $zipFs->exists('/wordpress')) {
			$path_in_zip = '/wordpress';
		}

		copy_between_filesystems([
			'source_filesystem' => $zipFs,
			'source_path'       => $path_in_zip,
			'target_filesystem' => $targetFs,
			'target_path'       => '/',
			'recursive'         => true,
		]);

		// If SQLite integration zip provided, unzip into appropriate folder
		if ($options->sqliteIntegrationPluginZip) {
			$resolved = $runtime->resolveDataReference($options->sqliteIntegrationPluginZip);
			if (!$resolved instanceof File) {
				throw new \InvalidArgumentException('Provided zip reference does not resolve to a file');
			}
			$zipFs = ZipFilesystem::create($resolved->stream);

			$targetPath = '/wp-content/plugins/sqlite-database-integration';
			$sourcePath = '/';
			if ($zipFs->exists('sqlite-database-integration')) {
				$sourcePath = '/sqlite-database-integration';
			}
			copy_between_filesystems([
				'source_filesystem' => $zipFs,
				'source_path'       => $sourcePath,
				'target_filesystem' => $targetFs,
				'target_path'       => $targetPath,
				'recursive'         => true,
			]);

			$targetFs->copy(
				wp_join_paths($targetPath, 'db.copy'),
				'/wp-content/db.php'
			);
		}

		// 3. Install WordPress if not installed
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

		if (trim($installCheck) !== '1') {
			$wp_cli_path = wp_join_paths($runtime->getDocumentRoot(), 'wp-cli.phar');
			if(!file_exists($wp_cli_path)) {
				$read_stream = (new Client())->fetch('https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar');
				$write_stream = FileWriteStream::from_path($wp_cli_path);
				pipe_stream($read_stream, $write_stream);
				$read_stream->close_reading();
				$write_stream->close_writing();
			}

			$fs = $runtime->getTargetFilesystem();
			if(!$fs->exists('/wp-config.php')) {
				if ( $fs->exists( 'wp-config-sample.php' ) ) {
					$fs->copy( 'wp-config-sample.php', 'wp-config.php' );
				} else {
					throw new \RuntimeException( 'Neither wp-config.php, nor wp-config-sample.php was found in the WordPress archive.' );
				}
			}

			// Perform installation using WP-CLI
			// @TODO: Remove the WP-CLI dependency to lower the download size for blueprints.phar.
			$runtime->runShellCommand([
				'php',
				$wp_cli_path,
				'core',
				'install',
				'--url=' . $options->siteUrl,
				'--title=WordPress Site',
				'--admin_user=admin',
				'--admin_password=password',
				'--admin_email=admin@example.com',
				'--skip-email'
			]);
		}

		return $runtime;
	}
}
