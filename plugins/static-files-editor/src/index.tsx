import React, { useMemo } from 'react';
import { useEffect, createRoot } from '@wordpress/element';
import { FileNode, FilePickerTree } from './components/FilePickerTree';
import { MobileMenu } from './components/MobileMenu/index';
import { store as editorStore, ErrorBoundary } from '@wordpress/editor';
import { store as preferencesStore } from '@wordpress/preferences';
import {
	register,
	dispatch,
	select,
	resolveSelect,
	subscribe,
	useSelect,
} from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { store as noticesStore } from '@wordpress/notices';
import {
	addComponentToEditorContentArea,
	addLocalFilesTab,
} from './add-local-files-tab';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { parse, serialize } from '@wordpress/blocks';
import { Spinner } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import css from './style.module.css';
import { FileSubtree } from 'components/FilePickerTree/types';
import './blocks/diff';
import {
	uiStore,
	WP_LOCAL_FILE_POST_TYPE,
	isPreviewableAssetPath,
} from './store';
import { threeWayMerge, validateMergedBlockMarkup } from './merge';

// Register middleware to log any 500 error responses for easier
// debugging in development mode.
apiFetch.use((options, next) => {
	return next(options).catch((error) => {
		if (error?.data?.status === 500) {
			console.log(error.data.error?.message);
		} else if (error.message) {
			console.log(error.message);
		}
		throw error;
	});
});

const API_ROOT = (window as any).wpApiSettings.root;

const getCurrentNonce = () => {
	return (window as any).wpApiSettings.nonce;
};

const apiUrl = (path: string) => {
	const url = new URL(API_ROOT + path, window.location.href);
	url.searchParams.set('_wpnonce', getCurrentNonce());
	return url.toString();
};

// Create a custom store for transient UI state

register(uiStore);

type ConnectedFileNode = FileNode & {
	post_id?: string;
};

function filesListToTree(list: ConnectedFileNode[]): ConnectedFileNode {
	const findChildren = (parentPath: string) => {
		return list
			.filter((item) => isDirectParentOf(parentPath, item.path))
			.map((item) => ({
				...item,
				children: findChildren(item.path),
			}));
	};

	return {
		path: '/',
		name: '',
		type: 'directory',
		children: list
			.filter((item) => item.path.split('/').length === 2)
			.map((item) => ({
				...item,
				children: findChildren(item.path),
			})),
	};
}

function isDirectParentOf(parentPath: string, childPath: string) {
	return (
		childPath.startsWith(parentPath + '/') &&
		!childPath.substring(parentPath.length + 1).includes('/')
	);
}

