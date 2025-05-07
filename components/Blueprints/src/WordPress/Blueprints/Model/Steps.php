<?php

namespace WordPress\Blueprints\Model;

use Exception;
use InvalidArgumentException; // Standard PHP exception
use JsonException; // Standard PHP exception
use RuntimeException;
use WordPress\Blueprints\BlueprintV2Validator;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Progress\ProgressEvent;
use WordPress\Blueprints\Progress\DoneEvent;
use WordPress\Blueprints\Resources\Model\DataReference;
use WordPress\Blueprints\Resources\Model\Directory;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\Blueprints\Resources\Model\WordPressOrgPlugin;
use WordPress\Blueprints\Resources\Model\WordPressOrgTheme;
use WordPress\Blueprints\Runner\WordPressBoot\BootOptions;
use WordPress\Blueprints\Runner\WordPressBoot\WordPressBootManager;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\pipe_stream;

// Silence PHP deprecation warnings
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Initialize runtime for the given document root
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../../../vendor/autoload.php';

function fetch_http_headers(string $url): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'follow_location' => 1,
            'max_redirects' => 10,
            'ignore_errors' => true,
        ]
    ]);

    // Use a stream to only fetch headers without downloading the body
    $fp = @fopen($url, 'r', false, $context);
    if (!$fp) {
        throw new RuntimeException("Failed to connect to: $url");
    }

    // Get headers from the stream
    $meta_data = stream_get_meta_data($fp);
    $headers = $meta_data['wrapper_data'];
    
    // Close the stream immediately to avoid downloading the body
    fclose($fp);

    // Convert headers to associative array
    $result = [];
    foreach ($headers as $header) {
        if (strpos($header, ':') !== false) {
            list($name, $value) = explode(':', $header, 2);
            $result[trim($name)] = trim($value);
        } elseif (strpos($header, 'HTTP/') === 0) {
            $result[0] = $header;
        }
    }

    return $result;
}

// --- Enums / Helper Data Objects ---

/**
 * Defines behavior when a plugin installation fails.
 */
enum PluginErrorBehavior: string {
    case SKIP_PLUGIN = 'skip-plugin';
    case THROW_ERROR = 'throw'; // Default behavior implied
}

/**
 * Standard HTTP Methods for RunPHPStep.
 */
enum HttpMethod: string {
    case GET = 'GET';
    case POST = 'POST';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';
    case PATCH = 'PATCH';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
}

/**
 * Represents version constraints for WordPress or PHP.
 */
class VersionConstraint {
    private string $minVersion;
    private ?string $maxVersion;
    private ?string $recommendedVersion;

    // Default values from the schema
    private static string $DEFAULT_WP_VERSION = 'latest';
    private static string $DEFAULT_PHP_VERSION = '8.0';

    public function __construct(string $minVersion, ?string $maxVersion = null, ?string $recommendedVersion = null) {
        $this->minVersion = $minVersion;
        $this->maxVersion = $maxVersion;
        $this->recommendedVersion = $recommendedVersion;
    }

    public function getMinVersion(): string {
        return $this->minVersion;
    }

    public function getMaxVersion(): ?string {
        return $this->maxVersion;
    }

    public function getRecommendedVersion(): ?string {
        return $this->recommendedVersion ?: $this->minVersion;
    }

    /**
     * Create a VersionConstraint from a string or array representation.
     *
     * @param string|array|null $constraint The version constraint as a string ("6.4") or array (["minVersion" => "6.4"])
     * @param bool $isPhp Whether this is a PHP version constraint (true) or WordPress (false)
     * @return self A new VersionConstraint object with appropriate defaults
     */
    public static function fromMixed($constraint, bool $isPhp = false): self {
        $defaultVersion = $isPhp ? self::$DEFAULT_PHP_VERSION : self::$DEFAULT_WP_VERSION;

        if ($constraint === null) {
            return new self($defaultVersion, null, $defaultVersion);
        }

        // Simple string like "6.4"
        if (is_string($constraint)) {
            return new self($constraint, null, $constraint);
        }

        // Array format with explicit keys
        if (is_array($constraint)) {
            $minVersion = $constraint['minVersion'] ?? $constraint['min'] ?? $defaultVersion;
            $maxVersion = $constraint['maxVersion'] ?? $constraint['max'] ?? null;

            // For WP: preferredVersion in schema, for PHP: recommendedVersion
            $recommendedVersion = $constraint['recommendedVersion'] ??
                                 $constraint['preferredVersion'] ??
                                 $constraint['recommended'] ??
                                 $constraint['preferred'] ??
                                 $minVersion;

            return new self($minVersion, $maxVersion, $recommendedVersion);
        }

        throw new InvalidArgumentException('Unsupported version constraint format: ' . gettype($constraint));
    }
}

/**
 * Container for Blueprint metadata with defaults.
 */
class BlueprintMetadata {
    private string $name;
    private string $description;
    private string $version;
    private array $authors;
    private ?string $authorUrl;
    private ?string $donateLink;
    private array $tags;
    private ?string $license;

    /**
     * Create a new BlueprintMetadata instance.
     *
     * @param string $name The name of the blueprint (required)
     * @param string $description Description of the blueprint (required)
     * @param string $version Version of the blueprint, typically semver format
     * @param array $authors List of author names
     * @param string|null $authorUrl URL to author's website
     * @param string|null $donateLink URL for donation/support
     * @param array $tags Tags or categories for this blueprint
     * @param string|null $license License identifier (e.g., "GPL-2.0")
     */
    public function __construct(
        string $name,
        string $description,
        string $version = '1.0.0',
        array $authors = [],
        ?string $authorUrl = null,
        ?string $donateLink = null,
        array $tags = [],
        ?string $license = null
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->version = $version;
        $this->authors = $authors;
        $this->authorUrl = $authorUrl;
        $this->donateLink = $donateLink;
        $this->tags = $tags;
        $this->license = $license;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function getVersion(): string {
        return $this->version;
    }

    public function getAuthors(): array {
        return $this->authors;
    }

    public function getAuthorUrl(): ?string {
        return $this->authorUrl;
    }

    public function getDonateLink(): ?string {
        return $this->donateLink;
    }

    public function getTags(): array {
        return $this->tags;
    }

    public function getLicense(): ?string {
        return $this->license;
    }

    /**
     * Create a BlueprintMetadata object from an array of data.
     *
     * @param array|null $data The metadata array from the blueprint
     * @return self A new BlueprintMetadata object with data or defaults
     */
    public static function fromArray(?array $data): self {
        if ($data === null) {
            return new self(
                'Untitled Blueprint',
                'No description provided'
            );
        }

        return new self(
            $data['name'] ?? 'Untitled Blueprint',
            $data['description'] ?? 'No description provided',
            $data['version'] ?? '1.0.0',
            $data['authors'] ?? [],
            $data['authorUrl'] ?? null,
            $data['donateLink'] ?? null,
            $data['tags'] ?? [],
            $data['license'] ?? null
        );
    }
}

// StepRunnerInterface
interface StepRunnerInterface {
	/**
	 * Runs the step with the given parameters.
	 *
	 * @param object $step The step object with configuration
	 * @param Runtime $runtime The runtime providing environment access
	 * @param Tracker $tracker The tracker for reporting progress
	 * @return mixed The result of running the step
	 */
	public function run(object $step, Runtime $runtime, Tracker $tracker);
}

// --- Step Classes ---

/**
 * Represents the 'activatePlugin' step.
 */
class ActivatePluginStep {
    /**
     * Path to the plugin directory or entry file.
     * Examples: '/wordpress/wp-content/plugins/plugin-name', 'plugin-name/plugin-name.php'
     */
    private string $pluginPath;

    /**
     * @param string $pluginPath Path to the plugin directory or entry file.
     */
    public function __construct(string $pluginPath) {
        $this->pluginPath = $pluginPath;
    }

    public function getPluginPath(): string {
        return $this->pluginPath;
    }

    public function setPluginPath(string $pluginPath): void {
        $this->pluginPath = $pluginPath;
    }
}

// ActivatePluginStepRunner
class ActivatePluginStepRunner {
	/**
	 * Runs the activatePlugin step.
	 *
	 * @param ActivatePluginStep $step The activatePlugin step configuration
	 * @param Runtime $runtime The runtime providing environment access
	 * @param Tracker $tracker The tracker for reporting progress
	 * @return mixed The result of running the step
	 */
	public function run(ActivatePluginStep $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Activating plugin ' . ($step->getPluginPath() ?? ''));
		return $runtime->evalPhpInSubProcess(
			file_get_contents(__DIR__ . '/ActivatePlugin/wp_activate_plugin.php'),
			[
				'PLUGIN_PATH' => $step->getPluginPath(),
			]
		);
	}
}


/**
 * Represents the 'activateTheme' step.
 */
class ActivateThemeStep {
    /**
     * The name of the theme folder inside wp-content/themes/.
     */
    private string $themeFolderName;

    /**
     * @param string $themeFolderName The name of the theme folder.
     */
    public function __construct(string $themeFolderName) {
        $this->themeFolderName = $themeFolderName;
    }

    public function getThemeFolderName(): string {
        return $this->themeFolderName;
    }

    public function setThemeFolderName(string $themeFolderName): void {
        $this->themeFolderName = $themeFolderName;
    }
}

// ActivateThemeStepRunner
class ActivateThemeStepRunner {
	public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Activating theme ' . $step->getThemeFolderName());
		return $runtime->evalPhpInSubProcess(
			file_get_contents(__DIR__ . '/ActivateTheme/wp_activate_theme.php'),
			[
				'THEME_FOLDER_NAME' => $step->getThemeFolderName(),
			]
		);
	}
}

/**
 * Represents the 'cp' (copy) step.
 */
class CpStep {
    private string $fromPath;
    private string $toPath;

    /**
     * @param string $fromPath The source path to copy from.
     * @param string $toPath   The destination path to copy to.
     */
    public function __construct(string $fromPath, string $toPath) {
        $this->fromPath = $fromPath;
        $this->toPath = $toPath;
    }

    public function getFromPath(): string {
        return $this->fromPath;
    }

