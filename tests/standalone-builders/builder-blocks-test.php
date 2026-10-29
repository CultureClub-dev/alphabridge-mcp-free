<?php
/**
 * The block writer (AB_MCP_Builder_Writer_Blocks): pages made of the
 * WordPress core blocks, GenerateBlocks 1.x and 2.x, Kadence, Spectra,
 * Stackable and Pagelayer, written through WordPress' HTML API.
 *
 * Runs on the free core's real block reader and profiles (bundled core, run
 * ./sync-free-core.sh first), on fixed copies of WordPress 7.0.2's block
 * parser and HTML API (test/support/), and on the measured markup of the
 * plan's measurement (test/fixtures/blocks/, the source in every file). What
 * is checked:
 * - every sample comes back byte for byte (parse_blocks/serialize_blocks)
 *   and is writable; a page that does not is only read;
 * - per library: the changed field and nothing else changes, byte for byte,
 *   copies in block attributes follow, the reader reads the new value back,
 *   and wp_undo puts the page back byte for byte;
 * - the special characters of plan §2 (A & B, "Zitat", <x>, –, ü) in the
 *   form each place stores them, and replace() finding them in every form;
 * - the refusals: formatting a plain field would remove, icons, styles and
 *   scripts inside an html field, unprofiled blocks, links WordPress would
 *   store differently, a page kses would change for the account, a page
 *   changed while the change was prepared;
 * - Pagelayer's tree in pagelayer-data: changed along only where it matches.
 *
 * Run: php test/builder-blocks-test.php
 */

require dirname( __DIR__ ) . '/standalone-lib/builder-harness.php';

/* ---- WordPress 7.0.2: block parser, block functions, HTML API (fixed copies) ---- */
foreach ( array(
	'class-wp-block-parser-block.php',
	'class-wp-block-parser-frame.php',
	'class-wp-block-parser.php',
	'blocks-functions.php',
	'class-wp-token-map.php',
	'html5-named-character-references.php',
	'class-wp-html-attribute-token.php',
	'class-wp-html-span.php',
	'class-wp-html-doctype-info.php',
	'class-wp-html-text-replacement.php',
	'class-wp-html-decoder.php',
	'class-wp-html-tag-processor.php',
) as $ab_file ) {
	require_once dirname( __DIR__ ) . '/standalone-lib/support/' . $ab_file;
}

/* ---- what the writer and the HTML API ask of WordPress beyond the harness ---- */
function wp_slash( $v ) { if ( is_array( $v ) ) { return array_map( 'wp_slash', $v ); } return is_string( $v ) ? addslashes( $v ) : $v; }
function wp_unslash( $v ) { if ( is_array( $v ) ) { return array_map( 'wp_unslash', $v ); } return is_string( $v ) ? stripslashes( $v ) : $v; }
$GLOBALS['ab_doing_it_wrong'] = array();
function _doing_it_wrong( $f, $m, $v ) { $GLOBALS['ab_doing_it_wrong'][] = $f . ': ' . $m; }
// Narrower than core (wp-includes/utf8.php): the noncharacters U+FDD0–U+FDEF, U+FFFE, U+FFFF.
function wp_has_noncharacters( $text ) { return 1 === preg_match( '/[\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}]/u', (string) $text ); }
// wp-includes/kses.php:1229–1263 (WordPress 7.0.2), the list unchanged.
function wp_kses_uri_attributes() {
	return apply_filters( 'wp_kses_uri_attributes', array( 'action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap', 'xmlns' ) );
}
/*
 * Stand-in for esc_url() (wp-includes/formatting.php:4480), narrower than
 * core: the same character filter, "&" written as &#038; and "'" as &#039;
 * (display context), http:// in front of an address without scheme, and ''
 * for a scheme outside the protocol list. Not a sanitiser.
 */
function esc_url( $url, $protocols = null, $context = 'display' ) {
	if ( '' === $url ) { return $url; }
	$url = str_replace( ' ', '%20', ltrim( (string) $url ) );
	$url = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', $url );
	if ( '' === $url ) { return $url; }
	if ( false === strpos( $url, ':' ) && ! in_array( $url[0], array( '/', '#', '?' ), true ) ) { $url = 'http://' . $url; }
	$url = preg_replace( '~&(?![A-Za-z0-9#]+;)~', '&amp;', $url );
	$url = str_replace( array( '&amp;', "'" ), array( '&#038;', '&#039;' ), $url );
	if ( '/' !== $url[0] && preg_match( '~^([a-z][a-z0-9+.-]*):~i', $url, $m ) && ! in_array( strtolower( $m[1] ), array( 'http', 'https', 'mailto', 'tel', 'ftp', 'ftps', 'news', 'irc', 'sms', 'fax', 'webcal', 'urn' ), true ) ) { return ''; }
	return $url;
}
// wp-includes/kses.php:2372 (WordPress 7.0.2), on the harness' stand-in for wp_kses_post().
function wp_filter_post_kses( $data ) { return addslashes( wp_kses_post( stripslashes( $data ) ) ); }
/*
 * Stand-in for wp_update_post(): what it does to post_content, in core's
 * order (wp-includes/post.php): the stored post slashed and merged under the
 * new fields, the content_save_pre filters on the slashed value (kses for an
 * account without unfiltered_html), then unslashed and stored; save_post.
 * $GLOBALS['ab_update_fail'] makes it fail as core does with $wp_error.
 */
$GLOBALS['ab_updates']     = array();
$GLOBALS['ab_update_fail'] = false;
function wp_update_post( $postarr, $wp_error = false ) {
	$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	if ( $GLOBALS['ab_update_fail'] || ! isset( $GLOBALS['db_posts'][ $id ] ) ) {
		return $wp_error ? new WP_Error( 'db_update_error', 'Could not update post in the database.' ) : 0;
	}
	$postarr                 = array_merge( wp_slash( $GLOBALS['db_posts'][ $id ] ), $postarr );
	$postarr['post_content'] = apply_filters( 'content_save_pre', $postarr['post_content'] );
	$data                    = wp_unslash( $postarr );
	$GLOBALS['db_posts'][ $id ]['post_content'] = $data['post_content'];
	$GLOBALS['ab_updates'][] = $id;
	do_action( 'save_post', $id, get_post( $id ), true );
	return $id;
}

/* ---- the free core's own readers, not the harness' test adapter ---- */
remove_all_filters( 'ab_mcp_builder_adapters' );
AB_MCP_Builders::reset();
AB_MCP_Block_Reader::reset();
$GLOBALS['ab_tools_on'] = array( 'wp_update_builder_element' => true );
add_filter( 'ab_mcp_pro_builder_tool_enabled', static function ( $v, $n ) { return array_key_exists( $n, $GLOBALS['ab_tools_on'] ) ? $GLOBALS['ab_tools_on'][ $n ] : $v; }, 10, 2 );

$W = AB_MCP_Builder_Writers::for_adapter( 'blocks' );
ok( $W instanceof AB_MCP_Builder_Writer_Blocks, 'A0 the block writer is registered for the adapter id blocks' );

$GLOBALS['ab_caps'] = array( 'edit_posts' => true, 'read_post:55' => true );
for ( $i = 300; $i < 400; $i++ ) { $GLOBALS['ab_caps'][ 'edit_post:' . $i ] = true; }
ab_post( 55, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );   // an image (ab_images: http://x/u/bild.jpg)

