<?php

use Rowbot\URL\URL;
use WordPress\DataLiberation\EntityReader\FilesystemEntityReader;
use WordPress\DataLiberation\Importer\ImportSession;
use WordPress\DataLiberation\Importer\ImportUtils;
use WordPress\DataLiberation\Importer\RetryFrontloadingIterator;
use WordPress\DataLiberation\Importer\StreamImporter;
use WordPress\DataLiberation\URL\WPURL;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\HttpClient\Crawler;

use function WordPress\DataLiberation\URL\is_child_url_of;
use function WordPress\Filesystem\wp_join_paths;

if(file_exists('/wordpress/wp-load.php')) {
	require_once '/wordpress/wp-load.php';
}

if(file_exists(__DIR__ . '/../../vendor/autoload.php')) {
	require_once __DIR__ . '/../../vendor/autoload.php';
} else if(file_exists(__DIR__ . '/wp-content/vendor/autoload.php')) {
	require_once __DIR__ . '/wp-content/vendor/autoload.php';
}

require_once __DIR__ . '/Parser.php';
require_once __DIR__ . '/playground-protocol/PlaygroundProtocolClient.php';
require_once __DIR__ . '/ConsoleWriter.php';
require_once __DIR__ . '/ProgressBar.php';

$console_writer = new PlaygroundConsoleWriter();

/**
 * Custom autoloader that should not be needed because we already have
 * the vendor autoloader in place.
 * @TODO: Investigate why it's needed and get rid of it.
 */
spl_autoload_register(function ($class) use ($console_writer) {
    // Base directory for components
    $baseDir = WP_CONTENT_DIR . '/components/';
    
    // Convert namespace to path
    $path = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
	if(str_starts_with($path, 'WordPress/')) {
		$path = substr($path, 10);
	}
    
    // Full path to the file
    $file = $baseDir . $path;
    
    // Check if file exists and include it
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    
    return false;
});

// Parse CLI arguments
function help_message_and_die($error = false) {
	global $console_writer;
    $console_writer->write("\033[1;32mUsage:\033[0m php import-markdown-directory.php \033[1;33mmode\033[0m [options]\n");
    $console_writer->write("  options:\n");
    $console_writer->write("    \033[1;33mmode:\033[0m path|git|crawler\n");
	$console_writer->write("    \033[1;34m--source-site-url=<url>\033[0m (required)\n");
	$console_writer->write("    \033[1;34m--additional-site-urls=<url>\033[0m – rewrite all the detected links based on this URL (multiple values allowed)\n");
    $console_writer->write("    \033[1;34m--media-url=<url>\033[0m – media files from this URL will be downloaded during the import (multiple values allowed)\n");
	$console_writer->write("\n");
	$console_writer->write("  \033[1;33mcrawler\033[0m mode usage:\n");
	$console_writer->write("    php import-markdown-directory.php crawler \033[1;34m<url>\033[0m\n");
	$console_writer->write("\n");
    $console_writer->write("  \033[1;33mpath\033[0m mode usage:\n");
    $console_writer->write("    php import-markdown-directory.php path \033[1;34m<path to directory>\033[0m\n");
    $console_writer->write("\n");
    $console_writer->write("  \033[1;33mgit\033[0m mode usage:\n");
    $console_writer->write("    php import-markdown-directory.php mode \033[1;34m<repo_url>\033[0m\n");
	$console_writer->write("    options:\n");
	$console_writer->write("      \033[1;34m--branch=<branch name>\033[0m (required)\n");
	$console_writer->write("      \033[1;34m--path-in-repo=<path in repo>\033[0m (optional)\n");
	if($error) {
		$console_writer->write("\n");
		$console_writer->write("\033[1;31mError:\033[0m ");
		$console_writer->write($error);
		$console_writer->write("\n");
		PlaygroundProtocolClient::getInstance()->exit();
	}
	die();
}