    public function setFromPath(string $fromPath): void {
        $this->fromPath = $fromPath;
    }

    public function getToPath(): string {
        return $this->toPath;
    }

    public function setToPath(string $toPath): void {
        $this->toPath = $toPath;
    }
}

// CpStepRunner
class CpStepRunner {
	/**
	 * Runs the cp step.
	 *
	 * @param CpStep $step The cp step configuration
	 * @param Runtime $runtime The runtime providing environment access
	 * @param Tracker $tracker The tracker for reporting progress
	 * @return mixed The result of running the step
	 */
	public function run(CpStep $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Copying from ' . $step->getFromPath() . ' to ' . $step->getToPath());
		return $runtime->getTargetFilesystem()->copy(
			$step->getFromPath(),
			$step->getToPath(),
			[ 'recursive' => true ]
		);
	}
}

/**
 * Represents the 'defineConstants' step.
 */
class DefineConstantsStep {
    /**
     * An associative array of constant names to their values (string, bool, int, float).
     * @var array<string, scalar>
     */
    private array $constants;

    /**
     * @param array<string, scalar> $constants Constants to define.
     */
    public function __construct(array $constants) {
        $this->constants = $constants;
    }

    /**
     * @return array<string, scalar>
     */
    public function getConstants(): array {
        return $this->constants;
    }

    /**
     * @param array<string, scalar> $constants
     */
    public function setConstants(array $constants): void {
        $this->constants = $constants;
    }
}

// DefineConstantsStepRunner
class DefineConstantsStepRunner {
	/**
	 * Runs the defineConstants step.
	 *
	 * @param DefineConstantsStep $step The defineConstants step configuration
	 * @param Runtime $runtime The runtime providing environment access
	 * @param Tracker $tracker The tracker for reporting progress
	 * @return mixed The result of running the step
	 */
	public function run(DefineConstantsStep $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Defining wp-config constants');
		$functions = file_get_contents(__DIR__ . '/DefineWpConfigConsts/functions.php');
		return $runtime->evalPhpInSubProcess(
			"$functions ?>" . '<?php
    $wp_config_path = getenv("DOCROOT") . "/wp-config.php";
    if (!file_exists($wp_config_path)) { error_log("Blueprint Error: wp-config.php file not found at " . $wp_config_path); exit(1); }
    if (!is_readable($wp_config_path) || !is_writable($wp_config_path)) { error_log("Blueprint Error: wp-config.php is not readable or writable at " . $wp_config_path); exit(1); }
	$consts = json_decode(getenv("CONSTS"), true);
	$wp_config = file_get_contents($wp_config_path);
	$new_wp_config = rewrite_wp_config_to_define_constants($wp_config, $consts);
	file_put_contents($wp_config_path, $new_wp_config);
',
			array('CONSTS' => json_encode($step->getConstants()))
		);
	}
}

/**
 * Represents the 'importThemeStarterContent' step.
 */
class ImportThemeStarterContentStep {
    /**
     * Optional slug of the theme to import content from.
     * If null, might imply the currently active theme.
     */
    private ?string $themeSlug;

    /**
     * @param string|null $themeSlug Optional theme slug.
     */
    public function __construct(?string $themeSlug = null) {
        $this->themeSlug = $themeSlug;
    }

    public function getThemeSlug(): ?string {
        return $this->themeSlug;
    }

    public function setThemeSlug(?string $themeSlug): void {
        $this->themeSlug = $themeSlug;
    }
}

/**
 * Represents the 'installPlugin' step.
 * Simplified by embedding PluginDefinition properties.
 */
class InstallPluginStep {
    /**
     * Plugin source reference.
     */
    private DataReference $source;

    /**
     * Whether to activate the plugin after installation. Defaults to true.
     */
    private bool $active;

    /**
     * Optional key-value pairs passed to the plugin during activation.
     * @var array<string, mixed>|null
     */
    private ?array $activationOptions;

    /**
     * Behavior on installation error. Defaults to THROW_ERROR.
     */
    private PluginErrorBehavior $onError;

    /**
     * @param DataReference $source            Plugin source reference.
     * @param bool $active              Activate after install?
     * @param array<string, mixed>|null $activationOptions Optional activation data.
     * @param PluginErrorBehavior $onError           Error handling behavior.
     */
    public function __construct(
        DataReference $source,
        bool $active = true,
        ?array $activationOptions = null,
        PluginErrorBehavior $onError = PluginErrorBehavior::THROW_ERROR
    ) {
        $this->source = $source;
        $this->active = $active;
        $this->activationOptions = $activationOptions;
        $this->onError = $onError;
    }

    public function getSource(): DataReference {
        return $this->source;
    }

    public function setSource(DataReference $source): void {
        $this->source = $source;
    }

    public function isActive(): bool {
        return $this->active;
    }

    public function setActive(bool $active): void {
        $this->active = $active;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActivationOptions(): ?array {
        return $this->activationOptions;
    }

    /**
     * @param array<string, mixed>|null $activationOptions
     */
    public function setActivationOptions(?array $activationOptions): void {
        $this->activationOptions = $activationOptions;
    }

    public function getOnError(): PluginErrorBehavior {
        return $this->onError;
    }

    public function setOnError(PluginErrorBehavior $onError): void {
        $this->onError = $onError;
    }
}

class InstallPluginStepRunner {
	public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Installing plugin');

		$fs = $runtime->getTargetFilesystem();
		FilesystemHelpers::withTemporaryDirectory($fs, function($temp_dir) use ($fs, $runtime, $step, $tracker) {
			$plugin_data = $runtime->resolveReferencedData($step->getSource());

			if ($plugin_data instanceof Directory) {
				$zip_path = $temp_dir . '/' . $plugin_data->dirname . '.zip';
				$zip_stream = $fs->open_write_stream($zip_path);
				$zip_encoder = new ZipEncoder($zip_stream);
				$zip_encoder->append_from_filesystem($plugin_data->filesystem);
				$zip_encoder->close();
			} elseif ($plugin_data instanceof File) {
				$zip_filename = preg_replace('/\.(zip|php)$/', '', $plugin_data->filename) . '.zip';
				$zip_path = $temp_dir . '/' . $zip_filename;
				$zip_stream = $fs->open_write_stream($zip_path);
				$plugin_data->stream->pull(4);
				if ($plugin_data->stream->peek(4) === "PK\x03\x04") {
					pipe_stream($plugin_data->stream, $zip_stream);
				} else {
					$zip_encoder = new ZipEncoder($zip_stream);
					$zip_encoder->append_file(new FileEntry([
						'path'              => $plugin_data->filename,
						'body_reader'       => $plugin_data->stream,
						'compressionMethod' => ZipDecoder::COMPRESSION_DEFLATE,
					]));
					$zip_encoder->close();
				}
			}
			$zip_stream->close_writing();

			$tracker->set(50, 'Installing plugin');

			$runtime->evalPhpInSubProcess(
				file_get_contents(__DIR__ . '/InstallPlugin/wp_install_plugin.php'),
				['PLUGIN_ZIP_PATH' => $zip_path, 'OUTPUT_FILE' => $temp_dir . '/plugin_path.txt']
			);

			$relative_path = $fs->get_contents($temp_dir . '/plugin_path.txt');

			if ($step->isActive()) {
				$tracker->set(75, 'Activating plugin');
				$runtime->evalPhpInSubProcess(
					file_get_contents(__DIR__ . '/ActivatePlugin/wp_activate_plugin.php'),
					['PLUGIN_PATH' => $relative_path]
				);
			}

			$tracker->set(100, 'Plugin installation complete');
		}, '');

		return true;
	}
}

/**
 * Represents the 'installTheme' step.
 */
class InstallThemeStep {
    /**
     * Theme source identifier (slug, slug@version, URL, ./path, /path).
     */
    private DataReference $source;

    /**
     * Whether to activate the theme after installing it. Defaults to false.
     */
    private bool $activate;

    /**
     * Whether to import the theme's starter content after installing it. Defaults to false.
     */
    private bool $importStarterContent;

    /**
     * Optional target folder name. Defaults based on source.
     */
    private ?string $targetFolderName;

    /**
     * @param DataReference      $source              Theme source identifier.
     * @param bool        $activate            Activate after install?
     * @param bool        $importStarterContent Import starter content?
     * @param string|null $targetFolderName    Optional target folder name.
     */
    public function __construct(
        DataReference $source,
        bool $activate = false,
        bool $importStarterContent = false,
        ?string $targetFolderName = null
    ) {
        $this->source = $source;
        $this->activate = $activate;
        $this->importStarterContent = $importStarterContent;
        $this->targetFolderName = $targetFolderName;
    }

    public function getSource(): DataReference {
        return $this->source;
    }

    public function setSource(DataReference $source): void {
        $this->source = $source;
    }

    public function isActivate(): bool {
        return $this->activate;
    }

    public function setActivate(bool $activate): void {
        $this->activate = $activate;
    }

    public function isImportStarterContent(): bool {
        return $this->importStarterContent;
    }

    public function setImportStarterContent(bool $importStarterContent): void {
        $this->importStarterContent = $importStarterContent;
    }

    public function getTargetFolderName(): ?string {
        return $this->targetFolderName;
    }

    public function setTargetFolderName(?string $targetFolderName): void {
        $this->targetFolderName = $targetFolderName;
    }
}

