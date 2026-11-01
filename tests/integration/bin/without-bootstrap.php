<?php
/**
 * Site A loaded as the plugin loads it, without the integration bootstrap:
 * what does the product code know then? For TestFetcherIsolationTest.
 *
 *   php without-bootstrap.php <state.json>
 *
 * Prints JSON: the plugin version WordPress loaded, whether
 * AB_MCP_Outbound and AB_MCP_Test_Sync_Fetcher exist, and every file this
 * process included from the tests directory besides this one. Reads the
 * state with json_decode() itself, so that not even the helpers of the
 * suite are loaded.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI || 2 !== count( $argv ) ) {
	exit( 2 );
}

$ab_it_state = json_decode( (string) file_get_contents( $argv[1] ), true );
$ab_it_url   = parse_url( $ab_it_state['sites']['a']['url'] );
$_SERVER     = array_merge(
	$_SERVER,
	array(
		'HTTP_HOST'       => $ab_it_url['host'] . ':' . $ab_it_url['port'],
		'SERVER_NAME'     => $ab_it_url['host'],
		'SERVER_PORT'     => (string) $ab_it_url['port'],
		'REQUEST_URI'     => '/',
		'REQUEST_METHOD'  => 'GET',
		'SERVER_PROTOCOL' => 'HTTP/1.1',
		'REMOTE_ADDR'     => '127.0.0.1',
	)
);
define( 'WP_USE_THEMES', false );
require $ab_it_state['sites']['a']['docroot'] . '/wp-load.php';

$ab_it_tests = realpath( dirname( __DIR__, 2 ) ) . '/';
$ab_it_from  = array();
foreach ( get_included_files() as $ab_it_file ) {
	$ab_it_real = realpath( $ab_it_file );
	if ( false !== $ab_it_real && 0 === strpos( $ab_it_real, $ab_it_tests ) && __FILE__ !== $ab_it_real ) {
		$ab_it_from[] = substr( $ab_it_real, strlen( $ab_it_tests ) );
	}
}

echo json_encode(
	array(
		'plugin'         => defined( 'AB_MCP_VERSION' ) ? AB_MCP_VERSION : null,
		'plugin_dir'     => defined( 'AB_MCP_DIR' ) ? realpath( AB_MCP_DIR ) : null,
		'outbound'       => class_exists( 'AB_MCP_Outbound', false ),
		'test_fetcher'   => class_exists( 'AB_MCP_Test_Sync_Fetcher', false ),
		'from_tests_dir' => $ab_it_from,
	)
);
