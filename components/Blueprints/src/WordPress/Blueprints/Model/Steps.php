<?php

namespace WordPress\Blueprints\Model;

use Exception;
use InvalidArgumentException; // Standard PHP exception
use JsonException; // Standard PHP exception
use RuntimeException;
use WordPress\Blueprints\BlueprintV2Validator;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Resources\Model\DataReference;
use WordPress\Blueprints\Resources\Model\Directory;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\pipe_stream;

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
     * Plugin source identifier (slug, slug@version, URL, ./path, /path).
     */
    private string $source;

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
     * @param string $source            Plugin source identifier.
     * @param bool $active              Activate after install?
     * @param array<string, mixed>|null $activationOptions Optional activation data.
     * @param PluginErrorBehavior $onError           Error handling behavior.
     */
    public function __construct(
        string $source,
        bool $active = true,
        ?array $activationOptions = null,
        PluginErrorBehavior $onError = PluginErrorBehavior::THROW_ERROR
    ) {
        $this->source = $source;
        $this->active = $active;
        $this->activationOptions = $activationOptions;
        $this->onError = $onError;
    }

    public function getSource(): string {
        return $this->source;
    }

    public function setSource(string $source): void {
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
		$tracker->setCaption('Installing plugin ' . $step->getSource());

		$fs = $runtime->getTargetFilesystem();
		FilesystemHelpers::withTemporaryDirectory($fs, function($temp_dir) use ($fs, $runtime, $step, $tracker) {
			// Create data reference for the plugin source
			$source = $step->getSource();
			$dataRef = DataReference::create($source);

			$plugin_data = $runtime->resolveDataReference($dataRef);

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

			$tracker->set(50, 'Installing plugin ' . $step->getSource());

			$runtime->evalPhpInSubProcess(
				file_get_contents(__DIR__ . '/InstallPlugin/wp_install_plugin.php'),
				['PLUGIN_ZIP_PATH' => $zip_path, 'OUTPUT_FILE' => $temp_dir . '/plugin_path.txt']
			);

			$relative_path = $fs->get_contents($temp_dir . '/plugin_path.txt');

			if ($step->isActive()) {
				$tracker->set(75, 'Activating plugin ' . $step->getSource());
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
    private string $source;

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
     * @param string      $source              Theme source identifier.
     * @param bool        $activate            Activate after install?
     * @param bool        $importStarterContent Import starter content?
     * @param string|null $targetFolderName    Optional target folder name.
     */
    public function __construct(
        string $source,
        bool $activate = false,
        bool $importStarterContent = false,
        ?string $targetFolderName = null
    ) {
        $this->source = $source;
        $this->activate = $activate;
        $this->importStarterContent = $importStarterContent;
        $this->targetFolderName = $targetFolderName;
    }

    public function getSource(): string {
        return $this->source;
    }

    public function setSource(string $source): void {
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
			$source = $step->getSource();
			$dataRef = DataReference::create($source);

			$theme_data = $runtime->resolveDataReference($dataRef);

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
 * Simplified: assumes DataReference is resolved to a file path or URL string.
 */
class RunSqlStep {
    /**
     * Path or URL to the SQL file.
     */
    private string $source;

    /**
     * @param string $source Path or URL to the SQL file.
     */
    public function __construct(string $source) {
        $this->source = $source;
    }

    public function getSource(): string {
        return $this->source;
    }

    public function setSource(string $source): void {
        $this->source = $source;
    }
}

class RunSQLStepRunner implements StepRunnerInterface {
	public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
		$tracker->setCaption('Running SQL queries');

		// Create data reference for the SQL file
		$source = $step->getSource();
		$dataRef = DataReference::create($source);

		$sql = $runtime->resolveDataReference($dataRef);

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
 * Simplified: assumes zipFile reference is resolved to a file path or URL string.
 */
class UnzipStep {
    /**
     * Path or URL to the zip file.
     */
    private string $zipFile;

    /**
     * The path to extract the contents to.
     */
    private string $extractToPath;

    /**
     * @param string $zipFile       Path or URL to the zip file.
     * @param string $extractToPath Destination path for extraction.
     */
    public function __construct(string $zipFile, string $extractToPath) {
        $this->zipFile = $zipFile;
        $this->extractToPath = $extractToPath;
    }

    public function getZipFile(): string {
        return $this->zipFile;
    }

    public function setZipFile(string $zipFile): void {
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

		// Create data reference for the zip file
		$zipFile = $step->getZipFile();
		$dataRef = DataReference::create($zipFile);

		$zip_stream = $runtime->resolveDataReference($dataRef);

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
 * Simplified: assumes DataReference values are resolved to actual string content.
 */
class WriteFilesStep {
    /**
     * An associative array where keys are file paths and values are their string contents.
     * @var array<string, string>
     */
    private array $files;

    /**
     * @param array<string, string> $files Files to write (path => content).
     */
    public function __construct(array $files) {
        $this->files = $files;
    }

    /**
     * @return array<string, string>
     */
    public function getFiles(): array {
        return $this->files;
    }

    /**
     * @param array<string, string> $files
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
			} else if(is_array($data)) {
				$data_ref = $data['source'] instanceof DataReference ?
					$data['source'] :
					DataReference::create($data['source']);
				$data_stream = $runtime->resolveDataReference($data_ref);
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
    private array $originalData;
    /** @var list<mixed> The calculated execution plan */
    private array $executionPlan;
    /** @var VersionConstraint|null Parsed WP version constraint */
    private ?VersionConstraint $wordPressVersionConstraint;
    /** @var VersionConstraint|null Parsed PHP version constraint */
    private ?VersionConstraint $phpVersionConstraint;
    /** @var BlueprintMetadata Blueprint metadata */
    private BlueprintMetadata $metadata;

    /**
     * Private constructor to enforce creation via the static `create` method.
     *
     * @param array $originalData
     * @param list<mixed> $executionPlan
     * @param VersionConstraint|null $wordPressVersionConstraint
     * @param VersionConstraint|null $phpVersionConstraint
     * @param BlueprintMetadata $metadata
     */
    private function __construct(
        array $originalData,
        array $executionPlan,
        ?VersionConstraint $wordPressVersionConstraint,
        ?VersionConstraint $phpVersionConstraint,
        BlueprintMetadata $metadata
    ) {
        $this->originalData = $originalData;
        $this->executionPlan = $executionPlan;
        $this->wordPressVersionConstraint = $wordPressVersionConstraint;
        $this->phpVersionConstraint = $phpVersionConstraint;
        $this->metadata = $metadata;
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
    public static function fromValidatedArray(array $data): self
    {
        $executionPlan = [];

        // --- Process Declarative Properties into Steps (in order) ---

        // 1. constants
        if (!empty($data['constants']) && is_array($data['constants'])) {
            $executionPlan[] = Blueprint::createStepObject('defineConstants', ['constants' => $data['constants']]);
        }

        // 2. siteOptions
        if (!empty($data['siteOptions']) && is_array($data['siteOptions'])) {
            // Ensure siteUrl is not included as per schema Omit<>
            unset($data['siteOptions']['siteUrl']);
            if (!empty($data['siteOptions'])) {
                $executionPlan[] = Blueprint::createStepObject('setSiteOptions', ['options' => $data['siteOptions']]);
            }
        }

        // 3. muPlugins - Install via writeFiles step
        if (!empty($data['muPlugins']) && is_array($data['muPlugins'])) {
            $files = [];
            foreach ($data['muPlugins'] as $pluginPath => $pluginContent) {
                if (is_string($pluginPath) && is_string($pluginContent)) {
                    $files['/wp-content/mu-plugins/' . $pluginPath] = $pluginContent;
                } elseif (is_string($pluginContent)) {
                    // Handle numeric keys
                    $files['/wp-content/mu-plugins/' . basename($pluginContent)] = $pluginContent;
                }
            }
            if (!empty($files)) {
                $executionPlan[] = Blueprint::createStepObject('writeFiles', ['files' => $files]);
            }
        }

        // 4. themes (install non-active)
        if (!empty($data['themes']) && is_array($data['themes'])) {
            foreach ($data['themes'] as $themeRef) {
                if (is_string($themeRef)) {
                    $executionPlan[] = Blueprint::createStepObject('installTheme', [
                        'source' => $themeRef,
                        'activate' => false,
                        'importStarterContent' => false,
                    ]);
                } elseif (is_array($themeRef) && isset($themeRef['source']) && is_string($themeRef['source'])) {
                    // Pass through the raw definition for extensibility.
                    $executionPlan[] = Blueprint::createStepObject('installTheme', [
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
        if (isset($data['activeTheme'])) {
            $themeRef = $data['activeTheme'];
            if (is_string($themeRef)) {
                $executionPlan[] = Blueprint::createStepObject('installTheme', [
                    'source' => $themeRef,
                    'activate' => true,
                    'importStarterContent' => false,
                ]);
            } elseif (is_array($themeRef) && isset($themeRef['source']) && is_string($themeRef['source'])) {
                $executionPlan[] = Blueprint::createStepObject('installTheme', [
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
        if (!empty($data['plugins']) && is_array($data['plugins'])) {
            foreach ($data['plugins'] as $pluginDef) {
                $executionPlan[] = Blueprint::createStepObject('installPlugin', ['plugin' => $pluginDef]);
            }
        }

        // 7. fonts – not directly supported; use RunPHP placeholders.
        if (!empty($data['fonts']) && is_array($data['fonts'])) {
			throw new InvalidArgumentException('Fonts are not supported yet.');
            $code = '// TODO: Install fonts declared in Blueprint. Fonts JSON: ' . var_export($data['fonts'], true) . ';';
            $executionPlan[] = Blueprint::createStepObject('runPHP', ['code' => $code]);
        }

        // 8. media – use RunPHP placeholders.
        if (!empty($data['media']) && is_array($data['media'])) {
			throw new InvalidArgumentException('Media is not supported yet.');
            $code = '// TODO: Import media declared in Blueprint. Media JSON: ' . var_export($data['media'], true) . ';';
            $executionPlan[] = Blueprint::createStepObject('runPHP', ['code' => $code]);
        }

        // 9. siteLanguage
        if (!empty($data['siteLanguage']) && is_string($data['siteLanguage'])) {
            $executionPlan[] = Blueprint::createStepObject('setSiteLanguage', ['language' => $data['siteLanguage']]);
        }

        // 10. roles - create custom roles using WordPress role management
        if (!empty($data['roles']) && is_array($data['roles'])) {
            $executionPlan[] = Blueprint::createStepObject('createRoles', ['roles' => $data['roles']]);
        }

        // 11. users - create users using WordPress user management
        if (!empty($data['users']) && is_array($data['users'])) {
            $executionPlan[] = Blueprint::createStepObject('createUsers', ['users' => $data['users']]);
        }

        // 12. postTypes – generate one MU-plugin per post type, skipping those already registered.
        if (!empty($data['postTypes']) && is_array($data['postTypes'])) {
            $executionPlan[] = Blueprint::createStepObject('createPostTypes', ['postTypes' => $data['postTypes']]);
        }

        // 13. content – Import inline posts via wp_insert_post().
        if (!empty($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $contentEntry) {
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

                $executionPlan[] = Blueprint::createStepObject('importPosts', ['posts' => $inlinePosts]);
            }
        }

        // 14. additionalStepsAfterExecution
        if (!empty($data['additionalStepsAfterExecution']) && is_array($data['additionalStepsAfterExecution'])) {
            foreach ($data['additionalStepsAfterExecution'] as $stepData) {
                $executionPlan[] = Blueprint::createStepObject($stepData['step'], $stepData);
            }
        }

        // --- Extract Metadata and Constraints ---
        $wpConstraint  = $data['wordpressVersion'] ?? null;
        $phpConstraint = $data['phpVersion'] ?? null;
        $metadata      = $data['blueprintMeta'] ?? null;

        return new self(
            $data,
            $executionPlan,
            VersionConstraint::fromMixed($wpConstraint, false),
            VersionConstraint::fromMixed($phpConstraint, true),
            BlueprintMetadata::fromArray($metadata)
        );
    }

    /**
     * Helper method to create a specific step object from its type and data.
     *
     * @param string $stepType The 'step' identifier (e.g., 'installPlugin').
     * @param array $data The properties for the step.
     * @return mixed A Step object instance.
     * @throws InvalidArgumentException If the step type is unknown or data is invalid.
     */
    private static function createStepObject(string $stepType, array $data): mixed
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
                    return new InstallPluginStep($pluginDef);
                } else {
                    $source = $pluginDef['source'];
                    $active = $pluginDef['active'] ?? true;
                    $options = $pluginDef['activationOptions'] ?? null;
                    $onError = isset($pluginDef['onError']) ? PluginErrorBehavior::from($pluginDef['onError']) : PluginErrorBehavior::THROW_ERROR;
                    return new InstallPluginStep($source, $active, $options, $onError);
                }
            case 'installTheme':
                return new InstallThemeStep(
                    $data['source'],
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
                return new RunSqlStep($data['source']);
            case 'setSiteLanguage':
                return new SetSiteLanguageStep($data['language']);
            case 'setSiteOptions':
                return new SetSiteOptionsStep($data['options']);
            case 'unzip':
                return new UnzipStep($data['zipFile'], $data['extractToPath']);
            case 'wp-cli':
                return new WPCLIStep($data['command'], $data['wpCliPath'] ?? null);
            case 'writeFiles':
                return new WriteFilesStep($data['files']);
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
            case 'runSql':
                return new RunSqlStep($data['source']);
            case 'setSiteLanguage':
                return new SetSiteLanguageStep($data['language']);
            case 'setSiteOptions':
                return new SetSiteOptionsStep($data['options']);
            case 'unzip':
                return new UnzipStep($data['zipFile'], $data['extractToPath']);
            case 'wp-cli':
                return new WPCLIStep($data['command'], $data['wpCliPath'] ?? null);
            case 'writeFiles':
                return new WriteFilesStep($data['files']);
            default:
                throw new InvalidArgumentException("Unknown step type: {$stepType}");
        }
    }

    // --- Getters ---

    public function getOriginalData(): array
    {
        return $this->originalData;
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


class BlueprintRunner {
    private ?Blueprint $blueprint;
    private Runtime $runtime;
    private Tracker $mainTracker;
	private RunnerConfiguration $runnerConfiguration;

    public function __construct(RunnerConfiguration $runnerConfiguration) {
        $this->runnerConfiguration = $runnerConfiguration;
        $this->runtime = new Runtime(
			$runnerConfiguration->getTargetSiteRoot(),
			$runnerConfiguration->getExecutionContextRoot(),
		);
        $this->mainTracker = new Tracker();
    }

	public function run() {
		$this->resolveBlueprint();
		$this->runSteps();
    }

	/**
	 * @TODO: support sourcing Blueprints from arbitrary sources
	 */
	private function resolveBlueprint() {
		$rawBlueprint = $this->runnerConfiguration->getRawBlueprint();
		if(is_string($rawBlueprint)) {
			$this->blueprint = Blueprint::fromJsonString($rawBlueprint);
		} else if(is_array($rawBlueprint)) {
			$this->blueprint = Blueprint::fromArray($rawBlueprint);
		} else {
			throw new RuntimeException('Invalid blueprint source');
		}
	}

	private function runSteps() {
        $results = [];
		$steps = $this->blueprint->getExecutionPlan();
        $stepCount = count($steps);

        for ($i = 0; $i < $stepCount; $i++) {
            $step = $steps[$i];
            $stepWeight = 1 / $stepCount;
            $stepTracker = $this->mainTracker->stage($stepWeight, $this->getStepCaption($step));

            try {
                $runner = $this->createStepRunner($step);
                $results[$i] = $runner->run($step, $this->runtime, $stepTracker);
                $stepTracker->finish();
            } catch ( Exception $e) {
                $results[$i] = $e;
                $stepTracker->finish();

                // Determine if we should continue or stop execution
                $continueOnError = $step->continueOnError ?? false;
                if (!$continueOnError) {
                    throw new RuntimeException(
                        "Error when executing step " . (get_class($step)) . " (number " . ($i + 1) . " in the plan)",
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


$complex_blueprint = Blueprint::fromValidatedArray(json_decode(<<<'JSON'
{
  "version": 2,
  "$schema": "https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/schema.json",
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
  "siteLanguage": "en_US",
  "siteOptions": {
    "blogname": "Full Featured Test Site",
    "timezone_string": "America/New_York",
    "permalink_structure": "/%postname%/"
  },
  "constants": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "WP_DEBUG_DISPLAY": false,
    "SCRIPT_DEBUG": true,
    "CUSTOM_CONSTANT": "custom-value"
  },
  "wordpressVersion": "6.4",
  "phpVersion": "8.0",
  "activeTheme": "twentytwentythree",
  "themes": [
    "twentytwentyfour"
  ],
  "plugins": [
    "akismet",
    {
      "source": "woocommerce",
      "active": true,
      "activationOptions": {
        "option1": "value1"
      }
    }
  ],
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
  "content": [
    {
      "type": "posts",
      "source": [
        {
          "post_title": "Sample Post",
          "post_content": "This is a sample post content.",
          "post_status": "publish",
          "post_type": "post"
        },
        {
          "post_title": "Sample Page",
          "post_content": "This is a sample page content.",
          "post_status": "publish",
          "post_type": "page"
        }
      ]
    }
  ],
  "users": [
    {
      "username": "editor",
      "email": "editor@example.com",
      "role": "editor",
      "meta": {
        "first_name": "Test",
        "last_name": "Editor"
      }
    }
  ],
  "roles": [
    {
      "name": "book_editor",
      "capabilities": {
        "read": "true",
        "edit_books": "true",
        "publish_books": "true"
      }
    }
  ],
  "additionalStepsAfterExecution": [
    {
      "step": "writeFiles",
      "files": [
        {
          "path": "wp-content/uploads/custom-file.txt",
          "content": "This is a custom file created by the Blueprint."
        }
      ]
    },
    {
      "step": "runPHP",
      "code": "echo 'Blueprint execution completed!';"
    }
  ]
} 
JSON, true, 512, JSON_THROW_ON_ERROR));

$simple_blueprint = <<<'JSON'
{
  "version": 2,
  "$schema": "https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/schema.json",
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
  "additionalStepsAfterExecution": [
    {
      "step": "writeFiles",
      "files": {
        "wp-content/uploads/custom-file.txt": "This is a custom file created by the Blueprint.",
        "builder.php": {
			"source": "./test_file.txt"
		}
      }
    }
  ]
} 
JSON;

// Silence PHP deprecation warnings
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Initialize runtime for the given document root
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../../../vendor/autoload.php';
$runtime = new Runtime(__DIR__ . '/test_blueprint_runner', __DIR__);

$runnerConfiguration = RunnerConfiguration::create()
	->setRawBlueprint($simple_blueprint)
	->setTargetSiteRoot(__DIR__ . '/test_blueprint_runner')
	->setExecutionContextRoot(__DIR__);

$runner = new BlueprintRunner($runnerConfiguration);
$runner->run();
