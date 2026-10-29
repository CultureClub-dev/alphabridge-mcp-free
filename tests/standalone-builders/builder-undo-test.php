<?php
/**
 * Undo kind builder_data: byte-exact restore of every stored copy of a
 * builder page, refusal over a later change (force overrides), parts under
 * one token for many pages, and the size limit measured after compression.
 *
 * Run: php test/builder-undo-test.php   (after ./sync-free-core.sh <free>)
 */

require dirname( __DIR__ ) . '/standalone-lib/builder-harness.php';

// The undo tools need the base class of the core's tools; only its argument readers.
abstract class AB_MCP_Tools_Base {
	protected static function s( $a, $k, $d = '' ) { return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : $d; }
	protected static function i( $a, $k, $d = 0 ) { return isset( $a[ $k ] ) ? (int) $a[ $k ] : $d; }
	protected static function b( $a, $k, $d = false ) { return isset( $a[ $k ] ) ? (bool) $a[ $k ] : $d; }
}
require_once AB_MCP_PRO_DIR . 'includes/tools/class-tools-undo-core.php';

$GLOBALS['ab_caps'] = array( 'edit_posts' => true, 'edit_post:10' => true, 'edit_post:20' => true, 'edit_post:21' => true, 'edit_post:22' => true, 'edit_post:30' => true, 'edit_post:40' => true, 'manage_options' => true );
$adapter            = new AB_Test_Fake_Adapter();

/** Everything the tables hold for one post, meta ids included. */
function ab_dump( $id ) {
	$meta = array();
	foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
		if ( $r[0] === (int) $id ) { $meta[ $mid ] = array( $r[1], $r[2] ); }
	}
	ksort( $meta );
	return array( 'post' => $GLOBALS['db_posts'][ $id ], 'meta' => $meta );
}
function ab_set_meta( $mid, $value ) { $GLOBALS['db_meta'][ $mid ][2] = $value; }

/* ======================================== A) byte for byte */

$layout = json_encode( ab_fake_elements(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\xff\xfe";   // not valid UTF-8
$mid    = ab_fake_page( 10, ab_fake_elements(), array( 'post_content_filtered' => 'Kopie «gefiltert» a:1:{s:1:"x";s:1:"y";}' ) );
ab_set_meta( $mid, $layout );
$css1 = ab_meta_add( 10, 'stackable_optimized_css', '.a{color:red}' );
$raw1 = ab_meta_add( 10, 'stackable_optimized_css_raw', 'a:1:{s:1:"a";s:3:"one";}' );
$raw2 = ab_meta_add( 10, 'stackable_optimized_css_raw', 'second row' );
ab_meta_add( 10, '_fake_code', '<script>never</script>' );
$orig = ab_dump( 10 );

$post  = get_post( 10 );
$extra = array( 'post_content_filtered', 'meta:_fake_draft', 'meta:_fake_code' );
$item  = AB_MCP_Undo_Builder::snapshot( $post, $adapter, $extra );
$locs  = array_keys( $item['locs'] );
ok( array( 'meta:_fake_draft', 'meta:_fake_layout', 'meta:stackable_optimized_css', 'meta:stackable_optimized_css_raw', 'post_content', 'post_content_filtered' ) === $locs, 'A1 the snapshot covers the copies, the derived CSS meta and the writer\'s extra locs: ' . implode( ', ', $locs ) );
ok( ! isset( $item['locs']['meta:_fake_code'] ), 'A2 never a key the adapter locks (page code), even where a writer names it' );
ok( isset( $item['locs']['meta:_fake_layout']['rows'][0]['b64'] ) && ! isset( $item['locs']['meta:_fake_layout']['rows'][0]['value'] ), 'A3 a value that is not UTF-8 is kept as base64' );
ok( false === $item['locs']['meta:_fake_draft']['existed'], 'A4 a loc the write may create is kept as missing' );
ok( 2 === count( $item['locs']['meta:stackable_optimized_css_raw']['rows'] ), 'A5 every row of a key is kept, not only the first' );

$token = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $item ), null, '#10: h1.title' );
ok( AB_MCP_Undo::valid_token( $token ), 'A6 the point is recorded' );

// The change: every copy written, a draft created, a derived row gone.
$GLOBALS['db_posts'][10]['post_content']          = 'neu';
$GLOBALS['db_posts'][10]['post_content_filtered'] = 'neu gefiltert';
ab_set_meta( $mid, '[{"id":"h1"}]' );
ab_set_meta( $css1, '.a{color:blue}' );
unset( $GLOBALS['db_meta'][ $raw2 ] );
ab_meta_add( 10, '_fake_draft', 'entwurf' );
ab_meta_add( 10, 'stackable_optimized_css', '.b{color:green}' );   // a second row of a key that had one
$own = AB_MCP_Undo_Builder::state_hashes( 10, $locs );
ok( true === AB_MCP_Undo_Builder::mark_written( $token, 10, $own ), 'A7 the state after the write is kept with the point' );

