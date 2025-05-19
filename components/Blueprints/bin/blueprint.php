<?php
/**
 * blueprint.php – the main entry point to the WordPress Blueprint Runner CLI.
 *
 * @TODO: Get the tests to pass
 * @TODO: Support "wordPressVersion": "beta"
 * @TODO: Support commands: "exec", "validate", "to-execution-plan" etc. See the Blueprints v2 spec for more commands ideas.
 * @TODO: Add a verbose mode
 * @TODO: A large test suite.
 * @TODO: Client HTTP queue deadlock when we enqueued a lot of requests and need to fetch a small
 *        ad-hoc resource such as a JSON list of translations.
 * @TODO [_spec_]: How to handle the default WordPress theme? Should it be preserved for new sites?
 *        What if we want to remove it? And what should be the semantics for existing sites?
 *        -> how to handle conflicts in general? pre-existing themes conflicting with new themes?
 *           pre-existing plugins conflicting with new plugins? refuse to execute? tell the user what
 *           to do? As in change the Blueprint? What if I don't want to change it? maybe interact with the user
 *           and ask whether they want to bale or override the theme/plugin?
 * @TODO (next spec version?): Add a flag that allows user-defined runPHP steps?
 * @TODO (low priority): Production-grade HTTP Cache support for remote files. Not the stopgap we have now.
 *                       We can ship Blueprints without http cache support, but do not ship the stopgap solution
 *                       in production.
 * @TODO (low priority): Range header-based HTTP stream for fast partial parsing of large remote zip files.
 *                       Needs to support servers lying about their Range support.
 * @TODO (low priority): Restrictions on supported step types, media files types, SQL queries types, etc.
 * @TODO (low priority): Fast unzipping of remote Zip Files by iterating over the entries
 *        instead of skipping over to the end central directory index entry.
 * @TODO (low priority) never require going through local paths. Make evalPHP explicitly support target filesystem paths so that
 *        we can be prepared for remote Blueprint execution.
 * ✅ @TODO: Get explicit user consent before using paths from a local directory
 * ✅ @TODO (low priority): Exception structure?
 * ✅ @TODO: Support --truncate-new-site-directory option for easy development – just re-run the same command to override a previous site.
 * ✅ @TODO: Prevent remote resources from using local bundle paths
 */

require __DIR__ . '/../../../vendor/autoload.php';

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Exception\PermissionsException;
use WordPress\Blueprints\Logger\CLILogger;
use WordPress\Blueprints\ProgressObserver;
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_canonicalize_path;
use function WordPress\Filesystem\wp_resolve_path;

// Enable colours on Windows 10+ (safe‑no‑op elsewhere)
if ( PHP_OS_FAMILY === 'Windows' && function_exists( 'sapi_windows_vt100_support' ) ) {
	@sapi_windows_vt100_support( STDOUT, true );
}

// -----------------------------------------------------------------------------
//   Option definition – tweak this block to add / modify CLI options
// -----------------------------------------------------------------------------
$supportedPermissions = RunnerConfiguration::ALL_PERMISSIONS;
$optionDefs           = [
	/* long               short hasVal default        description */
	'site-url'                    => [ 'u', true, null, 'Public site URL (https://example.com)' ],
	'site-path'                   => [ null, true, null, 'Target directory with WordPress install context)' ],
	'execution-context'           => [ 'x', true, null, 'Source directory with Blueprint context files' ],
	'mode'                        => [ 'm', true, 'create-new-site', 'Execution mode (create|apply)' ],
	'db-engine'                   => [ 'd', true, 'mysql', 'Database engine (mysql|sqlite)' ],
	'db-host'                     => [ null, true, '127.0.0.1', 'MySQL host' ],
	'db-user'                     => [ null, true, 'root', 'MySQL user' ],
	'db-pass'                     => [ null, true, '', 'MySQL password' ],
	'db-name'                     => [ null, true, 'wordpress', 'MySQL database' ],
	'db-path'                     => [ 'p', true, 'wp.db', 'SQLite file path' ],
	'truncate-new-site-directory' => [ 't', false, false, 'Delete target directory if it exists before execution' ],
	'allow'                       => [ null, true, null, 'Allowed permissions. One of: ' . implode( ', ', $supportedPermissions ) ],
	'help'                        => [ 'h', false, false, 'Show full help' ],
	'version'                     => [ 'V', false, false, 'Show version' ],
];