/* ---- helpers ---- */
function fx( $name ) {
	$d = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/standalone-lib/fixtures/blocks/' . $name . '.json' ), true );
	return (string) $d['content'];
}
function page( $id, $content ) {
	ab_post( $id, array( 'post_content' => $content ) );
	foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
		if ( $r[0] === $id ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
	}
	AB_MCP_Undo::clear_all();
	AB_MCP_Builders::reset();
	$GLOBALS['ab_hooks']['content_save_pre'] = array();
	$GLOBALS['ab_update_fail']               = false;
	return get_post( $id );
}
function content( $id ) { return (string) $GLOBALS['db_posts'][ $id ]['post_content']; }
function outline( $id ) {
	$post = get_post( $id );
	return AB_MCP_Builders::for_post( $post )->outline( $post, array( 'max_field_chars' => 10000, 'max_elements' => 2000 ) );
}
function el( $id, $element ) {
	foreach ( outline( $id ) as $e ) {
		if ( $e['id'] === $element ) { return $e; }
	}
	return null;
}
function fv( $id, $element, $field ) {
	$e = el( $id, $element );
	return isset( $e['fields'][ $field ]['value'] ) ? $e['fields'][ $field ]['value'] : null;
}
function set( $id, $element, array $fields, $apply = true ) {
	$post = get_post( $id );
	return $GLOBALS['W']->set_fields( $post, $element, $fields, array( 'apply' => $apply, 'layout_hash' => AB_MCP_Builders::layout_hash( $post ) ) );
}
function swap( $id, $search, $replace, array $opts = array() ) {
	$post = get_post( $id );
	return $GLOBALS['W']->replace( $post, $search, $replace, array_merge( array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( $post ), 'scope' => 'text', 'undo_tool' => 'wp_search_replace' ), $opts ) );
}
function undo( $token ) {
	$c = AB_MCP_Undo::claim( $token );
	return is_array( $c ) ? AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'], array( 'parts' => $c['parts'] ) ) : $c;
}
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
function msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function attrs_of( $content, $n = 0 ) {
	$flat = array();
	$walk = static function ( $blocks ) use ( &$walk, &$flat ) {
		foreach ( $blocks as $b ) {
			if ( ! empty( $b['blockName'] ) ) { $flat[] = $b; }
			$walk( $b['innerBlocks'] );
		}
	};
	$walk( parse_blocks( $content ) );
	return isset( $flat[ $n ] ) ? $flat[ $n ]['attrs'] : null;
}
/** Applied, read back, and exactly $expected stored. */
function applied( $r, $id, $expected, $label ) {
	ok( is_array( $r ) && true === $r['applied'] && true === $r['readback']['ok'], $label . ': applied and read back (' . code( $r ) . ' ' . msg( $r ) . ')' );
	$got = content( $id );
	$at  = 0;
	while ( $at < strlen( $got ) && $at < strlen( $expected ) && $got[ $at ] === $expected[ $at ] ) { $at++; }
	ok( $got === $expected, $label . ': the page is the old one with exactly this change' . ( $got === $expected ? '' : "\n    first difference at byte $at\n    got:  " . substr( $got, max( 0, $at - 120 ), 300 ) . "\n    want: " . substr( $expected, max( 0, $at - 120 ), 300 ) ) );
}

/* ============================================ A) every sample is writable */

$samples = 0;
foreach ( glob( dirname( __DIR__ ) . '/standalone-lib/fixtures/blocks/*/*.json' ) as $file ) {
	$d = json_decode( (string) file_get_contents( $file ), true );
	if ( ! isset( $d['content'] ) ) { continue; }
	$p    = page( 300, $d['content'] );
	$name = basename( dirname( $file ) ) . '/' . basename( $file );
	$samples++;
	if ( 'core/media-text.json' === $name ) {
		// Taken from a theme's pattern file, never saved by WordPress: it
		// writes "--" in an attribute where WordPress writes \u002d\u002d.
		ok( serialize_blocks( parse_blocks( $d['content'] ) ) !== $d['content'] && 'ab_mcp_builder_no_roundtrip' === code( $W->can_write( $p ) ), 'A1 a sample WordPress never saved does not come back byte for byte and is only read: ' . $name );
		continue;
	}
	ok( serialize_blocks( parse_blocks( $d['content'] ) ) === $d['content'], 'A1 round trip byte for byte: ' . $name );
	ok( true === $W->can_write( $p ), 'A2 writable: ' . $name );
}
ok( $samples >= 40, "A3 all samples were read ($samples)" );
$p = page( 300, fx( 'core/heading' ) );
ok( 'blocks' === AB_MCP_Builders::for_post( $p )->id() && in_array( 'wp_update_builder_element', AB_MCP_Builders::write_via( $p ), true ), 'A4 a block page names wp_update_builder_element in write_via' );

// A page WordPress does not write back byte for byte is only read: an
// empty object comes back as [] (json_decode to an array).
$odd = "<!-- wp:paragraph {\"style\":{}} -->\n<p>Hallo</p>\n<!-- /wp:paragraph -->";
$p   = page( 301, $odd );
ok( 'ab_mcp_builder_no_roundtrip' === code( $W->can_write( $p ) ) && false !== strpos( msg( $W->can_write( $p ) ), 'wp_update_post' ), 'A5 no byte-for-byte round trip: only read, and the answer names the way' );
ok( ! in_array( 'wp_update_builder_element', AB_MCP_Builders::write_via( $p ), true ), 'A6 and write_via does not name the tool' );
$r = set( 301, 'b0', array( 'text' => 'Neu' ) );
ok( 'ab_mcp_builder_no_roundtrip' === code( $r ) && $odd === content( 301 ) && array() === $GLOBALS['ab_updates'], 'A7 set_fields refuses, nothing written' );

/* ============================================ C) core blocks */

$old = fx( 'core/heading' );
page( 310, $old );
$pre = set( 310, 'b0', array( 'text' => 'Neue Überschrift' ), false );
ok( is_array( $pre ) && false === $pre['applied'] && 'New arrivals' === $pre['changes'][0]['before'] && 'Neue Überschrift' === $pre['changes'][0]['after'] && $old === content( 310 ), 'C1 preview: before and after, nothing written' );
$r = set( 310, 'b0', array( 'text' => 'Neue Überschrift' ) );
applied( $r, 310, str_replace( 'New arrivals', 'Neue Überschrift', $old ), 'C2 core/heading' );
ok( 'Neue Überschrift' === fv( 310, 'b0', 'text' ), 'C3 the reader reads the new heading' );
ok( AB_MCP_Undo::valid_token( $r['undo_token'] ) && ! is_wp_error( undo( $r['undo_token'] ) ) && $old === content( 310 ), 'C4 wp_undo puts the page back byte for byte' );

$old = fx( 'core/paragraph' );
page( 311, $old );
$r = set( 311, 'b0', array( 'text' => '<strong>Fett</strong> & mehr' ) );
applied( $r, 311, str_replace( 'Like flowers that bloom in unexpected places, every story unfolds with beauty and resilience, revealing hidden wonders.', '<strong>Fett</strong> &amp; mehr', $old ), 'C5 core/paragraph html (a bare & written as &amp;)' );
$r = set( 311, 'b0', array( 'text' => 'Böse <script>alert(1)</script>' ) );
ok( 'ab_mcp_builder_kses' === code( $r ), 'C6 a script in an html field is refused by the field rules' );
$hl = "<!-- wp:paragraph -->\n<p>Mit <span class=\"hl\" style=\"color:red\">Farbe</span></p>\n<!-- /wp:paragraph -->";
page( 317, $hl );
$r = set( 317, 'b0', array( 'text' => 'Ohne Farbe' ) );
applied( $r, 317, str_replace( 'Mit <span class="hl" style="color:red">Farbe</span>', 'Ohne Farbe', $hl ), 'C6b an html field replaced as sent' );
ok( false !== strpos( implode( ' ', $r['warnings'] ), 'formatting the outline does not show' ), 'C6c with the warning that formatting the outline did not show is gone' );

$old = fx( 'core/button' );
page( 312, $old );
$r = set( 312, 'b0', array( 'text' => 'Kontakt', 'url' => 'https://example.org/kontakt?a=1&b=2' ) );
$want = preg_replace( '~href="#">\s*Amazon\s*</a>~', 'href="https://example.org/kontakt?a=1&#038;b=2">Kontakt</a>', $old );
applied( $r, 312, $want, 'C7 core/button text and link (href through set_attribute and esc_url)' );
ok( 'https://example.org/kontakt?a=1&b=2' === fv( 312, 'b0', 'url' ) && 'Kontakt' === fv( 312, 'b0', 'text' ), 'C8 the reader reads text and link back' );
$r = set( 312, 'b0', array( 'url' => 'https://example.org/a^b' ) );
ok( 'ab_mcp_builder_url_changed' === code( $r ) && false !== strpos( msg( $r ), '«https://example.org/ab»' ) && $want === content( 312 ), 'C9 a link WordPress would store differently is refused, saying how it would be stored' );
$r = set( 312, 'b0', array( 'text' => 'Mehr <b>Info</b>' ) );
ok( 'ab_mcp_builder_plain_text' === code( $r ), 'C10 a button label takes plain text only' );

