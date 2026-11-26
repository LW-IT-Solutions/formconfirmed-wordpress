/**
 * formconfirmed - Settings -> formconfirmed.
 * Asks in a dialog of the page before a held message is deleted, adds copy buttons to the code examples, lets the
 * typed secret key be shown before saving and shows that a test or report is running. Every link and form also
 * works without this script. Settings: window.formconfirmedAdmin (texts).
 */
( function () {
	'use strict';
	var t = window.formconfirmedAdmin;
	var wrap = document.querySelector( '.fc-wrap' );
	if ( ! t || ! wrap ) {
		return;
	}

	function el( tag, text, className ) {
		var node = document.createElement( tag );
		if ( text ) {
			node.textContent = text;
		}
		if ( className ) {
			node.className = className;
		}
		return node;
	}

	/* Confirmation in a modal dialog: keeps the focus inside, closes on Escape, Enter activates the focused
	   button, and the focus returns to the link that opened it. */
	function confirmDialog( opener, onConfirm ) {
		var dialog = el( 'dialog', '', 'fc-dialog' );
		var heading = el( 'h2', t.deleteTitle );
		heading.id = 'fc-dialog-title';
		var text = el( 'p', t.deleteText );
		text.id = 'fc-dialog-text';
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', heading.id );
		dialog.setAttribute( 'aria-describedby', text.id );
		var actions = el( 'p', '', 'fc-dialog-actions' );
		var cancel = el( 'button', t.cancel, 'button' );
		cancel.type = 'button';
		var ok = el( 'button', t.delete, 'button button-primary fc-dialog-delete' );
		ok.type = 'button';
		actions.appendChild( cancel );
		actions.appendChild( ok );
		dialog.appendChild( heading );
		dialog.appendChild( text );
		dialog.appendChild( actions );
		cancel.addEventListener( 'click', function () {
			dialog.close( 'cancel' );
		} );
		ok.addEventListener( 'click', function () {
			dialog.close( 'ok' );
		} );
		dialog.addEventListener( 'close', function () {
			dialog.remove();
			if ( 'ok' === dialog.returnValue ) {
				onConfirm();
			} else if ( opener ) {
				opener.focus();
			}
		} );
		document.body.appendChild( dialog );
		dialog.showModal();
		cancel.focus();
	}

	// Delete a held message only after the dialog.
	Array.prototype.forEach.call( wrap.querySelectorAll( 'a.fc-delete' ), function ( link ) {
		link.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			confirmDialog( link, function () {
				window.location.href = link.href;
			} );
		} );
	} );

	// Copy buttons for the code examples.
	Array.prototype.forEach.call( wrap.querySelectorAll( 'pre.fc-code' ), function ( pre ) {
		if ( ! navigator.clipboard || ! window.isSecureContext ) {
			return;
		}
		var box = el( 'div', '', 'fc-code-box' );
		pre.parentNode.insertBefore( box, pre );
		box.appendChild( pre );
		var button = el( 'button', t.copy, 'button button-small fc-copy' );
		button.type = 'button';
		var live = el( 'span', '', 'screen-reader-text' );
		live.setAttribute( 'aria-live', 'polite' );
		box.appendChild( button );
		box.appendChild( live );
		button.addEventListener( 'click', function () {
			navigator.clipboard.writeText( pre.textContent ).then( function () {
				button.textContent = t.copied;
				live.textContent = t.copied;
				window.setTimeout( function () {
					button.textContent = t.copy;
					live.textContent = '';
				}, 2000 );
			} );
		} );
	} );

	// Show or hide the typed secret key. A saved key is never in the page.
	Array.prototype.forEach.call( wrap.querySelectorAll( '.fc-reveal' ), function ( button ) {
		var input = document.getElementById( button.getAttribute( 'aria-controls' ) );
		if ( ! input ) {
			return;
		}
		button.hidden = false;
		button.addEventListener( 'click', function () {
			var show = 'password' === input.type;
			input.type = show ? 'text' : 'password';
			button.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
			button.querySelector( '.screen-reader-text' ).textContent = show ? t.hide : t.show;
			var icon = button.querySelector( '.dashicons' );
			icon.classList.toggle( 'dashicons-visibility', ! show );
			icon.classList.toggle( 'dashicons-hidden', show );
		} );
	} );

	// Test and report run on the server and can take a few seconds: show it and prevent a second click.
	Array.prototype.forEach.call( wrap.querySelectorAll( 'form.fc-busy-form' ), function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var button = form.querySelector( 'button' );
			if ( 'true' === button.getAttribute( 'aria-busy' ) ) {
				event.preventDefault();
				return;
			}
			button.setAttribute( 'aria-busy', 'true' );
			var spinner = form.querySelector( '.spinner' );
			if ( spinner ) {
				spinner.classList.add( 'is-active' );
			}
		} );
	} );
}() );
