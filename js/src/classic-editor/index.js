/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { date } from '@wordpress/date';
import domReady from '@wordpress/dom-ready';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import * as api from '../preview-links';
import './editor.css';

const settings = window.DSPublicPostPreview || {};
const presets = api.getExpiryPresets( settings.maxLifetime );

const state = {
	links: settings.links || [],
	fresh: null,
	isOpen: false,
	// The open inline form as `{ id, type, value }`, with the unsaved input as `value`.
	// `type` is `label`, `expiry` or `revoke`, or `revoke-all` with a null `id`.
	editing: null,
	isBusy: false,
	error: '',
};

let root;
let focusSelector = null;

/**
 * Creates an element.
 *
 * Builds nodes instead of HTML, so labels never need escaping.
 *
 * @param {string} tag      Tag name.
 * @param {Object} props    Attributes, `className`, and `on…` event listeners.
 * @param {...*}   children Child nodes or strings; falsy values are skipped.
 * @return {HTMLElement} Element.
 */
function el( tag, props = {}, ...children ) {
	const node = document.createElement( tag );
	for ( const [ key, value ] of Object.entries( props ) ) {
		if ( key.startsWith( 'on' ) ) {
			node.addEventListener( key.slice( 2 ).toLowerCase(), value );
		} else if ( 'className' === key ) {
			node.className = value;
		} else if ( true === value ) {
			node.setAttribute( key, '' );
		} else if ( value || 0 === value ) {
			node.setAttribute( key, value );
		}
	}
	node.append( ...children.flat( 2 ).filter( ( child ) => child || 0 === child ) );

	return node;
}

/**
 * Creates a button. Every button needs `type="button"`, since the edit screen is one big form.
 *
 * @param {string}   className Classes.
 * @param {string}   text      Text.
 * @param {Function} onClick   Click handler.
 * @param {Object}   props     Further attributes.
 * @return {HTMLElement} Button.
 */
function button( className, text, onClick, props = {} ) {
	return el(
		'button',
		{ type: 'button', className, disabled: state.isBusy, onClick, ...props },
		text
	);
}

/**
 * Changes the state and renders again.
 *
 * @param {Object}      changes Changed state.
 * @param {string|null} focus   Selector of the element to focus afterwards.
 */
function update( changes, focus = null ) {
	Object.assign( state, changes );
	focusSelector = focus;
	render();
}

/**
 * Shows an error in the box and announces it.
 *
 * @param {string} message Message.
 */
function showError( message ) {
	speak( message, 'assertive' );
	update( { isBusy: false, error: message }, '.ppp-error' );
}

/**
 * Runs a request and shows its error, if any.
 *
 * @param {Function} call               Starts the request.
 * @param {Function} onSuccess          Called with the response, returns the changed state.
 * @param {Object}   options            Options.
 * @param {string}   options.focus      Selector of the element to focus on success.
 * @param {string}   [options.announce] Text to announce on success.
 */
async function run( call, onSuccess, { focus, announce } ) {
	update( { isBusy: true, error: '' } );
	try {
		update( { isBusy: false, editing: null, ...onSuccess( await call() ) }, focus );
		if ( announce ) {
			speak( announce );
		}
	} catch ( error ) {
		showError( api.getErrorMessage( error ) );
	}
}

function createLink() {
	// Opened right away, so an error has a place to show.
	state.isOpen = true;
	run(
		() => api.createLink( settings.postId ),
		( { url, ...link } ) => ( {
			links: [ link, ...state.links ],
			fresh: { id: link.id, url },
		} ),
		{ focus: '.ppp-fresh input' }
	);
}

function updateLink( id, changes ) {
	run(
		() => api.updateLink( settings.postId, id, changes ),
		( link ) => ( { links: state.links.map( ( item ) => ( item.id === id ? link : item ) ) } ),
		{ focus: `[data-link="${ id }"] .ppp-actions button` }
	);
}

function revokeLink( id ) {
	run(
		() => api.revokeLink( settings.postId, id ),
		() => ( { links: state.links.filter( ( item ) => item.id !== id ) } ),
		{ focus: '.ppp-create', announce: __( 'Preview link revoked.', 'public-post-preview' ) }
	);
}

function revokeAll() {
	run(
		() => api.revokeAllLinks( settings.postId ),
		() => ( { links: [], isOpen: false } ),
		{
			focus: '.ppp-toggle',
			announce: __( 'All preview links revoked.', 'public-post-preview' ),
		}
	);
}

function startEditing( id, type, value = null ) {
	const item = id ? `[data-link="${ id }"]` : '.ppp-footer';
	update(
		{ editing: { id, type, value }, error: '' },
		`${ item } .ppp-editor :is(input, select), ${ item } .ppp-actions .button-link-delete`
	);
}

function cancelEditing() {
	const { id } = state.editing;
	update(
		{ editing: null, error: '' },
		id ? `[data-link="${ id }"] .ppp-actions button` : '.ppp-revoke-all'
	);
}