class InstallThemeStepRunner {
	/**
	 * Runs the installTheme step.
	 *
	 * @param InstallThemeStep $step The installTheme step configuration
	 * @param Runtime $runtime The runtime providing environment access
	 * @param Tracker $tracker The tracker for reporting progress
	 * @return mixed The result of running the step
	 */
	public function run(InstallThemeStep $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Installing theme ' . $step->getSource());

		$fs = $runtime->getTargetFilesystem();
		FilesystemHelpers::withTemporaryDirectory($fs, function($temp_dir) use ($fs, $runtime, $step, $tracker) {
			// Create data reference for the theme source
			$dataRef = $step->getSource();
			$theme_data = $runtime->resolveReferencedData($dataRef);

			if ($theme_data instanceof Directory) {
				$zip_path = $temp_dir . '/' . $theme_data->dirname . '.zip';
				$zip_stream = $fs->open_write_stream($zip_path);
				$zip_encoder = new ZipEncoder($zip_stream);
				$zip_encoder->append_from_filesystem($theme_data->filesystem);
				$zip_encoder->close();
			} elseif ($theme_data instanceof File) {
				$zip_filename = preg_replace('/\.(zip|php)$/', '', $theme_data->filename) . '.zip';
				$zip_path = $temp_dir . '/' . $zip_filename;
				$zip_stream = $fs->open_write_stream($zip_path);
				$theme_data->stream->pull(4);
				if ($theme_data->stream->peek(4) === "PK\x03\x04") {
					pipe_stream($theme_data->stream, $zip_stream);
				} else {
					throw new \RuntimeException("Theme is not a valid zip file.");
				}
				$zip_stream->close_writing();
			}

			$tracker->set(50, 'Installing theme files');

			$output_file = $temp_dir . '/theme_stylesheet.txt';
			$install_script_result = $runtime->evalPhpInSubProcess(
				file_get_contents(__DIR__ . '/InstallTheme/wp_install_theme.php'),
				['THEME_ZIP_PATH' => $zip_path, 'OUTPUT_FILE' => $output_file]
			);

			if (!$fs->exists($output_file)) {
				throw new \RuntimeException(
					"Theme installation script did not create output file. Error output: {$install_script_result}"
				);
			}

			$theme_folder_name = trim($fs->get_contents($output_file));
			if (empty($theme_folder_name)) {
				throw new \RuntimeException(
					"Theme installation script did not return the theme stylesheet name."
				);
			}

			if ($step->isActivate()) {
				$tracker->set(75, 'Activating theme');
				$runtime->evalPhpInSubProcess(
					file_get_contents(__DIR__ . '/ActivateTheme/wp_activate_theme.php'),
					['THEME_FOLDER_NAME' => $theme_folder_name]
				);
			}

			$tracker->set(100, 'Theme installation complete');
		}, '');

		return true;
	}
}

/**
 * Represents the 'mkdir' (make directory) step.
 */
class MkdirStep {
    private string $path;

    /**
     * @param string $path The directory path to create.
     */
    public function __construct(string $path) {
        $this->path = $path;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function setPath(string $path): void {
        $this->path = $path;
    }
}

class MkdirStepRunner {
	/**
	 * Runs the mkdir step.
	 *
	 * @param MkdirStep $step The mkdir step configuration
	 * @param Runtime $runtime The runtime providing environment access
	 * @param Tracker $tracker The tracker for reporting progress
	 * @return mixed The result of running the step
	 */
	public function run(MkdirStep $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Creating directory ' . $step->getPath());

		$filesystem = $runtime->getTargetFilesystem();
		if ($filesystem->exists($step->getPath())) {
			throw new FilesystemException(
				sprintf('Path already exists: %s', $step->getPath())
			);
		}
		return $runtime->getTargetFilesystem()->mkdir($step->getPath(), ['recursive' => true]);
	}
}

/**
 * Represents the 'mv' (move) step.
 */
class MvStep {
    private string $fromPath;
    private string $toPath;

    /**
     * @param string $fromPath The source path to move from.
     * @param string $toPath   The destination path to move to.
     */
    public function __construct(string $fromPath, string $toPath) {
        $this->fromPath = $fromPath;
        $this->toPath = $toPath;
    }

    public function getFromPath(): string {
        return $this->fromPath;
    }

    public function setFromPath(string $fromPath): void {
        $this->fromPath = $fromPath;
    }

    public function getToPath(): string {
        return $this->toPath;
    }

    public function setToPath(string $toPath): void {
        $this->toPath = $toPath;
    }
}

class MvStepRunner {
	/**
	 * Runs the mv step.
	 *
	 * @param MvStep $step The mv step configuration
	 * @param Runtime $runtime The runtime providing environment access
	 * @param Tracker $tracker The tracker for reporting progress
	 * @return mixed The result of running the step
	 */
	public function run(MvStep $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Moving from ' . $step->getFromPath() . ' to ' . $step->getToPath());
		return $runtime->getTargetFilesystem()->rename($step->getFromPath(), $step->getToPath());
	}
}

/**
 * Represents the 'rm' (remove file) step.
 */
class RmStep {
    private string $path;

    /**
     * @param string $path The file path to remove.
     */
    public function __construct(string $path) {
        $this->path = $path;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function setPath(string $path): void {
        $this->path = $path;
    }
}

class RmStepRunner {
	public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Removing ' . $step->getPath());

		$filesystem = $runtime->getTargetFilesystem();
		$path = $step->getPath();

		if (!$filesystem->exists($path)) {
			throw new FilesystemException(sprintf('Path does not exist: %s', $path));
		}

		if ($filesystem->is_dir($path)) {
			return $filesystem->rmdir($path, ['recursive' => true]);
		} else {
			return $filesystem->rm($path);
		}
	}
}

/**
 * Represents the 'rmdir' (remove directory) step.
 */
class RmDirStep {
    private string $path;

    /**
     * @param string $path The directory path to remove.
     */
    public function __construct(string $path) {
        $this->path = $path;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function setPath(string $path): void {
        $this->path = $path;
    }
}

class RmDirStepRunner {
	/**
	 * Runs the rmdir step.
	 *
	 * @param RmDirStep $step The rmdir step configuration
	 * @param Runtime $runtime The runtime providing environment access
	 * @param Tracker $tracker The tracker for reporting progress
	 * @return mixed The result of running the step
	 */
	public function run(RmDirStep $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Removing directory ' . $step->getPath());
		return $runtime->getTargetFilesystem()->rmdir($step->getPath(), ['recursive' => true]);
	}
}

/**
 * Represents the 'runPHP' step.
 */
class RunPHPStep {
    private ?string $code;
    private ?string $relativeUri;
    private ?string $scriptPath;
    private ?string $protocol;
    private HttpMethod $method;
    /** @var array<string, string>|null */
    private ?array $headers;
    private ?string $body; // Simplified from string | Uint8Array
    /** @var array<string, string>|null */
    private ?array $env;
    /** @var array<string, string>|null */
    private ?array $__SERVER; // Renamed from $__SERVER to avoid PHP superglobal conflict

    /**
     * @param string|null $code        PHP code snippet to run (either code or scriptPath is required).
     * @param string|null $scriptPath  Path to PHP script to run (either code or scriptPath is required).
     * @param string|null $relativeUri Request path relative to domain.
     * @param HttpMethod  $method      HTTP method.
     * @param string|null $protocol    Request protocol (e.g., 'http', 'https').
     * @param array<string, string>|null $headers     Request headers.
     * @param string|null $body        Request body.
     * @param array<string, string>|null $env         Environment variables.
     * @param array<string, string>|null $__SERVER     $__SERVER variables.
     */
    public function __construct(
        ?string $code = null,
        ?string $scriptPath = null,
        ?string $relativeUri = null,
        HttpMethod $method = HttpMethod::GET,
        ?string $protocol = null,
        ?array $headers = null,
        ?string $body = null,
        ?array $env = null,
        ?array $__SERVER = null
    ) {
        // Basic validation: Ensure at least one execution target is provided
        if ($code === null && $scriptPath === null) {
             throw new \InvalidArgumentException('Either "code" or "scriptPath" must be provided for RunPHPStep.');
        }
        $this->code = $code;
        $this->scriptPath = $scriptPath;
        $this->relativeUri = $relativeUri;
        $this->method = $method;
        $this->protocol = $protocol;
        $this->headers = $headers;
        $this->body = $body;
        $this->env = $env;
        $this->__SERVER = $__SERVER;
    }

    // Getters and Setters for all properties...

    public function getCode(): ?string {
        return $this->code;
    }

    public function setCode(?string $code): void {
        $this->code = $code;
    }

    public function getRelativeUri(): ?string {
        return $this->relativeUri;
    }

    public function setRelativeUri(?string $relativeUri): void {
        $this->relativeUri = $relativeUri;
    }

    public function getScriptPath(): ?string {
        return $this->scriptPath;
    }

    public function setScriptPath(?string $scriptPath): void {
        $this->scriptPath = $scriptPath;
    }

    public function getProtocol(): ?string {
        return $this->protocol;
    }

    public function setProtocol(?string $protocol): void {
        $this->protocol = $protocol;
    }

    public function getMethod(): HttpMethod {
        return $this->method;
    }

    public function setMethod(HttpMethod $method): void {
        $this->method = $method;
    }

    /** @return array<string, string>|null */
    public function getHeaders(): ?array {
        return $this->headers;
    }

    /** @param array<string, string>|null $headers */
    public function setHeaders(?array $headers): void {
        $this->headers = $headers;
    }

    public function getBody(): ?string {
        return $this->body;
    }

    public function setBody(?string $body): void {
        $this->body = $body;
    }

    /** @return array<string, string>|null */
    public function getEnv(): ?array {
        return $this->env;
    }

    /** @param array<string, string>|null $env */
    public function setEnv(?array $env): void {
        $this->env = $env;
    }

    /** @return array<string, string>|null */
    public function get__SERVER(): ?array {
        return $this->__SERVER;
    }

    /** @param array<string, string>|null $__SERVER */
    public function set__SERVER(?array $__SERVER): void {
        $this->__SERVER = $__SERVER;
    }
}

class RunPHPStepRunner implements StepRunnerInterface {
	public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Running custom PHP code');
		return $runtime->evalPhpInSubProcess($step->getCode(), [
			'DOCROOT' => $runtime->getDocumentRoot(),
		]);
	}
}

/**
 * Represents the 'runSql' step.
 */
class RunSqlStep {
    /**
     * SQL source identifier (URL, ./path, /path).
     */
    private DataReference $source;

    /**
     * @param DataReference $source SQL source identifier.
     */
    public function __construct(DataReference $source) {
        $this->source = $source;
    }

    public function getSource(): DataReference {
        return $this->source;
    }

    public function setSource(DataReference $source): void {
        $this->source = $source;
    }
}

class RunSQLStepRunner implements StepRunnerInterface {
	public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Running SQL queries');

