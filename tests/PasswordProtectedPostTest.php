<?php
/**
 * A post password guards the published text from readers. `read_post` does
 * not check it — for a published post it maps to plain `read` — so the raw
 * text of a protected post goes only to someone who could edit the post.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tools_Content;

final class PasswordProtectedPostTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		ab_test_add_post( 5 );
		ab_test_add_post( 6, array( 'post_password' => 'sesame', 'post_content' => 'Members only' ) );
	}

	/**
	 * Everyone may read; editing is allowed for exactly these post ids.
	 *
	 * @param int[] $editable Posts the current user may edit.
	 */
	private function mayEdit( array $editable ): void {
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( $editable ): bool {
			if ( 'read_post' === $cap ) {
				return true;
			}
			return 'edit_post' === $cap && in_array( (int) ( $args[0] ?? 0 ), $editable, true );
		};
	}

	public function testAnUnprotectedPostIsReadableWithoutEditRights(): void {
		$this->mayEdit( array() );
		self::assertTrue( AB_MCP_Tools_Content::raw_content_allowed( get_post( 5 ) ) );
	}

	public function testAProtectedPostNeedsTheEditRight(): void {
		$this->mayEdit( array() );
		self::assertFalse( AB_MCP_Tools_Content::raw_content_allowed( get_post( 6 ) ) );

		$this->mayEdit( array( 6 ) );
		self::assertTrue( AB_MCP_Tools_Content::raw_content_allowed( get_post( 6 ) ) );
	}

	public function testGetPostRefusesAProtectedPostToAReader(): void {
		$this->mayEdit( array() );
		$out = AB_MCP_Tools_Content::get_post( array( 'id' => 6 ) );
		self::assertTrue( is_wp_error( $out ) );
		self::assertSame( 'ab_mcp_password_protected', $out->get_error_code() );
		self::assertStringNotContainsString( 'Members only', print_r( $out, true ) );
	}

	public function testDuplicateRefusesAProtectedPostToAReader(): void {
		$this->mayEdit( array() );
		$out = AB_MCP_Tools_Content::duplicate_post( array( 'id' => 6 ) );
		self::assertTrue( is_wp_error( $out ) );
		self::assertSame( 'ab_mcp_password_protected', $out->get_error_code() );
	}
	public function testARevisionFollowsItsParentsEditRight(): void {
		// A revision carries the text but never the password (core copies
		// title, content and excerpt only), and read_post on it maps to the
		// parent's read right.
		ab_test_add_post( 60, array( 'post_type' => 'revision', 'post_parent' => 6, 'post_content' => 'Members only, draft 2' ) );
		ab_test_add_post( 50, array( 'post_type' => 'revision', 'post_parent' => 5 ) );

		$this->mayEdit( array() );
		self::assertFalse( AB_MCP_Tools_Content::raw_content_allowed( get_post( 60 ) ), 'Protected parent, no edit right.' );
		self::assertFalse( AB_MCP_Tools_Content::raw_content_allowed( get_post( 50 ) ), 'Revisions are for editors, as in the REST API.' );

		$out = AB_MCP_Tools_Content::get_post( array( 'id' => 60 ) );
		self::assertTrue( is_wp_error( $out ) );
		self::assertSame( 'ab_mcp_forbidden', $out->get_error_code() );
		self::assertStringNotContainsString( 'Members only', print_r( $out, true ) );

		$this->mayEdit( array( 6, 5 ) );
		self::assertTrue( AB_MCP_Tools_Content::raw_content_allowed( get_post( 60 ) ) );
		self::assertTrue( AB_MCP_Tools_Content::raw_content_allowed( get_post( 50 ) ) );
	}

	public function testAnOrphanRevisionIsRefused(): void {
		ab_test_add_post( 70, array( 'post_type' => 'revision', 'post_parent' => 0 ) );
		$this->mayEdit( array( 0 ) );
		self::assertFalse( AB_MCP_Tools_Content::raw_content_allowed( get_post( 70 ) ) );
	}
	public function testListingRevisionsNeedsTheEditRightToo(): void {
		$this->mayEdit( array() );
		$out = AB_MCP_Tools_Content::list_revisions( array( 'id' => 5 ) );
		self::assertTrue( is_wp_error( $out ) );
		self::assertSame( 'ab_mcp_forbidden', $out->get_error_code() );
	}
	public function testRevisionListingsAreForEditorsOfOnePostAtATime(): void {
		// A count alone would tell of the text — so the query is refused
		// up front, however the type is spelt, unless it names one post the
		// caller may edit.
		$revision = ab_test_add_post( 60, array( 'post_type' => 'revision', 'post_parent' => 6, 'post_content' => 'Members only, draft 2' ) );
		$GLOBALS['ab_test_query'] = static function ( array $args ) use ( $revision ): array {
			return array( $revision );
		};

		$this->mayEdit( array() );
		foreach ( array( 'revision', 'REVISION', 'revi!sion' ) as $spelling ) {
			$out = AB_MCP_Tools_Content::list_posts( array( 'type' => $spelling, 'parent' => 6, 'search' => 'Members' ) );
			self::assertTrue( is_wp_error( $out ), $spelling );
			self::assertSame( 'ab_mcp_forbidden', $out->get_error_code(), $spelling );
		}
		$out = AB_MCP_Tools_Content::list_posts( array( 'type' => 'revision' ) );
		self::assertTrue( is_wp_error( $out ), 'No parent named.' );

		$out = \AB_MCP_Tools_Search_Bulk::search( array( 'query' => 'Members', 'type' => 'Revision' ) );
		self::assertTrue( is_wp_error( $out ), 'Revisions are not searched, for anyone.' );

		$this->mayEdit( array( 6 ) );
		$out = AB_MCP_Tools_Content::list_posts( array( 'type' => 'revision', 'parent' => 6 ) );
		self::assertCount( 1, $out['items'] );
		self::assertSame( 1, $out['pagination']['total'] );
		self::assertStringContainsString( 'Members only', $out['items'][0]['excerpt'], 'The editor of the parent sees it, excerpt included.' );
	}

	public function testAStrayRevisionInResultsIsWithheld(): void {
		// Only a third-party query filter could hand a revision back past the
		// gate; the entry must still not show. (The counts are WordPress' own.)
		$revision = ab_test_add_post( 60, array( 'post_type' => 'revision', 'post_parent' => 6, 'post_content' => 'Members only, draft 2' ) );
		$GLOBALS['ab_test_query'] = static function ( array $args ) use ( $revision ): array {
			return array( get_post( 5 ), $revision );
		};
		$this->mayEdit( array() );

		$items = AB_MCP_Tools_Content::list_posts( array( 'type' => 'post', 'search' => 'Members' ) )['items'];
		self::assertCount( 1, $items );
		self::assertSame( 5, $items[0]['id'] );

		$results = \AB_MCP_Tools_Search_Bulk::search( array( 'query' => 'Members' ) )['results'];
		self::assertCount( 1, $results );
		self::assertSame( 5, $results[0]['id'] );
	}

	public function testARevisionOfAnUnprotectedPostIsRefusedAsForbiddenNotAsProtected(): void {
		ab_test_add_post( 50, array( 'post_type' => 'revision', 'post_parent' => 5 ) );
		$this->mayEdit( array() );
		$out = AB_MCP_Tools_Content::get_post( array( 'id' => 50 ) );
		self::assertTrue( is_wp_error( $out ) );
		self::assertSame( 'ab_mcp_forbidden', $out->get_error_code() );
	}

	public function testAProtectedAttachmentKeepsItsCaptionFromReaders(): void {
		ab_test_add_post( 80, array( 'post_type' => 'attachment', 'post_password' => 'sesame', 'post_excerpt' => 'Secret caption', 'post_mime_type' => 'image/jpeg' ) );
		$this->mayEdit( array() );
		self::assertSame( '', \AB_MCP_Tools_Media::get_media( array( 'id' => 80 ) )['caption'] );
		$this->mayEdit( array( 80 ) );
		self::assertSame( 'Secret caption', \AB_MCP_Tools_Media::get_media( array( 'id' => 80 ) )['caption'] );
	}

	public function testAProtectedPostsExcerptStaysWithheldInListings(): void {
		$this->mayEdit( array() );
		$GLOBALS['ab_test_query'] = static function ( array $args ): array {
			return array( get_post( 6 ) );
		};
		$items = AB_MCP_Tools_Content::list_posts( array( 'type' => 'post' ) )['items'];
		self::assertStringNotContainsString( 'Members only', $items[0]['excerpt'] );
	}
}
