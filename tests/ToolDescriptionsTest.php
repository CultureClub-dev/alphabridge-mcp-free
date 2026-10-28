<?php
/**
 * What the tool definitions say to an AI client.
 *
 * Directories grade every tool by its description and parameter texts
 * (Glama's Tool Definition Quality Score, 23.10.2026: "Update a term." with
 * six bare parameters scored 1.8 of 5). Anthropic's directory rules add a
 * second condition: a description states what a tool does, it does not give
 * the model orders. A neighbouring tool may be named as a plain statement
 * ("wp_get_comment returns one comment"), not as a command ("Read it with
 * wp_get_comment").
 *
 * These are structural smoke tests: every parameter carries a description,
 * no description is little more than the tool's name, no text reads as an
 * order to the model, and the one enum agrees with its handler. Whether a
 * sentence is true is checked by reading the handler, not here.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Taxonomy_Comments;
use PHPUnit\Framework\TestCase;

final class ToolDescriptionsTest extends TestCase {

	/**
	 * Orders to the model: a fixed phrase, or a sentence that opens with a
	 * verb in the imperative and sends the model to a tool ("Read such pages
	 * with wp_get_builder_layout", "Use wp_x instead").
	 */
	private const ORDERS = array(
		'/\b(you must|you should|always call|never call|first call|call wp_[a-z_]+ first|use this instead|instead of this tool)\b/i',
		'/(?:^|[.;:!?]\s+)(?:Read|Use|Call|Run|Try|Check|Look|Fetch|Get|List|Pass|Send|See)\b[^.;:]*?\b(?:with|via|using|through)\s+wp_[a-z_]+/',
		'/(?:^|[.;:!?]\s+)(?:Use|Call|Run|Try|See)\s+wp_[a-z_]+/',
	);

	/**
	 * Orders in a parameter description: "Omit to keep it", or a sentence
	 * opening with a verb in the imperative ("Pass the id", "Leave it out").
	 * A parameter is described by what it is and what happens with it:
	 * "When omitted, it stays as it is", "Default 20".
	 */
	private const PARAMETER_ORDERS = array(
		'/\bomit\b/i',
		'/(?:^|[.;:!?]\s+)(?:Pass|Use|Call|Set|Send|Leave|Provide|Specify|Give|Include|Return|Cut|List|Enter|Choose|Add|Make|Read|Get|Put|Supply|Avoid|Do|Don\'t|Never|Always|Try|Check|See)\s/',
	);

	protected function setUp(): void {
		ab_test_reset();
	}

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

	/**
	 * The description and every parameter description of a tool.
	 *
	 * @param array $def Tool definition.
	 * @return string[]
	 */
	private function texts( array $def ): array {
		$texts = array( (string) $def['description'] );
		foreach ( (array) ( $def['inputSchema']['properties'] ?? array() ) as $prop ) {
			$texts[] = (string) ( $prop['description'] ?? '' );
		}
		return $texts;
	}

	public function testThereAreToolsToCheck(): void {
		self::assertGreaterThan( 30, count( $this->tools() ) );
	}

	public function testNoDescriptionIsLittleMoreThanTheName(): void {
		// "Update a term." restates wp_update_term: after the words of the
		// name and a few small ones, nothing is left. A dozen further words is
		// a floor, not a measure of quality.
		$small = array( 'a', 'an', 'the', 'of', 'to', 'in', 'on', 'for', 'and', 'or', 'by', 'with', 'one', 'single' );
		foreach ( $this->tools() as $name => $def ) {
			$skip  = array_merge( $small, explode( '_', (string) $name ) );
			$words = preg_split( '/[^a-z0-9]+/', strtolower( (string) $def['description'] ), -1, PREG_SPLIT_NO_EMPTY );
			$rest  = array_filter(
				$words,
				static function ( $w ) use ( $skip ) {
					return ! in_array( $w, $skip, true ) && ! in_array( rtrim( $w, 's' ), $skip, true );
				}
			);
			self::assertGreaterThanOrEqual( 12, count( $rest ), $name . ': the description says little beyond the name' );
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
		foreach ( $this->tools() as $name => $def ) {
			foreach ( $this->texts( $def ) as $text ) {
				foreach ( self::ORDERS as $pattern ) {
					self::assertDoesNotMatchRegularExpression( $pattern, $text, $name );
				}
			}
		}
	}

	public function testNoParameterDescriptionGivesOrders(): void {
		foreach ( $this->tools() as $name => $def ) {
			foreach ( (array) ( $def['inputSchema']['properties'] ?? array() ) as $key => $prop ) {
				foreach ( self::PARAMETER_ORDERS as $pattern ) {
					self::assertDoesNotMatchRegularExpression( $pattern, (string) ( $prop['description'] ?? '' ), $name . '.' . $key );
				}
			}
		}
	}

	public function testTheParameterPatternsCatchWhatTheyAreFor(): void {
		$caught = static function ( string $text ): bool {
			foreach ( self::PARAMETER_ORDERS as $pattern ) {
				if ( preg_match( $pattern, $text ) ) {
					return true;
				}
			}
			return false;
		};
		self::assertTrue( $caught( 'New title. Omit to keep it.' ) );
		self::assertTrue( $caught( 'Pass the post id.' ) );
		self::assertTrue( $caught( 'Post id. Leave it out for all posts.' ) );
		self::assertTrue( $caught( 'Include post meta. Default true.' ) );
		self::assertFalse( $caught( 'New title. When omitted, it stays as it is.' ) );
		self::assertFalse( $caught( 'true includes the public custom fields. Default true.' ) );
		self::assertFalse( $caught( 'Listed per page, 1–100. Default 20.' ) );
		self::assertFalse( $caught( 'Only entries by this author user id.' ) );
	}

	public function testTheOrderPatternsCatchWhatTheyAreFor(): void {
		$caught = static function ( string $text ): bool {
			foreach ( self::ORDERS as $pattern ) {
				if ( preg_match( $pattern, $text ) ) {
					return true;
				}
			}
			return false;
		};
		self::assertTrue( $caught( 'Update a post. Read such pages with wp_get_builder_layout.' ) );
		self::assertTrue( $caught( 'Use wp_delete_media instead.' ) );
		self::assertTrue( $caught( 'You must call wp_site_info first.' ) );
		self::assertFalse( $caught( 'Update a post. wp_get_builder_layout returns the outline of such pages.' ) );
		self::assertFalse( $caught( 'Files are deleted with wp_delete_media.' ) );
		self::assertFalse( $caught( 'Read-only; wp_get_term returns one term.' ) );
	}

	public function testTheModerationEnumIsWhatTheHandlerAccepts(): void {
		// The handler is asked about every candidate, independently of the
		// schema; the values it takes have to be exactly the enum. A value
		// left out of the enum, or one added that the handler refuses, fails.
		$enum = $this->tools()['wp_moderate_comment']['inputSchema']['properties']['status']['enum'];
		$GLOBALS['ab_test_comments'][5] = (object) array( 'comment_ID' => 5 );
		// The account may edit the comment: the rights are TermAndCommentRightsTest's.
		$GLOBALS['ab_test_can'] = static fn(): bool => true;

		$candidates = array( 'approve', 'hold', 'spam', 'trash', 'approved', 'unapproved', 'pending', 'unspam', 'untrash', 'delete', 'all', 'publish', '1', '0', '' );
		$accepted   = array();
		foreach ( $candidates as $value ) {
			$out = AB_MCP_Tools_Taxonomy_Comments::moderate_comment( array( 'comment_id' => 5, 'status' => $value ) );
			if ( is_array( $out ) ) {
				$accepted[] = $value;
				self::assertSame( $value, $out['status'] );
			} else {
				self::assertSame( 'ab_mcp_bad_status', $out->get_error_code(), $value );
			}
		}
		self::assertEqualsCanonicalizing( $accepted, $enum );
		self::assertCount( count( $accepted ), $GLOBALS['ab_test_moderated'], 'a refused status changes nothing' );
	}
}
