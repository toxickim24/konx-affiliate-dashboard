/**
 * KonX Affiliate Dashboard — Referral Tracking (localStorage fallback).
 *
 * This script runs on every frontend page. It provides a localStorage-based
 * parallel to the server-set HttpOnly cookie so that the referral code
 * survives full-page caching and environments where cookies are stripped.
 *
 * Flow:
 *  1. On any page with ?ref=CODE — store the code in localStorage.
 *  2. On the WooCommerce checkout page — read localStorage and populate
 *     a hidden <input> so the server can read it if the cookie is absent.
 *  3. On the thank-you page — clear localStorage.
 *
 * The HttpOnly cookie (set by PHP) is the primary mechanism. localStorage
 * is the fallback. The server tries the cookie first, then falls back to
 * the hidden field value that this script provides.
 *
 * @package KonxAffiliateDashboard
 */
( function () {
	'use strict';

	var STORAGE_KEY = 'konx_ref';

	/* ----------------------------------------------------------------
	 * Capture referral code from URL
	 * -------------------------------------------------------------- */
	function captureReferral() {
		var params, ref;

		try {
			params = new URLSearchParams( window.location.search );
			ref    = params.get( 'ref' );
		} catch ( e ) {
			return;
		}

		if ( ref && ref.length > 0 && ref.length <= 12 ) {
			try {
				localStorage.setItem( STORAGE_KEY, ref.toUpperCase() );
			} catch ( e ) {
				// localStorage unavailable — cookie is still set server-side.
			}
		}
	}

	/* ----------------------------------------------------------------
	 * Populate the hidden checkout field from localStorage
	 * -------------------------------------------------------------- */
	function populateCheckoutField() {
		var field = document.getElementById( 'konx_referral_code' );
		if ( ! field ) {
			return;
		}

		try {
			var code = localStorage.getItem( STORAGE_KEY );
			if ( code ) {
				field.value = code;
			}
		} catch ( e ) {
			// localStorage unavailable.
		}
	}

	/* ----------------------------------------------------------------
	 * Clear stored referral (called on thank-you page)
	 * -------------------------------------------------------------- */
	function clearReferral() {
		try {
			localStorage.removeItem( STORAGE_KEY );
		} catch ( e ) {
			// Ignore.
		}
	}

	/* ----------------------------------------------------------------
	 * Initialise
	 * -------------------------------------------------------------- */
	function onReady() {
		captureReferral();
		populateCheckoutField();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', onReady );
	} else {
		onReady();
	}

	// Re-populate after WooCommerce refreshes the checkout via AJAX.
	if ( typeof jQuery !== 'undefined' ) {
		jQuery( function ( $ ) {
			$( document.body ).on( 'updated_checkout', populateCheckoutField );
		} );
	}

	// Expose clear function for the thank-you page inline script.
	window.konxReferral = { clear: clearReferral };
} )();
