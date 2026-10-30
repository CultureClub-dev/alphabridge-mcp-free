<?php
/**
 * The update log and the hold (AB_MCP_Update_Log): a ring buffer of 200
 * entries, the newest entry per package, and the hold that keeps WordPress's
 * automatic updates from installing a version that was taken back — exactly
 * that package in exactly that version, nothing else. Where the hold cannot
 * reach them (a network whose main site runs without this plugin), WordPress's own
 * list of automatic updates: off it, noted in the hold, back on it only where
 * AlphaBridge took it off, and once a version newer than the held one is
 * installed; the plugin's own WP-Cron event for that, planned only while such a hold
 * is open (the flow on a network: update-flow-test.php multisite).
 *
 * Run:  php tests/standalone-lifecycle/update-log-test.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

// Network options (on a network every site shares the plugin files, and core's
// background updates run on the main site). A site option of the same name
// must not be what is read.
$GLOBALS['ab_opts'] = array();
function get_site_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ab_opts'] ) ? $GLOBALS['ab_opts'][ $k ] : $d; }
// $GLOBALS['ab_stuck'][ name ]: a write that does not land (a filter keeps the old value).
function update_site_option( $k, $v ) { if ( ! empty( $GLOBALS['ab_stuck'][ $k ] ) ) { return false; } $GLOBALS['ab_opts'][ $k ] = $v; return true; }
function delete_site_option( $k ) { unset( $GLOBALS['ab_opts'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return 'site option of this site only'; }
function update_option( $k, $v, $a = null ) { $GLOBALS['ab_site_only'][] = $k; return true; }
function delete_option( $k ) { $GLOBALS['ab_site_only'][] = $k; return true; }
// WP-Cron of this site, and what kind of request this is.
$GLOBALS['ab_cron']           = array();
$GLOBALS['ab_cron_scheduled'] = 0;
function wp_next_scheduled( $h ) { return isset( $GLOBALS['ab_cron'][ $h ] ) ? $GLOBALS['ab_cron'][ $h ]['time'] : false; }
function wp_schedule_event( $t, $r, $h ) { ++$GLOBALS['ab_cron_scheduled']; $GLOBALS['ab_cron'][ $h ] = array( 'time' => $t, 'recurrence' => $r ); return true; }
function wp_clear_scheduled_hook( $h ) { $had = isset( $GLOBALS['ab_cron'][ $h ] ); unset( $GLOBALS['ab_cron'][ $h ] ); return $had ? 1 : 0; }
function is_admin() { return ! empty( $GLOBALS['ab_is_admin'] ); }
function wp_doing_cron() { return ! empty( $GLOBALS['ab_doing_cron'] ); }
// No package is installed here (the flow with packages: update-flow-test.php).
define( 'WP_PLUGIN_DIR', __DIR__ . '/no-plugins-here' );

require dirname( __DIR__, 2 ) . '/includes/lifecycle/class-update-log.php';

$fails = 0;
$count = 0;
function ok( $c, $label ) {
	global $fails, $count;
	$count++;
	if ( ! $c ) { $fails++; echo "  FAIL: $label\n"; }
}
function row( $i, $item = 'a/a.php', $type = 'plugin' ) { return array( 'time' => 1000 + $i, 'type' => $type, 'item' => $item, 'from' => '1.0.0', 'to' => '2.0.0', 'result' => 'kept', 'n' => $i ); }

/* ring buffer */
for ( $i = 1; $i <= 205; $i++ ) {
	AB_MCP_Update_Log::add( row( $i, 0 === $i % 2 ? 'a/a.php' : 'b/b.php' ) );
}
$all = AB_MCP_Update_Log::entries();
ok( 200 === count( $all ), 'L1 the log keeps 200 entries (' . count( $all ) . ')' );
ok( 6 === $all[0]['n'] && 205 === $all[199]['n'], 'L1 the oldest are dropped first' );
ok( empty( $GLOBALS['ab_site_only'] ), 'L1 kept as a network option, not in one site\'s options' );
ok( 204 === AB_MCP_Update_Log::last_for( 'plugin', 'a/a.php' )['n'] && 205 === AB_MCP_Update_Log::last_for( 'plugin', 'b/b.php' )['n'], 'L2 last_for gives the newest entry of the package' );
ok( null === AB_MCP_Update_Log::last_for( 'theme', 'a/a.php' ) && null === AB_MCP_Update_Log::last_for( 'plugin', 'c/c.php' ), 'L2 another type or package has none' );
$GLOBALS['ab_opts'][ AB_MCP_Update_Log::OPTION ][] = 'garbage';
$GLOBALS['ab_opts'][ AB_MCP_Update_Log::OPTION ][] = array( 'type' => 'plugin' );
ok( 200 === count( AB_MCP_Update_Log::entries() ), 'L3 malformed entries are ignored' );
$GLOBALS['ab_opts'][ AB_MCP_Update_Log::OPTION ] = 'not an array';
ok( array() === AB_MCP_Update_Log::entries(), 'L3 and a broken option reads as empty' );
AB_MCP_Update_Log::add( row( 1 ) );
ok( 1 === count( AB_MCP_Update_Log::entries() ), 'L3 and is written fresh' );