$old = fx( 'core/image' );
page( 313, $old );
$r = set( 313, 'b0', array( 'image' => 55 ) );
$want = str_replace( array( '"id":8,', 'src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg=="', 'class="wp-image-8"' ), array( '"id":55,', 'src="http://x/u/bild.jpg"', 'class="wp-image-55"' ), $old );
applied( $r, 313, $want, 'C11 core/image: the id, its address and the wp-image class together' );
$fields = array();
foreach ( $r['changes'] as $c ) { $fields[ $c['field'] ] = $c['after']; }
ok( array( 'image' => 55, 'src' => 'http://x/u/bild.jpg' ) === $fields, 'C12 the answer shows the address that came with the image' );
ok( 55 === fv( 313, 'b0', 'image' ) && 'http://x/u/bild.jpg' === fv( 313, 'b0', 'src' ), 'C13 the reader reads id and address back' );

$fmt = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Hallo <em>Welt</em></h2>\n<!-- /wp:heading -->";
page( 314, $fmt );
$r = set( 314, 'b0', array( 'text' => 'Hallo Erde' ) );
ok( 'ab_mcp_builder_formatted' === code( $r ) && false !== strpos( msg( $r ), 'wp_search_replace' ) && $fmt === content( 314 ), 'C14 a heading with formatting inside is not flattened; the answer names wp_search_replace' );
$r = swap( 314, 'Welt', 'Erde' );
applied( $r, 314, str_replace( '<em>Welt</em>', '<em>Erde</em>', $fmt ), 'C15 replace changes the words and keeps the formatting' );

