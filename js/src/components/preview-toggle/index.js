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

const pluginPostStatusInfoPreviewUrlInput = css`
	cursor: pointer;
`

const pluginPostStatusInfoPreviewDescription = css`
	font-style: italic;
	color: #666;
	margin: .2em 0 0 !important;
`

class PreviewToggle extends Component {

	constructor( props ) {
		super( props )

		this.state = {
			previewEnabled: DSPublicPostPreviewData.previewEnabled,
			previewUrl: DSPublicPostPreviewData.previewUrl,
			urlCopied: false,
		}

		this.previewUrlInput = React.createRef();

		this.onChange = this.onChange.bind( this );
		this.onFocus = this.onFocus.bind( this );
		this.onClick = this.onClick.bind( this );
	}

	componentWillUnmount() {
		if ( this.timerId ) {
			clearTimeout( this.timerId );
		}
	}

	onChange( checked ) {
		this.request( {
			checked,
			post_ID: this.props.postId
		}, () => {
			this.setState( { previewEnabled: ! this.state.previewEnabled } );
		} )
	}

	onFocus() {
		this.previewUrlInput.current.focus();
		this.previewUrlInput.current.select();
	}

	onClick() {
		if ( this.timerId ) {
			clearTimeout( this.timerId );
		}

		this.previewUrlInput.current.select();
		const copied = document.execCommand( 'copy' );
		this.setState( { urlCopied: copied } );

		if ( copied ) {
			this.timerId = setTimeout( () => this.setState( { urlCopied: false } ), 2000 );
		}
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
			urlCopied,
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
						<input
							ref={ this.previewUrlInput }
							type="text"
							id="public-post-preview-url"
							className={ pluginPostStatusInfoPreviewUrlInput }
							value={ previewUrl }
							readOnly
							onFocus={ this.onFocus }
							onClick={ this.onClick }
						/>
						<p className={ pluginPostStatusInfoPreviewDescription }>
							{ __( '(Click to copy and share the link.)', 'public-post-preview' ) }
							{ urlCopied && <strong>{ ' ' + __( 'Copied!', 'public-post-preview' ) }</strong> }
						</p>
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

