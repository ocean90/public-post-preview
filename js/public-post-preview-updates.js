/* global DSPublicPostPreviewUpdates */
( function ( config ) {
	if ( ! config || ! window.fetch ) {
		return;
	}

	const container = document.getElementById( 'public-post-preview-updates' );
	if ( ! container ) {
		return;
	}

	const BASE_INTERVAL = 10000;
	// Delay before the next check, using a gradual backoff.
	const SCHEDULE = [
		{ until: 120000, delay: BASE_INTERVAL },
		{ until: 360000, delay: 30000 },
		{ until: Infinity, delay: 60000 },
	];

	let runStart = Date.now();
	let timer = null;
	let stopped = false;
	// The page itself is the first check.
	let lastCheck = Date.now();

	/**
	 * Stops polling for good.
	 */
	function stop() {
		stopped = true;
		clearTimeout( timer );
		timer = null;
	}

	/**
	 * Schedules the next check unless polling is stopped or the tab is hidden.
	 *
	 * @param {number} delay Milliseconds until the next check.
	 */
	function schedule( delay ) {
		clearTimeout( timer );
		timer = null;
		if ( stopped || document.hidden ) {
			return;
		}
		timer = setTimeout( check, delay );
	}

	/**
	 * Schedules the next check at the delay the current run has reached.
	 */
	function scheduleNext() {
		const elapsed = Date.now() - runStart;
		const step = SCHEDULE.find( ( entry ) => elapsed < entry.until );
		schedule( step.delay );
	}

	/**
	 * Asks the server whether the post has changed and shows a notice if so.
	 */
	async function check() {
		timer = null;
		if ( stopped || document.hidden ) {
			return;
		}

		lastCheck = Date.now();

		let response;
		try {
			response = await fetch( config.endpoint, {
				cache: 'no-store',
				headers: { Accept: 'application/json' },
			} );
		} catch ( error ) {
			scheduleNext();
			return;
		}

		// The link has expired or the preview was disabled. Neither recovers
		// without a new link, so there is nothing left to watch.
		if ( response.status === 403 || response.status === 404 ) {
			stop();
			return;
		}

		if ( ! response.ok ) {
			scheduleNext();
			return;
		}

		let data;
		try {
			data = await response.json();
		} catch ( error ) {
			scheduleNext();
			return;
		}

		if ( data.status === 'published' && data.permalink ) {
			stop();
			showNotice(
				config.strings.published,
				config.strings.viewPost,
				data.permalink
			);
			return;
		}

		if (
			data.status === 'preview' &&
			data.modified &&
			data.modified !== config.modified
		) {
			stop();
			showNotice( config.strings.updated, config.strings.reload );
			return;
		}

		scheduleNext();
	}

	/**
	 * Creates an element with a BEM class for the notice.
	 *
	 * @param {string} tag    Tag name.
	 * @param {string} suffix BEM element suffix.
	 * @return {HTMLElement} The element.
	 */
	function createElement( tag, suffix ) {
		const element = document.createElement( tag );
		element.className = 'public-post-preview-updates__' + suffix;
		return element;
	}

	/**
	 * Renders the notice into the live region.
	 *
	 * @param {string} message     Text of the notice.
	 * @param {string} actionLabel Label for the action control.
	 * @param {string} [href]      Link target. Without it the action reloads the page.
	 */
	function showNotice( message, actionLabel, href ) {
		const toast = createElement( 'div', 'toast' );

		const text = createElement( 'p', 'text' );
		text.textContent = message;

		let action;
		if ( href ) {
			action = createElement( 'a', 'action' );
			action.href = href;
		} else {
			action = createElement( 'button', 'action' );
			action.type = 'button';
			action.addEventListener( 'click', function () {
				window.location.reload();
			} );
		}
		action.textContent = actionLabel;

		const dismiss = createElement( 'button', 'dismiss' );
		dismiss.type = 'button';
		dismiss.setAttribute( 'aria-label', config.strings.dismiss );
		dismiss.textContent = '×';
		dismiss.addEventListener( 'click', function () {
			container.textContent = '';
		} );

		toast.append( text, action, dismiss );
		container.textContent = '';
		container.append( toast );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( stopped ) {
			return;
		}

		if ( document.hidden ) {
			clearTimeout( timer );
			timer = null;
			return;
		}

		// Back in view: start a fresh run from the base interval, checking
		// right away if the last check is older than that.
		runStart = Date.now();
		const elapsed = Date.now() - lastCheck;
		if ( elapsed >= BASE_INTERVAL ) {
			check();
		} else {
			schedule( BASE_INTERVAL - elapsed );
		}
	} );

	scheduleNext();
} )( window.DSPublicPostPreviewUpdates );