		// Get the data reference for the SQL file
		$source = $step->getSource();
		$sql = $runtime->resolveReferencedData($source);

		if (!$sql instanceof File) {
			throw new \InvalidArgumentException('The provided resource is not a file.');
		}

		return $runtime->evalPhpInSubProcess(
			<<<'CODE'
<?php
		require_once getenv("DOCROOT") . '/wp-load.php';
		$handle = STDIN;
		$buffer = '';
		global $wpdb;
		while ($bytes = fgets($handle)) {
			$buffer .= $bytes;
			if (!feof($handle) && substr($buffer, -1, 1) !== "\n") {
				continue;
			}
			$wpdb->query($buffer);
			$buffer = '';
		}
		fclose($handle);
CODE
			,
			null,
			$sql->stream->consume_all()
		);
	}
}

/**
 * Represents the 'setSiteLanguage' step.
 */
class SetSiteLanguageStep {
    /**
     * The language code (e.g., 'en_US', 'de_DE').
     */
    private string $language;

    /**
     * @param string $language The language code.
     */
    public function __construct(string $language) {
        $this->language = $language;
    }

    public function getLanguage(): string {
        return $this->language;
    }

    public function setLanguage(string $language): void {
        $this->language = $language;
    }
}

/**
 * Represents the 'setSiteOptions' step.
 */
class SetSiteOptionsStep {
    /**
     * An associative array of option names to their JSON-compatible values.
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * @param array<string, mixed> $options Site options to set.
     */
    public function __construct(array $options) {
        $this->options = $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array {
        return $this->options;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function setOptions(array $options): void {
        $this->options = $options;
    }
}

class SetSiteOptionsStepRunner implements StepRunnerInterface {
	public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Setting site options');
		return $runtime->evalPhpInSubProcess(
			'
<?php
		require getenv(\'DOCROOT\'). \'/wp-load.php\';
		$site_options = getenv("OPTIONS") ? json_decode(getenv("OPTIONS"), true) : [];
		foreach($site_options as $name => $value) {
			update_option($name, $value);
		}
',
			array('OPTIONS' => json_encode($step->getOptions()))
		);
	}
}

/**
 * Represents the 'unzip' step.
 */
class UnzipStep {
    /**
     * Zip file source identifier (URL, ./path, /path).
     */
    private DataReference $zipFile;

    /**
     * The path to extract the zip file to.
     */
    private string $extractToPath;

    /**
     * @param DataReference $zipFile Zip file source identifier.
     * @param string $extractToPath The path to extract the zip file to.
     */
    public function __construct(DataReference $zipFile, string $extractToPath) {
        $this->zipFile = $zipFile;
        $this->extractToPath = $extractToPath;
    }

    public function getZipFile(): DataReference {
        return $this->zipFile;
    }

    public function setZipFile(DataReference $zipFile): void {
        $this->zipFile = $zipFile;
    }

    public function getExtractToPath(): string {
        return $this->extractToPath;
    }

    public function setExtractToPath(string $extractToPath): void {
        $this->extractToPath = $extractToPath;
    }
}

class UnzipStepRunner implements StepRunnerInterface {
	public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->set(10, 'Unzipping...');

		$target_fs = $runtime->getTargetFilesystem();

		// Get the data reference for the zip file
		$zipFile = $step->getZipFile();
		$zip_stream = $runtime->resolveReferencedData($zipFile);

		if (!$zip_stream instanceof File) {
			throw new \InvalidArgumentException('The provided resource is not a zip file.');
		}

		$zip_fs = ZipFilesystem::create($zip_stream->stream);

		$tracker->set(50, 'Extracting files...');

		copy_between_filesystems([
			'source_filesystem' => $zip_fs,
			'source_path'       => '/',
			'target_filesystem' => $target_fs,
			'target_path'       => $step->getExtractToPath(),
			'recursive'         => true,
		]);

		$tracker->set(100, 'Extraction complete');

		return true;
	}
}

/**
 * Represents the 'wp-cli' step.
 */
class WPCLIStep {
    /**
     * The WP-CLI command arguments string (e.g., "plugin install woocommerce --activate").
     */
    private string $command;

    /**
     * Optional path to the WP-CLI executable.
     */
    private ?string $wpCliPath;

    /**
     * @param string      $command   The WP-CLI command string.
     * @param string|null $wpCliPath Optional path to WP-CLI executable.
     */
    public function __construct(string $command, ?string $wpCliPath = null) {
        $this->command = $command;
        $this->wpCliPath = $wpCliPath;
    }

    public function getCommand(): string {
        return $this->command;
    }

    public function setCommand(string $command): void {
        $this->command = $command;
    }

    public function getWpCliPath(): ?string {
        return $this->wpCliPath;
    }

    public function setWpCliPath(?string $wpCliPath): void {
        $this->wpCliPath = $wpCliPath;
    }
}

class WPCLIStepRunner implements StepRunnerInterface {
	public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Running WP-CLI command: ' . $step->getCommand());
		return $runtime->runShellCommand($step->getCommand());
	}
}

/**
 * Represents the 'writeFiles' step.
 */
class WriteFilesStep {
    /**
     * An associative array where keys are file paths and values are their contents.
     * @var array<string, string|DataReference>
     */
    private array $files;

    /**
     * @param array<string, string|DataReference> $files Files to write (path => content).
     */
    public function __construct(array $files) {
        $this->files = $files;
    }

    /**
     * @return array<string, string|DataReference>
     */
    public function getFiles(): array {
        return $this->files;
    }

    /**
     * @param array<string, string|DataReference> $files
     */
    public function setFiles(array $files): void {
        $this->files = $files;
    }
}

class WriteFilesStepRunner {
	public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
		$files = $step->getFiles();
		$total_files = count($files);

		$tracker->set(10, 'Writing files...');

		$target_fs = $runtime->getTargetFilesystem();
		$files_written = 0;

		foreach ($files as $path => $data) {
			if ($tracker) {
				$progress_value = 10 + (($files_written / $total_files) * 80);
				$tracker->set((int)$progress_value, "Writing file {$files_written}/{$total_files}: {$path}");
			}

			// Create directory if it doesn't exist
			$dir = dirname($path);
			if ($dir && $dir !== '/' && $dir !== '.') {
				$target_fs->mkdir($dir, ['recursive' => true]);
			}

			// Handle the data which can be a string or a DataReference
			if (is_string($data)) {
				$content = $data;
			} else {
				$data_stream = $runtime->resolveReferencedData($data);
				$content = $data_stream->stream->consume_all();
			}

			$target_fs->put_contents($path, $content);
			$files_written++;
		}

		$tracker->set(100, "All {$total_files} files written successfully.");

		return true;
	}
}


/**
 * Represents a validated and processed WordPress Blueprint.
 *
 * This class is created from a validated Blueprint JSON string using the static
 * `create()` method. It parses the JSON, extracts high-level constraints and
 * metadata, and generates the full execution plan as an ordered list of step
 * objects.
 */
class Blueprint
{
    private array $validated_array;
    /** @var list<mixed> The calculated execution plan */
    private array $executionPlan;
    /** @var VersionConstraint|null Parsed WP version constraint */
    private ?VersionConstraint $wordPressVersionConstraint;
    /** @var VersionConstraint|null Parsed PHP version constraint */
    private ?VersionConstraint $phpVersionConstraint;
    /** @var BlueprintMetadata Blueprint metadata */
    private BlueprintMetadata $metadata;
	/** @var array<string, DataReference> Data references */
	private array $dataReferences;

    /**
     * Private constructor to enforce creation via the static `create` method.
     *
     * @param array $validated_array
     * @param list<mixed> $this->executionPlan
     * @param VersionConstraint|null $wordPressVersionConstraint
     * @param VersionConstraint|null $phpVersionConstraint
     * @param BlueprintMetadata $metadata
     */
    private function __construct(
        array $validated_blueprint_array
    ) {
		$this->validated_array = $validated_blueprint_array;
		$this->initialize();
    }

    public static function fromJsonString(string $jsonString): self
    {
        try {
            $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonException('Invalid Blueprint JSON provided: ' . $e->getMessage(), $e->getCode(), $e);
        }

        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
		$validator = new BlueprintV2Validator();
		if (!$validator->validate($data)) {
			// @TODO: Propagate validation errors
			print_r($validator->get_errors());
			throw new InvalidArgumentException('Invalid Blueprint JSON provided: ' . $validator->get_errors());
		}
        return self::fromValidatedArray($data);
    }

    /**
     * Creates a Blueprint instance from a validated JSON string.
     *
     * Parses the JSON, extracts metadata and constraints, and generates
     * the execution plan by converting declarative properties and appending
     * imperative steps.
     *
     * @param array $data A validated Blueprint JSON string.
     * @return self A new Blueprint instance.
     * @throws JsonException If the JSON string is invalid.
     * @throws InvalidArgumentException If the JSON structure is invalid for creating steps.
     */
    public static function fromValidatedArray(array $validated_array): self
    {
        return new self( $validated_array );
    }

