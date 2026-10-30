<?php
/**
 * wp_update_plugin / wp_update_theme through AB_MCP_Update_Guard: the
 * order of the steps, what goes to the upgrader, the verdict at work, the
 * roll-back, the lock, the budget, the log and the hold. Runs the REAL guard,
 * probe, log and tool classes against tests/standalone-lib/support/update-harness.php.
 *
 * Proves:
 *  - order: test before, maintenance mode, update, maintenance mode again,
 *    wait, key read again from .maintenance, test after, roll-back, wait,
 *    control, maintenance mode off, lock free, offers cleaned and loaded
 *    again exactly once;
 *  - bulk_upgrade() always gets clear_update_cache false;
 *  - true from bulk_upgrade with the files on disk unchanged is "not
 *    updated", not a success; a package that keeps the version of its
 *    header (or has none) but brings new code is installed, tested and put
 *    back like any other, and the answer names the offered version;
 *  - the test after stops at the first fatal error only where there was none
 *    before; a fatal error that was there before keeps the update with a
 *    warning, a new one at another target puts it back;
 *  - a target that timed out after the update is asked once more, with
 *    longer, before anything is put back; one that keeps timing out puts it
 *    back without a hold;
 *  - a fatal error and a cut MCP endpoint put the previous version back,
 *    byte for byte and active, and hold that version back from the automatic
 *    updates (a newer one passes, a call by name tries it again, and where it
 *    passes then it is kept, the hold lifted, and the answer says so);
 *  - on a network whose main site runs without Pro (multisite): the package
 *    comes off WordPress's list of automatic updates instead, the hold notes
 *    it and the answer says so; it goes back on when a newer version is kept
 *    through AlphaBridge or found installed by Pro's own check, a WP-Cron
 *    event planned on the site where Pro runs while such a hold is open and
 *    run here as WP-Cron runs it, not while 1.0.0, 2.0.0 or no package is
 *    there; an automatic update that was off stays off and is never switched
 *    on; a write that does not land is not claimed, and a switch-on that does
 *    not land is tried again by the next check while the version installed
 *    then still is, not after the previous one was put back by hand; a theme
 *    the same way; in each of these answers the exception, an update marked
 *    for automatic installation on every site, which skips the list; with
 *    Pro on the main site or network-wide the list stays as it is, nothing
 *    is planned and no exception is named;
 *  - the parent theme of the active child theme is tested and put back like
 *    the active theme;
 *  - a kept update's copy that WordPress's file access cannot rename is
 *    deleted before the call answers;
 *  - the budget: no update starts that the test and a roll-back after it
 *    would not fit; a site or host that hangs after the update, an update
 *    that takes the time of the test and a slow site keep the call within
 *    45 s (by the test guard's clock), and the targets it had no time for
 *    are "not checked", not failed;
 *  - the offers loaded again: a fresh time of the check is not an answer;
 *  - the lock (our note, WordPress's, our stale note, core's lock taken
 *    after our stale note), an exception inside the upgrader (lock free,
 *    maintenance mode off, no path in the answer), a .maintenance that
 *    cannot be written (own key, no 503 promise), a roll-back that fails
 *    (the shutdown delete is unhooked);
 *  - inactive, probe switched off, a site that cannot reach itself: updated
 *    without a test, and the answer says so;
 *  - the file undo points of the folder are dropped after a kept update only;
 *  - the tool refuses what is not an installed plugin, AlphaBridge itself, a
 *    single-file plugin, a host without direct writes, an offer without a
 *    package, and with no offer names the last update from the log;
 *  - through the tool itself (not ab_plugin_job()): an active plugin is
 *    tested, the log names the offered version, a fatal error is put back,
 *    and only an offer of wordpress.org names a previous package there.
 *
 * Run:  php tests/standalone-lifecycle/update-flow-test.php
 *       php tests/standalone-lifecycle/update-flow-test.php filemods
 *       php tests/standalone-lifecycle/update-flow-test.php oldcore
 *       php tests/standalone-lifecycle/update-flow-test.php multisite
 */

if ( isset( $argv[1] ) && 'filemods' === $argv[1] ) {
	// A constant cannot be taken back in one process: its own run.
	define( 'DISALLOW_FILE_MODS', true );
	require __DIR__ . '/../standalone-lib/support/update-harness.php';
	ab_reset();
	$p = ab_install_plugin( 'good', '1.0.0' );
	ab_offer( 'plugin', $p, '1.0.0', '2.0.0', ab_package_plugin( 'good', '2.0.0' ) );
	ab_install_theme( 'calm', '1.0.0' );
	ab_offer( 'theme', 'calm', '1.0.0', '2.0.0', ab_package_theme( 'calm', '2.0.0' ) );
	ok( 'ab_mcp_file_mods' === ab_code( AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => $p ) ) ), 'M1 DISALLOW_FILE_MODS refuses a plugin update' );
	ok( 'ab_mcp_file_mods' === ab_code( AB_MCP_Tools_Install_Update::update_theme( array( 'stylesheet' => 'calm' ) ) ), 'M1 and a theme update' );
	ok( array() === ab_events_like( 'bulk_upgrade' ) && array() === ab_events_like( 'lock:' ), 'M1 before anything else' );
	ab_finish( 'UPDATE-FLOW (filemods)' );
}

if ( isset( $argv[1] ) && 'oldcore' === $argv[1] ) {
	// WordPress 6.5: no request gets through the maintenance mode, test
	// requests included, and maintenance_mode() wants a connected file
	// system. The guard leaves the maintenance mode to bulk_upgrade().
	$GLOBALS['ab_old_core'] = true;
	require __DIR__ . '/../standalone-lib/support/update-harness.php';
	ab_reset();
	$p = ab_install_plugin( 'good', '1.0.0' );
	ab_offer( 'plugin', $p, '1.0.0', '2.0.0', ab_package_plugin( 'good', '2.0.0' ) );
	$r = AB_Test_Update_Guard::update( ab_plugin_job( $p ) );
	ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'] && 'passed' === $r['probe']['after']['result'], 'O1 a healthy update is kept, the test after passed: ' . ab_msg( $r ) );
	ok( 'not_supported' === $r['maintenance'] && false !== strpos( $r['warning'], 'Before WordPress 6.6' ), 'O1 the answer says the maintenance mode was not held for the test' );
	ok( 1 === count( ab_events_like( 'maintenance:on' ) ), 'O1 the guard writes no .maintenance itself (only bulk_upgrade() does)' );
	ab_reset();
	$q = ab_install_plugin( 'bad', '1.0.0' );
	ab_offer( 'plugin', $q, '1.0.0', '2.0.0', ab_package_plugin( 'bad', '2.0.0', 'AB_FATAL_LOAD' ) );
	$r = AB_Test_Update_Guard::update( ab_plugin_job( $q ) );
	ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'plugin', $q ), 'O2 a fatal error is still put back' );
	ok( ! file_exists( ABSPATH . '.maintenance' ), 'O2 no maintenance mode left' );
	ab_finish( 'UPDATE-FLOW (oldcore)' );
}

