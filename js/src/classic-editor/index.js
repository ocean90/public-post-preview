/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { date, getDate } from '@wordpress/date';
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
	// What a link shows instead of its actions: `label`, `expiry` or `revoke`, or `revoke-all` for the list.
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
	node.append( ...children.flat().filter( ( child ) => child || 0 === child ) );

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
 * Runs a request and shows its error, if any.
 *
 * @param {Function} call      Starts the request.
 * @param {Function} onSuccess Called with the response, returns the changed state.
 * @param {string}   focus     Selector of the element to focus on success.
 */
async function run( call, onSuccess, focus ) {
	update( { isBusy: true, error: '' } );
	try {
		update( { isBusy: false, editing: null, ...onSuccess( await call() ) }, focus );
	} catch ( error ) {
		const message =
			error?.message || __( 'The preview link could not be saved.', 'public-post-preview' );
		speak( message, 'assertive' );
		update( { isBusy: false, error: message }, '.ppp-error' );
	}
}

function createLink() {
	run(
		() => api.createLink( settings.postId ),
		( { url, ...link } ) => ( {
			links: [ link, ...state.links ],
			fresh: { id: link.id, url },
			isOpen: true,
		} ),
		'.ppp-fresh input'
	);
}

function updateLink( id, changes ) {
	run(
		() => api.updateLink( settings.postId, id, changes ),
		( link ) => ( { links: state.links.map( ( item ) => ( item.id === id ? link : item ) ) } ),
		`[data-link="${ id }"] .ppp-actions button`
	);
}

function revokeLink( id ) {
	run(
		() => api.revokeLink( settings.postId, id ),
		() => {
			speak( __( 'Preview link revoked.', 'public-post-preview' ) );
			return {
				links: state.links.filter( ( item ) => item.id !== id ),
				fresh: state.fresh?.id === id ? null : state.fresh,
			};
		},
		'.ppp-create'
	);
}

function revokeAll() {
	run(
		() => api.revokeAllLinks( settings.postId ),
		() => {
			speak( __( 'All preview links revoked.', 'public-post-preview' ) );
			return { links: [], fresh: null, isOpen: false };
		},
		'.ppp-toggle'
	);
}

/**
 * Handles Enter and Escape in an inline editor, so Enter doesn't submit the post.
 *
 * @param {Function} onSave   Saves the editor.
 * @param {Function} onCancel Closes the editor.
 * @return {Function} Keydown handler.
 */
function onEditorKeydown( onSave, onCancel ) {
	return ( event ) => {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			onSave();
		} else if ( 'Escape' === event.key ) {
			event.preventDefault();
			onCancel();
		}
	};
}

/**
 * Renders the OK button and the cancel link of an inline editor.
 *
 * @param {Function} onSave   Saves the editor.
 * @param {Function} onCancel Closes the editor.
 * @param {string}   okText   Text of the OK button.
 * @return {HTMLElement[]} Buttons.
 */
function editorButtons( onSave, onCancel, okText = __( 'OK', 'public-post-preview' ) ) {
	return [
		button( 'button', okText, onSave ),
		' ',
		button( 'button-link', __( 'Cancel', 'public-post-preview' ), onCancel ),
	];
}

function renderLabelEditor( link, onCancel ) {
	const input = el( 'input', {
		type: 'text',
		id: `ppp-label-${ link.id }`,
		value: link.label,
		maxlength: 100,
		placeholder: __( 'e.g. Client review', 'public-post-preview' ),
	} );
	const save = () => updateLink( link.id, { label: input.value } );
	input.addEventListener( 'keydown', onEditorKeydown( save, onCancel ) );

	return el(
		'span',
		{ className: 'ppp-editor' },
		el(
			'label',
			{ className: 'screen-reader-text', for: input.id },
			__( 'Label', 'public-post-preview' )
		),
		input,
		editorButtons( save, onCancel )
	);
}