	private function initialize(): void {
		$validated_array = $this->validated_array;
        // --- Process Declarative Properties into Steps (in order) ---

        // 1. constants
        if ( !empty($validated_array['constants']) && is_array($validated_array['constants'])) {
            $this->executionPlan[] = $this->createStepObject('defineConstants', ['constants' => $validated_array['constants']]);
        }

        // 2. siteOptions
        if ( !empty($validated_array['siteOptions']) && is_array($validated_array['siteOptions'])) {
            // Ensure siteUrl is not included as per schema Omit<>
            unset($validated_array['siteOptions']['siteUrl']);
            if (!empty($validated_array['siteOptions'])) {
                $this->executionPlan[] = $this->createStepObject('setSiteOptions', ['options' => $validated_array['siteOptions']]);
            }
        }

        // 3. muPlugins - Install via writeFiles step
        if ( !empty($validated_array['muPlugins']) && is_array($validated_array['muPlugins'])) {
            $files = [];
            foreach ($validated_array['muPlugins'] as $pluginPath => $pluginContent) {
                if (is_string($pluginPath) && is_string($pluginContent)) {
                    $files['/wp-content/mu-plugins/' . $pluginPath] = $pluginContent;
                } elseif (is_string($pluginContent)) {
                    // Handle numeric keys
                    $files['/wp-content/mu-plugins/' . basename($pluginContent)] = $pluginContent;
                }
            }
            if (!empty($files)) {
                $this->executionPlan[] = $this->createStepObject('writeFiles', ['files' => $files]);
            }
        }

        // 4. themes (install non-active)
        if ( !empty($validated_array['themes']) && is_array($validated_array['themes'])) {
            foreach ($validated_array['themes'] as $themeRef) {
                if (is_string($themeRef)) {
                    $this->executionPlan[] = $this->createStepObject('installTheme', [
                        'source' => $themeRef,
                        'activate' => false,
                        'importStarterContent' => false,
                    ]);
                } elseif (is_array($themeRef) && isset($themeRef['source']) && is_string($themeRef['source'])) {
                    // Pass through the raw definition for extensibility.
                    $this->executionPlan[] = $this->createStepObject('installTheme', [
                        'source' => $themeRef['source'],
                        'activate' => $themeRef['activate'] ?? false,
                        'importStarterContent' => $themeRef['importStarterContent'] ?? false,
                        'targetFolderName' => $themeRef['targetFolderName'] ?? null,
                    ]);
                } else {
                    throw new InvalidArgumentException('Invalid theme reference format in "themes" array.');
                }
            }
        }

        // 5. activeTheme (install and activate)
        if (isset($validated_array['activeTheme'])) {
            $themeRef = $validated_array['activeTheme'];
            if (is_string($themeRef)) {
                $this->executionPlan[] = $this->createStepObject('installTheme', [
                    'source' => $themeRef,
                    'activate' => true,
                    'importStarterContent' => false,
                ]);
            } elseif (is_array($themeRef) && isset($themeRef['source']) && is_string($themeRef['source'])) {
                $this->executionPlan[] = $this->createStepObject('installTheme', [
                    'source' => $themeRef['source'],
                    'activate' => true,
                    'importStarterContent' => $themeRef['importStarterContent'] ?? false,
                    'targetFolderName' => $themeRef['targetFolderName'] ?? null,
                ]);
            } else {
                throw new InvalidArgumentException('Invalid theme reference format for "activeTheme".');
            }
        }

        // 6. plugins
        if ( !empty($validated_array['plugins']) && is_array($validated_array['plugins'])) {
            foreach ($validated_array['plugins'] as $pluginDef) {
                $this->executionPlan[] = $this->createStepObject('installPlugin', ['plugin' => $pluginDef]);
            }
        }

        // 7. fonts – not directly supported; use RunPHP placeholders.
        if ( !empty($validated_array['fonts']) && is_array($validated_array['fonts'])) {
			throw new InvalidArgumentException('Fonts are not supported yet.');
            $code = '// TODO: Install fonts declared in Blueprint. Fonts JSON: ' . var_export($validated_array['fonts'], true) . ';';
            $this->executionPlan[] = $this->createStepObject('runPHP', ['code' => $code]);
        }

        // 8. media – use RunPHP placeholders.
        if ( !empty($validated_array['media']) && is_array($validated_array['media'])) {
			throw new InvalidArgumentException('Media is not supported yet.');
            $code = '// TODO: Import media declared in Blueprint. Media JSON: ' . var_export($validated_array['media'], true) . ';';
            $this->executionPlan[] = $this->createStepObject('runPHP', ['code' => $code]);
        }

        // 9. siteLanguage
        if ( !empty($validated_array['siteLanguage']) && is_string($validated_array['siteLanguage'])) {
            $this->executionPlan[] = $this->createStepObject('setSiteLanguage', ['language' => $validated_array['siteLanguage']]);
        }

        // 10. roles - create custom roles using WordPress role management
        if ( !empty($validated_array['roles']) && is_array($validated_array['roles'])) {
            $this->executionPlan[] = $this->createStepObject('createRoles', ['roles' => $validated_array['roles']]);
        }

        // 11. users - create users using WordPress user management
        if ( !empty($validated_array['users']) && is_array($validated_array['users'])) {
            $this->executionPlan[] = $this->createStepObject('createUsers', ['users' => $validated_array['users']]);
        }

        // 12. postTypes – generate one MU-plugin per post type, skipping those already registered.
        if ( !empty($validated_array['postTypes']) && is_array($validated_array['postTypes'])) {
            $this->executionPlan[] = $this->createStepObject('createPostTypes', ['postTypes' => $validated_array['postTypes']]);
        }

        // 13. content – Import inline posts via wp_insert_post().
        if ( !empty($validated_array['content']) && is_array($validated_array['content'])) {
            foreach ($validated_array['content'] as $contentEntry) {
                if (!isset($contentEntry['type'], $contentEntry['source'])) {
                    throw new InvalidArgumentException('Invalid content entry: missing "type" or "source" key.');
                }

                // Only handle 'posts' content type for now.
                if ('posts' !== $contentEntry['type']) {
                    throw new InvalidArgumentException(
                        sprintf('Unsupported content type: "%s". Only "posts" is currently supported.', $contentEntry['type'])
                    );
                }

                if (!is_array($contentEntry['source'])) {
                    throw new InvalidArgumentException('Invalid content source: must be an array.');
                }

                // Filter inline post definitions (arrays) – skip file paths/URLs (strings).
                $inlinePosts = array_values(
                    array_filter(
                        $contentEntry['source'],
                        static fn($item) => is_array($item)
                    )
                );

                if (!$inlinePosts) {
                    // Nothing inline to import – skip.
                    continue;
                }

                $this->executionPlan[] = $this->createStepObject('importPosts', ['posts' => $inlinePosts]);
            }
        }

        // 14. additionalStepsAfterExecution
        if ( !empty($validated_array['additionalStepsAfterExecution']) && is_array($validated_array['additionalStepsAfterExecution'])) {
            foreach ($validated_array['additionalStepsAfterExecution'] as $stepData) {
                $this->executionPlan[] = $this->createStepObject($stepData['step'], $stepData);
            }
        }

        // --- Extract Metadata and Constraints ---
        $this->wordPressVersionConstraint  = VersionConstraint::fromMixed(
	        $validated_array['wordpressVersion'] ?? null,
			false
		);
        $this->phpVersionConstraint = VersionConstraint::fromMixed(
	        $validated_array['phpVersion'] ?? null,
			true
		);
        $this->metadata      = BlueprintMetadata::fromArray( $validated_array['blueprintMeta'] ?? null);
	}