$claim = AB_MCP_Undo::claim( $token );
ok( is_array( $claim ) && array() === $claim['parts'] && isset( $claim['entry']['after']['10'] ), 'A8 claim gives the snapshot, no parts, and the after state' );
$plan = AB_MCP_Undo_Restore::plan( $claim['entry'], $claim['data'], array( 'parts' => $claim['parts'] ) );
ok( is_array( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'A9 the plan restores the page' );
$GLOBALS['ab_meta_deleted'] = array();
$GLOBALS['ab_cleaned']      = array();
// What Spectra built from the changed page (file generation on, measured: spstale.mjs).
ab_meta_add( 10, '_uag_css_file_name', 'uag-css-10.css' );
update_option( '__uagb_asset_version', 1790910534 );
$res = AB_MCP_Undo_Restore::apply( $claim['entry'], $claim['data'], array( 'parts' => $claim['parts'] ) );
ok( is_array( $res ) && array( 10 ) === $res['restored'] && array() === $res['failed'], 'A10 restored' );
ok( $orig === ab_dump( 10 ), 'A11 byte for byte: columns, every meta row, the meta ids, the non-UTF-8 bytes' );
ok( array() === ab_meta_rows( 10, '_fake_draft' ), 'A12 the draft the change created is gone again' );
ok( array( $css1 ) === array_keys( ab_meta_rows( 10, 'stackable_optimized_css' ) ), 'A12 and so is a row the change added to a key that had one' );
ok( in_array( '10:_uag_page_assets', $GLOBALS['ab_meta_deleted'], true ) && in_array( '10:_elementor_element_cache', $GLOBALS['ab_meta_deleted'], true ), 'A13 the builder caches are emptied' );
ok( array() === ab_meta_rows( 10, '_uag_css_file_name' ) && 1790910534 !== get_option( '__uagb_asset_version' ), 'A13a Spectra\'s file name is emptied and its asset version moves on, so the restored CSS gets a new address' );
ok( in_array( 10, $GLOBALS['ab_cleaned'], true ), 'A14 and the post cache' );
$after = AB_MCP_Undo_Restore::after_restore( $res );
ok( isset( $after['page_cache_purged'] ) && 'post' === AB_MCP_Undo_Restore::page_cache_scope()['builder_data'], 'A15 page caches: the pages of the posts written' );
AB_MCP_Undo::clear_all();

// An empty column is there, empty: wpdb::get_var() answers null for it as
// for a missing row, and a snapshot that called it missing was refused whole
// by its own restore.
$mid11 = ab_fake_page( 11, ab_fake_elements(), array( 'post_content' => '', 'post_content_filtered' => '' ) );
$o11   = ab_dump( 11 );
$i11   = AB_MCP_Undo_Builder::snapshot( get_post( 11 ), $adapter, array( 'post_content_filtered' ) );
ok( true === $i11['locs']['post_content']['existed'] && '' === $i11['locs']['post_content']['value'] && true === $i11['locs']['post_content_filtered']['existed'], 'A16 an empty post_content is kept as there and empty' );
ok( false === AB_MCP_Undo_Builder::read_loc( 999, 'post_content' )['existed'], 'A16 a post that is not there stays missing' );
$GLOBALS['ab_caps']['edit_post:11'] = true;
$t11 = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $i11 ) );
$GLOBALS['db_posts'][11]['post_content']          = 'gefüllt';
$GLOBALS['db_posts'][11]['post_content_filtered'] = 'auch';
AB_MCP_Undo_Builder::mark_written( $t11, 11, AB_MCP_Undo_Builder::state_hashes( 11, array_keys( $i11['locs'] ) ) );
$c11 = AB_MCP_Undo::claim( $t11 );
$r11 = AB_MCP_Undo_Restore::apply( $c11['entry'], $c11['data'], array( 'parts' => $c11['parts'] ) );
ok( is_array( $r11 ) && array( 11 ) === $r11['restored'] && $o11 === ab_dump( 11 ), 'A17 and its restore empties the columns again' );
AB_MCP_Undo::clear_all();

/* ======================================== B) refused over a later change */

ab_fake_page( 20, ab_fake_elements() );
$p20   = get_post( 20 );
$i20   = AB_MCP_Undo_Builder::snapshot( $p20, $adapter );
$o20   = ab_dump( 20 );
$tok   = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $i20 ) );
$GLOBALS['db_posts'][20]['post_content'] = 'unser Stand';
AB_MCP_Undo_Builder::mark_written( $tok, 20, AB_MCP_Undo_Builder::state_hashes( 20, array_keys( $i20['locs'] ) ) );
$GLOBALS['db_posts'][20]['post_content'] = 'jemand anderes';   // edited since
$c    = AB_MCP_Undo::claim( $tok );
$plan = AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'], array( 'parts' => $c['parts'] ) );
ok( 0 === $plan['restorable'] && 'changed_since' === $plan['blocked'][0]['reason'] && array( 'post_content' ) === $plan['blocked'][0]['locs'], 'B1 a page changed since is blocked, naming the copy' );
$res = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'], array( 'parts' => $c['parts'] ) );
ok( array() === $res['restored'] && 'jemand anderes' === $GLOBALS['db_posts'][20]['post_content'], 'B2 and nothing is written' );
$res = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'], array( 'parts' => $c['parts'], 'force' => true ) );
ok( array( 20 ) === $res['restored'] && $o20 === ab_dump( 20 ), 'B3 force puts the old state back over it' );

// Already as before: nothing to write.
$res = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'], array( 'parts' => $c['parts'] ) );
ok( array( 20 ) === $res['restored'], 'B4 a page already as before counts as restored' );

// No after state known (the write never confirmed): changed means blocked.
$tok2 = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( AB_MCP_Undo_Builder::snapshot( get_post( 20 ), $adapter ) ) );
$GLOBALS['db_posts'][20]['post_content'] = 'ungewiss';
$c2   = AB_MCP_Undo::claim( $tok2 );
$plan = AB_MCP_Undo_Restore::plan( $c2['entry'], $c2['data'] );
ok( 0 === $plan['restorable'] && 'changed_since' === $plan['blocked'][0]['reason'], 'B5 without an after state a changed page needs force' );

