/**
 * WordPress dependencies
 */
import {
	Button,
	DateTimePicker,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalItem as Item,
	__experimentalText as Text,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { date, getDate, humanTimeDiff } from '@wordpress/date';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { DAY_IN_MS, getExpiryPresets, getLinkName } from '../preview-links';

/**
 * Form with Cancel and Save buttons, which also saves on Enter.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.onSave   Called when the form is submitted.
 * @param {Function} props.onCancel Called when the form is closed without saving.
 * @param {Element}  props.children Fields.
 */
function EditorForm( { onSave, onCancel, children } ) {
	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				onSave();
			} }
		>
			<VStack spacing={ 3 }>
				{ children }
				<HStack justify="flex-end">
					<Button variant="tertiary" size="compact" onClick={ onCancel }>
						{ __( 'Cancel', 'public-post-preview' ) }
					</Button>
					<Button variant="primary" size="compact" type="submit">
						{ __( 'Save', 'public-post-preview' ) }
					</Button>
				</HStack>
			</VStack>
		</form>
	);
}

/**
 * Form to choose a new expiry: a preset, or a date in the site's timezone.
 *
 * @param {Object}   props             Props.
 * @param {string}   props.expiresAt   Current expiry, ISO 8601 in UTC.
 * @param {number}   props.maxLifetime Maximum lifetime in seconds.
 * @param {Function} props.onSave      Called with the new expiry, ISO 8601 in UTC.
 * @param {Function} props.onCancel    Called when the form is closed without saving.
 */
function ExpiryEditor( { expiresAt, maxLifetime, onSave, onCancel } ) {
	const now = Date.now();
	const presets = getExpiryPresets( maxLifetime );
	// The first preset that extends the link, so saving the default never shortens it.
	const [ preset, setPreset ] = useState(
		() =>
			presets.find( ( item ) => now + item.duration > Date.parse( expiresAt ) )?.value ??
			'date'
	);
	const [ localDate, setLocalDate ] = useState( date( 'Y-m-d\\TH:i:s', expiresAt ) );

	const getExpiry = () =>
		'date' === preset
			? getDate( localDate ).toISOString()
			: new Date(
					now + presets.find( ( item ) => item.value === preset ).duration
				).toISOString();

	return (
		<EditorForm onSave={ () => onSave( getExpiry() ) } onCancel={ onCancel }>
			<ToggleGroupControl
				label={ __( 'Expires in', 'public-post-preview' ) }
				value={ preset }
				onChange={ setPreset }
				isBlock
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			>
				{ presets.map( ( item ) => (
					<ToggleGroupControlOption
						key={ item.value }
						value={ item.value }
						label={ item.label }
					/>
				) ) }
				<ToggleGroupControlOption
					value="date"
					label={ __( 'Date', 'public-post-preview' ) }
				/>
			</ToggleGroupControl>
			{ 'date' === preset && (
				<DateTimePicker
					currentDate={ localDate }
					onChange={ setLocalDate }
					isInvalidDate={ ( day ) =>
						day.getTime() < now - DAY_IN_MS || day.getTime() > now + maxLifetime * 1000
					}
				/>
			) }
		</EditorForm>
	);
}

/**
 * Form to change the label of a link.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.label    Current label.
 * @param {Function} props.onSave   Called with the new label.
 * @param {Function} props.onCancel Called when the form is closed without saving.
 */
function LabelEditor( { label, onSave, onCancel } ) {
	const [ value, setValue ] = useState( label );

	return (
		<EditorForm onSave={ () => onSave( value ) } onCancel={ onCancel }>
			<TextControl
				label={ __( 'Label', 'public-post-preview' ) }
				value={ value }
				onChange={ setValue }
				placeholder={ __( 'e.g. Client review', 'public-post-preview' ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		</EditorForm>
	);
}

/**
 * One link in the list, with its actions.
 *
 * @param {Object}   props             Props.
 * @param {Object}   props.link        Link.
 * @param {boolean}  props.isNew       Whether the link was just created, so its URL is the one shown above.
 * @param {number}   props.maxLifetime Maximum lifetime in seconds.
 * @param {Function} props.onUpdate    Called with the link ID and the changes; resolves to whether it worked.
 * @param {Function} props.onRevoke    Called with the link to revoke.
 */
export default function LinkItem( { link, isNew, maxLifetime, onUpdate, onRevoke } ) {
	const [ mode, setMode ] = useState( null );
	const name = getLinkName( link );
	const save = async ( changes ) => {
		if ( await onUpdate( link.id, changes ) ) {
			setMode( null );
		}
	};

	return (
		<Item>
			<VStack spacing={ 3 }>
				<HStack alignment="center" spacing={ 2 }>
					<VStack spacing={ 0 } alignment="topLeft" style={ { flex: 1, minWidth: 0 } }>
						<HStack spacing={ 2 } justify="flex-start">
							<Text weight={ 500 } truncate>
								{ name }
							</Text>
							{ isNew && (
								<span className="public-post-preview__badge">
									{ __( 'New', 'public-post-preview' ) }
								</span>
							) }
						</HStack>
						<Text variant="muted" size={ 12 }>
							{ sprintf(
								/* translators: %s: Relative time, e.g. "in 6 days". */
								__( 'Expires %s', 'public-post-preview' ),
								humanTimeDiff( link.expires_at )
							) }
						</Text>
						<Text variant="muted" size={ 12 }>
							{ link.last_accessed_at
								? sprintf(
										/* translators: %s: Relative time, e.g. "2 hours ago". */
										__( 'Last opened %s', 'public-post-preview' ),
										humanTimeDiff( link.last_accessed_at )
									)
								: __( 'Not opened yet', 'public-post-preview' ) }
						</Text>
					</VStack>
					<DropdownMenu
						icon={ moreVertical }
						/* translators: %s: Name of the preview link. */
						label={ sprintf( __( 'Actions for %s', 'public-post-preview' ), name ) }
						size="small"
						popoverProps={ { placement: 'bottom-end' } }
					>
						{ ( { onClose } ) => (
							<MenuGroup>
								<MenuItem
									onClick={ () => {
										onClose();
										setMode( 'label' );
									} }
								>
									{ __( 'Change label', 'public-post-preview' ) }
								</MenuItem>
								<MenuItem
									onClick={ () => {
										onClose();
										setMode( 'expiry' );
									} }
								>
									{ __( 'Extend', 'public-post-preview' ) }
								</MenuItem>
								<MenuItem
									isDestructive
									onClick={ () => {
										onClose();
										onRevoke( link );
									} }
								>
									{ __( 'Revoke', 'public-post-preview' ) }
								</MenuItem>
							</MenuGroup>
						) }
					</DropdownMenu>
				</HStack>
				{ 'label' === mode && (
					<LabelEditor
						label={ link.label }
						onSave={ ( label ) => save( { label } ) }
						onCancel={ () => setMode( null ) }
					/>
				) }
				{ 'expiry' === mode && (
					<ExpiryEditor
						expiresAt={ link.expires_at }
						maxLifetime={ maxLifetime }
						onSave={ ( expiresAt ) => save( { expires_at: expiresAt } ) }
						onCancel={ () => setMode( null ) }
					/>
				) }
			</VStack>
		</Item>
	);
}