    /**
     * Helper method to create a specific step object from its type and data.
     *
     * @param string $stepType The 'step' identifier (e.g., 'installPlugin').
     * @param array $data The properties for the step.
     * @return mixed A Step object instance.
     * @throws InvalidArgumentException If the step type is unknown or data is invalid.
     */
    private function createStepObject(string $stepType, array $data): mixed
    {
        switch ($stepType) {
            case 'activatePlugin':
                return new ActivatePluginStep($data['pluginPath']);
            case 'activateTheme':
                return new ActivateThemeStep($data['themeFolderName']);
            case 'cp':
                return new CpStep($data['fromPath'], $data['toPath']);
            case 'defineConstants':
                return new DefineConstantsStep($data['constants']);
            case 'importThemeStarterContent':
                return new ImportThemeStarterContentStep($data['themeSlug'] ?? null);
            case 'installPlugin':
                $pluginDef = $data['plugin'];
                if (is_string($pluginDef)) {
                    return new InstallPluginStep($this->createDataReference($pluginDef, [
						WordPressOrgPlugin::class,
					]));
                } else {
					$source = $this->createDataReference($pluginDef['source'], [
						WordPressOrgPlugin::class,
					]);
                    $active = $pluginDef['active'] ?? true;
                    $options = $pluginDef['activationOptions'] ?? null;
                    $onError = isset($pluginDef['onError']) ? PluginErrorBehavior::from($pluginDef['onError']) : PluginErrorBehavior::THROW_ERROR;
                    return new InstallPluginStep($source, $active, $options, $onError);
                }
            case 'installTheme':
                $source = $this->createDataReference($data['source'], [
					WordPressOrgTheme::class,
				]);
                return new InstallThemeStep(
                    $source,
                    $data['activate'] ?? false,
                    $data['importStarterContent'] ?? false,
                    $data['targetFolderName'] ?? null
                );
            case 'mkdir':
                return new MkdirStep($data['path']);
            case 'mv':
                return new MvStep($data['fromPath'], $data['toPath']);
            case 'rm':
                return new RmStep($data['path']);
            case 'rmdir':
                return new RmDirStep($data['path']);
            case 'runPHP':
                $method = isset($data['method']) ? HttpMethod::from($data['method']) : HttpMethod::GET;
                return new RunPHPStep(
                    $data['code'] ?? null,
                    $data['scriptPath'] ?? null,
                    $data['relativeUri'] ?? null,
                    $method,
                    $data['protocol'] ?? null,
                    $data['headers'] ?? null,
                    $data['body'] ?? null,
                    $data['env'] ?? null,
                    $data['$_SERVER'] ?? null
                );
            case 'runSql':
                $source = $this->createDataReference($data['source']);
                return new RunSqlStep($source);
            case 'setSiteLanguage':
                return new SetSiteLanguageStep($data['language']);
            case 'setSiteOptions':
                return new SetSiteOptionsStep($data['options']);

            case 'createRoles':
                if (empty($data['roles']) || !is_array($data['roles'])) {
                    throw new InvalidArgumentException('Invalid roles data: must be a non-empty array.');
                }

                $code = '<?php
				require_once(DOCUMENT_ROOT . "/wp-load.php");
				$roles = getenv("ROLES");
                foreach ($roles as $role) {
                    if (empty($role["name"]) || !is_string($role["name"])) {
                        continue;
                    }
                    
                    $role_name = $role["name"];
                    $display_name = $role["display_name"] ?? ucfirst($role_name);
                    $capabilities = $role["capabilities"] ?? array();
                    
                    // Check if role already exists
                    if (!get_role($role_name)) {
                        // Create the role with basic read capability
                        add_role($role_name, $display_name, array("read" => true));
                    }
                    
                    // Get the role object
                    $role_object = get_role($role_name);
                    
                    // Add capabilities
                    if (!empty($capabilities) && is_array($capabilities)) {
                        foreach ($capabilities as $capability => $grant) {
                            $has_cap = filter_var($grant, FILTER_VALIDATE_BOOLEAN);
                            if ($has_cap) {
                                $role_object->add_cap($capability);
                            } else {
                                $role_object->remove_cap($capability);
                            }
                        }
                    }
                }
            ';
                return new RunPHPStep(
                    $code,
                    null,
                    null,
                    HttpMethod::GET,
                    null,
                    null,
                    null,
                    ['ROLES' => $data['roles']]
                );

            case 'createUsers':
                if (empty($data['users']) || !is_array($data['users'])) {
                    throw new InvalidArgumentException('Invalid users data: must be a non-empty array.');
                }

                $code = '<?php
                require_once(DOCUMENT_ROOT . "/wp-load.php");
                $users = getenv("USERS");
                foreach ($users as $user) {
                    if (empty($user["username"]) || !is_string($user["username"])) {
                        continue;
                    }
                    
                    $username = $user["username"];
                    $email = $user["email"] ?? $username . "@example.com";
                    $password = $user["password"] ?? wp_generate_password(12, true, true);
                    $role = $user["role"] ?? "subscriber";
                    
                    // Check if user already exists
                    $existing_user = get_user_by("login", $username);
                    if ($existing_user) {
                        continue; // Skip if user already exists
                    }
                    
                    // Create the user
                    $user_id = wp_create_user($username, $password, $email);
                    
                    if (!is_wp_error($user_id)) {
                        // Set role
                        $user_object = new WP_User($user_id);
                        $user_object->set_role($role);
                        
                        // Set user meta if provided
                        if (!empty($user["meta"]) && is_array($user["meta"])) {
                            foreach ($user["meta"] as $meta_key => $meta_value) {
                                update_user_meta($user_id, $meta_key, $meta_value);
                            }
                        }
                    }
                }
            ';
                return new RunPHPStep(
                    $code,
                    null,
                    null,
                    HttpMethod::GET,
                    null,
                    null,
                    null,
                    ['USERS' => $data['users']]
                );

            case 'createPostTypes':
                if (empty($data['postTypes']) || !is_array($data['postTypes'])) {
                    throw new InvalidArgumentException('Invalid postTypes data: must be a non-empty array.');
                }

                $files = [];
                foreach ($data['postTypes'] as $slug => $args) {
                    if (!is_string($slug) || $slug === '') {
                        continue;
                    }

                    // Ensure $args is an array.
                    if (!is_array($args)) {
                        $args = [];
                    }

                    // Build a safe file name for the MU-plugin.
                    $fileSlug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($slug));
                    $pluginPath = "wp-content/mu-plugins/blueprint-post-type-{$fileSlug}.php";

                    // Human-friendly default label.
                    $defaultLabel = addslashes(ucwords(str_replace(['-', '_'], ' ', $slug)));

                    // Compose the plugin source.
                    $pluginCode = sprintf(
                        <<<'PHP'
<?php
/**
 * Blueprint-generated Custom Post Type: %1$s
 * This file is auto-generated – do not edit directly.
 */

add_action(
    'init',
    static function () {
        // Skip if a plugin or theme already registered this post type.
        if (post_type_exists('%1$s')) {
            return;
        }

        $args = %2$s;

        // Fallback to an empty array if malformed.
        if (!is_array($args)) {
            $args = [];
        }

        $defaults = [
            'public'       => true,
            'show_in_rest' => true,
            'label'        => '%3$s',
        ];

        register_post_type('%1$s', array_merge($defaults, $args));
    },
    0
);
PHP,
                        $slug,
                        var_export($args, true),
                        $defaultLabel
                    );

                    $files[$pluginPath] = $pluginCode;
                }

                if (empty($files)) {
                    throw new InvalidArgumentException('No valid post types to register.');
                }

                return new WriteFilesStep($files);

            case 'importPosts':
                if (empty($data['posts']) || !is_array($data['posts'])) {
                    throw new InvalidArgumentException('Invalid posts data: must be a non-empty array.');
                }

                $inlinePosts = array_values(
                    array_filter(
                        $data['posts'],
                        static fn($item) => is_array($item)
                    )
                );

                if (empty($inlinePosts)) {
                    throw new InvalidArgumentException('No inline posts to import.');
                }

                $postsArray = var_export($inlinePosts, true);
                $code = <<<PHP
<?php
// Blueprint Content Import – inline posts.
\$__bp_posts = {$postsArray};

foreach (\$__bp_posts as \$__bp_post) {
    // Ensure minimum required fields.
    \$defaults = [
        'post_type'   => \$__bp_post['post_type']   ?? 'post',
        'post_status' => \$__bp_post['post_status'] ?? 'publish',
    ];
    \$postData = array_merge(\$defaults, \$__bp_post);

    // Insert the post. Errors are silently ignored to keep the import moving.
    wp_insert_post(wp_slash(\$postData));
}
unset(\$__bp_posts, \$__bp_post, \$postData);
PHP;
                return new RunPHPStep($code);

            case 'runPHP':
                $method = isset($data['method']) ? HttpMethod::from($data['method']) : HttpMethod::GET;
                return new RunPHPStep(
                    $data['code'] ?? null,
                    $data['scriptPath'] ?? null,
                    $data['relativeUri'] ?? null,
                    $method,
                    $data['protocol'] ?? null,
                    $data['headers'] ?? null,
                    $data['body'] ?? null,
                    $data['env'] ?? null,
                    $data['$_SERVER'] ?? null
                );
            case 'unzip':
                $zipFile = $this->createDataReference($data['zipFile']);
                return new UnzipStep($zipFile, $data['extractToPath']);
            case 'wp-cli':
                return new WPCLIStep($data['command'], $data['wpCliPath'] ?? null);
            case 'writeFiles':
                $files = [];
                foreach ($data['files'] as $path => $content) {
                    if (is_string($content)) {
                        $files[$path] = $content;
                    } else {
                        $files[$path] = $this->createDataReference($content);
                    }
                }
                return new WriteFilesStep($files);
            default:
                throw new InvalidArgumentException("Unknown step type: {$stepType}");
        }
    }

	private function createDataReference(mixed $data, array $additional_reference_classes = []): DataReference {
		$reference = $data instanceof DataReference ? $data : DataReference::create($data, $additional_reference_classes);
		$this->dataReferences[$reference->id] = $reference;
		return $reference;
	}

    // --- Getters ---

	public function getDataReferences(): array
	{
		return $this->dataReferences;
	}

    public function getvalidated_array(): array
    {
        return $this->validated_array;
    }

    /**
     * @return list<mixed>
     */
    public function getExecutionPlan(): array
    {
        return $this->executionPlan;
    }

    /**
     * @return VersionConstraint|null
     */
    public function getWordPressVersionConstraint(): ?VersionConstraint
    {
        return $this->wordPressVersionConstraint;
    }

    /**
     * @return VersionConstraint|null
     */
    public function getPhpVersionConstraint(): ?VersionConstraint
    {
        return $this->phpVersionConstraint;
    }

    /**
     * @return BlueprintMetadata
     */
    public function getMetadata(): BlueprintMetadata
    {
        return $this->metadata;
    }
}


/**
 * Represents the configuration for the Blueprint Runner.
 *
 * These settings are provided out-of-band (CLI flags, ENV variables, etc.)
 * and are never stored inside a Blueprint document.
 */
class RunnerConfiguration
{
	private array|string $rawBlueprint;

    /** @var string Either 'create-new-site' or 'apply-to-existing-site' */
    private string $executionMode;

    /** @var string File-system directory in which the Blueprint will be executed */
    private string $targetSiteRoot;

	/** @var string The URL of the target site */
	private string $targetSiteUrl;

	/** @var string Local path to source the Blueprint context from */
	private string $executionContextRoot;

    /** @var string Database engine to use when executing the Blueprint */
    private string $databaseEngine = 'mysql';

    /** @var array|null Connection parameters for the database */
    private ?array $databaseCredentials = null;

    /** @var bool Whether to override user passwords with randomly-generated ones */
    private bool $generatePasswords = false;

    /** @var string|false Path to save randomly-generated passwords */
    private $savePasswords = false;

    /** @var string Strategy for importing content objects */
    private string $contentImportMode;

	static public function create(): self
	{
		return new self();
	}

	public function setRawBlueprint(array|string $rawBlueprint): self
	{
		$this->rawBlueprint = $rawBlueprint;
		return $this;
	}

	public function getRawBlueprint(): array|string
	{
		return $this->rawBlueprint;
	}

	public function getTargetSiteUrl(): string
	{
		return $this->targetSiteUrl;
	}

	public function setTargetSiteUrl(string $targetSiteUrl): self
	{
		$this->targetSiteUrl = $targetSiteUrl;
		return $this;
	}

    /**
     * Sets the execution mode.
     *
     * @param string $executionMode Either 'create-new-site' or 'apply-to-existing-site'
     * @return self
     * @throws InvalidArgumentException If the execution mode is invalid
     */
    public function setExecutionMode(string $executionMode): self
    {
        if (!in_array($executionMode, ['create-new-site', 'apply-to-existing-site'])) {
            throw new InvalidArgumentException("Invalid execution mode: {$executionMode}");
        }

        $this->executionMode = $executionMode;
        return $this;
    }

    /**
     * Sets the target site reference.
     *
     * @param string $targetSiteRef File-system directory for Blueprint execution
     * @return self
     */
    public function setTargetSiteRoot(string $targetSiteRoot): self
    {
        $this->targetSiteRoot = $targetSiteRoot;
        return $this;
    }

	public function getTargetSiteRoot(): string
	{
		return $this->targetSiteRoot;
	}

    /**
     * Sets the database engine.
     *
     * @param string $databaseEngine Database engine to use ('mysql' or 'sqlite')
     * @return self
     * @throws InvalidArgumentException If the database engine is invalid
     */
    public function setDatabaseEngine(string $databaseEngine): self
    {
        if (!in_array($databaseEngine, ['mysql', 'sqlite'])) {
            throw new InvalidArgumentException("Invalid database engine: {$databaseEngine}");
        }

        $this->databaseEngine = $databaseEngine;
        return $this;
    }

