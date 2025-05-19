<?php
/**
 * blueprint.php – the main entry point to the WordPress Blueprint Runner CLI.
 * 
 * @TODO: Get the tests to pass
 * @TODO: Support commands: "exec", "validate", "to-execution-plan" etc. See the Blueprints v2 spec for more commands ideas.
 * @TODO: Support --truncate-site-directory option for easy development – just re-run the same command to override a previous site.
 * @TODO: Prevent remote resources from using local bundle paths
 * @TODO: Get explicit user consent before using paths from a local directory
 * @TODO: Add a flag that allows user-defined runPHP steps?
 * @TODO: Add a verbose mode
 */

require __DIR__ . '/../../../vendor/autoload.php';

use WordPress\Blueprints\Logger\CLILogger;
use WordPress\Blueprints\RunnerConfiguration;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\ProgressObserver;
use WordPress\Blueprints\Runner;
use WordPress\Filesystem\LocalFilesystem;

// Enable colours on Windows 10+ (safe‑no‑op elsewhere)
if (\PHP_OS_FAMILY === 'Windows' && \function_exists('sapi_windows_vt100_support')) {
    @sapi_windows_vt100_support(\STDOUT, true);
}

// -----------------------------------------------------------------------------
//   Option definition – tweak this block to add / modify CLI options
// -----------------------------------------------------------------------------
$optionDefs = [
    /* long               short hasVal default        description */
    'site-url'          => ['u', true , null        , 'Public site URL (https://example.com)'],
    'site-path'         => [null, true, null        , 'Target directory with WordPress install context)'],
    'execution-context' => ['x', true , null        , 'Source directory with Blueprint context files'],
    'mode'              => ['m', true , 'create'    , 'Execution mode (create|apply)'],
    'db-engine'         => ['d', true , 'mysql'     , 'Database engine (mysql|sqlite)'],
    'db-host'           => [null,true , 'localhost' , 'MySQL host'],
    'db-user'           => [null,true , 'root'      , 'MySQL user'],
    'db-pass'           => [null,true , ''          , 'MySQL password'],
    'db-name'           => [null,true , 'wordpress' , 'MySQL database'],
    'db-path'           => ['p', true , 'wp.db'     , 'SQLite file path'],
    'dry-run'           => [null, false, false      , 'Don’t change anything, just validate'],
    'help'              => ['h', false, false       , 'Show full help'],
    'version'           => ['V', false, false       , 'Show version'],
];

// -----------------------------------------------------------------------------
//   Custom command‑line parser (POSIX‑ish but without getopt dependency)
// -----------------------------------------------------------------------------
function parseArguments(array $argv, array $optionDefs): array
{
    $positionals  = [];
    $options      = [];
    $short2long   = [];

    // Initialise defaults & maps
    foreach ($optionDefs as $long => $def) {
        [$short, , $default] = $def;
        $options[$long] = $default;
        if ($short) $short2long[$short] = $long;
    }

    $i = 1; // skip script name
    while ($i < count($argv)) {
        $token = $argv[$i];

        // Long option --foo or --foo=bar
        if (preg_match('/^--([^=]+)(=(.*))?$/', $token, $m)) {
            $long = $m[1];
            if (!isset($optionDefs[$long])) throw new InvalidArgumentException("Unknown option --$long");
            [$short,$hasVal] = $optionDefs[$long];
            if ($hasVal) {
                $val = $m[3] ?? ($argv[++$i] ?? null);
                if ($val === null) throw new InvalidArgumentException("Option --$long requires a value");
                $options[$long] = $val;
            } else {
                $options[$long] = true;
            }
            $i++;
            continue;
        }

        // Short option(s): -abc or -e mysql or -e=mysql
        if (preg_match('/^-([A-Za-z]{1,})(=(.*))?$/', $token, $m)) {
            $bundle    = str_split($m[1]);
            $inlineVal = $m[3] ?? null;
            foreach ($bundle as $idx => $short) {
                if (!isset($short2long[$short])) throw new InvalidArgumentException("Unknown option -$short");
                $long   = $short2long[$short];
                $hasVal = $optionDefs[$long][1];
                if ($hasVal) {
                    if ($inlineVal !== null && $idx === 0) {
                        $options[$long] = $inlineVal;
                    } else {
                        $val = ($idx === count($bundle)-1) ? ($argv[++$i] ?? null) : null;
                        if ($val === null) throw new InvalidArgumentException("Option -$short requires a value");
                        $options[$long] = $val;
                    }
                    break; // value‑bearing short stops bundle processing
                } else {
                    $options[$long] = true;
                }
            }
            $i++;
            continue;
        }

        // Positional argument
        $positionals[] = $token;
        $i++;
    }

    return [$positionals, $options];
}


