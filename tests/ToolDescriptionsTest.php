<?php
/**
 * What the tool definitions say to an AI client.
 *
 * Directories grade every tool by its description and parameter texts
 * (Glama's Tool Definition Quality Score, 23.10.2026: "Update a term." with
 * six bare parameters scored 1.8 of 5). Anthropic's directory rules add a
 * second condition: a description states what a tool does, it does not give
 * the model orders. So every parameter of every tool carries a description,
 * and no description tells the model what it must do or which tool to call
 * instead. A neighbouring tool may be named as a plain statement.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Tool_Registry;
use PHPUnit\Framework\TestCase;

final class ToolDescriptionsTest extends TestCase {

	/**
	 * Every tool this plugin registers, with its definition.
	 *
	 * @return array<string,array>
	 */
	private function tools(): array {
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/class-tools-*.php' ) as $file ) {
			require_once $file;
		}
		$r = new AB_MCP_Tool_Registry();
		foreach ( get_declared_classes() as $class ) {
			if ( 0 === strpos( $class, 'AB_MCP_Tools_' ) && 'AB_MCP_Tools_Base' !== $class && method_exists( $class, 'register' ) ) {
				$class::register( $r );
			}
		}
		return $r->all();
	}

	public function testThereAreToolsToCheck(): void {
		self::assertGreaterThan( 30, count( $this->tools() ) );
	}

	public function testEveryToolSaysWhatItDoesInMoreThanItsName(): void {
		foreach ( $this->tools() as $name => $def ) {
			$text = (string) $def['description'];
			self::assertGreaterThanOrEqual( 150, strlen( $text ), $name . ': a description that only restates the name' );
		}
	}

	public function testEveryParameterIsDescribed(): void {
		foreach ( $this->tools() as $name => $def ) {
			$props = (array) ( $def['inputSchema']['properties'] ?? array() );
			foreach ( $props as $key => $prop ) {
				self::assertNotSame( '', trim( (string) ( $prop['description'] ?? '' ) ), $name . '.' . $key . ' has no description' );
			}
		}
	}

	public function testNoDescriptionGivesTheModelOrders(): void {
		$orders = '/\b(you must|you should|always call|never call|first call|call [a-z_]+ first|use this instead|use wp_[a-z_]+ instead|instead of this tool)\b/i';
		foreach ( $this->tools() as $name => $def ) {
			$texts = array( (string) $def['description'] );
			foreach ( (array) ( $def['inputSchema']['properties'] ?? array() ) as $prop ) {
				$texts[] = (string) ( $prop['description'] ?? '' );
			}
			foreach ( $texts as $text ) {
				self::assertDoesNotMatchRegularExpression( $orders, $text, $name );
			}
		}
	}

	public function testAnEnumNamesOnlyValuesTheHandlerAccepts(): void {
		// wp_moderate_comment refuses any other status, so the enum promises
		// nothing the handler does not hold to.
		$status = $this->tools()['wp_moderate_comment']['inputSchema']['properties']['status'];
		self::assertSame( array( 'approve', 'hold', 'spam', 'trash' ), $status['enum'] );
	}
}
