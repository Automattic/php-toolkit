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

const { requestHandler } = await runCLI({
	command: 'server',
	port: 9400,
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
				step: 'installPlugin',
				pluginData: {
					resource: 'literal',
					name: 'data-liberation.zip',
					contents: readFile('./data-liberation.zip'),
				},
			},
			{
				step: 'resetData',
			},
			{
				step: 'writeFiles',
				writeToPath: '/wordpress',
				filesTree: {
					resource: 'literal:directory',
					name: 'wordpress',
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

		require_once '/wordpress/import-markdown-directory.php';
		
		?>`,
		env: {
			JS_ARGV: JSON.stringify(process.argv.slice(1)),
		},
	});
} catch (error) {
	console.log('Error running the import script');
	if ('response' in error) {
		console.log(error.response.text);
		console.log(error.response.errors);
	} else {
		console.error(error);
	}

	process.exit(1);
}
