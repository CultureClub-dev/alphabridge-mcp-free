<?php
/**
 * The helpers of the steps of a reading (AB_MCP_Sync_Step, R12b part 3.1;
 * the order belege/auftrag-teil-r12b.txt, sections 3.1 and 6.2 point 1),
 * once per method from one data provider (methods()), without WordPress:
 *
 * - in time the same result as the direct call (and the same effect where a
 *   test sees one: the sorted array, the context hashed or inflated, the
 *   option read, the query of $wpdb), the check and the step told to the
 *   seam, the clock read once and no gate (DeadlineClock, variant T1);
 * - without a deadline (null) the same, without a reading of the clock;
 * - over at its own check (DeadlineClock::late_at_step(): the halt in the
 *   helper, set on purpose): the stop in full, the step not run (no step of
 *   the seam, the effect untouched), and asked again the same stop without
 *   a reading of the clock;
 * - a value that is no deadline: ab_mcp_read_deadline in full, no step, no
 *   reading of the clock;
 * - the first stop stays: the reason of a reader held before (halt()) is
 *   the answer while the clock is over too, without a reading of it.
 *
 * The list is closed both ways: each public method of the class is a case
 * here and each case a method; each is static, takes the deadline first and
 * has no default value. A step the helper ran with no effect a test sees
 * (the disk, the codecs that return their value) is shown by the seam and
 * the result only.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Deadline;
use AB_MCP_Sync_Step;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use WP_Error;

require_once __DIR__ . '/site-info-stubs.php';
require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/uninstall-stubs.php';
require_once __DIR__ . '/../includes/class-store-inputs.php';
require_once __DIR__ . '/../includes/sync/class-sync-deadline.php';
// The clock of the budget (AB_MCP_Sync_Env::$clock), which DeadlineClock sets.
require_once __DIR__ . '/../includes/sync/class-sync-env.php';
require_once __DIR__ . '/../includes/sync/class-sync-step.php';
require_once __DIR__ . '/DeadlineClock.php';

/**
 * The global $wpdb of a test: each query it is asked, the answer a text of it.
 */
final class SyncStepWpdb {

	/** @var array[] The arguments of each call of get_var(), in order. */
	public $asked = array();

	/**
	 * @param mixed ...$args The query and its place.
	 * @return string
	 */
	public function get_var( ...$args ) {
		$this->asked[] = $args;
		return 'value of ' . $args[0];
	}
}

final class SyncStepTest extends TestCase {

	/** The deadline is over (ab_mcp_read_late), in full. */
	const LATE_TEXT = 'The time this call had for its reading ran out before the reading was done, so the reading stopped before its next step and what it read is not used. Call again; where it keeps failing, ask the host for a longer time limit of PHP (max_execution_time).';

	/** A deadline that is not one (ab_mcp_read_deadline), in full. */
	const DEADLINE_TEXT = 'The time limit this reading was given is not a time (a finite number of seconds), so nothing was read. This is a fault of the code that asked for the reading, not of the site: call again, and where it keeps failing, update AlphaBridge MCP or report it to its makers.';

	/** The option the case get_option reads. */
	const OPTION = 'ab_mcp_step_test';

	/** @var string A folder of this test: a file f.txt and a link l to it. */
	private static $dir = '';

	/** @var mixed The global $wpdb before the test. */
	private $wpdb = null;

	protected function setUp(): void {
		ab_test_reset();
		DeadlineClock::restore();
		self::$dir = sys_get_temp_dir() . '/ab-mcp-step-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( self::$dir, 0700 );
		file_put_contents( self::$dir . '/f.txt', 'abc' );
		symlink( self::$dir . '/f.txt', self::$dir . '/l' );
		$GLOBALS['ab_test_options'][ self::OPTION ] = array( 'kept' => true );
		$this->wpdb = null;
		if ( isset( $GLOBALS['wpdb'] ) ) {
			$this->wpdb = $GLOBALS['wpdb'];
		}
	}

	protected function tearDown(): void {
		DeadlineClock::restore();
		$GLOBALS['wpdb'] = $this->wpdb;
		unlink( self::$dir . '/l' );
		unlink( self::$dir . '/f.txt' );
		rmdir( self::$dir );
	}

	/** @return array<string,array{string}> Each method of the helpers. */
	public static function methods(): array {
		$out = array();
		foreach ( array_keys( self::ways() ) as $name ) {
			$out[ $name ] = array( $name );
		}
		return $out;
	}

