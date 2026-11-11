<?php
/**
 * A class skipped in setUpBeforeClass(), as a test does where its tool is
 * missing (Codex round 1, A4): PHPUnit 11.5 writes no testcase for its two
 * tests to the JUnit log, only «Test Suite Skipped» to the event log. Run
 * through klassenskip.xml (CiRunCompleteTest); a run with it is complete.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class KlassenSkipProbe extends TestCase {

	public static function setUpBeforeClass(): void {
		self::markTestSkipped( 'The whole class is skipped on purpose.' );
	}

	public function testEins(): void {
		self::assertTrue( true );
	}

	public function testZwei(): void {
		self::assertTrue( true );
	}
}
