/**
 * External dependencies
 */
import { get } from 'lodash';
import { css } from 'emotion'

import { __ } from '@wordpress/i18n';
import {
	CheckboxControl,
	ClipboardButton,
	Path,
	SVG,
} from '@wordpress/components';
import {
	Component,
	createRef,
	Fragment,
} from '@wordpress/element';
import { withSelect } from '@wordpress/data';
import { PluginPostStatusInfo } from '@wordpress/edit-post';
import { ifCondition, compose } from '@wordpress/compose';

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
	width: 100%;
	margin-right: 12px;
`

const pluginPostStatusInfoPreviewDescription = css`
	font-style: italic;
	color: #666;
	margin: .2em 0 0 !important;
`

const pluginPostStatusInfoPreviewUrlInputWrapper = css`
	display: flex;
	justify-content: flex-start;
	align-items: center;
	margin: 0;
`

class PreviewToggle extends Component {

	constructor( props ) {
		super( props )

		this.state = {
			previewEnabled: DSPublicPostPreviewData.previewEnabled,
			previewUrl: DSPublicPostPreviewData.previewUrl,
			hasCopied: false,
		}

		this.previewUrlInput = createRef();

		this.onChange = this.onChange.bind( this );
		this.onPreviewUrlInputFocus = this.onPreviewUrlInputFocus.bind( this );
	}

	onChange( checked ) {
		this.request( {
			checked,
			post_ID: this.props.postId
		}, () => {
			this.setState( { previewEnabled: ! this.state.previewEnabled } );
		} )
	}

	onPreviewUrlInputFocus() {
		this.previewUrlInput.current.focus();
		this.previewUrlInput.current.select();
	}

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
			hasCopied
		} = this.state;

		const ariaCopyLabel = hasCopied ? __( 'Preview URL copied', 'public-post-preview' ) : __( 'Copy the preview URL', 'public-post-preview' );

		return (
			<Fragment>
				<PluginPostStatusInfo>
					<CheckboxControl
						label={ __( 'Enable public preview', 'public-post-preview' ) }
						checked={ previewEnabled }
						onChange={ this.onChange }
					/>
				</PluginPostStatusInfo>
				{ previewEnabled &&
					<PluginPostStatusInfo className={ pluginPostStatusInfoPreviewUrl }>
						<p className={ pluginPostStatusInfoPreviewUrlInputWrapper }>
							<label htmlFor="public-post-preview-url" className="screen-reader-text">{ __( 'Preview URL', 'public-post-preview' ) }</label>
							<input
								ref={ this.previewUrlInput }
								type="text"
								id="public-post-preview-url"
								className={ pluginPostStatusInfoPreviewUrlInput }
								value={ previewUrl }
								readOnly
								onFocus={ this.onPreviewUrlInputFocus }
							/>
							<ClipboardButton
								text={ previewUrl }
								label={ ariaCopyLabel }
								onCopy={ () => this.setState( { hasCopied: true } ) }
								onFinishCopy={ () => this.setState( { hasCopied: false } ) }
								aria-disabled={ hasCopied }
								icon={ <SVG width="20" height="20" viewBox="0 0 14 16" xmlns="http://www.w3.org/2000/svg" focusable="false" ><Path fillRule="evenodd" d="M2 13h4v1H2v-1zm5-6H2v1h5V7zm2 3V8l-3 3 3 3v-2h5v-2H9zM4.5 9H2v1h2.5V9zM2 12h2.5v-1H2v1zm9 1h1v2c-.02.28-.11.52-.3.7-.19.18-.42.28-.7.3H1c-.55 0-1-.45-1-1V4c0-.55.45-1 1-1h3c0-1.11.89-2 2-2 1.11 0 2 .89 2 2h3c.55 0 1 .45 1 1v5h-1V6H1v9h10v-2zM2 5h8c0-.55-.45-1-1-1H8c-.55 0-1-.45-1-1s-.45-1-1-1-1 .45-1 1-.45 1-1 1H3c-.55 0-1 .45-1 1z"/></SVG> }
							/>
						</p>
						<p className={ pluginPostStatusInfoPreviewDescription }>
							{ __( 'Copy and share this preview URL.', 'public-post-preview' ) }
						</p>
					</PluginPostStatusInfo>
				}
			</Fragment>
		);
	}
}

export default compose( [
	withSelect( ( select ) => {
		const {
			getPostType,
		} = select( 'core' );
		const {
			getCurrentPostId,
			getEditedPostAttribute,
		} = select( 'core/editor' );
		const postType = getPostType( getEditedPostAttribute( 'type' ) );

		return {
			postId: getCurrentPostId(),
			status: getEditedPostAttribute( 'status' ),
			isViewable: get( postType, [ 'viewable' ], false ),
		};
	} ),
	ifCondition( ( { isViewable } ) => isViewable ),
	ifCondition( ( { status } ) => {
		return [
			'auto-draft',
			'publish',
			'private',
		].indexOf( status ) === -1;
	} ),
] )( PreviewToggle );