// Planned, then changed before the write: the write checks again.
$c2['entry']['after'] = array( '20' => AB_MCP_Undo_Builder::state_hashes( 20, array_keys( $c2['data']['posts'][0]['locs'] ) ) );
$plan                 = AB_MCP_Undo_Restore::plan( $c2['entry'], $c2['data'] );
ok( 1 === $plan['restorable'], 'B6 (planned while the page is as the change left it)' );
$GLOBALS['db_posts'][20]['post_content'] = 'dazwischen';
$r = AB_MCP_Undo_Builder::restore_planned( $plan['items'][0] );
ok( is_wp_error( $r ) && 'ab_mcp_undo_post_changed' === $r->get_error_code() && 'dazwischen' === $GLOBALS['db_posts'][20]['post_content'], 'B7 a change between plan and write is not overwritten' );
AB_MCP_Undo::clear_all();

/* ======================================== C) a row whose id is gone */

$m30  = ab_fake_page( 30, ab_fake_elements() );
$i30  = AB_MCP_Undo_Builder::snapshot( get_post( 30 ), $adapter );
$o30  = ab_dump( 30 );
$tok3 = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $i30 ) );
unset( $GLOBALS['db_meta'][ $m30 ] );               // a plugin's save deletes the row …
$new30 = ab_meta_add( 30, '_fake_layout', '[]' );   // … and adds it again
AB_MCP_Undo_Builder::mark_written( $tok3, 30, AB_MCP_Undo_Builder::state_hashes( 30, array_keys( $i30['locs'] ) ) );
$c3  = AB_MCP_Undo::claim( $tok3 );
$res = AB_MCP_Undo_Restore::apply( $c3['entry'], $c3['data'] );
ok( array( 30 ) === $res['restored'] && $o30 === ab_dump( 30 ) && array( $m30 ) === array_keys( ab_meta_rows( 30, '_fake_layout' ) ), 'C1 a row deleted and added again: back under its own id, which the database never handed out again; the added row is gone — ids included, as it was' );
ok( ! isset( $res['warnings'] ), 'C2 nothing to warn about: the page is exactly as it was' );
AB_MCP_Undo::clear_all();

// A key with two rows: get_post_meta( …, true ) reads the lowest meta_id.
ab_fake_page( 30, ab_fake_elements() );
$ra   = ab_meta_add( 30, 'stackable_optimized_css_raw', 'A' );
$rb   = ab_meta_add( 30, 'stackable_optimized_css_raw', 'B' );
$i30  = AB_MCP_Undo_Builder::snapshot( get_post( 30 ), $adapter );
$o30  = ab_dump( 30 );
$tok3 = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $i30 ) );
unset( $GLOBALS['db_meta'][ $ra ] );                          // the first row deleted …
ab_meta_add( 30, 'stackable_optimized_css_raw', 'A2' );       // … and added again behind the second
AB_MCP_Undo_Builder::mark_written( $tok3, 30, AB_MCP_Undo_Builder::state_hashes( 30, array_keys( $i30['locs'] ) ) );
$c3  = AB_MCP_Undo::claim( $tok3 );
$res = AB_MCP_Undo_Restore::apply( $c3['entry'], $c3['data'] );
ok( array( 30 ) === $res['restored'] && array() === $res['failed'] && $o30 === ab_dump( 30 ) && 'A' === get_post_meta( 30, 'stackable_optimized_css_raw', true ), 'C3 two rows, the first deleted and added again: both back under their ids and in their order (get_post_meta reads A again), not refused as written-but-different' );
AB_MCP_Undo::clear_all();

// The old id handed out again to another row (MySQL before 8.0 can after a restart).
ab_fake_page( 30, ab_fake_elements() );
foreach ( array_keys( ab_meta_rows( 30, 'stackable_optimized_css_raw' ) ) as $mid ) {
	unset( $GLOBALS['db_meta'][ $mid ] );
}
$ra   = ab_meta_add( 30, 'stackable_optimized_css_raw', 'A' );
$rb   = ab_meta_add( 30, 'stackable_optimized_css_raw', 'B' );
$i30  = AB_MCP_Undo_Builder::snapshot( get_post( 30 ), $adapter );
$tok3 = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $i30 ) );
unset( $GLOBALS['db_meta'][ $ra ] );
$GLOBALS['db_meta'][ $ra ] = array( 99, 'other_key', 'fremd' );   // the id taken by another post's row
ab_meta_add( 30, 'stackable_optimized_css_raw', 'A2' );
AB_MCP_Undo_Builder::mark_written( $tok3, 30, AB_MCP_Undo_Builder::state_hashes( 30, array_keys( $i30['locs'] ) ) );
$c3  = AB_MCP_Undo::claim( $tok3 );
$res = AB_MCP_Undo_Restore::apply( $c3['entry'], $c3['data'] );
ok( array( 30 ) === $res['restored'] && array( 'A', 'B' ) === array_values( ab_meta_rows( 30, 'stackable_optimized_css_raw' ) ) && array( 99, 'other_key', 'fremd' ) === $GLOBALS['db_meta'][ $ra ], 'C4 the old id taken by another row: the values back in their order under other ids; the other row untouched' );
ok( isset( $res['warnings'][0] ) && false !== strpos( $res['warnings'][0], 'stackable_optimized_css_raw was restored over its meta key' ), 'C5 and the answer says so' );
unset( $GLOBALS['db_meta'][ $ra ] );
AB_MCP_Undo::clear_all();

/* ======================================== D) parts under one token */

