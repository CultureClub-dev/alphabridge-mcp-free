<?php
/**
 * The integration suite stays out of what ships (Entscheid 34 of the Z8
 * plan): no shipped PHP file names the test fetcher, the helpers of the
 * integration suite or its directory — so no filter, switch or
 * class_exists() can reach for them — and the release leaves the tests
 * directory out. At runtime the same is TestFetcherIsolationTest in
 * tests/integration (site A loaded without the bootstrap).
 *
 * What ships is read from .gitattributes (export-ignore, which git archive
 * honours for a release) rather than listed here again.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ShippedCodeKnowsNoTestFetcherTest extends TestCase {

	/** What only the integration suite defines or holds. */
	private const NEEDLES = array( 'AB_MCP_Test_', 'AB_MCP_IT_', 'tests/integration', 'class-test-sync-fetcher' );

	/**
	 * The top-level entries a release leaves out.
	 *
	 * @return string[]
	 */
	private static function export_ignored(): array {
		$ignored = array();
		foreach ( (array) file( dirname( __DIR__ ) . '/.gitattributes', FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( preg_match( '#^/([^\s/]+)\s+export-ignore\b#', (string) $line, $m ) ) {
				$ignored[] = $m[1];
			}
		}
		return $ignored;
	}

	/**
	 * Every PHP file of the working tree a release carries.
	 *
	 * @return string[] Paths from the repo root.
	 */
	private static function shipped_php(): array {
		$root = dirname( __DIR__ );
		$skip = array_merge( self::export_ignored(), array( '.git', 'vendor', 'node_modules' ) );
		$out  = array();
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
			if ( in_array( explode( '/', $relative )[0], $skip, true ) || 'php' !== $file->getExtension() ) {
				continue;
			}
			$out[] = $relative;
		}
		sort( $out );
		return $out;
	}

	public function testNoShippedFileNamesTheTestFetcherOrTheIntegrationSuite(): void {
		$files = self::shipped_php();
		// The scan sees the plugin, not an empty list.
		self::assertContains( 'alphabridge-mcp.php', $files );
		self::assertContains( 'includes/class-outbound.php', $files );
		self::assertGreaterThan( 100, count( $files ) );
		self::assertNotContains( 'tests/integration/support/class-test-sync-fetcher.php', $files );

		foreach ( $files as $relative ) {
			$code = (string) file_get_contents( dirname( __DIR__ ) . '/' . $relative );
			foreach ( self::NEEDLES as $needle ) {
				self::assertStringNotContainsString( $needle, $code, "$relative names $needle, which only the integration suite has." );
			}
		}
	}

	public function testTheReleaseLeavesTheTestsDirectoryOut(): void {
		self::assertContains( 'tests', self::export_ignored(), '.gitattributes: /tests export-ignore' );
		self::assertContains( '/tests', array_map( 'trim', (array) file( dirname( __DIR__ ) . '/.distignore', FILE_IGNORE_NEW_LINES ) ), '.distignore: /tests' );
		self::assertFileExists( dirname( __DIR__ ) . '/tests/integration/support/class-test-sync-fetcher.php' );
	}
}
