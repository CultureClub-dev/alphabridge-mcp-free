<?php
/**
 * The shortcode writer (AB_MCP_Builder_Writer_Shortcodes): WPBakery,
 * Divi 4, Avada and Flatsome in post_content, Enfold in its layout meta with
 * the copy in post_content — on the free core's own readers (the bundled
 * core, run ./sync-free-core.sh first).
 *
 * The pages are the vendor examples of the measurement folder of the
 * page-builder plan, word for word where a constant names its line
 * (alphabridge-docs: docs/recherche/2026-09-30-page-builder-messung/quellen/
 * <file>.md); they are also the free core's test pages (tests/
 * ShortcodeBuildersTest.php). They are documentation, not measurements.
 *
 * Checked: the encodings (a case table), writing exactly at the reader's
 * byte offsets (everything else byte for byte), Enfold's meta and copy in one
 * undo point, the read-back through the free reader, wp_undo, locked
 * shortcodes (plan §8), placeholders, the kses guard, a page changed while
 * the change was prepared, replace() with locked matches, and that preview
 * and answer say the profile is not measured.
 *
 * Run: php test/builder-shortcodes-test.php     (after ./sync-free-core.sh <free>)
 */

/* What WordPress does with slashes and content filters, for the writer's save path. */
function wp_slash( $v ) {
	if ( is_array( $v ) ) {
		return array_map( 'wp_slash', $v );
	}
	return is_string( $v ) ? addslashes( $v ) : $v;
}
function wp_unslash( $v ) {
	if ( is_array( $v ) ) {
		return array_map( 'wp_unslash', $v );
	}
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
/* Mirrors wp-includes/post.php sanitize_post_field(), 'db' context: pre_post_content, then content_save_pre. */
function sanitize_post_field( $field, $value, $post_id, $context = 'display' ) {
	if ( 'db' !== $context ) {
		return $value;
	}
	$value = apply_filters( "pre_{$field}", $value );
	return apply_filters( str_replace( 'post_', '', $field ) . '_save_pre', $value );
}
/*
 * Stand-in for wp_update_post(): slashed data in, the content filters run on
 * it (wp_insert_post() → sanitize_post( …, 'db' )), unslashed into the row,
 * then save_post. Narrower than core: only post_content, no revisions.
 */
$GLOBALS['ab_update_post_calls'] = 0;
$GLOBALS['ab_update_post_fail']  = false;
function wp_update_post( $postarr, $wp_error = false ) {
	$GLOBALS['ab_update_post_calls']++;
	$id = (int) $postarr['ID'];
	if ( $GLOBALS['ab_update_post_fail'] || ! isset( $GLOBALS['db_posts'][ $id ] ) ) {
		return $wp_error ? new WP_Error( 'db_update_error', 'Could not update post in the database.' ) : 0;
	}
	if ( array_key_exists( 'post_content', $postarr ) ) {
		$GLOBALS['db_posts'][ $id ]['post_content'] = wp_unslash( sanitize_post_field( 'post_content', $postarr['post_content'], $id, 'db' ) );
	}
	do_action( 'save_post', $id, get_post( $id ), true );
	return $id;
}
/* What kses_init_filters() hangs on content_save_pre for an account without unfiltered_html (slash-aware, as wp_filter_post_kses()). */
function ab_test_kses( $data ) {
	return addslashes( wp_kses_post( stripslashes( (string) $data ) ) );
}

require dirname( __DIR__ ) . '/standalone-lib/builder-harness.php';

// The free core's own readers, not the harness's test adapter.
remove_all_filters( 'ab_mcp_builder_adapters' );
AB_MCP_Builders::reset();
AB_MCP_Builder_Writers::reset();

// Every shortcode builder active, as their signatures check it.
define( 'WPB_VC_VERSION', '9.0.1' );
define( 'ET_BUILDER_PRODUCT_VERSION', '4.27.9' );
define( 'UX_BUILDER_VERSION', '3.20.11' );
function Avia_Builder() { return null; } // phpcs:ignore -- Enfold's function name.
update_option( 'active_plugins', array( 'fusion-builder/fusion-builder.php' ) );

add_filter( 'ab_mcp_pro_builder_tool_enabled', static function ( $v, $n ) { return 'wp_update_builder_element' === $n ? true : $v; }, 10, 2 );
$GLOBALS['ab_caps'] = array( 'edit_posts' => true, 'unfiltered_html' => true, 'read_post:55' => true );
for ( $i = 200; $i < 300; $i++ ) {
	$GLOBALS['ab_caps'][ 'edit_post:' . $i ] = true;
}
ab_post( 55, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) ); // an image (ab_images): http://x/u/bild.jpg

/* ------------------------------------------------- vendor examples, verbatim */

const WPB_HEADING = '[vc_custom_heading text="Über uns" font_container="tag:h2|text_align:left" use_theme_fonts="yes"]';                   // wpbakery.md l. 49.
const WPB_TEXT    = '[vc_column_text]<p>Wir sind Ihr Partner für Preise &amp; Angebote.</p>[/vc_column_text]';                          // wpbakery.md l. 57.
const WPB_BUTTON  = '[vc_btn title="Angebote ansehen" link="url:https%3A%2F%2Fbeispiel.ch%2Fpreise%2F|title:Preise|target:_blank|rel:nofollow"]'; // wpbakery.md l. 66.
const WPB_IMAGE   = '[vc_single_image image="123" img_size="full" onclick="custom_link" link="https://beispiel.ch/ueber-uns/" img_link_target="_blank"]'; // wpbakery.md l. 74.
const WPB_QUOTES  = '[vc_custom_heading text="Er sagt ``Hallo`` `{`x`}`"]';                                                              // wpbakery.md l. 96.
const WPB_CSS     = 'css=".vc_custom_1727700000000{margin-bottom: 20px !important;}"';                                                   // wpbakery.md l. 77.

const DIVI_PAGE   = "[et_pb_section fb_built=\"1\" theme_builder_area=\"post_content\" _builder_version=\"…\" _module_preset=\"default\"]\n  [et_pb_row _builder_version=\"…\" theme_builder_area=\"post_content\"]\n    [et_pb_column type=\"4_4\" _builder_version=\"…\"]\n      [et_pb_cta title=\"Über uns\" button_text=\"Mehr erfahren\" …]<p>Text …</p>[/et_pb_cta]\n    [/et_pb_column]\n  [/et_pb_row]\n[/et_pb_section]"; // divi-4.md l. 12–18.
const DIVI_BUTTON = '[et_pb_button button_text="Preise ansehen" button_url="https://example.ch/preise/" url_new_window="on" _builder_version="…"][/et_pb_button]'; // divi-4.md l. 63.
const DIVI_IMAGE  = '[et_pb_image src="https://example.ch/wp-content/uploads/2026/09/team.jpg" alt="Unser Team" title_text="Team" url="https://example.ch/team/" url_new_window="off"][/et_pb_image]'; // divi-4.md l. 68.

const AVADA_PAGE  = "[fusion_builder_container …][fusion_builder_row][fusion_builder_column …]\n[fusion_title …]Über uns[/fusion_title]\n[fusion_text …]<p>Preise &amp; Angebote …</p>[/fusion_text]\n[fusion_button link=\"https://example.com/kontakt/\" target=\"_self\" …]Kontakt[/fusion_button]\n[fusion_imageframe …]…[/fusion_imageframe]\n[/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]"; // avada.md l. 57–62.

const FS_TITLE    = '[title style="center" text="Über uns" tag_name="h2"]';                               // flatsome.md l. 38.
const FS_COL      = '[col span="12" span__sm="12"]<p>Wir sind …</p>[/col]';                               // flatsome.md l. 40.
const FS_BUTTON   = '[button text="Preise & Angebote" link="https://example.ch/preise/" target="_blank" radius="99"]'; // flatsome.md l. 42.
const FS_BLOCK    = '[block id="size-guide"]';                                                           // flatsome.md l. 28.

const ENF_HEADING = "[av_heading heading='Über uns' tag='h2' style='blockquote modern-quote' subheading_active='subheading_below' link='' link_target='' av_uid='av-mb1x2y']Seit 1999 in Bern[/av_heading]"; // enfold.md l. 50.
const ENF_TEXT    = "[av_textblock size='' font_color='' color='' av_uid='av-mb1x2z' custom_class='' admin_preview_bg='']\n<p>Preise &amp; Angebote für 2027</p>\n[/av_textblock]"; // enfold.md l. 55.
const ENF_BUTTON  = "[av_button label='Offerte anfragen' link='page,42' link_target='' size='large' position='center' av_uid='av-mb1x30']"; // enfold.md l. 60.
const ENF_IMAGE   = "[av_image src='https://beispiel.ch/wp-content/uploads/team.jpg' attachment='123' attachment_size='full' align='center' link='' target='' caption='' av_uid='av-mb1x31'][/av_image]"; // enfold.md l. 65.

