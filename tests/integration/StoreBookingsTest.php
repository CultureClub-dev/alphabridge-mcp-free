<?php
/**
 * The bookings of the protected store (bookings.json) when they cannot be
 * read (Z8b branch 3, Behebung 1, B2 = V1 of the pre-check; the rule
 * «unreadable is never empty»): a file absent by the listing of the folder
 * of the store is the empty first stock; a file that holds no JSON, an
 * entry that is no booking, a link, a file without read rights or a read
 * that fails is a fault with its way, and no call writes the bookings anew
 * over it, so the bookings of other runs stay byte for byte. On real paths
 * with site A.
 *
 * The cases but the one of the seam use nothing of branch 3, so they run on
 * its base too (40a282c), where they show the fault itself: the bookings
 * read as empty and written anew with the one booking of the call.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Store_Calls;
use AB_MCP_Protected_Store;
use AB_MCP_Store_Budget;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/support/class-it-store-calls.php';

final class StoreBookingsTest extends TestCase {

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		self::wipe();
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
		self::assertTrue( AB_MCP_Protected_Store::available(), 'The store beside WordPress is there.' );
	}

	protected function tearDown(): void {
		if ( class_exists( AB_MCP_Test_Seam::class ) ) {
			AB_MCP_Test_Seam::restore_all();
		}
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_IT_Sites::reset( 'a' );
	}

	private static function root(): string {
		return (string) AB_MCP_IT_Sites::state()['root'];
	}

	private static function wipe(): void {
		$paths   = array( self::root() . '/ab-mcp-store', self::root() . '/run/ab-store-bookings-aim' );
		$uploads = AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-content/uploads';
		foreach ( array_merge( (array) glob( $uploads . '/ab-mcp-store-*' ), (array) glob( $uploads . '/.ab-tmp-*' ) ) as $path ) {
			$paths[] = (string) $path;
		}
		foreach ( $paths as $path ) {
			if ( 0 !== strpos( $path, self::root() . '/' ) ) {
				throw new RuntimeException( "Will not remove $path: it is not in AB_IT_ROOT." );
			}
			self::remove_tree( $path );
		}
	}

	private static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		chmod( $path, 0700 );
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove_tree( $path . '/' . $entry );
		}
		rmdir( $path );
	}

	private static function run_row( int $run, string $state ): void {
		global $wpdb;
		$wpdb->insert(
			AB_MCP_Sync_Schema::table( 'runs' ),
			array(
				'run_id' => $run,
				'type'   => 'push',
				'state'  => $state,
			)
		);
		AB_MCP_Sync_Schema::forget();
	}

	/** The folder of the store with the bookings of run 7 written by the store itself. */
	private static function booked(): string {
		self::run_row( 7, 'open' );
		self::run_row( 8, 'open' );
		self::assertIsArray( AB_MCP_Store_Budget::book( 7, 100 ) );
		$root = AB_MCP_Protected_Store::root();
		self::assertIsString( $root );
		self::assertFileExists( $root . '/bookings.json' );
		return $root;
	}

	/** Neither a booking nor a release writes over the bookings; the bytes of the file stay. */
	private static function assert_left( string $file, string $why, ?string $bytes ): void {
		$book = AB_MCP_Store_Budget::book( 8, 100 );
		self::assertInstanceOf( \WP_Error::class, $book, "$why: book() fails." );
		self::assertSame( 'ab_mcp_store_bookings_unread', $book->get_error_code(), $why );
		self::assertStringContainsString( 'bookings.json', $book->get_error_message(), "$why: the answer names the file." );
		self::assertStringContainsString( 'Ask the host', $book->get_error_message(), "$why: and the way." );
		$release = AB_MCP_Store_Budget::release( 7 );
		self::assertInstanceOf( \WP_Error::class, $release, "$why: release() fails." );
		self::assertInstanceOf( \WP_Error::class, AB_MCP_Store_Budget::bookings(), "$why: bookings() says it." );
		if ( null !== $bytes ) {
			self::assertSame( $bytes, (string) file_get_contents( $file ), "$why: the file stays byte for byte." );
		}
	}

	public function testBookingsThatAreNoBookingsAreNeitherEmptyNorWrittenOver(): void {
		$root = self::booked();
		$file = $root . '/bookings.json';
		$good = (string) file_get_contents( $file );
		self::assertArrayHasKey( '7', (array) json_decode( $good, true ), 'The booking of run 7, another run than the next call.' );
		foreach ( array(
			'no JSON'         => substr( $good, 0, -3 ),
			'no object'       => '"7"',
			'an entry'        => '{"7":{"bytes":"viel","booked":1,"user_id":1}}',
			'a run of no id'  => '{"sieben":{"bytes":100,"booked":1,"user_id":1}}',
		) as $why => $bytes ) {
			file_put_contents( $file, $bytes );
			self::assert_left( $file, $why, $bytes );
		}
	}

	public function testBookingsThatCannotBeReadAreNeitherEmptyNorWrittenOver(): void {
		$root = self::booked();
		$file = $root . '/bookings.json';
		$good = (string) file_get_contents( $file );
		// A link: never followed, never written through.
		$aim = self::root() . '/run/ab-store-bookings-aim';
		rename( $file, $aim );
		symlink( $aim, $file );
		self::assert_left( $file, 'a link', $good );
		self::assertTrue( is_link( $file ), 'The link stays a link.' );
		unlink( $file );
		rename( $aim, $file );
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			return;
		}
		chmod( $file, 0000 );
		try {
			self::assert_left( $file, 'without read rights', null );
		} finally {
			chmod( $file, 0600 );
		}
		self::assertSame( $good, (string) file_get_contents( $file ), 'without read rights: the file stays byte for byte.' );
	}

	public function testBookingsWhoseReadFailsAreNeitherEmptyNorWrittenOver(): void {
		// Behebung 1, B5: the read of the store's seam fails for the file
		// alone (branch 3 on: the bookings are read through the seam).
		$root        = self::booked();
		$file        = $root . '/bookings.json';
		$good        = (string) file_get_contents( $file );
		$calls       = new AB_MCP_IT_Store_Calls();
		$calls->fail = array( 'read' => array( $file ) );
		AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, 'naht_protected_store', $calls );
		self::assert_left( $file, 'a read that fails', $good );
		AB_MCP_Test_Seam::restore_all();
		self::assertContains( array( 'read', $file ), $calls->failed );
		// And an lstat() that fails is no proof that the file is missing.
		$calls       = new AB_MCP_IT_Store_Calls();
		$calls->fail = array( 'lstat' => array( $file ) );
		AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, 'naht_protected_store', $calls );
		self::assert_left( $file, 'an lstat() that fails', $good );
	}

	public function testWithoutTheFileTheBookingsBeginEmptyAndTheNextBookingIsWritten(): void {
		$root = self::booked();
		unlink( $root . '/bookings.json' );
		self::assertSame( array(), AB_MCP_Store_Budget::bookings(), 'Absent by the listing of the folder: the empty first stock.' );
		self::assertIsArray( AB_MCP_Store_Budget::book( 8, 100 ) );
		self::assertSame( array( 8 ), array_keys( AB_MCP_Store_Budget::bookings() ) );
		self::assertTrue( AB_MCP_Store_Budget::release( 8 ) );
		self::assertSame( array(), AB_MCP_Store_Budget::bookings() );
	}
}