if ( isset( $argv[1] ) && 'multisite' === $argv[1] ) {
	// An update from another site of a network replaces the files of every
	// site; the lock is core's, on the main site.
	$GLOBALS['ab_multisite'] = true;
	require __DIR__ . '/../standalone-lib/support/update-harness.php';
	ab_reset();
	$GLOBALS['ab_blog'] = 3;
	$p = ab_install_plugin( 'good', '1.0.0' );
	ab_offer( 'plugin', $p, '1.0.0', '2.0.0', ab_package_plugin( 'good', '2.0.0' ) );
	$r = AB_Test_Update_Guard::update( ab_plugin_job( $p ) );
	ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'], 'N1 updated from site 3: ' . ab_msg( $r ) );
	ok( array( 1, 1 ) === $GLOBALS['ab_lock_blog'], 'N1 both locks, the update lock and the one for changes to active code, were taken on the main site' );
	ok( ab_in_order( array( 'switch_to_blog:1', 'lock:create', 'restore_current_blog', 'switch_to_blog:1', 'lock:release', 'restore_current_blog' ) ), 'N1 taken and freed there, and back to site 3 each time' );
	ok( 3 === $GLOBALS['ab_blog'], 'N1 the request is on site 3 again' );
	// Pro active on site 3 only: the hold cannot reach the automatic updates
	// on the main site, so WordPress's own list of them (a network option)
	// is what keeps 2.0.0 out.
	$GLOBALS['ab_pro_network'] = false;
	$GLOBALS['ab_pro_on_main'] = false;
	/** A fresh case on site 3: the list of automatic updates as given, no hold, bad 1.0.0 offered 2.0.0 with a fatal error. */
	function ab_net_case( array $list ) {
		ab_reset();
		$GLOBALS['ab_blog'] = 3;
		delete_site_option( AB_MCP_Update_Log::HOLDS );
		update_site_option( 'auto_update_plugins', $list );
		$q = ab_install_plugin( 'bad', '1.0.0' );
		ab_offer( 'plugin', $q, '1.0.0', '2.0.0', ab_package_plugin( 'bad', '2.0.0', 'AB_FATAL_LOAD' ) );
		return AB_Test_Update_Guard::update( ab_plugin_job( $q ) );
	}
	function ab_auto_list( $type = 'plugin' ) { return get_site_option( 'auto_update_' . $type . 's', array() ); }
	$q = 'bad/bad.php';
	// As alphabridge-mcp.php hooks it (update-log-test.php F3 holds the line).
	add_action( AB_MCP_Update_Log::RESUME, array( 'AB_MCP_Update_Log', 'resume_auto_updates' ), 10, 0 );
	function ab_resume_planned( $blog ) { return isset( $GLOBALS['ab_cron'][ $blog ][ AB_MCP_Update_Log::RESUME ] ) && 'twicedaily' === $GLOBALS['ab_cron'][ $blog ][ AB_MCP_Update_Log::RESUME ]['recurrence']; }

	echo "N2 the automatic update of the plugin was off\n";
	$r = ab_net_case( array( 'other/other.php' ) );
	$m = ab_msg( $r );
	ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && false !== strpos( $m, '2.0.0 will not be installed again automatically: the automatic update of the plugin bad is off.' ) && false === strpos( $m, 'neither by WordPress' ) && false === strpos( $m, 'switch' ), 'N2 Pro only on a site that is not the main one, the automatic update off: the answer says only that 2.0.0 does not come back by itself: ' . $m );
	ok( false !== strpos( $m, 'One exception: an update offered with the mark for automatic installation on every site (wordpress.org sets it for some security releases) can be installed by WordPress\'s automatic updates even where the automatic update is off, as the list is not read for it; AlphaBridge MCP active on the main site or network-wide holds 2.0.0 back from that too.' ), 'N2 and that an update marked for automatic installation on every site skips that setting: ' . $m );
	ok( array( 'other/other.php' ) === ab_auto_list() && false === AB_MCP_Update_Log::held( 'plugin', $q )['auto_off'], 'N2 WordPress\'s list stays as it was, and the hold notes nothing switched off' );
	ok( ! ab_resume_planned( 3 ) && ! ab_resume_planned( 1 ), 'N2 nothing switched off: Pro\'s own check is not planned' );
	ab_install_plugin( 'bad', '2.0.1' );
	AB_MCP_Update_Log::resume_auto_updates(); // as when it runs for another package's hold
	ok( array( 'other/other.php' ) === ab_auto_list() && null !== AB_MCP_Update_Log::held( 'plugin', $q ), 'N2 a newer version installed: an automatic update someone had switched off stays off, and Pro\'s check leaves the hold as it is (only one AlphaBridge switched off ends there)' );
	ab_install_plugin( 'bad', '1.0.0' );
	ab_offer( 'plugin', $q, '1.0.0', '2.0.1', ab_package_plugin( 'bad', '2.0.1' ) );
	$r = AB_Test_Update_Guard::update( ab_plugin_job( $q ) );
	ok( ! is_wp_error( $r ) && '2.0.1' === $r['version_after'] && array( 'other/other.php' ) === ab_auto_list() && false === strpos( $r['note'], 'automatic update' ), 'N2 a newer version kept through AlphaBridge: still off, and no word of it: ' . ab_msg( $r ) );

	echo "N4 the automatic update of the plugin was on\n";
	$r = ab_net_case( array( 'other/other.php', $q ) );
	$m = ab_msg( $r );
	ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && false !== strpos( $m, 'AlphaBridge has switched the automatic update of the plugin bad off, so that WordPress does not install 2.0.0 again: it runs its automatic updates on the main site of this network, where AlphaBridge MCP is not active. AlphaBridge switches it on again once a newer version is installed' ), 'N4 the answer says AlphaBridge switched it off, why, and when it comes back: ' . $m );
	ok( false === strpos( $m, 'switch the automatic update of this plugin or theme off' ) && false !== strpos( $m, 'Wait for a fixed release' ), 'N4 instead of asking for it, and with the advice for a fatal error' );
	ok( false !== strpos( $m, 'One exception: an update offered with the mark for automatic installation on every site (wordpress.org sets it for some security releases) can be installed by WordPress\'s automatic updates even where the automatic update is off, as the list is not read for it; AlphaBridge MCP active on the main site or network-wide holds 2.0.0 back from that too.' ), 'N4 and that an update marked for automatic installation on every site skips the list: ' . $m );
	ok( array( 'other/other.php' ) === ab_auto_list(), 'N4 off WordPress\'s list of automatic updates, the other entry stays' );
	$h = AB_MCP_Update_Log::held( 'plugin', $q );
	ok( true === $h['auto_off'] && '2.0.0' === $h['version'], 'N4 the hold notes that AlphaBridge switched it off' );
	ok( ab_resume_planned( 3 ) && ! ab_resume_planned( 1 ), 'N4 Pro\'s own check is planned, twice a day, on site 3 where Pro runs, not on the main site' );
	ab_run_cron( 3 );
	ok( array( 'other/other.php' ) === ab_auto_list() && null !== AB_MCP_Update_Log::held( 'plugin', $q ) && ab_resume_planned( 3 ), 'N5 Pro\'s check with 1.0.0 still installed: stays off, the hold and the check stay' );
	ab_install_plugin( 'bad', '2.0.0' );
	ab_run_cron( 3 );
	ok( array( 'other/other.php' ) === ab_auto_list() && null !== AB_MCP_Update_Log::held( 'plugin', $q ), 'N5 nor with 2.0.0 itself installed by hand' );
	ab_install_plugin( 'bad', '1.0.0' );
	$GLOBALS['ab_events'] = array();
	$r = AB_Test_Update_Guard::update( ab_plugin_job( $q ) );
	ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'AlphaBridge has switched the automatic update of the plugin bad off' ) && true === AB_MCP_Update_Log::held( 'plugin', $q )['auto_off'] && array( 'other/other.php' ) === ab_auto_list(), 'N6 tried again by name and put back again: still off through AlphaBridge, and still to be switched on again: ' . ab_msg( $r ) );
	ab_install_plugin( 'bad', '2.0.1' );
	ab_run_cron( 3 );
	ok( array( 'other/other.php', $q ) === ab_auto_list() && null === AB_MCP_Update_Log::held( 'plugin', $q ), 'N7 a newer version installed past AlphaBridge (wp-admin): back on the list when Pro\'s check runs on site 3, the hold lifted' );
	ok( ! ab_resume_planned( 3 ), 'N7 and with no such hold left, the check is no longer planned' );
	ab_run_cron( 3 );
	ok( array( 'other/other.php', $q ) === ab_auto_list(), 'N7 once, no second entry' );

	echo "N8 a newer version kept through AlphaBridge\n";
	ab_net_case( array( $q ) );
	ok( array() === ab_auto_list(), 'N8 switched off after the roll-back' );
	ab_offer( 'plugin', $q, '1.0.0', '2.0.1', ab_package_plugin( 'bad', '2.0.1' ) );
	$r = AB_Test_Update_Guard::update( ab_plugin_job( $q ) );
	ok( ! is_wp_error( $r ) && '2.0.1' === $r['version_after'] && array( $q ) === ab_auto_list() && null === AB_MCP_Update_Log::held( 'plugin', $q ), 'N8 kept: back on the list at once, the hold lifted: ' . ab_msg( $r ) );
	ok( false !== strpos( $r['note'], 'AlphaBridge switched the automatic update of the plugin bad on again, which it had switched off after a roll-back.' ), 'N8 and the answer says so: ' . $r['note'] );

	echo "N12 the switch-on after a kept update does not land\n";
	ab_net_case( array( $q ) );
	// A fixed package under the same version: 2.0.0 is no newer than the held one.
	ab_offer( 'plugin', $q, '1.0.0', '2.0.0', ab_package_plugin( 'bad', '2.0.0' ) );
	$GLOBALS['ab_opts_stuck']['auto_update_plugins'] = true;
	$r = AB_Test_Update_Guard::update( ab_plugin_job( $q ) );
	unset( $GLOBALS['ab_opts_stuck']['auto_update_plugins'] );
	$h = AB_MCP_Update_Log::held( 'plugin', $q );
	ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'] && array() === ab_auto_list() && false === strpos( $r['note'], 'on again' ) && is_array( $h ) && '2.0.0' === $h['switch_on'] && ab_resume_planned( 3 ), 'N12 kept, the write to the list does not land: no claim, the hold stays with the version installed, and the check stays planned: ' . ab_msg( $r ) );
	ab_run_cron( 3 );
	ok( array( $q ) === ab_auto_list() && null === AB_MCP_Update_Log::held( 'plugin', $q ), 'N12 the next check switches it on, though 2.0.0 is no newer than the held version, and lifts the hold' );
	ab_net_case( array( $q ) );
	ab_offer( 'plugin', $q, '1.0.0', '2.0.0', ab_package_plugin( 'bad', '2.0.0' ) );
	$GLOBALS['ab_opts_stuck']['auto_update_plugins'] = true;
	AB_Test_Update_Guard::update( ab_plugin_job( $q ) );
	unset( $GLOBALS['ab_opts_stuck']['auto_update_plugins'] );
	ab_install_plugin( 'bad', '1.0.0' );
	ab_run_cron( 3 );
	ok( array() === ab_auto_list() && '2.0.0' === AB_MCP_Update_Log::held( 'plugin', $q )['switch_on'], 'N12 1.0.0 put back by hand before the next check: not switched on, which would let WordPress install the held 2.0.0 again' );
	ab_install_plugin( 'bad', '2.0.0' );
	ab_run_cron( 3 );
	ok( array( $q ) === ab_auto_list() && null === AB_MCP_Update_Log::held( 'plugin', $q ), 'N12 the kept 2.0.0 back: switched on' );
	ab_net_case( array( $q ) );
	ab_offer( 'plugin', $q, '1.0.0', '2.0.0', ab_package_plugin( 'bad', '2.0.0' ) );
	$GLOBALS['ab_opts_stuck']['auto_update_plugins'] = true;
	AB_Test_Update_Guard::update( ab_plugin_job( $q ) );
	unset( $GLOBALS['ab_opts_stuck']['auto_update_plugins'] );
	ab_rm_any( WP_PLUGIN_DIR . '/bad' );
	ab_run_cron( 3 );
	ok( array() === ab_auto_list() && null !== AB_MCP_Update_Log::held( 'plugin', $q ), 'N12 the package deleted before the next check: nothing switched on' );

	echo "N9 the plugin deleted\n";
	ab_net_case( array( $q ) );
	ab_rm_any( WP_PLUGIN_DIR . '/bad' );
	ab_run_cron( 3 );
	ok( array() === ab_auto_list() && null !== AB_MCP_Update_Log::held( 'plugin', $q ), 'N9 Pro\'s check without the package: nothing switched on, the hold stays' );

	echo "N10 the list cannot be written\n";
	$GLOBALS['ab_opts']['auto_update_plugins']       = array( $q );
	$GLOBALS['ab_opts_stuck']['auto_update_plugins'] = true; // a filter keeps the old value
	$r = ab_net_case( array( $q ) );
	unset( $GLOBALS['ab_opts_stuck']['auto_update_plugins'] );
	$m = ab_msg( $r );
	ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && false !== strpos( $m, 'so they may install 2.0.0 again: switch the automatic update of this plugin or theme off, or activate AlphaBridge MCP network-wide.' ) && false === strpos( $m, 'AlphaBridge has switched' ), 'N10 a write to the list that does not land: the answer asks for it as before, and does not claim it: ' . $m );
	ok( false !== strpos( $m, 'One exception: an update offered with the mark for automatic installation on every site (wordpress.org sets it for some security releases) can be installed by WordPress\'s automatic updates even where the automatic update is off, as the list is not read for it; AlphaBridge MCP active on the main site or network-wide holds 2.0.0 back from that too.' ), 'N10 and that switching it off would not hold back an update marked for automatic installation on every site: ' . $m );
	ok( array( $q ) === ab_auto_list() && false === AB_MCP_Update_Log::held( 'plugin', $q )['auto_off'], 'N10 and the hold notes nothing switched off' );

	echo "N11 a theme\n";
	ab_reset();
	$GLOBALS['ab_blog'] = 3;
	delete_site_option( AB_MCP_Update_Log::HOLDS );
	update_site_option( 'auto_update_themes', array( 'calm' ) );
	update_site_option( 'auto_update_plugins', array( 'calm' ) );
	ab_install_theme( 'calm', '1.0.0' );
	ab_offer( 'theme', 'calm', '1.0.0', '2.0.0', ab_package_theme( 'calm', '2.0.0', 'AB_FATAL_LOAD' ) );
	$r = AB_MCP_Tools_Install_Update::update_theme( array( 'stylesheet' => 'calm' ) );
	ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'AlphaBridge has switched the automatic update of the theme calm off' ) && array() === ab_auto_list( 'theme' ) && array( 'calm' ) === ab_auto_list() && true === AB_MCP_Update_Log::held( 'theme', 'calm' )['auto_off'], 'N11 a theme the same way, off WordPress\'s list of themes (not of plugins): ' . ab_msg( $r ) );
	ab_install_theme( 'calm', '2.1.0' );
	ab_run_cron( 3 );
	ok( array( 'calm' ) === ab_auto_list( 'theme' ) && null === AB_MCP_Update_Log::held( 'theme', 'calm' ), 'N11 and back on it once a newer version is installed' );

	echo "N3 Pro where the automatic updates run\n";
	$GLOBALS['ab_pro_on_main'] = true;
	$r = ab_net_case( array( $q ) );
	ok( false !== strpos( ab_msg( $r ), 'will not be installed again automatically (neither by WordPress' ) && array( $q ) === ab_auto_list() && false === AB_MCP_Update_Log::held( 'plugin', $q )['auto_off'], 'N3 Pro also on the main site: the hold reaches the automatic updates, WordPress\'s list stays as it was: ' . ab_msg( $r ) );
	ok( false === strpos( ab_msg( $r ), 'One exception' ), 'N3 counter-check: where Pro\'s filter answers on the main site, it holds back a marked update too, and the answer names no exception: ' . ab_msg( $r ) );
	ok( ! ab_resume_planned( 3 ) && ! ab_resume_planned( 1 ), 'N3 and nothing is planned' );
	$GLOBALS['ab_pro_on_main'] = false;
	$GLOBALS['ab_pro_network'] = true;
	$r = ab_net_case( array( $q ) );
	ok( false !== strpos( ab_msg( $r ), 'will not be installed again automatically (neither by WordPress' ) && array( $q ) === ab_auto_list(), 'N3 so does Pro active network-wide' );
	ab_finish( 'UPDATE-FLOW (multisite)' );
}

require __DIR__ . '/../standalone-lib/support/update-harness.php';

/** Do these events occur in this order (each by its prefix)? */
function ab_in_order( array $want ) {
	$i = 0;
	foreach ( $GLOBALS['ab_events'] as $e ) {
		if ( $i < count( $want ) && 0 === strpos( $e, $want[ $i ] ) ) {
			++$i;
		}
	}
	return count( $want ) === $i;
}
/** Index of the first event with this prefix after $from, or -1. */
function ab_at( $prefix, $from = 0 ) {
	foreach ( $GLOBALS['ab_events'] as $i => $e ) {
		if ( $i >= $from && 0 === strpos( $e, $prefix ) ) {
			return $i;
		}
	}
	return -1;
}
function ab_sha( $file ) { return hash_file( 'sha256', WP_PLUGIN_DIR . '/' . $file ); }
function ab_requests_since( $n ) { return array_slice( $GLOBALS['ab_requests'], $n ); }

/* ================================================ F1 a healthy update */

