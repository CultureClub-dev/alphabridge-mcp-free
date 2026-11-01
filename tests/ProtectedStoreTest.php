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
 *   the backups by ab_mcp_staging_backup_days;
 * - owns() takes a folder only with this installation's manifest, every
 *   field of it;
 * - without a store nothing is written, and every message names the way.
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

	public function testTheCleanUpKeepsWhatAnOpenRunHoldsAndTheRestByItsTime(): void {
		$now = 1800000000;
		$day = 86400;
		$old = static fn( string $area, int $age, int $run = 0 ): array => array(
			'area'    => $area,
			'created' => $now - $age,
			'run_id'  => $run,
		);
		foreach ( array( 'out', 'in', 'reports' ) as $area ) {
			self::assertTrue( AB_MCP_Protected_Store::kept( $old( $area, $day - 1 ), $now, array() ), "$area: younger than a day." );
			self::assertFalse( AB_MCP_Protected_Store::kept( $old( $area, $day ), $now, array() ), "$area: a day old." );
			self::assertTrue( AB_MCP_Protected_Store::kept( $old( $area, 30 * $day, 7 ), $now, array( 3, 7 ) ), "$area: a run that is not closed holds it." );
			self::assertFalse( AB_MCP_Protected_Store::kept( $old( $area, 30 * $day, 7 ), $now, array( 3 ) ), "$area: a closed run does not." );
			self::assertTrue( AB_MCP_Protected_Store::kept( $old( $area, 30 * $day, 7 ), $now, null ), "$area: while the runs cannot be read, a run's group stays." );
			self::assertFalse( AB_MCP_Protected_Store::kept( $old( $area, 30 * $day ), $now, null ), "$area: one of no run goes by its time." );
		}
		self::assertSame( 7, AB_MCP_Protected_Store::backup_days() );
		self::assertTrue( AB_MCP_Protected_Store::kept( $old( 'backups', 7 * $day - 1 ), $now, array() ) );
		self::assertFalse( AB_MCP_Protected_Store::kept( $old( 'backups', 7 * $day ), $now, array() ), 'Backups: seven days.' );

		add_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, static fn(): int => 30 );
		self::assertSame( 30, AB_MCP_Protected_Store::backup_days(), 'Pro sets 30 through the filter.' );
		self::assertTrue( AB_MCP_Protected_Store::kept( $old( 'backups', 29 * $day ), $now, array() ) );
		self::assertFalse( AB_MCP_Protected_Store::kept( $old( 'backups', 30 * $day ), $now, array() ) );
		remove_all_filters_for( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER );
		add_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, static fn(): int => 0 );
		self::assertSame( 1, AB_MCP_Protected_Store::backup_days(), 'At least a day.' );
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
		self::assertSame( array( 'removed' => 0, 'kept' => 0 ), AB_MCP_Protected_Store::cleanup() );
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
		foreach ( AB_MCP_Protected_Store::FINDINGS['outside'] as $outside => $text ) {
			foreach ( AB_MCP_Protected_Store::FINDINGS['uploads'] as $uploads => $text2 ) {
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
}

/**
 * Take every callback off a filter (the bootstrap has remove_filter() only).
 *
 * @param string $hook Filter.
 */
function remove_all_filters_for( string $hook ): void {
	unset( $GLOBALS['ab_test_filters'][ $hook ] );
}
