// The choice of assistant on the settings page (assets/admin.js): one panel
// shows, the blocks that name their assistants in data-for follow, the
// choice is remembered in this browser, and an address that names a block
// inside a panel (#ab-oauth-claude, #ab-oauth-chatgpt, #ab-url-auth — where
// a save, «Check again» or the link under the status line lands) shows that
// panel whatever was remembered. Runs the real script in a small in-memory
// stand-in for the DOM: node tests/admin-js-test.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const code    = readFileSync( join( dirname( fileURLToPath( import.meta.url ) ), '..', 'assets', 'admin.js' ), 'utf8' );
const CLIENTS = [ 'claude', 'chatgpt', 'cursor', 'other' ];
const TOKEN   = [ 'cursor', 'other' ];

/** One run of admin.js on a page with the four panels, the token form and
 * the connector-address block (both for cursor and other), the three
 * blocks the addresses name, a remembered choice and an address. */
function run( { stored = null, hash = '', storageBlocked = false } ) {
	const listeners = {};
	const el = ( attrs, parent = null ) => ( {
		attrs,
		parent,
		hidden: 'hidden' in attrs,
		getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
		setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
		closest( sel ) {
			if ( '.ab-panel' === sel ) { return 'data-panel' in this.attrs ? this : this.parent; }
			if ( '.ab-client' === sel ) { return 'data-client' in this.attrs ? this : null; }
			return null;
		},
	} );
	const buttons = CLIENTS.map( ( c ) => el( { 'data-client': c, 'aria-pressed': 'claude' === c ? 'true' : 'false' } ) );
	const panels  = CLIENTS.map( ( c ) => el( 'claude' === c ? { 'data-panel': c } : { 'data-panel': c, hidden: '' } ) );
	const token   = el( { 'data-for': 'cursor other', hidden: '' } );
	const urlAuth = el( { 'data-for': 'cursor other', hidden: '', id: 'ab-url-auth' } );
	const byId    = {
		'ab-oauth-claude':  el( { id: 'ab-oauth-claude' }, panels[ 0 ] ),
		'ab-oauth-chatgpt': el( { id: 'ab-oauth-chatgpt' }, panels[ 1 ] ),
		'ab-url-auth':      urlAuth,
	};
	const document = {
		addEventListener( kind, fn ) { ( listeners[ kind ] = listeners[ kind ] || [] ).push( fn ); },
		querySelector() { return null; },
		querySelectorAll( q ) {
			if ( '.ab-client' === q ) { return buttons; }
			if ( '.ab-panel' === q ) { return panels; }
			if ( '#ab-connect [data-for]' === q ) { return [ token, urlAuth ]; }
			return [];
		},
		getElementById( id ) { return byId[ id ] || null; },
	};
	let remembered = stored;
	const window = {
		location: { hash },
		localStorage: {
			getItem() { if ( storageBlocked ) { throw new Error( 'blocked' ); } return remembered; },
			setItem( k, v ) { if ( storageBlocked ) { throw new Error( 'blocked' ); } remembered = v; },
		},
		addEventListener() {},
	};
	vm.runInNewContext( code, { document, window, console } );

	const shown = () => {
		const visible = panels.filter( ( p ) => ! p.hidden ).map( ( p ) => p.attrs[ 'data-panel' ] );
		const pressed = buttons.filter( ( b ) => 'true' === b.attrs[ 'aria-pressed' ] ).map( ( b ) => b.attrs[ 'data-client' ] );
		assert.equal( visible.length, 1, 'exactly one panel shows' );
		assert.deepEqual( pressed, visible, 'the pressed button is the shown panel' );
		for ( const block of [ token, urlAuth ] ) {
			assert.equal( block.hidden, ! TOKEN.includes( visible[ 0 ] ), 'the blocks for cursor and other follow' );
		}
		return visible[ 0 ];
	};
	const click = ( client ) => {
		for ( const fn of listeners.click || [] ) {
			fn( { target: buttons[ CLIENTS.indexOf( client ) ], preventDefault() {} } );
		}
	};
	return { shown, click, remembered: () => remembered };
}

let cases = 0;
const expectStart = ( stored, hash, storageBlocked ) => {
	let expected = ! storageBlocked && CLIENTS.includes( stored ) ? stored : 'claude';
	if ( '#ab-oauth-claude' === hash ) { expected = 'claude'; }
	if ( '#ab-oauth-chatgpt' === hash ) { expected = 'chatgpt'; }
	if ( '#ab-url-auth' === hash && ! TOKEN.includes( expected ) ) { expected = 'cursor'; }
	return expected;
};

for ( const storageBlocked of [ false, true ] ) {
	for ( const stored of [ null, 'claude', 'chatgpt', 'cursor', 'other', 'elsewhere' ] ) {
		for ( const hash of [ '', '#ab-oauth-claude', '#ab-oauth-chatgpt', '#ab-url-auth', '#unknown' ] ) {
			const page = run( { stored, hash, storageBlocked } );
			const want = expectStart( stored, hash, storageBlocked );
			assert.equal( page.shown(), want, `start: stored=${ stored } hash=${ hash } blocked=${ storageBlocked }` );
			cases++;
			// Every click shows that panel and, where storage works, remembers it.
			for ( const client of CLIENTS ) {
				page.click( client );
				assert.equal( page.shown(), client, `click ${ client }` );
				if ( ! storageBlocked ) {
					assert.equal( page.remembered(), client, 'remembered' );
				}
				cases++;
			}
		}
	}
}
/* ---- The preview protection: switching on over ajax shows the password once
 * and, where the loader for wp-activate.php could not be written, the note
 * and the file to create at once, as a reload would. ---- */
