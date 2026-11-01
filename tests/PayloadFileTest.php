<?php
/**
 * The files the undo store and the protected store write and read
 * (AB_MCP_Payload_File, moved out of AB_MCP_Undo for Z8):
 *
 * - name() is what the undo store has always named its snapshots, and the
 *   secret changes the name;
 * - write() puts a NEW file in place through a temp file: a hard link to the
 *   old file keeps the old bytes, a link at the path is replaced and what it
 *   points to stays untouched, no temp file is left, and a write into a
 *   folder that is not there leaves nothing;
 * - read() checks the size before reading, caps the inflation, and hands out
 *   bytes only against their SHA-256, naming each refusal by its code.
 *
 * That the undo store behaves as before is its own suite
 * (tests/standalone/, UndoStandaloneTest, unchanged).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Payload_File;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-payload-file.php';

final class PayloadFileTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/ab-mcp-payload-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700 );
	}

	protected function tearDown(): void {
		foreach ( (array) scandir( $this->dir ) as $file ) {
			if ( '.' !== $file && '..' !== $file ) {
				unlink( $this->dir . '/' . $file );
			}
		}
		rmdir( $this->dir );
	}

	public function testTheNameIsTheOneTheUndoStoreHasAlwaysUsed(): void {
		self::assertSame( sha1( 'undo_abc|test-salt-auth' ) . '.bin', AB_MCP_Payload_File::name( 'undo_abc', wp_salt( 'auth' ) ) );
		self::assertNotSame( AB_MCP_Payload_File::name( 'undo_abc', 'one' ), AB_MCP_Payload_File::name( 'undo_abc', 'two' ), 'The secret changes the name.' );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{40}\z/', AB_MCP_Payload_File::name( 'group', 'id', '' ), 'Without an extension.' );
	}

	public function testCompressionIsGzipAndReadsBack(): void {
		$payload = str_repeat( 'abc', 1000 );
		$blob    = AB_MCP_Payload_File::compress( $payload );
		self::assertSame( "\x1f\x8b", substr( $blob, 0, 2 ) );
		self::assertTrue( AB_MCP_Payload_File::write( $this->dir . '/p.bin', $blob ) );
		self::assertSame( $payload, AB_MCP_Payload_File::read( $this->dir . '/p.bin', hash( 'sha256', $payload ), 1 << 20, 1 << 20 ) );
		self::assertTrue( AB_MCP_Payload_File::write( $this->dir . '/plain.bin', 'plain' ) );
		self::assertSame( 'plain', AB_MCP_Payload_File::read( $this->dir . '/plain.bin', hash( 'sha256', 'plain' ), 100, 100 ), 'A file that is not gzip is read as it is.' );
	}

	public function testAWriteRenamesANewFileIntoPlace(): void {
		$path = $this->dir . '/file.bin';
		file_put_contents( $path, 'old' );
		link( $path, $this->dir . '/hardlink' );
		self::assertTrue( AB_MCP_Payload_File::write( $path, 'new' ) );
		self::assertSame( 'new', file_get_contents( $path ) );
		self::assertSame( 'old', file_get_contents( $this->dir . '/hardlink' ), 'The old file is not written over: the path holds a new one.' );
		self::assertSame( array( 'file.bin', 'hardlink' ), self::listing( $this->dir ), 'No temp file is left.' );
	}

	public function testALinkAtThePathIsReplacedNotFollowed(): void {
		$victim = $this->dir . '/victim';
		file_put_contents( $victim, 'keep' );
		symlink( $victim, $this->dir . '/file.bin' );
		self::assertTrue( AB_MCP_Payload_File::write( $this->dir . '/file.bin', 'new' ) );
		self::assertSame( 'keep', file_get_contents( $victim ) );
		self::assertFalse( is_link( $this->dir . '/file.bin' ) );
		self::assertSame( 'new', file_get_contents( $this->dir . '/file.bin' ) );
	}

	public function testAWriteThatFailsLeavesNothing(): void {
		self::assertFalse( @AB_MCP_Payload_File::write( $this->dir . '/missing/file.bin', 'x' ) );
		self::assertSame( array(), self::listing( $this->dir ) );
	}

	public function testEachRefusalOfTheReadHasItsCode(): void {
		$raw = 'payload';
		self::assertSame( 'missing', AB_MCP_Payload_File::read( $this->dir . '/none', hash( 'sha256', $raw ), 100, 100 )->get_error_code() );

		file_put_contents( $this->dir . '/big', str_repeat( 'x', 101 ) );
		self::assertSame( 'size', AB_MCP_Payload_File::read( $this->dir . '/big', hash( 'sha256', str_repeat( 'x', 101 ) ), 100, 1000 )->get_error_code(), 'Larger on disk than plausible: refused before reading.' );

		$bomb = gzencode( str_repeat( "\0", 10000 ), 9 );
		file_put_contents( $this->dir . '/bomb', $bomb );
		self::assertSame( 'large', AB_MCP_Payload_File::read( $this->dir . '/bomb', hash( 'sha256', str_repeat( "\0", 10000 ) ), 1000, 9999 )->get_error_code(), 'Inflated past the ceiling.' );
		self::assertSame( str_repeat( "\0", 10000 ), AB_MCP_Payload_File::read( $this->dir . '/bomb', hash( 'sha256', str_repeat( "\0", 10000 ) ), 1000, 10000 ), 'Exactly at the ceiling is read.' );

		file_put_contents( $this->dir . '/p', $raw );
		self::assertSame( 'hash', AB_MCP_Payload_File::read( $this->dir . '/p', hash( 'sha256', 'other' ), 100, 100 )->get_error_code() );
		self::assertSame( 'hash', AB_MCP_Payload_File::read( $this->dir . '/p', '', 100, 100 )->get_error_code(), 'No hash, no bytes.' );
		self::assertSame( 'hash', AB_MCP_Payload_File::read( $this->dir . '/p', strtoupper( hash( 'sha256', $raw ) ), 100, 100 )->get_error_code(), 'The hash as SHA-256 writes it.' );
		self::assertSame( $raw, AB_MCP_Payload_File::read( $this->dir . '/p', hash( 'sha256', $raw ), 100, 100 ) );
		foreach ( array( 'missing', 'size', 'large', 'hash' ) as $code ) {
			self::assertContains( $code, AB_MCP_Payload_File::REFUSALS );
		}
	}

	/** @return string[] */
	private static function listing( string $dir ): array {
		$out = array_values( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) );
		sort( $out );
		return $out;
	}
}