echo "F1 healthy update of an active plugin\n";
ab_reset();
$f1 = ab_install_plugin( 'good', '1.0.0' );
ab_offer( 'plugin', $f1, '1.0.0', '2.0.0', ab_package_plugin( 'good', '2.0.0' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f1 ) );
ok( ! is_wp_error( $r ) && true === $r['updated'] && true === $r['kept'], 'F1 kept: ' . ab_msg( $r ) );
ok( '1.0.0' === $r['version_before'] && '2.0.0' === $r['version_after'], 'F1 versions before and after' );
ok( '2.0.0' === ab_installed_version( 'plugin', $f1 ), 'F1 2.0.0 is on disk' );
ok( true === $r['active_before'] && true === $r['active_after'] && is_plugin_active( $f1 ), 'F1 the plugin stays active' );
ok( 'passed' === $r['probe']['before']['result'] && 'passed' === $r['probe']['after']['result'] && true === $r['probe']['tested'], 'F1 the test before and after passed' );
ok( array( false ) === array_column( $GLOBALS['ab_bulk_args'], 'clear_update_cache' ), 'F1 bulk_upgrade() got clear_update_cache false' );
ok( 'held' === $r['maintenance'], 'F1 the maintenance mode was held' );
ok( ab_in_order( array( 'maintenance:on:', 'pause:1', 'bulk_upgrade' ) ), 'F1 a second between the maintenance mode and the first moved file' );
ok( ! is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/good' ) && 1 === count( glob( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/good.ab-old-*' ) ), 'F1 the copy of 1.0.0 is renamed to a name of its own' );
ok( false === has_action( 'shutdown', array( $GLOBALS['ab_last_upgrader'], 'delete_temp_backup' ) ), 'F1 core\'s delete by the plain name is taken off' );
ok( 1 === has_action( 'shutdown', array( 'AB_MCP_Update_Guard', 'on_shutdown' ) ), 'F1 update() puts the guard on the shutdown action at priority 1' );
// A later update of the same package puts its copy at the plain name before this request ends.
mkdir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/good', 0777, true );
file_put_contents( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/good/good.php', 'copy of a later update' );
ab_run_shutdown();
ok( is_file( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/good/good.php' ) && array() === glob( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/good.ab-old-*' ), 'F1 at shutdown only its own copy goes, not one a later update put at the plain name' );
ok(
	ab_in_order( array( 'lock:create', 'probe:home', 'probe:mcp', 'probe:admin_post', 'maintenance:on:', 'bulk_upgrade', 'maintenance:on:', 'pause:2', 'probe:home', 'probe:mcp', 'probe:admin_post', 'maintenance:off', 'lock:release', 'wp_clean_plugins_cache:1', 'wp_update_plugins' ) ),
	'F1 order: lock, test, maintenance, update (which writes its own .maintenance), wait, test, maintenance off, lock free, offers cleaned and loaded'
);
ok( 1 === count( ab_events_like( 'maintenance:off' ) ), 'F1 the maintenance mode goes off once, at the end: bulk_upgrade() does not switch it off in between' );
$after_bulk = ab_at( 'bulk_upgrade' );
ok( 1 === count( array_filter( array_slice( $GLOBALS['ab_events'], $after_bulk ), static function ( $e ) { return 'wp_update_plugins' === $e; } ) ), 'F1 the offers are loaded again exactly once after the update' );
ok( 1 === count( ab_events_like( 'wp_clean_plugins_cache:1' ) ), 'F1 and cleaned exactly once' );
// The key of the test after is the one of the LAST .maintenance write.
$ons  = ab_events_like( 'maintenance:on:' );
$last = (int) substr( end( $ons ), strlen( 'maintenance:on:' ) );
$rq   = ab_requests_since( 3 );
ok( 3 === count( $rq ) && md5( (string) $last ) === $rq[0]['key'] && (string) $last === $rq[0]['nonce'], 'F1 the test after uses md5( $upgrading ) and $upgrading of the last .maintenance write' );
ok( is_string( $rq[0]['transient'] ) && (string) $last === $rq[0]['transient'], 'F1 and the transient holds the nonce as a string' );
ok( ! file_exists( ABSPATH . '.maintenance' ), 'F1 the maintenance mode is off at the end' );
ok( false === get_option( 'auto_updater.lock' ) && null === AB_MCP_Update_Log::running(), 'F1 the lock and our note are gone' );
ok( array( array( 'plugin', 'good' ) ) === AB_MCP_Undo::$dropped && array( 'good/old.php' ) === $r['dropped_file_undo'], 'F1 the file undo points of the folder are dropped' );
ok( AB_MCP_Update_Log::replaced_at( 'plugin', 'good' ) > 0 && AB_MCP_Update_Log::replaced_at( 'plugin', 'good' ) <= time(), 'F1 and the network marks the folder as replaced, for the file undo points of every site' );
ok( true === $r['offers_refreshed'] && ! isset( get_site_transient( 'update_plugins' )->response[ $f1 ] ), 'F1 the offers are fresh, and 2.0.0 is no longer offered' );
$log = AB_MCP_Update_Log::last_for( 'plugin', $f1 );
ok( is_array( $log ) && 'kept' === $log['result'] && '1.0.0' === $log['from'] && '2.0.0' === $log['to'] && false === $log['hold'], 'F1 the log has the update' );
ok( null === $r['undo'] && false !== strpos( $r['way_back'], 'no undo point' ), 'F1 no undo point, and the answer says so' );
ok( false !== strpos( $r['probe']['not_checked'], 'wp-admin screens' ), 'F1 the answer says what was not checked' );

/* ============================================== F2 fatal error, put back */

echo "F2 update with a fatal error\n";
ab_reset();
$f2  = ab_install_plugin( 'bad', '1.0.0' );
$sha = ab_sha( $f2 );
ab_offer( 'plugin', $f2, '1.0.0', '2.0.0', ab_package_plugin( 'bad', '2.0.0', 'AB_FATAL_LOAD' ) );
update_site_option( 'auto_update_plugins', array( $f2 ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f2 ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ), 'F2 rolled back: ' . ab_code( $r ) );
ok( '1.0.0' === ab_installed_version( 'plugin', $f2 ) && $sha === ab_sha( $f2 ), 'F2 1.0.0 is back, byte for byte' );
ok( is_plugin_active( $f2 ), 'F2 and active' );
ok( 0 === AB_MCP_Update_Log::replaced_at( 'plugin', 'bad' ), 'F2 put back: the folder is not marked as replaced' );
$m = ab_msg( $r );
ok( false !== strpos( $m, 'Not kept' ) && false !== strpos( $m, 'fatal error' ) && false !== strpos( $m, 'wp-content/plugins/bad/bad.php' ) && false !== strpos( $m, 'Version 1.0.0 is back and active' ), 'F2 the message says what failed where and what is back: ' . $m );
ok( false !== strpos( $m, 'The test requests pass again' ), 'F2 and that the control passed' );
ok( false !== strpos( $m, 'will not be installed again automatically' ), 'F2 and that 2.0.0 is held back' );
ok( false === strpos( $m, 'not asked' ), 'F2 the targets not asked after the first fatal error are not named' );
$d = ab_data( $r );
ok( 'passed' === $d['probe']['after_rollback']['result'], 'F2 the control after the roll-back passed' );
// The test after stopped at the first fatal error: no fatal error before.
$p1 = ab_at( 'pause:2' );
$mv = ab_at( 'move_dir:', $p1 );
$asked_after = array_slice( $GLOBALS['ab_events'], $p1, $mv - $p1 );
ok( array( 'probe:home' ) === array_values( array_filter( $asked_after, static function ( $e ) { return 0 === strpos( $e, 'probe:' ); } ) ), 'F2 the test after stopped at the first fatal error' );
ok( ab_in_order( array( 'move_dir:plugins/bad>plugins/bad.ab-new-', 'move_dir:plugins/bad>plugins/bad', 'pause:2', 'probe:home', 'probe:mcp', 'probe:admin_post', 'maintenance:off', 'lock:release' ) ), 'F2 the new version is moved aside first, the copy back, then wait, control, maintenance off' );
ok( 1 === count( glob( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/bad.ab-new-*' ) ), 'F2 the new version waits under its own name until shutdown' );
ok( false === has_action( 'shutdown', array( $GLOBALS['ab_last_upgrader'], 'delete_temp_backup' ) ), 'F2 core\'s delete of the copy at shutdown is taken off' );
ab_run_shutdown();
ok( ! is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/bad' ) && array() === glob( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/bad.ab-new-*' ), 'F2 after shutdown neither the copy nor the new version is left in upgrade-temp-backup' );
$hold = AB_MCP_Update_Log::held( 'plugin', $f2 );
ok( is_array( $hold ) && '2.0.0' === $hold['version'] && 'fatal' === $hold['reason'], 'F2 2.0.0 is held back' );
ok( array( $f2 ) === get_site_option( 'auto_update_plugins' ) && false === $hold['auto_off'], 'F2 on a single site the filter holds it back: WordPress\'s list of automatic updates stays as it was' );
delete_site_option( 'auto_update_plugins' );
ok( false === AB_MCP_Update_Log::auto_update_plugin( true, (object) array( 'plugin' => $f2, 'new_version' => '2.0.0' ) ), 'F2 the automatic update of 2.0.0 is refused' );
ok( true === AB_MCP_Update_Log::auto_update_plugin( true, (object) array( 'plugin' => $f2, 'new_version' => '2.0.1' ) ), 'F2 a newer version passes' );
ok( isset( get_site_transient( 'update_plugins' )->response[ $f2 ] ), 'F2 the update is offered again' );
ok( array() === AB_MCP_Undo::$dropped, 'F2 no file undo point is dropped' );
ok( ! file_exists( ABSPATH . '.maintenance' ) && false === get_option( 'auto_updater.lock' ), 'F2 maintenance mode off, lock free' );
$log = AB_MCP_Update_Log::last_for( 'plugin', $f2 );
ok( 'rolled_back' === $log['result'] && 'fatal' === $log['reason'] && true === $log['hold'] && '1.0.0' === $log['installed'] && 'fatal' === $log['probe']['after']['targets'][0]['result'], 'F2 the log has the roll-back with the error' );

echo "F3 a held version named again is tried again\n";
$GLOBALS['ab_events'] = array();
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f2 ) );
ok( 1 === count( ab_events_like( 'bulk_upgrade' ) ) && 'ab_mcp_update_rolled_back' === ab_code( $r ), 'F3 tried again, and put back again' );
// The same version passes the next time (the vendor fixed the package, or what clashed with it is gone).
ab_run_shutdown();
ab_offer( 'plugin', $f2, '1.0.0', '2.0.0', ab_package_plugin( 'bad', '2.0.0' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f2 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'] && false !== strpos( $r['warning'], '2.0.0 had been rolled back on' ) && false !== strpos( $r['warning'], 'kept this time' ), 'F3 kept when it passes, and the answer says it had been rolled back: ' . ( is_array( $r ) ? $r['warning'] : ab_msg( $r ) ) );
ok( null === AB_MCP_Update_Log::held( 'plugin', $f2 ), 'F3 and the hold is lifted' );

/* ============================================ F4 untested and unchanged */

echo "F4 inactive plugin\n";
ab_reset();
$f4 = ab_install_plugin( 'idle', '1.0.0', '', false );
ab_offer( 'plugin', $f4, '1.0.0', '2.0.0', ab_package_plugin( 'idle', '2.0.0', 'AB_FATAL_LOAD' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f4 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'] && false === $r['probe']['tested'], 'F4 updated without a test' );
ok( array() === $GLOBALS['ab_requests'] && array() === ab_events_like( 'maintenance:on' ) && array() === ab_events_like( 'pause:' ), 'F4 no test request, no maintenance mode, no wait' );
ok( false !== strpos( $r['note'], 'not active' ), 'F4 the answer says why: ' . $r['note'] );
ok( 'kept' === AB_MCP_Update_Log::last_for( 'plugin', $f4 )['result'], 'F4 the log says kept' );

echo "F5 true from bulk_upgrade with the version unchanged\n";
ab_reset();
$f5 = ab_install_plugin( 'same', '1.0.0' );
ab_offer( 'plugin', $f5, '1.0.0', '2.0.0', ab_package_plugin( 'same', '2.0.0' ) );
$job = ab_plugin_job( $f5 );
delete_site_transient( 'update_plugins' ); // the offer is gone when the upgrader looks: "up_to_date", true
$r = AB_Test_Update_Guard::update( $job );
ok( 'ab_mcp_update_not_updated' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'still at version 1.0.0' ), 'F5 not updated: ' . ab_msg( $r ) );
ok( array() === ab_events_like( 'pause:2' ) && ! file_exists( ABSPATH . '.maintenance' ), 'F5 no test after it, maintenance mode off' );
ok( 'not_updated' === AB_MCP_Update_Log::last_for( 'plugin', $f5 )['result'], 'F5 the log says not updated' );

/* ======================================= F6, F7 a fatal error before */

echo "F6 a fatal error that was there before, healthy update\n";
ab_reset();
ab_install_plugin( 'noisy', '1.0.0', 'AB_FATAL_FRONT' );
$f6 = ab_install_plugin( 'fine', '1.0.0' );
ab_offer( 'plugin', $f6, '1.0.0', '2.0.0', ab_package_plugin( 'fine', '2.0.0' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f6 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'], 'F6 kept: ' . ab_msg( $r ) );
ok( false !== strpos( $r['warning'], 'fatal error that was there before' ), 'F6 with the warning' );
ok( array( 'fatal', 'ok', 'ok' ) === array_column( $r['probe']['after']['targets'], 'result' ), 'F6 the test after asked every target, not only to the first fatal error' );

echo "F7 a fatal error before, and the update breaks the MCP endpoint\n";
ab_reset();
ab_install_plugin( 'noisy', '1.0.0', 'AB_FATAL_FRONT' );
$f7 = ab_install_plugin( 'restless', '1.0.0' );
ab_offer( 'plugin', $f7, '1.0.0', '2.0.0', ab_package_plugin( 'restless', '2.0.0', 'AB_FATAL_REST' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f7 ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'plugin', $f7 ), 'F7 put back' );
ok( false !== strpos( ab_msg( $r ), 'this MCP endpoint: fatal error' ), 'F7 because of the new fatal error at this MCP endpoint: ' . ab_msg( $r ) );
ok( 'fatal' === AB_MCP_Update_Log::held( 'plugin', $f7 )['reason'], 'F7 and held back' );

/* ========================================================= F8, F9 time */

echo "F8 the first request after the update times out\n";
ab_reset();
$f8 = ab_install_plugin( 'sleepy', '1.0.0' );
ab_offer( 'plugin', $f8, '1.0.0', '2.0.0', ab_package_plugin( 'sleepy', '2.0.0', 'AB_SLOW_ONCE' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f8 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'], 'F8 kept after asking again: ' . ab_msg( $r ) );
$homes = array_values( array_filter( $GLOBALS['ab_requests'], static function ( $q ) { return 'home' === $q['target']; } ) );
ok( 3 === count( $homes ) && $homes[2]['timeout'] > AB_MCP_Php_Probe::TIMEOUT && $homes[2]['timeout'] <= AB_MCP_Update_Guard::REASK_MAX, 'F8 the home page was asked again, with longer (' . ( isset( $homes[2] ) ? $homes[2]['timeout'] : '?' ) . ' s)' );
ok( ! empty( $r['probe']['after']['targets'][0]['asked_again'] ), 'F8 the answer says it was asked again' );
ok( 'passed' === $r['probe']['after']['result'], 'F8 and the result after is that of the second answer, not of the timeout: ' . $r['probe']['after']['result'] );

echo "F9 the update hangs the site\n";
ab_reset();
$f9 = ab_install_plugin( 'hangs', '1.0.0' );
ab_offer( 'plugin', $f9, '1.0.0', '2.0.0', ab_package_plugin( 'hangs', '2.0.0', 'AB_HANG' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f9 ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'plugin', $f9 ), 'F9 put back' );
ok( null === AB_MCP_Update_Log::held( 'plugin', $f9 ), 'F9 not held back: a target that no longer answers can be the host' );
ok( false !== strpos( ab_msg( $r ), 'stays offered' ), 'F9 and the message says it stays offered' );
ok( AB_Test_Update_Guard::elapsed() <= AB_MCP_Update_Guard::BUDGET, sprintf( 'F9 the call stays within its budget of %d s (%.1f s by the guard\'s clock)', AB_MCP_Update_Guard::BUDGET, AB_Test_Update_Guard::elapsed() ) );
ok( array() === array_filter( $GLOBALS['ab_requests'], static function ( $q ) { return $q['timeout'] > AB_MCP_Php_Probe::TIMEOUT; } ), 'F9 no target is asked again: the budget has no room for a longer second try' );
ok( 'passed' === ab_data( $r )['probe']['after_rollback']['result'], 'F9 the control after the roll-back still fits and passes' );

ok( false === strpos( ab_msg( $r ), 'without WordPress\'s test report' ) && false !== strpos( ab_msg( $r ), 'If the site is only slow' ), 'F9 the message about time, where nothing answered' );

echo "F9 the new version ends the answer itself\n";
ab_reset();
$f9e = ab_install_plugin( 'ender', '1.0.0' );
ab_offer( 'plugin', $f9e, '1.0.0', '2.0.0', ab_package_plugin( 'ender', '2.0.0', 'AB_ENDS_ALL' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f9e ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'plugin', $f9e ) && null === AB_MCP_Update_Log::held( 'plugin', $f9e ), 'F9 put back, not held back' );
ok( false !== strpos( ab_msg( $r ), 'With the new version the site answered without WordPress\'s test report' ) && false !== strpos( ab_msg( $r ), 'The update stays offered' ) && false === strpos( ab_msg( $r ), 'If the site is only slow' ), 'F9 the message says what it means, not that the site is slow: ' . ab_msg( $r ) );
ab_reset();
$f9m = ab_install_plugin( 'mixed', '1.0.0' );
ab_offer( 'plugin', $f9m, '1.0.0', '2.0.0', ab_package_plugin( 'mixed', '2.0.0', 'AB_FRONT_HANGS AB_ENDS_ALL' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f9m ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'without WordPress\'s test report' ) && false !== strpos( ab_msg( $r ), 'If the site is only slow' ), 'F9 one target timed out, the others answered without the report: both are said: ' . ab_msg( $r ) );

echo "F9 only the home page hangs after the update, the other targets answer (Codex, 03.10.2026)\n";
ab_reset();
$f9b  = ab_install_plugin( 'fronthangs', '1.0.0' );
$sha0 = ab_sha( $f9b );
ab_offer( 'plugin', $f9b, '1.0.0', '2.0.0', ab_package_plugin( 'fronthangs', '2.0.0', 'AB_FRONT_HANGS' ) );
$r     = AB_Test_Update_Guard::update( ab_plugin_job( $f9b ) );
$homes = array_values( array_filter( $GLOBALS['ab_requests'], static function ( $q ) { return 'home' === $q['target']; } ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && $sha0 === ab_sha( $f9b ), 'F9 put back, byte for byte: ' . ab_code( $r ) . ' ' . ab_msg( $r ) );
ok( 4 === count( $homes ) && $homes[2]['timeout'] > AB_MCP_Php_Probe::TIMEOUT, 'F9 the home page was asked again, with longer (' . ( isset( $homes[2] ) ? round( $homes[2]['timeout'], 1 ) : '?' ) . ' s)' );
$row = is_wp_error( $r ) ? ab_data( $r )['probe']['after']['targets'][0] : array();
ok( isset( $row['result'] ) && 'unreachable' === $row['result'] && empty( $row['out_of_time'] ) && true === $row['asked_again'], 'F9 the second try ran into the deadline without an answer: the first timeout stands, not "not checked"' );
ok( false !== strpos( ab_msg( $r ), 'stays offered' ) && null === AB_MCP_Update_Log::held( 'plugin', $f9b ), 'F9 not held back, as for any target that stopped answering' );
$d = ab_data( $r );
ok( isset( $d['probe']['after_rollback']['result'] ) && 'passed' === $d['probe']['after_rollback']['result'] && AB_Test_Update_Guard::elapsed() <= AB_MCP_Update_Guard::BUDGET, sprintf( 'F9 the second try left the roll-back its reserve: the control passes, within the budget (%.1f s by the guard\'s clock)', AB_Test_Update_Guard::elapsed() ) );

/* ================================================ F10 MCP endpoint cut */

echo "F10 the update cuts the MCP endpoint\n";
ab_reset();
$f10 = ab_install_plugin( 'closer', '1.0.0' );
ab_offer( 'plugin', $f10, '1.0.0', '2.0.0', ab_package_plugin( 'closer', '2.0.0', 'AB_CLOSE_REST' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f10 ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'plugin', $f10 ), 'F10 put back' );
ok( false !== strpos( ab_msg( $r ), 'cut this connection' ), 'F10 the message says it would cut the connection' );
ok( 'mcp_cut' === AB_MCP_Update_Log::held( 'plugin', $f10 )['reason'], 'F10 and held back' );

/* =================================================== F11 the budget */

echo "F11 no time left in the budget\n";
ab_reset();
$f11 = ab_install_plugin( 'late', '1.0.0' );
ab_offer( 'plugin', $f11, '1.0.0', '2.0.0', ab_package_plugin( 'late', '2.0.0' ) );
AB_Test_Update_Guard::$start = microtime( true ) - 44;
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f11 ) );
ok( 'ab_mcp_update_budget' === ab_code( $r ), 'F11 refused: ' . ab_code( $r ) );
ok( array() === ab_events_like( 'bulk_upgrade' ) && '1.0.0' === ab_installed_version( 'plugin', $f11 ), 'F11 nothing was updated' );
ok( false === get_option( 'auto_updater.lock' ) && ! file_exists( ABSPATH . '.maintenance' ), 'F11 lock free, no maintenance mode' );
AB_Test_Update_Guard::$start = microtime( true ) - 35;
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f11 ) );
ok( 'ab_mcp_update_budget' === ab_code( $r ) && array() === ab_events_like( 'bulk_upgrade' ), 'F11 with 10 s left it does not start: the test and a roll-back after the update would not fit' );
AB_Test_Update_Guard::$start = microtime( true ) - 25;
$GLOBALS['ab_events'] = array();
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f11 ) );
ok( ! is_wp_error( $r ) && true === $r['probe']['tested'], 'F11 with 20 s left it runs, tested: ' . ab_msg( $r ) );

/* ===================================================== F12-F14 lock */

echo "F12-F14 the lock\n";
ab_reset();
$f12 = ab_install_plugin( 'locked', '1.0.0' );
ab_offer( 'plugin', $f12, '1.0.0', '2.0.0', ab_package_plugin( 'locked', '2.0.0' ) );
update_option( 'auto_updater.lock', time() - 30 );
AB_MCP_Update_Log::set_running( array( 'type' => 'plugin', 'item' => 'other/other.php', 'started' => time() - 30, 'nonce' => 'abc' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f12 ) );
ok( 'ab_mcp_update_busy' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'other/other.php through AlphaBridge has been running' ), 'F12 our own note: ' . ab_msg( $r ) );
ok( array() === ab_events_like( 'bulk_upgrade' ) && 'abc' === AB_MCP_Update_Log::running()['nonce'], 'F12 nothing ran, the other note stays' );
AB_MCP_Update_Log::clear_running();
update_option( 'auto_updater.lock', time() - 300 );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f12 ) );
ok( 'ab_mcp_update_busy' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'background updates' ), 'F13 without our note: WordPress\'s background updates' );
update_option( 'auto_updater.lock', time() - 2 );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f12 ) );
ok( 'ab_mcp_update_busy' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'a moment ago' ), 'F13 a lock seconds old without a note: no guess who' );
update_option( 'auto_updater.lock', time() - 700 + 3600 - 3600 );
update_option( 'auto_updater.lock', time() - 700 );
AB_MCP_Update_Log::set_running( array( 'type' => 'plugin', 'item' => 'other/other.php', 'started' => time() - 700, 'nonce' => 'old' ) );
$GLOBALS['ab_events'] = array();
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f12 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === ab_installed_version( 'plugin', $f12 ), 'F14 our note older than 10 minutes: the lock is taken over and the update runs' );
ok( false === get_option( 'auto_updater.lock' ) && null === AB_MCP_Update_Log::running(), 'F14 and freed at the end' );
// Our note is from a request that died over an hour ago, and core's background updates took the lock since.
$since = time() - 100;
update_option( 'auto_updater.lock', $since );
AB_MCP_Update_Log::set_running( array( 'type' => 'plugin', 'item' => 'other/other.php', 'started' => time() - 4000, 'nonce' => 'dead' ) );
$GLOBALS['ab_events'] = array();
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f12 ) );
ok( 'ab_mcp_update_busy' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'background updates are running' ), 'F14 a lock taken after our old note is not ours: busy, core\'s background updates: ' . ab_msg( $r ) );
ok( $since === get_option( 'auto_updater.lock' ) && array() === ab_events_like( 'lock:release' ) && array() === ab_events_like( 'bulk_upgrade' ), 'F14 their lock stays, nothing ran' );
AB_MCP_Update_Log::clear_running();
delete_option( 'auto_updater.lock' );

