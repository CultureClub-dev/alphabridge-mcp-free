<?php
/**
 * The rules of the plan of the writers (the seam, first step T3, U3):
 * AB_MCP_Sync_Push::verdict() asks the registration for the writer of the
 * kind and subtype of an entry, then the rule plan_lock() of that writer. An
 * entry of a kind without a writer is locked with the sentence of the core
 * (Z8d), also a kind of the engine whose writer is not registered (here
 * only), as draft or not; with the writers this plugin registers, each kind
 * gives the lock or the item it gave before its rule went to its writer: a
 * type or a taxonomy the engine does not write, as draft, made by a run.
 *
 * The registrations made as the engine loads are kept before each test and
 * put back after it (reflection), for the other tests of the process.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Push;
use AB_MCP_Sync_Writers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

require_once dirname( __DIR__ ) . '/includes/sync/class-sync-push.php';

final class SyncPlanLockTest extends TestCase {

	/** The overrides of an entry of an object new on the staging site. */
	private const MADE = array( 'action' => 'create', 'result' => 'only_staging', 'live' => null, 'pair' => null );

	/** @var array|null The table as the engine registered it. */
	private $kept = null;

	protected function setUp(): void {
		$this->kept = ( new ReflectionProperty( AB_MCP_Sync_Writers::class, 'writers' ) )->getValue();
	}

	protected function tearDown(): void {
		( new ReflectionProperty( AB_MCP_Sync_Writers::class, 'writers' ) )->setValue( null, $this->kept );
	}

	/** What verdict() makes of a page changed on the staging site (SyncRunTest::entry()) with $over. */
	private static function verdict( array $over, bool $draft = false ) {
		$entry = array_merge(
			array(
				'n'       => 1,
				'kind'    => 'post',
				'subtype' => 'page',
				'result'  => 'changed_on_staging',
				'action'  => 'take_over',
				'staging' => array( 'ref' => '12' ),
				'live'    => array( 'ref' => '34' ),
				'pair'    => array( 'id' => 5 ),
			),
			$over
		);
		return ( new ReflectionMethod( AB_MCP_Sync_Push::class, 'verdict' ) )->invoke( null, $entry, null, $draft, false );
	}

	/** Each kind of AB_MCP_Sync_Engine::WRITERS at f69155e, written out: a subtype it writes, its writer. */
	public static function kinds(): array {
		return array(
			'post'        => array( 'post', 'page', 'AB_MCP_Sync_Post_Writer' ),
			'term'        => array( 'term', 'category', 'AB_MCP_Sync_Term_Writer' ),
			'attachment'  => array( 'attachment', 'attachment', 'AB_MCP_Sync_Attachment_Writer' ),
			'menu'        => array( 'menu', '', 'AB_MCP_Sync_Menu_Writer' ),
			'menu_item'   => array( 'menu_item', '', 'AB_MCP_Sync_Menu_Item_Writer' ),
			'widget_area' => array( 'widget_area', '', 'AB_MCP_Sync_Widget_Area_Writer' ),
			'custom_css'  => array( 'custom_css', '', 'AB_MCP_Sync_Css_Writer' ),
			'option'      => array( 'option', '', 'AB_MCP_Sync_Option_Writer' ),
		);
	}

	#[DataProvider( 'kinds' )]
	public function testAKindWithoutAWriterIsLockedWithTheSentenceOfTheCore( string $kind, string $subtype, string $class ): void {
		$entry = array( 'kind' => $kind, 'subtype' => $subtype );
		$none  = array( 'locked' => array( 'n' => 1, 'kind' => $kind, 'subtype' => $subtype, 'action' => 'take_over', 'result' => 'changed_on_staging', 'reason' => 'The kind ' . $kind . ' is not written by a run of this version.', 'stage' => 'Z8d' ) );
		self::assertSame( array( $kind, 'update' ), array( self::verdict( $entry )['item']['kind'], self::verdict( $entry )['item']['op'] ), 'With the writers of this plugin.' );
		AB_MCP_Sync_Writers::reset();
		self::assertSame( $none, self::verdict( $entry ), 'No writer registered.' );
		self::assertSame( $none, self::verdict( $entry, true ), 'As draft: the registration is asked before the rule.' );
		foreach ( self::kinds() as list( $other, , $writer ) ) {
			if ( $other !== $kind ) {
				self::assertTrue( AB_MCP_Sync_Writers::register( $other, AB_MCP_Sync_Writers::WHOLE, $writer ), $other );
			}
		}
		self::assertSame( $none, self::verdict( $entry ), 'Every other kind registered.' );
		self::assertTrue( AB_MCP_Sync_Writers::register( $kind, AB_MCP_Sync_Writers::WHOLE, $class ) );
		self::assertSame( array( $kind, 'update' ), array( self::verdict( $entry )['item']['kind'], self::verdict( $entry )['item']['op'] ), 'Registered again.' );
	}

	public function testThePlanAsksTheWriterOfTheSubtypeOfAnEntry(): void {
		// Only here: post with post and page (D2), a kind registered by subtype.
		AB_MCP_Sync_Writers::reset();
		self::assertTrue( AB_MCP_Sync_Writers::register( 'post', array( 'post', 'page' ), 'AB_MCP_Sync_Post_Writer' ) );
		self::assertSame( array( 'post', 'page', 'update' ), array_values( array_intersect_key( self::verdict( array() )['item'], array_flip( array( 'kind', 'subtype', 'op' ) ) ) ) );
		$block = self::verdict( array( 'subtype' => 'wp_block' ) );
		self::assertSame( array( 'The kind post is not written by a run of this version.', 'Z8d' ), array( $block['locked']['reason'], $block['locked']['stage'] ) );
	}

	/**
	 * Case => the overrides of the entry, as draft, and what verdict() gave
	 * before the rules went to the writers: the item (kind, op) or the lock
	 * (its reason or a part of it, its stage).
	 */
	public static function rules(): array {
		$cases = array(
			'a page'                    => array( array(), false, array( 'item' => array( 'post', 'update' ) ) ),
			'a new page as draft'       => array( self::MADE, true, array( 'item' => array( 'post', 'create' ) ) ),
			'a type it does not write'  => array( array( 'subtype' => 'product' ), false, array( 'locked' => array( 'The engine of the kind post writes the types post, page, wp_block, wp_template, wp_template_part, wp_global_styles, wp_navigation; the type product waits for its field schema.', 'Z8d' ) ) ),
			'a category'                => array( array( 'kind' => 'term', 'subtype' => 'category' ), false, array( 'item' => array( 'term', 'update' ) ) ),
			'a taxonomy it does not write' => array( array( 'kind' => 'term', 'subtype' => 'genre' ), false, array( 'locked' => array( 'The engine of the kind term writes the terms of category, post_tag, wp_pattern_category; the taxonomy genre waits for its classification.', 'Z8d' ) ) ),
			'an attachment'             => array( array( 'kind' => 'attachment', 'subtype' => 'attachment' ), false, array( 'item' => array( 'attachment', 'update' ) ) ),
			'an attachment as draft'    => array( array( 'kind' => 'attachment', 'subtype' => 'attachment' ), true, array( 'locked' => array( 'An attachment has no status draft: leave it out of as_draft.', null ) ) ),
		);
		// The structure of the site (branch 11): each kind an item, never as
		// draft; a widget area, an option and the additional CSS not made.
		foreach ( array( 'menu' => null, 'menu_item' => null, 'widget_area' => 'widget_area:create', 'custom_css' => 'custom_css:create', 'option' => 'option:create' ) as $kind => $key ) {
			$entry                        = array( 'kind' => $kind, 'subtype' => '' );
			$cases[ $kind ]               = array( $entry, false, array( 'item' => array( $kind, 'update' ) ) );
			$cases[ $kind . ' as draft' ] = array( $entry, true, array( 'locked' => array( 'A ' . $kind . ' has no status draft: leave it out of as_draft.', null ) ) );
			$cases[ 'a new ' . $kind ]    = array( self::MADE + $entry, false, null === $key ? array( 'item' => array( $kind, 'create' ) ) : array( 'locked' => array( '(' . $key . ')', 'Z8d' ) ) );
		}
		return $cases;
	}

	#[DataProvider( 'rules' )]
	public function testWithTheWritersOfThisPluginEachKindGivesTheLockOrTheItemOfBefore( array $over, bool $draft, array $want ): void {
		$got = self::verdict( $over, $draft );
		self::assertSame( array_keys( $want ), array_keys( (array) $got ) );
		if ( isset( $want['item'] ) ) {
			self::assertSame( array( $want['item'][0], $want['item'][1], $draft ), array( $got['item']['kind'], $got['item']['op'], $got['item']['as_draft'] ) );
			return;
		}
		self::assertStringContainsString( $want['locked'][0], $got['locked']['reason'] );
		self::assertSame( $want['locked'][1], $got['locked']['stage'] );
	}
}
