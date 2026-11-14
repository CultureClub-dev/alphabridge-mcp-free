<?php
/**
 * The guard of the read budget (R12b, part 2; the order belege/
 * auftrag-teil-r12b.txt, section 3.2): outside the chain of the check of
 * names every read from the disk, step of processing and pass of a loop on
 * the ways of R12 begins only after the one deadline is checked right
 * before it. The guard measures this on the source, without control flow.
 *
 * Part 2.1 is its reader and its basis: the reader
 * (tests/ReadBudgetScan.php) reads every way of R12 without a problem, and
 * what it counts there is what the lists of tests/ReadBudgetSoll.php say,
 * both ways, each entry used: G1 the functions outside the chain, the chain,
 * the boundaries and cut edges met, G2 the steps, G3 the loops, G4 the
 * recursions, G5 the entries into the chain, G6 the dynamic calls, G7 the
 * checks; and the steps are the closed list of the order (section 1).
 *
 * Part 2.2 are the rules R1 to R7 over this basis (tests/ReadBudgetRules.php)
 * with their lists (tests/ReadBudgetExceptions.php): each violation at the
 * head is an open exception of OPEN with its number and reason, both ways;
 * the named boundaries are the separate, closed list NAMED; and each
 * detection of a rule has its self-test on code of its own (section 3.2 (i)
 * and (ii) of the order).
 *
 * A change of the code on these ways turns this red until the lists are kept
 * by hand, with the reason.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ReadBudgetScan.php';
require_once __DIR__ . '/ReadBudgetRules.php';

final class ReadBudgetGuardTest extends TestCase {

	/**
	 * The steps (the order, section 1, «Was „Schritt“ heisst», closed): kind =>
	 * the functions of PHP and WordPress. A new one comes only with an entry
	 * here and in KIND.
	 */
	private const STEPS = array(
		'Platte'     => array( 'file_get_contents', 'filesize', 'fopen', 'fread', 'fstat', 'is_file', 'is_readable', 'lstat', 'realpath', 'scandir', 'stat' ),
		'Codec'      => array( 'WP_HTML_Decoder::decode_attribute', 'WP_HTML_Decoder::decode_text_node', 'base64_decode', 'base64_encode', 'bin2hex', 'gzdecode', 'hash', 'hash_final', 'hash_init', 'hash_update', 'hex2bin', 'iconv', 'idn_to_ascii', 'inflate_add', 'inflate_init', 'json_decode', 'json_encode', 'maybe_unserialize', 'mb_check_encoding', 'mb_convert_encoding', 'pack', 'rawurldecode', 'sha1', 'sodium_crypto_sign_verify_detached', 'stripcslashes', 'unserialize', 'wp_json_encode' ),
		'Sortierung' => array( 'ksort', 'sort', 'usort' ),
		'Datenbank'  => array( 'admin_url', 'get_option', 'home_url', 'wp_upload_dir', 'wpdb->get_var', 'wpdb->query' ),
	);

	/** @var ?array<string,mixed> What the reader read (once). */
	private static ?array $model = null;

	/** @var ?array<string,mixed> What the rules found (once). */
	private static ?array $rules = null;

	/** @return array<string,mixed> */
	private static function soll(): array {
		return require __DIR__ . '/ReadBudgetSoll.php';
	}

	/** @return array<string,mixed> */
	private static function model(): array {
		if ( null === self::$model ) {
			self::$model = ReadBudgetScan::read( ReadBudgetScan::sources( dirname( __DIR__ ) ), self::soll()['HAND'] );
		}
		return self::$model;
	}

	/** @return array<string,mixed> */
	private static function basis(): array {
		return ReadBudgetScan::basis( self::model(), self::soll()['HAND'] );
	}

	/** @return array<string,mixed> */
	private static function lists(): array {
		return require __DIR__ . '/ReadBudgetExceptions.php';
	}

	/** @return array<string,mixed> What the rules find at the head (once). */
	private static function rules(): array {
		if ( null === self::$rules ) {
			$soll        = self::soll();
			self::$rules = ReadBudgetRules::check( self::model(), $soll['HAND'], $soll, self::lists() );
		}
		return self::$rules;
	}

	/**
	 * Want and have alike; the message names what only one of them holds.
	 *
	 * @param array<string,mixed> $want The list of tests/ReadBudgetSoll.php.
	 * @param array<string,mixed> $have What the reader counts.
	 */
	private static function same( array $want, array $have, string $what ): void {
		$lines = function ( array $list ): array {
			$out = array();
			foreach ( $list as $k => $v ) {
				if ( ! is_array( $v ) ) {
					$out[] = $k . ' = ' . var_export( $v, true );
					continue;
				}
				foreach ( $v as $i => $x ) {
					$out[] = $k . ' #' . ( $i + 1 ) . ' ' . var_export( $x, true );
				}
			}
			return $out;
		};
		$w    = $lines( $want );
		$h    = $lines( $have );
		$diff = array();
		foreach ( array_diff( $w, $h ) as $x ) {
			$diff[] = 'only in the list: ' . $x;
		}
		foreach ( array_diff( $h, $w ) as $x ) {
			$diff[] = 'only in the code:  ' . $x;
		}
		self::assertSame( array(), $diff, $what . ' (tests/ReadBudgetSoll.php, kept by hand with the reason)' );
		self::assertSame( $want, $have, $what );
	}

	public function testTheReaderReadsEveryWayOfR12WithoutAProblem(): void {
		$model = self::model();
		self::assertSame( array(), $model['problems'], 'Each call resolved, each dynamic call in DYN, each function of PHP or WordPress in KIND, each entry of a hand table used.' );
		self::assertArrayHasKey( ReadBudgetScan::START, $model['reach'] );
		foreach ( self::soll()['HAND']['ROOTS'] as $root ) {
			self::assertArrayHasKey( $root, $model['reach'], 'An entry of R12.' );
			self::assertArrayNotHasKey( $root, $model['chain'], 'An entry of R12 is outside the chain.' );
		}
	}

	public function testTheFunctionsOutsideTheChainAreG1WithTheirRoles(): void {
		$soll = self::soll();
		self::same( $soll['G1'], self::basis()['G1'], 'G1: the functions outside the chain (file::Class::name => role)' );
		foreach ( $soll['G1'] as $function => $role ) {
			self::assertContains( $role, array( 'Rahmen', 'Fristhelfer', 'Weg' ), $function );
		}
	}

	public function testTheChainIsWhatOnlyHeldReaches(): void {
		$soll  = self::soll();
		$basis = self::basis();
		self::assertSame( array_keys( $soll['CHAIN'] ), array_keys( $basis['CHAIN'] ), 'The chain of the check of names: the functions reached only through AB_MCP_Sync_Env::held().' );
		foreach ( $soll['CHAIN'] as $function => $why ) {
			self::assertNotSame( '', $why, $function );
		}
		self::assertSame( array(), array_intersect_key( $soll['CHAIN'], $soll['G1'] ) );
		self::assertArrayHasKey( 'includes/sync/class-sync-env.php::' . ReadBudgetScan::HELD, $soll['CHAIN'] );
	}

	public function testTheBoundariesAndCutEdgesMetAreTheListedOnes(): void {
		$soll  = self::soll();
		$basis = self::basis();
		$want  = array();
		foreach ( $soll['BOUNDS'] as $edge => list( $count, $why ) ) {
			self::assertNotSame( '', $why, $edge );
			$want[ $edge ] = $count;
		}
		self::same( $want, $basis['BOUNDS'], 'The boundaries met (BOUNDARY and includes/lifecycle/): caller -> boundary => sites' );
		self::same( $soll['CUTS'], $basis['CUTS'], 'The cut edges met: caller -> target => sites' );
		foreach ( $soll['HAND']['CUT'] as $edge => $why ) {
			self::assertNotSame( '', $why, $edge );
		}
	}

	public function testTheStepsOutsideTheChainAreG2(): void {
		$soll = self::soll();
		self::same( $soll['G2'], self::basis()['G2'], 'G2: the steps outside the chain, by function in the order of the source' );
		foreach ( $soll['G2'] as $function => $steps ) {
			foreach ( $steps as $step ) {
				self::assertTrue( $soll['HAND']['KIND'][ $step ][1], $function . ': ' . $step . ' is a step.' );
			}
		}
	}

	public function testTheLoopsOutsideTheChainAreG3(): void {
		self::same( self::soll()['G3'], self::basis()['G3'], 'G3: the loops outside the chain, by function in the order of the source (head as read)' );
	}

	public function testTheRecursionsOutsideTheChainAreG4(): void {
		self::same( self::soll()['G4'], self::basis()['G4'], 'G4: the recursions outside the chain, group => its edges' );
	}

	public function testTheEntriesIntoTheChainAreG5AndAllGoIntoHeld(): void {
		$soll = self::soll();
		self::same( $soll['G5'], self::basis()['G5'], 'G5: the entries into the chain' );
		foreach ( $soll['G5'] as $function => $entries ) {
			foreach ( $entries as $entry ) {
				self::assertStringStartsWith( ReadBudgetScan::HELD . ' (', $entry, $function . ': the chain is entered only through held().' );
			}
		}
	}

	public function testTheDynamicCallsOutsideTheChainAreG6WithTheirTargets(): void {
		$soll = self::soll();
		self::same( $soll['G6'], self::basis()['G6'], 'G6: the dynamic calls outside the chain (form -> kind of the target: target)' );
		foreach ( $soll['HAND']['DYN'] as $entry => $dyn ) {
			self::assertContains( $dyn['kind'], array( 'Plugin', 'nativ', 'Closure', 'Uhr' ), $entry );
			self::assertNotSame( '', $dyn['why'], $entry );
			self::assertSame( 'Plugin' === $dyn['kind'], array() !== $dyn['to'], $entry . ': a target of the plugin exactly where the kind is Plugin.' );
			self::assertSame( 'nativ' === $dyn['kind'], isset( $dyn['fn'] ), $entry . ': a function of PHP exactly where the kind is nativ.' );
		}
	}

	public function testTheChecksAreG7(): void {
		self::same( self::soll()['G7'], self::basis()['G7'], 'G7: the checks (CHECK asks the clock, STOP the halt held)' );
	}

	public function testTheStepsAreTheClosedListOfTheOrder(): void {
		$have = array();
		foreach ( self::soll()['HAND']['KIND'] as $name => list( $kind, $step, $why ) ) {
			self::assertContains( $kind, array_merge( ReadBudgetScan::STEP_KINDS, array( 'sonst' ) ), $name );
			self::assertIsBool( $step, $name );
			self::assertNotSame( '', $why, $name );
			if ( $step ) {
				$have[ $kind ][] = $name;
			}
		}
		foreach ( $have as $kind => $names ) {
			sort( $names );
			$have[ $kind ] = $names;
		}
		$want = self::STEPS;
		foreach ( $want as $kind => $names ) {
			sort( $names );
			$want[ $kind ] = $names;
		}
		ksort( $have );
		ksort( $want );
		self::assertSame( $want, $have, 'A step of the order is a step in KIND, and nothing else is.' );
	}

	public function testTheReaderTellsWhatItCannotReadAndCountsAHelperOfTheStepsAsAStep(): void {
		// A way of its own: prepare() reads through the helper of the steps,
		// calls a method by a name in a variable, a function with a
		// namespace and a loop in the alternative syntax.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $o, $m ) {
		$read = AB_MCP_Sync_Step::file_get_contents( $deadline, '/x' );
		$o->$m( $read );
		Other\Space\run( $read );
		foreach ( array( 1 ) as $one ):
			strlen( $read );
		endforeach;
		return strlen( $read );
	}
}
PHP;
		$hand  = array(
			'STEP_CLASS'   => 'AB_MCP_Sync_Step',
			'FRAME'        => array(),
			'NO_CAND'      => array(),
			'ROOTS'        => array(),
			'BOUNDARY'     => array(),
			'HELPERS'      => array(),
			'HELPER_EVENT' => array(),
			'RET'          => array(),
			'INST_BYNAME'  => array(),
			'INST'         => array(),
			'DYN'          => array(),
			'CUT'          => array(),
			'KIND'         => array(
				'file_get_contents' => array( 'Platte', true, 'An access to the file system.' ),
				'strlen'            => array( 'sonst', false, 'A string in memory.' ),
				'array_pop'         => array( 'sonst', false, 'Used by no code here.' ),
			),
		);
		$model = ReadBudgetScan::read( array( 'includes/a.php' => $code ), $hand );
		self::assertSame(
			array(
				// While it reads the body.
				'UNSUPPORTED includes/a.php:6 name with a namespace Other\Space\run in AB_MCP_Sync_Code_Swap::prepare',
				'UNSUPPORTED includes/a.php:7 loop in the alternative syntax in AB_MCP_Sync_Code_Swap::prepare',
				// Once all is read.
				'DYN includes/a.php:5 AB_MCP_Sync_Code_Swap::prepare ->$m( without an entry AB_MCP_Sync_Code_Swap::prepare|->$m(',
				'KIND not used: array_pop',
			),
			$model['problems']
		);
		$basis = ReadBudgetScan::basis( $model, $hand );
		self::assertSame( array( 'AB_MCP_Sync_Code_Swap::prepare' => array( 'file_get_contents' ) ), $basis['G2'], 'A step through its helper is a step of G2.' );
		self::assertSame( array( 'AB_MCP_Sync_Step::file_get_contents' => 'CHECK' ), $basis['G7'], 'The helper is a check of G7.' );
		self::assertSame( array( 'includes/a.php::AB_MCP_Sync_Code_Swap::prepare' => 'Weg' ), $basis['G1'] );
	}

	/* ---------- Part 2.2: the rules R1 to R7 at the head ---------- */

	public function testTheRulesReadEveryFunctionOfG1WithoutAProblem(): void {
		self::assertSame( array(), self::rules()['problems'], 'Each statement read, each check with its form in CHECKS, each entry of ATOMIC, CHECKS, FACHWERT, COMPARE and FRAME_OUT used.' );
	}

	public function testEachViolationOfTheRulesIsAnOpenException(): void {
		$lists = self::lists();
		self::assertSame( array(), ReadBudgetRules::open_diff( $lists['OPEN'], self::rules()['violations'] ), 'OPEN (tests/ReadBudgetExceptions.php): each violation of R1 to R7 by function in the order of its source, kept by hand with the numbers and the reason; a part of the build strikes what it mends.' );
		foreach ( $lists['OPEN'] as $fn => $entries ) {
			foreach ( $entries as $entry ) {
				self::assertCount( 3, $entry, $fn );
				self::assertMatchesRegularExpression( '/^R[1-7] \S/', $entry[0], $fn );
				self::assertIsList( $entry[1], $fn . ' ' . $entry[0] );
				foreach ( $entry[1] as $nr ) {
					self::assertIsInt( $nr, $fn . ' ' . $entry[0] );
				}
				self::assertNotSame( '', $entry[2], $fn . ' ' . $entry[0] );
			}
		}
	}

	public function testTheNamedBoundariesAreTheSeparateClosedList(): void {
		$named = self::lists()['NAMED'];
		self::assertSame( array(), ReadBudgetRules::named_diff( $named, self::rules()['named'] ), 'NAMED: the sorts outside the chain, the filters and the counts of the chain, both ways.' );
		self::assertSame( array( 'SORT', 'FILTER', 'CHAIN' ), array_keys( $named ) );
		foreach ( array( 'SORT', 'FILTER' ) as $kind ) {
			foreach ( $named[ $kind ] as $fn => $entries ) {
				foreach ( $entries as $entry ) {
					self::assertIsInt( $entry[1], $fn );
					self::assertNotSame( '', $entry[2], $fn );
				}
			}
		}
		self::assertNotSame( '', $named['CHAIN']['why'] );
		self::assertSame( count( self::soll()['CHAIN'] ), $named['CHAIN']['functions'], 'The chain of NAMED is the chain of the basis.' );
	}

	public function testTheListsOfTheRulesAreClosed(): void {
		$lists = self::lists();
		$soll  = self::soll();
		self::assertCount( 8, $lists['ATOMIC'], 'The eight atomic seams of the order (section 3.1).' );
		self::assertNotContains( 'AB_MCP_Protected_Store_Calls::read', $lists['ATOMIC'], 'Protected_Store_Calls::read() is composed.' );
		foreach ( $lists['ATOMIC'] as $seam ) {
			self::assertSame( 1, count( $soll['G2'][ $seam ] ), $seam . ': one step.' );
			self::assertSame( 'Platte', $soll['HAND']['KIND'][ $soll['G2'][ $seam ][0] ][0], $seam . ': a step of the disk.' );
		}
		$checks = array();
		foreach ( $soll['G7'] as $check => $kind ) {
			if ( 'CHECK' === $kind ) {
				$checks[] = $check;
			}
		}
		$listed = array_keys( $lists['CHECKS'] );
		sort( $checks );
		sort( $listed );
		self::assertSame( $checks, $listed, 'CHECKS: the checks of G7, each with its form.' );
		foreach ( $lists['CHECKS'] as $check => $form ) {
			self::assertContains( $form, array( 'wp', 'true', 'false' ), $check );
		}
		foreach ( $lists['FACHWERT'] as $fn => list( $answer, $why ) ) {
			self::assertNotSame( '', $answer, $fn );
			self::assertNotSame( '', $why, $fn );
			self::assertArrayHasKey( $fn, self::model()['reach'], $fn );
			self::assertArrayNotHasKey( $fn, self::model()['chain'], $fn );
		}
		foreach ( $lists['FRAME_OUT'] as $edge => $why ) {
			self::assertNotSame( '', $why, $edge );
			self::assertArrayHasKey( explode( '|', $edge )[0], $soll['HAND']['FRAME'], $edge . ': a call of the frame.' );
		}
		self::assertSame( array( 'strcmp' ), $lists['COMPARE'], 'A comparison may call strcmp() (N2 of the order).' );
	}

	/* ---------- Part 2.2: the self-tests of the rules ---------- */

	/**
	 * The reader and the rules over code of their own: from
	 * AB_MCP_Sync_Code_Swap::prepare(), with hand tables and lists given.
	 *
	 * @param array<string,array{0:string,1:bool}> $kind  KIND (the reason added).
	 * @param array<string,mixed>                  $lists Lists of the rules (the rest empty).
	 * @param array<string,mixed>                  $hand  Hand tables (the rest empty).
	 * @param array<string,mixed>                  $soll  CHAIN and G4 of the basis (else empty).
	 * @return array{model:array<string,mixed>,rules:array<string,mixed>,open:array<string,list<string>>}
	 */
	private static function own( string $code, array $kind, array $lists = array(), array $hand = array(), array $soll = array() ): array {
		$tables = array(
			'STEP_CLASS'   => 'AB_MCP_Sync_Step',
			'FRAME'        => array(),
			'NO_CAND'      => array(),
			'ROOTS'        => array(),
			'BOUNDARY'     => array(),
			'HELPERS'      => array(),
			'HELPER_EVENT' => array(),
			'RET'          => array(),
			'INST_BYNAME'  => array(),
			'INST'         => array(),
			'DYN'          => array(),
			'CUT'          => array(),
			'KIND'         => array(),
		);
		foreach ( $hand as $table => $entries ) {
			$tables[ $table ] = $entries;
		}
		foreach ( $kind as $name => list( $k, $step ) ) {
			$tables['KIND'][ $name ] = array( $k, $step, 'In the self-test.' );
		}
		$all = array(
			'ATOMIC'    => array(),
			'CHECKS'    => array(),
			'FACHWERT'  => array(),
			'COMPARE'   => array(),
			'SORTS'     => array( 'usort', 'uasort', 'uksort' ),
			'FILTERS'   => array( 'apply_filters' ),
			'FRAME_OUT' => array(),
		);
		foreach ( $lists as $list => $entries ) {
			$all[ $list ] = $entries;
		}
		$basis = array(
			'CHAIN' => array(),
			'G4'    => array(),
		);
		foreach ( $soll as $list => $entries ) {
			$basis[ $list ] = $entries;
		}
		$model = ReadBudgetScan::read( array( 'includes/a.php' => $code ), $tables );
		$rules = ReadBudgetRules::check( $model, $tables, $basis, $all );
		return array(
			'model' => $model,
			'rules' => $rules,
			'open'  => ReadBudgetRules::by_function( $rules['violations'] ),
		);
	}

	public function testR1StepsOnlyThroughTheHelperOrInAnAtomicSeam(): void {
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $path ) {
		$a = hash( 'sha256', $path );
		$b = AB_MCP_Sync_Step::hash( $deadline, 'sha256', $path );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		$c = self::calls()->one( $path );
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		$d = self::calls()->two( $path );
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		return self::calls()->three( $path );
	}
	private static function calls() {
		return new AB_MCP_Sync_Code_Swap_Calls();
	}
}
final class AB_MCP_Sync_Code_Swap_Calls {
	public function __construct() {
	}
	public function one( $path ) {
		return @lstat( $path );
	}
	public function two( $path ) {
		return is_file( $path ) && lstat( $path );
	}
	public function three( $path ) {
		return lstat( $path ) ? file_get_contents( $path ) : false;
	}
}
PHP;
		$own = self::own(
			$code,
			array(
				'hash'              => array( 'Codec', true ),
				'lstat'             => array( 'Platte', true ),
				'is_file'           => array( 'Platte', true ),
				'file_get_contents' => array( 'Platte', true ),
				'is_wp_error'       => array( 'sonst', false ),
			),
			array(
				'ATOMIC' => array( 'AB_MCP_Sync_Code_Swap_Calls::one', 'AB_MCP_Sync_Code_Swap_Calls::two' ),
				'CHECKS' => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
			)
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				// A direct step (the helper beside it is none).
				'AB_MCP_Sync_Code_Swap::prepare'     => array( 'R1 hash' ),
				// A composed seam is no exception.
				'AB_MCP_Sync_Code_Swap_Calls::three' => array( 'R1 lstat', 'R1 file_get_contents' ),
				// A seam listed as atomic with two steps (one() with one step: green).
				'AB_MCP_Sync_Code_Swap_Calls::two'   => array( 'R1 atomic seam with 2 steps' ),
			),
			$own['open']
		);
	}

	public function testR2EachPassOfALoopBeginsWithACheckThatLeaves(): void {
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $list ) {
		foreach ( $list as $a ) {
			strlen( $a );
		}
		foreach ( $list as $a ) {
			$left = AB_MCP_Sync_Deadline::left( $deadline );
			if ( true !== $left ) {
				return $left;
			}
			strlen( $a );
		}
		foreach ( $list as $a ) {
			if ( ! is_string( $a ) || true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				break;
			}
		}
		foreach ( $list as $a ) {
			$r = self::stopped( $deadline ) ? null : strlen( $a );
			if ( null === $r ) {
				return null;
			}
		}
		foreach ( $list as $a )
			strlen( $a );
		for ( $i = 0; $i < 3; $i++ ) {
			strlen( 'x' );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( self::stopped( $deadline ) ) {
				return null;
			}
		}
		while ( true ) {
			strlen( 'x' );
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return null;
			}
		}
		while ( true ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				strlen( 'x' );
			}
		}
		while ( true ) {
			if ( strlen( 'x' ) || true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return null;
			}
		}
		while ( true ) {
			if ( self::other() || true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return null;
			}
		}
		do {
			strlen( 'x' );
		} while ( false );
		do {
			$left = AB_MCP_Sync_Deadline::left( $deadline );
			if ( true !== $left ) {
				return $left;
			}
		} while ( false );
		return true;
	}
	private static function stopped( $deadline ) {
		return null !== $deadline;
	}
	private static function other() {
		return 1;
	}
}
PHP;
		$own = self::own(
			$code,
			array(
				'strlen'    => array( 'sonst', false ),
				'is_string' => array( 'sonst', false ),
			),
			array(
				'CHECKS' => array(
					'AB_MCP_Sync_Deadline::left'    => 'wp',
					'AB_MCP_Sync_Code_Swap::stopped' => 'true',
				),
			),
			array(
				'HELPERS'      => array( 'AB_MCP_Sync_Code_Swap::stopped' => true ),
				'HELPER_EVENT' => array( 'AB_MCP_Sync_Code_Swap::stopped' => 'CHECK' ),
			)
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// foreach: no check; with a check (pair, if with break, ternary) green; one statement without braces.
					'R2 foreach ( $list as $a )',
					'R2 foreach ( $list as $a )',
					// for: no check; with a check green.
					'R2 for ( $i = 0; $i < 3; $i++ )',
					// while: the check after work; a check that does not leave; a function of PHP before the check
					// (green); a function of the plugin before it.
					'R2 while ( true )',
					'R2 while ( true )',
					'R2 while ( true )',
					// do: no check; with a check green.
					'R2 do … while ( false )',
				),
			),
			$own['open']
		);
	}

	public function testR3EachEdgeOfARecursionAfterACheckAndAHaltAskedAfterItsReturn(): void {
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $v ) {
		return self::walk( $v, $deadline );
	}
	private static function walk( $v, $deadline ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		$out = array();
		foreach ( $v as $k => $item ) {
			if ( self::stopped( $deadline ) ) {
				return null;
			}
			$out[ $k ] = self::walk( $item, $deadline );
		}
		if ( null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return null;
		}
		$x = strrev( 'x' );
		$y = self::stopped( $deadline ) ? null : self::walk( $x, $deadline );
		if ( null === $y ) {
			return null;
		}
		$z = self::walk( $y, $deadline );
		return strrev( $z );
	}
	private static function stopped( $deadline ) {
		return null !== $deadline;
	}
}
PHP;
		$own = self::own(
			$code,
			array(
				'is_array' => array( 'sonst', false ),
				'strrev'   => array( 'Codec', true ),
			),
			array(
				'CHECKS'   => array( 'AB_MCP_Sync_Code_Swap::stopped' => 'true' ),
				'FACHWERT' => array( 'AB_MCP_Sync_Code_Swap::walk' => array( 'null', 'In the self-test.' ) ),
			),
			array(
				'HELPERS'      => array( 'AB_MCP_Sync_Code_Swap::stopped' => true ),
				'HELPER_EVENT' => array( 'AB_MCP_Sync_Code_Swap::stopped' => 'CHECK' ),
			)
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::walk' => array(
					// A circle the basis does not know (G4 empty here).
					'R3 circle not in G4: AB_MCP_Sync_Code_Swap::walk',
					// In the loop after its check, the halt asked after the loop: R3 green; the halt of the value
					// not passed on in the loop (R7, FACHWERT without stop()).
					'R7 AB_MCP_Sync_Code_Swap::walk',
					'R1 strrev',
					// In the ternary after its halt test: green before; its value tested (null === $y) is no
					// halt query, the next work is the next edge: red after (and R7).
					'R3 after AB_MCP_Sync_Code_Swap::walk',
					'R7 AB_MCP_Sync_Code_Swap::walk',
					// After work (the edge before): red before; work (strrev) after its return without a halt asked.
					'R3 before AB_MCP_Sync_Code_Swap::walk',
					'R3 after AB_MCP_Sync_Code_Swap::walk',
					'R7 AB_MCP_Sync_Code_Swap::walk',
					'R1 strrev',
				),
			),
			$own['open']
		);
	}

	public function testR4EntriesAndSeamsOfTheDiskRightAfterACheck(): void {
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $name ) {
		$a = AB_MCP_Sync_Env::held( $name );
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		$b = AB_MCP_Sync_Env::held( $name );
		$c = self::calls()->read( $name );
		if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
			self::calls()->close( $name );
			return null;
		}
		$p = $name . '/x';
		$d = self::calls()->read( $p );
		$e = true === AB_MCP_Sync_Deadline::left( $deadline ) ? self::calls()->read( $p ) : null;
		if ( null === $e ) {
			return null;
		}
		$f = $a . $b . $c . $d . $e;
		$g = array_map( static fn( $n ) => AB_MCP_Sync_Env::held( $n ), array( $f ) );
		return $g;
	}
	private static function calls() {
		return new AB_MCP_Sync_Code_Swap_Calls();
	}
}
final class AB_MCP_Sync_Code_Swap_Calls {
	public function __construct() {
	}
	public function read( $path ) {
		return file_get_contents( $path );
	}
	public function close( $path ) {
		return $path;
	}
}
final class AB_MCP_Sync_Env {
	public static function held( $name ) {
		return hash( 'sha256', $name );
	}
}
PHP;
		$own = self::own(
			$code,
			array(
				'file_get_contents' => array( 'Platte', true ),
				'hash'              => array( 'Codec', true ),
				'array_map'         => array( 'sonst', false ),
			),
			array(
				'ATOMIC' => array( 'AB_MCP_Sync_Code_Swap_Calls::read' ),
				'CHECKS' => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
			),
			array(),
			array( 'CHAIN' => array( 'includes/a.php::AB_MCP_Sync_Env::held' => 'Kette' ) )
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame( array( 'AB_MCP_Sync_Env::held' => true ), $own['model']['chain'] );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// An entry without a check; after a check green; a seam after an entry with a step (work between).
					'R4 entry AB_MCP_Sync_Env::held',
					'R4 seam AB_MCP_Sync_Code_Swap_Calls::read',
					// close() after a halt is allowed (no step of the disk); a seam after a check with statements
					// without work between, and in a ternary after the test that there is no halt: green. An
					// entry in a closure (a check in it needed).
					'R4 entry AB_MCP_Sync_Env::held',
				),
			),
			$own['open']
		);
	}

	public function testR5DynamicCallsWithTheirTargetCallbacksAndPureComparisons(): void {
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $o, $m, $f, $list ) {
		$a = $f( 1 );
		$b = $o::make( 1 );
		$c = call_user_func( $f, 1 );
		$d = call_user_func_array( $f, array( 1 ) );
		$e = $o->$m( 1 );
		$g = new $m();
		array_map( $f, $list );
		array_map( 'strval', $list );
		array_map( static function ( $x ) { return $x; }, $list );
		usort( $list, static function ( $x, $y ) { return strcmp( $x, $y ); } );
		usort( $list, static function ( $x, $y ) { return strcmp( json_encode( $x ), $y ); } );
		usort( $list, static function ( $x, $y ) { return AB_MCP_Sync_Credentials::unit( $x ) <=> $y; } );
		usort( $list, 'strcasecmp' );
		register_shutdown_function( static function () { return 1; } );
		( $f )( 1 );
		$list[0]( 1 );
		'strlen'( 'x' );
		$o->{$m}( 1 );
		return array( $a, $b, $c, $d, $e, $g );
	}
}
final class AB_MCP_Sync_Credentials {
	public static function unit( $x ) {
		return $x;
	}
}
PHP;
		$dyn = array(
			'to'   => array(),
			'kind' => 'Closure',
			'why'  => 'In the self-test.',
		);
		$own = self::own(
			$code,
			array(
				'call_user_func'             => array( 'sonst', false ),
				'call_user_func_array'       => array( 'sonst', false ),
				'array_map'                  => array( 'sonst', false ),
				'strval'                     => array( 'sonst', false ),
				'usort'                      => array( 'Sortierung', true ),
				'strcmp'                     => array( 'sonst', false ),
				'strcasecmp'                 => array( 'sonst', false ),
				'json_encode'                => array( 'Codec', true ),
				'register_shutdown_function' => array( 'sonst', false ),
			),
			array( 'COMPARE' => array( 'strcmp' ) ),
			array(
				'DYN' => array(
					'AB_MCP_Sync_Code_Swap::prepare|$f('            => $dyn,
					'AB_MCP_Sync_Code_Swap::prepare|$o::make'       => $dyn,
					'AB_MCP_Sync_Code_Swap::prepare|call_user_func' => $dyn,
				),
			)
		);
		self::assertSame(
			array(
				// While the reader reads (the forms it knows, without an entry in DYN).
				'DYN includes/a.php:7 AB_MCP_Sync_Code_Swap::prepare call_user_func_array without an entry AB_MCP_Sync_Code_Swap::prepare|call_user_func_array',
				'DYN includes/a.php:8 AB_MCP_Sync_Code_Swap::prepare ->$m( without an entry AB_MCP_Sync_Code_Swap::prepare|->$m(',
				'DYN includes/a.php:9 AB_MCP_Sync_Code_Swap::prepare new $m without an entry AB_MCP_Sync_Code_Swap::prepare|new $m',
			),
			$own['model']['problems']
		);
		self::assertSame(
			array(
				// The calls of an expression the reader cannot resolve.
				'UNSUPPORTED includes/a.php:18 call of an expression ()() in AB_MCP_Sync_Code_Swap::prepare',
				'UNSUPPORTED includes/a.php:19 call of an expression (]() in AB_MCP_Sync_Code_Swap::prepare',
				"UNSUPPORTED includes/a.php:20 call of an expression ('strlen'() in AB_MCP_Sync_Code_Swap::prepare",
				'UNSUPPORTED includes/a.php:21 call of an expression (}() in AB_MCP_Sync_Code_Swap::prepare',
			),
			$own['rules']['problems']
		);
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					'R5 callback without target: array_map',
					'R1 usort',
					'R1 usort',
					'R1 json_encode',
					'R5 comparison not pure: usort calls json_encode',
					'R1 usort',
					'R5 comparison not pure: usort calls AB_MCP_Sync_Credentials::unit',
					'R1 usort',
					'R5 comparison not pure: usort calls strcasecmp',
					'R5 closure given to register_shutdown_function, which is no function of ITERCB',
				),
			),
			$own['open']
		);
		self::assertSame( array( 'AB_MCP_Sync_Code_Swap::prepare' => array( 'usort', 'usort', 'usort', 'usort' ) ), $own['rules']['named']['SORT'] );
	}

	public function testR6G1IsClosedAndTheChainIsEnteredOnlyThroughHeld(): void {
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $name ) {
		self::other( $name );
		self::away( $name );
		return self::checked( $name );
	}
	private static function other( $n ) {
		return $n;
	}
	private static function away( $n ) {
		return $n;
	}
	private static function checked( $name ) {
		$a = AB_MCP_Sync_Env::held( $name );
		$b = AB_MCP_Sync_Env::inner( $name );
		return self::fresh( $a . $b );
	}
	private static function fresh( $x ) {
		return $x;
	}
}
final class AB_MCP_Sync_Env {
	public static function held( $name ) {
		return self::inner( $name );
	}
	public static function inner( $name ) {
		return strlen( $name );
	}
}
PHP;
		$own = self::own(
			$code,
			array( 'strlen' => array( 'sonst', false ) ),
			array( 'FRAME_OUT' => array( 'AB_MCP_Sync_Code_Swap::prepare|AB_MCP_Sync_Code_Swap::away' => 'In the self-test.' ) ),
			array( 'FRAME' => array( 'AB_MCP_Sync_Code_Swap::prepare' => array( 'AB_MCP_Sync_Code_Swap::checked' ) ) ),
			array(
				'CHAIN' => array(
					'includes/a.php::AB_MCP_Sync_Env::held'  => 'Kette',
					'includes/a.php::AB_MCP_Sync_Env::inner' => 'Kette',
				),
			)
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				// The entry through held() is in G5 and under R4 (no check here); the entry beside it is red.
				'AB_MCP_Sync_Code_Swap::checked' => array( 'R4 entry AB_MCP_Sync_Env::held', 'R6 entry into the chain beside held(): AB_MCP_Sync_Env::inner' ),
				'AB_MCP_Sync_Code_Swap::prepare' => array( 'R6 call of the frame not in FRAME_OUT: AB_MCP_Sync_Code_Swap::other' ),
			),
			$own['open']
		);
		// The direct entry takes inner() out of the chain of the basis, and a new function on the way is in G1:
		// both red against the lists of the basis (testTheFunctionsOutsideTheChainAreG1WithTheirRoles).
		self::assertSame( array( 'AB_MCP_Sync_Env::held' => true ), $own['model']['chain'] );
		self::assertSame(
			array(
				'includes/a.php::AB_MCP_Sync_Code_Swap::checked' => 'Weg',
				'includes/a.php::AB_MCP_Sync_Code_Swap::fresh'   => 'Weg',
				'includes/a.php::AB_MCP_Sync_Code_Swap::prepare' => 'Rahmen',
				'includes/a.php::AB_MCP_Sync_Env::inner'         => 'Weg',
			),
			ReadBudgetScan::basis( $own['model'], array( 'FRAME' => array( 'AB_MCP_Sync_Code_Swap::prepare' => array() ), 'HELPERS' => array(), 'NO_CAND' => array(), 'STEP_CLASS' => 'AB_MCP_Sync_Step' ) )['G1']
		);
	}

	public function testR7TheHaltPassedOnAfterEachCallThatCanGiveOne(): void {
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline ) {
		$a = self::can( $deadline );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		$b = self::can( $deadline );
		strlen( 'b' );
		$c = null === $b ? null : self::can( $deadline );
		if ( true !== $c ) {
			return $c;
		}
		$d = self::can( null );
		strlen( 'd' );
		$e = self::fach( $deadline );
		if ( null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return null;
		}
		$f = self::fach( $deadline );
		strlen( 'f' );
		$g = self::fach( $deadline );
		$s = AB_MCP_Sync_Deadline::stop( $deadline );
		if ( null !== $s ) {
			return $s;
		}
		$h = AB_MCP_Sync_Step::hash( $deadline, 'x' );
		strlen( 'h' );
		if ( self::can( $deadline ) ) {
			return 1;
		}
		return self::can( $deadline );
	}
	private static function can( $deadline ) {
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		return 1;
	}
	private static function fach( $deadline ) {
		if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
			return null;
		}
		return 1;
	}
}
PHP;
		$own = self::own(
			$code,
			array(
				'is_wp_error' => array( 'sonst', false ),
				'strlen'      => array( 'sonst', false ),
				'hash'        => array( 'Codec', true ),
			),
			array(
				'CHECKS'   => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
				'FACHWERT' => array( 'AB_MCP_Sync_Code_Swap::fach' => array( 'null', 'In the self-test.' ) ),
			)
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// A WP_Error tested and returned (is_wp_error, true !== of a ternary of it): green; not tested.
					'R7 AB_MCP_Sync_Code_Swap::can',
					// Without a deadline no halt. FACHWERT and stop() next (if, or $s and if): green; without stop().
					'R7 AB_MCP_Sync_Code_Swap::fach',
					// A helper of the steps not tested; a call in a condition; returned whole: green.
					'R7 AB_MCP_Sync_Step::hash',
					'R7 AB_MCP_Sync_Code_Swap::can',
				),
			),
			$own['open']
		);
	}

	public function testTheRulesTellWhatTheyCannotRead(): void {
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $a ) {
		if ( $a ):
			strlen( $a );
		endif;
		return $a;
	}
}
PHP;
		$own = self::own( $code, array( 'strlen' => array( 'sonst', false ) ) );
		self::assertSame(
			array(
				'UNSUPPORTED includes/a.php:4 control structure in the alternative syntax in AB_MCP_Sync_Code_Swap::prepare',
				'UNSUPPORTED includes/a.php:6 statement beginning with endif in AB_MCP_Sync_Code_Swap::prepare',
			),
			$own['rules']['problems']
		);
	}
}
