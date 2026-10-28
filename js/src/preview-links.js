/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

export const DAY_IN_MS = 24 * 60 * 60 * 1000;

const EXPIRY_PRESETS = [
	{
		value: 'day',
		label: __( '1 day', 'public-post-preview' ),
		duration: DAY_IN_MS,
	},
	{
		value: 'week',
		label: __( '7 days', 'public-post-preview' ),
		duration: 7 * DAY_IN_MS,
	},
	{
		value: 'month',
		label: __( '30 days', 'public-post-preview' ),
		duration: 30 * DAY_IN_MS,
	},
];

/**
 * Returns the presets within the maximum lifetime.
 *
 * @param {number} maxLifetime Maximum lifetime in seconds.
 * @return {Object[]} Presets.
 */
export function getExpiryPresets( maxLifetime ) {
	return EXPIRY_PRESETS.filter( ( preset ) => preset.duration <= maxLifetime * 1000 );
}

/**
 * Returns the name of a link for the list.
 *
 * @param {Object} link Link.
 * @return {string} Label, or the end of the link.
 */
export function getLinkName( link ) {
	/* translators: %s: Last four characters of the preview link. */
	return link.label || sprintf( __( 'Link …%s', 'public-post-preview' ), link.hint );
}

/**
 * Whether a link has not expired yet.
 *
 * @param {Object} link Link.
 * @return {boolean} True if the link still works.
 */
export function isActive( link ) {
	return Date.parse( link.expires_at ) > Date.now();
}

const getPath = ( postId, linkId ) =>
	`/public-post-preview/v1/posts/${ postId }/links` + ( linkId ? `/${ linkId }` : '' );

export const listLinks = ( postId ) => apiFetch( { path: getPath( postId ) } );

export const createLink = ( postId ) => apiFetch( { path: getPath( postId ), method: 'POST' } );

export const updateLink = ( postId, linkId, changes ) =>
	apiFetch( {
		path: getPath( postId, linkId ),
		method: 'PATCH',
		data: changes,
	} );

export const revokeLink = ( postId, linkId ) =>
	apiFetch( { path: getPath( postId, linkId ), method: 'DELETE' } );

export const revokeAllLinks = ( postId ) =>
	apiFetch( { path: getPath( postId ), method: 'DELETE' } );
