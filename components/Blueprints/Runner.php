<?php

namespace WordPress\Blueprints;

use InvalidArgumentException;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\DataReferenceResolver;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\DataReference\InlineFile;
use WordPress\Blueprints\DataReference\URLReference;
use WordPress\Blueprints\DataReference\WordPressOrgPlugin;
use WordPress\Blueprints\DataReference\WordPressOrgTheme;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\SiteResolver\ExistingSiteResolver;
use WordPress\Blueprints\SiteResolver\NewSiteResolver;
use WordPress\Blueprints\Steps\ActivatePluginStep;
use WordPress\Blueprints\Steps\ActivateThemeStep;
use WordPress\Blueprints\Steps\CpStep;
use WordPress\Blueprints\Steps\DefineConstantsStep;
use WordPress\Blueprints\Steps\Exception;
use WordPress\Blueprints\Steps\ImportMediaStep;
use WordPress\Blueprints\Steps\ImportThemeStarterContentStep;
use WordPress\Blueprints\Steps\InstallPluginStep;
use WordPress\Blueprints\Steps\InstallThemeStep;
use WordPress\Blueprints\Steps\MkdirStep;
use WordPress\Blueprints\Steps\MvStep;
use WordPress\Blueprints\Steps\RmDirStep;
use WordPress\Blueprints\Steps\RmStep;
use WordPress\Blueprints\Steps\RunPHPStep;
use WordPress\Blueprints\Steps\RunSqlStep;
use WordPress\Blueprints\Steps\RuntimeException;
use WordPress\Blueprints\Steps\SetSiteLanguageStep;
use WordPress\Blueprints\Steps\SetSiteOptionsStep;
use WordPress\Blueprints\Steps\UnzipStep;
use WordPress\Blueprints\Steps\WPCLIStep;
use WordPress\Blueprints\Steps\WriteFilesStep;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\FilesystemCache;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Encoding\utf8_is_valid_byte_stream;
use function WordPress\Zip\is_zip_file_stream;

class Runner {
	private Client $client;
	private DataReferenceResolver $assets;
	private Filesystem $blueprintExecutionContext;
	private array $blueprintArray;
	private array $dataReferences;
	private ?VersionConstraint $phpVersionConstraint;
	private Tracker $mainTracker;
	private ProgressObserver $progressObserver;

	public function __construct( private RunnerConfiguration $configuration ) {
		$this->client      = new Client( [
			/**
			 * Store cached HTTP responses in a temporary directory with a stable path
			 * to reuse across multiple runs.
			 */
			'cache' => new FilesystemCache(
				LocalFilesystem::create( 
					sys_get_temp_dir() . '/wp-blueprints'
				)
			)
		] );
		$this->mainTracker = new Tracker();

		// Set up progress logging
		$this->progressObserver = $configuration->getProgressObserver() ?? new ProgressObserver(
			function ( $progress, $caption ) {
				fprintf( STDERR, "[%3d%%] %s\n", $progress, $caption );
			}
		);
		$this->progressObserver->attachTo( $this->mainTracker );
	}

	public function run(): void {
		$tempRoot = sys_get_temp_dir() . '/wp-blueprints-runtime-' . uniqid();
		mkdir( $tempRoot, 0777, true );

		try {
			// Create all top-level progress stages upfront so the tracker knows what %
			// of the total work is being done with every progress update.
			//
			// The stage weights are arbitrary and can be tweaked as needed.
			// They have to add up to 1.
			$blueprintStage        = $this->mainTracker->stage( 0.05, 'Resolving Blueprint' );
			$targetResolutionStage = $this->mainTracker->stage( 0.2, 'Setting up WordPress site' );
			$dataResolutionStage   = $this->mainTracker->stage( 0.25, 'Resolving data references' );
			$executionStage        = $this->mainTracker->stage( 0.5, 'Executing Blueprint steps' );

			$this->assets = new DataReferenceResolver( $this->client );

			$blueprintStage->setCaption( 'Loading Blueprint data' );
			$this->loadBlueprint();
			$this->validateBlueprint();
			$this->assets->setExecutionContext( $this->blueprintExecutionContext );
			$blueprintStage->finish();

			$targetResolutionStage->setCaption( 'Resolving target site' );

			$targetSiteFs = LocalFilesystem::create( $this->configuration->getTargetSiteRoot() );
			$runtime      = new Runtime(
				$targetSiteFs,
				$this->configuration,
				$this->assets,
				$this->client,
				$this->blueprintArray,
				$tempRoot
			);

			if ( $this->configuration->getExecutionMode() === 'apply-to-existing-site' ) {
				ExistingSiteResolver::resolve( $runtime, $targetResolutionStage );
			} else {
				NewSiteResolver::resolve( $runtime, $targetResolutionStage );
			}
			$targetResolutionStage->finish();

			$plan = $this->createExecutionPlan();
			$this->assets->startEagerResolution( $this->dataReferences, $dataResolutionStage );
			$this->executePlan( $executionStage, $plan, $runtime );
		} finally {
			LocalFilesystem::create( $tempRoot )->rmdir( '/', [
				'recursive' => true,
			]);
		}
	}