foreach ( array( 20, 21, 22 ) as $pid ) {
	ab_fake_page( $pid, ab_fake_elements() );
}
$before = array();
foreach ( array( 20, 21, 22 ) as $pid ) {
	$before[ $pid ] = ab_dump( $pid );
}
$files0 = count( ab_bin_files() );
$tok    = null;
foreach ( array( 20, 21, 22 ) as $pid ) {
	$it  = AB_MCP_Undo_Builder::snapshot( get_post( $pid ), $adapter );
	$got = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( $it ), $tok, 'replace Willkommen' );
	ok( AB_MCP_Undo::valid_token( $got ) && ( null === $tok || $got === $tok ), "D1 post $pid joins the same token" );
	$tok = $got;
	$GLOBALS['db_posts'][ $pid ]['post_content'] = 'ersetzt ' . $pid;
	AB_MCP_Undo_Builder::mark_written( $tok, $pid, AB_MCP_Undo_Builder::state_hashes( $pid, array_keys( $it['locs'] ) ) );
}
ok( $files0 + 3 === count( ab_bin_files() ), 'D2 one file per part' );
$list = AB_MCP_Undo::entries_for( 10 );
ok( 1 === count( $list ) && 3 === $list[0]['items'], 'D3 one point in the list, three pages' );
// The same post again: the first snapshot is the oldest state and wins.
$again = AB_MCP_Undo_Builder::snapshot( get_post( 21 ), $adapter );
ok( $tok === AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( $again ), $tok ), 'D4 (a fourth part, post 21 again)' );
$c = AB_MCP_Undo::claim( $tok );
ok( 3 === count( $c['parts'] ), 'D5 claim reads every part' );
$plan = AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'], array( 'parts' => $c['parts'] ) );
ok( 3 === $plan['restorable'], 'D6 the plan has each page once' );
$nonce = AB_MCP_Undo::begin_apply( $tok );
ok( is_wp_error( AB_MCP_Undo::append_part( $tok, array( 'kind' => 'builder_data', 'items' => 1, 'data' => AB_MCP_Undo_Builder::data( 'text', array( $again ) ) ) ) ), 'D7 no part joins a point while it is being restored' );
$res = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'], array( 'parts' => $c['parts'] ) );
ok( array( 20, 21, 22 ) === $res['restored'], 'D8 all three pages restored' );
$same = true;
foreach ( array( 20, 21, 22 ) as $pid ) {
	$same = $same && $before[ $pid ] === ab_dump( $pid );
}
ok( $same, 'D9 each byte for byte, post 21 from its first snapshot' );
ok( true === AB_MCP_Undo::finish_apply( $tok, $nonce, true ) && $files0 === count( ab_bin_files() ), 'D10 consuming the point removes the files of every part' );

// Discard, expiry and the orphan sweep reach the parts too.
$t = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( AB_MCP_Undo_Builder::snapshot( get_post( 20 ), $adapter ) ) );
AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( AB_MCP_Undo_Builder::snapshot( get_post( 21 ), $adapter ) ), $t );
ok( $files0 + 2 === count( ab_bin_files() ), 'D11 (two files)' );
foreach ( ab_bin_files() as $f ) { touch( $f, time() - 2 * DAY_IN_SECONDS ); }
AB_MCP_Undo::gc();
ok( $files0 + 2 === count( ab_bin_files() ), 'D12 the orphan sweep keeps the part files of a live point' );
ok( true === AB_MCP_Undo::discard( $t ) && $files0 === count( ab_bin_files() ), 'D13 discarding removes every part file' );

// Another user's token, another kind.
$t = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( AB_MCP_Undo_Builder::snapshot( get_post( 20 ), $adapter ) ) );
$GLOBALS['ab_uid'] = 8;
$r                 = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( AB_MCP_Undo_Builder::snapshot( get_post( 21 ), $adapter ) ), $t );
ok( is_wp_error( $r ) && 'ab_mcp_undo_not_found' === $r->get_error_code(), 'D14 no part on another user\'s point' );
$GLOBALS['ab_uid'] = 7;
$r = AB_MCP_Undo::append_part( $t, array( 'kind' => 'post_fields', 'items' => 1, 'data' => array( 'id' => 1 ) ) );
ok( is_wp_error( $r ) && 'ab_mcp_undo_kind_mismatch' === $r->get_error_code(), 'D15 parts only for builder_data' );

// A damaged part: nothing is restored.
AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( AB_MCP_Undo_Builder::snapshot( get_post( 21 ), $adapter ) ), $t );
$e     = AB_MCP_Undo::entry_for( $t );
$pfile = $GLOBALS['ab_upload'] . '/ab-mcp-undo/' . $e['parts'][0]['payload'];
file_put_contents( $pfile, gzencode( '{"v":1,"b64":false,"data":{"v":1,"scope":"text","posts":[]}}' ) );
$c = AB_MCP_Undo::claim( $t );
ok( is_wp_error( $c ) && 'ab_mcp_undo_corrupt' === $c->get_error_code(), 'D16 a part that fails its hash fails the whole claim' );
// An entry whose part has no hash is dropped from the index.
$idx                        = get_option( AB_MCP_Undo::OPTION );
$bad                        = $idx[ count( $idx ) - 1 ];
$bad['parts'][0]['sha256']  = 'nope';
ok( ! AB_MCP_Undo::valid_entry( $bad ), 'D17 an entry with a part without its hash is invalid' );
$bad2          = $idx[ count( $idx ) - 1 ];
$bad2['after'] = array( '20' => array( 'post_content' => 'kein-hash' ) );
ok( ! AB_MCP_Undo::valid_entry( $bad2 ), 'D18 an after state that is not a hash is invalid' );
$good = $idx[ count( $idx ) - 1 ];
ok( AB_MCP_Undo::valid_entry( $good ), 'D19 (the entry as written is valid)' );
AB_MCP_Undo::clear_all();

/* ======================================== E) the limit, after compression */