/* ========================================== F15 an exception inside */

echo "F15 the upgrader throws\n";
ab_reset();
$f15 = ab_install_plugin( 'throws', '1.0.0' );
ab_offer( 'plugin', $f15, '1.0.0', '2.0.0', ab_package_plugin( 'throws', '2.0.0' ) );
$GLOBALS['ab_bulk_throw'] = true;
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f15 ) );
ok( 'ab_mcp_update_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), '"update"' ), 'F15 a clear error naming the step: ' . ab_msg( $r ) );
ok( false === strpos( ab_msg( $r ), '/secret/path' ), 'F15 without the exception text' );
ok( false === get_option( 'auto_updater.lock' ) && null === AB_MCP_Update_Log::running() && ! file_exists( ABSPATH . '.maintenance' ), 'F15 lock free, note gone, maintenance mode off' );
$log = AB_MCP_Update_Log::last_for( 'plugin', $f15 );
ok( 'failed' === $log['result'] && false !== strpos( $log['error'], 'RuntimeException' ), 'F15 the log keeps the exception' );

/* ==================================== F16 no maintenance file possible */

echo "F16 .maintenance cannot be written\n";
ab_reset();
$f16 = ab_install_plugin( 'nomaint', '1.0.0' );
ab_offer( 'plugin', $f16, '1.0.0', '2.0.0', ab_package_plugin( 'nomaint', '2.0.0', 'AB_FATAL_LOAD' ) );
$GLOBALS['ab_maint_fail'] = true;
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f16 ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'plugin', $f16 ), 'F16 the fatal error is still found and put back' );
ok( 'not_possible' === ab_data( $r )['maintenance'], 'F16 maintenance: not_possible' );
$rq = $GLOBALS['ab_requests'];
ok( preg_match( '/^[a-f0-9]{32}\z/', $rq[3]['key'] ) && 1 !== preg_match( '/^\d+\z/', $rq[3]['nonce'] ), 'F16 the test after uses a key of its own' );
ab_reset();
$f16b = ab_install_plugin( 'nomaint2', '1.0.0' );
ab_offer( 'plugin', $f16b, '1.0.0', '2.0.0', ab_package_plugin( 'nomaint2', '2.0.0' ) );
$GLOBALS['ab_maint_fail'] = true;
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f16b ) );
ok( ! is_wp_error( $r ) && 'not_possible' === $r['maintenance'] && false !== strpos( $r['warning'], 'could not switch on its maintenance mode' ), 'F16 a kept update promises no maintenance page' );

/* ================================================ F17 roll-back fails */