	/*──────────────── Blueprint load / validation / createExecutionPlan ─────────────*/
	private function loadBlueprint() {
		$reference = $this->configuration->getBlueprint();

		if ( is_array( $reference ) ) {
			$this->blueprintArray   = $reference;
			$this->blueprintExecutionContext = $this->configuration->getExecutionContext() ?? InMemoryFilesystem::create();

			return;
		}

		if ( $reference instanceof ExecutionContextPath ) {
			$absolute_path = $reference->get_normalized_path();
			if ( substr( $absolute_path, 0, 1 ) !== '/' ) {
				throw new \Exception( 'Blueprint path must be absolute' );
			}
			$resolved = new File(
				FileReadStream::from_path( $absolute_path ),
				$reference->get_filename()
			);
		} else {
			$resolved = $this->assets->resolve( $reference );
		}
		
		if ( $resolved instanceof File ) {
			$stream = $resolved->stream;

			if ( is_zip_file_stream( $stream ) ) {
				$this->blueprintExecutionContext = new ZipFilesystem( $stream );
				$blueprintString        = $this->blueprintExecutionContext->get_contents( '/blueprint.json' );
			} else {
				// JSON file
				$blueprintString = $stream->consume_all();
				if ( $reference instanceof URLReference ) {
					throw new \Exception( 'URLReference not supported yet as a blueprint reference type' );
				} elseif ( $reference instanceof ExecutionContextPath ) {
					// It was resolved as an ExecutionContextPath, but it's actually a local
					// filesystem path at this point.
					// The execution context is the directory containing the blueprint.json file.
					$this->blueprintExecutionContext = LocalFilesystem::create( dirname( $reference->get_path() ) );
				} elseif ( $reference instanceof InlineFile ) {
					$this->blueprintExecutionContext = InMemoryFilesystem::create();
				} else {
					throw new \Exception( 'Unsupported blueprint reference type: ' . get_class( $reference ) );
				}
			}
		} elseif ( $resolved instanceof Directory ) {
			$this->blueprintExecutionContext = $resolved->filesystem;
			$blueprintString        = $this->blueprintExecutionContext->get_contents( '/blueprint.json' );
		} else {
			throw new \Exception( 'Invalid blueprint reference' );
		}

		// ### Validate the Blueprint

		// Preliminary validation of the provided Blueprint string:

		// 1. **UTF-8 Encoding:** Assert the Blueprint input is UTF-8 encoded.
		$is_valid_utf8 = false;
		if ( function_exists( 'mb_check_encoding' ) ) {
			$is_valid_utf8 = mb_check_encoding( $blueprintString, 'UTF-8' );
		} else {
			$is_valid_utf8 = utf8_is_valid_byte_stream( $blueprintString );
		}
		
		if ( ! $is_valid_utf8 ) {
			throw new \Exception( 'Blueprint must be encoded as UTF-8' );
		}

		// 2. **JSON Validity:** Assert the input is a valid JSON document.
		$this->blueprintArray = json_decode( $blueprintString, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \Exception( 'Blueprint must be a valid JSON document' );
		}
	}

