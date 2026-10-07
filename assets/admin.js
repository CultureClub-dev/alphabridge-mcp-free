/**
 * AlphaBridge MCP settings screen: the main switch for write access, the
 * choice of assistant, copy buttons, the fine-tuning (switches and search),
 * and the token create/rotate flow. Creating or
 * rotating a connection reveals a secret; that is done over authenticated
 * admin-ajax so the plaintext is returned once, straight to this browser, and
 * is never persisted server-side.
 *
 * Enqueued via admin_enqueue_scripts on the plugin's own page only.
 *
 * @package AlphaBridge_MCP
 */
( function () {
	'use strict';

	var cfg = window.abMcpAdmin || {};
	var copiedLabel = cfg.copied || 'Copied';

	/* ---- Copy buttons (static value via data-copy, or live input via data-copy-target) ---- */
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '.ab-copy' ) : null;
		if ( ! b ) {
			return;
		}
		var text = b.getAttribute( 'data-copy' );
		if ( null === text ) {
			var sel = b.getAttribute( 'data-copy-target' );
			var el  = sel ? document.querySelector( sel ) : null;
			text = el ? el.value : '';
		}
		if ( text && navigator.clipboard ) {
			navigator.clipboard.writeText( text );
		}
		var o = b.textContent;
		b.textContent = copiedLabel;
		setTimeout( function () {
			b.textContent = o;
		}, 1200 );
	} );

	/* ---- The choice of assistant: one panel shows; the token form only for
	 * the assistants that need a token. The choice is remembered in this
	 * browser only. ---- */
	var CLIENT_KEY = 'abMcpClient';

	function pickClient( client, remember ) {
		var buttons = document.querySelectorAll( '.ab-client' );
		if ( ! buttons.length ) {
			return;
		}
		var known = false;
		buttons.forEach( function ( b ) {
			if ( b.getAttribute( 'data-client' ) === client ) {
				known = true;
			}
		} );
		if ( ! known ) {
			client = 'claude';
		}
		buttons.forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-client' ) === client ? 'true' : 'false' );
		} );
		document.querySelectorAll( '.ab-panel' ).forEach( function ( p ) {
			p.hidden = p.getAttribute( 'data-panel' ) !== client;
		} );
		var token = document.querySelector( '.ab-token' );
		if ( token ) {
			token.hidden = ( ' ' + ( token.getAttribute( 'data-for' ) || '' ) + ' ' ).indexOf( ' ' + client + ' ' ) === -1;
		}
		if ( remember ) {
			try {
				window.localStorage.setItem( CLIENT_KEY, client );
			} catch ( err ) {
				// Storage blocked: the choice simply is not remembered.
			}
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '.ab-client' ) : null;
		if ( b ) {
			pickClient( b.getAttribute( 'data-client' ), true );
		}
	} );

	( function () {
		var stored = null;
		try {
			stored = window.localStorage.getItem( CLIENT_KEY );
		} catch ( err ) {
			stored = null;
		}
		if ( stored ) {
			pickClient( stored, false );
		}
	}() );

	/* ---- The main switch for write access. Off, the switch opens a small
	 * window at it with the notice, the two boxes, the button and «Cancel»;
	 * the button works once the first box is ticked, and the second too
	 * where it is required (the agreement to the Terms of Use; with a paid
	 * licence it is offered, not required). The window keeps the focus inside
	 * while open, Escape and Cancel close it and give the focus back to the
	 * switch. Without this script the same fields stand in the card as a
	 * plain form. On, the switch submits the form that switches off: nothing
	 * to add here. ---- */
	( function () {
		var card = document.getElementById( 'ab-mode' );
		var sw = card ? card.querySelector( '.ab-power' ) : null;
		var dlg = document.getElementById( 'ab-mode-confirm' );
		var form = document.getElementById( 'ab-mode-form' );
		if ( ! card || ! sw || ! dlg || ! form ) {
			return;
		}
		var box = dlg.querySelector( 'input[name="confirm_full"]' );
		var terms = dlg.querySelector( 'input[name="agree_terms"]' );
		var go = dlg.querySelector( '.ab-mode__go' );
		var cancel = dlg.querySelector( '.ab-mode__cancel' );
		if ( ! box || ! go ) {
			return;
		}
		dlg.setAttribute( 'role', 'dialog' );
		dlg.setAttribute( 'aria-modal', 'false' );
		card.classList.add( 'ab-mode--js' );
		dlg.hidden = true;
		if ( cancel ) {
			cancel.hidden = false;
		}

		function sync() {
			go.disabled = ! box.checked || ( !! terms && terms.required && ! terms.checked );
		}

		// Right under the switch, wherever the card's text made it end up.
		function place() {
			var c = card.getBoundingClientRect();
			var b = sw.getBoundingClientRect();
			dlg.style.top = Math.round( b.bottom - c.top + 12 ) + 'px';
		}

		function open() {
			box.checked = false;
			if ( terms ) {
				terms.checked = false;
			}
			sync();
			dlg.hidden = false;
			card.classList.add( 'is-confirming' );
			sw.setAttribute( 'aria-expanded', 'true' );
			place();
			box.focus();
		}

		function close( back ) {
			if ( dlg.hidden ) {
				return;
			}
			dlg.hidden = true;
			card.classList.remove( 'is-confirming' );
			sw.setAttribute( 'aria-expanded', 'false' );
			if ( back ) {
				sw.focus();
			}
		}

		sw.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( dlg.hidden ) {
				open();
			} else {
				close( true );
			}
		} );
		box.addEventListener( 'change', sync );
		if ( terms ) {
			terms.addEventListener( 'change', sync );
		}
		if ( cancel ) {
			cancel.addEventListener( 'click', function () {
				close( true );
			} );
		}
		card.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! dlg.hidden ) {
				e.preventDefault();
				close( true );
			}
		} );
		// Tab from the last control goes back to the first, and back.
		dlg.addEventListener( 'keydown', function ( e ) {
			if ( 'Tab' !== e.key ) {
				return;
			}
			var items = Array.prototype.filter.call( dlg.querySelectorAll( 'input, button' ), function ( el ) {
				return ! el.disabled && ! el.hidden;
			} );
			if ( ! items.length ) {
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! dlg.hidden && ! dlg.contains( e.target ) && ! sw.contains( e.target ) ) {
				close( false );
			}
		} );
		window.addEventListener( 'resize', function () {
			if ( ! dlg.hidden ) {
				place();
			}
		} );
		form.addEventListener( 'submit', function ( e ) {
			if ( ! box.checked ) {
				e.preventDefault();
				if ( dlg.hidden ) {
					open();
				} else {
					box.focus();
				}
			}
		} );
	}() );

	/* ---- The review request in the header: its link opens the review form in
	 * a new tab, and admin-post records the answer there. This tab drops the
	 * strip at once instead of showing it until the next load. ---- */
	document.addEventListener( 'click', function ( e ) {
		var go = e.target.closest ? e.target.closest( '.ab-review__go' ) : null;
		var strip = go ? go.closest( '.ab-review' ) : null;
		if ( strip ) {
			strip.hidden = true;
		}
	} );

	/* ---- Folded places on this page: a main group of the fine-tuning or a
	 * tool group in it (a button with aria-expanded each), or a details
	 * element. ---- */
	function setOpen( el, open ) {
		var sub = el.classList.contains( 'ab-sub' );
		var btn = el.querySelector( sub ? '.ab-sub__toggle' : '.ab-group__toggle' );
		var body = el.querySelector( sub ? '.ab-sub__body' : '.ab-group__body' );
		if ( ! btn || ! body ) {
			return;
		}
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		body.hidden = ! open;
		el.classList.toggle( 'is-open', open );
	}

	function isOpen( el ) {
		var btn = el.querySelector( el.classList.contains( 'ab-sub' ) ? '.ab-sub__toggle' : '.ab-group__toggle' );
		return !! btn && 'true' === btn.getAttribute( 'aria-expanded' );
	}

	// Open whatever folds the element with this id away, so a link to it,
	// or the address after a save («…#ab-fine»), or the link in an answer to
	// an assistant («…#ab-tool-wp_update_post»), lands on it.
	function unfold( id ) {
		var t = id ? document.getElementById( id ) : null;
		if ( ! t ) {
			return null;
		}
		for ( var el = t; el && el !== document.body; el = el.parentElement ) {
			if ( 'DETAILS' === el.tagName ) {
				el.open = true;
			} else if ( el.classList && ( el.classList.contains( 'ab-group' ) || el.classList.contains( 'ab-sub' ) ) ) {
				setOpen( el, true );
			}
		}
		return t;
	}

	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[href^="#ab-"]' ) : null;
		if ( a ) {
			unfold( a.getAttribute( 'href' ).slice( 1 ) );
		}
	} );

	if ( window.location.hash && /^#ab-[\w-]+$/.test( window.location.hash ) ) {
		var landed = unfold( window.location.hash.slice( 1 ) );
		if ( landed && landed.scrollIntoView ) {
			landed.scrollIntoView();
			if ( landed.classList && landed.classList.contains( 'ab-tool-row' ) ) {
				landed.classList.add( 'is-target' );
			}
		}
	}

	/* ---- Fine-tuning: all tools, main groups, tool groups, single tools,
	 * search ---- */
	var fine = document.getElementById( 'ab-fine' );
	// The form holds only its hidden fields; switches and the save button
	// join it through their form attribute, an add-on's fields too.
	var caps = document.getElementById( 'ab-caps' );
	var dirty = false;
	// Forms of add-ons of before 4.5.0 inside a main group, changed but not saved.
	var addonDirty = [];
	// Every switch the switches for a group or for all move: the tools, and
	// the items an add-on put into a group.
	var MOVED = 'input.ab-tool:not([disabled]), input.ab-item:not([disabled])';

	function fmt( s, a, b ) {
		return String( s ).replace( /%1\$[sd]/, a ).replace( /%2\$[sd]/, b );
	}

	// A switch for several: checked when all below it are on, half when some.
	function sum( scope, toggle ) {
		var items = scope.querySelectorAll( 'input.ab-tool, input.ab-item' );
		var k = 0;
		items.forEach( function ( t ) {
			if ( t.checked ) {
				k++;
			}
		} );
		if ( toggle ) {
			toggle.checked = items.length > 0 && k === items.length;
			toggle.indeterminate = k > 0 && k < items.length;
		}
	}

	// «1 ability off», «2 abilities off»: the items of an add-on (the
	// abilities of other plugins) that are off, '' for none.
	function itemsOff( n ) {
		if ( ! n ) {
			return '';
		}
		return String( 1 === n ? ( cfg.itemOff || '%s ability off' ) : ( cfg.itemsOff || '%s abilities off' ) ).replace( /%(1\$)?[sd]/, n );
	}

	// Show what an add-on's switched-off items add to a count, or nothing.
	function showMore( el, n ) {
		if ( el ) {
			el.textContent = itemsOff( n );
			el.hidden = ! n;
		}
	}

	// Recount: every tool group's switch, every main group's switch and
	// state, the counter and the switch for all. The switches for a group and
	// for all move the tools and an add-on's items alike, so they show half
	// on when an item is off; the counts say so: «x of y on» counts the tools,
	// and a line under it the items that are off («1 ability off»).
	function refresh() {
		var on = 0;
		var all = 0;
		var offAll = 0;
		fine.querySelectorAll( '.ab-sub' ).forEach( function ( sub ) {
			sum( sub, sub.querySelector( '.ab-sub-toggle' ) );
		} );
		fine.querySelectorAll( '.ab-group' ).forEach( function ( g ) {
			var tools = g.querySelectorAll( 'input.ab-tool' );
			var n = tools.length;
			var k = 0;
			var off = 0;
			tools.forEach( function ( t ) {
				if ( t.checked ) {
					k++;
				}
			} );
			g.querySelectorAll( 'input.ab-item' ).forEach( function ( t ) {
				if ( ! t.checked ) {
					off++;
				}
			} );
			on += k;
			all += n;
			offAll += off;
			sum( g, g.querySelector( '.ab-group-toggle' ) );
			var st = g.querySelector( '.ab-status' );
			if ( st ) {
				st.setAttribute( 'data-state', 0 === k && n > 0 ? 'off' : ( k === n && 0 === off ? 'on' : 'partial' ) );
				var count = st.querySelector( '.ab-status__count' );
				if ( count ) {
					count.textContent = fmt( cfg.partial || '%1$s of %2$s on', k, n );
				} else {
					st.textContent = fmt( cfg.partial || '%1$s of %2$s on', k, n );
				}
				showMore( st.querySelector( '.ab-status__more' ), off );
			}
		} );
		var c = fine.querySelector( '.ab-on-count' );
		if ( c ) {
			c.textContent = on;
		}
		showMore( fine.querySelector( '.ab-capbar__more' ), offAll );
		sum( fine, fine.querySelector( '.ab-all-toggle' ) );
	}

	function markBar( bar ) {
		if ( ! bar || bar.classList.contains( 'is-dirty' ) ) {
			return;
		}
		bar.classList.add( 'is-dirty' );
		var t = bar.querySelector( '.ab-savebar__text' );
		if ( t && t.getAttribute( 'data-dirty' ) ) {
			t.textContent = t.getAttribute( 'data-dirty' );
		}
	}

	function markDirty() {
		dirty = true;
		markBar( fine.querySelector( '.ab-fine > .ab-savebar' ) );
	}

	// A search opens the groups it finds something in, and folds them again
	// when it is cleared, unless they were open before.
	function bySearch( el, q, hits ) {
		if ( q && hits && ! isOpen( el ) ) {
			el.setAttribute( 'data-opened-by-search', '1' );
			setOpen( el, true );
		} else if ( ! q && el.hasAttribute( 'data-opened-by-search' ) ) {
			el.removeAttribute( 'data-opened-by-search' );
			setOpen( el, false );
		}
	}

	function filter( q ) {
		q = String( q || '' ).trim().toLowerCase();
		var any = false;
		fine.querySelectorAll( '.ab-group' ).forEach( function ( g ) {
			var hits = 0;
			g.querySelectorAll( '.ab-tool-row' ).forEach( function ( r ) {
				var ok = ! q || ( r.getAttribute( 'data-search' ) || '' ).indexOf( q ) !== -1;
				r.hidden = ! ok;
				if ( ok ) {
					hits++;
				}
			} );
			g.querySelectorAll( '.ab-sub' ).forEach( function ( sub ) {
				var subHits = 0;
				sub.querySelectorAll( '.ab-tool-row' ).forEach( function ( r ) {
					if ( ! r.hidden ) {
						subHits++;
					}
				} );
				sub.hidden = !! q && 0 === subHits;
				bySearch( sub, q, subHits );
			} );
			g.hidden = !! q && 0 === hits;
			bySearch( g, q, hits );
			if ( hits ) {
				any = true;
			}
		} );
		var none = fine.querySelector( '.ab-caps__none' );
		if ( none ) {
			none.hidden = any;
		}
	}

	if ( fine && caps ) {
		refresh();

		fine.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.ab-group__toggle, .ab-sub__toggle' ) : null;
			if ( ! btn ) {
				return;
			}
			var el = btn.closest( btn.classList.contains( 'ab-sub__toggle' ) ? '.ab-sub' : '.ab-group' );
			el.removeAttribute( 'data-opened-by-search' );
			setOpen( el, ! isOpen( el ) );
		} );

		fine.addEventListener( 'change', function ( e ) {
			var el = e.target;
			if ( el.classList.contains( 'ab-search' ) ) {
				return;
			}
			// A field of an add-on's own form inside a group: that form is
			// unsaved, the fine-tuning is not.
			if ( el.form && el.form !== caps ) {
				if ( addonDirty.indexOf( el.form ) === -1 ) {
					addonDirty.push( el.form );
				}
				markBar( el.form.querySelector( '.ab-savebar' ) );
				return;
			}
			var scope = null;
			if ( el.classList.contains( 'ab-all-toggle' ) ) {
				scope = fine;
			} else if ( el.classList.contains( 'ab-group-toggle' ) ) {
				scope = el.closest( '.ab-group' );
			} else if ( el.classList.contains( 'ab-sub-toggle' ) ) {
				scope = el.closest( '.ab-sub' );
			}
			if ( scope ) {
				scope.querySelectorAll( MOVED ).forEach( function ( t ) {
					t.checked = el.checked;
				} );
			}
			refresh();
			markDirty();
		} );

		document.addEventListener( 'submit', function ( e ) {
			if ( e.target === caps ) {
				dirty = false;
				return;
			}
			var i = addonDirty.indexOf( e.target );
			if ( i !== -1 ) {
				addonDirty.splice( i, 1 );
			}
		} );

		var search = fine.querySelector( '.ab-search' );
		if ( search ) {
			search.addEventListener( 'input', function () {
				filter( search.value );
			} );
			// Enter in the search field must not save anything.
			search.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
				}
			} );
		}
	}

	// Switches or an add-on's settings changed but not saved: the browser
	// asks before leaving.
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( ! dirty && 0 === addonDirty.length ) {
			return;
		}
		e.preventDefault();
		e.returnValue = '';
	} );

	/* ---- Click-to-select read-only fields (delegated, covers revealed inputs) ---- */
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest ? e.target.closest( '.ab-select' ) : null;
		if ( el && el.select ) {
			el.select();
		}
	} );

	/* ---- Confirm-before-submit forms (delete) — delegated so dynamic rows work ---- */
	document.addEventListener( 'submit', function ( e ) {
		var f = e.target.closest ? e.target.closest( '.ab-confirm-form' ) : null;
		if ( f && ! window.confirm( f.getAttribute( 'data-confirm' ) || '' ) ) {
			e.preventDefault();
		}
	} );

	/* ---- Token create / rotate over ajax ---- */
	function ajax( action, data ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce || '' );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function setVal( sel, val ) {
		var el = document.querySelector( sel );
		if ( el ) {
			el.value = val || '';
		}
	}

	// Kept from the last reveal so the one-click enable button can build the
	// connector URL without a second token round trip (the plaintext token exists
	// only in this page's memory — it is never stored readable server-side).
	var lastEndpoint = '';
	var lastToken = '';

	function showConnectorUrl() {
		var row = document.querySelector( '.ab-reveal-url-row' );
		var enable = document.querySelector( '.ab-reveal-url-enable' );
		setVal( '.ab-reveal-url', lastEndpoint + '/' + lastToken );
		if ( row ) {
			row.hidden = false;
		}
		if ( enable ) {
			enable.hidden = true;
		}
	}

	function hideConnectorUrl() {
		var row = document.querySelector( '.ab-reveal-url-row' );
		var enable = document.querySelector( '.ab-reveal-url-enable' );
		setVal( '.ab-reveal-url', '' );
		if ( row ) {
			row.hidden = true;
		}
		if ( enable ) {
			enable.hidden = false;
		}
	}

	function reveal( d ) {
		var endpoint = d.endpoint || '';
		var token = d.token || '';
		lastEndpoint = endpoint;
		lastToken = token;

		// Bearer token always; the connector URL only when it will actually
		// authenticate (path-auth on) — otherwise the enable button takes its place.
		setVal( '.ab-reveal-token', token );
		if ( d.pathAuth ) {
			showConnectorUrl();
		} else {
			hideConnectorUrl();
		}

		// Claude Code: one command, the server over HTTP with the token in the
		// Authorization header (header authentication works regardless of the
		// connector-URL setting).
		setVal( '.ab-reveal-cli', 'claude mcp add --transport http alphabridge ' + endpoint + ' --header "Authorization: Bearer ' + token + '"' );

		// Ready-made config for Cursor, through mcp-remote. Single quotes so the
		// literal ${AUTH} is not treated as a template placeholder.
		var json =
			'{\n' +
			'  "mcpServers": {\n' +
			'    "alphabridge": {\n' +
			'      "command": "npx",\n' +
			'      "args": ["mcp-remote", "' + endpoint + '", "--header", "Authorization:${AUTH}"],\n' +
			'      "env": { "AUTH": "Bearer ' + token + '" }\n' +
			'    }\n' +
			'  }\n' +
			'}';
		setVal( '.ab-reveal-json', json );

		// The reveal sits outside the assistants' panels: a rotation further
		// down shows here too, whichever assistant is picked.
		var box = document.querySelector( '.ab-reveal' );
		if ( box ) {
			box.hidden = false;
			box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
		var hint = document.querySelector( '.ab-connector-hint' );
		if ( hint && cfg.createHint ) {
			hint.textContent = cfg.createHint;
		}
	}

	function upsertRow( d, oldHash ) {
		if ( ! d.row ) {
			return;
		}
		var tmp = document.createElement( 'tbody' );
		tmp.innerHTML = d.row.trim();
		var row = tmp.firstChild;
		if ( ! row ) {
			return;
		}
		var body = document.querySelector( '.ab-conn-rows' );
		if ( ! body ) {
			return;
		}
		var existing = oldHash ? body.querySelector( 'tr[data-hash="' + oldHash + '"]' ) : null;
		if ( existing ) {
			existing.replaceWith( row );
		} else {
			var empty = body.querySelector( '.ab-empty-row' );
			if ( empty ) {
				empty.hidden = true;
			}
			var firstRow = body.querySelector( 'tr:not(.ab-empty-row)' );
			if ( firstRow ) {
				body.insertBefore( row, firstRow );
			} else {
				body.appendChild( row );
			}
		}
	}

	function busy( btn, on ) {
		if ( ! btn ) {
			return;
		}
		btn.disabled = on;
		if ( on ) {
			btn.dataset.abLabel = btn.innerHTML;
			if ( cfg.working ) {
				btn.textContent = cfg.working;
			}
		} else if ( btn.dataset.abLabel ) {
			btn.innerHTML = btn.dataset.abLabel;
		}
	}

	function fail( msg ) {
		window.alert( msg || cfg.failed || 'Error' );
	}

	document.addEventListener( 'click', function ( e ) {
		var create = e.target.closest ? e.target.closest( '.ab-create' ) : null;
		var rotate = e.target.closest ? e.target.closest( '.ab-rotate' ) : null;

		if ( create ) {
			e.preventDefault();
			var data = {};
			if ( ! create.getAttribute( 'data-default' ) ) {
				var form = create.closest( '.ab-create-form' ) || document;
				var q = function ( n ) {
					var el = form.querySelector( '[name="' + n + '"]' );
					return el ? el.value : '';
				};
				data = {
					label: q( 'label' ),
					user_id: q( 'user_id' ),
					scope: q( 'scope' ),
					expires_days: q( 'expires_days' )
				};
			}
			busy( create, true );
			ajax( 'ab_mcp_create_token', data ).then( function ( res ) {
				busy( create, false );
				if ( res && res.success ) {
					reveal( res.data );
					upsertRow( res.data, '' );
				} else {
					fail( res && res.data && res.data.message );
				}
			} ).catch( function () {
				busy( create, false );
				fail();
			} );
			return;
		}

		if ( rotate ) {
			e.preventDefault();
			if ( ! window.confirm( rotate.getAttribute( 'data-confirm' ) || '' ) ) {
				return;
			}
			var oldHash = rotate.getAttribute( 'data-hash' );
			busy( rotate, true );
			ajax( 'ab_mcp_rotate_token', { hash: oldHash } ).then( function ( res ) {
				busy( rotate, false );
				if ( res && res.success ) {
					reveal( res.data );
					upsertRow( res.data, oldHash );
				} else {
					fail( res && res.data && res.data.message );
				}
			} ).catch( function () {
				busy( rotate, false );
				fail();
			} );
			return;
		}

		var enableUrl = e.target.closest ? e.target.closest( '.ab-enable-url-auth' ) : null;
		if ( enableUrl ) {
			e.preventDefault();
			busy( enableUrl, true );
			ajax( 'ab_mcp_enable_url_auth', {} ).then( function ( res ) {
				busy( enableUrl, false );
				if ( res && res.success ) {
					showConnectorUrl();
					// Keep the Advanced form's checkbox in sync with the new state.
					var adv = document.querySelector( 'input[name="connector_url_auth_enabled"]' );
					if ( adv ) {
						adv.checked = true;
					}
				} else {
					fail( res && res.data && res.data.message );
				}
			} ).catch( function () {
				busy( enableUrl, false );
				fail();
			} );
		}
	} );
}() );
