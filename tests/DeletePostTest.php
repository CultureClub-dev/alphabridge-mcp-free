<?php
/**
 * wp_delete_post promises the trash unless force is set. WordPress moves only
 * posts and pages there when asked through wp_delete_post(); any other type
 * it deletes for good, and a file together with the file on the server. The
 * tool now trashes every type itself, and asks for force where the trash is
 * no way: a file, a post already in the trash.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tools_Content;

final class DeletePostTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
	}

	private static function code( $res ): string {
		return is_wp_error( $res ) ? (string) $res->get_error_code() : '';
	}

	public function testAPostGoesToTheTrash(): void {
		ab_test_add_post( 5, array( 'post_status' => 'publish' ) );
		$res = AB_MCP_Tools_Content::delete_post( array( 'id' => 5 ) );
		self::assertSame( array( 'deleted' => true, 'permanent' => false, 'id' => 5 ), $res );
		self::assertSame( 'trash', get_post( 5 )->post_status );
		self::assertSame( array(), $GLOBALS['ab_test_deleted'], 'nothing deleted for good' );
	}

	public function testAnyOtherTypeGoesToTheTrashToo(): void {
		ab_test_add_post( 6, array( 'post_type' => 'product', 'post_status' => 'publish' ) );
		$res = AB_MCP_Tools_Content::delete_post( array( 'id' => 6 ) );
		self::assertFalse( is_wp_error( $res ) );
		self::assertSame( 'trash', get_post( 6 )->post_status, 'wp_delete_post() would have deleted a product for good' );
		self::assertSame( array(), $GLOBALS['ab_test_deleted'] );
	}

	public function testOldTrashNotesAreClearedFirst(): void {
		ab_test_add_post( 5, array( 'post_status' => 'draft' ) );
		$GLOBALS['ab_test_meta'][5] = array(
			'_wp_trash_meta_status'          => array( 'publish' ),
			'_wp_trash_meta_time'            => array( '1600000000' ),
			'_wp_trash_meta_comments_status' => array( array( 3 => '1' ) ),
		);
		AB_MCP_Tools_Content::delete_post( array( 'id' => 5 ) );
		self::assertSame( array( array( 5, '_wp_trash_meta_status' ), array( 5, '_wp_trash_meta_time' ) ), $GLOBALS['ab_test_meta_deleted'], 'by the old time the daily clean-up would delete the post for good at once' );
		self::assertSame( array( array( 3 => '1' ) ), $GLOBALS['ab_test_meta'][5]['_wp_trash_meta_comments_status'], 'the note on the comments stays' );
	}

	public function testAFileNeedsForceOrDeleteMedia(): void {
		ab_test_add_post( 7, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		self::assertSame( 'ab_mcp_file_not_trashed', self::code( AB_MCP_Tools_Content::delete_post( array( 'id' => 7 ) ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_trashed'] );
		self::assertSame( array(), $GLOBALS['ab_test_deleted'] );
		$res = AB_MCP_Tools_Content::delete_post( array( 'id' => 7, 'force' => true ) );
		self::assertSame( array( 'deleted' => true, 'permanent' => true, 'id' => 7 ), $res );
		self::assertSame( array( array( 7, true ) ), $GLOBALS['ab_test_deleted'] );
	}

	public function testAPostInTheTrashNeedsForce(): void {
		ab_test_add_post( 8, array( 'post_status' => 'trash' ) );
		self::assertSame( 'ab_mcp_already_trashed', self::code( AB_MCP_Tools_Content::delete_post( array( 'id' => 8 ) ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_deleted'] );
		self::assertFalse( is_wp_error( AB_MCP_Tools_Content::delete_post( array( 'id' => 8, 'force' => true ) ) ) );
		self::assertNull( get_post( 8 ) );
	}

	public function testARefusedTrashIsReported(): void {
		ab_test_add_post( 9, array( 'post_status' => 'publish' ) );
		$GLOBALS['ab_test_trash_refused'] = true;
		self::assertSame( 'ab_mcp_trash_failed', self::code( AB_MCP_Tools_Content::delete_post( array( 'id' => 9 ) ) ) );
		self::assertSame( 'publish', get_post( 9 )->post_status );
	}
}
