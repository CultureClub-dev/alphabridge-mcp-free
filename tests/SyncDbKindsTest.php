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

use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Db_Kinds;
use AB_MCP_Sync_Inventory;
use AB_MCP_Sync_Kind_Post;
use AB_MCP_Sync_Kinds;
use PHPUnit\Framework\Attributes\DataProvider;
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
		self::assertSame( array( 'post', 'term', 'menu', 'widget_area', 'custom_css', 'option', 'attachment' ), $names );
		$claims = array();
		foreach ( AB_MCP_Sync_Kinds::all() as $kind ) {
			foreach ( $kind->responsibility() as $key ) {
				self::assertArrayNotHasKey( $key, $claims, "$key has one owner." );
				$claims[ $key ] = $kind->kind();
			}
		}
		foreach ( AB_MCP_Sync_Kinds::OWNERSHIP as $key => $entry ) {
			if ( isset( $entry['stage'] ) || null === $entry['kind'] ) {
				continue;
			}
			self::assertSame( $entry['kind'], $claims[ $key ] ?? null, "$key belongs to a kind of this branch." );
		}
	}

	public function testWhatWritesIsNotInThisBranch(): void {
		foreach ( AB_MCP_Sync_Db_Kinds::all() as $kind ) {
			// The post kind writes since branch 8, the term kind since branch 9
			// (AB_MCP_Sync_Term_Writer), the attachment kind since branch 10
			// (AB_MCP_Sync_Attachment_Writer), the kinds of the structure of a
			// site since branch 11 (menus, widget areas, the additional CSS,
			// options): every kind exports its objects.
			if ( in_array( $kind->kind(), array( 'post', 'term', 'attachment', 'menu', 'widget_area', 'custom_css', 'option' ), true ) ) {
				self::assertSame( array(), $kind->export( array() ), 'The kind exports its objects (branches 8 and 9): ' . $kind->kind() );
				self::assertSame( 'ab_mcp_sync_object_invalid', $kind->export( array( 'x' ) )->get_error_code() );
			}
			// Every other method of the interface that would write answers
			// that the kind does not write, whatever the kind: the engine
			// writes through its writers (AB_MCP_Sync_Engine::WRITERS), never
			// through a kind (since branch 11 every kind exports, so the
			// methods are asked for each of them; the CI of free#141).
			foreach ( array(
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
		self::assertNull( $source['objects'], 'No bound of objects unless the source names one.' );
	}

	public function testASourceMayBoundTheObjectsOfAnInventory(): void {
		$base   = array( 'locks' => array() );
		$source = AB_MCP_Sync_Inventory::source( array(), 60, null, 2 );
		self::assertSame( 2, $source['objects'] );
		self::assertFalse( AB_MCP_Sync_Inventory::out_of_time( $source ) );
		AB_MCP_Sync_Inventory::entry( $base );
		self::assertFalse( AB_MCP_Sync_Inventory::out_of_time( $source ), 'One object of two.' );
		AB_MCP_Sync_Inventory::entry( $base );
		self::assertTrue( AB_MCP_Sync_Inventory::out_of_time( $source ), 'The bound reached, before the time.' );
		$unbound = AB_MCP_Sync_Inventory::source( array(), 60 );
		self::assertFalse( AB_MCP_Sync_Inventory::out_of_time( $unbound ), 'A source without a bound counts no objects.' );
		$again = AB_MCP_Sync_Inventory::source( array(), 60, null, 2 );
		self::assertFalse( AB_MCP_Sync_Inventory::out_of_time( $again ), 'A new source counts anew.' );
		self::assertSame( 1, AB_MCP_Sync_Inventory::source( array(), 60, null, 0 )['objects'], 'At least one object.' );
		self::assertFalse( AB_MCP_Sync_Inventory::out_of_time( 'no source' ) );
	}

	public function testTheStatusOfAnObjectOfTheInventory(): void {
		$base = array( 'form' => array( 'x' ), 'locks' => array() );
		self::assertSame( array( 'locks' => array(), 'mapped' => null, 'mapped_possible' => null, 'status' => 'z8' ), AB_MCP_Sync_Inventory::entry( $base ), 'Without a hash, no mapped form (Z8 branch 6).' );
		self::assertSame( 'locked', AB_MCP_Sync_Inventory::entry( array( 'locks' => array( array( 'key' => 'post:builder' ) ) ) + $base )['status'] );
		self::assertSame( 'z8', AB_MCP_Sync_Inventory::entry( array( 'status' => 'core' ) + $base )['status'] );
		self::assertSame( 'locked', AB_MCP_Sync_Inventory::entry( array( 'status' => 'core', 'locks' => array( array( 'key' => 'option:off_schema' ) ) ) + $base )['status'] );
		self::assertSame( 'locked', AB_MCP_Sync_Inventory::entry( array( 'status' => 'unclassified' ) + $base )['status'] );
		self::assertSame( 'locked', AB_MCP_Sync_Inventory::entry( array( 'status' => 'owner' ) + $base )['status'] );
		foreach ( array( 'b9', 'guarded', 'unreachable', 'unreadable' ) as $status ) {
			self::assertSame( $status, AB_MCP_Sync_Inventory::entry( array( 'status' => $status ) + $base )['status'] );
		}
	}

	/* ------------------------------------- credentials in each column */

	/** @return array<string,array{0:string,1:string,2:string}> column, value, field path. */
	public static function credentialColumns(): array {
		$values = array(
			'JSON'                  => array( '{"api_key":"probe"}', 'api_key' ),
			'serialized'            => array( 'a:1:{s:8:"password";s:5:"probe";}', 'password' ),
			'a block'               => array( '<!-- wp:acme/box {"token":"probe"} /-->', '[block:acme/box].token' ),
			'a shortcode'           => array( '[acme secret="probe"]', '[shortcode:acme].secret' ),
			'a shortcode, escaped'  => array( '[acme data=\'{\\"api_key\\":\\"probe\\"}\']', '[shortcode:acme].data.api_key' ),
			'a shortcode, position' => array( "[acme '{\"client_secret\":\"probe\"}']", '[shortcode:acme].0.client_secret' ),
		);
		$out = array();
		// Every column of the form a text can stand in (wp-admin/includes/schema.php).
		// post_password is a credential by its name (testAProtectedPostLocks()).
		foreach ( array( 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_name', 'post_content_filtered', 'post_type', 'post_mime_type' ) as $column ) {
			foreach ( $values as $what => list( $value, $field ) ) {
				$out[ $column . ', ' . $what ] = array( $column, $value, $field );
			}
		}
		return $out;
	}

	#[DataProvider( 'credentialColumns' )]
	public function testEachColumnOfAPostIsAUnitOfTheCredentialCheck( string $column, string $value, string $field ): void {
		$row = self::post_row();
		$ctx = AB_MCP_Sync_Canon::context(
			array( 'https://staging.test' ),
			static function ( int $id ) {
				return 'user' . $id;
			}
		);
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Kind_Post::row_form( $row, $ctx, $acc );
		self::assertSame( array(), $acc['credentials']['mixed'], 'Clean without the value.' );
		$row[ $column ] = $value;
		$acc            = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Kind_Post::row_form( $row, $ctx, $acc );
		self::assertSame( array( array( 'unit' => $column, 'fields' => array( $field ) ) ), $acc['credentials']['mixed'] );
		self::assertContains( array( '*:credential_field', $column ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $acc['locks'] ) );
	}

	public function testAProtectedPostLocks(): void {
		// Codex, round 16 (A2): post_password is a credential by its name.
		$ctx = AB_MCP_Sync_Canon::context( array( 'https://staging.test' ) );
		$row = self::post_row();
		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Kind_Post::row_form( $row, $ctx, $acc );
		self::assertSame( array(), $acc['locks'], 'Without a password.' );
		$row['post_password'] = 'demo-secret';
		$acc                  = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Kind_Post::row_form( $row, $ctx, $acc );
		self::assertSame( array( array( 'unit' => 'post_password', 'fields' => array( 'post_password' ) ) ), $acc['credentials']['mixed'] );
		$lock = array_values( array_filter( $acc['locks'], static fn( $l ) => 'post_password' === $l['at'] ) );
		self::assertSame( array( 'post:password_protected', 'Z8d' ), array( $lock[0]['key'], $lock[0]['stage'] ) );
		self::assertStringContainsString( 'by hand', $lock[0]['reason'] );
		// A column of another kind named so: a field.
		$acc = AB_MCP_Sync_Canon::acc();
		\AB_MCP_Sync_Credentials::column( $acc, 'term', 'api_key', 'x' );
		self::assertSame( array( '*:credential_field' ), array_column( $acc['locks'], 'key' ) );
	}

	/** A row of a post as the table holds it. */
	private static function post_row(): array {
		return array(
			'ID'                    => '7',
			'post_author'           => '1',
			'post_date'             => '2026-10-01 10:00:00',
			'post_date_gmt'         => '2026-10-01 08:00:00',
			'post_content'          => '',
			'post_title'            => 'Titel',
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'open',
			'ping_status'           => 'open',
			'post_password'         => '',
			'post_name'             => 'titel',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2026-10-01 10:00:00',
			'post_modified_gmt'     => '2026-10-01 08:00:00',
			'post_content_filtered' => '',
			'post_parent'           => '0',
			'guid'                  => 'https://staging.test/?p=7',
			'menu_order'            => '0',
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => '0',
		);
	}
}