/* the hold */
AB_MCP_Update_Log::hold( 'plugin', 'a/a.php', '2.0.0', 'fatal' );
AB_MCP_Update_Log::hold( 'theme', 'calm', '3.1', 'mcp_cut' );
$offer = static function ( $item, $v ) { return (object) array( 'plugin' => $item, 'slug' => dirname( $item ), 'new_version' => $v ); };
ok( false === AB_MCP_Update_Log::auto_update_plugin( true, $offer( 'a/a.php', '2.0.0' ) ), 'H1 the held version is not updated automatically' );
ok( false === AB_MCP_Update_Log::auto_update_plugin( null, $offer( 'a/a.php', '2.0.0' ) ), 'H1 also where nothing had decided yet (null)' );
ok( true === AB_MCP_Update_Log::auto_update_plugin( true, $offer( 'a/a.php', '2.0.1' ) ), 'H2 a newer version is' );
ok( null === AB_MCP_Update_Log::auto_update_plugin( null, $offer( 'a/a.php', '2.0.1' ) ), 'H2 and the filter value passes unchanged' );
ok( false === AB_MCP_Update_Log::auto_update_plugin( false, $offer( 'a/a.php', '2.0.1' ) ), 'H2 a site that does not auto-update stays so' );
ok( true === AB_MCP_Update_Log::auto_update_plugin( true, $offer( 'b/b.php', '2.0.0' ) ), 'H3 another plugin in the same version is' );
ok( true === AB_MCP_Update_Log::auto_update_theme( true, (object) array( 'theme' => 'a/a.php', 'new_version' => '2.0.0' ) ), 'H3 a theme of the same name as the held plugin is' );
ok( false === AB_MCP_Update_Log::auto_update_theme( true, (object) array( 'theme' => 'calm', 'new_version' => '3.1' ) ), 'H4 the held theme version is not' );
ok( true === AB_MCP_Update_Log::auto_update_theme( true, (object) array( 'theme' => 'calm', 'new_version' => '3.2' ) ), 'H4 a newer theme version is' );
ok( true === AB_MCP_Update_Log::auto_update_plugin( true, 'not an object' ) && true === AB_MCP_Update_Log::auto_update_plugin( true, (object) array( 'plugin' => 'a/a.php' ) ), 'H5 an offer without the fields passes unchanged' );
$h = AB_MCP_Update_Log::held( 'plugin', 'a/a.php' );
ok( '2.0.0' === $h['version'] && 'fatal' === $h['reason'] && is_int( $h['time'] ), 'H6 held() gives version, reason and time' );
AB_MCP_Update_Log::release( 'plugin', 'a/a.php' );
ok( null === AB_MCP_Update_Log::held( 'plugin', 'a/a.php' ) && true === AB_MCP_Update_Log::auto_update_plugin( true, $offer( 'a/a.php', '2.0.0' ) ), 'H7 release() lifts the hold' );
ok( null !== AB_MCP_Update_Log::held( 'theme', 'calm' ), 'H7 and only that one' );