/* ---------------------------------------------------------------- helpers */

/** A page whose builder keeps it in post_content. */
function sc_page( $id, $content, array $meta = array() ) {
	ab_post( $id, array( 'post_content' => $content ) );
	foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
		if ( $r[0] === (int) $id ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
	}
	foreach ( $meta as $k => $v ) {
		ab_meta_add( $id, $k, $v );
	}
	return get_post( $id );
}
/** An Enfold page: the layout in the meta, a stale copy in post_content. */
function sc_enfold( $id, $layout, $copy = '[av_textblock]Alte Kopie[/av_textblock]' ) {
	return sc_page( $id, $copy, array( '_aviaLayoutBuilder_active' => 'active', '_aviaLayoutBuilderCleanData' => $layout ) );
}
/** The writer for a page. */
function sc_writer( $post ) {
	return AB_MCP_Builder_Writers::for_post( $post );
}
/** The outline the free reader gives. */
function sc_outline( $post ) {
	return AB_MCP_Builders::for_post( get_post( (int) $post->ID ) )->outline( get_post( (int) $post->ID ), array( 'max_field_chars' => 10000 ) );
}
/** The element of an outline with this type (the n-th). */
function sc_el( $post, $type, $n = 0 ) {
	foreach ( sc_outline( $post ) as $el ) {
		if ( $el['type'] === $type && 0 === $n-- ) { return $el; }
	}
	return null;
}
/** One field value of the outline. */
function sc_val( $post, $type, $field, $n = 0 ) {
	$el = sc_el( $post, $type, $n );
	return isset( $el['fields'][ $field ]['value'] ) ? $el['fields'][ $field ]['value'] : null;
}
/** Set fields and apply, with the layout_hash the outline was read with. */
function sc_apply( $post, $el_id, array $fields, array $opts = array() ) {
	$post = get_post( (int) $post->ID );
	return sc_writer( $post )->set_fields( $post, $el_id, $fields, array_merge( array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( $post ) ), $opts ) );
}
/** Undo by token, as wp_undo does. */
function sc_undo( $token ) {
	$c = AB_MCP_Undo::claim( $token );
	if ( is_wp_error( $c ) ) { return $c; }
	return AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'], array( 'parts' => $c['parts'] ) );
}
/** Whether a list of warnings carries the «not measured» warning. */
function sc_unmeasured( array $warnings ) {
	foreach ( $warnings as $w ) {
		if ( 0 === strpos( $w, 'profile not yet measured' ) ) { return true; }
	}
	return false;
}
function sc_content( $id ) { return $GLOBALS['db_posts'][ (int) $id ]['post_content']; }
function sc_meta( $id, $key ) { return ab_first_meta( (int) $id, $key ); }
function sc_reset() {
	AB_MCP_Undo::clear_all();
	$GLOBALS['ab_caps']['unfiltered_html'] = true;
	remove_all_filters( 'content_save_pre' );
	remove_all_filters( 'save_post' );
	remove_all_filters( 'ab_mcp_pro_builder_derived_meta' );
	remove_all_filters( 'ab_mcp_builder_shortcode_profiles' );
	remove_all_filters( 'ab_mcp_pro_shortcode_locked_tags' );
	$GLOBALS['ab_update_post_fail'] = false;
	unset( $GLOBALS['shortcode_tags'] );
	AB_MCP_Builders::reset();
	AB_MCP_Builder_Writers::reset();
}

$E = 'AB_MCP_Shortcode_Encoder';

/* ======================================== E) the encodings: a case table */

// WPBakery: the vendor example, written the other way round (wpbakery.md l. 96).
ok( 'Er sagt ``Hallo`` `{`x`}`' === $E::attr( 'Er sagt "Hallo" [x]', 'wpbakery', '"' ), 'E1 WPBakery: " as two back-ticks, [ ] as `{` `}` (the vendor example in reverse)' );
ok( 'Er sagt %22Hallo%22 %91x%93 C:%92' === $E::attr( 'Er sagt "Hallo" [x] C:\\', 'divi4', '"' ), 'E2 Divi 4: %22 %91 %93 %92' );
ok( 'Peter’s Café' === $E::attr( "Peter's Café", '', "'" ) && 'Er sagt ‘Hallo’' === $E::attr( "Er sagt 'Hallo'", '', "'" ), 'E3 Enfold: an apostrophe as ’, a pair as ‘…’ (enfold.md d))' );
ok( 'A &quot;B&quot; &#91;x&#93; C:&#92;' === $E::attr( 'A "B" [x] C:\\', '', '"' ), 'E4 no documented encoding (Avada, Flatsome): character references' );
ok( 'a &lt; b' === $E::attr( 'a < b', 'wpbakery', '"' ) && 'Über <strong>uns</strong>' === $E::attr( 'Über <strong>uns</strong>', '', "'" ), 'E5 a "<" WordPress would drop the value for is written as &lt;; complete tags stay' );
// The plan's table of special characters (§9): every codec, every quote, read back by the free reader.
$cases = array( 'A & B', '"Zitat"', '<x>', 'Preise – Angebote', 'Grüsse über ü', "Peter's", 'a]b[c', "Tab\tund\\n", "nbsp\u{00A0}hier" );
$all   = true;
foreach ( $cases as $v ) {
	foreach ( array( array( 'wpbakery', '"' ), array( 'divi4', '"' ), array( '', '"' ), array( '', "'" ), array( 'wpbakery', "'" ) ) as $cq ) {
		$raw = $E::attr( $v, $cq[0], $cq[1] );
		if ( ! $E::shows( $E::decode( $raw, $cq[0], $cq[1] ), $v, $cq[1] ) ) {
			$all = false;
			echo '    case ' . json_encode( array( $v, $cq, $raw, $E::decode( $raw, $cq[0], $cq[1] ) ), JSON_UNESCAPED_UNICODE ) . "\n";
		}
	}
}
ok( $all, 'E6 the special-character table (A & B, "Zitat", <x>, –, ü, apostrophe, brackets, backslash, no-break space) reads back as written in every codec and quote' );
ok( null === $E::decode( 'a"b', '', '"' ) && null === $E::decode( 'a]b', 'wpbakery', '"' ), 'E7 a raw value WordPress cannot read as one value is no value' );
ok( ! $E::shows( $E::decode( $E::attr( 'a``b', 'wpbakery', '"' ), 'wpbakery', '"' ), 'a``b', '"' ) && ! $E::shows( $E::decode( $E::attr( '50%22', 'divi4', '"' ), 'divi4', '"' ), '50%22', '"' ), 'E8 text that looks like the codec itself (``, %22) does not survive — the writer refuses it' );
ok( 'url:https%3A%2F%2Fbeispiel.ch%2Fangebote%2F|title:Preise|target:_blank|rel:nofollow' === $E::vc_link( 'url:https%3A%2F%2Fbeispiel.ch%2Fpreise%2F|title:Preise|target:_blank|rel:nofollow', 'https://beispiel.ch/angebote/' ), 'E9 WPBakery link: the url part replaced, title, target and rel kept byte for byte' );
ok( 'url:%2Fkontakt|title:x' === $E::vc_link( 'title:x', '/kontakt' ) && 'url:%2Fk' === $E::vc_link( '', '/k' ), 'E10 a link without url part gets one in front' );
ok( 'manually,https://beispiel.ch/' === $E::enfold_link( 'https://beispiel.ch/' ) && '' === $E::enfold_link( '' ), 'E11 Enfold link: manually,<address>' );
ok( 'A &amp; B &lt; C &gt; D' === $E::content( 'A & B < C > D', 'heading' ) && 'A &amp; B' === $E::content( 'A &amp; B', 'heading' ), 'E12 plain text between tags: & < > as references, never twice' );
ok( '<p>A &amp; B &amp; C</p>' === $E::content( '<p>A & B &amp; C</p>', 'html' ), 'E13 html between tags: a bare & as &amp;, references kept' );
ok( array( 'html' => 'A &amp; B', 'plain' => 'A & B' ) === $E::html_forms( 'A & B' ) && array( 'plain' => 'Über uns' ) === $E::html_forms( 'Über uns' ), 'E14 the forms a text is searched in: as HTML stores it, and as written' );

