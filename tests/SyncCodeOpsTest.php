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

use AB_MCP_Sync_Code_Ops;
use AB_MCP_Sync_Code_Swap;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/includes/sync/class-sync-code-ops.php';

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