    /**
     * Sets the database credentials.
     *
     * @param array $databaseCredentials Connection parameters for the database
     * @return self
     */
    public function setDatabaseCredentials(array $databaseCredentials): self
    {
        $this->databaseCredentials = $databaseCredentials;
        return $this;
    }

    /**
     * Sets whether to generate passwords.
     *
     * @param bool $generatePasswords Whether to override user passwords
     * @return self
     */
    public function setGeneratePasswords(bool $generatePasswords): self
    {
        $this->generatePasswords = $generatePasswords;
        return $this;
    }

    /**
     * Sets the path to save passwords.
     *
     * @param string|false $savePasswords Path to save generated passwords
     * @return self
     */
    public function setSavePasswords($savePasswords): self
    {
        $this->savePasswords = $savePasswords;
        return $this;
    }

	public function setExecutionContextRoot(string $executionContextRoot): self
	{
		$this->executionContextRoot = $executionContextRoot;
		return $this;
	}

    /**
     * Sets the content import mode.
     *
     * @param string $contentImportMode Strategy for importing content ('append', 'replace-all', or 'merge')
     * @return self
     * @throws InvalidArgumentException If the content import mode is invalid
     */
    public function setContentImportMode(string $contentImportMode): self
    {
        if (!in_array($contentImportMode, ['append', 'replace-all', 'merge'])) {
            throw new InvalidArgumentException("Invalid content import mode: {$contentImportMode}");
        }

        $this->contentImportMode = $contentImportMode;
        return $this;
    }

    /**
     * Validates the configuration.
     *
     * @return bool
     * @throws InvalidArgumentException If the configuration is invalid
     */
    public function validate(): bool
    {
        if (!isset($this->executionMode)) {
            throw new InvalidArgumentException('Execution mode must be set');
        }

        if (!isset($this->targetSiteRef)) {
            throw new InvalidArgumentException('Target site reference must be set');
        }

        if (!isset($this->contentImportMode)) {
            throw new InvalidArgumentException('Content import mode must be set');
        }

        if ($this->databaseEngine === 'mysql' && $this->executionMode === 'create-new-site' && empty($this->databaseCredentials)) {
            throw new InvalidArgumentException('MySQL credentials are required for create-new-site mode');
        }

        return true;
    }

    /**
     * Gets the execution mode.
     *
     * @return string
     */
    public function getExecutionMode(): string
    {
        return $this->executionMode;
    }

    /**
     * Gets the target site reference.
     *
     * @return string
     */
    public function getExecutionContextRoot(): string
    {
        return $this->executionContextRoot;
    }

    /**
     * Gets the database engine.
     *
     * @return string
     */
    public function getDatabaseEngine(): string
    {
        return $this->databaseEngine;
    }

    /**
     * Gets the database credentials.
     *
     * @return array|null
     */
    public function getDatabaseCredentials(): ?array
    {
        return $this->databaseCredentials;
    }

    /**
     * Checks if passwords should be generated.
     *
     * @return bool
     */
    public function shouldGeneratePasswords(): bool
    {
        return $this->generatePasswords;
    }

    /**
     * Gets the path to save passwords.
     *
     * @return string|false
     */
    public function getSavePasswordsPath()
    {
        return $this->savePasswords;
    }

    /**
     * Gets the content import mode.
     *
     * @return string
     */
    public function getContentImportMode(): string
    {
        return $this->contentImportMode;
    }
}

/**
 * Progress logging handler that listens to Tracker progress events
 */
class ProgressLogger {
    /**
     * @var callable
     */
    private $logCallback;

    /**
     * Create a new progress logger with the given logging function
     *
     * @param callable $logCallback Function that receives progress updates
     */
    public function __construct(callable $logCallback) {
        $this->logCallback = $logCallback;
    }

    /**
     * Attach this logger to a Tracker instance
     *
     * @param Tracker $tracker The tracker to log progress for
     */
    public function attachTo(Tracker $tracker) {
        $tracker->events->addListener(
            ProgressEvent::class,
            function (ProgressEvent $event) {
                call_user_func($this->logCallback, $event->getProgress(), $event->getCaption());
            }
        );

        $tracker->events->addListener(
            DoneEvent::class,
            function () {
                call_user_func($this->logCallback, 100, 'Complete');
            }
        );
    }
}

class BlueprintRunner {
    private ?Blueprint $blueprint;
    private Runtime $runtime;
    private Tracker $mainTracker;
    private RunnerConfiguration $runnerConfiguration;
    private ProgressLogger $progressLogger;

    /**
     * Create a new BlueprintRunner with the given configuration
     *
     * @param RunnerConfiguration $runnerConfiguration Configuration for the runner
     * @param callable|null $progressCallback Optional callback for progress updates
     */
    public function __construct(RunnerConfiguration $runnerConfiguration, ?callable $progressCallback = null) {
        $this->runnerConfiguration = $runnerConfiguration;
        $this->mainTracker = new Tracker();

		// Default progress handler logs to stderr
        $this->progressLogger = new ProgressLogger(
            $progressCallback ?? function($progress, $caption) {
                fprintf(STDERR, "[%3d%%] %s\n", $progress, $caption);
            }
        );
        $this->progressLogger->attachTo($this->mainTracker);
    }

    public function run() {
        // Create all top-level progress stages upfront so the tracker knows what %
		// of the total work is being done with every progress update.
		//
		// The stage weights are arbitrary and can be tweaked as needed.
		// They have to add up to 1.
        $dataResolutionStage = $this->mainTracker->stage(0.25, 'Resolving data references');
        $blueprintStage = $this->mainTracker->stage(0.05, 'Resolving Blueprint');
        $siteStage = $this->mainTracker->stage(0.2, 'Setting up WordPress site');
        $executionStage = $this->mainTracker->stage(0.5, 'Executing Blueprint steps');

        $blueprintStage->setCaption('Loading Blueprint data');
		// Resolve the blueprint.
		// @TODO: Support more data sources.
		// @TODO: Rename Blueprint class to ExecutionPlan. Separate it from
		//        the execution target resolution.
        $rawBlueprint = $this->runnerConfiguration->getRawBlueprint();
        if(is_string($rawBlueprint)) {
            $this->blueprint = Blueprint::fromJsonString($rawBlueprint);
        } else if(is_array($rawBlueprint)) {
            $this->blueprint = Blueprint::fromArray($rawBlueprint);
        } else {
            throw new RuntimeException('Invalid blueprint source');
        }
        $blueprintStage->finish();

		// @TODO: Resolve just like any other resource (but only if we're creating a new site)
		if (!isset($options['wordPressZip'])) {
			$wordPressZip = DataReference::create('https://wordpress.org/latest.zip');
		} else if(is_string($options['wordPressZip'])) {
			$wordPressZip = DataReference::create($options['wordPressZip']);
		} else {
			throw new \InvalidArgumentException('The wordPressZip option must be a DataReference but was ' . gettype($options['wordPressZip']));
		}

		// @TODO: Resolve just like any other resource (but only if we're creating a new site)
		if(!isset($options['sqliteIntegrationPluginZip'])) {
			$sqliteIntegrationPluginZip = DataReference::create('https://downloads.wordpress.org/plugin/sqlite-database-integration.zip');
		} else if(is_string($options['sqliteIntegrationPluginZip'])) {
			$sqliteIntegrationPluginZip = DataReference::create($options['sqliteIntegrationPluginZip']);
		} else {
			throw new \InvalidArgumentException('The sqliteIntegrationPluginZip option must be a DataReference but was ' . gettype($options['sqliteIntegrationPluginZip']));
		}

		$data_references = [
			...$this->blueprint->getDataReferences(),
			$wordPressZip,
			$sqliteIntegrationPluginZip,
		];

        $this->runtime = new Runtime(
            documentRoot: $this->runnerConfiguration->getTargetSiteRoot(),
			executionContext: LocalFilesystem::create(
				$this->runnerConfiguration->getExecutionContextRoot()
			),
			dataReferences: $data_references,
			dataResolutionTracker: $dataResolutionStage,
        );
		$this->runtime->startResolvingAllDataReferences();

        $siteStage->setCaption('Booting WordPress environment');
		// Resolve the execution target.
		// @TODO: Support existing sites.
		echo "Booting WordPress environment\n";
        WordPressBootManager::boot( [
			'runtime' => $this->runtime,
			'siteUrl' => $this->runnerConfiguration->getTargetSiteUrl(),
			'wordPressZip' => $wordPressZip,
			'sqliteIntegrationPluginZip' => $sqliteIntegrationPluginZip,
		] );
        $siteStage->finish();

        $this->runSteps($executionStage);
    }

