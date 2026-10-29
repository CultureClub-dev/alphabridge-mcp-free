<?php
/**
 * The page-builder writers for Beaver Builder, SiteOrigin Page Builder and
 * SeedProd, on the free core's own readers and the measured pages.
 *
 * Pages: test/fixtures/builders/<builder>/ are verbatim copies of the free
 * plugin's fixtures (integration/free-4.4, commit c83aa4d); how each was
 * measured is in its README.md. Not copied: beaver/measured-start.json, which
 * only the free plugin's own reader test compares against. Where a test needs a page the measurement did
 * not record (a draft with another value, a second image size, an HTML copy
 * with a stale field), it changes a copy of a measured page and says so.
 *
 * Each builder's own code is a stand-in, declared in this file: it mirrors
 * the functions the writer calls, in the version the writer is built for, and
 * names the lines it mirrors. The tests prove what the writers do with those
 * functions — the order of the calls, the copies kept in step, the refusals
 * with their way — not what the builders do themselves; that was measured on
 * real installations (the writers' MEASURED constant, with what was measured
 * and what was not; recipes in test/e2e/builders/php/recipes/), so the answers
 * no longer say «profile not yet measured».
 *
 * Run: php test/builder-writers-meta-test.php beaver
 *      php test/builder-writers-meta-test.php siteorigin
 *      php test/builder-writers-meta-test.php seedprod
 *      (after ./sync-free-core.sh <free 4.4.0 or newer>)
 */

$scenario = isset( $argv[1] ) ? $argv[1] : '';
if ( ! in_array( $scenario, array( 'beaver', 'siteorigin', 'seedprod' ), true ) ) {
	fwrite( STDERR, "Szenario fehlt oder unbekannt: '$scenario' — erlaubt: beaver, siteorigin, seedprod\n" );
	exit( 2 );
}

require dirname( __DIR__ ) . '/standalone-lib/builder-harness.php';

/* ------------------------------------------------- WordPress, narrower */

// WordPress' slashing pair. The harness' update_post_meta() unslashes once
// wp_unslash() exists, as update_metadata() does (wp-includes/meta.php).
function wp_slash( $v ) {
	if ( is_array( $v ) ) {
		foreach ( $v as $k => $x ) {
			$v[ $k ] = wp_slash( $x );
		}
		return $v;
	}
	return is_string( $v ) ? addslashes( $v ) : $v;
}
// stripslashes_deep() through map_deep(): arrays and object properties alike, objects in place.
function wp_unslash( $v ) {
	if ( is_array( $v ) ) {
		foreach ( $v as $k => $x ) {
			$v[ $k ] = wp_unslash( $x );
		}
	} elseif ( is_object( $v ) ) {
		foreach ( get_object_vars( $v ) as $k => $x ) {
			$v->$k = wp_unslash( $x );
		}
	} elseif ( is_string( $v ) ) {
		$v = stripslashes( $v );
	}
	return $v;
}
// Image sizes of the test images: id => size => address.
$GLOBALS['ab_sizes'] = array();
function wp_get_attachment_image_src( $id, $size = 'thumbnail' ) {
	if ( isset( $GLOBALS['ab_sizes'][ (int) $id ][ $size ] ) ) {
		return array( $GLOBALS['ab_sizes'][ (int) $id ][ $size ], 100, 100, true );
	}
	$url = wp_get_attachment_url( $id );
	return false === $url ? false : array( $url, 800, 600, false );
}

// The tool's base class from the core, reduced to the helpers the tool uses.
abstract class AB_MCP_Tools_Base {
	protected static function s( $a, $k, $d = '' ) { return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : $d; }
	protected static function i( $a, $k, $d = 0 ) { return isset( $a[ $k ] ) ? (int) $a[ $k ] : $d; }
	protected static function b( $a, $k, $d = false ) { return isset( $a[ $k ] ) ? (bool) $a[ $k ] : $d; }
	protected static function need( $a, array $keys ) {
		foreach ( $keys as $k ) {
			if ( ! isset( $a[ $k ] ) ) {
				return new WP_Error( 'ab_mcp_missing', $k );
			}
		}
		return true;
	}
}
require_once AB_MCP_PRO_DIR . 'includes/tools/class-tools-builder-write.php';

/* ------------------------------------------- the free core's readers */

// The harness reads with a test adapter only; here the free core's own.
remove_all_filters( 'ab_mcp_builder_adapters' );
AB_MCP_Builders::reset();
AB_MCP_Builder_Writers::reset();
add_filter( 'ab_mcp_pro_builder_tool_enabled', static function ( $v, $n ) { return 'wp_update_builder_element' === $n ? true : $v; }, 10, 2 );
define( 'FL_BUILDER_VERSION', '2.11.0.6' );
define( 'SITEORIGIN_PANELS_VERSION', '2.36.1' );
define( 'SEEDPROD_VERSION', '6.20.10' );

$GLOBALS['ab_caps'] = array( 'edit_posts' => true, 'unfiltered_html' => true, 'read_post:55' => true, 'read_post:57' => true );
foreach ( range( 60, 99 ) as $pid ) {
	$GLOBALS['ab_caps'][ 'edit_post:' . $pid ] = true;
}
ab_post( 55, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
ab_post( 57, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
$GLOBALS['ab_images'][57] = 'http://x/u/zweites.jpg';

function ab_fixture( $builder, $file ) {
	return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/standalone-lib/fixtures/builders/' . $builder . '/' . $file ), true );
}
/** The row of a post and its meta rows, as stored. */
function ab_db( $id ) {
	$meta = array();
	foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
		if ( $r[0] === (int) $id ) {
			$meta[ $mid ] = $r;
		}
	}
	return array( $GLOBALS['db_posts'][ (int) $id ], $meta );
}
function ab_clear_meta( $id ) {
	foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
		if ( $r[0] === (int) $id ) {
			unset( $GLOBALS['db_meta'][ $mid ] );
		}
	}
}
function ab_fresh() {
	AB_MCP_Undo::clear_all();
	unset( $GLOBALS['ab_opts'][ AB_MCP_Undo::LOCK ] );
	$GLOBALS['ab_meta_deleted'] = array();
	$GLOBALS['ab_toolstate']    = array();
}
function ab_has( $list, $needle ) {
	foreach ( (array) $list as $line ) {
		if ( false !== strpos( (string) $line, $needle ) ) {
			return true;
		}
	}
	return false;
}
function ab_undo( $token ) {
	$c = AB_MCP_Undo::claim( $token );
	return AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'], array( 'parts' => $c['parts'] ) );
}
function ab_tool( array $a ) {
	return AB_MCP_Tools_Builder_Write::update_builder_element( $a );
}

/* ============================================================== beaver */