/* the hold keeps both versions: the offer's, which the automatic updates go by, and the header's */
AB_MCP_Update_Log::hold( 'plugin', 'v/v.php', '2.1', 'fatal', '2.1.0' );
ok( false === AB_MCP_Update_Log::auto_update_plugin( true, $offer( 'v/v.php', '2.1' ) ), 'H8 the offer\'s version is held (the vendor wrote 2.1, the header says 2.1.0)' );
ok( false === AB_MCP_Update_Log::auto_update_plugin( true, $offer( 'v/v.php', '2.1.0' ) ), 'H8 and so is the header\'s' );
ok( true === AB_MCP_Update_Log::auto_update_plugin( true, $offer( 'v/v.php', '2.2' ) ), 'H8 a newer one is not' );
ok( '2.1.0' === AB_MCP_Update_Log::held( 'plugin', 'v/v.php' )['file_version'], 'H8 held() gives the header\'s version too' );
AB_MCP_Update_Log::hold( 'plugin', 'w/w.php', '3.0', 'fatal' );
ok( false === AB_MCP_Update_Log::auto_update_plugin( true, $offer( 'w/w.php', '3.0' ) ) && true === AB_MCP_Update_Log::auto_update_plugin( true, $offer( 'w/w.php', '' ) ), 'H9 without a header version: the offer\'s only, and an empty one matches nothing' );
$GLOBALS['ab_opts'][ AB_MCP_Update_Log::HOLDS ]['plugin:old/old.php'] = array( 'version' => '1.5', 'time' => 1, 'reason' => 'fatal' );
ok( false === AB_MCP_Update_Log::auto_update_plugin( true, $offer( 'old/old.php', '1.5' ) ), 'H10 a hold written before there was file_version still holds' );
ok( false === AB_MCP_Update_Log::holds_version( null, '1.5' ) && false === AB_MCP_Update_Log::holds_version( array( 'version' => '1.5' ), '' ), 'H11 holds_version: no hold, no version' );