// -----------------------------------------------------------------------------
//   Custom command‑line parser (POSIX‑ish but without getopt dependency)
// -----------------------------------------------------------------------------
function parseArguments( array $argv, array $optionDefs ): array {
	$positionals = [];
	$options     = [];
	$short2long  = [];

	// Initialise defaults & maps
	foreach ( $optionDefs as $long => $def ) {
		[ $short, , $default ] = $def;
		$options[ $long ] = $default;
		if ( $short ) {
			$short2long[ $short ] = $long;
		}
	}

	$i = 1; // skip script name
	while ( $i < count( $argv ) ) {
		$token = $argv[ $i ];

		// Long option --foo or --foo=bar
		if ( preg_match( '/^--([^=]+)(=(.*))?$/', $token, $m ) ) {
			$long = $m[1];
			if ( ! isset( $optionDefs[ $long ] ) ) {
				throw new InvalidArgumentException( "Unknown option --$long" );
			}
			[ $short, $hasVal ] = $optionDefs[ $long ];
			if ( $hasVal ) {
				$val = $m[3] ?? ( $argv[ ++ $i ] ?? null );
				if ( $val === null ) {
					throw new InvalidArgumentException( "Option --$long requires a value" );
				}
				$options[ $long ] = $val;
			} else {
				$options[ $long ] = true;
			}
			$i ++;
			continue;
		}

		// Short option(s): -abc or -e mysql or -e=mysql
		if ( preg_match( '/^-([A-Za-z]{1,})(=(.*))?$/', $token, $m ) ) {
			$bundle    = str_split( $m[1] );
			$inlineVal = $m[3] ?? null;
			foreach ( $bundle as $idx => $short ) {
				if ( ! isset( $short2long[ $short ] ) ) {
					throw new InvalidArgumentException( "Unknown option -$short" );
				}
				$long   = $short2long[ $short ];
				$hasVal = $optionDefs[ $long ][1];
				if ( $hasVal ) {
					if ( $inlineVal !== null && $idx === 0 ) {
						$options[ $long ] = $inlineVal;
					} else {
						$val = ( $idx === count( $bundle ) - 1 ) ? ( $argv[ ++ $i ] ?? null ) : null;
						if ( $val === null ) {
							throw new InvalidArgumentException( "Option -$short requires a value" );
						}
						$options[ $long ] = $val;
					}
					break; // value‑bearing short stops bundle processing
				} else {
					$options[ $long ] = true;
				}
			}
			$i ++;
			continue;
		}

		// Positional argument
		$positionals[] = $token;
		$i ++;
	}

	return [ $positionals, $options ];
}


