<?php
/**
 * Updates Push MD release metadata from a version string.
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

if ( 2 !== $argc ) {
	fwrite( STDERR, "Usage: php bin/update-push-md-version.php <version>\n" );
	exit( 1 );
}

$version = $argv[1];
if ( 1 !== preg_match( '/\A[0-9]+(?:\.[0-9]+){1,3}(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?\z/', $version ) ) {
	fwrite( STDERR, "Invalid Push MD release version: $version\n" );
	exit( 1 );
}

$root_dir = dirname( __DIR__ );

function pmd_update_version_field( $root_dir, $relative_path, $pattern, $version ) {
	$path     = $root_dir . '/' . $relative_path;
	$contents = file_get_contents( $path );

	if ( false === $contents ) {
		fwrite( STDERR, "Unable to read $relative_path\n" );
		exit( 1 );
	}

	$count   = 0;
	$updated = preg_replace_callback(
		$pattern,
		function ( $matches ) use ( $version, &$count ) {
			$count++;
			$suffix = isset( $matches[2] ) ? $matches[2] : '';
			return $matches[1] . $version . $suffix;
		},
		$contents
	);

	if ( 1 !== $count || null === $updated ) {
		fwrite( STDERR, "Expected one release metadata match in $relative_path; found $count\n" );
		exit( 1 );
	}

	if ( $updated === $contents ) {
		echo "$relative_path already uses $version\n";
		return;
	}

	if ( false === file_put_contents( $path, $updated ) ) {
		fwrite( STDERR, "Unable to write $relative_path\n" );
		exit( 1 );
	}

	echo "Updated $relative_path to $version\n";
}

pmd_update_version_field(
	$root_dir,
	'plugins/push-md/push-md.php',
	'/(\* Version:\s*)[^\r\n]+/',
	$version
);
pmd_update_version_field(
	$root_dir,
	'plugins/push-md/readme.txt',
	'/(Stable tag:\s*)[^\r\n]+/',
	$version
);
pmd_update_version_field(
	$root_dir,
	'plugins/push-md/class-push-md-admin.php',
	"/(const ASSET_VERSION\s*=\s*')[^']+(';)/",
	$version
);
