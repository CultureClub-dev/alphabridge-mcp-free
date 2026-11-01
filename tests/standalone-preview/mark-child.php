<?php
/**
 * The mark of the password page with nothing loaded but what the must-use
 * loader of the preview protection loads (AB_MCP_Settings,
 * AB_MCP_Page_Cache, AB_MCP_Preview_Protection; loader_source()), as on
 * wp-activate.php: AB_MCP_OAuth is not there, so the mark must not reach
 * for it. Started by PreviewProtectionTest in a process of its own, since
 * the classes are loaded in the test process from the start.
 *
 * Output is buffered from the first line, so headers can still be sent
 * when the mark goes out, as on a web server; then the buffer is flushed,
 * and the mark must be left out without a warning. Prints one line per
 * check, «MARK-CHILD ok» at the end.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

ob_start();
error_reporting( E_ALL );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['ab_sent'] = array();
function do_action( $tag, ...$args ) {
	if ( 'ab_mcp_preview_header_sent' === $tag ) {
		$GLOBALS['ab_sent'][] = $args[0];
	}
}

$fails = 0;
function ok( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$fails;
	}
}

$root = dirname( __DIR__, 2 ) . '/';
foreach ( array( 'class-settings.php', 'class-page-cache.php', 'class-preview-protection.php' ) as $file ) {
	require_once $root . 'includes/' . $file;
}
ok( ! class_exists( 'AB_MCP_OAuth', false ), 'A AB_MCP_OAuth is not loaded, as under the loader' );
ok( ! headers_sent(), 'B headers can still be sent' );
try {
	( new ReflectionMethod( 'AB_MCP_Preview_Protection', 'send_mark' ) )->invoke( null );
	ok( array( 'X-AB-MCP: preview-protection' ) === $GLOBALS['ab_sent'], 'C the mark went out with the class alone' );
} catch ( Throwable $e ) {
	ok( false, 'C the mark went out with the class alone: ' . get_class( $e ) . ': ' . $e->getMessage() );
}

// Once output has gone out, a header can no longer be sent: the mark is
// left out, without the warning header() would give (PHPUnit writes to
// STDOUT past PHP's output, so in the test process headers_sent() stays
// false and this case cannot arise there).
ob_end_flush();
flush();
$GLOBALS['ab_sent']     = array();
$GLOBALS['ab_warnings'] = array();
set_error_handler(
	static function ( $errno, $errstr ) {
		$GLOBALS['ab_warnings'][] = $errstr;
		return true;
	}
);
ok( headers_sent(), 'D output has gone out' );
( new ReflectionMethod( 'AB_MCP_Preview_Protection', 'send_mark' ) )->invoke( null );
restore_error_handler();
ok( array() === $GLOBALS['ab_sent'] && array() === $GLOBALS['ab_warnings'], 'E then no mark and no warning: ' . implode( ' / ', $GLOBALS['ab_warnings'] ) );
echo 0 === $fails ? "MARK-CHILD ok\n" : "MARK-CHILD $fails failed\n";
exit( 0 === $fails ? 0 : 1 );