function renderExpiryEditor( link, onCancel ) {
	const now = Date.now();
	const select = el(
		'select',
		{ id: `ppp-expiry-${ link.id }` },
		presets.map( ( preset ) => el( 'option', { value: preset.value }, preset.label ) ),
		el( 'option', { value: 'date' }, __( 'Custom date', 'public-post-preview' ) )
	);
	select.value = api.getExtendingPreset( presets, link.expires_at );

	// `datetime-local` has no timezone, so its value is read in the site's timezone.
	const dateInput = el( 'input', {
		type: 'datetime-local',
		value: date( 'Y-m-d\\TH:i', link.expires_at ),
		min: date( 'Y-m-d\\TH:i', now ),
		max: date( 'Y-m-d\\TH:i', now + settings.maxLifetime * 1000 ),
		'aria-label': __( 'Expiry date', 'public-post-preview' ),
	} );
	dateInput.hidden = 'date' !== select.value;
	select.addEventListener( 'change', () => {
		dateInput.hidden = 'date' !== select.value;
	} );

	const save = () => {
		const preset = presets.find( ( item ) => item.value === select.value );
		const expiry = preset
			? new Date( Date.now() + preset.duration )
			: getDate( dateInput.value );
		updateLink( link.id, { expires_at: expiry.toISOString() } );
	};
	select.addEventListener( 'keydown', onEditorKeydown( save, onCancel ) );
	dateInput.addEventListener( 'keydown', onEditorKeydown( save, onCancel ) );

	return el(
		'span',
		{ className: 'ppp-editor' },
		el(
			'label',
			{ className: 'screen-reader-text', for: select.id },
			__( 'Expires in', 'public-post-preview' )
		),
		select,
		dateInput,
		editorButtons( save, onCancel )
	);
}

function renderConfirm( question, confirmText, onConfirm, onCancel ) {
	return el(
		'span',
		{ className: 'ppp-actions' },
		question,
		' ',
		button( 'button-link button-link-delete', confirmText, onConfirm ),
		' | ',
		button( 'button-link', __( 'Cancel', 'public-post-preview' ), onCancel )
	);
}

function renderLink( link ) {
	const name = api.getLinkName( link );
	const isEditing = state.editing?.id === link.id;
	const cancel = () =>
		update( { editing: null }, `[data-link="${ link.id }"] .ppp-actions button` );
	const edit = ( type ) => () =>
		update(
			{ editing: { id: link.id, type } },
			`[data-link="${ link.id }"] .ppp-editor :is(input, select)`
		);

	let actions;
	if ( isEditing && 'label' === state.editing.type ) {
		actions = renderLabelEditor( link, cancel );
	} else if ( isEditing && 'expiry' === state.editing.type ) {
		actions = renderExpiryEditor( link, cancel );
	} else if ( isEditing && 'revoke' === state.editing.type ) {
		actions = renderConfirm(
			__( 'Revoke this link?', 'public-post-preview' ),
			__( 'Revoke', 'public-post-preview' ),
			() => revokeLink( link.id ),
			cancel
		);
	} else {
		actions = el(
			'span',
			{ className: 'ppp-actions' },
			button( 'button-link', __( 'Change label', 'public-post-preview' ), edit( 'label' ), {
				'aria-label': sprintf(
					/* translators: %s: Name of the preview link. */
					__( 'Change label of %s', 'public-post-preview' ),
					name
				),
			} ),
			' | ',
			button( 'button-link', __( 'Extend', 'public-post-preview' ), edit( 'expiry' ), {
				'aria-label': sprintf(
					/* translators: %s: Name of the preview link. */
					__( 'Extend %s', 'public-post-preview' ),
					name
				),
			} ),
			' | ',
			button(
				'button-link button-link-delete',
				__( 'Revoke', 'public-post-preview' ),
				edit( 'revoke' ),
				{
					'aria-label': sprintf(
						/* translators: %s: Name of the preview link. */
						__( 'Revoke %s', 'public-post-preview' ),
						name
					),
				}
			)
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
	if ( 'revoke-all' === state.editing ) {
		footer = renderConfirm(
			__( 'Revoke all links?', 'public-post-preview' ),
			__( 'Revoke all', 'public-post-preview' ),
			revokeAll,
			() => update( { editing: null }, '.ppp-revoke-all' )
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
					() => update( { editing: 'revoke-all' }, '.ppp-footer .button-link-delete' )
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
	if ( root ) {
		render();
	}
} );
