(function () {
	setupCopyButtons();
	setupTerminal();

	function setupCopyButtons() {
		var copyButtons = document.querySelectorAll( '[data-copy-target]' );
		copyButtons.forEach(
			function ( button ) {
				button.addEventListener(
					'click',
					function () {
						var target = document.getElementById( button.getAttribute( 'data-copy-target' ) );
						if ( ! target ) {
							return;
						}

						copyText( target.textContent ).then(
							function () {
								markCopied( button );
							}
						);
					}
				);
			}
		);
	}

	function setupTerminal() {
		var outputEl       = document.getElementById( 'landing-terminal-output' );
		var inputEl        = document.getElementById( 'landing-terminal-input' );
		var inputGhostEl   = document.getElementById( 'landing-terminal-input-ghost' );
		var inputRowEl     = inputEl ? inputEl.closest( '.terminal-input-row' ) : null;
		var cwdEl          = document.getElementById( 'landing-terminal-cwd' );
		var titleEl        = document.getElementById( 'landing-terminal-title' );
		var checkoutDir    = 'site';
		var remoteUrl      = 'https://example.com/wp-json/git/v1/md.git';
		var cwd            = '~';
		var history        = [];
		var historyIndex   = 0;
		var dirtyPath      = '';
		var aheadMessage   = '';
		var revisionNumber = 184;
		var diffRemoveLine = 'Old CTA copy';
		var diffAddLine    = 'Keep WordPress in charge';
		var files          = {
			'AGENTS.md': '# WordPress content guidelines\n\n- Keep block markup valid.\n- Pull before editing stale content.\n- Let WordPress roles decide who can publish.\n',
			'page/home.md': '# Home\n\nOld CTA copy\n',
			'post/hello-world.md': '# Hello World\n\nHello from WordPress.\n',
			'wp_template_part/header.html': '<!-- wp:site-title /-->\n<!-- wp:navigation /-->\n',
			'wp_global_styles/theme.json': '{\n  "version": 3,\n  "styles": {\n    "color": {\n      "background": "#faf8f1"\n    }\n  }\n}\n'
		};
		var pendingFiles   = {};

		if ( ! outputEl || ! inputEl || ! inputGhostEl || ! inputRowEl || ! cwdEl || ! titleEl ) {
			return;
		}

		function appendLine( text, className ) {
			var line       = document.createElement( 'div' );
			line.className = 'terminal-line';
			if ( className ) {
				line.className += ' ' + className;
			}
			line.textContent = text === undefined ? '' : String( text );
			outputEl.appendChild( line );
			outputEl.scrollTop = outputEl.scrollHeight;
		}

		function appendPrompt( command ) {
			appendLine( 'emulator:' + displayCwd() + '$ ' + command, 'terminal-accent' );
		}

		function displayCwd() {
			if ( cwd === '~' ) {
				return '~';
			}
			return cwd === '/' ? '~/' + checkoutDir : '~/' + checkoutDir + cwd;
		}

		function updatePrompt() {
			cwdEl.textContent   = displayCwd();
			titleEl.textContent = 'emulator:' + displayCwd();
		}

		function updateInputGhost() {
			var textEl   = document.createElement( 'span' );
			var cursorEl = document.createElement( 'span' );
			var value    = inputEl.value;
			var focused  = inputRowEl.classList.contains( 'is-focused' );

			inputGhostEl.textContent = '';
			cursorEl.className       = 'terminal-cursor';

			if ( value ) {
				textEl.textContent = value;
				inputGhostEl.appendChild( textEl );
				inputGhostEl.appendChild( cursorEl );
			} else if ( focused ) {
				inputGhostEl.appendChild( cursorEl );
			} else {
				textEl.className   = 'terminal-placeholder';
				textEl.textContent = inputEl.getAttribute( 'placeholder' ) || '';
				inputGhostEl.appendChild( cursorEl );
				inputGhostEl.appendChild( textEl );
			}
		}

		function bootTranscript() {
			outputEl.textContent = '';
			appendLine( 'Push MD command emulator', 'terminal-success' );
			appendLine( 'This public demo mirrors commands you can run with any Git client.', 'terminal-muted' );
			appendLine( '' );
			runCommand( 'git clone ' + remoteUrl + ' ' + checkoutDir, { silentHistory: true } );
			runCommand( 'cd ' + checkoutDir, { silentHistory: true } );
			runCommand( 'codex "Update page/home.md"', { silentHistory: true } );
			runCommand( 'git diff -- page/home.md', { silentHistory: true } );
			runCommand( 'git commit -am "Update home page"', { silentHistory: true } );
			runCommand( 'git push origin trunk', { silentHistory: true } );
			appendLine( '' );
			appendLine( 'Try: git status, git pull, ls, cat AGENTS.md, codex "Update page/home.md", help', 'terminal-muted' );
		}

		function runCommand( command, options ) {
			var parts;
			var base;

			options = options || {};
			command = String( command || '' ).replace( /^\s+|\s+$/g, '' );
			if ( ! command ) {
				return;
			}

			appendPrompt( command );
			if ( ! options.silentHistory ) {
				history.push( command );
				historyIndex = history.length;
			}

			parts = command.split( /\s+/ );
			base  = parts[0];

			if ( base === 'help' ) {
				printHelp();
			} else if ( base === 'clear' ) {
				outputEl.textContent = '';
			} else if ( base === 'pwd' ) {
				appendLine( '/' + checkoutDir + ( cwd === '/' || cwd === '~' ? '' : cwd ) );
			} else if ( base === 'ls' ) {
				printLs( parts.slice( 1 ).join( ' ' ) || '.' );
			} else if ( base === 'tree' ) {
				printTree();
			} else if ( base === 'cat' ) {
				printCat( parts.slice( 1 ).join( ' ' ) );
			} else if ( base === 'cd' ) {
				changeDirectory( parts[1] || checkoutDir );
			} else if ( base === 'status' ) {
				printGit( [ 'status' ] );
			} else if ( base === 'git' ) {
				printGit( parts.slice( 1 ), command );
			} else if ( base === 'codex' || base === 'agent' ) {
				runAgentEdit();
			} else {
				appendLine( base + ': command not found. Try `help`.', 'terminal-error' );
			}
		}

		function printHelp() {
			appendLine( 'Emulated commands:', 'terminal-success' );
			appendLine( '  ls, tree, cat <file>       inspect the WordPress checkout' );
			appendLine( '  git status, diff, pull     see Git state through WordPress' );
			appendLine( '  git commit -m "..."        record a local Markdown edit' );
			appendLine( '  git push origin trunk      ask WordPress to accept the change' );
			appendLine( '  codex "Update page/home.md" create a demo edit' );
			appendLine( '  clear                      clear the terminal' );
		}

		function runAgentEdit() {
			dirtyPath = 'page/home.md';
			if ( files[dirtyPath].indexOf( 'Keep WordPress in charge' ) !== -1 ) {
				diffRemoveLine          = 'Keep WordPress in charge';
				diffAddLine             = 'Your WordPress database remains the source of truth';
				pendingFiles[dirtyPath] = '# Home\n\nYour WordPress database remains the source of truth\n';
			} else {
				diffRemoveLine          = 'Old CTA copy';
				diffAddLine             = 'Keep WordPress in charge';
				pendingFiles[dirtyPath] = '# Home\n\nKeep WordPress in charge\n';
			}
			appendLine( 'edit: page/home.md', 'terminal-comment' );
			appendLine( 'Agent updated Markdown locally. Commit, then push to WordPress.', 'terminal-muted' );
		}

		function printGit( args, command ) {
			var subcommand = args[0] || '';

			if ( subcommand === 'clone' ) {
				appendLine( "Cloning into '" + ( args[2] || checkoutDir ) + "'..." );
				appendLine( 'remote: exported posts and pages as Markdown', 'terminal-muted' );
				appendLine( 'remote: exported templates as block HTML', 'terminal-muted' );
				appendLine( 'remote: exported Global Styles as JSON', 'terminal-muted' );
				appendLine( 'Receiving objects: 5, done.' );
			} else if ( subcommand === 'status' ) {
				appendLine( 'On branch trunk' );
				if ( dirtyPath ) {
					appendLine( 'Changes not staged for commit:', 'terminal-warning' );
					appendLine( '  modified:   ' + dirtyPath );
				} else if ( aheadMessage ) {
					appendLine( 'Your branch is ahead of origin/trunk by 1 commit.', 'terminal-warning' );
				} else {
					appendLine( 'Your branch is up to date with origin/trunk.', 'terminal-success' );
					appendLine( 'nothing to commit, working tree clean' );
				}
			} else if ( subcommand === 'diff' ) {
				printDiff();
			} else if ( subcommand === 'commit' ) {
				printCommit( command || args.join( ' ' ) );
			} else if ( subcommand === 'push' ) {
				printPush();
			} else if ( subcommand === 'pull' ) {
				printPull();
			} else if ( subcommand === 'remote' ) {
				appendLine( 'origin  ' + remoteUrl + ' (fetch)' );
				appendLine( 'origin  ' + remoteUrl + ' (push)' );
			} else if ( subcommand === 'branch' ) {
				appendLine( '* trunk' );
			} else if ( subcommand === 'log' ) {
				appendLine( 'a17f184 Update home page' );
				appendLine( '5d6c013 Import WordPress content' );
			} else {
				appendLine( 'git: supported here: clone, status, diff, commit, push, pull, remote, branch, log', 'terminal-muted' );
			}
		}

		function printDiff() {
			if ( ! dirtyPath ) {
				appendLine( '(no local changes)', 'terminal-muted' );
				return;
			}

			appendLine( 'diff --git a/' + dirtyPath + ' b/' + dirtyPath );
			appendLine( '@@', 'terminal-muted' );
			appendLine( '- ' + diffRemoveLine, 'terminal-diff-remove' );
			appendLine( '+ ' + diffAddLine, 'terminal-diff-add' );
		}

		function printCommit( command ) {
			var messageMatch = String( command || '' ).match( /-[A-Za-z]*m\s+["']([^"']+)["']/ );
			var message      = messageMatch ? messageMatch[1] : 'Update content';

			if ( ! dirtyPath ) {
				appendLine( 'nothing to commit, working tree clean', 'terminal-muted' );
				return;
			}

			files[dirtyPath] = pendingFiles[dirtyPath];
			pendingFiles     = {};
			dirtyPath        = '';
			aheadMessage     = message;
			appendLine( '[trunk a17f184] ' + message );
			appendLine( ' 1 file changed, 1 insertion(+), 1 deletion(-)' );
		}

		function printPush() {
			if ( dirtyPath ) {
				appendLine( 'error: failed to push some refs to origin', 'terminal-error' );
				appendLine( 'Commit local Markdown edits before pushing.', 'terminal-muted' );
				return;
			}
			if ( ! aheadMessage ) {
				appendLine( 'Everything up-to-date', 'terminal-muted' );
				return;
			}

			revisionNumber++;
			appendLine( 'Enumerating objects: 5, done.' );
			appendLine( 'Writing objects: 100% (3/3), 412 bytes | 412.00 KiB/s, done.' );
			appendLine( 'remote: checked WordPress permissions', 'terminal-success' );
			appendLine( 'remote: stale state check passed', 'terminal-success' );
			appendLine( 'remote: created WordPress revision #' + revisionNumber, 'terminal-success' );
			appendLine( 'remote: applied 1 Markdown change', 'terminal-success' );
			appendLine( 'To ' + remoteUrl );
			aheadMessage = '';
		}

		function printPull() {
			if ( dirtyPath ) {
				appendLine( 'error: Your local changes would be overwritten by merge.', 'terminal-error' );
				appendLine( 'Commit or discard local edits before pulling from WordPress.', 'terminal-muted' );
				return;
			}

			appendLine( 'From ' + remoteUrl );
			appendLine( ' * branch            trunk      -> FETCH_HEAD' );
			appendLine( 'remote: exported latest WordPress revisions', 'terminal-success' );
			appendLine( 'WP-Admin edits are Git-visible on pull.', 'terminal-muted' );
			appendLine( 'Already up to date.' );
		}

		function changeDirectory( path ) {
			var nextPath = normalizePath( path );

			if ( path === checkoutDir || path === '~/' + checkoutDir ) {
				cwd = '/';
				updatePrompt();
				return;
			}

			if ( ! isDirectory( nextPath ) ) {
				appendLine( 'cd: ' + path + ': No such directory', 'terminal-error' );
				return;
			}

			cwd = nextPath;
			updatePrompt();
		}

		function printLs( path ) {
			var entries = directoryEntries( normalizePath( path ) );

			if ( ! entries.length ) {
				appendLine( '(empty)', 'terminal-muted' );
				return;
			}

			appendLine( entries.join( '  ' ) );
		}

		function printTree() {
			appendLine( '.' );
			appendLine( '|-- AGENTS.md' );
			appendLine( '|-- page/' );
			appendLine( '|   `-- home.md' );
			appendLine( '|-- post/' );
			appendLine( '|   `-- hello-world.md' );
			appendLine( '|-- wp_global_styles/' );
			appendLine( '|   `-- theme.json' );
			appendLine( '`-- wp_template_part/' );
			appendLine( '    `-- header.html' );
		}

		function printCat( path ) {
			var key;

			if ( ! path ) {
				appendLine( 'cat: missing file operand', 'terminal-error' );
				return;
			}

			key = pathKey( normalizePath( path ) );
			if ( ! files[key] ) {
				appendLine( 'cat: ' + path + ': No such file', 'terminal-error' );
				return;
			}

			files[key].split( /\r\n|\n|\r/ ).forEach(
				function ( line ) {
					appendLine( line );
				}
			);
		}

		function normalizePath( path ) {
			var base;
			var segments;

			path = path || '.';
			if ( path === checkoutDir || path === '~/' + checkoutDir || path === '/' + checkoutDir ) {
				return '/';
			}
			if ( path.indexOf( '~/' + checkoutDir + '/' ) === 0 ) {
				path = path.substring( checkoutDir.length + 3 );
			}
			if ( path.indexOf( '/' + checkoutDir + '/' ) === 0 ) {
				path = path.substring( checkoutDir.length + 1 );
			}
			base     = cwd === '~' || cwd === '/' ? [] : pathKey( cwd ).split( '/' ).filter( Boolean );
			segments = path.charAt( 0 ) === '/' ? [] : base;

			path.split( '/' ).forEach(
				function ( segment ) {
					if ( ! segment || segment === '.' ) {
						return;
					}
					if ( segment === '..' ) {
						segments.pop();
					} else {
						segments.push( segment );
					}
				}
			);

			return '/' + segments.join( '/' );
		}

		function pathKey( path ) {
			return String( path || '' ).replace( /^\/+/, '' ).replace( /\/+$/, '' );
		}

		function isDirectory( path ) {
			var key = pathKey( path );
			var prefix;
			var filePath;

			if ( ! key ) {
				return true;
			}

			prefix = key + '/';
			for ( filePath in files ) {
				if ( Object.prototype.hasOwnProperty.call( files, filePath ) && filePath.indexOf( prefix ) === 0 ) {
					return true;
				}
			}
			return false;
		}

		function directoryEntries( path ) {
			var key     = pathKey( path );
			var prefix  = key ? key + '/' : '';
			var entries = {};
			var filePath;
			var rest;
			var slashAt;
			var name;

			for ( filePath in files ) {
				if ( ! Object.prototype.hasOwnProperty.call( files, filePath ) ) {
					continue;
				}
				if ( prefix && filePath.indexOf( prefix ) !== 0 ) {
					continue;
				}

				rest = prefix ? filePath.substring( prefix.length ) : filePath;
				if ( ! rest ) {
					continue;
				}

				slashAt       = rest.indexOf( '/' );
				name          = slashAt === -1 ? rest : rest.substring( 0, slashAt ) + '/';
				entries[name] = true;
			}

			return Object.keys( entries ).sort();
		}

		inputEl.addEventListener(
			'keydown',
			function ( event ) {
				if ( event.key === 'Enter' ) {
					runCommand( inputEl.value );
					inputEl.value = '';
					updateInputGhost();
				} else if ( event.key === 'ArrowUp' ) {
					if ( history.length ) {
						historyIndex  = Math.max( 0, historyIndex - 1 );
						inputEl.value = history[historyIndex] || '';
						updateInputGhost();
						event.preventDefault();
					}
				} else if ( event.key === 'ArrowDown' ) {
					if ( history.length ) {
						historyIndex  = Math.min( history.length, historyIndex + 1 );
						inputEl.value = history[historyIndex] || '';
						updateInputGhost();
						event.preventDefault();
					}
				}
			}
		);

		inputEl.addEventListener( 'input', updateInputGhost );
		inputEl.addEventListener(
			'focus',
			function () {
				inputRowEl.classList.add( 'is-focused' );
				updateInputGhost();
			}
		);
		inputEl.addEventListener(
			'blur',
			function () {
				inputRowEl.classList.remove( 'is-focused' );
				updateInputGhost();
			}
		);
		inputRowEl.addEventListener(
			'click',
			function () {
				inputEl.focus();
				inputRowEl.classList.add( 'is-focused' );
				updateInputGhost();
			}
		);

		updatePrompt();
		updateInputGhost();
		bootTranscript();
	}

	function markCopied( button ) {
		var original       = button.textContent;
		button.textContent = 'Copied';
		button.classList.add( 'is-copied' );
		window.setTimeout(
			function () {
				button.textContent = original;
				button.classList.remove( 'is-copied' );
			},
			1400
		);
	}

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise(
			function ( resolve ) {
				var input   = document.createElement( 'textarea' );
				input.value = text;
				input.setAttribute( 'readonly', 'readonly' );
				input.style.position = 'fixed';
				input.style.opacity  = '0';
				document.body.appendChild( input );
				input.select();
				document.execCommand( 'copy' );
				document.body.removeChild( input );
				resolve();
			}
		);
	}
}());