function ConnectedFilePickerTree() {
	const { selectedPath, filesList, isFileListInitialized } = useSelect(
		(select) => {
			const originalFilesList =
				select(coreStore).getEntityRecords(
					'static-files-editor',
					'files',
					{
						per_page: -1,
					}
				) || [];
			const filesList = originalFilesList
				.map((file) =>
					select(coreStore).getEditedEntityRecord(
						'static-files-editor',
						'files',
						file.id
					)
				)
				.map((file) => ({
					...file,
					name:
						select(coreStore).getEditedEntityRecord(
							'postType',
							WP_LOCAL_FILE_POST_TYPE,
							file.post_id
						)?.title ||
						file.path.split('/').pop() ||
						'',
				}))
				.filter((file) => !file.isDeleted);
			return {
				selectedPath: select(uiStore).getSelectedPath(),
				filesList,
				isFileListInitialized:
					filesList.length ||
					!select(coreStore).isResolving('getEntityRecords', [
						'static-files-editor',
						'files',
						{
							per_page: -1,
						},
					]),
			};
		},
		[]
	);

	// One-time only – initialize the selected path
	useEffect(() => {
		if (
			!isFileListInitialized ||
			selectedPath !== undefined ||
			filesList.length === 0
		) {
			return;
		}
		const initialEditedPostId = select(editorStore).getCurrentPostId();
		const initialFile = filesList.find(
			(file) => file.post_id === initialEditedPostId
		);
		dispatch(uiStore).setSelectedPath(initialFile?.path || '/');
	}, [isFileListInitialized]);

	const fileTree = useMemo(() => {
		return filesListToTree(filesList);
	}, [filesList]);

	const onNavigateToEntityRecord = useSelect(
		(select) =>
			select(blockEditorStore).getSettings().onNavigateToEntityRecord
	);

	const handleNodeDeleted = async (path: string) => {
		// For optimistic updates
		dispatch(coreStore).editEntityRecord(
			'static-files-editor',
			'files',
			path,
			{
				isDeleted: true,
			}
		);
		try {
			dispatch(coreStore).deleteEntityRecord(
				'static-files-editor',
				'files',
				path
			);
		} catch (e) {
			// Naively assume we haven't edited anything else in the meantime
			dispatch(coreStore).undo();
			dispatch(noticesStore).createErrorNotice(
				'Error moving file. Please try again.',
				{
					type: 'snackbar',
				}
			);
		}
	};

	const handleFileClick = async (path: string) => {
		dispatch(uiStore).setSelectedPath(path);
	};

	const handleNodesCreated = async (tree: FileSubtree) => {
		if (
			tree.children.length > 1 ||
			tree.children[0].type !== 'file' ||
			tree.children[0].content instanceof File
		) {
			// Batch create multiple files
			await dispatch(uiStore).createFilesBatch(tree);
			return;
		}
		// Create a single file
		const node = tree.children[0];
		const nodePath = `${tree.path}/${node.name}`.replace(/^\/+/, '/');
		let newFile = null;
		try {
			newFile = await dispatch(coreStore).saveEntityRecord(
				'static-files-editor',
				'files',
				{
					path: nodePath,
					content: node.content || '',
				},
				{ throwOnError: true }
			);
		} catch (e) {
			dispatch(noticesStore).createErrorNotice(
				'Error creating file. Please try again.',
				{
					type: 'snackbar',
				}
			);
			return;
		}
		if (!newFile.post_id) {
			return;
		}
		// Wait until the post is considered available. Otherwise
		// The editor will attempt to load the new post, fail,
		// hide the block canvas, fetch the post, receive it,
		// and only then show the editor again.
		await resolveSelect(coreStore).getEntityRecord(
			'postType',
			WP_LOCAL_FILE_POST_TYPE,
			newFile.post_id
		);
		onNavigateToEntityRecord({
			postId: newFile.post_id,
			postType: WP_LOCAL_FILE_POST_TYPE,
		});
		dispatch(uiStore).setSelectedPath(nodePath);
		return nodePath;
	};

	const handleNodeMoved = async ({
		fromPath,
		toPath,
	}: {
		fromPath: string;
		toPath: string;
	}) => {
		dispatch(coreStore).editEntityRecord(
			'static-files-editor',
			'files',
			fromPath,
			{
				path: toPath,
			}
		);
		try {
			await dispatch(coreStore).saveEditedEntityRecord(
				'static-files-editor',
				'files',
				fromPath,
				{ throwOnError: true }
			);
		} catch (e) {
			// Naively assume we haven't edited anything else in the meantime
			dispatch(coreStore).undo();
			dispatch(noticesStore).createErrorNotice(
				'Error moving file. Please try again.',
				{
					type: 'snackbar',
				}
			);
		}
	};

	/**
	 * Enable drag and drop of files from the file picker tree to desktop.
	 */
	const handleDragStart = (
		e: React.DragEvent,
		path: string,
		node: ConnectedFileNode
	) => {
		// Directory downloads are not supported yet.
		if (node.type === 'file') {
			const url = apiUrl(
				`static-files-editor/v1/download-file?path=${path}`
			);
			const filename = path.split('/').pop();
			// For dragging & dropping to desktop
			e.dataTransfer.setData(
				'DownloadURL',
				`text/plain:${filename}:${url}`
			);
			if ('post_type' in node && node.post_type === 'attachment') {
				// Create DOM elements to safely construct HTML

				const figure = document.createElement('figure');
				figure.className = 'wp-block-image size-full';

				const img = document.createElement('img');
				img.src = url;
				img.alt = '';
				img.className = `wp-image-${node.post_id}`;

				figure.appendChild(img);

				// Wrap in WordPress block comments
				// For dragging & dropping into the editor canvas
				e.dataTransfer.setData(
					'text/html',
					`<!-- wp:image {"id":${JSON.stringify(
						node.post_id
					).replaceAll(
						'-->',
						''
					)},"sizeSlug":"full","linkDestination":"none"} -->
${figure.outerHTML}
<!-- /wp:image -->`
				);
			} else if (isPreviewableAssetPath(path)) {
				const img = document.createElement('img');
				img.src = url;
				img.alt = filename;
				e.dataTransfer.setData('text/html', img.outerHTML);
			}
		}
	};

	if (!isFileListInitialized) {
		return <Spinner />;
	}

	if (selectedPath === undefined) {
		// Wait until the selected path is initialized
		return <Spinner />;
	}

	if (!fileTree) {
		return <div>No files found</div>;
	}

	return (
		<FilePickerTree
			treeRoot={fileTree}
			onSelect={handleFileClick}
			selectedPath={selectedPath}
			onNodesCreated={handleNodesCreated}
			onNodeDeleted={handleNodeDeleted}
			onNodeMoved={handleNodeMoved}
			onDragStart={handleDragStart as any}
		/>
	);
}

