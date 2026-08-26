/**
 * AlphaBridge MCP settings screen: copy buttons, tool group toggles, and the
 * token create/rotate flow. Creating or rotating a connection reveals a secret;
 * that is done over authenticated admin-ajax so the plaintext is returned once,
 * straight to this browser, and is never persisted server-side.
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

	/* ---- Capability toggles ---- */
	function boxes( scope ) {
		return scope.querySelectorAll( 'input.ab-tool:not([disabled])' );
	}

	document.querySelectorAll( '.ab-all' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var on = b.getAttribute( 'data-on' ) === '1';
			boxes( document ).forEach( function ( c ) {
				c.checked = on;
			} );
			var f = document.getElementById( 'ab-caps' );
			if ( f ) {
				f.submit();
			}
		} );
	} );

	document.querySelectorAll( '.ab-grp' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var on = b.getAttribute( 'data-on' ) === '1';
			var d = b.closest( 'details' );
			boxes( d ).forEach( function ( c ) {
				c.checked = on;
			} );
		} );
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

		var box = document.querySelector( '.ab-reveal' );
		if ( box ) {
			box.hidden = false;
			box.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
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
			btn.dataset.abLabel = btn.textContent;
			if ( cfg.working ) {
				btn.textContent = cfg.working;
			}
		} else if ( btn.dataset.abLabel ) {
			btn.textContent = btn.dataset.abLabel;
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