$GLOBALS['ab_hooks']['ab_mcp_undo_snapshot_max'][10][] = array( static function () { return 4096; }, 1 );
ab_post( 40, array( 'post_content' => str_repeat( 'Gleicher Absatz über und über. ', 600 ) ) );   // ~24 KB as JSON, under the 32 KB raw ceiling, compresses well
ab_meta_add( 40, '_fake_layout', '[]' );
$big = AB_MCP_Undo_Builder::snapshot( get_post( 40 ), $adapter );
ok( true === AB_MCP_Undo_Builder::check_size( 'element', array( $big ) ), 'E1 a 24 KB page fits a 4 KB point: measured after compression' );
$t = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $big ) );
$e = is_string( $t ) ? AB_MCP_Undo::entry_for( $t ) : array( 'bytes' => 0, 'stored' => 0 );
ok( is_string( $t ) && $e['bytes'] > 20000 && $e['stored'] <= 4096, 'E2 recorded: ' . $e['bytes'] . ' bytes raw, ' . $e['stored'] . ' stored' );
$GLOBALS['db_posts'][40]['post_content'] = bin2hex( random_bytes( 4000 ) ) . random_bytes( 3000 );   // incompressible
$noise = AB_MCP_Undo_Builder::snapshot( get_post( 40 ), $adapter );
$chk   = AB_MCP_Undo_Builder::check_size( 'element', array( $noise ) );
ok( is_wp_error( $chk ) && 'ab_mcp_undo_too_large' === $chk->get_error_code() && 'compressed' === $chk->get_error_data()['measured'], 'E3 the preview check says too large, measured compressed' );
$r = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $noise ) );
ok( is_wp_error( $r ) && 'ab_mcp_undo_too_large' === $r->get_error_code(), 'E4 and recording refuses it the same way' );
// Three pages that fit one by one but not together: parts.
$items = array();
foreach ( array( 20, 21, 22 ) as $pid ) {
	$GLOBALS['db_posts'][ $pid ]['post_content'] = bin2hex( random_bytes( 1400 ) );
	$items[ $pid ]                              = AB_MCP_Undo_Builder::snapshot( get_post( $pid ), $adapter );
}
ok( is_wp_error( AB_MCP_Undo_Builder::check_size( 'text', array_values( $items ) ) ), 'E5 (the three together are over 4 KB)' );
$t = null;
foreach ( $items as $pid => $it ) {
	$t = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( $it ), $t );
	ok( AB_MCP_Undo::valid_token( $t ), "E6 page $pid fits as a part of its own" );
}
$e = AB_MCP_Undo::entry_for( $t );
ok( $e['stored'] > 4096 && 2 === count( $e['parts'] ), 'E7 the point holds more than 4 KB in parts' );
$GLOBALS['ab_hooks']['ab_mcp_undo_max_total_bytes'][10][] = array( static function () use ( $e ) { return (int) $e['stored'] + 10; }, 1 );
$r = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( $items[20] ), $t );
ok( is_wp_error( $r ) && 'total' === $r->get_error_data()['measured'], 'E8 a part that would push the point over the whole store is refused' );
unset( $GLOBALS['ab_hooks']['ab_mcp_undo_snapshot_max'], $GLOBALS['ab_hooks']['ab_mcp_undo_max_total_bytes'] );
AB_MCP_Undo::clear_all();
// The number of parts is bounded: a restore reads every part before it plans.
$small = AB_MCP_Undo_Builder::snapshot( get_post( 20 ), $adapter );
$t     = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( $small ) );
for ( $n = 0; $n < AB_MCP_Undo::MAX_PARTS; $n++ ) {
	AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( $small ), $t );
}
$e = AB_MCP_Undo::entry_for( $t );
$r = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( $small ), $t );
ok( AB_MCP_Undo::MAX_PARTS === count( $e['parts'] ) && is_wp_error( $r ) && 'parts' === $r->get_error_data()['measured'], 'E9 at most ' . AB_MCP_Undo::MAX_PARTS . ' parts; the next one is refused and says so' );
AB_MCP_Undo::clear_all();

/* ======================================== F) rights and shapes */

