<?php
/**
 * The protected store of the takeover (AB_MCP_Protected_Store, Z8 decisions
 * 7, 8 and 20), as far as a test without a database and a web server shows
 * it:
 *
 * - holds() decides "under" by device and inode, through links and other
 *   spellings of a path;
 * - judge() proves the uploads folder only with the control file served and
 *   all three test files answered 403 or 404 without their content;
 * - needs_check() makes the proof again after RECHECK, when a guard file is
 *   missing, and a failed one after RETRY;
 * - kept() keeps a group of a run that is not closed (and of every run
 *   while the runs cannot be read), the others by the time of their area,
 *   the backups by ab_mcp_staging_backup_days; an entry whose area, time
 *   made or run is not as the table SIDECAR says is kept (Behebung 2 of
 *   Z8b branch 3, A1);
 * - owns() takes a folder only with this installation's manifest, every
 *   field of it;
 * - without a store nothing is written, and every message names the way;
 * - the head of the instruction of a mark (guide_head()) names for each
 *   form of a mark only the cases its commands check, and the instruction
 *   says of a command only that it contains checks (Teil D3b-1, point 0).
 *
 * The places on real paths, the self-check over HTTP, two installations
 * side by side, the budgets under the lock with two requests at once and
 * the uninstall are tests/integration/ProtectedStoreTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Protected_Store;
use AB_MCP_Store_Budget;
use AB_MCP_Sync_Schema;
use AbTestSyncWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;
use WP_Error;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-protected-store.php';

final class ProtectedStoreTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['wpdb'] = new AbTestSyncWpdb();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		$this->dir = realpath( sys_get_temp_dir() ) . '/ab-mcp-store-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700 );
	}

	protected function tearDown(): void {
		self::remove( $this->dir );
		unset( $GLOBALS['wpdb'] );
		AB_MCP_Protected_Store::forget();
		remove_all_filters_for( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER );
	}

	private static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove( $path . '/' . $entry );
		}
		rmdir( $path );
	}

	/* ------------------------------------------------------------- holds */

	public function testHoldsDecidesByIdentityNotByText(): void {
		$root = $this->dir . '/docroot';
		mkdir( $root . '/wp/wp-content', 0700, true );
		mkdir( $this->dir . '/beside', 0700 );
		symlink( $root . '/wp/wp-content', $this->dir . '/alias' );
		symlink( $this->dir . '/beside', $root . '/out' );

		self::assertTrue( AB_MCP_Protected_Store::holds( $root, $root ), 'The folder itself.' );
		self::assertTrue( AB_MCP_Protected_Store::holds( $root, $root . '/wp/wp-content' ) );
		self::assertTrue( AB_MCP_Protected_Store::holds( $root, $root . '/wp/../wp/wp-content' ), 'Another spelling.' );
		self::assertTrue( AB_MCP_Protected_Store::holds( $root, $this->dir . '/alias' ), 'A link from outside into it.' );
		self::assertTrue( AB_MCP_Protected_Store::holds( $root . '/', $this->dir . '/alias/' ), 'Slashes do not matter.' );
		self::assertFalse( AB_MCP_Protected_Store::holds( $root, $this->dir ), 'Its parent.' );
		self::assertFalse( AB_MCP_Protected_Store::holds( $root, $this->dir . '/beside' ), 'A sibling.' );
		self::assertFalse( AB_MCP_Protected_Store::holds( $root, $root . '/out' ), 'A link in it that points outside: by what it resolves to.' );
		self::assertFalse( AB_MCP_Protected_Store::holds( $root, $this->dir . '/docroot-other' ), 'A path that only begins with its text.' );
		self::assertFalse( AB_MCP_Protected_Store::holds( $root, $root . '/missing' ), 'A path that is not there cannot be resolved.' );
		self::assertFalse( AB_MCP_Protected_Store::holds( $this->dir . '/missing', $root ) );
	}

	/* ------------------------------------------------------- self-check */

	/** @return array<string,array{0:mixed,1:array,2:bool,3:string|null}> */
	public static function answers(): array {
		$ok      = ab_test_http_response( 200, 'TOKEN' );
		$f403    = ab_test_http_response( 403, 'Forbidden' );
		$f404    = ab_test_http_response( 404, 'Not Found' );
		$refused = new WP_Error( 'ab_mcp_outbound_local', 'The address was not asked: it is local or not public.' );
		return array(
			'all refused'                     => array( $ok, array( $f403, $f404, $f403 ), true, null ),
			'all not found'                   => array( $ok, array( $f404, $f404, $f404 ), true, null ),
			'one served'                      => array( $ok, array( $f403, ab_test_http_response( 200, 'TOKEN' ), $f403 ), false, 'served' ),
			'served without the content'      => array( $ok, array( $f403, ab_test_http_response( 200, '' ), $f403 ), false, 'served' ),
			'a 404 that carries the content'  => array( $ok, array( $f403, ab_test_http_response( 404, 'x TOKEN x' ), $f403 ), false, 'served' ),
			'a login asked for'               => array( $ok, array( $f403, ab_test_http_response( 401, '' ), $f403 ), false, 'served' ),
			'a redirect'                      => array( $ok, array( ab_test_http_response( 302, '' ), $f403, $f403 ), false, 'served' ),
			'a server error'                  => array( $ok, array( $f403, $f403, ab_test_http_response( 500, '' ) ), false, 'served' ),
			'only two answers'                => array( $ok, array( $f403, $f403 ), false, 'served' ),
			'a test file not asked'           => array( $ok, array( $f403, $refused, $f403 ), false, 'self_unreachable' ),
			'the control file not served'     => array( $f404, array( $f404, $f404, $f404 ), false, 'control' ),
			'the control file without itself' => array( ab_test_http_response( 200, 'other' ), array( $f404, $f404, $f404 ), false, 'control' ),
			'the site cannot ask itself'      => array( $refused, array(), false, 'self_unreachable' ),
		);
	}

	#[DataProvider( 'answers' )]
	public function testTheSelfCheckProvesOnlyWhatTheAnswersShow( $control, array $probes, bool $ok, ?string $reason ): void {
		$verdict = AB_MCP_Protected_Store::judge( $control, $probes, 'TOKEN' );
		self::assertSame( $ok, $verdict['ok'] );
		self::assertSame( $reason, $verdict['reason'] );
	}

	public function testTheProofIsMadeAgainAfterADayAndWhenAGuardIsMissing(): void {
		$now = 1800000000;
		$ok  = array(
			'where'   => 'uploads',
			'checked' => $now - AB_MCP_Protected_Store::RECHECK + 1,
			'ok'      => true,
			'reason'  => null,
			'detail'  => array(),
		);
		self::assertFalse( AB_MCP_Protected_Store::needs_check( $ok, 'uploads', $now, true ), 'A proof younger than a day holds.' );
		self::assertTrue( AB_MCP_Protected_Store::needs_check( $ok, 'uploads', $now + 1, true ), 'A day old: made again.' );
		self::assertTrue( AB_MCP_Protected_Store::needs_check( $ok, 'uploads', $now, false ), 'A guard file missing: made again.' );
		self::assertTrue( AB_MCP_Protected_Store::needs_check( $ok, 'outside', $now, true ), 'A proof of the other place counts for nothing.' );
		self::assertTrue( AB_MCP_Protected_Store::needs_check( null, 'uploads', $now, true ), 'None yet.' );

		$failed = array_merge( $ok, array( 'ok' => false, 'reason' => 'served', 'checked' => $now - AB_MCP_Protected_Store::RETRY + 1 ) );
		self::assertFalse( AB_MCP_Protected_Store::needs_check( $failed, 'uploads', $now, true ), 'A failed check waits RETRY seconds.' );
		self::assertTrue( AB_MCP_Protected_Store::needs_check( $failed, 'uploads', $now + 1, true ) );
		self::assertTrue( AB_MCP_Protected_Store::needs_check( $failed, 'uploads', $now, false ), 'Not when a guard file is missing.' );
	}

	/* ---------------------------------------------------------- clean-up */

	/**
	 * The inputs of kept() as the clean-up gives them (RETENTION_INPUTS):
	 * the entry of the group, the time, the runs that are not closed, what
	 * the retention says of each run's backups, the seconds of each area.
	 */
	private static function inputs( array $entry, $now, $open, $backups, $keep = null ): array {
		if ( null === $keep ) {
			$keep = array();
			foreach ( array_keys( AB_MCP_Protected_Store::SIDECAR ) as $area ) {
				$keep[ $area ] = AB_MCP_Protected_Store::keep( $area );
			}
		}
		return array(
			'entry'   => $entry,
			'now'     => $now,
			'open'    => $open,
			'backups' => $backups,
			'keep'    => $keep,
		);
	}

	public function testTheCleanUpKeepsWhatAnOpenRunHoldsAndTheRestByItsTime(): void {
		$now  = 1800000000;
		$day  = 86400;
		$old  = static fn( string $area, int $age, int $run = 0 ): array => array(
			'area'    => $area,
			'created' => $now - $age,
			'run_id'  => $run,
		);
		$kept = static fn( array $entry, $open, $backups = array(), $at = null, $keep = null ) => AB_MCP_Protected_Store::kept( self::inputs( $entry, $at ?? $now, $open, $backups, $keep ) );
		foreach ( array( 'out', 'in', 'reports' ) as $area ) {
			self::assertTrue( $kept( $old( $area, $day - 1 ), array() ), "$area: younger than a day." );
			self::assertFalse( $kept( $old( $area, $day ), array() ), "$area: a day old." );
			self::assertTrue( $kept( $old( $area, 30 * $day, 7 ), array( 3, 7 ) ), "$area: a run that is not closed holds it." );
			self::assertFalse( $kept( $old( $area, 30 * $day, 7 ), array( 3 ) ), "$area: a closed run does not." );
			self::assertNull( $kept( $old( $area, 30 * $day, 7 ), null ), "$area: while the runs cannot be read, a run's group stays, unknown." );
			// A group of no run needs no runs (Behebung 3, B4): it goes by its
			// time also while the runs cannot be read.
			self::assertFalse( $kept( $old( $area, 30 * $day ), null, null ), "$area: one of no run goes by its time." );
		}
		// The backups of a run with a row follow the retention contract as the
		// row holds it (decision 20; branch 8; AB_MCP_Sync_Schema::backup_rows():
		// true kept, false gone, whatever their age), and stay while the runs
		// cannot be read. The backups of a run without a row (never made, or
		// its row gone with a database put back) are kept by their age, as a
		// close that does not qualify: never removed at once.
		self::assertSame( 0, AB_MCP_Protected_Store::backup_days(), 'Free keeps no qualified close by its age, the last three only.' );
		self::assertTrue( $kept( $old( 'backups', 400 * $day, 7 ), array(), array( 7 => true ) ) );
		self::assertFalse( $kept( $old( 'backups', 1, 7 ), array(), array( 3 => true, 7 => false ) ), 'A run whose row says its backups go: they go, whatever their age.' );
		self::assertTrue( $kept( $old( 'backups', 7 * $day - 1, 7 ), array(), array( 3 => true ) ), 'A run without a row: kept by its age, not removed at once.' );
		self::assertFalse( $kept( $old( 'backups', 7 * $day, 7 ), array(), array( 3 => true ) ), 'A run without a row: gone after the days of a close that does not qualify.' );
		self::assertNull( $kept( $old( 'backups', 400 * $day, 7 ), array(), null ), 'While the retention of the runs cannot be read, every run\'s backups stay, unknown.' );
		self::assertFalse( $kept( $old( 'stage', 400 * $day, 7 ), array(), null ), 'A group outside backups needs no retention of backups (Behebung 3, B4).' );
		// Behebung 2 of Z8b branch 3, A1: backups and stage know groups of a
		// run only (AB_MCP_Protected_Store::SIDECAR), so an entry of no run
		// there is unknown, and kept whatever its age; out, in and reports
		// know groups of no run (above).
		foreach ( array( 'backups', 'stage' ) as $area ) {
			self::assertNull( $kept( $old( $area, 400 * $day ), array(), array() ), "$area of no run: unknown, kept." );
		}
		// What kept() decides by, missing, null, of another type or outside
		// its range: kept as unknown, never made up.
		foreach ( array( 'out', 'in', 'reports', 'backups', 'stage' ) as $area ) {
			$base = $old( $area, 400 * $day, 7 );
			self::assertFalse( $kept( $base, array(), array( 7 => false ) ), "$area: whole and past its time, it goes." );
			foreach ( array( 'run_id', 'created', 'area' ) as $field ) {
				$without = $base;
				unset( $without[ $field ] );
				self::assertNull( $kept( $without, array(), array( 7 => false ) ), "$area without $field." );
				self::assertNull( $kept( array( $field => null ) + $base, array(), array( 7 => false ) ), "$area, $field null." );
				self::assertNull( $kept( array( $field => (string) $base[ $field ] . 'x' ) + $base, array(), array( 7 => false ) ), "$area, $field of another type." );
			}
			self::assertNull( $kept( array( 'run_id' => -1 ) + $base, array(), array( 7 => false ) ), "$area, run -1." );
			self::assertNull( $kept( array( 'run_id' => '7' ) + $base, array(), array( 7 => false ) ), "$area, run as a string." );
			self::assertNull( $kept( array( 'created' => 0 ) + $base, array(), array( 7 => false ) ), "$area, made at 0." );
		}

		add_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, static fn(): int => 30 );
		self::assertSame( 30, AB_MCP_Protected_Store::backup_days(), 'Pro sets 30 through the filter.' );
		self::assertTrue( $kept( $old( 'backups', 30 * $day - 1, 7 ), array(), array( 3 => true ) ), 'Pro: a run without a row is kept the days of the filter.' );
		self::assertFalse( $kept( $old( 'backups', 30 * $day, 7 ), array(), array( 3 => true ) ), 'Pro: and goes after them.' );
		self::assertFalse( $kept( $old( 'backups', 1, 7 ), array(), array( 7 => false ) ), 'Pro: a row that says its backups go is not kept by the days of the filter.' );
		// Days of the filter that are no whole number of 0 to 3650 are not
		// known (Behebung 3, A2): no period is made of them.
		foreach ( array( -5, 3651, '30 days', 30.5, null, '030', true ) as $days ) {
			remove_all_filters_for( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER );
			add_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, static fn() => $days );
			$shown = var_export( $days, true );
			self::assertNull( AB_MCP_Protected_Store::backup_days(), "days $shown: not known." );
			self::assertNull( AB_MCP_Protected_Store::keep( 'backups' ), "days $shown: backups keep no period made up." );
			self::assertNull( $kept( $old( 'backups', 400 * $day, 7 ), array(), array( 3 => true ) ), "days $shown: a backup of a run without a row stays, unknown." );
			self::assertFalse( $kept( $old( 'reports', 400 * $day ), array() ), "days $shown: a report goes by its own time." );
		}
		remove_all_filters_for( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER );
		add_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, static fn(): string => '30' );
		self::assertSame( 30, AB_MCP_Protected_Store::backup_days(), 'The digits of a whole number are the number.' );
		remove_all_filters_for( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER );
		// keep() names the age of an area of groups only: the marks keep a
		// closed mark a day by KEEP['marks'] in sweep(), and are no area.
		foreach ( array( 'marks', 'tmp', 'elsewhere', 5, null ) as $area ) {
			self::assertNull( AB_MCP_Protected_Store::keep( $area ), var_export( $area, true ) . ': no area of groups, no age.' );
		}
	}

	public function testKeptTakesTheInputsOfItsListAndNoValueBeside(): void {
		// Behebung 3 of Z8b branch 3: the one list of the inputs of the
		// retention decision; kept() takes exactly these, each needed one
		// that is null keeps the group as unknown.
		self::assertSame( array( 'entry', 'now', 'open', 'backups', 'keep' ), array_keys( AB_MCP_Protected_Store::RETENTION_INPUTS ) );
		foreach ( AB_MCP_Protected_Store::RETENTION_INPUTS as $name => $source ) {
			list( $class, $method ) = explode( '::', $source );
			self::assertTrue( method_exists( $class, $method ), "$name: its source $source is there." );
		}
		$now  = 1800000000;
		$goes = array(
			'area'    => 'backups',
			'created' => $now - 400 * 86400,
			'run_id'  => 7,
		);
		$in   = self::inputs( $goes, $now, array( 3 ), array( 7 => false ) );
		self::assertFalse( AB_MCP_Protected_Store::kept( $in ), 'The base: it goes.' );
		$less = $in;
		unset( $less['keep'] );
		self::assertNull( AB_MCP_Protected_Store::kept( $less ), 'An input missing: unknown.' );
		self::assertNull( AB_MCP_Protected_Store::kept( $in + array( 'more' => 1 ) ), 'An input not on the list: unknown, never read.' );
		self::assertFalse( AB_MCP_Protected_Store::kept( array_reverse( $in, true ) ), 'The same inputs in another order decide the same.' );
		// Each input of the list, null where the group needs it: a backup of a
		// closed run whose row the retention names needs the entry, the runs
		// and the retention; one whose run has no row is kept by its age and
		// needs the time and the seconds of its area.
		$by_age = array_replace( $in, array( 'backups' => array( 3 => true ) ) );
		self::assertFalse( AB_MCP_Protected_Store::kept( $by_age ), 'The base by age: it goes.' );
		$needs  = array(
			'entry'   => $in,
			'now'     => $by_age,
			'open'    => $in,
			'backups' => $in,
			'keep'    => $by_age,
		);
		self::assertSame( array_keys( AB_MCP_Protected_Store::RETENTION_INPUTS ), array_keys( $needs ), 'Every input of the list has its case here: a new one without is red.' );
		foreach ( $needs as $name => $base ) {
			self::assertNull( AB_MCP_Protected_Store::kept( array_replace( $base, array( $name => null ) ) ), "$name null where this group needs it: unknown, kept." );
		}
		self::assertFalse( AB_MCP_Protected_Store::kept( array_replace( $in, array( 'now' => null, 'keep' => null ) ) ), 'Not needed by this group (its run decides): time and seconds decide nothing.' );
		foreach ( array( 0, -1, '1800000000', 1.8e9 ) as $at ) {
			self::assertNull( AB_MCP_Protected_Store::kept( array_replace( $by_age, array( 'now' => $at ) ) ), 'now ' . var_export( $at, true ) . ': unknown.' );
		}
		$keep            = $in['keep'];
		$keep['backups'] = null;
		self::assertNull( AB_MCP_Protected_Store::kept( array_replace( $in, array( 'keep' => $keep, 'backups' => array() ) ) ), 'The seconds of the area not known: unknown.' );
		self::assertSame( true, AB_MCP_Protected_Store::kept( array_replace( $in, array( 'backups' => array( 7 => true ) ) ) ), 'A run the retention keeps.' );
		self::assertNull( AB_MCP_Protected_Store::kept( array_replace( $in, array( 'backups' => array( 7 => 1 ) ) ) ), 'A retention of no bool: unknown, neither kept nor freed by a cast.' );
		self::assertNull( AB_MCP_Protected_Store::kept( array_replace( $in, array( 'open' => 'none' ) ) ), 'Runs that are no list: unknown.' );
	}

	public function testTheRunsAreNotReadWithoutTheirTable(): void {
		// Without the tables the runs cannot be read: null, not "none open".
		self::assertNull( AB_MCP_Sync_Schema::open_runs() );
		self::assertSame( array( 'closed_done', 'closed_findings', 'closed_aborted', 'closed_checked', 'closed_reverted' ), AB_MCP_Sync_Schema::CLOSED );
	}

	/* ---------------------------------------------------------- ownership */

	public function testAFolderIsOursOnlyWithOurManifestInEveryField(): void {
		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', 'ab_unit' );
		}
		$id    = str_repeat( 'a', 32 );
		$owner = AB_MCP_Protected_Store::identity( $id );
		self::assertSame( array( 'format', 'install_id', 'abspath', 'database', 'address' ), array_keys( $owner ) );
		self::assertSame( realpath( ABSPATH ), $owner['abspath'], 'ABSPATH resolved.' );
		self::assertSame( hash( 'sha256', DB_NAME . '|wp_' ), $owner['database'], 'DB_NAME and the prefix, hashed.' );
		self::assertSame( 'https://example.test', $owner['address'] );

		$folder = $this->dir . '/store';
		mkdir( $folder, 0700 );
		self::assertFalse( AB_MCP_Protected_Store::owns( $folder, $id ), 'No manifest, not ours.' );
		file_put_contents( $folder . '/owner.json', json_encode( $owner + array( 'created' => '1' ) ) );
		self::assertTrue( AB_MCP_Protected_Store::owns( $folder, $id ) );
		self::assertFalse( AB_MCP_Protected_Store::owns( $folder, str_repeat( 'b', 32 ) ), 'Another id.' );
		foreach ( array_keys( $owner ) as $field ) {
			$other           = $owner;
			$other[ $field ] = $owner[ $field ] . 'x';
			file_put_contents( $folder . '/owner.json', json_encode( $other ) );
			self::assertFalse( AB_MCP_Protected_Store::owns( $folder, $id ), "Another $field." );
		}
		file_put_contents( $folder . '/owner.json', json_encode( $owner ) );
		$GLOBALS['wpdb']->prefix = 'wpb_';
		self::assertFalse( AB_MCP_Protected_Store::owns( $folder, $id ), 'Another table prefix in the same database.' );
		$GLOBALS['wpdb']->prefix = 'wp_';
		$GLOBALS['ab_test_home'] = 'https://staging.example.test';
		self::assertFalse( AB_MCP_Protected_Store::owns( $folder, $id ), 'Another address.' );
		$GLOBALS['ab_test_home'] = 'https://example.test/blog';
		self::assertFalse( AB_MCP_Protected_Store::owns( $folder, $id ), 'Another path.' );
		$GLOBALS['ab_test_home'] = 'https://example.test:8443';
		self::assertFalse( AB_MCP_Protected_Store::owns( $folder, $id ), 'Another port.' );
		$GLOBALS['ab_test_home'] = 'http://example.test';
		self::assertTrue( AB_MCP_Protected_Store::owns( $folder, $id ), 'The same site moved from https to http (or back) keeps its folder.' );
		unset( $GLOBALS['ab_test_home'] );
		symlink( $folder, $this->dir . '/link' );
		self::assertFalse( AB_MCP_Protected_Store::owns( $this->dir . '/link', $id ), 'A link is not our folder.' );
		self::assertTrue( AB_MCP_Protected_Store::owns( $folder, $id ) );
	}

	/* ------------------------------------------------- without a store */

	public function testWithoutAStoreNothingIsWrittenAndTheWayIsNamed(): void {
		// The stand-in of the database stores no id: there is no store.
		$proof = AB_MCP_Protected_Store::proof();
		self::assertFalse( $proof['available'] );
		self::assertSame( 'no_id', $proof['reason'] );
		self::assertNull( $proof['where'] );
		self::assertFalse( AB_MCP_Protected_Store::available() );
		$gid = str_repeat( 'c', 32 );
		foreach ( array(
			AB_MCP_Protected_Store::root(),
			AB_MCP_Protected_Store::open( 'out', array( 'declared' => 10 ) ),
			AB_MCP_Protected_Store::put( 'out', $gid, 'chunk', 'x' ),
			AB_MCP_Protected_Store::get( 'out', $gid, 'chunk', hash( 'sha256', 'x' ) ),
			AB_MCP_Protected_Store::groups( 'backups' ),
			AB_MCP_Protected_Store::files( 'backups', $gid ),
			AB_MCP_Protected_Store::bind( 'backups', $gid, 3 ),
			AB_MCP_Store_Budget::book( 3, 100 ),
			AB_MCP_Store_Budget::release( 3 ),
			AB_MCP_Store_Budget::bookings(),
		) as $i => $res ) {
			self::assertInstanceOf( WP_Error::class, $res, "call $i" );
			self::assertSame( 'ab_mcp_store_unavailable', $res->get_error_code(), "call $i" );
			self::assertSame( $proof['message'], $res->get_error_message() );
		}
		self::assertSame( array( 'removed' => 0, 'kept' => 0, 'unknown' => 0 ), AB_MCP_Protected_Store::cleanup() );
		self::assertFalse( AB_MCP_Protected_Store::remove( 'out', $gid ) );
		$writes = array_filter( $GLOBALS['wpdb']->queries, static fn( string $q ): bool => 0 === strpos( $q, 'INSERT IGNORE' ) );
		self::assertCount( 1, $writes, 'The id was asked for once, by INSERT IGNORE.' );
		self::assertStringContainsString( "'ab_mcp_install_id'", (string) reset( $writes ) );
	}

	public function testTheReferencesAreCheckedBeforeAnything(): void {
		self::assertSame( 'ab_mcp_store_area', AB_MCP_Protected_Store::open( 'tmp' )->get_error_code() );
		self::assertSame( 'ab_mcp_store_label', AB_MCP_Protected_Store::open( 'out', array( 'label' => 'a/b' ) )->get_error_code() );
		$gid = str_repeat( 'd', 32 );
		self::assertSame( 'ab_mcp_store_group', AB_MCP_Protected_Store::put( 'out', '../x', 'a', 'x' )->get_error_code() );
		self::assertSame( 'ab_mcp_store_group', AB_MCP_Protected_Store::put( 'out', strtoupper( $gid ), 'a', 'x' )->get_error_code() );
		foreach ( array( '../a', 'a/b', '.htaccess', 'a..b', 'x.tmp', 'A', '', str_repeat( 'a', 65 ) ) as $name ) {
			self::assertSame( 'ab_mcp_store_name', AB_MCP_Protected_Store::put( 'out', $gid, $name, 'x' )->get_error_code(), $name );
		}
		self::assertSame( 'ab_mcp_store_too_large', AB_MCP_Protected_Store::put( 'out', $gid, 'a', str_repeat( 'x', AB_MCP_Protected_Store::FILE_MAX + 1 ) )->get_error_code() );
		self::assertSame( array(), $GLOBALS['wpdb']->queries, 'Nothing was asked of the database.' );
	}

	/* ----------------------------------------------------------- messages */

	public function testEveryMessageOfAMissingStoreNamesTheWay(): void {
		$way      = ( new ReflectionClassConstant( AnswerWayTest::class, 'WAY' ) )->getValue();
		$messages = array( AB_MCP_Protected_Store::message( 'no_id', array() ), AB_MCP_Protected_Store::message( 'no_document_root', array() ) );
		foreach ( AB_MCP_Protected_Store::findings()['outside'] as $outside => $text ) {
			foreach ( AB_MCP_Protected_Store::findings()['uploads'] as $uploads => $text2 ) {
				$message = AB_MCP_Protected_Store::message( 'none', array( 'outside' => $outside, 'uploads' => $uploads ) );
				self::assertStringContainsString( $text, $message );
				self::assertStringContainsString( $text2, $message );
				$messages[] = $message;
			}
		}
		foreach ( $messages as $message ) {
			self::assertMatchesRegularExpression( $way, $message );
			self::assertStringContainsString( 'nothing is exported, imported or taken over', $message );
			self::assertStringNotContainsString( '/', str_replace( array( 'location ~ /ab-mcp-store- {', 'Apache and LiteSpeed', 'wp-admin' ), '', $message ), 'No path of the server in the message.' );
		}
	}

	/* ---------------------------------------------- the head of the instruction */

	/**
	 * The forms of a mark: its trees (backup: the old version, stage: the
	 * new one), and the cases of the head of its instruction, each with its
	 * section.
	 *
	 * @return array<string,array{0:string[],1:string[]}>
	 */
	public static function marks(): array {
		return array(
			'old and new'  => array(
				array( 'backup', 'stage' ),
				array(
					'- The old version is at the target: section [vor-den-umbenennungen]. Its command only checks.',
					'- Nothing is at the target, and the old version is at the place of the backup: section [zwischen-den-umbenennungen].',
					'- Another entry is at the target, and the old version is at the place of the backup: section [zwischen-den-umbenennungen-ziel-belegt].',
					'- The new version is at the target, the old version is at the place of the backup, and nothing is at the place of the stage: section [nach-den-umbenennungen].',
				),
			),
			'only the old' => array(
				array( 'backup' ),
				array(
					'- The old version is at the target: section [vor-den-umbenennungen]. Its command only checks.',
					'- Nothing is at the target, and the old version is at the place of the backup: section [zwischen-den-umbenennungen].',
					'- Another entry is at the target, and the old version is at the place of the backup: section [zwischen-den-umbenennungen-ziel-belegt].',
				),
			),
			'a new unit'   => array(
				array( 'stage' ),
				array(
					'- Nothing is at the target, and the new version is at the place of the stage: section [vor-den-umbenennungen]. Its command only checks.',
					'- The new version is at the target, and nothing is at the place of the stage: section [nach-den-umbenennungen].',
				),
			),
		);
	}

	/**
	 * Prüft: the head of the instruction of a mark (guide_head(), option B;
	 * Codex on 3014d8b, finding 2), for each form of a mark: the command
	 * that only reads names the target and each tree of the mark in the
	 * store, in this order; only the cases that the checks of a command ask
	 * are named, each with its section (a case of a version only where the
	 * mark has that version); every other state is told to run no command,
	 * to stop and to take the way back of the run with its number. And the
	 * instruction that guide() writes holds these lines after its title,
	 * says of a command only that it contains checks and to stop where it
	 * prints no OK, and sends only to the section the true case names
	 * (Codex on 5199de3, findings 1 and 2): no line of it says that a
	 * command moves nothing where a check fails, or to run the command of
	 * the point where the swap stopped.
	 * Sonst: where the site answers no request any more, a person would be
	 * sent to a command whose checks do not fit what lies there, would find
	 * no case for a state that is surely one, or would take a command that
	 * stopped without OK after its move for one that moved nothing.
	 *
	 * @param string[] $roles The trees of the mark.
	 * @param string[] $cases The cases of its head, each with its section.
	 */
	#[DataProvider( 'marks' )]
	public function testTheHeadOfTheInstructionNamesForEachFormOfAMarkOnlyTheCasesItsCommandsCheck( array $roles, array $cases ): void {
		$trees = array();
		foreach ( $roles as $i => $role ) {
			$trees[] = array(
				'role' => $role,
				'path' => ( 'backup' === $role ? 'backups' : 'stage' ) . '/g' . $i . '/ab-it',
				'dev'  => 16777231,
				'ino'  => 1000 + $i,
				'hash' => null,
			);
		}
		$byrole = array_column( $trees, null, 'role' );
		$root   = '/srv/store';
		$target = '/srv/www/wp-content/plugins/ab-it';
		$head   = new ReflectionMethod( AB_MCP_Protected_Store::class, 'guide_head' );
		$lines  = $head->invoke( null, $target, $root, $byrole['backup'] ?? null, $byrole['stage'] ?? null, 7, static fn( $path ) => '<' . $path . '>' );
		$places = array( '<' . $target . '>' );
		foreach ( $trees as $tree ) {
			$places[] = '<' . $root . '/' . $tree['path'] . '>';
		}
		$want = array_merge(
			array(
				'First see which command fits. The next line only reads: for the target and for each place of the store named below it shows the inode of what is there (the first number of its line), or says that nothing is there:',
				'$ ls -di ' . implode( ' ', $places ),
				'Then take the case below that is true of what it showed, and run the command of the section it names. An entry is the old version where it has the inode named below for the backup, the new version where it has the inode named below for the stage, and another entry where it has neither.',
			),
			$cases,
			array( 'Where none of them is true (another inode, or none, where a case names a version; or an entry where a case says that nothing is there), run no command of this file. Stop, and take the way back of the run as soon as the site answers again: wp_site_revert action=plan with run_id 7.' )
		);
		self::assertSame( $want, $lines, 'The command that only reads, the cases of the form, the line for every other state.' );

		// The instruction as guide() writes it, with the same mark.
		$guide = AB_MCP_Protected_Store::guide(
			array(
				'store'  => $root . '/',
				'target' => $target,
				'trees'  => $trees,
				'run_id' => 7,
				'seq'    => 3,
				'begun'  => 0,
				'unit'   => array( 'kind' => 'plugin', 'ref' => 'ab-it' ),
				'active' => array(),
			)
		);
		$all = explode( "\n", $guide );
		$ls  = "$ ls -di '" . implode( "' '", array_merge( array( $target ), array_map( static fn( array $t ): string => $root . '/' . $t['path'], $trees ) ) ) . "'";
		self::assertSame( 'AlphaBridge MCP: how to put the old state back by hand, without WordPress', $all[0] );
		self::assertSame( array_replace( $want, array( 1 => $ls ) ), array_slice( $all, 2, count( $want ) ), 'After the title the head of the mark as guide_head() makes it, each path quoted for the shell.' );
		self::assertContains( 'In a shell, run only the command of the section that the true case above names. The command contains checks; where it does not print OK, stop and ask before anything else is moved.', $all, 'Only the command of the section the head names, and of it only that it contains checks (Codex on 5199de3, findings 1 and 2).' );
		foreach ( array( 'moves nothing', 'nothing is moved', 'checks first', 'come first', 'the point where the swap stopped' ) as $promise ) {
			self::assertStringNotContainsString( $promise, $guide, 'No promise that a command moves nothing where a check fails, and no choice beside the head: ' . $promise );
		}
	}
}

/**
 * Take every callback off a filter (the bootstrap has remove_filter() only).
 *
 * @param string $hook Filter.
 */
function remove_all_filters_for( string $hook ): void {
	unset( $GLOBALS['ab_test_filters'][ $hook ] );
}