function cliArgsToRunnerConfiguration( array $positionals, array $options ): RunnerConfiguration {
	global $supportedPermissions;

	$config = new RunnerConfiguration();

	// Map positional arguments
	if ( empty( $positionals ) ) {
		throw new InvalidArgumentException( "A Blueprint reference must be specified as a positional argument." );
	}

	// The first positional is the blueprint reference
	try {
		$blueprint_reference = $positionals[0];
		if ( strncmp( $blueprint_reference, './', strlen( './' ) ) === 0 ) {
			$blueprint_reference = realpath( $blueprint_reference );
		}
		$config->setBlueprint( DataReference::create( $blueprint_reference ) );
	} catch ( InvalidArgumentException $e ) {
		throw new InvalidArgumentException( "Invalid Blueprint reference: " . $positionals[0] );
	}

	if ( empty( $options['site-path'] ) ) {
		throw new InvalidArgumentException( "--site-path option is required." );
	}

	if ( ! empty( $options['mode'] ) ) {
		// Accept 'create-new-site' or 'apply-to-existing-site' as CLI values, map to internal values
		$mode = $options['mode'];
		if ( $mode === 'create-new-site' ) {
			$config->setExecutionMode( 'create-new-site' );
		} elseif ( $mode === 'apply-to-existing-site' ) {
			$config->setExecutionMode( 'apply-to-existing-site' );
		} else {
			throw new InvalidArgumentException( "Invalid execution mode: {$mode}. Supported modes are: create-new-site, apply-to-existing-site" );
		}
	}

	$targetSiteRoot         = $options['site-path'];
	$absoluteTargetSiteRoot = wp_canonicalize_path( wp_resolve_path( $targetSiteRoot ) );

	if ( $options['truncate-new-site-directory'] ) {
		if ( $options['mode'] !== 'create-new-site' ) {
			throw new InvalidArgumentException( "--truncate-new-site-directory can only be used with --mode=create" );
		}
		$fs = LocalFilesystem::create( $absoluteTargetSiteRoot );
		$fs->rmdir( '/', [ 'recursive' => true ] );
		$fs->mkdir( '/', [ 'chmod' => 0755 ] );
	}

	if ( ! is_dir( $absoluteTargetSiteRoot ) ) {
		throw new InvalidArgumentException( "The --site-path path does not exist: {$targetSiteRoot}" );
	}
	$config->setTargetSiteRoot( $absoluteTargetSiteRoot );
	$config->setTargetSiteUrl( $options['site-url'] );


	// Set database engine
	if ( ! empty( $options['db-engine'] ) ) {
		$config->setDatabaseEngine( $options['db-engine'] );
	}

	// Set database credentials
	$dbEngine = $options['db-engine'] ?? 'mysql';
	$dbCreds  = [];
	if ( $dbEngine === 'mysql' ) {
		$dbCreds = [
			'host'         => $options['db-host'] ?? '127.0.0.1',
			'username'     => $options['db-user'] ?? 'root',
			'password'     => $options['db-pass'] ?? '',
			'databaseName' => $options['db-name'] ?? 'wordpress',
		];
	} elseif ( $dbEngine === 'sqlite' ) {
		$dbCreds = [
			'path' => $options['db-path'] ?? 'wp.db',
		];
	}
	$config->setDatabaseCredentials( $dbCreds );

	// Set allow options
	if ( ! empty( $options['allow'] ) ) {
		$allow = explode( ',', $options['allow'] );
		foreach ( $allow as $permission ) {
			switch ( $permission ) {
				case 'read-local-fs':
					$config->setAllowLocalFilesystemAccess( true );
					break;
				default:
					throw new InvalidArgumentException( "Unknown --allow permission: $permission. Allowed permissions: " . implode( ', ',
							$supportedPermissions ) );
			}
		}
	}

	$config->setLogger(
		new CLILogger( 'php://stdout', CLILogger::VERBOSITY_INFO )
	);

	return $config;
}


// -----------------------------------------------------------------------------
//   Help & version
// -----------------------------------------------------------------------------
function showUsageShort(): void {
	$script = basename( $_SERVER['argv'][0] );
	echo "\033[1mUsage:\033[0m php $script \033[33m<blueprint>\033[0m --site-url=\033[33m<url>\033[0m --site-path=\033[33m<path>\033[0m [options]\n";
}

function showHelp( array $optionDefs ): void {
	$script = basename( $_SERVER['argv'][0] );
	echo "\033[1mWordPress Blueprint Runner\033[0m\n";
	showUsageShort();
	echo "\n";
	echo "\033[1mPositional arguments:\033[0m\n";
	echo "  blueprint            Path / URL / DataReference to the blueprint (required)\n\n";

	echo "\033[1mOptions:\033[0m\n";
	foreach ( $optionDefs as $long => [$short, $hasVal, $def, $desc] ) {
		$flags = '  ' . ( $short ? "-$short, " : '    ' ) . "--$long";
		if ( $hasVal ) {
			$flags .= " <value>";
		}
		$defaultText = is_null( $def ) ? '' : ' (default ' . var_export( $def, true ) . ')';
		if ( $long === 'site-path' ) {
			$defaultText = ' (required)';
		}
		printf( "%-34s %s\n", $flags, $desc . $defaultText );
	}
	echo "\nExamples:\n";
	echo "  php $script my-blueprint.json --site-url https://mysite.test --site-path /var/www/mysite.com\n";
	echo "  php $script my-blueprint.json --execution-context /var/www --site-url https://mysite.test --mode apply --site-path ./site\n";
	echo "  php $script my-blueprint.json --site-url https://mysite.test --site-path ./mysite --truncate-site-directory\n";
	echo "\n";
}

