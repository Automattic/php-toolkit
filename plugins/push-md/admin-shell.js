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
	var config            = window.pushMdAdminShell || {};
	var nonce             = config.nonce || '';
	var statusUrl         = config.statusUrl || '';
	var retryUrl          = config.retryUrl || '';
	var pullRequestsUrl   = config.pullRequestsUrl || '';
	var commentsUrl       = config.commentsUrl || '';
	var mergeBranchUrl    = config.mergeBranchUrl || '';
	var adminPageUrl      = config.adminPageUrl || '';
	var pullRequestId     = intval( config.pullRequestId );
	var reviewStates      = config.reviewStates || {};
	var remoteUrl         = config.remoteUrl || '';
	var checkoutDir       = config.checkoutDir || 'site';
	var cloneCommand      = config.cloneCommand || ('git clone ' + remoteUrl + ' ' + checkoutDir);
	var progress          = config.initialProgress || {};
	var stateEl           = document.getElementById( 'push-md-state' );
	var stateCopyEl       = document.getElementById( 'push-md-state-copy' );
	var statePillEl       = document.getElementById( 'push-md-state-pill' );
	var barEl             = document.getElementById( 'push-md-bar' );
	var percentEl         = document.getElementById( 'push-md-percent' );
	var countsEl          = document.getElementById( 'push-md-counts' );
	var messageEl         = document.getElementById( 'push-md-message' );
	var outputEl          = document.getElementById( 'push-md-terminal-output' );
	var inputEl           = document.getElementById( 'push-md-terminal-input' );
	var cwdEl             = document.getElementById( 'push-md-prompt-cwd' );
	var titleEl           = document.getElementById( 'push-md-terminal-title' );
	var commitListEl      = document.getElementById( 'push-md-commit-list' );
	var branchPanelEl     = document.getElementById( 'push-md-branches-panel' );
	var branchListEl      = document.getElementById( 'push-md-branch-list' );
	var branchMessageEl   = document.getElementById( 'push-md-branches-message' );
	var branchRefreshEl   = document.getElementById( 'push-md-branches-refresh' );
	var checkout          = normalizeCheckout( progress.checkout );
	var cwd               = '/';
	var history           = [];
	var historyIndex      = 0;
	var hasAnnouncedReady = progress.state === 'done';

	if (pullRequestId) {
		bootPullRequestReview();
		return;
	}

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
			__( '%1$d / %2$d', 'push-md' ),
			progress.processed,
			progress.total
		);
		messageEl.textContent = progress.message;
		renderCommits( progress.commits || [] );
		if (previousState !== 'done' && progress.state === 'done' && ! hasAnnouncedReady) {
			hasAnnouncedReady = true;
			appendLine( __( 'remote: Initial import complete. The checkout is ready.', 'push-md' ), 'is-success' );
			appendLine( __( 'Try: ls, git pull, git commit -m "Update content", git push', 'push-md' ), 'is-muted' );
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
			emptyItem.textContent = __( 'No commits yet', 'push-md' );
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

	function setBranchMessage(message, className) {
		if ( ! branchMessageEl) {
			return;
		}

		branchMessageEl.className   = 'push-md-branch-message' + (className ? ' ' + className : '');
		branchMessageEl.textContent = message || '';
	}

	function parseJsonResponse(response) {
		return response.text().then(
			function (text) {
				var data = {};
				if (text) {
					try {
						data = JSON.parse( text );
					} catch (error) {
						data = { message: text };
					}
				}

				if ( ! response.ok) {
					throw new Error( data.message || response.statusText || __( 'Request failed.', 'push-md' ) );
				}

				return data;
			}
		);
	}

	function fetchBranches() {
		if ( ! pullRequestsUrl || ! branchListEl || ! branchMessageEl) {
			return;
		}

		setBranchMessage( __( 'Loading Pull Requests...', 'push-md' ), 'is-muted' );
		fetch(
			pullRequestsUrl + '?context=edit&per_page=100&status=push_md_active,push_md_merged,push_md_closed&_fields=id,title,status,date,modified,meta,push_md_preview_url',
			{
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce }
			}
		).then(
			parseJsonResponse
		).then(
			function (data) {
				renderBranches( (data || []).map( normalizePullRequest ) );
			}
		).catch(
			function (error) {
				branchListEl.textContent = '';
				setBranchMessage( error.message || __( 'Could not load Pull Requests.', 'push-md' ), 'is-error' );
			}
		);
	}

	function normalizePullRequest(item) {
		var meta = item.meta || {};
		return {
			id: item.id,
			branch: meta.push_md_branch || (item.title && (item.title.raw || item.title.rendered)) || '',
			base_oid: meta.push_md_base_oid || '',
			tip_oid: meta.push_md_tip_oid || '',
			review_state: meta.push_md_review_state || 'pending',
			description: item.content && (item.content.raw || item.content.rendered) ? (item.content.raw || stripHtml( item.content.rendered )) : '',
			description_rendered: item.content && item.content.rendered ? item.content.rendered : '',
			status: item.status || '',
			url: item.push_md_preview_url || '',
			pull_request_url: adminPageUrl + '&pr=' + item.id,
			updated_at: Math.floor( Date.parse( item.modified || item.date || '' ) / 1000 )
		};
	}

	function renderBranches(branches) {
		branchListEl.textContent = '';
		branches                 = Array.isArray( branches ) ? branches.slice( 0 ) : [];
		if (branchPanelEl) {
			branchPanelEl.hidden = ! branches.length;
		}
		branches.sort(
			function (left, right) {
				return intval( right.updated_at ) - intval( left.updated_at );
			}
		);

		if ( ! branches.length) {
			setBranchMessage( __( 'No Pull Requests.', 'push-md' ), 'is-muted' );
			return;
		}

		var activeBranches = [];
		var mergedBranches = [];
		var closedBranches = [];
		branches.forEach(
			function (branch) {
				if (branch.status === 'push_md_merged') {
					mergedBranches.push( branch );
				} else if (branch.status === 'push_md_closed') {
					closedBranches.push( branch );
				} else {
					activeBranches.push( branch );
				}
			}
		);

		setBranchMessage( '' );
		if (activeBranches.length) {
			branchListEl.appendChild( createBranchSection( __( 'Open', 'push-md' ), activeBranches, 'is-active' ) );
		}
		if (mergedBranches.length) {
			branchListEl.appendChild( createBranchSection( __( 'Merged', 'push-md' ), mergedBranches, 'is-merged' ) );
		}
		if (closedBranches.length) {
			branchListEl.appendChild( createBranchSection( __( 'Closed', 'push-md' ), closedBranches, 'is-closed' ) );
		}
		if ( ! activeBranches.length) {
			setBranchMessage( __( 'No open Pull Requests.', 'push-md' ), 'is-muted' );
		}
	}

	function createBranchSection(title, branches, className) {
		var section = document.createElement( 'div' );
		var heading = document.createElement( 'h3' );

		section.className   = 'push-md-branch-section ' + className;
		heading.className   = 'push-md-branch-section-title';
		heading.textContent = title;
		section.appendChild( heading );

		branches.forEach(
			function (branch) {
				section.appendChild( createBranchRow( branch ) );
			}
		);

		return section;
	}

	function createBranchRow(branch) {
		var branchName = String( branch.branch || '' );
		var previewUrl = String( branch.url || '' );
		var isActive   = branch.status === 'push_md_active';
		var row        = document.createElement( 'div' );
		var details    = document.createElement( 'div' );
		var actions    = document.createElement( 'div' );
		var name       = document.createElement( 'a' );
		var meta       = document.createElement( 'div' );
		var preview    = document.createElement( 'a' );
		var copy       = document.createElement( 'button' );
		var merge      = document.createElement( 'button' );

		row.className     = 'push-md-branch-row' + (isActive ? '' : ' is-merged');
		details.className = 'push-md-branch-details';
		actions.className = 'push-md-branch-actions';
		name.className    = 'push-md-branch-name';
		meta.className    = 'push-md-branch-meta';
		name.textContent  = branchName;
		name.href         = branch.pull_request_url;

		meta.appendChild(
			createMetaItem(
				__( 'Updated', 'push-md' ),
				formatTimestamp( branch.updated_at )
			)
		);
		if (branch.tip_oid) {
			meta.appendChild( createMetaItem( __( 'Tip', 'push-md' ), shortOid( branch.tip_oid ) ) );
		}
		if (branch.base_oid) {
			meta.appendChild( createMetaItem( __( 'Base', 'push-md' ), shortOid( branch.base_oid ) ) );
		}
		details.appendChild( name );
		details.appendChild( createReviewStateBadge( branch.review_state ) );
		details.appendChild( meta );

		var review         = document.createElement( 'a' );
		review.className   = 'button';
		review.href        = branch.pull_request_url;
		review.textContent = __( 'Review', 'push-md' );
		actions.appendChild( review );

		if ( ! isActive) {
			row.appendChild( details );
			row.appendChild( actions );

			return row;
		}

		preview.className   = 'button';
		preview.href        = previewUrl;
		preview.target      = '_blank';
		preview.rel         = 'noopener noreferrer';
		preview.textContent = __( 'Preview', 'push-md' );

		copy.type        = 'button';
		copy.className   = 'button';
		copy.textContent = __( 'Copy URL', 'push-md' );
		copy.addEventListener(
			'click',
			function () {
				copyText( previewUrl, copy );
			}
		);

		merge.type        = 'button';
		merge.className   = 'button button-primary';
		merge.textContent = __( 'Merge', 'push-md' );
		merge.addEventListener(
			'click',
			function () {
				mergeBranch( branchName, merge );
			}
		);

		if (previewUrl) {
			actions.appendChild( preview );
		}
		actions.appendChild( copy );
		actions.appendChild( merge );
		row.appendChild( details );
		row.appendChild( actions );

		return row;
	}

	function createMetaItem(label, value) {
		var item            = document.createElement( 'span' );
		var labelEl         = document.createElement( 'span' );
		var valueEl         = document.createElement( 'strong' );
		item.className      = 'push-md-branch-meta-item';
		labelEl.textContent = label + ': ';
		valueEl.textContent = value;
		item.appendChild( labelEl );
		item.appendChild( valueEl );

		return item;
	}

	function reviewStateLabel(state) {
		return reviewStates[state] && reviewStates[state].label ? reviewStates[state].label : state;
	}

	function createReviewStateBadge(state) {
		var badge         = document.createElement( 'span' );
		state             = String( state || 'pending' );
		badge.className   = 'push-md-review-state is-' + state.replace( /[^a-z0-9_-]/g, '-' );
		badge.textContent = reviewStateLabel( state );

		return badge;
	}

	function createChangedUrlList(changedUrls, isMerged) {
		var list       = document.createElement( 'ul' );
		list.className = 'push-md-changed-url-list';
		changedUrls    = Array.isArray( changedUrls ) ? changedUrls : [];

		if ( ! changedUrls.length) {
			var empty         = document.createElement( 'li' );
			empty.className   = 'is-muted';
			empty.textContent = __( 'No changed preview URLs available.', 'push-md' );
			list.appendChild( empty );
			return list;
		}

		changedUrls.forEach(
			function (item) {
				var row    = document.createElement( 'li' );
				var action = document.createElement( 'span' );
				var target = isMerged ? document.createElement( 'span' ) : document.createElement( 'a' );

				action.className   = 'push-md-changed-action';
				action.textContent = formatChangedAction( item.action );
				target.className   = 'push-md-changed-target';
				target.textContent = String( item.path || item.url || '' );
				if ( ! isMerged) {
					target.href   = String( item.url || '' );
					target.target = '_blank';
					target.rel    = 'noopener noreferrer';
				}

				row.appendChild( action );
				row.appendChild( target );
				list.appendChild( row );
			}
		);

		return list;
	}

	function formatChangedAction(action) {
		if (action === 'created') {
			return __( 'Created', 'push-md' );
		}
		if (action === 'deleted') {
			return __( 'Deleted', 'push-md' );
		}
		if (action === 'updated') {
			return __( 'Updated', 'push-md' );
		}

		return __( 'Changed', 'push-md' );
	}

	function bootPullRequestReview() {
		var view    = document.getElementById( 'push-md-pr-view' );
		var message = document.getElementById( 'push-md-pr-message' );
		if ( ! view || ! pullRequestsUrl || ! commentsUrl) {
			return;
		}

		message.textContent = __( 'Loading Pull Request...', 'push-md' );
		Promise.all(
			[
				fetch(
					pullRequestsUrl + '/' + pullRequestId + '?context=edit&_fields=id,title,content,status,date,modified,meta,push_md_diff,push_md_preview_url',
					{ credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } }
				).then( parseJsonResponse ),
				fetch(
					commentsUrl + '?context=edit&post=' + pullRequestId + '&type=note&status=all&per_page=100&_fields=id,author_name,date,content,meta,parent,push_md_tip_oid',
					{ credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } }
				).then( parseJsonResponse )
			]
		).then(
			function (results) {
				message.textContent = '';
				renderPullRequestReview( normalizePullRequest( results[0] ), results[0].push_md_diff || { files: [] }, results[1] || [] );
			}
		).catch(
			function (error) {
				view.textContent    = '';
				message.textContent = error.message || __( 'Could not load Pull Request.', 'push-md' );
				message.className   = 'push-md-branch-message is-error';
			}
		);
	}

	function renderPullRequestReview(pullRequest, diff, notes) {
		var view         = document.getElementById( 'push-md-pr-view' );
		var header       = document.createElement( 'div' );
		var title        = document.createElement( 'h1' );
		var status       = document.createElement( 'span' );
		var reviewState  = createReviewStateBadge( pullRequest.review_state );
		var statusGroup  = document.createElement( 'div' );
		var meta         = document.createElement( 'div' );
		var actions      = document.createElement( 'div' );
		var isActive     = pullRequest.status === 'push_md_active';
		var usedNoteIds  = {};
		var generalNotes = notes.filter(
			function (note) {
				return ! note.meta || ! note.meta.push_md_path;
			}
		);

		view.textContent      = '';
		header.className      = 'push-md-pr-header';
		title.textContent     = pullRequest.branch;
		status.className      = 'push-md-pr-status ' + pullRequest.status;
		status.textContent    = pullRequest.status === 'push_md_merged' ? __( 'Merged', 'push-md' ) : (pullRequest.status === 'push_md_closed' ? __( 'Closed', 'push-md' ) : __( 'Open', 'push-md' ));
		statusGroup.className = 'push-md-pr-states';
		statusGroup.appendChild( status );
		statusGroup.appendChild( reviewState );
		meta.className = 'push-md-branch-meta';
		meta.appendChild( createMetaItem( __( 'Base', 'push-md' ), shortOid( pullRequest.base_oid ) ) );
		meta.appendChild( createMetaItem( __( 'Tip', 'push-md' ), shortOid( pullRequest.tip_oid ) ) );
		meta.appendChild( createMetaItem( __( 'Updated', 'push-md' ), formatTimestamp( pullRequest.updated_at ) ) );
		actions.className = 'push-md-pr-actions';
		if (isActive && pullRequest.url) {
			var preview         = document.createElement( 'a' );
			preview.className   = 'button';
			preview.href        = pullRequest.url;
			preview.target      = '_blank';
			preview.rel         = 'noopener noreferrer';
			preview.textContent = __( 'Preview', 'push-md' );
			actions.appendChild( preview );

			var merge         = document.createElement( 'button' );
			merge.type        = 'button';
			merge.className   = 'button button-primary';
			merge.textContent = __( 'Merge', 'push-md' );
			merge.addEventListener(
				'click',
				function () {
					mergeBranch( pullRequest.branch, merge ); }
			);
			actions.appendChild( merge );
		}
		header.appendChild( statusGroup );
		header.appendChild( title );
		header.appendChild( meta );
		header.appendChild( actions );
		header.appendChild( createDescriptionPanel( pullRequest, isActive ) );
		view.appendChild( header );
		view.appendChild( createCommitHistoryPanel( diff && diff.commits ? diff.commits : [] ) );
		view.appendChild( createConversationPanel( generalNotes, isActive, usedNoteIds ) );

		var files = diff && Array.isArray( diff.files ) ? diff.files : [];
		files.forEach(
			function (file) {
				view.appendChild( createDiffFile( file, notes, isActive, usedNoteIds ) );
			}
		);
		if ( ! files.length) {
			var empty         = document.createElement( 'div' );
			empty.className   = 'push-md-panel';
			empty.textContent = __( 'No changed files.', 'push-md' );
			view.appendChild( empty );
		}

		var unplaced = notes.filter(
			function (note) {
				return note.meta && note.meta.push_md_path && ! usedNoteIds[note.id];
			}
		);
		if (unplaced.length) {
			var unplacedPanel         = document.createElement( 'div' );
			var unplacedTitle         = document.createElement( 'h2' );
			unplacedPanel.className   = 'push-md-panel';
			unplacedTitle.textContent = __( 'Unplaced comments', 'push-md' );
			unplacedPanel.appendChild( unplacedTitle );
			unplaced.forEach(
				function (note) {
					unplacedPanel.appendChild( createNote( note, noteAnchorLabel( note ) ) ); }
			);
			view.appendChild( unplacedPanel );
		}
	}

	function createDescriptionPanel(pullRequest, isActive) {
		var panel         = document.createElement( 'section' );
		var title         = document.createElement( 'h2' );
		var description   = document.createElement( 'div' );
		panel.className   = 'push-md-pr-description';
		title.textContent = __( 'Description', 'push-md' );
		panel.appendChild( title );
		description.className = 'push-md-pr-description-content' + (pullRequest.description ? '' : ' is-empty');
		description.innerHTML = pullRequest.description_rendered || '';

		if ( ! isActive) {
			if ( ! pullRequest.description) {
				description.textContent = __( 'No description provided.', 'push-md' );
			}
			panel.appendChild( description );

			return panel;
		}

		var form                    = document.createElement( 'form' );
		var button                  = document.createElement( 'button' );
		var initialContent          = description.innerHTML;
		form.className              = 'push-md-pr-description-form';
		description.contentEditable = 'true';
		description.setAttribute( 'role', 'textbox' );
		description.setAttribute( 'aria-multiline', 'true' );
		description.setAttribute( 'data-placeholder', __( 'Describe this Pull Request.', 'push-md' ) );
		button.type        = 'submit';
		button.className   = 'button button-primary';
		button.textContent = __( 'Save description', 'push-md' );
		button.hidden      = true;
		form.appendChild( description );
		form.appendChild( button );
		description.addEventListener(
			'input',
			function () {
				description.classList.toggle( 'is-empty', '' === description.textContent.trim() );
				button.hidden = description.innerHTML === initialContent;
			}
		);
		form.addEventListener(
			'submit',
			function (event) {
				event.preventDefault();
				if (button.hidden) {
					return;
				}
				button.disabled = true;
				updatePullRequestDescription( description.textContent.trim() ? description.innerHTML : '' ).catch(
					function (error) {
						button.disabled = false;
						window.alert( error.message || __( 'Could not save the Pull Request description.', 'push-md' ) );
					}
				);
			}
		);
		panel.appendChild( form );

		return panel;
	}

	function createCommitHistoryPanel(commits) {
		var panel         = document.createElement( 'section' );
		var title         = document.createElement( 'h2' );
		var list          = document.createElement( 'ol' );
		panel.className   = 'push-md-panel push-md-pr-commit-history';
		title.textContent = __( 'Commit history', 'push-md' );
		list.className    = 'push-md-pr-commit-list';
		panel.appendChild( title );
		panel.appendChild( list );
		commits = Array.isArray( commits ) ? commits : [];
		if ( ! commits.length) {
			var empty         = document.createElement( 'li' );
			empty.className   = 'is-empty';
			empty.textContent = __( 'No commits in this Pull Request.', 'push-md' );
			list.appendChild( empty );

			return panel;
		}

		commits.forEach(
			function (commit) {
				var item            = document.createElement( 'li' );
				var oid             = document.createElement( 'code' );
				var copy            = document.createElement( 'div' );
				var subject         = document.createElement( 'strong' );
				oid.textContent     = shortOid( commit.oid );
				subject.textContent = commit.subject || __( '(no message)', 'push-md' );
				copy.className      = 'push-md-pr-commit-copy';
				item.appendChild( oid );
				copy.appendChild( subject );
				if (commit.description) {
					var description         = document.createElement( 'p' );
					description.textContent = commit.description;
					copy.appendChild( description );
				}
				item.appendChild( copy );
				list.appendChild( item );
			}
		);

		return panel;
	}

	function createConversationPanel(notes, isActive, usedNoteIds) {
		var panel         = document.createElement( 'div' );
		var title         = document.createElement( 'h2' );
		var list          = document.createElement( 'div' );
		panel.className   = 'push-md-panel push-md-conversation';
		title.textContent = __( 'Conversation', 'push-md' );
		list.className    = 'push-md-notes';
		notes.forEach(
			function (note) {
				usedNoteIds[note.id] = true;
				list.appendChild( createNote( note, '' ) );
			}
		);
		panel.appendChild( title );
		panel.appendChild( list );
		if (isActive) {
			panel.appendChild( createNoteForm( {}, __( 'Leave a comment', 'push-md' ), true ) );
		}
		return panel;
	}

	function createDiffFile(file, notes, isActive, usedNoteIds) {
		var container       = document.createElement( 'section' );
		var header          = document.createElement( 'div' );
		var path            = document.createElement( 'code' );
		var badge           = document.createElement( 'span' );
		var rows            = document.createElement( 'div' );
		container.className = 'push-md-diff-file';
		header.className    = 'push-md-diff-header';
		path.textContent    = file.path;
		badge.className     = 'push-md-changed-action';
		badge.textContent   = formatChangedAction( file.action );
		header.appendChild( path );
		header.appendChild( badge );
		if (file.preview_url) {
			var preview         = document.createElement( 'a' );
			preview.href        = file.preview_url;
			preview.target      = '_blank';
			preview.rel         = 'noopener noreferrer';
			preview.textContent = __( 'Preview file', 'push-md' );
			header.appendChild( preview );
		}
		rows.className = 'push-md-diff-rows';
		(file.rows || []).forEach(
			function (row) {
				var line              = document.createElement( 'div' );
				var add               = document.createElement( 'button' );
				var oldNumber         = document.createElement( 'span' );
				var newNumber         = document.createElement( 'span' );
				var content           = document.createElement( 'code' );
				var anchorSide        = row.new_line !== null ? 'new' : 'old';
				var anchorLine        = row.new_line !== null ? row.new_line : row.old_line;
				line.className        = 'push-md-diff-line is-' + row.type;
				add.type              = 'button';
				add.className         = 'push-md-diff-add';
				add.textContent       = '+';
				add.title             = __( 'Comment on this line', 'push-md' );
				add.disabled          = ! isActive;
				oldNumber.textContent = row.old_line === null ? '' : row.old_line;
				newNumber.textContent = row.new_line === null ? '' : row.new_line;
				content.textContent   = row.content;
				line.appendChild( add );
				line.appendChild( oldNumber );
				line.appendChild( newNumber );
				line.appendChild( content );
				if (isActive) {
					add.addEventListener(
						'click',
						function () {
							var existing = line.nextSibling;
							if (existing && existing.classList && existing.classList.contains( 'push-md-inline-form' )) {
								existing.remove();
								return;
							}
							var form = createNoteForm(
								{ push_md_path: file.path, push_md_side: anchorSide, push_md_line: anchorLine },
								__( 'Comment on line', 'push-md' ) + ' ' + anchorLine
							);
							form.classList.add( 'push-md-inline-form' );
							line.parentNode.insertBefore( form, line.nextSibling );
						}
					);
				}
				rows.appendChild( line );
				appendAnchoredNotes( rows, notes, file.path, row, usedNoteIds );
			}
		);
		container.appendChild( header );
		container.appendChild( rows );
		return container;
	}

	function appendAnchoredNotes(container, notes, path, row, usedNoteIds) {
		notes.forEach(
			function (note) {
				var meta       = note.meta || {};
				var matchesOld = meta.push_md_path === path && meta.push_md_side === 'old' && intval( meta.push_md_line ) === intval( row.old_line );
				var matchesNew = meta.push_md_path === path && meta.push_md_side === 'new' && intval( meta.push_md_line ) === intval( row.new_line );
				if (matchesOld || matchesNew) {
					usedNoteIds[note.id] = true;
					container.appendChild( createNote( note, noteAnchorLabel( note ) ) );
				}
			}
		);
	}

	function createNote(note, label) {
		var element         = document.createElement( 'article' );
		var header          = document.createElement( 'div' );
		var content         = document.createElement( 'div' );
		element.className   = 'push-md-note';
		header.className    = 'push-md-note-header';
		var stateLabel      = note.meta && note.meta.push_md_review_state
			? sprintf( __( 'Changed state to %s', 'push-md' ), reviewStateLabel( note.meta.push_md_review_state ) )
			: '';
		header.textContent  = (note.author_name || __( 'Reviewer', 'push-md' )) + (label ? ' · ' + label : '') + (stateLabel ? ' · ' + stateLabel : '');
		content.className   = 'push-md-note-content';
		content.textContent = note.content && (note.content.raw || note.content.rendered) ? (note.content.raw || stripHtml( note.content.rendered )) : '';
		element.appendChild( header );
		element.appendChild( content );
		return element;
	}

	function createNoteForm(meta, label, allowReviewState) {
		var form             = document.createElement( 'form' );
		var textarea         = document.createElement( 'textarea' );
		var button           = document.createElement( 'button' );
		var stateSelect      = null;
		form.className       = 'push-md-note-form';
		textarea.rows        = 3;
		textarea.placeholder = label;
		button.type          = 'submit';
		button.className     = 'button button-primary';
		button.textContent   = __( 'Comment', 'push-md' );
		form.appendChild( textarea );
		if (allowReviewState) {
			var stateField             = document.createElement( 'label' );
			var stateFieldText         = document.createElement( 'span' );
			stateSelect                = document.createElement( 'select' );
			stateField.className       = 'push-md-note-state-field';
			stateFieldText.textContent = __( 'Change state', 'push-md' );
			var noChange               = document.createElement( 'option' );
			noChange.value             = '';
			noChange.textContent       = __( 'No state change', 'push-md' );
			stateSelect.appendChild( noChange );
			Object.keys( reviewStates ).forEach(
				function (state) {
					var option         = document.createElement( 'option' );
					option.value       = state;
					option.textContent = reviewStateLabel( state );
					stateSelect.appendChild( option );
				}
			);
			stateField.appendChild( stateFieldText );
			stateField.appendChild( stateSelect );
			form.appendChild( stateField );
		}
		form.appendChild( button );
		form.addEventListener(
			'submit',
			function (event) {
				event.preventDefault();
				if ( ! textarea.value.trim()) {
					return;
				}
				var submittedMeta = {};
				Object.keys( meta ).forEach(
					function (key) {
						submittedMeta[key] = meta[key]; }
				);
				if (stateSelect && stateSelect.value) {
					submittedMeta.push_md_review_state = stateSelect.value;
				}
				button.disabled = true;
				postNote( textarea.value, submittedMeta ).catch(
					function (error) {
						button.disabled = false;
						window.alert( error.message || __( 'Could not save comment.', 'push-md' ) );
					}
				);
			}
		);
		return form;
	}

	function postNote(content, meta) {
		return fetch(
			commentsUrl,
			{
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify( { post: pullRequestId, type: 'note', content: content, meta: meta } )
			}
		).then( parseJsonResponse ).then( bootPullRequestReview );
	}

	function updatePullRequestDescription(content) {
		return fetch(
			pullRequestsUrl + '/' + pullRequestId,
			{
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify( { content: content } )
			}
		).then( parseJsonResponse ).then( bootPullRequestReview );
	}

	function noteAnchorLabel(note) {
		var meta = note.meta || {};
		return String( meta.push_md_path || '' ) + ':' + String( meta.push_md_line || '' ) + ' (' + String( meta.push_md_side || '' ) + ')';
	}

	function stripHtml(value) {
		var element       = document.createElement( 'div' );
		element.innerHTML = String( value || '' );
		return element.textContent || '';
	}

	function mergeBranch(branchName, button) {
		if ( ! mergeBranchUrl) {
			return;
		}
		if ( ! window.confirm( sprintf( __( 'Merge "%s" into live WordPress content?', 'push-md' ), branchName ) ) ) {
			return;
		}

		var originalText   = button.textContent;
		button.disabled    = true;
		button.textContent = __( 'Merging...', 'push-md' );
		setBranchMessage( sprintf( __( 'Merging %s...', 'push-md' ), branchName ), 'is-warning' );

		fetch(
			mergeBranchUrl,
			{
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				body: JSON.stringify( { branch: branchName } )
			}
		).then(
			parseJsonResponse
		).then(
			function (data) {
				setBranchMessage( sprintf( __( 'Merged %s into live content.', 'push-md' ), data.branch || branchName ), 'is-success' );
				if (pullRequestId) {
					bootPullRequestReview();
				} else {
					fetchBranches();
					poll();
				}
			}
		).catch(
			function (error) {
				button.disabled    = false;
				button.textContent = originalText;
				setBranchMessage( error.message || sprintf( __( 'Could not merge %s.', 'push-md' ), branchName ), 'is-error' );
			}
		);
	}

	function intval(value) {
		var parsed = parseInt( value, 10 );
		return isNaN( parsed ) ? 0 : parsed;
	}

	function formatTimestamp(value) {
		var timestamp = intval( value );
		if ( ! timestamp) {
			return __( 'Unknown', 'push-md' );
		}

		return new Date( timestamp * 1000 ).toLocaleString();
	}

	function shortOid(value) {
		value = String( value || '' );
		return value.length > 12 ? value.substring( 0, 12 ) : value;
	}

	function appendLine(text, className) {
		var line       = document.createElement( 'div' );
		line.className = 'push-md-terminal-line';
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
				__( 'emulator:%1$s$ %2$s', 'push-md' ),
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
			__( 'emulator:%s', 'push-md' ),
			displayCwd()
		);
	}

	function bootTranscript() {
		outputEl.textContent = '';
		appendLine( __( 'Push MD command emulator', 'push-md' ), 'is-success' );
		appendLine( __( 'This preview mirrors commands you can run in your real terminal after cloning.', 'push-md' ), 'is-muted' );
		appendLine( __( 'No server shell is opened here, and emulator commands do not change WordPress.', 'push-md' ), 'is-muted' );
		appendLine( '' );
		appendPrompt( cloneCommand );
		appendLine(
			sprintf(
				/* translators: %s: Checkout directory name. */
				__( "Cloning into '%s'...", 'push-md' ),
				checkoutDir
			)
		);
		if (progress.state === 'done') {
			appendLine( __( 'remote: Initial import complete.', 'push-md' ), 'is-success' );
		} else {
			appendLine(
				sprintf(
					/* translators: %d: Import progress percentage. */
					__( 'remote: Preparing WordPress content (%d%%)...', 'push-md' ),
					progress.percent
				),
				'is-warning'
			);
		}
		appendLine(
			sprintf(
				/* translators: %d: Number of checkout entries. */
				__( 'Receiving objects: %d checkout entries', 'push-md' ),
				Math.max( 1, checkout.files.length )
			)
		);
		appendPrompt( 'cd ' + checkoutDir );
		cwd = '/';
		updatePrompt();
		runCommand( 'git status', { silentHistory: true } );
		if (checkout.files.length) {
			runCommand( 'ls', { silentHistory: true } );
			appendLine( __( 'Try: git pull, git commit -m "Update content", git push', 'push-md' ), 'is-muted' );
		} else {
			appendLine( __( 'The emulated file tree will appear here as soon as the first commit is staged.', 'push-md' ), 'is-muted' );
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
					__( '%s: command not found. Try `help`.', 'push-md' ),
					base
				),
				'is-error'
			);
		}
	}

	function printHelp() {
		appendLine( __( 'Emulated commands:', 'push-md' ), 'is-success' );
		appendLine( __( '  ls [-l] [path]        list checkout files', 'push-md' ) );
		appendLine( __( '  cd [path]             change directory', 'push-md' ) );
		appendLine( __( '  pwd                   print current directory', 'push-md' ) );
		appendLine( __( '  cat <file>            show a preview of a file', 'push-md' ) );
		appendLine( __( '  tree [path]           show the checkout shape', 'push-md' ) );
		appendLine( __( '  git status            show import and branch state', 'push-md' ) );
		appendLine( __( '  git log --oneline     show recent Push MD commits', 'push-md' ) );
		appendLine( __( '  git remote -v         show the WordPress remote', 'push-md' ) );
		appendLine( __( '  git pull              preview refreshing from WordPress', 'push-md' ) );
		appendLine( __( '  git add <path>        preview staging a change', 'push-md' ) );
		appendLine( __( '  git commit -m "..."   preview a local content commit', 'push-md' ) );
		appendLine( __( '  git push              preview sending changes back', 'push-md' ) );
		appendLine( __( '  clear                 clear the terminal', 'push-md' ) );
		appendLine( __( 'Use the copy table below for the real terminal URL and clone command.', 'push-md' ), 'is-muted' );
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
					__( 'ls: %s: No such file or directory', 'push-md' ),
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
			appendLine( __( '(empty)', 'push-md' ), 'is-muted' );
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
					__( 'Preview is limited to the first %d paths.', 'push-md' ),
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
					__( 'cd: %s: No such directory', 'push-md' ),
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
			appendLine( __( 'cat: missing file operand', 'push-md' ), 'is-error' );
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
					__( 'cat: %s: No such file', 'push-md' ),
					path
				),
				'is-error'
			);
			return;
		}
		if (file.type === 'symlink') {
			appendLine( file.content || __( '(symlink target unavailable)', 'push-md' ) );
			return;
		}
		if (file.content === undefined) {
			appendLine( __( 'Preview content for this file is not loaded in the shell.', 'push-md' ), 'is-warning' );
			appendLine(
				sprintf(
					/* translators: %s: Suggested file path. */
					__( 'Try: cat %s', 'push-md' ),
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
					__( 'tree: %s: No such directory', 'push-md' ),
					path
				),
				'is-error'
			);
			return;
		}
		appendLine( root === '/' ? '.' : root.replace( /^\//, '' ) );
		printTreeChildren( root, '', 0 );
		if (checkout.truncated) {
			appendLine( __( 'Preview is limited; clone or pull for the full tree.', 'push-md' ), 'is-muted' );
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
			appendLine( __( 'On branch trunk', 'push-md' ) );
			if (progress.state === 'done') {
				appendLine( __( 'Your branch is up to date with origin/trunk.', 'push-md' ), 'is-success' );
				appendLine( __( 'nothing to commit, working tree clean', 'push-md' ) );
			} else if (progress.state === 'failed') {
				appendLine(
					sprintf(
						/* translators: %s: Import failure message. */
						__( 'remote: import failed: %s', 'push-md' ),
						progress.message
					),
					'is-error'
				);
			} else {
				appendLine(
					sprintf(
						/* translators: %d: Import progress percentage. */
						__( 'remote: preparing repository (%d%%)', 'push-md' ),
						progress.percent
					),
					'is-warning'
				);
				appendLine(
					sprintf(
						/* translators: 1: Imported item count, 2: Total item count. */
						__( '%1$d / %2$d content items imported', 'push-md' ),
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
					__( 'origin  %s (fetch)', 'push-md' ),
					remoteUrl
				)
			);
			appendLine(
				sprintf(
					/* translators: %s: Git remote URL. */
					__( 'origin  %s (push)', 'push-md' ),
					remoteUrl
				)
			);
		} else if (subcommand === 'branch') {
			appendLine( __( '* trunk', 'push-md' ) );
		} else if (subcommand === 'pull') {
			if (progress.state === 'done') {
				appendLine(
					sprintf(
						/* translators: %s: Git remote URL. */
						__( 'From %s', 'push-md' ),
						remoteUrl
					)
				);
				appendLine( __( ' * branch            trunk      -> FETCH_HEAD', 'push-md' ) );
				appendLine( __( 'Already up to date.', 'push-md' ), 'is-success' );
				appendLine( __( 'In your real terminal, `git pull` refreshes this checkout from WordPress.', 'push-md' ), 'is-muted' );
			} else {
				appendLine( __( 'Repository is still preparing. Try again shortly.', 'push-md' ), 'is-warning' );
			}
		} else if (subcommand === 'add') {
			appendLine(
				sprintf(
					/* translators: %s: Path staged in the emulator. */
					__( 'Staged in emulator only: %s', 'push-md' ),
					args.slice( 1 ).join( ' ' ) || '.'
				)
			);
			appendLine( __( 'In your real terminal, `git add` stages file edits before committing.', 'push-md' ), 'is-muted' );
		} else if (subcommand === 'commit') {
			printGitCommit( args.slice( 1 ) );
		} else if (subcommand === 'push') {
			printGitPush();
		} else if (subcommand === 'clone') {
			appendLine(
				sprintf(
					/* translators: %s: Checkout directory name. */
					__( "Cloning into '%s'...", 'push-md' ),
					args[2] || checkoutDir
				)
			);
			appendLine(
				sprintf(
					/* translators: %s: Git remote URL. */
					__( 'remote: Push MD at %s', 'push-md' ),
					remoteUrl
				)
			);
			appendLine(
				sprintf(
					/* translators: %d: Number of received objects. */
					__( 'Receiving objects: %d', 'push-md' ),
					Math.max( 1, checkout.files.length )
				)
			);
		} else if (subcommand === 'checkout' && args[1] === 'trunk') {
			appendLine( __( 'Already on trunk', 'push-md' ) );
		} else if (subcommand === 'show' && args[1] && args[1].indexOf( 'HEAD:' ) === 0) {
			printCat( args[1].replace( /^HEAD:/, '' ) );
		} else if (subcommand === 'diff') {
			appendLine( __( '(no local changes)', 'push-md' ), 'is-muted' );
		} else {
			appendLine( __( 'git: supported here: status, log, remote, branch, pull, add, commit, push, clone, checkout, show, diff', 'push-md' ), 'is-muted' );
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
				__( '[trunk emulated] %s', 'push-md' ),
				message
			)
		);
		appendLine( __( ' 1 file changed, 4 insertions(+), 1 deletion(-)', 'push-md' ) );
		appendLine( __( 'This emulator does not create commits. In your real terminal, `git commit` records local file edits before `git push` sends them to WordPress.', 'push-md' ), 'is-muted' );
	}

	function printGitPush() {
		if (progress.state !== 'done') {
			appendLine( __( 'remote: Push MD is still preparing the repository.', 'push-md' ), 'is-warning' );
			appendLine( __( 'Real pushes are accepted after the import reaches 100%.', 'push-md' ), 'is-muted' );
			return;
		}

		appendLine( __( 'Enumerating objects: 5, done.', 'push-md' ) );
		appendLine( __( 'Writing objects: 100% (3/3), 412 bytes | 412.00 KiB/s, done.', 'push-md' ) );
		appendLine( __( 'remote: Push MD would validate the pushed files, check permissions, and apply supported changes through WordPress.', 'push-md' ), 'is-success' );
		appendLine( __( 'To actually push changes, clone the URL below, edit files locally, then run `git push origin trunk` in your real terminal.', 'push-md' ), 'is-muted' );
	}

	function printGitLog() {
		var commits = progress.commits || [];
		if ( ! commits.length) {
			appendLine( __( 'No commits yet. Import is still warming up.', 'push-md' ), 'is-warning' );
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

	document.getElementById( 'push-md-retry' ).addEventListener(
		'click',
		function () {
			appendLine( '' );
			appendPrompt( 'push-md seed retry' );
			appendLine( __( 'Resetting the repository import...', 'push-md' ), 'is-warning' );
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
			button.textContent = __( 'Copied', 'push-md' );
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

	Array.prototype.slice.call( document.querySelectorAll( '.push-md-copy-button' ) ).forEach(
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

	Array.prototype.slice.call( document.querySelectorAll( '[data-push-md-command]' ) ).forEach(
		function (button) {
			button.addEventListener(
				'click',
				function () {
					runCommand( button.getAttribute( 'data-push-md-command' ) );
					inputEl.focus();
				}
			);
		}
	);

	if (branchRefreshEl) {
		branchRefreshEl.addEventListener( 'click', fetchBranches );
	}

	render( progress );
	fetchBranches();
	updatePrompt();
	bootTranscript();
	poll();
}());
