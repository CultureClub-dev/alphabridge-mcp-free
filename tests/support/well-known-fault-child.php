<?php
/**
 * One request of the well-known relay in a PHP process of its own, for
 * WellKnownAbortTest: with a fault plan for the test seam that may end the
 * process (an abort at a named call, the time limit of PHP), and a state on
 * disk that outlives it, as a database and a cache outlive a request.
 *
 * The state file (JSON) holds the lock rows of the options table (rows), the
 * cache (transients) and how many fetches all requests made (fetches). It is
 * written after every effect of the calls object, before the call returns,
 * so an abort right after a call leaves what that call did, and an abort
 * before a call leaves nothing of it (AB_Test_Wk_Persisting_Calls).
 *
 * Argument: JSON with state (path), marker (path of the marker of
 * AB_MCP_Test_Abort, or ''), docroot (the web directory; ABSPATH, a
 * WordPress under /staging/123), path (the request URI) and faults (the
 * plan for AB_MCP_Test_Seam::install()). Prints the kind of the answer.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

$ab_case = json_decode( (string) $argv[1], true );
define( 'ABSPATH', rtrim( (string) $ab_case['docroot'], '/' ) . '/' );
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/wpdb-lock-fake.php';
require __DIR__ . '/../integration/support/class-test-abort.php';
require __DIR__ . '/../integration/support/class-test-seam.php';
ab_test_reset();

$ab_state_file = (string) $ab_case['state'];
$ab_state      = is_file( $ab_state_file ) ? (array) json_decode( (string) file_get_contents( $ab_state_file ), true ) : array();
$ab_state     += array(
	'rows'       => array(),
	'transients' => array(),
	'fetches'    => 0,
);
$GLOBALS['wpdb']               = new wpdb();
$GLOBALS['wpdb']->rows         = (array) $ab_state['rows'];
$GLOBALS['ab_test_transients'] = (array) $ab_state['transients'];
$GLOBALS['ab_wk_fetches']      = (int) $ab_state['fetches'];

/** Write the state as it is now, whole (rename). */
function ab_wk_save( string $file ): void {
	$tmp = $file . '.' . getmypid() . '.tmp';
	file_put_contents(
		$tmp,
		(string) json_encode(
			array(
				'rows'       => $GLOBALS['wpdb']->rows,
				'transients' => array_filter( $GLOBALS['ab_test_transients'], static fn( $key ): bool => 0 === strpos( (string) $key, 'ab_mcp_wk_' ), ARRAY_FILTER_USE_KEY ),
				'fetches'    => $GLOBALS['ab_wk_fetches'],
			)
		)
	);
	rename( $tmp, $file );
}

/**
 * The real calls of the relay, each followed by writing the state.
 */
final class AB_Test_Wk_Persisting_Calls {

	/** @var AB_MCP_OAuth_Well_Known_Calls */
	private $real;

	/** @var string */
	private $file;

	public function __construct( string $file ) {
		$this->real = new AB_MCP_OAuth_Well_Known_Calls();
		$this->file = $file;
	}

	public function fetch( $url, array $args ) {
		++$GLOBALS['ab_wk_fetches'];
		ab_wk_save( $this->file );
		return $this->real->fetch( $url, $args );
	}

	public function get_transient( $key ) {
		return $this->real->get_transient( $key );
	}

	public function set_transient( $key, $value, $ttl ) {
		$out = $this->real->set_transient( $key, $value, $ttl );
		ab_wk_save( $this->file );
		return $out;
	}

	public function realpath( $path ) {
		return $this->real->realpath( $path );
	}

	public function query( $sql ) {
		$out = $this->real->query( $sql );
		ab_wk_save( $this->file );
		return $out;
	}

	public function delete( $table, array $where ) {
		$out = $this->real->delete( $table, $where );
		ab_wk_save( $this->file );
		return $out;
	}
}

$GLOBALS['ab_test_http_answer'] = static function ( string $url ) {
	preg_match( '#\A(https?://[^/]+)(.*)/\.well-known/oauth-authorization-server#', $url, $m );
	$GLOBALS['ab_test_home']      = $m[1] . $m[2];
	$GLOBALS['ab_test_rest_base'] = $m[1] . $m[2] . '/wp-json/';
	$data                         = AB_MCP_OAuth::metadata();
	$GLOBALS['ab_test_home']      = 'https://example.test';
	unset( $GLOBALS['ab_test_rest_base'] );
	return ab_test_http_response( 200, (string) wp_json_encode( $data ), array( 'Content-Type' => 'application/json' ) );
};

if ( '' !== (string) $ab_case['marker'] ) {
	AB_MCP_Test_Abort::arm( (string) $ab_case['marker'] );
}
AB_MCP_Test_Seam::install( AB_MCP_OAuth::class, 'naht_oauth_well_known', new AB_Test_Wk_Persisting_Calls( $ab_state_file ), (array) $ab_case['faults'] );

$ab_answer = AB_MCP_OAuth::well_known_answer( (string) $ab_case['path'] );
ab_wk_save( $ab_state_file );
echo json_encode( array( 'kind' => $ab_answer['kind'] ) );
