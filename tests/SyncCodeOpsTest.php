<?php
/**
 * What a step of the code reads before it does anything (Z8b branch 5; plan
 * E8, E9, 3.1 Z24; the pre-check of branch 5, V18 and V19): its unit from
 * the plan of its run (AB_MCP_Sync_Code_Ops::item()), its stage from its
 * journal (staged()), the answer of the check of its stage (checked(),
 * ready only without a refusal), whether code of a unit runs on this site
 * (AB_MCP_Sync_Code_Swap::runs(), from the rows of the options through the
 * seam of the swap), and how two versions stand to each other (order()).
 * Each of them in the form of its row of AB_MCP_Store_Inputs::TABLE; what is
 * not of that form is not known, never a value (StoreInputsTest drives every
 * wrong form of each row at these consumers).
 *
 * Fault kinds by effect (plan 8.3): rows and options read (return false: no
 * row; read error: null; a value half written or of another form). They
 * write nothing, so exit/fatal, time limit and concurrent writer do not
 * apply here; a stylesheet switched while it is read is the one writer
 * beside the reading (theme()).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Shape;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Code_Ops;
use AB_MCP_Sync_Code_Plan;
use AB_MCP_Sync_Code_Stage;
use AB_MCP_Sync_Code_Swap;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/includes/sync/class-sync-code-ops.php';
require_once dirname( __DIR__ ) . '/includes/sync/class-sync-code-plan.php';

final class SyncCodeOpsTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	protected function tearDown(): void {
		AB_MCP_Sync_Code_Swap::forget();
	}

	/**
	 * A stand-in of the calls of the swap: the rows of the options as the
	 * test gives them (a row, false for none, null for one that cannot be
	 * read; a list for one answer per reading of the same option).
	 *
	 * @param array<string,mixed> $rows Option => what its reading answers.
	 */
	public static function rows( array $rows ): object {
		$calls = new class() {
			/** @var array<string,mixed> */
			public $rows = array();
			/** @var string[] */
			public $read = array();
			public function option( $name ) {
				$this->read[] = (string) $name;
				$row          = $this->rows[ $name ] ?? false;
				if ( is_array( $row ) && array_key_exists( 0, $row ) ) {
					$row = count( $this->rows[ $name ] ) > 1 ? array_shift( $this->rows[ $name ] ) : $row[0];
				}
				return is_string( $row ) ? array( 'value' => $row, 'autoload' => 'yes' ) : $row;
			}
		};
		$calls->rows = $rows;
		$seam        = new \ReflectionProperty( AB_MCP_Sync_Code_Swap::class, 'naht_sync_code_swap' );
		if ( PHP_VERSION_ID < 80100 ) {
			$seam->setAccessible( true );
		}
		$seam->setValue( null, $calls );
		return $calls;
	}

	/** An object that is no text but turns into this one where it is cast. */
	private static function stringable( string $text ): object {
		return new class( $text ) {
			/** @var string */
			private $text;
			public function __construct( string $text ) {
				$this->text = $text;
			}
			public function __toString(): string {
				return $this->text;
			}
		};
	}

	/** A valid unit of a plan of the code. */
	public static function unit( array $over = array() ): array {
		return $over + array(
			'seq'          => 2,
			'op'           => 'stage',
			'stage_seq'    => null,
			'n'            => 7,
			'kind'         => 'plugin',
			'ref'          => 'shop',
			'unit_op'      => 'update',
			'pair_id'      => 5,
			'pair_hash'    => str_repeat( 'a', 64 ),
			'staging_hash' => str_repeat( 'b', 64 ),
			'version'      => array(
				'staging' => '2.1',
				'live'    => '2.0',
			),
			'runs'         => true,
			'accept'       => true,
		);
	}

	/** The code of a plan with a stage and a swap of one unit. */
	public static function code( array $over = array() ): array {
		return $over + array(
			'env'      => array( str_repeat( 'e', 32 ) ),
			'packages' => array(),
			'units'    => array( self::unit(), self::unit( array( 'seq' => 3, 'op' => 'swap', 'stage_seq' => 2 ) ) ),
		);
	}

	/** A valid journal of a stage step. */
	public static function journal( array $stage = array(), array $unit = array() ): array {
		return array(
			'unit'  => $unit + array(
				'kind' => 'plugin',
				'ref'  => 'shop',
				'op'   => 'stage',
			),
			'stage' => $stage + array(
				'gid'    => str_repeat( 'c', 32 ),
				'name'   => 'shop',
				'hash'   => str_repeat( 'd', 64 ),
				'source' => 'wordpress.org',
				'files'  => 3,
				'bytes'  => 120,
			),
		);
	}

	public function testTheUnitOfAStepComesFromThePlanOfItsRunByTheNumberOfTheStep(): void {
		// A run of the contents: its plan holds no code, and item() says so
		// (the engine goes on with its objects).
		self::assertNull( AB_MCP_Sync_Code_Ops::item( array( 'objects' => array() ), 2 ) );
		$plan = array( 'objects' => array(), 'code' => self::code() );
		$item = AB_MCP_Sync_Code_Ops::item( $plan, 3 );
		self::assertSame( array( 'swap', 2, 'plugin', 'shop' ), array( $item['unit']['op'], $item['unit']['stage_seq'], $item['unit']['kind'], $item['unit']['ref'] ) );
		self::assertSame( array( array( str_repeat( 'e', 32 ) ), array() ), array( $item['env'], $item['packages'] ) );
		self::assertSame( 'stage', AB_MCP_Sync_Code_Ops::item( $plan, 2 )['unit']['op'] );
		// A plan of the code that names no unit for the step, or does not
		// read in its form: refused with the way, never taken for a step of
		// the contents (V18), never another unit.
		foreach ( array( 4, 1, 0, '2', null ) as $seq ) {
			$none = AB_MCP_Sync_Code_Ops::item( $plan, $seq );
			self::assertInstanceOf( WP_Error::class, $none, var_export( $seq, true ) );
			self::assertSame( 'ab_mcp_sync_code_plan', $none->get_error_code() );
			self::assertStringContainsString( 'wp_site_push action=abort', $none->get_error_message(), 'With the way.' );
		}
		foreach ( array( null, array(), 'code', self::code( array( 'env' => array() ) ), self::code( array( 'units' => array( self::unit( array( 'kind' => 'mu_plugin' ) ) ) ) ), self::code( array( 'units' => array( self::unit( array( 'op' => 'activate' ) ) ) ) ) ) as $i => $code ) {
			$bad = AB_MCP_Sync_Code_Ops::item( array( 'code' => $code ), 2 );
			self::assertInstanceOf( WP_Error::class, $bad, "#$i" );
			self::assertSame( 'ab_mcp_sync_code_plan', $bad->get_error_code(), "#$i" );
		}
	}

	public function testAnEntryOfAUnitWhoseFieldsDoNotReadInTheirFormPlansNothing(): void {
		// The fields of the entry the plan of the code decides on (which unit,
		// what the report does with it) are read in their form, never cast
		// (Entscheidstellen): a wrong form refuses the plan with the way,
		// before a pair, a package or a row of this site is read.
		self::assertTrue( AB_MCP_Shape::valid_form( AB_MCP_Sync_Code_Plan::ENTRY ) );
		$unit  = new \ReflectionMethod( AB_MCP_Sync_Code_Plan::class, 'unit' );
		$entry = array(
			'kind'    => 'environment',
			'subtype' => 'plugin',
			'ref'     => 'shop',
			'action'  => 'take_over',
			'result'  => 'changed_on_staging',
		);
		$wrong = array(
			'subtype' => array( 5, 'mu_plugin', '', null, array( 'plugin' ) ),
			'ref'     => array( 5, '', null, true, array( 'shop' ) ),
			'action'  => array( 1, '', null, false, array( 'take_over' ) ),
		);
		foreach ( $wrong as $field => $values ) {
			$less = $entry;
			unset( $less[ $field ] );
			$cases = array( 'absent' => $less );
			foreach ( $values as $i => $value ) {
				$cases[ "#$i" ] = array( $field => $value ) + $entry;
			}
			foreach ( $cases as $what => $one ) {
				$got = $unit->invoke( null, array(), 7, $one, array(), array( 'accept' => array(), 'packages' => array() ), array() );
				self::assertInstanceOf( WP_Error::class, $got, "$field $what" );
				self::assertSame( 'ab_mcp_sync_push_report', $got->get_error_code(), "$field $what" );
				self::assertStringContainsString( 'Entry 7 of the report does not read in the form a report of this version writes', $got->get_error_message(), "$field $what" );
				self::assertStringContainsString( 'Make a new report (wp_site_diff) and plan again', $got->get_error_message(), "$field $what: with the way." );
			}
		}
	}

	public function testTheVersionsOfATakeoverAreReadInTheirFormAndAVersionNotKnownOnEitherSideLocks(): void {
		// Reading 2 as the lead decided it (13.11.): two versions known and
		// alike are the same version, a newer one on the staging site an
		// update; none, empty or not of the row unit.version on either side,
		// on both sides too, is not known and locks; an older one locks.
		$live = static fn( $version ): array => array( 'live' => array( 'version' => $version ) );
		self::assertSame( 'edit', AB_MCP_Sync_Code_Plan::version_op( '1.2.0', $live( '1.2.0' ) ) );
		self::assertSame( 'update', AB_MCP_Sync_Code_Plan::version_op( '1.10', $live( '1.2.0' ) ) );
		$older = AB_MCP_Sync_Code_Plan::version_op( '1.0', $live( '1.2.0' ) );
		self::assertInstanceOf( WP_Error::class, $older );
		self::assertSame( 'code:downgrade', $older->get_error_code() );
		self::assertStringContainsString( 'an older version of this unit (1.0) than this site (1.2.0)', $older->get_error_message() );
		$unknown = array(
			"'' on both sides"   => array( '', $live( '' ) ),
			'none on both sides' => array( null, $live( null ) ),
			"'' here"            => array( '1.2.0', $live( '' ) ),
			"'' on staging"      => array( '', $live( '1.2.0' ) ),
			'none here'          => array( '1.2.0', $live( null ) ),
			'none on staging'    => array( null, $live( '1.2.0' ) ),
			'a number'           => array( 1.2, $live( '1.2.0' ) ),
			'a word here'        => array( '1.2.0', $live( 'beta' ) ),
			'a list here'        => array( '1.2.0', $live( array( '1.2.0' ) ) ),
			'no live line'       => array( '1.2.0', array() ),
			'a live line text'   => array( '1.2.0', array( 'live' => '1.2.0' ) ),
			'no version in it'   => array( '1.2.0', array( 'live' => array() ) ),
		);
		foreach ( $unknown as $what => $case ) {
			$got = AB_MCP_Sync_Code_Plan::version_op( $case[0], $case[1] );
			self::assertInstanceOf( WP_Error::class, $got, $what );
			self::assertSame( 'code:version_unknown', $got->get_error_code(), $what );
			self::assertStringContainsString( 'Give the plugin or theme a Version in its head on both sites', $got->get_error_message(), "$what: with the way." );
		}
	}

	public function testThePairOfATakeoverIsReadInItsFormAndAPairNotNamedSoLocksWithItsOwnReason(): void {
		// The decision of the lead of 13.11. (1a-2, point 4): the pair an entry
		// names is a whole number of 1 or more; none or another form refuses
		// the plan with its own reason, never as a pair 0 that is gone.
		$pair = new \ReflectionMethod( AB_MCP_Sync_Code_Plan::class, 'pair_of' );
		self::assertTrue( AB_MCP_Shape::valid_form( AB_MCP_Sync_Code_Plan::PAIR ) );
		$cases = array(
			'no pair'          => array(),
			'pair null'        => array( 'pair' => null ),
			'pair a number'    => array( 'pair' => 3 ),
			'no id'            => array( 'pair' => array() ),
			'id 0'             => array( 'pair' => array( 'id' => 0 ) ),
			'id negative'      => array( 'pair' => array( 'id' => -1 ) ),
			'id as text'       => array( 'pair' => array( 'id' => '3' ) ),
			'id a float'       => array( 'pair' => array( 'id' => 3.0 ) ),
			'id null'          => array( 'pair' => array( 'id' => null ) ),
			'id true'          => array( 'pair' => array( 'id' => true ) ),
		);
		foreach ( $cases as $what => $entry ) {
			$got = $pair->invoke( null, $entry + array( 'subtype' => 'plugin', 'ref' => 'shop' ), 7, 'plugin', 'shop', array( 'source' => 'b' ) );
			self::assertInstanceOf( WP_Error::class, $got, $what );
			self::assertSame( 'ab_mcp_sync_push_pair', $got->get_error_code(), $what );
			self::assertStringContainsString( 'Entry 7 of the report names no pair in the form a report of this version writes it', $got->get_error_message(), $what );
			self::assertStringContainsString( 'Make a new report (wp_site_diff) and plan again', $got->get_error_message(), "$what: with the way." );
			self::assertStringNotContainsString( 'no longer there', $got->get_error_message(), "$what: not a pair that is gone." );
		}
	}

	public function testThePathsThatGoIntoTheBackupReadInTheirFormAndMoreIsNeverMadeUp(): void {
		// Reading 4 and the decision of the lead of 13.11. (1a-2, point 5): the
		// list only_live of the entry is whole where more is not there; more,
		// where it is, is a whole number of 1 or more; any other value and a
		// list that is not whole without it are not known (null: locked).
		$gone  = new \ReflectionMethod( AB_MCP_Sync_Code_Plan::class, 'gone' );
		$entry = static fn( array $paths ): array => array( 'subtype' => 'plugin', 'ref' => 'shop', 'paths' => $paths );
		$whole = array( 'only_live' => array( 'a.txt', 'b.txt' ), 'only_live_count' => 2 );
		self::assertSame( array( 'a.txt', 'b.txt' ), $gone->invoke( null, array(), 7, $entry( $whole ) ), 'Whole without more: the normal case.' );
		self::assertSame( array(), $gone->invoke( null, array(), 7, $entry( array( 'only_live' => array(), 'only_live_count' => 0 ) ) ) );
		$cases = array(
			'not whole without more' => $entry( array( 'only_live_count' => 3 ) + $whole ),
			'more 0'                 => $entry( $whole + array( 'more' => 0 ) ),
			'more negative'          => $entry( $whole + array( 'more' => -1 ) ),
			'more as text'           => $entry( $whole + array( 'more' => '1' ) ),
			'more a float'           => $entry( $whole + array( 'more' => 1.0 ) ),
			'more null'              => $entry( $whole + array( 'more' => null ) ),
			'more true'              => $entry( $whole + array( 'more' => true ) ),
			'no paths'               => array( 'subtype' => 'plugin', 'ref' => 'shop' ),
			'paths a text'           => array( 'subtype' => 'plugin', 'ref' => 'shop', 'paths' => 'a.txt' ),
			'no count'               => $entry( array( 'only_live' => array( 'a.txt' ) ) ),
			'a count as text'        => $entry( array( 'only_live_count' => '2' ) + $whole ),
			'an empty path'          => $entry( array( 'only_live' => array( 'a.txt', '' ) ) + $whole ),
		);
		foreach ( $cases as $what => $one ) {
			self::assertNull( $gone->invoke( null, array(), 7, $one ), $what );
		}
	}

	public function testThePathsThatGoIntoTheBackupAreReadNowAndNamedOnlyWhereTheyAreThoseOfTheReport(): void {
		// A1 of Codex round 1 on the code (the decision of the lead of 13.11.):
		// the paths here that the signed manifest lacks, as read now, compared
		// name by name with those of the report; a name a rule holds is the
		// same path by its SHA-256, on either side. Not read whole is not
		// known, never «none»; each lock has its own reason.
		$to     = new \ReflectionMethod( AB_MCP_Sync_Code_Plan::class, 'to_backup' );
		$h      = static fn( string $path ): string => 'sha256:' . hash( 'sha256', $path );
		$entry  = static fn( array $only ): array => array( 'subtype' => 'plugin', 'ref' => 'shop', 'paths' => array( 'only_live' => $only, 'only_live_count' => count( $only ) ) );
		$signed = array( array( 'shop.php', 10, str_repeat( 'a', 64 ) ), array( $h( 'key=1.txt' ), 3, str_repeat( 'b', 64 ) ) );
		// Two paths only here, read and said each in an order that is not
		// the one of their SHA-256: the comparison is by name, not by order.
		$two = array( 'alt.txt', 'pw=2.txt' );
		usort( $two, static fn( string $a, string $b ): int => strcmp( $h( $b ), $h( $a ) ) );
		$said = array();
		foreach ( $two as $path ) {
			$said[] = 'pw=2.txt' === $path ? $h( $path ) : $path;
		}
		$here = array_merge( array( 'shop.php', 'key=1.txt' ), $two );
		self::assertSame( $said, $to->invoke( null, array(), 7, $entry( $said ), $signed, $here ), 'The normal case: named as the report names them, a name held on either side alike.' );
		self::assertSame( array(), $to->invoke( null, array(), 7, $entry( array() ), $signed, array( 'shop.php', 'key=1.txt' ) ), 'None here that the new version lacks.' );
		// A file named like a hash (sha256: and 64 hex digits) is a name the
		// report holds by its SHA-256 (AB_MCP_Sync_Env::path_name()), here and
		// in the signed manifest alike: a path read here is always hashed.
		$like = 'sha256:' . str_repeat( 'c', 64 );
		$only = 'sha256:' . str_repeat( 'd', 64 );
		$both = array_merge( $signed, array( array( $h( $like ), 4, str_repeat( 'e', 64 ) ) ) );
		self::assertSame( array( $h( $only ) ), $to->invoke( null, array(), 7, $entry( array( $h( $only ) ) ), $both, array( 'shop.php', 'key=1.txt', $like, $only ) ), 'A name like a hash, on both sides and only here.' );
		$code  = static function ( $got ): string {
			self::assertInstanceOf( WP_Error::class, $got );
			return $got->get_error_code();
		};
		$way   = array(
			'code:paths_changed' => array( 'Make a new report (wp_site_diff), then plan again' ),
			'code:paths_unread'  => array( 'Where the time of this call did not suffice, plan again (wp_site_push action=plan)', 'cannot be read: make it readable, then plan again', 'where the unit is no longer on this site, make a new report (wp_site_diff)' ),
		);
		$cases = array(
			'a path added here'                  => array( $said, array_merge( $here, array( 'neu.txt' ) ), 'code:paths_changed' ),
			'a path of the report gone here'     => array( $said, array( 'shop.php', 'key=1.txt', 'alt.txt' ), 'code:paths_changed' ),
			'none in the report, one here'       => array( array(), array( 'shop.php', 'key=1.txt', 'alt.txt' ), 'code:paths_changed' ),
			'not read whole here'                => array( $said, null, 'code:paths_unread' ),
			'not read whole, none in the report' => array( array(), null, 'code:paths_unread' ),
		);
		foreach ( $cases as $what => list( $only, $read, $want ) ) {
			$got = $to->invoke( null, array(), 7, $entry( $only ), $signed, $read );
			self::assertSame( $want, $code( $got ), $what );
			self::assertStringContainsString( '(' . $want . ')', $got->get_error_message(), $what );
			foreach ( $way[ $want ] as $said_way ) {
				self::assertStringContainsString( $said_way, $got->get_error_message(), "$what: with the way of each cause." );
			}
		}
		$unknown = $to->invoke( null, array(), 7, array( 'subtype' => 'plugin', 'ref' => 'shop' ), $signed, $here );
		self::assertSame( 'code:paths_unknown', $code( $unknown ), 'The report does not hold them whole: before anything is read.' );
	}

	public function testAStepOfARunNotOfItsFormOrWithFieldsNotReadStagesNothing(): void {
		// run(): the run of a step comes in the form of runs.run_id (digits
		// of a column of the database are its form), and a
		// step with fields not read (unread) is not known; neither stages
		// nor checks nor swaps anything, the step stays as it is (V19).
		// Both steps: run() reads the run before it branches.
		$cases = array(
			'run_id a word'    => array( array( 'run_id' => '7x' ), array( 'parts' => null ) ),
			'run_id 0'         => array( array( 'run_id' => 0 ), array( 'parts' => null ) ),
			'run_id null'      => array( array( 'run_id' => null ), array( 'parts' => null ) ),
			'run_id a float'   => array( array( 'run_id' => 7.0 ), array( 'parts' => null ) ),
			'a field not read' => array( array( 'run_id' => 7 ), array( 'parts' => null, 'unread' => array( 'parts' ) ) ),
		);
		foreach ( array( 'stage' => array(), 'swap' => array( 'seq' => 3, 'op' => 'swap', 'stage_seq' => 2 ) ) as $op => $over ) {
			$item = array(
				'unit'     => self::unit( $over ),
				'env'      => self::code()['env'],
				'packages' => array(),
			);
			foreach ( $cases as $what => $case ) {
				$got = AB_MCP_Sync_Code_Ops::run( $case[0], $case[1], $item );
				self::assertInstanceOf( WP_Error::class, $got, $op . ', ' . $what );
				self::assertSame( 'ab_mcp_sync_code_journal', $got->get_error_code(), $op . ', ' . $what );
			}
		}
	}

	public function testTheStageOfAStepComesFromItsJournalInItsFormOrIsNotKnown(): void {
		$read = AB_MCP_Sync_Code_Ops::staged( self::journal() );
		self::assertSame( array( str_repeat( 'c', 32 ), 'shop', str_repeat( 'd', 64 ) ), array( $read['stage']['gid'], $read['stage']['name'], $read['stage']['hash'] ) );
		self::assertSame( 'packages', AB_MCP_Sync_Code_Ops::staged( self::journal( array( 'source' => 'packages' ) ) )['stage']['source'] );
		foreach ( array( null, array(), 'x', self::journal( array( 'gid' => 'c' ) ), self::journal( array( 'hash' => str_repeat( 'D', 64 ) ) ), self::journal( array( 'name' => 'a/b' ) ), self::journal( array( 'name' => '' ) ), self::journal( array( 'source' => 'elsewhere' ) ), self::journal( array( 'files' => '3' ) ), self::journal( array(), array( 'op' => 'swap' ) ), self::journal( array(), array( 'kind' => 'language' ) ), array( 'object' => array( 'kind' => 'post' ) ) + self::journal() ) as $i => $journal ) {
			self::assertNull( AB_MCP_Sync_Code_Ops::staged( $journal ), "#$i" );
		}
	}

	public function testTheAnswerOfTheCheckOfAStageIsTakenOnlyInItsFormAndReadyOnlyWithoutARefusal(): void {
		// checked() (B1 of the Fable check 2 on d1e0c77): the answer of
		// AB_MCP_Sync_Code_Stage::complete() as the step takes it; anything
		// else is null, and the step decides nothing on it. complete() of this
		// version gives no other answer (its state reads through state_of(),
		// which holds the same rule), so the step's own reading is driven here
		// directly: ready only without a refusal, refused only with one.
		$checked  = new \ReflectionMethod( AB_MCP_Sync_Code_Ops::class, 'checked' );
		$refusal  = array(
			'code'    => 'code:syntax',
			'message' => 'A syntax error.',
			'path'    => 'shop.php',
			'line'    => 3,
		);
		$ready    = array(
			'state'   => AB_MCP_Sync_Code_Stage::READY,
			'checked' => 2,
			'files'   => 2,
			'notes'   => array(),
			'refusal' => null,
		);
		$refused  = array(
			'state'   => AB_MCP_Sync_Code_Stage::REFUSED,
			'refusal' => $refusal,
		) + $ready;
		$checking = array(
			'state'   => AB_MCP_Sync_Code_Stage::CHECKING,
			'checked' => 1,
		) + $ready;
		$unnamed  = array(
			'refusal' => array(
				'path' => null,
				'line' => null,
			) + $refusal,
		) + $refused;
		foreach ( array( $ready, $refused, $checking, $unnamed ) as $i => $state ) {
			self::assertSame( $state, $checked->invoke( null, $state ), "#$i" );
		}
		$wrong = array(
			'ready with a refusal'       => array( 'refusal' => $refusal ) + $ready,
			'refused without one'        => array( 'refusal' => null ) + $refused,
			'checking with a refusal'    => array( 'refusal' => $refusal ) + $checking,
			'no array'                   => 'ready',
			'a state of no kind'         => array( 'state' => 'done' ) + $ready,
			'checked as digits'          => array( 'checked' => '2' ) + $ready,
			'files missing'              => array_diff_key( $ready, array( 'files' => true ) ),
			'notes no list'              => array( 'notes' => 'none' ) + $ready,
			'refusal missing'            => array_diff_key( $ready, array( 'refusal' => true ) ),
			'refusal no array'           => array( 'refusal' => 'code:syntax' ) + $refused,
			'refusal without its line'   => array( 'refusal' => array_diff_key( $refusal, array( 'line' => true ) ) ) + $refused,
			'refusal code no text'       => array( 'refusal' => array( 'code' => 5 ) + $refusal ) + $refused,
			'refusal path no text'       => array( 'refusal' => array( 'path' => 5 ) + $refusal ) + $refused,
			'refusal line as digits'     => array( 'refusal' => array( 'line' => '3' ) + $refusal ) + $refused,
			'refusal message missing'    => array( 'refusal' => array_diff_key( $refusal, array( 'message' => true ) ) ) + $refused,
		);
		foreach ( $wrong as $what => $state ) {
			self::assertNull( $checked->invoke( null, $state ), $what );
		}
	}

	/** The refusal of a stage whose packages do not hold every file, as AB_MCP_Sync_Code_Stage::from_packages() words it. */
	private const F43_WHY = 'The packages of code and the live unit do not hold 2000 files of the unit as the staging site has them, so it is not staged. Export them on the staging site (wp_site_export with purpose=code, this unit and the paths named here), take the package over here, then stage again with every package of it.';

	/** The result of a step of F43 that names every path (message, code, paths), as it read before the list was bounded. */
	private static function f43_whole( string $why, array $paths ): array {
		return array(
			'message' => 'The plugin shop is not staged (code:stage_incomplete), so it is not swapped; nothing of this site changed. ' . $why . ' The paths of the files missing here (the first 2000 where more are missing), for paths of wp_site_export: ' . json_encode( $paths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '. This run does not stage the plugin shop again: plan a new run (wp_site_push action=plan) with every package of the unit, the one with these files as well; where this run is still open, abort it first (wp_site_push action=abort).',
			'code'    => 'code:stage_incomplete',
			'paths'   => $paths,
		);
	}

	/** The result of a step of F43 that names the first $n paths only: how many it names, how many it leaves out, and the way to those. */
	private static function f43_cut( string $why, array $paths, int $n ): array {
		$first = array_slice( $paths, 0, $n );
		return array(
			'message' => 'The plugin shop is not staged (code:stage_incomplete), so it is not swapped; nothing of this site changed. ' . $why . ' The paths of the files missing here, the first ' . $n . ' of them, for paths of wp_site_export: ' . json_encode( $first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '. The result of a step holds at most 8388608 bytes as stored, so this list leaves out ' . ( count( $paths ) - $n ) . ' of the paths it takes (the first 2000 where more are missing). This run does not stage the plugin shop again: plan a new run (wp_site_push action=plan) with every package of the unit, the one with the files named here as well; where this run is still open, abort it first (wp_site_push action=abort). Where files are still missing then, the new run names the next ones in the same way: export those as well and plan again, until the unit is staged.',
			'code'    => 'code:stage_incomplete',
			'paths'   => $first,
		);
	}

	/** The bytes a result of a step takes as the run stores it. */
	private static function stored( array $result ): int {
		return strlen( AB_MCP_Sync_Canon::encode( $result ) );
	}

	/**
	 * The refusal and 1999 paths whose answer with every path named takes,
	 * as stored, $over bytes more than a step holds. The paths are mostly
	 * quotation marks (six bytes each as stored, so the texts stay small);
	 * the bytes are met with letters at the end of the paths, two bytes of
	 * the result each (one in the message, one in paths), and an odd rest
	 * with one byte of the refusal.
	 *
	 * @return array{0:string,1:string[]}
	 */
	private static function f43_at( int $over ): array {
		$paths = array();
		for ( $i = 0; $i < 1999; $i++ ) {
			$paths[] = sprintf( '%04d/', $i ) . str_repeat( str_repeat( '"', 170 ) . '/', 4 ) . str_repeat( 'a', 20 );
		}
		$gap = AB_MCP_Sync_Run::MAX_PARTS + $over - self::stored( self::f43_whole( self::F43_WHY, $paths ) );
		self::assertGreaterThan( 0, $gap );
		$why     = self::F43_WHY . ( 1 === $gap % 2 ? '!' : '' );
		$letters = intdiv( $gap, 2 );
		foreach ( $paths as $i => $path ) {
			$paths[ $i ] = $path . str_repeat( 'a', intdiv( $letters, 1999 ) + ( $i < $letters % 1999 ? 1 : 0 ) );
		}
		return array( $why, $paths );
	}

	/** The cases of F43 by name: the refusal, the paths of the files missing, and whether the list is cut. */
	private static function f43_case( string $case ): array {
		switch ( $case ) {
			case 'three paths':
				return array( self::F43_WHY, array( 'viele/f1.txt', 'ein "Name"/mit \\ und ä.php', 'a/b/c.css' ), false );
			case '2000 paths of a usual length':
				return array( self::F43_WHY, array_map( static fn( int $i ): string => sprintf( 'includes/modules/module-%04d/class-module-%04d-controller.php', $i, $i ), range( 0, 1999 ) ), false );
			case 'a result of exactly the bytes a step holds':
				return array_merge( self::f43_at( 0 ), array( false ) );
			case 'a result of one byte more than a step holds':
				return array_merge( self::f43_at( 1 ), array( true ) );
			case '2000 paths of 813 bytes with many quotation marks':
				// Codex D5: a quotation mark takes six bytes of the result (two in
				// paths, four in the message, whose list is JSON within JSON).
				return array( self::F43_WHY, array_map( static fn( int $i ): string => sprintf( '%04d/', $i ) . str_repeat( str_repeat( '"', 200 ) . '/', 4 ) . 'f.js', range( 0, 1999 ) ), true );
		}
		throw new \LogicException( $case );
	}

	/** The names of the cases of F43. */
	public static function f43_cases(): array {
		return array(
			'three paths'                                       => array( 'three paths' ),
			'2000 paths of a usual length'                      => array( '2000 paths of a usual length' ),
			'a result of exactly the bytes a step holds'        => array( 'a result of exactly the bytes a step holds' ),
			'a result of one byte more than a step holds'       => array( 'a result of one byte more than a step holds' ),
			'2000 paths of 813 bytes with many quotation marks' => array( '2000 paths of 813 bytes with many quotation marks' ),
		);
	}

	/**
	 * not_staged() of step 2 of run 31 with files missing, on a $wpdb that
	 * takes the statements of a step that ends (AB_MCP_Sync_Run::
	 * update_step() with its own check of the size, touch()): the answer,
	 * and what each prepare() was given, by its statement.
	 *
	 * @return array{0:mixed,1:array<string,array[]>}
	 */
	private static function f43( string $why, array $paths ): array {
		$db = new class() {
			/** @var string */
			public $prefix = 'wp_';
			/** @var string */
			public $last_error = '';
			/** @var array[] */
			public $prepared = array();
			public function prepare( $query, ...$args ) {
				$this->prepared[] = array( $query, $args );
				return $query;
			}
			public function query( $sql ) {
				unset( $sql );
				return 1;
			}
		};
		$before = $GLOBALS['wpdb'] ?? null;
		$status = new \ReflectionProperty( AB_MCP_Sync_Schema::class, 'status' );
		if ( PHP_VERSION_ID < 80100 ) {
			$status->setAccessible( true );
		}
		$GLOBALS['wpdb'] = $db;
		$status->setValue( null, array( 'available' => true, 'reason' => null ) );
		try {
			$answer = ( new \ReflectionMethod( AB_MCP_Sync_Code_Ops::class, 'not_staged' ) )->invoke( null, 31, 2, self::unit(), 'code:stage_incomplete', $why, array( 'paths' => $paths ) );
		} finally {
			$GLOBALS['wpdb'] = $before;
			$status->setValue( null, null );
		}
		$set = array();
		foreach ( $db->prepared as $one ) {
			$set[ $one[0] ][] = $one[1];
		}
		return array( $answer, $set );
	}

	/**
	 * Prüft: F43 (Codex D5): the answer of a unit not staged for files
	 * missing names every path where the result of its step is then at most
	 * what a step holds as the run stores it (8388608 bytes), to the byte,
	 * and reads as it did before (a few paths, 2000 of a usual length, a
	 * result of exactly those bytes). Where it would be more (one byte more;
	 * 2000 paths of 813 bytes with many quotation marks, each six bytes as
	 * stored), the step still ends failed from planned with its answer: the
	 * list holds the first paths, as many as fit (one more would not), the
	 * result holds the same ones within the bytes of a step, and the answer
	 * says how many it names, how many it leaves out and that the new run
	 * names the next ones.
	 * Sonst: the run would refuse the result as too large
	 * (ab_mcp_sync_run_too_large), the step would stay planned, and the
	 * customer would get no path and no way instead of the answer of F43; or
	 * a list cut without a word would leave files missing for good.
	 */
	#[DataProvider( 'f43_cases' )]
	public function testAUnitWithFilesMissingNamesAsManyOfTheirPathsAsTheResultOfItsStepHolds( string $case ): void {
		list( $why, $paths, $cut ) = self::f43_case( $case );
		$most                      = AB_MCP_Sync_Run::MAX_PARTS;
		self::assertSame( 8388608, $most );
		// What the result would take with every path named. (The texts are
		// megabytes: each is dropped where it is no longer read, and none is
		// handed to an assertion that would print it.)
		$whole = self::stored( self::f43_whole( $why, $paths ) );
		if ( 'a result of exactly the bytes a step holds' === $case ) {
			self::assertSame( $most, $whole );
		}
		if ( 'a result of one byte more than a step holds' === $case ) {
			self::assertSame( $most + 1, $whole );
		}
		self::assertSame( $cut, $whole > $most, 'With every path named the result takes ' . $whole . ' bytes.' );

		list( $answer, $set ) = self::f43( $why, $paths );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		self::assertSame( array( 'next' ), array_keys( $answer ) );
		$says = $answer['next'];
		unset( $answer );
		// The step ended: failed, from planned, its result written once and
		// within the bytes a step holds.
		self::assertSame( array( array( 'failed' ) ), $set['state = %s'] ?? null );
		self::assertSame( array( array( 'planned' ) ), $set[' AND state = %s'] ?? null );
		self::assertSame( array( array( 31, 2 ) ), $set[' WHERE run_id = %d AND seq = %d'] ?? null );
		self::assertCount( 1, $set['result = %s'] ?? array() );
		$size   = strlen( $set['result = %s'][0][0] );
		$stored = hash( 'sha256', $set['result = %s'][0][0] );
		unset( $set );
		self::assertLessThanOrEqual( $most, $size, 'The result as stored is one a step holds.' );
		// How many paths the answer names: all of them, or the number it says.
		$head = 'The plugin shop is not staged (code:stage_incomplete), so it is not swapped; nothing of this site changed. ' . $why . ' The paths of the files missing here';
		self::assertSame( $head, substr( $says, 0, strlen( $head ) ) );
		$n = count( $paths );
		if ( $cut ) {
			self::assertSame( 1, preg_match( '/^, the first ([1-9][0-9]*) of them, for paths of wp_site_export: \[/', substr( $says, strlen( $head ), 80 ), $m ), substr( $says, strlen( $head ), 80 ) );
			$n = (int) $m[1];
			self::assertLessThan( count( $paths ), $n, 'The list is cut.' );
		}
		$want = $cut ? self::f43_cut( $why, $paths, $n ) : self::f43_whole( $why, $paths );
		self::assertCount( $n, $want['paths'] );
		self::assertTrue( str_starts_with( $says, $want['message'] ) && ' The run goes on with the next step (action=next).' === substr( $says, strlen( $want['message'] ) ), 'The answer names the first ' . $n . ' of ' . count( $paths ) . ' paths with the way, then the next step; it ends: ' . substr( $says, -760 ) );
		unset( $says );
		self::assertSame( hash( 'sha256', AB_MCP_Sync_Canon::encode( $want ) ), $stored, 'The result as stored (' . $size . ' bytes): the message of the answer, the code, and the same ' . $n . ' paths.' );
		unset( $want );
		if ( $cut ) {
			self::assertGreaterThan( $most, self::stored( self::f43_cut( $why, $paths, $n + 1 ) ), 'One path more would not have fitted: ' . $n . ' of ' . count( $paths ) . ' named.' );
		}
	}

	public function testWhichPluginsRunIsReadFromTheRowOfActivePluginsAndARowNotReadIsNotKnown(): void {
		$calls = self::rows( array( 'active_plugins' => serialize( array( 'shop/shop.php', 'shop/extra.php', 'hello.php', 'shop-pay/pay.php' ) ) ) );
		self::assertSame( array( 'shop/shop.php', 'shop/extra.php', 'hello.php', 'shop-pay/pay.php' ), AB_MCP_Sync_Code_Swap::active_plugins() );
		self::assertSame( array( 'active_plugins' ), $calls->read, 'The row itself, once.' );
		self::assertSame( array( 'shop/extra.php', 'shop/shop.php' ), AB_MCP_Sync_Code_Swap::active_mains( 'shop' ), 'Every main file of the folder, and of no folder whose name only begins like it.' );
		self::assertSame( array( 'hello.php' ), AB_MCP_Sync_Code_Swap::active_mains( 'hello.php' ), 'A plugin of one file.' );
		self::assertTrue( AB_MCP_Sync_Code_Swap::runs( 'plugin', 'shop' ) );
		self::assertTrue( AB_MCP_Sync_Code_Swap::runs( 'plugin', 'shop-pay' ) );
		self::assertFalse( AB_MCP_Sync_Code_Swap::runs( 'plugin', 'sho' ) );
		self::assertFalse( AB_MCP_Sync_Code_Swap::runs( 'plugin', 'gallery' ) );
		// Keys with a gap, as deactivate_plugins() leaves them.
		self::rows( array( 'active_plugins' => serialize( array( 0 => 'a/a.php', 2 => 'shop/shop.php' ) ) ) );
		self::assertSame( array( 'a/a.php', 'shop/shop.php' ), AB_MCP_Sync_Code_Swap::active_plugins() );
		// No row at all: no plugin was ever activated.
		self::rows( array( 'active_plugins' => false ) );
		self::assertSame( array(), AB_MCP_Sync_Code_Swap::active_plugins() );
		self::assertFalse( AB_MCP_Sync_Code_Swap::runs( 'plugin', 'shop' ) );
		self::rows( array( 'active_plugins' => serialize( array() ) ) );
		self::assertFalse( AB_MCP_Sync_Code_Swap::runs( 'plugin', 'shop' ) );
		// Not read, or not a list of main files: not known, never «does not run».
		$unknown = array(
			'a reading that fails'    => null,
			'a row without its value' => array( 'autoload' => 'yes' ),
			'a value that is no text' => array( 'value' => 7, 'autoload' => 'yes' ),
			// No text, but an object that turns into one: cast, it would read
			// as a list of main files, or as none (B of the Codex round on
			// 5b044fa: the check of the type on its own).
			'a stringable, a list'    => array( 'value' => self::stringable( serialize( array( 'shop/shop.php' ) ) ), 'autoload' => 'yes' ),
			'a stringable, no plugin' => array( 'value' => self::stringable( serialize( array() ) ), 'autoload' => 'yes' ),
			'an empty value'          => '',
			'a text'                  => 'shop/shop.php',
			'half written'            => substr( serialize( array( 'shop/shop.php' ) ), 0, 20 ),
			'an object'               => 'O:8:"stdClass":0:{}',
			'a main file no text'     => serialize( array( 'shop/shop.php', 7 ) ),
			'an empty main file'      => serialize( array( 'shop/shop.php', '' ) ),
			'keys that are texts'     => serialize( array( 'shop' => 'shop/shop.php' ) ),
			'a number'                => serialize( 3 ),
			'false'                   => serialize( false ),
		);
		foreach ( $unknown as $what => $row ) {
			self::rows( array( 'active_plugins' => array( $row ) ) );
			self::assertNull( AB_MCP_Sync_Code_Swap::active_plugins(), $what );
			self::assertNull( AB_MCP_Sync_Code_Swap::active_mains( 'shop' ), $what );
			self::assertNull( AB_MCP_Sync_Code_Swap::runs( 'plugin', 'shop' ), $what );
		}
		self::rows( array( 'active_plugins' => serialize( array( 'shop/shop.php' ) ) ) );
		foreach ( array( '', null, 7, array( 'shop' ) ) as $ref ) {
			self::assertNull( AB_MCP_Sync_Code_Swap::runs( 'plugin', $ref ), 'No unit named: not known.' );
		}
	}

	public function testWhichThemeRunsIsReadFromBothItsRowsAndASwitchInBetweenIsNotKnown(): void {
		$calls = self::rows( array( 'stylesheet' => 'lake-child', 'template' => 'lake' ) );
		self::assertSame( array( 'stylesheet' => 'lake-child', 'template' => 'lake' ), AB_MCP_Sync_Code_Swap::theme() );
		self::assertSame( array( 'stylesheet', 'template', 'stylesheet' ), $calls->read, 'The stylesheet read again after the template.' );
		self::assertTrue( AB_MCP_Sync_Code_Swap::runs( 'theme', 'lake-child' ), 'The theme that runs.' );
		self::assertTrue( AB_MCP_Sync_Code_Swap::runs( 'theme', 'lake' ), 'Its parent runs as well.' );
		self::assertFalse( AB_MCP_Sync_Code_Swap::runs( 'theme', 'hill' ) );
		// A switch of the theme while it is read: the two rows never stood together.
		self::rows( array( 'stylesheet' => array( 'lake-child', 'hill' ), 'template' => 'lake' ) );
		self::assertNull( AB_MCP_Sync_Code_Swap::theme() );
		self::rows( array( 'stylesheet' => array( 'lake-child', 'hill' ), 'template' => 'lake' ) );
		self::assertNull( AB_MCP_Sync_Code_Swap::runs( 'theme', 'hill' ), 'Not known, never «does not run».' );
		foreach ( array( 'no row' => false, 'a reading that fails' => null, 'an empty name' => '', 'no text' => array( array( 'value' => 7, 'autoload' => 'yes' ) ) ) as $what => $row ) {
			foreach ( array( 'stylesheet', 'template' ) as $option ) {
				self::rows( array( $option => $row ) + array( 'stylesheet' => 'lake', 'template' => 'lake' ) );
				self::assertNull( AB_MCP_Sync_Code_Swap::theme(), "$option: $what" );
				self::rows( array( $option => $row ) + array( 'stylesheet' => 'lake', 'template' => 'lake' ) );
				self::assertNull( AB_MCP_Sync_Code_Swap::runs( 'theme', 'hill' ), "$option: $what" );
			}
		}
		self::rows( array( 'stylesheet' => 'lake', 'template' => 'lake' ) );
		self::assertNull( AB_MCP_Sync_Code_Swap::runs( 'theme', '' ) );
		self::assertNull( AB_MCP_Sync_Code_Swap::runs( 'mu_plugin', 'lake' ), 'A kind that is neither: not known.' );
	}

	public function testTheSwapStepAsksTheReleaseOfItsCodeNowAndRefusesWhereItIsNotKnownOrNotReleased(): void {
		// Z24 (Frage 7 of part 2a): released() is the one rule; run() asks it
		// for the step swap, read now through the seam, before it swaps.
		// Red at 2108f7d: run() refused every swap with not_built, whatever
		// ran. That a released step goes on to the swap shows F56
		// (tests/integration/SyncCodeRunTest.php).
		$item  = static fn( array $over ): array => array(
			'unit'     => self::unit( array( 'seq' => 3, 'op' => 'swap', 'stage_seq' => 2 ) + $over ),
			'env'      => array( str_repeat( 'e', 32 ) ),
			'packages' => array(),
		);
		$shop  = serialize( array( 'shop/shop.php' ) );
		$other = serialize( array( 'gallery/gallery.php' ) );
		$cases = array(
			'not known'                 => array( array( 'active_plugins' => 'a:1:{broken' ), array( 'accept' => true ), 'ab_mcp_sync_code_runs_unknown', 'could not be read now' ),
			'runs, not released'        => array( array( 'active_plugins' => $shop ), array( 'accept' => false ), 'ab_mcp_sync_code_not_released', 'does not release it' ),
		);
		foreach ( $cases as $what => list( $rows, $over, $code, $says ) ) {
			$calls = self::rows( $rows );
			$got   = AB_MCP_Sync_Code_Ops::run( array( 'run_id' => 1 ), array( 'seq' => 3 ), $item( $over ) );
			self::assertInstanceOf( WP_Error::class, $got, $what );
			self::assertSame( $code, $got->get_error_code(), $what . ': ' . $got->get_error_message() );
			self::assertStringContainsString( $says, $got->get_error_message(), $what );
			self::assertSame( array( 'active_plugins' ), $calls->read, $what . ': read now, once.' );
		}
		self::rows( array( 'active_plugins' => $other ) );
		self::assertFalse( AB_MCP_Sync_Code_Ops::released( self::unit( array( 'accept' => false ) ) ), 'Not running: false, no refusal.' );
		self::rows( array( 'active_plugins' => $shop ) );
		self::assertTrue( AB_MCP_Sync_Code_Ops::released( self::unit() ), 'Running and released: true.' );
	}

	public function testAUnitMadeNewThatWordPressNamesAsRunningIsRefusedWithAcceptOrWithoutAndClaimsNoRunningCode(): void {
		// Finding 2 of part 2a, the decision of the lead: a unit the plan makes
		// new (its folder was missing at the report) has no running code here
		// to take out of OPcache; where the row of active_plugins (or the theme)
		// names it all the same, what of it runs is not known. The one rule of
		// Z24 refuses it with its own code and way, with accept or without,
		// never with «code runs». Red at 4881c0d: released() answered true with
		// accept and ab_mcp_sync_code_not_released («runs») without it.
		$new  = array(
			'unit_op'   => 'create',
			'pair_id'   => null,
			'pair_hash' => null,
		);
		$shop = serialize( array( 'shop/shop.php' ) );
		foreach ( array( 'with accept' => true, 'without accept' => false ) as $how => $accept ) {
			self::rows( array( 'active_plugins' => $shop ) );
			$got = AB_MCP_Sync_Code_Ops::released( self::unit( array( 'accept' => $accept ) + $new ) );
			self::assertInstanceOf( WP_Error::class, $got, $how );
			self::assertSame( 'ab_mcp_sync_code_create_runs', $got->get_error_code(), $how . ': ' . $got->get_error_message() );
			self::assertStringContainsString( 'The plan makes the plugin shop new on this site, and WordPress names it as running here now', $got->get_error_message(), $how );
			self::assertStringContainsString( 'so what of it runs here is not known', $got->get_error_message(), $how );
			self::assertStringContainsString( 'the page Plugins deactivates a plugin whose files are missing), then call action=next again; or abort the run (wp_site_push action=abort)', $got->get_error_message(), $how . ': with the way.' );
			self::assertStringNotContainsString( 'Code of the plugin shop runs', $got->get_error_message(), $how . ': no claim that its code runs.' );
			$step = AB_MCP_Sync_Code_Ops::run(
				array( 'run_id' => 1 ),
				array( 'seq' => 3 ),
				array(
					'unit'     => self::unit( array( 'seq' => 3, 'op' => 'swap', 'stage_seq' => 2, 'accept' => $accept ) + $new ),
					'env'      => array( str_repeat( 'e', 32 ) ),
					'packages' => array(),
				)
			);
			self::assertSame( 'ab_mcp_sync_code_create_runs', $step->get_error_code(), $how . ': the entrance asks the same rule.' );
		}
		// The theme: the one that runs or its parent.
		self::rows( array( 'stylesheet' => 'shop', 'template' => 'shop' ) );
		self::assertSame( 'ab_mcp_sync_code_create_runs', AB_MCP_Sync_Code_Ops::released( self::unit( array( 'kind' => 'theme' ) + $new ) )->get_error_code(), 'A theme made new that runs.' );
		// Not running: a unit made new goes on, without a release.
		self::rows( array( 'active_plugins' => serialize( array( 'gallery/gallery.php' ) ) ) );
		self::assertFalse( AB_MCP_Sync_Code_Ops::released( self::unit( array( 'accept' => false ) + $new ) ), 'A unit made new that does not run: false, no refusal.' );
	}

	public function testOPcacheIsAskedInTheSwapOnlyThroughInvalidateAndAlphaBridgeOnlyStrictly(): void {
		// Z13: no call of OPcache under includes/sync/ but the one method of the
		// seam of the swap, and that method called only from invalidate(). V6:
		// the swap asks AlphaBridge itself only through own_state(), never the
		// lenient own_file_refusal(). Each rule with its counter-sample.
		$rule  = static function ( array $files ): array {
			$bad = array();
			foreach ( $files as $name => $text ) {
				$want = 'class-sync-code-swap.php' === $name ? 1 : 0;
				foreach ( array( 'invalidate_opcache(', 'opcache_invalidate(', 'wp_opcache_invalidate(' ) as $call ) {
					if ( substr_count( $text, $call ) !== ( 'invalidate_opcache(' === $call ? $want : 0 ) ) {
						$bad[] = "$name: $call";
					}
				}
				if ( 1 === $want ) {
					preg_match_all( '/function (\w+)\(|->opcache\(/', $text, $m, PREG_SET_ORDER );
					$in = '';
					foreach ( $m as $hit ) {
						if ( isset( $hit[1] ) && '' !== $hit[1] ) {
							$in = $hit[1];
						} elseif ( 'invalidate' !== $in ) {
							$bad[] = "$name: ->opcache( in $in()";
						}
					}
					if ( false !== strpos( $text, 'own_file_refusal' ) || 1 !== substr_count( $text, 'own_state(' ) ) {
						$bad[] = "$name: AlphaBridge itself not only through own_state()";
					}
				}
			}
			return $bad;
		};
		$files = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/sync/*.php' ) as $file ) {
			// The code without its comments, which name the calls they explain.
			$code = '';
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
				$code .= is_array( $token ) ? ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $token[1] ) : $token;
			}
			$files[ basename( $file ) ] = $code;
		}
		self::assertSame( array(), $rule( $files ) );
		$swap = $files['class-sync-code-swap.php'];
		self::assertNotSame( array(), $rule( array( 'class-sync-code-run.php' => '<?php wp_opcache_invalidate( $f );' ) + $files ), 'Counter-sample: OPcache elsewhere.' );
		self::assertNotSame( array(), $rule( array( 'class-sync-code-swap.php' => str_replace( 'private static function php_file(', 'private static function php_file() { self::calls()->opcache( 1 ); } private static function php_file2(', $swap ) ) + $files ), 'Counter-sample: ->opcache( outside invalidate().' );
		self::assertNotSame( array(), $rule( array( 'class-sync-code-swap.php' => str_replace( 'own_state(', 'own_file_refusal(', $swap ) ) + $files ), 'Counter-sample: the lenient check.' );
	}

	public function testTwoVersionsAreOrderedOnlyWhereBothAreVersionsElseTheOrderIsNotKnown(): void {
		self::assertSame( 'newer', AB_MCP_Sync_Code_Ops::order( '2.1', '2.0' ) );
		self::assertSame( 'newer', AB_MCP_Sync_Code_Ops::order( '2.10', '2.9' ), 'By its parts, never as a text.' );
		self::assertSame( 'older', AB_MCP_Sync_Code_Ops::order( '2.0', '2.0.1' ) );
		self::assertSame( 'older', AB_MCP_Sync_Code_Ops::order( '2.0-beta1', '2.0' ) );
		self::assertSame( 'same', AB_MCP_Sync_Code_Ops::order( '2.0', '2.0' ) );
		foreach ( array( '', null, 2, 2.0, true, array( '2.0' ), 'two', 'v2.0', ' 2.0', "2.0\n", '2.0 beta', str_repeat( '1', 65 ) ) as $bad ) {
			self::assertNull( AB_MCP_Sync_Code_Ops::order( $bad, '2.0' ), var_export( $bad, true ) . ' on the staging side.' );
			self::assertNull( AB_MCP_Sync_Code_Ops::order( '2.0', $bad ), var_export( $bad, true ) . ' on this side.' );
		}
	}
}