/* where the hold cannot reach the automatic updates: WordPress's own list of them */
$GLOBALS['ab_opts']['auto_update_plugins'] = array( 'x/x.php', 'a/a.php' );
ok( 'switched_off' === AB_MCP_Update_Log::auto_update_off( 'plugin', 'a/a.php' ) && array( 'x/x.php' ) === $GLOBALS['ab_opts']['auto_update_plugins'], 'A1 auto_update_off() takes the package off auto_update_plugins, the others stay' );
$GLOBALS['ab_opts']['auto_update_plugins'] = array( 'x/x.php' );
ok( 'was_off' === AB_MCP_Update_Log::auto_update_off( 'plugin', 'a/a.php' ) && array( 'x/x.php' ) === $GLOBALS['ab_opts']['auto_update_plugins'], 'A2 not on the list: was_off, the list as it was' );
unset( $GLOBALS['ab_opts']['auto_update_plugins'] );
ok( 'was_off' === AB_MCP_Update_Log::auto_update_off( 'plugin', 'a/a.php' ) && ! array_key_exists( 'auto_update_plugins', $GLOBALS['ab_opts'] ), 'A2 no list at all: was_off, and none is written' );
$GLOBALS['ab_opts']['auto_update_plugins'] = array( 'a/a.php' );
$GLOBALS['ab_stuck']['auto_update_plugins'] = true;
ok( 'failed' === AB_MCP_Update_Log::auto_update_off( 'plugin', 'a/a.php' ) && array( 'a/a.php' ) === $GLOBALS['ab_opts']['auto_update_plugins'], 'A3 a write that does not land: failed, not switched_off' );
unset( $GLOBALS['ab_stuck'] );
$GLOBALS['ab_opts']['auto_update_themes'] = array( 'calm', 'a/a.php' );
ok( 'switched_off' === AB_MCP_Update_Log::auto_update_off( 'theme', 'calm' ) && array( 'a/a.php' ) === $GLOBALS['ab_opts']['auto_update_themes'] && array( 'a/a.php' ) === $GLOBALS['ab_opts']['auto_update_plugins'], 'A4 a theme comes off auto_update_themes, the list of plugins stays' );
AB_MCP_Update_Log::hold( 'plugin', 'a/a.php', '2.0.0', 'fatal', '2.0.0', true );
ok( true === AB_MCP_Update_Log::held( 'plugin', 'a/a.php' )['auto_off'], 'A5 the hold notes that AlphaBridge switched it off' );
AB_MCP_Update_Log::hold( 'plugin', 'a/a.php', '2.0.1', 'fatal', '2.0.1' );
ok( true === AB_MCP_Update_Log::held( 'plugin', 'a/a.php' )['auto_off'] && '2.0.1' === AB_MCP_Update_Log::held( 'plugin', 'a/a.php' )['version'], 'A5 and keeps it through a later hold of the same package: it is off the list since the first' );
AB_MCP_Update_Log::hold( 'plugin', 'b/b.php', '1.1', 'fatal' );
ok( false === AB_MCP_Update_Log::held( 'plugin', 'b/b.php' )['auto_off'], 'A5 another package: nothing switched off' );
$GLOBALS['ab_opts']['auto_update_plugins'] = array( 'x/x.php' );
ok( true === AB_MCP_Update_Log::release( 'plugin', 'a/a.php' ) && array( 'x/x.php', 'a/a.php' ) === $GLOBALS['ab_opts']['auto_update_plugins'] && null === AB_MCP_Update_Log::held( 'plugin', 'a/a.php' ), 'A6 release() of a hold AlphaBridge switched off: back on the list, hold lifted' );
ok( false === AB_MCP_Update_Log::release( 'plugin', 'b/b.php' ) && array( 'x/x.php', 'a/a.php' ) === $GLOBALS['ab_opts']['auto_update_plugins'], 'A6 release() of a hold without it: the list stays, someone else switched it off' );
ok( false === AB_MCP_Update_Log::release( 'plugin', 'b/b.php' ), 'A6 release() without a hold: false' );
AB_MCP_Update_Log::hold( 'plugin', 'x/x.php', '3.0', 'fatal', '', true );
ok( true === AB_MCP_Update_Log::release( 'plugin', 'x/x.php' ) && array( 'x/x.php', 'a/a.php' ) === $GLOBALS['ab_opts']['auto_update_plugins'], 'A6 already back on the list (by hand): true, and no second entry' );
$GLOBALS['ab_opts']['auto_update_plugins'] = array();
$GLOBALS['ab_stuck']['auto_update_plugins'] = true;
AB_MCP_Update_Log::hold( 'plugin', 'y/y.php', '3.0', 'fatal', '', true );
ok( false === AB_MCP_Update_Log::release( 'plugin', 'y/y.php' ), 'A6 a write that does not land: release() does not claim it is on again' );
$h = AB_MCP_Update_Log::held( 'plugin', 'y/y.php' );
ok( is_array( $h ) && true === $h['auto_off'] && array_key_exists( 'switch_on', $h ) && array() === $GLOBALS['ab_opts']['auto_update_plugins'], 'A7 and keeps the hold, marked for another try (with the version installed then: none here)' );
unset( $GLOBALS['ab_stuck'] );
ok( true === AB_MCP_Update_Log::release( 'plugin', 'y/y.php' ) && array( 'y/y.php' ) === $GLOBALS['ab_opts']['auto_update_plugins'] && null === AB_MCP_Update_Log::held( 'plugin', 'y/y.php' ), 'A7 the next try, the write landing: on the list, the hold lifted' );
$GLOBALS['ab_opts']['auto_update_plugins'] = array();
$GLOBALS['ab_stuck']['auto_update_plugins'] = true;
AB_MCP_Update_Log::hold( 'plugin', 'z/z.php', '3.0', 'fatal', '', true );
AB_MCP_Update_Log::release( 'plugin', 'z/z.php' );
unset( $GLOBALS['ab_stuck'] );
AB_MCP_Update_Log::hold( 'plugin', 'z/z.php', '3.1', 'fatal' );
$h = AB_MCP_Update_Log::held( 'plugin', 'z/z.php' );
ok( true === $h['auto_off'] && ! isset( $h['switch_on'] ) && '3.1' === $h['version'], 'A8 a new hold of the package after that: still switched off through AlphaBridge, no longer only waiting for the switch-on' );
AB_MCP_Update_Log::release( 'plugin', 'z/z.php' );

