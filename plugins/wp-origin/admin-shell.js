(function () {
	var i18n              = window.wp && window.wp.i18n ? window.wp.i18n : {};
	var __                = i18n.__ || function ( text ) {
		return text;
	};
	var sprintf           = i18n.sprintf || function () {
		var text = arguments[0];
		for (var i = 1; i < arguments.length; i++) {
			text = text.replace( /%(\d+\$)?[sd]/, arguments[i] );
		}
		return text;
	};
	var config            = window.wpOriginAdminShell || {};
	var nonce             = config.nonce || '';
	var statusUrl         = config.statusUrl || '';
	var retryUrl          = config.retryUrl || '';
	var remoteUrl         = config.remoteUrl || '';
	var checkoutDir       = config.checkoutDir || 'site';
	var cloneCommand      = config.cloneCommand || ('git clone ' + remoteUrl + ' ' + checkoutDir);
	var progress          = config.initialProgress || {};
	var stateEl           = document.getElementById( 'wp-origin-state' );
	var stateCopyEl       = document.getElementById( 'wp-origin-state-copy' );
	var statePillEl       = document.getElementById( 'wp-origin-state-pill' );
	var barEl             = document.getElementById( 'wp-origin-bar' );
	var percentEl         = document.getElementById( 'wp-origin-percent' );
	var countsEl          = document.getElementById( 'wp-origin-counts' );
	var messageEl         = document.getElementById( 'wp-origin-message' );
	var outputEl          = document.getElementById( 'wp-origin-terminal-output' );
	var inputEl           = document.getElementById( 'wp-origin-terminal-input' );
	var cwdEl             = document.getElementById( 'wp-origin-prompt-cwd' );
	var titleEl           = document.getElementById( 'wp-origin-terminal-title' );
	var commitListEl      = document.getElementById( 'wp-origin-commit-list' );
	var checkout          = normalizeCheckout( progress.checkout );
	var cwd               = '/';
	var history           = [];
	var historyIndex      = 0;
	var hasAnnouncedReady = progress.state === 'done';

	if ( ! stateEl || ! outputEl || ! inputEl) {
		return;
	}

	function render(data) {
		var previousState       = progress.state;
		progress                = data || {};
		checkout                = normalizeCheckout( progress.checkout );
		stateEl.textContent     = progress.state;
		stateCopyEl.textContent = progress.state;
		statePillEl.classList.toggle( 'is-done', progress.state === 'done' );
		statePillEl.classList.toggle( 'is-failed', progress.state === 'failed' );
		barEl.style.width     = progress.percent + '%';
		percentEl.textContent = progress.percent;
		countsEl.textContent  = sprintf(
			/* translators: 1: Imported item count, 2: Total item count. */
			__( '%1$d / %2$d', 'wp-origin' ),
			progress.processed,
			progress.total
		);
		messageEl.textContent = progress.message;
		renderCommits( progress.commits || [] );
		if (previousState !== 'done' && progress.state === 'done' && ! hasAnnouncedReady) {
			hasAnnouncedReady = true;
			appendLine( __( 'remote: Initial import complete. The checkout is ready.', 'wp-origin' ), 'is-success' );
			appendLine( __( 'Try: ls, git pull, git commit -m "Update content", git push', 'wp-origin' ), 'is-muted' );
		}
	}

	function poll() {
		if ( ! statusUrl) {
			return;
		}

		fetch( statusUrl, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } } )
			.then(
				function (response) {
					return response.json();
				}
			)
			.then(
				function (data) {
					render( data );
					if (data.state !== 'done' && data.state !== 'failed') {
						setTimeout( poll, 2000 );
					}
				}
			)
			.catch(
				function () {
					setTimeout( poll, 5000 );
				}
			);
	}

	function normalizeCheckout(rawCheckout) {
		var normalized = rawCheckout || {};
		if ( ! normalized.files) {
			normalized.files = [];
		}
		return normalized;
	}

	function renderCommits(commits) {
		commitListEl.textContent = '';
		if ( ! commits.length) {
			var emptyItem         = document.createElement( 'li' );
			emptyItem.textContent = __( 'No commits yet', 'wp-origin' );
			commitListEl.appendChild( emptyItem );
			return;
		}
		commits.slice( 0, 5 ).forEach(
			function (commit) {
				var item            = document.createElement( 'li' );
				var oid             = document.createElement( 'code' );
				var subject         = document.createElement( 'span' );
				oid.textContent     = commit.oid;
				subject.textContent = commit.subject;
				item.appendChild( oid );
				item.appendChild( subject );
				commitListEl.appendChild( item );
			}
		);
	}

	function appendLine(text, className) {
		var line       = document.createElement( 'div' );
		line.className = 'wp-origin-terminal-line';
		if (className) {
			line.className += ' ' + className;
		}
		line.textContent = text === undefined ? '' : String( text );
		outputEl.appendChild( line );
		outputEl.scrollTop = outputEl.scrollHeight;
	}

	function appendPrompt(command) {
		appendLine(
			sprintf(
				/* translators: 1: Current working directory, 2: Emulated shell command. */
				__( 'emulator:%1$s$ %2$s', 'wp-origin' ),
				displayCwd(),
				command
			),
			'is-accent'
		);
	}

	function displayCwd() {
		return cwd === '/' ? '~/' + checkoutDir : '~/' + checkoutDir + cwd;
	}

	function updatePrompt() {
		cwdEl.textContent   = displayCwd();
		titleEl.textContent = sprintf(
			/* translators: %s: Current working directory. */
			__( 'emulator:%s', 'wp-origin' ),
			displayCwd()
		);
	}

	function bootTranscript() {
		outputEl.textContent = '';
		appendLine( __( 'WP Origin command emulator', 'wp-origin' ), 'is-success' );
		appendLine( __( 'This preview mirrors commands you can run in your real terminal after cloning.', 'wp-origin' ), 'is-muted' );
		appendLine( __( 'No server shell is opened here, and emulator commands do not change WordPress.', 'wp-origin' ), 'is-muted' );
		appendLine( '' );
		appendPrompt( cloneCommand );
		appendLine(
			sprintf(
				/* translators: %s: Checkout directory name. */
				__( "Cloning into '%s'...", 'wp-origin' ),
				checkoutDir
			)
		);
		if (progress.state === 'done') {
			appendLine( __( 'remote: Initial import complete.', 'wp-origin' ), 'is-success' );
		} else {
			appendLine(
				sprintf(
					/* translators: %d: Import progress percentage. */
					__( 'remote: Preparing WordPress content (%d%%)...', 'wp-origin' ),
					progress.percent
				),
				'is-warning'
			);
		}
		appendLine(
			sprintf(
				/* translators: %d: Number of checkout entries. */
				__( 'Receiving objects: %d checkout entries', 'wp-origin' ),
				Math.max( 1, checkout.files.length )
			)
		);
		appendPrompt( 'cd ' + checkoutDir );
		cwd = '/';
		updatePrompt();
		runCommand( 'git status', { silentHistory: true } );
		if (checkout.files.length) {
			runCommand( 'ls', { silentHistory: true } );
			appendLine( __( 'Try: git pull, git commit -m "Update content", git push', 'wp-origin' ), 'is-muted' );
		} else {
			appendLine( __( 'The emulated file tree will appear here as soon as the first commit is staged.', 'wp-origin' ), 'is-muted' );
		}
	}

	function runCommand(command, options) {
		options = options || {};
		command = String( command || '' ).replace( /^\s+|\s+$/g, '' );
		if ( ! command) {
			return;
		}
		appendPrompt( command );
		if ( ! options.silentHistory) {
			history.push( command );
			historyIndex = history.length;
		}

		var parts = command.split( /\s+/ );
		var base  = parts[0];
		if (base === 'help') {
			printHelp();
		} else if (base === 'clear') {
			outputEl.textContent = '';
		} else if (base === 'pwd') {
			appendLine( '/' + checkoutDir + (cwd === '/' ? '' : cwd) );
		} else if (base === 'ls') {
			printLs( parts.slice( 1 ) );
		} else if (base === 'cd') {
			changeDirectory( parts[1] || '/' );
		} else if (base === 'cat') {
			printCat( parts.slice( 1 ).join( ' ' ) );
		} else if (base === 'tree') {
			printTree( parts[1] || '.' );
		} else if (base === 'status') {
			printGit( ['status'] );
		} else if (base === 'git') {
			printGit( parts.slice( 1 ) );
		} else {
			appendLine(
				sprintf(
					/* translators: %s: Emulated shell command. */
					__( '%s: command not found. Try `help`.', 'wp-origin' ),
					base
				),
				'is-error'
			);
		}
	}

	function printHelp() {
		appendLine( __( 'Emulated commands:', 'wp-origin' ), 'is-success' );
		appendLine( __( '  ls [-l] [path]        list checkout files', 'wp-origin' ) );
		appendLine( __( '  cd [path]             change directory', 'wp-origin' ) );
		appendLine( __( '  pwd                   print current directory', 'wp-origin' ) );
		appendLine( __( '  cat <file>            show a preview of a file', 'wp-origin' ) );
		appendLine( __( '  tree [path]           show the checkout shape', 'wp-origin' ) );
		appendLine( __( '  git status            show import and branch state', 'wp-origin' ) );
		appendLine( __( '  git log --oneline     show recent WP Origin commits', 'wp-origin' ) );
		appendLine( __( '  git remote -v         show the WordPress remote', 'wp-origin' ) );
		appendLine( __( '  git pull              preview refreshing from WordPress', 'wp-origin' ) );
		appendLine( __( '  git add <path>        preview staging a change', 'wp-origin' ) );
		appendLine( __( '  git commit -m "..."   preview a local content commit', 'wp-origin' ) );
		appendLine( __( '  git push              preview sending changes back', 'wp-origin' ) );
		appendLine( __( '  clear                 clear the terminal', 'wp-origin' ) );
		appendLine( __( 'Use the copy table below for the real terminal URL and clone command.', 'wp-origin' ), 'is-muted' );
	}

	function printLs(args) {
		var longForm = false;
		var target   = '.';
		args.forEach(
			function (arg) {
				if (arg.indexOf( '-' ) === 0) {
					longForm = arg.indexOf( 'l' ) !== -1;
				} else {
					target = arg;
				}
			}
		);

		var targetPath = normalizePath( target );
		var file       = findFile( targetPath );
		if (file) {
			if (file.type === 'symlink' && ! longForm && isDirectory( targetPath )) {
				printDirectoryEntries( targetPath, longForm );
				return;
			}
			appendLine( formatEntryName( file.path.split( '/' ).pop(), file.type, longForm, file ) );
			return;
		}
		file = findFile( resolvePathForLookup( targetPath ) );
		if (file && ! isDirectory( targetPath )) {
			appendLine( formatEntryName( targetPath.split( '/' ).pop(), file.type, longForm, file ) );
			return;
		}
		if ( ! isDirectory( targetPath )) {
			appendLine(
				sprintf(
					/* translators: %s: File path. */
					__( 'ls: %s: No such file or directory', 'wp-origin' ),
					target
				),
				'is-error'
			);
			return;
		}

		printDirectoryEntries( targetPath, longForm );
	}

	function printDirectoryEntries(targetPath, longForm) {
		var entries = directoryEntries( targetPath );
		if ( ! entries.length) {
			appendLine( __( '(empty)', 'wp-origin' ), 'is-muted' );
			return;
		}
		if (longForm) {
			entries.forEach(
				function (entry) {
					appendLine( formatEntryName( entry.name, entry.type, true, entry.file ) );
				}
			);
		} else {
			appendLine(
				entries.map(
					function (entry) {
						return entry.type === 'directory' ? entry.name + '/' : entry.name;
					}
				).join( '  ' )
			);
		}
		if (checkout.truncated) {
			appendLine(
				sprintf(
					/* translators: %d: Total number of previewed paths. */
					__( 'Preview is limited to the first %d paths.', 'wp-origin' ),
					checkout.path_count
				),
				'is-muted'
			);
		}
	}

	function formatEntryName(name, type, longForm, file) {
		if ( ! longForm) {
			return type === 'directory' ? name + '/' : name;
		}
		var mode = type === 'directory' ? 'drwxr-xr-x' : (type === 'symlink' ? 'lrwxrwxrwx' : '-rw-r--r--');
		var size = file && file.size !== undefined ? String( file.size ) : '-';
		while (size.length < 7) {
			size = ' ' + size;
		}
		if (type === 'symlink' && file && file.content) {
			return mode + ' ' + size + ' ' + name + ' -> ' + file.content;
		}
		return mode + ' ' + size + ' ' + (type === 'directory' ? name + '/' : name);
	}

	function changeDirectory(path) {
		if (path === checkoutDir || path === '~/' + checkoutDir) {
			cwd = '/';
			updatePrompt();
			return;
		}

		var next = normalizePath( path );
		if ( ! isDirectory( next )) {
			appendLine(
				sprintf(
					/* translators: %s: Directory path. */
					__( 'cd: %s: No such directory', 'wp-origin' ),
					path
				),
				'is-error'
			);
			return;
		}
		cwd = next;
		updatePrompt();
	}

	function printCat(path) {
		if ( ! path) {
			appendLine( __( 'cat: missing file operand', 'wp-origin' ), 'is-error' );
			return;
		}
		var targetPath = normalizePath( path );
		var file       = findFile( targetPath );
		if (file && file.type === 'symlink') {
			file = findFile( resolvePathForLookup( targetPath ) ) || file;
		} else if ( ! file) {
			file = findFile( resolvePathForLookup( targetPath ) );
		}
		if ( ! file) {
			appendLine(
				sprintf(
					/* translators: %s: File path. */
					__( 'cat: %s: No such file', 'wp-origin' ),
					path
				),
				'is-error'
			);
			return;
		}
		if (file.type === 'symlink') {
			appendLine( file.content || __( '(symlink target unavailable)', 'wp-origin' ) );
			return;
		}
		if (file.content === undefined) {
			appendLine( __( 'Preview content for this file is not loaded in the shell.', 'wp-origin' ), 'is-warning' );
			appendLine(
				sprintf(
					/* translators: %s: Suggested file path. */
					__( 'Try: cat %s', 'wp-origin' ),
					sampleCatPath()
				),
				'is-muted'
			);
			return;
		}
		String( file.content ).split( /\r\n|\n|\r/ ).forEach(
			function (line) {
				appendLine( line );
			}
		);
	}

	function printTree(path) {
		var root = normalizePath( path );
		if ( ! isDirectory( root )) {
			appendLine(
				sprintf(
					/* translators: %s: Directory path. */
					__( 'tree: %s: No such directory', 'wp-origin' ),
					path
				),
				'is-error'
			);
			return;
		}
		appendLine( root === '/' ? '.' : root.replace( /^\//, '' ) );
		printTreeChildren( root, '', 0 );
		if (checkout.truncated) {
			appendLine( __( 'Preview is limited; clone or pull for the full tree.', 'wp-origin' ), 'is-muted' );
		}
	}

	function printTreeChildren(path, indent, depth) {
		if (depth > 2) {
			return;
		}
		var entries = directoryEntries( path );
		entries.slice( 0, 18 ).forEach(
			function (entry, index) {
				var marker = index === entries.length - 1 ? '`-- ' : '|-- ';
				appendLine( indent + marker + (entry.type === 'directory' ? entry.name + '/' : entry.name) );
				if (entry.type === 'directory') {
					printTreeChildren( joinPath( path, entry.name ), indent + (index === entries.length - 1 ? '    ' : '|   '), depth + 1 );
				}
			}
		);
	}

	function printGit(args) {
		var subcommand = args[0] || '';
		if (subcommand === 'status') {
			appendLine( __( 'On branch trunk', 'wp-origin' ) );
			if (progress.state === 'done') {
				appendLine( __( 'Your branch is up to date with origin/trunk.', 'wp-origin' ), 'is-success' );
				appendLine( __( 'nothing to commit, working tree clean', 'wp-origin' ) );
			} else if (progress.state === 'failed') {
				appendLine(
					sprintf(
						/* translators: %s: Import failure message. */
						__( 'remote: import failed: %s', 'wp-origin' ),
						progress.message
					),
					'is-error'
				);
			} else {
				appendLine(
					sprintf(
						/* translators: %d: Import progress percentage. */
						__( 'remote: preparing repository (%d%%)', 'wp-origin' ),
						progress.percent
					),
					'is-warning'
				);
				appendLine(
					sprintf(
						/* translators: 1: Imported item count, 2: Total item count. */
						__( '%1$d / %2$d content items imported', 'wp-origin' ),
						progress.processed,
						progress.total
					)
				);
			}
		} else if (subcommand === 'log') {
			printGitLog();
		} else if (subcommand === 'remote') {
			appendLine(
				sprintf(
					/* translators: %s: Git remote URL. */
					__( 'origin  %s (fetch)', 'wp-origin' ),
					remoteUrl
				)
			);
			appendLine(
				sprintf(
					/* translators: %s: Git remote URL. */
					__( 'origin  %s (push)', 'wp-origin' ),
					remoteUrl
				)
			);
		} else if (subcommand === 'branch') {
			appendLine( __( '* trunk', 'wp-origin' ) );
		} else if (subcommand === 'pull') {
			if (progress.state === 'done') {
				appendLine(
					sprintf(
						/* translators: %s: Git remote URL. */
						__( 'From %s', 'wp-origin' ),
						remoteUrl
					)
				);
				appendLine( __( ' * branch            trunk      -> FETCH_HEAD', 'wp-origin' ) );
				appendLine( __( 'Already up to date.', 'wp-origin' ), 'is-success' );
				appendLine( __( 'In your real terminal, `git pull` refreshes this checkout from WordPress.', 'wp-origin' ), 'is-muted' );
			} else {
				appendLine( __( 'Repository is still preparing. Try again shortly.', 'wp-origin' ), 'is-warning' );
			}
		} else if (subcommand === 'add') {
			appendLine(
				sprintf(
					/* translators: %s: Path staged in the emulator. */
					__( 'Staged in emulator only: %s', 'wp-origin' ),
					args.slice( 1 ).join( ' ' ) || '.'
				)
			);
			appendLine( __( 'In your real terminal, `git add` stages file edits before committing.', 'wp-origin' ), 'is-muted' );
		} else if (subcommand === 'commit') {
			printGitCommit( args.slice( 1 ) );
		} else if (subcommand === 'push') {
			printGitPush();
		} else if (subcommand === 'clone') {
			appendLine(
				sprintf(
					/* translators: %s: Checkout directory name. */
					__( "Cloning into '%s'...", 'wp-origin' ),
					args[2] || checkoutDir
				)
			);
			appendLine(
				sprintf(
					/* translators: %s: Git remote URL. */
					__( 'remote: WP Origin at %s', 'wp-origin' ),
					remoteUrl
				)
			);
			appendLine(
				sprintf(
					/* translators: %d: Number of received objects. */
					__( 'Receiving objects: %d', 'wp-origin' ),
					Math.max( 1, checkout.files.length )
				)
			);
		} else if (subcommand === 'checkout' && args[1] === 'trunk') {
			appendLine( __( 'Already on trunk', 'wp-origin' ) );
		} else if (subcommand === 'show' && args[1] && args[1].indexOf( 'HEAD:' ) === 0) {
			printCat( args[1].replace( /^HEAD:/, '' ) );
		} else if (subcommand === 'diff') {
			appendLine( __( '(no local changes)', 'wp-origin' ), 'is-muted' );
		} else {
			appendLine( __( 'git: supported here: status, log, remote, branch, pull, add, commit, push, clone, checkout, show, diff', 'wp-origin' ), 'is-muted' );
		}
	}

	function printGitCommit(args) {
		var message = '(no message)';
		for (var i = 0; i < args.length; i++) {
			if (args[i] === '-m' && args[i + 1]) {
				message = args[i + 1].replace( /^['"]|['"]$/g, '' );
				break;
			}
		}

		appendLine(
			sprintf(
				/* translators: %s: Commit message. */
				__( '[trunk emulated] %s', 'wp-origin' ),
				message
			)
		);
		appendLine( __( ' 1 file changed, 4 insertions(+), 1 deletion(-)', 'wp-origin' ) );
		appendLine( __( 'This emulator does not create commits. In your real terminal, `git commit` records local file edits before `git push` sends them to WordPress.', 'wp-origin' ), 'is-muted' );
	}

	function printGitPush() {
		if (progress.state !== 'done') {
			appendLine( __( 'remote: WP Origin is still preparing the repository.', 'wp-origin' ), 'is-warning' );
			appendLine( __( 'Real pushes are accepted after the import reaches 100%.', 'wp-origin' ), 'is-muted' );
			return;
		}

		appendLine( __( 'Enumerating objects: 5, done.', 'wp-origin' ) );
		appendLine( __( 'Writing objects: 100% (3/3), 412 bytes | 412.00 KiB/s, done.', 'wp-origin' ) );
		appendLine( __( 'remote: WP Origin would validate the pushed files, check permissions, and apply supported changes through WordPress.', 'wp-origin' ), 'is-success' );
		appendLine( __( 'To actually push changes, clone the URL below, edit files locally, then run `git push origin trunk` in your real terminal.', 'wp-origin' ), 'is-muted' );
	}

	function printGitLog() {
		var commits = progress.commits || [];
		if ( ! commits.length) {
			appendLine( __( 'No commits yet. Import is still warming up.', 'wp-origin' ), 'is-warning' );
			return;
		}
		commits.forEach(
			function (commit) {
				appendLine( commit.oid + ' ' + commit.subject );
			}
		);
	}

	function normalizePath(path) {
		path = path || '.';
		if (path === '.' || path === './') {
			return cwd;
		}
		if (path === '~' || path === '~/' + checkoutDir || path === '/' + checkoutDir) {
			return '/';
		}
		if (path.indexOf( '~/' + checkoutDir + '/' ) === 0) {
			path = '/' + path.substring( checkoutDir.length + 3 );
		}
		if (path.indexOf( '/' + checkoutDir + '/' ) === 0) {
			path = path.substring( checkoutDir.length + 1 );
		}

		var segments = path.charAt( 0 ) === '/' ? [] : cwd.split( '/' ).filter( Boolean );
		path.split( '/' ).forEach(
			function (segment) {
				if ( ! segment || segment === '.') {
					return;
				}
				if (segment === '..') {
					segments.pop();
				} else {
					segments.push( segment );
				}
			}
		);
		return '/' + segments.join( '/' );
	}

	function pathKey(path) {
		return String( path || '' ).replace( /^\/+/, '' ).replace( /\/+$/, '' );
	}

	function joinPath(base, name) {
		return (base === '/' ? '' : base) + '/' + name;
	}

	function findFile(path) {
		var key = pathKey( path );
		for (var i = 0; i < checkout.files.length; i++) {
			if (checkout.files[i].path === key) {
				return checkout.files[i];
			}
		}
		return null;
	}

	function isDirectory(path) {
		var key = pathKey( resolvePathForLookup( path ) );
		if ( ! key) {
			return true;
		}
		var prefix = key + '/';
		for (var i = 0; i < checkout.files.length; i++) {
			if (checkout.files[i].path.indexOf( prefix ) === 0) {
				return true;
			}
		}
		return false;
	}

	function directoryEntries(path) {
		var key    = pathKey( resolvePathForLookup( path ) );
		var prefix = key ? key + '/' : '';
		var seen   = {};
		checkout.files.forEach(
			function (file) {
				if (prefix && file.path.indexOf( prefix ) !== 0) {
					return;
				}
				if ( ! prefix && file.path.indexOf( '/' ) === -1) {
					seen[file.path] = { name: file.path, type: file.type, file: file };
					return;
				}
				var rest = prefix ? file.path.substring( prefix.length ) : file.path;
				if ( ! rest) {
					return;
				}
				var slashAt = rest.indexOf( '/' );
				if (slashAt === -1) {
					seen[rest] = { name: rest, type: file.type, file: file };
				} else {
					var directory = rest.substring( 0, slashAt );
					if ( ! seen[directory]) {
						seen[directory] = { name: directory, type: 'directory' };
					}
				}
			}
		);

		return Object.keys( seen ).sort().map(
			function (name) {
				return seen[name];
			}
		);
	}

	function resolvePathForLookup(path) {
		var segments = pathKey( path ).split( '/' ).filter( Boolean );
		var resolved = [];
		for (var i = 0; i < segments.length; i++) {
			resolved.push( segments[i] );
			var file = findFile( '/' + resolved.join( '/' ) );
			if (file && file.type === 'symlink' && file.content) {
				resolved = pathKey( normalizeSymlinkTarget( file.path, file.content ) ).split( '/' ).filter( Boolean );
			}
		}

		return '/' + resolved.join( '/' );
	}

	function normalizeSymlinkTarget(filePath, target) {
		var parent = pathKey( filePath ).split( '/' );
		parent.pop();
		return normalizePathFromBase( '/' + parent.join( '/' ), target );
	}

	function normalizePathFromBase(base, path) {
		var segments = path.charAt( 0 ) === '/' ? [] : pathKey( base ).split( '/' ).filter( Boolean );
		path.split( '/' ).forEach(
			function (segment) {
				if ( ! segment || segment === '.') {
					return;
				}
				if (segment === '..') {
					segments.pop();
				} else {
					segments.push( segment );
				}
			}
		);
		return '/' + segments.join( '/' );
	}

	function sampleCatPath() {
		var preferred = ['post/hello-world.md', 'page/sample-page.md', 'AGENTS.md'];
		for (var i = 0; i < preferred.length; i++) {
			if (findFile( '/' + preferred[i] ) && findFile( '/' + preferred[i] ).content !== undefined) {
				return preferred[i];
			}
		}
		for (var j = 0; j < checkout.files.length; j++) {
			if (checkout.files[j].content !== undefined && checkout.files[j].type === 'file') {
				return checkout.files[j].path;
			}
		}
		return 'post/hello-world.md';
	}

	document.getElementById( 'wp-origin-retry' ).addEventListener(
		'click',
		function () {
			appendLine( '' );
			appendPrompt( 'wp-origin seed retry' );
			appendLine( __( 'Resetting the repository import...', 'wp-origin' ), 'is-warning' );
			fetch(
				retryUrl,
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': nonce }
				}
			).then(
				function () {
					hasAnnouncedReady = false;
					setTimeout( poll, 1000 );
				}
			);
		}
	);

	function copyText(value, button) {
		function markCopied() {
			var originalText   = button.textContent;
			button.textContent = __( 'Copied', 'wp-origin' );
			button.classList.add( 'is-copied' );
			setTimeout(
				function () {
					button.textContent = originalText;
					button.classList.remove( 'is-copied' );
				},
				1400
			);
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText( value ).then( markCopied );
			return;
		}

		var textarea   = document.createElement( 'textarea' );
		textarea.value = value;
		textarea.setAttribute( 'readonly', 'readonly' );
		textarea.style.position = 'absolute';
		textarea.style.left     = '-9999px';
		document.body.appendChild( textarea );
		textarea.select();
		document.execCommand( 'copy' );
		document.body.removeChild( textarea );
		markCopied();
	}

	Array.prototype.slice.call( document.querySelectorAll( '.wp-origin-copy-button' ) ).forEach(
		function (button) {
			button.addEventListener(
				'click',
				function () {
					copyText( button.getAttribute( 'data-copy-value' ) || '', button );
				}
			);
		}
	);

	inputEl.addEventListener(
		'keydown',
		function (event) {
			if (event.key === 'Enter') {
				runCommand( inputEl.value );
				inputEl.value = '';
			} else if (event.key === 'ArrowUp') {
				if (history.length) {
					historyIndex  = Math.max( 0, historyIndex - 1 );
					inputEl.value = history[historyIndex] || '';
					event.preventDefault();
				}
			} else if (event.key === 'ArrowDown') {
				if (history.length) {
					historyIndex  = Math.min( history.length, historyIndex + 1 );
					inputEl.value = history[historyIndex] || '';
					event.preventDefault();
				}
			}
		}
	);

	Array.prototype.slice.call( document.querySelectorAll( '[data-wp-origin-command]' ) ).forEach(
		function (button) {
			button.addEventListener(
				'click',
				function () {
					runCommand( button.getAttribute( 'data-wp-origin-command' ) );
					inputEl.focus();
				}
			);
		}
	);

	render( progress );
	updatePrompt();
	bootTranscript();
	poll();
}());
