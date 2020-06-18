/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import PreviewToggle from './components/preview-toggle';

registerPlugin( 'public-post-preview', {
	render: PreviewToggle,
} );
