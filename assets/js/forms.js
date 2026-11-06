/**
 * formconfirmed - puts the widget element into forms that the server cannot equip itself
 * (Elementor Pro forms, multi-step Fluent Forms), also into forms that appear later (popups).
 * The widget script of the service does the rest. Settings: window.formconfirmedForms
 * (sel: CSS selector, sitekey, purpose).
 */
( function () {
	'use strict';
	var c = window.formconfirmedForms;
	if ( ! c || ! c.sel || ! c.sitekey ) {
		return;
	}
	function equip() {
		var forms = document.querySelectorAll( c.sel ), neu = false;
		for ( var i = 0; i < forms.length; i++ ) {
			if ( forms[ i ].querySelector( '.fcp-widget' ) ) {
				continue;
			}
			var box = document.createElement( 'div' );
			box.className = 'fcp-widget';
			box.setAttribute( 'data-sitekey', c.sitekey );
			box.setAttribute( 'data-purpose', c.purpose || 'contact' );
			var knopf = forms[ i ].querySelector( 'button[type="submit"], input[type="submit"]' );
			if ( knopf && knopf.parentNode && forms[ i ].contains( knopf.parentNode ) ) {
				knopf.parentNode.insertBefore( box, knopf );
			} else {
				forms[ i ].appendChild( box );
			}
			neu = true;
		}
		if ( neu && window.__fcpWidget && typeof window.__fcpWidget.scan === 'function' ) {
			window.__fcpWidget.scan();
		}
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', equip );
	} else {
		equip();
	}
	if ( typeof MutationObserver !== 'undefined' ) {
		var warte = null;
		new MutationObserver( function () {
			if ( warte ) {
				return;
			}
			warte = setTimeout( function () {
				warte = null;
				equip();
			}, 200 );
		} ).observe( document.documentElement, { childList: true, subtree: true } );
	}
}() );
