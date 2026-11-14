<?php
/**
 * The guard of the read budget (R12b, part 2; the order belege/
 * auftrag-teil-r12b.txt, section 3.2): outside the chain of the check of
 * names every read from the disk, step of processing and pass of a loop on
 * the ways of R12 begins only after the one deadline is checked right
 * before it. The guard measures this on the source, without control flow.
 *
 * This part (2.1) is its reader and its basis: the reader
 * (tests/ReadBudgetScan.php) reads every way of R12 without a problem, and
 * what it counts there is what the lists of tests/ReadBudgetSoll.php say,
 * both ways, each entry used: G1 the functions outside the chain, the chain,
 * the boundaries and cut edges met, G2 the steps, G3 the loops, G4 the
 * recursions, G5 the entries into the chain, G6 the dynamic calls, G7 the
 * checks; and the steps are the closed list of the order (section 1). The
 * rules R1 to R7 over this basis are part 2.2.
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
}