function reportProgress( $progress, $caption ) {
	static $lastLength = 0;
	static $columns = null;
	$output        = sprintf( "[%3d%%] %s", $progress, $caption );
	$currentLength = strlen( $output );

	// Get terminal width if possible
	if ( null === $columns ) {
		if ( function_exists( 'exec' ) && false !== exec( 'tput cols 2>/dev/null', $out ) ) {
			$columns = (int) $out[0];
		} elseif ( function_exists( 'shell_exec' ) && ( $shellColumns = shell_exec( 'tput cols 2>/dev/null' ) ) ) {
			$columns = (int) $shellColumns;
		}
		if ( null === $columns ) {
			$columns = 80;
		}
	}

	// Truncate if longer than terminal width
	if ( $currentLength > $columns - 1 ) {
		$output        = substr( $output, 0, $columns - 4 ) . '...';
		$currentLength = $columns - 1;
	}

	fprintf( STDERR, "\r%s%s", $output, $currentLength < $lastLength ? str_repeat( ' ', $lastLength - $currentLength ) : '' );
	$lastLength = $currentLength;
}

// -----------------------------------------------------------------------------
//   Main entry
// -----------------------------------------------------------------------------
try {
	try {
		[ $positionals, $options ] = parseArguments( $_SERVER['argv'], $optionDefs );

		if ( $options['help'] ) {
			showHelp( $optionDefs );
			exit( 0 );
		}
		if ( $options['version'] ) {
			echo "WordPress Blueprint Runner CLI v0.0.1-alpha\n";
			exit( 0 );
		}

		// Validate positional blueprint
		if ( count( $positionals ) < 1 ) {
			showHelp( $optionDefs );
			exit( 0 );
		}

		// Convert CLI arguments to RunnerConfiguration
		$config = cliArgsToRunnerConfiguration( $positionals, $options );
		$config
			->setProgressObserver( new ProgressObserver( function ( $progress, $caption ) {
				reportProgress( $progress, $caption );
			} ) );
		$runner = new Runner( $config );
	} catch ( InvalidArgumentException $ex ) {
		echo "\033[31mError:\033[0m " . $ex->getMessage() . PHP_EOL;
		echo "Try '--help' for usage." . PHP_EOL;
		exit( 1 );
	}

	try {
		// Continue with runner execution (not implemented here)
		if ( $config->getExecutionMode() === 'create-new-site' ) {
			echo "\033[1;32mCreating a new site\033[0m\n";
		} else {
			echo "\033[1;32mUpdating an existing site\033[0m\n";
		}
		echo sprintf( "  Site URL:  %s\n", $config->getTargetSiteUrl() );
		echo sprintf( "  Site path: %s\n", $config->getTargetSiteRoot() );
		echo sprintf( "  Blueprint: %s\n", $config->getBlueprint()->get_human_readable_name() );
		echo PHP_EOL;
		// In a real application you might now pass $config to a service class.
		$runner->run();
		echo PHP_EOL;
		echo sprintf( "\033[32m✔ Blueprint successfully executed.\033[0m\n" );
	} catch ( PermissionsException $ex ) {
		echo PHP_EOL . PHP_EOL;
		$permission = $ex->getPermission();
		$flag       = RunnerConfiguration::getPermissionCliFlag( $permission );

		echo sprintf( "\033[31mPermission Error:\033[0m %s\n", $ex->getMessage() );
		echo sprintf( "\033[33mTip:\033[0m Run with \033[1m--allow=%s\033[0m to grant this permission.\n", $flag );
		exit( 1 );
	}
} catch ( BlueprintExecutionException $ex ) {
	echo PHP_EOL;
	if ( ! $ex->schemaError ) {
		echo sprintf( "\033[31mError:\033[0m %s\n", $ex->getMessage() );
		while ( $ex->getPrevious() ) {
			$ex = $ex->getPrevious();
			echo sprintf( "\033[31mCaused by:\033[0m %s\n", $ex->getMessage() );
		}
		exit( 1 );
	}

	echo sprintf( "\033[31mError:\033[0m %s See the validation errors below:\n", $ex->getMessage() );
	$lastPrettyPath = '';
	$currentError   = $ex->schemaError;
	while ( $currentError ) {
		$prettyPath = $currentError->getPrettyPath();
		if ( $prettyPath !== $lastPrettyPath ) {
			echo sprintf( "\033[31m%s\033[0m: \n", $prettyPath );
		}
		echo $currentError->message . PHP_EOL;
		$currentError   = $currentError->getMostProbableCause();
		$lastPrettyPath = $prettyPath;
	}
	exit( 1 );
}
