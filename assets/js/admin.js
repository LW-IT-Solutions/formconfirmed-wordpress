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

	/* ------------------------------------------------------------------ 1.8.0 */

	var still = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var numberFormat = window.Intl && Intl.NumberFormat ? new Intl.NumberFormat( document.documentElement.lang || undefined ) : null;

	function format( n ) {
		return numberFormat ? numberFormat.format( n ) : String( n );
	}
	function fill( text, a, b ) {
		return String( text ).replace( '%1$d', a ).replace( '%2$d', b ).replace( '%d', a );
	}
	// Runs once when the element comes into view (at once without IntersectionObserver).
	function whenSeen( node, run ) {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			run();
			return;
		}
		var seen = new IntersectionObserver( function ( entries ) {
			if ( entries[ 0 ].isIntersecting ) {
				seen.disconnect();
				run();
			}
		}, { threshold: 0.2 } );
		seen.observe( node );
	}

	// Counters count up once when they appear. The final value is in the page; reduced motion keeps it as it is.
	if ( ! still && window.requestAnimationFrame ) {
		Array.prototype.forEach.call( wrap.querySelectorAll( '[data-fc-count]' ), function ( node ) {
			var target = parseInt( node.getAttribute( 'data-fc-count' ), 10 );
			var final = node.textContent;
			if ( ! target || target < 10 ) {
				return;
			}
			node.textContent = format( 0 );
			whenSeen( node, function () {
				var start = null;
				function step( now ) {
					start = start || now;
					var p = Math.min( 1, ( now - start ) / 800 );
					node.textContent = p < 1 ? format( Math.round( target * ( 1 - Math.pow( 1 - p, 3 ) ) ) ) : final;
					if ( p < 1 ) {
						window.requestAnimationFrame( step );
					}
				}
				window.requestAnimationFrame( step );
			} );
		} );
	}

	// Ring chart: a segment or tile under the pointer or in focus names its number in the middle.
	Array.prototype.forEach.call( wrap.querySelectorAll( '[data-fc-donut]' ), function ( donut ) {
		var box = donut.closest( '.fc-dist-top' ) || donut;
		var n = donut.querySelector( '[data-fc-center-n]' );
		var l = donut.querySelector( '[data-fc-center-l]' );
		var segs = donut.querySelectorAll( '.fc-donut-seg' );
		function show( key ) {
			var hit = null;
			Array.prototype.forEach.call( segs, function ( seg ) {
				var on = seg.getAttribute( 'data-fc-key' ) === key;
				seg.classList.toggle( 'is-active', on );
				hit = on ? seg : hit;
			} );
			Array.prototype.forEach.call( box.querySelectorAll( '.fc-tile[data-fc-key]' ), function ( tile ) {
				tile.classList.toggle( 'is-active', tile.getAttribute( 'data-fc-key' ) === key );
			} );
			donut.classList.toggle( 'has-active', !! hit );
			n.textContent = hit ? hit.getAttribute( 'data-fc-n' ) : n.getAttribute( 'data-fc-default' );
			l.textContent = hit ? hit.getAttribute( 'data-fc-l' ) : l.getAttribute( 'data-fc-default' );
		}
		Array.prototype.forEach.call( box.querySelectorAll( '[data-fc-key]' ), function ( node ) {
			var key = node.getAttribute( 'data-fc-key' );
			node.addEventListener( 'mouseenter', function () {
				show( key );
			} );
			node.addEventListener( 'focus', function () {
				show( key );
			} );
			node.addEventListener( 'mouseleave', function () {
				show( '' );
			} );
			node.addEventListener( 'blur', function () {
				show( '' );
			} );
		} );
		if ( ! still ) {
			donut.classList.add( 'is-waiting' );
			whenSeen( donut, function () {
				donut.classList.remove( 'is-waiting' );
				donut.classList.add( 'is-drawing' );
			} );
		}
	} );

	// Settings: the flow follows the switches before they are saved.
	Array.prototype.forEach.call( wrap.querySelectorAll( '[data-fc-flow]' ), function ( flow ) {
		var form = flow.closest( 'form' );
		if ( ! form ) {
			return;
		}
		var delegated = '1' === flow.getAttribute( 'data-fc-delegated' );
		var backup = form.querySelector( '#fc-backup' );
		var content = form.querySelector( '#fc-content' );
		function current() {
			var picked = form.querySelector( 'input[name="uncertain"]:checked' );
			return {
				hold: ! picked || 'reject' !== picked.value,
				backup: delegated || !! ( backup && backup.checked ),
				content: !! ( content && content.checked ),
			};
		}
		var first = current();
		function pulse( node ) {
			if ( still ) {
				return;
			}
			node.classList.remove( 'is-changed' );
			void node.offsetWidth; // restart the animation
			node.classList.add( 'is-changed' );
		}
		function render() {
			var now = current();
			Array.prototype.forEach.call( flow.querySelectorAll( '.fc-flow-unclear' ), function ( node ) {
				var text = node.getAttribute( now.hold ? 'data-fc-hold' : 'data-fc-reject' );
				if ( node.textContent !== text ) {
					node.textContent = text;
					node.classList.toggle( 'fc-tone-warn', now.hold );
					node.classList.toggle( 'fc-tone-bad', ! now.hold );
					pulse( node );
				}
			} );
			var mid = flow.querySelector( '[data-fc-backup-node]' );
			var midText = mid.querySelector( '[data-fc-backup-on]' );
			var wanted = midText.getAttribute( now.backup ? 'data-fc-backup-on' : 'data-fc-backup-off' );
			if ( midText.textContent !== wanted ) {
				midText.textContent = wanted;
				pulse( mid );
			}
			mid.classList.toggle( 'is-off', ! now.backup );
			flow.querySelector( '[data-fc-backup-pass]' ).hidden = ! now.backup;
			flow.querySelector( '[data-fc-backup-fail-label]' ).hidden = ! now.backup;
			var check = flow.querySelector( '[data-fc-content-on]' );
			var checkText = check.getAttribute( now.content ? 'data-fc-content-on' : 'data-fc-content-off' );
			if ( check.textContent !== checkText ) {
				check.textContent = checkText;
				pulse( check.parentNode );
			}
			flow.querySelector( '[data-fc-flow-unsaved]' ).hidden = now.hold === first.hold && now.backup === first.backup && now.content === first.content;
		}
		form.addEventListener( 'change', render );
	} );

	// Forms tab: search, filter, live count and a bar for unsaved changes.
	var formsForm = wrap.querySelector( 'form[data-fc-forms-form]' );
	var toolbar = wrap.querySelector( '[data-fc-toolbar]' );
	if ( formsForm && toolbar ) {
		var search = toolbar.querySelector( '[data-fc-search]' );
		var chips = toolbar.querySelectorAll( '[data-fc-show]' );
		var shown = toolbar.querySelector( '[data-fc-shown]' );
		var noMatch = wrap.querySelector( '[data-fc-nomatch]' );
		var summary = wrap.querySelector( '[data-fc-summary]' );
		var savebar = formsForm.querySelector( '[data-fc-savebar]' );
		var boxes = formsForm.querySelectorAll( 'input[type="checkbox"]' );
		var initial = Array.prototype.map.call( boxes, function ( box ) {
			return box.checked;
		} );
		var mode = 'all';
		toolbar.hidden = false;

		// The plugin name is the first text of each heading: wrap it once, so a search can mark it.
		Array.prototype.forEach.call( formsForm.querySelectorAll( '.fc-place h2' ), function ( heading ) {
			if ( heading.firstChild && 3 === heading.firstChild.nodeType ) {
				heading.replaceChild( el( 'span', heading.firstChild.textContent.trim(), 'fc-place-name' ), heading.firstChild );
			}
		} );

		function rowOn( row ) {
			var box = row.querySelector( 'input[type="checkbox"]' );
			var section = row.closest( '[data-fc-adapter]' );
			var adapter = section && section.querySelector( '#fc-a-' + section.getAttribute( 'data-fc-adapter' ) );
			return !! ( box && box.checked && ( ! adapter || adapter.checked ) );
		}
		function mark( node, term ) {
			var name = node.getAttribute( 'data-fc-plain' );
			if ( null === name ) {
				name = node.textContent;
				node.setAttribute( 'data-fc-plain', name );
			}
			var at = term ? name.toLowerCase().indexOf( term ) : -1;
			node.textContent = '';
			if ( at < 0 ) {
				node.textContent = name;
				return;
			}
			node.appendChild( document.createTextNode( name.slice( 0, at ) ) );
			node.appendChild( el( 'mark', name.slice( at, at + term.length ), 'fc-hit' ) );
			node.appendChild( document.createTextNode( name.slice( at + term.length ) ) );
		}
		function filter() {
			var term = search.value.trim().toLowerCase();
			var total = 0;
			var visible = 0;
			Array.prototype.forEach.call( formsForm.querySelectorAll( '.fc-place' ), function ( section ) {
				var rows = section.querySelectorAll( 'tr[data-fc-row]' );
				var nameNode = section.querySelector( '.fc-place-name' );
				var sectionHit = !! term && ( section.getAttribute( 'data-fc-name' ) || '' ).toLowerCase().indexOf( term ) >= 0;
				var avail = '1' === section.getAttribute( 'data-fc-avail' );
				var any = false;
				if ( rows.length ) {
					Array.prototype.forEach.call( rows, function ( row ) {
						var label = row.querySelector( 'td:nth-child(2) > label' );
						var hit = ( ! term || sectionHit || ( row.getAttribute( 'data-fc-name' ) || '' ).toLowerCase().indexOf( term ) >= 0 ) &&
							( 'all' === mode || ( 'on' === mode ) === rowOn( row ) );
						row.hidden = ! hit;
						total++;
						visible += hit ? 1 : 0;
						any = any || hit;
						if ( label ) {
							mark( label, sectionHit ? '' : term );
						}
					} );
				} else if ( avail && '1' !== section.getAttribute( 'data-fc-perform' ) ) {
					var place = section.querySelector( '#fc-a-' + section.getAttribute( 'data-fc-adapter' ) );
					any = ( ! term || sectionHit ) && ( 'all' === mode || ( 'on' === mode ) === !! ( place && place.checked ) );
					total++;
					visible += any ? 1 : 0;
				}
				section.hidden = !! ( term || 'all' !== mode ) && ! any;
				if ( nameNode ) {
					mark( nameNode, sectionHit ? term : '' );
				}
			} );
			Array.prototype.forEach.call( formsForm.querySelectorAll( '.fc-card:not(.fc-place)' ), function ( card ) {
				card.hidden = !! term || 'all' !== mode;
			} );
			noMatch.hidden = visible > 0;
			shown.textContent = fill( t.shown, visible, total );
		}
		function count() {
			var on = 0;
			var changed = 0;
			Array.prototype.forEach.call( formsForm.querySelectorAll( '.fc-place[data-fc-avail="1"]' ), function ( section ) {
				var adapter = section.querySelector( '#fc-a-' + section.getAttribute( 'data-fc-adapter' ) );
				var adapterOn = !! ( adapter && adapter.checked );
				section.classList.toggle( 'is-paused', ! adapterOn );
				if ( '1' === section.getAttribute( 'data-fc-perform' ) ) {
					var forms = 0;
					Array.prototype.forEach.call( section.querySelectorAll( 'tr[data-fc-row]' ), function ( row ) {
						forms += rowOn( row ) ? 1 : 0;
					} );
					on += forms;
					var badge = section.querySelector( '[data-fc-badge]' );
					if ( badge ) {
						badge.textContent = fill( t.formsOf, forms, badge.getAttribute( 'data-fc-total' ) );
						badge.classList.toggle( 'fc-badge-on', forms > 0 );
						badge.classList.toggle( 'fc-badge-off', ! forms );
					}
				} else {
					on += adapterOn ? 1 : 0;
				}
			} );
			Array.prototype.forEach.call( boxes, function ( box, i ) {
				changed += box.checked !== initial[ i ] ? 1 : 0;
			} );
			if ( summary ) {
				var places = parseInt( summary.getAttribute( 'data-fc-places' ), 10 ) || 1;
				var saved = parseInt( summary.getAttribute( 'data-fc-saved' ), 10 ) || 0;
				var ghost = summary.querySelector( '.fc-meter-ghost' );
				summary.querySelector( '.fc-meter-fill' ).style.width = Math.round( Math.min( on, saved ) / places * 100 ) + '%';
				ghost.style.left = Math.round( Math.min( on, saved ) / places * 100 ) + '%';
				ghost.style.width = Math.round( Math.abs( on - saved ) / places * 100 ) + '%';
				ghost.classList.toggle( 'is-less', on < saved );
				summary.querySelector( '[data-fc-summary-live]' ).textContent = changed ? fill( t.after, on, places ) : '';
				summary.classList.toggle( 'is-changed', !! changed );
			}
			savebar.hidden = ! changed;
			savebar.querySelector( '[data-fc-savebar-text]' ).textContent = fill( t.unsaved, changed );
		}
		search.addEventListener( 'input', filter );
		search.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && search.value ) {
				event.preventDefault();
				search.value = '';
				filter();
			}
		} );
		Array.prototype.forEach.call( chips, function ( chip ) {
			chip.addEventListener( 'click', function () {
				mode = chip.getAttribute( 'data-fc-show' );
				Array.prototype.forEach.call( chips, function ( other ) {
					other.setAttribute( 'aria-pressed', other === chip ? 'true' : 'false' );
				} );
				filter();
			} );
		} );
		noMatch.querySelector( '[data-fc-reset]' ).addEventListener( 'click', function () {
			search.value = '';
			chips[ 0 ].click();
			search.focus();
		} );
		formsForm.addEventListener( 'change', function () {
			count();
			if ( 'all' !== mode ) {
				filter();
			}
		} );
		savebar.querySelector( '[data-fc-undo]' ).addEventListener( 'click', function () {
			Array.prototype.forEach.call( boxes, function ( box, i ) {
				box.checked = initial[ i ];
			} );
			count();
			filter();
			if ( summary ) {
				summary.querySelector( '[data-fc-summary-live]' ).textContent = t.undone;
			}
			search.focus();
		} );
		// "/" jumps to the search field.
		document.addEventListener( 'keydown', function ( event ) {
			var target = event.target || {};
			if ( '/' === event.key && ! event.ctrlKey && ! event.metaKey && ! event.altKey && ! /^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName || '' ) && ! target.isContentEditable ) {
				event.preventDefault();
				search.focus();
			}
		} );
		count();
		filter();
	}

	// Held messages: the legend filters the rows of this page by reason.
	var heldSummary = wrap.querySelector( '[data-fc-held-summary]' );
	if ( heldSummary ) {
		var heldRows = wrap.querySelectorAll( '.fc-held tbody tr[data-fc-reason]' );
		var picked = '';
		var heldLive = el( 'p', '', 'fc-held-shown' );
		heldLive.setAttribute( 'role', 'status' );
		heldSummary.appendChild( heldLive );
		var buttons = Array.prototype.map.call( heldSummary.querySelectorAll( '.fc-legend-item' ), function ( item ) {
			var button = el( 'button', '', 'fc-legend-item fc-chip' );
			button.type = 'button';
			button.setAttribute( 'data-fc-reason', item.getAttribute( 'data-fc-reason' ) );
			button.setAttribute( 'aria-pressed', 'false' );
			while ( item.firstChild ) {
				button.appendChild( item.firstChild );
			}
			item.parentNode.replaceChild( button, item );
			return button;
		} );
		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var reason = button.getAttribute( 'data-fc-reason' );
				var n = 0;
				picked = picked === reason ? '' : reason;
				buttons.forEach( function ( other ) {
					other.setAttribute( 'aria-pressed', other.getAttribute( 'data-fc-reason' ) === picked ? 'true' : 'false' );
				} );
				Array.prototype.forEach.call( heldRows, function ( row ) {
					row.hidden = !! picked && row.getAttribute( 'data-fc-reason' ) !== picked;
					n += row.hidden ? 0 : 1;
				} );
				heldSummary.classList.toggle( 'is-filtered', !! picked );
				heldLive.textContent = picked ? fill( t.heldShown, n ) : '';
			} );
		} );
	}
}() );