$html = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Titel</h2>\n<!-- /wp:heading -->\n\n<!-- wp:html -->\n<div>Titel</div>\n<!-- /wp:html -->\n\n<!-- wp:acme/hero {\"title\":\"Titel\"} -->\n<div class=\"acme\">Titel</div>\n<!-- /wp:acme/hero -->";
page( 315, $html );
ok( 'ab_mcp_builder_locked' === code( set( 315, 'b2', array( 'text' => 'X' ) ) ), 'C16 core/html is locked' );
$r = set( 315, 'b4', array( 'text' => 'X' ) );
ok( 'ab_mcp_builder_unprofiled' === code( $r ) && false !== strpos( msg( $r ), 'acme/hero' ) && $html === content( 315 ), 'C17 a block without profile is only read, with the way' );
$r = swap( 315, 'Titel', 'Name' );
ok( is_array( $r ) && 1 === count( $r['changes'] ) && 'b0' === $r['changes'][0]['element'], 'C18 replace changes only the profiled heading' );
ok( false !== strpos( implode( ' ', $r['notes'] ), '3 further matches are outside the fields' ) && false !== strpos( implode( ' ', $r['notes'] ), 'scope: raw' ), 'C19 and counts the matches in the HTML block and the unprofiled block, naming scope: raw' );
ok( 3 === substr_count( content( 315 ), 'Titel' ) && 1 === substr_count( content( 315 ), '>Name</h2>' ), 'C20 those stay as they are' );

$link = "<!-- wp:paragraph -->\n<p>Besuch <a href=\"https://alt.example/x\">uns</a> auf alt.example</p>\n<!-- /wp:paragraph -->";
page( 316, $link );
$r = swap( 316, 'alt.example', 'neu.example' );
applied( $r, 316, str_replace( 'alt.example', 'neu.example', $link ), 'C21 replace in a paragraph: the words and the link inside' );
page( 316, $link );
$r = swap( 316, 'https://alt.example/x', 'javascript:alert(1)' );
ok( 'ab_mcp_builder_nothing' === code( $r ) && $link === content( 316 ) && false !== strpos( msg( $r ), 'left' ), 'C22 a replacement that would make a link run code is left, and the answer says so' );
page( 316, $link );
$r = swap( 316, 'https://alt.example/x', 'ftp://alt.example/x' );
ok( 'ab_mcp_builder_nothing' === code( $r ) && $link === content( 316 ), 'C22b nor a link in the text outside the link rules (ftp, which WordPress itself would keep)' );

page( 318, fx( 'core/heading' ) );
$r = swap( 318, 'NEW ARRIVALS', 'Neuheiten', array( 'case_insensitive' => true ) );
applied( $r, 318, str_replace( 'New arrivals', 'Neuheiten', fx( 'core/heading' ) ), 'C23 replace, case-insensitive' );
page( 318, fx( 'core/heading' ) );
$r = swap( 318, 'New.*', 'X', array( 'regex' => true ) );
ok( 'ab_mcp_builder_regex' === code( $r ) && false !== strpos( msg( $r ), 'scope: raw' ) && fx( 'core/heading' ) === content( 318 ), 'C24 a regular expression is refused with the way (scope: raw)' );

// A text the HTML API and the outline read differently ("&copy" without
// semicolon is © to a browser, &copy to the outline): left, not guessed.
$legacy = "<!-- wp:paragraph -->\n<p>Foo &copy 2024</p>\n<!-- /wp:paragraph -->";
page( 319, $legacy );
$r = swap( 319, '©', '(c)' );
ok( 'ab_mcp_builder_nothing' === code( $r ) && $legacy === content( 319 ) && false !== strpos( msg( $r ), 'would not read as the replacement predicts' ), 'C25 a replacement the outline would not show as predicted is left, and the answer says so' );

/* ============================================ S) special characters (plan §2) */

// A plain field refuses "<x>" as a tag (field rules); "a < b > c" is text.
$sz    = 'A & B "Zitat" a < b > c – Grüsse ü';
$sz_h  = 'A &amp; B "Zitat" a &lt; b &gt; c – Grüsse ü';
$sz_j  = 'A \u0026amp; B \u0022Zitat\u0022 a \u0026lt; b \u0026gt; c – Grüsse ü';
$old   = fx( 'core/heading' );
page( 320, $old );
$r = set( 320, 'b0', array( 'text' => $sz ) );
applied( $r, 320, str_replace( 'New arrivals', $sz_h, $old ), 'S1 core heading: & < > as entities, quotes as the block editor writes them (no &quot;, no &apos;)' );
ok( $sz === fv( 320, 'b0', 'text' ), 'S2 and the reader reads exactly the text that was sent' );
$r = set( 320, 'b0', array( 'text' => "Kein 'Apostroph' als Entität" ) );
ok( is_array( $r ) && false !== strpos( content( 320 ), "Kein 'Apostroph' als Entität" ), 'S3 an apostrophe stays an apostrophe (WordPress 6.9 writes &apos;)' );

$old = fx( 'kadence/singlebtn' );
page( 321, $old );
$r = set( 321, '19290_62d41f-67', array( 'text' => $sz ) );
applied( $r, 321, str_replace( '"text":"Call To Action"', '"text":"' . $sz_j . '"', $old ), 'S4 Kadence singlebtn: the label stored HTML-escaped in the attribute (Kadence prints it unfiltered)' );
$a = attrs_of( content( 321 ) );
ok( $sz_h === $a['text'] && $sz === fv( 321, '19290_62d41f-67', 'text' ), 'S5 so < and > reach the page as text, and the reader reads the label back' );
$r = set( 321, '19290_62d41f-67', array( 'text' => 'Mehr <x> dazu' ) );
ok( 'ab_mcp_builder_plain_text' === code( $r ), 'S5b a tag in a button label is refused (plain text only)' );

$old = fx( 'spectra/buttons-child-sonderzeichen' );
page( 322, $old );
$r = set( 322, '0b139ae9', array( 'text' => 'Ä & Ö "neu"' ) );
$a = attrs_of( content( 322 ) );
ok( is_array( $r ) && true === $r['applied'] && 'Ä &amp; Ö "neu"' === $a['label'] && false !== strpos( content( 322 ), '<div class="uagb-button__link">Ä &amp; Ö "neu"</div>' ), 'S6 Spectra button: label in the markup and its copy in the attribute, the copy as the editor keeps rich text' );
ok( false !== strpos( content( 322 ), '<svg xmlns="https://www.w3.org/2000/svg"' ) && 'Ä & Ö "neu"' === fv( 322, '0b139ae9', 'text' ), 'S7 the icon next to it stays, the reader reads the label back' );

// Search finds the text in every form a block page stores it.
// The core sample is a cut-out with stray closing comments; its heading block alone.
preg_match( '~<!-- wp:heading .*?<!-- /wp:heading -->~s', fx( 'core/sonderzeichen' ), $ab_m );
$mix = $ab_m[0] . "\n\n" . fx( 'kadence/singlebtn-sonderzeichen' ) . "\n\n" . fx( 'spectra/buttons-child-sonderzeichen' ) . "\n\n" . fx( 'stackable/heading-sonderzeichen' );
page( 323, $mix );
ok( true === $W->can_write( get_post( 323 ) ), 'S8 the four special-character samples form one writable page' );
$r = swap( 323, 'A & B', 'C & D' );
$by = array();
foreach ( is_array( $r ) ? $r['changes'] : array() as $c ) { $by[ $c['element'] . '.' . $c['field'] ] = $c['after']; }
ok( is_array( $r ) && true === $r['applied'] && 4 === count( $by ), 'S9 «A & B» is found in the core heading, the Kadence label, the Spectra label and the Stackable heading (' . implode( ', ', array_keys( $by ) ) . ' ' . msg( $r ) . ')' );
$now = content( 323 );
ok( false !== strpos( $now, '<h2 class="wp-block-heading">C &amp; D "Zitat" &lt;x&gt; – Grüsse ü</h2>' ), 'S10 core: the text node rewritten with the replacement, & < > as entities' );
ok( false !== strpos( $now, '"text":"C \u0026amp; D \u0022Zitat\u0022 \u003cx\u003e – Grüsse ü"' ), 'S11 Kadence: the words in the attribute HTML-escaped, the markup the label holds (<x>) left as it is' );
ok( false !== strpos( $now, '<div class="uagb-button__link">C &amp; D "Zitat" &lt;x&gt; – Grüsse ü</div>' ) && false !== strpos( $now, '"label":"C \u0026amp; D \u0022Zitat\u0022 \u0026lt;x\u0026gt; – Grüsse ü"' ), 'S12 Spectra: markup and its copy (the copy now as the editor keeps rich text)' );
ok( 2 === substr_count( $now, '"name":"A \u0026 B' ) && false !== strpos( implode( ' ', $r['notes'] ), '2 further matches' ), 'S13 the block names (metadata.name) are not content: left, and counted' );
// <x> is text in three fields; the Kadence label holds it as markup (the
// measurement stored it raw, and Kadence prints the label as HTML).
$token = $r['undo_token'];
ok( 'ab_mcp_undo_forbidden' === code( undo( $token ) ) && $now === content( 323 ), 'S15 the undo point of wp_search_replace asks for the permission that tool needs (manage_options)' );
$GLOBALS['ab_caps']['manage_options'] = true;
ok( AB_MCP_Undo::valid_token( $token ) && ! is_wp_error( undo( $token ) ) && $mix === content( 323 ), 'S16 with it, wp_undo puts the page back byte for byte after a replace' );
unset( $GLOBALS['ab_caps']['manage_options'] );
foreach ( array( '"Zitat"' => 4, '–' => 4, 'ü' => 4, '<x>' => 3 ) as $needle => $fields ) {
	page( 324, $mix );
	$r = swap( 324, $needle, '[neu]' );
	ok( is_array( $r ) && $fields === count( $r['changes'] ), "S14 «{$needle}» is found as text in {$fields} fields (" . ( is_array( $r ) ? count( $r['changes'] ) : msg( $r ) ) . ')' );
}

/* ============================================ G) GenerateBlocks */

$old = fx( 'generateblocks/gb2-button' );
page( 330, $old );
$r = set( 330, '4e08132d', array( 'url' => 'https://example.org/neu', 'text' => 'Los' ) );
applied( $r, 330, str_replace( array( '"htmlAttributes":{"href":"#"}', 'href="#">Button<' ), array( '"htmlAttributes":{"href":"https://example.org/neu"}', 'href="https://example.org/neu">Los<' ), $old ), 'G1 GenerateBlocks 2.x button: link in the markup and in htmlAttributes.href, text; styles and css untouched' );
ok( false === strpos( implode( ' ', array_column( outline( 330 ), 'note' ) ), 'differs' ), 'G2 the reader finds the copy in step' );

$old = fx( 'generateblocks/gb2-button-link-attribute-only' );
page( 331, $old );
$r = set( 331, '4e08132d', array( 'url' => 'https://example.org/z' ) );
applied( $r, 331, str_replace( array( 'href="#">', '"href":"https://example.org/neu-A2"' ), array( 'href="https://example.org/z">', '"href":"https://example.org/z"' ), $old ), 'G3 a link set explicitly goes into a copy that already held something else too (the block editor found the block invalid)' );
ok( false !== strpos( implode( ' ', $r['notes'] ), 'htmlAttributes.href held another value' ) && false !== strpos( implode( ' ', $r['notes'] ), 'set to the new value too' ), 'G4 and the answer says so' );
// Measured variant H: the link changed in the markup only, the copy still "#".
$old = fx( 'generateblocks/gb2-button-link-markup-only' );
page( 338, $old );
$r = set( 338, '4e08132d', array( 'url' => 'https://example.org/bl-repariert' ) );
applied( $r, 338, str_replace( array( 'href="https://example.org/neu-H2">', '"htmlAttributes":{"href":"#"}' ), array( 'href="https://example.org/bl-repariert">', '"htmlAttributes":{"href":"https://example.org/bl-repariert"}' ), $old ), 'G4a markup-only link set again: markup and htmlAttributes.href agree afterwards' );
// A replace was not asked to set the link, only to change a text in it: a stale copy is left.
page( 339, fx( 'generateblocks/gb2-button-link-markup-only' ) );
$r = swap( 339, 'neu-H2', 'neu-H3' );
ok( is_array( $r ) && true === $r['applied'] && false !== strpos( content( 339 ), '"htmlAttributes":{"href":"#"}' ) && false !== strpos( content( 339 ), 'href="https://example.org/neu-H3">' ) && false !== strpos( implode( ' ', $r['notes'] ), 'was left as it is' ), 'G4b a replace leaves a stale copy as it is and says so: ' . msg( $r ) );

$old = fx( 'generateblocks/gb2-media' );
page( 332, $old );
$r = set( 332, '48cac4fb', array( 'image' => 55 ) );
applied( $r, 332, str_replace( array( '"mediaId":4934', 'https://patterns.generatepress.com/wp-content/uploads/2024/10/placeholder800x-768x768.png' ), array( '"mediaId":55', 'http://x/u/bild.jpg' ), $old ), 'G5 GenerateBlocks 2.x media: mediaId, src and htmlAttributes.src' );

page( 333, $old );
$r = swap( 333, 'https://patterns.generatepress.com', 'https://neu.example' );
applied( $r, 333, str_replace( 'https://patterns.generatepress.com', 'https://neu.example', $old ), 'G6 replace of a domain: the image address and its copy' );

$old = fx( 'generateblocks/gb1-headline' ) . "\n\n" . fx( 'generateblocks/gb1-button' ) . "\n\n" . fx( 'generateblocks/gb1-image' );
page( 334, $old );
$r1 = set( 334, '0f1bafd0', array( 'text' => 'Jetzt <em>handeln</em>' ) );
$r2 = set( 334, '18ce89a0', array( 'text' => 'Weiter', 'url' => '/weiter' ) );
$r3 = set( 334, '17547336', array( 'alt' => 'Bild & Text' ) );
applied( $r3, 334, str_replace( array( '>Take some action<', 'href="#">Read more<', 'alt=""' ), array( '>Jetzt <em>handeln</em><', 'href="/weiter">Weiter<', 'alt="Bild &amp; Text"' ), $old ), 'G7 GenerateBlocks 1.x: headline (html), button text and link, image alt' );
ok( is_array( $r1 ) && is_array( $r2 ) && 'Bild & Text' === fv( 334, '17547336', 'alt' ), 'G8 the reader reads the alt text back' );
$r = set( 334, '17547336', array( 'alt' => 'Ein <b>fettes</b> Bild' ) );
ok( 'ab_mcp_builder_plain_text' === code( $r ), 'G9 an alt text takes no tags (it is an attribute)' );

// A value whose markup another field is read by: it would change that field too.
$old = fx( 'generateblocks/gb2-heading' );
page( 335, $old );
ok( null === fv( 335, '2ddee60a', 'url' ), 'G10 the GenerateBlocks heading has no link field' );
$r = set( 335, '2ddee60a', array( 'text' => '<a class="gb-text" href="/x">Mit Link</a>' ) );
ok( 'ab_mcp_builder_side_effect' === code( $r ) && $old === content( 335 ), 'G11 html that would give the element a link field the reader then finds is refused, nothing written' );
$r = set( 335, '2ddee60a', array( 'text' => 'Mit <a href="/x">Link</a>' ) );
ok( is_array( $r ) && true === $r['applied'] && null === fv( 335, '2ddee60a', 'url' ), 'G12 a link without that class is written' );

/* ============================================ K) Kadence */

$old = fx( 'kadence/heading' );
page( 340, $old );
$r = set( 340, '19290_a73277-99', array( 'text' => 'Ein <strong>kurzer</strong> Titel' ) );
applied( $r, 340, str_replace( '>Write a Brief Title<', '>Ein <strong>kurzer</strong> Titel<', $old ), 'K1 Kadence advancedheading (html)' );

$old = fx( 'kadence/singlebtn-link' );
page( 341, $old );
$r = set( 341, '19290_62d41f-67', array( 'url' => 'https://example.org/[x]' ) );
ok( 'ab_mcp_builder_url' === code( $r ) && $old === content( 341 ), 'K2 a Kadence link with "[" is refused (Kadence runs it through do_shortcode)' );
$r = set( 341, '19290_62d41f-67', array( 'url' => 'tel:+41441234567' ) );
applied( $r, 341, str_replace( '"link":"https://example.org/neu-K2"', '"link":"tel:+41441234567"', $old ), 'K3 a Kadence link in the attribute' );

page( 341, fx( 'kadence/singlebtn-link' ) );
$r = swap( 341, 'https://example.org/neu-K2', 'javascript:alert(1)' );
ok( 'ab_mcp_builder_nothing' === code( $r ) && fx( 'kadence/singlebtn-link' ) === content( 341 ), 'K5 replace never turns a link into one that runs code' );
page( 341, fx( 'kadence/singlebtn-link' ) );
$r = swap( 341, 'https://', 'ftp://' );
ok( 'ab_mcp_builder_nothing' === code( $r ) && fx( 'kadence/singlebtn-link' ) === content( 341 ), 'K6 nor into one outside the link rules (http, https, mailto, tel, relative)' );

$old = fx( 'kadence/image' );
page( 342, $old );
$r = set( 342, '19290_29834c-f6', array( 'image' => 55, 'alt' => 'Velo' ) );
applied( $r, 342, str_replace( array( '"id":2864', 'src="https://patterns.startertemplatecloud.com/wp-content/uploads/2023/02/Example-A-Roll-Image-scaled.jpg" alt=""', 'wp-image-2864' ), array( '"id":55', 'src="http://x/u/bild.jpg" alt="Velo"', 'wp-image-55' ), $old ), 'K4 Kadence image: id, address, alt and class' );

/* ============================================ P) Spectra */

$old = fx( 'spectra/advanced-heading' );
page( 350, $old );
$r = set( 350, '28f119cb', array( 'text' => 'Unsere Sicht' ) );
applied( $r, 350, str_replace( '>Our Vision<', '>Unsere Sicht<', $old ), 'P1 Spectra advanced heading' );

$old = fx( 'spectra/about-20' );
page( 351, $old );
$img = null;
foreach ( outline( 351 ) as $e ) {
	if ( 'uagb/image' === $e['type'] ) { $img = $e; break; }
}
ok( null !== $img && 'd4e717a3' === $img['id'] && isset( $img['fields']['src'] ), 'P2 the Spectra sample has its image d4e717a3' );
/** The Spectra image block d4e717a3 and everything around it, as separate strings. */
function spectra_image( $content ) {
	preg_match( '~^(.*)(<!-- wp:uagb/image \{"block_id":"d4e717a3".*?<!-- /wp:uagb/image -->)(.*)$~s', $content, $m );
	return isset( $m[3] ) ? array( $m[1] . '|' . $m[3], $m[2], parse_blocks( $m[2] )[0] ) : array( '', '', null );
}
$was = spectra_image( $old );
ok( false !== strpos( $was[1], 'srcset=' ) && false !== strpos( $was[1], 'sizes=' ) && 3 === count( array_intersect_key( $was[2]['attrs'], array_flip( array( 'url', 'urlTablet', 'urlMobile' ) ) ) ), 'P3 the stored image has srcset, sizes and three copies of its address' );
$r   = set( 351, 'd4e717a3', array( 'src' => 'https://example.org/neu.jpg' ) );
$now = spectra_image( content( 351 ) );
ok( is_array( $r ) && true === $r['applied'] && 'https://example.org/neu.jpg' === fv( 351, 'd4e717a3', 'src' ), 'P4 Spectra image: the new address in the markup, read back' );
ok( $was[0] === $now[0], 'P5 nothing outside the image block changed' );
$want = $was[2]['attrs'];
foreach ( array( 'url', 'urlTablet', 'urlMobile' ) as $k ) { $want[ $k ] = 'https://example.org/neu.jpg'; }
ok( $want === $now[2]['attrs'], 'P6 url, urlTablet and urlMobile follow; every other attribute stays' );
$p = new WP_HTML_Tag_Processor( $now[1] );
$p->next_tag( 'img' );
// Spectra's save() builds srcset from url, urlTablet and urlMobile and writes sizes as fixed text
// (measured: the editor's expected markup after a change, e2e report editor-2026-10-02T03-04-57-916Z.json).
$n = 'https://example.org/neu.jpg';
ok( "$n ,$n 780w, $n 360w" === $p->get_attribute( 'srcset' ) && '(max-width: 480px) 150px' === $p->get_attribute( 'sizes' ) && $n === $p->get_attribute( 'src' ) && 'uag-image-80569' === $p->get_attribute( 'class' ) && '564' === $p->get_attribute( 'width' ), 'P7 srcset (built from the copies of the address) lists the new address, as Spectra\'s save() writes it; sizes and the rest of the tag stay' );
ok( str_replace( 'https://websitedemos.net/wp-content/uploads/2023/10/desserts-01.jpg', $n, $was[1] ) === $now[1], 'P7a the block is the old one with every old address (comment, src, srcset) replaced by the new one, byte for byte' );
ok( false !== strpos( implode( ' ', $r['notes'] ), 'lists the new address now' ) && false === strpos( implode( ' ', $r['notes'] ), 'taken off' ), 'P8 and the answer says so' );
// A srcset that lists other files (sizes of the old image) cannot be rewritten: taken off as before.
$other = str_replace( 'desserts-01.jpg 360w', 'desserts-01-300x200.jpg 360w', $old );
page( 353, $other );
$r   = set( 353, 'd4e717a3', array( 'src' => $n ) );
$now = spectra_image( content( 353 ) );
$p   = new WP_HTML_Tag_Processor( $now[1] );
$p->next_tag( 'img' );
ok( is_array( $r ) && true === $r['applied'] && null === $p->get_attribute( 'srcset' ) && null === $p->get_attribute( 'sizes' ) && $n === $p->get_attribute( 'src' ) && false !== strpos( implode( ' ', $r['notes'] ), 'srcset and sizes were taken off' ), 'P8a a srcset with other files of the old image is taken off with sizes, and the answer says so' );
page( 352, $old );
$r   = set( 352, 'd4e717a3', array( 'image' => 55 ) );
$now = spectra_image( content( 352 ) );
$p   = new WP_HTML_Tag_Processor( $now[1] );
$p->next_tag( 'img' );
ok( is_array( $r ) && 55 === $now[2]['attrs']['id'] && 'http://x/u/bild.jpg' === $now[2]['attrs']['urlMobile'] && 'http://x/u/bild.jpg' === $p->get_attribute( 'src' ) && 'uag-image-55' === $p->get_attribute( 'class' ), 'P9 a new image: id, address, its copies and the uag-image class' );
ok( 'http://x/u/bild.jpg ,http://x/u/bild.jpg 780w, http://x/u/bild.jpg 360w' === $p->get_attribute( 'srcset' ) && '(max-width: 480px) 150px' === $p->get_attribute( 'sizes' ), 'P9a and its srcset as Spectra builds it from the new copies' );

/* ============================================ T) Stackable */

$old = fx( 'stackable/heading' ) . "\n\n" . fx( 'stackable/text' ) . "\n\n" . fx( 'stackable/button' ) . "\n\n" . fx( 'stackable/image' );
page( 360, $old );
$r1 = set( 360, 'ab2c9eb', array( 'text' => 'Überschrift' ) );
$r2 = set( 360, '86365db', array( 'text' => 'Kurz.' ) );
$r3 = set( 360, '8f3cb19', array( 'text' => 'Senden', 'url' => '#kontakt' ) );
// b084424 looks like a path id (b + digits), so the reader addresses this image by its path.
$sid = null;
foreach ( outline( 360 ) as $e ) {
	if ( 'stackable/image' === $e['type'] ) { $sid = $e['id']; }
}
ok( 1 === preg_match( '~^b\d+$~', (string) $sid ), 'T0 the Stackable image is addressed by its path (' . $sid . ')' );
$r4 = set( 360, (string) $sid, array( 'image' => 55 ) );
$want = str_replace(
	array( '>heading_placeholder<', 'So you wanna see what Stackable is all about? Well this whole page is created using Stackable blocks only! Click on any block and below so see how Stackable blocks can make your life better.', 'href=""><span class="stk-button__inner-text">ewqdwq<', '"imageUrl":"https://stackable-files.pages.dev/library-v4/images/stk-design-library-image-1.jpeg","imageId":5695', 'class="stk-img wp-image-5695" src="https://stackable-files.pages.dev/library-v4/images/stk-design-library-image-1.jpeg"' ),
	array( '>Überschrift<', 'Kurz.', 'href="#kontakt"><span class="stk-button__inner-text">Senden<', '"imageUrl":"http://x/u/bild.jpg","imageId":55', 'class="stk-img wp-image-55" src="http://x/u/bild.jpg"' ),
	$old
);
applied( $r4, 360, $want, 'T1 Stackable heading, text, button and image (imageUrl follows, the <style> stays)' );
ok( is_array( $r1 ) && is_array( $r2 ) && is_array( $r3 ), 'T2 all four applied' );

/* ============================================ L) Pagelayer and its tree */

$tree = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/standalone-lib/fixtures/blocks/pagelayer/ability-meta.json' ), true );
$old  = fx( 'pagelayer/ability-page' );
page( 370, $old );
ab_meta_add( 370, 'pagelayer-data', $tree['meta'] );
ok( 1427 === strlen( $tree['meta'] ), 'L0 the fixture tree has the measured length' );
$r = set( 370, 'c924782', array( 'text' => 'Öffnungszeiten: Mo–Sa 9–17 Uhr' ) );
applied( $r, 370, str_replace( '<p>Öffnungszeiten: Mo–Fr 9–18 Uhr</p>', '<p>Öffnungszeiten: Mo–Sa 9–17 Uhr</p>', $old ), 'L1 Pagelayer text inside its <p>' );
ok( str_replace( 's:42:"<p>Öffnungszeiten: Mo–Fr 9–18 Uhr</p>"', 's:42:"<p>Öffnungszeiten: Mo–Sa 9–17 Uhr</p>"', $tree['meta'] ) === ab_first_meta( 370, 'pagelayer-data' ), 'L2 the tree in pagelayer-data matched the page and follows, nothing else in it changes' );
ok( false !== strpos( implode( ' ', $r['notes'] ), 'pagelayer-data' ), 'L3 the answer says so' );
$r = set( 370, 'tbb2714', array( 'text' => 'Jetzt buchen' ) );
ok( is_array( $r ) && true === $r['applied'] && false !== strpos( content( 370 ), '{"text":"Jetzt buchen","link":"https://example.com/reservieren"' ) && false !== strpos( ab_first_meta( 370, 'pagelayer-data' ), 's:4:"text";s:12:"Jetzt buchen";s:4:"link";s:31:"https://example.com/reservieren";' ), 'L4 Pagelayer button: the label in the comment and in the tree' );
$r = set( 370, 'xd36828', array( 'text' => 'Willkommen im Veloladen' ) );
ok( is_array( $r ) && false !== strpos( content( 370 ), '<h1>Willkommen im Veloladen</h1>' ) && false !== strpos( ab_first_meta( 370, 'pagelayer-data' ), 's:32:"<h1>Willkommen im Veloladen</h1>"' ), 'L5 Pagelayer heading inside its <h1>, tree in step' );
ok( 1418 === strlen( ab_first_meta( 370, 'pagelayer-data' ) ), 'L6 the tree has the length the measurement found after the same two changes by Pagelayer\'s abilities (1418)' );
$c = AB_MCP_Undo::claim( $r['undo_token'] );
AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'], array( 'parts' => $c['parts'] ) );
ok( false !== strpos( ab_first_meta( 370, 'pagelayer-data' ), 'Willkommen bei Alpine Bikes' ) && false !== strpos( content( 370 ), 'Willkommen bei Alpine Bikes' ), 'L7 wp_undo puts page and tree back' );

// After a save in Pagelayer's editor the tree is stale: it stays as it is.
$old   = fx( 'pagelayer/after-editor' );
$stale = str_replace( array( 's:36:"<h1>Willkommen bei Alpine Bikes</h1>"', 's:17:"Jetzt reservieren"' ), array( 's:32:"<h1>Willkommen im Veloladen</h1>"', 's:12:"Jetzt buchen"' ), $tree['meta'] );
page( 371, $old );
ab_meta_add( 371, 'pagelayer-data', $stale );
$r = set( 371, 'c924782', array( 'text' => 'Neu & gut' ) );
applied( $r, 371, str_replace( array( '"text":"\n\u003cp\u003eÖffnungszeiten: Mo–Fr 9–18 Uhr\u003c/p\u003e\n"', "\n<p>Öffnungszeiten: Mo–Fr 9–18 Uhr</p>\n" ), array( '"text":"\n\u003cp\u003eNeu \u0026amp; gut\u003c/p\u003e\n"', "\n<p>Neu &amp; gut</p>\n" ), $old ), 'L8 after an editor save: the text and its copy in the attribute text' );
ok( $stale === ab_first_meta( 371, 'pagelayer-data' ), 'L9 the stale tree is left as it is' );
ok( false !== strpos( implode( ' ', $r['warnings'] ), 'no longer matches the page' ), 'L10 with the warning what an ability would do with it' );
// A tree that is only Pagelayer's editor marker (a time stamp) is left too.
page( 372, fx( 'pagelayer/ability-page' ) );
ab_meta_add( 372, 'pagelayer-data', '1727700000' );
$r = set( 372, 'c924782', array( 'text' => 'X' ) );
ok( is_array( $r ) && '1727700000' === ab_first_meta( 372, 'pagelayer-data' ) && array() === $r['warnings'], 'L11 an editor marker in pagelayer-data is not a tree: untouched, no warning' );
page( 374, fx( 'pagelayer/ability-page' ) );
$r = set( 374, 'tbb2714', array( 'text' => 'Velo & Bike' ) );
ok( is_array( $r ) && false !== strpos( content( 374 ), '"text":"Velo \u0026amp; Bike"' ) && false !== strpos( implode( ' ', $r['warnings'] ), 'has not been measured' ), 'L13 a Pagelayer label with & is stored HTML-escaped, with the warning that Pagelayer\'s output of it is not measured' );
// The page changed without the tree (measured: post_content only, pl-2): the
// tree is stale although its shape still fits, and stays as it is.
$old = fx( 'pagelayer/post-content-changed' );
page( 375, $old );
ab_meta_add( 375, 'pagelayer-data', $tree['meta'] );
$r = set( 375, 'xd36828', array( 'text' => 'Hallo' ) );
ok( is_array( $r ) && true === $r['applied'] && $tree['meta'] === ab_first_meta( 375, 'pagelayer-data' ) && false !== strpos( implode( ' ', $r['warnings'] ), 'no longer matches the page' ), 'L14 a tree whose texts no longer match the page is left as it is, with the warning' );
page( 373, fx( 'pagelayer/code-page' ) );
ok( 'ab_mcp_builder_locked' === code( set( 373, 'mze001', array( 'text' => 'x' ) ) ) && 'ab_mcp_builder_locked' === code( set( 373, 'mzs001', array( 'text' => 'x' ) ) ), 'L12 pl_embed and pl_shortcodes are locked' );

/* ============================================ X) refusals and the guards around the write */

// An html field whose element holds an icon.
$icon = "<!-- wp:generateblocks/text {\"uniqueId\":\"ic01\",\"tagName\":\"p\"} -->\n<p class=\"gb-text\">Mit <svg viewBox=\"0 0 1 1\"><text>Icon</text><path d=\"M0 0\"></path></svg> Icon</p>\n<!-- /wp:generateblocks/text -->";
page( 380, $icon );
$r = set( 380, 'ic01', array( 'text' => 'Ohne' ) );
ok( 'ab_mcp_builder_span_hidden' === code( $r ) && false !== strpos( msg( $r ), 'svg' ) && $icon === content( 380 ), 'X1 an html field holding an SVG icon is not overwritten' );
$r = swap( 380, 'Icon', 'Symbol' );
applied( $r, 380, str_replace( ' Icon</p>', ' Symbol</p>', $icon ), 'X2 replace changes its words around the icon, and nothing inside the SVG' );

// An account WordPress filters with kses, on a page kses would change elsewhere.
$guard = fx( 'core/heading' ) . "\n\n<!-- wp:html -->\n<div>Zähler</div><script>var z = 1;</script>\n<!-- /wp:html -->";
page( 381, $guard );
add_filter( 'content_save_pre', 'wp_filter_post_kses' );
$p = set( 381, 'b0', array( 'text' => 'Neu' ), false );
$r = set( 381, 'b0', array( 'text' => 'Neu' ) );
ok( 'ab_mcp_builder_kses_page' === code( $p ) && 'ab_mcp_builder_kses_page' === code( $r ) && $guard === content( 381 ) && false !== strpos( msg( $r ), 'super admin' ), 'X3 kses would remove the script of another block: refused in preview and apply, nothing written, the way named' );
page( 382, fx( 'core/heading' ) );
add_filter( 'content_save_pre', 'wp_filter_post_kses' );
$r = set( 382, 'b0', array( 'text' => 'Neu' ) );
ok( is_array( $r ) && true === $r['applied'], 'X4 the same account writes a page kses leaves as it is' );

// Saved by someone else while the change was prepared: nothing is overwritten.
$old = fx( 'core/heading' );
page( 383, $old );
$GLOBALS['ab_hooks']['ab_mcp_undo_snapshot_max'][10][] = array( static function ( $v ) { $GLOBALS['db_posts'][383]['post_content'] = str_replace( 'New arrivals', 'Jemand anderes', $GLOBALS['db_posts'][383]['post_content'] ); return $v; }, 1 );
$r = set( 383, 'b0', array( 'text' => 'Meins' ) );
$GLOBALS['ab_hooks']['ab_mcp_undo_snapshot_max'] = array();
ok( is_wp_error( $r ) && false !== strpos( msg( $r ), 'saved by someone else' ) && str_replace( 'New arrivals', 'Jemand anderes', $old ) === content( 383 ), 'X5 a page saved while the undo point was recorded is neither overwritten nor put back (' . msg( $r ) . ')' );
ok( is_wp_error( $r ) && null === $r->get_error_data()['undo_token'] && false === $r->get_error_data()['rolled_back'] && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'X5b and no undo point is left that would undo their save' );

// WordPress fails to save: the page is unchanged, the undo point dropped.
page( 384, $old );
$GLOBALS['ab_update_fail'] = true;
$r = set( 384, 'b0', array( 'text' => 'Meins' ) );
ok( is_wp_error( $r ) && 'db_update_error' === $r->get_error_data()['cause'] && null === $r->get_error_data()['undo_token'] && $old === content( 384 ), 'X6 a failed save: unchanged, no undo point left' );
$GLOBALS['ab_update_fail'] = false;

// The slashes: WordPress strips a level of them; the writer adds one.
$old = fx( 'generateblocks/gb2-button' );
page( 385, $old );
$r = set( 385, '4e08132d', array( 'text' => 'Ä' ) );
ok( is_array( $r ) && false !== strpos( content( 385 ), '"\u0026:is(:hover, :focus)"' ), 'X7 \u0026 in the styles survives the save (wp_slash)' );

// A save filter that changes the field: the read-back fails, the page goes back.
page( 386, fx( 'core/heading' ) );
add_filter( 'content_save_pre', static function ( $c ) { return str_replace( 'Neu', 'Anders', $c ); } );
$r = set( 386, 'b0', array( 'text' => 'Neu' ) );
ok( 'ab_mcp_builder_readback_failed' === code( $r ) && true === $r->get_error_data()['rolled_back'] && fx( 'core/heading' ) === content( 386 ), 'X8 a field that does not read back: the page is put back' );

// Path ids need the layout_hash; a changed page is refused.
page( 387, fx( 'core/heading' ) );
$r = $W->set_fields( get_post( 387 ), 'b0', array( 'text' => 'Neu' ), array( 'apply' => true ) );
ok( 'ab_mcp_builder_path_needs_hash' === code( $r ), 'X9 a path id without layout_hash is refused' );
$r = $W->set_fields( get_post( 387 ), 'b0', array( 'text' => 'Neu' ), array( 'apply' => true, 'layout_hash' => str_repeat( '0', 64 ) ) );
ok( 'ab_mcp_builder_layout_changed' === code( $r ) && fx( 'core/heading' ) === content( 387 ), 'X10 a stale layout_hash is refused' );

// The same value again: nothing to write.
$r = set( 387, 'b0', array( 'text' => 'New arrivals' ) );
ok( 'ab_mcp_builder_unchanged' === code( $r ), 'X11 the same value again writes nothing' );

// Placeholders stay; replace leaves a field it would break.
$ph = "<!-- wp:paragraph -->\n<p>Hallo {post_title}, willkommen</p>\n<!-- /wp:paragraph -->";
page( 388, $ph );
ok( 'ab_mcp_builder_placeholder' === code( set( 388, 'b0', array( 'text' => 'Hallo, willkommen' ) ) ), 'X12 a placeholder cannot be dropped' );
$r = swap( 388, '{post_title}', 'Gast' );
ok( 'ab_mcp_builder_nothing' === code( $r ) && $ph === content( 388 ), 'X13 replace leaves a placeholder alone' );

// Unmeasured profile fields are written, with the warning.
$q = "<!-- wp:list-item -->\n<li>Erster Punkt</li>\n<!-- /wp:list-item -->";
page( 389, $q );
$r = set( 389, 'b0', array( 'text' => 'Zweiter Punkt' ) );
ok( is_array( $r ) && true === $r['applied'] && false !== strpos( implode( ' ', $r['warnings'] ), 'profile not yet measured' ), 'X14 a field the profile has from code only: written, with «profile not yet measured»' );

// A field whose element holds an inner block: written around it, never across it.
$nested = "<!-- wp:list-item -->\n<li>Oben<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>Innen</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list --></li>\n<!-- /wp:list-item -->";
page( 390, $nested );
ok( true === $W->can_write( get_post( 390 ) ), 'X15 a nested list round-trips' );
$r = set( 390, 'b0', array( 'text' => 'Neu oben' ) );
applied( $r, 390, str_replace( '<li>Oben<!--', '<li>Neu oben<!--', $nested ), 'X16 the item\'s own text is written in front of its inner list, which stays' );
$r = set( 390, 'b0.0.0', array( 'text' => 'Neu innen' ) );
applied( $r, 390, str_replace( array( '<li>Oben<!--', '<li>Innen</li>' ), array( '<li>Neu oben<!--', '<li>Neu innen</li>' ), $nested ), 'X17 the inner item is written' );
$around = "<!-- wp:list-item -->\n<li>Oben<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>Innen</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list --> unten</li>\n<!-- /wp:list-item -->";
page( 391, $around );
$r = set( 391, 'b0', array( 'text' => 'Alles neu' ) );
ok( 'ab_mcp_builder_split' === code( $r ) && $around === content( 391 ), 'X16b a field whose text runs on after an inner block is refused' );

/* ============================================ R) every field of the real samples */

// Every writable field of the vendor samples, one at a time: written with
// nothing else on the page changing and taken back byte for byte, or refused
// for a reason the writer names, with the page untouched.
$known = array( 'ab_mcp_builder_formatted', 'ab_mcp_builder_span_hidden', 'ab_mcp_builder_several', 'ab_mcp_builder_split', 'ab_mcp_builder_url_changed', 'ab_mcp_builder_plain_text', 'ab_mcp_builder_kses', 'ab_mcp_builder_placeholder', 'ab_mcp_builder_unprofiled' );
$tally = array();
foreach ( array( 'kadence/example-page', 'spectra/about-20', 'spectra/about-24', 'stackable/preview-page', 'core/list', 'core/quote', 'core/cover', 'core/sonderzeichen', 'pagelayer/after-editor' ) as $name ) {
	$old  = fx( $name );
	$tally[ $name ] = array( 'applied' => 0, 'refused' => array() );
	page( 398, $old );
	$base = outline( 398 );
	foreach ( $base as $e ) {
		if ( ! empty( $e['locked'] ) || empty( $e['fields'] ) ) { continue; }
		foreach ( $e['fields'] as $fname => $f ) {
			$value = 'url' === $f['kind'] ? 'https://example.org/neu' : ( 'image' === $f['kind'] ? 55 : 'Geändert' );
			page( 398, $old );
			$r = set( 398, $e['id'], array( $fname => $value ) );
			if ( is_wp_error( $r ) ) {
				$tally[ $name ]['refused'][] = $e['type'] . '.' . $fname . ':' . substr( code( $r ), 15 );
				ok( in_array( code( $r ), $known, true ) && $old === content( 398 ), "R1 $name {$e['id']}.$fname: refused for a named reason, page untouched (" . code( $r ) . ' ' . msg( $r ) . ')' );
				continue;
			}
			$tally[ $name ]['applied']++;
			$now   = outline( 398 );
			$same  = array_column( $base, 'id' ) === array_column( $now, 'id' );
			$moved = array();
			foreach ( $base as $k => $b ) {
				foreach ( isset( $b['fields'] ) ? $b['fields'] : array() as $n => $v ) {
					$w = isset( $now[ $k ]['fields'][ $n ] ) ? $now[ $k ]['fields'][ $n ]['value'] : null;
					if ( $w !== $v['value'] ) { $moved[] = $b['id'] . '.' . $n; }
				}
			}
			$expect = array( $e['id'] . '.' . $fname );
			if ( 'image' === $f['kind'] && isset( $e['fields']['src'] ) ) { $expect[] = $e['id'] . '.src'; }
			sort( $moved );
			sort( $expect );
			ok( true === $r['applied'] && $same && $expect === $moved, "R2 $name {$e['id']}.$fname: written, and no other field of the page changed (" . implode( ', ', $moved ) . ')' );
			ok( ! is_wp_error( undo( $r['undo_token'] ) ) && $old === content( 398 ), "R3 $name {$e['id']}.$fname: taken back byte for byte" );
		}
	}
}
$sum = 0;
foreach ( $tally as $name => $t ) { $sum += $t['applied']; }
ok( $sum >= 60, "R4 at least 60 fields of the samples were written ($sum)" );
foreach ( $tally as $name => $t ) {
	echo '  R ' . $name . ': ' . $t['applied'] . ' written' . ( array() === $t['refused'] ? '' : ', refused: ' . implode( ' ', array_count_values( $t['refused'] ) === array() ? array() : array_map( static function ( $k, $n ) { return $k . ( $n > 1 ? " ×$n" : '' ); }, array_keys( array_count_values( $t['refused'] ) ), array_count_values( $t['refused'] ) ) ) ) . "\n";
}

/* ============================================ W) through the tool */

abstract class AB_MCP_Tools_Base {
	protected static function s( $a, $k, $d = '' ) { return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : $d; }
	protected static function i( $a, $k, $d = 0 ) { return isset( $a[ $k ] ) ? (int) $a[ $k ] : $d; }
	protected static function b( $a, $k, $d = false ) { return isset( $a[ $k ] ) ? (bool) $a[ $k ] : $d; }
	protected static function need( $a, array $keys ) {
		foreach ( $keys as $k ) {
			if ( ! isset( $a[ $k ] ) || '' === $a[ $k ] ) { return new WP_Error( 'ab_mcp_missing_arg', 'Missing required argument(s): ' . $k ); }
		}
		return true;
	}
}
if ( ! function_exists( 'array_is_list' ) ) {
	function array_is_list( array $a ) { return array_keys( $a ) === range( 0, count( $a ) - 1 ); }
}
require_once AB_MCP_PRO_DIR . 'includes/tools/class-tools-builder-write.php';
$old = fx( 'kadence/text' );
page( 395, $old );
$t = AB_MCP_Tools_Builder_Write::update_builder_element( array( 'id' => 395, 'element_id' => '19290_31cc85-39', 'fields' => array( 'html' => 'Kurz & <em>gut</em>' ) ) );
ok( is_array( $t ) && 'blocks' === $t['builder'] && false === $t['applied'] && 'Kurz &amp; <em>gut</em>' === $t['element']['after']['text'] && $old === content( 395 ), 'W1 wp_update_builder_element previews a Kadence text through the block writer (kind alias html)' );
$t = AB_MCP_Tools_Builder_Write::update_builder_element( array( 'id' => 395, 'element_id' => '19290_31cc85-39', 'fields' => (object) array( 'text' => 'Kurz & <em>gut</em>' ), 'apply' => true, 'layout_hash' => $t['layout_hash'] ) );
ok( is_array( $t ) && true === $t['applied'] && AB_MCP_Undo::valid_token( $t['undo_token'] ) && 'Kurz &amp; <em>gut</em>' === preg_replace( '~^.*<p[^>]*>(.*)</p>.*$~s', '$1', content( 395 ) ), 'W2 and applies it with an undo token' );
$t = AB_MCP_Tools_Builder_Write::update_builder_element( array( 'id' => 395, 'element_id' => '19290_31cc85-39', 'fields' => array( 'text' => 'x' ), 'apply' => true, 'publish' => false ) );
ok( 'ab_mcp_builder_no_draft' === code( $t ) && false !== strpos( msg( $t ), 'Leave publish out' ), 'W3 publish=false: block pages keep no draft of their own, and the answer says what to do' );

// Nothing of this reaches WordPress as a misuse of its HTML API.
ok( array() === $GLOBALS['ab_doing_it_wrong'], 'X18 no _doing_it_wrong from the HTML API: ' . implode( '; ', $GLOBALS['ab_doing_it_wrong'] ) );

ab_result( 'BUILDER-BLOCKS-CHECKS' );