echo "F17 the roll-back fails\n";
ab_reset();
$f17 = ab_install_plugin( 'stuck', '1.0.0' );
ab_offer( 'plugin', $f17, '1.0.0', '2.0.0', ab_package_plugin( 'stuck', '2.0.0', 'AB_FATAL_LOAD' ) );
$GLOBALS['ab_move_fail'] = 'plugins/stuck';
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f17 ) );
ok( 'ab_mcp_update_rollback_failed' === ab_code( $r ), 'F17 the answer says the roll-back failed: ' . ab_code( $r ) );
ok( AB_MCP_Update_Log::replaced_at( 'plugin', 'stuck' ) > 0, 'F17 the new version stays: the folder is marked as replaced' );
ok( false !== strpos( ab_msg( $r ), 'upgrade-temp-backup/plugins/stuck' ), 'F17 and where the copy is' );
ok( false === has_action( 'shutdown', array( $GLOBALS['ab_last_upgrader'], 'delete_temp_backup' ) ) && array() !== ab_events_like( 'remove_action:shutdown' ), 'F17 the delete at shutdown is unhooked, so the copy stays' );
ab_run_shutdown();
ok( is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/stuck' ), 'F17 and is still there after shutdown' );
ok( is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/stuck' ) && '2.0.0' === ab_installed_version( 'plugin', $f17 ), 'F17 the copy is there, and the plugin folder is not left missing' );
ok( ! file_exists( ABSPATH . '.maintenance' ) && false === get_option( 'auto_updater.lock' ), 'F17 maintenance mode off, lock free' );

// The way back breaks off half-way through the copy WordPress falls back to (Codex, 03.10.2026):
// the previous version's main file is in the folder, its include is not.
ab_reset();
$f17b = ab_install_plugin( 'half', '1.0.0' );
file_put_contents( WP_PLUGIN_DIR . '/half/zinc.php', "<?php\n// include of 1.0.0\n" );
$pkg = ab_package_plugin( 'half', '2.0.0', 'AB_FATAL_LOAD' );
file_put_contents( $pkg . '/zinc.php', "<?php\n// include of 2.0.0\n" );
ab_offer( 'plugin', $f17b, '1.0.0', '2.0.0', $pkg );
$GLOBALS['ab_move_partial'] = array( 'plugins/half' => 1 );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f17b ) );
ok( 'ab_mcp_update_rollback_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'The new version is still on the site.' ), 'F17 a copy that breaks off: the roll-back failed, the new version is on the site: ' . ab_msg( $r ) );
ok( '2.0.0' === ab_installed_version( 'plugin', $f17b ) && "<?php\n// include of 2.0.0\n" === file_get_contents( WP_PLUGIN_DIR . '/half/zinc.php' ), 'F17 the complete new version is back in the folder, its include too' );
ok( is_file( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/half/zinc.php' ) && '1.0.0' === get_file_data( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/half/half.php', array( 'Version' => 'Version' ) )['Version'], 'F17 and the previous version is whole in upgrade-temp-backup' );
ab_run_shutdown();
ok( is_file( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/half/zinc.php' ) && ! file_exists( ABSPATH . '.maintenance' ), 'F17 it stays there after shutdown; maintenance mode off' );
// And the new version cannot be moved back either: the answer does not claim it is on the site.
ab_reset();
$f17c = ab_install_plugin( 'halfer', '1.0.0' );
file_put_contents( WP_PLUGIN_DIR . '/halfer/zinc.php', "<?php\n// include of 1.0.0\n" );
$pkg = ab_package_plugin( 'halfer', '2.0.0', 'AB_FATAL_LOAD' );
file_put_contents( $pkg . '/zinc.php', "<?php\n// include of 2.0.0\n" );
ab_offer( 'plugin', $f17c, '1.0.0', '2.0.0', $pkg );
$GLOBALS['ab_move_partial'] = array( 'plugins/halfer' => 2 );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f17c ) );
ok( 'ab_mcp_update_rollback_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'may be missing on the site' ) && false === strpos( ab_msg( $r ), 'The new version is still on the site.' ), 'F17 neither way back works: the answer says parts may be missing: ' . ab_msg( $r ) );
ok( is_file( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/halfer/zinc.php' ), 'F17 and the previous version is whole in upgrade-temp-backup' );

/* ======================================== F18, F19 nothing to test with */

echo "F18 test requests switched off\n";
ab_reset();
$f18 = ab_install_plugin( 'noprobe', '1.0.0' );
ab_offer( 'plugin', $f18, '1.0.0', '2.0.0', ab_package_plugin( 'noprobe', '2.0.0' ) );
$GLOBALS['ab_filters']['ab_mcp_php_probe_enabled'] = false;
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f18 ) );
ok( ! is_wp_error( $r ) && array() === $GLOBALS['ab_requests'] && false !== strpos( $r['warning'], 'switched off' ), 'F18 updated without a test, with the warning' );

echo "F19 the site cannot reach itself\n";
ab_reset();
$f19 = ab_install_plugin( 'island', '1.0.0' );
ab_offer( 'plugin', $f19, '1.0.0', '2.0.0', ab_package_plugin( 'island', '2.0.0' ) );
$GLOBALS['ab_net'] = 'down';
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f19 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'] && false !== strpos( $r['warning'], 'Not checked' ), 'F19 updated, "not checked": ' . ( is_array( $r ) ? $r['warning'] : ab_msg( $r ) ) );
ok( 3 === count( $GLOBALS['ab_requests'] ), 'F19 the targets not reached before are not asked again' );
ok( 'not_checked' === AB_MCP_Update_Log::last_for( 'plugin', $f19 )['result'], 'F19 the log says not checked' );

/* ======================================================= F20 themes */

echo "F20 themes\n";
ab_reset();
ab_install_theme( 'calm', '1.0.0' );
$GLOBALS['ab_stylesheet'] = 'calm';
$GLOBALS['ab_template']   = 'calm';
$tsha = hash_file( 'sha256', get_theme_root() . '/calm/functions.php' );
ab_offer( 'theme', 'calm', '1.0.0', '2.0.0', ab_package_theme( 'calm', '2.0.0', 'AB_FATAL_LOAD' ) );
$r = AB_MCP_Tools_Install_Update::update_theme( array( 'stylesheet' => 'calm' ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'theme', 'calm' ), 'F20 a fatal error in the active theme is put back: ' . ab_code( $r ) );
ok( $tsha === hash_file( 'sha256', get_theme_root() . '/calm/functions.php' ), 'F20 byte for byte' );
ok( false !== strpos( ab_msg( $r ), 'still the active theme' ), 'F20 the message says the theme is still active' );
ok( false === AB_MCP_Update_Log::auto_update_theme( true, (object) array( 'theme' => 'calm', 'new_version' => '2.0.0' ) ), 'F20 and the automatic update of that version is refused' );
ok( ab_in_order( array( 'move_dir:themes/calm>themes/calm.ab-new-', 'move_dir:themes/calm>themes/calm', 'wp_clean_themes_cache:1', 'wp_update_themes' ) ), 'F20 restored from themes/, theme offers cleaned and loaded again' );
ab_reset();
ab_install_theme( 'calm', '1.0.0' );
ab_offer( 'theme', 'calm', '1.0.0', '2.1.0', ab_package_theme( 'calm', '2.1.0' ) );
$r = AB_MCP_Tools_Install_Update::update_theme( array( 'stylesheet' => 'calm' ) );
ok( ! is_wp_error( $r ) && '2.1.0' === $r['version_after'] && 'active' === $r['role'] && 'calm' === $r['stylesheet'], 'F20 a healthy theme update is kept' );
ok( null === $r['previous_package'], 'F20 a package not from downloads.wordpress.org/theme/ names no previous version there' );
ok( array( false ) === array_column( $GLOBALS['ab_bulk_args'], 'clear_update_cache' ), 'F20 Theme_Upgrader::bulk_upgrade() got clear_update_cache false too' );
ok( null === AB_MCP_Update_Log::held( 'theme', 'calm' ), 'F20 and the hold is lifted' );

/* ================================== F21 two broken plugins in a row */

echo "F21 two broken plugins one after the other\n";
ab_reset();
$a = ab_install_plugin( 'brokena', '1.0.0' );
$b = ab_install_plugin( 'brokenb', '1.0.0' );
ab_offer( 'plugin', $a, '1.0.0', '2.0.0', ab_package_plugin( 'brokena', '2.0.0', 'AB_FATAL_LOAD' ) );
ab_offer( 'plugin', $b, '1.0.0', '2.0.0', ab_package_plugin( 'brokenb', '2.0.0', 'AB_FATAL_LOAD' ) );
$ra = AB_Test_Update_Guard::update( ab_plugin_job( $a ) );
$rb = AB_Test_Update_Guard::update( ab_plugin_job( $b ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $ra ) && 'ab_mcp_update_rolled_back' === ab_code( $rb ), 'F21 both put back' );
ok( '1.0.0' === ab_installed_version( 'plugin', $a ) && '1.0.0' === ab_installed_version( 'plugin', $b ), 'F21 both at 1.0.0' );

/* ===================================== F22 the upgrader reports an error */

echo "F22 the upgrader stops with an error\n";
ab_reset();
$f22 = ab_install_plugin( 'nodownload', '1.0.0' );
ab_offer( 'plugin', $f22, '1.0.0', '2.0.0', ab_package_plugin( 'nodownload', '2.0.0' ) );
$GLOBALS['ab_bulk_result'] = new WP_Error( 'download_failed', 'Download failed.' );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f22 ) );
ok( 'ab_mcp_update_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'Download failed.' ) && false !== strpos( ab_msg( $r ), 'Version 1.0.0 is on disk now' ), 'F22 WordPress\'s reason and the version on disk: ' . ab_msg( $r ) );
ok( array() === ab_events_like( 'pause:2' ) && ! file_exists( ABSPATH . '.maintenance' ) && false === get_option( 'auto_updater.lock' ), 'F22 no test after it, maintenance mode off, lock free' );
ok( 'failed' === AB_MCP_Update_Log::last_for( 'plugin', $f22 )['result'], 'F22 the log says failed' );

/* ================================ F23-F26 stopped half-way, and races */

