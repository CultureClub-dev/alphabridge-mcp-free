<?php
/**
 * The database kinds as contract A has them in this branch
 * (AB_MCP_Sync_Db_Kinds): each registers with exactly the entries
 * AB_MCP_Sync_Kinds::OWNERSHIP gives it, writes in phase 5, and answers every
 * method that writes or asks for the rights to write with
 * ab_mcp_sync_not_in_branch; the inventory's budget and the status of its
 * objects. Reading against WordPress is tests/integration (SyncCanonTest,
 * SyncLocksTest).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Db_Kinds;
use AB_MCP_Sync_Inventory;
use AB_MCP_Sync_Kinds;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-db-kinds.php';

final class SyncDbKindsTest extends TestCase {

	protected function setUp(): void {
		AB_MCP_Sync_Kinds::reset();
	}

	protected function tearDown(): void {
		AB_MCP_Sync_Kinds::reset();
		AB_MCP_Sync_Inventory::$clock = null;
	}

	public function testEachKindRegistersWithWhatTheTableGivesIt(): void {
		self::assertTrue( AB_MCP_Sync_Db_Kinds::register_all() );
		self::assertTrue( AB_MCP_Sync_Db_Kinds::register_all(), 'Twice is once.' );
		$names = array();
		foreach ( AB_MCP_Sync_Kinds::all() as $kind ) {
			$names[] = $kind->kind();
			self::assertSame( 5, $kind->phase() );
		}
		self::assertSame( array( 'post', 'term', 'menu', 'widget_area', 'custom_css', 'option' ), $names );
		$claims = array();
		foreach ( AB_MCP_Sync_Kinds::all() as $kind ) {
			foreach ( $kind->responsibility() as $key ) {
				self::assertArrayNotHasKey( $key, $claims, "$key has one owner." );
				$claims[ $key ] = $kind->kind();
			}
		}
		foreach ( AB_MCP_Sync_Kinds::OWNERSHIP as $key => $entry ) {
			if ( isset( $entry['stage'] ) || null === $entry['kind'] || 'attachment' === $entry['kind'] ) {
				continue;
			}
			self::assertSame( $entry['kind'], $claims[ $key ] ?? null, "$key belongs to a kind of this branch." );
		}
	}

	public function testWhatWritesIsNotInThisBranch(): void {
		foreach ( AB_MCP_Sync_Db_Kinds::all() as $kind ) {
			foreach ( array(
				'export'       => array( array() ),
				'rights'       => array( 'update', '1' ),
				'core_effects' => array( 'update' ),
				'fields'       => array(),
				'parts'        => array( array() ),
				'backup'       => array( array() ),
				'write'        => array( array() ),
				'read_back'    => array( array() ),
				'restore_part' => array( array() ),
				'post_write'   => array( array() ),
				'verify'       => array( array() ),
			) as $method => $args ) {
				$answer = $kind->$method( ...$args );
				self::assertInstanceOf( \WP_Error::class, $answer, $kind->kind() . "::$method" );
				self::assertSame( 'ab_mcp_sync_not_in_branch', $answer->get_error_code() );
				self::assertSame( $method, $answer->get_error_data()['method'] );
				self::assertStringContainsString( 'branch 8 of Z8', $answer->get_error_message() );
			}
			self::assertInstanceOf( \WP_Error::class, $kind->canonical( 'post 1' ), 'canonical() names the form it takes.' );
		}
	}

	public function testTheBudgetOfTheInventory(): void {
		$now                          = 1000.0;
		AB_MCP_Sync_Inventory::$clock = static function () use ( &$now ) {
			return $now;
		};
		$source = AB_MCP_Sync_Inventory::source( array(), 60 );
		self::assertSame( 1060.0, $source['deadline'] );
		self::assertFalse( AB_MCP_Sync_Inventory::out_of_time( $source ) );
		$now = 1059.9;
		self::assertFalse( AB_MCP_Sync_Inventory::out_of_time( $source ) );
		$now = 1060.0;
		self::assertTrue( AB_MCP_Sync_Inventory::out_of_time( $source ) );
		self::assertSame( 60, AB_MCP_Sync_Inventory::BUDGET );
	}

	public function testTheStatusOfAnObjectOfTheInventory(): void {
		$base = array( 'form' => array( 'x' ), 'locks' => array() );
		self::assertSame( array( 'locks' => array(), 'status' => 'z8' ), AB_MCP_Sync_Inventory::entry( $base ) );
		self::assertSame( 'locked', AB_MCP_Sync_Inventory::entry( array( 'locks' => array( array( 'key' => 'post:builder' ) ) ) + $base )['status'] );
		self::assertSame( 'z8', AB_MCP_Sync_Inventory::entry( array( 'status' => 'core' ) + $base )['status'] );
		self::assertSame( 'locked', AB_MCP_Sync_Inventory::entry( array( 'status' => 'core', 'locks' => array( array( 'key' => 'option:off_schema' ) ) ) + $base )['status'] );
		self::assertSame( 'locked', AB_MCP_Sync_Inventory::entry( array( 'status' => 'unclassified' ) + $base )['status'] );
		self::assertSame( 'locked', AB_MCP_Sync_Inventory::entry( array( 'status' => 'owner' ) + $base )['status'] );
		foreach ( array( 'b9', 'guarded', 'unreachable', 'unreadable' ) as $status ) {
			self::assertSame( $status, AB_MCP_Sync_Inventory::entry( array( 'status' => $status ) + $base )['status'] );
		}
	}
}
