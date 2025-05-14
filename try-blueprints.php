<?php

/**
 * @TODO: A large test suite.
 * @TODO: Client HTTP queue deadlock when we enqueued a lot of requests and need to fetch a small
 *        ad-hoc resource such as a JSON list of translations.
 * @TODO: Blueprint JSON validation.
 * @TODO [_spec_]: Add importMedia step to the specification.
 * @TODO [_spec_]: How to handle the default WordPress theme? Should it be preserved for new sites?
 *        What if we want to remove it? And what should be the semantics for existing sites?
 * @TODO (low priority): Production-grade HTTP Cache support for remote files. Not the stopgap we have now.
 *                       We can ship Blueprints without http cache support, but do not ship the stopgap solution 
 *                       in production.
 * @TODO (low priority): Exception structure?
 * @TODO (low priority): Range header-based HTTP stream for fast partial parsing of large remote zip files.
 *                       Needs to support servers lying about their Range support.
 * @TODO (low priority): Restrictions on supported step types, media files types, SQL queries types, etc.
 * @TODO (low priority): Fast unzipping of remote Zip Files by iterating over the entries
 *        instead of skipping over to the end central directory index entry.
 * ✅ @TODO: Support chunked encoding Zip Files without the Content-Length header.
 */
  
namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\ProgressObserver;
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;

// Silence PHP deprecation warnings
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Initialize runtime for the given document root
require_once __DIR__ . '/vendor/autoload.php';

$config = (new RunnerConfiguration())
    ->setBlueprint([
		"version" => 2,
		'$schema' => "https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/schema.json",
		"plugins" => [
			"friends"
		],
		"themes" => [
			"adventurer"
		],
		"wordpressVersion" => "6.5",
		"phpVersion" => [
			"min" => "8.0",
			"max" => "8.4",
			"recommended" => "8.2"
		],
		"activeTheme" => "twentytwentyfour",
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
		// "muPlugins" => [
		// 	"0-test" => [
		// 		"filename" => "0-test.php",
		// 		"content" => "<?php
		// 			echo 'test';
		// 		? >"
		// 	]
		// ],
		"users" => [
			[
				"username" => "admin",
				"password" => "password",
				"email" => "adam@example.com",
				"role" => "adamadamin"
			]
		],
		"roles" => [
			[
				"name" => "adamadamin",
				// @TODO: What's the correct way to set capabilities?
				"capabilities" => ["manage_options"=>"manage_options"]
			]
		],
		"siteOptions" => [
			"blogname" => "Blueprint Demo Site",
			"timezone_string" => "America/New_York",
			"permalink_structure" => "/%year%/%monthnum%/%postname%/"
		],
		"siteLanguage" => "en_US",
		"constants" => [
			"WP_DEBUG" => true,
			"SCRIPT_DEBUG" => true
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
					"0_readme.md" => "https://gist.githubusercontent.com/adamziel/a93297e21f37612751a2904c193d44fa/raw/5f25cdc900c0a44aefa0e1c06352c09c67312f1e/0_README.md",
					"playground" => [
						"gitRepository" => "https://github.com/adamziel/mysql-sqlite-network-proxy.git",
						"path" => "php-implementation",
						// @TODO: Accept branch names without the refs/heads/ prefix
						"ref" => "refs/heads/trunk"
					]
				]
			]
		]
	])
	// TODO: What is the default database engine?
	->setDatabaseEngine('sqlite')
	// TODO: Neat!
    ->setExecutionMode('create-new-site') // or 'apply-to-existing-site'
    ->setTargetSiteRoot(__DIR__ . '/my-new-site')
    ->setTargetSiteUrl('http://127.0.0.1:2456') // Arbitrary URL for the new site
	->setProgressObserver(new ProgressObserver(function ($progress, $caption) {
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
	}));
;

(new Runner($config))->run();