addLocalFilesTab({
	name: 'local-files',
	title: 'Local Files',
	panel: (
		<div
			className={css['file-picker-tree-container']}
			id="file-picker-tree-container"
		>
			<ErrorBoundary>
				<ConnectedFilePickerTree />
			</ErrorBoundary>
		</div>
	),
});

function FilePreviewOverlay() {
	const selectedNode = useSelect(
		(select) => select(uiStore).getSelectedNode(),
		[]
	);

	if (
		!selectedNode ||
		selectedNode.type !== 'file' ||
		!isPreviewableAssetPath(selectedNode.path)
	) {
		return null;
	}

	const extension = selectedNode.path.split('.').pop()?.toLowerCase();
	const isPreviewable = ['jpg', 'jpeg', 'png', 'gif', 'svg'].includes(
		extension || ''
	);

	return (
		<div
			style={{
				position: 'absolute',
				top: 0,
				left: 0,
				right: 0,
				bottom: 0,
				backgroundColor: 'white',
				padding: '20px',
				zIndex: 1000,
			}}
		>
			<h2>{selectedNode.path.split('/').pop()}</h2>
			{isPreviewable ? (
				<img
					src={apiUrl(
						`static-files-editor/v1/download-file?path=${selectedNode.path}`
					)}
					alt={selectedNode.path}
					style={{ maxWidth: '100%', maxHeight: '80vh' }}
				/>
			) : (
				<div>Preview not available for this file type</div>
			)}
		</div>
	);
}

addComponentToEditorContentArea(<FilePreviewOverlay />);

function PostLoadingOverlay() {
	const isResolvingPost = useSelect((select) => {
		const selectedPath = select(uiStore).getSelectedPath();
		if (!selectedPath) {
			return false;
		}
		if (isPreviewableAssetPath(selectedPath)) {
			return false;
		}
		const file = select(coreStore).getEntityRecord(
			'static-files-editor',
			'files',
			selectedPath
		);
		if (!file?.post_id) {
			return false;
		}
		const isResolvingPostId = select(uiStore).isPostIdResolving();
		if (isResolvingPostId) {
			return true;
		}
		const isResolvingPost = !select(coreStore).hasFinishedResolution(
			'getEntityRecord',
			['postType', WP_LOCAL_FILE_POST_TYPE, file.post_id]
		);
		return isResolvingPost;
	}, []);
	if (!isResolvingPost) {
		return null;
	}
	return (
		<div
			style={{
				position: 'absolute',
				top: 0,
				left: 0,
				right: 0,
				bottom: 0,
				backgroundColor: 'rgba(0, 0, 0, 0.5)',
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'center',
				zIndex: 1000,
			}}
		>
			<Spinner />
		</div>
	);
}

addComponentToEditorContentArea(<PostLoadingOverlay />);

dispatch(preferencesStore).set('welcomeGuide', false);
dispatch(preferencesStore).set('enableChoosePatternModal', false);
dispatch(editorStore).setIsListViewOpened(true);