define('NEW_SITE_CONTENT_ROOT', get_site_url());
$console_writer->write("Target site URL: " . NEW_SITE_CONTENT_ROOT . "\n");

$parser = new Phalcon\Cop\Parser();
$args = $parser->parse($argv);

$args['mode'] = $args[0] ?? '';
$args['data_url'] = $args[1] ?? '';

if($args['mode'] === 'path') {
	if (!isset($args['data_url'])) {
		help_message_and_die('The "path" argument is required.');
	}

	PlaygroundProtocolClient::getInstance()->mountDirectory($args['data_url'], '/files-to-import');
	$chrooted_fs = LocalFilesystem::create('/files-to-import');

	$args['source-site-url'] = 'file:///';
} else if ($args['mode'] === 'git') {
	if (!isset($args['data_url'])) {
		help_message_and_die('The "repo" argument is required.');
	}

	$args['repo'] = $args['data_url'];
    if (!str_ends_with($args['repo'], '.git')) {
        help_message_and_die('The "repo" argument must end with ".git" when mode is "git".');
    }

    if (!isset($args['branch'])) {
        help_message_and_die('The "branch" argument is required when mode is "git".');
    }

	$console_writer->write("Sparse checkout of the git repository\n");
	$temp_dir = sys_get_temp_dir() . '/import-static-' . uniqid();
	$cache_fs = LocalFilesystem::create($temp_dir);
	$docs_repo = new GitRepository($cache_fs);
	$docs_repo->add_remote('origin', $args['repo']);
	$remote = $docs_repo->get_remote_client('origin');
	$path_in_repo = $args['path-in-repo'] ?? '';
	$branch = $args['branch'] ?? 'trunk';
	$remote->fetch($branch, [
		'path' => $path_in_repo,
		'shallow' => true,
	]);
	$docs_repo->set_branch_tip('refs/heads/' . $branch, $docs_repo->get_branch_tip('refs/remotes/origin/' . $branch));
	$docs_repo->checkout('refs/heads/' . $branch);
	$git_fs = GitFilesystem::create($docs_repo);
	$chrooted_fs = new ChrootLayer($git_fs, $path_in_repo);
} else if ($args['mode'] === 'crawler') {
	if (!isset($args['data_url'])) {
		help_message_and_die('The "url" argument is required.');
	}
	if(!WPURL::parse($args['data_url'])) {
		help_message_and_die('The "url" argument must be a valid URL.');
	}
	$args['source-site-url'] = $args['data_url'];
	$tmp_dir = sys_get_temp_dir() . '/import-static-' . uniqid();
	$chrooted_fs = LocalFilesystem::create($tmp_dir);
	$crawler = new Crawler($args['data_url'], [
		'preprocess_url' => function(URL $url) use ($args) {
			if(!is_child_url_of($url, $args['data_url'])) {
				return false;
			}
			$url->search = '';
			if(in_array($url->pathname, ['/feed/', '/wp-json/'])) {
				return false;
			}
			if(preg_match('#^/\d{4}/\d{2}/\d{2}/[^/]+/$#', $url->pathname)) {
				return $url;
			}
			if(preg_match('#^/[^/]+/$#', $url->pathname)) {
				return $url;
			}
			return false;
		},
	]);
	$progress = new ProgressBar($console_writer, null);
	$progress->start('Crawling website...');
	while($crawler->crawl_next()) {
		$parsed_url = WPURL::parse($crawler->get_current_url());
		$file_path = $parsed_url->pathname;
		if($file_path === '/') {
			$file_path = '/index.html';
		} else if(str_ends_with($file_path, '/')) {
			/**
			 * Choose to treat /2021/10/03/dont-waste-time-on-boring-programming-lessons/ as
			 * /2021/10/03/dont-waste-time-on-boring-programming-lessons.html
			 * 
			 * Another possible choice would be to save it as
			 * /2021/10/03/dont-waste-time-on-boring-programming-lessons/index.html
			 */
			$file_path = rtrim($file_path, '/');
		}
		
		if(!$file_path || strlen($file_path) < 1) {
			$file_path = sha1($crawler->get_current_url());
		}

		$extension = pathinfo($file_path, PATHINFO_EXTENSION);
		if(!$extension) {
			$file_path .= '.html';
		}

		/**
		 * Replace date-based paths with "posts" directory.
		 *
		 * Why? wp_insert_post() seems to mangle the post_name if it consists of a few numbers
		 * and that messes up the URLs of the imported posts.
		 * 
		 * @TODO: Investigate the reasons of this behavior.
		 */
		$file_path = preg_replace('#/\d{4}/\d{2}/\d{2}/#', '/posts/', $file_path);
		$content = $crawler->get_current_content();
		// @TODO: This is very naive – we should use the URL processor instead.
		$content = preg_replace('#/\d{4}/\d{2}/\d{2}/#', '/posts/', $content);

		$chrooted_fs->mkdir(dirname($file_path), ['recursive' => true]);
		$chrooted_fs->put_contents(
			$file_path,
			$content
		);
		$progress->setMessage('Fetching '.$parsed_url->pathname);
		$progress->advance();
	}
	$progress->finish();
} else {
    help_message_and_die('The "mode" argument is required and must be either "path" or "git".');
	exit(1);
}

