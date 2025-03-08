<?php

use WordPress\DataLiberation\EntityReader\FilesystemEntityReader;
use WordPress\DataLiberation\Importer\ImportSession;
use WordPress\DataLiberation\Importer\RetryFrontloadingIterator;
use WordPress\DataLiberation\Importer\StreamImporter;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;

if(file_exists('/wordpress/wp-load.php')) {
	require_once '/wordpress/wp-load.php';
}

if(file_exists(__DIR__ . '/../../vendor/autoload.php')) {
	require_once __DIR__ . '/../../vendor/autoload.php';
}

// Parse CLI arguments

function help_message_and_die($error = false) {
    echo "\033[1;32mUsage:\033[0m php import-markdown-directory.php \033[1;33mmode\033[0m [options]\n";
    echo "  \033[1;33mmode:\033[0m path|git\n";
    echo "\n";
    echo "  \033[1;33mpath\033[0m mode usage:\n";
    echo "    php import-markdown-directory.php path \033[1;34m<path to directory>\033[0m\n";
    echo "\n";
    echo "  \033[1;33mgit\033[0m mode usage:\n";
    echo "    php import-markdown-directory.php mode \033[1;34m<repo_url>\033[0m\n";
	echo "    options:\n";
	echo "      \033[1;34m--branch=<branch name>\033[0m (required)\n";
	echo "      \033[1;34m--pathInRepo=<path in repo>\033[0m (optional)\n";
	if($error) {
		echo "\n";
		echo "\033[1;31mError:\033[0m ";
		echo $error;
		echo "\n";
		exit(1);
	}
	die();
}

require_once __DIR__ . '/Parser.php';
require_once __DIR__ . '/playground-protocol/PlaygroundProtocolClient.php';
require_once __DIR__ . '/ConsoleWriter.php';
require_once __DIR__ . '/ProgressBar.php';

define('IMPORT_ROOT_SLUG', '/imported_content/');

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

	$temp_dir = sys_get_temp_dir() . '/import-static-' . uniqid();
	$cache_fs = LocalFilesystem::create($temp_dir);
	$docs_repo = new GitRepository($cache_fs);
	$docs_repo->add_remote('origin', $args['repo']);
	$remote = $docs_repo->get_remote_client('origin');
	$remote->fetch('trunk', [
		'path' => $args['pathInRepo'],
		'shallow' => true,
	]);
	$docs_repo->set_branch_tip('refs/heads/' . $args['branch'], $docs_repo->get_branch_tip('refs/remotes/origin/' . $args['branch']));
	$git_fs = GitFilesystem::create($docs_repo);
	$chrooted_fs = new ChrootLayer($git_fs, $args['pathInRepo']);
} else {
    help_message_and_die('The "mode" argument is required and must be either "path" or "git".');
	exit(1);
}

// Do the work

add_action(
	'data_liberation.rewrite_url',
	function ( $processor, $entity, $importer ) {
		var_dump($processor->get_raw_url());
		die();
	},
	10,
	3
);

$console_writer = new PlaygroundConsoleWriter();
$data_url = $args['data_url'];
$console_writer->write("Importing static files from $data_url...\n");

try {
	$importer = StreamImporter::create(
		function () use ( $chrooted_fs ) {
			return new FilesystemEntityReader(
				$chrooted_fs,
				[
					'first_post_id' => 2
				]
			);
		}
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
	do {
		$result = data_liberation_import_step_customized( $import_session, $importer, $console_writer );
		if($importer->get_stage() === StreamImporter::STAGE_FINISHED) {
			$console_writer->write("\n");
			$console_writer->write("\033[1;32mImport finished!\033[0m Visit your site at: \n");
			$console_writer->write("\033[1;36m" . get_site_url() . IMPORT_ROOT_SLUG . "\033[0m\n");
			break;
		} else if(false === $result) {
			$console_writer->write("Failed\n");
			break;
		} else {
			// Twiddle our thumbs...
			$console_writer->write("Resource quota exhausted. Paused at stage: " . $import_session->get_stage() . "\n");
		}
	} while(true);
} finally {
	if(isset($cache_fs)) {
		$cache_fs->rmdir( '/', [
			'recursive' => true,
		] );
	}
}

wp_update_post(array(
	'ID' => 2,
	'post_title' => 'Imported content',
	'post_name' => IMPORT_ROOT_SLUG,
	'post_content' => '
<!-- wp:paragraph -->
<p>This page is the root of all your imported files. To see the imported tree structure, go to wp-admin or check the main menu above.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>For quick access to the imported pages, see this flat list:</p>
<!-- /wp:paragraph -->

<!-- wp:query {"queryId":1,"query":{"perPage":10,"pages":0,"offset":0,"postType":"page","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-title {"isLink":true} /-->
<!-- wp:post-excerpt /-->
<!-- /wp:post-template -->

<!-- wp:query-pagination -->
<!-- wp:query-pagination-previous /-->
<!-- wp:query-pagination-numbers /-->
<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination --></div>
<!-- /wp:query -->',
));

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
                    $resolved_all_failures = $session->count_unfinished_frontloading_placeholders() === 0;
                    if ( ! $resolved_all_failures ) {
                        if($progress_bar) {
							$progress_bar->finish();
						}
                        return false;
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
                $session->create_frontloading_placeholders( $importer->get_indexed_assets_urls() );
                $session->bump_total_number_of_entities($entities_counts);
                
				if(!$progress_bar) {
					$progress_bar = new ProgressBar($console_writer, null);
					$progress_bar->setMessage("Indexing entities");
					$progress_bar->start();
				}
                $progress_bar->setCurrent(array_sum($entities_counts));
                break;
                
            case StreamImporter::STAGE_FRONTLOAD_ASSETS:
                $progress = $importer->get_frontloading_progress();                
                $session->bump_frontloading_progress(
                    $progress,
                    $importer->get_frontloading_events()
                );

				if(!$progress_bar) {
					$progress_bar = new ProgressBar($console_writer, null);
					$progress_bar->setMessage("Loading assets");
					$progress_bar->start();
				}
                $progress_bar->setCurrent($session->count_unfinished_frontloading_placeholders());
                break;
                
            case StreamImporter::STAGE_IMPORT_ENTITIES:
                $imported_counts = $importer->get_imported_entities_counts();
                $total_imported = array_sum($imported_counts);
                
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