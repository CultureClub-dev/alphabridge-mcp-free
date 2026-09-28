<?php
/**
 * The other ways a file could be made visible or moved, and how a post comes
 * out of the trash through wp_update_post:
 * - wp_create_post and wp_duplicate_post make no files — without a file
 *   behind them they would be attachment pages with whatever text was given,
 *   stored as "inherit";
 * - wp_update_post takes a parent only as a post id, never null or a list;
 *   wp_create_post no list (null is no parent there);
 * - a file goes under another post only where the account may edit it;
 * - a post in the trash that is given a status comes out as wp_untrash_post()
 *   takes one out: notes gone, comments back, hooks run — in one save;
 * - wp_update_media keeps the rule of wp_update_post and reports a failed save.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tools_Content;
use AB_MCP_Tools_Media;

final class FilePathsTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		update_option( 'timezone_string', 'Europe/Zurich' );
		self::rights();
	}

	/**
	 * Every capability, but the right to publish only when $publish, and
	 * edit_post not for the ids in $no_edit.
	 *
	 * @param int[] $no_edit Post ids the account may not edit.
	 */
	private static function rights( bool $publish = true, array $no_edit = array() ): void {
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args = array() ) use ( $publish, $no_edit ): bool {
			if ( 'publish_posts' === $cap ) {
				return $publish;
			}
			return ! ( 'edit_post' === $cap && in_array( (int) ( $args[0] ?? 0 ), $no_edit, true ) );
		};
	}

	private static function code( $res ): string {
		return is_wp_error( $res ) ? (string) $res->get_error_code() : '';
	}

	/* ------------------------------------------------ create and duplicate */

	public function testCreatePostMakesNoFiles(): void {
		$res = AB_MCP_Tools_Content::create_post( array( 'type' => 'attachment', 'title' => 'Not a file', 'status' => 'draft' ) );
		self::assertSame( 'ab_mcp_invalid_type', self::code( $res ) );
		self::assertSame( array(), $GLOBALS['ab_test_inserted'] );
	}

	public function testCreatePostTakesAParentOnlyAsANumber(): void {
		$res = AB_MCP_Tools_Content::create_post( array( 'type' => 'page', 'title' => 'Child', 'parent' => array( 7 ) ) );
		self::assertSame( 'ab_mcp_invalid_parent', self::code( $res ), 'a list would reach WordPress as 1, an unrelated post' );
		self::assertSame( array(), $GLOBALS['ab_test_inserted'] );
		$res = AB_MCP_Tools_Content::create_post( array( 'type' => 'page', 'title' => 'Top', 'parent' => null ) );
		self::assertFalse( is_wp_error( $res ), 'null is no parent, as without one' );
	}

	public function testDuplicateMakesNoFiles(): void {
		ab_test_add_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'private' ) );
		self::assertSame( 'ab_mcp_invalid_type', self::code( AB_MCP_Tools_Content::duplicate_post( array( 'id' => 5 ) ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_inserted'] );
	}

	public function testDuplicateReportsAFailedInsert(): void {
		ab_test_add_post( 6, array( 'post_status' => 'publish' ) );
		$res = AB_MCP_Tools_Content::duplicate_post( array( 'id' => 6 ) );
		self::assertFalse( is_wp_error( $res ) );
		self::assertSame( array( true ), $GLOBALS['ab_test_insert_wp_error'], 'asked with $wp_error' );
		$GLOBALS['ab_test_insert_fails'] = true;
		self::assertTrue( is_wp_error( AB_MCP_Tools_Content::duplicate_post( array( 'id' => 6 ) ) ), 'a failure is an error, not a copy with the id 0' );
	}

	/* ------------------------------------------------------------- parents */

	public function testUpdatePostTakesAParentOnlyAsANumber(): void {
		ab_test_add_post( 7, array( 'post_type' => 'page' ) );
		ab_test_add_post( 8, array( 'post_type' => 'page', 'post_parent' => 7 ) );
		foreach ( array( null, array( 7 ) ) as $bad ) {
			self::assertSame( 'ab_mcp_invalid_parent', self::code( AB_MCP_Tools_Content::update_post( array( 'id' => 8, 'parent' => $bad ) ) ) );
		}
		self::assertSame( array(), $GLOBALS['ab_test_updated'], 'the page stays where it is' );
	}

	public function testAFileGoesOnlyUnderAPostTheAccountMayEdit(): void {
		ab_test_add_post( 7, array( 'post_status' => 'publish' ) );
		ab_test_add_post( 9, array( 'post_status' => 'publish' ) );
		ab_test_add_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 7 ) );
		self::rights( true, array( 7, 9 ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'parent' => 9 ) ) ), 'it would show in that post\'s gallery' );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
		self::assertFalse( is_wp_error( AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'parent' => 7, 'title' => 'Caption' ) ) ), 'the parent it has is no change' );
		self::rights( true );
		self::assertFalse( is_wp_error( AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'parent' => 9 ) ) ) );
		self::assertSame( 'ab_mcp_not_found', self::code( AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'parent' => 999 ) ) ) );
	}

	public function testAPageTakesAnyPageAsParent(): void {
		ab_test_add_post( 7, array( 'post_type' => 'page' ) );
		ab_test_add_post( 8, array( 'post_type' => 'page' ) );
		self::rights( true, array( 7 ) );
		self::assertFalse( is_wp_error( AB_MCP_Tools_Content::update_post( array( 'id' => 8, 'parent' => 7 ) ) ), 'as in WordPress: the edit right on the parent is asked only for files' );
	}

	/* --------------------------------------------------------- the trash */

	private static function trashed( int $id ): void {
		ab_test_add_post( $id, array( 'post_status' => 'trash' ) );
		$GLOBALS['ab_test_meta'][ $id ] = array(
			'_wp_trash_meta_status' => array( 'publish' ),
			'_wp_trash_meta_time'   => array( '1700000000' ),
		);
	}

	/** @return string[] The actions fired, by name. */
	private static function actions(): array {
		return array_column( $GLOBALS['ab_test_actions'], 0 );
	}

	public function testAPostComesOutOfTheTrashAsWordPressTakesOneOut(): void {
		self::trashed( 5 );
		$res = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'status' => 'draft' ) );
		self::assertFalse( is_wp_error( $res ) );
		self::assertSame( 'draft', get_post( 5 )->post_status );
		self::assertCount( 1, $GLOBALS['ab_test_updated'], 'one save: status, date and fields together' );
		self::assertSame( array(), $GLOBALS['ab_test_meta'][5], 'the trash notes are gone' );
		self::assertSame( array( 5 ), $GLOBALS['ab_test_comments_untrashed'], 'the comments get their states back' );
		self::assertSame( array( 'untrash_post', 'untrashed_post' ), self::actions() );
		self::assertSame( array( 5, 'publish' ), $GLOBALS['ab_test_actions'][1][1], 'with the status from before the trash' );
	}

	public function testAPluginCanKeepThePostInTheTrash(): void {
		self::trashed( 5 );
		add_filter( 'pre_untrash_post', static fn() => false );
		self::assertSame( 'ab_mcp_untrash_refused', self::code( AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'status' => 'draft' ) ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
		self::assertSame( 'trash', get_post( 5 )->post_status );
	}

	public function testAFailedSaveKeepsTheTrashNotes(): void {
		self::trashed( 5 );
		$GLOBALS['ab_test_update_fails'] = true;
		self::assertTrue( is_wp_error( AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'status' => 'draft' ) ) ) );
		self::assertSame( array( 'publish' ), $GLOBALS['ab_test_meta'][5]['_wp_trash_meta_status'], 'a file under it keeps the visibility this note gives it' );
		self::assertSame( array(), $GLOBALS['ab_test_comments_untrashed'] );
		self::assertSame( array( 'untrash_post' ), self::actions() );
	}

	public function testAnErrorAfterTheWriteStillFinishesTheWayOut(): void {
		self::trashed( 5 );
		$GLOBALS['ab_test_update_errors_after_write'] = true;
		self::assertTrue( is_wp_error( AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'status' => 'draft' ) ) ), 'the error is reported' );
		self::assertSame( array(), $GLOBALS['ab_test_meta'][5], 'but the row left the trash, so the notes go' );
		self::assertSame( array( 5 ), $GLOBALS['ab_test_comments_untrashed'] );
	}

	public function testATitleAloneLeavesThePostInTheTrash(): void {
		self::trashed( 5 );
		self::assertFalse( is_wp_error( AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'title' => 'New' ) ) ) );
		self::assertSame( 'trash', get_post( 5 )->post_status );
		self::assertSame( array(), self::actions() );
		self::assertSame( array( 'publish' ), $GLOBALS['ab_test_meta'][5]['_wp_trash_meta_status'] );
	}

	/* ------------------------------------------------------ wp_update_media */

	public function testUpdateMediaReportsAFailedSave(): void {
		ab_test_add_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		self::assertFalse( is_wp_error( AB_MCP_Tools_Media::update_media( array( 'id' => 5, 'title' => 'Caption' ) ) ) );
		$GLOBALS['ab_test_update_fails'] = true;
		self::assertSame( 'ab_mcp_update_failed', self::code( AB_MCP_Tools_Media::update_media( array( 'id' => 5, 'title' => 'Caption' ) ) ) );
	}

	public function testUpdateMediaKeepsTheRuleOfUpdatePost(): void {
		self::rights( false );
		ab_test_add_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 7 ) );
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_parent' => 5 ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( AB_MCP_Tools_Media::update_media( array( 'id' => 5, 'title' => 'Caption' ) ) ), 'a loop runs through the file: WordPress would store no parent' );
		self::assertSame( array(), $GLOBALS['ab_test_updated'] );
	}
}
