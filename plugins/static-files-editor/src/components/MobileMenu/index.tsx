import React, { useCallback, useState } from 'react';
import classNames from 'classnames';
import css from './style.module.css';
import { store as editorStore } from '@wordpress/editor';
import { useSelect, dispatch } from '@wordpress/data'; // <-- Added useSelect here
import apiFetch from '@wordpress/api-fetch';
import { Spinner } from '@wordpress/components';
import { store as noticesStore } from '@wordpress/notices';

const MobileMenu: React.FC = () => {
	const [isPulling, setIsPulling] = useState(false);

	// Get the current list view state from the editorStore.
	// It's assumed that the editor store has a selector "getIsListViewOpened".
	const isListViewOpened = useSelect(
		(select) => select(editorStore).isListViewOpened(),
		[]
	);

	const forcePull = useCallback(async () => {
		setIsPulling(true);
		try {
			await apiFetch({
				path: '/static-files-editor/v1/git/force-pull',
				method: 'POST',
			});
			dispatch(noticesStore).createSuccessNotice(
				'Force pull completed successfully',
				{
					type: 'snackbar',
				}
			);
			window.location.reload();
		} catch (error) {
			dispatch(noticesStore).createErrorNotice(
				'Force pull failed. Please try again.',
				{
					type: 'snackbar',
				}
			);
		} finally {
			setIsPulling(false);
		}
	}, []);

	return (
		<div className={css.mobileMenu}>
			<a
				href="#"
				onClick={() => dispatch(editorStore).setIsListViewOpened(true)}
				// When list view is open, Notes list should be highlighted.
				className={classNames(css.menuItem, {
					[css.active]: isListViewOpened,
				})}
			>
				Notes list
			</a>
			<a
				href="#"
				onClick={() => dispatch(editorStore).setIsListViewOpened(false)}
				// When list view is closed, Editor is active.
				className={classNames(css.menuItem, {
					[css.active]: !isListViewOpened,
				})}
			>
				Editor
			</a>
			<a
				href="#"
				onClick={(e) => {
					e.preventDefault();
					forcePull();
				}}
				className={css.menuItem}
			>
				{isPulling ? <Spinner /> : 'Force pull'}
			</a>
			<a href="/wp-admin/" className={css.menuItem}>
				WP Admin
			</a>
		</div>
	);
};

export { MobileMenu };