function cliArgsToRunnerConfiguration(array $positionals, array $options): RunnerConfiguration
{
    $config = new RunnerConfiguration();

    // Map positional arguments
    if (empty($positionals)) {
        throw new InvalidArgumentException("A Blueprint reference must be specified as a positional argument.");
    }

    // The first positional is the blueprint reference
	try {
		$blueprint_reference = $positionals[0];
		if(str_starts_with($blueprint_reference, './')) {
			$blueprint_reference = realpath($blueprint_reference);
		}
		$config->setBlueprint(DataReference::create($blueprint_reference));
	} catch (InvalidArgumentException $e) {
		throw new InvalidArgumentException("Invalid Blueprint reference: " . $positionals[0]);
	}

    // Set site root (target directory) if provided via --execution-context, else default to cwd
    if (!empty($options['execution-context'])) {
        $executionContext = $options['execution-context'];
        $absoluteExecutionContext = realpath($executionContext);
        if ($absoluteExecutionContext === false) {
            throw new InvalidArgumentException("The execution context path does not exist: {$executionContext}");
        }
        $config->setExecutionContext(LocalFilesystem::create($absoluteExecutionContext));
    }

    if (empty($options['site-path'])) {
        throw new InvalidArgumentException("--site-path option is required.");
    }

	$targetSiteRoot = $options['site-path'];
    $absoluteTargetSiteRoot = realpath($targetSiteRoot);
    if ($absoluteTargetSiteRoot === false) {
        throw new InvalidArgumentException("The target site root path does not exist: {$targetSiteRoot}");
    }
    $config->setTargetSiteRoot($absoluteTargetSiteRoot);

	$config->setTargetSiteUrl($options['site-url']);

    // Set execution mode
    if (!empty($options['mode'])) {
        // Accept 'create' or 'apply' as CLI values, map to internal values
        $mode = $options['mode'];
        if ($mode === 'create') {
            $config->setExecutionMode('create-new-site');
        } elseif ($mode === 'apply') {
            $config->setExecutionMode('apply-to-existing-site');
        } else {
            $config->setExecutionMode($mode);
        }
    }

    // Set database engine
    if (!empty($options['db-engine'])) {
        $config->setDatabaseEngine($options['db-engine']);
    }

    // Set database credentials
    $dbEngine = $options['db-engine'] ?? 'mysql';
    $dbCreds = [];
    if ($dbEngine === 'mysql') {
        $dbCreds = [
            'host'         => $options['db-host'] ?? 'localhost',
            'username'     => $options['db-user'] ?? 'root',
            'password'     => $options['db-pass'] ?? '',
            'databaseName' => $options['db-name'] ?? 'wordpress',
        ];
    } elseif ($dbEngine === 'sqlite') {
        $dbCreds = [
            'path' => $options['db-path'] ?? 'wp.db',
        ];
    }
    $config->setDatabaseCredentials($dbCreds);

    // Set execution context (Filesystem) if provided
    if (!empty($options['execution-context'])) {
        // Only set if Filesystem class is available and directory exists
        if (class_exists('\WordPress\Filesystem\Filesystem') && is_dir($options['execution-context'])) {
            $fs = new \WordPress\Filesystem\Filesystem($options['execution-context']);
            $config->setExecutionContext($fs);
        }
    }

	$config->setLogger(
		new CLILogger('php://stdout', CLILogger::VERBOSITY_INFO)
	);

    return $config;
}


// -----------------------------------------------------------------------------
//   Help & version
// -----------------------------------------------------------------------------
function showUsageShort(): void
{
    $script = basename($_SERVER['argv'][0]);
    echo "\033[1mUsage:\033[0m php $script \033[33m<blueprint>\033[0m --site-url=\033[33m<url>\033[0m --site-path=\033[33m<path>\033[0m [options]\n";
}

function showHelp(array $optionDefs): void
{
    $script = basename($_SERVER['argv'][0]);
    echo "\033[1mWordPress Blueprint Runner\033[0m\n";
    showUsageShort();
    echo "\n";
    echo "\033[1mPositional arguments:\033[0m\n";
    echo "  blueprint            Path / URL / DataReference to the blueprint (required)\n\n";

    echo "\033[1mOptions:\033[0m\n";
    foreach ($optionDefs as $long => [$short,$hasVal,$def,$desc]) {
        $flags = '  '.($short ? "-$short, " : '    ')."--$long";
        if ($hasVal) $flags .= " <value>";
        $defaultText = is_null($def) ? '' : ' (default '.var_export($def,true).')';
        if ($long === 'site-path') {
            $defaultText = ' (required)';
        }
        printf("%-34s %s\n", $flags, $desc.$defaultText);
    }
    echo "\nExamples:\n";
    echo "  php $script my-blueprint.yaml --site-url https://mysite.test --site-path /var/www/mysite.com\n";
    echo "  php $script my-blueprint.yaml --execution-context /var/www --site-url https://mysite.test --mode apply --site-path ./site\n";
    echo "\n";
}

