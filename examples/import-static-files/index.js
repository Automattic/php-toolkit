#!/usr/bin/env node

import { runCLI } from '@wp-playground/cli';
import path from 'path';
import { fileURLToPath } from 'url';
import yargs from 'yargs/yargs';
import { hideBin } from 'yargs/helpers';
import fs from 'fs';
import { createPlaygroundProtocolHandler } from './playground-protocol/playground-protocol-handler.js';

const readFile = (relativePath, encoding) => {
	return fs.readFileSync(
		path.join(import.meta.dirname, relativePath),
		encoding
	);
};

// Production
// const { requestHandler } = await runCLI({
// 	command: 'server',
// 	port: 9400,
// 	blueprint: {
// 		$schema: 'https://playground.wordpress.net/blueprint-schema.json',
// 		login: true,
// 		landingPage: '/wp-admin/edit.php?post_type=local_file',
// 		constants: {
// 			WP_DEBUG: true,
// 			WP_DEBUG_LOG: true,
// 			WP_DEBUG_DISPLAY: true,
// 		},
// 		steps: [
// 			{
// 				step: 'installPlugin',
// 				pluginData: {
// 					resource: 'literal',
// 					name: 'data-liberation.zip',
// 					contents: readFile('./data-liberation.zip'),
// 				},
// 				options: {
// 					activate: true,
// 				},
// 			},
// 			{
// 				step: 'resetData',
// 			},
// 			{
// 				step: 'writeFiles',
// 				writeToPath: '/wordpress',
// 				filesTree: {
// 					resource: 'literal:directory',
// 					name: 'wordpress',
// 					files: {
// 						'Parser.php': readFile('./Parser.php'),
// 						'import-markdown-directory.php': readFile(
// 							'./import-markdown-directory.php'
// 						),
// 						'playground-protocol/PlaygroundProtocolClient.php':
// 							readFile(
// 								'./playground-protocol/PlaygroundProtocolClient.php'
// 							),
// 						'ConsoleWriter.php': readFile('./ConsoleWriter.php'),
// 						'ProgressBar.php': readFile('./ProgressBar.php'),
// 					},
// 				},
// 			},
// 		],
// 	},
// });

// Development:
const { requestHandler } = await runCLI({
	command: 'server',
	port: 9400,
	mount: [
		`${path.join(import.meta.dirname, '../../components')}:/wordpress/wp-content/components`,
		`${path.join(import.meta.dirname, '../../vendor')}:/wordpress/wp-content/vendor`,
		`${path.join(import.meta.dirname, '../../plugins/data-liberation')}:/wordpress/wp-content/plugins/data-liberation`
	],
	blueprint: {
		$schema: 'https://playground.wordpress.net/blueprint-schema.json',
		login: true,
		landingPage: '/wp-admin/edit.php?post_type=local_file',
		constants: {
			WP_DEBUG: true,
			WP_DEBUG_LOG: true,
			WP_DEBUG_DISPLAY: true,
		},
		steps: [
			{
				step: 'activatePlugin',
				pluginPath: 'data-liberation/plugin.php'
			},
			{
				step: 'resetData',
			},
			{
				step: 'runPHP',
				code: `<?php
				require_once '/wordpress/wp-load.php';
				// Do **not** use the trailing slash. It would require
				// a more involved URL rewriting logic. Right now, we
				// can assume that relative URLs between all static files
				// in the same directory should retain the same structure.
				// 
				// Imagine the following directory structure:
				// /directory
				//   index.md
				//   subpage.md
				//
				// If the permalink structure is /%postname%, we can reflect
				// the directory structure in the URLs and have two pages:
				// /index and /subpage. Any relative links between the two
				// files will work as expected.
				//
				// However, if the permalink structure is /%postname%/,
				// we end up with /index/ and /subpage/. A relative link
				// from index.md to "./subpage.md" would now point to
				// /index/subpage/ instead of /subpage/.
				//
				// Note we cannot simply structure the new pages as
				// /index/ and /index/subpage/ because the inverse is also true:
				// a link from subpage.md to "./index.md" would point to
				// /index/subpage/index/ instead of /index/.
				//
				// This conundrum could be resolved with an involved URL
				// resolver that is aware of the original URL structure,
				// the target URL structure, the URL of each post, and would
				// apply a transformation to all the URLs. This is not an
				// easy problem to solve – we would need to infer facts about
				// the URL structure of files that haven't been imported yet.
				//
				// It is much easier to just drop the trailing slash, so that's
				// what we're going to do.
				update_option('permalink_structure', '/%postname%');
				flush_rewrite_rules();
				?>`,
			},
			{
				step: 'writeFiles',
				writeToPath: '/wordpress/wp-content/plugins/static-files-importer',
				filesTree: {
					resource: 'literal:directory',
					name: 'static-files-importer',
					files: {
						'Parser.php': readFile('./Parser.php'),
						'import-markdown-directory.php': readFile(
							'./import-markdown-directory.php'
						),
						'playground-protocol/PlaygroundProtocolClient.php':
							readFile(
								'./playground-protocol/PlaygroundProtocolClient.php'
							),
						'ConsoleWriter.php': readFile('./ConsoleWriter.php'),
						'ProgressBar.php': readFile('./ProgressBar.php'),
					},
				},
			},
		],
	},
});

const php = await requestHandler.getPrimaryPhp();
// @TODO: Explore running the Blueprint from the PHP script after validating the CLI args
php.onMessage(createPlaygroundProtocolHandler(php));

try {
	const result = await php.run({
		code: `<?php 
		/**
		 * Workaround to pass the CLI args from Node.js to the PHP script.
		 * 
		 * @TODO: Support passing $argv to the script at the platform level
		 */
		$argv = json_decode(getenv('JS_ARGV'), true);

		require_once '/wordpress/wp-content/plugins/static-files-importer/import-markdown-directory.php';
		
		?>`,
		env: {
			JS_ARGV: JSON.stringify(process.argv.slice(1)),
		},
	});
} catch (error) {
	// @TODO: remove silencing asyncify errors
	if ((error + '').includes('Unreachable code should not be executed')) {
		process.exit(0);
	}

	console.log('Error running the import script');
	if ('response' in error) {
		console.log(error.response.text);
		console.log(error.response.errors);
	} else {
		console.error(error);
	}

	process.exit(1);
}