echo "F23 a roll-back leaves no delete at shutdown that hits a later update's copy\n";
ab_reset();
$f23 = ab_install_plugin( 'race', '1.0.0' );
ab_offer( 'plugin', $f23, '1.0.0', '2.0.0', ab_package_plugin( 'race', '2.0.0', 'AB_FATAL_LOAD' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f23 ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ), 'F23 rolled back' );
mkdir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/race', 0777, true );
file_put_contents( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/race/race.php', 'copy of a later update' );
ab_run_shutdown();
ok( is_file( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/race/race.php' ), 'F23 the later update\'s copy survives this request\'s shutdown' );

echo "F24 an exception after the files were replaced\n";
ab_reset();
$f24 = ab_install_plugin( 'hooked', '1.0.0' );
$sha24 = ab_sha( $f24 );
ab_offer( 'plugin', $f24, '1.0.0', '2.0.0', ab_package_plugin( 'hooked', '2.0.0' ) );
add_action( 'upgrader_process_complete', static function () { throw new RuntimeException( 'another plugin\'s hook failed' ); } );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f24 ) );
ok( 'ab_mcp_update_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'it is back (version 1.0.0 on disk)' ), 'F24 the untested new version is replaced by the previous one at once: ' . ab_msg( $r ) );
ok( '1.0.0' === ab_installed_version( 'plugin', $f24 ) && $sha24 === ab_sha( $f24 ) && is_plugin_active( $f24 ), 'F24 1.0.0 byte for byte, active' );
$mv24 = ab_at( 'move_dir:', ab_at( 'bulk_upgrade' ) );
ok( $mv24 > 0 && -1 === ab_at( 'maintenance:off', ab_at( 'bulk_upgrade' ) ) || ( $mv24 > 0 && ab_at( 'maintenance:off', ab_at( 'bulk_upgrade' ) ) > $mv24 ), 'F24 put back while the maintenance mode is still on (the hook ran after core would have switched it off)' );
ok( '1.0.0' === AB_MCP_Update_Log::last_for( 'plugin', $f24 )['from'], 'F24 the log names 1.0.0 as the version before' );
ok( ! file_exists( ABSPATH . '.maintenance' ) && false === get_option( 'auto_updater.lock' ), 'F24 maintenance mode off, lock free — also the one bulk_upgrade() wrote itself' );
ok( 'restored' === AB_MCP_Update_Log::last_for( 'plugin', $f24 )['rescued'], 'F24 the log says restored' );
// The same with a package that keeps the version in its header: what is on disk decides, not the version.
ab_reset();
$f24b   = ab_install_plugin( 'hookedsame', '1.0.0' );
$sha24b = ab_sha( $f24b );
ab_offer( 'plugin', $f24b, '1.0.0', '2.0.0', ab_package_plugin( 'hookedsame', '1.0.0', 'new code, old header' ) );
add_action( 'upgrader_process_complete', static function () { throw new RuntimeException( 'another plugin\'s hook failed' ); } );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f24b ) );
ok( 'ab_mcp_update_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'it is back' ) && $sha24b === ab_sha( $f24b ), 'F24 a package with the same version in its header is put back as well, byte for byte: ' . ab_msg( $r ) );
// And a theme whose style.css stays the same while functions.php changes.
ab_reset();
ab_install_theme( 'calm', '1.0.0' );
$fsha = hash_file( 'sha256', get_theme_root() . '/calm/functions.php' );
ab_offer( 'theme', 'calm', '1.0.0', '2.0.0', ab_package_theme( 'calm', '1.0.0', 'new code, same style.css' ) );
add_action( 'upgrader_process_complete', static function () { throw new RuntimeException( 'another plugin\'s hook failed' ); } );
wp_update_themes();
$r = AB_Test_Update_Guard::update(
	array( 'type' => 'theme', 'item' => 'calm', 'slug' => 'calm', 'root' => get_theme_root(), 'dir' => 'themes', 'tool' => 'wp_update_theme', 'active' => true, 'role' => 'active', 'offer' => array( 'version' => '2.0.0', 'package' => 'x', 'wporg' => false, 'slug' => 'calm' ) )
);
ok( 'ab_mcp_update_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'it is back' ) && $fsha === hash_file( 'sha256', get_theme_root() . '/calm/functions.php' ), 'F24 a theme with the same style.css and a new functions.php is put back too: ' . ab_msg( $r ) );

// And a package whose main file stays byte for byte the same while an included file changes (Codex, 03.10.2026).
ab_reset();
$f24c  = ab_install_plugin( 'incl', '1.0.0' );
file_put_contents( WP_PLUGIN_DIR . '/incl/inc.php', "<?php\n// old include\n" );
$inc0  = hash_file( 'sha256', WP_PLUGIN_DIR . '/incl/inc.php' );
$main0 = ab_sha( $f24c );
$pkg   = ab_package_plugin( 'incl', '1.0.0' );
file_put_contents( $pkg . '/inc.php', "<?php\n// AB_FATAL_LOAD\n" );
ab_offer( 'plugin', $f24c, '1.0.0', '2.0.0', $pkg );
add_action( 'upgrader_process_complete', static function () { throw new RuntimeException( 'another plugin\'s hook failed' ); } );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f24c ) );
ok( 'ab_mcp_update_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'it is back' ), 'F24 the same main file and a new include: put back all the same: ' . ab_msg( $r ) );
ok( $inc0 === hash_file( 'sha256', WP_PLUGIN_DIR . '/incl/inc.php' ) && $main0 === ab_sha( $f24c ), 'F24 the include of 1.0.0 is back, byte for byte' );
ok( 'restored' === AB_MCP_Update_Log::last_for( 'plugin', $f24c )['rescued'], 'F24 the log says restored' );
ab_run_shutdown();
ok( $inc0 === hash_file( 'sha256', WP_PLUGIN_DIR . '/incl/inc.php' ) && ! is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/incl' ), 'F24 and stays after shutdown, no copy left at the plain name' );
// A copy an earlier request left under the folder's name is not this update's: an update that fails before it moves anything leaves it and the folder alone.
ab_reset();
$f24d = ab_install_plugin( 'stale', '1.0.0' );
$sha24d = ab_sha( $f24d );
mkdir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/stale', 0777, true );
file_put_contents( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/stale/stale.php', ab_plugin_src( 'stale', '0.9.0' ) );
ab_offer( 'plugin', $f24d, '1.0.0', '2.0.0', ab_package_plugin( 'stale', '2.0.0' ) );
$GLOBALS['ab_bulk_result'] = new WP_Error( 'download_failed', 'Download failed.' );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f24d ) );
ok( 'ab_mcp_update_failed' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'plugin', $f24d ) && $sha24d === ab_sha( $f24d ), 'F24 a download that fails: 1.0.0 stays, the old copy is not put in its place: ' . ab_msg( $r ) );
ok( is_file( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/stale/stale.php' ) && ! isset( AB_MCP_Update_Log::last_for( 'plugin', $f24d )['rescued'] ), 'F24 and the old copy is left where it was' );

echo "F25 the copy fails after the previous version was moved aside\n";
ab_reset();
$f25 = ab_install_plugin( 'halfway', '1.0.0' );
$sha25 = ab_sha( $f25 );
ab_offer( 'plugin', $f25, '1.0.0', '2.0.0', ab_package_plugin( 'halfway', '2.0.0' ) );
$GLOBALS['ab_copy_fail'] = true;
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f25 ) );
ok( 'ab_mcp_update_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'could not be copied' ) && false !== strpos( ab_msg( $r ), 'it is back' ), 'F25 WordPress\'s reason, and the previous version is back: ' . ab_msg( $r ) );
ok( '1.0.0' === ab_installed_version( 'plugin', $f25 ) && $sha25 === ab_sha( $f25 ), 'F25 1.0.0 is back before the request ends' );
$back = ab_at( 'move_dir:', ab_at( 'bulk_upgrade' ) );
ok( $back >= 0 && $back < ab_at( 'maintenance:off', ab_at( 'bulk_upgrade' ) + 3 ) && $back < ab_at( 'lock:release' ), 'F25 put back under the maintenance mode and the lock, not at shutdown' );
ab_run_shutdown();
ok( ! in_array( 'core:restore_temp_backup', $GLOBALS['ab_events'], true ) && '1.0.0' === ab_installed_version( 'plugin', $f25 ), 'F25 core\'s own restore at shutdown is taken off' );

