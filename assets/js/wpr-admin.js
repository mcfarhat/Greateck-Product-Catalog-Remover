/**
 * Drives the batched removal from the browser.
 *
 * Each request deletes a slice of the catalog and reports back, so a store with
 * 100,000 products never depends on a single long-running PHP request.
 *
 * @package WooProductRemover
 */

( function () {
	'use strict';

	var form       = document.getElementById( 'wpr-form' );
	var startBtn   = document.getElementById( 'wpr-start' );
	var understand = document.getElementById( 'wpr-understand' );
	var progress   = document.getElementById( 'wpr-progress' );
	var barFill    = document.getElementById( 'wpr-bar-fill' );
	var status     = document.getElementById( 'wpr-status' );
	var results    = document.getElementById( 'wpr-results' );

	if ( ! form || ! startBtn ) {
		return;
	}

	var i18n = wprData.i18n;

	/**
	 * Swap the placeholders in a translated string.
	 *
	 * @param {string} template String containing %1$s and %2$s.
	 * @param {string} one      First replacement.
	 * @param {string} two      Second replacement.
	 * @return {string} Rendered string.
	 */
	function format( template, one, two ) {
		return template.replace( '%1$s', one ).replace( '%2$s', two );
	}

	/**
	 * Post to admin-ajax.
	 *
	 * @param {string} action Action name.
	 * @param {Object} extra  Additional fields.
	 * @return {Promise<Object>} Parsed payload.
	 */
	function post( action, extra ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', wprData.nonce );

		Object.keys( extra || {} ).forEach( function ( key ) {
			body.append( key, extra[ key ] );
		} );

		return fetch( wprData.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var message = payload && payload.data && payload.data.message
						? payload.data.message
						: i18n.failed;

					throw new Error( message );
				}

				return payload.data;
			} );
	}

	/**
	 * Update the bar and the status line.
	 *
	 * @param {Object} data Response payload.
	 */
	function render( data ) {
		var total   = data.total || 0;
		var handled = data.handled || 0;
		var percent = total > 0 ? Math.min( 100, Math.round( ( handled / total ) * 100 ) ) : 100;

		barFill.style.width = percent + '%';

		if ( data.done ) {
			status.textContent = i18n.done;
		} else if ( data.remaining === 0 ) {
			status.textContent = i18n.finishing;
		} else {
			status.textContent = format(
				i18n.working,
				handled.toLocaleString(),
				total.toLocaleString()
			);
		}
	}

	/**
	 * List what was removed once the run finishes.
	 *
	 * @param {Object} counts Counters keyed by type.
	 */
	function renderResults( counts ) {
		var labels = {
			products: i18n.products,
			variations: i18n.variations,
			images: i18n.images,
			reviews: i18n.reviews,
			terms: i18n.terms
		};

		results.innerHTML = '';

		Object.keys( labels ).forEach( function ( key ) {
			if ( ! counts[ key ] ) {
				return;
			}

			var item = document.createElement( 'li' );

			item.textContent = labels[ key ] + ': ' + counts[ key ].toLocaleString();
			results.appendChild( item );
		} );

		if ( ! results.children.length ) {
			var empty = document.createElement( 'li' );

			empty.textContent = i18n.nothing;
			results.appendChild( empty );
		}
	}

	/**
	 * Run batches until the server says it is finished.
	 *
	 * @return {Promise<Object>} Final payload.
	 */
	function step() {
		return post( 'wpr_step', {} ).then( function ( data ) {
			render( data );

			if ( data.error ) {
				throw new Error( data.error );
			}

			if ( data.done ) {
				return data;
			}

			return step();
		} );
	}

	understand.addEventListener( 'change', function () {
		startBtn.disabled = ! understand.checked;
	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		if ( ! understand.checked ) {
			return;
		}

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( i18n.confirm ) ) {
			return;
		}

		startBtn.disabled  = true;
		understand.disabled = true;
		progress.hidden    = false;
		results.innerHTML  = '';
		status.textContent = i18n.starting;

		var options = {};

		[ 'remove_terms', 'remove_images', 'remove_reviews', 'remove_downloads' ].forEach( function ( name ) {
			var field = form.querySelector( '[name="' + name + '"]' );

			if ( field && field.checked ) {
				options[ name ] = '1';
			}
		} );

		post( 'wpr_start', options )
			.then( function ( data ) {
				render( data );

				if ( 0 === data.remaining ) {
					// Nothing to delete, but the cleanup pass still needs to run.
					return step();
				}

				return step();
			} )
			.then( function ( data ) {
				renderResults( data.counts );
			} )
			.catch( function ( error ) {
				status.textContent = error.message || i18n.failed;
				status.classList.add( 'wpr-error' );
				startBtn.disabled = false;
				understand.disabled = false;
			} );
	} );
}() );
