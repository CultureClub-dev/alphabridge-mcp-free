<?php
/**
 * How a connection is labelled in the settings list.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_OAuth;

final class ConnectionLabelTest extends TestCase {

	public function testTheHubIsLabelledWithItsNameAlone(): void {
		self::assertSame(
			'AlphaBridge Connect',
			AB_MCP_OAuth::connection_label( 'AlphaBridge Connect' ),
			'No "· Claude Connect" suffix: it would say the same thing twice.'
		);
	}

	public function testSurroundingWhitespaceDoesNotHideTheHub(): void {
		self::assertSame( 'AlphaBridge Connect', AB_MCP_OAuth::connection_label( '  AlphaBridge Connect ' ) );
	}

	public function testTheComparisonIgnoresCase(): void {
		self::assertSame( 'AlphaBridge Connect', AB_MCP_OAuth::connection_label( 'alphabridge connect' ) );
	}

	public function testEveryOtherClientKeepsTheSuffix(): void {
		self::assertSame( 'Claude · Claude Connect', AB_MCP_OAuth::connection_label( 'Claude' ) );
		self::assertSame( 'Cursor · Claude Connect', AB_MCP_OAuth::connection_label( 'Cursor' ) );
	}

	public function testALookalikeNameIsNotTreatedAsTheHub(): void {
		self::assertSame(
			'AlphaBridge Connector · Claude Connect',
			AB_MCP_OAuth::connection_label( 'AlphaBridge Connector' ),
			'Only the exact name counts; a similar one must stay distinguishable.'
		);
	}

	public function testAnEmptyClientNameStillProducesAReadableLabel(): void {
		self::assertSame( ' · Claude Connect', AB_MCP_OAuth::connection_label( '' ) );
	}
}
