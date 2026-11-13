<?php
/**
 * What a step of the code reads before it does anything (Z8b branch 5; plan
 * E8, E9, 3.1 Z24; the pre-check of branch 5, V18 and V19): its unit from
 * the plan of its run (AB_MCP_Sync_Code_Ops::item()), its stage from its
 * journal (staged()), whether code of a unit runs on this site
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
use AB_MCP_Sync_Code_Ops;
use AB_MCP_Sync_Code_Plan;
use AB_MCP_Sync_Code_Swap;
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

	public function testTheStageOfAStepComesFromItsJournalInItsFormOrIsNotKnown(): void {
		$read = AB_MCP_Sync_Code_Ops::staged( self::journal() );
		self::assertSame( array( str_repeat( 'c', 32 ), 'shop', str_repeat( 'd', 64 ) ), array( $read['stage']['gid'], $read['stage']['name'], $read['stage']['hash'] ) );
		self::assertSame( 'packages', AB_MCP_Sync_Code_Ops::staged( self::journal( array( 'source' => 'packages' ) ) )['stage']['source'] );
		foreach ( array( null, array(), 'x', self::journal( array( 'gid' => 'c' ) ), self::journal( array( 'hash' => str_repeat( 'D', 64 ) ) ), self::journal( array( 'name' => 'a/b' ) ), self::journal( array( 'name' => '' ) ), self::journal( array( 'source' => 'elsewhere' ) ), self::journal( array( 'files' => '3' ) ), self::journal( array(), array( 'op' => 'swap' ) ), self::journal( array(), array( 'kind' => 'language' ) ), array( 'object' => array( 'kind' => 'post' ) ) + self::journal() ) as $i => $journal ) {
			self::assertNull( AB_MCP_Sync_Code_Ops::staged( $journal ), "#$i" );
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
