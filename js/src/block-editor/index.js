/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import PreviewLinkRow from './preview-link-row';
import './editor.css';

registerPlugin( 'public-post-preview', {
	render: PreviewLinkRow,
} );