function MobileMenuContainer() {
	useEffect(() => {
		const waitForEditPostLayout = setInterval(() => {
			const editPostLayout = document.querySelector(
				'.interface-interface-skeleton__editor'
			);
			if (editPostLayout) {
				clearInterval(waitForEditPostLayout);
				const mobileMenuContainer = document.createElement('div');
				editPostLayout.appendChild(mobileMenuContainer);

				const root = createRoot(mobileMenuContainer);
				root.render(<MobileMenu />);
			}
		}, 100);

		return () => clearInterval(waitForEditPostLayout);
	}, []);

	return null;
}

addComponentToEditorContentArea(<MobileMenuContainer />);

/**
 * On mobile devices, when a block is inserted when the inserter sidebar is open,
 * keeping the sidebar open is confusing – as in "wait, did I just insert a block?"
 * This function closes the sidebar when a block is inserted on mobile.
 */
const closeInserterOnBlockInsert = () => {
	let previousBlocks = select('core/block-editor').getBlocks();

	subscribe(() => {
		const currentBlocks = select('core/block-editor').getBlocks();

		if (currentBlocks.length > previousBlocks.length) {
			// Adjust the selector to match your container
			const filePickerContainer = document.querySelector(
				'.editor-inserter-sidebar'
			) as any;
			if (
				filePickerContainer &&
				filePickerContainer.offsetWidth > window.innerWidth * 0.9
			) {
				dispatch(editorStore).setIsInserterOpened(false);
			}
		}
		previousBlocks = currentBlocks;
	});
};

closeInserterOnBlockInsert();

