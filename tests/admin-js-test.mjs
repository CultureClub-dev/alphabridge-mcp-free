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
	const card     = node( { id: 'ab-preview', class: 'ab-staging ab-preview' }, [ status, reveal, onBtn, renewBtn, offForm, loader ] );
	// The rest of the card «Staging», outside the part of the protection.
	const checklist  = node( { id: 'ab-staging-checklist', class: 'ab-staging ab-staging-checklist' } );
	checklist.outerHTML = '<div id="ab-staging-checklist">before</div>';
	const stateLine  = node( { class: 'ab-direct__status ab-staging-card__state' } );
	const suggestion = node( { class: 'ab-note ab-staging__suggestion' } );
	const page       = node( { id: 'ab-staging-card' }, [ stateLine, checklist, suggestion, card ] );
	const document = {
		addEventListener( kind, fn ) { ( listeners[ kind ] = listeners[ kind ] || [] ).push( fn ); },
		querySelector( sel ) { return page.querySelector( sel ); },
		querySelectorAll( sel ) { return page.querySelectorAll( sel ); },
		getElementById( id ) { return page.all().find( ( c ) => c.attrs.id === id ) || null; },
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
	return { sent, card, onBtn, renewBtn, offForm, password, reveal, status, loader, text, source, checklist, stateLine, suggestion };
}

const written = await runPreview( { success: true, data: { password: 'abcdEFGH23456789', status: 'On since now', loader: { path: '/mu/alphabridge-preview-protection.php', written: true }, loader_note: '', loader_source: '', staging_checklist: '<div class="ab-staging ab-staging-checklist" id="ab-staging-checklist">after</div>', staging_state: 'Staging site. Checklist: 3 of 4 done. Staging first: ask.', staging_suggestion: 'Suggestion: staging site. The preview protection is on.' } } );
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
// The checklist, the line under the title and the suggestion follow, as a reload would show them.
assert.equal( written.checklist.outerHTML, '<div class="ab-staging ab-staging-checklist" id="ab-staging-checklist">after</div>', 'the checklist is the one of the answer' );
assert.equal( written.stateLine.textContent, 'Staging site. Checklist: 3 of 4 done. Staging first: ask.' );
assert.equal( written.suggestion.textContent, 'Suggestion: staging site. The preview protection is on.' );
assert.equal( written.password.value, 'abcdEFGH23456789', 'the password stays shown' );
cases++;

const missing = await runPreview( { success: true, data: { password: 'abcdEFGH23456789', status: 'On since now', loader: { path: '/mu/alphabridge-preview-protection.php', written: false }, loader_note: 'could not be written to /mu/alphabridge-preview-protection.php', loader_source: "<?php\n// ab-mcp-preview-loader" } } );
assert.equal( missing.loader.hidden, false, 'the note shows at once, without a reload' );
assert.equal( missing.text.textContent, 'could not be written to /mu/alphabridge-preview-protection.php' );
assert.equal( missing.source.value, "<?php\n// ab-mcp-preview-loader", 'with the file to create' );
assert.equal( missing.password.value, 'abcdEFGH23456789', 'and the password all the same' );
assert.equal( missing.checklist.outerHTML, '<div id="ab-staging-checklist">before</div>', 'an answer without a checklist leaves it as it is' );
assert.equal( missing.stateLine.textContent, '' );
cases++;

/* ---- The card «Staging»: its details element folds by itself; a click on
 * its summary also keeps the choice for this user over ajax (folded=1 when
 * it was open, 0 when it was folded). A click elsewhere sends nothing, and
 * an address naming a part inside the folded card opens it without
 * changing what is kept. ---- */