function saveEditing() {
	const { id, type, value } = state.editing;
	if ( 'label' === type ) {
		updateLink( id, { label: value } );
		return;
	}

	const expiry = api.getExpiryFromChoice( presets, value.preset, value.date );
	if ( expiry ) {
		updateLink( id, { expires_at: expiry } );
	} else {
		showError( __( 'Please enter a valid date.', 'public-post-preview' ) );
	}
}

/**
 * Renders the OK button and the cancel link of an inline form.
 *
 * @return {Array} Buttons.
 */
function editorButtons() {
	return [
		button( 'button', __( 'OK', 'public-post-preview' ), saveEditing ),
		' ',
		button( 'button-link', __( 'Cancel', 'public-post-preview' ), cancelEditing ),
	];
}

function renderLabelEditor( link ) {
	const id = `ppp-label-${ link.id }`;

	return el(
		'span',
		{ className: 'ppp-editor' },
		el(
			'label',
			{ className: 'screen-reader-text', for: id },
			__( 'Label', 'public-post-preview' )
		),
		el( 'input', {
			type: 'text',
			id,
			value: state.editing.value,
			maxlength: 100,
			placeholder: __( 'e.g. Client review', 'public-post-preview' ),
			disabled: state.isBusy,
			onInput: ( event ) => {
				state.editing.value = event.target.value;
			},
		} ),
		editorButtons()
	);
}

function renderExpiryEditor( link ) {
	const now = Date.now();
	const { value } = state.editing;
	const id = `ppp-expiry-${ link.id }`;

	const select = el(
		'select',
		{ id, disabled: state.isBusy },
		presets.map( ( preset ) => el( 'option', { value: preset.value }, preset.label ) ),
		el( 'option', { value: 'date' }, __( 'Custom date', 'public-post-preview' ) )
	);
	select.value = value.preset;

	// `datetime-local` has no timezone, so its value is read in the site's timezone.
	const dateInput = el( 'input', {
		type: 'datetime-local',
		value: value.date,
		min: date( 'Y-m-d\\TH:i', now ),
		max: date( 'Y-m-d\\TH:i', now + settings.maxLifetime * 1000 ),
		'aria-label': __( 'Expiry date', 'public-post-preview' ),
		disabled: state.isBusy,
		onInput: ( event ) => {
			value.date = event.target.value;
		},
	} );
	dateInput.hidden = 'date' !== value.preset;

	select.addEventListener( 'change', () => {
		value.preset = select.value;
		dateInput.hidden = 'date' !== value.preset;
	} );

	return el(
		'span',
		{ className: 'ppp-editor' },
		el(
			'label',
			{ className: 'screen-reader-text', for: id },
			__( 'Expires in', 'public-post-preview' )
		),
		select,
		dateInput,
		editorButtons()
	);
}

function renderConfirm( question, confirmText, onConfirm ) {
	return el(
		'span',
		{ className: 'ppp-actions' },
		question,
		' ',
		button( 'button-link button-link-delete', confirmText, onConfirm ),
		' | ',
		button( 'button-link', __( 'Cancel', 'public-post-preview' ), cancelEditing )
	);
}

const LINK_ACTIONS = [
	{
		type: 'label',
		className: 'button-link',
		text: __( 'Change label', 'public-post-preview' ),
		getAriaLabel: ( name ) =>
			/* translators: %s: Name of the preview link. */
			sprintf( __( 'Change label of %s', 'public-post-preview' ), name ),
		getValue: ( link ) => link.label,
	},
	{
		type: 'expiry',
		className: 'button-link',
		text: __( 'Extend', 'public-post-preview' ),
		getAriaLabel: ( name ) =>
			/* translators: %s: Name of the preview link. */
			sprintf( __( 'Extend %s', 'public-post-preview' ), name ),
		getValue: ( link ) => ( {
			preset: api.getExtendingPreset( presets, link.expires_at ),
			date: date( 'Y-m-d\\TH:i', link.expires_at ),
		} ),
	},
	{
		type: 'revoke',
		className: 'button-link button-link-delete',
		text: __( 'Revoke', 'public-post-preview' ),
		getAriaLabel: ( name ) =>
			/* translators: %s: Name of the preview link. */
			sprintf( __( 'Revoke %s', 'public-post-preview' ), name ),
		getValue: () => null,
	},
];

function renderLink( link ) {
	const name = api.getLinkName( link );
	const type = state.editing?.id === link.id ? state.editing.type : null;

	let actions;
	if ( 'label' === type ) {
		actions = renderLabelEditor( link );
	} else if ( 'expiry' === type ) {
		actions = renderExpiryEditor( link );
	} else if ( 'revoke' === type ) {
		actions = renderConfirm(
			__( 'Revoke this link?', 'public-post-preview' ),
			__( 'Revoke', 'public-post-preview' ),
			() => revokeLink( link.id )
		);
	} else {
		actions = el(
			'span',
			{ className: 'ppp-actions' },
			LINK_ACTIONS.map( ( action, index ) => [
				index > 0 && ' | ',
				button(
					action.className,
					action.text,
					() => startEditing( link.id, action.type, action.getValue( link ) ),
					{ 'aria-label': action.getAriaLabel( name ) }
				),
			] )
		);
	}

	return el(
		'li',
		{ 'data-link': link.id },
		el( 'strong', {}, name ),
		el(
			'span',
			{ className: 'ppp-meta' },
			`${ api.getExpiryText( link ) }, ${ api.getAccessText( link ) }`
		),
		actions
	);
}

