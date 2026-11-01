<?php
/**
 * The rules of the object pairs that need no database (AB_MCP_Sync_Pairs,
 * Z8 decisions 3, 10, 14 and 23):
 *
 * - the changes of state: active → unclear, active → residual,
 *   unclear → residual, nothing else; out of residual and unclear back to
 *   active only through realign() (shown on rows in the integration suite);
 * - override and base_extra are stored as canonical JSON within their
 *   bounds (MAX_OVERRIDE, MAX_EXTRA bytes, MAX_EXTRA_DEPTH levels; no
 *   floats, no objects, valid UTF-8, keys sorted);
 * - a canon change gives new bases only when the live form equals the
 *   target, else unclear; a side that is not there is never «equal».
 *
 * What the rows do (unique keys, conditional writes, snapshots, restore,
 * the canon change on a row) is tests/integration/SyncPairsTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Pairs;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-pairs.php';

final class SyncPairsRulesTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	public function testOnlyTheseChangesOfStateAreMade(): void {
		$allowed = array( 'active>unclear', 'active>residual', 'unclear>residual' );
		foreach ( AB_MCP_Sync_Pairs::STATES as $from ) {
			foreach ( AB_MCP_Sync_Pairs::STATES as $to ) {
				self::assertSame( in_array( "$from>$to", $allowed, true ), AB_MCP_Sync_Pairs::transition_allowed( $from, $to ), "$from > $to" );
			}
		}
		self::assertFalse( AB_MCP_Sync_Pairs::transition_allowed( 'residual', 'active' ), 'Rule 0: a residual pair is ended only by a confirmed new alignment.' );
		self::assertFalse( AB_MCP_Sync_Pairs::transition_allowed( 'nonsense', 'active' ) );
		self::assertSame( array( 'active', 'deleted', 'unclear', 'residual' ), AB_MCP_Sync_Pairs::STATES );
	}

	public function testOverrideIsAnObjectOfFieldsWithPlainValues(): void {
		self::assertSame( '{"comment_status":"closed","post_status":"draft"}', AB_MCP_Sync_Pairs::encode_json( 'override', array( 'post_status' => 'draft', 'comment_status' => 'closed' ) ), 'Sorted, compact.' );
		self::assertNull( AB_MCP_Sync_Pairs::encode_json( 'override', null ) );
		foreach ( array(
			'list'        => array( 'draft' ),
			'empty'       => array(),
			'nested'      => array( 'post_status' => array( 'draft' ) ),
			'float'       => array( 'menu_order' => 1.5 ),
			'object'      => array( 'post_status' => (object) array() ),
			'bad name'    => array( 'Post-Status' => 'draft' ),
			'not array'   => 'draft',
			'too long'    => array( 'post_content' => str_repeat( 'x', AB_MCP_Sync_Pairs::MAX_OVERRIDE ) ),
		) as $case => $value ) {
			$answer = AB_MCP_Sync_Pairs::encode_json( 'override', $value );
			self::assertInstanceOf( WP_Error::class, $answer, $case );
			self::assertSame( 'ab_mcp_staging_pair_invalid', $answer->get_error_code(), $case );
			self::assertSame( array( 'field' => 'override' ), $answer->get_error_data(), $case );
		}
	}

	public function testBaseExtraIsBoundedJson(): void {
		// The instance mapping of a widget area, keys sorted at every level.
		self::assertSame(
			'{"instances":{"block-2":4,"text-3":7},"order":["text-3","block-2"]}',
			AB_MCP_Sync_Pairs::encode_json(
				'base_extra',
				array(
					'order'     => array( 'text-3', 'block-2' ),
					'instances' => array(
						'text-3'  => 7,
						'block-2' => 4,
					),
				)
			)
		);
		self::assertSame( '["ä/é"]', AB_MCP_Sync_Pairs::encode_json( 'base_extra', array( 'ä/é' ) ), 'Unicode and slashes as they are.' );

		// Exactly MAX_EXTRA bytes go in, one more does not: ["…"] is four bytes around the string.
		$max = AB_MCP_Sync_Pairs::MAX_EXTRA;
		self::assertSame( $max, strlen( (string) AB_MCP_Sync_Pairs::encode_json( 'base_extra', array( str_repeat( 'x', $max - 4 ) ) ) ) );
		$over = AB_MCP_Sync_Pairs::encode_json( 'base_extra', array( str_repeat( 'x', $max - 3 ) ) );
		self::assertInstanceOf( WP_Error::class, $over );
		self::assertStringContainsString( 'longer than ' . $max . ' bytes', $over->get_error_message() );

		// MAX_EXTRA_DEPTH levels go in, one more does not.
		$deep = array( 1 );
		for ( $i = 1; $i < AB_MCP_Sync_Pairs::MAX_EXTRA_DEPTH; $i++ ) {
			$deep = array( $deep );
		}
		self::assertIsString( AB_MCP_Sync_Pairs::encode_json( 'base_extra', $deep ) );
		self::assertInstanceOf( WP_Error::class, AB_MCP_Sync_Pairs::encode_json( 'base_extra', array( $deep ) ) );

		foreach ( array(
			'float'   => array( 'x' => 0.1 ),
			'object'  => array( 'x' => new \stdClass() ),
			'utf-8'   => array( "\xC3\x28" ),
			'empty'   => array(),
			'scalar'  => 'x',
		) as $case => $value ) {
			self::assertInstanceOf( WP_Error::class, AB_MCP_Sync_Pairs::encode_json( 'base_extra', $value ), $case );
		}
	}

	public function testACanonChangeTakesNewBasesOnlyWhenTheMappedFormsAreEqual(): void {
		$live    = hash( 'sha256', 'live' );
		$staging = hash( 'sha256', 'staging' );
		$other   = hash( 'sha256', 'other' );

		$same = AB_MCP_Sync_Pairs::canon_outcome(
			array(
				'live'    => $live,
				'staging' => $staging,
				'target'  => $live,
			),
			'canon/2'
		);
		self::assertSame( 'active', $same['state'] );
		self::assertSame( 'canon/2', $same['canon'] );
		self::assertSame( $live, $same['base_live'], 'base_live is the hash of the target.' );
		self::assertSame( $staging, $same['base_staging'], 'base_staging the staging form in its own ID space.' );
		self::assertEqualsWithDelta( time(), $same['base_time'], 2 );

		foreach ( array(
			'different'       => array( $live, $staging, $other ),
			'no live object'  => array( null, $staging, $live ),
			'no staging'      => array( $live, null, $live ),
			'no target'       => array( $live, $staging, null ),
			'nothing at all'  => array( null, null, null ),
		) as $case => list( $l, $s, $t ) ) {
			self::assertSame(
				array( 'state' => 'unclear' ),
				AB_MCP_Sync_Pairs::canon_outcome(
					array(
						'live'    => $l,
						'staging' => $s,
						'target'  => $t,
					),
					'canon/2'
				),
				$case . ': the bases and the version stay; the pair waits for a person.'
			);
		}

		foreach ( array(
			'not an array'   => 'equal',
			'no target key'  => array( 'live' => $live, 'staging' => $staging ),
			'upper case'     => array( 'live' => strtoupper( $live ), 'staging' => $staging, 'target' => strtoupper( $live ) ),
			'short'          => array( 'live' => 'abc', 'staging' => $staging, 'target' => 'abc' ),
		) as $case => $forms ) {
			$answer = AB_MCP_Sync_Pairs::canon_outcome( $forms, 'canon/2' );
			self::assertInstanceOf( WP_Error::class, $answer, $case );
			self::assertSame( 'ab_mcp_staging_pair_invalid', $answer->get_error_code(), $case );
		}
	}

	public function testAStoredRowReadsWithNumbersAndArrays(): void {
		$row = AB_MCP_Sync_Pairs::decode(
			array(
				'id'           => '7',
				'source_id'    => 'src',
				'kind'         => 'post',
				'live_ref'     => '12',
				'staging_ref'  => '34',
				'state'        => 'active',
				'override'     => '{"post_status":"draft"}',
				'canon'        => 'canon/1',
				'base_live'    => str_repeat( 'a', 64 ),
				'base_staging' => str_repeat( 'b', 64 ),
				'base_extra'   => null,
				'base_time'    => '1793520000',
				'base_origin'  => 'push',
				'last_run'     => null,
				'updated'      => '1793520001',
			)
		);
		self::assertSame( 7, $row['id'] );
		self::assertSame( '12', $row['live_ref'], 'A ref stays a string.' );
		self::assertSame( array( 'post_status' => 'draft' ), $row['override'] );
		self::assertNull( $row['base_extra'] );
		self::assertSame( 1793520000, $row['base_time'] );
		self::assertNull( $row['last_run'] );
		self::assertSame( 1793520001, $row['updated'] );
	}
}
