import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';
import {
	Component,
	Fragment,
} from '@wordpress/element';
import { withSelect } from '@wordpress/data';
import { PluginPostStatusInfo } from '@wordpress/edit-post';
import { css } from 'emotion'

const {
	ajaxurl,
	DSPublicPostPreviewData,
} = window;

const pluginPostStatusInfoPreviewUrl = css`
	flex-direction: column;
	align-items: stretch;
	margin-top: 10px;
`

class PreviewToggle extends Component {

	constructor( props ) {
		super( props )

		this.state = {
			previewEnabled: DSPublicPostPreviewData.previewEnabled,
			previewUrl: DSPublicPostPreviewData.previewUrl,
		}

		this.onChange = this.onChange.bind( this )
	}

	onChange( checked ) {
		this.request( {
			checked,
			post_ID: this.props.postId
		}, () => {
			this.setState( { previewEnabled: ! this.state.previewEnabled } );
		} )
	}

	/**
	 * Does the AJAX request.
	 *
	 * @since  2.0.0
	 *
	 * @param  {Object}  data     The data to send.
	 * @param  {Object}  callback Callback function for a successful request.
	 */
	request( data, callback ) {
		jQuery.ajax( {
			type: 'POST',
			url: ajaxurl,
			data: {
				action: 'public-post-preview',
				_ajax_nonce: DSPublicPostPreviewData.nonce,
				...data
			},
			success: callback,
		} );
	}

	render() {
		const {
			previewEnabled,
			previewUrl,
		} = this.state;

		return (
			<Fragment>
				<PluginPostStatusInfo>
					<CheckboxControl
						label={ __( 'Enable Public Preview', 'public-post-preview' ) }
						checked={ previewEnabled }
						onChange={ this.onChange }
					/>
				</PluginPostStatusInfo>
				{ previewEnabled &&
					<PluginPostStatusInfo className={ pluginPostStatusInfoPreviewUrl }>
						<label htmlFor="public-post-preview-url" className="screen-reader-text">{ __( 'Preview URL', 'public-post-preview' ) }</label>
						<input type="text" id="public-post-preview-url" value={ previewUrl } readOnly />
						{ __( '(Copy and share this link.)', 'public-post-preview' ) }
					</PluginPostStatusInfo>
				}
			</Fragment>
		);
	}
}

export default withSelect( ( select ) => {
	const {  getCurrentPostId } = select( 'core/editor' );
	return {
		postId: getCurrentPostId(),
	};
} )( PreviewToggle );
