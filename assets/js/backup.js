/**
 * formconfirmed - the backup check in the browser (see includes/class-formconfirmed-backup.php).
 *
 * Equips every protected contact form and comment form (a form with a .fcp-widget element whose
 * purpose is "contact" or "comment") with the hidden fields of the backup check, copies the
 * interaction proof into the form on its first real interaction, fetches a proof-of-work challenge
 * from THIS site (admin-ajax, action formconfirmed_pow), solves it in small steps and holds a
 * submit back until the answer is in the field. Nothing is loaded from anywhere else.
 * Settings: window.formconfirmedBackup.
 */
( function ( c ) {
	'use strict';
	if ( ! c ) {
		return;
	}
	function field( f, n, v ) {
		if ( f.querySelector( 'input[name="' + n + '"]' ) ) {
			return null;
		}
		var i = document.createElement( 'input' );
		i.type = 'hidden';
		i.name = n;
		i.value = v;
		f.appendChild( i );
		return i;
	}
	function equip( f ) {
		field( f, 'formconfirmed_t', c.time );
		field( f, 'formconfirmed_s', c.token );
		var j = field( f, 'formconfirmed_j', '' );
		if ( j ) {
			j.setAttribute( 'data-fc', c.fe );
		}
		field( f, 'formconfirmed_pow', '' );
		if ( ! f.querySelector( 'input[name="formconfirmed_hp"]' ) ) {
			var s = document.createElement( 'span' ), l = document.createElement( 'label' ), i = document.createElement( 'input' );
			s.setAttribute( 'aria-hidden', 'true' );
			s.style.cssText = 'position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important';
			i.type = 'text';
			i.name = 'formconfirmed_hp';
			i.value = '';
			i.tabIndex = -1;
			i.setAttribute( 'autocomplete', 'off' );
			l.appendChild( document.createTextNode( c.label ) );
			l.appendChild( i );
			s.appendChild( l );
			f.appendChild( s );
		}
	}
	/* SHA-256 in plain JavaScript: crypto.subtle exists only on https pages. */
	var K = new Int32Array( [ 0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da, 0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070, 0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2 ] ), W = new Int32Array( 64 );
	function sha( s ) {
		var n = s.length, b = ( ( n + 8 ) >> 6 ) + 1, M = new Int32Array( b << 4 ), i, j, x, y, t1, t2, a, d, e, g, h, k, l, m,
			h0 = 0x6a09e667, h1 = 0xbb67ae85 | 0, h2 = 0x3c6ef372, h3 = 0xa54ff53a | 0, h4 = 0x510e527f, h5 = 0x9b05688c | 0, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
		for ( i = 0; i < n; i++ ) {
			M[ i >> 2 ] |= s.charCodeAt( i ) << ( 24 - ( ( i & 3 ) << 3 ) );
		}
		M[ n >> 2 ] |= 0x80 << ( 24 - ( ( n & 3 ) << 3 ) );
		M[ ( b << 4 ) - 1 ] = n << 3;
		for ( j = 0; j < b << 4; j += 16 ) {
			for ( i = 0; i < 16; i++ ) {
				W[ i ] = M[ j + i ];
			}
			for ( i = 16; i < 64; i++ ) {
				x = W[ i - 15 ];
				y = W[ i - 2 ];
				W[ i ] = ( ( ( x >>> 7 ) | ( x << 25 ) ) ^ ( ( x >>> 18 ) | ( x << 14 ) ) ^ ( x >>> 3 ) ) + W[ i - 16 ] + ( ( ( y >>> 17 ) | ( y << 15 ) ) ^ ( ( y >>> 19 ) | ( y << 13 ) ) ^ ( y >>> 10 ) ) + W[ i - 7 ] | 0;
			}
			a = h0; k = h1; l = h2; d = h3; e = h4; m = h5; g = h6; h = h7;
			for ( i = 0; i < 64; i++ ) {
				t1 = h + ( ( ( e >>> 6 ) | ( e << 26 ) ) ^ ( ( e >>> 11 ) | ( e << 21 ) ) ^ ( ( e >>> 25 ) | ( e << 7 ) ) ) + ( ( e & m ) ^ ( ~e & g ) ) + K[ i ] + W[ i ] | 0;
				t2 = ( ( ( a >>> 2 ) | ( a << 30 ) ) ^ ( ( a >>> 13 ) | ( a << 19 ) ) ^ ( ( a >>> 22 ) | ( a << 10 ) ) ) + ( ( a & k ) ^ ( a & l ) ^ ( k & l ) ) | 0;
				h = g; g = m; m = e; e = d + t1 | 0; d = l; l = k; k = a; a = t1 + t2 | 0;
			}
			h0 = h0 + a | 0; h1 = h1 + k | 0; h2 = h2 + l | 0; h3 = h3 + d | 0; h4 = h4 + e | 0; h5 = h5 + m | 0; h6 = h6 + g | 0; h7 = h7 + h | 0;
		}
		return [ h0, h1, h2, h3, h4, h5, h6, h7 ];
	}
	function solve( ch, done ) {
		var t = [], n = 0, max = ch.maxnumber | 0, i;
		for ( i = 0; i < 8; i++ ) {
			t[ i ] = parseInt( ch.challenge.substr( i * 8, 8 ), 16 ) | 0;
		}
		( function step() {
			var bis = Math.min( n + ( ( c.chunk | 0 ) || 2000 ), max + 1 ), r;
			for ( ; n < bis; n++ ) {
				r = sha( ch.salt + n );
				if ( r[ 0 ] === t[ 0 ] && r[ 1 ] === t[ 1 ] && r[ 2 ] === t[ 2 ] && r[ 3 ] === t[ 3 ] && r[ 4 ] === t[ 4 ] && r[ 5 ] === t[ 5 ] && r[ 6 ] === t[ 6 ] && r[ 7 ] === t[ 7 ] ) {
					return done( JSON.stringify( { algorithm: ch.algorithm, challenge: ch.challenge, number: n, salt: ch.salt, signature: ch.signature } ) );
				}
			}
			if ( n > max ) {
				return done( '' );
			}
			setTimeout( step, 0 );
		}() );
	}
	function st( f ) {
		return f.__fcBackup || ( f.__fcBackup = { ok: 0, busy: 0, go: 0, exp: 0, fail: 0, then: null } );
	}
	function end( f, v, t0 ) {
		var s = st( f ), i = f.querySelector( 'input[name="formconfirmed_pow"]' ), t = s.then;
		s.busy = 0;
		s.then = null;
		if ( v && i ) {
			i.value = v;
			s.ok = 1;
			s.exp = t0 + ( c.ttl - 60 ) * 1000;
		} else {
			s.ok = 0;
			s.fail = Date.now();
		}
		if ( t ) {
			t();
		}
	}
	/* Send the form again the way the visitor did - never form.submit(): that skips the AJAX handlers. */
	function send( f, b ) {
		if ( f.requestSubmit ) {
			f.requestSubmit( b && b.form === f ? b : undefined );
			return;
		}
		if ( b && b.form === f && b.click ) {
			b.click();
			return;
		}
		var x = document.createElement( 'input' );
		x.type = 'submit';
		x.style.display = 'none';
		f.appendChild( x );
		x.click();
		f.removeChild( x );
	}
	function start( f ) {
		var s = st( f ), x, t0 = Date.now();
		if ( s.busy ) {
			return;
		}
		s.busy = 1;
		x = new XMLHttpRequest();
		x.open( 'POST', c.url, true );
		x.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		x.onload = function () {
			var ch = null;
			try {
				ch = JSON.parse( x.responseText );
			} catch ( err ) {}
			if ( ! ch || ! ch.challenge || ! ch.salt ) {
				return end( f, '' );
			}
			solve( ch, function ( v ) {
				end( f, v, t0 );
			} );
		};
		x.onerror = function () {
			end( f, '' );
		};
		x.timeout = 15000;   /* never keep a form waiting forever */
		x.ontimeout = function () {
			end( f, '' );
		};
		x.send( 'action=' + encodeURIComponent( c.action ) );
	}
	function protectedForm( f ) {
		var w = f.querySelector( '.fcp-widget' ), p;
		if ( ! w || ( c.nopow && f.matches && f.matches( c.nopow ) ) ) {
			return false;
		}
		p = w.getAttribute( 'data-purpose' ) || 'contact';
		return 'contact' === p || 'comment' === p;
	}
	function arm( f ) {
		if ( f.getAttribute( 'data-fc-backup' ) || ! protectedForm( f ) ) {
			return;
		}
		f.setAttribute( 'data-fc-backup', '1' );
		equip( f );
		var done = false;
		function go() {
			if ( done ) {
				return;
			}
			done = true;
			var j = f.querySelector( 'input[name="formconfirmed_j"]' );
			if ( j ) {
				j.value = j.getAttribute( 'data-fc' ) || '';
			}
		}
		[ 'focusin', 'keydown', 'pointerdown', 'touchstart' ].forEach( function ( e ) {
			f.addEventListener( e, go, { once: true, passive: true } );
		} );
		/* Prepare the answer when a field gets focus or input; after a failed request not again for 20 s. */
		[ 'focusin', 'input' ].forEach( function ( e ) {
			f.addEventListener( e, function () {
				var s = st( f );
				if ( ! s.ok && ! s.busy && Date.now() - s.fail > 20000 ) {
					start( f );
				}
			}, { passive: true } );
		} );
	}
	function scan() {
		document.querySelectorAll( 'form' ).forEach( arm );
	}
	scan();
	document.addEventListener( 'submit', function ( e ) {
		var f = e.target, s, b;
		if ( ! f || ! f.getAttribute || ! f.getAttribute( 'data-fc-backup' ) ) {
			return;
		}
		s = st( f );
		if ( s.go ) {
			s.go = 0;
			return;
		}
		if ( s.ok && Date.now() < s.exp ) {
			s.ok = 0;
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		b = e.submitter;
		f.setAttribute( 'aria-busy', 'true' );
		s.then = function () {
			s.go = 1;
			s.ok = 0;
			f.removeAttribute( 'aria-busy' );
			send( f, b );
		};
		if ( ! s.busy ) {
			start( f );
		}
	}, true );
	if ( window.MutationObserver ) {
		var t = null;
		new MutationObserver( function () {
			if ( t ) {
				return;
			}
			t = setTimeout( function () {
				t = null;
				scan();
			}, 150 );
		} ).observe( document.documentElement, { childList: true, subtree: true } );
	}
}( window.formconfirmedBackup ) );