/* newer than the hold: plain versions only, every version the hold names */
$newer = array(
	array( '2.0.1', array( 'version' => '2.0.0' ), true ),
	array( '10.0', array( 'version' => '9.9.9' ), true ),
	array( '2.1.1', array( 'version' => '2.1', 'file_version' => '2.1.0' ), true ),
	array( '2.0.0', array( 'version' => '2.0.0' ), false ),
	array( '1.9', array( 'version' => '2.0.0' ), false ),
	array( '2.1.0', array( 'version' => '2.1', 'file_version' => '2.1.0' ), false ),
	array( '2.0.1-beta', array( 'version' => '2.0.0' ), false ),
	array( "2.0.1\n", array( 'version' => '2.0.0' ), false ),
	array( '3.0', array( 'version' => '2.0b' ), false ),
	array( '3.0', array( 'version' => '', 'file_version' => '' ), false ),
	array( '', array( 'version' => '2.0.0' ), false ),
	array( null, array( 'version' => '2.0.0' ), false ),
);
$wrong = array();
foreach ( $newer as $row ) {
	if ( $row[2] !== AB_MCP_Update_Log::newer_than_hold( $row[0], $row[1] ) ) {
		$wrong[] = var_export( $row[0], true ) . ' vs ' . implode( '/', $row[1] );
	}
}
ok( array() === $wrong, 'V1 newer_than_hold(): a plain version above every version of the hold, nothing else (a line break after it, a beta, another form, none): ' . implode( '; ', $wrong ) );

/* The plugin's own check (the WP-Cron event RESUME): planned while a hold with auto_off is open, and only then */
$R = AB_MCP_Update_Log::RESUME;
$GLOBALS['ab_opts'][ AB_MCP_Update_Log::HOLDS ] = array();
$GLOBALS['ab_opts']['auto_update_plugins']          = array();
$GLOBALS['ab_cron']                                 = array();
$GLOBALS['ab_cron_scheduled']                       = 0;
AB_MCP_Update_Log::hold( 'plugin', 'p/p.php', '1.1', 'fatal' );
ok( false === wp_next_scheduled( $R ), 'S1 a hold without auto_off plans nothing' );
AB_MCP_Update_Log::hold( 'plugin', 'q/q.php', '2.0', 'fatal', '', true );
ok( 'twicedaily' === ( $GLOBALS['ab_cron'][ $R ]['recurrence'] ?? '' ) && 'ab_mcp_pro_resume_auto_updates' === $R, 'S2 a hold with auto_off plans the event, twice a day' );
AB_MCP_Update_Log::hold( 'plugin', 'r/r.php', '2.0', 'fatal', '', true );
ok( 1 === $GLOBALS['ab_cron_scheduled'], 'S2 once: a second such hold plans no second event' );
AB_MCP_Update_Log::release( 'plugin', 'q/q.php' );
ok( false !== wp_next_scheduled( $R ), 'S3 one of two lifted: the event stays' );
AB_MCP_Update_Log::release( 'plugin', 'r/r.php' );
ok( false === wp_next_scheduled( $R ), 'S3 the last one lifted: the event goes' );
AB_MCP_Update_Log::hold( 'plugin', 's/s.php', '2.0', 'fatal', '', true );
$GLOBALS['ab_stuck']['auto_update_plugins'] = true;
AB_MCP_Update_Log::release( 'plugin', 's/s.php' );
unset( $GLOBALS['ab_stuck'] );
ok( false !== wp_next_scheduled( $R ), 'S4 a switch-on that did not land: the event stays for the next try' );
unset( $GLOBALS['ab_cron'][ $R ] );
AB_MCP_Update_Log::keep_resume_scheduled();
ok( false === wp_next_scheduled( $R ), 'S5 the event cleared by hand: a visitor\'s request leaves it so' );
$GLOBALS['ab_is_admin'] = true;
AB_MCP_Update_Log::keep_resume_scheduled();
ok( 'twicedaily' === ( $GLOBALS['ab_cron'][ $R ]['recurrence'] ?? '' ), 'S5 a request in wp-admin plans it again, as a hold needs it' );
$GLOBALS['ab_is_admin'] = false;
unset( $GLOBALS['ab_cron'][ $R ] );
$GLOBALS['ab_doing_cron'] = true;
AB_MCP_Update_Log::keep_resume_scheduled();
ok( false !== wp_next_scheduled( $R ), 'S5 so does a request of WP-Cron' );
AB_MCP_Update_Log::release( 'plugin', 's/s.php' );
AB_MCP_Update_Log::release( 'plugin', 'p/p.php' );
$GLOBALS['ab_cron'][ $R ] = array( 'time' => 1, 'recurrence' => 'twicedaily' );
AB_MCP_Update_Log::keep_resume_scheduled();
ok( false === wp_next_scheduled( $R ), 'S6 with no such hold open, a request of WP-Cron takes an event left behind off' );
$GLOBALS['ab_cron'][ $R ] = array( 'time' => 1, 'recurrence' => 'twicedaily' );
AB_MCP_Update_Log::resume_auto_updates();
ok( false === wp_next_scheduled( $R ), 'S7 the event itself, run with no such hold open, takes itself off' );
$GLOBALS['ab_doing_cron'] = false;