	/**
	 * Resolves a specific WordPress release URL and version string based on
	 * a version query string such as "latest", "beta", or "6.6".
	 *
	 * Examples:
	 * ```php
	 * $result = resolveWordPressRelease('latest');
	 * // becomes ['releaseUrl' => 'https://wordpress.org/wordpress-6.6.2.zip', 'version' => '6.6.2']
	 *
	 * $result = resolveWordPressRelease('beta');
	 * // becomes ['releaseUrl' => 'https://wordpress.org/wordpress-6.6.2-RC1.zip', 'version' => '6.6.2-RC1']
	 *
	 * $result = resolveWordPressRelease('6.6');
	 * // becomes ['releaseUrl' => 'https://wordpress.org/wordpress-6.6.2.zip', 'version' => '6.6.2']
	 * ```
	 *
	 * @param string $versionQuery The WordPress version query string to resolve.
	 * @return array The resolved WordPress release URL and version string.
	 */
	static public function resolveWordPressRelease($versionQuery = 'latest')
	{
		if (
			str_starts_with($versionQuery, 'https://') ||
			str_starts_with($versionQuery, 'http://')
		) {
			$sha1 = substr(sha1($versionQuery), 0, 8);
			return [
				'releaseUrl' => $versionQuery,
				'version' => 'custom-' . $sha1,
				'source' => 'inferred',
			];
		} else if ($versionQuery === 'trunk' || $versionQuery === 'nightly') {
			return [
				'releaseUrl' => 'https://wordpress.org/nightly-builds/wordpress-latest.zip',
				'version' => 'nightly-' . date('Y-m-d'),
				'source' => 'inferred',
			];
		}

		$response = file_get_contents('https://api.wordpress.org/core/version-check/1.7/?channel=beta');
		$latestVersions = json_decode($response, true);

		$latestVersions = array_filter($latestVersions['offers'], function($v) {
			return $v['response'] === 'autoupdate';
		});

		foreach ($latestVersions as $apiVersion) {
			if ($versionQuery === 'beta' && strpos($apiVersion['version'], 'beta') !== false) {
				return [
					'releaseUrl' => $apiVersion['download'],
					'version' => $apiVersion['version'],
					'source' => 'api',
				];
			} else if (
				$versionQuery === 'latest' &&
				strpos($apiVersion['version'], 'beta') === false
			) {
				// The first non-beta item in the list is the latest version.
				return [
					'releaseUrl' => $apiVersion['download'],
					'version' => $apiVersion['version'],
					'source' => 'api',
				];
			} else if (
				substr($apiVersion['version'], 0, strlen($versionQuery)) ===
				$versionQuery
			) {
				return [
					'releaseUrl' => $apiVersion['download'],
					'version' => $apiVersion['version'],
					'source' => 'api',
				];
			}
		}

		return [
			'releaseUrl' => "https://wordpress.org/wordpress-{$versionQuery}.zip",
			'version' => $versionQuery,
			'source' => 'inferred',
		];
	}
	
    /**
     * Run the steps in the execution plan with progress tracking
     *
     * @param Tracker $parentTracker The parent tracker for step execution
     * @return array Results from each step execution
     */
    private function runSteps(Tracker $parentTracker) {
        $results = [];
        $steps = $this->blueprint->getExecutionPlan();
        $stepCount = count($steps);

        if ($stepCount === 0) {
            $parentTracker->finish();
            return $results;
        }

        // Create sub-trackers for each step with equal weight
        $stepWeight = 1.0 / $stepCount;
        $stepTrackers = [];

        // Create all step trackers upfront for accurate progress calculation
        for ($i = 0; $i < $stepCount; $i++) {
            $step = $steps[$i];
            $stepNumber = $i + 1;
            $stepCaption = $this->getStepCaption($step);
            $stepTrackers[$i] = $parentTracker->stage(
                $stepWeight,
                sprintf("Step %d/%d: %s", $stepNumber, $stepCount, $stepCaption)
            );
        }

        // Execute each step
        for ($i = 0; $i < $stepCount; $i++) {
            $step = $steps[$i];
            $stepTracker = $stepTrackers[$i];

            try {
                $runner = $this->createStepRunner($step);
                $results[$i] = $runner->run($step, $this->runtime, $stepTracker);

                // If step didn't call finish(), do it for them
                if (!$stepTracker->isDone()) {
                    $stepTracker->finish();
                }
            } catch (Exception $e) {
                $results[$i] = $e;
                $stepTracker->setCaption(sprintf("%s (FAILED: %s)",
                    $stepTracker->getCaption(),
                    $e->getMessage()
                ));

                // Mark as done but not 100% to indicate error
                $stepTracker->set(99.9);
                $stepTracker->finish();

                // Determine if we should continue or stop execution
                $continueOnError = $step->continueOnError ?? false;
                if (!$continueOnError) {
                    throw new RuntimeException(
                        sprintf("Error when executing step %s (number %d in the plan)",
                            get_class($step),
                            $i + 1
                        ),
                        0,
                        $e
                    );
                }
            }
        }

        return $results;
    }

    private function getStepCaption($step): string {
        $stepClass = get_class($step);
        return substr($stepClass, strrpos($stepClass, '\\') + 1);
    }

    private function createStepRunner($step) {
        if ($step instanceof ActivatePluginStep) {
            return new ActivatePluginStepRunner();
        }
        if ($step instanceof ActivateThemeStep) {
            return new ActivateThemeStepRunner();
        }
        if ($step instanceof CpStep) {
            return new CpStepRunner();
        }
        if ($step instanceof DefineConstantsStep) {
            return new DefineConstantsStepRunner();
        }
        if ($step instanceof InstallPluginStep) {
            return new InstallPluginStepRunner();
        }
        if ($step instanceof InstallThemeStep) {
            return new InstallThemeStepRunner();
        }
        if ($step instanceof MkdirStep) {
            return new MkdirStepRunner();
        }
        if ($step instanceof MvStep) {
            return new MvStepRunner();
        }
        if ($step instanceof RmStep) {
            return new RmStepRunner();
        }
        if ($step instanceof RmDirStep) {
            return new RmDirStepRunner();
        }
        if ($step instanceof RunPHPStep) {
            return new RunPHPStepRunner();
        }
        if ($step instanceof RunSqlStep) {
            return new RunSQLStepRunner();
        }
        if ($step instanceof SetSiteOptionsStep) {
            return new SetSiteOptionsStepRunner();
        }
        if ($step instanceof UnzipStep) {
            return new UnzipStepRunner();
        }
        if ($step instanceof WPCLIStep) {
            return new WPCLIStepRunner();
        }
        if ($step instanceof WriteFilesStep) {
            return new WriteFilesStepRunner();
        }

        throw new \InvalidArgumentException('Unknown step type: ' . get_class($step));
    }
}

// $complex_blueprint = Blueprint::fromValidatedArray(json_decode(<<<'JSON'
// {
//   "version": 2,
//   "$schema": "https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/schema.json",
//   "blueprintMeta": {
//     "name": "Full Featured Blueprint",
//     "description": "A blueprint demonstrating most of the available features",
//     "version": "1.0.0",
//     "authors": ["Test Author", "Another Author"],
//     "authorUrl": "https://example.com",
//     "donateLink": "https://example.com/donate",
//     "tags": ["test", "full-features", "demo"],
//     "license": "GPL-2.0"
//   },
//   "siteLanguage": "en_US",
//   "siteOptions": {
//     "blogname": "Full Featured Test Site",
//     "timezone_string": "America/New_York",
//     "permalink_structure": "/%postname%/"
//   },
//   "constants": {
//     "WP_DEBUG": true,
//     "WP_DEBUG_LOG": true,
//     "WP_DEBUG_DISPLAY": false,
//     "SCRIPT_DEBUG": true,
//     "CUSTOM_CONSTANT": "custom-value"
//   },
//   "wordpressVersion": "6.4",
//   "phpVersion": "8.0",
//   "activeTheme": "twentytwentythree",
//   "themes": [
//     "twentytwentyfour"
//   ],
//   "plugins": [
//     "akismet",
//     {
//       "source": "woocommerce",
//       "active": true,
//       "activationOptions": {
//         "option1": "value1"
//       }
//     }
//   ],
//   "postTypes": {
//     "book": {
//       "label": "Books",
//       "description": "Books post type",
//       "public": true,
//       "has_archive": true,
//       "show_in_rest": true,
//       "supports": ["title", "editor", "author", "thumbnail", "excerpt", "comments"]
//     }
//   },
//   "content": [
//     {
//       "type": "posts",
//       "source": [
//         {
//           "post_title": "Sample Post",
//           "post_content": "This is a sample post content.",
//           "post_status": "publish",
//           "post_type": "post"
//         },
//         {
//           "post_title": "Sample Page",
//           "post_content": "This is a sample page content.",
//           "post_status": "publish",
//           "post_type": "page"
//         }
//       ]
//     }
//   ],
//   "users": [
//     {
//       "username": "editor",
//       "email": "editor@example.com",
//       "role": "editor",
//       "meta": {
//         "first_name": "Test",
//         "last_name": "Editor"
//       }
//     }
//   ],
//   "roles": [
//     {
//       "name": "book_editor",
//       "capabilities": {
//         "read": "true",
//         "edit_books": "true",
//         "publish_books": "true"
//       }
//     }
//   ],
//   "additionalStepsAfterExecution": [
//     {
//       "step": "writeFiles",
//       "files": [
//         {
//           "path": "wp-content/uploads/custom-file.txt",
//           "content": "This is a custom file created by the Blueprint."
//         }
//       ]
//     },
//     {
//       "step": "runPHP",
//       "code": "echo 'Blueprint execution completed!';"
//     }
//   ]
// }
// JSON, true, 512, JSON_THROW_ON_ERROR));

$simple_blueprint = <<<'JSON'
{
  "version": 2,
  "$schema": "https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/schema.json",
  "plugins": [
    "friends"
  ],
  "blueprintMeta": {
    "name": "Full Featured Blueprint",
    "description": "A blueprint demonstrating most of the available features",
    "version": "1.0.0",
    "authors": ["Test Author", "Another Author"],
    "authorUrl": "https://example.com",
    "donateLink": "https://example.com/donate",
    "tags": ["test", "full-features", "demo"],
    "license": "GPL-2.0"
  },
  "postTypes": {
    "book": {
      "label": "Books",
      "description": "Books post type",
      "public": true,
      "has_archive": true,
      "show_in_rest": true,
      "supports": ["title", "editor", "author", "thumbnail", "excerpt", "comments"]
    }
  },
  "additionalStepsAfterExecution": [
    {
      "step": "writeFiles",
      "files": {
        "wp-content/uploads/custom-file.txt": {
            "filename": "custom-file.txt",
            "content": "This is a custom file created by the Blueprint."
        },
        "builder.php": "./test_file.txt"
      }
    }
  ]
} 
JSON;

// Update the test run at the bottom to use the progress handler
$runnerConfiguration = RunnerConfiguration::create()
    ->setRawBlueprint($simple_blueprint)
    ->setTargetSiteRoot(__DIR__ . '/test_blueprint_runner')
    ->setTargetSiteUrl('http://127.0.0.1:9850')
    ->setExecutionContextRoot(__DIR__);

// Create a BlueprintRunner with a custom progress logger that writes to STDOUT
$runner = new BlueprintRunner($runnerConfiguration, function($progress, $caption) {
    printf("[%3d%%] %s\n", $progress, $caption);
});
$runner->run();
