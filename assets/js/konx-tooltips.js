/**
 * KonX Tooltip — Touch device fallback.
 *
 * CSS handles positioning via :hover/:focus. This script adds
 * toggle behavior for touch devices where hover isn't available.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var tooltip = e.target.closest( '.konx-tooltip' );

		// Close all open tooltips.
		document.querySelectorAll( '.konx-tooltip.open' ).forEach( function ( t ) {
			if ( t !== tooltip ) {
				t.classList.remove( 'open' );
			}
		} );

		// Toggle the clicked tooltip.
		if ( tooltip ) {
			tooltip.classList.toggle( 'open' );
			e.preventDefault();
		}
	} );
} )();