$index_file_pattern = '#(?:index|readme)\.(?:md|html|xhtml)$#i';
$import_path_prefix = '/imported-content';

/**
 * Maps a filesystem path to a WordPress-friendly URL path we can assign
 * to the imported page.
 * 
 * Example: "/docs/README.md" -> "/docs/readme"
 * 
 * @param string $path The filesystem path to convert
 * @return string The WordPress-friendly URL path
 */
function map_file_path_to_wordpress_url( $path ) {
	global $index_file_pattern, $import_path_prefix;

	/**
	 * Ensure a named top-level parent directory to base the entire
	 * URL structure on. The goal is to have a consistent way to resolve
	 * URLs for all the following files:
	 * 
	 * - README.md
	 * - chapter-5/README.md
	 * - chapter-5/section-1.md
	 * - chapter-5/section-3/readme.md
	 * 
	 * Without the top-level directory, the best URL we can give the
	 * /README.md file would be `/readme`. However, the `chapter-5/README.md`
	 * would get a URL like `/chapter-5` which is inconsistent. However,
	 * if we transform the path structure as follows, everything becomes
	 * consistent:
	 *
	 * - /imported-content/README.md
	 * - /imported-content/chapter-5/README.md
	 * - /imported-content/chapter-5/section-1.md
	 * - /imported-content/chapter-5/section-3/readme.md
	 *
	 * We want to keep all the links working after the import. A single,
	 * consistent URL mapping strategy makes it much easier. The alternative
	 * would be to maintain a mapping of parents to paths and use it whenever
	 * creating pages and rewriting URLs.
	 * 
	 * This isn't trivial. Having a top-level path prefix is not perfect,
	 * but it's a sound compromise.
	 */
	$path = wp_join_paths($import_path_prefix, $path);

	if(1 === preg_match($index_file_pattern, $path)) {
		$path = dirname($path);
	}

	$extensions = array('.md', '.html', '.xhtml');
	foreach ($extensions as $ext) {
		if (str_ends_with($path, $ext)) {
			$path = substr($path, 0, -strlen($ext));
			break;
		}
	}

	return strtolower($path);
}

/**
 * Transforms links pointing to imported static files (e.g. ./getting-started.md)
 * to the format they will have after being imported into WordPress (e.g. /docs/getting-started).
 */