function renderFresh( link ) {
	const input = el( 'input', {
		type: 'text',
		className: 'code',
		readonly: true,
		value: state.fresh.url,
		'aria-label': __( 'New preview link', 'public-post-preview' ),
		onFocus: ( event ) => event.target.select(),
	} );
	const copy = async () => {
		try {
			await window.navigator.clipboard.writeText( state.fresh.url );
			speak( __( 'Preview link copied.', 'public-post-preview' ) );
		} catch {
			// Without clipboard access, selecting the URL lets the user copy it by hand.
			input.select();
		}
	};

	return el(
		'div',
		{ className: 'ppp-fresh' },
		el(
			'div',
			{ className: 'notice notice-success inline' },
			el(
				'p',
				{},
				__( 'Copy the link now. It is shown only this once.', 'public-post-preview' )
			)
		),
		el(
			'div',
			{ className: 'ppp-url' },
			input,
			button( 'button', __( 'Copy', 'public-post-preview' ), copy )
		),
		link && el( 'p', { className: 'description' }, api.getExpiryText( link ) )
	);
}

function renderBox( links ) {
	const count = links.length;
	const fresh = state.fresh && links.find( ( link ) => link.id === state.fresh.id );

	let footer;
	if ( 'revoke-all' === state.editing?.type ) {
		footer = renderConfirm(
			__( 'Revoke all links?', 'public-post-preview' ),
			__( 'Revoke all', 'public-post-preview' ),
			revokeAll
		);
	} else {
		footer = [
			button(
				'button ppp-create',
				count
					? __( 'Create another link', 'public-post-preview' )
					: __( 'Create link', 'public-post-preview' ),
				createLink
			),
			count > 1 &&
				button(
					'button-link button-link-delete ppp-revoke-all',
					__( 'Revoke all', 'public-post-preview' ),
					() => startEditing( null, 'revoke-all' )
				),
		];
	}

	return el(
		'div',
		{ className: 'ppp-box', id: 'public-post-preview-box' },
		state.error &&
			el(
				'div',
				{ className: 'notice notice-error inline ppp-error', tabindex: -1 },
				el( 'p', {}, state.error )
			),
		fresh && renderFresh( fresh ),
		count > 0 && el( 'ul', {}, links.map( renderLink ) ),
		el( 'p', { className: 'ppp-footer' }, footer ),
		settings.defaultLifetimeLabel &&
			el(
				'p',
				{ className: 'description' },
				sprintf(
					/* translators: %s: Duration, e.g. "2 days". */
					__( 'New links expire after %s.', 'public-post-preview' ),
					settings.defaultLifetimeLabel
				)
			)
	);
}

function render() {
	// The list isn't reloaded, so links that expire while the screen is open drop out here.
	const links = state.links.filter( api.isActive );
	const count = links.length;

	let toggle;
	if ( ! count && ! state.isOpen ) {
		toggle = button(
			'button-link ppp-toggle',
			__( 'Create link', 'public-post-preview' ),
			createLink
		);
	} else {
		toggle = button(
			'button-link ppp-toggle',
			state.isOpen
				? __( 'Close', 'public-post-preview' )
				: __( 'Edit', 'public-post-preview' ),
			() => update( { isOpen: ! state.isOpen, editing: null, error: '' }, '.ppp-toggle' ),
			{ 'aria-expanded': String( state.isOpen ), 'aria-controls': 'public-post-preview-box' }
		);
	}

	// The leading space matches the markup of the other sections, which sets the gap after the icon.
	root.replaceChildren(
		' ',
		__( 'Public preview:', 'public-post-preview' ),
		' ',
		el(
			'strong',
			{},
			count
				? sprintf(
						/* translators: %s: Number of preview links. */
						_n( '%s link', '%s links', count, 'public-post-preview' ),
						count
					)
				: __( 'Off', 'public-post-preview' )
		),
		' ',
		toggle,
		state.isOpen ? renderBox( links ) : ''
	);

	if ( focusSelector ) {
		root.querySelector( focusSelector )?.focus();
		focusSelector = null;
	}
}

domReady( () => {
	root = document.getElementById( 'public-post-preview' );
	if ( ! root ) {
		return;
	}

	root.addEventListener( 'keydown', ( event ) => {
		if (
			! [ 'Enter', 'Escape' ].includes( event.key ) ||
			! event.target.matches( 'input, select' )
		) {
			return;
		}

		// In any field here, Enter would submit the post form.
		event.preventDefault();
		if ( state.editing && ! state.isBusy && event.target.closest( '.ppp-editor' ) ) {
			if ( 'Enter' === event.key ) {
				saveEditing();
			} else {
				cancelEditing();
			}
		}
	} );
	render();
} );
