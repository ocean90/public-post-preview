import { registerPlugin } from '@wordpress/plugins';
import { default as PreviewToggle } from './components/preview-toggle';

registerPlugin( 'public-post-preview', {
	render: PreviewToggle,
} );
