<?php
/**
 * The verdict of an update: is the new version no worse than the old one at
 * every target? AB_MCP_Update_Guard::verdict() is a pure function of the
 * two reports; this is its table (plan "Updates mit Probe und Rückbau",
 * section 6), one row per case, and combinations over the three targets.
 *
 * Run:  php tests/standalone-lifecycle/update-verdict-test.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
function __( $s, $d = null ) { return $s; }
function home_url( $p = '' ) {
	if ( ! empty( $GLOBALS['ab_throw_home'] ) ) {
		throw new RuntimeException( 'a filter of home_url() failed' );
	}
	return 'https://example.test' . $p;
}
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }

require dirname( __DIR__, 2 ) . '/includes/lifecycle/class-update-guard.php';

$fails = 0;
$count = 0;
function ok( $c, $label ) {
	global $fails, $count;
	$count++;
	if ( ! $c ) { $fails++; echo "  FAIL: $label\n"; }
}

/* rows as AB_MCP_Php_Probe::judge() gives them */
function r_ok( $t ) { return array( 'target' => $t, 'result' => 'ok', 'http_status' => 'mcp' === $t ? 401 : 200 ); }
function r_fatal( $t, $file = 'wp-content/plugins/x/x.php', $line = 3, $msg = 'Uncaught Error: Call to undefined function a()' ) { return array( 'target' => $t, 'result' => 'fatal', 'http_status' => 500, 'error' => array( 'message' => $msg, 'file' => $file, 'line' => $line ) ); }
function r_stopped( $t, $status = 302, $code = '' ) { return array( 'target' => $t, 'result' => 'stopped', 'http_status' => $status, 'code' => $code, 'reason' => 'ended early' ); }
function r_down( $t, $why = 'cURL error 7: Failed to connect' ) { return array( 'target' => $t, 'result' => 'unreachable', 'reason' => $why ); }
function r_np( $t ) { return array( 'target' => $t, 'result' => 'not_probed' ); }
function rep( ...$rows ) { return array( 'result' => 'x', 'targets' => $rows ); }
function v( array $b, array $a ) { return AB_MCP_Update_Guard::verdict( rep( ...$b ), rep( ...$a ) ); }
function all_ok() { return array( r_ok( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ); }

/*
 * name => [ before rows, after rows, keep, why, hold, same, stopped, unknown ]
 */
$cases = array(
	'ok → ok everywhere'                                => array( all_ok(), all_ok(), true, '', false, 0, 0, 0 ),
	'ok → fatal at the home page'                       => array( all_ok(), array( r_fatal( 'home' ), r_np( 'mcp' ), r_np( 'admin_post' ) ), false, 'fatal', true, 0, 0, 0 ),
	'ok → fatal at this MCP endpoint'                   => array( all_ok(), array( r_ok( 'home' ), r_fatal( 'mcp' ), r_np( 'admin_post' ) ), false, 'fatal', true, 0, 0, 0 ),
	'ok → fatal at admin-post.php'                      => array( all_ok(), array( r_ok( 'home' ), r_ok( 'mcp' ), r_fatal( 'admin_post' ) ), false, 'fatal', true, 0, 0, 0 ),
	'stopped → fatal (home)'                            => array( array( r_stopped( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_fatal( 'home' ), r_np( 'mcp' ), r_np( 'admin_post' ) ), false, 'fatal', true, 0, 0, 0 ),
	'fatal A → the same fatal A'                        => array( array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), true, '', false, 1, 0, 0 ),
	'fatal A → fatal B (other line)'                    => array( array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_fatal( 'home', 'wp-content/plugins/x/x.php', 4 ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), false, 'fatal', true, 0, 0, 0 ),
	'fatal A → fatal B (other file)'                    => array( array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_fatal( 'home', 'wp-content/plugins/y/y.php' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), false, 'fatal', true, 0, 0, 0 ),
	'fatal A → fatal B (other message)'                 => array( array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_fatal( 'home', 'wp-content/plugins/x/x.php', 3, 'Cannot redeclare b()' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), false, 'fatal', true, 0, 0, 0 ),
	'fatal A → the same A, and a new one at MCP'        => array( array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_fatal( 'home' ), r_fatal( 'mcp', 'wp-content/plugins/z/z.php' ), r_ok( 'admin_post' ) ), false, 'fatal', true, 1, 0, 0 ),
	'fatal → ok (the update repaired it)'               => array( array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), all_ok(), true, '', false, 0, 0, 0 ),
	'ok → unreachable (asked again already)'            => array( all_ok(), array( r_down( 'home', 'cURL error 28: timed out' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), false, 'unreachable', false, 0, 0, 0 ),
	'ok → unreachable, other reason'                    => array( all_ok(), array( r_ok( 'home' ), r_down( 'mcp' ), r_ok( 'admin_post' ) ), false, 'unreachable', false, 0, 0, 0 ),
	'stopped → unreachable'                             => array( array( r_stopped( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_down( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), false, 'unreachable', false, 0, 0, 0 ),
	'fatal → unreachable'                               => array( array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_down( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), false, 'unreachable', false, 0, 0, 0 ),
	'ok → not_probed (a safeguard)'                     => array( all_ok(), array( r_ok( 'home' ), r_ok( 'mcp' ), r_np( 'admin_post' ) ), false, 'not_probed', false, 0, 0, 0 ),
	'ok → missing row counts as not_probed'             => array( all_ok(), array( r_ok( 'home' ), r_ok( 'mcp' ) ), false, 'not_probed', false, 0, 0, 0 ),
	'unreachable before: not compared'                  => array( array( r_down( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_ok( 'mcp' ), r_ok( 'admin_post' ) ), true, '', false, 0, 0, 1 ),
	'unreachable before, the others break: back'        => array( array( r_down( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_fatal( 'mcp' ), r_np( 'admin_post' ) ), false, 'fatal', true, 0, 0, 1 ),
	'MCP ok → MCP stopped'                              => array( all_ok(), array( r_ok( 'home' ), r_stopped( 'mcp', 401, 'rest_not_logged_in' ), r_ok( 'admin_post' ) ), false, 'mcp_cut', true, 0, 0, 0 ),
	'MCP stopped → the same stop'                       => array( array( r_ok( 'home' ), r_stopped( 'mcp', 401, 'rest_not_logged_in' ), r_ok( 'admin_post' ) ), array( r_ok( 'home' ), r_stopped( 'mcp', 401, 'rest_not_logged_in' ), r_ok( 'admin_post' ) ), true, '', false, 0, 0, 0 ),
	'MCP stopped → another stop'                        => array( array( r_ok( 'home' ), r_stopped( 'mcp', 401, 'rest_not_logged_in' ), r_ok( 'admin_post' ) ), array( r_ok( 'home' ), r_stopped( 'mcp', 403, 'rest_forbidden' ), r_ok( 'admin_post' ) ), false, 'mcp_cut', true, 0, 0, 0 ),
	'MCP fatal → MCP stopped (better)'                  => array( array( r_ok( 'home' ), r_fatal( 'mcp' ), r_ok( 'admin_post' ) ), array( r_ok( 'home' ), r_stopped( 'mcp', 401, 'x' ), r_ok( 'admin_post' ) ), true, '', false, 0, 0, 0 ),
	'home ok → stopped (redirect)'                      => array( all_ok(), array( r_stopped( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), true, '', false, 0, 1, 0 ),
	'admin-post ok → stopped (wp_die)'                  => array( all_ok(), array( r_ok( 'home' ), r_ok( 'mcp' ), r_stopped( 'admin_post', 500 ) ), true, '', false, 0, 1, 0 ),
	'home stopped → the same stop'                      => array( array( r_stopped( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_stopped( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), true, '', false, 0, 0, 0 ),
	'home stopped → another stop'                       => array( array( r_stopped( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_stopped( 'home', 503 ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), true, '', false, 0, 1, 0 ),
	'home fatal → stopped (better)'                     => array( array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_stopped( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), true, '', false, 0, 0, 0 ),
	'fatal and MCP cut: fatal wins, held'               => array( all_ok(), array( r_fatal( 'home' ), r_stopped( 'mcp', 401, 'x' ), r_ok( 'admin_post' ) ), false, 'fatal', true, 0, 0, 0 ),
	'unreachable and MCP cut: MCP cut wins, held'       => array( all_ok(), array( r_down( 'home' ), r_stopped( 'mcp', 401, 'x' ), r_ok( 'admin_post' ) ), false, 'mcp_cut', true, 0, 0, 0 ),
	'unreachable and not_probed: unreachable, no hold'  => array( all_ok(), array( r_down( 'home' ), r_ok( 'mcp' ), r_np( 'admin_post' ) ), false, 'unreachable', false, 0, 0, 0 ),
	'everything unreachable before'                     => array( array( r_down( 'home' ), r_down( 'mcp' ), r_down( 'admin_post' ) ), array(), true, '', false, 0, 0, 3 ),
);
foreach ( $cases as $name => $c ) {
	list( $before, $after, $keep, $why, $hold, $same, $stopped, $unknown ) = $c;
	$v = v( $before, $after );
	ok( $keep === $v['keep'], "$name: keep " . var_export( $keep, true ) );
	ok( $why === $v['why'], "$name: why '$why' (got '{$v['why']}')" );
	ok( $hold === $v['hold'], "$name: hold " . var_export( $hold, true ) );
	ok( $same === count( $v['same'] ) && $stopped === count( $v['stopped'] ) && $unknown === count( $v['unknown'] ), "$name: same/stopped/unknown $same/$stopped/$unknown (got " . count( $v['same'] ) . '/' . count( $v['stopped'] ) . '/' . count( $v['unknown'] ) . ')' );
	ok( $keep === ( array() === $v['blamed'] ), "$name: kept exactly when nothing is blamed" );
}

// A blamed row carries what the target answered before.
$v = v( all_ok(), array( r_fatal( 'home' ), r_np( 'mcp' ), r_np( 'admin_post' ) ) );
ok( 'ok' === $v['blamed'][0]['before']['result'] && 'home' === $v['blamed'][0]['target'], 'blamed rows carry the row before' );
// Swapped arguments give another answer: before and after are not the same thing.
$v1 = v( all_ok(), array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ) );
$v2 = v( array( r_fatal( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), all_ok() );
ok( false === $v1['keep'] && true === $v2['keep'], 'before and after are not interchangeable' );
// The order of the rows does not matter.
$v = v( array( r_ok( 'admin_post' ), r_ok( 'home' ), r_ok( 'mcp' ) ), array( r_ok( 'mcp' ), r_fatal( 'admin_post' ), r_ok( 'home' ) ) );
ok( false === $v['keep'] && 'admin_post' === $v['blamed'][0]['target'], 'rows are matched by target, not by position' );

// Out of time: a target this call had no time left for is not checked, not failed.
function r_oot( $t ) { return array( 'target' => $t, 'result' => 'not_probed', 'out_of_time' => true, 'reason' => 'not asked: this call had no time left' ); }
$v = v( all_ok(), array( r_ok( 'home' ), r_oot( 'mcp' ), r_oot( 'admin_post' ) ) );
ok( true === $v['keep'] && '' === $v['why'] && 2 === count( $v['unchecked'] ) && array() === $v['blamed'], 'out of time: kept, the two targets unchecked, nothing blamed' );
$v = v( all_ok(), array( r_oot( 'home' ), r_oot( 'mcp' ), r_oot( 'admin_post' ) ) );
ok( true === $v['keep'] && 3 === count( $v['unchecked'] ), 'out of time everywhere: kept, nothing checked' );
$v = v( all_ok(), array( r_down( 'home', 'cURL error 28: timed out' ), r_oot( 'mcp' ), r_oot( 'admin_post' ) ) );
ok( false === $v['keep'] && 'unreachable' === $v['why'] && false === $v['hold'] && 2 === count( $v['unchecked'] ), 'a target that timed out with its full time still puts it back, beside unchecked ones' );
$v = v( all_ok(), array( r_fatal( 'home' ), r_oot( 'mcp' ), r_np( 'admin_post' ) ) );
ok( false === $v['keep'] && 'fatal' === $v['why'] && true === $v['hold'], 'a fatal error beside an unchecked target: back, held' );
$v = v( all_ok(), array( r_ok( 'home' ), r_ok( 'mcp' ), r_np( 'admin_post' ) ) );
ok( false === $v['keep'] && array() === $v['unchecked'], 'not_probed without out_of_time is still a safeguard, not unchecked' );
$v = v( array( r_down( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ) ), array( r_oot( 'mcp' ), r_ok( 'admin_post' ) ) );
ok( true === $v['keep'] && 1 === count( $v['unknown'] ) && 1 === count( $v['unchecked'] ), 'unreachable before and unchecked after are told apart' );

// The dashboard left out after the change for its login (logged_in of
// AB_MCP_Php_Probe::run()): not checked, not failed (Codex, 06.10.2026:
// its missing row put the change back).
function with_admin() { return array( r_ok( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ), r_ok( 'admin' ) ); }
function rep_login( $said, ...$rows ) {
	$r              = rep( ...$rows );
	$r['logged_in'] = array( 'result' => $said, 'reason' => 'the dashboard sent the request to the login page' );
	return $r;
}
$v = AB_MCP_Update_Guard::verdict( rep( ...with_admin() ), rep_login( 'refused', ...all_ok() ) );
ok( true === $v['keep'] && '' === $v['why'] && array() === $v['blamed'] && 1 === count( $v['login'] ) && 'admin' === $v['login'][0]['target'] && 'ok' === $v['login'][0]['before']['result'], 'L1 login refused after the change: kept, the dashboard not checked, nothing blamed' );
$v = AB_MCP_Update_Guard::verdict( rep( ...with_admin() ), rep_login( 'no_session', ...all_ok() ) );
ok( true === $v['keep'] && 1 === count( $v['login'] ), 'L2 no session after the change: kept, not checked' );
$v = AB_MCP_Update_Guard::verdict( rep( ...with_admin() ), rep_login( 'asked', ...all_ok() ) );
ok( false === $v['keep'] && 'not_probed' === $v['why'] && array() === $v['login'], 'L3 let in, but its row is missing: still a safeguard' );
$v = AB_MCP_Update_Guard::verdict( rep( ...with_admin() ), rep( ...all_ok() ) );
ok( false === $v['keep'] && 'not_probed' === $v['why'], 'L4 missing without logged_in: still a safeguard' );
$v = AB_MCP_Update_Guard::verdict( rep( ...with_admin() ), rep_login( 'refused', r_ok( 'home' ), r_ok( 'admin_post' ) ) );
ok( false === $v['keep'] && 'not_probed' === $v['why'] && 'mcp' === $v['blamed'][0]['target'] && 1 === count( $v['login'] ), 'L5 a refused login covers the dashboard only, not a missing row of this MCP endpoint' );
$v = AB_MCP_Update_Guard::verdict( rep( ...with_admin() ), rep_login( 'refused', r_fatal( 'home' ), r_np( 'mcp' ), r_np( 'admin_post' ) ) );
ok( false === $v['keep'] && 'fatal' === $v['why'] && true === $v['hold'], 'L6 a fatal error beside a refused login: back, held' );
$v = AB_MCP_Update_Guard::verdict( rep( ...with_admin() ), rep_login( 'refused', r_ok( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ), r_fatal( 'admin' ) ) );
ok( false === $v['keep'] && 'fatal' === $v['why'] && array() === $v['login'], 'L7 a row of the dashboard is judged, whatever logged_in says' );
$w = AB_MCP_Update_Guard::login_warning( AB_MCP_Update_Guard::verdict( rep( ...with_admin() ), rep_login( 'refused', ...all_ok() ) ) );
ok( false !== strpos( $w, 'The dashboard answered before the change but was not checked after it: the dashboard sent the request to the login page. Make sure you can still log in.' ), 'L8 the warning names why: ' . $w );
ok( '' === AB_MCP_Update_Guard::login_warning( v( all_ok(), all_ok() ) ) && '' === AB_MCP_Update_Guard::login_warning( AB_MCP_Update_Guard::verdict( rep( ...with_admin() ), rep( ...with_admin() ) ) ), 'L9 no warning where the dashboard was checked or never asked' );
// The dashboard refusing the account after the change (Codex, 06.10.2026:
// a 403 counted as ok): stopped, kept with a warning, as a redirect there.
$deny = array( r_ok( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ), r_stopped( 'admin', 403 ) );
$v    = AB_MCP_Update_Guard::verdict( rep( ...with_admin() ), rep( ...$deny ) );
ok( true === $v['keep'] && '' === $v['why'] && 1 === count( $v['stopped'] ) && 'admin' === $v['stopped'][0]['target'] && 403 === $v['stopped'][0]['http_status'], 'L10 the dashboard refusing the account after the change: kept, named among the targets that end early' );
$v = AB_MCP_Update_Guard::verdict( rep( ...$deny ), rep( ...$deny ) );
ok( true === $v['keep'] && array() === $v['stopped'], 'L11 refused already before the change, with the same status: nothing new to name' );
$v = AB_MCP_Update_Guard::verdict( rep( r_ok( 'home' ), r_ok( 'mcp' ), r_ok( 'admin_post' ), r_stopped( 'admin', 401 ) ), rep( ...$deny ) );
ok( true === $v['keep'] && 1 === count( $v['stopped'] ), 'L12 another status than before: named' );

// probe_until(): one target at a time, as an update, an activation and a
// deploy ask after the change.
class AB_Test_Probe_Guard extends AB_MCP_Update_Guard {
	public static $answers = array();
	public static $asked   = array();
	protected static function now() { return 1000.0; }
	protected static function probe( array $only, $stop, $timeout = null, $until = null ) {
		$name          = (string) $only[0];
		self::$asked[] = $name;
		return self::$answers[ $name ];
	}
	public static function round( array $only, $deadline ) { return static::probe_until( $only, false, $deadline ); }
}
$refused = array( 'result' => 'refused', 'reason' => 'the dashboard sent the request to the login page' );
AB_Test_Probe_Guard::$answers = array(
	'home'  => array( 'result' => 'passed', 'targets' => array( r_ok( 'home' ) ) ),
	'admin' => array( 'result' => 'unreachable', 'targets' => array(), 'logged_in' => $refused ),
);
$after = AB_Test_Probe_Guard::round( array( 'home', 'admin' ), 1100.0 );
ok( array( 'home' ) === array_column( $after['targets'], 'target' ) && $refused === ( $after['logged_in'] ?? null ) && array( 'home', 'admin' ) === AB_Test_Probe_Guard::$asked && 'passed' === $after['result'], 'P1 probe_until: no made-up row for a dashboard left out for its login, and logged_in carried on' );
$v = AB_MCP_Update_Guard::verdict( rep( r_ok( 'home' ), r_ok( 'admin' ) ), $after );
ok( true === $v['keep'] && 1 === count( $v['login'] ) && false !== strpos( AB_MCP_Update_Guard::login_warning( $v ), 'login page' ), 'P2 a refused login after the change keeps it, with the warning' );
AB_Test_Probe_Guard::$answers['admin'] = array( 'result' => 'passed', 'targets' => array( r_ok( 'admin' ) ), 'logged_in' => array( 'result' => 'asked' ) );
$after = AB_Test_Probe_Guard::round( array( 'home', 'admin' ), 1100.0 );
ok( array( 'home', 'admin' ) === array_column( $after['targets'], 'target' ) && 'asked' === ( $after['logged_in']['result'] ?? '' ), 'P3 a dashboard that was let in keeps its row' );
AB_Test_Probe_Guard::$answers['mcp'] = array( 'result' => 'unreachable', 'targets' => array() );
$after = AB_Test_Probe_Guard::round( array( 'mcp' ), 1100.0 );
ok( array( array( 'target' => 'mcp', 'result' => 'not_probed' ) ) === $after['targets'] && ! isset( $after['logged_in'] ), 'P4 a target without a row for another reason still gets the not_probed row' );
// A round whose setup throws, from code the change brought (Codex,
// 06.10.2026, round 2: the deploy stayed live unchecked).
$GLOBALS['ab_throw_home'] = true;
$after = AB_Test_Probe_Guard::round( array( 'home', 'mcp' ), 1100.0 );
$GLOBALS['ab_throw_home'] = false;
ok( 'unreachable' === $after['result'] && array( 'home', 'mcp' ) === array_column( $after['targets'], 'target' ) && false !== strpos( (string) $after['targets'][0]['reason'], 'RuntimeException' ), 'P5 a round whose setup throws: every target not reached, with what was thrown' );
$v = AB_MCP_Update_Guard::verdict( rep( r_ok( 'home' ), r_ok( 'mcp' ) ), $after );
ok( false === $v['keep'] && 'unreachable' === $v['why'], 'P6 and the verdict puts the change back' );

// installed(): whether the upgrader installed something, not by the version.
ok( true === AB_MCP_Update_Guard::installed( array( 'destination_name' => 'x' ), 'a', 'a' ), 'installed: the upgrader\'s array, even with the same files' );
ok( false === AB_MCP_Update_Guard::installed( true, 'a', 'a' ), 'installed: true ("up_to_date") with the same files is nothing' );
ok( true === AB_MCP_Update_Guard::installed( true, 'a', 'b' ), 'installed: true, but other files on disk' );
ok( false === AB_MCP_Update_Guard::installed( true, null, null ), 'installed: no main file before and after' );
ok( true === AB_MCP_Update_Guard::installed( true, 'a', null ), 'installed: the main file is gone' );

// timed_out(): only a timeout is asked again.
ok( AB_MCP_Update_Guard::timed_out( r_down( 'home', 'cURL error 28: Operation timed out after 10000 milliseconds' ) ), 'timed_out: cURL error 28' );
ok( ! AB_MCP_Update_Guard::timed_out( r_down( 'home', 'cURL error 7: Failed to connect' ) ), 'timed_out: not a refused connection' );
ok( ! AB_MCP_Update_Guard::timed_out( r_ok( 'home' ) ), 'timed_out: not a row without a reason' );

// reached(): the targets asked after the update.
ok( array( 'home', 'mcp', 'admin_post' ) === AB_MCP_Update_Guard::reached( rep( r_fatal( 'home' ), r_stopped( 'mcp' ), r_ok( 'admin_post' ) ) ), 'reached: fatal and stopped answered' );
ok( array( 'mcp' ) === AB_MCP_Update_Guard::reached( rep( r_down( 'home' ), r_ok( 'mcp' ), r_np( 'admin_post' ) ) ), 'reached: not the unreachable ones' );

if ( $fails ) {
	printf( "Update-Verdict: FAILED (%d von %d)\n", $fails, $count );
	exit( 1 );
}
printf( "ALLE %d UPDATE-VERDICT-CHECKS BESTANDEN (0 Fehler)\n", $count );
exit( 0 );