/* the note on the lock */
ok( null === AB_MCP_Update_Log::running(), 'R1 no note' );
AB_MCP_Update_Log::set_running( array( 'type' => 'plugin', 'item' => 'a/a.php', 'started' => 5, 'nonce' => 'n' ) );
ok( 'n' === AB_MCP_Update_Log::running()['nonce'], 'R2 the note is read back' );
$GLOBALS['ab_opts'][ AB_MCP_Update_Log::RUNNING ] = array( 'item' => 'x' );
ok( null === AB_MCP_Update_Log::running(), 'R3 a note without nonce and start is none' );
AB_MCP_Update_Log::clear_running();
ok( ! array_key_exists( AB_MCP_Update_Log::RUNNING, $GLOBALS['ab_opts'] ), 'R4 cleared' );
ok( empty( $GLOBALS['ab_site_only'] ), 'R5 log, holds and note: network options only' );

/* the main plugin file hooks the filters, late */
$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/alphabridge-mcp.php' );
ok( false !== strpos( $main, "add_filter( 'auto_update_plugin', array( 'AB_MCP_Update_Log', 'auto_update_plugin' ), 100, 2 );" ), 'F1 auto_update_plugin is hooked at 100' );
ok( false !== strpos( $main, "add_filter( 'auto_update_theme', array( 'AB_MCP_Update_Log', 'auto_update_theme' ), 100, 2 );" ), 'F1 auto_update_theme is hooked at 100' );
ok( false !== strpos( $main, "require_once AB_MCP_DIR . 'includes/lifecycle/class-update-log.php';" ) && strpos( $main, "require_once AB_MCP_DIR . 'includes/lifecycle/class-update-log.php';" ) < strpos( $main, 'AB_MCP_Plugin::instance()' ), 'F2 and loaded on every request, not only with the tools (in the main file, before the plugin boots)' );
ok( false !== strpos( $main, "add_action( AB_MCP_Update_Log::RESUME, array( 'AB_MCP_Update_Log', 'resume_auto_updates' ), 10, 0 );" ), 'F3 the plugin\'s own event runs the check that puts a package AlphaBridge switched off back on the list' );
ok( false !== strpos( $main, "add_action( 'init', array( 'AB_MCP_Update_Log', 'keep_resume_scheduled' ), 20, 0 );" ), 'F3 and init keeps it planned' );
ok( false === strpos( $main, "add_action( 'wp_update_plugins'" ) && false === strpos( $main, "add_action( 'wp_update_themes'" ), 'F3 not WordPress\'s update checks: they are not planned on a site that is not the main one' );

if ( $fails ) {
	printf( "Update-Log: FAILED (%d von %d)\n", $fails, $count );
	exit( 1 );
}
printf( "ALLE %d UPDATE-LOG-CHECKS BESTANDEN (0 Fehler)\n", $count );
exit( 0 );
