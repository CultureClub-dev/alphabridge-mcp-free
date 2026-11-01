<?php
/**
 * The test fetcher exists only through the integration bootstrap
 * (Entscheid 34 of the Z8 plan): site A, loaded in a process of its own
 * exactly as WordPress loads it, runs the plugin and knows neither the
 * class nor any file of the tests directory. That the shipped files do
 * not name it is ShippedCodeKnowsNoTestFetcherTest in the unit suite.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TestFetcherIsolationTest extends TestCase {

	public function testThePluginLoadsWithoutTheBootstrapAndKnowsNoTestFetcher(): void {
		// Here the bootstrap loaded it, from the suite's own directory.
		self::assertTrue( class_exists( 'AB_MCP_Test_Sync_Fetcher', false ) );
		self::assertSame( realpath( __DIR__ . '/support/class-test-sync-fetcher.php' ), ( new ReflectionClass( 'AB_MCP_Test_Sync_Fetcher' ) )->getFileName() );

		$command = array( PHP_BINARY, __DIR__ . '/bin/without-bootstrap.php', AB_MCP_IT_Sites::state_file() );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $out . $err );

		$seen = json_decode( (string) $out, true );
		self::assertIsArray( $seen, $out . $err );
		// The plugin did load there — the same code as here — so the
		// absence below is the absence of the bootstrap, not of the plugin.
		self::assertSame( AB_MCP_VERSION, $seen['plugin'] );
		self::assertSame( realpath( AB_MCP_DIR ), $seen['plugin_dir'] );
		self::assertTrue( $seen['outbound'] );
		self::assertFalse( $seen['test_fetcher'], 'The product code loaded the test fetcher.' );
		self::assertSame( array(), $seen['from_tests_dir'], 'The product code loaded files of the tests directory.' );
	}
}
