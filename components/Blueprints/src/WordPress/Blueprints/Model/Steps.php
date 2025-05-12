<?php

/**
 * @TODO: Fast unzipping of remote Zip Files by iterating over the entries
 *        instead of skipping over to the end central directory index entry.
 * @TODO: Processing Zip Files without the Content-Length header.
 * @TODO: HTTP Cache support for remote files.
 * @TODO: Restrictions on supported step types, media files types, SQL queries types, etc.
 * @TODO: Add importMedia step to the specification.
 */

namespace WordPress\Blueprints\Model;

use Exception;
use InvalidArgumentException; // Standard PHP exception
use JsonException; // Standard PHP exception
use RuntimeException;
use Symfony\Component\Process\Process;
use WordPress\Blueprints\BlueprintV2Validator;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Progress\ProgressEvent;
use WordPress\Blueprints\Progress\DoneEvent;
use WordPress\Blueprints\Progress\ProgressTrackedReadStream;
use WordPress\Blueprints\Resources\Model\DataReference;
use WordPress\Blueprints\Resources\Model\Directory;
use WordPress\Blueprints\Resources\Model\ExecutionContextPath;
use WordPress\Blueprints\Resources\Model\File;
use WordPress\Blueprints\Resources\Model\GitPath;
use WordPress\Blueprints\Resources\Model\InlineDirectory;
use WordPress\Blueprints\Resources\Model\InlineFile;
use WordPress\Blueprints\Resources\Model\URLReference;
use WordPress\Blueprints\Resources\Model\WordPressOrgPlugin;
use WordPress\Blueprints\Resources\Model\WordPressOrgTheme;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\NotEnoughDataException;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\FilesystemHelpers;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\FilesystemCache;
use WordPress\HttpClient\Request;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\pipe_stream;
use function WordPress\Filesystem\wp_join_paths;
use function WordPress\Zip\is_zip_file_stream;

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
		$plugin_data = $runtime->resolve($step->getSource());

		$fs = $runtime->getTargetFilesystem();
		FilesystemHelpers::withTemporaryDirectory($fs, function($temp_dir) use ($fs, $runtime, $step, $tracker, $plugin_data) {
			$tracker->setCaption('Installing plugin ' . $plugin_data->get_human_readable_name());
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

				if (is_zip_file_stream($plugin_data->stream)) {
					pipe_stream($plugin_data->stream, $zip_stream);
					$plugin_data->stream->close_reading();
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

			$tracker->set(50);
			$runtime->evalPhpInSubProcess(
				file_get_contents(__DIR__ . '/InstallPlugin/wp_install_plugin.php'),
				['PLUGIN_ZIP_PATH' => $zip_path, 'OUTPUT_FILE' => $temp_dir . '/plugin_path.txt']
			);

			$relative_path = $fs->get_contents($temp_dir . '/plugin_path.txt');

			if ($step->isActive()) {
				$tracker->set(75, 'Activating plugin ' . $plugin_data->get_human_readable_name());
				$runtime->evalPhpInSubProcess(
					file_get_contents(__DIR__ . '/ActivatePlugin/wp_activate_plugin.php'),
					['PLUGIN_PATH' => $relative_path]
				);
			}

			$tracker->set(100);
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
		$fs = $runtime->getTargetFilesystem();
		FilesystemHelpers::withTemporaryDirectory($fs, function($temp_dir) use ($fs, $runtime, $step, $tracker) {
			// Create data reference for the theme source
			$dataRef = $step->getSource();
			$theme_data = $runtime->resolve($dataRef);
			$tracker->setCaption('Installing theme ' . $theme_data->get_human_readable_name());

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
				
				if (is_zip_file_stream($theme_data->stream)) {
					pipe_stream($theme_data->stream, $zip_stream);
				} else {
					throw new \RuntimeException("Theme is not a valid zip file.");
				}
				$zip_stream->close_writing();
			}

			$tracker->set(50);

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
				$tracker->set(75, 'Activating theme ' . $theme_folder_name);
				$runtime->evalPhpInSubProcess(
					file_get_contents(__DIR__ . '/ActivateTheme/wp_activate_theme.php'),
					['THEME_FOLDER_NAME' => $theme_folder_name]
				);
			}

			$tracker->set(100);
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
			'DOCROOT' => $runtime->getConfiguration()->getTargetSiteRoot(),
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
		$sql = $runtime->resolve($source);

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

class SetSiteLanguageStepRunner implements StepRunnerInterface {
    public function run(object $step, Runtime $runtime, Tracker $tracker): mixed {
        $tracker->setCaption('Translating');
        $language = $step->getLanguage();
        
        // Define WPLANG constant
		$runner = new DefineConstantsStepRunner();
        $runner->run(new DefineConstantsStep(['WPLANG' => $language]), $runtime, new Tracker());
    
        
        // Create language directories if they don't exist
        $fs = $runtime->getTargetFilesystem();
        $languages_dir = "wp-content/languages";
        $plugins_languages_dir = "{$languages_dir}/plugins";
        $themes_languages_dir = "{$languages_dir}/themes";
        
        if (!$fs->is_dir($languages_dir)) {
            $fs->mkdir($languages_dir, 0755, true);
        }
        if (!$fs->is_dir($plugins_languages_dir)) {
            $fs->mkdir($plugins_languages_dir, 0755, true);
        }
        if (!$fs->is_dir($themes_languages_dir)) {
            $fs->mkdir($themes_languages_dir, 0755, true);
        }
        
        // Get core translation package URL
        $wp_version = trim($runtime->evalPhpInSubProcess(
            "<?php
            require getenv('DOCROOT') . '/wp-includes/version.php';
            echo \$wp_version;
            "
        ));
        
        // Get plugin translations
        $plugins_data = json_decode($runtime->evalPhpInSubProcess(
            "<?php
            require_once(getenv('DOCROOT') . '/wp-load.php');
            require_once(getenv('DOCROOT') . '/wp-admin/includes/plugin.php');
            echo json_encode(
                array_values(
                    array_map(
                        function(\$plugin) {
                            return [
                                'slug'    => \$plugin['TextDomain'],
                                'version' => \$plugin['Version']
                            ];
                        },
                        array_filter(
                            get_plugins(),
                            function(\$plugin) {
                                return !empty(\$plugin['TextDomain']);
                            }
                        )
                    )
                )
            );"
        ), true);
        
        // Get theme translations
        $themes_data = json_decode($runtime->evalPhpInSubProcess(
            "<?php
            require_once(getenv('DOCROOT') . '/wp-load.php');
            require_once(getenv('DOCROOT') . '/wp-admin/includes/theme.php');
            echo json_encode(
                array_values(
                    array_map(
                        function(\$theme) {
                            return [
                                'slug'    => \$theme->get('TextDomain'),
                                'version' => \$theme->get('Version')
                            ];
                        },
                        wp_get_themes()
                    )
                )
            );"
        ), true);

		$client = $runtime->getHttpClient();
        
        // Prepare all download URLs
        $download_targets = [];
        
        // Core translation
		if($language === 'en_US') {
			$core_translation_url = $this->getWordPressTranslationUrl($wp_version, $language, $client);
			if($core_translation_url) {
				$download_targets[] = [
					'request' => new Request($core_translation_url),
					'target_dir' => $languages_dir,
					'name' => "core-{$language}"
				];
			}
		}
        
        // Plugin translations
        if (is_array($plugins_data)) {
            foreach ($plugins_data as $plugin) {
                if (empty($plugin['slug']) || empty($plugin['version'])) {
                    continue;
                }
                
                $plugin_translation_url = "https://downloads.wordpress.org/translation/plugin/{$plugin['slug']}/{$plugin['version']}/{$language}.zip";
                $download_targets[] = [
					'request' => new Request($plugin_translation_url),
                    'target_dir' => $plugins_languages_dir,
                    'name' => "plugin-{$plugin['slug']}-{$language}",
                    'is_plugin' => true,
                    'slug' => $plugin['slug']
                ];
            }
        }

        // Theme translations
        if (is_array($themes_data)) {
            foreach ($themes_data as $theme) {
                if (empty($theme['slug']) || empty($theme['version'])) {
                    continue;
                }
                
                $theme_translation_url = "https://downloads.wordpress.org/translation/theme/{$theme['slug']}/{$theme['version']}/{$language}.zip";
                $download_targets[] = [
					'request' => new Request($theme_translation_url),
                    'target_dir' => $themes_languages_dir,
                    'name' => "theme-{$theme['slug']}-{$language}",
                    'is_theme' => true,
                    'slug' => $theme['slug']
                ];
            }
        }
        
        // Download all translations in parallel
		$nb_requests = count($download_targets);
		foreach($download_targets as $k => $target) {
			$stage = $tracker->stage( 1 / $nb_requests, 'Fetching translations for ');
			$download_targets[$k]['stream'] = $client->fetch($target['request'], [
				// @see Runtime for more details on these options
				'progress_tracker' => $stage,
				'eagerly_enqueue' => true,
				'buffer_size' => 100 * 1024 * 1024,
			]);
		}
        
        foreach ($download_targets as $target) {
            try {
                $zipFs = ZipFilesystem::create($target['stream']);
                copy_between_filesystems([
                    'source_filesystem' => $zipFs,
                    'source_path' => '/',
                    'target_filesystem' => $runtime->getTargetFilesystem(),
                    'target_path' => $target['target_dir'],
                    'recursive' => true,
                ]);
            } catch (\Exception $e) {
                // Only log warnings for plugin and theme translations
				// @TODO: Find a more useful way of communicating warnings
                if (isset($target['is_plugin'])) {
                    echo "Warning: Failed to download translations for plugin {$target['slug']}: " . $e->getMessage() . "\n";
                } elseif (isset($target['is_theme'])) {
                    echo "Warning: Failed to download translations for theme {$target['slug']}: " . $e->getMessage() . "\n";
                } else {
                    // For core translations, we should re-throw the exception
                    throw new \Exception("Failed to download core translations: " . $e->getMessage(), 0, $e);
                }
            }
        }
        
        return true;
    }
    
    /**
     * Get the translation package URL for a given WordPress version and language.
     *
     * @param string $wpVersion WordPress version
     * @param string $language Language code
     * @return string Translation package URL
     * @throws \Exception If translation package is not found
     */
    private function getWordPressTranslationUrl(string $wpVersion, string $language, Client $client): string|false {
		try {
			$api_url = "https://api.wordpress.org/translations/core/1.0/?version={$wpVersion}";
			$translations_data = $client->fetch($api_url)->json();
			
			if (!isset($translations_data['translations']) || !is_array($translations_data['translations'])) {
				throw new \Exception("Invalid response from WordPress.org translations API");
			}
			
			foreach ($translations_data['translations'] as $translation) {
				if (strtolower($translation['language']) === strtolower($language)) {
					return $translation['package'];
				}
			}
		} catch (\Exception $e) {
			// Log warning about translation API failure
			error_log("Warning: Failed to fetch translations from WordPress.org API: " . $e->getMessage());
		}
		return false;
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
		$zip_stream = $runtime->resolve($zipFile);

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
				$data_stream = $runtime->resolve($data);
				$content = $data_stream->stream->consume_all();
			}

			$target_fs->put_contents($path, $content);
			$files_written++;
		}

		$tracker->set(100, "All {$total_files} files written successfully.");

		return true;
	}
}

class ProcessFailedException extends \Exception {

	/**
	 * @var \Symfony\Component\Process\Process
	 */
	protected $process;

	public function __construct( Process $process, ?\Throwable $previous = null ) {
		$this->process = $process;
		parent::__construct(
			'Process `' . $process->getCommandLine() . '` failed with exit code ' . $process->getExitCode() . " and the following stderr output: \n" . $process->getErrorOutput() . "\n" . $process->getOutput(),
			$process->getExitCode(),
			$previous
		);
	}

	public function getProcess(): Process {
		return $this->process;
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


/*──────────────────────── Value objects ───────────────────────────*/
class VersionConstraint
{
    public function __construct(
        private ?string $min = null,
        private ?string $max = null,
        private ?string $recommended = null
    ) {}
    public static function fromMixed(mixed $src): self {
		if (is_string($src)) {
			return new self(null, null, $src);
		}
		if (is_array($src)) {
			return new self($src['min'] ?? null, $src['max'] ?? null, $src['recommended'] ?? null);
		}
		throw new \InvalidArgumentException('Invalid version constraint');
	}
    public function getMin(): ?string         { return $this->min; }
    public function getMax(): ?string         { return $this->max; }
    public function getRecommended(): ?string { return $this->recommended; }

	public function satisfiedBy(string $version): bool {
		if ($this->min !== null) {
			if (version_compare($version, $this->min, '<')) {
				return false;
			}
		}
		if ($this->max !== null) {
			if (version_compare($version, $this->max, '>')) {
				return false;
			}
		}
		return true;
	}

	public function __toString(): string {
		$parts = [];
		if ($this->min !== null) {
			$parts[] = "min: {$this->min}";
		}
		if ($this->max !== null) {
			$parts[] = "max: {$this->max}";
		}
		if ($this->recommended !== null) {
			$parts[] = "recommended: {$this->recommended}";
		}
		return sprintf('VersionConstraint(%s)', implode(', ', $parts));
	}
}

/*──────────────────────── Runner configuration ────────────────────*/
class RunnerConfiguration
{
    private DataReference|array $blueprintRef;
    private string  $mode    = 'create-new-site';    // or apply-to-existing-site
    private string  $rootDir = '';
    private string  $siteUrl = '';
    private ?Filesystem $executionContext = null;
	private string $databaseEngine = 'mysql';
	private array $databaseCredentials = [];

    public function setBlueprint(DataReference|array $r): self    { 
		$this->blueprintRef = $r;
        return $this; 
    }
    public function getBlueprint(): DataReference|array  { return $this->blueprintRef; }

    public function setExecutionMode(string $m): self             { $this->mode = $m; return $this; }
    public function getExecutionMode(): string                    { return $this->mode; }

    public function setTargetSiteRoot(string $d): self            { $this->rootDir = $d; return $this; }
    public function getTargetSiteRoot(): string                   { return $this->rootDir; }

    public function setTargetSiteUrl(string $u): self             { $this->siteUrl = $u; return $this; }
    public function getTargetSiteUrl(): string                    { return $this->siteUrl; }
    
    public function setExecutionContext(Filesystem $fs): self     { $this->executionContext = $fs; return $this; }
	public function getExecutionContext(): Filesystem|null        { return $this->executionContext; }

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

	public function getDatabaseEngine(): string { return $this->databaseEngine; }

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

	public function getDatabaseCredentials(): array { return $this->databaseCredentials; }
}

/*──────────────────────── Data-reference resolver ─────────────────*/
class DataReferenceResolver
{
	private array $subTrackers;
	private array $dataReferences;
	private array $resolvedDataReferences;
	private Tracker $dataResolutionTracker;
    public function __construct(
        private Client     $client,
		private Filesystem $executionContext
    ) {}

	public function startEagerResolution(array $dataReferences, Tracker $dataResolutionTracker) {
		$this->dataResolutionTracker = $dataResolutionTracker;
		$this->dataReferences = $dataReferences;
		$nb_data_references = count( $this->dataReferences );
		foreach( $this->dataReferences as $dataReference ) {
			$this->subTrackers[$dataReference->id] = $this->dataResolutionTracker->stage(
				1 / $nb_data_references,
				'Resolving data reference #' . $dataReference->id . ': ' . $dataReference->get_human_readable_name()
			);
			$this->resolve($dataReference);
		}
	}

    /** Core service method shared by runner, target resolvers and steps */
    public function resolve( DataReference $reference, ?Tracker $progress_tracker = null ): File|Directory {
		if( isset( $this->resolvedDataReferences[$reference->id] ) ) {
			return $this->resolvedDataReferences[$reference->id];
		}

		if( $progress_tracker === null ) {
			$progress_tracker = $this->subTrackers[$reference->id] ?? new Tracker();
		}

		if ( $reference instanceof WordPressOrgPlugin ) {
			$reference = new URLReference('https://downloads.wordpress.org/plugin/' . $reference->get_slug() . '.latest-stable.zip');
		} elseif ( $reference instanceof WordPressOrgTheme ) {
			$reference = new URLReference('https://downloads.wordpress.org/theme/' . $reference->get_slug() . '.latest-stable.zip');
		}

		if ( $reference instanceof URLReference ) {
			$url = $reference->get_url();
			$filename = basename( parse_url( $url, PHP_URL_PATH ) );

			// @TODO: Memoize downloads to the disk – probably by adding
			//        disk cache (or even http cache) support to the Client
			//        class.
			$tracked_stream = $this->client->fetch(
				$url,
				array(
					/**
					 * Use a 100MB buffer to support seek()-ing in the streamed ZIP files.
					 * To support ZIPs larger than 100MB, we'll need a custom SeekableRequestReadStream that:
					 *
					 * * Uses range headers when possible.
					 * * Buffers data on disk for seeking(), not in memory.
					 */
					'buffer_size' => 100 * 1024 * 1024,
					'progress_tracker' => $progress_tracker,
					'eagerly_enqueue' => true,
				)
			);
			return new File(
				$tracked_stream,
				$filename
			);
		} elseif ( $reference instanceof ExecutionContextPath ) {
			$path = $reference->get_path();
			if( ! $this->executionContext->exists( $path ) ) {
				throw new \RuntimeException( 'File not found: ' . $path );
			}
			if( $this->executionContext->is_file( $path ) ) {
				$stream = $this->executionContext->open_read_stream( $path );
				$tracked_stream = new ProgressTrackedReadStream( $stream, $progress_tracker );
				return new File( $tracked_stream, basename( $path ) );
			} else if( $this->executionContext->is_dir( $path ) ) {
				// @TODO: Actually track the download progress for directories.
				$this->subTrackers[$reference->id]->finish();
				return new Directory(
					new ChrootLayer( $this->executionContext, $path ),
					basename( $path )
				);
			} else {
				throw new \RuntimeException( 'Path is not a file or directory: ' . $path );
			}
		} elseif ( $reference instanceof InlineFile ) {
			$progress_tracker->finish();
			return new File( new MemoryPipe( $reference->get_content() ), $reference->get_filename() );
		} elseif ( $reference instanceof InlineDirectory ) {
			$progress_tracker->finish();
			$fs = InMemoryFilesystem::create();
			/**
			 * @TODO: This can be recursive, we need to support nested directories.
			 */
			foreach( $reference->get_children() as $child ) {
				$fs->put_contents( $child->get_path(), $child->get_content() );
			}
			return new Directory( $fs, $reference->get_name() );
		} elseif ( $reference instanceof GitPath ) {
			// @TODO: Actually track the download progress for git repositories.
			$progress_tracker->finish();

			/**
			 * @TODO: Use a local path as in the Blueprints v2 spec Appendix B.
			 *        Even medium-sized repos can use all the memory.
			 */
			$repo = new GitRepository( InMemoryFilesystem::create() );
			$repo->add_remote( 'origin', $reference->get_git_repository() );
			$remote = $repo->get_remote( 'origin' );
			$remote->pull(
				$reference->get_ref(),
				array(
					'path' => $reference->get_path(),
				)
			);
			return new Directory(
				new GitFilesystem( $repo ),
				basename( $reference->get_path() ) ?: 'git-repo'
			);
		}

		throw new \Exception( 'Unsupported reference type ' . get_class( $reference ) );
	}
}

/*──────────────────────── Resolver: existing site ─────────────────*/
class ExistingSiteResolver
{
    static public function resolve(Runtime $runtime, Tracker $targetResolutionStage)
    {
		throw new \Exception('Not implemented yet');
	}
}

/*──────────────────────── Resolver: new site ──────────────────────*/
class NewSiteResolver
{
    static public function resolve(Runtime $runtime, Tracker $targetResolutionStage)
    {
		$stages = [
			'resolve_assets' => $targetResolutionStage->stage(0.66),
			'install_wordpress' => $targetResolutionStage->stage(0.33, 'Installing WordPress'),
		];

		$blueprint = $runtime->getBlueprint();

		// Ensure document root directory exists (LocalFilesystem::create creates it)
		$targetFs = $runtime->getTargetFilesystem();

		// Unzip WordPress core into document root
		$wpVersionConstraint = isset($blueprint['wordpressVersion'])
			? VersionConstraint::fromMixed($blueprint['wordpressVersion'])
			: null;

		$wpZip = self::resolveWordPressZipUrl($runtime->getHttpClient(), $wpVersionConstraint);

		$assets = [
			'wordpress' => DataReference::create($wpZip),
		];
		if ($runtime->getConfiguration()->getDatabaseEngine() === 'sqlite') {
			// @TODO: configurable sqlite integration plugin zip URL
			$assets['sqlite-integration'] = DataReference::create('https://downloads.wordpress.org/plugin/sqlite-database-integration.zip');
		}
		$assets['wp-cli'] = DataReference::create('https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar');

		$runtime->getDataReferenceResolver()->startEagerResolution($assets, $stages['resolve_assets']);

		$stages['resolve_assets']->setCaption('Downloading WordPress');

		$resolved = $runtime->resolve($assets['wordpress']);
		if (!$resolved instanceof File) {
			throw new \InvalidArgumentException('Provided zip reference does not resolve to a file');
		}
		$zipFs = ZipFilesystem::create($resolved->stream);

		$path_in_zip = '/';
		if(!$zipFs->exists('/wp-content') && $zipFs->exists('/wordpress')) {
			$path_in_zip = '/wordpress';
		}

		$stages['install_wordpress']->set(0.2, 'Setting up WordPress files');

		copy_between_filesystems([
			'source_filesystem' => $zipFs,
			'source_path'       => $path_in_zip,
			'target_filesystem' => $targetFs,
			'target_path'       => '/',
			'recursive'         => true,
		]);

		$stages['install_wordpress']->set(0.6, 'Installing WordPress');

		// If SQLite integration zip provided, unzip into appropriate folder
		if ($runtime->getConfiguration()->getDatabaseEngine() === 'sqlite') {
			$stages['resolve_assets']->setCaption('Downloading SQLite integration plugin');
			$resolved = $runtime->resolve($assets['sqlite-integration']);
			if (!$resolved instanceof File) {
				throw new \InvalidArgumentException('Provided zip reference does not resolve to a file');
			}
			$zipFs = ZipFilesystem::create($resolved->stream);

			$targetPath = '/wp-content/plugins/sqlite-database-integration';
			$sourcePath = '/';
			if ($zipFs->exists('sqlite-database-integration')) {
				$sourcePath = '/sqlite-database-integration';
			}
			// @TODO: Track unzipping progress
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

		if (trim($installCheck) !== '1') {
			$wp_cli_filename = 'wp-cli.phar';
			if(!$targetFs->exists($wp_cli_filename)) {
				$stages['resolve_assets']->setCaption('Downloading wp-cli');
				$resolved = $runtime->resolve($assets['wp-cli']);
				if (!$resolved instanceof File) {
					throw new \InvalidArgumentException('Provided zip reference does not resolve to a file');
				}
				$write_stream = $targetFs->open_write_stream($wp_cli_filename);
				pipe_stream($resolved->stream, $write_stream);
				$write_stream->close_writing();
			}

			if(!$targetFs->exists('/wp-config.php')) {
				if ( $targetFs->exists( 'wp-config-sample.php' ) ) {
					$targetFs->copy( 'wp-config-sample.php', 'wp-config.php' );
				} else {
					throw new \RuntimeException( 'Neither wp-config.php, nor wp-config-sample.php was found in the WordPress archive.' );
				}
			}

			// Perform installation using WP-CLI
			// @TODO: Remove the WP-CLI dependency to lower the download size for blueprints.phar.
			$stages['install_wordpress']->set(0.7, 'Installing WordPress');
			$wp_cli_path = wp_join_paths($runtime->getConfiguration()->getTargetSiteRoot(), 'wp-cli.phar');
			$runtime->runShellCommand([
				'php',
				$wp_cli_path,
				'core',
				'install',
				'--url=' . $runtime->getConfiguration()->getTargetSiteUrl(),
				'--title=WordPress Site',
				'--admin_user=admin',
				'--admin_password=password',
				'--admin_email=admin@example.com',
				'--skip-email'
			]);
		}
		$targetResolutionStage->finish();
    }

	static private function resolveWordPressZipUrl(Client $client, ?VersionConstraint $constraint): string {
		if($constraint === null) {
			return 'https://wordpress.org/latest.zip';
		}

		$min = $constraint->getMin();
		$max = $constraint->getMax();
		$recommended = $constraint->getRecommended();

		$version_string = $recommended ?? $max ?? $min;

		if ($version_string === 'latest') {
			return 'https://wordpress.org/latest.zip';
		}

		if (
			str_starts_with($version_string, 'https://') ||
			str_starts_with($version_string, 'http://')
		) {
			return $version_string;
		}
		
		if ($version_string === 'nightly') {
			return 'https://wordpress.org/nightly-builds/wordpress-latest.zip';
		}

		$latestVersions = $client->fetch('https://api.wordpress.org/core/version-check/1.7/?channel=beta')->json();
		$latestVersions = array_filter($latestVersions['offers'], function($v) {
			return $v['response'] === 'autoupdate';
		});

		foreach ($latestVersions as $apiVersion) {
			if ($version_string === 'beta' && strpos($apiVersion['version'], 'beta') !== false) {
				return $apiVersion['download'];
			} else if (
				$version_string === 'latest' &&
				strpos($apiVersion['version'], 'beta') === false
			) {
				// The first non-beta item in the list is the latest version.
				return $apiVersion['download'];
			} else if (
				substr($apiVersion['version'], 0, strlen($version_string)) ===
				$version_string
			) {
				return $apiVersion['download'];
			}
		}

		throw new \Exception('Invalid WordPress version constraint');
	}
}

/*──────────────────────── Runtime passed to steps ─────────────────*/
class Runtime
{
    public function __construct(
        private Filesystem $targetFs,
		private RunnerConfiguration $configuration,
        private DataReferenceResolver $assets,
        private Client                $client,
		private array $blueprint
    ) {}
	public function getHttpClient(): Client { return $this->client; }
	public function getBlueprint(): array { return $this->blueprint; }
	public function getConfiguration(): RunnerConfiguration { return $this->configuration; }
    public function getTargetFilesystem(): Filesystem        { return $this->targetFs; }
	public function getDataReferenceResolver(): DataReferenceResolver { return $this->assets; }
    public function resolve(DataReference $r, ?Tracker $progress_tracker = null): File|Directory{
		return $this->assets->resolve($r, $progress_tracker);
	}

	public function withTemporaryDirectory( callable $callback ) {
		return FilesystemHelpers::withTemporaryDirectory( $this->targetFs, $callback );
	}

	public function withTemporaryFile( callable $callback, ?string $suffix = null ) {
		return FilesystemHelpers::withTemporaryFile( $this->targetFs, $callback, $suffix );
	}

	/**
	 * @param mixed[]|null $env
	 * @param float        $timeout
	 */
	public function evalPhpInSubProcess(
		$code,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		return $this->withTemporaryFile(function($tempFile) use ($code, $env, $input, $timeout) {
			$this->targetFs->put_contents($tempFile, '<?php $_SERVER["HTTP_HOST"] = "localhost"; ?>' . $code);

			return $this->runShellCommand(
				array(
					'php',
					$tempFile,
				),
				$this->configuration->getTargetSiteRoot(),
				array_merge(
					array(
						'DOCROOT' => $this->configuration->getTargetSiteRoot(),
					),
					$env ?? array()
				),
				$input,
				$timeout
			);
		});
	}

	/**
	 * @TODO: Migrate from Symfony Process to a more lightweight implementation.
	 * @TODO: Expose stdout and stderr as byte streams.
	 * @TODO: Don't wait until the process terminates. Just return the streams and
	 *        some kind of wait() method for the caller to decide.
	 * 
	 * @param mixed[]      $command
	 * @param string|null  $cwd
	 * @param mixed[]|null $env
	 * @param float        $timeout
	 */
	public function runShellCommand(
		$command,
		$cwd = null,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		$cwd = $cwd ?? $this->configuration->getTargetSiteRoot();

		$process = new Process(
			$command,
			$cwd,
			$env,
			$input,
			$timeout
		);
		$process->start();
		$process->wait();
		if ( $process->getExitCode() !== 0 ) {
			// @TODO: Don't just echo this here
			echo $process->getErrorOutput();
			throw new ProcessFailedException( $process );
		}

		return $process->getOutput();
	}
}

/*──────────────────────── Blueprint runner ────────────────────────*/
class BlueprintRunner
{
    private Client               $client;
    private DataReferenceResolver $assets;
	private Filesystem $executionContext;
	private array $blueprintArray;
	private array $dataReferences;
	private ?VersionConstraint $phpVersionConstraint;
	private Tracker $mainTracker;
	private ProgressLogger $progressLogger;

    public function __construct(private RunnerConfiguration $configuration)
    {
        $cache = new FilesystemCache(LocalFilesystem::create(__DIR__ . '/cache'));
        $this->client     = new Client(['cache' => $cache]);
        $this->mainTracker = new Tracker();

        // Set up progress logging
        $this->progressLogger = new ProgressLogger(
            function($progress, $caption) {
                fprintf(STDERR, "[%3d%%] %s\n", $progress, $caption);
            }
        );
        $this->progressLogger->attachTo($this->mainTracker);
    }

    public function run(): void
    {
        // Create all top-level progress stages upfront so the tracker knows what %
		// of the total work is being done with every progress update.
		//
		// The stage weights are arbitrary and can be tweaked as needed.
		// They have to add up to 1.
        $blueprintStage = $this->mainTracker->stage(0.05, 'Resolving Blueprint');
        $targetResolutionStage = $this->mainTracker->stage(0.2, 'Setting up WordPress site');
        $dataResolutionStage = $this->mainTracker->stage(0.25, 'Resolving data references');
        $executionStage = $this->mainTracker->stage(0.5, 'Executing Blueprint steps');

        $blueprintStage->setCaption('Loading Blueprint data');
        $this->loadBlueprint();
        $this->validateBlueprint();
        $blueprintStage->finish();

		$targetResolutionStage->setCaption('Resolving target site');
        $this->assets = new DataReferenceResolver(
			$this->client,
			$this->executionContext
		);

		$targetSiteFs = LocalFilesystem::create($this->configuration->getTargetSiteRoot());
        $runtime = new Runtime(
			$targetSiteFs,
			$this->configuration,
			$this->assets,
			$this->client,
			$this->blueprintArray
		);

        if($this->configuration->getExecutionMode() === 'apply-to-existing-site') {
			ExistingSiteResolver::resolve($runtime, $targetResolutionStage);
		} else {
			NewSiteResolver::resolve($runtime, $targetResolutionStage);
		}
		$targetResolutionStage->finish();

        $plan = $this->createExecutionPlan();
		$this->assets->startEagerResolution($this->dataReferences, $dataResolutionStage);
        $this->executePlan($executionStage, $plan, $runtime);
    }

    /*──────────────── Blueprint load / validation / createExecutionPlan ─────────────*/
    private function loadBlueprint()
    {
		$reference = $this->configuration->getBlueprint();
		if(is_array($reference)) {
			$this->blueprintArray = $reference;
			$this->executionContext = $this->configuration->getExecutionContext() ?? InMemoryFilesystem::create();
			return;
		}

		$resolved = $this->assets->resolve($reference);
        if ($resolved instanceof File) {
			$stream = $resolved->stream;

			if (is_zip_file_stream($stream)) {
				$this->executionContext = new ZipFilesystem($stream);
				$blueprintString = $this->executionContext->get_contents('/blueprint.json');
			} else {
				// JSON file
				$blueprintString = $stream->consume_all();
				if($reference instanceof URLReference) {
					throw new \Exception('URLReference not supported yet as a blueprint reference type');
				} else if($reference instanceof ExecutionContextPath) {
					// It was resolved as an ExecutionContextPath, but it's actually a local
					// filesystem path at this point.
					// The execution context is the directory containing the blueprint.json file.
					$this->executionContext = LocalFilesystem::create(dirname($reference->get_path()));
				} else {
					// @TODO: Support other reference types
					throw new \Exception('Unsupported blueprint reference type');
				}
			}
        } else if ($resolved instanceof Directory) {
			$this->executionContext = $resolved->filesystem;
			$blueprintString = $this->executionContext->get_contents('/blueprint.json');
		} else {
			throw new \Exception('Invalid blueprint reference');
		}

		// ### Validate the Blueprint

		// Preliminary validation of the provided Blueprint string:

		// 1. **UTF-8 Encoding:** Assert the Blueprint input is UTF-8 encoded.
		if(!function_exists('mb_check_encoding')) {
			// @TODO: Use Dennis Snells' utf-8 decoder as a fallback.
			throw new \Exception('mb_check_encoding() is not available, cannot validate UTF-8 encoding of the blueprint');
		}

		if (!mb_check_encoding($blueprintString, 'UTF-8')) {
			throw new \Exception('Blueprint must be encoded as UTF-8');
		}

		// 2. **JSON Validity:** Assert the input is a valid JSON document.
		$this->blueprintArray = json_decode($blueprintString, true);
		if(json_last_error() !== JSON_ERROR_NONE) {
			throw new \Exception('Blueprint must be a valid JSON document');
		}
    }

    private function validateBlueprint(): void {
		// Schema conformance
		$v = new BlueprintV2Validator();
		$is_valid = $v->validate($this->blueprintArray);
		if(!$is_valid) {
			throw new \Exception('Blueprint is invalid');
		}

		if(isset($this->blueprintArray['phpVersion'])) {
			$this->phpVersionConstraint = VersionConstraint::fromMixed($this->blueprintArray['phpVersion']);
		} else {
			$this->phpVersionConstraint = VersionConstraint::fromMixed([
				'recommended' => '8.0',
			]);
		}

		// Validate the constraint is satisfiable
		// @TODO: Explore moving this over to the VersionConstraint class
		//        we'll need a WordPressVersionConstraint class that understands
		//        WordPress versioning scheme (and "latest", "nightly", etc)
		if($this->phpVersionConstraint->getMin() !== null) {
			if($this->phpVersionConstraint->getMin() > $this->phpVersionConstraint->getMax()) {
				throw new \Exception('min must be less than or equal to max');
			}
			if($this->phpVersionConstraint->getRecommended() < $this->phpVersionConstraint->getMin()) {
				throw new \Exception('recommended must be between min and max');
			}
		}

		if($this->phpVersionConstraint->getMax() !== null) {
			if($this->phpVersionConstraint->getRecommended() > $this->phpVersionConstraint->getMax()) {
				throw new \Exception('recommended must be less than or equal to max');
			}
		}

		// Validate PHP version constraint if specified in the blueprint
		$currentPhpVersion = PHP_VERSION;
		
		// Check if the current PHP version satisfies the constraint
		if (!$this->phpVersionConstraint->satisfiedBy($currentPhpVersion)) {
			throw new \Exception(
				sprintf(
					'PHP version requirement not satisfied. Blueprint requires %s, but current version is %s',
					$this->phpVersionConstraint->__toString(),
					$currentPhpVersion
				)
			);
		}
	}

	private function createExecutionPlan(): array {
		$validated_array = $this->blueprintArray;
        // --- Process Declarative Properties into Steps (in order) ---

		$plan = [];
        // 1. constants
        if ( !empty($validated_array['constants']) && is_array($validated_array['constants'])) {
            $plan[] = $this->createStepObject('defineConstants', ['constants' => $validated_array['constants']]);
        }

        // 2. siteOptions
        if ( !empty($validated_array['siteOptions']) && is_array($validated_array['siteOptions'])) {
            // Ensure siteUrl is not included as per schema Omit<>
            unset($validated_array['siteOptions']['siteUrl']);
            if (!empty($validated_array['siteOptions'])) {
                $plan[] = $this->createStepObject('setSiteOptions', ['options' => $validated_array['siteOptions']]);
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
                $plan[] = $this->createStepObject('writeFiles', ['files' => $files]);
            }
        }

        // 4. themes (install non-active)
        if ( !empty($validated_array['themes']) && is_array($validated_array['themes'])) {
            foreach ($validated_array['themes'] as $themeRef) {
                if (is_string($themeRef)) {
                    $plan[] = $this->createStepObject('installTheme', [
                        'source' => $themeRef,
                        'activate' => false,
                        'importStarterContent' => false,
                    ]);
                } elseif (is_array($themeRef) && isset($themeRef['source']) && is_string($themeRef['source'])) {
                    // Pass through the raw definition for extensibility.
                    $plan[] = $this->createStepObject('installTheme', [
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
                $plan[] = $this->createStepObject('installTheme', [
                    'source' => $themeRef,
                    'activate' => true,
                    'importStarterContent' => false,
                ]);
            } elseif (is_array($themeRef) && isset($themeRef['source']) && is_string($themeRef['source'])) {
                $plan[] = $this->createStepObject('installTheme', [
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
                $plan[] = $this->createStepObject('installPlugin', ['plugin' => $pluginDef]);
            }
        }

        // 7. fonts – not directly supported; use RunPHP placeholders.
        if ( !empty($validated_array['fonts']) && is_array($validated_array['fonts'])) {
			throw new InvalidArgumentException('Your Blueprint contains a "fonts" property that is not supported yet.');
        }

        // 8. media – Import media files
        if ( !empty($validated_array['media']) && is_array($validated_array['media'])) {
            $plan[] = $this->createStepObject('importMedia', ['media' => $validated_array['media']]);
        }

        // 9. siteLanguage
        if ( !empty($validated_array['siteLanguage']) && is_string($validated_array['siteLanguage'])) {
            $plan[] = $this->createStepObject('setSiteLanguage', ['language' => $validated_array['siteLanguage']]);
        }

        // 10. roles - create custom roles using WordPress role management
        if ( !empty($validated_array['roles']) && is_array($validated_array['roles'])) {
            $plan[] = $this->createStepObject('createRoles', ['roles' => $validated_array['roles']]);
        }

        // 11. users - create users using WordPress user management
        if ( !empty($validated_array['users']) && is_array($validated_array['users'])) {
            $plan[] = $this->createStepObject('createUsers', ['users' => $validated_array['users']]);
        }

        // 12. postTypes – generate one MU-plugin per post type, skipping those already registered.
        if ( !empty($validated_array['postTypes']) && is_array($validated_array['postTypes'])) {
            $plan[] = $this->createStepObject('createPostTypes', ['postTypes' => $validated_array['postTypes']]);
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

                $plan[] = $this->createStepObject('importPosts', ['posts' => $inlinePosts]);
            }
        }

        // 14. additionalStepsAfterExecution
        if ( !empty($validated_array['additionalStepsAfterExecution']) && is_array($validated_array['additionalStepsAfterExecution'])) {
            foreach ($validated_array['additionalStepsAfterExecution'] as $stepData) {
                $plan[] = $this->createStepObject($stepData['step'], $stepData);
            }
        }

		return $plan;
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
				require_once(getenv("DOCROOT") . "/wp-load.php");
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
                require_once(getenv("DOCROOT") . "/wp-load.php");
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

                // @TODO: Do we need a separate step here? To make sure we're not overwriting existing post types?
                //        Or would WriteFilesStep be enough, perhaps with a "no override" flag?
                // @TODO: Install SCF and use it to register post types.

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
                    if(!isset($args['label'])) {
                        $args['label'] = $defaultLabel;
                    }

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
								register_post_type(%1$s, %2$s);
							},
							0
						);
						PHP,
                        var_export($slug, true),
                        var_export($args, true),
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
				require_once(getenv("DOCROOT") . "/wp-load.php");

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
            case 'importMedia':
                $media = [];
                foreach ($data['media'] as $path => $content) {
                    if (is_string($content)) {
						$media[$path] = (new MediaFileDefinition())
							->setSource( $this->createDataReference($content) );
						continue;
                    }

					$media[$path] = (new MediaFileDefinition())
						->setSource( $this->createDataReference($content['source']) )
						->setTitle( $content['title'] ?? null )
						->setDescription( $content['description'] ?? null )
						->setAlt( $content['alt'] ?? null )
						->setCaption( $content['caption'] ?? null );
                }
                return new ImportMediaStep($media);
            default:
                throw new InvalidArgumentException("Unknown step type: {$stepType}");
        }
    }

	private function createDataReference(mixed $data, array $additional_reference_classes = []): DataReference {
		$reference = $data instanceof DataReference ? $data : DataReference::create($data, $additional_reference_classes);
		$this->dataReferences[$reference->id] = $reference;
		return $reference;
	}

	
    /**
     * Run the steps in the execution plan with progress tracking
     *
     * @param Tracker $parentTracker The parent tracker for step execution
     * @return array Results from each step execution
     */
    private function executePlan(Tracker $parentTracker, array $steps, Runtime $runtime): array {
		/**
		 * Execute the steps in the execution plan with progress tracking
		 */
        $results = [];
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
                $results[$i] = $runner->run($step, $runtime, $stepTracker);

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
        if ($step instanceof SetSiteLanguageStep) {
            return new SetSiteLanguageStepRunner();
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
        if ($step instanceof ImportMediaStep) {
            return new ImportMediaStepRunner();
        }

        throw new \InvalidArgumentException('Unknown step type: ' . get_class($step));
    }
}


/**
 * Represents the 'importMedia' step.
 */
class ImportMediaStep {
    /**
     * An associative array of media files to import.
     * @var array<string, DataReference|string>
     */
    private array $media;

    /**
     * @param array<string, DataReference|string> $media Media files to import.
     */
    public function __construct(array $media) {
        $this->media = $media;
    }

    /**
     * @return array<string, DataReference|string>
     */
    public function getMedia(): array {
        return $this->media;
    }

    /**
     * @param array<string, DataReference|string> $media
     */
    public function setMedia(array $media): void {
        $this->media = $media;
    }
}

class ImportMediaStepRunner implements StepRunnerInterface {
    public function run(object $step, Runtime $runtime, Tracker $tracker) {
        $tracker->setCaption('Importing media files');
		$medias = $step->getMedia();
        $total_files = count($medias);
        
        if ($total_files === 0) {
            $tracker->finish();
            return true;
        }
        
        $files_imported = 0;
        $fs = $runtime->getTargetFilesystem();
        $wp_upload_dir = FilesystemHelpers::withTemporaryDirectory($fs, function($temp_dir) use ($fs, $runtime) {
            $output_file = $temp_dir . '/upload_dir.json';
            $runtime->evalPhpInSubProcess(
                '<?php
                require_once(getenv("DOCROOT") . "/wp-load.php");
                $upload_dir = wp_upload_dir();
                file_put_contents(getenv("OUTPUT_FILE"), json_encode($upload_dir));
                ',
                ['OUTPUT_FILE' => $output_file]
            );
            return $fs->get_contents($output_file);
        }, '');
        
        $upload_dir = json_decode($wp_upload_dir, true);
        if (!$upload_dir || !isset($upload_dir['path'])) {
            throw new RuntimeException('Failed to get WordPress upload directory');
        }

        // Get the upload path relative to the WordPress root
        $upload_base_dir = ltrim(substr($upload_dir['path'], strlen($runtime->getConfiguration()->getTargetSiteRoot())), '/');
        $upload_base_url = $upload_dir['url'];
        
        // Ensure the uploads directory exists
        $fs = $runtime->getTargetFilesystem();
        if (!$fs->is_dir($upload_base_dir)) {
            $fs->mkdir($upload_base_dir, ['recursive' => true]);
        }

		$progress_download = $tracker->stage(0.5 / $total_files);
		$progress_import = $tracker->stage(0.5 / $total_files);

		$data_references = [];
        foreach ($medias as $media_definition) {
			$data_references[] = $media_definition->source;
		}

		$resolved = $runtime->getDataReferenceResolver()->startEagerResolution(
			$data_references,
			$progress_download
		);
        
		$progress_import_step = 1.0/$total_files;
        foreach ($medias as $media_definition) {
			$human_readable_name = $media_definition->source->get_human_readable_name();
            $progress_import->setCaption("Importing media file {$files_imported}/{$total_files}: {$human_readable_name}");
            
            try {
				$resolved = $runtime->resolve($media_definition->source);
                
                if (!$resolved instanceof File) {
                    throw new RuntimeException("Failed to resolve media file: $human_readable_name");
                }
                
                // Create a new file in the uploads directory
                $target_path = $this->resolveTargetPath(
					$runtime,
					$media_definition->source,
					$upload_base_dir
				);
                
                $write_stream = $fs->open_write_stream($target_path);
                pipe_stream($resolved->stream, $write_stream);
                $resolved->stream->close_reading();
                $write_stream->close_writing();
                
                // Add to WordPress media library                
                $attachment_id = $runtime->evalPhpInSubProcess(
                    <<<'CODE'
                    <?php
                    require_once(getenv("DOCROOT") . "/wp-load.php");
                    require_once(getenv("DOCROOT") . "/wp-admin/includes/image.php");
                    
                    $file_path = getenv("MEDIA_FILE_PATH");
                    $attachment_meta = json_decode(getenv("ATTACHMENT_META"), true);
                    $attachment_data = [
                        'post_title' => $attachment_meta['title'] ?? preg_replace('/\.[^.]+$/', '', basename($file_path)),
                        'post_mime_type' => wp_check_filetype(basename($file_path), null)['type'] ?? 'application/octet-stream',
                        'post_content' => $attachment_meta['description'] ?? '',
                        'post_status' => 'inherit',
                        'post_excerpt' => $attachment_meta['caption'] ?? '',
                        'meta_input' => [
                            '_wp_attachment_image_alt' => $attachment_meta['alt'] ?? '',
                        ],
                    ];                    
                    $attachment_id = wp_insert_attachment($attachment_data, $file_path);
                    
                    if (is_wp_error($attachment_id)) {
                        echo "0";
                        exit(1);
                    }
                    
                    // Generate metadata and create thumbnails if needed
                    $mime_type = $attachment_data['post_mime_type'];
                    if (strpos($mime_type, 'image/') === 0) {
                        $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
                        wp_update_attachment_metadata($attachment_id, $attachment_metadata);
                    }
                    
                    echo $attachment_id;
                    CODE,
                    [
                        'MEDIA_FILE_PATH' => $target_path,
                        'ATTACHMENT_META' => json_encode([
                            'title' => $media_definition->title,
                            'description' => $media_definition->description,
                            'alt' => $media_definition->alt,
                            'caption' => $media_definition->caption
                        ])
                    ]
                );

                if(!$attachment_id) {
                    throw new RuntimeException("Failed to import media file: $human_readable_name");
                }
                
                $progress_import->increment($progress_import_step);
            } catch (Exception $e) {
                // Log error but continue with other media files
                error_log("Failed to import media file {$target_path}: " . $e->getMessage());
            }
            
            $files_imported++;
        }
        
        $tracker->finish();
    }

	private function resolveTargetPath(
		Runtime $runtime,
		DataReference $source,
		string $upload_base_dir
	): string {
		$fs = $runtime->getTargetFilesystem();

		$filename = $source->get_filename();			
		if(!$filename) {
			throw new RuntimeException(sprintf(
				'Failed to get filename for media file: %s. We can\'t infer the extension.',
				$source->get_human_readable_name()
			));
		}
		
		/**
		 * If we already have a file with the same name, choose a random
		 * filename.
		 */
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
		$target_path = $upload_base_dir . '/' . $filename;
		while ($fs->exists($target_path)) {
			$filename = substr(sha1(uniqid('media_', true)), 0, 12) . '.' . $extension;
            $target_path = $upload_base_dir . '/' . $filename;
		}

		$parent_dir = dirname($target_path);
		if(!$fs->is_dir($parent_dir)) {
			$fs->mkdir($parent_dir, ['recursive' => true]);
		}

		return $target_path;
	}
}

class MediaFileDefinition {
	public DataReference $source;
	public ?string $title = null;
	public ?string $description = null;
	public ?string $alt = null;
	public ?string $caption = null;

	/**
	 * Set the source for the media file
	 * 
	 * @param DataReference $source The source reference for the media file
	 * @return self
	 */
	public function setSource(DataReference $source): self {
		$this->source = $source;
		return $this;
	}
	
	/**
	 * Set the title for the media file
	 * 
	 * @param string|null $title The title for the media file
	 * @return self
	 */
	public function setTitle(?string $title): self {
		$this->title = $title;
		return $this;
	}
	
	/**
	 * Set the description for the media file
	 * 
	 * @param string|null $description The description for the media file
	 * @return self
	 */
	public function setDescription(?string $description): self {
		$this->description = $description;
		return $this;
	}
	
	/**
	 * Set the alt text for the media file
	 * 
	 * @param string|null $alt The alt text for the media file
	 * @return self
	 */
	public function setAlt(?string $alt): self {
		$this->alt = $alt;
		return $this;
	}
	
	/**
	 * Set the caption for the media file
	 * 
	 * @param string|null $caption The caption for the media file
	 * @return self
	 */
	public function setCaption(?string $caption): self {
		$this->caption = $caption;
		return $this;
	}
}


/*────────────────────────── Example usage ─────────────────────────*/

$config = (new RunnerConfiguration())
    ->setBlueprint([
		"version" => 2,
		'$schema' => "https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/schema.json",
		"plugins" => [
			"friends"
		],
		// @TODO: Should the default WordPress theme stay? Do we need an option for this!
		"themes" => [
			"adventurer"
		],
		"blueprintMeta" => [
			"name" => "Full Featured Blueprint",
			"description" => "A blueprint demonstrating most of the available features",
			"version" => "1.0.0",
			"authors" => ["Test Author", "Another Author"],
			"authorUrl" => "https://example.com",
			"donateLink" => "https://example.com/donate",
			"tags" => ["test", "full-features", "demo"],
			"license" => "GPL-2.0"
		],
		"postTypes" => [
			"book" => [
				"label" => "Books",
				"description" => "Books post type",
				"public" => true,
				"has_archive" => true,
				"show_in_rest" => true,
				"supports" => ["title", "editor", "author", "thumbnail", "excerpt", "comments"]
			]
		],
		"media" => [
			"https://wordpress.org/files/2024/10/design-visual-6-7.png",
            [
                "source" => "https://wordpress.org/files/2024/10/design-visual-6-7.png",
                "title" => "Introduction Video",
                "description" => "A brief introduction to our company",
                "alt" => "Company introduction video"
            ],
		],
		"additionalStepsAfterExecution" => [
			[
				"step" => "writeFiles",
				"files" => [
					"wp-content/uploads/custom-file.txt" => [
						"filename" => "custom-file.txt",
						"content" => "This is a custom file created by the Blueprint."
					],
					"builder.php" => "./test_file.txt"
				]
			]
		]
	])
	->setDatabaseEngine('sqlite')
    ->setExecutionMode('create-new-site')
    ->setTargetSiteRoot(__DIR__ . '/test_blueprint_runner')
    ->setTargetSiteUrl('http://127.0.0.1:2456');

(new BlueprintRunner($config))->run();