$pol = AB_MCP_Undo_Restore::policy();
ok( 'builder_data' === $pol['wp_update_builder_element']['kind'] && 'edit_posts' === $pol['wp_update_builder_element']['cap'], 'F1 policy: wp_update_builder_element, builder_data, edit_posts' );
ok( 'builder_data' === $pol['wp_search_replace']['kind'] && 'manage_options' === $pol['wp_search_replace']['cap'], 'F2 policy: wp_search_replace, builder_data, manage_options' );
ab_fake_page( 20, ab_fake_elements() );
$it = AB_MCP_Undo_Builder::snapshot( get_post( 20 ), $adapter );
$t  = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $it ) );
$c  = AB_MCP_Undo::claim( $t );
$GLOBALS['ab_caps']['edit_post:20'] = false;
$plan                               = AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'] );
ok( 'forbidden' === $plan['blocked'][0]['reason'], 'F3 a post the account may no longer edit is blocked' );
$GLOBALS['ab_caps']['edit_post:20'] = true;
$GLOBALS['ab_caps']['edit_posts']   = false;
ok( is_wp_error( AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'] ) ), 'F4 without edit_posts the point cannot be touched' );
$GLOBALS['ab_caps']['edit_posts'] = true;
$raw = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'raw', array( $it ) );
$cr  = AB_MCP_Undo::claim( $raw );
$plan = AB_MCP_Undo_Restore::plan( $cr['entry'], $cr['data'] );
ok( 0 === $plan['restorable'] && 'forbidden: unfiltered_html' === $plan['blocked'][0]['reason'], 'F5 a raw replace needs unfiltered_html to be undone' );
$GLOBALS['ab_caps']['unfiltered_html'] = true;
ok( 1 === AB_MCP_Undo_Restore::plan( $cr['entry'], $cr['data'] )['restorable'], 'F6 and with it, it can' );
// Shapes this class does not write.
$shapes = array(
	'version'     => array( 'v' => 2, 'scope' => 'element', 'posts' => array( $it ) ),
	'scope'       => array( 'v' => 1, 'scope' => 'other', 'posts' => array( $it ) ),
	'no locs'     => array( 'v' => 1, 'scope' => 'element', 'posts' => array( array( 'id' => 20, 'locs' => array() ) ) ),
	'wp key'      => array( 'v' => 1, 'scope' => 'element', 'posts' => array( array( 'id' => 20, 'locs' => array( 'meta:_wp_attached_file' => array( 'existed' => false ) ) ) ) ),
	'bad b64'     => array( 'v' => 1, 'scope' => 'element', 'posts' => array( array( 'id' => 20, 'locs' => array( 'post_content' => array( 'existed' => true, 'b64' => '***' ) ) ) ) ),
	'both values' => array( 'v' => 1, 'scope' => 'element', 'posts' => array( array( 'id' => 20, 'locs' => array( 'post_content' => array( 'existed' => true, 'value' => 'a', 'b64' => 'YQ==' ) ) ) ) ),
	'column gone' => array( 'v' => 1, 'scope' => 'element', 'posts' => array( array( 'id' => 20, 'locs' => array( 'post_content' => array( 'existed' => false ) ) ) ) ),
	'post table'  => array( 'v' => 1, 'scope' => 'element', 'posts' => array( array( 'id' => 20, 'locs' => array( 'post_title' => array( 'existed' => true, 'value' => 'x' ) ) ) ) ),
);
foreach ( $shapes as $label => $shape ) {
	$p = AB_MCP_Undo_Restore::plan( $c['entry'], $shape );
	ok( is_wp_error( $p ) && 'ab_mcp_undo_corrupt' === $p->get_error_code(), "F7 refused whole: $label" );
}
AB_MCP_Undo::clear_all();

/* ======================================== G) through wp_undo */

ab_fake_page( 22, ab_fake_elements() );
$o22 = ab_dump( 22 );
$it  = AB_MCP_Undo_Builder::snapshot( get_post( 22 ), $adapter );
$t   = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $it ) );
$GLOBALS['db_posts'][22]['post_content'] = 'geschrieben';
AB_MCP_Undo_Builder::mark_written( $t, 22, AB_MCP_Undo_Builder::state_hashes( 22, array_keys( $it['locs'] ) ) );
$GLOBALS['db_posts'][22]['post_content'] = 'danach bearbeitet';
$dry = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $t, 'dry_run' => true ) );
ok( 0 === $dry['would_undo'] && 'changed_since' === $dry['blocked'][0]['reason'], 'G1 wp_undo dry run: blocked, changed since' );
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $t ) );
ok( false === $res['consumed'] && 'danach bearbeitet' === $GLOBALS['db_posts'][22]['post_content'], 'G2 wp_undo without force keeps the newer change and the token' );
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $t, 'force' => true ) );
ok( true === $res['consumed'] && $o22 === ab_dump( 22 ), 'G3 wp_undo with force restores and uses the token up' );
$src = (string) file_get_contents( AB_MCP_PRO_DIR . 'includes/tools/class-tools-undo-core.php' );
ok( false !== strpos( $src, "'force'" ) && false !== strpos( $src, 'unless force=true' ), 'G4 wp_undo offers and describes force' );