	private function validateBlueprint(): void {
		// Schema conformance
		$v        = new Validator();
		$is_valid = $v->validate( $this->blueprintArray );
		if ( ! $is_valid ) {
			print_r( $v->get_errors() );
			throw new \Exception( 'Blueprint is invalid' );
		}

		if ( isset( $this->blueprintArray['phpVersion'] ) ) {
			$this->phpVersionConstraint = VersionConstraint::fromMixed( $this->blueprintArray['phpVersion'] );
		} else {
			$this->phpVersionConstraint = VersionConstraint::fromMixed( [
				'recommended' => '8.0',
			] );
		}

		// Validate the constraint is satisfiable
		// @TODO: Explore moving this over to the VersionConstraint class
		//        we'll need a WordPressVersionConstraint class that understands
		//        WordPress versioning scheme (and "latest", "nightly", etc)
		if ( $this->phpVersionConstraint->getMin() !== null ) {
			if ( $this->phpVersionConstraint->getMin() > $this->phpVersionConstraint->getMax() ) {
				throw new \Exception( 'min must be less than or equal to max' );
			}
			if ( $this->phpVersionConstraint->getRecommended() < $this->phpVersionConstraint->getMin() ) {
				throw new \Exception( 'recommended must be between min and max' );
			}
		}

		if ( $this->phpVersionConstraint->getMax() !== null ) {
			if ( $this->phpVersionConstraint->getRecommended() > $this->phpVersionConstraint->getMax() ) {
				throw new \Exception( 'recommended must be less than or equal to max' );
			}
		}

		// Validate PHP version constraint if specified in the blueprint
		$currentPhpVersion = PHP_VERSION;

		// Check if the current PHP version satisfies the constraint
		if ( ! $this->phpVersionConstraint->satisfiedBy( $currentPhpVersion ) ) {
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
		if ( ! empty( $validated_array['constants'] ) && is_array( $validated_array['constants'] ) ) {
			$plan[] = $this->createStepObject( 'defineConstants', [ 'constants' => $validated_array['constants'] ] );
		}

		// 2. siteOptions
		if ( ! empty( $validated_array['siteOptions'] ) && is_array( $validated_array['siteOptions'] ) ) {
			// Ensure siteUrl is not included as per schema Omit<>
			unset( $validated_array['siteOptions']['siteUrl'] );
			if ( ! empty( $validated_array['siteOptions'] ) ) {
				$plan[] = $this->createStepObject( 'setSiteOptions', [ 'options' => $validated_array['siteOptions'] ] );
			}
		}

		// 3. muPlugins - Install via writeFiles step
		if ( ! empty( $validated_array['muPlugins'] ) && is_array( $validated_array['muPlugins'] ) ) {
			$files = [];
			foreach ( $validated_array['muPlugins'] as $pluginPath => $pluginContent ) {
				if ( is_string( $pluginPath ) && is_string( $pluginContent ) ) {
					$files[ '/wp-content/mu-plugins/' . $pluginPath ] = $pluginContent;
				} elseif ( is_string( $pluginContent ) ) {
					// Handle numeric keys
					$files[ '/wp-content/mu-plugins/' . basename( $pluginContent ) ] = $pluginContent;
				}
			}
			if ( ! empty( $files ) ) {
				$plan[] = $this->createStepObject( 'writeFiles', [ 'files' => $files ] );
			}
		}

		// 4. themes (install non-active)
		if ( ! empty( $validated_array['themes'] ) && is_array( $validated_array['themes'] ) ) {
			foreach ( $validated_array['themes'] as $themeRef ) {
				if ( is_string( $themeRef ) ) {
					$plan[] = $this->createStepObject( 'installTheme', [
						'source'               => $themeRef,
						'activate'             => false,
						'importStarterContent' => false,
					] );
				} elseif ( is_array( $themeRef ) && isset( $themeRef['source'] ) && is_string( $themeRef['source'] ) ) {
					// Pass through the raw definition for extensibility.
					$plan[] = $this->createStepObject( 'installTheme', [
						'source'               => $themeRef['source'],
						'activate'             => $themeRef['activate'] ?? false,
						'importStarterContent' => $themeRef['importStarterContent'] ?? false,
						'targetFolderName'     => $themeRef['targetFolderName'] ?? null,
					] );
				} else {
					throw new InvalidArgumentException( 'Invalid theme reference format in "themes" array.' );
				}
			}
		}

		// 5. activeTheme (install and activate)
		if ( isset( $validated_array['activeTheme'] ) ) {
			$themeRef = $validated_array['activeTheme'];
			if ( is_string( $themeRef ) ) {
				$plan[] = $this->createStepObject( 'installTheme', [
					'source'               => $themeRef,
					'activate'             => true,
					'importStarterContent' => false,
				] );
			} elseif ( is_array( $themeRef ) && isset( $themeRef['source'] ) && is_string( $themeRef['source'] ) ) {
				$plan[] = $this->createStepObject( 'installTheme', [
					'source'               => $themeRef['source'],
					'activate'             => true,
					'importStarterContent' => $themeRef['importStarterContent'] ?? false,
					'targetFolderName'     => $themeRef['targetFolderName'] ?? null,
				] );
			} else {
				throw new InvalidArgumentException( 'Invalid theme reference format for "activeTheme".' );
			}
		}

		// 6. plugins
		if ( ! empty( $validated_array['plugins'] ) && is_array( $validated_array['plugins'] ) ) {
			foreach ( $validated_array['plugins'] as $pluginDef ) {
				$plan[] = $this->createStepObject( 'installPlugin', [ 'plugin' => $pluginDef ] );
			}
		}

		// 7. fonts – not directly supported; use RunPHP placeholders.
		if ( ! empty( $validated_array['fonts'] ) && is_array( $validated_array['fonts'] ) ) {
			throw new InvalidArgumentException( 'Your Blueprint contains a "fonts" property that is not supported yet.' );
		}

		// 8. media – Import media files
		if ( ! empty( $validated_array['media'] ) && is_array( $validated_array['media'] ) ) {
			$plan[] = $this->createStepObject( 'importMedia', [ 'media' => $validated_array['media'] ] );
		}

		// 9. siteLanguage
		if ( ! empty( $validated_array['siteLanguage'] ) && is_string( $validated_array['siteLanguage'] ) ) {
			$plan[] = $this->createStepObject( 'setSiteLanguage', [ 'language' => $validated_array['siteLanguage'] ] );
		}

		// 10. roles - create custom roles using WordPress role management
		if ( ! empty( $validated_array['roles'] ) && is_array( $validated_array['roles'] ) ) {
			$plan[] = $this->createStepObject( 'createRoles', [ 'roles' => $validated_array['roles'] ] );
		}

		// 11. users - create users using WordPress user management
		if ( ! empty( $validated_array['users'] ) && is_array( $validated_array['users'] ) ) {
			$plan[] = $this->createStepObject( 'createUsers', [ 'users' => $validated_array['users'] ] );
		}

		// 12. postTypes – generate one MU-plugin per post type, skipping those already registered.
		if ( ! empty( $validated_array['postTypes'] ) && is_array( $validated_array['postTypes'] ) ) {
			$plan[] = $this->createStepObject( 'createPostTypes', [ 'postTypes' => $validated_array['postTypes'] ] );
		}

		// 13. content – Import inline posts via wp_insert_post().
		if ( ! empty( $validated_array['content'] ) && is_array( $validated_array['content'] ) ) {
			foreach ( $validated_array['content'] as $contentEntry ) {
				if ( ! isset( $contentEntry['type'], $contentEntry['source'] ) ) {
					throw new InvalidArgumentException( 'Invalid content entry: missing "type" or "source" key.' );
				}

				// Only handle 'posts' content type for now.
				if ( 'posts' !== $contentEntry['type'] ) {
					throw new InvalidArgumentException(
						sprintf( 'Unsupported content type: "%s". Only "posts" is currently supported.', $contentEntry['type'] )
					);
				}

				if ( ! is_array( $contentEntry['source'] ) ) {
					throw new InvalidArgumentException( 'Invalid content source: must be an array.' );
				}

				// Filter inline post definitions (arrays) – skip file paths/URLs (strings).
				$inlinePosts = array_values(
					array_filter(
						$contentEntry['source'],
						static fn( $item ) => is_array( $item )
					)
				);

				if ( ! $inlinePosts ) {
					// Nothing inline to import – skip.
					continue;
				}

				$plan[] = $this->createStepObject( 'importPosts', [ 'posts' => $inlinePosts ] );
			}
		}

		// 14. additionalStepsAfterExecution
		if ( ! empty( $validated_array['additionalStepsAfterExecution'] ) && is_array( $validated_array['additionalStepsAfterExecution'] ) ) {
			foreach ( $validated_array['additionalStepsAfterExecution'] as $stepData ) {
				$plan[] = $this->createStepObject( $stepData['step'], $stepData );
			}
		}

		return $plan;
	}

	/**
	 * Helper method to create a specific step object from its type and data.
	 *
	 * @param  string  $stepType  The 'step' identifier (e.g., 'installPlugin').
	 * @param  array  $data  The properties for the step.
	 *
	 * @return mixed A Step object instance.
	 * @throws InvalidArgumentException If the step type is unknown or data is invalid.
	 */
	private function createStepObject( string $stepType, array $data ): mixed {
		switch ( $stepType ) {
			case 'activatePlugin':
				return new ActivatePluginStep( $data['pluginPath'] );
			case 'activateTheme':
				return new ActivateThemeStep( $data['themeFolderName'] );
			case 'cp':
				return new CpStep( $data['fromPath'], $data['toPath'] );
			case 'defineConstants':
				return new DefineConstantsStep( $data['constants'] );
			case 'importThemeStarterContent':
				return new ImportThemeStarterContentStep( $data['themeSlug'] ?? null );
			case 'installPlugin':
				$pluginDef = $data['plugin'];
				if ( is_string( $pluginDef ) ) {
					return new InstallPluginStep( $this->createDataReference( $pluginDef, [
						WordPressOrgPlugin::class,
					] ) );
				} else {
					$source  = $this->createDataReference( $pluginDef['source'], [
						WordPressOrgPlugin::class,
					] );
					$active  = $pluginDef['active'] ?? true;
					$options = $pluginDef['activationOptions'] ?? null;
					$onError = isset( $pluginDef['onError'] ) ? $pluginDef['onError'] : 'throw';

					return new InstallPluginStep( $source, $active, $options, $onError );
				}
			case 'installTheme':
				$source = $this->createDataReference( $data['source'], [
					WordPressOrgTheme::class,
				] );

				return new InstallThemeStep(
					$source,
					$data['activate'] ?? false,
					$data['importStarterContent'] ?? false,
					$data['targetFolderName'] ?? null
				);
			case 'mkdir':
				return new MkdirStep( $data['path'] );
			case 'mv':
				return new MvStep( $data['fromPath'], $data['toPath'] );
			case 'rm':
				return new RmStep( $data['path'] );
			case 'rmdir':
				return new RmDirStep( $data['path'] );
			case 'runPHP':
				return RunPHPStep::fromArray( $data );
			case 'runSql':
				$source = $this->createDataReference( $data['source'] );

				return new RunSqlStep( $source );
			case 'setSiteLanguage':
				return new SetSiteLanguageStep( $data['language'] );
			case 'setSiteOptions':
				return new SetSiteOptionsStep( $data['options'] );

			case 'createRoles':
				if ( empty( $data['roles'] ) || ! is_array( $data['roles'] ) ) {
					throw new InvalidArgumentException( 'Invalid roles data: must be a non-empty array.' );
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

				return RunPHPStep::fromArray( [
					'code' => $code,
					'env'  => [ 'ROLES' => $data['roles'] ],
				] );

			case 'createUsers':
				if ( empty( $data['users'] ) || ! is_array( $data['users'] ) ) {
					throw new InvalidArgumentException( 'Invalid users data: must be a non-empty array.' );
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
                }';

				return RunPHPStep::fromArray( [
					'code' => $code,
					'env'  => [ 'USERS' => $data['users'] ],
				] );

			case 'createPostTypes':
				if ( empty( $data['postTypes'] ) || ! is_array( $data['postTypes'] ) ) {
					throw new InvalidArgumentException( 'Invalid postTypes data: must be a non-empty array.' );
				}

				// @TODO: Do we need a separate step here? To make sure we're not overwriting existing post types?
				//        Or would WriteFilesStep be enough, perhaps with a "no override" flag?
				// @TODO: Install SCF and use it to register post types.

				$files = [];
				foreach ( $data['postTypes'] as $slug => $args ) {
					if ( ! is_string( $slug ) || $slug === '' ) {
						continue;
					}

					// Ensure $args is an array.
					if ( ! is_array( $args ) ) {
						$args = [];
					}

					// Build a safe file name for the MU-plugin.
					$fileSlug   = preg_replace( '/[^a-z0-9\-]+/i', '-', strtolower( $slug ) );
					$pluginPath = "wp-content/mu-plugins/blueprint-post-type-{$fileSlug}.php";

					// Human-friendly default label.
					$defaultLabel = addslashes( ucwords( str_replace( [ '-', '_' ], ' ', $slug ) ) );
					if ( ! isset( $args['label'] ) ) {
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
						var_export( $slug, true ),
						var_export( $args, true ),
					);

					$files[ $pluginPath ] = $this->createDataReference( [
						'filename' => $pluginPath,
						'content'  => $pluginCode,
					] );
				}

				if ( empty( $files ) ) {
					throw new InvalidArgumentException( 'No valid post types to register.' );
				}

				return new WriteFilesStep( $files );

			case 'importPosts':
				if ( empty( $data['posts'] ) || ! is_array( $data['posts'] ) ) {
					throw new InvalidArgumentException( 'Invalid posts data: must be a non-empty array.' );
				}

				$inlinePosts = array_values(
					array_filter(
						$data['posts'],
						static fn( $item ) => is_array( $item )
					)
				);

				if ( empty( $inlinePosts ) ) {
					throw new InvalidArgumentException( 'No inline posts to import.' );
				}

				$postsArray = var_export( $inlinePosts, true );
				$code       = <<<PHP
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

				return RunPHPStep::fromArray( [
					'code' => $code,
				] );

			case 'runPHP':
				$method = isset( $data['method'] ) ? $data['method'] : 'GET';

				return RunPHPStep::fromArray( $data );
			case 'unzip':
				$zipFile = $this->createDataReference( $data['zipFile'] );

				return new UnzipStep( $zipFile, $data['extractToPath'] );
			case 'wp-cli':
				return new WPCLIStep( $data['command'], $data['wpCliPath'] ?? null );
			case 'writeFiles':
				$files = [];
				foreach ( $data['files'] as $path => $content ) {
					$files[ $path ] = $this->createDataReference( $content );
				}

				return new WriteFilesStep( $files );
			case 'importMedia':
				$media = [];
				foreach ( $data['media'] as $path => $content ) {
					if ( is_string( $content ) ) {
						$media[ $path ] = MediaFileDefinition::fromArray( [
							'source' => $this->createDataReference( $content ),
						] );
						continue;
					}

					$media[ $path ] = MediaFileDefinition::fromArray( [
						'source'      => $this->createDataReference( $content['source'] ),
						'title'       => $content['title'] ?? null,
						'description' => $content['description'] ?? null,
						'alt'         => $content['alt'] ?? null,
						'caption'     => $content['caption'] ?? null,
					] );
				}

				return new ImportMediaStep( $media );
			default:
				throw new InvalidArgumentException( "Unknown step type: {$stepType}" );
		}
	}

	private function createDataReference( mixed $data, array $additional_reference_classes = [] ): DataReference {
		$reference                              = $data instanceof DataReference ? $data
			: DataReference::create( $data, $additional_reference_classes );
		// @TODO: If referencing a ExecutionContextPath, ensure we have the user consent
		//        to load data from the local filesystem.
		$this->dataReferences[ $reference->id ] = $reference;

		return $reference;
	}


	/**
	 * Run the steps in the execution plan with progress tracking
	 *
	 * @param  Tracker  $parentTracker  The parent tracker for step execution
	 *
	 * @return array Results from each step execution
	 */
	private function executePlan( Tracker $parentTracker, array $steps, Runtime $runtime ): array {
		/**
		 * Execute the steps in the execution plan with progress tracking
		 */
		$results   = [];
		$stepCount = count( $steps );

		if ( $stepCount === 0 ) {
			$parentTracker->finish();

			return $results;
		}

		// Create sub-trackers for each step with equal weight
		$stepWeight   = 1.0 / $stepCount;
		$stepTrackers = [];

		// Create all step trackers upfront for accurate progress calculation
		for ( $i = 0; $i < $stepCount; $i ++ ) {
			$step               = $steps[ $i ];
			$stepNumber         = $i + 1;
			$stepTrackers[ $i ] = $parentTracker->stage(
				$stepWeight,
				sprintf( "Step %d/%d", $stepNumber, $stepCount )
			);
		}

		// Execute each step
		for ( $i = 0; $i < $stepCount; $i ++ ) {
			$step        = $steps[ $i ];
			$stepTracker = $stepTrackers[ $i ];

			try {
				$results[ $i ] = $step->run( $runtime, $stepTracker );

				// If step didn't call finish(), do it for them
				if ( ! $stepTracker->isDone() ) {
					$stepTracker->finish();
				}
			} catch ( \Exception $e ) {
				$results[ $i ] = $e;
				$stepTracker->setCaption( sprintf( "%s (FAILED: %s)",
					$stepTracker->getCaption(),
					$e->getMessage()
				) );

				// Mark as done but not 100% to indicate error
				$stepTracker->set( 99.9 );
				$stepTracker->finish();

				// Determine if we should continue or stop execution
				$continueOnError = $this->continueOnError ?? false;
				if ( ! $continueOnError ) {
					throw new \RuntimeException(
						sprintf( "Error when executing step %s (number %d in the plan)",
							get_class( $step ),
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
}
