/**
 * WordPress dependencies
 */
import { __experimentalInspectorPopoverHeader as InspectorPopoverHeader } from '@wordpress/block-editor';
import {
	Button,
	Dropdown,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHStack as HStack,
	__experimentalInputControl as InputControl,
	__experimentalInputControlSuffixWrapper as InputControlSuffixWrapper,
	__experimentalItemGroup as ItemGroup,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginPostStatusInfo, store as editorStore } from '@wordpress/editor';
import { useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { copySmall } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { getLinkName, isActive } from '../preview-links';
import LinkItem from './link-item';
import usePreviewLinks from './use-preview-links';

const {
	defaultLifetimeLabel,
	maxLifetime,
	excludedStatuses = [],
} = window.DSPublicPostPreview || {};

/**
 * The URL of a link created in this session, which can't be shown again later.
 *
 * @param {Object} props     Props.
 * @param {string} props.url Preview URL.
 */
function FreshLink( { url } ) {
	const { createNotice } = useDispatch( noticesStore );
	const ref = useCopyToClipboard( url, () =>
		createNotice( 'info', __( 'Preview link copied.', 'public-post-preview' ), {
			type: 'snackbar',
			isDismissible: true,
		} )
	);

	return (
		<VStack spacing={ 2 }>
			<InputControl
				label={ __( 'New preview link', 'public-post-preview' ) }
				value={ url }
				readOnly
				onFocus={ ( event ) => event.target.select() }
				__next40pxDefaultSize
				suffix={
					<InputControlSuffixWrapper variant="control">
						<Button
							icon={ copySmall }
							label={ __( 'Copy link', 'public-post-preview' ) }
							size="small"
							ref={ ref }
						/>
					</InputControlSuffixWrapper>
				}
			/>
			<Text variant="muted" size={ 12 }>
				{ __( 'Copy the link now. It is shown only this once.', 'public-post-preview' ) }
			</Text>
		</VStack>
	);
}

/**
 * The "Preview link" row in the post summary, with the links in a popover.
 */
export default function PreviewLinkRow() {
	const { postId, isPreviewable } = useSelect( ( select ) => {
		const { getCurrentPostId, getCurrentPostAttribute } = select( editorStore );

		return {
			postId: getCurrentPostId(),
			isPreviewable: ! excludedStatuses.includes( getCurrentPostAttribute( 'status' ) ),
		};
	}, [] );

	const { links, fresh, isLoading, isBusy, createLink, updateLink, revokeLink, revokeAll } =
		usePreviewLinks( postId, isPreviewable );
	const [ anchor, setAnchor ] = useState( null );
	const [ confirming, setConfirming ] = useState( null );
	const popoverProps = useMemo(
		() => ( { anchor, placement: 'left-start', offset: 36, shift: true } ),
		[ anchor ]
	);

	if ( ! isPreviewable ) {
		return null;
	}

	// The list isn't reloaded, so links that expire while the editor is open drop out here.
	const activeLinks = links.filter( isActive );
	const count = activeLinks.length;
	const toggleLabel = count
		? sprintf(
				/* translators: %s: Number of preview links. */
				_n( '%s link', '%s links', count, 'public-post-preview' ),
				count
			)
		: __( 'Create link', 'public-post-preview' );

	return (
		<PluginPostStatusInfo>
			<HStack className="editor-post-panel__row" ref={ setAnchor }>
				<div className="editor-post-panel__row-label">
					{ __( 'Preview link', 'public-post-preview' ) }
				</div>
				<div className="editor-post-panel__row-control">
					<Dropdown
						className="public-post-preview__dropdown"
						contentClassName="public-post-preview__dialog"
						focusOnMount
						popoverProps={ popoverProps }
						renderToggle={ ( { isOpen, onToggle } ) => (
							<Button
								size="compact"
								variant="tertiary"
								aria-expanded={ isOpen }
								isBusy={ isLoading || ( isBusy && ! count ) }
								disabled={ isLoading || ( isBusy && ! count ) }
								accessibleWhenDisabled
								onClick={ async () => {
									if ( ! isOpen && ! count && ! ( await createLink() ) ) {
										return;
									}
									onToggle();
								} }
							>
								{ toggleLabel }
							</Button>
						) }
						renderContent={ ( { onClose } ) => (
							<>
								<InspectorPopoverHeader
									title={ __( 'Public preview', 'public-post-preview' ) }
									onClose={ onClose }
								/>
								<VStack spacing={ 4 }>
									{ fresh && <FreshLink url={ fresh.url } /> }
									{ count > 0 && (
										<ItemGroup isBordered isSeparated size="medium">
											{ activeLinks.map( ( link ) => (
												<LinkItem
													key={ link.id }
													link={ link }
													isNew={ fresh?.id === link.id }
													maxLifetime={ maxLifetime }
													onUpdate={ updateLink }
													onRevoke={ ( item ) =>
														setConfirming( {
															message: sprintf(
																/* translators: %s: Name of the preview link. */
																__(
																	'Revoke “%s”? Whoever has the link can no longer see the draft.',
																	'public-post-preview'
																),
																getLinkName( item )
															),
															onConfirm: () => revokeLink( item.id ),
														} )
													}
												/>
											) ) }
										</ItemGroup>
									) }
									<HStack justify="space-between">
										<Button
											variant="secondary"
											size="compact"
											isBusy={ isBusy }
											disabled={ isBusy }
											accessibleWhenDisabled
											onClick={ createLink }
										>
											{ count
												? __( 'Create another link', 'public-post-preview' )
												: __( 'Create link', 'public-post-preview' ) }
										</Button>
										{ count > 1 && (
											<Button
												variant="tertiary"
												size="compact"
												isDestructive
												onClick={ () =>
													setConfirming( {
														message: __(
															'Revoke all preview links? Nobody can see the draft through them anymore.',
															'public-post-preview'
														),
														onConfirm: revokeAll,
													} )
												}
											>
												{ __( 'Revoke all', 'public-post-preview' ) }
											</Button>
										) }
									</HStack>
									{ defaultLifetimeLabel && (
										<Text variant="muted" size={ 12 }>
											{ sprintf(
												/* translators: %s: Duration, e.g. "2 days". */
												__(
													'New links expire after %s.',
													'public-post-preview'
												),
												defaultLifetimeLabel
											) }
										</Text>
									) }
								</VStack>
							</>
						) }
					/>
				</div>
			</HStack>
			<ConfirmDialog
				isOpen={ !! confirming }
				confirmButtonText={ __( 'Revoke', 'public-post-preview' ) }
				onConfirm={ () => {
					confirming.onConfirm();
					setConfirming( null );
				} }
				onCancel={ () => setConfirming( null ) }
			>
				{ confirming?.message }
			</ConfirmDialog>
		</PluginPostStatusInfo>
	);
}