add_action(
	'data_liberation.stream_importer.postprocess_url',
	function ( $processor, $context ) use (
		$chrooted_fs,
		/**
		 * With &, $import_path_prefix reflects the latest value.
		 * Without &, it's a local copy of the value from the outer scope.
		 */
		&$import_path_prefix
	) {
		/**
		 * If we didn't rewrite the base URL, the URL points outside
		 * of the imported root directory. Let's keep it as it is.
		 */
		if(!$context['applied_base_url_mapping']) {
			return;
		}

		$path_original = $processor->get_parsed_url()->pathname;

		/**
		 * Remove the site path from the URL path and check:
		 * Is this URL pointing to a file that exists in the imported
		 * directory?
		 */
		$base_url_path_prefix = $context['applied_base_url_mapping']['to']->pathname;
		$path_relative_to_base = substr($path_original, strlen($base_url_path_prefix));
		if($chrooted_fs->is_file($path_relative_to_base)) {
			/**
			 * Yes! We are linking to an imported page. Let's transform the link
			 * to a WordPress-friendly URL scheme.
			 */
			$path_rewritten = map_file_path_to_wordpress_url($path_relative_to_base);
			$path_rewritten = wp_join_paths($base_url_path_prefix, $path_rewritten);
		} else if($processor->is_url_absolute()) {
			/**
			 * No. We are linking to a content page within our site but there is
			 * no corresponding static file. This happens e.g. in the Gutenberg
			 * handbook where the markdown files contain absolute URLs to the deployed
			 * site, e.g.:
			 * 
			 *     Start by ensuring you have Node.js and `npm` installed on your computer. Review
			 *     the [Node.js development environment](https://developer.wordpress.org/block-editor/getting-started/devenv/nodejs-development-environment/) guide if not.
			 *
			 * Our best shot is to keep the URL as is, just with the imported
			 * content root prepended to it.
			 */
			$path_rewritten = wp_join_paths($base_url_path_prefix, $import_path_prefix, $path_relative_to_base);
		} else {
			/**
			 * It's a relative URL pointing somewhere within the URL space we're importing
			 * to, but there is no corresponding static file. This is unexpected. There is
			 * nothing we can do at this point – let's just keep the URL as it is.
			 */
			return;
		}
		$processor->set_url(
			$path_rewritten,
			WPURL::parse($path_rewritten, $processor->get_parsed_url())
		);
	},
	10,
	3
);

/**
 * Assigns post_name to every imported static page.
 */
add_filter(
	'data_liberation.stream_importer.preprocess_entity',
	function ( $entity ) use ( &$import_path_prefix, $index_file_pattern ) {
		static $preprocessed_an_entity = false;
		if($entity->get_type() !== 'post') {
			return $entity;
		}

		$data = $entity->get_data();

		if(isset($data['parsed_metadata']['slug'])) {
			$data['post_name'] = basename($data['parsed_metadata']['slug'][0]);
		} else if(isset($data['local_file_path'])) {
			/**
			 * The default import content path is "/imported-content". However,
			 * maybe we can find a friendlier path prefix based on the post
			 * title of the top-level index file.
			 * 
			 * For example, a "Getting Started" guide found at "README.md"
			 * could be imported to "/getting-started".
			 */
			if(!$preprocessed_an_entity) {
				$preprocessed_an_entity = true;
				$dirname = dirname($data['local_file_path']);
				$dirname_makes_a_bad_slug = $dirname !== '.' && $dirname === '/';
				$is_index_file = 1 === preg_match($index_file_pattern, $data['local_file_path']);
				$post_title_not_derived_from_path = $data['post_title'] !== ImportUtils::slug_to_title( basename( $data['local_file_path'] ) );

				if(
					$dirname_makes_a_bad_slug &&
					$is_index_file &&
					$post_title_not_derived_from_path &&
					strlen($data['post_title']) > 1
				) {
					$import_path_prefix = wp_import_slugify($data['post_title']);
				}
			}
	
			$wordpress_url = map_file_path_to_wordpress_url($data['local_file_path']);
			$data['post_name'] = basename($wordpress_url);
		} else {
			return $entity;
		}

		$entity->set_data($data);
		return $entity;
	},
	10,
	2
);

