<?php
/**
 * Three tests, run only through abbruch.xml (CiRunCompleteTest): A passes,
 * B ends the process with a real exit, C would fail but never runs. The
 * name does not end in Test.php, so the unit suite does not collect it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class AbbruchProbe extends TestCase {

	public function testA(): void {
		self::assertTrue( true );
	}

	public function testB(): void {
		exit;
	}

	public function testC(): void {
		self::assertTrue( false, 'C runs only when the process goes on after B.' );
	}
}