echo "F26 the shutdown of a request that died half-way\n";
ab_reset();
$f26 = ab_install_plugin( 'dies', '1.0.0' );
$sha26 = ab_sha( $f26 );
ab_offer( 'plugin', $f26, '1.0.0', '2.0.0', ab_package_plugin( 'dies', '2.0.0', 'AB_FATAL_LOAD' ) );
$r = AB_Test_Update_Guard::pretend_died( ab_plugin_job( $f26 ) );
ok( '2.0.0' === ab_installed_version( 'plugin', $f26 ) && is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/dies' ) && file_exists( ABSPATH . '.maintenance' ), 'F26 (2.0.0 on disk, the copy of 1.0.0 aside, maintenance mode on)' );
ok( 1 === has_action( 'shutdown', array( 'AB_MCP_Update_Guard', 'on_shutdown' ) ) && 100 === has_action( 'shutdown', array( $GLOBALS['ab_last_upgrader'], 'delete_temp_backup' ) ), 'F26 the guard runs at shutdown priority 1, core\'s delete at 100' );
ab_run_shutdown();
ok( '1.0.0' === ab_installed_version( 'plugin', $f26 ) && $sha26 === ab_sha( $f26 ), 'F26 at shutdown the previous version comes back, before core deletes the copy' );
ok( ! file_exists( ABSPATH . '.maintenance' ) && false === get_option( 'auto_updater.lock' ) && null === AB_MCP_Update_Log::running(), 'F26 maintenance mode off, lock and note gone' );
AB_MCP_Update_Guard::on_shutdown();
ok( '1.0.0' === ab_installed_version( 'plugin', $f26 ), 'F26 and a second shutdown call does nothing' );
// The way back fails at shutdown too (Codex, 03.10.2026): the new files stay, and the folder is
// marked as replaced. In Pro a file undo point recorded before the update was then run through
// the real restore plan, which refuses it (ab_mcp_undo_outdated); file undo points are Pro's
// (its file tools and its restore of them), so here only the mark they are refused by is checked.
foreach ( array( 'copy_kept' => array( 'ab_move_fail', 'plugins/diesstuck' ), 'incomplete' => array( 'ab_move_partial', array( 'plugins/diesstuck' => 2 ) ) ) as $how => $fail ) {
	ab_reset();
	$f26b  = ab_install_plugin( 'diesstuck', '1.0.0' );
	$since = time();
	ab_offer( 'plugin', $f26b, '1.0.0', '2.0.0', ab_package_plugin( 'diesstuck', '2.0.0', 'AB_FATAL_LOAD' ) );
	AB_Test_Update_Guard::pretend_died( ab_plugin_job( $f26b ) );
	$GLOBALS[ $fail[0] ] = $fail[1];
	ab_run_shutdown();
	ok( is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/diesstuck' ) && '1.0.0' !== ab_installed_version( 'plugin', $f26b ), "F26 $how: the way back fails at shutdown, the copy of 1.0.0 is kept" );
	ok( AB_MCP_Update_Log::replaced_at( 'plugin', 'diesstuck' ) >= $since, "F26 $how: and the folder is marked as replaced, from when the update began" );
	ok( ! file_exists( ABSPATH . '.maintenance' ) && false === get_option( 'auto_updater.lock' ) && null === AB_MCP_Update_Log::running(), "F26 $how: maintenance mode off, lock and note gone" );
}

echo "F27 the copy of a kept update cannot be renamed\n";
ab_reset();
$f27 = ab_install_plugin( 'norename', '1.0.0' );
ab_offer( 'plugin', $f27, '1.0.0', '2.0.0', ab_package_plugin( 'norename', '2.0.0' ) );
$GLOBALS['ab_fs_move_fail'] = true;
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f27 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'], 'F27 kept: ' . ab_msg( $r ) );
ok( ! is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/norename' ) && array() === glob( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/norename.ab-old-*' ), 'F27 the copy is deleted before the call answers, not left under the plain name' );
ok( false === has_action( 'shutdown', array( $GLOBALS['ab_last_upgrader'], 'delete_temp_backup' ) ), 'F27 and core\'s delete by the plain name is taken off' );

/* ============================== F28 the parent theme of a child theme */

echo "F28 the parent theme of the active child theme\n";
ab_reset();
ab_install_theme( 'calm', '1.0.0' );
ab_install_theme( 'kind', '1.0.0' );
$GLOBALS['ab_stylesheet'] = 'kind';
$GLOBALS['ab_template']   = 'calm';
$psha = hash_file( 'sha256', get_theme_root() . '/calm/functions.php' );
ab_offer( 'theme', 'calm', '1.0.0', '2.0.0', ab_package_theme( 'calm', '2.0.0', 'AB_FATAL_LOAD' ) );
$r = AB_MCP_Tools_Install_Update::update_theme( array( 'stylesheet' => 'calm' ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'theme', 'calm' ) && $psha === hash_file( 'sha256', get_theme_root() . '/calm/functions.php' ), 'F28 a fatal error in the parent is put back, byte for byte: ' . ab_code( $r ) );
ok( true === ab_data( $r )['active_after'] && false === strpos( ab_msg( $r ), 'still the active theme' ), 'F28 the parent still runs, and the message does not call it the active theme' );
ok( 'fatal' === AB_MCP_Update_Log::held( 'theme', 'calm' )['reason'], 'F28 and 2.0.0 is held back' );
ab_reset();
ab_install_theme( 'calm', '1.0.0' );
ab_offer( 'theme', 'calm', '1.0.0', '2.1.0', ab_package_theme( 'calm', '2.1.0' ) );
$r = AB_MCP_Tools_Install_Update::update_theme( array( 'stylesheet' => 'calm' ) );
ok( ! is_wp_error( $r ) && '2.1.0' === $r['version_after'] && 'parent' === $r['role'], 'F28 a healthy update of the parent is kept, as the parent: ' . ab_msg( $r ) );
ok( true === $r['probe']['tested'] && 'passed' === $r['probe']['after']['result'] && true === $r['active_after'] && false === strpos( $r['note'], 'does not run here' ), 'F28 tested before and after, and no note that its code does not run here' );
$GLOBALS['ab_stylesheet'] = 'calm';
$GLOBALS['ab_template']   = 'calm';


/* ========================== F29-F31 a package that keeps its version */

echo "F29 a package that keeps the version of its header, healthy\n";
ab_reset();
$f29  = ab_install_plugin( 'samever', '1.0.0' );
$sha0 = ab_sha( $f29 );
$pkg  = ab_package_plugin( 'samever', '1.0.0', 'new code, old header' );
ab_offer( 'plugin', $f29, '1.0.0', '2.0.0', $pkg );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f29 ) );
ok( ! is_wp_error( $r ) && true === $r['kept'], 'F29 installed and kept, not "not updated": ' . ab_msg( $r ) );
ok( hash_file( 'sha256', $pkg . '/samever.php' ) === ab_sha( $f29 ) && $sha0 !== ab_sha( $f29 ), 'F29 the new code is on disk' );
ok( true === $r['probe']['tested'] && 'passed' === $r['probe']['after']['result'] && ab_at( 'probe:home', ab_at( 'pause:2' ) ) > 0, 'F29 and was tested after the update' );
ok( '1.0.0' === $r['version_after'] && '2.0.0' === $r['version_offered'] && false !== strpos( $r['warning'], 'offered as version 2.0.0, but the version in its header is 1.0.0' ), 'F29 the answer says what was offered and what the header says: ' . $r['warning'] );
ok( 1 === count( glob( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/samever.ab-old-*' ) ), 'F29 the copy of the previous code is handled as after any kept update' );
$log = AB_MCP_Update_Log::last_for( 'plugin', $f29 );
ok( 'kept' === $log['result'] && '2.0.0' === $log['to'] && '1.0.0' === $log['installed'], 'F29 the log has it as kept, offered 2.0.0, installed 1.0.0' );

echo "F30 a package that keeps the version of its header, with a fatal error\n";
ab_reset();
$f30  = ab_install_plugin( 'samebad', '1.0.0' );
$sha0 = ab_sha( $f30 );
ab_offer( 'plugin', $f30, '1.0.0', '2.0.0', ab_package_plugin( 'samebad', '1.0.0', 'AB_FATAL_LOAD' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f30 ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && $sha0 === ab_sha( $f30 ) && is_plugin_active( $f30 ), 'F30 put back, byte for byte and active: ' . ab_code( $r ) );
$m = ab_msg( $r );
ok( false !== strpos( $m, 'updated from 1.0.0 to the package offered as 2.0.0 (version in its header: 1.0.0)' ) && false === strpos( $m, 'Nothing was changed' ), 'F30 the message says what was installed: ' . $m );
ok( false !== strpos( $m, '2.0.0 will not be installed again automatically' ), 'F30 and names the offered version as held' );
$hold = AB_MCP_Update_Log::held( 'plugin', $f30 );
ok( '2.0.0' === $hold['version'] && '1.0.0' === $hold['file_version'], 'F30 the hold keeps the offered version and the one in the header' );
ok( false === AB_MCP_Update_Log::auto_update_plugin( true, (object) array( 'plugin' => $f30, 'new_version' => '2.0.0' ) ), 'F30 the automatic update of the offer 2.0.0 is refused' );
ok( array() === ab_events_like( 'core:delete_temp_backup' ), 'F30 core does not delete the copy before it was put back' );
// The vendor fixes the package and keeps the header: kept, and the answer names the roll-back before.
ab_run_shutdown();
ab_offer( 'plugin', $f30, '1.0.0', '2.0.0', ab_package_plugin( 'samebad', '1.0.0', 'fixed, old header' ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f30 ) );
ok( ! is_wp_error( $r ) && false !== strpos( $r['warning'], '2.0.0 had been rolled back on' ) && null === AB_MCP_Update_Log::held( 'plugin', $f30 ), 'F30 a fixed package under the same offer is kept, the hold lifted, and the answer names the roll-back: ' . ( is_array( $r ) ? $r['warning'] : ab_msg( $r ) ) );

echo "F31 a package without a version in its header, with a fatal error\n";
ab_reset();
mkdir( WP_PLUGIN_DIR . '/nover', 0777, true );
file_put_contents( WP_PLUGIN_DIR . '/nover/nover.php', "<?php\n/**\n * Plugin Name: Test nover\n */\n" );
update_option( 'active_plugins', array( 'nover/nover.php' ) );
$sha0 = ab_sha( 'nover/nover.php' );
$pkg  = $GLOBALS['ab_root'] . '/packages/nover-x';
mkdir( $pkg, 0777, true );
file_put_contents( $pkg . '/nover.php', "<?php\n/**\n * Plugin Name: Test nover\n */\n// AB_FATAL_LOAD\n" );
ab_offer( 'plugin', 'nover/nover.php', '', '2.0.0', $pkg );
$r = AB_Test_Update_Guard::update( ab_plugin_job( 'nover/nover.php' ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && $sha0 === ab_sha( 'nover/nover.php' ), 'F31 put back too, byte for byte: ' . ab_code( $r ) . ' ' . ab_msg( $r ) );

/* ================================================ F32-F34 the budget */

echo "F32 the host hangs after the update, the control included\n";
ab_reset();
$f32  = ab_install_plugin( 'hostdown', '1.0.0' );
$sha0 = ab_sha( $f32 );
ab_offer( 'plugin', $f32, '1.0.0', '2.0.0', ab_package_plugin( 'hostdown', '2.0.0' ) );
add_action( 'upgrader_process_complete', static function () { $GLOBALS['ab_net'] = 'hang'; } );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f32 ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && $sha0 === ab_sha( $f32 ), 'F32 put back: ' . ab_code( $r ) );
ok( AB_Test_Update_Guard::elapsed() <= AB_MCP_Update_Guard::BUDGET + 0.5, sprintf( 'F32 within the budget of %d s (%.1f s by the guard\'s clock)', AB_MCP_Update_Guard::BUDGET, AB_Test_Update_Guard::elapsed() ) );
$m = ab_msg( $r );
ok( false !== strpos( $m, 'With it back, the test requests say:' ) && false !== strpos( $m, 'this MCP endpoint: not checked, this call ran out of time' ), 'F32 the control says honestly which targets it had no time for: ' . $m );
ok( null === AB_MCP_Update_Log::held( 'plugin', $f32 ) && false !== strpos( $m, 'stays offered' ), 'F32 not held back, as for any target that stopped answering' );
ok( ! file_exists( ABSPATH . '.maintenance' ) && false === get_option( 'auto_updater.lock' ), 'F32 maintenance mode off, lock free' );

echo "F33 the update itself takes the time of the test\n";
ab_reset();
$f33 = ab_install_plugin( 'bigpackage', '1.0.0' );
ab_offer( 'plugin', $f33, '1.0.0', '2.0.0', ab_package_plugin( 'bigpackage', '2.0.0' ) );
add_action( 'upgrader_process_complete', static function () { $GLOBALS['ab_clock'] += 36; } );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f33 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'] && false === $r['probe']['tested'], 'F33 kept without a test: ' . ab_msg( $r ) );
ok( false !== strpos( $r['warning'], 'Not checked: this call ran out of time before the home page' ) && false !== strpos( $r['warning'], 'the update itself took 36 s' ), 'F33 and the answer says so: ' . $r['warning'] );
ok( 3 === count( $GLOBALS['ab_requests'] ), 'F33 no test request after the update' );
ok( AB_Test_Update_Guard::elapsed() <= AB_MCP_Update_Guard::BUDGET, sprintf( 'F33 within the budget (%.1f s)', AB_Test_Update_Guard::elapsed() ) );
ok( 'not_checked' === AB_MCP_Update_Log::last_for( 'plugin', $f33 )['result'], 'F33 the log says not checked' );
ok( ! file_exists( ABSPATH . '.maintenance' ) && false === get_option( 'auto_updater.lock' ), 'F33 maintenance mode off, lock free' );

echo "F35 the update takes long, and the site answers slowly after it\n";
ab_reset();
$f35 = ab_install_plugin( 'slowsite', '1.0.0' );
ab_offer( 'plugin', $f35, '1.0.0', '2.0.0', ab_package_plugin( 'slowsite', '2.0.0' ) );
add_action( 'upgrader_process_complete', static function () { $GLOBALS['ab_clock'] += 31; $GLOBALS['ab_net'] = 'slow:6'; } );
$r  = AB_Test_Update_Guard::update( ab_plugin_job( $f35 ) );
$rq = ab_requests_since( 3 );
ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'] && false === $r['probe']['tested'], 'F35 kept, not tested: ' . ab_msg( $r ) );
ok( 1 === count( $rq ) && $rq[0]['timeout'] < AB_MCP_Php_Probe::TIMEOUT, 'F35 the home page got only the seconds left before the reserve of a roll-back (' . ( isset( $rq[0] ) ? round( $rq[0]['timeout'], 1 ) : '?' ) . ' s)' );
ok( 3 === count( array_filter( $r['probe']['after']['targets'], static function ( $row ) { return ! empty( $row['out_of_time'] ); } ) ), 'F35 a request cut short that times out is not checked, not failed' );
ok( false !== strpos( $r['warning'], 'ran out of time before the home page' ) && AB_Test_Update_Guard::elapsed() <= AB_MCP_Update_Guard::BUDGET, sprintf( 'F35 the answer says so, within the budget (%.1f s)', AB_Test_Update_Guard::elapsed() ) );

echo "F36 the home page redirects twice, slowly, after the update (Codex, 03.10.2026)\n";
ab_reset();
$f36 = ab_install_plugin( 'slowhops', '1.0.0' );
ab_offer( 'plugin', $f36, '1.0.0', '2.0.0', ab_package_plugin( 'slowhops', '2.0.0' ) );
add_action( 'upgrader_process_complete', static function () { $GLOBALS['ab_clock'] += 22; $GLOBALS['ab_net'] = 'slow:9'; $GLOBALS['ab_home_redirects'] = 2; } );
$r    = AB_Test_Update_Guard::update( ab_plugin_job( $f36 ) );
$rq   = ab_requests_since( 3 );
$home = array_values( array_filter( $rq, static function ( $q ) { return 'home' === $q['target']; } ) );
ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'] && AB_Test_Update_Guard::elapsed() <= AB_MCP_Update_Guard::BUDGET, sprintf( 'F36 within the budget, the redirects included (%.1f s by the guard\'s clock): %s', AB_Test_Update_Guard::elapsed(), ab_msg( $r ) ) );
ok( 2 === count( $home ) && AB_MCP_Php_Probe::TIMEOUT === (int) $home[0]['timeout'] && $home[1]['timeout'] < 9, 'F36 the redirect got only the seconds left before the reserve of a roll-back (' . ( isset( $home[1] ) ? round( $home[1]['timeout'], 1 ) : '?' ) . ' s), not a test request\'s time of its own' );
$rows = $r['probe']['after']['targets'];
ok( 'not_probed' === $rows[0]['result'] && ! empty( $rows[0]['out_of_time'] ) && array( 'http://site.test/hop1/' ) === $rows[0]['redirects'], 'F36 the home page cut short is not checked, not failed, and names the redirect it followed' );
ok( 3 === count( array_filter( $rows, static function ( $row ) { return ! empty( $row['out_of_time'] ); } ) ) && false !== strpos( $r['warning'], 'ran out of time' ), 'F36 the targets it had no time for are not checked, and the answer says so: ' . $r['warning'] );
// With the time, the same chain is followed to its end within the round.
ab_reset();
$f36b = ab_install_plugin( 'fasthops', '1.0.0' );
ab_offer( 'plugin', $f36b, '1.0.0', '2.0.0', ab_package_plugin( 'fasthops', '2.0.0' ) );
add_action( 'upgrader_process_complete', static function () { $GLOBALS['ab_net'] = 'slow:2'; $GLOBALS['ab_home_redirects'] = 2; } );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f36b ) );
ok( ! is_wp_error( $r ) && 'passed' === $r['probe']['after']['result'] && array( 'http://site.test/hop1/', 'http://site.test/hop2/' ) === $r['probe']['after']['targets'][0]['redirects'], 'F36 with time left, both redirects are followed and every target passes: ' . ab_msg( $r ) );

echo "F34 wordpress.org does not answer when the offers are loaded again\n";
ab_reset();
$f34 = ab_install_plugin( 'offline', '1.0.0' );
ab_offer( 'plugin', $f34, '1.0.0', '2.0.0', ab_package_plugin( 'offline', '2.0.0' ) );
$job = ab_plugin_job( $f34 );
$GLOBALS['ab_offers_fail'] = true;
$r = AB_Test_Update_Guard::update( $job );
ok( ! is_wp_error( $r ) && false === $r['offers_refreshed'] && false !== strpos( $r['warning'], 'could not be loaded again' ), 'F34 the answer says the list was not loaded again: ' . ( is_array( $r ) ? $r['warning'] : ab_msg( $r ) ) );
ok( isset( get_site_transient( 'update_plugins' )->last_checked ), 'F34 (although WordPress wrote a fresh time of the check)' );

/* ============================================ T the tool's own checks */

echo "F37 an activation or a deploy into active code runs (Codex, 06.10.2026)\n";
ab_reset();
$f37 = ab_install_plugin( 'good', '1.0.0' );
ab_offer( 'plugin', $f37, '1.0.0', '2.0.0', ab_package_plugin( 'good', '2.0.0' ) );
$t = time() - 4;
update_option( AB_MCP_Change_Lock::OPTION, $t );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f37 ) );
ok( 'ab_mcp_change_busy' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'another change of the code this site runs through AlphaBridge (an activation, a deploy, or a PHP file or snippet written or undone) has been running for 4 s' ), 'F37 refused while another change runs: ' . ab_msg( $r ) );
ok( '1.0.0' === ab_installed_version( 'plugin', $f37 ) && array() === ab_events_like( 'bulk_upgrade' ) && array() === ab_events_like( 'probe:' ), 'F37 nothing updated, no test request' );
ok( false === get_option( 'auto_updater.lock' ) && null === AB_MCP_Update_Log::running(), 'F37 the update lock and our note are freed again' );
ok( $t === (int) get_option( AB_MCP_Change_Lock::OPTION ) && ! AB_MCP_Change_Lock::held(), 'F37 the other request\'s lock stays as it was' );
delete_option( AB_MCP_Change_Lock::OPTION );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f37 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === ab_installed_version( 'plugin', $f37 ), 'F37 counter-check: once it is free, the update runs: ' . ab_msg( $r ) );
ok( false === get_option( AB_MCP_Change_Lock::OPTION ) && false === get_option( 'auto_updater.lock' ) && ! AB_MCP_Change_Lock::held(), 'F37 and frees both locks' );

echo "F38 activated between the call and the lock (Codex, 06.10.2026)\n";
ab_reset();
$f38 = ab_install_plugin( 'late', '1.0.0', '', false );
ab_offer( 'plugin', $f38, '1.0.0', '2.0.0', ab_package_plugin( 'late', '2.0.0', 'AB_FATAL_LOAD' ) );
$job = ab_plugin_job( $f38 );
// Another request activates it before this one takes the lock.
update_option( 'active_plugins', array( $f38 ) );
$r = AB_Test_Update_Guard::update( $job );
ok( false === $job['active'] && 'ab_mcp_update_rolled_back' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'plugin', $f38 ), 'F38 read again under the lock: tested, and the fatal 2.0.0 put back: ' . ab_msg( $r ) );
ok( array() !== ab_events_like( 'probe:' ), 'F38 test requests ran' );
ab_reset();
$f38 = ab_install_plugin( 'early', '1.0.0' );
ab_offer( 'plugin', $f38, '1.0.0', '2.0.0', ab_package_plugin( 'early', '2.0.0' ) );
$job = ab_plugin_job( $f38 );
update_option( 'active_plugins', array() );
$r = AB_Test_Update_Guard::update( $job );
ok( true === $job['active'] && ! is_wp_error( $r ) && '2.0.0' === ab_installed_version( 'plugin', $f38 ) && array() === ab_events_like( 'probe:' ), 'F38 counter-check: deactivated in between, it is updated without a test, as an inactive plugin is' );

