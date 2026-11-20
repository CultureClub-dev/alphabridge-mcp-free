<?php
/**
 * The swap before its first mutation (Z8b branch 5, part 2a), as far as it
 * runs without a site: prepare() up to the stage, through the seam of its
 * calls, and release() (the Behebung after the Codex round on 4881c0d: A2
 * the test requests switched off for every swap, A3 the place of a unit made
 * new, A6 a Throwable under the lock, A7 a lock not given back named; the
 * core lock taken back in part A1 of the rest of branch 5). What needs the
 * stage, the pair and the file system of a site runs in
 * tests/integration/SyncCodeRunTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types=1 );

namespace AlphaBridge\Tests;

use AB_MCP_Change_Lock;
use AB_MCP_Own_Files;
use AB_MCP_Protected_Store;
use AB_MCP_Sync_Code_Swap;
use AB_MCP_Sync_Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/sync-stubs.php';
require_once dirname( __DIR__ ) . '/includes/sync/class-sync-code-swap.php';

final class SyncCodeSwapTest extends TestCase {

	/** A journal of a swap as begun() writes it (its form is the swap's). */
	private const JOURNAL = array(
		'old' => array(
			'path' => '/site/wp-content/ab-store/backups/1/shop',
			'hash' => 'aaaa',
		),
		'seq' => 3,
	);

	protected function setUp(): void {
		ab_test_reset();
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/ab-mcp-uninstall-test-' . getmypid() );
		}
		// One clock for the swap and its readings (R12): the clock of the
		// budget of the readings is the swap's. The store is decided by
		// prepare() before its readings (here: no id, so no store).
		AB_MCP_Sync_Code_Swap::$clock = static fn(): float => 0.0;
		AB_MCP_Sync_Env::$clock       = AB_MCP_Sync_Code_Swap::$clock;
		$GLOBALS['wpdb']              = new \AbTestSyncWpdb();
		AB_MCP_Protected_Store::forget();
	}

	protected function tearDown(): void {
		$held = new \ReflectionProperty( AB_MCP_Sync_Code_Swap::class, 'held' );
		if ( PHP_VERSION_ID < 80100 ) {
			$held->setAccessible( true );
		}
		$held->setValue(
			null,
			array(
				'change' => false,
			)
		);
		AB_MCP_Sync_Code_Swap::forget();
		AB_MCP_Own_Files::forget();
		AB_MCP_Sync_Code_Swap::$clock = null;
		AB_MCP_Sync_Env::$clock       = null;
		unset( $GLOBALS['wpdb'] );
		AB_MCP_Protected_Store::forget();
	}

	/** What a swap is asked with (the row swap.prepare). */
	private static function args( array $unit = array() ): array {
		return array(
			'run_id' => 1,
			'unit'   => SyncCodeOpsTest::unit( $unit + array( 'seq' => 3, 'op' => 'swap', 'stage_seq' => 2 ) ),
			'env'    => array( str_repeat( 'e', 32 ) ),
			'stage'  => SyncCodeOpsTest::journal()['stage'],
			'until'  => 100.5,
		);
	}

	/** A unit made new (no pair). */
	private static function made_new(): array {
		return array(
			'unit_op'   => 'create',
			'pair_id'   => null,
			'pair_hash' => null,
		);
	}

	/**
	 * The stand-in of every call of the swap: the answers the test gives, a
	 * list of what was called.
	 *
	 * @param array<string,mixed> $on Method => answer, or a callable answering.
	 */
	private static function calls( array $on = array() ): SyncCodeSwapCalls {
		$calls = new SyncCodeSwapCalls( $on );
		$seam  = new \ReflectionProperty( AB_MCP_Sync_Code_Swap::class, 'naht_sync_code_swap' );
		if ( PHP_VERSION_ID < 80100 ) {
			$seam->setAccessible( true );
		}
		$seam->setValue( null, $calls );
		return $calls;
	}

	/** @return array<string,mixed> The locks prepare() holds. */
	private static function held(): array {
		$held = new \ReflectionProperty( AB_MCP_Sync_Code_Swap::class, 'held' );
		if ( PHP_VERSION_ID < 80100 ) {
			$held->setAccessible( true );
		}
		return $held->getValue();
	}

	/**
	 * Prüft: the journal of a swap counts as begun only where its write from
	 * planned took and the step as stored now reads whole as begun with this
	 * very journal, in the same canonical form (keys in any order); every
	 * other reading refuses with code:journal, which names the error of the
	 * database where there is one (T5, the journal of a swap; part D3b-2 of
	 * the rest). The test of the unit tells only the write that fails.
	 * Sonst: the swap could rename on a journal not stored, half read, of
	 * another state or with other values, so that the way back and the next
	 * call would not know the backup it made.
	 */
	#[DataProvider( 'journals_read_back' )]
	public function testTheJournalOfASwapCountsAsBegunOnlyWhereItReadsBackAsWritten( $saved, $step, string $says ): void {
		$asked = array();
		$calls = self::calls(
			array(
				'journal' => static function ( ...$args ) use ( &$asked, $saved ) {
					$asked = $args;
					return $saved;
				},
				'step'    => $step,
			)
		);
		$begun = new \ReflectionMethod( AB_MCP_Sync_Code_Swap::class, 'begun' );
		if ( PHP_VERSION_ID < 80100 ) {
			$begun->setAccessible( true );
		}
		$answer = $begun->invoke( null, self::args(), self::JOURNAL );
		self::assertSame( array( 1, 3, array( 'state' => 'begun', 'parts' => self::JOURNAL ), 'planned' ), $asked, 'Written as begun, from planned only.' );
		self::assertSame( true === $saved ? array( 'journal', 'step' ) : array( 'journal' ), $calls->called, 'Read back only where the write took.' );
		if ( '' === $says ) {
			self::assertTrue( $answer );
			return;
		}
		self::assertInstanceOf( WP_Error::class, $answer );
		self::assertSame( 'code:journal', $answer->get_error_code() );
		self::assertStringContainsString( 'The journal of the swap of the plugin shop could not be written and read back before its first rename (' . $says . '), so nothing of it was moved.', $answer->get_error_message() );
		self::assertStringContainsString( 'wp_site_push action=status', $answer->get_error_message(), 'With the way.' );
	}

	public static function journals_read_back(): array {
		$step = static fn( array $over ): array => $over + array(
			'state'  => 'begun',
			'parts'  => self::JOURNAL,
			'unread' => array(),
		);
		$other = self::JOURNAL;
		$other['old']['hash'] = 'aaab';
		$typed = self::JOURNAL;
		$typed['seq'] = '3';
		return array(
			'written and read back'                    => array( true, $step( array() ), '' ),
			'read back with its keys in another order' => array( true, $step( array( 'parts' => array_reverse( self::JOURNAL, true ) ) ), '' ),
			'read back without unread'                 => array( true, array_diff_key( $step( array() ), array( 'unread' => 1 ) ), '' ),
			'the write refused by the database'        => array( new WP_Error( 'ab_mcp_sync_db', 'the database is locked' ), null, 'the database is locked' ),
			'the write not taken'                      => array( false, null, '-' ),
			'the step not read back'                   => array( true, null, '-' ),
			'the step read as an error'                => array( true, new WP_Error( 'ab_mcp_sync_db', 'gone' ), '-' ),
			'the step of another state'                => array( true, $step( array( 'state' => 'planned' ) ), '-' ),
			'a record of the step not read'            => array( true, $step( array( 'unread' => array( 'result' ) ) ), '-' ),
			'no journal in the step'                   => array( true, $step( array( 'parts' => null ) ), '-' ),
			'another value in the journal'             => array( true, $step( array( 'parts' => $other ) ), '-' ),
			'a value of another type'                  => array( true, $step( array( 'parts' => $typed ) ), '-' ),
		);
	}

	public function testTheTestRequestsSwitchedOffRefuseEverySwapAndClaimNoRunningCode(): void {
		// A2 of the Codex round on 4881c0d (Z14, E5 4): without the test
		// requests before, nothing is swapped, of running code or not. Red at
		// 4881c0d: code that does not run went on past the probe that was off.
		$cases = array(
			'not running, off'      => array( serialize( array( 'gallery/gallery.php' ) ), false ),
			'not running, not read' => array( serialize( array( 'gallery/gallery.php' ) ), null ),
			'running, off'          => array( serialize( array( 'shop/shop.php' ) ), false ),
		);
		foreach ( $cases as $what => list( $active, $enabled ) ) {
			$calls = self::calls(
				array(
					'option'        => array( 'value' => $active ),
					'probe_enabled' => $enabled,
				)
			);
			$got   = AB_MCP_Sync_Code_Swap::prepare( self::args( array( 'accept' => true ) ) );
			self::assertInstanceOf( WP_Error::class, $got, $what );
			self::assertSame( 'code:probe_off', $got->get_error_code(), $what . ': ' . $got->get_error_message() );
			self::assertStringContainsString( 'The test requests of AlphaBridge are switched off on this site (the filter ab_mcp_php_probe_enabled), or not read as switched on, so the plugin shop is not swapped', $got->get_error_message(), $what );
			self::assertStringContainsString( 'Switch the test requests on again, or update the unit by hand in wp-admin.', $got->get_error_message(), $what . ': with the way.' );
			self::assertStringNotContainsString( 'runs on this site', $got->get_error_message(), $what . ': no claim of running code.' );
			self::assertSame( array(), array_intersect( array( 'probe', 'opcache', 'lstat', 'scandir' ), $calls->called ), $what . ': no test request, no OPcache, no reading.' );
			self::assertSame( array( 'release_lock' ), array_values( array_intersect( $calls->called, array( 'release_lock' ) ) ), $what . ': the lock given back.' );
			self::assertSame( array( 'change' => false ), self::held(), $what );
		}
	}

	public function testThePlaceOfAUnitMadeNewIsEmptyOnlyWhereItsFolderListsWithoutItAndNothingIsRead(): void {
		// A3 of the Codex round on 4881c0d (Z3): «missing» and «not known» are
		// two; only a place read as empty lets a unit made new on. Red at
		// 4881c0d: lstat() false alone counted as empty.
		$stat  = array( 'mode' => 0040755, 'dev' => 1 );
		$cases = array(
			'lstat() fails, the folder lists the name' => array( false, array( '.', '..', 'shop', 'gallery' ), 'code:live_changed', 'Something lies in the place of the plugin shop' ),
			'lstat() fails, the folder not listed'    => array( false, false, 'code:place_unknown', 'Whether the place of the plugin shop on this site, which the plan makes new here, is empty could not be read now' ),
			'something there'                         => array( $stat, array( '.', '..', 'gallery' ), 'code:live_changed', 'Something lies in the place of the plugin shop' ),
			'something there, the folder not listed'  => array( $stat, false, 'code:live_changed', 'Something lies in the place of the plugin shop' ),
		);
		foreach ( $cases as $what => list( $lstat, $names, $code, $says ) ) {
			$calls = self::calls(
				array(
					'option'  => array( 'value' => serialize( array( 'gallery/gallery.php' ) ) ),
					'lstat'   => $lstat,
					'scandir' => $names,
				)
			);
			$got   = AB_MCP_Sync_Code_Swap::prepare( self::args( self::made_new() ) );
			self::assertInstanceOf( WP_Error::class, $got, $what );
			self::assertSame( $code, $got->get_error_code(), $what . ': ' . $got->get_error_message() );
			self::assertStringContainsString( $says, $got->get_error_message(), $what );
			self::assertContains( 'probe', $calls->called, $what . ': after the test requests before.' );
			self::assertSame( array( 'change' => false ), self::held(), $what . ': the lock given back.' );
		}
		// Read as empty, a unit made new goes on to the stage: in
		// tests/integration/SyncCodeRunTest.php (the stage needs the store).
		self::assertContains( WP_PLUGIN_DIR, $calls->listed, 'The folder above the place listed.' );
	}

	public function testTheLockNotGivenBackStaysHeldAndIsNamed(): void {
		// A7 of the Codex round on 4881c0d: a lock not given back stays held
		// here and is named. Red at 4881c0d: false counted as given back. (The
		// lock for updates and its own finally went with the core lock, part A1
		// of the rest of branch 5.)
		$held = new \ReflectionProperty( AB_MCP_Sync_Code_Swap::class, 'held' );
		if ( PHP_VERSION_ID < 80100 ) {
			$held->setAccessible( true );
		}
		foreach ( array( 'change false' => array( false, array( 'change' ) ), 'change null' => array( null, array( 'change' ) ), 'none' => array( true, array() ) ) as $what => list( $change, $kept ) ) {
			$held->setValue( null, array( 'change' => true ) );
			self::calls( array( 'release_lock' => $change ) );
			self::assertSame( $kept, AB_MCP_Sync_Code_Swap::release(), $what );
			self::assertSame( array( 'change' => in_array( 'change', $kept, true ) ), self::held(), $what );
		}
		// prepare(): a refusal names the lock it could not give back.
		self::calls(
			array(
				'probe_enabled' => false,
				'release_lock'  => false,
			)
		);
		$got = AB_MCP_Sync_Code_Swap::prepare( self::args( array( 'accept' => true ) ) );
		self::assertSame( 'code:probe_off', $got->get_error_code(), 'The refusal as it is.' );
		self::assertStringContainsString( 'or update the unit by hand in wp-admin. AlphaBridge\'s lock for changes of the code, which this call took, could not be given back, so it stays: other changes through AlphaBridge can be refused until it is ' . intdiv( AB_MCP_Change_Lock::TIMEOUT, 60 ) . ' minutes old', $got->get_error_message() );
		self::assertSame( array( 'kept' => array( 'change' ) ), $got->get_error_data() );
	}

	public function testAThrowableUnderTheLockGivesTheLockForChangesBack(): void {
		// A6 at the caller: a Throwable after the lock for changes is taken
		// gives it back (the first call under it; before part A1 of the rest of
		// branch 5 the lock for updates).
		$calls  = self::calls(
			array(
				'option' => static function () {
					throw new \RuntimeException( 'a hook threw' );
				},
			)
		);
		$caught = null;
		try {
			AB_MCP_Sync_Code_Swap::prepare( self::args() );
		} catch ( \RuntimeException $e ) {
			$caught = $e->getMessage();
		}
		self::assertSame( 'a hook threw', $caught );
		self::assertSame( array( 'take_lock', 'option', 'release_lock' ), $calls->called );
		self::assertSame( array( 'change' => false ), self::held() );
	}

	public function testAPluginFolderNotListedIsUnknownUnlessItsAbsenceIsRead(): void {
		// A4 of the Codex round on 4881c0d (V6, Z11): a folder WordPress loads
		// plugins from that is not listed counts as missing only where the
		// folder above lists whole without it; else AlphaBridge's identities
		// there are not known, and own_state() answers null. Red at 4881c0d:
		// scandir() and file_exists() false (a folder above not searchable)
		// passed as «not there».
		$dir   = rtrim( WP_PLUGIN_DIR, '/' );
		$calls = static function ( $up ) use ( $dir ): object {
			return new class( $dir, $up ) {
				/** @var string */
				private $dir;
				/** @var mixed */
				private $up;
				public function __construct( string $dir, $up ) {
					$this->dir = $dir;
					$this->up  = $up;
				}
				public function realpath( $path ) {
					return $path;
				}
				public function stat( $path ) {
					return $this->dir === $path ? false : array( 'dev' => 1, 'ino' => crc32( (string) $path ) );
				}
				public function scandir( $path ) {
					if ( $this->dir === $path ) {
						return false;
					}
					return dirname( $this->dir ) === $path ? $this->up : array( '.', '..' );
				}
			};
		};
		$seam = new \ReflectionProperty( AB_MCP_Own_Files::class, 'naht_own_files' );
		if ( PHP_VERSION_ID < 80100 ) {
			$seam->setAccessible( true );
		}
		$seam->setValue( null, $calls( false ) );
		self::assertNull( AB_MCP_Own_Files::own_state( sys_get_temp_dir() ), 'The folder above not listed: unknown.' );
		$seam->setValue( null, $calls( array( '.', '..', basename( $dir ) ) ) );
		self::assertNull( AB_MCP_Own_Files::own_state( sys_get_temp_dir() ), 'Listed above, not read itself: unknown.' );
		$seam->setValue( null, $calls( array( '.', '..', 'themes' ) ) );
		self::assertNotNull( AB_MCP_Own_Files::own_state( sys_get_temp_dir() ), 'Its absence read: it holds nothing of AlphaBridge.' );
		$seam->setValue( null, $calls( false ) );
		self::assertTrue( AB_MCP_Own_Files::own_file_refusal( sys_get_temp_dir() ), 'The file tools keep their lenient rule.' );
	}
}