// Subscribe to the entity record and resetBlocks() whenever it changes
const replaceEditorContentOnEntityChange = () => {
	let lastPostId = select(editorStore).getCurrentPostId();
	let lastContent = select(editorStore).getEditedPostContent();
	const scheduledSaves = new Map<number, NodeJS.Timeout>();

	/**
	 * Mode 1: Save the post content after 5 seconds of inactivity.
	 */
	// subscribe(() => {
	// 	const currentPostId = select(editorStore).getCurrentPostId();
	// 	const currentContent = select(editorStore).getEditedPostContent();

	// 	if (currentPostId !== lastPostId) {
	// 		lastPostId = currentPostId;
	// 		lastContent = currentContent;
	// 		return;
	// 	}

	// 	if (currentContent === lastContent) {
	// 		return;
	// 	}
	// 	lastContent = currentContent;

	// 	// Clear any existing timeout for this postId
	// 	if (scheduledSaves.has(currentPostId)) {
	// 		clearTimeout(scheduledSaves.get(currentPostId));
	// 		scheduledSaves.delete(currentPostId);
	// 	}

	// 	// Schedule a new save in 5 seconds
	// 	// @TODO: Don't start the next one until this one is finished.
	// 	const timeoutId = setTimeout(async () => {
	// 		scheduledSaves.delete(currentPostId);
	// 		await dispatch(coreStore).saveEditedEntityRecord(
	// 			'postType',
	// 			WP_LOCAL_FILE_POST_TYPE,
	// 			currentPostId,
	// 			{ throwOnError: true }
	// 		);
	// 	}, 5000);

	// 	scheduledSaves.set(currentPostId, timeoutId);
	// });

	/**
	 * Mode 2: Save/refresh the post content every 5 seconds, regardless of user activity.
	 */
	setInterval(async () => {
		const currentPostId = select(editorStore).getCurrentPostId();
		const post = select(coreStore).getEntityRecord(
			'postType',
			WP_LOCAL_FILE_POST_TYPE,
			currentPostId
		);
		const editedPost = select(coreStore).getEditedEntityRecord(
			'postType',
			WP_LOCAL_FILE_POST_TYPE,
			currentPostId
		);
		const postContent =
			typeof post?.content?.raw === 'string' && post.content.raw.trim();
		const editedPostContent = getPostContent(editedPost).trim();
		const hasEdits = postContent !== editedPostContent;
		if (hasEdits) {
			// Make sure the post content is up to date before autosaving.
			await dispatch(coreStore).editEntityRecord(
				'postType',
				WP_LOCAL_FILE_POST_TYPE,
				currentPostId,
				{ content: { raw: editedPostContent } }
			);
			await dispatch(coreStore).saveEditedEntityRecord(
				'postType',
				WP_LOCAL_FILE_POST_TYPE,
				currentPostId,
				{ throwOnError: true }
			);
		} else {
			const response = await apiFetch({
				path: `/wp/v2/${WP_LOCAL_FILE_POST_TYPE}/${currentPostId}?context=edit`,
			});

			/**
			 * @TODO: Fix occasional data loss when the user keeps typing after the save have been
			 *        initiated. We likely need to keep track of any edits made after the saveEditedEntityRecord()
			 *        call and store them upon receiveEntityRecords.
			 *
			 *        Note that, in the unlikely case of a concurrent edit while we were typing,
			 *        we'll need to diff the saved markup with the received markup and rebase the edits.
			 */
			dispatch(coreStore).receiveEntityRecords(
				'postType',
				WP_LOCAL_FILE_POST_TYPE,
				[response],
				undefined,
				true
			);
		}
	}, 5000);

	/**
	 * Reconcile any changes to the post content with the current editor content.
	 *
	 * Approach 1: Subscribe to the store changes, merge the changes, and adjust the editor content
	 *             accordingly.
	 *
	 * Upsides: Works regardless of the exact method of updating the store.
	 * Downsides: It does not have access to the last post content before the save request was made
	 *            and thus may lead losing the delta typed between the save started and the save completed.
	 *
	 * @TODO: Restore the cursor position after calling resetBlocks().
	 */
	let lastSavedPost = select(coreStore).getEntityRecord(
		'postType',
		WP_LOCAL_FILE_POST_TYPE,
		select(editorStore).getCurrentPostId()
	);
	subscribe(() => {
		const postId = select(editorStore).getCurrentPostId();
		const updatedPost = select(coreStore).getEntityRecord(
			'postType',
			WP_LOCAL_FILE_POST_TYPE,
			postId
		);

		if (updatedPost && !lastSavedPost) {
			lastSavedPost = updatedPost;
		}
		if (!lastSavedPost?.content?.raw || lastSavedPost === updatedPost) {
			return;
		}

		if (
			updatedPost &&
			updatedPost?.content?.raw?.trim() !==
				lastSavedPost?.content?.raw?.trim()
		) {
			lastSavedPost = updatedPost;
			// Idea 1.1 – merge the document when we notice the post entity have changed.
			// const currentEditorBlocks = select(blockEditorStore).getBlocks();
			// const currentEditorContent = serialize(currentEditorBlocks);

			// let finalBlockMarkup = '';
			// if (lastSavedPost?.content?.raw) {
			//     const mergedBlockMarkup = threeWayMerge(
			//         lastSavedPost.content.raw.trim(),
			//         currentEditorContent.trim(),
			//         updatedPost.content.raw.trim(),
			//         validateMergedBlockMarkup
			//     );
			//     lastSavedPost = updatedPost;

			//     finalBlockMarkup = mergedBlockMarkup.hasConflicts
			//         ? currentEditorContent
			//         : mergedBlockMarkup.mergedContent;
			// } else {
			//     finalBlockMarkup = updatedPost.content.raw;
			// }
			// if(finalBlockMarkup === currentEditorContent) {
			//     return;
			// }

			// Idea 1.2 – assume the merge was done somewhere else and simplyt
			//            repopulate the editor with the updated post content.
			// Downside:  It runs more often than necessary, pausing the writing
			//            experience by moving focus out of the current block.
			// const post = select(coreStore).getEditedEntityRecord(
			//     'postType',
			//     WP_LOCAL_FILE_POST_TYPE,
			//     postId
			// );
			// if(!post) {
			//     return;
			// }

			// const blocks = parse(post.content);
			// dispatch(blockEditorStore).resetBlocks(blocks);

			// Idea 1.3 – handle everything in apiFetch() as a substitute for
			//            patching saveEntityRecord().
		}
	});

	/**
	 * Reconcile any changes to the post content with the current editor content.
	 *
	 * Approach 2: Observe all the network traffic to/from server and reconcile the post content based
	 *             on the datastore at request time, response time, and response information.
	 *
	 * Upsides: We have all the information we need to perform a merge and rebase.
	 * Downsides: Not all requests lead to a post update so we may sometimes update the editor content
	 *            when the user does not expect it.
	 *
	 * @TODO: Restore the cursor position after calling resetBlocks().
	 */
	apiFetch.use(async (options, next) => {
		const currentPostId = select(editorStore).getCurrentPostId();
		if (
			!options.path.startsWith(
				`/wp/v2/${WP_LOCAL_FILE_POST_TYPE}/${currentPostId}`
			)
		) {
			return next(options);
		}

		const isGetRequest =
			options.method === 'GET' || options.method === undefined;

		const postWhenSaveStarted = select(coreStore).getEntityRecord(
			'postType',
			WP_LOCAL_FILE_POST_TYPE,
			currentPostId
		);
		// if (typeof options?.data?.content?.raw === 'string') {
		//     options.data.content.raw = removeEmptyTextNodesFromHTML(options.data.content.raw);
		// }
		const contentWhenSaveStarted = getPostContent(
			options.data || postWhenSaveStarted
		);

		let response = (await next(options)) as Response;
		const postEditedSinceSaveStarted = select(
			coreStore
		).getEditedEntityRecord(
			'postType',
			WP_LOCAL_FILE_POST_TYPE,
			currentPostId
		);

		let postFromTheServer = null;
		if (response instanceof Response) {
			const [responseToConsume, responseToReturn] = teeResponse(response);
			postFromTheServer = await responseToConsume.json();
			response = responseToReturn;
		} else if (typeof response === 'object') {
			postFromTheServer = response;
		} else {
			return response;
		}

		preserveEditsSinceSaveStarted(
			contentWhenSaveStarted,
			postWhenSaveStarted,
			postEditedSinceSaveStarted,
			postFromTheServer,
			isGetRequest
		);
		return response;
	});

	function preserveEditsSinceSaveStarted(
		contentWhenSaveStarted,
		postWhenSaveStarted,
		postEditedSinceSaveStarted,
		postFromTheServer,
		wasGetRequest = false
	) {
		let serverContent = getPostContent(postFromTheServer);
		let postEditedContent = getPostContent(postEditedSinceSaveStarted);

		/**
		 * No merge needed if we've started with an empty post.
		 * The server response is our source of truth.
		 */
		if (false === serverContent || false === postEditedContent) {
			return;
		}

		contentWhenSaveStarted = consistentBlockMarkupFormatting(
			contentWhenSaveStarted
		);
		serverContent = consistentBlockMarkupFormatting(serverContent);
		postEditedContent = consistentBlockMarkupFormatting(postEditedContent);

		const currentPostId = postWhenSaveStarted.id;

		/**
		 * When the server replied with the post we saved, the data layer
		 * will overwrite the current post content with the server response.
		 *
		 * We know the user made some changes since the save was initiated,
		 * let's store them in the data layer as edits on top of the new
		 * post entity, but let's not touch the block editor content as it
		 * is up to date.
		 */
		if (blockMarkupEquals(contentWhenSaveStarted, serverContent)) {
			console.log('Branch 1');
			if (!blockMarkupEquals(contentWhenSaveStarted, postEditedContent)) {
				console.log('Branch 1.1');
				setTimeout(() => {
					storeMarkupEdits(currentPostId, postEditedContent);
				}, 5);
			}
			return;
		}

		/**
		 * No need to merge the post content if we haven't made any edits
		 * since the save was initiated. Just overwrite the post editor content
		 * with the server response.
		 */
		if (blockMarkupEquals(contentWhenSaveStarted, postEditedContent)) {
			console.log('Branch 2');
			setTimeout(() => {
				console.log('Branch 2.1');
				dispatch(blockEditorStore).resetBlocks(parse(serverContent));
			}, 5);
			return;
		}

		console.log({
			serverContent,
			postEditedContent,
			contentWhenSaveStarted,
			'contentWhenSaveStarted === serverContent': blockMarkupEquals(
				contentWhenSaveStarted,
				serverContent
			),
			'postEditedContent === serverContent': blockMarkupEquals(
				postEditedContent,
				serverContent
			),
			'contentWhenSaveStarted === postEditedContent': blockMarkupEquals(
				contentWhenSaveStarted,
				postEditedContent
			),
		});
		/**
		 * Otherwise, the server reply contains edits from
		 * another party that we did not perform in the editor.
		 *
		 * Let's run a three-way merge to reconcile those edits with everything
		 * the user may have typed in the editor since the save started.
		 */
		// Merge the post content with the edits and the server content.
		const mergedBlockMarkup = threeWayMerge(
			contentWhenSaveStarted,
			postEditedContent,
			serverContent,
			validateMergedBlockMarkup
		);

		/**
		 * @TODO: Support merging post meta as well – the post title at a very least
		 *        for the MVP.
		 * @TODO: Analyze what does a conflict mean in practice in this context.
		 *        Decide how to handle it – potentially tell the user and add an
		 *        undo entry? Or is ignoring it and overwriting with the current
		 *        editor content fine in most scenarios?
		 */
		const finalBlockMarkup = mergedBlockMarkup.hasConflicts
			? postEditedContent
			: mergedBlockMarkup.mergedContent.trim();

		if (
			!mergedBlockMarkup.hasConflicts &&
			finalBlockMarkup === postEditedContent
		) {
			console.log('Branch 3');
			console.log('three way merge no conflicts');
			return;
		}

		/**
		 * Data loss prevention.
		 *
		 * Preserve the delta between mergedBlockMarkup and currentEditorContent as post edits to ensure:
		 *
		 * * The next save operation will submit them to the server.
		 * * The next "update" operation will merge them with the server content.
		 *
		 * Ideally this would happen synchronously, but for the MVP we're using a setTimeout() call.
		 * Note there's a slight chance of getting a keyboard event between handling the request and
		 * our setTimeout(), which would wrongly place the next user edit **before** the delta that
		 * we already have.
		 */
		setTimeout(() => {
			console.log('Branch 4');
			console.log('!Reconciling edits');
			if (!wasGetRequest) {
				storeMarkupEdits(currentPostId, finalBlockMarkup);
			}

			// If we're here, the server responded with a document we haven't
			// seen before which likely means there have been concurrent edits.
			// Let's populate the editor with the final merged outcome.
			console.log('resetBlocks', parse(finalBlockMarkup));
			console.log({
				contentWhenSaveStarted,
				serverContent,
				'contentWhenSaveStarted === serverContent':
					contentWhenSaveStarted === serverContent,
			});
			dispatch(blockEditorStore).resetBlocks(parse(finalBlockMarkup));
		}, 5);

		// @TODO: Adjust cursor position:
		// Diff and rebase the edits to restore the cursor position where
		// the user expects it. Also, restore focus to the same block that
		// had it before the request.
	}

	/**
	 * Ensures that HTML tags, whitespaces, tag closers etc are formatted
	 * in a consistent way that can be compared for equality and three-way merged.
	 *
	 * This doesn't matter when working with block markup as the server preserves
	 * the formatting provided by the client. It's only relevant when storing
	 * the post content as markdown where the exact formatting is lost.
	 *
	 * For example, Markdown transformation can turn
	 *
	 * ```html
	 * <!-- /wp:paragraph --></blockquote>
	 * ```
	 *
	 * into
	 *
	 * ```html
	 * <!-- /wp:paragraph -->
	 *
	 * </blockquote>
	 * ```
	 *
	 * This function ensures the formatting remains consistent for diffing and merging.
	 */
    function consistentBlockMarkupFormatting(htmlString) {
        return serialize(parse(htmlString));
	}

	function blockMarkupEquals(a, b) {
		const cleanedA = removeEmptyTextNodesFromHTML(a);
		const cleanedB = removeEmptyTextNodesFromHTML(b);
		return cleanedA === cleanedB;
	}

	function removeEmptyTextNodesFromHTML(htmlString) {
		// Create a temporary DOM element to parse the HTML string
		const tempDiv = document.createElement('div');
		tempDiv.innerHTML = htmlString;

		// Function to remove empty text nodes
		function removeEmptyTextNodes(node) {
			const childNodes = node.childNodes;

			// Iterate over the child nodes backwards to avoid issues with live node list
			for (let i = childNodes.length - 1; i >= 0; i--) {
				const child = childNodes[i];

				if (child.nodeType === Node.TEXT_NODE) {
					if (!/\S/.test(child.nodeValue)) {
						node.removeChild(child);
					}
				} else if (child.nodeType === Node.ELEMENT_NODE) {
					removeEmptyTextNodes(child);
				}
			}
		}

		// Remove empty text nodes from the parsed HTML
		removeEmptyTextNodes(tempDiv);

		// Return the processed HTML string
		return tempDiv.innerHTML;
	}

	function getPostContent(post) {
		const contentField = post.content;
		if (post.blocks) {
			return serialize(post.blocks);
		}
		if (typeof contentField === 'string') {
			return contentField.trim();
		}
		if (typeof contentField?.raw === 'string') {
			return contentField.raw.trim();
		}
		return false;
	}

	function storeMarkupEdits(postId, content) {
		dispatch(coreStore).editEntityRecord(
			'postType',
			WP_LOCAL_FILE_POST_TYPE,
			postId,
			{
				content: {
					raw: content,
				},
			}
		);
	}

	function teeResponse(response: Response) {
		const [body1, body2] = response.body.tee();
		return [
			new Response(body1, {
				status: response.status,
				statusText: response.statusText,
				headers: response.headers,
			}),
			new Response(body2, {
				status: response.status,
				statusText: response.statusText,
				headers: response.headers,
			}),
		];
	}

	// const originalSaveEntityRecord = dispatch(coreStore).saveEditedEntityRecord;
	// dispatch(coreStore).saveEditedEntityRecord = async (type, name, id, ...rest) => {
	//     await originalSaveEntityRecord(type, name, id, ...rest);
	//     const currentPostId = select(editorStore).getCurrentPostId();
	//     if (type === 'postType' && name === WP_LOCAL_FILE_POST_TYPE && currentPostId === id) {
	//         const currentContent = select(editorStore).getEditedPostContent();
	//         const hasEdits = select(coreStore).hasEditsForEntityRecord(
	//             'postType',
	//             WP_LOCAL_FILE_POST_TYPE,
	//             currentPostId
	//         );
	//     }
	// };

	// Uncomment this to replace the editor content on autosave.
	// let lastStoredAutosave = select(coreStore).getAutosave(
	// 	WP_LOCAL_FILE_POST_TYPE,
	// 	select(editorStore).getCurrentPostId(),
	// 	select(coreStore).getCurrentUser().id
	// );
	// subscribe(() => {
	// 	const postId = select(editorStore).getCurrentPostId();
	// 	const user = select(coreStore).getCurrentUser();

	// 	const updatedAutosave = select(coreStore).getAutosave(
	// 		WP_LOCAL_FILE_POST_TYPE,
	// 		postId,
	// 		user.id
	// 	);
	// 	if (updatedAutosave && !lastStoredAutosave) {
	// 		lastStoredAutosave = updatedAutosave;
	// 	}
	// 	if (!lastStoredAutosave || lastStoredAutosave === updatedAutosave) {
	// 		return;
	// 	}

	// 	const currentEditorBlocks = select(blockEditorStore).getBlocks();
	// 	const currentEditorContent = serialize(currentEditorBlocks);

	// 	if (
	// 		!updatedAutosave ||
	// 		updatedAutosave.content.raw === lastStoredAutosave?.content?.raw ||
	// 		updatedAutosave.content.raw === currentEditorContent
	// 	) {
	// 		return;
	// 	}

	// 	const mergedBlockMarkup = threeWayMerge(
	// 		lastStoredAutosave.content.raw.trim(),
	// 		currentEditorContent.trim(),
	// 		updatedAutosave.content.raw.trim(),
	// 		validateMergedBlockMarkup
	//     );

	// 	lastStoredAutosave = updatedAutosave;

	// 	const finalBlockMarkup = mergedBlockMarkup.hasConflicts
	// 		? currentEditorContent
	// 		: mergedBlockMarkup.mergedContent;

	// 	const blocks = parse(finalBlockMarkup);
	// 	dispatch(blockEditorStore).resetBlocks(blocks);
	// });
};

replaceEditorContentOnEntityChange();
