<?php
/**
 * AB_MCP_Php_Probe::run() with the key of WordPress's maintenance mode.
 * While an update holds the maintenance mode, WordPress answers every
 * request with its 503 page, unless it carries md5( $upgrading ) as key and
 * $upgrading as nonce (wp_is_maintenance_mode()); the scrape mechanism then
 * wants the transient scrape_key_<key> to hold the nonce, compared strictly
 * (wp_start_scraping_edited_file_errors()). So run() takes a key and nonce
 * from its caller and stores the nonce as a string; and a timeout for the
 * one target an update asks again.
 *
 * Run:  php tests/standalone-lifecycle/probe-key-test.php
 */

require __DIR__ . '/../standalone-lib/support/update-harness.php';

ab_reset();
$upgrading = time() + 5;
file_put_contents( ABSPATH . '.maintenance', '<?php $upgrading = ' . $upgrading . '; ?>' );

echo "K1 the key of the maintenance mode\n";
$r = AB_MCP_Php_Probe::run( array(), false, array( 'key' => md5( (string) $upgrading ), 'nonce' => (string) $upgrading ) );
ok( 'passed' === $r['result'], 'K1 with it every target is reached through the maintenance mode: ' . $r['result'] );
$q = $GLOBALS['ab_requests'][0];
ok( md5( (string) $upgrading ) === $q['key'] && (string) $upgrading === $q['nonce'], 'K1 the requests carry that key and nonce' );
ok( is_string( $q['transient'] ) && (string) $upgrading === $q['transient'], 'K1 the transient scrape_key_<md5> holds the nonce as a string' );
ok( false === get_transient( 'scrape_key_' . md5( (string) $upgrading ) ), 'K1 and is gone afterwards' );

echo "K2 without it\n";
$GLOBALS['ab_requests'] = array();
$r = AB_MCP_Php_Probe::run( array(), false );
ok( 'unreachable' === $r['result'] && false !== strpos( $r['targets'][0]['reason'], 'HTTP 503' ), 'K2 a key of its own meets the maintenance page: ' . $r['result'] );
ok( 1 === preg_match( '/^[a-f0-9]{32}\z/', $GLOBALS['ab_requests'][0]['key'] ) && $GLOBALS['ab_requests'][0]['key'] !== md5( (string) $upgrading ), 'K2 (a random key)' );

echo "K3 a key that is not one is not used\n";
foreach ( array( 'short', str_repeat( 'G', 32 ), md5( 'x' ) . "\n" ) as $bad ) {
	$GLOBALS['ab_requests'] = array();
	AB_MCP_Php_Probe::run( array( 'home' ), false, array( 'key' => $bad, 'nonce' => '1' ) );
	ok( $bad !== $GLOBALS['ab_requests'][0]['key'], 'K3 refuses key ' . json_encode( $bad ) );
}
$GLOBALS['ab_requests'] = array();
AB_MCP_Php_Probe::run( array( 'home' ), false, array( 'key' => md5( 'x' ), 'nonce' => 17 ) );
ok( md5( 'x' ) !== $GLOBALS['ab_requests'][0]['key'], 'K3 and a nonce that is not a string' );

echo "K4 a timeout for the request\n";
@unlink( ABSPATH . '.maintenance' );
$GLOBALS['ab_requests'] = array();
AB_MCP_Php_Probe::run( array( 'home' ), false, array( 'timeout' => 44.5 ) );
ok( 44.5 === (float) $GLOBALS['ab_requests'][0]['timeout'], 'K4 the timeout given is used' );
$GLOBALS['ab_requests'] = array();
AB_MCP_Php_Probe::run( array( 'home' ), false, array( 'timeout' => 500 ) );
ok( (float) AB_MCP_Php_Probe::TIMEOUT_MAX === (float) $GLOBALS['ab_requests'][0]['timeout'], 'K4 never above TIMEOUT_MAX' );
$GLOBALS['ab_requests'] = array();
AB_MCP_Php_Probe::run( array( 'home' ) );
ok( (float) AB_MCP_Php_Probe::TIMEOUT === (float) $GLOBALS['ab_requests'][0]['timeout'], 'K4 without it, the usual' );

ab_finish( 'PROBE-KEY' );
