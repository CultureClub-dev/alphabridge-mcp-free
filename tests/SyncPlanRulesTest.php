<?php
/**
 * The rules of N-c of the seam at fixed inputs (first step T3, U4), where no
 * other test of the unit suite meets the place: the identity of an object
 * that is not here, for the reserve of its backup (no. 4: a term by the rule
 * absent_identity() of its writer, every other kind the form of a post the
 * core holds); the rank of order() (no. 5: a term first by the rule
 * first_by_depth(), no other kind has the rule); the objects of the space of
 * posts a run creates (no. 2: a post by the core, an attachment by the rule
 * made_in_posts(), no other kind); the parent a run gives a term here (no. 1,
 * P4-B2: AB_MCP_Sync_Term_Writer::planned_parent() gives null for a refused
 * line and for a parent the run makes first, else the parent's number, 0 as
 * well). With the writers this plugin registers.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Push;
use AB_MCP_Sync_Term_Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

require_once dirname( __DIR__ ) . '/includes/sync/class-sync-push.php';

final class SyncPlanRulesTest extends TestCase {

	/** What the private method $name of AB_MCP_Sync_Push gives for $args. */
	private static function push( string $name, ...$args ) {
		return ( new ReflectionMethod( AB_MCP_Sync_Push::class, $name ) )->invoke( null, ...$args );
	}

	/** An item of the plan of the kind and subtype, new on the staging site as $ref. */
	private static function item( string $kind, string $subtype, string $ref = '12' ): array {
		return array( 'kind' => $kind, 'subtype' => $subtype, 'op' => 'create', 'staging_ref' => $ref, 'action' => 'create', 'refs' => array() );
	}

	/** Each kind of AB_MCP_Sync_Engine::WRITERS at f69155e, written out, with a subtype it writes. */
	public static function kinds(): array {
		return array(
			'post'        => array( 'post', 'page' ),
			'term'        => array( 'term', 'category' ),
			'attachment'  => array( 'attachment', 'attachment' ),
			'menu'        => array( 'menu', 'nav_menu' ),
			'menu_item'   => array( 'menu_item', 'nav_menu_item' ),
			'widget_area' => array( 'widget_area', '' ),
			'custom_css'  => array( 'custom_css', 'custom_css' ),
			'option'      => array( 'option', '' ),
		);
	}

	#[DataProvider( 'kinds' )]
	public function testTheIdentityOfAnObjectThatIsNotHereIsByTheRuleOfATermElseTheFormOfAPost( string $kind, string $subtype ): void {
		$want = 'term' === $kind
			? array( 'taxonomy' => $subtype, 'slug' => '12' )
			: array( 'post_type' => $subtype, 'post_name' => '12', 'theme' => '' );
		self::assertSame( $want, self::push( 'absent_identity', self::item( $kind, $subtype ) ) );
	}

	#[DataProvider( 'kinds' )]
	public function testATermRanksFirstByItsDepthAndNoOtherKindHasTheRule( string $kind, string $subtype ): void {
		$item = self::item( $kind, $subtype ) + array( 'depth' => 3 );
		$want = 'term' === $kind ? array( 0, 3, 7 ) : null;
		self::assertSame( $want, self::push( 'writer_rule', $item, 'first_by_depth', array( $item, 7 ) ) );
	}

	public function testTheTermsComeFirstInTheOrderByTheirDepth(): void {
		self::assertSame( array( 0, 0, 7 ), AB_MCP_Sync_Term_Writer::first_by_depth( array(), 7 ), 'No depth set: 0.' );
		$items = array(
			1 => self::item( 'post', 'page', '10' ),
			2 => array( 'depth' => 1 ) + self::item( 'term', 'category', '5' ),
			3 => array( 'depth' => 0 ) + self::item( 'term', 'category', '6' ),
		);
		self::assertSame( array( 3, 2, 1 ), array_keys( self::push( 'order', $items )['items'] ), 'The root, its child, then the page.' );
	}

	#[DataProvider( 'kinds' )]
	public function testOnlyAPostAndAnAttachmentAreObjectsTheRunCreatesInTheSpaceOfPosts( string $kind, string $subtype ): void {
		self::assertSame( in_array( $kind, array( 'post', 'attachment' ), true ), self::push( 'made_in_posts', self::item( $kind, $subtype ) ) );
	}

	public function testThePlannedParentOfATermIsNullForARefusedLineOrAParentTheRunMakesFirstElseItsNumber(): void {
		$line = static fn( string $parent, string $taxonomy = 'category' ): array => array(
			'kind'     => 'term',
			'ref'      => '12',
			'identity' => array( 'taxonomy' => $taxonomy, 'slug' => 'news' ),
			'status'   => 'z8',
			'locks'    => array(),
			'refs'     => array(),
			'form'     => array( 'kind' => 'term', 'row' => array( 'name' => 'News', 'slug' => 'news' ), 'meta' => array() ),
			'content'  => array( 'row' => array( 'name' => 'News', 'slug' => 'news', 'term_group' => '0', 'taxonomy' => $taxonomy, 'description' => '', 'parent' => $parent ), 'meta' => array(), 'terms' => array() ),
		);
		$ctx  = static fn( array $pending = array() ): array => array(
			'map'       => array( 'post' => array(), 'term' => array( 7 => 70 ) ),
			'addresses' => array( 'https://staging.example.com' => 'https://www.example.com' ),
			'routes'    => null,
			'pending'   => $pending + array( 'post' => array(), 'term' => array() ),
		);
		$one  = array( 'op' => 'update', 'live_ref' => '34', 'as_draft' => false );
		self::assertNull( AB_MCP_Sync_Term_Writer::planned_parent( $one, new WP_Error( 'ab_mcp_sync_line', 'No line.' ), $ctx() ), 'A line the package refused.' );
		self::assertNull( AB_MCP_Sync_Term_Writer::planned_parent( $one, $line( '7', 'genre' ), $ctx() ), 'A line the term refuses.' );
		self::assertNull( AB_MCP_Sync_Term_Writer::planned_parent( $one, $line( '8' ), $ctx( array( 'term' => array( 8 => true ) ) ) ), 'A parent the run makes first.' );
		self::assertSame( 70, AB_MCP_Sync_Term_Writer::planned_parent( $one, $line( '7' ), $ctx() ), 'The parent by its pair.' );
		self::assertSame( 0, AB_MCP_Sync_Term_Writer::planned_parent( $one, $line( '0' ), $ctx() ), 'A root.' );
	}
}