/**
 * Naive slugification function.
 * @TODO: Use a more sophisticated one with utf-8 support etc.
 */
function wp_import_slugify( $title ) {
	return preg_replace( '/[^a-z0-9]+/i', '-', trim( strtolower( $title ) ) );
}

$data_url = $args['data_url'];
$console_writer->write("Importing static files from $data_url\n");

// Validate required arguments
if (!isset($args['source-site-url'])) {
    help_message_and_die('The --source-site-url argument is required.');
}

try {
	// Parse URL mapping arguments
	$source_site_url = $args['source-site-url'];
	$additional_url_mappings = [];
	foreach ($parser->getArray('additional-site-urls') as $url) {
		$additional_url_mappings[] = [
			'from' => $url,
			'to' => NEW_SITE_CONTENT_ROOT,
		];
	}

	$console_writer->write("Starting the import\n");
	$importer = StreamImporter::create(
		function () use ( $chrooted_fs, $source_site_url, $index_file_pattern ) {
			return new FilesystemEntityReader(
				$chrooted_fs,
				[
					'index_file_pattern' => $index_file_pattern,
					'filter_pattern' => '#\.(?:md|html|xhtml)$#',
					/**
					 * Use a number so large, there's no chance for wp_table INSERTs
					 * to interfere with the post IDs generated by the FilesystemEntityReader.
					 * 
					 * Some inserts are ran even by the importer, e.g. frontloading stubs.
					 *
					 * @TODO: Make sure this doesn't automatically bump the AUTOINCREMENT counter in MySQL.
					 * @TODO: Bump the AUTOINCREMENT counter manually after a finished import.
					 */
					'first_post_id' => 10000000,
					'base_url' => $source_site_url,
				]
			);
		}, [
			'source_site_url' => $source_site_url,
			'new_site_content_root_url' => NEW_SITE_CONTENT_ROOT,
			'source_media_root_urls' => $parser->getArray('media-url') ?: [$source_site_url],
			'additional_url_mappings' => $additional_url_mappings,
			'index_batch_size' => 1,
			'attachment_downloader_options' => [
				'source_from_filesystem' => $chrooted_fs
			]
		]
	);

	$import_session = ImportSession::create(
		array(
			'data_source' => 'local_directory',
			// @TODO: the phrase "file_name" doesn't make sense here. We're sourcing
			//        data from a directory, not a file. This string is used to tell
			//        the user in the UI what this they're importing in this import
			//        session. Let's rename it to something more descriptive.
			'file_name' => $args['data_url'],
		)
	);
	$retries_iterator = new RetryFrontloadingIterator( $import_session->get_id() );
	$importer->set_frontloading_retries_iterator( $retries_iterator );

	// @TODO: Prettier progress reporting
	$ignored_message_printed = false;
	do {
		$result = data_liberation_import_step_customized( $import_session, $importer, $console_writer );
		if($importer->get_stage() === StreamImporter::STAGE_FINISHED) {
			$console_writer->write("\n");
			$console_writer->write("\033[1;32mImport finished!\033[0m See your imported content at: \n");

			// Get the first page with non-empty content.
			$posts = get_posts([
				'numberposts' => 10,
				'orderby' => 'ID',
				'order' => 'ASC',
				'post_type' => 'page',
				'post_status' => 'publish'
			]);
			
			$url = NEW_SITE_CONTENT_ROOT;
			foreach ($posts as $post) {
				if (!empty($post->post_content)) {
					$url = get_permalink($post);
					break;
				}
			}
			$console_writer->write("\033[1;36m" . $url . "\033[0m\n");
			break;
		} else if(false === $result) {
			if($importer->get_stage() === StreamImporter::STAGE_FRONTLOAD_ASSETS) {
				if(!$ignored_message_printed) {
					$console_writer->write("\nSome assets could not be downloaded – they will be ignored so we can continue with the import.\n");
					$ignored_message_printed = true;
				}
				// $import_session->mark_frontloading_errors_as_ignored();
			} else {
				$console_writer->write("Import failed, aborting\n");
				break;
			}
		} else {
			// Twiddle our thumbs, importing in progress...
		}
	} while(true);
} finally {
	if(isset($cache_fs)) {
		$cache_fs->rmdir( '/', [
			'recursive' => true,
		] );
	}
}