/** Every call of the swap, answered as a test of SyncCodeSwapTest gives it. */
final class SyncCodeSwapCalls {

	/** @var array<string,mixed> */
	private $on;

	/** @var string[] What was called, in order. */
	public $called = array();

	/** @var string[] The folders listed. */
	public $listed = array();

	/** @param array<string,mixed> $on Method => answer, or a callable answering. */
	public function __construct( array $on ) {
		$this->on = $on + array(
			'option'        => array( 'value' => serialize( array( 'shop/shop.php' ) ) ),
			'take_lock'     => true,
			'release_lock'  => true,
			'lock_row'      => array( 'value' => '1700000000' ),
			'probe_enabled' => true,
			'probe'         => array(
				'targets' => array(
					array( 'target' => 'home', 'result' => 'ok' ),
					array( 'target' => 'mcp', 'result' => 'ok' ),
					array( 'target' => 'admin_post', 'result' => 'ok' ),
				),
			),
			'opcache'       => 'invalidated',
			'own'           => false,
			'stat'          => array( 'mode' => 0040755, 'dev' => 1 ),
			'lstat'         => array( 'mode' => 0100644, 'dev' => 1 ),
			'scandir'       => array( '.', '..' ),
			'journal'       => true,
			'step'          => null,
		);
	}

