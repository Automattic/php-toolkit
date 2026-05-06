(function () {
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
		countsEl.textContent  = progress.processed + ' / ' + progress.total;
		messageEl.textContent = progress.message;
		renderCommits( progress.commits || [] );
		if (previousState !== 'done' && progress.state === 'done' && ! hasAnnouncedReady) {
			hasAnnouncedReady = true;
			appendLine( 'remote: Initial import complete. The checkout is ready.', 'is-success' );
			appendLine( 'Try: ls, git pull, git commit -m "Update content", git push', 'is-muted' );
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
			emptyItem.textContent = 'No commits yet';
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
		appendLine( 'emulator:' + displayCwd() + '$ ' + command, 'is-accent' );
	}

	function displayCwd() {
		return cwd === '/' ? '~/' + checkoutDir : '~/' + checkoutDir + cwd;
	}

	function updatePrompt() {
		cwdEl.textContent   = displayCwd();
		titleEl.textContent = 'emulator:' + displayCwd();
	}

	function bootTranscript() {
		outputEl.textContent = '';
		appendLine( 'WP Origin command emulator', 'is-success' );
		appendLine( 'This preview mirrors commands you can run in your real terminal after cloning.', 'is-muted' );
		appendLine( 'No server shell is opened here, and emulator commands do not change WordPress.', 'is-muted' );
		appendLine( '' );
		appendPrompt( cloneCommand );
		appendLine( "Cloning into '" + checkoutDir + "'..." );
		if (progress.state === 'done') {
			appendLine( 'remote: Initial import complete.', 'is-success' );
		} else {
			appendLine( 'remote: Preparing WordPress content (' + progress.percent + '%)...', 'is-warning' );
		}
		appendLine( 'Receiving objects: ' + Math.max( 1, checkout.files.length ) + ' checkout entries' );
		appendPrompt( 'cd ' + checkoutDir );
		cwd = '/';
		updatePrompt();
		runCommand( 'git status', { silentHistory: true } );
		if (checkout.files.length) {
			runCommand( 'ls', { silentHistory: true } );
			appendLine( 'Try: git pull, git commit -m "Update content", git push', 'is-muted' );
		} else {
			appendLine( 'The emulated file tree will appear here as soon as the first commit is staged.', 'is-muted' );
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
			appendLine( base + ': command not found. Try `help`.', 'is-error' );
		}
	}

	function printHelp() {
		appendLine( 'Emulated commands:', 'is-success' );
		appendLine( '  ls [-l] [path]        list checkout files' );
		appendLine( '  cd [path]             change directory' );
		appendLine( '  pwd                   print current directory' );
		appendLine( '  cat <file>            show a preview of a file' );
		appendLine( '  tree [path]           show the checkout shape' );
		appendLine( '  git status            show import and branch state' );
		appendLine( '  git log --oneline     show recent WP Origin commits' );
		appendLine( '  git remote -v         show the WordPress remote' );
		appendLine( '  git pull              preview refreshing from WordPress' );
		appendLine( '  git add <path>        preview staging a change' );
		appendLine( '  git commit -m "..."   preview a local content commit' );
		appendLine( '  git push              preview sending changes back' );
		appendLine( '  clear                 clear the terminal' );
		appendLine( 'Use the copy table below for the real terminal URL and clone command.', 'is-muted' );
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
			appendLine( 'ls: ' + target + ': No such file or directory', 'is-error' );
			return;
		}

		printDirectoryEntries( targetPath, longForm );
	}

	function printDirectoryEntries(targetPath, longForm) {
		var entries = directoryEntries( targetPath );
		if ( ! entries.length) {
			appendLine( '(empty)', 'is-muted' );
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
			appendLine( 'Preview is limited to the first ' + checkout.path_count + ' paths.', 'is-muted' );
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
			appendLine( 'cd: ' + path + ': No such directory', 'is-error' );
			return;
		}
		cwd = next;
		updatePrompt();
	}

	function printCat(path) {
		if ( ! path) {
			appendLine( 'cat: missing file operand', 'is-error' );
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
			appendLine( 'cat: ' + path + ': No such file', 'is-error' );
			return;
		}
		if (file.type === 'symlink') {
			appendLine( file.content || '(symlink target unavailable)' );
			return;
		}
		if (file.content === undefined) {
			appendLine( 'Preview content for this file is not loaded in the shell.', 'is-warning' );
			appendLine( 'Try: cat ' + sampleCatPath(), 'is-muted' );
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
			appendLine( 'tree: ' + path + ': No such directory', 'is-error' );
			return;
		}
		appendLine( root === '/' ? '.' : root.replace( /^\//, '' ) );
		printTreeChildren( root, '', 0 );
		if (checkout.truncated) {
			appendLine( 'Preview is limited; clone or pull for the full tree.', 'is-muted' );
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
			appendLine( 'On branch trunk' );
			if (progress.state === 'done') {
				appendLine( 'Your branch is up to date with origin/trunk.', 'is-success' );
				appendLine( 'nothing to commit, working tree clean' );
			} else if (progress.state === 'failed') {
				appendLine( 'remote: import failed: ' + progress.message, 'is-error' );
			} else {
				appendLine( 'remote: preparing repository (' + progress.percent + '%)', 'is-warning' );
				appendLine( progress.processed + ' / ' + progress.total + ' content items imported' );
			}
		} else if (subcommand === 'log') {
			printGitLog();
		} else if (subcommand === 'remote') {
			appendLine( 'origin  ' + remoteUrl + ' (fetch)' );
			appendLine( 'origin  ' + remoteUrl + ' (push)' );
		} else if (subcommand === 'branch') {
			appendLine( '* trunk' );
		} else if (subcommand === 'pull') {
			if (progress.state === 'done') {
				appendLine( 'From ' + remoteUrl );
				appendLine( ' * branch            trunk      -> FETCH_HEAD' );
				appendLine( 'Already up to date.', 'is-success' );
				appendLine( 'In your real terminal, `git pull` refreshes this checkout from WordPress.', 'is-muted' );
			} else {
				appendLine( 'Repository is still preparing. Try again shortly.', 'is-warning' );
			}
		} else if (subcommand === 'add') {
			appendLine( 'Staged in emulator only: ' + (args.slice( 1 ).join( ' ' ) || '.') );
			appendLine( 'In your real terminal, `git add` stages file edits before committing.', 'is-muted' );
		} else if (subcommand === 'commit') {
			printGitCommit( args.slice( 1 ) );
		} else if (subcommand === 'push') {
			printGitPush();
		} else if (subcommand === 'clone') {
			appendLine( "Cloning into '" + (args[2] || checkoutDir) + "'..." );
			appendLine( 'remote: WP Origin at ' + remoteUrl );
			appendLine( 'Receiving objects: ' + Math.max( 1, checkout.files.length ) );
		} else if (subcommand === 'checkout' && args[1] === 'trunk') {
			appendLine( 'Already on trunk' );
		} else if (subcommand === 'show' && args[1] && args[1].indexOf( 'HEAD:' ) === 0) {
			printCat( args[1].replace( /^HEAD:/, '' ) );
		} else if (subcommand === 'diff') {
			appendLine( '(no local changes)', 'is-muted' );
		} else {
			appendLine( 'git: supported here: status, log, remote, branch, pull, add, commit, push, clone, checkout, show, diff', 'is-muted' );
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

		appendLine( '[trunk emulated] ' + message );
		appendLine( ' 1 file changed, 4 insertions(+), 1 deletion(-)' );
		appendLine( 'This emulator does not create commits. In your real terminal, `git commit` records local file edits before `git push` sends them to WordPress.', 'is-muted' );
	}

	function printGitPush() {
		if (progress.state !== 'done') {
			appendLine( 'remote: WP Origin is still preparing the repository.', 'is-warning' );
			appendLine( 'Real pushes are accepted after the import reaches 100%.', 'is-muted' );
			return;
		}

		appendLine( 'Enumerating objects: 5, done.' );
		appendLine( 'Writing objects: 100% (3/3), 412 bytes | 412.00 KiB/s, done.' );
		appendLine( 'remote: WP Origin would validate the pushed files, check permissions, and apply supported changes through WordPress.', 'is-success' );
		appendLine( 'To actually push changes, clone the URL below, edit files locally, then run `git push origin trunk` in your real terminal.', 'is-muted' );
	}

	function printGitLog() {
		var commits = progress.commits || [];
		if ( ! commits.length) {
			appendLine( 'No commits yet. Import is still warming up.', 'is-warning' );
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
			appendLine( 'Resetting the repository import...', 'is-warning' );
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
			button.textContent = 'Copied';
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