function reportProgress($progress, $caption) {
	static $lastLength = 0;
	static $columns = null;
	$output = sprintf("[%3d%%] %s", $progress, $caption);
	$currentLength = strlen($output);
	
	// Get terminal width if possible
	if (null === $columns) {
		if (function_exists('exec') && false !== exec('tput cols 2>/dev/null', $out)) {
			$columns = (int) $out[0];
		} elseif (function_exists('shell_exec') && ($shellColumns = shell_exec('tput cols 2>/dev/null'))) {
			$columns = (int) $shellColumns;
		}
		if (null === $columns) {
			$columns = 80;
		}
	}
	
	// Truncate if longer than terminal width
	if ($currentLength > $columns - 1) {
		$output = substr($output, 0, $columns - 4) . '...';
		$currentLength = $columns - 1;
	}
	
	fprintf(STDERR, "\r%s%s", $output, $currentLength < $lastLength ? str_repeat(' ', $lastLength - $currentLength) : '');
	$lastLength = $currentLength;	
}

// -----------------------------------------------------------------------------
//   Main entry
// -----------------------------------------------------------------------------
try {
    [$positionals, $options] = parseArguments($_SERVER['argv'], $optionDefs);

    if ($options['help']) {
        showHelp($optionDefs);
        exit(0);
    }
    if ($options['version']) {
		echo "WordPress Blueprint Runner CLI v1.2.0\n";
        exit(0);
    }

    // Validate positional blueprint
    if (count($positionals) < 1) {
        showHelp($optionDefs);
		exit(1);
    }

    // Convert CLI arguments to RunnerConfiguration
    $config = cliArgsToRunnerConfiguration($positionals, $options);

    if ($options['dry-run']) {
        echo "\033[32mArguments valid – dry‑run mode, exiting without changes.\033[0m\n";
        exit(0);
    }
	$config
		->setProgressObserver(new ProgressObserver(function ($progress, $caption) {
			reportProgress($progress, $caption);
		}));
	$runner = new Runner($config);
} catch (InvalidArgumentException $ex) {
    echo "\033[31mError:\033[0m ".$ex->getMessage().PHP_EOL;
    echo "Try '--help' for usage.".PHP_EOL;
    exit(1);
} catch (\Throwable $ex) {
    echo "\033[31mUnexpected error:\033[0m ".$ex->getMessage().PHP_EOL;
    exit(1);
}

try {
    // Continue with runner execution (not implemented here)
	if($config->getExecutionMode() === 'create-new-site') {
		echo "\033[1;32mCreating a new site\033[0m\n";
	} else {
		echo "\033[1;32mUpdating an existing site\033[0m\n";
	}
	echo sprintf("  Site URL:  %s\n", $config->getTargetSiteUrl());
	echo sprintf("  Site path: %s\n", $config->getTargetSiteRoot());
	echo sprintf("  Blueprint: %s\n", $config->getBlueprint()->get_human_readable_name());
	echo PHP_EOL;
    // In a real application you might now pass $config to a service class.
	$runner->run();
	echo PHP_EOL;
	echo sprintf("\033[32m✔ Blueprint successfully executed.\033[0m\n");
} catch (BlueprintExecutionException $ex) {
	echo PHP_EOL . PHP_EOL;
	if(!$ex->schemaError) {
		echo sprintf("\033[31mError:\033[0m %s\n", $ex->getMessage());
		while($ex->getPrevious()) {
			$ex = $ex->getPrevious();
			echo sprintf("\033[31mCaused by:\033[0m %s\n", $ex->getMessage());
		}
		exit(1);
	}

	echo sprintf("\033[31mError:\033[0m %s See the validation errors below:\n", $ex->getMessage());
	$lastPrettyPath = '';
	$currentError = $ex->schemaError;
	while ($currentError) {
		$prettyPath = $currentError->getPrettyPath();
		if($prettyPath !== $lastPrettyPath) {
			echo sprintf("\033[31m%s\033[0m: \n", $prettyPath);
		}
		echo $currentError->message . PHP_EOL;
		$currentError = $currentError->getMostProbableCause();
		$lastPrettyPath = $prettyPath;
	}
	exit(1);
// } catch (\Throwable $ex) {
// 	var_dump($ex instanceof BlueprintExecutionException);
// 	var_dump(get_class($ex));
// 	echo "\033[31mUnexpected error:\033[0m ".$ex->getMessage().PHP_EOL;
// 	exit(1);
}