/**
 * @TODO: Expose a primitive like the step function below from the
 *        DataLiberation PHP component. Support all sorts of pause conditions
 *        such as time limits, retry counts, memory limits, etc.
 */
function data_liberation_import_step_customized( ImportSession $session, StreamImporter $importer, ConsoleWriter $console_writer ) {
    $soft_time_limit_seconds = 15;
    $hard_time_limit_seconds = 25;
    $start_time = microtime( true );
    $fetched_files = 0;
	$progress_bar = null;
    
    while ( true ) {
        $time_taken = microtime( true ) - $start_time;
        if ( $time_taken >= $soft_time_limit_seconds ) {
            if ( $importer->get_stage() === StreamImporter::STAGE_FRONTLOAD_ASSETS ) {
                if ( $fetched_files > 0 ) {
                    return true;
                }
            } else {
                return true;
            }
        }
        if ( $time_taken >= $hard_time_limit_seconds ) {
            return true;
        }

        if ( true !== $importer->next_step() ) {
            $session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );

            $should_advance_to_next_stage = null !== $importer->get_next_stage();
            if ( $should_advance_to_next_stage ) {
                if ( StreamImporter::STAGE_FRONTLOAD_ASSETS === $importer->get_stage() ) {
                    $resolved_all_failures = $session->count_unfinished_frontloading_stubs() === 0;
                    if ( ! $resolved_all_failures ) {
						// Uncomment once this script's intent becomes exiting on unresolved frontloading failures.
                        // if($progress_bar) {
						// 	$progress_bar->finish();
						// }
                        // return false;
                    }
                }
            }
            if ( ! $importer->advance_to_next_stage() ) {
                if($progress_bar) {
					$progress_bar->finish();
                }
                return false;
            }
            $session->set_stage( $importer->get_stage() );
            $session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );
            $console_writer->clearLine();
			$progress_bar = null;

            continue;
        }

        switch ( $importer->get_stage() ) {
            case StreamImporter::STAGE_INDEX_ENTITIES:
                $entities_counts = $importer->get_indexed_entities_counts();
                $session->create_frontloading_stubs( $importer->get_indexed_assets_urls() );
                $session->bump_total_number_of_entities($entities_counts);
				if(!$progress_bar) {
					$progress_bar = new ProgressBar($console_writer, null);
					$progress_bar->setMessage("Indexing entities");
					$progress_bar->start();
				}
                $progress_bar->setCurrent(array_sum($session->get_total_number_of_entities()));
                break;
                
            case StreamImporter::STAGE_FRONTLOAD_ASSETS:
                $progress = $importer->get_frontloading_progress();
                $session->bump_frontloading_progress(
                    $progress,
                    $importer->get_frontloading_events()
                );

				if(!$progress_bar) {
					$progress_bar = new ProgressBar($console_writer, null);
					$progress_bar->setMessage("Fetching media files");
					$progress_bar->start();
				}
                $progress_bar->setCurrent($session->count_unfinished_frontloading_stubs());
                break;
                
            case StreamImporter::STAGE_IMPORT_ENTITIES:
                $imported_counts = $importer->get_imported_entities_counts();
                
                $session->bump_imported_entities_counts($imported_counts);

				if(!$progress_bar) {
					$progress_bar = new ProgressBar($console_writer, $session->count_remaining_entities());
					$progress_bar->setMessage("Importing entities");
					$progress_bar->start();
				}
                $progress_bar->setCurrent($session->count_all_imported_entities());
                break;
        }

        $session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );
    }
    return false;
}