// Decision Thomas 29.10.2026: a partial undo narrows the point. Two pages
// in one point, one changed since: the other comes back, the point is used
// up, and a new one holds only the page left out — with the state it was
// left in, so it is still refused without force.
AB_MCP_Undo::clear_all();
$GLOBALS['ab_caps']['edit_post:24'] = true;
$GLOBALS['ab_caps']['edit_post:25'] = true;
ab_fake_page( 24, ab_fake_elements() );
ab_fake_page( 25, ab_fake_elements() );
$o24 = ab_dump( 24 );
$o25 = ab_dump( 25 );
$i24 = AB_MCP_Undo_Builder::snapshot( get_post( 24 ), $adapter );
$i25 = AB_MCP_Undo_Builder::snapshot( get_post( 25 ), $adapter );
$t   = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $i24, $i25 ) );
$GLOBALS['db_posts'][24]['post_content'] = 'geschrieben 24';
$GLOBALS['db_posts'][25]['post_content'] = 'geschrieben 25';
AB_MCP_Undo_Builder::mark_written( $t, 24, AB_MCP_Undo_Builder::state_hashes( 24, array_keys( $i24['locs'] ) ) );
AB_MCP_Undo_Builder::mark_written( $t, 25, AB_MCP_Undo_Builder::state_hashes( 25, array_keys( $i25['locs'] ) ) );
$GLOBALS['db_posts'][25]['post_content'] = 'danach bearbeitet 25';
$idx = get_option( AB_MCP_Undo::OPTION );
$idx[0]['created_at'] = time() - 3600; // made an hour ago
update_option( AB_MCP_Undo::OPTION, $idx );
$old = AB_MCP_Undo::claim( $t );
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $t ) );
ok( array( 24 ) === $res['restored'] && $o24 === ab_dump( 24 ) && 'danach bearbeitet 25' === $GLOBALS['db_posts'][25]['post_content'], 'G5 a partial undo of two pages: the unchanged one comes back, the changed one stays' );
ok( true === $res['consumed'] && is_wp_error( AB_MCP_Undo::claim( $t ) ) && isset( $res['rest_token'] ), 'G6 ... the point is used up and the rest gets a token of its own' );
$rest = AB_MCP_Undo::claim( (string) $res['rest_token'] );
ok( ! is_wp_error( $rest ) && array( 25 ) === array_map( 'intval', array_column( $rest['data']['posts'], 'id' ) ) && array( '25' ) === array_map( 'strval', array_keys( $rest['entry']['after'] ) ) && $old['entry']['after']['25'] === $rest['entry']['after']['25'], 'G7 ... holding only the page left out, with the state it was left in' );
ok( AB_MCP_Undo::expires_of( $old['entry'] ) === $rest['entry']['expires_at'] && $old['entry']['created_at'] === $rest['entry']['created_at'] && 1 === $rest['entry']['items'] && 'wp_update_builder_element' === $rest['entry']['tool'], 'G8 ... and the old expiry and age' );
$GLOBALS['db_posts'][24]['post_content'] = 'später 24';
$dry = AB_MCP_Tools_Undo_Core::undo( array( 'token' => (string) $res['rest_token'], 'dry_run' => true ) );
ok( 0 === $dry['would_undo'] && 25 === $dry['blocked'][0]['ref'] && 'changed_since' === $dry['blocked'][0]['reason'], 'G9 the rest is still refused without force' );
$res2 = AB_MCP_Tools_Undo_Core::undo( array( 'token' => (string) $res['rest_token'], 'force' => true ) );
ok( true === $res2['consumed'] && $o25 === ab_dump( 25 ) && 'später 24' === $GLOBALS['db_posts'][24]['post_content'], 'G10 with force the rest comes back, and the page that came back first keeps its later change' );
AB_MCP_Undo::clear_all();
// Codex 29.10.2026: a window shortened since the point was made holds for
// the rest too — not the expiry the point was stored with.
ab_fake_page( 24, ab_fake_elements() );
ab_fake_page( 25, ab_fake_elements() );
$i24 = AB_MCP_Undo_Builder::snapshot( get_post( 24 ), $adapter );
$i25 = AB_MCP_Undo_Builder::snapshot( get_post( 25 ), $adapter );
$t   = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $i24, $i25 ) );
$GLOBALS['db_posts'][25]['post_content'] = 'danach bearbeitet 25';
$idx = get_option( AB_MCP_Undo::OPTION );
$idx[0]['created_at'] = time() - 12 * 3600;
$idx[0]['expires_at'] = time() - 12 * 3600 + 7 * 86400;
update_option( AB_MCP_Undo::OPTION, $idx );
update_option( AB_MCP_Undo::TTL_OPTION, 1 );
AB_MCP_Undo_Builder::mark_written( $t, 25, array( 'post_content' => str_repeat( 'a', 64 ) ) );
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $t ) );
$rest = isset( $res['rest_token'] ) ? AB_MCP_Undo::claim( (string) $res['rest_token'] ) : null;
ok( is_array( $rest ) && $rest['entry']['expires_at'] <= time() + 12 * 3600, 'G11 a window shortened since: the rest keeps the 12 hours the point had left' );
update_option( AB_MCP_Undo::TTL_OPTION, 7 );
AB_MCP_Undo::clear_all();

