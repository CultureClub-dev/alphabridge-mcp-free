<?php
/**
 * Six tests that run to the end, through ganz.xml (CiRunCompleteTest): three
 * data sets of one test, a skipped one, an incomplete one and a plain one.
 * The JUnit log and --list-tests-xml must count them alike.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GanzProbe extends TestCase {

	public static function sets(): array {
		return array(
			'eins' => array( 1 ),
			'zwei' => array( 2 ),
			array( 3 ),
		);
	}

	#[DataProvider( 'sets' )]
	public function testSet( int $n ): void {
		self::assertGreaterThan( 0, $n );
	}

	public function testSkipped(): void {
		self::markTestSkipped( 'Skipped on purpose: counts as run.' );
	}

	public function testIncomplete(): void {
		self::markTestIncomplete( 'Incomplete on purpose: counts as run.' );
	}

	public function testPlain(): void {
		self::assertTrue( true );
	}
}