	/**
	 * Each method: the call of the helper with a deadline (helper), the
	 * direct call (direct), each answering array( result, effect ), and the
	 * effect where the step did not run (untouched; null where a test sees
	 * none).
	 *
	 * @return array<string,array{helper:callable,direct:callable,untouched:mixed}>
	 */
	private static function ways(): array {
		$dir  = self::$dir;
		$file = $dir . '/f.txt';
		$gz   = gzencode( 'abc' );
		$keys = sodium_crypto_sign_seed_keypair( str_repeat( "\1", SODIUM_CRYPTO_SIGN_SEEDBYTES ) );
		$sig  = sodium_crypto_sign_detached( 'message', sodium_crypto_sign_secretkey( $keys ) );
		$pub  = sodium_crypto_sign_publickey( $keys );
		$cmp  = static function ( $a, $b ) {
			return $a <=> $b;
		};
		return array(
			// The disk: a folder is no file but readable; the link and its target differ for lstat() and stat().
			'is_file'                            => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::is_file( $d, $dir ), null ),
				'direct'    => static fn() => array( is_file( $dir ), null ),
				'untouched' => null,
			),
			'is_readable'                        => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::is_readable( $d, $dir ), null ),
				'direct'    => static fn() => array( is_readable( $dir ), null ),
				'untouched' => null,
			),
			'filesize'                           => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::filesize( $d, $file ), null ),
				'direct'    => static fn() => array( filesize( $file ), null ),
				'untouched' => null,
			),
			'file_get_contents'                  => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::file_get_contents( $d, $file, false, null, 1, 1 ), null ),
				'direct'    => static fn() => array( file_get_contents( $file, false, null, 1, 1 ), null ),
				'untouched' => null,
			),
			'scandir'                            => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::scandir( $d, $dir, SCANDIR_SORT_DESCENDING ), null ),
				'direct'    => static fn() => array( scandir( $dir, SCANDIR_SORT_DESCENDING ), null ),
				'untouched' => null,
			),
			'lstat'                              => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::lstat( $d, $dir . '/l' ), null ),
				'direct'    => static fn() => array( lstat( $dir . '/l' ), null ),
				'untouched' => null,
			),
			'stat'                               => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::stat( $d, $dir . '/l' ), null ),
				'direct'    => static fn() => array( stat( $dir . '/l' ), null ),
				'untouched' => null,
			),
			'realpath'                           => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::realpath( $d, $dir . '/./l' ), null ),
				'direct'    => static fn() => array( realpath( $dir . '/./l' ), null ),
				'untouched' => null,
			),
			// The database: the option counts its readings (tests/bootstrap.php), $wpdb its queries.
			'get_option'                         => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::get_option( $d, self::OPTION, 'none' ), self::option_reads() ),
				'direct'    => static fn() => array( get_option( self::OPTION, 'none' ), self::option_reads() ),
				'untouched' => 0,
			),
			'home_url'                           => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::home_url( $d, '/x' ), null ),
				'direct'    => static fn() => array( home_url( '/x' ), null ),
				'untouched' => null,
			),
			'admin_url'                          => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::admin_url( $d, 'admin.php?page=x' ), null ),
				'direct'    => static fn() => array( admin_url( 'admin.php?page=x' ), null ),
				'untouched' => null,
			),
			'wp_upload_dir'                      => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::wp_upload_dir( $d, null, false ), null ),
				'direct'    => static fn() => array( wp_upload_dir( null, false ), null ),
				'untouched' => null,
			),
			'wpdb_get_var'                       => array(
				'helper'    => static function ( $d ) {
					$GLOBALS['wpdb'] = new SyncStepWpdb();
					return array( AB_MCP_Sync_Step::wpdb_get_var( $d, 'SELECT 1', 0 ), $GLOBALS['wpdb']->asked );
				},
				'direct'    => static function () {
					$GLOBALS['wpdb'] = new SyncStepWpdb();
					return array( $GLOBALS['wpdb']->get_var( 'SELECT 1', 0 ), $GLOBALS['wpdb']->asked );
				},
				'untouched' => array(),
			),
			// The codecs; a context shows what the step did to it.
			'hash'                               => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::hash( $d, 'sha256', 'abc', true ), null ),
				'direct'    => static fn() => array( hash( 'sha256', 'abc', true ), null ),
				'untouched' => null,
			),
			'hash_init'                          => array(
				'helper'    => static fn( $d ) => array( self::finished( AB_MCP_Sync_Step::hash_init( $d, 'sha1' ) ), null ),
				'direct'    => static fn() => array( self::finished( hash_init( 'sha1' ) ), null ),
				'untouched' => null,
			),
			'hash_update'                        => array(
				'helper'    => static function ( $d ) {
					$context = hash_init( 'sha256' );
					return array( AB_MCP_Sync_Step::hash_update( $d, $context, 'abc' ), hash_final( $context ) );
				},
				'direct'    => static function () {
					$context = hash_init( 'sha256' );
					return array( hash_update( $context, 'abc' ), hash_final( $context ) );
				},
				'untouched' => hash( 'sha256', '' ),
			),
			'hash_final'                         => array(
				'helper'    => static function ( $d ) {
					$context = hash_init( 'sha256' );
					hash_update( $context, 'abc' );
					return array( AB_MCP_Sync_Step::hash_final( $d, $context, true ), self::finished( $context ) );
				},
				'direct'    => static function () {
					$context = hash_init( 'sha256' );
					hash_update( $context, 'abc' );
					return array( hash_final( $context, true ), self::finished( $context ) );
				},
				'untouched' => hash( 'sha256', 'abc' ),
			),
			'sha1'                               => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::sha1( $d, 'abc' ), null ),
				'direct'    => static fn() => array( sha1( 'abc' ), null ),
				'untouched' => null,
			),
			'json_decode'                        => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::json_decode( $d, '{"a":[1,2.5]}', true, 8 ), null ),
				'direct'    => static fn() => array( json_decode( '{"a":[1,2.5]}', true, 8 ), null ),
				'untouched' => null,
			),
			'json_encode'                        => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::json_encode( $d, array( 'a' => '/é', 'b' => 1.0 ), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), null ),
				'direct'    => static fn() => array( json_encode( array( 'a' => '/é', 'b' => 1.0 ), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), null ),
				'untouched' => null,
			),
			'wp_json_encode'                     => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::wp_json_encode( $d, array( 'a' => '/é' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), null ),
				'direct'    => static fn() => array( wp_json_encode( array( 'a' => '/é' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), null ),
				'untouched' => null,
			),
			'maybe_unserialize'                  => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::maybe_unserialize( $d, 'a:1:{s:1:"a";i:1;}' ), null ),
				'direct'    => static fn() => array( maybe_unserialize( 'a:1:{s:1:"a";i:1;}' ), null ),
				'untouched' => null,
			),
			'base64_encode'                      => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::base64_encode( $d, "\0\xff" ), null ),
				'direct'    => static fn() => array( base64_encode( "\0\xff" ), null ),
				'untouched' => null,
			),
			'base64_decode'                      => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::base64_decode( $d, 'AP8=', true ), null ),
				'direct'    => static fn() => array( base64_decode( 'AP8=', true ), null ),
				'untouched' => null,
			),
			'bin2hex'                            => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::bin2hex( $d, "\0\xff" ), null ),
				'direct'    => static fn() => array( bin2hex( "\0\xff" ), null ),
				'untouched' => null,
			),
			'hex2bin'                            => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::hex2bin( $d, '00ff' ), null ),
				'direct'    => static fn() => array( hex2bin( '00ff' ), null ),
				'untouched' => null,
			),
			'pack'                               => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::pack( $d, 'En', 1.5, 7 ), null ),
				'direct'    => static fn() => array( pack( 'En', 1.5, 7 ), null ),
				'untouched' => null,
			),
			'gzdecode'                           => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::gzdecode( $d, $gz, 10 ), null ),
				'direct'    => static fn() => array( gzdecode( $gz, 10 ), null ),
				'untouched' => null,
			),
			'inflate_init'                       => array(
				'helper'    => static fn( $d ) => array( self::inflated( AB_MCP_Sync_Step::inflate_init( $d, ZLIB_ENCODING_GZIP ), $gz ), null ),
				'direct'    => static fn() => array( self::inflated( inflate_init( ZLIB_ENCODING_GZIP ), $gz ), null ),
				'untouched' => null,
			),
			'inflate_add'                        => array(
				'helper'    => static function ( $d ) use ( $gz ) {
					$context = inflate_init( ZLIB_ENCODING_GZIP );
					return array( AB_MCP_Sync_Step::inflate_add( $d, $context, $gz, ZLIB_FINISH ), inflate_get_status( $context ) );
				},
				'direct'    => static function () use ( $gz ) {
					$context = inflate_init( ZLIB_ENCODING_GZIP );
					return array( inflate_add( $context, $gz, ZLIB_FINISH ), inflate_get_status( $context ) );
				},
				'untouched' => inflate_get_status( inflate_init( ZLIB_ENCODING_GZIP ) ),
			),
			'sodium_crypto_sign_verify_detached' => array(
				'helper'    => static fn( $d ) => array( AB_MCP_Sync_Step::sodium_crypto_sign_verify_detached( $d, $sig, 'message', $pub ), null ),
				'direct'    => static fn() => array( sodium_crypto_sign_verify_detached( $sig, 'message', $pub ), null ),
				'untouched' => null,
			),
			// The sorts: the array shows whether the step ran.
			'sort'                               => array(
				'helper'    => static function ( $d ) {
					$list = array( 'b', 'a', '10', '9' );
					return array( AB_MCP_Sync_Step::sort( $d, $list, SORT_STRING ), $list );
				},
				'direct'    => static function () {
					$list = array( 'b', 'a', '10', '9' );
					return array( sort( $list, SORT_STRING ), $list );
				},
				'untouched' => array( 'b', 'a', '10', '9' ),
			),
			'ksort'                              => array(
				'helper'    => static function ( $d ) {
					$map = array( 'b' => 1, 'a' => 2, '10' => 3, '9' => 4 );
					return array( AB_MCP_Sync_Step::ksort( $d, $map, SORT_STRING ), $map );
				},
				'direct'    => static function () {
					$map = array( 'b' => 1, 'a' => 2, '10' => 3, '9' => 4 );
					return array( ksort( $map, SORT_STRING ), $map );
				},
				'untouched' => array( 'b' => 1, 'a' => 2, '10' => 3, '9' => 4 ),
			),
			'usort'                              => array(
				'helper'    => static function ( $d ) use ( $cmp ) {
					$list = array( 3, 1, 2 );
					return array( AB_MCP_Sync_Step::usort( $d, $list, $cmp ), $list );
				},
				'direct'    => static function () use ( $cmp ) {
					$list = array( 3, 1, 2 );
					return array( usort( $list, $cmp ), $list );
				},
				'untouched' => array( 3, 1, 2 ),
			),
		);
	}

	/** @return int How often the option of the case get_option was read. */
	private static function option_reads(): int {
		if ( isset( $GLOBALS['ab_test_option_reads'][ self::OPTION ] ) ) {
			return $GLOBALS['ab_test_option_reads'][ self::OPTION ];
		}
		return 0;
	}

	/**
	 * What a context of hash_init() gives at its end, or 'finished' where it
	 * was finished before; anything else as it is (a stop).
	 *
	 * @param mixed $context A context.
	 * @return mixed
	 */
	private static function finished( $context ) {
		if ( ! $context instanceof \HashContext ) {
			return $context;
		}
		try {
			return hash_final( $context );
		} catch ( \Error $e ) {
			return 'finished';
		}
	}

	/**
	 * What a context of inflate_init() makes of a gzip member; anything else
	 * as it is (a stop).
	 *
	 * @param mixed  $context A context.
	 * @param string $gz      The member.
	 * @return mixed
	 */
	private static function inflated( $context, string $gz ) {
		if ( ! $context instanceof \InflateContext ) {
			return $context;
		}
		return inflate_add( $context, $gz, ZLIB_FINISH );
	}

	/**
	 * @param mixed  $value What a helper answered.
	 * @param string $code  The code of the stop.
	 * @param string $text  Its text, in full.
	 */
	private static function assertRefused( $value, string $code, string $text, string $message ): void {
		self::assertInstanceOf( WP_Error::class, $value, $message );
		self::assertSame( $code, $value->get_error_code(), $message );
		self::assertSame( $text, $value->get_error_message(), $message );
	}

	/**
	 * The steps the seam saw run, without their callers.
	 *
	 * @return string[]
	 */
	private static function ran( DeadlineClock $clock ): array {
		$out = array();
		foreach ( $clock->events as $event ) {
			self::assertStringStartsWith( 'step: ', $event, 'Only steps: a helper is no gate.' );
			$out[] = substr( $event, 6, strpos( $event, ' (' ) - 6 );
		}
		return $out;
	}

	/**
	 * The steps told by the seam as checked, or read by a check, without
	 * their callers.
	 *
	 * @param string[] $list checks or step_reads.
	 * @return string[]
	 */
	private static function steps( array $list ): array {
		$out = array();
		foreach ( $list as $at ) {
			$out[] = substr( $at, 0, strpos( $at, ' (' ) );
		}
		return $out;
	}

	/* ------------------------------------------------- once per method */

	#[DataProvider( 'methods' )]
	public function testInTimeTheHelperAnswersAsTheDirectCall( string $name ): void {
		$way    = self::ways()[ $name ];
		$direct = ( $way['direct'] )();
		$GLOBALS['ab_test_option_reads'] = array();
		$clock = DeadlineClock::in_time()->install();
		$have  = ( $way['helper'] )( DeadlineClock::deadline() );
		self::assertSame( $direct, $have, 'The result and the effect of the direct call, unchanged.' );
		self::assertSame( array( $name ), self::steps( $clock->checks ), 'Checked once.' );
		self::assertSame( array( $name ), self::steps( $clock->step_reads ), 'The clock read once, by the check.' );
		self::assertSame( array( $name ), self::ran( $clock ), 'The step ran once, after the check.' );
		self::assertSame( array( array(), 0 ), array( $clock->gates, $clock->others ), 'No gate, no other reading.' );
	}

	#[DataProvider( 'methods' )]
	public function testWithoutADeadlineTheHelperIsTheBareCallWithoutAReadingOfTheClock( string $name ): void {
		$way    = self::ways()[ $name ];
		$direct = ( $way['direct'] )();
		$GLOBALS['ab_test_option_reads'] = array();
		$clock = DeadlineClock::in_time()->install();
		$have  = ( $way['helper'] )( null );
		self::assertSame( $direct, $have, 'The bare call.' );
		self::assertSame( array( $name ), self::ran( $clock ), 'The step ran.' );
		self::assertSame( array( array(), array(), 0 ), array( $clock->step_reads, $clock->gates, $clock->others ), 'No reading of the clock.' );
	}

	#[DataProvider( 'methods' )]
	public function testOverAtItsCheckTheHelperAnswersTheStopRunsNoStepAndAsksTheClockNoMore( string $name ): void {
		$way      = self::ways()[ $name ];
		$clock    = DeadlineClock::late_at_step( 1 )->install();
		$deadline = DeadlineClock::deadline();
		$have     = ( $way['helper'] )( $deadline );
		self::assertRefused( $have[0], 'ab_mcp_read_late', self::LATE_TEXT, 'The stop of its own check.' );
		self::assertSame( $way['untouched'], $have[1], 'The step did not run.' );
		self::assertSame( array(), $clock->events, 'No step: a call refused is no step run.' );
		self::assertSame( array( $name ), self::steps( $clock->step_reads ), 'The clock read once.' );
		$again = ( $way['helper'] )( $deadline );
		self::assertSame( $have[0], $again[0], 'The stop held, the same.' );
		self::assertSame( $way['untouched'], $again[1], 'Again no step.' );
		self::assertSame( array( $name, $name ), self::steps( $clock->checks ), 'Checked twice.' );
		self::assertSame( array( $name ), self::steps( $clock->step_reads ), 'After the stop no reading of the clock.' );
		self::assertSame( array( array(), array(), 0 ), array( $clock->events, $clock->gates, $clock->others ) );
		self::assertSame( $have[0], AB_MCP_Sync_Deadline::stop( $deadline ), 'The stop of the deadline.' );
	}

	#[DataProvider( 'methods' )]
	public function testAValueThatIsNoDeadlineIsRefusedWithoutAStepOrAReadingOfTheClock( string $name ): void {
		$way   = self::ways()[ $name ];
		$clock = DeadlineClock::in_time()->install();
		foreach ( array( DeadlineClock::UNTIL, 'soon' ) as $value ) {
			$have = ( $way['helper'] )( $value );
			self::assertRefused( $have[0], 'ab_mcp_read_deadline', self::DEADLINE_TEXT, gettype( $value ) );
			self::assertSame( $way['untouched'], $have[1], 'The step did not run: ' . gettype( $value ) );
		}
		self::assertSame( array( $name, $name ), self::steps( $clock->checks ) );
		self::assertSame( array( array(), array(), array(), 0 ), array( $clock->events, $clock->step_reads, $clock->gates, $clock->others ), 'No step and no reading of the clock.' );
	}

	#[DataProvider( 'methods' )]
	public function testTheFirstStopStaysWithoutAReadingOfTheClock( string $name ): void {
		$way      = self::ways()[ $name ];
		$clock    = DeadlineClock::late_at_step( 1 )->install();
		$deadline = DeadlineClock::deadline();
		$why      = new WP_Error( 'ab_mcp_store_undecided', 'why' );
		self::assertSame( $why, AB_MCP_Sync_Deadline::halt( $deadline, $why ) );
		$have = ( $way['helper'] )( $deadline );
		self::assertSame( $why, $have[0], 'The reason of the reader held first, not the clock over.' );
		self::assertSame( $way['untouched'], $have[1], 'The step did not run.' );
		self::assertSame( $why, ( $way['helper'] )( $deadline )[0], 'Still the first stop.' );
		self::assertSame( array( array(), array(), array(), 0 ), array( $clock->events, $clock->step_reads, $clock->gates, $clock->others ), 'No step and no reading of the clock.' );
	}

	/* ------------------------------------------------------- the class */

	public function testTheMethodsAreTheClosedListOfTheCases(): void {
		$class   = new ReflectionClass( AB_MCP_Sync_Step::class );
		$methods = array();
		foreach ( $class->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			$methods[] = $method->getName();
			self::assertTrue( $method->isStatic(), $method->getName() );
			$params = $method->getParameters();
			self::assertSame( 'deadline', $params[0]->getName(), $method->getName() . ': the deadline first.' );
			foreach ( $params as $param ) {
				self::assertFalse( $param->isDefaultValueAvailable(), $method->getName() . ': no default value ($' . $param->getName() . ').' );
			}
		}
		$cases = array_keys( self::ways() );
		sort( $methods );
		sort( $cases );
		self::assertSame( $cases, $methods, 'Each public method is a case here, and each case a method.' );
		self::assertTrue( $class->isFinal() );
		self::assertNull( AB_MCP_Sync_Step::$watch, 'No seam outside a test.' );
	}

	public function testTheClockKeepsTheCheckOfAHelperAsAStepOfItsCallerAndItsHaltOnPurpose(): void {
		$clock    = DeadlineClock::in_time()->install();
		$deadline = DeadlineClock::deadline();
		self::assertTrue( AB_MCP_Sync_Deadline::left( $deadline ) );
		self::assertSame( 3, self::via( $deadline ) );
		self::assertTrue( AB_MCP_Sync_Deadline::left( $deadline ) );
		$at = 'filesize (' . self::class . '::via)';
		self::assertSame( array( $at ), $clock->checks, 'The check by its caller.' );
		self::assertSame( array( $at ), $clock->step_reads );
		self::assertCount( 2, $clock->gates, 'The two gates of the test, the helper none of them.' );
		self::assertSame( array( $clock->gates[0], 'step: ' . $at, $clock->gates[1] ), $clock->events, 'The step in order with the gates.' );
		self::assertSame( array( $clock->gates[0] ), $clock->until_gate( 1 ) );

		// The halt in the second helper, on purpose: from then on every reading of a deadline is over.
		$clock    = DeadlineClock::late_at_step( 2 )->install();
		$deadline = DeadlineClock::deadline();
		self::assertSame( 3, self::via( $deadline ) );
		self::assertTrue( AB_MCP_Sync_Deadline::left( $deadline ), 'A gate between: in time.' );
		$stop = self::via( $deadline );
		self::assertRefused( $stop, 'ab_mcp_read_late', self::LATE_TEXT, 'The second helper.' );
		self::assertSame( $stop, AB_MCP_Sync_Deadline::left( $deadline ), 'The stop held: no reading.' );
		self::assertRefused( AB_MCP_Sync_Deadline::left( DeadlineClock::deadline() ), 'ab_mcp_read_late', self::LATE_TEXT, 'A gate of another deadline after it: over.' );
		self::assertCount( 2, $clock->gates );
		self::assertSame( array( 'step: ' . $at, $clock->gates[0], $clock->gates[1] ), $clock->events, 'The refused call is no step.' );

		$clock = DeadlineClock::unknown_at_step( 1 )->install();
		self::assertRefused( self::via( DeadlineClock::deadline() ), 'ab_mcp_read_clock', 'The time of this call could not be measured during its reading, so the reading stopped before its next step and what it read is not used. Call again.', 'The clock unknown at the helper.' );
		self::assertSame( array(), $clock->events );
	}

	/**
	 * A reader of the test that calls a helper.
	 *
	 * @param mixed $deadline The deadline.
	 * @return mixed
	 */
	private static function via( $deadline ) {
		return AB_MCP_Sync_Step::filesize( $deadline, self::$dir . '/f.txt' );
	}
}
