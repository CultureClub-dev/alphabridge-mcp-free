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
			'No "· OAuth" suffix: the site owner should see at a glance that it came through the hub.'
		);
	}

	public function testSurroundingWhitespaceDoesNotHideTheHub(): void {
		self::assertSame( 'AlphaBridge Connect', AB_MCP_OAuth::connection_label( '  AlphaBridge Connect ' ) );
	}

	public function testTheComparisonIgnoresCase(): void {
		self::assertSame( 'AlphaBridge Connect', AB_MCP_OAuth::connection_label( 'alphabridge connect' ) );
	}

	public function testEveryOtherClientGetsItsNameAndOAuth(): void {
		self::assertSame( 'Claude · OAuth', AB_MCP_OAuth::connection_label( 'Claude' ) );
		self::assertSame( 'Cursor · OAuth', AB_MCP_OAuth::connection_label( 'Cursor' ) );
	}

	public function testNoClientIsLabelledWithAnotherVendorsProduct(): void {
		// On 26.09.2026 the connections list of a live site read
		// "ChatGPT · Claude Connect".
		$label = AB_MCP_OAuth::connection_label( 'ChatGPT' );
		self::assertSame( 'ChatGPT · OAuth', $label );
		self::assertStringNotContainsString( 'Claude', $label );
	}

	public function testALookalikeNameIsNotTreatedAsTheHub(): void {
		self::assertSame(
			'AlphaBridge Connector · OAuth',
			AB_MCP_OAuth::connection_label( 'AlphaBridge Connector' ),
			'Only the exact name counts; a similar one must stay distinguishable.'
		);
	}

	public function testANamelessClientIsLabelledOAuthWithoutADanglingSeparator(): void {
		self::assertSame( 'OAuth', AB_MCP_OAuth::connection_label( '' ) );
		self::assertSame( 'OAuth', AB_MCP_OAuth::connection_label( "  \t " ), 'Whitespace alone is no name.' );
	}
}
