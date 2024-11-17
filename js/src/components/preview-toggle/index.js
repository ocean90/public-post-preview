/**
 * External dependencies
 */
import { css } from '@emotion/css';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	CheckboxControl,
	Button,
	ExternalLink,
	TextControl,
	VisuallyHidden,
} from '@wordpress/components';
import { Component, createRef, createInterpolateElement } from '@wordpress/element';
import { withSelect, withDispatch, useDispatch } from '@wordpress/data';
import { PluginPostStatusInfo } from '@wordpress/edit-post';
import { ifCondition, compose, useCopyToClipboard } from '@wordpress/compose';
import { store as noticesStore } from '@wordpress/notices';
import { store as editorStore, PluginPreviewMenuItem } from '@wordpress/editor';
import { copySmall, seen } from '@wordpress/icons';
import { store as coreStore } from '@wordpress/core-data';

const { ajaxurl, DSPublicPostPreviewData } = window;

const pluginPostStatusInfoRow = css`
	flex-direction: column;
	align-items: flex-start;
`;

const pluginPostStatusInfoPreviewUrl = css`
	margin-top: 8px;
	width: 100%;
`;

const pluginPostStatusInfoPreviewDescription = css`
	color: #757575;
	margin: 8px 0 0 !important;
`;

const pluginPostStatusInfoPreviewUrlInputWrapper = css`
	position: relative;
	display: flex;
	justify-content: flex-start;
	align-items: center;

	.components-base-control {
		width: 100%;
	}
`;

const pluginPostStatusInfoPreviewUrlInput = css`
	.components-text-control__input {
		background-color: #fff;
		padding-right: 30px !important;
	}
`;

const pluginPostStatusInfoPreviewCheckbox = css`
	label {
		max-width: 100%;
	}
`;

const copyButton = css`
	position: absolute;
	right: 5px;
	top; 0;
`;

function CopyButton( { text } ) {
	const { createNotice } = useDispatch( noticesStore );
	const ref = useCopyToClipboard( text, () => {
		createNotice( 'info', __( 'Preview link copied to clipboard.', 'public-post-preview' ), {
			isDismissible: true,
			type: 'snackbar',
		} );
	} );
	return (
		<Button
			icon={ copySmall }
			ref={ ref }
			label={ __( 'Copy the preview URL', 'public-post-preview' ) }
			className={ copyButton }
			size="small"
		/>
	);
}

function PreviewMenuItem( { previewUrl } ) {
	if ( 'function' !== typeof PluginPreviewMenuItem || ! previewUrl ) {
		return null;
	}

	return (
		<PluginPreviewMenuItem icon={ seen } href={ previewUrl } target="_blank">
			{ __( 'Open public preview', 'public-post-preview' ) }
			<VisuallyHidden as="span">
				{
					/* translators: accessibility text */
					__( '(opens in a new tab)', 'public-post-preview' )
				}
			</VisuallyHidden>
		</PluginPreviewMenuItem>
	);
}

class PreviewToggle extends Component {
	constructor( props ) {
		super( props );

		this.state = {
			previewEnabled: DSPublicPostPreviewData.previewEnabled,
			previewUrl: DSPublicPostPreviewData.previewUrl,
		};

		this.previewUrlInput = createRef();

		this.onChange = this.onChange.bind( this );
		this.onPreviewUrlInputFocus = this.onPreviewUrlInputFocus.bind( this );
	}

	onChange( checked ) {
		const data = new window.FormData();
		data.append( 'checked', checked );
		data.append( 'post_ID', this.props.postId );

		this.sendRequest( data )
			.then( ( response ) => {
				if ( response.status >= 200 && response.status < 300 ) {
					return response;
				}

				throw response;
			} )
			.then( ( response ) => response.json() )
			.then( ( response ) => {
				if ( ! response.success ) {
					throw response;
				}

				const previewEnabled = ! this.state.previewEnabled;
				this.setState( {
					previewEnabled,
					previewUrl: response?.data?.preview_url || '',
				} );

				this.props.createNotice(
					'info',
					previewEnabled
						? __( 'Public preview enabled.', 'public-post-preview' )
						: __( 'Public preview disabled.', 'public-post-preview' ),
					{
						id: 'public-post-preview',
						isDismissible: true,
						type: 'snackbar',
					}
				);
			} )
			.catch( () => {
				this.props.createNotice(
					'error',
					__( 'Error while changing the public preview status.', 'public-post-preview' ),
					{
						id: 'public-post-preview',
						isDismissible: true,
						type: 'snackbar',
					}
				);
			} );
	}

	onPreviewUrlInputFocus() {
		this.previewUrlInput.current.focus();
		this.previewUrlInput.current.select();
	}

	sendRequest( data ) {
		data.append( 'action', 'public-post-preview' );
		data.append( '_ajax_nonce', DSPublicPostPreviewData.nonce );
		return window.fetch( ajaxurl, {
			method: 'POST',
			body: data,
		} );
	}

	render() {
		const { previewEnabled, previewUrl } = this.state;

		return (
			<>
				<PreviewMenuItem previewUrl={ previewEnabled && previewUrl ? previewUrl : null } />
				<PluginPostStatusInfo className={ pluginPostStatusInfoRow }>
					<CheckboxControl
						label={ __( 'Enable public preview', 'public-post-preview' ) }
						checked={ previewEnabled }
						onChange={ this.onChange }
						className={ pluginPostStatusInfoPreviewCheckbox }
						__nextHasNoMarginBottom
					/>
					{ previewEnabled && (
						<div className={ pluginPostStatusInfoPreviewUrl }>
							<div className={ pluginPostStatusInfoPreviewUrlInputWrapper }>
								<TextControl
									ref={ this.previewUrlInput }
									hideLabelFromVision
									label={ __( 'Preview URL', 'public-post-preview' ) }
									value={ previewUrl }
									readOnly
									onFocus={ this.onPreviewUrlInputFocus }
									className={ pluginPostStatusInfoPreviewUrlInput }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								<CopyButton text={ previewUrl } />
							</div>
							<p className={ pluginPostStatusInfoPreviewDescription }>
								{ createInterpolateElement(
									__(
										'Copy and share <a>the preview URL</a>.',
										'public-post-preview'
									),
									{
										a: <ExternalLink href={ previewUrl } />,
									}
								) }
							</p>
						</div>
					) }
				</PluginPostStatusInfo>
			</>
		);
	}
}

export default compose( [
	withSelect( ( select ) => {
		const { getPostType } = select( coreStore );
		const { getCurrentPostId, getEditedPostAttribute } = select( editorStore );
		const postType = getPostType( getEditedPostAttribute( 'type' ) );

		return {
			postId: getCurrentPostId(),
			status: getEditedPostAttribute( 'status' ),
			isViewable: postType?.viewable || false,
		};
	} ),
	ifCondition( ( { isViewable } ) => isViewable ),
	ifCondition( ( { status } ) => {
		return [ 'auto-draft', 'publish', 'private' ].indexOf( status ) === -1;
	} ),
	withDispatch( ( dispatch ) => {
		return {
			createNotice: dispatch( noticesStore ).createNotice,
		};
	} ),
] )( PreviewToggle );
