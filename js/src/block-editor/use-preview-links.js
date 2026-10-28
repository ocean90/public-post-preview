/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

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
	const [ isBusy, setIsBusy ] = useState( false );
	const { createErrorNotice } = useDispatch( noticesStore );

	const path = `/public-post-preview/v1/posts/${ postId }/links`;

	useEffect( () => {
		if ( ! enabled ) {
			setLinks( [] );
			return;
		}

		apiFetch( { path } )
			.then( setLinks )
			.catch( () => setLinks( [] ) );
	}, [ path, enabled ] );

	const request = useCallback(
		async ( options ) => {
			setIsBusy( true );
			try {
				return await apiFetch( options );
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
		const link = await request( { path, method: 'POST' } );
		if ( link ) {
			const { url, ...data } = link;
			setLinks( ( current ) => [ data, ...current ] );
			setFresh( { id: link.id, url } );
		}
	}, [ path, request ] );

	const updateLink = useCallback(
		async ( id, changes ) => {
			const link = await request( {
				path: `${ path }/${ id }`,
				method: 'PATCH',
				data: changes,
			} );
			if ( link ) {
				setLinks( ( current ) =>
					current.map( ( item ) => ( item.id === id ? link : item ) )
				);
			}
			return !! link;
		},
		[ path, request ]
	);

	const revokeLink = useCallback(
		async ( id ) => {
			if ( await request( { path: `${ path }/${ id }`, method: 'DELETE' } ) ) {
				setLinks( ( current ) => current.filter( ( item ) => item.id !== id ) );
				setFresh( ( current ) => ( current?.id === id ? null : current ) );
			}
		},
		[ path, request ]
	);

	const revokeAll = useCallback( async () => {
		if ( await request( { path, method: 'DELETE' } ) ) {
			setLinks( [] );
			setFresh( null );
		}
	}, [ path, request ] );

	return { links, fresh, isBusy, createLink, updateLink, revokeLink, revokeAll };
}
