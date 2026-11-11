<?php
/**
 * A red test whose status a shutdown function turns into 0 (Codex round 1,
 * A1): PHPUnit computes status 1, the shutdown function registered here
 * ends the process with exit( 0 ) after it. Run through rot.xml
 * (CiRunCompleteTest).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class RotProbe extends TestCase {

	public static function setUpBeforeClass(): void {
		register_shutdown_function(
			static function () {
				exit( 0 );
			}
		);
	}

	public function testGruen(): void {
		self::assertTrue( true );
	}

	public function testRot(): void {
		self::assertTrue( false, 'Red on purpose.' );
	}
}
