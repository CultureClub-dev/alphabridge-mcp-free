<?php
/**
 * A file with "inherit" is as visible as its parent: public without one, and
 * public the moment the parent is published. WordPress stores a file with any
 * status but private, trash or auto-draft as "inherit". wp_update_post asked
 * for the right to publish only when the status asked for would publish, so
 * an account without that right could make a private file public by asking
 * for "draft" or "pending", or make a file hidden under a draft public by
 * giving it another parent. A save that leaves a file to a parent it did not
 * follow before now needs the right to publish too — whatever the parent's
 * status is now.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tools_Content;

final class FileVisibilityTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		update_option( 'timezone_string', 'Europe/Zurich' );
		self::publishing( false );
	}

	/** Every capability the tool asks for, the right to publish only when $can. */
	private static function publishing( bool $can ): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'publish_posts' !== $cap || $can;
	}

	private static function file( int $id, string $status, int $parent ): void {
		ab_test_add_post( $id, array( 'post_type' => 'attachment', 'post_status' => $status, 'post_parent' => $parent ) );
	}

	/** @param array<string,mixed> $call */
	private static function refused( array $call ): bool {
		$GLOBALS['ab_test_updated'] = array();
		$res = AB_MCP_Tools_Content::update_post( $call );
		return is_wp_error( $res ) && 'ab_mcp_forbidden' === $res->get_error_code() && array() === $GLOBALS['ab_test_updated'];
	}

	/** @param array<string,mixed> $call */
	private static function written( array $call ): bool {
		$GLOBALS['ab_test_updated'] = array();
		$res = AB_MCP_Tools_Content::update_post( $call );
		return ! is_wp_error( $res ) && 1 === count( $GLOBALS['ab_test_updated'] );
	}

	public function testAPrivateFileWithoutAParentIsNotMadePublicThroughDraft(): void {
		self::file( 5, 'private', 0 );
		self::assertTrue( self::refused( array( 'id' => 5, 'status' => 'draft' ) ), 'WordPress would store "inherit": published without a parent' );
		self::assertTrue( self::refused( array( 'id' => 5, 'status' => 'pending' ) ) );
	}

	public function testWithTheRightToPublishItGoesThrough(): void {
		self::publishing( true );
		self::file( 5, 'private', 0 );
		self::assertTrue( self::written( array( 'id' => 5, 'status' => 'draft' ) ) );
	}

	public function testUnderADraftParentTooTheFileWouldFollowIt(): void {
		ab_test_add_post( 7, array( 'post_status' => 'draft' ) );
		self::file( 5, 'private', 7 );
		self::assertTrue( self::refused( array( 'id' => 5, 'status' => 'draft' ) ), 'whoever publishes the draft later shows the file' );
	}

	public function testUnderAPublishedParentTheFileIsPublic(): void {
		ab_test_add_post( 7, array( 'post_status' => 'publish' ) );
		self::file( 5, 'private', 7 );
		self::assertTrue( self::refused( array( 'id' => 5, 'status' => 'draft' ) ) );
	}

	public function testTheParentsStatusIsNotJudged(): void {
		ab_test_add_post( 7, array( 'post_status' => 'trash' ) );
		self::file( 5, 'private', 7 );
		self::assertTrue( self::refused( array( 'id' => 5, 'status' => 'draft' ) ), 'under a parent in the trash as under any other' );
	}

	public function testAnotherParentCanMakeAHiddenFilePublic(): void {
		ab_test_add_post( 7, array( 'post_status' => 'draft' ) );
		self::file( 5, 'inherit', 7 );
		self::assertTrue( self::refused( array( 'id' => 5, 'parent' => 0 ) ), 'away from the draft, without a parent, the file is public' );
		self::assertTrue( self::refused( array( 'id' => 5, 'parent' => null ) ), 'a parent given as null reaches WordPress as 0' );
	}

	public function testAnotherParentIsRefusedWhateverItsStatus(): void {
		ab_test_add_post( 7, array( 'post_status' => 'draft' ) );
		ab_test_add_post( 9, array( 'post_status' => 'publish' ) );
		self::file( 5, 'inherit', 9 );
		self::assertTrue( self::refused( array( 'id' => 5, 'parent' => 7 ) ), 'under a draft too: the draft may be published later' );
		self::assertTrue( self::written( array( 'id' => 5, 'parent' => 9 ) ), 'the parent it has is no other one' );
	}

	public function testASaveThatLeavesStatusAndParentAsTheyAreNeedsNothing(): void {
		ab_test_add_post( 7, array( 'post_status' => 'draft' ) );
		self::file( 5, 'inherit', 7 );
		self::assertTrue( self::written( array( 'id' => 5, 'title' => 'New caption' ) ) );
		self::assertTrue( self::written( array( 'id' => 5, 'status' => 'draft' ) ), 'stored as "inherit" again, under the same parent' );
	}

	public function testAPrivateFileTakesAnotherParentWithoutTheRight(): void {
		ab_test_add_post( 7, array( 'post_status' => 'draft' ) );
		self::file( 5, 'private', 7 );
		self::assertTrue( self::written( array( 'id' => 5, 'parent' => 0 ) ), 'a private file shows nobody more under another parent' );
	}

	public function testAFileInTheTrashGetsNoOtherParent(): void {
		ab_test_add_post( 7, array( 'post_status' => 'draft' ) );
		self::file( 5, 'trash', 7 );
		self::assertTrue( self::refused( array( 'id' => 5, 'parent' => 0 ) ), 'it comes out of the trash as "inherit", under that parent' );
		self::assertTrue( self::refused( array( 'id' => 5, 'status' => 'draft' ) ), 'nor is it taken out as "inherit"' );
		self::assertTrue( self::written( array( 'id' => 5, 'title' => 'New caption' ) ) );
	}

	public function testTheFileItselfAsParentCountsAsNone(): void {
		ab_test_add_post( 7, array( 'post_status' => 'draft' ) );
		self::file( 5, 'inherit', 7 );
		self::assertTrue( self::refused( array( 'id' => 5, 'parent' => 5 ) ), 'WordPress stores no parent, and the file would be public' );
	}

	public function testASaveWordPressReparentsNeedsTheRight(): void {
		self::file( 5, 'inherit', 7 );
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_parent' => 5 ) );
		self::assertTrue( self::refused( array( 'id' => 5, 'title' => 'New caption' ) ), 'a loop runs through the file: any save stores no parent, and the file would be public' );
	}

	public function testALoopAboveTheFileLeavesItsParent(): void {
		self::file( 5, 'inherit', 7 );
		ab_test_add_post( 7, array( 'post_status' => 'draft', 'post_parent' => 8 ) );
		ab_test_add_post( 8, array( 'post_status' => 'draft', 'post_parent' => 7 ) );
		self::assertTrue( self::written( array( 'id' => 5, 'title' => 'New caption' ) ), 'the loop does not run through the file — and the walk up ends' );
	}

	public function testForAFileTheRefusalDoesNotSendItToDraft(): void {
		self::file( 5, 'private', 0 );
		$res = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'status' => 'publish' ) );
		self::assertTrue( is_wp_error( $res ) && 'ab_mcp_forbidden' === $res->get_error_code() );
		self::assertStringNotContainsString( 'use status', $res->get_error_message(), '"draft" and "pending" would be refused as well' );
		ab_test_add_post( 6, array( 'post_status' => 'draft' ) );
		$res = AB_MCP_Tools_Content::update_post( array( 'id' => 6, 'status' => 'publish' ) );
		self::assertTrue( is_wp_error( $res ) );
		self::assertStringContainsString( 'use status "draft" or "pending"', $res->get_error_message(), 'other posts keep the advice' );
	}

	public function testOtherPostsAreJudgedAsBefore(): void {
		ab_test_add_post( 5, array( 'post_status' => 'private' ) );
		self::assertTrue( self::written( array( 'id' => 5, 'status' => 'draft', 'parent' => 0 ) ), 'a draft post shows nobody anything, whatever its parent' );
	}
}
