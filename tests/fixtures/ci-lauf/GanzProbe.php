<?php
/**
 * Seven tests that run to the end, through ganz.xml and klassenskip.xml
 * (CiRunCompleteTest): three data sets of one test, a skipped one, an
 * incomplete one, a plain one, and one skipped by a requirement (PHPUnit
 * writes «Test Skipped» for it, without «Test Finished»).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
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

	#[RequiresPhpExtension( 'ab_it_gibt_es_nicht' )]
	public function testRequirement(): void {
		self::assertTrue( true );
	}
}