echo "F39 an update that runs long keeps its locks fresh (Codex, 06.10.2026)\n";
ab_reset();
$f39  = ab_install_plugin( 'slow', '1.0.0' );
ab_offer( 'plugin', $f39, '1.0.0', '2.0.0', ab_package_plugin( 'slow', '2.0.0' ) );
$seen = array();
$GLOBALS['ab_in_upgrade'] = static function ( $stage ) use ( &$seen ) {
	if ( 'downloading_package' === $stage ) {
		// Nine minutes into a slow download: both locks are nearly stale.
		$old = time() - 540;
		update_option( AB_MCP_Change_Lock::OPTION, $old );
		$note            = AB_MCP_Update_Log::running();
		$note['started'] = $old;
		AB_MCP_Update_Log::set_running( $note );
		$t = new ReflectionProperty( 'AB_MCP_Change_Lock', 'touched' );
		if ( PHP_VERSION_ID < 80100 ) { $t->setAccessible( true ); }
		$t->setValue( null, $old );
		$l = new ReflectionProperty( 'AB_MCP_Update_Guard', 'live' );
		if ( PHP_VERSION_ID < 80100 ) { $l->setAccessible( true ); }
		$live         = $l->getValue();
		$live['beat'] = $old;
		$l->setValue( null, $live );
	}
	if ( 'unpack_package' === $stage ) {
		$seen['lock'] = (int) get_option( AB_MCP_Change_Lock::OPTION );
		$seen['note'] = AB_MCP_Update_Log::running();
	}
};
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f39 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === ab_installed_version( 'plugin', $f39 ), 'F39 the update itself runs as before: ' . ab_msg( $r ) );
ok( isset( $seen['lock'] ) && $seen['lock'] >= time() - 5, 'F39 the next step the upgrader reports writes the lock for changes anew' );
ok( isset( $seen['note'] ) && is_array( $seen['note'] ) && AB_MCP_Update_Guard::last_sign( $seen['note'] ) >= time() - 5 && (int) $seen['note']['started'] <= time() - 540, 'F39 and our note gets a beat, its start stays (the answer names how long it runs)' );
// The beat counts as a sign of life for the others too.
ab_reset();
$f39 = ab_install_plugin( 'other', '1.0.0' );
ab_offer( 'plugin', $f39, '1.0.0', '2.0.0', ab_package_plugin( 'other', '2.0.0' ) );
update_option( 'auto_updater.lock', time() - 700 );
AB_MCP_Update_Log::set_running( array( 'type' => 'plugin', 'item' => 'big/big.php', 'started' => time() - 700, 'nonce' => 'long', 'beat' => time() - 10 ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f39 ) );
ok( 'ab_mcp_update_busy' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'plugin', $f39 ), 'F39 an update whose note beat ten seconds ago is not taken over, though it started 700 s ago: ' . ab_msg( $r ) );
$c = AB_MCP_Change_Lock::take();
ok( is_wp_error( $c ) && false !== strpos( $c->get_error_message(), 'an update of big/big.php through AlphaBridge is running' ), 'F39 an activation or a deploy is refused as well' );
AB_MCP_Update_Log::set_running( array( 'type' => 'plugin', 'item' => 'big/big.php', 'started' => time() - 700, 'nonce' => 'long', 'beat' => time() - 700 ) );
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f39 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === ab_installed_version( 'plugin', $f39 ), 'F39 counter-check: without a beat for 700 s it is taken over, as before: ' . ab_msg( $r ) );

echo "F40 one step of the upgrader that runs long (Codex, 06.10.2026, round 4)\n";
ab_reset();
$f40  = ab_install_plugin( 'copy', '1.0.0' );
ab_offer( 'plugin', $f40, '1.0.0', '2.0.0', ab_package_plugin( 'copy', '2.0.0' ) );
$seen = array();
$GLOBALS['ab_in_upgrade'] = static function ( $stage ) use ( &$seen ) {
	if ( 'installing_package' !== $stage ) {
		return;
	}
	// Eleven minutes into one copy that reports nothing: no beat since.
	$note            = AB_MCP_Update_Log::running();
	$note['started'] = time() - 700;
	$note['beat']    = time() - 700;
	AB_MCP_Update_Log::set_running( $note );
	$seen['note'] = AB_MCP_Update_Log::running();
	$m            = new ReflectionMethod( 'AB_MCP_Change_Lock', 'update_running' );
	if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); }
	$seen['busy'] = $m->invoke( null );
};
$r = AB_Test_Update_Guard::update( ab_plugin_job( $f40 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === ab_installed_version( 'plugin', $f40 ), 'F40 the update runs as before: ' . ab_msg( $r ) );
ok( isset( $seen['note']['until'] ) && $seen['note']['until'] >= time() + AB_MCP_Update_Guard::LEASE - 60, 'F40 while the upgrader runs, our note holds a lease' );
ok( 'copy/copy.php' === ( $seen['busy'] ?? null ), 'F40 so an activation or a deploy is still refused after eleven minutes without a beat' );
ok( ( time() - AB_MCP_Update_Guard::last_sign( $seen['note'] ) ) < AB_MCP_Update_Guard::STALE, 'F40 and another update does not take core\'s lock over' );
ok( null === AB_MCP_Update_Log::running() && false === get_option( 'auto_updater.lock' ), 'F40 the note goes at the end, with the lock' );

echo "F41 F42 a throw before the update starts (Codex, 06.10.2026, round 4)\n";
class AB_Test_Role_Throws extends AB_Test_Update_Guard {
	protected static function role_now( array $job ) { throw new RuntimeException( 'a filter threw' ); }
}
class AB_Test_Init_Throws extends AB_Test_Update_Guard {
	protected static function hook_shutdown() { throw new RuntimeException( 'init threw' ); }
}
ab_reset();
$f41 = ab_install_plugin( 'shy', '1.0.0', '', false );
ab_offer( 'plugin', $f41, '1.0.0', '2.0.0', ab_package_plugin( 'shy', '2.0.0' ) );
$r = AB_Test_Role_Throws::update( ab_plugin_job( $f41 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === ab_installed_version( 'plugin', $f41 ) && array() !== ab_events_like( 'probe:' ), 'F41 whether it runs cannot be read: it counts as running and is tested: ' . ab_msg( $r ) );
ab_reset();
$f42 = ab_install_plugin( 'init', '1.0.0' );
ab_offer( 'plugin', $f42, '1.0.0', '2.0.0', ab_package_plugin( 'init', '2.0.0' ) );
$threw = '';
try {
	AB_Test_Init_Throws::update( ab_plugin_job( $f42 ) );
} catch ( RuntimeException $e ) {
	$threw = $e->getMessage();
}
ok( 'init threw' === $threw && '1.0.0' === ab_installed_version( 'plugin', $f42 ), 'F42 a throw after the locks were taken, before the update: it goes up, nothing updated' );
ok( false === get_option( 'auto_updater.lock' ) && null === AB_MCP_Update_Log::running() && false === get_option( AB_MCP_Change_Lock::OPTION ) && ! AB_MCP_Change_Lock::held(), 'F42 and both locks and our note are free again' );

echo "T the tool\n";
ab_reset();
foreach ( array( '', '../x/x.php', 'nope/nope.php', 'good' ) as $bad ) {
	ok( 'ab_mcp_bad_plugin' === ab_code( AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => $bad ) ) ), "T1 refuses '$bad'" );
}
ok( 'ab_mcp_update_own' === ab_code( AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => 'alphabridge-mcp/alphabridge-mcp.php' ) ) ), 'T2 AlphaBridge itself is refused' );
file_put_contents( WP_PLUGIN_DIR . '/solo.php', "<?php\n/**\n * Plugin Name: Solo\n * Version: 1.0\n */\n" );
ok( 'ab_mcp_update_single_file' === ab_code( AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => 'solo.php' ) ) ), 'T3 a single-file plugin is refused' );
$t4 = ab_install_plugin( 'current', '3.0.0' );
$r  = AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => $t4 ) );
ok( 'ab_mcp_update_none' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'version 3.0.0 is installed' ) && false === strpos( ab_msg( $r ), 'Last update' ), 'T4 no offer: the installed version' );
AB_MCP_Update_Log::add( array( 'time' => 1790000000, 'user' => 1, 'tool' => 'wp_update_plugin', 'type' => 'plugin', 'item' => $t4, 'from' => '2.0.0', 'to' => '3.0.0', 'result' => 'kept' ) );
$r = AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => $t4 ) );
ok( false !== strpos( ab_msg( $r ), 'Last update through AlphaBridge: ' . gmdate( 'Y-m-d H:i', 1790000000 ) . ' UTC, kept (2.0.0 to 3.0.0)' ), 'T4 and the last update from the log: ' . ab_msg( $r ) );
$GLOBALS['ab_fs_method'] = 'ftpext';
$r = AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => $t4 ) );
ok( 'ab_mcp_update_fs' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'FS_METHOD' ) && false !== strpos( ab_msg( $r ), 'ftpext' ), 'T5 no direct writes: the reason, not "no update"' );
$GLOBALS['ab_fs_method'] = 'direct';
$t6 = ab_install_plugin( 'nolicence', '1.0.0' );
ab_offer( 'plugin', $t6, '1.0.0', '2.0.0', '' );
ok( 'ab_mcp_update_no_package' === ab_code( AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => $t6 ) ) ), 'T6 an offer without a package' );
// false from bulk_upgrade (no file system access) is the file system, not "no update".
$t7 = ab_install_plugin( 'ftp', '1.0.0' );
ab_offer( 'plugin', $t7, '1.0.0', '2.0.0', ab_package_plugin( 'ftp', '2.0.0' ) );
$job = ab_plugin_job( $t7 );
$GLOBALS['ab_fs_method'] = 'ftpext';
$r = AB_Test_Update_Guard::update( $job );
ok( 'ab_mcp_update_fs' === ab_code( $r ), 'T7 false from bulk_upgrade is the file system reason: ' . ab_code( $r ) );
$GLOBALS['ab_fs_method'] = 'direct';
ok( 'ab_mcp_bad_theme' === ab_code( AB_MCP_Tools_Install_Update::update_theme( array( 'stylesheet' => 'nope' ) ) ), 'T8 a theme that is not installed is refused' );
// Once through the tool itself, with the real wait.
ab_reset();
$t9 = ab_install_plugin( 'viatool', '1.0.0' );
ab_offer( 'plugin', $t9, '1.0.0', '2.0.0', ab_package_plugin( 'viatool', '2.0.0' ), array( 'id' => 'w.org/plugins/viatool' ) );
$r = AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => $t9 ) );
ok( ! is_wp_error( $r ) && '2.0.0' === $r['version_after'] && $t9 === $r['plugin'], 'T9 through the tool: kept: ' . ab_msg( $r ) );
ok( 'https://downloads.wordpress.org/plugin/viatool.1.0.0.zip' === $r['previous_package'], 'T9 with the previous package of wordpress.org' );
ok( true === $r['probe']['tested'] && 'passed' === $r['probe']['after']['result'] && true === $r['active_before'], 'T9 the tool hands the guard an active plugin as active: tested before and after' );
$log = AB_MCP_Update_Log::last_for( 'plugin', $t9 );
ok( 'kept' === $log['result'] && '1.0.0' === $log['from'] && '2.0.0' === $log['to'], 'T9 the log names the offered version' );
// And once a fatal error, through the tool, from a vendor's updater (no id of wordpress.org).
ab_reset();
$t10   = ab_install_plugin( 'viatoolbad', '1.0.0' );
$sha10 = ab_sha( $t10 );
ab_offer( 'plugin', $t10, '1.0.0', '2.0.0', ab_package_plugin( 'viatoolbad', '2.0.0', 'AB_FATAL_LOAD' ) );
$r = AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => $t10 ) );
ok( 'ab_mcp_update_rolled_back' === ab_code( $r ) && '1.0.0' === ab_installed_version( 'plugin', $t10 ) && $sha10 === ab_sha( $t10 ) && is_plugin_active( $t10 ), 'T10 through the tool: a fatal error is put back, byte for byte and active: ' . ab_code( $r ) );
$log = AB_MCP_Update_Log::last_for( 'plugin', $t10 );
ok( 'rolled_back' === $log['result'] && '2.0.0' === $log['to'] && array_key_exists( 'previous_package', $log ) && null === $log['previous_package'], 'T10 a vendor\'s offer names no previous version at wordpress.org' );
ok( '2.0.0' === AB_MCP_Update_Log::held( 'plugin', $t10 )['version'], 'T10 and 2.0.0 is held back' );

ab_finish( 'UPDATE-FLOW' );
