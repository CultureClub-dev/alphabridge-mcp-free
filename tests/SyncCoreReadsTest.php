<?php
/**
 * The cases of SyncCoreReads in the unit suite: no HTML API, no
 * WP_HTML_Decoder, no url_to_postid(). A text with a tag or a character
 * reference fails closed here (the credential check: unreadable; an
 * address: locked, nothing rewritten); every other case gives its answer.
 * The same cases read by the core: tests/integration/SyncCoreReadsTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-refs.php';

final class SyncCoreReadsTest extends TestCase {

	use SyncCoreReads;

	protected static function core(): bool {
		return false;
	}

	public function testThisSuiteHasNoHtmlApi(): void {
		self::assertFalse( class_exists( 'WP_HTML_Tag_Processor' ) );
		self::assertFalse( class_exists( 'WP_HTML_Decoder' ) );
		self::assertFalse( function_exists( 'url_to_postid' ) );
	}
}