if ( 'beaver' === $scenario ) {
	$W  = AB_MCP_Builder_Writers::for_adapter( 'beaver' );
	$bb = ab_fixture( 'beaver', 'page.json' );
	ok( $W instanceof AB_MCP_Builder_Writer_Beaver, 'B0 the Beaver writer registers from its file under the reader\'s id' );

	/** The measured page; draft 'same' (as measured), 'changed' (the measured draft with an unpublished edit) or 'none'. */
	function ab_bb_page( $id, $draft = 'same' ) {
		$bb = ab_fixture( 'beaver', 'page.json' );
		ab_post( $id, array( 'post_content' => $bb['post_content'] ) );
		ab_clear_meta( $id );
		foreach ( $bb['meta'] as $k => $v ) {
			if ( '_fl_builder_draft' === $k ) {
				if ( 'none' === $draft ) {
					continue;
				}
				if ( 'changed' === $draft ) {
					$v = $bb['draft_changed']['_fl_builder_draft'];
				}
			}
			ab_meta_add( $id, $k, $v );
		}
		ab_fresh();
	}
	function ab_bb_node( $id, $node, $key = '_fl_builder_data' ) {
		$data = get_post_meta( $id, $key, true );
		return isset( $data[ $node ] ) ? $data[ $node ] : null;
	}
	$H = 'p6i7qt9enzw3';   // heading «Willkommen bei Alpine Bikes»
	$T = 'qfibgsmt2h14';   // rich-text «Öffnungszeiten: Mo–Fr 9–18 Uhr» (Mo–Sa 9–17 in the changed draft)
	$P = 'cjtw68brznap';   // photo, image 4
	$G = 'morbnjca48hl';   // button-group

	/* ---- without Beaver's model: only reading, with the way ---- */
	ab_bb_page( 60 );
	$can = $W->can_write( get_post( 60 ) );
	ok( is_wp_error( $can ) && false !== strpos( $can->get_error_message(), 'Activate Beaver Builder, or change the page in its editor' ), 'B1 without FLBuilderModel the writer refuses and names the way' );
	ok( ! in_array( 'wp_update_builder_element', AB_MCP_Builders::write_via( get_post( 60 ) ), true ), 'B2 and write_via does not name the tool' );
	$s0  = ab_db( 60 );
	$res = ab_tool( array( 'id' => 60, 'element_id' => $H, 'fields' => array( 'heading' => 'Neu' ), 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_cannot_write' === $res->get_error_code() && $s0 === ab_db( 60 ), 'B3 the tool refuses with that answer and writes nothing' );

	/*
	 * Stand-in of Beaver's model, Beaver Builder Lite 2.11.0.6, the methods the
	 * writer calls (classes/class-fl-builder-model.php):
	 * set_post_id/reset_post_id :449/:462; get_layout_data :5890 (from post meta,
	 * nodes cloned shallowly); update_layout_data :5960 (slash_settings :5521,
	 * then update_metadata, which unslashes); get_node_settings_with_defaults_merged
	 * :1788 (defaults under the stored settings — the stand-in's defaults are a
	 * few per module, not Beaver's list); process_node_settings :1823 →
	 * process_module_settings :4442 (the module's update(): the photo module sets
	 * photo_src if missing and caches the attachment data, modules/photo/photo.php:
	 * 82-95) and sanitize_settings :5469 (link fields through FLBuilderUtils::
	 * esc_attr(), classes/class-fl-builder-utils.php:452); verify_settings :5330
	 * → verify_settings_kses :5384 (wp_kses_post with safe_style_css emptied —
	 * no inline style survives — against fix_kses :5447); user_has_unfiltered_html
	 * :8947; is_module_registered :3659; delete_all_asset_cache :1147;
	 * get_post_types :406.
	 */
	if ( ! class_exists( 'FLBuilderModel', false ) ) {
		class FLBuilderModel {
			public static $post_id    = array();
			public static $log        = array();
			public static $registered = array( 'heading', 'rich-text', 'button', 'photo', 'callout', 'cta', 'icon', 'numbers', 'button-group', 'menu', 'box' );
			public static $keep_old   = false;   // process_node_settings() hands back the old settings
			public static $skip_draft = false;   // update_layout_data() leaves the draft
			public static $drop_node  = false;   // update_layout_data() loses a node
			public static $alter      = false;   // process_node_settings() changes a heading on its own
			public static function set_post_id( $id ) {
				array_unshift( self::$post_id, (int) $id );
				self::$log[] = 'set_post_id:' . (int) $id;
			}
			public static function reset_post_id() {
				array_shift( self::$post_id );
				self::$log[] = 'reset_post_id';
			}
			public static function get_post_id() {
				return empty( self::$post_id ) ? false : self::$post_id[0];
			}
			public static function get_layout_data( $status = null, $post_id = null ) {
				$post_id     = $post_id ? $post_id : self::get_post_id();
				self::$log[] = 'get_layout_data:' . $status . ':' . ( self::get_post_id() === (int) $post_id ? 'own' : 'other' );
				$data        = get_post_meta( $post_id, 'published' === $status ? '_fl_builder_data' : '_fl_builder_draft', true );
				$data        = is_array( $data ) ? $data : array();
				foreach ( $data as $k => $n ) {
					if ( is_object( $n ) ) {
						$data[ $k ] = clone $n;
					}
				}
				return $data;
			}
			public static function update_layout_data( $data, $status = null, $post_id = null ) {
				$post_id     = $post_id ? $post_id : self::get_post_id();
				self::$log[] = 'update_layout_data:' . $status . ':' . ( self::get_post_id() === (int) $post_id ? 'own' : 'other' );
				if ( 'draft' === $status && self::$skip_draft ) {
					return;
				}
				if ( self::$drop_node ) {
					array_pop( $data );
				}
				update_post_meta( $post_id, 'published' === $status ? '_fl_builder_data' : '_fl_builder_draft', self::slash_settings( $data ) );
			}
			public static function slash_settings( $data ) {
				if ( is_array( $data ) ) {
					foreach ( $data as $k => $v ) {
						$data[ $k ] = self::slash_settings( $v );
					}
				} elseif ( is_object( $data ) ) {
					foreach ( get_object_vars( $data ) as $k => $v ) {
						$data->$k = self::slash_settings( $v );
					}
				} elseif ( is_string( $data ) ) {
					$data = wp_slash( $data );
				}
				return $data;
			}
			public static function get_node_settings_with_defaults_merged( $node ) {
				self::$log[] = 'defaults:' . $node->node;
				$defaults    = array(
					'heading' => array( 'tag' => 'h2', 'link' => '', 'link_target' => '_self' ),
					'photo'   => array( 'crop' => '', 'align' => 'center', 'link_type' => '' ),
				);
				$type        = isset( $node->settings->type ) ? $node->settings->type : '';
				return (object) array_merge( isset( $defaults[ $type ] ) ? $defaults[ $type ] : array(), (array) $node->settings );
			}
			public static function process_node_settings( $node, $new ) {
				self::$log[] = 'process:' . $node->node;
				if ( self::$keep_old ) {
					return $node->settings;
				}
				if ( self::$alter && isset( $new->heading ) ) {
					$new->heading = 'Etwas anderes';
				}
				if ( 'photo' === ( isset( $node->settings->type ) ? $node->settings->type : '' ) ) {
					if ( ! isset( $new->photo_src ) ) {
						$new->photo_src = '';
					}
					$new->data = (object) array( 'id' => (int) $new->photo, 'url' => (string) wp_get_attachment_url( (int) $new->photo ) );
				}
				foreach ( array( 'link', 'link_url', 'btn_link' ) as $f ) {
					if ( isset( $new->$f ) && is_string( $new->$f ) ) {
						$new->$f = str_replace( array( '&quot;', '&#039;' ), "'", htmlspecialchars( $new->$f, ENT_QUOTES, 'UTF-8', false ) );
					}
				}
				return $new;
			}
			public static function verify_settings( $settings ) {
				self::$log[] = 'verify';
				foreach ( $settings as $key => $value ) {
					if ( is_string( $value ) ) {
						$value     = stripslashes( $value );
						$sanitized = preg_replace( '~\sstyle\s*=\s*("[^"]*"|\'[^\']*\')~i', '', wp_kses_post( $value ) );
						if ( json_encode( $sanitized ) !== json_encode( self::fix_kses( $value ) ) ) {
							return array( 'key' => $key, 'value' => $value, 'parsed' => $sanitized );
						}
					} elseif ( is_object( $value ) || is_array( $value ) ) {
						$check = self::verify_settings( $value );
						if ( true !== $check ) {
							return $check;
						}
					}
				}
				return true;
			}
			public static function fix_kses( $value ) {
				$value = preg_replace( '/&([a-z0-9#]+);/i', '&$1;', $value );
				$value = preg_replace( '#(&)(?!(.*);)#i', '&amp;', $value );
				return preg_replace( '#(<[a-z]+)(\/>)#', '$1 $2', $value );
			}
			public static function user_has_unfiltered_html() {
				return current_user_can( 'unfiltered_html' );
			}
			public static function is_module_registered( $type ) {
				return in_array( $type, self::$registered, true );
			}
			public static function delete_all_asset_cache( $id ) {
				self::$log[] = 'delete_all_asset_cache:' . (int) $id;
			}
			public static function get_post_types() {
				return array( 'page', 'post' );
			}
		}
	}
	function ab_bb_reset() {
		FLBuilderModel::$post_id    = array();
		FLBuilderModel::$log        = array();
		FLBuilderModel::$keep_old   = false;
		FLBuilderModel::$skip_draft = false;
		FLBuilderModel::$drop_node  = false;
		FLBuilderModel::$alter      = false;
	}

	/* ---- preview ---- */
	ab_bb_page( 61 );
	ab_bb_reset();
	ok( true === $W->can_write( get_post( 61 ) ) && in_array( 'wp_update_builder_element', AB_MCP_Builders::write_via( get_post( 61 ) ), true ), 'B4 with Beaver\'s model the writer writes, and write_via names the tool' );
	$s0  = ab_db( 61 );
	$pre = $W->set_fields( get_post( 61 ), $H, array( 'heading' => 'Willkommen im Veloladen' ), array() );
	ok( is_array( $pre ) && false === $pre['applied'] && 'Willkommen bei Alpine Bikes' === $pre['changes'][0]['before'] && 'Willkommen im Veloladen' === $pre['changes'][0]['after'], 'B5 preview: before as stored, after as asked' );
	ok( $s0 === ab_db( 61 ) && array() === FLBuilderModel::$log, 'B6 preview: nothing written, Beaver\'s model not called' );
	ok( ! ab_has( $pre['warnings'], 'profile not yet measured' ), 'B7 measured on a real installation (Beaver Builder Lite 2.11.0.6): no «profile not yet measured»' );
	ok( ab_has( $pre['notes'], 'post_content keeps Beaver Builder\'s text copy' ), 'B8 and that post_content stays Beaver\'s old copy' );

	/* ---- apply: live and draft through Beaver's model ---- */
	$other_before = array();
	foreach ( get_post_meta( 61, '_fl_builder_data', true ) as $nid => $n ) {
		$other_before[ $nid ] = serialize( $n );
	}
	$res = $W->set_fields( get_post( 61 ), $H, array( 'heading' => 'Willkommen im Veloladen' ), array( 'apply' => true, 'layout_hash' => $pre['layout_hash'] ) );
	ok( is_array( $res ) && true === $res['applied'] && true === $res['readback']['ok'], 'B9 applied, read back' );
	$log = FLBuilderModel::$log;
	$last = max( array_keys( array_filter( $log, static function ( $l ) { return 0 === strpos( $l, 'update_layout_data:' ); } ) ) );
	ok( 'set_post_id:61' === $log[0] && 'reset_post_id' === $log[ $last + 1 ], 'B10 set_post_id() before the first call into the model, reset_post_id() right after the last (T5)' );
	ok( in_array( 'get_layout_data:published:own', $log, true ) && in_array( 'update_layout_data:published:own', $log, true ) && in_array( 'update_layout_data:draft:own', $log, true ) && ! ab_has( $log, ':other' ), 'B11 published and draft read and stored through the model, for this post' );
	ok( 2 === count( array_keys( $log, 'process:' . $H, true ) ) && 2 === count( array_keys( $log, 'defaults:' . $H, true ) ), 'B12 the node\'s settings are processed by Beaver, with its defaults, once per copy' );
	ok( ! in_array( 'verify', $log, true ), 'B13 an account with unfiltered_html is not checked by kses (as Beaver)' );
	$live  = ab_bb_node( 61, $H );
	$draft = ab_bb_node( 61, $H, '_fl_builder_draft' );
	ok( 'Willkommen im Veloladen' === $live->settings->heading && 'Willkommen im Veloladen' === $draft->settings->heading, 'B14 the heading is new in the live layout and in the draft' );
	ok( '_self' === $live->settings->link_target && 'h1' === $live->settings->tag && 'https://example.com/ueber-uns' === $live->settings->link, 'B15 the module\'s defaults merged in under its stored settings, which are kept' );
	$same = true;
	foreach ( get_post_meta( 61, '_fl_builder_data', true ) as $nid => $n ) {
		if ( $nid !== $H && serialize( $n ) !== $other_before[ $nid ] ) {
			$same = false;
		}
	}
	ok( $same, 'B16 every other node of the layout is as it was' );
	ok( $s0[0]['post_content'] === $GLOBALS['db_posts'][61]['post_content'], 'B17 post_content (Beaver\'s copy) is not rendered or touched' );
	ok( in_array( 'delete_all_asset_cache:61', FLBuilderModel::$log, true ) && in_array( 'beaver', $res['caches']['builder_caches'], true ), 'B18 Beaver\'s asset cache emptied after the write' );
	$entry = AB_MCP_Undo::entry_for( $res['undo_token'] );
	ok( 'builder_data' === $entry['kind'] && isset( $entry['after']['61']['meta:_fl_builder_data'], $entry['after']['61']['meta:_fl_builder_draft'] ) && ! isset( $entry['after']['61']['meta:_fl_builder_data_settings'] ), 'B19 one undo point holds live layout and draft, never the page code settings' );
	ab_undo( $res['undo_token'] );
	ok( $s0 === ab_db( 61 ), 'B20 wp_undo puts both back byte for byte' );

	/* ---- the tool ---- */
	ab_bb_page( 62 );
	ab_bb_reset();
	$res = ab_tool( array( 'id' => 62, 'element_id' => $T, 'fields' => array( 'html' => '<p>Öffnungszeiten: <strong>Mo–Sa</strong> 9–17 Uhr</p>' ), 'apply' => true ) );
	ok( is_array( $res ) && true === $res['applied'] && 'beaver' === $res['builder'] && 'rich-text' === $res['element']['type'] && '<p>Öffnungszeiten: <strong>Mo–Sa</strong> 9–17 Uhr</p>' === $res['element']['after']['text'], 'B21 wp_update_builder_element writes a rich-text through the Beaver writer' );
	ok( '<p>Öffnungszeiten: <strong>Mo–Sa</strong> 9–17 Uhr</p>' === ab_bb_node( 62, $T )->settings->text, 'B22 stored as sent' );

	/* ---- the draft holds another version ---- */
	ab_bb_page( 63, 'changed' );
	ab_bb_reset();
	$draft0 = ab_meta_rows( 63, '_fl_builder_draft' );
	$res    = $W->set_fields( get_post( 63 ), $T, array( 'text' => '<p>Öffnungszeiten: Mo–Fr 8–18 Uhr</p>' ), array( 'apply' => true ) );
	ok( is_array( $res ) && true === $res['applied'] && '<p>Öffnungszeiten: Mo–Fr 8–18 Uhr</p>' === ab_bb_node( 63, $T )->settings->text, 'B23 a field the draft holds otherwise: the live page changes' );
	ok( $draft0 === ab_meta_rows( 63, '_fl_builder_draft' ) && ! in_array( 'update_layout_data:draft:own', FLBuilderModel::$log, true ), 'B24 the draft with its unpublished edit is left byte for byte' );
	ok( ab_has( $res['warnings'], 'publishing that draft in Beaver Builder brings its version back' ), 'B25 and the answer says publishing the draft brings its version back' );
	ab_bb_reset();
	$res = $W->set_fields( get_post( 63 ), $H, array( 'heading' => 'Hallo' ), array( 'apply' => true ) );
	ok( 'Hallo' === ab_bb_node( 63, $H )->settings->heading && 'Hallo' === ab_bb_node( 63, $H, '_fl_builder_draft' )->settings->heading && ! ab_has( $res['warnings'], 'brings its version back' ), 'B26 a field the draft holds the same: both change, no warning' );
	ok( 'Öffnungszeiten: Mo–Sa 9–17 Uhr' === AB_MCP_Builders::field_text( ab_bb_node( 63, $T, '_fl_builder_draft' )->settings->text ), 'B27 and the draft\'s own edit of another node stays' );

	/* ---- publish=false: only the draft ---- */
	ab_bb_page( 64, 'changed' );
	ab_bb_reset();
	$live0 = ab_meta_rows( 64, '_fl_builder_data' );
	$res   = $W->set_fields( get_post( 64 ), $T, array( 'text' => '<p>Öffnungszeiten: Mo–So 9–17 Uhr</p>' ), array( 'apply' => true, 'publish' => false ) );
	ok( is_array( $res ) && true === $res['applied'] && false === $res['published'] && '<p>Öffnungszeiten: Mo–Sa 9–17 Uhr</p>' === $res['changes'][0]['before'], 'B28 publish=false: applied into the draft, before is the draft\'s own value' );
	ok( $live0 === ab_meta_rows( 64, '_fl_builder_data' ) && '<p>Öffnungszeiten: Mo–So 9–17 Uhr</p>' === ab_bb_node( 64, $T, '_fl_builder_draft' )->settings->text, 'B29 the live layout is untouched, the draft carries the change' );
	ok( ab_has( $res['notes'], 'the live page keeps its version until someone publishes' ) && array() === $res['caches']['page_cache_purged'], 'B30 the answer says the live page keeps its version; no page cache purged' );
	ab_bb_page( 65, 'none' );
	ab_bb_reset();
	$s0  = ab_db( 65 );
	$res = $W->set_fields( get_post( 65 ), $H, array( 'heading' => 'X' ), array( 'apply' => true, 'publish' => false ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_no_draft' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'This page has no Beaver Builder draft yet' ) && false !== strpos( $res->get_error_message(), 'Leave publish out' ) && $s0 === ab_db( 65 ) && array() === FLBuilderModel::$log, 'B31 publish=false on a page without a draft: refused with the way, nothing written' );
	ab_bb_reset();
	$res = $W->set_fields( get_post( 65 ), $H, array( 'heading' => 'Ohne Entwurf' ), array( 'apply' => true ) );
	ok( is_array( $res ) && 'Ohne Entwurf' === ab_bb_node( 65, $H )->settings->heading && array() === ab_meta_rows( 65, '_fl_builder_draft' ) && ! ab_has( $res['warnings'], 'draft' ), 'B32 without a draft the live page changes and no draft is made' );
	// publish=false with the value the live page has: the draft still changes.
	ab_bb_page( 76, 'changed' );
	ab_bb_reset();
	$live0 = ab_meta_rows( 76, '_fl_builder_data' );
	$res   = $W->set_fields( get_post( 76 ), $T, array( 'text' => ab_bb_node( 76, $T )->settings->text ), array( 'apply' => true, 'publish' => false ) );
	ok( is_array( $res ) && true === $res['applied'] && ab_bb_node( 76, $T )->settings->text === ab_bb_node( 76, $T, '_fl_builder_draft' )->settings->text && $live0 === ab_meta_rows( 76, '_fl_builder_data' ), 'B33 publish=false with the live page\'s own value: the draft takes it (unchanged only where the draft already holds it)' );
	$res = $W->set_fields( get_post( 76 ), $T, array( 'text' => ab_bb_node( 76, $T )->settings->text ), array( 'apply' => true, 'publish' => false ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_nothing' === $res->get_error_code(), 'B34 and once it does: nothing to change' );
	// Settings holding an object of another plugin's class are not copied.
	ab_bb_page( 77 );
	ab_bb_reset();
	$rows                                   = ab_meta_rows( 77, '_fl_builder_data' );
	$data                                   = unserialize( reset( $rows ), array( 'allowed_classes' => array( 'stdClass' ) ) );
	$data[ $H ]->settings->extra            = new ArrayObject( array( 1, 2 ) );
	$GLOBALS['db_meta'][ key( $rows ) ][2] = serialize( $data );
	$s0                                     = ab_db( 77 );
	$res                                    = $W->set_fields( get_post( 77 ), $H, array( 'heading' => 'Neu' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'hold data of another plugin' ) && $s0 === ab_db( 77 ) && ! in_array( 'process:' . $H, FLBuilderModel::$log, true ), 'B35 settings with an object of another class: not handed to Beaver, nothing written' );

	/* ---- photo with photo_src ---- */
	ab_bb_page( 66 );
	ab_bb_reset();
	$res  = $W->set_fields( get_post( 66 ), $P, array( 'image_id' => 55 ), array( 'apply' => true ) );
	$node = ab_bb_node( 66, $P );
	ok( is_array( $res ) && 55 === $node->settings->photo && 'http://x/u/bild.jpg' === $node->settings->photo_src, 'B36 a photo: id and its address together (full size, as the old address was)' );
	ok( 55 === (int) $node->settings->data->id && 55 === ab_bb_node( 66, $P, '_fl_builder_draft' )->settings->photo, 'B37 Beaver\'s module update cached the new attachment; the draft has it too' );
	// A copy of the measured page whose photo showed the "medium" size.
	ab_bb_page( 67 );
	$rows = ab_meta_rows( 67, '_fl_builder_data' );
	$data = unserialize( reset( $rows ), array( 'allowed_classes' => array( 'stdClass' ) ) );
	$data[ $P ]->settings->data->sizes->medium = (object) array( 'url' => 'http://x/alt-medium.png' );
	$data[ $P ]->settings->photo_src            = 'http://x/alt-medium.png';
	$GLOBALS['db_meta'][ key( $rows ) ][2]     = serialize( $data );
	$GLOBALS['ab_sizes'][57]                    = array( 'medium' => 'http://x/u/zweites-300x200.jpg' );
	ab_bb_reset();
	$res = $W->set_fields( get_post( 67 ), $P, array( 'photo' => 57 ), array( 'apply' => true ) );
	ok( is_array( $res ) && 'http://x/u/zweites-300x200.jpg' === ab_bb_node( 67, $P )->settings->photo_src, 'B38 the new photo keeps the image size the old address showed' );

	/* ---- button group: a field inside an item ---- */
	ab_bb_page( 68 );
	ab_bb_reset();
	$res = $W->set_fields( get_post( 68 ), $G, array( 'items.0.link' => 'https://example.org/a?x=1&y=2' ), array( 'apply' => true ) );
	ok( is_array( $res ) && true === $res['readback']['ok'] && 'https://example.org/a?x=1&y=2' === html_entity_decode( ab_bb_node( 68, $G )->settings->items[0]->link ), 'B39 a link inside a list item is set and read back' );

	/* ---- kses: Beaver's own check for an account without unfiltered_html ---- */
	ab_bb_page( 69 );
	ab_bb_reset();
	unset( $GLOBALS['ab_caps']['unfiltered_html'] );
	$s0  = ab_db( 69 );
	$res = $W->set_fields( get_post( 69 ), $T, array( 'text' => '<p style="color:red">Rot</p>' ), array() );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_kses' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'Beaver Builder does not store the new value' ) && $s0 === ab_db( 69 ), 'B40 a value Beaver would silently refuse (inline style, account without unfiltered_html) is refused in the preview already' );
	$res = $W->set_fields( get_post( 69 ), $T, array( 'text' => '<p><strong>Fett</strong> &amp; klar</p>' ), array( 'apply' => true ) );
	ok( is_array( $res ) && true === $res['applied'] && in_array( 'verify', FLBuilderModel::$log, true ), 'B41 plain markup passes Beaver\'s check and is written' );
	$GLOBALS['ab_caps']['unfiltered_html'] = true;

	/* ---- locked, global, unknown ---- */
	ab_bb_page( 70 );
	ab_bb_reset();
	$s0 = ab_db( 70 );
	foreach ( array( 'rwl07jd6s9x5' => 'code element', '435kad0ehp1w' => 'wordpress widget', 'aq9y6uelbvs3' => 'global element', 'd36muyaq1ns4' => 'code element' ) as $nid => $why ) {
		$res = $W->set_fields( get_post( 70 ), $nid, array( 'text' => 'x' ), array( 'apply' => true ) );
		ok( is_wp_error( $res ) && 'ab_mcp_builder_locked' === $res->get_error_code() && false !== strpos( $res->get_error_message(), $why ), "B42 locked $why ($nid) refused" );
	}
	ok( $s0 === ab_db( 70 ) && array() === FLBuilderModel::$log, 'B43 nothing written, Beaver\'s model not called' );
	FLBuilderModel::$registered = array( 'rich-text' );
	$res = $W->set_fields( get_post( 70 ), $H, array( 'heading' => 'Neu' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'not registered on this site' ) && $s0 === ab_db( 70 ), 'B44 a module Beaver has not registered (its plugin off) is not processed; the page is unchanged' );
	FLBuilderModel::$registered = array( 'heading', 'rich-text', 'button', 'photo', 'callout', 'cta', 'icon', 'numbers', 'button-group', 'menu', 'box' );

	/* ---- read-back: Beaver kept the old settings / lost a node / left the draft ---- */
	ab_bb_page( 71 );
	ab_bb_reset();
	$s0                       = ab_db( 71 );
	FLBuilderModel::$keep_old = true;
	$res                      = $W->set_fields( get_post( 71 ), $H, array( 'heading' => 'Neu' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_readback_failed' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'The page is unchanged' ) && null === $res->get_error_data()['undo_token'] && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'B45 Beaver hands back the old settings (as for a value it refuses): the read-back says the field did not change, the page is unchanged, no undo point is left' );
	ab_bb_reset();
	FLBuilderModel::$alter = true;
	$res                   = $W->set_fields( get_post( 71 ), $H, array( 'heading' => 'Neu' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_readback_failed' === $res->get_error_code() && true === $res->get_error_data()['rolled_back'] && $s0[0] === ab_db( 71 )[0] && array_values( $s0[1] ) === array_values( ab_db( 71 )[1] ), 'B46 Beaver stores another value than asked: the read-back fails and every copy is put back byte for byte' );
	ab_bb_page( 72 );
	ab_bb_reset();
	$s0                        = ab_db( 72 );
	FLBuilderModel::$drop_node = true;
	$res                       = $W->set_fields( get_post( 72 ), $H, array( 'heading' => 'Neu' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_readback_failed' === $res->get_error_code() && true === $res->get_error_data()['rolled_back'] && array_values( $s0[1] ) === array_values( ab_db( 72 )[1] ), 'B47 a node lost in the save: put back' );
	ab_bb_page( 73 );
	ab_bb_reset();
	$s0                         = ab_db( 73 );
	FLBuilderModel::$skip_draft = true;
	$res                        = $W->set_fields( get_post( 73 ), $H, array( 'heading' => 'Neu' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'Beaver Builder\'s draft do not show their new value' ) && true === $res->get_error_data()['rolled_back'] && array_values( $s0[1] ) === array_values( ab_db( 73 )[1] ), 'B48 the draft not kept in step: the read-back says so and the page is put back' );

	/* ---- replace ---- */
	ab_bb_page( 74 );
	ab_bb_reset();
	$res = $W->replace( get_post( 74 ), 'MZ ', 'Neu ', array( 'apply' => true, 'scope' => 'text', 'undo_tool' => 'wp_search_replace' ) );
	ok( is_array( $res ) && true === $res['applied'] && count( $res['changes'] ) >= 8, 'B49 replace: every text field with the text changed (' . ( is_array( $res ) ? count( $res['changes'] ) : 0 ) . ')' );
	$fields = is_array( $res ) ? array_map( static function ( $c ) { return $c['element'] . ':' . $c['field']; }, $res['changes'] ) : array();
	ok( in_array( $G . ':items.0.text', $fields, true ) && ! ab_has( $fields, 'rwl07jd6s9x5' ) && ! ab_has( $fields, 'd36muyaq1ns4' ), 'B50 including a list item; never a locked element' );
	$raw = ab_meta_rows( 74, '_fl_builder_data' );
	$raw = (string) reset( $raw );
	ok( false !== strpos( $raw, 'MZ code video embed_code' ) && false === strpos( $raw, '"MZ callout title"' ) && false !== strpos( $raw, 'Neu callout title' ), 'B51 the locked video embed keeps its text; the callout changed' );
	ab_bb_page( 75 );
	$pre = $W->replace( get_post( 75 ), 'MZ ', 'Neu ', array() );
	$raw = ab_meta_rows( 75, '_fl_builder_data' );
	$raw = (string) reset( $raw );
	ok( is_array( $pre ) && false === $pre['applied'] && substr_count( $raw, 'MZ ' ) > count( $pre['changes'] ), 'B52 replace preview: matches outside the writable fields stay' );
	$res = $W->replace( get_post( 75 ), 'MZ ', 'Neu ', array( 'publish' => false ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_no_draft' === $res->get_error_code(), 'B53 replace with publish=false is refused with the way' );
	$res = $W->replace( get_post( 75 ), 'gibt es nicht', 'x', array() );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_nothing' === $res->get_error_code() && false === strpos( $res->get_error_message(), 'post_content' ), 'B54 replace without a match: nothing to change, and no note about a change that is not made' );
	// wp_search_replace passes case_insensitive and regex (scope text).
	ab_bb_page( 78 );
	ab_bb_reset();
	$cs  = $W->replace( get_post( 78 ), 'MZ ', 'Neu ', array() );
	$ci  = $W->replace( get_post( 78 ), 'mz ', 'Neu ', array( 'case_insensitive' => true ) );
	$low = $W->replace( get_post( 78 ), 'mz ', 'Neu ', array() );
	ok( is_array( $cs ) && is_array( $ci ) && count( $cs['changes'] ) === count( $ci['changes'] ) && $cs['locked_matches'] === $ci['locked_matches'] && $ci['locked_matches'] > 0, 'B55 case_insensitive: «mz » finds the fields and the locked matches «MZ » finds (' . ( is_array( $ci ) ? count( $ci['changes'] ) . '/' . $ci['locked_matches'] : 'error' ) . ')' );
	ok( is_wp_error( $low ) && 'ab_mcp_builder_nothing' === $low->get_error_code(), 'B56 without it, «mz » finds nothing' );
	$callout = array();
	foreach ( is_array( $ci ) ? $ci['changes'] : array() as $c ) {
		if ( 'MZ callout title' === $c['before'] ) {
			$callout = $c;
		}
	}
	ok( isset( $callout['after'] ) && 'Neu callout title' === $callout['after'], 'B57 the replacement is taken as written, the rest of the value stays' );
	$s0  = ab_db( 78 );
	$res = $W->replace( get_post( 78 ), 'MZ\s', 'Neu ', array( 'regex' => true, 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_regex' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'scope: raw' ) && $s0 === ab_db( 78 ), 'B58 regex is refused with the way (scope: raw), nothing written' );

	ab_result( 'BUILDER-WRITERS (beaver)-CHECKS' );
}

/* ========================================================== siteorigin */

if ( 'siteorigin' === $scenario ) {
	$W = AB_MCP_Builder_Writers::for_adapter( 'siteorigin' );
	ok( $W instanceof AB_MCP_Builder_Writer_SiteOrigin, 'S0 the SiteOrigin writer registers from its file under the reader\'s id' );

	/** A measured page: page-a (headline, editor, button, image) or page-more (with a Custom HTML widget holding a script). */
	function ab_so_page( $id, $file = 'page-a.json' ) {
		$f = ab_fixture( 'siteorigin', $file );
		ab_post( $id, array( 'post_content' => $f['post_content'] ) );
		ab_clear_meta( $id );
		foreach ( $f['meta'] as $k => $v ) {
			ab_meta_add( $id, $k, $v );
		}
		ab_fresh();
	}
	function ab_so_data( $id ) {
		return get_post_meta( $id, 'panels_data', true );
	}

	/* ---- neither SiteOrigin's ability nor its admin class: only reading ---- */
	ab_so_page( 60 );
	$can = $W->can_write( get_post( 60 ) );
	ok( is_wp_error( $can ) && false !== strpos( $can->get_error_message(), 'change the page in its editor' ), 'S1 without SiteOrigin\'s save functions the writer refuses and names the way' );
	$s0  = ab_db( 60 );
	$res = ab_tool( array( 'id' => 60, 'element_id' => 'w0', 'fields' => array( 'headline.text' => 'Neu' ), 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 60 ) ) ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_cannot_write' === $res->get_error_code() && $s0 === ab_db( 60 ), 'S2 the tool refuses with that answer and writes nothing' );

	/*
	 * Stand-ins of Page Builder by SiteOrigin 2.36.1:
	 * - SiteOrigin_Panels_Admin (inc/admin.php): single(); copy_content_to_post()
	 *   :525-577, which renders the layout into post_content (here: the texts of
	 *   the widgets) and stores it with wp_update_post() (here: the posts row);
	 *   with_save_guard() :592-601; kses_deep() :1472-1489, as written there,
	 *   with the harness' wp_kses_post(); double_slash_string() :2012.
	 * - The ability siteorigin-panels/layout-update (inc/abilities.php): the
	 *   framework's permission check (edit_post, :207-216 and WP_Ability::
	 *   execute()), then update_meta_layout() :378-473 — every widget's update()
	 *   (here: the Custom HTML widget's, which filters its content for an account
	 *   without unfiltered_html, wp-includes/widgets/class-wp-widget-custom-html.php),
	 *   the kses floor on all widgets, panels_data double-slashed into
	 *   update_post_meta(), the copy under the save guard.
	 * - wp_has_ability() / wp_get_ability() (wp-includes/abilities-api.php:357, :389).
	 */
	if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
		class SiteOrigin_Panels_Admin {
			public static $single = null;
			public static $copies = array();
			public static $throw  = false;
			public $in_save_post  = false;
			public static function single() {
				if ( null === self::$single ) {
					self::$single = new self();
				}
				return self::$single;
			}
			public function copy_content_to_post( $post, $post_id, $panels_data ) {
				self::$copies[] = array( 'id' => (int) $post_id, 'guarded' => $this->in_save_post );
				if ( self::$throw ) {
					throw new RuntimeException( 'render failed' );
				}
				$texts = array();
				array_walk_recursive(
					$panels_data['widgets'],
					static function ( $v, $k ) use ( &$texts ) {
						if ( is_string( $v ) && in_array( $k, array( 'text', 'title', 'alt', 'url', 'content' ), true ) ) {
							$texts[] = $v;
						}
					}
				);
				$GLOBALS['db_posts'][ (int) $post_id ]['post_content'] = '<div class="panel-layout">' . implode( "\n", $texts ) . '</div>';
			}
			public function with_save_guard( $callback ) {
				$previous           = $this->in_save_post;
				$this->in_save_post = true;
				try {
					return $callback();
				} finally {
					$this->in_save_post = $previous;
				}
			}
			public static function kses_deep( $value ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $key => $item ) {
						$value[ $key ] = self::kses_deep( $item );
					}
					return $value;
				}
				if ( is_string( $value ) ) {
					$needs_kses = false !== strpbrk( $value, '<>' ) || 1 === preg_match( '/&(?:#(?:[0-9]+|[xX][0-9A-Fa-f]+)|[A-Za-z][A-Za-z0-9]*);/', $value );
					return $needs_kses ? wp_kses_post( $value ) : preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
				}
				return $value;
			}
			public static function double_slash_string( $value ) {
				return is_string( $value ) ? addcslashes( $value, '\\' ) : $value;
			}
		}
		class AB_Test_SO_Ability {
			public $calls   = array();
			public $fail    = false;   // the ability returns a WP_Error
			public $decline = false;   // the ability answers updated=false
			public $alter   = false;   // a widget's update() rewrites the headline
			public function get_name() {
				return 'siteorigin-panels/layout-update';
			}
			public function execute( $input ) {
				$this->calls[] = $input;
				$id            = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return new WP_Error( 'ability_invalid_permissions', 'Ability "siteorigin-panels/layout-update" does not have necessary permission.' );
				}
				if ( $this->fail ) {
					return new WP_Error( 'ability_callback_exception', 'Ability "siteorigin-panels/layout-update" callback threw an exception: kaputt' );
				}
				if ( $this->decline ) {
					return array( 'post_id' => $id, 'updated' => false, 'source' => 'unsupported', 'message' => 'Layout not found.' );
				}
				$pd = $input['panels_data'];
				unset( $pd['sanitize_signature'] );
				foreach ( $pd['widgets'] as $i => $w ) {
					$class = isset( $w['panels_info']['class'] ) ? $w['panels_info']['class'] : '';
					if ( 'WP_Widget_Custom_HTML' === $class && ! current_user_can( 'unfiltered_html' ) ) {
						$pd['widgets'][ $i ]['content'] = wp_kses_post( $w['content'] );
					}
					if ( $this->alter && isset( $w['headline']['text'] ) ) {
						$pd['widgets'][ $i ]['headline']['text'] = 'Von SiteOrigin umgeschrieben';
					}
					unset( $pd['widgets'][ $i ]['panels_info']['raw'] );
				}
				$pd['widgets'] = SiteOrigin_Panels_Admin::kses_deep( $pd['widgets'] );
				update_post_meta( $id, 'panels_data', ab_so_map_deep( $pd ) );
				$admin = SiteOrigin_Panels_Admin::single();
				$post  = get_post( $id );
				$admin->with_save_guard(
					static function () use ( $admin, $post, $id, $pd ) {
						$admin->copy_content_to_post( $post, $id, $pd );
					}
				);
				return array( 'post_id' => $id, 'updated' => true, 'source' => 'meta' );
			}
		}
		function ab_so_map_deep( $v ) {
			if ( is_array( $v ) ) {
				foreach ( $v as $k => $x ) {
					$v[ $k ] = ab_so_map_deep( $x );
				}
				return $v;
			}
			return SiteOrigin_Panels_Admin::double_slash_string( $v );
		}
		$GLOBALS['so_ability']    = new AB_Test_SO_Ability();
		$GLOBALS['so_ability_on'] = false;
		function wp_has_ability( $name ) {
			return ! empty( $GLOBALS['so_ability_on'] ) && 'siteorigin-panels/layout-update' === $name;
		}
		function wp_get_ability( $name ) {
			if ( ! wp_has_ability( $name ) ) {
				ok( false, 'wp_get_ability() asked for an ability that is not registered (WordPress raises _doing_it_wrong)' );
				return null;
			}
			return $GLOBALS['so_ability'];
		}
	}
	function ab_so_reset( $ability_on ) {
		$GLOBALS['so_ability']        = new AB_Test_SO_Ability();
		$GLOBALS['so_ability_on']     = $ability_on;
		SiteOrigin_Panels_Admin::$copies = array();
		SiteOrigin_Panels_Admin::$throw  = false;
	}

	/* ---- the ability (2.36.1) ---- */
	ab_so_page( 61 );
	ab_so_reset( true );
	ok( true === $W->can_write( get_post( 61 ) ) && in_array( 'wp_update_builder_element', AB_MCP_Builders::write_via( get_post( 61 ) ), true ), 'S3 with SiteOrigin loaded the writer writes, and write_via names the tool' );
	$s0  = ab_db( 61 );
	$pre = $W->set_fields( get_post( 61 ), 'w0', array( 'headline.text' => 'Willkommen im Veloladen' ), array() );
	ok( is_array( $pre ) && false === $pre['applied'] && 'Willkommen bei Alpine Bikes' === $pre['changes'][0]['before'] && $s0 === ab_db( 61 ) && array() === $GLOBALS['so_ability']->calls, 'S4 preview: before as stored, nothing written, the ability not called' );
	ok( ab_has( $pre['notes'], 'through SiteOrigin Page Builder\'s layout-update ability' ) && ! ab_has( $pre['warnings'], 'profile not yet measured' ), 'S5 the preview names the way it will save; measured on a real installation (Page Builder 2.36.1): no «profile not yet measured»' );
	$want                                  = ab_so_data( 61 );
	$want['widgets'][0]['headline']['text'] = 'Willkommen im Veloladen';
	$res                                   = $W->set_fields( get_post( 61 ), 'w0', array( 'headline.text' => 'Willkommen im Veloladen' ), array( 'apply' => true, 'layout_hash' => $pre['layout_hash'] ) );
	ok( is_array( $res ) && true === $res['applied'] && true === $res['readback']['ok'], 'S6 applied through the ability, read back' );
	ok( 1 === count( $GLOBALS['so_ability']->calls ) && 61 === $GLOBALS['so_ability']->calls[0]['post_id'] && $want === $GLOBALS['so_ability']->calls[0]['panels_data'], 'S7 the ability got the stored layout with exactly this field changed' );
	ok( 'Willkommen im Veloladen' === ab_so_data( 61 )['widgets'][0]['headline']['text'] && 1 === count( SiteOrigin_Panels_Admin::$copies ) && true === SiteOrigin_Panels_Admin::$copies[0]['guarded'] && false !== strpos( $GLOBALS['db_posts'][61]['post_content'], 'Willkommen im Veloladen' ), 'S8 stored; SiteOrigin renewed its post_content copy under its save guard' );
	ab_undo( $res['undo_token'] );
	ok( $s0 === ab_db( 61 ), 'S9 wp_undo puts panels_data and the copy back byte for byte' );

	/* ---- a page with a script: not through the ability ---- */
	ab_so_page( 62, 'page-more.json' );
	ab_so_reset( true );
	$want                         = ab_so_data( 62 );
	$want['widgets'][3]['text']   = 'Jetzt buchen';
	$pre                          = $W->set_fields( get_post( 62 ), 'w3', array( 'text' => 'Jetzt buchen' ), array() );
	ok( is_array( $pre ) && ab_has( $pre['notes'], 'not through its layout-update ability' ), 'S10 a page whose markup the ability would filter (a Custom HTML widget with a script): the preview says it saves the editor\'s way' );
	$res = $W->set_fields( get_post( 62 ), 'w3', array( 'text' => 'Jetzt buchen' ), array( 'apply' => true, 'layout_hash' => $pre['layout_hash'] ) );
	ok( is_array( $res ) && true === $res['applied'] && array() === $GLOBALS['so_ability']->calls, 'S11 applied without the ability' );
	ok( $want === ab_so_data( 62 ) && false !== strpos( ab_so_data( 62 )['widgets'][5]['content'], '<script>window.mzRoh=1;</script>' ), 'S12 panels_data holds exactly the change; the script of the untouched widget stays' );
	ok( 1 === count( SiteOrigin_Panels_Admin::$copies ) && true === SiteOrigin_Panels_Admin::$copies[0]['guarded'], 'S13 the post_content copy renewed through SiteOrigin, under its save guard' );
	// The same page through the ability would have lost the script: what the route avoids.
	ab_so_page( 63, 'page-more.json' );
	ab_so_reset( true );
	unset( $GLOBALS['ab_caps']['unfiltered_html'] );
	$GLOBALS['so_ability']->execute( array( 'post_id' => 63, 'panels_data' => ab_so_data( 63 ) ) );
	ok( false === strpos( ab_so_data( 63 )['widgets'][5]['content'], '<script>' ), 'S14 (control) the ability itself strips that script, for any account' );
	$GLOBALS['ab_caps']['unfiltered_html'] = true;

	/* ---- the editor's way keeps backslashes (wp_slash before update_post_meta) ---- */
	ab_so_page( 64, 'page-more.json' );
	ab_so_reset( false );
	$rows                                     = ab_meta_rows( 64, 'panels_data' );
	$data                                     = unserialize( reset( $rows ) );
	$data['widgets'][6]['text']               = 'Pfad C:\\Daten\\neu und "Zitat"';
	$GLOBALS['db_meta'][ key( $rows ) ][2]    = serialize( $data );
	$res = $W->set_fields( get_post( 64 ), 'w6', array( 'title' => 'Mit Backslash \\ im Titel' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 64 ) ) ) );
	$got = ab_so_data( 64 )['widgets'][6];
	ok( is_array( $res ) && ab_has( $res['notes'], 'has no layout-update ability' ) && 'Mit Backslash \\ im Titel' === $got['title'] && 'Pfad C:\\Daten\\neu und "Zitat"' === $got['text'], 'S15 without the ability (before 2.36.1): stored the editor\'s way, backslashes and quotes kept' );

	/* ---- the ability refuses or declines ---- */
	ab_so_page( 65 );
	ab_so_reset( true );
	$s0                            = ab_db( 65 );
	$GLOBALS['so_ability']->fail   = true;
	$res                           = $W->set_fields( get_post( 65 ), 'w0', array( 'headline.text' => 'Neu' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 65 ) ) ) );
	ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'kaputt' ) && false !== strpos( $res->get_error_message(), 'The page is unchanged' ) && $s0 === ab_db( 65 ) && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'S16 an error of the ability: passed on, page unchanged, no undo point left' );
	ab_so_reset( true );
	$GLOBALS['so_ability']->decline = true;
	$res                            = $W->set_fields( get_post( 65 ), 'w0', array( 'headline.text' => 'Neu' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 65 ) ) ) );
	ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'Layout not found.' ) && $s0 === ab_db( 65 ), 'S17 an ability that answers updated=false: SiteOrigin\'s reason passed on, page unchanged' );

	/* ---- read-back: SiteOrigin stored something else ---- */
	ab_so_page( 66 );
	ab_so_reset( true );
	$s0                           = ab_db( 66 );
	$GLOBALS['so_ability']->alter = true;
	$res                          = $W->set_fields( get_post( 66 ), 'w0', array( 'headline.text' => 'Neu' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 66 ) ) ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_readback_failed' === $res->get_error_code() && true === $res->get_error_data()['rolled_back'] && $s0[0] === ab_db( 66 )[0] && array_values( $s0[1] ) === array_values( ab_db( 66 )[1] ), 'S18 a widget update() that rewrites the value: the read-back fails, panels_data and the copy are put back byte for byte' );

	/* ---- image, locked widgets ---- */
	ab_so_page( 67 );
	ab_so_reset( true );
	$res = $W->set_fields( get_post( 67 ), 'w3', array( 'image_id' => 55, 'alt' => 'Neues Bild' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 67 ) ) ) );
	ok( is_array( $res ) && 55 === ab_so_data( 67 )['widgets'][3]['image'] && 'Neues Bild' === ab_so_data( 67 )['widgets'][3]['alt'], 'S19 the image widget takes an attachment id and its alt text' );
	ab_so_page( 68, 'page-more.json' );
	ab_so_reset( true );
	$s0 = ab_db( 68 );
	foreach ( array( 'w5' => 'html widget', 'w2' => 'shortcode' ) as $wid => $why ) {
		$res = $W->set_fields( get_post( 68 ), $wid, array( 'text' => 'x' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 68 ) ) ) );
		ok( is_wp_error( $res ) && 'ab_mcp_builder_locked' === $res->get_error_code() && false !== strpos( $res->get_error_message(), $why ), "S20 locked $why ($wid) refused" );
	}
	ok( $s0 === ab_db( 68 ) && array() === $GLOBALS['so_ability']->calls, 'S21 nothing written' );
	$res = $W->set_fields( get_post( 68 ), 'w3', array( 'url' => 'javascript:alert(1)' ), array() );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_url' === $res->get_error_code(), 'S22 a javascript: link refused by the field rules' );

	/* ---- the copy fails: the layout still counts ---- */
	ab_so_page( 69, 'page-more.json' );
	ab_so_reset( true );
	SiteOrigin_Panels_Admin::$throw = true;
	$pc0 = $GLOBALS['db_posts'][69]['post_content'];
	$res = $W->set_fields( get_post( 69 ), 'w3', array( 'text' => 'Jetzt buchen' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 69 ) ) ) );
	ok( is_array( $res ) && true === $res['applied'] && 'Jetzt buchen' === ab_so_data( 69 )['widgets'][3]['text'] && ab_has( $res['warnings'], 'SiteOrigin failed while renewing the post_content copy (RuntimeException)' ) && $pc0 === $GLOBALS['db_posts'][69]['post_content'], 'S23 SiteOrigin fails to renew its copy: the layout is stored, the answer warns, the copy stays' );

	/* ---- replace ---- */
	ab_so_page( 70, 'page-more.json' );
	ab_so_reset( true );
	$res = $W->replace( get_post( 70 ), 'https://example.com/', 'https://example.org/', array( 'apply' => true, 'scope' => 'text', 'undo_tool' => 'wp_search_replace' ) );
	$d   = ab_so_data( 70 );
	ok( is_array( $res ) && 4 === count( $res['changes'] ) && 'https://example.org/reservieren' === $d['widgets'][3]['url'] && 'https://example.org/mz-bild-link' === $d['widgets'][4]['url'] && 'https://example.org/mz-headline-link' === $d['widgets'][0]['headline']['destination_url'], 'S24 replace: every link with the address changed (' . ( is_array( $res ) ? count( $res['changes'] ) : 0 ) . ')' );

	ab_so_page( 72, 'page-more.json' );
	ab_so_reset( true );
	$s0  = ab_db( 72 );
	$res = $W->replace( get_post( 72 ), 'https://example.com/', 'javascript:alert(1)//', array( 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_nothing' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'Left w3:url as it is' ) && false !== strpos( $res->get_error_message(), 'javascript:' ) && $s0 === ab_db( 72 ), 'S28 replace that would make every link a javascript: link: each one left and named with the reason, nothing written' );
	ab_so_page( 73, 'page-more.json' );
	ab_so_reset( true );
	$res = $W->replace( get_post( 73 ), 'HTTPS://Example.com/', 'https://example.org/', array( 'apply' => true, 'case_insensitive' => true, 'scope' => 'text', 'undo_tool' => 'wp_search_replace' ) );
	$d   = ab_so_data( 73 );
	ok( is_array( $res ) && 4 === count( $res['changes'] ) && 'https://example.org/reservieren' === $d['widgets'][3]['url'] && 'https://example.org/mz-headline-link' === $d['widgets'][0]['headline']['destination_url'], 'S29 case_insensitive: the same four links as S24 (' . ( is_array( $res ) ? count( $res['changes'] ) : 'error' ) . ')' );
	ab_so_page( 74, 'page-more.json' );
	ab_so_reset( true );
	$s0  = ab_db( 74 );
	$res = $W->replace( get_post( 74 ), 'https://example\.com/', 'https://example.org/', array( 'apply' => true, 'regex' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_regex' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'SiteOrigin' ) && $s0 === ab_db( 74 ), 'S30 regex is refused with the way, nothing written' );
	// An editor widget with an embedded map: the field rules leave it (post
	// content may not hold the iframe), so its match is not changed — and
	// must not vanish either: counted as locked, the reason handed on.
	ab_so_page( 75 );
	ab_so_reset( true );
	$rows                                  = ab_meta_rows( 75, 'panels_data' );
	$data                                  = unserialize( reset( $rows ) );
	$data['widgets'][1]['text']            = '<p>Anfahrt: https://alt.example/anfahrt</p><iframe src="https://www.google.com/maps/embed?pb=1"></iframe>';
	$data['widgets'][2]['url']             = 'https://alt.example/kontakt';
	$GLOBALS['db_meta'][ key( $rows ) ][2] = serialize( $data );
	$s0  = ab_db( 75 );
	$res = $W->replace( get_post( 75 ), 'https://alt.example', 'https://neu.example', array( 'scope' => 'text', 'undo_tool' => 'wp_search_replace' ) );
	ok( is_array( $res ) && 1 === count( $res['changes'] ) && 'w2' === $res['changes'][0]['element'] && 1 === $res['locked_matches'] && 1 === count( $res['left'] ) && 0 === strpos( $res['left'][0], 'Left w1:text as it is' ), 'S31 replace: the button link changes; the editor text with the map is left, its match counted as locked and its reason in left' );
	$data['widgets'][2]['url']             = '/kontakt';
	$GLOBALS['db_meta'][ key( $rows ) ][2] = serialize( $data );
	$res = $W->replace( get_post( 75 ), 'https://alt.example', 'https://neu.example', array( 'apply' => true, 'scope' => 'text', 'undo_tool' => 'wp_search_replace' ) );
	$d   = is_wp_error( $res ) ? $res->get_error_data() : array();
	ok( is_wp_error( $res ) && 'ab_mcp_builder_nothing' === $res->get_error_code() && 1 === $d['locked_matches'] && 1 === count( $d['left'] ) && 0 === strpos( $d['left'][0], 'Left w1:text as it is' ) && $s0[0] === ab_db( 75 )[0], 'S32 the map is the only match: nothing written, the match counted as locked (wp_search_replace names scope raw) and the reason in the data' );

	// (Free: the section on Pro's ability switches is left out; see etappe-b/port_tests.py.)

	/* ---- the tool ---- */
	ab_so_page( 71 );
	ab_so_reset( true );
	$res = ab_tool( array( 'id' => 71, 'element_id' => 'w2', 'fields' => array( 'text' => 'Jetzt buchen', 'url' => '/buchen' ), 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 71 ) ) ) );
	ok( is_array( $res ) && true === $res['applied'] && 'siteorigin' === $res['builder'] && '/buchen' === $res['element']['after']['url'] && '/buchen' === ab_so_data( 71 )['widgets'][2]['url'], 'S25 wp_update_builder_element changes text and link of a button widget' );
	$res = ab_tool( array( 'id' => 71, 'element_id' => 'w2', 'fields' => array( 'text' => 'Wieder' ), 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_path_needs_hash' === $res->get_error_code(), 'S26 a widget id is a position: applying without layout_hash is refused' );
	ab_so_reset( false );
	$s0  = ab_db( 71 );
	$res = $W->set_fields( get_post( 71 ), 'w2', array( 'text' => 'Jetzt buchen' ), array( 'apply' => true, 'layout_hash' => AB_MCP_Builders::layout_hash( get_post( 71 ) ) ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_nothing' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'already hold these values' ) && $s0 === ab_db( 71 ) && array() === SiteOrigin_Panels_Admin::$copies, 'S27 a field that already holds the value: said so, nothing written (update_post_meta() would report an unchanged value as a failure)' );

	ab_result( 'BUILDER-WRITERS (siteorigin)-CHECKS' );
}

/* ============================================================ seedprod */

if ( 'seedprod' === $scenario ) {
	$W = AB_MCP_Builder_Writers::for_adapter( 'seedprod' );
	ok( $W instanceof AB_MCP_Builder_Writer_SeedProd, 'P0 the SeedProd writer registers from its file under the reader\'s id' );

	$PAGE = ab_fixture( 'seedprod', 'page.json' );
	$EDIT = ab_fixture( 'seedprod', 'editor-html.json' );
	/**
	 * The HTML SeedProd's editor wrote for this page: the measured excerpts
	 * around each field (sp-2-nach-editor.json, or sp-3-alphabridge.json, where
	 * only post_content had been changed), in page order — not the whole
	 * post_content, which the measurement did not keep.
	 */
	function ab_sp_html( $run = 'sp-2-nach-editor.json' ) {
		$f   = ab_fixture( 'seedprod', 'editor-html.json' )['fragments'];
		$e   = $f[ $run ];
		$txt = isset( $e['text'] ) ? $e['text'] : $e['neu_text'];
		return '<div id="sp-page">' . implode( ' ', array( $e['h1'], $txt, $e['link'], $e['button'], $e['bild_datei'] ) ) . '</div>';
	}
	/** JSON as SeedProd's editor writes it: letters and slashes as they are (measured), here re-encoded from the ability's form. */
	function ab_sp_unescaped( $json ) {
		return json_encode( json_decode( $json ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	function ab_sp_page( $id, $html, $json ) {
		ab_post( $id, array( 'post_content' => $html, 'post_content_filtered' => $json ) );
		ab_clear_meta( $id );
		foreach ( ab_fixture( 'seedprod', 'page.json' )['meta'] as $k => $v ) {
			ab_meta_add( $id, $k, $v );
		}
		ab_fresh();
	}
	function ab_sp_hash( $id ) {
		return AB_MCP_Builders::layout_hash( get_post( $id ) );
	}
	$J = $PAGE['post_content_filtered'];

	/* ---- a page saved only through SeedProd's ability (as measured): nothing to keep in step ---- */
	ab_sp_page( 60, $PAGE['post_content'], $J );
	$can = $W->can_write( get_post( 60 ) );
	ok( '' === $PAGE['post_content'] && is_wp_error( $can ) && false !== strpos( $can->get_error_message(), 'Open the page in SeedProd and save it once' ), 'P1 post_content empty (measured: a page saved through the ability): refused, with the way' );
	ok( ! in_array( 'wp_update_builder_element', AB_MCP_Builders::write_via( get_post( 60 ) ), true ), 'P2 write_via does not name the tool there' );

	/* ---- both copies, the JSON as the ability writes it (escaped) ---- */
	ab_sp_page( 61, ab_sp_html(), $J );
	$s0 = ab_db( 61 );
	ok( true === $W->can_write( get_post( 61 ) ) && in_array( 'wp_update_builder_element', AB_MCP_Builders::write_via( get_post( 61 ) ), true ), 'P3 a page with both copies: the writer writes, write_via names the tool' );
	$pre = $W->set_fields( get_post( 61 ), 'blk001', array( 'headerTxt' => 'Willkommen im Veloladen' ), array() );
	ok( is_array( $pre ) && false === $pre['applied'] && 'Willkommen bei Alpine Bikes' === $pre['changes'][0]['before'] && $s0 === ab_db( 61 ), 'P4 preview: before as stored, nothing written' );
	ok( ab_has( $pre['notes'], 'both are changed together, with one undo point' ) && ! ab_has( $pre['warnings'], 'profile not yet measured' ), 'P5 the preview says both copies change together; measured on a real installation (SeedProd Lite 6.20.10): no «profile not yet measured»' );
	$SPW = 'SeedProd does not lock a page while its editor is open: a SeedProd editor that is open now will put the old text back the next time it saves. Close or reload the SeedProd editor first.';
	ok( in_array( $SPW, $pre['warnings'], true ), 'P5b SeedProd sets no _edit_lock while its editor is open (measured, 6.20.10): the preview warns that an open SeedProd editor puts the old text back, and names the way' );
	$res = $W->set_fields( get_post( 61 ), 'blk001', array( 'headerTxt' => 'Willkommen im Veloladen' ), array( 'apply' => true, 'layout_hash' => $pre['layout_hash'] ) );
	ok( is_array( $res ) && true === $res['applied'] && true === $res['readback']['ok'], 'P6 applied, read back' );
	ok( in_array( $SPW, $res['warnings'], true ) && array() === ab_meta_rows( 61, '_edit_lock' ), 'P6b the applied answer carries the same warning, also with no _edit_lock on the page' );
	ok( str_replace( '"headerTxt":"Willkommen bei Alpine Bikes"', '"headerTxt":"Willkommen im Veloladen"', $J ) === $GLOBALS['db_posts'][61]['post_content_filtered'], 'P7 the JSON: that one value, nothing else, byte for byte' );
	ok( str_replace( '>Willkommen bei Alpine Bikes<', '>Willkommen im Veloladen<', $s0[0]['post_content'] ) === $GLOBALS['db_posts'][61]['post_content'], 'P8 the HTML: that one text, nothing else' );
	$entry = AB_MCP_Undo::entry_for( $res['undo_token'] );
	ok( isset( $entry['after']['61']['post_content'], $entry['after']['61']['post_content_filtered'] ), 'P9 one undo point holds both copies' );
	ab_undo( $res['undo_token'] );
	ok( $s0 === ab_db( 61 ), 'P10 wp_undo puts both back byte for byte' );

	// Rich text with letters JSON escapes: the token keeps the form it had.
	$res = $W->set_fields( get_post( 61 ), 'blk002', array( 'txt' => '<p>Öffnungszeiten: Mo–Sa 9–17 Uhr</p>' ), array( 'apply' => true ) );
	ok( is_array( $res ) && false !== strpos( $GLOBALS['db_posts'][61]['post_content_filtered'], '"txt":"<p>\u00d6ffnungszeiten: Mo\u2013Sa 9\u201317 Uhr<\/p>"' ) && false !== strpos( $GLOBALS['db_posts'][61]['post_content'], '><p>Öffnungszeiten: Mo–Sa 9–17 Uhr</p></div>' ), 'P11 escaped JSON stays escaped (\\u00d6, \\/), the HTML takes the markup as it is' );
	$res = $W->set_fields( get_post( 61 ), 'blk003', array( 'link' => 'https://example.org/buchen?a=1&b=2' ), array( 'apply' => true ) );
	ok( is_array( $res ) && false !== strpos( $GLOBALS['db_posts'][61]['post_content_filtered'], '"link":"https:\/\/example.org\/buchen?a=1&b=2"' ) && false !== strpos( $GLOBALS['db_posts'][61]['post_content'], 'href="https://example.org/buchen?a=1&amp;b=2"' ), 'P12 a link: escaped in the JSON as it was, as an attribute in the HTML' );
	$res = $W->set_fields( get_post( 61 ), 'blk003', array( 'btnTxt' => 'Jetzt & sofort' ), array( 'apply' => true ) );
	ok( is_array( $res ) && false !== strpos( $GLOBALS['db_posts'][61]['post_content_filtered'], '"btnTxt":"Jetzt & sofort"' ) && false !== strpos( $GLOBALS['db_posts'][61]['post_content'], '<span>Jetzt &amp; sofort</span>' ), 'P13 a button label with &: as text in the JSON, escaped as a browser writes it in the HTML' );
	$res = $W->set_fields( get_post( 61 ), 'blk004', array( 'altTxt' => 'Das "Velo" vor dem Laden' ), array( 'apply' => true ) );
	ok( is_array( $res ) && false !== strpos( $GLOBALS['db_posts'][61]['post_content_filtered'], '"altTxt":"Das \"Velo\" vor dem Laden"' ) && false !== strpos( $GLOBALS['db_posts'][61]['post_content'], 'alt="Das &quot;Velo&quot; vor dem Laden"' ), 'P14 an alt text: an attribute value in the HTML, quotes escaped' );

	/* ---- the JSON as SeedProd's editor writes it (letters as they are) ---- */
	$U = ab_sp_unescaped( $J );
	ab_sp_page( 62, ab_sp_html(), $U );
	ok( false !== strpos( $U, '"txt":"<p>Öffnungszeiten: Mo–Fr 9–18 Uhr</p>"' ) && false !== strpos( $U, '"link":"https://example.com/reservieren"' ), 'P15 (setup) the editor\'s form of the JSON' );
	$res = $W->set_fields( get_post( 62 ), 'blk002', array( 'txt' => '<p>Öffnungszeiten: Mo–Sa 9–17 Uhr</p>' ), array( 'apply' => true ) );
	ok( is_array( $res ) && str_replace( 'Mo–Fr 9–18 Uhr</p>"', 'Mo–Sa 9–17 Uhr</p>"', $U ) === $GLOBALS['db_posts'][62]['post_content_filtered'], 'P16 unescaped JSON stays unescaped, nothing else changes' );

	/* ---- what stays readable only ---- */
	ab_sp_page( 63, ab_sp_html(), $J );
	$s0  = ab_db( 63 );
	$res = $W->set_fields( get_post( 63 ), 'blk004', array( 'src' => 55 ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'not images' ) && false !== strpos( $res->get_error_message(), 'Change the image in SeedProd\'s editor' ) && $s0 === ab_db( 63 ), 'P17 an image: refused with the way, nothing written' );
	// The HTML shows another version of the text (measured: only post_content had been changed).
	ab_sp_page( 64, ab_sp_html( 'sp-3-alphabridge.json' ), $J );
	$s0  = ab_db( 64 );
	$res = $W->set_fields( get_post( 64 ), 'blk002', array( 'txt' => '<p>Neu</p>' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_copies_differ' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'Open and save the page in SeedProd\'s editor' ) && $s0 === ab_db( 64 ), 'P18 the site shows another version than SeedProd\'s data (measured case): refused, nothing written' );
	$res = $W->set_fields( get_post( 64 ), 'blk001', array( 'headerTxt' => 'Neu' ), array( 'apply' => true ) );
	ok( is_array( $res ) && true === $res['applied'], 'P19 a field of the same page whose copies agree is still written' );
	// The heading's own HTML is stale, a paragraph elsewhere holds the same words.
	$html = str_replace( array( '>Willkommen bei Alpine Bikes</h1>', '<p>Öffnungszeiten: Mo–Fr 9–18 Uhr</p>' ), array( '>Willkommen bei uns</h1>', '<p>Willkommen bei Alpine Bikes</p>' ), ab_sp_html() );
	ab_sp_page( 65, $html, $J );
	$s0  = ab_db( 65 );
	$res = $W->set_fields( get_post( 65 ), 'blk001', array( 'headerTxt' => 'Neu' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_copies_differ' === $res->get_error_code() && $s0 === ab_db( 65 ), 'P20 the same words in another block\'s HTML are never taken for the heading: refused' );
	// The label twice inside its own block.
	ab_sp_page( 66, str_replace( '<span>Jetzt reservieren</span>', '<span>Jetzt reservieren</span><span>Jetzt reservieren</span>', ab_sp_html() ), $J );
	$s0  = ab_db( 66 );
	$res = $W->set_fields( get_post( 66 ), 'blk003', array( 'btnTxt' => 'Buchen' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'stands 1 time(s) in SeedProd\'s data and 2 time(s)' ) && $s0 === ab_db( 66 ), 'P21 more places in the HTML than fields: refused, nothing written' );

	/* ---- the same label on two buttons ---- */
	$dj = json_decode( $J, true );
	$b2 = $dj['document']['sections'][0]['rows'][0]['cols'][0]['blocks'][2];
	$b2['id'] = 'blk005';
	$dj['document']['sections'][0]['rows'][0]['cols'][0]['blocks'][] = $b2;
	$J2   = wp_json_encode( $dj );
	$e    = $EDIT['fragments']['sp-2-nach-editor.json'];
	$html = substr( ab_sp_html(), 0, -6 ) . ' ' . str_replace( 'blk003', 'blk005', $e['link'] . ' ' . $e['button'] ) . '</div>';
	ab_sp_page( 67, $html, $J2 );
	$s0  = ab_db( 67 );
	$res = $W->set_fields( get_post( 67 ), 'blk003', array( 'btnTxt' => 'Buchen' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'stands 2 time(s) in SeedProd\'s data' ) && $s0 === ab_db( 67 ), 'P22 one of two buttons with the same label: SeedProd\'s data cannot say which place is which: refused' );
	$res = $W->replace( get_post( 67 ), 'Jetzt reservieren', 'Jetzt buchen', array( 'apply' => true, 'scope' => 'text', 'undo_tool' => 'wp_search_replace' ) );
	ok( is_array( $res ) && 2 === count( $res['changes'] ) && 2 === substr_count( $GLOBALS['db_posts'][67]['post_content_filtered'], '"btnTxt":"Jetzt buchen"' ) && 2 === substr_count( $GLOBALS['db_posts'][67]['post_content'], '<span>Jetzt buchen</span>' ) && 0 === substr_count( $GLOBALS['db_posts'][67]['post_content'], 'Jetzt reservieren' ), 'P23 replace over both: both buttons, both copies' );
	ok( in_array( $SPW, $res['warnings'], true ), 'P23b a replacement (wp_search_replace) carries the warning too' );

	/* ---- a block without its own id ---- */
	$dj = json_decode( $J, true );
	unset( $dj['document']['sections'][0]['rows'][0]['cols'][0]['blocks'][3]['id'] );
	ab_sp_page( 68, ab_sp_html(), wp_json_encode( $dj ) );
	$res = $W->set_fields( get_post( 68 ), 'p0.0.0.3', array( 'altTxt' => 'Neu' ), array( 'apply' => true, 'layout_hash' => ab_sp_hash( 68 ) ) );
	ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'has no id of its own' ), 'P24 a block addressed by its position: its HTML cannot be found, refused' );

	/* ---- replace: some fields left, each with its reason ---- */
	ab_sp_page( 69, str_replace( 'href="https://example.com/reservieren"', 'href="/alt"', ab_sp_html() ), $J );
	$res = $W->replace( get_post( 69 ), 'reservieren', 'buchen', array( 'apply' => true, 'scope' => 'text', 'undo_tool' => 'wp_search_replace' ) );
	ok( is_array( $res ) && 1 === count( $res['changes'] ) && 'btnTxt' === $res['changes'][0]['field'] && ab_has( $res['notes'], 'blk003:link' ), 'P25 replace: the label changes; the link, whose HTML shows another version, is left and named' );
	ok( false !== strpos( $GLOBALS['db_posts'][69]['post_content_filtered'], '"link":"https:\/\/example.com\/reservieren"' ), 'P26 the left link is untouched in both copies' );
	ab_sp_page( 90, str_replace( 'href="https://example.com/reservieren"', 'href="/alt"', ab_sp_html() ), $J );
	$res = $W->replace( get_post( 90 ), 'RESERVIEREN', 'buchen', array( 'apply' => true, 'case_insensitive' => true, 'scope' => 'text', 'undo_tool' => 'wp_search_replace' ) );
	ok( is_array( $res ) && 1 === count( $res['changes'] ) && 'btnTxt' === $res['changes'][0]['field'] && 'Jetzt buchen' === $res['changes'][0]['after'] && false !== strpos( $GLOBALS['db_posts'][90]['post_content'], '<span>Jetzt buchen</span>' ) && false !== strpos( $GLOBALS['db_posts'][90]['post_content_filtered'], '"btnTxt":"Jetzt buchen"' ), 'P26b case_insensitive: «RESERVIEREN» changes the label as P25 does, in both copies' );
	ab_sp_page( 91, ab_sp_html(), $J );
	$s0  = ab_db( 91 );
	$res = $W->replace( get_post( 91 ), 'reserv.eren', 'buchen', array( 'apply' => true, 'regex' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_regex' === $res->get_error_code() && $s0 === ab_db( 91 ), 'P26c regex is refused with the way, nothing written' );
	$CODE = ab_fixture( 'seedprod', 'code-page.json' );
	ab_sp_page( 70, $EDIT['code_page'], $CODE['post_content_filtered'] );
	$res = $W->replace( get_post( 70 ), 'MZ Ueberschrift', 'Titel', array( 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_nothing' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'blk104:headerTxt' ), 'P27 replace where every match is left: the answer says which and why, not just «nothing»' );
	$res = $W->set_fields( get_post( 70 ), 'blk101', array( 'code' => 'x' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_locked' === $res->get_error_code(), 'P28 a custom-html block is locked' );

	/* ---- read-back: somebody changes the HTML right after the save ---- */
	ab_sp_page( 71, ab_sp_html(), $J );
	$GLOBALS['ab_sp_race'] = true;
	add_filter(
		'ab_mcp_builder_element_profiles',
		static function ( $profile, $builder ) {
			if ( ! empty( $GLOBALS['ab_sp_race'] ) && 'seedprod' === $builder && false !== strpos( $GLOBALS['db_posts'][71]['post_content'], 'Willkommen im Veloladen' ) ) {
				$GLOBALS['ab_sp_race']                    = false;
				$GLOBALS['db_posts'][71]['post_content'] .= '<!-- jemand anderes -->';
			}
			return $profile;
		},
		10,
		2
	);
	$res = $W->set_fields( get_post( 71 ), 'blk001', array( 'headerTxt' => 'Willkommen im Veloladen' ), array( 'apply' => true ) );
	ok( is_wp_error( $res ) && 'ab_mcp_builder_readback_failed' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'post_content did not hold what was written' ) && false === $res->get_error_data()['rolled_back'] && null !== $res->get_error_data()['undo_token'], 'P29 the HTML changed by someone else right after the save: the read-back fails, nothing is put back over it, the undo token is named' );

	/* ---- an attribute value is always escaped, whatever form the old one had ---- */
	// The old alt text holds an entity, in both copies: in the HTML it stands
	// as it is, unescaped (kses keeps it so). A quote in the new value must
	// not end the attribute: this write passes kses by.
	$GLOBALS['ab_caps']['unfiltered_html'] = false;
	$html = str_replace( 'alt="Velo vor dem Laden"', 'alt="A &amp; B"', ab_sp_html() );
	ab_sp_page( 73, $html, str_replace( '"altTxt":"Velo vor dem Laden"', '"altTxt":"A &amp; B"', $J ) );
	$res = $W->set_fields( get_post( 73 ), 'blk004', array( 'altTxt' => 'x" onload="alert(document.domain)' ), array( 'apply' => true ) );
	preg_match( '~<img[^>]*>~', $GLOBALS['db_posts'][73]['post_content'], $img );
	ok( is_array( $res ) && true === $res['applied'] && false !== strpos( $img[0], 'alt="x&quot; onload=&quot;alert(document.domain)"' ) && false === strpos( $img[0], 'onload="' ), 'P31 an account without unfiltered_html, a quote in the new alt text: escaped in the attribute, no attribute of its own' );
	$GLOBALS['ab_caps']['unfiltered_html'] = true;
	$res = $W->set_fields( get_post( 73 ), 'blk004', array( 'altTxt' => 'Velo & Zubehör' ), array( 'apply' => true ) );
	ok( is_array( $res ) && false !== strpos( $GLOBALS['db_posts'][73]['post_content'], 'alt="Velo &amp; Zubehör"' ), 'P32 an & in an attribute is written as a browser writes it' );

	/* ---- the tool ---- */
	ab_sp_page( 72, ab_sp_html(), $J );
	$res = ab_tool( array( 'id' => 72, 'element_id' => 'blk003', 'fields' => array( 'text' => 'Jetzt buchen', 'url' => '/buchen' ), 'apply' => true ) );
	ok( is_array( $res ) && true === $res['applied'] && 'seedprod' === $res['builder'] && false !== strpos( $GLOBALS['db_posts'][72]['post_content'], 'href="/buchen"' ) && false !== strpos( $GLOBALS['db_posts'][72]['post_content'], '<span>Jetzt buchen</span>' ), 'P30 wp_update_builder_element changes label and link of a SeedProd button in both copies' );
	ok( in_array( $SPW, $res['warnings'], true ), 'P30b and its answer warns about an open SeedProd editor' );
	$res = ab_tool( array( 'id' => 72, 'element_id' => 'blk004', 'fields' => array( 'src' => 55 ), 'apply' => true ) );
	ok( is_wp_error( $res ), 'P30c (a refused write is an error and writes nothing; no warning needed)' );

	ab_result( 'BUILDER-WRITERS (seedprod)-CHECKS' );
}

ab_result( 'BUILDER-WRITERS (' . $scenario . ')-CHECKS' );
