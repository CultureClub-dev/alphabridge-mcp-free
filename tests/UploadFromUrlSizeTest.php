<?php
/**
 * A file from a URL larger than the limit is refused, not stored cut off.
 *
 * The download passes limit_response_size to the transport, which cuts the
 * body at that length. With the limit itself as the cut, an oversized file
 * arrived exactly as long as the maximum, passed the size check and went into
 * the media library broken, answered with uploaded: true (Codex, 24.10.2026;
 * reproduced with the Requests transport of WordPress 7.1.3).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tools_Media;

final class UploadFromUrlSizeTest extends TestCase {

	private const LIMIT = 1024;

	protected function setUp(): void {
		$GLOBALS['ab_test_http'] = array();
		add_filter( 'ab_mcp_upload_max_bytes', array( self::class, 'limit' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'ab_mcp_upload_max_bytes', array( self::class, 'limit' ) );
		$GLOBALS['ab_test_http_answer'] = null;
	}

	public static function limit(): int {
		return self::LIMIT;
	}

	private static function serve( int $bytes, string $type = 'application/pdf' ): void {
		$GLOBALS['ab_test_http_answer'] = static function () use ( $bytes, $type ) {
			return ab_test_http_response( 200, '%PDF-' . str_repeat( 'x', $bytes - 5 ), array( 'Content-Type' => $type ) );
		};
	}

	private static function code( $result ): string {
		return is_wp_error( $result ) ? (string) $result->get_error_code() : '';
	}

	public function test_a_file_one_byte_over_the_limit_is_refused(): void {
		self::serve( self::LIMIT + 1 );
		$r = AB_MCP_Tools_Media::upload_from_url( array( 'url' => 'https://example.com/big.pdf' ) );
		self::assertSame( 'ab_mcp_too_large', self::code( $r ) );
		self::assertSame( self::LIMIT + 1, $GLOBALS['ab_test_http'][0]['args']['limit_response_size'], 'the transport is asked for one byte more than allowed' );
	}

	public function test_a_much_larger_file_is_refused_too(): void {
		self::serve( self::LIMIT * 3 );
		self::assertSame( 'ab_mcp_too_large', self::code( AB_MCP_Tools_Media::upload_from_url( array( 'url' => 'https://example.com/big.pdf' ) ) ) );
	}

	public function test_a_file_of_exactly_the_limit_passes_the_size_check(): void {
		// Served as HTML, so the call stops at the next check, after the size:
		// that one answering shows the size check let the file through.
		self::serve( self::LIMIT, 'text/html' );
		$r = AB_MCP_Tools_Media::upload_from_url( array( 'url' => 'https://example.com/fits.pdf' ) );
		self::assertSame( 'ab_mcp_bad_type', self::code( $r ), 'counter-check: the limit itself is allowed' );
	}
}