	/**
	 * @param string $method The call.
	 * @param array  $args   Its arguments.
	 * @return mixed
	 */
	private function answer( string $method, array $args ) {
		$this->called[] = $method;
		$on             = $this->on[ $method ];
		return $on instanceof \Closure ? $on( ...$args ) : $on;
	}

	public function option( $name ) {
		return $this->answer( 'option', array( $name ) );
	}

	public function take_lock() {
		return $this->answer( 'take_lock', array() );
	}

	public function release_lock() {
		return $this->answer( 'release_lock', array() );
	}

	public function lock_row() {
		return $this->answer( 'lock_row', array() );
	}

	public function probe_enabled() {
		return $this->answer( 'probe_enabled', array() );
	}

	public function probe( array $opts ) {
		return $this->answer( 'probe', array( $opts ) );
	}

	public function opcache( $path ) {
		return $this->answer( 'opcache', array( $path ) );
	}

	public function own( $path ) {
		return $this->answer( 'own', array( $path ) );
	}

	public function stat( $path ) {
		return $this->answer( 'stat', array( $path ) );
	}

	public function lstat( $path ) {
		return $this->answer( 'lstat', array( $path ) );
	}

	public function scandir( $dir ) {
		$this->listed[] = $dir;
		return $this->answer( 'scandir', array( $dir ) );
	}

	public function journal( $run_id, $seq, array $set, $from ) {
		return $this->answer( 'journal', array( $run_id, $seq, $set, $from ) );
	}

	public function step( $run_id, $seq ) {
		return $this->answer( 'step', array( $run_id, $seq ) );
	}
}
