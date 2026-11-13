<?php
/**
 * The swap before its first mutation (Z8b branch 5, part 2a), as far as it
 * runs without a site: prepare() up to the stage, through the seam of its
 * calls, and release() (the Behebung after the Codex round on 4881c0d: A2
 * the test requests switched off for every swap, A3 the place of a unit made
 * new, A6 a Throwable on the way to the lock, A7 the locks given back each in
 * its own finally, a lock not given back named). What needs the stage, the
 * pair and the file system of a site runs in
 * tests/integration/SyncCodeRunTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types=1 );

namespace AlphaBridge\Tests;

use AB_MCP_Change_Lock;
use AB_MCP_Own_Files;
use AB_MCP_Sync_Code_Swap;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/includes/sync/class-sync-code-swap.php';

final class SyncCodeSwapTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/ab-mcp-uninstall-test-' . getmypid() );
		}
		AB_MCP_Sync_Code_Swap::$clock = static fn(): float => 0.0;
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
				'core'   => null,
			)
		);
		AB_MCP_Sync_Code_Swap::forget();
		AB_MCP_Own_Files::forget();
		AB_MCP_Sync_Code_Swap::$clock = null;
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
			self::assertSame( array( 'free_core_lock', 'release_lock' ), array_values( array_intersect( $calls->called, array( 'release_lock', 'free_core_lock' ) ) ), $what . ': both locks given back, the lock for updates first.' );
			self::assertSame( array( 'change' => false, 'core' => null ), self::held(), $what );
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
			self::assertSame( array( 'change' => false, 'core' => null ), self::held(), $what . ': both locks given back.' );
		}
		// Read as empty, a unit made new goes on to the stage: in
		// tests/integration/SyncCodeRunTest.php (the stage needs the store).
		self::assertContains( WP_PLUGIN_DIR, $calls->listed, 'The folder above the place listed.' );
	}

	public function testBothLocksAreGivenBackEachInItsOwnFinallyAndALockNotGivenBackIsNamed(): void {
		// A7 of the Codex round on 4881c0d: a Throwable or an answer not true
		// of one release does not keep the other from running; a lock not
		// given back stays held here and is named. Red at 4881c0d: a Throwable
		// of free_core_lock() kept the lock for changes, false counted as
		// given back.
		$held = new \ReflectionProperty( AB_MCP_Sync_Code_Swap::class, 'held' );
		if ( PHP_VERSION_ID < 80100 ) {
			$held->setAccessible( true );
		}
		$hold = static function () use ( $held ): void {
			$held->setValue(
				null,
				array(
					'change' => true,
					'core'   => 'abc',
				)
			);
		};
		$hold();
		$calls  = self::calls(
			array(
				'free_core_lock' => static function () {
					throw new \RuntimeException( 'the database went away' );
				},
			)
		);
		$caught = null;
		try {
			AB_MCP_Sync_Code_Swap::release();
		} catch ( \RuntimeException $e ) {
			$caught = $e->getMessage();
		}
		self::assertSame( 'the database went away', $caught, 'The Throwable goes on.' );
		self::assertSame( array( 'free_core_lock', 'release_lock' ), $calls->called, 'The lock for changes is given back all the same.' );
		self::assertSame( array( 'change' => false, 'core' => 'abc' ), self::held(), 'The lock for updates stays held here.' );
		foreach ( array( 'core false' => array( false, true, array( 'core' ) ), 'core null' => array( null, true, array( 'core' ) ), 'change false' => array( true, false, array( 'change' ) ), 'both' => array( false, false, array( 'core', 'change' ) ), 'none' => array( true, true, array() ) ) as $what => list( $core, $change, $kept ) ) {
			$hold();
			self::calls(
				array(
					'free_core_lock' => $core,
					'release_lock'   => $change,
				)
			);
			self::assertSame( $kept, AB_MCP_Sync_Code_Swap::release(), $what );
			self::assertSame(
				array(
					'change' => in_array( 'change', $kept, true ),
					'core'   => in_array( 'core', $kept, true ) ? 'abc' : null,
				),
				self::held(),
				$what
			);
		}
		// prepare(): a refusal names the lock it could not give back.
		self::calls(
			array(
				'core_lock'    => new WP_Error( 'ab_mcp_update_busy', 'Not started: the lock for updates is held.' ),
				'release_lock' => false,
			)
		);
		$got = AB_MCP_Sync_Code_Swap::prepare( self::args() );
		self::assertSame( 'ab_mcp_update_busy', $got->get_error_code(), 'The refusal of the lock for updates as it is (no code:busy).' );
		self::assertStringContainsString( 'Not started: the lock for updates is held. AlphaBridge\'s lock for changes of the code, which this call took, could not be given back, so it stays: other changes through AlphaBridge can be refused until it is ' . intdiv( AB_MCP_Change_Lock::TIMEOUT, 60 ) . ' minutes old', $got->get_error_message() );
		self::assertSame( array( 'kept' => array( 'change' ) ), $got->get_error_data() );
	}

	public function testAThrowableOnTheWayToTheLockForUpdatesGivesTheLockForChangesBack(): void {
		// A6 at the caller: whatever the guard does with its own lock, the lock
		// for changes prepare() took goes back on a Throwable.
		$calls  = self::calls(
			array(
				'core_lock' => static function () {
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
		self::assertSame( array( 'take_lock', 'core_lock', 'release_lock' ), $calls->called );
		self::assertSame( array( 'change' => false, 'core' => null ), self::held() );
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
			'option'         => array( 'value' => serialize( array( 'shop/shop.php' ) ) ),
			'take_lock'      => true,
			'release_lock'   => true,
			'lock_row'       => array( 'value' => '1700000000' ),
			'core_lock'      => 'abc',
			'core_held'      => true,
			'free_core_lock' => true,
			'probe_enabled'  => true,
			'probe'          => array(
				'targets' => array(
					array( 'target' => 'home', 'result' => 'ok' ),
					array( 'target' => 'mcp', 'result' => 'ok' ),
					array( 'target' => 'admin_post', 'result' => 'ok' ),
				),
			),
			'opcache'        => 'invalidated',
			'own'            => false,
			'stat'           => array( 'mode' => 0040755, 'dev' => 1 ),
			'lstat'          => array( 'mode' => 0100644, 'dev' => 1 ),
			'scandir'        => array( '.', '..' ),
			'stored'         => false,
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

	public function core_lock( $kind, $ref ) {
		return $this->answer( 'core_lock', array( $kind, $ref ) );
	}

	public function core_held( $nonce ) {
		return $this->answer( 'core_held', array( $nonce ) );
	}

	public function free_core_lock( $nonce ) {
		return $this->answer( 'free_core_lock', array( $nonce ) );
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

	public function stored( $name ) {
		return $this->answer( 'stored', array( $name ) );
	}
}
