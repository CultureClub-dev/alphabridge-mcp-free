<?php
/**
 * How the pairs in this folder were measured (not run by the tests): the
 * block pages of the free core's fixtures through wp_kses( …, 'post' ) of a
 * real WordPress, loaded without a database — the files wp_kses() and the
 * block parser need, the default pre_kses filters, blog_charset UTF-8 — and
 * compared with AB_MCP_Pro_Kses_Guard.
 *
 * Run: WP=<WordPress 7.0.2> FIX=<free>/tests/fixtures/builders OUT=test/fixtures/kses \
 *      php -d error_reporting=24575 test/fixtures/kses/messen.php
 * (measured 02.10.2026 with ~/.wp-env/wp-env-wporg-4.0.0-f26facd6/WordPress
 * and the free fixtures of integration/free-4.4, 23ce9cb).
 */

define( 'ABSPATH', rtrim( (string) getenv( 'WP' ), '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
foreach ( array( 'compat-utf8.php', 'compat.php', 'plugin.php', 'class-wp-token-map.php', 'utf8.php', 'load.php', 'functions.php', 'formatting.php', 'html-api/html5-named-character-references.php', 'html-api/class-wp-html-attribute-token.php', 'html-api/class-wp-html-span.php', 'html-api/class-wp-html-doctype-info.php', 'html-api/class-wp-html-text-replacement.php', 'html-api/class-wp-html-decoder.php', 'html-api/class-wp-html-tag-processor.php', 'kses.php', 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php', 'blocks.php' ) as $file ) {
	require ABSPATH . WPINC . '/' . $file;
}
add_filter( 'pre_kses', 'wp_pre_kses_less_than' );
add_filter( 'pre_kses', 'wp_pre_kses_block_attributes', 10, 3 );
add_filter( 'pre_option_blog_charset', static function () { return 'UTF-8'; } );
require dirname( __DIR__, 3 ) . '/alphabridge-mcp-pro/includes/class-kses-guard.php';

$pick = array( 'core/cover', 'core/image', 'core/media-text', 'core/sonderzeichen', 'core/paragraph', 'generateblocks/gb1-image', 'generateblocks/gb2-button', 'kadence/image', 'kadence/singlebtn-sonderzeichen', 'spectra/about-20', 'stackable/image', 'pagelayer/code-page' );
foreach ( glob( getenv( 'FIX' ) . '/{core,generateblocks,kadence,spectra,stackable,pagelayer,seedprod}/*.json', GLOB_BRACE ) as $f ) {
	$j = json_decode( file_get_contents( $f ), true );
	if ( ! isset( $j['content'] ) ) {
		continue;
	}
	$c    = $j['content'];
	$k    = wp_kses_post( $c );
	$name = basename( dirname( $f ) ) . '/' . basename( $f, '.json' );
	$diff = AB_MCP_Pro_Kses_Guard::canonical( $k ) !== AB_MCP_Pro_Kses_Guard::canonical( $c );
	printf( "%-46s bytes:%s meaning:%s\n", $name, $k === $c ? 'same' : 'diff', $diff ? 'CHANGED' : 'kept' );
	if ( getenv( 'OUT' ) && in_array( $name, $pick, true ) ) {
		file_put_contents(
			getenv( 'OUT' ) . '/' . str_replace( '/', '-', $name ) . '.json',
			json_encode(
				array(
					'source'  => 'free core tests/fixtures/builders/' . $name . '.json (integration/free-4.4, 23ce9cb), from ' . $j['source'],
					'kses'    => 'wp_kses( content, \'post\' ) of WordPress 7.0.2 (~/.wp-env/wp-env-wporg-4.0.0-f26facd6/WordPress), run without a database with the default pre_kses filters (wp_pre_kses_less_than, wp_pre_kses_block_attributes), blog_charset UTF-8; measured 02.10.2026',
					'changed' => $diff,
					'content' => $c,
					'stored'  => $k,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			) . "\n"
		);
	}
}
