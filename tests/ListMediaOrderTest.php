<?php
/**
 * wp_list_media pages with LIMIT and OFFSET, ordered by WordPress' default,
 * the upload date. Files uploaded in the same second — a batch, an import —
 * tie, and the database may order them differently for every page, so a file
 * can show up on two pages or on none. Without a search the id breaks the
 * tie, as in wp_list_posts. A search keeps WordPress' own order by relevance,
 * which it applies only when no orderby is given.
 *
 * The test double does not run SQL: these tests check what the tool asks
 * WP_Query for.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tools_Media;

final class ListMediaOrderTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	/**
	 * The arguments list_media hands to WP_Query for this call.
	 *
	 * @param array $call Tool arguments.
	 */
	private function queryArgs( array $call ): array {
		$seen                     = null;
		$GLOBALS['ab_test_query'] = static function ( array $args ) use ( &$seen ): array {
			$seen = $args;
			return array();
		};
		AB_MCP_Tools_Media::list_media( $call );
		self::assertIsArray( $seen, 'list_media asks WP_Query.' );
		return $seen;
	}

	public function testWithoutASearchTheNewestComeFirstWithTheIdBreakingTies(): void {
		$args = $this->queryArgs( array() );

		self::assertSame( array( 'date' => 'DESC', 'ID' => 'DESC' ), $args['orderby'] );
	}

	public function testAFilterByTypeKeepsTheTieBreaker(): void {
		$args = $this->queryArgs( array( 'mime' => 'image' ) );

		self::assertSame( 'image', $args['post_mime_type'] );
		self::assertSame( array( 'date' => 'DESC', 'ID' => 'DESC' ), $args['orderby'] );
	}

	public function testASearchKeepsWordPressOrderByRelevance(): void {
		// WordPress orders search results by relevance only when no orderby
		// is given; naming one would switch that off.
		$args = $this->queryArgs( array( 'search' => 'logo' ) );

		self::assertSame( 'logo', $args['s'] );
		self::assertArrayNotHasKey( 'orderby', $args );
	}
}