// Codex (GitHub) 29.10.2026: a point of several parts narrows part by part,
// each measured on its own as when it was recorded — not into one snapshot
// that might not fit.
foreach ( array( 26, 27, 28 ) as $pid ) {
	$GLOBALS['ab_caps'][ 'edit_post:' . $pid ] = true;
	ab_fake_page( $pid, ab_fake_elements() );
}
$o27 = ab_dump( 27 );
$o28 = ab_dump( 28 );
$its = array();
foreach ( array( 26, 27, 28 ) as $pid ) {
	$its[ $pid ] = AB_MCP_Undo_Builder::snapshot( get_post( $pid ), $adapter );
}
$t = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'element', array( $its[26] ) );
AB_MCP_Undo_Builder::record( 'wp_search_replace', 'element', array( $its[27] ), $t );
AB_MCP_Undo_Builder::record( 'wp_search_replace', 'element', array( $its[28] ), $t );
foreach ( array( 26, 27, 28 ) as $pid ) {
	$GLOBALS['db_posts'][ $pid ]['post_content'] = 'ersetzt ' . $pid;
	AB_MCP_Undo_Builder::mark_written( $t, $pid, AB_MCP_Undo_Builder::state_hashes( $pid, array_keys( $its[ $pid ]['locs'] ) ) );
}
$GLOBALS['db_posts'][27]['post_content'] = 'danach 27';
$GLOBALS['db_posts'][28]['post_content'] = 'danach 28';
$res  = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $t ) );
$rest = isset( $res['rest_token'] ) ? AB_MCP_Undo::claim( (string) $res['rest_token'] ) : null;
ok( is_array( $rest ) && array( 26 ) === $res['restored'] && array( 27 ) === array_map( 'intval', array_column( $rest['data']['posts'], 'id' ) ) && 1 === count( $rest['parts'] ) && array( 28 ) === array_map( 'intval', array_column( $rest['parts'][0]['posts'], 'id' ) ), 'G12 the rest of a point of three parts keeps its parts: 27 as the first, 28 as a part' );
ok( is_array( $rest ) && 2 === $rest['entry']['items'] && array( '27', '28' ) === array_map( 'strval', array_keys( $rest['entry']['after'] ) ), 'G13 ... with both pages counted and their state after the change' );
$res2 = AB_MCP_Tools_Undo_Core::undo( array( 'token' => (string) $res['rest_token'], 'force' => true ) );
ok( true === $res2['consumed'] && $o27 === ab_dump( 27 ) && $o28 === ab_dump( 28 ), 'G14 ... and with force both come back' );
AB_MCP_Undo::clear_all();
// A part that cannot be added: no half rest point; the old point stays,
// with the warning that says why.
foreach ( array( 26, 27, 28 ) as $pid ) {
	ab_fake_page( $pid, ab_fake_elements() );
}
$t = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'element', array( $its[26] ) );
AB_MCP_Undo_Builder::record( 'wp_search_replace', 'element', array( $its[27] ), $t );
AB_MCP_Undo_Builder::record( 'wp_search_replace', 'element', array( $its[28] ), $t );
foreach ( array( 26, 27, 28 ) as $pid ) {
	AB_MCP_Undo_Builder::mark_written( $t, $pid, AB_MCP_Undo_Builder::state_hashes( $pid, array_keys( $its[ $pid ]['locs'] ) ) );
}
$GLOBALS['db_posts'][27]['post_content'] = 'danach 27';
$GLOBALS['db_posts'][28]['post_content'] = 'danach 28';
// The three parts take about the same; room for one and a half of them.
$cap = (int) ( get_option( AB_MCP_Undo::OPTION )[0]['stored'] / 2 );
add_filter( 'ab_mcp_undo_max_total_bytes', function () use ( $cap ) { return $cap; } );
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $t ) );
unset( $GLOBALS['ab_hooks']['ab_mcp_undo_max_total_bytes'] );
ok( ! isset( $res['rest_token'] ) && false === $res['consumed'] && ! empty( $res['warning'] ) && array( $t ) === array_column( get_option( AB_MCP_Undo::OPTION ), 'token' ), 'G15 a part that does not fit: no half rest point, the old point stays with the warning' );
AB_MCP_Undo::clear_all();
// ... and if that half rest point cannot be taken out again (the index busy),
// the answer names it, so it can be discarded by hand.
foreach ( array( 26, 27, 28 ) as $pid ) {
	ab_fake_page( $pid, ab_fake_elements() );
}
$t = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'element', array( $its[26] ) );
AB_MCP_Undo_Builder::record( 'wp_search_replace', 'element', array( $its[27] ), $t );
AB_MCP_Undo_Builder::record( 'wp_search_replace', 'element', array( $its[28] ), $t );
foreach ( array( 26, 27, 28 ) as $pid ) {
	AB_MCP_Undo_Builder::mark_written( $t, $pid, AB_MCP_Undo_Builder::state_hashes( $pid, array_keys( $its[ $pid ]['locs'] ) ) );
}
$GLOBALS['db_posts'][27]['post_content'] = 'danach 27';
$GLOBALS['db_posts'][28]['post_content'] = 'danach 28';
$cap = (int) ( get_option( AB_MCP_Undo::OPTION )[0]['stored'] / 2 );
// The index lock turns busy once the rest point stands (from its first part on).
add_filter( 'ab_mcp_undo_max_total_bytes', function () use ( $cap ) { if ( count( (array) get_option( AB_MCP_Undo::OPTION ) ) > 1 ) { update_option( AB_MCP_Undo::LOCK, (string) time() ); } return $cap; } );
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $t ) );
unset( $GLOBALS['ab_hooks']['ab_mcp_undo_max_total_bytes'] );
delete_option( AB_MCP_Undo::LOCK );
ok( isset( $res['incomplete_rest_token'] ) && ! isset( $res['rest_token'] ) && in_array( $res['incomplete_rest_token'], array_column( get_option( AB_MCP_Undo::OPTION ), 'token' ), true ), 'G16 a half rest point that cannot be taken out is named in the answer' );
AB_MCP_Undo::clear_all();

/* ======================================== H) the check after the write */

// A database that keeps something else than it is given (a column that
// cannot hold four-byte characters, say): the restore must say so, not
// report the page as restored.
ab_fake_page( 40, ab_fake_elements(), array( 'post_content' => 'Kopie mit 😀' ) );
$i40 = AB_MCP_Undo_Builder::snapshot( get_post( 40 ), $adapter );
$t40 = AB_MCP_Undo_Builder::record( 'wp_update_builder_element', 'element', array( $i40 ) );
$GLOBALS['db_posts'][40]['post_content'] = 'neu';
AB_MCP_Undo_Builder::mark_written( $t40, 40, AB_MCP_Undo_Builder::state_hashes( 40, array_keys( $i40['locs'] ) ) );
$inner           = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new class( $inner ) {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options  = 'wp_options';
	private $inner;
	public function __construct( $inner ) { $this->inner = $inner; }
	public function __call( $name, $args ) { return call_user_func_array( array( $this->inner, $name ), $args ); }
	public function update( $table, $data, $where ) {
		if ( 'wp_posts' === $table && isset( $data['post_content'] ) ) {
			$data['post_content'] = preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', (string) $data['post_content'] );
		}
		return $this->inner->update( $table, $data, $where );
	}
};
$c   = AB_MCP_Undo::claim( $t40 );
$res = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'] );
ok( array() === $res['restored'] && isset( $res['failed'][0]['reason'] ) && false !== strpos( $res['failed'][0]['reason'], 'holds something else for: post_content' ), 'H1 wp_undo on a database that keeps other bytes than it is given: the page is reported as failed, not as restored' );
$r = AB_MCP_Undo_Builder::write_back( $i40 );
ok( is_wp_error( $r ) && 'ab_mcp_undo_post_mismatch' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'post_content' ), 'H2 write_back itself answers ab_mcp_undo_post_mismatch, naming the copy' );
$GLOBALS['wpdb'] = $inner;
AB_MCP_Undo::clear_all();

ab_result( 'BUILDER-UNDO-CHECKS' );
