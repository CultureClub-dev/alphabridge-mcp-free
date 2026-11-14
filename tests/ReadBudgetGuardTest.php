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
 * Part 2.3a mends A1 to A4 of Codex, round 1 over the guard
 * (belege/codex-r12b-w1-antwort.md), each with its self-test: the answer of
 * a branch at a halt, a Fachwert asked for the halt with the right
 * polarity, the check of a pair assigned whole, and the deadline of a call
 * left out or null only where shown.
 *
 * Part 2.3b mends A5, A6 and B1 of the same round and two findings of the
 * same kind as A2 and A4: the closed lists compared with the number of
 * occurrences, each dynamic form of a call with its self-test, the callback
 * of a helper of a sort, the polarity of a halt asked after the return of a
 * recursion, and the deadline of a call whose arguments the model does not
 * hold.
 *
 * Part 2.3c mends no. 1 to 4 of Codex, round 2 over the guard
 * (belege/codex-r12b-w2-antwort.md), and the three spellings of the Fable
 * check (belege/fable-pruefstelle5-waechter-bericht.md, P1 to P3), after the
 * decision of 14.11. 21:01 (option a): the answer to a halt is one of a
 * fixed list of whole forms (return $v; alone, a whole entry of ANSWERS,
 * break; in the pair of R2), a translation its whole call; a value of
 * FACHWERT returned whole is no form of R7 (E26 dropped); the sources of the
 * lists are counted, not compared; and each branch accepted at a halt tells
 * the identifier of its form. The guard reads the spelling of the answer:
 * that a form passes the halt on unchanged is shown by the tests of part 7
 * (the boundary N7 of the order).
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
		self::assertSame( array(), self::rules()['problems'], 'Each statement read, each check with its form in CHECKS, each entry of ATOMIC, CHECKS, FACHWERT, COMPARE and FRAME_OUT used, each entry of ANSWERS at as many places as it says.' );
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
			$parses = true;
			try {
				token_get_all( '<?php return ' . $answer . ';', TOKEN_PARSE );
			} catch ( \ParseError $error ) {
				$parses = false;
			}
			self::assertTrue( $parses, $fn . ': the answer is a PHP expression (what its branches at a halt return).' );
			self::assertNotSame( '', $why, $fn );
			self::assertArrayHasKey( $fn, self::model()['reach'], $fn );
			self::assertArrayNotHasKey( $fn, self::model()['chain'], $fn );
		}
		foreach ( $lists['ANSWERS'] as $fn => $entries ) {
			self::assertArrayHasKey( $fn, self::model()['reach'], $fn );
			self::assertArrayNotHasKey( $fn, self::model()['chain'], $fn );
			self::assertIsList( $entries, $fn );
			self::assertNotSame( array(), $entries, $fn );
			foreach ( $entries as $entry ) {
				self::assertSame( array( 0, 1, 2, 3 ), array_keys( $entry ), $fn . ': the statements, the variable of the halt or null, the number of its places, the reason.' );
				list( $statements, $halt, $places, $why ) = $entry;
				self::assertIsString( $statements, $fn );
				$parses = true;
				try {
					token_get_all( '<?php ' . $statements, TOKEN_PARSE );
				} catch ( \ParseError $error ) {
					$parses = false;
				}
				self::assertTrue( $parses, $fn . ': the answer is whole statements of PHP (the branch at a halt, token by token; the rules take it only where its last statement returns a value, and tell an entry not used).' );
				self::assertDoesNotMatchRegularExpression( '/^\s*return\s+\$\w+\s*;\s*$/', $statements, $fn . ': the return of a variable alone is the form SAME where the variable holds the halt, and else no answer to list.' );
				if ( null !== $halt ) {
					self::assertMatchesRegularExpression( '/^\$[A-Za-z_][A-Za-z0-9_]*$/', $halt, $fn . ': the variable of the halt.' );
					self::assertStringContainsString( $halt . '->', $statements, $fn . ': the statements read the halt in the variable they name.' );
				}
				self::assertIsInt( $places, $fn . ': the number of its places.' );
				self::assertGreaterThan( 0, $places, $fn );
				self::assertNotSame( '', $why, $fn );
			}
		}
		foreach ( $lists['FRAME_OUT'] as $edge => list( $sites, $why ) ) {
			self::assertIsInt( $sites, $edge . ': the number of its call sites.' );
			self::assertGreaterThan( 0, $sites, $edge );
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
			'ATOMIC'       => array(),
			'CHECKS'       => array(),
			'FACHWERT'  => array(),
			'ANSWERS'   => array(),
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

	/**
	 * An entry of ANSWERS in a self-test: the whole statements of a branch at
	 * a halt, the number of its places, and the variable of the halt where
	 * the statements name it.
	 *
	 * @return array{0:string,1:?string,2:int,3:string}
	 */
	private static function answer( string $statements, int $places, ?string $halt = null ): array {
		return array( $statements, $halt, $places, 'In the self-test.' );
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
				return AB_MCP_Sync_Deadline::stop( $deadline );
			}
		}
		foreach ( $list as $a )
			strlen( $a );
		for ( $i = 0; $i < 3; $i++ ) {
			strlen( 'x' );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( self::stopped( $deadline ) ) {
				return AB_MCP_Sync_Deadline::stop( $deadline );
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
				return AB_MCP_Sync_Deadline::stop( $deadline );
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
				'CHECKS'  => array(
					'AB_MCP_Sync_Deadline::left'    => 'wp',
					'AB_MCP_Sync_Code_Swap::stopped' => 'true',
				),
				'ANSWERS' => array( 'AB_MCP_Sync_Code_Swap::prepare' => array( self::answer( 'return AB_MCP_Sync_Deadline::stop( $deadline );', 3 ) ) ),
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
					// foreach: no check; with a check (pair, ternary) green; an if with a break (the halt not kept for
					// after the loop, part 2.3a); one statement without braces.
					'R2 foreach ( $list as $a )',
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
		$w = self::walk( $v, $deadline );
		$s = AB_MCP_Sync_Deadline::stop( $deadline );
		if ( null !== $s ) {
			return $s;
		}
		return $w;
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
				'ANSWERS'  => array( 'AB_MCP_Sync_Code_Swap::walk' => array( self::answer( 'return null;', 3 ) ) ),
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
			return AB_MCP_Sync_Deadline::stop( $deadline );
		}
		$p = $name . '/x';
		$d = self::calls()->read( $p );
		$e = true === AB_MCP_Sync_Deadline::left( $deadline ) ? self::calls()->read( $p ) : null;
		if ( null === $e ) {
			return AB_MCP_Sync_Deadline::stop( $deadline );
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
				'ATOMIC'  => array( 'AB_MCP_Sync_Code_Swap_Calls::read' ),
				'CHECKS'  => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
				'ANSWERS' => array(
					'AB_MCP_Sync_Code_Swap::prepare' => array(
						self::answer( 'self::calls()->close( $name ); return AB_MCP_Sync_Deadline::stop( $deadline );', 1 ),
						self::answer( 'return AB_MCP_Sync_Deadline::stop( $deadline );', 1 ),
					),
				),
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
					// close() after a halt is allowed (no step of the disk) as a whole answer of ANSWERS (part 2.3c:
					// not by a rule of cleanup); a seam after a check with statements
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
			array( 'FRAME_OUT' => array( 'AB_MCP_Sync_Code_Swap::prepare|AB_MCP_Sync_Code_Swap::away' => array( 1, 'In the self-test.' ) ) ),
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
			return AB_MCP_Sync_Deadline::stop( $deadline );
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
				'ANSWERS'  => array( 'AB_MCP_Sync_Code_Swap::prepare' => array( self::answer( 'return AB_MCP_Sync_Deadline::stop( $deadline );', 1 ) ) ),
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

	/* ---------- Part 2.3a: the changes after Codex, round 1 over the guard ---------- */

	public function testEachBranchAtAHaltAnswersAsPrescribed(): void {
		// Codex A1 (round 1), in the forms of part 2.3c: a branch at a halt answers the same halt (return $v; alone)
		// or a whole answer of ANSWERS of its function (its Fachwert, the halt the deadline holds, a translation as
		// its whole call); nothing before the return that is not in the entry, no return in a ternary.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $list ) {
		$a = self::can( $deadline );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		$b = self::can( $deadline );
		if ( is_wp_error( $b ) ) {
			return true;
		}
		$c = self::can( $deadline );
		if ( is_wp_error( $c ) ) {
			return self::can( $deadline );
		}
		$d = self::can( $deadline );
		if ( is_wp_error( $d ) ) {
			return self::refusal( $d->get_error_code(), 'Not read: ' . $d->get_error_message() );
		}
		$e = self::can( $deadline );
		if ( is_wp_error( $e ) ) {
			return self::refusal( 'code:other', 'Not read.' );
		}
		$f = self::can( $deadline );
		if ( is_wp_error( $f ) ) {
			strrev( 'f' );
			return $f;
		}
		$g = self::can( $deadline );
		if ( is_wp_error( $g ) ) {
			AB_MCP_Sync_Deadline::left( $deadline );
			return $g;
		}
		$h = self::can( $deadline );
		if ( is_wp_error( $h ) ) {
			self::calls()->close( 'h' );
			return $h;
		}
		$i = self::can( $deadline );
		if ( is_wp_error( $i ) ) {
			if ( $list ) {
				return $i;
			}
			return $i;
		}
		foreach ( $list as $x ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return AB_MCP_Sync_Deadline::stop( $deadline );
			}
		}
		foreach ( $list as $x ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return null;
			}
		}
		foreach ( $list as $x ) {
			$left = AB_MCP_Sync_Deadline::left( $deadline );
			if ( true !== $left ) {
				break;
			}
		}
		if ( true !== $left ) {
			return $left;
		}
		foreach ( $list as $x ) {
			$left = AB_MCP_Sync_Deadline::left( $deadline );
			if ( true !== $left ) {
				break;
			}
		}
		$q = self::seam_a( $deadline, 'q' );
		if ( is_wp_error( $q ) ) {
			return $q;
		}
		$r = self::seam_b( $deadline, 'r' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$t = self::seam_c( $deadline, 't' );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$z = self::fach( $deadline, $list );
		$s = AB_MCP_Sync_Deadline::stop( $deadline );
		if ( null !== $s ) {
			return $s;
		}
		return $z;
	}
	private static function seam_a( $deadline, $p ) {
		return true === AB_MCP_Sync_Deadline::left( $deadline ) ? self::calls()->read( $p ) : null;
	}
	private static function seam_b( $deadline, $p ) {
		return true === AB_MCP_Sync_Deadline::left( $deadline ) ? self::calls()->read( $p ) : AB_MCP_Sync_Deadline::stop( $deadline );
	}
	private static function seam_c( $deadline, $p ) {
		if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) || false === self::calls()->read( $p ) ) {
			return null;
		}
		return 1;
	}
	private static function fach( $deadline, $list ) {
		foreach ( $list as $x ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return '';
			}
		}
		foreach ( $list as $x ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return null;
			}
		}
		return 'x';
	}
	private static function can( $deadline ) {
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		return 1;
	}
	private static function refusal( $code, $text ) {
		return array( $code, $text );
	}
	private static function calls() {
		return new AB_MCP_Sync_Code_Swap_Calls();
	}
}
final class AB_MCP_Sync_Code_Swap_Calls {
	public function __construct() {
	}
	public function close( $path ) {
		return $path;
	}
	public function read( $path ) {
		return file_get_contents( $path );
	}
}
PHP;
		$own = self::own(
			$code,
			array(
				'is_wp_error'                 => array( 'sonst', false ),
				'strrev'                      => array( 'Codec', true ),
				'file_get_contents'           => array( 'Platte', true ),
				'WP_Error->get_error_code'    => array( 'sonst', false ),
				'WP_Error->get_error_message' => array( 'sonst', false ),
			),
			array(
				'ATOMIC'   => array( 'AB_MCP_Sync_Code_Swap_Calls::read' ),
				'CHECKS'   => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
				'FACHWERT' => array( 'AB_MCP_Sync_Code_Swap::fach' => array( "''", 'In the self-test.' ) ),
				'ANSWERS'  => array(
					'AB_MCP_Sync_Code_Swap::fach'    => array( self::answer( "return '';", 1 ) ),
					'AB_MCP_Sync_Code_Swap::prepare' => array(
						self::answer( 'return self::refusal( $d->get_error_code(), \'Not read: \' . $d->get_error_message() );', 1, '$d' ),
						self::answer( 'return AB_MCP_Sync_Deadline::stop( $deadline );', 1 ),
					),
				),
			),
			array(
				'INST_BYNAME' => array(
					'get_error_code'    => 'WP_Error',
					'get_error_message' => 'WP_Error',
				),
			)
		);
		self::assertSame( array(), $own['model']['problems'] );
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::fach'    => array(
					// Its own Fachwert '' (FACHWERT, its entry of ANSWERS): green; null is not its answer.
					"R2 foreach ( \$list as \$x )",
				),
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// The same halt: green. true (Codex A1, SW:675), the call again (Codex A1), a refusal without the
					// code of the halt (no entry of ANSWERS), work before the return, a check before it, close()
					// before it without an entry (part 2.3c: no cleanup by rule), an if before it: red; the
					// translation as the whole call of its entry: green.
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R1 strrev',
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
					// The halt the deadline holds: green; null where the function is not in FACHWERT: red; a break
					// with the halt returned right after the loop: green; a break without: red.
					'R2 foreach ( $list as $x )',
					'R2 foreach ( $list as $x )',
				),
				// A seam in a ternary after the halt test, the statement answering in the ternary (null, or the halt
				// the deadline holds): red, a return in a ternary is no whole form (part 2.3c). A seam in a
				// condition after the halt test, its branch null (no entry of ANSWERS): red.
				'AB_MCP_Sync_Code_Swap::seam_a'  => array( 'R4 seam AB_MCP_Sync_Code_Swap_Calls::read' ),
				'AB_MCP_Sync_Code_Swap::seam_b'  => array( 'R4 seam AB_MCP_Sync_Code_Swap_Calls::read' ),
				'AB_MCP_Sync_Code_Swap::seam_c'  => array( 'R4 seam AB_MCP_Sync_Code_Swap_Calls::read' ),
			),
			$own['open']
		);
	}

	public function testAFachwertIsAskedForTheHaltWithTheRightPolarity(): void {
		// Codex A2: after a call of a function in FACHWERT only its own forms: an if that asks the halt with the right
		// polarity, or $s = stop() and null !== $s; is_wp_error() on the Fachwert does not do, nor the call returned
		// whole, also not by a function in FACHWERT with the same answer (part 2.3c, Codex round 2, no. 3: E26 dropped).
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline ) {
		$a = self::fach( $deadline );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		$b = self::fach( $deadline );
		if ( null !== $deadline && null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return AB_MCP_Sync_Deadline::stop( $deadline );
		}
		$c = self::fach( $deadline );
		if ( null !== $deadline && null === AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return AB_MCP_Sync_Deadline::stop( $deadline );
		}
		$d = self::fach( $deadline );
		if ( null === $d || true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
			return AB_MCP_Sync_Deadline::stop( $deadline );
		}
		$e = self::fach( $deadline );
		$s = AB_MCP_Sync_Deadline::stop( $deadline );
		if ( null === $s ) {
			return $s;
		}
		$f = self::inner( $deadline );
		$s = AB_MCP_Sync_Deadline::stop( $deadline );
		if ( null !== $s ) {
			return $s;
		}
		$g = self::other( $deadline );
		$s = AB_MCP_Sync_Deadline::stop( $deadline );
		if ( null !== $s ) {
			return $s;
		}
		return self::fach( $deadline );
	}
	private static function inner( $deadline ) {
		return self::fach( $deadline );
	}
	private static function other( $deadline ) {
		return self::fach( $deadline );
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
			array( 'is_wp_error' => array( 'sonst', false ) ),
			array(
				'CHECKS'   => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
				'FACHWERT' => array(
					'AB_MCP_Sync_Code_Swap::fach'  => array( 'null', 'In the self-test.' ),
					'AB_MCP_Sync_Code_Swap::inner' => array( 'null', 'In the self-test.' ),
					'AB_MCP_Sync_Code_Swap::other' => array( "''", 'In the self-test.' ),
				),
				'ANSWERS'  => array( 'AB_MCP_Sync_Code_Swap::prepare' => array( self::answer( 'return AB_MCP_Sync_Deadline::stop( $deadline );', 2 ) ) ),
			)
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				// Returned whole by a function in FACHWERT: red, with the same answer (inner(), SH:103 and the six
				// places like it at the head) as with another (other()).
				'AB_MCP_Sync_Code_Swap::inner'   => array( 'R7 AB_MCP_Sync_Code_Swap::fach' ),
				'AB_MCP_Sync_Code_Swap::other'   => array( 'R7 AB_MCP_Sync_Code_Swap::fach' ),
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// is_wp_error() on the Fachwert (Codex A2, CS:1183): red. null !== $deadline && null !== stop():
					// green (SH:389); the polarity turned (Codex A2, SH:389): red. A check in its halt test after a
					// pure disjunct: green. $s = stop() tested null === $s: red.
					'R7 AB_MCP_Sync_Code_Swap::fach',
					'R7 AB_MCP_Sync_Code_Swap::fach',
					'R7 AB_MCP_Sync_Code_Swap::fach',
					// Returned whole by a function not in FACHWERT: red.
					'R7 AB_MCP_Sync_Code_Swap::fach',
				),
			),
			$own['open']
		);
	}

	public function testACheckOfAPairIsAssignedWhole(): void {
		// Codex A3: $v = CHECK; if ( HALT( $v ) ) … guards only where the check is assigned whole; in one branch of a
		// ternary it does not run on every way on.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $list, $path ) {
		foreach ( $list as $one ) {
			$left = is_array( $one ) ? true : AB_MCP_Sync_Deadline::left( $deadline );
			if ( true !== $left ) {
				return $left;
			}
		}
		foreach ( $list as $one ) {
			$left = AB_MCP_Sync_Deadline::left( $deadline );
			if ( true !== $left ) {
				return $left;
			}
		}
		foreach ( $list as $one ) {
			$v = is_array( $one ) ? true : AB_MCP_Sync_Step::hash( $deadline, 'sha256', $one );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
		}
		$left = is_string( $path ) ? true : AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		$a = self::calls()->read( $path );
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		$b = self::calls()->read( $path );
		return array( $a, $b );
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
}
PHP;
		$own = self::own(
			$code,
			array(
				'is_array'          => array( 'sonst', false ),
				'is_string'         => array( 'sonst', false ),
				'is_wp_error'       => array( 'sonst', false ),
				'file_get_contents' => array( 'Platte', true ),
				'hash'              => array( 'Codec', true ),
			),
			array(
				'ATOMIC' => array( 'AB_MCP_Sync_Code_Swap_Calls::read' ),
				'CHECKS' => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
			)
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// The check in a branch of a ternary at the start of a loop (Codex A3, ST:602): red; assigned
					// whole: green; a helper of the steps in a branch: red.
					'R2 foreach ( $list as $one )',
					'R2 foreach ( $list as $one )',
					// Before a seam of the disk (Codex A3, before head_text()): red; assigned whole: green.
					'R4 seam AB_MCP_Sync_Code_Swap_Calls::read',
				),
			),
			$own['open']
		);
	}

	public function testTheDeadlineOfACallIsLeftOutOrNullOnlyWhereShown(): void {
		// Codex A4: an argument unpacked at or before the parameter of the deadline is UNSUPPORTED (and a deadline
		// given); a named argument is placed by its name; only a deadline shown left out or null takes R7 off (E17).
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $args ) {
		self::can( 1, null );
		self::can( 1 );
		self::can( ...array( 1, $deadline ) );
		self::can( 1, deadline: $deadline );
		self::can( 1, deadline: null );
		self::can( x: 1 );
		self::can( 1, ...$args );
		self::two( $deadline, ...$args );
		return 1;
	}
	private static function can( $x, $deadline = null ) {
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		return $x;
	}
	private static function two( $deadline, ...$rest ) {
		return self::can( 2, $deadline );
	}
}
PHP;
		$own = self::own(
			$code,
			array(),
			array( 'CHECKS' => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ) )
		);
		self::assertSame(
			array(
				'UNSUPPORTED includes/a.php:6 arguments unpacked at or before the parameter $deadline of AB_MCP_Sync_Code_Swap::can in AB_MCP_Sync_Code_Swap::prepare',
				'UNSUPPORTED includes/a.php:10 arguments unpacked at or before the parameter $deadline of AB_MCP_Sync_Code_Swap::can in AB_MCP_Sync_Code_Swap::prepare',
			),
			$own['rules']['problems']
		);
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// null, left out, named null, another named one only: no deadline (green). Unpacked at the
					// deadline (Codex A4, SW:673) and at its place: a deadline (red, and UNSUPPORTED); named with it:
					// red; unpacked after it: given positionally (red).
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::two',
				),
			),
			$own['open']
		);
	}

	/* ---------- Part 2.3b: Codex, round 1 over the guard, A5, and R3 and R7 of the same kind as A2 and A4 ---------- */

	public function testTheClosedListsAreComparedWithTheNumberOfOccurrences(): void {
		// Codex A5: an entry twice in a list, or a call twice in the code where the list holds it once, is a
		// difference: NAMED and OPEN by function and place in its order, counted; FRAME_OUT with the number of the
		// call sites of each edge, ANSWERS with the number of the places of each entry (part 2.3c; in part 2.3b
		// TRANSLATIONS); no entry twice in ATOMIC, COMPARE, SORTS or FILTERS.
		self::assertSame( array( array( 'a', 'a' ), array( 'c' ) ), ReadBudgetRules::counted( array( 'a', 'b', 'a', 'a' ), array( 'b', 'a', 'c' ) ) );
		$chain = array(
			'functions'  => 1,
			'steps'      => 0,
			'loops'      => 0,
			'recursions' => 0,
		);
		$one   = array( 'usort', 166, 'In the self-test.' );
		$named = array(
			'SORT'   => array( 'AB_MCP_Sync_File_Manifest::folder' => array( $one, $one ) ),
			'FILTER' => array(),
			'CHAIN'  => $chain + array( 'why' => 'In the self-test.' ),
		);
		$have  = array(
			'SORT'   => array( 'AB_MCP_Sync_File_Manifest::folder' => array( 'usort' ) ),
			'FILTER' => array(),
			'CHAIN'  => $chain,
		);
		// The entry of NAMED twice (Codex A5, File_Manifest::folder): red.
		self::assertSame( array( 'SORT only in the list: AB_MCP_Sync_File_Manifest::folder #2 usort' ), ReadBudgetRules::named_diff( $named, $have ) );
		$named['SORT']['AB_MCP_Sync_File_Manifest::folder'] = array( $one );
		self::assertSame( array(), ReadBudgetRules::named_diff( $named, $have ) );
		// A key of the chain more or less: red.
		$named['CHAIN']['extra'] = 1;
		unset( $named['CHAIN']['loops'] );
		self::assertSame(
			array(
				'CHAIN: the keys of the list are extra, functions, recursions, steps, why, not functions, loops, recursions, steps, why',
				'CHAIN loops: the list none, the code 0',
			),
			ReadBudgetRules::named_diff( $named, $have )
		);
		// An entry of OPEN twice: red.
		$violation = array(
			'rule' => 'R1',
			'fn'   => 'AB_MCP_Payload_File::name',
			'file' => 'includes/a.php',
			'line' => 3,
			'pos'  => 9,
			'what' => 'sha1',
		);
		$entry     = array( 'R1 sha1', array(), 'In the self-test.' );
		self::assertSame( array( 'only in the list: AB_MCP_Payload_File::name #2 R1 sha1' ), ReadBudgetRules::open_diff( array( 'AB_MCP_Payload_File::name' => array( $entry, $entry ) ), array( $violation ) ) );
		self::assertSame( array( 'only in the code:  AB_MCP_Payload_File::name #2 R1 sha1 (includes/a.php:3)' ), ReadBudgetRules::open_diff( array( 'AB_MCP_Payload_File::name' => array( $entry ) ), array( $violation, $violation ) ) );
		// In code of its own: a call of the frame not followed twice, a translation (an entry of ANSWERS) at two
		// places, a filter twice.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $x ) {
		self::away( 1 );
		self::away( 2 );
		return self::inner( $deadline, $x );
	}
	private static function away( $n ) {
		return $n;
	}
	private static function inner( $deadline, $x ) {
		$a = apply_filters( 'f', array() );
		$b = apply_filters( 'g', array() );
		$v = self::can( $deadline );
		if ( true !== $v ) {
			return self::refusal( $v->get_error_code(), $v->get_error_message() );
		}
		$v = self::can( $deadline );
		if ( true !== $v ) {
			return self::refusal( $v->get_error_code(), $v->get_error_message() );
		}
		return array( $a, $b, $x );
	}
	private static function can( $deadline ) {
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		return true;
	}
	private static function refusal( $code, $text ) {
		return $code . $text;
	}
}
PHP;
		$lists = array(
			'CHECKS'    => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
			'FRAME_OUT' => array( 'AB_MCP_Sync_Code_Swap::prepare|AB_MCP_Sync_Code_Swap::away' => array( 1, 'In the self-test.' ) ),
			'ANSWERS'   => array( 'AB_MCP_Sync_Code_Swap::inner' => array( self::answer( 'return self::refusal( $v->get_error_code(), $v->get_error_message() );', 1, '$v' ) ) ),
			'SORTS'     => array( 'usort', 'usort', 'uasort', 'uksort' ),
		);
		$hand  = array(
			'FRAME'       => array( 'AB_MCP_Sync_Code_Swap::prepare' => array( 'AB_MCP_Sync_Code_Swap::inner' ) ),
			'INST_BYNAME' => array(
				'get_error_code'    => 'WP_Error',
				'get_error_message' => 'WP_Error',
			),
		);
		$kind  = array(
			'apply_filters'               => array( 'sonst', false ),
			'WP_Error->get_error_code'    => array( 'sonst', false ),
			'WP_Error->get_error_message' => array( 'sonst', false ),
		);
		$own   = self::own( $code, $kind, $lists, $hand );
		self::assertSame( array(), $own['model']['problems'] );
		self::assertSame(
			array(
				'SORTS 2 times: usort',
				'FRAME_OUT AB_MCP_Sync_Code_Swap::prepare|AB_MCP_Sync_Code_Swap::away: 2 call sites, the list 1',
				'ANSWERS AB_MCP_Sync_Code_Swap::inner #1: 2 places, the list 1: return self :: refusal ( $v -> get_error_code ( ) , $v -> get_error_message ( ) ) ;',
			),
			$own['rules']['problems']
		);
		self::assertSame( array(), $own['open'] );
		$named = array(
			'SORT'   => array(),
			'FILTER' => array( 'AB_MCP_Sync_Code_Swap::inner' => array( array( 'apply_filters', 0, 'In the self-test.' ) ) ),
			'CHAIN'  => array(
				'functions'  => 0,
				'steps'      => 0,
				'loops'      => 0,
				'recursions' => 0,
				'why'        => 'In the self-test.',
			),
		);
		// The second call of a filter: red.
		self::assertSame( array( 'FILTER only in the code:  AB_MCP_Sync_Code_Swap::inner #2 apply_filters' ), ReadBudgetRules::named_diff( $named, $own['rules']['named'] ) );
		// The numbers as the code holds them: green.
		$lists['FRAME_OUT']['AB_MCP_Sync_Code_Swap::prepare|AB_MCP_Sync_Code_Swap::away'][0] = 2;
		$lists['ANSWERS']['AB_MCP_Sync_Code_Swap::inner'][0][2]                               = 2;
		$lists['SORTS'] = array( 'usort', 'uasort', 'uksort' );
		self::assertSame( array(), self::own( $code, $kind, $lists, $hand )['rules']['problems'] );
	}

	/* ---------- Part 2.3c: Codex, round 2 over the guard, no. 4 (replaces the test of the keys of part 2.3b) ---------- */

	/**
	 * What PHP keeps of the source of a list: the source in a file of its
	 * own, read as the lists are (require).
	 *
	 * @return mixed
	 */
	private static function kept( string $php ) {
		$file = tempnam( sys_get_temp_dir(), 'r12b' );
		self::assertIsString( $file );
		file_put_contents( $file, $php );
		try {
			return require $file;
		} finally {
			unlink( $file );
		}
	}

	public function testTheSourcesOfTheListsAreCountedNotCompared(): void {
		// Codex round 2, no. 4 (round 1, A5): PHP keeps only the last of two elements of an array with keys alike,
		// in whatever spelling, so a list would say more than it holds. Each array as written is counted against
		// what PHP keeps of it at the same place; a key is a string or an integer literal, anything else is told.
		foreach ( array( 'ReadBudgetExceptions.php', 'ReadBudgetSoll.php' ) as $file ) {
			$php = file_get_contents( __DIR__ . '/' . $file );
			self::assertIsString( $php, $file );
			self::assertSame( array(), ReadBudgetRules::list_counts( $php, require __DIR__ . '/' . $file ), 'tests/' . $file . ': each array holds as many elements as written as PHP keeps of it.' );
		}
		$told = static function ( string $php ): array {
			return ReadBudgetRules::list_counts( $php, self::kept( $php ) );
		};
		// As written and as kept alike, also nested, with a trailing comma, in both spellings of an array: green.
		self::assertSame( array(), $told( "<?php\n// A list.\nreturn array(\n\t'A' => array( 'x|y' => array( 1, 'a' ), \"x|z\" => [ 1, 'b', ], ),\n\t'B' => array( 'k' => true, 2 => null, 'l', ),\n\t3   => array(),\n);\n" ) );
		// The counter-probe of Codex (round 2, no. 4): the key of NAMED SORT again in double quotes, before the entry.
		self::assertSame(
			array( 'line 3: the array holds 3 elements as written and 2 as PHP keeps it (a key twice, in whatever spelling)' ),
			$told( "<?php\nreturn array(\n\t'SORT' => array(\n\t\t\"AB_MCP_Sync_File_Manifest::folder\" => array( array( 'usort', 166, 'a' ), array( 'usort', 167, 'b' ) ),\n\t\t'AB_MCP_Sync_File_Manifest::folder' => array( array( 'usort', 166, 'a' ) ),\n\t\t'AB_MCP_Sync_File_Manifest::still'  => array( array( 'usort', 168, 'c' ) ),\n\t),\n);\n" )
		);
		// The same key again in the same spelling (part 2.3b).
		self::assertSame(
			array(
				'line 2: the array holds 7 elements as written and 6 as PHP keeps it (a key twice, in whatever spelling)',
			),
			$told( "<?php\nreturn array(\n\t'A' => 1,\n\t'B' => 2,\n\t'A' => 3,\n\t'C' => 4,\n\t'D' => 5,\n\t'E' => 6,\n\t'F' => 7,\n);\n" )
		);
		// The same key in the spellings PHP holds alike: single and double quotes with an escape, a number and
		// the number as a string, an element without a key next to its number; and deep in a list, in the short
		// spelling of an array.
		self::assertSame(
			array(
				'line 3: the array holds 2 elements as written and 1 as PHP keeps it (a key twice, in whatever spelling)',
				'line 4: the array holds 2 elements as written and 1 as PHP keeps it (a key twice, in whatever spelling)',
				'line 5: the array holds 2 elements as written and 1 as PHP keeps it (a key twice, in whatever spelling)',
				'line 6: the array holds 2 elements as written and 1 as PHP keeps it (a key twice, in whatever spelling)',
			),
			$told( "<?php\nreturn array(\n\t'A' => array( 'a\\\\b' => 1, \"a\\\\b\" => 2 ),\n\t'B' => array( 1 => 'a', '1' => 'b' ),\n\t'C' => array( 'a', 0 => 'b' ),\n\t'D' => array( 'x', array( 'y' ), [ 'k' => 3, \"k\" => 4 ] ),\n);\n" )
		);
		// A key that is no string and no integer literal: a constant, true, a float, a negative number, a text
		// put together. Each is told, also where PHP makes a key of its own of it.
		self::assertSame(
			array(
				'line 3: a key that is no string literal and no integer literal: PHP_INT_SIZE',
				'line 4: a key that is no string literal and no integer literal: true',
				'line 5: a key that is no string literal and no integer literal: 2.0',
				'line 6: a key that is no string literal and no integer literal: -1',
				"line 7: a key that is no string literal and no integer literal: 'a'.'b'",
			),
			$told( "<?php\nreturn array(\n\tPHP_INT_SIZE => 1,\n\ttrue => 2,\n\t2.0 => 3,\n\t-1 => 4,\n\t'a' . 'b' => 5,\n\t'c' => 6,\n);\n" )
		);
		// An array that is not written at its place (in a call, unpacked) is not counted: told.
		self::assertSame(
			array(
				'line 3: an array or an unpacking in a value that is no array as written is not counted',
				'line 4: an array or an unpacking in a value that is no array as written is not counted',
			),
			$told( "<?php\nreturn array(\n\t'A' => array_merge( array( 'k' => 1, 'k' => 2 ) ),\n\t...array( 'B' => 1 ),\n\t'C' => array( 'k' => 1 ),\n);\n" )
		);
		// The source is the return of its array and nothing else.
		self::assertSame( array( 'the source does not begin with the return of its array' ), $told( "<?php\n\$a = array( 'k' => 1, 'k' => 2 );\nreturn array( 'A' => \$a );\n" ) );
		self::assertSame( array( 'line 2: what the source returns is no array as written' ), $told( "<?php\nreturn array_merge( array( 'k' => 1 ), array( 'k' => 2 ) );\n" ) );
		self::assertSame( array( 'line 2: the source holds more than the return of its array' ), $told( "<?php\nreturn array( 'k' => 1 ) + array( 'k' => 2 );\n" ) );
		// What PHP holds at the place of an array as written is none: told.
		self::assertSame( array( 'line 2: an array as written where PHP holds none' ), ReadBudgetRules::list_counts( "<?php\nreturn array( 'A' => array( 1 ) );\n", array( 'A' => 1 ) ) );
	}

	public function testAHaltAskedAfterTheReturnOfARecursionHasTheRightPolarity(): void {
		// R3 after the return (part 2.3b, of the same kind as Codex A2): only a halt query with the right polarity
		// whose branch answers as prescribed; another halt query is none, and the work after it is red.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $v ) {
		$w = self::walk( $v, $deadline );
		if ( null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return AB_MCP_Sync_Deadline::stop( $deadline );
		}
		return $w;
	}
	private static function walk( $v, $deadline ) {
		if ( ! is_array( $v ) || self::stopped( $deadline ) ) {
			return null;
		}
		$a = self::walk( $v[0], $deadline );
		if ( null === AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return null;
		}
		$x = strrev( 'a' );
		if ( self::stopped( $deadline ) ) {
			return null;
		}
		$b = self::walk( $v[1], $deadline );
		if ( ! self::stopped( $deadline ) ) {
			return null;
		}
		$y = strrev( 'b' );
		if ( self::stopped( $deadline ) ) {
			return null;
		}
		$c = self::walk( $v[2], $deadline );
		if ( null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return null;
		}
		$z = strrev( 'c' );
		if ( self::stopped( $deadline ) ) {
			return null;
		}
		$d = self::walk( $v[3], $deadline );
		$s = AB_MCP_Sync_Deadline::stop( $deadline );
		if ( null === $s ) {
			return null;
		}
		$p = strrev( 'd' );
		if ( self::stopped( $deadline ) ) {
			return null;
		}
		$e = self::walk( $v[4], $deadline );
		$t = AB_MCP_Sync_Deadline::stop( $deadline );
		if ( null !== $t ) {
			return null;
		}
		$q = strrev( 'e' );
		if ( self::stopped( $deadline ) ) {
			return null;
		}
		$f = self::walk( $v[5], $deadline );
		if ( self::stopped( $deadline ) ) {
			$f = null;
		}
		$o = strrev( 'f' );
		if ( self::stopped( $deadline ) ) {
			return null;
		}
		$r = strrev( self::walk( $v[6], $deadline ) );
		if ( null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return null;
		}
		return strrev( $a . $b . $c . $d . $e . $f . $x . $y . $z . $p . $q . $o . $r );
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
				'ANSWERS'  => array(
					'AB_MCP_Sync_Code_Swap::prepare' => array( self::answer( 'return AB_MCP_Sync_Deadline::stop( $deadline );', 1 ) ),
					'AB_MCP_Sync_Code_Swap::walk'    => array( self::answer( 'return null;', 9 ) ),
				),
			),
			array(
				'HELPERS'      => array( 'AB_MCP_Sync_Code_Swap::stopped' => true ),
				'HELPER_EVENT' => array( 'AB_MCP_Sync_Code_Swap::stopped' => 'CHECK' ),
			),
			array( 'G4' => array( 'AB_MCP_Sync_Code_Swap::walk' => array() ) )
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::walk' => array(
					// null === stop(): the wrong polarity, no halt asked (red after, and R7).
					'R3 after AB_MCP_Sync_Code_Swap::walk',
					'R7 AB_MCP_Sync_Code_Swap::walk',
					'R1 strrev',
					// ! stopped() of a check whose form is true: the wrong polarity (red).
					'R3 after AB_MCP_Sync_Code_Swap::walk',
					'R7 AB_MCP_Sync_Code_Swap::walk',
					'R1 strrev',
					// null !== stop() answering: green.
					'R1 strrev',
					// $s = stop(); if ( null === $s ): the wrong polarity (red).
					'R3 after AB_MCP_Sync_Code_Swap::walk',
					'R7 AB_MCP_Sync_Code_Swap::walk',
					'R1 strrev',
					// $t = stop(); if ( null !== $t ) answering: green.
					'R1 strrev',
					// stopped() with the right polarity, but its branch does not answer: no halt asked (red).
					'R3 after AB_MCP_Sync_Code_Swap::walk',
					'R7 AB_MCP_Sync_Code_Swap::walk',
					'R1 strrev',
					// A call around the edge works on its value before the halt is asked: red after (and R7).
					'R1 strrev',
					'R3 after AB_MCP_Sync_Code_Swap::walk',
					'R7 AB_MCP_Sync_Code_Swap::walk',
					'R1 strrev',
				),
			),
			$own['open']
		);
	}

	public function testTheDeadlineOfACallWhoseArgumentsTheModelDoesNotHoldIsNotShownLeftOut(): void {
		// Part 2.3b, of the same kind as Codex A4: a callback (its arguments are given by the function of PHP that
		// calls it), call_user_func_array() and new $x are UNSUPPORTED where the target has a parameter $deadline
		// (and a deadline counts as given); call_user_func() gives its target the arguments after the first.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $list, $f, $m ) {
		$a = array_map( array( __CLASS__, 'one' ), $list );
		$b = array_map( 'AB_MCP_Sync_Code_Swap::one', $list );
		$c = call_user_func( $f, 1, $deadline );
		$d = call_user_func( $f, 1 );
		$e = call_user_func( $f, 1, null );
		$g = call_user_func_array( $f, array( 1, $deadline ) );
		$h = new $m( 1 );
		return array( $a, $b, $c, $d, $e, $g, $h );
	}
	private static function one( $x, $deadline = null ) {
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		return $x;
	}
}
final class AB_MCP_Sync_Thing {
	public function __construct( $deadline = null ) {
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return;
		}
	}
}
PHP;
		$dyn = static function ( string $to ): array {
			return array(
				'to'   => array( $to ),
				'kind' => 'Plugin',
				'why'  => 'In the self-test.',
			);
		};
		$own = self::own(
			$code,
			array(
				'array_map'            => array( 'sonst', false ),
				'call_user_func'       => array( 'sonst', false ),
				'call_user_func_array' => array( 'sonst', false ),
			),
			array( 'CHECKS' => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ) ),
			array(
				'DYN' => array(
					'AB_MCP_Sync_Code_Swap::prepare|call_user_func'       => $dyn( 'AB_MCP_Sync_Code_Swap::one' ),
					'AB_MCP_Sync_Code_Swap::prepare|call_user_func_array' => $dyn( 'AB_MCP_Sync_Code_Swap::one' ),
					'AB_MCP_Sync_Code_Swap::prepare|new $m'               => $dyn( 'AB_MCP_Sync_Thing::__construct' ),
				),
			)
		);
		self::assertSame( array(), $own['model']['problems'] );
		self::assertSame(
			array(
				'UNSUPPORTED includes/a.php:4 the arguments of a call Rückruf (array) are not read, so the parameter $deadline of AB_MCP_Sync_Code_Swap::one is not shown left out, in AB_MCP_Sync_Code_Swap::prepare',
				'UNSUPPORTED includes/a.php:5 the arguments of a call Rückruf (Text) are not read, so the parameter $deadline of AB_MCP_Sync_Code_Swap::one is not shown left out, in AB_MCP_Sync_Code_Swap::prepare',
				'UNSUPPORTED includes/a.php:9 the arguments of a call dynamisch (call_user_func_array) are not read, so the parameter $deadline of AB_MCP_Sync_Code_Swap::one is not shown left out, in AB_MCP_Sync_Code_Swap::prepare',
				'UNSUPPORTED includes/a.php:10 the arguments of a call dynamisch (new $m) are not read, so the parameter $deadline of AB_MCP_Sync_Thing::__construct is not shown left out, in AB_MCP_Sync_Code_Swap::prepare',
			),
			$own['rules']['problems']
		);
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// The two callbacks: a deadline counts as given (red; the halt is not passed on).
					'R7 AB_MCP_Sync_Code_Swap::one',
					'R7 AB_MCP_Sync_Code_Swap::one',
					// call_user_func( $f, 1, $deadline ): given (red); ( $f, 1 ) left out and ( $f, 1, null ): no
					// deadline (green).
					'R7 AB_MCP_Sync_Code_Swap::one',
					// call_user_func_array() and new $m: a deadline counts as given (red).
					'R7 AB_MCP_Sync_Code_Swap::one',
					'R7 AB_MCP_Sync_Thing::__construct',
				),
			),
			$own['open']
		);
	}

	public function testASortThroughTheHelperKeepsItsCallback(): void {
		// Codex B1: AB_MCP_Sync_Step::usort( $deadline, … ) is the step usort (G2) and a check (G7), and its
		// callback, one place after the deadline, is a callback as with usort(): in G6 with the same label, in NAMED,
		// its comparison pure (R5, COMPARE used); a closure given to a helper of a step that calls no function is red.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $list, $f ) {
		$a = AB_MCP_Sync_Step::usort( $deadline, $list, 'strcmp' );
		if ( true !== $a ) {
			return $a;
		}
		$b = AB_MCP_Sync_Step::usort( $deadline, $list, static function ( $x, $y ) { return strcmp( $x, $y ); } );
		if ( true !== $b ) {
			return $b;
		}
		$c = AB_MCP_Sync_Step::usort( $deadline, $list, static function ( $x, $y ) { return AB_MCP_Sync_Credentials::unit( $x ) <=> $y; } );
		if ( true !== $c ) {
			return $c;
		}
		$d = AB_MCP_Sync_Step::usort( $deadline, $list, 'strcasecmp' );
		if ( true !== $d ) {
			return $d;
		}
		$e = AB_MCP_Sync_Step::usort( $deadline, $list, $f );
		if ( true !== $e ) {
			return $e;
		}
		$g = AB_MCP_Sync_Step::usort( $deadline, 'strcmp', $list );
		if ( true !== $g ) {
			return $g;
		}
		$h = AB_MCP_Sync_Step::usort( $deadline, $list, array( 'AB_MCP_Sync_Credentials', 'cmp' ) );
		if ( true !== $h ) {
			return $h;
		}
		$i = AB_MCP_Sync_Step::json_encode( $deadline, static function () { return 1; } );
		if ( true !== $i ) {
			return $i;
		}
		return $list;
	}
}
final class AB_MCP_Sync_Credentials {
	public static function unit( $x ) {
		return $x;
	}
	public static function cmp( $x, $y ) {
		return 0;
	}
}
PHP;
		$own = self::own(
			$code,
			array(
				'usort'       => array( 'Sortierung', true ),
				'json_encode' => array( 'Codec', true ),
				'strcmp'      => array( 'sonst', false ),
				'strcasecmp'  => array( 'sonst', false ),
			),
			array( 'COMPARE' => array( 'strcmp' ) )
		);
		self::assertSame( array(), $own['model']['problems'] );
		self::assertSame( array(), $own['rules']['problems'], 'COMPARE used by the helper with strcmp.' );
		$basis = ReadBudgetScan::basis( $own['model'], array( 'FRAME' => array(), 'HELPERS' => array(), 'NO_CAND' => array(), 'STEP_CLASS' => 'AB_MCP_Sync_Step' ) );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// The labels of usort() itself (E5: G6 stays the same where a sort moves into the helper).
					'usort -> nativ: strcmp',
					'usort -> Closure',
					'usort -> Closure',
					'usort -> nativ: strcasecmp',
					'usort -> anders: $f',
					// The callback at its place: the second argument after the deadline (here the array).
					'usort -> anders: $list',
					"usort -> anders: array( 'AB_MCP_Sync_Credentials', 'cmp' )",
				),
			),
			$basis['G6']
		);
		self::assertSame( array( 'AB_MCP_Sync_Code_Swap::prepare' => array( 'usort', 'usort', 'usort', 'usort', 'usort', 'usort', 'usort', 'json_encode' ) ), $basis['G2'], 'Each helper is its step.' );
		self::assertSame(
			array(
				'AB_MCP_Sync_Step::json_encode' => 'CHECK',
				'AB_MCP_Sync_Step::usort'       => 'CHECK',
			),
			$basis['G7']
		);
		self::assertSame( array( 'AB_MCP_Sync_Code_Swap::prepare' => array( 'usort', 'usort', 'usort', 'usort', 'usort' ) ), $own['rules']['named']['SORT'], 'NAMED: each sort with a target, as with usort().' );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// strcmp() as text and in a closure: pure (green).
					'R5 comparison not pure: usort calls AB_MCP_Sync_Credentials::unit',
					'R5 comparison not pure: usort calls strcasecmp',
					// A variable, and the callback not at its place: no target.
					'R5 callback without target: usort',
					'R5 callback without target: usort',
					'R5 comparison not pure: usort calls a function of the plugin',
					'R5 closure given to AB_MCP_Sync_Step::json_encode, which is no function of ITERCB',
				),
			),
			$own['open']
		);
	}

	public function testEachDynamicFormOfACallIsInDynOrRejected(): void {
		// Codex A6: each form of a dynamic call the reader knows is a problem without its entry in DYN and, with it,
		// a call of its target in G6; each form it does not read is UNSUPPORTED; a callback without a target and a
		// closure given to a function that calls none are red (R5). The matrix form, self-test, mutation of the code
		// is in belege/r12b-2-3b-uebergabe.md.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $o, $m, $f, $list ) {
		$f( 1 );
		$o::make( 1 );
		$o->$m( 1 );
		$o?->$m( 1 );
		self::$m( 1 );
		$o::$m( 2 );
		new $m( 1 );
		call_user_func( $f, 1 );
		call_user_func_array( $f, array( 1 ) );
		( $f )( 1 );
		$list[0]( 1 );
		'strlen'( 'x' );
		$o->{$m}( 1 );
		self::{$m}( 1 );
		$o::{'make'}( 1 );
		${'f'}( 1 );
		Other\Space\run( 1 );
		array_map( $f, $list );
		array_map( 'AB_MCP_Sync_Code_Swap::one', $list );
		array_map( array( __CLASS__, 'one' ), $list );
		register_shutdown_function( static function () { return 1; } );
		$c = strlen( ... );
		array_map( strlen( ... ), $list );
		return $c;
	}
	public static function one( $x ) {
		return $x;
	}
}
PHP;
		$kind = array(
			'call_user_func'             => array( 'sonst', false ),
			'call_user_func_array'       => array( 'sonst', false ),
			'array_map'                  => array( 'sonst', false ),
			'register_shutdown_function' => array( 'sonst', false ),
			'strlen'                     => array( 'sonst', false ),
		);
		$own  = self::own( $code, $kind );
		$p    = 'AB_MCP_Sync_Code_Swap::prepare';
		self::assertSame(
			array(
				'UNSUPPORTED includes/a.php:20 name with a namespace Other\Space\run in ' . $p,
				'DYN includes/a.php:4 ' . $p . ' $f( without an entry ' . $p . '|$f(',
				'DYN includes/a.php:5 ' . $p . ' $o::make without an entry ' . $p . '|$o::make',
				'DYN includes/a.php:6 ' . $p . ' ->$m( without an entry ' . $p . '|->$m(',
				'DYN includes/a.php:7 ' . $p . ' ?->$m( without an entry ' . $p . '|?->$m(',
				'DYN includes/a.php:8 ' . $p . ' ::$m( without an entry ' . $p . '|::$m(',
				'DYN includes/a.php:9 ' . $p . ' ::$m( without an entry ' . $p . '|::$m(',
				'DYN includes/a.php:10 ' . $p . ' new $m without an entry ' . $p . '|new $m',
				'DYN includes/a.php:11 ' . $p . ' call_user_func without an entry ' . $p . '|call_user_func',
				'DYN includes/a.php:12 ' . $p . ' call_user_func_array without an entry ' . $p . '|call_user_func_array',
			),
			$own['model']['problems']
		);
		self::assertSame(
			array(
				'UNSUPPORTED includes/a.php:13 call of an expression ()() in ' . $p,
				'UNSUPPORTED includes/a.php:14 call of an expression (]() in ' . $p,
				"UNSUPPORTED includes/a.php:15 call of an expression ('strlen'() in " . $p,
				'UNSUPPORTED includes/a.php:16 call of an expression (}() in ' . $p,
				'UNSUPPORTED includes/a.php:17 call of an expression (}() in ' . $p,
				'UNSUPPORTED includes/a.php:18 call of an expression (}() in ' . $p,
				'UNSUPPORTED includes/a.php:19 call of an expression (}() in ' . $p,
			),
			$own['rules']['problems']
		);
		self::assertSame(
			array(
				$p => array(
					'R5 callback without target: array_map',
					'R5 closure given to register_shutdown_function, which is no function of ITERCB',
					// A first-class callable is read as the call it names (the stricter reading); given as a callback
					// it is no target.
					'R5 callback without target: array_map',
				),
			),
			$own['open']
		);
		// With their entries in DYN: no problem of the reader, the call of the target in G6 and as an edge.
		$to  = array(
			'to'   => array( 'AB_MCP_Sync_Code_Swap::one' ),
			'kind' => 'Plugin',
			'why'  => 'In the self-test.',
		);
		$dyn = array();
		foreach ( array( '$f(', '$o::make', '->$m(', '?->$m(', '::$m(', 'new $m', 'call_user_func', 'call_user_func_array' ) as $form ) {
			$dyn[ $p . '|' . $form ] = $to;
		}
		$own = self::own( $code, $kind, array(), array( 'DYN' => $dyn ) );
		self::assertSame( array( 'UNSUPPORTED includes/a.php:20 name with a namespace Other\Space\run in ' . $p ), $own['model']['problems'] );
		$basis = ReadBudgetScan::basis( $own['model'], array( 'FRAME' => array(), 'HELPERS' => array(), 'NO_CAND' => array(), 'STEP_CLASS' => 'AB_MCP_Sync_Step' ) );
		self::assertSame(
			array(
				$p => array(
					'$f( -> Plugin: AB_MCP_Sync_Code_Swap::one',
					'$o::make -> Plugin: AB_MCP_Sync_Code_Swap::one',
					'->$m( -> Plugin: AB_MCP_Sync_Code_Swap::one',
					'?->$m( -> Plugin: AB_MCP_Sync_Code_Swap::one',
					'::$m( -> Plugin: AB_MCP_Sync_Code_Swap::one',
					'::$m( -> Plugin: AB_MCP_Sync_Code_Swap::one',
					'new $m -> Plugin: AB_MCP_Sync_Code_Swap::one',
					'call_user_func -> Plugin: AB_MCP_Sync_Code_Swap::one',
					'call_user_func_array -> Plugin: AB_MCP_Sync_Code_Swap::one',
					'array_map -> anders: $f',
					"array_map -> anders: 'AB_MCP_Sync_Code_Swap::one'",
					"array_map -> anders: array( __CLASS__, 'one' )",
					'array_map -> anders: strlen( ... )',
				),
			),
			$basis['G6']
		);
		self::assertArrayHasKey( 'AB_MCP_Sync_Code_Swap::one', $own['model']['edges'][ $p ] );
	}

	/* ---------- Part 2.3c: Codex, round 2 over the guard, no. 1 to 3, and P1 to P3 of the Fable check ---------- */

	public function testABranchAtAHaltAnswersInAWholeFormOnly(): void {
		// Part 2.3c (the boundary N7): the answer to a halt is return $v; alone ($v holds the halt), a whole entry of
		// ANSWERS of the function (its value of FACHWERT with its side value, the halt the deadline holds), or
		// break; in the pair of R2. Anything else in the branch is red: the variable of the halt written before it
		// is returned (Codex round 2, no. 1, SW:675), written through another name (P1), the side value turned
		// (P2, PS:4196; P3, CN:812) or left out, a halt query of the deadline after a call whose WP_Error may be
		// another error (E27).
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $list ) {
		$a = self::can( $deadline );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		$b = self::can( $deadline );
		if ( is_wp_error( $b ) ) {
			$b = true;
			return $b;
		}
		$alias = &$c;
		$c     = self::can( $deadline );
		if ( is_wp_error( $c ) ) {
			$alias = true;
			return $c;
		}
		$d = self::can( $deadline );
		if ( is_wp_error( $d ) ) {
			return AB_MCP_Sync_Deadline::stop( $deadline );
		}
		foreach ( $list as $x ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return AB_MCP_Sync_Deadline::stop( $deadline );
			}
		}
		foreach ( $list as $x ) {
			$left = AB_MCP_Sync_Deadline::left( $deadline );
			if ( true !== $left ) {
				break;
			}
		}
		if ( true !== $left ) {
			return $left;
		}
		$whole = true;
		$e     = self::fach( $deadline, $list, $whole );
		$s     = AB_MCP_Sync_Deadline::stop( $deadline );
		if ( null !== $s ) {
			return $s;
		}
		return array( $a, $e, $whole );
	}
	private static function fach( $deadline, $list, &$whole ) {
		foreach ( $list as $x ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				$whole = null;
				return array();
			}
		}
		foreach ( $list as $x ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				$whole = true;
				return array();
			}
		}
		foreach ( $list as $x ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return array();
			}
		}
		foreach ( $list as $x ) {
			if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
				$whole = null;
				return array(); // The same answer again: its second place.
			}
		}
		return $list;
	}
	private static function can( $deadline ) {
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		return 1;
	}
}
PHP;
		$kind  = array( 'is_wp_error' => array( 'sonst', false ) );
		$lists = array(
			'CHECKS'   => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
			'FACHWERT' => array( 'AB_MCP_Sync_Code_Swap::fach' => array( 'array()', 'In the self-test.' ) ),
			'ANSWERS'  => array(
				'AB_MCP_Sync_Code_Swap::fach'    => array( self::answer( '$whole = null; return array();', 2 ) ),
				'AB_MCP_Sync_Code_Swap::prepare' => array( self::answer( 'return AB_MCP_Sync_Deadline::stop( $deadline );', 1 ) ),
			),
		);
		$own   = self::own( $code, $kind, $lists );
		self::assertSame( array(), $own['model']['problems'] );
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::fach'    => array(
					// Its value with its side value, as its entry holds them: green (two places). The side value
					// turned (P2, P3); the value without its side value: red.
					'R2 foreach ( $list as $x )',
					'R2 foreach ( $list as $x )',
				),
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// return $v; alone: green. $v = true; return $v; (Codex round 2, no. 1); $alias = true; return
					// $v; (P1); the halt query of the deadline of the entry after a call (E27): red. In the loop
					// the entry, and break; with return $left; right after the loop: green.
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
				),
			),
			$own['open']
		);
		// Each branch accepted tells its form (N7): the function, the line, the identifier (a side value in it),
		// by function and place in its order.
		$halts = array();
		foreach ( $own['rules']['halts'] as $halt ) {
			self::assertSame( 'includes/a.php', $halt['file'] );
			$halts[] = $halt['fn'] . ':' . $halt['line'] . ' ' . $halt['form'];
		}
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::fach:48 LISTED $whole = null ; return array ( ) ;',
				'AB_MCP_Sync_Code_Swap::fach:65 LISTED $whole = null ; return array ( ) ;',
				'AB_MCP_Sync_Code_Swap::prepare:6 SAME',
				'AB_MCP_Sync_Code_Swap::prepare:25 LISTED return AB_MCP_Sync_Deadline :: stop ( $deadline ) ;',
				'AB_MCP_Sync_Code_Swap::prepare:31 BREAK',
				'AB_MCP_Sync_Code_Swap::prepare:35 SAME',
				'AB_MCP_Sync_Code_Swap::prepare:41 SAME',
			),
			$halts
		);
		self::assertSame(
			array(
				'BREAK'                                                       => 1,
				'LISTED $whole = null ; return array ( ) ;'                   => 2,
				'LISTED return AB_MCP_Sync_Deadline :: stop ( $deadline ) ;' => 1,
				'SAME'                                                        => 3,
			),
			ReadBudgetRules::forms( $own['rules']['halts'] )
		);
		self::assertSame( 'SAME', ReadBudgetRules::FORM_SAME );
		self::assertSame( 'BREAK', ReadBudgetRules::FORM_BREAK );
		self::assertSame( 'LISTED', ReadBudgetRules::FORM_LISTED );

		// The list ANSWERS is closed and counted: a place more or less than the entry says, an entry not used, an
		// entry twice, an entry without its four parts, a function without an entry, and in a function of FACHWERT
		// an answer that does not end with the return of its value there.
		$with = static function ( array $answers ) use ( $code, $kind, $lists ): array {
			$lists['ANSWERS'] = $answers;
			return self::own( $code, $kind, $lists )['rules']['problems'];
		};
		$stop = self::answer( 'return AB_MCP_Sync_Deadline::stop( $deadline );', 1 );
		$side = self::answer( '$whole = null; return array();', 2 );
		self::assertSame(
			array(
				'ANSWERS AB_MCP_Sync_Code_Swap::fach #1: 2 places, the list 3: $whole = null ; return array ( ) ;',
				'ANSWERS not used: AB_MCP_Sync_Code_Swap::fach #2 return array ( ) ; return array ( ) ;',
				'ANSWERS AB_MCP_Sync_Code_Swap::fach #3: twice in its function: $whole = null ; return array ( ) ;',
				'ANSWERS AB_MCP_Sync_Code_Swap::fach #4: not the statements, the variable of the halt or null, the number of its places and the reason',
				'ANSWERS not used: AB_MCP_Sync_Code_Swap::fach #5 $whole = null ; return null ;',
				'ANSWERS AB_MCP_Sync_Code_Swap::fach #5: in a function of FACHWERT the answer ends with the return of its value there (array()): $whole = null ; return null ;',
				'ANSWERS AB_MCP_Sync_Code_Swap::can: no entry',
			),
			$with(
				array(
					'AB_MCP_Sync_Code_Swap::fach'    => array(
						self::answer( '$whole = null; return array();', 3 ),
						self::answer( 'return array(); return array();', 1 ),
						$side,
						array( '$whole = null; return array();', 2, 'In the self-test.' ),
						self::answer( '$whole = null; return null;', 1 ),
					),
					'AB_MCP_Sync_Code_Swap::prepare' => array( $stop ),
					'AB_MCP_Sync_Code_Swap::can'     => array(),
				)
			)
		);
		// With the entry of the value alone the third loop is green and the list tells nothing; an entry does not
		// make the turned side value green.
		$own = self::own(
			$code,
			$kind,
			array(
				'ANSWERS' => array(
					'AB_MCP_Sync_Code_Swap::fach'    => array( $side, self::answer( 'return array();', 1 ) ),
					'AB_MCP_Sync_Code_Swap::prepare' => array( $stop ),
				),
			) + $lists
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame( array( 'R2 foreach ( $list as $x )' ), $own['open']['AB_MCP_Sync_Code_Swap::fach'] );
	}

	public function testATranslationOfAHaltIsItsWholeCallOfTheList(): void {
		// Part 2.3c (Codex round 2, no. 2, SW:945): a translation is an entry of ANSWERS, its whole call token by
		// token, at a halt held in the variable the entry names. The text of the halt in a branch of a ternary that
		// never runs, another text, the code alone, or the entry at a halt held in another variable: red.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $unit ) {
		$signed = self::can( $deadline );
		if ( is_wp_error( $signed ) ) {
			/* translators: 1: why, 2: the unit. */
			return self::refusal( $signed->get_error_code(), sprintf( '%1$s So %2$s is not swapped.', $signed->get_error_message(), $unit ) );
		}
		$a = self::can( $deadline );
		if ( is_wp_error( $a ) ) {
			return self::refusal( $a->get_error_code(), sprintf( '%1$s So %2$s is not swapped.', ( false ? $a->get_error_message() : '' ), $unit ) );
		}
		$b = self::can( $deadline );
		if ( is_wp_error( $b ) ) {
			return self::refusal( $b->get_error_code(), 'Not read. ' . $b->get_error_message() );
		}
		$c = self::can( $deadline );
		if ( is_wp_error( $c ) ) {
			return self::refusal( $signed->get_error_code(), sprintf( '%1$s So %2$s is not swapped.', $signed->get_error_message(), $unit ) );
		}
		return $unit;
	}
	private static function can( $deadline ) {
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true !== $left ) {
			return $left;
		}
		return 1;
	}
	private static function refusal( $code, $text ) {
		return array( $code, $text );
	}
}
PHP;
		$kind  = array(
			'is_wp_error'                 => array( 'sonst', false ),
			'sprintf'                     => array( 'sonst', false ),
			'WP_Error->get_error_code'    => array( 'sonst', false ),
			'WP_Error->get_error_message' => array( 'sonst', false ),
		);
		$hand  = array(
			'INST_BYNAME' => array(
				'get_error_code'    => 'WP_Error',
				'get_error_message' => 'WP_Error',
			),
		);
		$entry = 'return self::refusal( $signed->get_error_code(), sprintf( \'%1$s So %2$s is not swapped.\', $signed->get_error_message(), $unit ) );';
		$lists = array(
			'CHECKS'  => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
			'ANSWERS' => array( 'AB_MCP_Sync_Code_Swap::prepare' => array( self::answer( $entry, 1, '$signed' ) ) ),
		);
		$own   = self::own( $code, $kind, $lists, $hand );
		self::assertSame( array(), $own['model']['problems'] );
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// The whole call of the entry at the halt in $signed: green. The text in a dead branch of a
					// ternary (Codex round 2, no. 2); another call that holds the code and the text (green in part
					// 2.3a, E23); the entry at a halt held in $c: red.
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
					'R7 AB_MCP_Sync_Code_Swap::can',
				),
			),
			$own['open']
		);
		self::assertSame( array( 'LISTED return self :: refusal ( $signed -> get_error_code ( ) , sprintf ( \'%1$s So %2$s is not swapped.\' , $signed -> get_error_message ( ) , $unit ) ) ;' => 1 ), ReadBudgetRules::forms( $own['rules']['halts'] ) );
		// Without the variable of the halt in the entry the answer at the halt in $c would be its second place:
		// the entry names the variable, so the halt answered is the halt translated.
		$lists['ANSWERS']['AB_MCP_Sync_Code_Swap::prepare'] = array( self::answer( $entry, 1 ) );
		self::assertSame(
			array( 'ANSWERS AB_MCP_Sync_Code_Swap::prepare #1: 2 places, the list 1: return self :: refusal ( $signed -> get_error_code ( ) , sprintf ( \'%1$s So %2$s is not swapped.\' , $signed -> get_error_message ( ) , $unit ) ) ;' ),
			self::own( $code, $kind, $lists, $hand )['rules']['problems']
		);
		// A return without a value answers nothing, also with an entry of its own.
		$bare = str_replace( 'return self::refusal( $b->get_error_code(), \'Not read. \' . $b->get_error_message() );', 'return;', $code );
		self::assertNotSame( $code, $bare );
		$lists['ANSWERS']['AB_MCP_Sync_Code_Swap::prepare'] = array( self::answer( $entry, 1, '$signed' ), self::answer( 'return;', 1 ) );
		$own = self::own( $bare, $kind, $lists, $hand );
		self::assertSame( array( 'ANSWERS not used: AB_MCP_Sync_Code_Swap::prepare #2 return ;' ), $own['rules']['problems'] );
		self::assertCount( 3, $own['open']['AB_MCP_Sync_Code_Swap::prepare'] );
	}

	public function testAValueOfFachwertReturnedWholeDoesNotPassTheHaltOn(): void {
		// Part 2.3c (Codex round 2, no. 3): R7 by its wording. After a call of a function of FACHWERT the next
		// statement asks the halt; the call returned whole (or as a branch of a ternary, or in a switch) is no
		// form, also not in a function of FACHWERT with the same answer (E26 of part 2.3a is dropped: SH:103, 105,
		// 107, 109, 397, SI:1141, FM:387). A halt query after the return does not run and does not count.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $x ) {
		switch ( $x ) {
			case 'a':
				return self::fach( $deadline );
			case 'b':
				return self::fach( $deadline );
		}
		if ( null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return null;
		}
		$v = self::fine( $deadline );
		if ( null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return null;
		}
		$w = self::whole( $deadline );
		if ( null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return null;
		}
		return self::rest( $deadline, $x );
	}
	private static function rest( $deadline, $x ) {
		return is_string( $x ) ? self::fach( $deadline ) : null;
	}
	private static function whole( $deadline ) {
		return self::fach( $deadline );
	}
	private static function fine( $deadline ) {
		$v = self::fach( $deadline );
		if ( null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
			return null;
		}
		return $v;
	}
	private static function fach( $deadline ) {
		if ( true !== AB_MCP_Sync_Deadline::left( $deadline ) ) {
			return null;
		}
		return 1;
	}
}
PHP;
		$fach = array( 'null', 'In the self-test.' );
		$own  = self::own(
			$code,
			array( 'is_string' => array( 'sonst', false ) ),
			array(
				'CHECKS'   => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
				'FACHWERT' => array(
					'AB_MCP_Sync_Code_Swap::rest'  => $fach,
					'AB_MCP_Sync_Code_Swap::whole' => $fach,
					'AB_MCP_Sync_Code_Swap::fine'  => $fach,
					'AB_MCP_Sync_Code_Swap::fach'  => $fach,
				),
				'ANSWERS'  => array(
					'AB_MCP_Sync_Code_Swap::fine'    => array( self::answer( 'return null;', 1 ) ),
					'AB_MCP_Sync_Code_Swap::prepare' => array( self::answer( 'return null;', 2 ) ),
				),
			)
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				// In fine(): assigned, the halt asked, then returned (what the part that changes the function
				// writes): green. In a switch (SH:103 to 109): red, the second too although a halt query stands after the switch
				// (after a return no next statement runs); as the last statement (SH:397): red.
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					'R7 AB_MCP_Sync_Code_Swap::fach',
					'R7 AB_MCP_Sync_Code_Swap::fach',
					'R7 AB_MCP_Sync_Code_Swap::rest',
				),
				// As a branch of a ternary (SI:1141): red.
				'AB_MCP_Sync_Code_Swap::rest'    => array( 'R7 AB_MCP_Sync_Code_Swap::fach' ),
				// Returned whole in a function of FACHWERT with the same answer (FM:387): red.
				'AB_MCP_Sync_Code_Swap::whole'   => array( 'R7 AB_MCP_Sync_Code_Swap::fach' ),
			),
			$own['open']
		);
	}

	public function testAHaltAskedAfterATernaryHasTheRightPolarity(): void {
		// Part 2.3c, found on the way (of the same kind as Codex A2): after $v = HALT ? X : …; the next statement
		// tests X, or asks the halt itself with the right polarity after pure disjuncts only; a halt query in
		// another form (null === stop(), a check not in its form) passes no halt on.
		$code = <<<'PHP'
<?php
final class AB_MCP_Sync_Code_Swap {
	public static function prepare( $deadline, $list ) {
		foreach ( $list as $a ) {
			$r = true !== AB_MCP_Sync_Deadline::left( $deadline ) ? null : strlen( $a );
			if ( null === $r ) {
				return AB_MCP_Sync_Deadline::stop( $deadline );
			}
		}
		foreach ( $list as $a ) {
			$r = true !== AB_MCP_Sync_Deadline::left( $deadline ) ? null : strlen( $a );
			if ( ! is_string( $a ) || null !== AB_MCP_Sync_Deadline::stop( $deadline ) ) {
				return AB_MCP_Sync_Deadline::stop( $deadline );
			}
		}
		foreach ( $list as $a ) {
			$r = true !== AB_MCP_Sync_Deadline::left( $deadline ) ? null : strlen( $a );
			if ( null === AB_MCP_Sync_Deadline::stop( $deadline ) ) {
				return AB_MCP_Sync_Deadline::stop( $deadline );
			}
		}
		foreach ( $list as $a ) {
			$r = true !== AB_MCP_Sync_Deadline::left( $deadline ) ? null : strlen( $a );
			if ( true === AB_MCP_Sync_Deadline::left( $deadline ) ) {
				return AB_MCP_Sync_Deadline::stop( $deadline );
			}
		}
		foreach ( $list as $a ) {
			$r = true !== AB_MCP_Sync_Deadline::left( $deadline ) ? null : strlen( $a );
			if ( true === AB_MCP_Sync_Deadline::left( $deadline ) || null === $r ) {
				return AB_MCP_Sync_Deadline::stop( $deadline );
			}
		}
		return true;
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
				'CHECKS'  => array( 'AB_MCP_Sync_Deadline::left' => 'wp' ),
				'ANSWERS' => array( 'AB_MCP_Sync_Code_Swap::prepare' => array( self::answer( 'return AB_MCP_Sync_Deadline::stop( $deadline );', 2 ) ) ),
			)
		);
		self::assertSame( array(), $own['rules']['problems'] );
		self::assertSame(
			array(
				'AB_MCP_Sync_Code_Swap::prepare' => array(
					// X tested, and the halt asked with the right polarity after a pure disjunct: green. null ===
					// stop(); the test that there is no halt; a check before the test of X (asked again after the
					// halt): red.
					'R2 foreach ( $list as $a )',
					'R2 foreach ( $list as $a )',
					'R2 foreach ( $list as $a )',
				),
			),
			$own['open']
		);
	}

	public function testEachBranchAcceptedAtAHaltAtTheHeadTellsItsForm(): void {
		// Part 2.3c (the boundary N7 of the order): the guard gives for each branch at a halt it accepts the
		// function, the line and the identifier of its form, so that part 7 gives each form a test that triggers
		// the halt and a mutation. The listed forms are those of ANSWERS, each at as many places as its entries say.
		$halts = self::rules()['halts'];
		$lists = self::lists();
		self::assertNotSame( array(), $halts );
		$have = array();
		foreach ( $halts as $halt ) {
			self::assertSame( array( 'fn', 'file', 'line', 'pos', 'form' ), array_keys( $halt ) );
			self::assertArrayHasKey( $halt['fn'], self::model()['reach'], $halt['fn'] );
			self::assertArrayNotHasKey( $halt['fn'], self::model()['chain'], $halt['fn'] );
			self::assertSame( self::model()['methods'][ $halt['fn'] ]['file'], $halt['file'], $halt['fn'] );
			self::assertGreaterThan( 0, $halt['line'], $halt['fn'] );
			self::assertMatchesRegularExpression( '/^(SAME|BREAK|LISTED \S.* ;)$/', $halt['form'], $halt['fn'] . ':' . $halt['line'] );
			if ( 0 === strpos( $halt['form'], ReadBudgetRules::FORM_LISTED . ' ' ) ) {
				$key = $halt['fn'] . ' ' . $halt['form'];
				if ( ! isset( $have[ $key ] ) ) {
					$have[ $key ] = 0;
				}
				++$have[ $key ];
			}
		}
		$want = array();
		foreach ( $lists['ANSWERS'] as $fn => $entries ) {
			foreach ( $entries as $entry ) {
				$words = array();
				foreach ( \PhpToken::tokenize( '<?php ' . $entry[0] ) as $token ) {
					if ( ! $token->isIgnorable() && T_OPEN_TAG !== $token->id ) {
						$words[] = $token->text;
					}
				}
				$key = $fn . ' ' . ReadBudgetRules::FORM_LISTED . ' ' . implode( ' ', $words );
				if ( ! isset( $want[ $key ] ) ) {
					$want[ $key ] = 0;
				}
				$want[ $key ] += $entry[2];
			}
		}
		ksort( $have );
		ksort( $want );
		self::assertSame( $want, $have, 'The listed forms of the branches accepted at a halt are the entries of ANSWERS, each at its places.' );
		$forms = ReadBudgetRules::forms( $halts );
		self::assertSame( count( $halts ), array_sum( $forms ) );
		self::assertArrayHasKey( ReadBudgetRules::FORM_SAME, $forms, 'return $v; alone, $v the variable that holds the halt.' );
		// The side values of the answers are in the identifiers (PS:4188 and 4196, CN:812).
		self::assertArrayHasKey( 'LISTED $whole = null ; return array ( ) ;', $forms );
		self::assertArrayHasKey( 'LISTED $ok = false ; return null ;', $forms );
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