async function runPreview( answer ) {
	const listeners = {};
	const node = ( attrs = {}, children = [] ) => {
		const n = {
			attrs, children, hidden: 'hidden' in attrs, value: '', textContent: '', innerHTML: '', disabled: false, dataset: {},
			getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
			matches( sel ) {
				return sel.split( ',' ).some( ( one ) => {
					one = one.trim();
					if ( one.startsWith( '#' ) ) { return this.attrs.id === one.slice( 1 ); }
					if ( one.startsWith( '.' ) ) { return ( this.attrs.class || '' ).split( ' ' ).includes( one.slice( 1 ) ); }
					if ( one.startsWith( '[' ) ) { return one.slice( 1, -1 ) in this.attrs; }
					return false;
				} );
			},
			closest( sel ) { let c = this; while ( c ) { if ( c.matches( sel ) ) { return c; } c = c.parent || null; } return null; },
			all() { return this.children.flatMap( ( c ) => [ c, ...c.all() ] ); },
			querySelector( sel ) { return this.all().find( ( c ) => c.matches( sel ) ) || null; },
			querySelectorAll( sel ) { return this.all().filter( ( c ) => c.matches( sel ) ); },
		};
		for ( const c of children ) { c.parent = n; }
		return n;
	};
	const onBtn    = node( { class: 'ab-btn ab-preview-ajax', 'data-preview': 'on', 'data-when': 'off' } );
	const renewBtn = node( { class: 'ab-btn ab-preview-ajax', 'data-preview': 'password', 'data-when': 'on', hidden: '' } );
	const offForm  = node( { class: 'ab-preview__off', 'data-when': 'on', hidden: '' } );
	const password = node( { class: 'ab-select ab-preview-password' } );
	const reveal   = node( { class: 'ab-reveal ab-preview-reveal', hidden: '' }, [ password ] );
	const status   = node( { class: 'ab-direct__status ab-preview-status' } );
	const text     = node( { class: 'ab-preview__loader-text' } );
	const source   = node( { class: 'ab-preview__source' } );
	const loader   = node( { class: 'notice ab-preview__loader', hidden: '' }, [ text, source ] );
	const card     = node( { id: 'ab-preview', class: 'ab-card ab-preview' }, [ status, reveal, onBtn, renewBtn, offForm, loader ] );
	const document = {
		addEventListener( kind, fn ) { ( listeners[ kind ] = listeners[ kind ] || [] ).push( fn ); },
		querySelector( sel ) { return card.querySelector( sel ); },
		querySelectorAll( sel ) { return card.querySelectorAll( sel ); },
		getElementById( id ) { return 'ab-preview' === id ? card : null; },
	};
	let sent = null;
	const window = {
		abMcpAdmin: { ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php', nonce: 'n' },
		location: { hash: '' },
		localStorage: { getItem() { return null; }, setItem() {} },
		addEventListener() {},
		FormData: class { constructor() { this.fields = {}; } append( k, v ) { this.fields[ k ] = v; } },
		fetch( url, init ) { sent = { url, body: init.body.fields }; return Promise.resolve( { json: () => Promise.resolve( answer ) } ); },
		alert( msg ) { throw new Error( 'alert: ' + msg ); },
	};
	vm.runInNewContext( code, { document, window, console } );
	for ( const fn of listeners.click || [] ) {
		fn( { target: onBtn, preventDefault() {} } );
	}
	// The fetch and its json() resolve in two steps.
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	return { sent, card, onBtn, renewBtn, offForm, password, reveal, status, loader, text, source };
}

const written = await runPreview( { success: true, data: { password: 'abcdEFGH23456789', status: 'On since now', loader: { path: '/mu/alphabridge-preview-protection.php', written: true }, loader_note: '', loader_source: '' } } );
assert.equal( written.sent.body.action, 'ab_mcp_preview_protection' );
assert.equal( written.sent.body.preview, 'on' );
assert.equal( written.password.value, 'abcdEFGH23456789', 'the password, shown once' );
assert.equal( written.reveal.hidden, false );
assert.equal( written.status.textContent, 'On since now' );
assert.equal( written.onBtn.hidden, true, 'on is done' );
assert.equal( written.renewBtn.hidden, false );
assert.equal( written.offForm.hidden, false );
assert.equal( written.loader.hidden, true, 'the loader was written: no note' );
assert.equal( written.text.textContent, '' );
cases++;

const missing = await runPreview( { success: true, data: { password: 'abcdEFGH23456789', status: 'On since now', loader: { path: '/mu/alphabridge-preview-protection.php', written: false }, loader_note: 'could not be written to /mu/alphabridge-preview-protection.php', loader_source: "<?php\n// ab-mcp-preview-loader" } } );
assert.equal( missing.loader.hidden, false, 'the note shows at once, without a reload' );
assert.equal( missing.text.textContent, 'could not be written to /mu/alphabridge-preview-protection.php' );
assert.equal( missing.source.value, "<?php\n// ab-mcp-preview-loader", 'with the file to create' );
assert.equal( missing.password.value, 'abcdEFGH23456789', 'and the password all the same' );
cases++;

console.log( `admin-js-test: ${ cases } cases passed` );