/* ============================================== W) WPBakery (post_content) */

sc_reset();
$wpb = '[vc_row][vc_column]' . WPB_HEADING . "\n" . WPB_TEXT . "\n" . WPB_BUTTON . "\n" . WPB_IMAGE . "\n" . '[vc_raw_js]JTNDc2NyaXB0JTNF[/vc_raw_js][/vc_column][/vc_row]';
$p   = sc_page( 200, $wpb );
$W   = sc_writer( $p );
ok( $W instanceof AB_MCP_Builder_Writer_Shortcodes && 'wpbakery' === $W->id(), 'W1 a WPBakery page has the shortcode writer for wpbakery' );
ok( in_array( 'wp_update_builder_element', AB_MCP_Builders::write_via( $p ), true ), 'W2 write_via names wp_update_builder_element' );
$ids = array();
foreach ( array( 'wpbakery', 'divi4', 'avada', 'flatsome', 'enfold' ) as $b ) {
	$ids[] = null !== AB_MCP_Builder_Writers::for_adapter( $b ) ? $b : '-';
}
ok( array( 'wpbakery', 'divi4', 'avada', 'flatsome', 'enfold' ) === $ids, 'W3 one writer per shortcode builder the free core reads' );

$h    = sc_el( $p, 'vc_custom_heading' );
$hash = AB_MCP_Builders::layout_hash( $p );
$pre  = $W->set_fields( $p, $h['id'], array( 'text' => 'Über uns & "Team" [neu]' ), array() );
ok( is_array( $pre ) && false === $pre['applied'] && $wpb === sc_content( 200 ) && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'W4 preview writes nothing and records no undo point' );
ok( sc_unmeasured( $pre['warnings'] ), 'W5 the preview says the profile is not yet measured' );
ok( 'Über uns' === $pre['changes'][0]['before'] && 'Über uns & "Team" [neu]' === $pre['changes'][0]['after'], 'W6 before and after as the page shows them' );
$r = sc_apply( $p, $h['id'], array( 'text' => 'Über uns & "Team" [neu]' ) );
ok( is_array( $r ) && true === $r['applied'] && true === $r['readback']['ok'], 'W7 applied, read back through the free reader' );
ok( sc_unmeasured( $r['warnings'] ), 'W8 the answer says the profile is not yet measured' );
$want = str_replace( 'text="Über uns"', 'text="Über uns & ``Team`` `{`neu`}`"', $wpb );
ok( $want === sc_content( 200 ), 'W9 exactly the attribute value changed, in WPBakery\'s encoding; every other byte as before' );
ok( 'Über uns & "Team" [neu]' === sc_val( get_post( 200 ), 'vc_custom_heading', 'text' ), 'W10 the reader shows the new heading' );
$u = sc_undo( $r['undo_token'] );
ok( is_array( $u ) && 1 === $u['undone'] && $wpb === sc_content( 200 ), 'W11 wp_undo puts the page back byte for byte' );

// Button link: the url part of the vc_link, the rest of it kept.
$b = sc_el( get_post( 200 ), 'vc_btn' );
$r = sc_apply( get_post( 200 ), $b['id'], array( 'url' => 'https://beispiel.ch/angebote/', 'text' => 'Alle Angebote' ) );
ok( is_array( $r ) && true === $r['applied'], 'W12 button text and link applied' );
ok( false !== strpos( sc_content( 200 ), '[vc_btn title="Alle Angebote" link="url:https%3A%2F%2Fbeispiel.ch%2Fangebote%2F|title:Preise|target:_blank|rel:nofollow"]' ), 'W13 the link keeps title, target and rel; the address is rawurlencoded' );
sc_undo( $r['undo_token'] );

// Text block: HTML between the tags.
$t = sc_el( get_post( 200 ), 'vc_column_text' );
$tp = $W->set_fields( get_post( 200 ), $t['id'], array( 'text' => '<p>x</p>' ), array() );
ok( is_array( $tp ) && '<p>Wir sind Ihr Partner für Preise &amp; Angebote.</p>' === $tp['changes'][0]['before'], 'W14 before is the stored HTML in full, so the preview shows what the new value replaces' );
$r = sc_apply( get_post( 200 ), $t['id'], array( 'text' => '<p>Neu: Preise & <strong>Rabatte</strong></p>' ) );
ok( is_array( $r ) && false !== strpos( sc_content( 200 ), '[vc_column_text]<p>Neu: Preise &amp; <strong>Rabatte</strong></p>[/vc_column_text]' ), 'W15 the text block holds the new HTML, & as &amp;' );
sc_undo( $r['undo_token'] );

// Image by attachment id.
$i = sc_el( get_post( 200 ), 'vc_single_image' );
$r = sc_apply( get_post( 200 ), $i['id'], array( 'image' => 55 ) );
ok( is_array( $r ) && false !== strpos( sc_content( 200 ), '[vc_single_image image="55" img_size="full"' ) && 55 === sc_val( get_post( 200 ), 'vc_single_image', 'image' ), 'W16 the image takes the attachment id' );
sc_undo( $r['undo_token'] );

// Refusals: nothing written in any of them.
$before = sc_content( 200 );
$points = count( get_option( AB_MCP_Undo::OPTION, array() ) );
$r1 = sc_apply( get_post( 200 ), $b['id'], array( 'url' => 'javascript:alert(1)' ) );
$r2 = sc_apply( get_post( 200 ), $t['id'], array( 'text' => '<p>x</p><script>alert(1)</script>' ) );
$r3 = sc_apply( get_post( 200 ), $h['id'], array( 'text' => 'Über <b>uns</b>' ) );
$r4 = sc_apply( get_post( 200 ), $t['id'], array( 'text' => '<p>x</p>[vc_raw_html]PHA+[/vc_raw_html]' ) );
$r5 = sc_apply( get_post( 200 ), $h['id'], array( 'text' => 'a``b' ) );
$r6 = $W->set_fields( get_post( 200 ), $h['id'], array( 'text' => 'Neu' ), array( 'apply' => true ) );
ok( is_wp_error( $r1 ) && 'ab_mcp_builder_url' === $r1->get_error_code(), 'W17 a javascript: link is refused' );
ok( is_wp_error( $r2 ) && 'ab_mcp_builder_kses' === $r2->get_error_code(), 'W18 a script in a text is refused' );
ok( is_wp_error( $r3 ) && 'ab_mcp_builder_plain_text' === $r3->get_error_code(), 'W19 markup in a heading is refused' );
ok( is_wp_error( $r4 ) && 'ab_mcp_builder_shortcode_in_value' === $r4->get_error_code(), 'W20 a shortcode in a new text is refused (it would add an element)' );
ok( is_wp_error( $r5 ) && 'ab_mcp_builder_encoding' === $r5->get_error_code(), 'W21 a text that WPBakery\'s encoding cannot carry is refused, with the way' );
ok( is_wp_error( $r6 ) && 'ab_mcp_builder_path_needs_hash' === $r6->get_error_code(), 'W22 a position id needs the layout_hash to apply' );
$js = null;
foreach ( sc_outline( get_post( 200 ) ) as $el ) {
	if ( 'vc_raw_js' === $el['type'] ) { $js = $el; }
}
$r7 = sc_apply( get_post( 200 ), $js['id'], array( 'text' => 'x' ) );
ok( is_wp_error( $r7 ) && 'ab_mcp_builder_locked' === $r7->get_error_code(), 'W23 [vc_raw_js] is locked (plan §8)' );
ok( $before === sc_content( 200 ) && $points === count( get_option( AB_MCP_Undo::OPTION, array() ) ), 'W24 none of the refusals wrote anything or left an undo point' );

// A field the element does not carry yet: the attribute is added.
sc_page( 201, '[vc_row][vc_column][vc_btn title="Los" style="modern"][/vc_column][/vc_row]' );
$b2 = sc_el( get_post( 201 ), 'vc_btn' );
$r  = sc_apply( get_post( 201 ), $b2['id'], array( 'url' => '/kontakt' ) );
ok( is_array( $r ) && '[vc_row][vc_column][vc_btn title="Los" style="modern" link="url:%2Fkontakt"][/vc_column][/vc_row]' === sc_content( 201 ) && '/kontakt' === sc_val( get_post( 201 ), 'vc_btn', 'url' ), 'W25 a link the button lacks is added as an attribute, after the others' );
sc_undo( $r['undo_token'] );
ok( '[vc_row][vc_column][vc_btn title="Los" style="modern"][/vc_column][/vc_row]' === sc_content( 201 ), 'W26 and taken away again by wp_undo' );

