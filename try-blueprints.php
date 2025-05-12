<?php

/**
 * @TODO: Fast unzipping of remote Zip Files by iterating over the entries
 *        instead of skipping over to the end central directory index entry.
 * @TODO: Processing Zip Files without the Content-Length header.
 * @TODO: HTTP Cache support for remote files.
 * @TODO: Restrictions on supported step types, media files types, SQL queries types, etc.
 * @TODO: Add importMedia step to the specification.
 * @TODO: How to handle the default WordPress theme? Should it be preserved for new sites?
 *        What if we want to remove it? And what should be the semantics for existing sites?
 */

namespace WordPress\Blueprints\Steps;

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
	->setDatabaseEngine('sqlite')
    ->setExecutionMode('create-new-site')
    // ->setExecutionMode('apply-to-existing-site')
    ->setTargetSiteRoot(__DIR__ . '/my-new-site')
    ->setTargetSiteUrl('http://127.0.0.1:2456')
;

(new Runner($config))->run();
