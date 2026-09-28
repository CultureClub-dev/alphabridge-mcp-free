<?php
/**
 * WordPress saves a post's page template along on every save. Where that
 * template is gone — a theme switched since — wp_update_post() with
 * $wp_error gives up after the row is written and before the hooks that
 * schedule a post and clear caches. The tool now saves without $wp_error:
 * WordPress falls back to the default template and finishes the save.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Tools_Content;

final class PageTemplateGoneTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		update_option( 'timezone_string', 'Europe/Zurich' );
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		ab_test_add_post( 5, array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About' ) );
	}

	public function testAPageWhoseTemplateIsGoneIsSavedLikeAnyOther(): void {
		$GLOBALS['ab_test_update_errors_after_write'] = true;
		$res = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'title' => 'About us' ) );
		self::assertFalse( is_wp_error( $res ), 'no «Invalid page template» for a change that is saved' );
		self::assertTrue( $res['updated'] );
		self::assertSame( 'About us', get_post( 5 )->post_title );
		self::assertSame( array( false ), $GLOBALS['ab_test_update_wp_error'], 'saved without $wp_error' );
		self::assertSame( array( 5 ), $GLOBALS['ab_test_template_reset'], 'WordPress falls back to the default template, as the classic editor does' );
	}

	public function testASaveThatFailsIsStillReported(): void {
		$GLOBALS['ab_test_update_fails'] = true;
		$res = AB_MCP_Tools_Content::update_post( array( 'id' => 5, 'title' => 'About us' ) );
		self::assertTrue( is_wp_error( $res ) );
		self::assertSame( 'ab_mcp_update_failed', $res->get_error_code() );
		self::assertSame( 'About', get_post( 5 )->post_title );
	}
}
