<?php
/**
 * wp_list_posts pages with LIMIT and OFFSET. Ordered by a field that several
 * posts can share — a date to the second, a title, a menu order — the
 * database may return the tied posts in a different order for every page, so
 * a post can show up on two pages or on none. The post id, unique, breaks the
 * tie, in the direction of the requested order.
 *
 * The test double does not run SQL: these tests check what the tool asks
 * WP_Query for, not the order a database would return.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tools_Content;

final class ListPostsOrderTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	/**
	 * The arguments list_posts hands to WP_Query for this call.
	 *
	 * @param array $call Tool arguments.
	 */
	private function queryArgs( array $call ): array {
		$seen                     = null;
		$GLOBALS['ab_test_query'] = static function ( array $args ) use ( &$seen ): array {
			$seen = $args;
			return array();
		};
		AB_MCP_Tools_Content::list_posts( $call );
		self::assertIsArray( $seen, 'list_posts asks WP_Query.' );
		return $seen;
	}

	public function testTheDefaultIsNewestFirstWithTheIdBreakingTies(): void {
		self::assertSame( array( 'date' => 'DESC', 'ID' => 'DESC' ), $this->queryArgs( array() )['orderby'] );
	}

	/**
	 * Every post field WP_Query::parse_orderby() accepts, except the id: the
	 * four the tool describes and the other names WordPress reads. The list is
	 * the same from WordPress 6.5, the oldest this plugin supports, to 7.2 in
	 * development.
	 */
	public static function sharedFields(): array {
		$fields = array(
			'date',
			'title',
			'modified',
			'menu_order',
			'name',
			'author',
			'parent',
			'type',
			'comment_count',
			'post_date',
			'post_title',
			'post_modified',
			'post_name',
			'post_author',
			'post_parent',
			'post_type',
		);
		return array_combine( $fields, array_map( static fn( string $f ): array => array( $f ), $fields ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'sharedFields' )]
	public function testASharedFieldGetsTheIdAsSecondKeyInTheSameDirection( string $field ): void {
		self::assertSame( array( $field => 'DESC', 'ID' => 'DESC' ), $this->queryArgs( array( 'orderby' => $field ) )['orderby'] );
		self::assertSame( array( $field => 'ASC', 'ID' => 'ASC' ), $this->queryArgs( array( 'orderby' => $field, 'order' => 'asc' ) )['orderby'] );
	}

	public function testOrderingByTheIdNeedsNoSecondKey(): void {
		$args = $this->queryArgs( array( 'orderby' => 'ID', 'order' => 'ASC' ) );
		self::assertSame( 'ID', $args['orderby'], 'The id is unique: no second key, and not the id twice.' );
		self::assertSame( 'ASC', $args['order'] );
	}

	/**
	 * WordPress gives these values a meaning of its own by comparing the whole
	 * orderby string: «relevance» orders search results by how well they
	 * match, «rand» drops the direction, «none» leaves ORDER BY out. Inside a
	 * list they would lose that, so they go through as they came — like a value
	 * WordPress does not know, for which it orders by date, as before: another
	 * spelling of a field is such a value, since WordPress matches names
	 * exactly. Several fields in one string WordPress splits itself; they go
	 * through as well.
	 */
	public static function wholeValues(): array {
		return array(
			'relevance'      => array( 'relevance' ),
			'rand'           => array( 'rand' ),
			'none'           => array( 'none' ),
			'unknown'        => array( 'shoe_size' ),
			'Date'           => array( 'Date' ),
			'TITLE'          => array( 'TITLE' ),
			'id'             => array( 'id' ),
			'several fields' => array( 'date title' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'wholeValues' )]
	public function testAValueWordPressReadsAsAWholeGoesThroughUnchanged( string $value ): void {
		$args = $this->queryArgs( array( 'orderby' => $value, 'search' => 'recap' ) );
		self::assertSame( $value, $args['orderby'] );
		self::assertSame( 'DESC', $args['order'] );
	}

	public function testASearchKeepsItsTermAndItsOrder(): void {
		// WordPress orders search results by relevance only without an
		// orderby or with exactly «relevance»; this tool always names one, so
		// a search was ordered by date before and still is.
		$args = $this->queryArgs( array( 'search' => 'recap' ) );
		self::assertSame( 'recap', $args['s'] );
		self::assertSame( array( 'date' => 'DESC', 'ID' => 'DESC' ), $args['orderby'] );
	}
}