// A heading whose text comes from the post title (source="post_title"): not written.
sc_page( 202, '[vc_row][vc_column][vc_custom_heading text="Alt" source="post_title"][/vc_column][/vc_row]' );
$h2 = sc_el( get_post( 202 ), 'vc_custom_heading' );
$r  = sc_apply( get_post( 202 ), $h2['id'], array( 'text' => 'Neu' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_field_locked' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'post title' ), 'W27 a heading that shows the post title is not written; the answer says why' );
// A "safe"-encoded WPBakery value (#E-8_…): not written.
sc_page( 203, '[vc_row][vc_column][vc_btn title="#E-8_JTNDYiUzRQ==" link="url:%2F"][/vc_column][/vc_row]' );
$b3 = sc_el( get_post( 203 ), 'vc_btn' );
$r  = sc_apply( get_post( 203 ), $b3['id'], array( 'text' => 'Neu' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_field_locked' === $r->get_error_code(), 'W28 an encoded #E-8_ value is not written' );

// The writer's own lock: a profile filter that describes [vc_raw_html] does not open it.
sc_reset();
add_filter(
	'ab_mcp_builder_shortcode_profiles',
	static function ( $profiles ) {
		foreach ( $profiles as $k => $pr ) {
			if ( 'wpbakery' === $pr['builder'] ) {
				$profiles[ $k ]['tags']['vc_raw_html'] = array( 'fields' => array( 'text' => array( 'kind' => 'html', 'from' => 'content' ) ) );
			}
		}
		return $profiles;
	}
);
AB_MCP_Builders::reset();
AB_MCP_Builder_Writers::reset();
sc_page( 204, '[vc_row][vc_column][vc_raw_html]JTNDcCUzRWElM0MlMkZwJTNF[/vc_raw_html][/vc_column][/vc_row]' );
$rh = sc_el( get_post( 204 ), 'vc_raw_html' );
$r  = null === $rh ? null : sc_apply( get_post( 204 ), $rh['id'], array( 'text' => '<p>x</p>' ) );
ok( null !== $rh && isset( $rh['fields']['text'] ) && is_wp_error( $r ) && 'ab_mcp_builder_locked' === $r->get_error_code(), 'W29 [vc_raw_html] stays locked in the writer even where a profile filter describes it (plan §8)' );
ok( '[vc_row][vc_column][vc_raw_html]JTNDcCUzRWElM0MlMkZwJTNF[/vc_raw_html][/vc_column][/vc_row]' === sc_content( 204 ), 'W30 and is not written' );
$rr = sc_writer( get_post( 204 ) )->replace( get_post( 204 ), 'JTNDcCUz', 'XX', array( 'apply' => true ) );
ok( is_wp_error( $rr ) && 'ab_mcp_builder_nothing' === $rr->get_error_code() && 1 === $rr->get_error_data()['locked_matches'], 'W31 replace leaves it too and counts the match as locked' );
sc_reset();

/* ======================================== K) the kses guard and the save */

sc_reset();
$kpage = '[vc_row][vc_column]' . WPB_HEADING . "\n[vc_column_text]<p>Video</p><iframe src=\"https://video.example/1\"></iframe>[/vc_column_text][/vc_column][/vc_row]";
sc_page( 210, $kpage );
$GLOBALS['ab_caps']['unfiltered_html'] = false;
add_filter( 'content_save_pre', 'ab_test_kses' );
$kh  = sc_el( get_post( 210 ), 'vc_custom_heading' );
$pre = sc_writer( get_post( 210 ) )->set_fields( get_post( 210 ), $kh['id'], array( 'text' => 'Neu' ), array() );
ok( is_array( $pre ) && 1 === count( array_filter( $pre['warnings'], static function ( $w ) { return 0 === strpos( $w, 'Applying will be refused:' ); } ) ), 'K1 for an account without unfiltered_html the preview warns that WordPress would filter the rest of the page' );
$calls = $GLOBALS['ab_update_post_calls'];
$r     = sc_apply( get_post( 210 ), $kh['id'], array( 'text' => 'Neu' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_kses' === $r->get_error_data()['cause'] && $kpage === sc_content( 210 ) && $calls === $GLOBALS['ab_update_post_calls'], 'K2 and applying is refused before WordPress saves: the iframe elsewhere stays' );
ok( null === $r->get_error_data()['undo_token'] && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'K3 the undo point of a change that wrote nothing is dropped' );
// Only how characters are written differs (a bare & in another attribute): written, as WordPress stores it.
sc_page( 211, '[vc_row][vc_column]' . WPB_HEADING . '[vc_btn title="Preise & Angebote" link="url:%2F"][/vc_column][/vc_row]' );
$kh2 = sc_el( get_post( 211 ), 'vc_custom_heading' );
$r   = sc_apply( get_post( 211 ), $kh2['id'], array( 'text' => 'Neu' ) );
ok( is_array( $r ) && true === $r['applied'] && false !== strpos( sc_content( 211 ), 'text="Neu"' ) && false !== strpos( sc_content( 211 ), 'title="Preise &amp; Angebote"' ), 'K4 where kses only writes & as &amp;, the change is saved as WordPress stores it' );
ok( 1 === count( array_filter( $r['notes'], static function ( $n ) { return false !== strpos( $n, 'HTML entities' ); } ) ), 'K5 and the answer says so' );
sc_reset();
// A save that fails: nothing changed, the undo point dropped.
sc_page( 212, '[vc_row][vc_column]' . WPB_HEADING . '[/vc_column][/vc_row]' );
$GLOBALS['ab_update_post_fail'] = true;
$fh = sc_el( get_post( 212 ), 'vc_custom_heading' );
$r  = sc_apply( get_post( 212 ), $fh['id'], array( 'text' => 'Neu' ) );
ok( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'The page is unchanged' ) && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'K6 a failed save leaves the page and no undo point' );
$GLOBALS['ab_update_post_fail'] = false;
// The builder (or another plugin) rewrites the page on save_post: the read-back finds it and puts the page back.
sc_page( 213, '[vc_row][vc_column]' . WPB_HEADING . '[/vc_column][/vc_row]' );
add_action(
	'save_post',
	static function ( $id ) {
		$GLOBALS['db_posts'][ (int) $id ]['post_content'] = str_replace( 'text="Neu"', 'text="Anders"', $GLOBALS['db_posts'][ (int) $id ]['post_content'] );
	}
);
$rb = sc_el( get_post( 213 ), 'vc_custom_heading' );
$r  = sc_apply( get_post( 213 ), $rb['id'], array( 'text' => 'Neu' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_write_failed' === $r->get_error_code() && '[vc_row][vc_column]' . WPB_HEADING . '[/vc_column][/vc_row]' === sc_content( 213 ), 'K7 a save stored differently from what was written is caught and the page put back' );
ok( true === $r->get_error_data()['rolled_back'], 'K8 the answer says it was put back' );
sc_reset();
// Somebody saves the page between preparing and writing: nothing of this change is written.
sc_page( 214, '[vc_row][vc_column]' . WPB_HEADING . '[/vc_column][/vc_row]' );
$ch = sc_el( get_post( 214 ), 'vc_custom_heading' );
add_filter(
	'ab_mcp_pro_builder_derived_meta',
	static function ( $keys ) {
		// Runs while the undo point is taken, after the change was prepared.
		$GLOBALS['db_posts'][214]['post_content'] = str_replace( 'Über uns', 'Über uns (Editor)', $GLOBALS['db_posts'][214]['post_content'] );
		return $keys;
	}
);
$r = sc_apply( get_post( 214 ), $ch['id'], array( 'text' => 'Neu' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_layout_changed' === $r->get_error_data()['cause'] && false !== strpos( sc_content( 214 ), 'text="Über uns (Editor)"' ), 'K9 a page saved by someone else while the change was prepared is not overwritten' );
sc_reset();
// The same save a moment later, after the undo point took the page: not put
// back by the rollback either, and no undo point is left that would undo it.
sc_page( 216, '[vc_row][vc_column]' . WPB_HEADING . '[/vc_column][/vc_row]' );
$ch2                        = sc_el( get_post( 216 ), 'vc_custom_heading' );
$GLOBALS['ab_foreign_once'] = true;
add_filter(
	'ab_mcp_undo_snapshot_max',
	static function ( $v ) {
		if ( ! empty( $GLOBALS['ab_foreign_once'] ) ) {
			$GLOBALS['ab_foreign_once']               = false;
			$GLOBALS['db_posts'][216]['post_content'] = str_replace( 'Über uns', 'Über uns (Editor)', $GLOBALS['db_posts'][216]['post_content'] );
		}
		return $v;
	}
);
$r = sc_apply( get_post( 216 ), $ch2['id'], array( 'text' => 'Neu' ) );
remove_all_filters( 'ab_mcp_undo_snapshot_max' );
ok( false === $GLOBALS['ab_foreign_once'] && is_wp_error( $r ) && false !== strpos( sc_content( 216 ), 'text="Über uns (Editor)"' ) && false === strpos( sc_content( 216 ), 'text="Neu"' ), 'K9b saved by someone else after the undo point took the page: their save stays, nothing of this change is written' );
ok( is_wp_error( $r ) && null === $r->get_error_data()['undo_token'] && false === $r->get_error_data()['rolled_back'] && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'K9c and no undo point is left that would undo their save' );
sc_reset();

// Backslashes elsewhere on the page survive the save (WordPress unslashes what it is given).
sc_page( 215, '[vc_row][vc_column]' . WPB_HEADING . "\n[vc_column_text]<p>Ordner C:\\Daten\\neu und \\u0026</p>[/vc_column_text][/vc_column][/vc_row]" );
$sh = sc_el( get_post( 215 ), 'vc_custom_heading' );
$r  = sc_apply( get_post( 215 ), $sh['id'], array( 'text' => 'Neu' ) );
ok( is_array( $r ) && true === $r['applied'] && false !== strpos( sc_content( 215 ), '<p>Ordner C:\\Daten\\neu und \\u0026</p>' ), 'K10 a backslash elsewhere on the page is stored as it was (wp_slash before wp_update_post)' );
sc_reset();

/* ======================================================= D) Divi 4 */

$divi = DIVI_PAGE . "\n" . DIVI_BUTTON . "\n" . DIVI_IMAGE . "\n" . '[et_pb_code _builder_version="4.27"]%3Cscript%3E[/et_pb_code]' . "\n" . '[et_pb_text global_module="77"]<p>Global</p>[/et_pb_text]' . "\n" . '[et_pb_button button_text="@ET-DC@eyJkeW5hbWljIjp0cnVlfQ==@" button_url="/x"][/et_pb_button]';
sc_page( 220, $divi, array( '_et_pb_use_builder' => 'on' ) );
$db = sc_el( get_post( 220 ), 'et_pb_button' );
$r  = sc_apply( get_post( 220 ), $db['id'], array( 'text' => 'Preise "jetzt" [neu] C:\\' ) );
ok( is_array( $r ) && false !== strpos( sc_content( 220 ), 'button_text="Preise %22jetzt%22 %91neu%93 C:%92"' ) && 'Preise "jetzt" [neu] C:\\' === sc_val( get_post( 220 ), 'et_pb_button', 'text' ), 'D1 Divi 4: the button text in %22 %91 %93 %92, read back as sent' );
ok( str_replace( 'button_text="Preise ansehen"', 'button_text="Preise %22jetzt%22 %91neu%93 C:%92"', $divi ) === sc_content( 220 ), 'D2 every other byte as before' );
sc_undo( $r['undo_token'] );
$di = sc_el( get_post( 220 ), 'et_pb_image' );
$r  = sc_apply( get_post( 220 ), $di['id'], array( 'image' => 55 ) );
ok( is_array( $r ) && false !== strpos( sc_content( 220 ), '[et_pb_image src="http://x/u/bild.jpg" alt="Unser Team"' ) && 'http://x/u/bild.jpg' === sc_val( get_post( 220 ), 'et_pb_image', 'image' ), 'D3 Divi keeps images by address: image_id writes the attachment\'s address' );
sc_undo( $r['undo_token'] );
$cta = sc_el( get_post( 220 ), 'et_pb_cta' );
$r   = sc_apply( get_post( 220 ), $cta['id'], array( 'text' => '<p>Neuer Text</p>', 'title' => 'Wir' ) );
ok( is_array( $r ) && false !== strpos( sc_content( 220 ), '[et_pb_cta title="Wir" button_text="Mehr erfahren" …]<p>Neuer Text</p>[/et_pb_cta]' ), 'D4 a call to action: title attribute and the text between the tags in one change' );
sc_undo( $r['undo_token'] );
$code = null;
foreach ( sc_outline( get_post( 220 ) ) as $el ) {
	if ( 'et_pb_code' === $el['type'] ) { $code = $el; }
}
$r = sc_apply( get_post( 220 ), $code['id'], array( 'text' => 'x' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_locked' === $r->get_error_code(), 'D5 the code module is locked (plan §8)' );
$dyn = sc_el( get_post( 220 ), 'et_pb_button', 1 );
$r   = sc_apply( get_post( 220 ), $dyn['id'], array( 'text' => 'Fest' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_field_locked' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'dynamic content' ), 'D6 a Divi Dynamic Content value is not written; the answer says why' );
$gl = sc_el( get_post( 220 ), 'et_pb_text' );
$r  = sc_writer( get_post( 220 ) )->set_fields( get_post( 220 ), $gl['id'], array( 'text' => '<p>Lokal</p>' ), array() );
ok( is_array( $r ) && 1 === count( array_filter( $r['warnings'], static function ( $w ) { return false !== strpos( $w, 'Divi Library item 77' ); } ) ), 'D7 a global module warns that its synced fields come from the library item' );
ok( $divi === sc_content( 220 ), 'D8 the Divi page is as it was after all undos' );

/* ========================================================= A) Avada */

sc_reset();
$avada = AVADA_PAGE . "\n" . '[fusion_text]<p>Hallo {post_title}, {post_terms,type:category,separator:|}</p>[/fusion_text]' . "\n" . '[fusion_code]PHNjcmlwdD4=[/fusion_code]';
sc_page( 230, $avada );
$at = sc_el( get_post( 230 ), 'fusion_title' );
$r  = sc_apply( get_post( 230 ), $at['id'], array( 'text' => 'Über uns & Team <3' ) );
ok( is_array( $r ) && false !== strpos( sc_content( 230 ), '[fusion_title …]Über uns &amp; Team &lt;3[/fusion_title]' ) && 'Über uns & Team <3' === sc_val( get_post( 230 ), 'fusion_title', 'text' ), 'A1 Avada title: plain text between the tags, & and < as references' );
sc_undo( $r['undo_token'] );
$ab = sc_el( get_post( 230 ), 'fusion_button' );
$r  = sc_apply( get_post( 230 ), $ab['id'], array( 'text' => 'Schreiben Sie uns', 'url' => 'mailto:info@example.com' ) );
ok( is_array( $r ) && false !== strpos( sc_content( 230 ), '[fusion_button link="mailto:info@example.com" target="_self" …]Schreiben Sie uns[/fusion_button]' ), 'A2 Avada button: link attribute and label between the tags' );
sc_undo( $r['undo_token'] );
$ph = sc_el( get_post( 230 ), 'fusion_text', 1 );
$r1 = sc_apply( get_post( 230 ), $ph['id'], array( 'text' => '<p>Hallo {post_title}</p>' ) );
$r2 = sc_apply( get_post( 230 ), $ph['id'], array( 'text' => '<p>Hallo</p>' ) );
$r3 = sc_apply( get_post( 230 ), $ph['id'], array( 'text' => '<p>Grüezi {post_title}, {post_terms,type:category,separator:|}</p>' ) );
ok( is_wp_error( $r1 ) && 'ab_mcp_builder_placeholder' === $r1->get_error_code() && false !== strpos( $r1->get_error_message(), '{post_terms,type:category,separator:|}' ), 'A3 Avada\'s Inline Dynamic Data with parameters ({post_terms,…}) is kept: the profile\'s placeholders count' );
ok( is_wp_error( $r2 ) && 'ab_mcp_builder_placeholder' === $r2->get_error_code(), 'A4 so is {post_title}' );
ok( is_array( $r3 ) && true === $r3['applied'], 'A5 a new text that keeps them is written' );
sc_undo( $r3['undo_token'] );
$code = null;
foreach ( sc_outline( get_post( 230 ) ) as $el ) {
	if ( 'fusion_code' === $el['type'] ) { $code = $el; }
}
$r = sc_apply( get_post( 230 ), $code['id'], array( 'text' => 'x' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_locked' === $r->get_error_code() && $avada === sc_content( 230 ), 'A6 the Code Block is locked; the page is as it was' );

/* ======================================================= F) Flatsome */

sc_reset();
$fs = "<p>Einleitung vor dem Abschnitt</p>\n[section]\n[row]\n" . FS_COL . "\n[col span=\"6\"]Text davor " . FS_TITLE . ' ' . FS_BUTTON . " Text danach[/col]\n[/row]\n[/section]\n" . FS_BLOCK . "\n[ux_html]<script>alert(1)</script>[/ux_html]";
sc_page( 240, $fs );
$ft = sc_el( get_post( 240 ), 'title' );
$r  = sc_apply( get_post( 240 ), $ft['id'], array( 'text' => 'Wir sagen "Danke"' ) );
ok( is_array( $r ) && false !== strpos( sc_content( 240 ), '[title style="center" text="Wir sagen &quot;Danke&quot;" tag_name="h2"]' ) && 'Wir sagen "Danke"' === sc_val( get_post( 240 ), 'title', 'text' ), 'F1 Flatsome (no documented encoding): a quote as &quot;, read back as sent' );
sc_undo( $r['undo_token'] );
$intro = sc_el( get_post( 240 ), '#text' );
$r     = sc_apply( get_post( 240 ), $intro['id'], array( 'text' => '<p>Neue Einleitung</p>' ) );
ok( is_array( $r ) && 0 === strpos( sc_content( 240 ), "<p>Neue Einleitung</p>\n[section]" ), 'F2 HTML between the shortcodes at the top of the page is an element of its own and can be written' );
sc_undo( $r['undo_token'] );
$mixed = sc_el( get_post( 240 ), 'col', 1 );
$r     = sc_apply( get_post( 240 ), $mixed['id'], array( 'text' => 'Neu' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_field_locked' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'wp_search_replace' ), 'F3 a column that holds shortcodes and text: no single place for a new text, the answer names replace' );
$blk = null;
foreach ( sc_outline( get_post( 240 ) ) as $el ) {
	if ( 'block' === $el['type'] ) { $blk = $el; }
}
$r = sc_apply( get_post( 240 ), $blk['id'], array( 'text' => 'x' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_locked' === $r->get_error_code() && $fs === sc_content( 240 ), 'F4 a UX Block (global) is locked; the page is as it was' );

/* ===================================================== N) Enfold (meta) */

sc_reset();
$layout = ENF_HEADING . "\n\n" . ENF_TEXT . "\n\n" . ENF_BUTTON . "\n\n" . ENF_IMAGE . "\n\n" . "[av_codeblock wrapper_element='' av_uid='av-code1']<script>x</script>[/av_codeblock]";
$stale  = '[av_textblock]Alte Kopie[/av_textblock]';
sc_enfold( 250, $layout, $stale );
$en = sc_writer( get_post( 250 ) );
ok( $en instanceof AB_MCP_Builder_Writer_Shortcodes && 'enfold' === $en->id(), 'N1 an Enfold page has the shortcode writer for enfold' );
$r = $en->set_fields( get_post( 250 ), 'av-mb1x2y', array( 'heading' => "Peter's Café" ), array( 'apply' => true ) );
ok( is_array( $r ) && true === $r['applied'], 'N2 the builder\'s own element id (av_uid) needs no layout_hash' );
$new = str_replace( "heading='Über uns'", "heading='Peter’s Café'", $layout );
ok( $new === sc_meta( 250, '_aviaLayoutBuilderCleanData' ), 'N3 the layout meta holds the change, the apostrophe as Enfold writes it (’); every other byte as before' );
ok( $new === sc_content( 250 ), 'N4 post_content holds the same text, as Enfold\'s builder keeps it' );
ok( 'Peter’s Café' === sc_val( get_post( 250 ), 'av_heading', 'heading' ) && true === $r['readback']['ok'], 'N5 read back from the meta' );
$entry = AB_MCP_Undo::entry_for( $r['undo_token'] );
$c     = AB_MCP_Undo::claim( $r['undo_token'] );
ok( isset( $c['data']['posts'][0]['locs']['meta:_aviaLayoutBuilderCleanData'], $c['data']['posts'][0]['locs']['post_content'] ) && 1 === count( get_option( AB_MCP_Undo::OPTION, array() ) ), 'N6 one undo point holds the meta and the copy' );
$u = sc_undo( $r['undo_token'] );
ok( $layout === sc_meta( 250, '_aviaLayoutBuilderCleanData' ) && $stale === sc_content( 250 ), 'N7 wp_undo puts both back byte for byte, the stale copy included' );
// Button: an internal link becomes an address of its own.
$r = $en->set_fields( get_post( 250 ), 'av-mb1x30', array( 'url' => 'https://beispiel.ch/kontakt/', 'text' => 'Offerte' ), array( 'apply' => true ) );
ok( is_array( $r ) && false !== strpos( sc_meta( 250, '_aviaLayoutBuilderCleanData' ), "[av_button label='Offerte' link='manually,https://beispiel.ch/kontakt/' link_target=''" ), 'N8 an Enfold link is written as manually,<address>' );
sc_undo( $r['undo_token'] );
// Image: the attachment, and the address copy with it.
$r = $en->set_fields( get_post( 250 ), 'av-mb1x31', array( 'image' => 55 ), array( 'apply' => true ) );
ok( is_array( $r ) && false !== strpos( sc_meta( 250, '_aviaLayoutBuilderCleanData' ), "[av_image src='http://x/u/bild.jpg' attachment='55' attachment_size='full'" ), 'N9 the image takes the attachment, and src follows it' );
ok( 2 === count( $r['changes'] ) && 'src' === $r['changes'][1]['field'], 'N10 the answer shows the address copy as a change of its own' );
sc_undo( $r['undo_token'] );
$r = $en->set_fields( get_post( 250 ), 'av-code1', array( 'text' => 'x' ), array( 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_locked' === $r->get_error_code() && $layout === sc_meta( 250, '_aviaLayoutBuilderCleanData' ), 'N11 the code block is locked' );
// The text block's text, with an apostrophe: content keeps it straight (enfold.md d)).
$r = $en->set_fields( get_post( 250 ), 'av-mb1x2z', array( 'text' => "<p>Peter's Angebot</p>" ), array( 'apply' => true ) );
ok( is_array( $r ) && false !== strpos( sc_meta( 250, '_aviaLayoutBuilderCleanData' ), "[/av_heading]\n\n[av_textblock size='' font_color='' color='' av_uid='av-mb1x2z' custom_class='' admin_preview_bg='']\n<p>Peter's Angebot</p>\n[/av_textblock]" ), 'N12 between the tags the apostrophe stays, the line breaks around the text too' );
sc_undo( $r['undo_token'] );
// The layout meta holds no shortcodes yet: the reader and Enfold's editor take post_content; the write fills both.
sc_enfold( 251, '', '[av_textblock av_uid=\'av-x1\']<p>Erste Fassung</p>[/av_textblock]' );
$r = sc_writer( get_post( 251 ) )->set_fields( get_post( 251 ), 'av-x1', array( 'text' => '<p>Zweite</p>' ), array( 'apply' => true ) );
ok( is_array( $r ) && "[av_textblock av_uid='av-x1']<p>Zweite</p>[/av_textblock]" === sc_meta( 251, '_aviaLayoutBuilderCleanData' ) && sc_meta( 251, '_aviaLayoutBuilderCleanData' ) === sc_content( 251 ), 'N13 a page read from post_content: the new text goes into the layout meta and the copy, as Enfold\'s editor does' );
sc_undo( $r['undo_token'] );
ok( '' === sc_meta( 251, '_aviaLayoutBuilderCleanData' ) && "[av_textblock av_uid='av-x1']<p>Erste Fassung</p>[/av_textblock]" === sc_content( 251 ), 'N14 wp_undo puts both back' );
// Enfold's own placeholders ({wp_post_title}, {av_dynamic_el …}).
sc_enfold( 252, "[av_heading heading='Willkommen {av_dynamic_el src=\"wp_post\" key=\"title\"}' av_uid='av-d1'][/av_heading]" );
$r = sc_writer( get_post( 252 ) )->set_fields( get_post( 252 ), 'av-d1', array( 'heading' => 'Willkommen' ), array( 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_placeholder' === $r->get_error_code(), 'N15 Enfold\'s dynamic element in a heading is kept' );

// Backslashes in the layout meta survive too (update_post_meta() unslashes).
sc_enfold( 253, "[av_textblock av_uid='av-b1']<p>C:\\Daten \\u0026</p>[/av_textblock]\n\n[av_heading heading='Alt' av_uid='av-b2'][/av_heading]" );
$r = sc_writer( get_post( 253 ) )->set_fields( get_post( 253 ), 'av-b2', array( 'heading' => 'Neu' ), array( 'apply' => true ) );
ok( is_array( $r ) && "[av_textblock av_uid='av-b1']<p>C:\\Daten \\u0026</p>[/av_textblock]\n\n[av_heading heading='Neu' av_uid='av-b2'][/av_heading]" === sc_meta( 253, '_aviaLayoutBuilderCleanData' ), 'N16 a backslash in the layout meta is stored as it was (wp_slash before update_post_meta)' );

/* ===================================================== R) replace() */

sc_reset();
$rp = '[vc_row ' . WPB_CSS . '][vc_column]' . WPB_HEADING . "\n" . WPB_TEXT . "\n" . WPB_BUTTON . "\n" . WPB_IMAGE . "\n" . '[vc_custom_heading text="Bild: https://beispiel.ch/b.jpg"]' . "\n" . '[vc_raw_js]beispiel.ch[/vc_raw_js][vc_btn title="x" link="url:%2F" css=".a{background:url(https://beispiel.ch/bg.png)}"][/vc_column][/vc_row]';
sc_page( 260, $rp );
$rw  = sc_writer( get_post( 260 ) );
$pre = $rw->replace( get_post( 260 ), 'beispiel.ch', 'example.org', array( 'scope' => 'text' ) );
ok( is_array( $pre ) && false === $pre['applied'] && $rp === sc_content( 260 ) && sc_unmeasured( $pre['warnings'] ), 'R1 replace previews first, with the «not measured» warning' );
ok( 3 === count( $pre['changes'] ) && 3 === $pre['matches'], 'R2 three fields hold the address: the button link, the image link, the heading text' );
ok( 2 === $pre['locked_matches'], 'R3 the matches in the code element and in a css attribute count as locked (' . $pre['locked_matches'] . ')' );
$r = $rw->replace( get_post( 260 ), 'beispiel.ch', 'example.org', array( 'apply' => true, 'layout_hash' => $pre['layout_hash'], 'scope' => 'text' ) );
$c = sc_content( 260 );
ok( is_array( $r ) && true === $r['applied'] && true === $r['readback']['ok'], 'R4 applied and read back' );
ok( false !== strpos( $c, 'link="url:https%3A%2F%2Fexample.org%2Fpreise%2F|title:Preise|target:_blank|rel:nofollow"' ) && false !== strpos( $c, 'link="https://example.org/ueber-uns/"' ) && false !== strpos( $c, 'text="Bild: https://example.org/b.jpg"' ), 'R5 each in its encoding: the WPBakery link rawurlencoded, the image link and the heading as they are' );
ok( false !== strpos( $c, '[vc_raw_js]beispiel.ch[/vc_raw_js]' ) && false !== strpos( $c, 'background:url(https://beispiel.ch/bg.png)' ), 'R6 the code element and the css attributes are left as they are' );
sc_undo( $r['undo_token'] );
ok( $rp === sc_content( 260 ), 'R7 wp_undo takes the whole replacement back' );
// «Preise & Angebote» is stored as «Preise &amp; Angebote» in the text block.
$r = $rw->replace( get_post( 260 ), 'Preise & Angebote', 'Preise & Rabatte', array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 260 ) ) ) );
ok( is_array( $r ) && false !== strpos( sc_content( 260 ), '<p>Wir sind Ihr Partner für Preise &amp; Rabatte.</p>' ), 'R8 a text with & is found as HTML stores it, and replaced in the same form' );
sc_undo( $r['undo_token'] );
// Enfold: «Peter's» is stored as «Peter’s» in an attribute.
sc_enfold( 261, "[av_heading heading='Peter’s Café' av_uid='av-p1']Peter's Unterzeile[/av_heading]" );
$r = sc_writer( get_post( 261 ) )->replace( get_post( 261 ), "Peter's", "Anna's", array( 'apply' => true ) );
ok( is_array( $r ) && "[av_heading heading='Anna’s Café' av_uid='av-p1']Anna's Unterzeile[/av_heading]" === sc_meta( 261, '_aviaLayoutBuilderCleanData' ) && 2 === $r['matches'], 'R9 Enfold: the apostrophe is found as ’ in the attribute and as \' between the tags' );
ok( sc_meta( 261, '_aviaLayoutBuilderCleanData' ) === sc_content( 261 ), 'R10 and the copy in post_content follows' );
// Flatsome: the text runs of a column that holds shortcodes too.
sc_page( 262, "[section]\n[row]\n[col span=\"6\"]Text davor " . FS_TITLE . " Text danach[/col]\n[/row]\n[/section]" );
$r = sc_writer( get_post( 262 ) )->replace( get_post( 262 ), 'Text', 'Satz', array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 262 ) ) ) );
ok( is_array( $r ) && "[section]\n[row]\n[col span=\"6\"]Satz davor " . FS_TITLE . " Satz danach[/col]\n[/row]\n[/section]" === sc_content( 262 ), 'R11 a column with shortcodes in it: each text run replaced, the title in between untouched' );
// No writable match, and a replacement that would add a shortcode.
$r1 = sc_writer( get_post( 262 ) )->replace( get_post( 262 ), 'nirgends', 'x', array( 'apply' => true ) );
$r2 = sc_writer( get_post( 262 ) )->replace( get_post( 262 ), 'Satz davor', '[ux_html]x[/ux_html]', array( 'apply' => true ) );
ok( is_wp_error( $r1 ) && 'ab_mcp_builder_nothing' === $r1->get_error_code() && 0 === $r1->get_error_data()['locked_matches'], 'R12 no match: nothing to replace, zero locked matches' );
ok( is_wp_error( $r2 ) && 'ab_mcp_builder_nothing' === $r2->get_error_code() && false !== strpos( implode( ' ', $r2->get_error_data()['notes'] ), 'shortcode' ), 'R13 a replacement that would add a shortcode is left out, with the reason' );
// wp_search_replace passes case_insensitive and regex (Enfold pages come here in scope text).
sc_page( 263, $rp );
$ci = sc_writer( get_post( 263 ) )->replace( get_post( 263 ), 'BEISPIEL.ch', 'example.org', array( 'scope' => 'text', 'case_insensitive' => true ) );
ok( is_array( $ci ) && 3 === count( $ci['changes'] ) && 3 === $ci['matches'] && 2 === $ci['locked_matches'], 'R14 case_insensitive: «BEISPIEL.ch» finds what R2 and R3 find (' . ( is_array( $ci ) ? count( $ci['changes'] ) . '/' . $ci['locked_matches'] : 'error' ) . ')' );
ok( is_array( $ci ) && 3 === count( array_filter( $ci['changes'], static function ( $c ) { return false !== strpos( (string) $c['after'], 'example.org' ) && false === stripos( (string) $c['after'], 'beispiel.ch' ); } ) ), 'R14b and each of them takes the replacement' );
$cs = sc_writer( get_post( 263 ) )->replace( get_post( 263 ), 'BEISPIEL.ch', 'example.org', array( 'scope' => 'text' ) );
ok( is_wp_error( $cs ) && 'ab_mcp_builder_nothing' === $cs->get_error_code() && 0 === $cs->get_error_data()['locked_matches'], 'R15 without it, nothing' );
sc_enfold( 264, "[av_heading heading='Peter’s Café' av_uid='av-p1']PETER's Unterzeile[/av_heading]" );
$r = sc_writer( get_post( 264 ) )->replace( get_post( 264 ), "peter's", "Anna's", array( 'apply' => true, 'case_insensitive' => true ) );
ok( is_array( $r ) && "[av_heading heading='Anna’s Café' av_uid='av-p1']Anna's Unterzeile[/av_heading]" === sc_meta( 264, '_aviaLayoutBuilderCleanData' ) && 2 === $r['matches'], 'R16 Enfold, case_insensitive: each form of the text, however spelt, takes the replacement in its own form' );
ok( sc_meta( 264, '_aviaLayoutBuilderCleanData' ) === sc_content( 264 ), 'R17 and the copy in post_content follows' );
$r = sc_writer( get_post( 263 ) )->replace( get_post( 263 ), 'beispiel\.ch', 'example.org', array( 'apply' => true, 'regex' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_regex' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'scope: raw' ) && $rp === sc_content( 263 ), 'R18 regex is refused with the way (scope: raw), nothing written' );

/* ========================================== X) more cases and the tool */

sc_reset();
// A text block with an embed: the outline does not show it, so it is not rewritten whole.
sc_page( 280, '[vc_row][vc_column][vc_column_text]<p>Video</p><iframe src="https://video.example/1"></iframe>[/vc_column_text][/vc_column][/vc_row]' );
$xe = sc_el( get_post( 280 ), 'vc_column_text' );
$r  = sc_apply( get_post( 280 ), $xe['id'], array( 'text' => '<p>Neu</p>' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_field_locked' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'embedded' ) && false !== strpos( sc_content( 280 ), '<iframe' ), 'X1 a text that holds an embed is not replaced whole; the answer names the way' );
// An unquoted attribute value (WordPress reads name=value too): written in quotes.
sc_page( 281, "[section]\n[row]\n[col span=6][button text=Los link=/kontakt][/col]\n[/row]\n[/section]" );
$xb = sc_el( get_post( 281 ), 'button' );
$r  = sc_apply( get_post( 281 ), $xb['id'], array( 'text' => 'Jetzt los' ) );
ok( is_array( $r ) && false !== strpos( sc_content( 281 ), '[button text="Jetzt los" link=/kontakt]' ) && 'Jetzt los' === sc_val( get_post( 281 ), 'button', 'text' ), 'X2 an unquoted value is written in quotes, the next attribute untouched' );
// The answer says how Enfold stores an apostrophe.
sc_enfold( 282, "[av_button label='Los' link='' av_uid='av-n1']" );
$r = sc_writer( get_post( 282 ) )->set_fields( get_post( 282 ), 'av-n1', array( 'text' => "Peter's Angebot" ), array() );
ok( is_array( $r ) && 1 === count( array_filter( $r['notes'], static function ( $n ) { return false !== strpos( $n, 'Peter’s Angebot' ); } ) ), 'X3 the preview says the label is stored with ’, as Enfold writes it' );
// A field set to the value it holds: no save, no undo point (as the block and settings writers answer).
sc_reset();
sc_page( 284, '[vc_row][vc_column]' . WPB_HEADING . "\n" . WPB_BUTTON . '[/vc_column][/vc_row]' );
$xh    = sc_el( get_post( 284 ), 'vc_custom_heading' );
$xb    = sc_el( get_post( 284 ), 'vc_btn' );
$c0    = sc_content( 284 );
$calls = $GLOBALS['ab_update_post_calls'];
$r     = sc_apply( get_post( 284 ), $xh['id'], array( 'text' => 'Über uns' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_nothing' === $r->get_error_code() && $calls === $GLOBALS['ab_update_post_calls'] && $c0 === sc_content( 284 ) && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'X7 a heading set to the text it has: said so; wp_update_post() not called, no undo point' );
$r = sc_apply( get_post( 284 ), $xb['id'], array( 'text' => (string) $xb['fields']['text']['value'], 'url' => (string) $xb['fields']['url']['value'] ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_nothing' === $r->get_error_code() && $calls === $GLOBALS['ab_update_post_calls'], 'X8 a button whose label and link already hold these values (the link in vc_link form): no save' );
$r = sc_apply( get_post( 284 ), $xh['id'], array( 'text' => 'Über uns neu' ) );
ok( is_array( $r ) && true === $r['applied'] && $calls + 1 === $GLOBALS['ab_update_post_calls'], 'X9 (a real change is still written)' );
// A value that differs, but that the builder's encoding stores as the bytes
// already there (a text between tags is written trimmed): no change either.
sc_reset();
sc_page( 285, AVADA_PAGE );
$xa    = sc_el( get_post( 285 ), 'fusion_title' );
$c0    = sc_content( 285 );
$calls = $GLOBALS['ab_update_post_calls'];
$r     = sc_apply( get_post( 285 ), $xa['id'], array( 'text' => 'Über uns ' ) );
ok( is_wp_error( $r ) && 'ab_mcp_builder_nothing' === $r->get_error_code() && $calls === $GLOBALS['ab_update_post_calls'] && $c0 === sc_content( 285 ) && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'X10 «Über uns » between the tags of an Avada title is stored trimmed, as it stands: no save, no undo point' );

// Match counts only for those who may edit the post.
$GLOBALS['ab_caps']['edit_post:262'] = false;
$r = sc_writer( get_post( 262 ) )->replace( get_post( 262 ), 'nirgends', 'x', array() );
ok( is_wp_error( $r ) && 'ab_mcp_forbidden' === $r->get_error_code(), 'X4 replace tells an account that cannot edit the post nothing about it' );
$GLOBALS['ab_caps']['edit_post:262'] = true;

// The tool: wp_update_builder_element on a shortcode page.
abstract class AB_MCP_Tools_Base {
	protected static function need( $a, array $keys ) {
		foreach ( $keys as $k ) {
			if ( ! isset( $a[ $k ] ) ) { return new WP_Error( 'ab_mcp_missing', $k ); }
		}
		return true;
	}
	protected static function s( $a, $k, $d = '' ) { return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : $d; }
	protected static function i( $a, $k, $d = 0 ) { return isset( $a[ $k ] ) ? (int) $a[ $k ] : $d; }
	protected static function b( $a, $k, $d = false ) { return isset( $a[ $k ] ) ? (bool) $a[ $k ] : $d; }
}
require_once AB_MCP_PRO_DIR . 'includes/tools/class-tools-builder-write.php';
sc_page( 283, '[vc_row][vc_column]' . WPB_BUTTON . '[/vc_column][/vc_row]' );
$xt  = sc_el( get_post( 283 ), 'vc_btn' );
$res = AB_MCP_Tools_Builder_Write::update_builder_element( array( 'id' => 283, 'element_id' => $xt['id'], 'fields' => array( 'text' => 'Zu den Angeboten' ), 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 283 ) ) ) );
ok( is_array( $res ) && 'wpbakery' === $res['builder'] && true === $res['applied'] && 'Angebote ansehen' === $res['element']['before']['text'] && 'Zu den Angeboten' === $res['element']['after']['text'] && sc_unmeasured( $res['warnings'] ) && null !== $res['undo_token'], 'X5 the tool answers with builder, element before and after, the undo token and the «not measured» warning' );
ok( false !== strpos( sc_content( 283 ), '[vc_btn title="Zu den Angeboten" link="url:https%3A%2F%2Fbeispiel.ch%2Fpreise%2F|title:Preise|target:_blank|rel:nofollow"]' ), 'X6 and the page holds the new label' );

/* ============================================ S) the structure check */

sc_reset();
sc_page( 270, '[vc_row][vc_column]' . WPB_HEADING . '[/vc_column][/vc_row]' );
$sw    = sc_writer( get_post( 270 ) );
$build = new ReflectionMethod( $sw, 'build' );
$pageM = new ReflectionMethod( $sw, 'page' );
if ( PHP_VERSION_ID < 80100 ) {
	// Needed to call a private method before PHP 8.1, deprecated since 8.5.
	if ( PHP_VERSION_ID < 80100 ) { $build->setAccessible( true ); }
	if ( PHP_VERSION_ID < 80100 ) { $pageM->setAccessible( true ); }
}
$pg = $pageM->invoke( $sw, get_post( 270 ) );
$at = strpos( $pg['text'], '[/vc_column]' );
$s1 = $build->invoke( $sw, $pg, array( array( $at, $at, '[vc_section][/vc_section]' ) ), array( 'changes' => array() ) );
$ub = strpos( $pg['text'], 'Über' );
$s2 = $build->invoke( $sw, $pg, array( array( $ub, $ub + strlen( 'Über' ), 'Hallo' ) ), array( 'changes' => array() ) );
ok( is_wp_error( $s1 ) && 'ab_mcp_builder_structure' === $s1->get_error_code(), 'S1 a text that would add a shortcode is caught before anything is written' );
ok( is_array( $s2 ) && false !== strpos( $s2['write']['text'], 'text="Hallo uns"' ) && hash( 'sha256', $pg['text'] ) === $s2['write']['sha'], 'S2 a change of a value passes it, with the hash of the text it was made on' );

ab_result( 'BUILDER-SHORTCODES' );