async function runCard( { hash = '', open = true } ) {
	const listeners = {};
	const sent = [];
	const mk = ( tagName, attrs, parent ) => ( {
		tagName, attrs, parentElement: parent, hidden: false, open: false,
		classList: { contains: ( c ) => ( attrs.class || '' ).split( ' ' ).includes( c ) },
		getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
		closest( sel ) {
			for ( let c = this; c; c = c.parentElement ) {
				if ( sel.startsWith( '.' ) && ! sel.includes( ',' ) && c.classList.contains( sel.slice( 1 ) ) ) { return c; }
			}
			return null;
		},
		scrollIntoView() {},
	} );
	const body    = mk( 'BODY', {}, null );
	const card    = mk( 'SECTION', { id: 'ab-staging-card', class: 'ab-card ab-staging-card' }, body );
	const details = mk( 'DETAILS', { class: 'ab-staging-card__fold' }, card );
	details.open  = open;
	const summary = mk( 'SUMMARY', { class: 'ab-staging-card__summary' }, details );
	const part    = mk( 'DIV', { id: 'ab-staging-first', class: 'ab-staging ab-staging-first' }, details );
	const title   = mk( 'H2', { class: 'ab-card__title' }, card );
	const byId    = { 'ab-staging-card': card, 'ab-staging-first': part };
	const document = {
		body,
		addEventListener( kind, fn ) { ( listeners[ kind ] = listeners[ kind ] || [] ).push( fn ); },
		querySelector() { return null; },
		querySelectorAll() { return []; },
		getElementById( id ) { return byId[ id ] || null; },
	};
	const window = {
		abMcpAdmin: { ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php', nonce: 'n' },
		location: { hash },
		localStorage: { getItem() { return null; }, setItem() {} },
		addEventListener() {},
		FormData: class { constructor() { this.fields = {}; } append( k, v ) { this.fields[ k ] = v; } },
		fetch( url, init ) { sent.push( { url, body: init.body.fields } ); return Promise.resolve( { json: () => Promise.resolve( { success: true, data: {} } ) } ); },
		alert( msg ) { throw new Error( 'alert: ' + msg ); },
	};
	vm.runInNewContext( code, { document, window, console } );
	const click = async ( target ) => {
		for ( const fn of listeners.click || [] ) {
			fn( { target, preventDefault() {} } );
		}
		await new Promise( ( r ) => setTimeout( r, 0 ) );
	};
	return { details, summary, title, sent, click };
}

{
	const page = await runCard( { open: true } );
	await page.click( page.summary );
	assert.deepEqual( page.sent.map( ( s ) => s.body ), [ { action: 'ab_mcp_staging_card', nonce: 'n', folded: '1' } ], 'open, a click folds it and keeps that' );
	assert.equal( page.sent[ 0 ].url, 'https://example.test/wp-admin/admin-ajax.php' );
	page.details.open = false; // The browser folds the details element itself.
	await page.click( page.summary );
	assert.deepEqual( page.sent[ 1 ].body, { action: 'ab_mcp_staging_card', nonce: 'n', folded: '0' }, 'folded, a click unfolds it' );
	await page.click( page.title );
	assert.equal( page.sent.length, 2, 'a click elsewhere keeps nothing' );
	cases++;
}
{
	const page = await runCard( { open: false, hash: '#ab-staging-first' } );
	assert.equal( page.details.open, true, 'an address naming a part inside opens the folded card' );
	assert.equal( page.sent.length, 0, 'and changes nothing kept' );
	cases++;
}
{
	const page = await runCard( { open: false, hash: '#ab-elsewhere' } );
	assert.equal( page.details.open, false, 'another address leaves it folded' );
	assert.equal( page.sent.length, 0 );
	cases++;
}

/* ---- An anchor of the card «Staging» on «AI Connection» (since 4.16.0):
 * the server leads an address of the page before 4.16.0 on to «AI
 * Connection» and never sees the anchor (…alphabridge-mcp#ab-preview). The
 * script goes on to «Staging» with the same anchor, with location.replace(),
 * when the anchor is one of the server's list (abMcpAdmin.stagingAnchors,
 * AB_MCP_Guidance::STAGING_ANCHORS, read here from the PHP file; AdminMenuTest
 * holds what the server sends to it),
 * the page is «AI Connection» and the part is not on it. Nothing else leads
 * on: another anchor, the page «Staging», a part that is there, a page
 * without the list or the address. ---- */
// The table itself, read from includes/class-guidance.php, as the server sends it.
const guidance        = readFileSync( join( dirname( fileURLToPath( import.meta.url ) ), '..', 'includes', 'class-guidance.php' ), 'utf8' );
const STAGING_ANCHORS = [ ...( /const STAGING_ANCHORS = array\(([^;]*)\);/.exec( guidance ) || [ '', '' ] )[ 1 ].matchAll( /'([^']+)'/g ) ].map( ( m ) => m[ 1 ] );
assert.ok( STAGING_ANCHORS.length >= 3 && STAGING_ANCHORS.includes( 'ab-preview' ), 'the table of the anchors of «Staging» is found' );
const STAGING_URL     = 'https://example.test/wp-admin/admin.php?page=alphabridge-staging';
function runLanding( { hash, screen = 'connection', present = [], cfg = null } ) {
	const replaced = [];
	const byId     = Object.fromEntries( present.map( ( id ) => [ id, { attrs: { id }, hidden: false, closest() { return null; }, getAttribute() { return null; } } ] ) );
	const document = {
		addEventListener() {},
		querySelector() { return null; },
		querySelectorAll() { return []; },
		getElementById( id ) { return byId[ id ] || null; },
	};
	const location = { hash, href: 'https://example.test/wp-admin/admin.php?page=alphabridge-connection' + hash, replace( url ) { replaced.push( url ); } };
	const window   = {
		abMcpAdmin: cfg || { ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php', nonce: 'n', screen, stagingAnchors: STAGING_ANCHORS, stagingUrl: STAGING_URL },
		location,
		localStorage: { getItem() { return null; }, setItem() {} },
		addEventListener() {},
	};
	vm.runInNewContext( code, { document, window, console } );
	return { replaced, href: location.href };
}

for ( const id of STAGING_ANCHORS ) {
	const page = runLanding( { hash: '#' + id } );
	assert.deepEqual( page.replaced, [ STAGING_URL + '#' + id ], `#${ id } on «AI Connection» goes on to «Staging» with the same anchor` );
	assert.equal( page.href, 'https://example.test/wp-admin/admin.php?page=alphabridge-connection#' + id, 'in place of this address (replace, not a new entry in the history)' );
	cases++;
}
for ( const hash of [ '', '#', '#ab-mode', '#ab-fine', '#ab-tool-wp_list_posts', '#ab-undo', '#ab-staging-elsewhere', '#ab-previewx', '#xab-preview', '#AB-PREVIEW' ] ) {
	assert.deepEqual( runLanding( { hash } ).replaced, [], `${ hash || 'no anchor' }: not an anchor of «Staging», nothing leads on` );
	cases++;
}
assert.deepEqual( runLanding( { hash: '#ab-preview', screen: 'staging' } ).replaced, [], 'on «Staging» itself nothing leads on, even where the part is missing' );
assert.deepEqual( runLanding( { hash: '#ab-preview', present: [ 'ab-preview' ] } ).replaced, [], 'a part that is on this page is landed on here' );
assert.deepEqual( runLanding( { hash: '#ab-preview', cfg: { stagingAnchors: STAGING_ANCHORS, stagingUrl: STAGING_URL } } ).replaced, [], 'a page that does not say it is «AI Connection» leads nowhere' );
assert.deepEqual( runLanding( { hash: '#ab-preview', cfg: { screen: 'connection', stagingAnchors: STAGING_ANCHORS } } ).replaced, [], 'without the address of «Staging» nothing leads on' );
assert.deepEqual( runLanding( { hash: '#ab-preview', cfg: { screen: 'connection', stagingUrl: STAGING_URL } } ).replaced, [], 'without the list nothing leads on' );
cases += 5;

/* ---- The page «Staging» (since 4.16.0): the script runs on a page that
 * carries the card «Staging» and nothing of «AI Connection» (no switch for
 * write access, no assistants, no fine-tuning, no connections), without an
 * error; an address naming a part opens the folded card, «Switch on» of the
 * preview protection asks over ajax and shows the password, and the fold is
 * kept. The elements are the ones the page «Staging» draws
 * (AdminPagesTest::testThePageStagingCarriesWhatAdminJsUsesThere). ---- */
async function runStagingPage( { hash = '', open = true } ) {
	const listeners = {};
	const sent = [];
	// A small stand-in for the DOM: tag, id, classes and attributes, also
	// inside the parts a descendant selector names. A selector it cannot
	// read throws, so the test fails rather than finds nothing.
	const simple = ( one ) => {
		const m = /^([a-z]+)?((?:#[\w-]+|\.[\w-]+|\[[\w-]+(?:\^?="[^"]*")?\])*)$/i.exec( one );
		if ( ! m ) {
			throw new Error( 'selector not supported by the stand-in: ' + one );
		}
		const parts = m[ 2 ].match( /#[\w-]+|\.[\w-]+|\[[^\]]+\]/g ) || [];
		return ( n ) => ( ! m[ 1 ] || n.tagName === m[ 1 ].toUpperCase() ) && parts.every( ( p ) => {
			if ( '#' === p[ 0 ] ) { return n.attrs.id === p.slice( 1 ); }
			if ( '.' === p[ 0 ] ) { return ( n.attrs.class || '' ).split( ' ' ).includes( p.slice( 1 ) ); }
			const a = /^\[([\w-]+)(?:(\^?)="([^"]*)")?\]$/.exec( p );
			if ( ! ( a[ 1 ] in n.attrs ) ) { return false; }
			if ( undefined === a[ 3 ] ) { return true; }
			return a[ 2 ] ? String( n.attrs[ a[ 1 ] ] ).startsWith( a[ 3 ] ) : String( n.attrs[ a[ 1 ] ] ) === a[ 3 ];
		} );
	};
	const matches = ( n, sel ) => sel.split( ',' ).some( ( complex ) => {
		const chain = complex.trim().split( /\s*>\s*|\s+/ ).map( simple );
		if ( ! chain[ chain.length - 1 ]( n ) ) { return false; }
		let i = chain.length - 2;
		for ( let up = n.parentElement; up && i >= 0; up = up.parentElement ) {
			if ( up.attrs && chain[ i ]( up ) ) { i--; }
		}
		return i < 0;
	} );
	const node = ( tagName, attrs = {}, children = [] ) => {
		const n = {
			tagName, attrs, children, parentElement: null, hidden: 'hidden' in attrs, open: false,
			value: '', textContent: '', innerHTML: '', outerHTML: '', disabled: false, dataset: {},
			classList: {
				contains: ( c ) => ( attrs.class || '' ).split( ' ' ).includes( c ),
				add: ( c ) => { attrs.class = ( ( attrs.class || '' ) + ' ' + c ).trim(); },
				toggle() {},
			},
			getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
			setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
			matches( sel ) { return matches( this, sel ); },
			closest( sel ) { for ( let c = this; c && c.attrs; c = c.parentElement ) { if ( matches( c, sel ) ) { return c; } } return null; },
			all() { return this.children.flatMap( ( c ) => [ c, ...c.all() ] ); },
			querySelector( sel ) { return this.all().find( ( c ) => matches( c, sel ) ) || null; },
			querySelectorAll( sel ) { return this.all().filter( ( c ) => matches( c, sel ) ); },
			scrollIntoView() { this.scrolled = true; },
		};
		for ( const c of children ) { c.parentElement = n; }
		return n;
	};
	const onBtn    = node( 'BUTTON', { type: 'button', class: 'ab-btn ab-preview-ajax', 'data-preview': 'on', 'data-when': 'off' } );
	const renewBtn = node( 'BUTTON', { type: 'button', class: 'ab-btn ab-btn--ghost ab-preview-ajax', 'data-preview': 'password', 'data-when': 'on', hidden: '' } );
	const offForm  = node( 'FORM', { method: 'post', class: 'ab-preview__off', 'data-when': 'on', hidden: '' }, [ node( 'BUTTON', { class: 'ab-btn ab-btn--ghost' } ) ] );
	const password = node( 'INPUT', { type: 'text', class: 'ab-select ab-preview-password' } );
	const copy     = node( 'BUTTON', { type: 'button', class: 'ab-btn ab-btn--ghost ab-btn--sm ab-copy', 'data-copy-target': '.ab-preview-password' } );
	const reveal   = node( 'DIV', { class: 'ab-reveal ab-preview-reveal', hidden: '' }, [ node( 'DIV', { class: 'ab-field' }, [ password, copy ] ) ] );
	const status   = node( 'P', { class: 'ab-direct__status ab-preview-status' } );
	const loader   = node( 'DIV', { class: 'notice notice-warning inline ab-preview__loader', hidden: '' }, [ node( 'P', { class: 'ab-preview__loader-text' } ), node( 'DETAILS', {}, [ node( 'SUMMARY' ), node( 'TEXTAREA', { class: 'ab-preview__source' } ) ] ) ] );
	const preview  = node( 'DIV', { id: 'ab-preview', class: 'ab-staging ab-preview' }, [ node( 'H3', { class: 'ab-setting__title' } ), status, reveal, node( 'DIV', { class: 'ab-preview__actions' }, [ onBtn, renewBtn, offForm ] ), loader ] );
	const checklist = node( 'DIV', { id: 'ab-staging-checklist', class: 'ab-staging ab-staging-checklist' } );
	const record    = node( 'DIV', { id: 'ab-staging', class: 'ab-staging' }, [ node( 'FORM', { method: 'post' }, [ node( 'INPUT', { type: 'url', id: 'ab-staging-partner', name: 'partner_url' } ), node( 'SELECT', { id: 'ab-staging-host', name: 'host', class: 'ab-select' } ) ] ) ] );
	const copyPart  = node( 'DIV', { id: 'ab-staging-copy', class: 'ab-staging ab-staging-copy' } );
	const first     = node( 'DIV', { id: 'ab-staging-first', class: 'ab-staging ab-staging-first' }, [ node( 'FORM', { method: 'post', class: 'ab-staging-first__form' } ) ] );
	const summary   = node( 'SUMMARY', { class: 'ab-staging-card__summary' } );
	const details   = node( 'DETAILS', { class: 'ab-staging-card__fold' }, [ summary, node( 'P', { class: 'ab-lead' } ), checklist, record, copyPart, preview, first ] );
	details.open    = open;
	const stateLine = node( 'P', { class: 'ab-direct__status ab-staging-card__state' } );
	const card      = node( 'SECTION', { id: 'ab-staging-card', class: 'ab-card ab-staging-card' }, [ node( 'P', { class: 'ab-eyebrow' } ), node( 'H2', { id: 'ab-staging-card-title', class: 'ab-card__title' } ), stateLine, details ] );
	const wrap      = node( 'DIV', { class: 'wrap ab-mcp ab-mcp--staging' }, [ node( 'H1' ), node( 'P', { class: 'ab-lead' } ), node( 'HR', { class: 'wp-header-end' } ), node( 'DIV', { class: 'ab-main' }, [ card ] ) ] );
	const body      = node( 'BODY', {}, [ wrap ] );
	const document = {
		body,
		activeElement: body,
		addEventListener( kind, fn ) { ( listeners[ kind ] = listeners[ kind ] || [] ).push( fn ); },
		querySelector( sel ) { return body.querySelector( sel ); },
		querySelectorAll( sel ) { return body.querySelectorAll( sel ); },
		getElementById( id ) { return body.all().find( ( c ) => c.attrs.id === id ) || null; },
		createElement( tagName ) { return node( tagName.toUpperCase() ); },
	};
	const answer = { success: true, data: { password: 'abcdEFGH23456789', status: 'On since now', loader: { written: true }, loader_note: '', loader_source: '' } };
	const replaced = [];
	const window = {
		abMcpAdmin: { ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php', nonce: 'n', working: 'Working…', screen: 'staging', stagingAnchors: STAGING_ANCHORS, stagingUrl: STAGING_URL },
		location: { hash, replace( url ) { replaced.push( url ); } },
		localStorage: { getItem() { return 'chatgpt'; }, setItem() {} },
		addEventListener() {},
		FormData: class { constructor() { this.fields = {}; } append( k, v ) { this.fields[ k ] = v; } },
		fetch( url, init ) { sent.push( { url, body: init.body.fields } ); return Promise.resolve( { json: () => Promise.resolve( answer ) } ); },
		alert( msg ) { throw new Error( 'alert: ' + msg ); },
		confirm() { return true; },
	};
	vm.runInNewContext( code, { document, window, console, navigator: {}, setTimeout } );
	const click = async ( target ) => {
		for ( const fn of listeners.click || [] ) {
			fn( { target, preventDefault() {} } );
		}
		await new Promise( ( r ) => setTimeout( r, 0 ) );
	};
	return { sent, click, onBtn, renewBtn, offForm, password, reveal, status, details, summary, preview, replaced };
}

{
	// Landing after «Switch off» (…#ab-preview) on a card the user folded.
	const page = await runStagingPage( { hash: '#ab-preview', open: false } );
	assert.equal( page.details.open, true, 'the address opens the folded card at the part it names' );
	assert.equal( page.preview.scrolled, true, 'and lands on it' );
	assert.equal( page.sent.length, 0, 'loading sends nothing' );
	assert.deepEqual( page.replaced, [], '«Staging» itself never leads on' );
	await page.click( page.onBtn );
	assert.deepEqual( page.sent.map( ( s ) => s.body ), [ { action: 'ab_mcp_preview_protection', nonce: 'n', preview: 'on' } ], '«Switch on» asks over ajax, with the nonce of the page' );
	assert.equal( page.sent[ 0 ].url, 'https://example.test/wp-admin/admin-ajax.php' );
	assert.equal( page.password.value, 'abcdEFGH23456789', 'the password, shown once' );
	assert.equal( page.reveal.hidden, false );
	assert.equal( page.status.textContent, 'On since now' );
	assert.equal( page.onBtn.hidden, true );
	assert.equal( page.renewBtn.hidden, false );
	assert.equal( page.offForm.hidden, false );
	cases++;
}
{
	const page = await runStagingPage( { open: true } );
	await page.click( page.summary );
	assert.deepEqual( page.sent.map( ( s ) => s.body ), [ { action: 'ab_mcp_staging_card', nonce: 'n', folded: '1' } ], 'the fold is kept on «Staging» too' );
	cases++;
}

console.log( `admin-js-test: ${ cases } cases passed` );
