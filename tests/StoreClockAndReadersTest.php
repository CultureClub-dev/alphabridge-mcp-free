<?php
/**
 * Two guards over the source by its tokens (Z8b branch 3, Behebung 5b; no
 * pattern over the text): in the files of the store, its budget, its table
 * and the runs no clock but the one checked clock of the plugin,
 * AB_MCP_Sync_Run::now(), beside the named exceptions; and every method
 * that reads a row of AB_MCP_Store_Inputs::TABLE or that clock is driven
 * with every wrong form of its row by a test named here, or named with the
 * reason it is not. A raw time() put in, or a new reader without its
 * driver, is red here.
 *
 * The time() guard reaches the files of FILES only (those of branch 3 and
 * the export): a time() elsewhere is a limit (GRENZEN-CODE.md, G-3-10).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-store-inputs.php';
require_once __DIR__ . '/SourceScan.php';

final class StoreClockAndReadersTest extends TestCase {

	/** The files the time() guard reads (the touched files of branches 3 and 4 and the export). */
	private const FILES = array(
		'includes/class-protected-store.php',
		'includes/class-shape.php',
		'includes/class-store-budget.php',
		'includes/class-store-inputs.php',
		'includes/sync/class-sync-backup.php',
		'includes/sync/class-sync-engine.php',
		'includes/sync/class-sync-run.php',
		'includes/sync/class-sync-schema.php',
		'includes/sync/class-sync-export.php',
		'includes/sync/class-sync-manifest.php',
		'includes/sync/class-sync-code-stage.php',
		'includes/sync/class-sync-code-package.php',
		'includes/sync/class-sync-code-kind.php',
		'includes/sync/class-sync-code-ops.php',
		'includes/sync/class-sync-code-plan.php',
		'includes/sync/class-sync-code-swap.php',
		'includes/sync/class-sync-push.php',
	);

	/**
	 * The time() these files may call, each with why: the clock itself, and
	 * what decides nothing of removal, retention, protection by a mark or a
	 * budget and writes no time one of those reads.
	 */
	private const TIME = array(
		'includes/sync/class-sync-run.php'   => array(
			'AB_MCP_Sync_Run::now' => 'the one checked clock itself (period.clock), with its seam $clock',
		),
		'includes/class-protected-store.php' => array(
			'AB_MCP_Protected_Store::admitted'   => 'the self-check of the place (valid()): whether the store takes files, with microtime() in the same check; decides no removal, retention or budget',
			'AB_MCP_Protected_Store::php_proved' => 'the self-check of the place (valid()): whether a tree may go into it; decides no removal, retention or budget',
			'AB_MCP_Protected_Store::observe'    => 'the self-check of the place (needs_check()): when the place is checked again; decides no removal, retention or budget',
			'AB_MCP_Protected_Store::put_mark'   => 'begun of a mark: for its instruction only; guard() and sweep() do not read it (mark.json has no row for it)',
			'AB_MCP_Protected_Store::ensure'     => 'created of the manifest of a folder: shown only; owns() compares the id, the database and the address',
			'AB_MCP_Protected_Store::schedule'   => 'the schedule of the daily clean-up (WP-Cron); the clean-up takes its time from the clock',
		),
		'includes/class-store-budget.php'    => array(
			'AB_MCP_Store_Budget::book' => 'booked of a booking: written only; no decision reads it (budget.bookings checks it as a whole number)',
		),
		'includes/sync/class-sync-schema.php' => array(
			'AB_MCP_Sync_Schema::install'            => 'the time a failed installation of the tables is retried after (wait_after_failure())',
			'AB_MCP_Sync_Schema::wait_after_failure' => 'the wait before the installation of the tables is tried again',
		),
		'includes/sync/class-sync-export.php' => array(
			'AB_MCP_Sync_Export::own' => 'whether a package of this account is still given out (its expiry); no removal: a package goes by its retention, kept() and sidecar.time',
		),
	);

	/**
	 * The clocks of budgets within one call (microtime(), hrtime()) the files
	 * of Z8b branch 4 may call, each with why (decision of the lead
	 * 13.11.2026, Codex round 1 of branch 4, B4): only how long one call
	 * runs; a value that is no finite number ends the slice, never done or
	 * ready; times across requests only through AB_MCP_Sync_Run::now().
	 */
	private const BUDGET = array(
		'includes/sync/class-sync-code-stage.php'   => array(
			'AB_MCP_Sync_Code_Stage::clock'     => 'the budget of complete() (CHECK_BUDGET) and of a reading of a unit (UNIT_BUDGET) within one call; checked: no finite number is unknown, the call ends, the stage stays checking',
			'AB_MCP_Sync_Code_Stage_Calls::run' => 'the time limit of php -l within one call (LINT_TIMEOUT); an end of it is unavailable, never passed',
		),
		'includes/sync/class-sync-code-package.php' => array(),
		'includes/sync/class-sync-code-kind.php'    => array(),
		'includes/sync/class-sync-code-ops.php'     => array(),
		'includes/sync/class-sync-code-plan.php'    => array(),
		'includes/sync/class-sync-code-swap.php'    => array(),
		'includes/sync/class-sync-push.php'         => array(),
		'includes/sync/class-sync-engine.php'       => array(
			'AB_MCP_Sync_Engine::now'        => 'the budget of work of one call (self::$work: when a call stops writing, checking or verifying and the seconds its answer names to wait), with its seam $clock for tests; measured within one call, never stored or read by a later call',
			'AB_MCP_Sync_Engine::run_verify' => 'the deadline of the PHP probe of the verify (AB_MCP_Php_Probe::run(), until) from the time left of the same call; never stored',
		),
		'includes/sync/class-sync-file-manifest.php' => array(
			'AB_MCP_Sync_File_Manifest::now' => 'the budget of a reading of a unit within one call (its deadline, due()), which branch 4 uses for the live unit, the unit built and the tree to check; checked: no finite number is unknown, the reading ends as at the end of its time (Codex confirmation 1, B-3)',
		),
		'includes/sync/class-sync-export.php'       => array(
			'AB_MCP_Sync_Export::code_unit' => 'the budget of the reading of a unit for a package of code within one call (UNIT_BUDGET); checked: no finite number reads nothing (unreadable)',
		),
	);

	/** Tests that drive the readers, by file. */
	private const UNIT = 'tests/StoreInputsTest.php::testEveryWrongFormOfARowReachesItsConsumerAsUnknownAndEveryValidOneAsAValue';
	private const IT   = 'tests/integration/StoreInputsTest.php::testEveryWrongFormOfARowReachesItsConsumerInTheStoreAsUnknown';
	private const NOW  = 'tests/integration/StoreInputsTest.php::testATimeOfTheCleanUpNotOfItsFormRemovesNothing';
	private const CLK  = 'tests/integration/StoreInputsTest.php::testTheClockOfTheRunsAgesNoTempFileAndAbandonsNoMakingWithoutATime';
	private const DCL  = 'tests/integration/StoreInputsTest.php::testOpenTakesTheDeclaredBytesOfAGroupOnlyInTheFormOfTheirRow';
	private const RUN  = 'tests/integration/StoreRemoverTest.php::testAClockThatGivesNoTimeStoresNoPeriodAtTheCloseAndTheBackupsStay';
	private const HELD = 'tests/integration/StoreRemoverTest.php::testAGroupWhoseFilesCannotAllBeCountedTakesNoFile';

	/**
	 * Every reader of a row or of the clock, with its rows and the tests
	 * that drive it with every wrong form of each, or why it needs none.
	 */
	private const READERS = array(
		'AB_MCP_Protected_Store::backup_days'   => array( array( 'period.days' ), array( self::UNIT ) ),
		'AB_MCP_Protected_Store::cleanup'       => array( array( 'period.clock' ), array( self::CLK ) ),
		'AB_MCP_Protected_Store::close_mark_in' => array( array( 'period.clock' ), array( self::CLK ) ),
		'AB_MCP_Protected_Store::entries'       => array( array( 'budget.listing' ), array( self::IT ) ),
		'AB_MCP_Protected_Store::free_space'    => array( array( 'budget.free' ), array( self::IT ) ),
		'AB_MCP_Protected_Store::held'          => array( array( 'budget.file_size', 'budget.held_names' ), array( self::IT ) ),
		'AB_MCP_Protected_Store::in_range'      => array( array( 'sidecar.*' ), array( self::IT ) ),
		'AB_MCP_Protected_Store::inputs'        => array( array( 'retention.now' ), array( self::UNIT, self::NOW ) ),
		'AB_MCP_Protected_Store::kept'          => array( array( 'retention.backups', 'retention.keep', 'retention.now', 'retention.open' ), array( self::UNIT ) ),
		'AB_MCP_Protected_Store::open'          => array( array( 'budget.declared', 'period.clock' ), array( self::DCL, self::CLK ) ),
		'AB_MCP_Protected_Store::raw_size'      => array( array( 'budget.file_size' ), array( self::HELD ), 'the raw size of unpack( \'V\' ) is a whole number or no array; a file it cannot read is the case named' ),
		'AB_MCP_Protected_Store::read_mark'     => array( array( 'mark.json' ), array( self::IT ) ),
		'AB_MCP_Protected_Store::sidecar'       => array( array( 'sidecar.json' ), array( self::IT ) ),
		'AB_MCP_Protected_Store::sweep'         => array( array( 'file.mtime' ), array( self::IT ) ),
		'AB_MCP_Protected_Store::temp_gone'     => array( array( 'file.mtime', 'tmp.build_mtime' ), array( self::IT ) ),
		'AB_MCP_Protected_Store::young'         => array( array( 'period.clock' ), array( self::CLK ) ),
		'AB_MCP_Store_Budget::book'             => array( array( 'budget.book_bytes', 'budget.book_run' ), array( self::IT ) ),
		'AB_MCP_Store_Budget::package_refusal'  => array( array( 'budget.declared', 'budget.max_bytes', 'budget.max_exports', 'budget.max_packages' ), array( self::UNIT ) ),
		'AB_MCP_Store_Budget::read_bookings'    => array( array( 'budget.bookings' ), array( self::IT ) ),
		'AB_MCP_Store_Budget::release'          => array( array( 'budget.book_run' ), array( self::IT ) ),
		'AB_MCP_Sync_Backup::fits_bytes'        => array( array( 'backup.run', 'budget.payload' ), array( self::IT ) ),
		'AB_MCP_Sync_Backup::group'             => array( array( 'backup.run' ), array( self::IT ) ),
		'AB_MCP_Sync_Engine::context'           => array( array( 'period.clock' ), array( self::RUN ) ),
		'AB_MCP_Sync_Engine::restore_part'      => array( array( 'period.clock' ), array( self::RUN ) ),
		'AB_MCP_Sync_Code_Ops::item'            => array( array( 'plan.code' ), array( self::UNIT ) ),
		'AB_MCP_Sync_Code_Ops::order'           => array( array( 'unit.version' ), array( self::IT ), 'its one consumer is AB_MCP_Sync_Code_Plan::version_op(), on which unit() locks; the driver goes through it' ),
		'AB_MCP_Sync_Code_Ops::stage'           => array( array( 'runs.run_id' ), array( 'tests/SyncCodeOpsTest.php::testAStepOfARunNotOfItsFormOrWithFieldsNotReadStagesNothing' ), 'the run of a step is the row the call read under its lock (fresh()); a run_id not of its form refuses before anything is staged, the step stays planned' ),
		'AB_MCP_Sync_Code_Ops::staged'          => array( array( 'step.stage' ), array( self::IT ) ),
		'AB_MCP_Sync_Code_Package::index'      => array( array( 'code.index' ), array( self::IT ) ),
		'AB_MCP_Sync_Code_Plan::of'             => array( array( 'plan.code' ), array( self::UNIT ), 'the plan made is read back in the form of its row before it is returned; a plan not of it makes no run (the same row item() reads at every step)' ),
		'AB_MCP_Sync_Code_Swap::active_plugins' => array( array( 'site.active_plugins' ), array( self::IT ) ),
		'AB_MCP_Sync_Code_Swap::theme'          => array( array( 'site.theme' ), array( self::IT ) ),
		'AB_MCP_Sync_Code_Stage::from_org'      => array( array( 'stage.version' ), array( self::UNIT ) ),
		'AB_MCP_Sync_Code_Stage::head_refusal'  => array( array( 'stage.head' ), array( self::UNIT ) ),
		'AB_MCP_Sync_Code_Stage::state_of'      => array( array( 'stage.state' ), array( self::IT ) ),
		'AB_MCP_Sync_Code_Stage::update_host'   => array( array( 'stage.update_uri' ), array( self::UNIT ) ),
		'AB_MCP_Sync_Env::host_of_head'         => array( array( 'stage.update_head' ), array( self::UNIT ) ),
		'AB_MCP_Sync_Export::discard_stale'     => array( array( 'period.clock' ), array( self::CLK ) ),
		'AB_MCP_Sync_Manifest::clock'          => array( array( 'period.clock' ), array( 'tests/SyncManifestTest.php::testNoTimeOfTheClockSignsOrReadsAPackage' ) ),
		'AB_MCP_Sync_Push::begin'               => array( array( 'period.clock' ), array( self::RUN ), 'the time goes only into create() (expires), which refuses without a time before anything is written' ),
		'AB_MCP_Sync_Revert::begin_locked'      => array( array( 'period.clock' ), array( self::RUN ), 'the time goes only into create() (expires), which refuses without a time before anything is written' ),
		'AB_MCP_Sync_Run::create'               => array( array( 'period.clock' ), array( self::RUN ) ),
		'AB_MCP_Sync_Run::duration'             => array( array( 'period.clock' ), array(), 'the duration of a run, shown only (summary()); decides nothing' ),
		'AB_MCP_Sync_Run::insert_step'          => array( array( 'period.clock' ), array( self::RUN ) ),
		'AB_MCP_Sync_Run::lock_fresh'           => array( array( 'period.clock' ), array( self::RUN ) ),
		'AB_MCP_Sync_Run::now'                  => array( array( 'period.clock' ), array( self::UNIT ) ),
		'AB_MCP_Sync_Run::set_budget'           => array( array( 'period.clock' ), array( self::RUN ) ),
		'AB_MCP_Sync_Run::set_state'            => array( array( 'period.clock', 'period.qualified' ), array( self::RUN ) ),
		'AB_MCP_Sync_Run::touch'                => array( array( 'period.clock' ), array( self::RUN ) ),
		'AB_MCP_Sync_Run::under_lock'           => array( array( 'period.clock' ), array(), 'the time of day in the message of the lock, shown only; lock_fresh() decides' ),
		'AB_MCP_Sync_Run::update_step'          => array( array( 'period.clock' ), array( self::RUN ) ),
		'AB_MCP_Sync_Schema::backup_rows'       => array( array( 'period.clock', 'retention.now', 'runs.forward_closed_at', 'runs.keep_until', 'runs.plan', 'runs.run_id', 'runs.state', 'runs.type' ), array( self::UNIT, self::CLK ) ),
		'AB_MCP_Sync_Schema::keep_until'        => array( array( 'period.clock', 'period.days', 'period.qualified' ), array( self::UNIT ) ),
		'AB_MCP_Sync_Schema::open_runs'         => array( array( 'runs.open_ids' ), array( self::UNIT ) ),
	);

	private static function root(): string {
		return dirname( __DIR__ ) . '/';
	}

	/**
	 * The time() of files that the list of exceptions does not name.
	 *
	 * @param array<string,string> $sources Path => source.
	 * @return string[] path:line function
	 */
	private static function raw_times( array $sources ): array {
		$out = array();
		foreach ( $sources as $file => $code ) {
			foreach ( SourceScan::scan( $code )['time'] as $hit ) {
				if ( ! isset( self::TIME[ $file ][ $hit[0] ] ) ) {
					$out[] = $file . ':' . $hit[1] . ' ' . $hit[0];
				}
			}
		}
		return $out;
	}

	/** @return array<string,string> */
	private static function sources(): array {
		$out = array();
		foreach ( self::FILES as $file ) {
			$out[ $file ] = (string) file_get_contents( self::root() . $file );
		}
		return $out;
	}

	public function testNoTimeButTheOneClockAndTheNamedExceptions(): void {
		$sources = self::sources();
		self::assertSame( array(), self::raw_times( $sources ), 'A time() beside the clock: read AB_MCP_Sync_Run::now() instead, or name it with its reason.' );
		// Every exception is still there, with its reason.
		$found = array();
		foreach ( $sources as $file => $code ) {
			foreach ( SourceScan::scan( $code )['time'] as $hit ) {
				$found[ $file ][ $hit[0] ] = true;
			}
		}
		foreach ( self::TIME as $file => $named ) {
			foreach ( $named as $fn => $why ) {
				self::assertNotSame( '', trim( $why ), "$fn: why" );
				self::assertArrayHasKey( $fn, $found[ $file ] ?? array(), "$file: $fn names a time() that is gone; take it out of the list." );
			}
		}
	}

	public function testTheFilesOfBranchFourMeasureACallOnlyWithTheNamedClocksOfBudgets(): void {
		$sources = self::sources();
		$found   = array();
		$raw     = array();
		foreach ( self::BUDGET as $file => $named ) {
			$code = $sources[ $file ] ?? (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
			foreach ( SourceScan::scan( $code )['budget'] as $hit ) {
				$found[ $file ][ $hit[0] ] = true;
				if ( ! isset( $named[ $hit[0] ] ) ) {
					$raw[] = $file . ':' . $hit[1] . ' ' . $hit[0];
				}
			}
			foreach ( $named as $fn => $why ) {
				self::assertNotSame( '', trim( $why ), "$fn: why" );
				self::assertArrayHasKey( $fn, $found[ $file ] ?? array(), "$file: $fn names a clock of a budget that is gone; take it out of the list." );
			}
		}
		self::assertSame( array(), $raw, 'A clock of a budget beside the named ones: measure through the named clock, or name it with its reason.' );
		$wrap = static fn( string $body ): string => "<?php\nclass AB_MCP_X {\n\tpublic static function f() {\n\t\t$body\n\t}\n}\n";
		foreach ( array( 'return microtime( true );', 'return \\hrtime( true );', 'return MICROTIME();' ) as $body ) {
			self::assertSame( array( array( 'AB_MCP_X::f', 4 ) ), SourceScan::scan( $wrap( $body ) )['budget'], $body );
		}
		self::assertSame( array(), SourceScan::scan( $wrap( 'return $o->microtime();' ) )['budget'] );
	}

	public function testTheScanFindsEverySpellingOfACallOfTimeAndNoOther(): void {
		$wrap  = static fn( string $body ): string => "<?php\nclass AB_MCP_X {\n\tpublic static function f() {\n\t\t$body\n\t}\n}\n";
		$calls = array( 'return time();', 'return \\time();', 'return TIME();', 'return time ( );', "return time\n(\n);", 'return 1 + time() - 2;', 'return array_map( static fn() => time(), array( 1 ) );' );
		foreach ( $calls as $body ) {
			self::assertCount( 1, SourceScan::scan( $wrap( $body ) )['time'], $body );
			self::assertSame( 'AB_MCP_X::f', SourceScan::scan( $wrap( $body ) )['time'][0][0], $body );
		}
		$none = array( 'return $o->time();', 'return $o?->time();', 'return B::time();', "return 'time()';", '// time()', '/* time() */', 'return $time;', 'return microtime( true );', 'return new time();' );
		foreach ( $none as $body ) {
			self::assertSame( array(), SourceScan::scan( $wrap( $body ) )['time'], $body );
		}
		self::assertSame( array(), SourceScan::scan( "<?php\nfunction time() {}\n" )['time'], 'A function of that name is no call.' );
	}

	public function testATimeSmuggledIntoAFileOfTheStoreIsRed(): void {
		$sources = self::sources();
		$file    = 'includes/class-protected-store.php';
		$anchor  = 'public static function cleanup( $now = null ) {';
		self::assertStringContainsString( $anchor, $sources[ $file ] );
		foreach ( array( '$t = time();', '$t = \\time();', '$t = time ( );' ) as $line ) {
			$changed          = $sources;
			$changed[ $file ] = str_replace( $anchor, $anchor . "\n\t\t" . $line, $sources[ $file ] );
			self::assertSame( array( $file . ':' . ( 1 + substr_count( strstr( $sources[ $file ], $anchor, true ), "\n" ) + 1 ) . ' AB_MCP_Protected_Store::cleanup' ), self::raw_times( $changed ), $line );
		}
	}

	/**
	 * The readers of the code that READERS does not name as they are, and
	 * those it names that are gone.
	 *
	 * @param array<string,string[]> $readers SourceScan::readers().
	 * @return string[]
	 */
	private static function undriven( array $readers ): array {
		$out = array();
		foreach ( $readers as $fn => $rows ) {
			if ( ! isset( self::READERS[ $fn ] ) ) {
				$out[] = "$fn reads " . implode( ', ', $rows ) . ' and has no driver here';
			} elseif ( self::READERS[ $fn ][0] !== $rows ) {
				$out[] = "$fn reads " . implode( ', ', $rows ) . ', its entry names ' . implode( ', ', self::READERS[ $fn ][0] );
			}
		}
		foreach ( array_keys( self::READERS ) as $fn ) {
			if ( ! isset( $readers[ $fn ] ) ) {
				$out[] = "$fn reads no row any more; take it out";
			}
		}
		return $out;
	}

	/** @return string[] */
	private static function includes(): array {
		$out  = array();
		$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . 'includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $walk as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$out[] = $file->getPathname();
			}
		}
		sort( $out );
		return $out;
	}

	public function testEveryReaderOfARowOrOfTheClockIsDrivenOrNamed(): void {
		$readers = SourceScan::readers( self::includes() );
		self::assertGreaterThan( 30, count( $readers ), 'The scan finds the readers.' );
		self::assertSame( array(), self::undriven( $readers ) );
		$rows  = array_keys( \AB_MCP_Store_Inputs::TABLE );
		$tests = array();
		foreach ( self::READERS as $fn => $entry ) {
			foreach ( $entry[0] as $row ) {
				self::assertTrue( in_array( rtrim( $row, '*' ), $rows, true ) || ( '*' === substr( $row, -1 ) && array() !== preg_grep( '/^' . preg_quote( rtrim( $row, '*' ), '/' ) . '/', $rows ) ), "$fn: $row is a row of the table" );
			}
			self::assertTrue( array() !== $entry[1] || ( isset( $entry[2] ) && '' !== trim( $entry[2] ) ), "$fn: driven, or named with why not" );
			foreach ( $entry[1] as $test ) {
				list( $file, $method ) = explode( '::', $test );
				if ( ! isset( $tests[ $file ] ) ) {
					$tests[ $file ] = SourceScan::scan( (string) file_get_contents( self::root() . $file ) )['functions'];
				}
				self::assertContains( $method, $tests[ $file ], "$fn: its driver $test is there" );
			}
		}
	}

	public function testANewReaderWithoutItsDriverIsRed(): void {
		$readers = SourceScan::readers( self::includes() );
		$file    = tempnam( sys_get_temp_dir(), 'ab-scan' );
		file_put_contents( $file, "<?php\nfinal class AB_MCP_New {\n\tpublic static function limit() {\n\t\treturn AB_MCP_Store_Inputs::read( 'budget.free', 5 );\n\t}\n\tpublic static function when() {\n\t\treturn \\AB_MCP_Sync_Run::now();\n\t}\n}\n" );
		$more = SourceScan::readers( array( $file ) );
		unlink( $file );
		self::assertSame( array( 'AB_MCP_New::limit' => array( 'budget.free' ), 'AB_MCP_New::when' => array( 'period.clock' ) ), $more );
		self::assertSame(
			array( 'AB_MCP_New::limit reads budget.free and has no driver here', 'AB_MCP_New::when reads period.clock and has no driver here' ),
			self::undriven( $readers + $more )
		);
		// A reader that reads one more row than its entry names is red too.
		$readers['AB_MCP_Store_Budget::release'][] = 'budget.free';
		self::assertNotSame( array(), self::undriven( $readers ) );
	}
}
