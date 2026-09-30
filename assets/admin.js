/**
 * AlphaBridge MCP settings screen: the choice of assistant, copy buttons, the
 * capability switches and search, and the token create/rotate flow. Creating or
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

	/* ---- Links to a folded setting on this page open it. ---- */
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[href^="#ab-"]' ) : null;
		if ( ! a ) {
			return;
		}
		var t = document.getElementById( a.getAttribute( 'href' ).slice( 1 ) );
		if ( t && 'DETAILS' === t.tagName ) {
			t.open = true;
		}
	} );

	/* ---- Capabilities: all tools, groups, single tools, search ---- */
	var caps = document.getElementById( 'ab-caps' );
	var dirty = false;

	function fmt( s, a, b ) {
		return String( s ).replace( '%1$d', a ).replace( '%2$d', b );
	}

	// Recount every group from its tool switches: the group switch (on, off, or
	// part on), the group's pill, the counter and the switch for all tools.
	function refresh() {
		if ( ! caps ) {
			return;
		}
		var on = 0;
		var all = 0;
		caps.querySelectorAll( '.ab-group' ).forEach( function ( g ) {
			var tools = g.querySelectorAll( 'input.ab-tool' );
			var n = tools.length;
			var k = 0;
			tools.forEach( function ( t ) {
				if ( t.checked ) {
					k++;
				}
			} );
			on += k;
			all += n;
			var gt = g.querySelector( '.ab-group-toggle' );
			if ( gt ) {
				gt.checked = k > 0;
				gt.indeterminate = k > 0 && k < n;
			}
			var st = g.querySelector( '.ab-status' );
			if ( st ) {
				st.className = 'ab-pill ab-status ' + ( 0 === k ? 'ab-pill--off' : ( k === n ? 'ab-pill--on' : 'ab-pill--partial' ) );
				st.textContent = 0 === k ? ( cfg.off || 'Off' ) : ( k === n ? ( cfg.on || 'On' ) : fmt( cfg.partial || '%1$d/%2$d', k, n ) );
			}
		} );
		var c = caps.querySelector( '.ab-on-count' );
		if ( c ) {
			c.textContent = on;
		}
		var a = caps.querySelector( '.ab-all-toggle' );
		if ( a ) {
			a.checked = all > 0 && on === all;
			a.indeterminate = on > 0 && on < all;
		}
	}

	function markDirty() {
		dirty = true;
		var bar = caps.querySelector( '.ab-savebar' );
		if ( ! bar || bar.classList.contains( 'is-dirty' ) ) {
			return;
		}
		bar.classList.add( 'is-dirty' );
		var t = bar.querySelector( '.ab-savebar__text' );
		if ( t && t.getAttribute( 'data-dirty' ) ) {
			t.textContent = t.getAttribute( 'data-dirty' );
		}
	}

	function filter( q ) {
		q = String( q || '' ).trim().toLowerCase();
		var any = false;
		caps.querySelectorAll( '.ab-group' ).forEach( function ( g ) {
			var hits = 0;
			g.querySelectorAll( '.ab-tool-row' ).forEach( function ( r ) {
				var ok = ! q || ( r.getAttribute( 'data-search' ) || '' ).indexOf( q ) !== -1;
				r.hidden = ! ok;
				if ( ok ) {
					hits++;
				}
			} );
			g.hidden = !! q && 0 === hits;
			if ( q && hits ) {
				g.open = true;
			}
			if ( hits ) {
				any = true;
			}
		} );
		var none = caps.querySelector( '.ab-caps__none' );
		if ( none ) {
			none.hidden = any;
		}
	}

	if ( caps ) {
		refresh();

		caps.addEventListener( 'change', function ( e ) {
			var el = e.target;
			if ( el.classList.contains( 'ab-search' ) ) {
				return;
			}
			if ( el.classList.contains( 'ab-all-toggle' ) ) {
				caps.querySelectorAll( 'input.ab-tool:not([disabled])' ).forEach( function ( t ) {
					t.checked = el.checked;
				} );
			} else if ( el.classList.contains( 'ab-group-toggle' ) ) {
				el.closest( '.ab-group' ).querySelectorAll( 'input.ab-tool:not([disabled])' ).forEach( function ( t ) {
					t.checked = el.checked;
				} );
			}
			refresh();
			markDirty();
		} );

		// The switch in a group's summary switches the group without folding it.
		// A cancelled click puts the checkbox back after the event, so the new
		// state is set right after it.
		caps.addEventListener( 'click', function ( e ) {
			var sw = e.target.closest ? e.target.closest( '.ab-group > summary .ab-switch' ) : null;
			if ( ! sw ) {
				return;
			}
			e.preventDefault();
			var input = sw.querySelector( 'input' );
			var want = e.target === input ? input.checked : ! input.checked;
			window.setTimeout( function () {
				input.checked = want;
				input.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
			}, 0 );
		} );

		caps.addEventListener( 'submit', function () {
			dirty = false;
		} );

		var search = caps.querySelector( '.ab-search' );
		if ( search ) {
			search.addEventListener( 'input', function () {
				filter( search.value );
			} );
			// Enter in the search field must not save the capabilities.
			search.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
				}
			} );
		}
	}

	// Switches changed but not saved: the browser asks before leaving.
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( ! dirty ) {
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

		// Ready-made config for Cursor / Claude Code (header authentication, which
		// always works regardless of the connector-URL setting). Single quotes so
		// the literal ${AUTH} is not treated as a template placeholder.
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
