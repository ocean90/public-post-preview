/**
 * WordPress dependencies
 */
import { useDispatch } from '@wordpress/data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import * as api from '../preview-links';

/**
 * Loads and changes the preview links of a post through the REST API.
 *
 * @param {number}  postId  Post ID.
 * @param {boolean} enabled Whether the post can have links.
 * @return {Object} Links, the link created in this session with its URL, and actions.
 */
export default function usePreviewLinks( postId, enabled ) {
	const [ links, setLinks ] = useState( [] );
	const [ fresh, setFresh ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( enabled );
	const [ isBusy, setIsBusy ] = useState( false );
	const { createErrorNotice } = useDispatch( noticesStore );

	useEffect( () => {
		// Links are deleted on publish, so a fresh URL from before can't work anymore.
		setLinks( [] );
		setFresh( null );
		setIsLoading( enabled );
		if ( ! enabled ) {
			return;
		}

		let isCurrent = true;
		api.listLinks( postId )
			.then( ( result ) => isCurrent && setLinks( result ) )
			.catch(
				( error ) =>
					isCurrent &&
					createErrorNotice(
						error?.message ||
							__( 'The preview links could not be loaded.', 'public-post-preview' ),
						{ type: 'snackbar' }
					)
			)
			.finally( () => isCurrent && setIsLoading( false ) );

		return () => {
			isCurrent = false;
		};
	}, [ postId, enabled, createErrorNotice ] );

	const request = useCallback(
		async ( call ) => {
			setIsBusy( true );
			try {
				return await call();
			} catch ( error ) {
				createErrorNotice(
					error?.message ||
						__( 'The preview link could not be saved.', 'public-post-preview' ),
					{ type: 'snackbar' }
				);
				return null;
			} finally {
				setIsBusy( false );
			}
		},
		[ createErrorNotice ]
	);

	const createLink = useCallback( async () => {
		const link = await request( () => api.createLink( postId ) );
		if ( link ) {
			const { url, ...data } = link;
			setLinks( ( current ) => [ data, ...current ] );
			setFresh( { id: link.id, url } );
		}
		return !! link;
	}, [ postId, request ] );

	const updateLink = useCallback(
		async ( id, changes ) => {
			const link = await request( () => api.updateLink( postId, id, changes ) );
			if ( link ) {
				setLinks( ( current ) =>
					current.map( ( item ) => ( item.id === id ? link : item ) )
				);
			}
			return !! link;
		},
		[ postId, request ]
	);

	const revokeLink = useCallback(
		async ( id ) => {
			if ( await request( () => api.revokeLink( postId, id ) ) ) {
				setLinks( ( current ) => current.filter( ( item ) => item.id !== id ) );
				setFresh( ( current ) => ( current?.id === id ? null : current ) );
			}
		},
		[ postId, request ]
	);

	const revokeAll = useCallback( async () => {
		if ( await request( () => api.revokeAllLinks( postId ) ) ) {
			setLinks( [] );
			setFresh( null );
		}
	}, [ postId, request ] );

	return {
		links,
		fresh,
		isLoading,
		isBusy,
		createLink,
		updateLink,
		revokeLink,
		revokeAll,
	};
}
