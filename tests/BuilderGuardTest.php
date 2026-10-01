<?php
/**
 * The guard in wp_update_post: a change to content that the site would not
 * show is refused before anything is written, with the way to make it;
 * where it would show but not last, it is written with a warning; where the
 * builder is not running, with a note. Everything else is as before.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builders;
use AB_MCP_Tools_Content;
use PHPUnit\Framework\TestCase;

final class BuilderGuardTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_now']          = strtotime( '2026-10-01 12:00:00 UTC' );
		$this->mayDoAnything();
	}

	/** An Elementor reader is registered (the free core ships none yet). */
	private function elementorReader(): void {
		add_filter(
			'ab_mcp_builder_adapters',
			static function ( $list ) {
				$list[] = new FakeBuilderAdapter( 'elementor' );
				return $list;
			}
		);
		AB_MCP_Builders::reset();
	}

	private function elementorPage(): object {
		return $this->page( 50, '<p>Text copy of the page</p>', array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '[]' ) );
	}

	public function testStorageBWithAReaderRefusesContentAndNamesTheWay(): void {
		$this->builders( array( 'elementor' ) );
		$this->elementorReader();
		$this->elementorPage();

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 50, 'content' => '<p>New</p>', 'title' => 'New title' ) );
		self::assertTrue( is_wp_error( $out ) );
		self::assertSame( 'ab_mcp_builder_content_copy', $out->get_error_code() );
		self::assertSame( array( 'builder' => 'elementor' ), $out->get_error_data() );
		$msg = $out->get_error_message();
		self::assertStringContainsString( 'Nothing was written', $msg );
		self::assertStringContainsString( 'post_content is only a copy', $msg );
		self::assertStringContainsString( 'wp_get_builder_layout', $msg, 'The way to read it.' );
		self::assertStringContainsString( 'Elementor editor in wp-admin', $msg, 'The way to change it here.' );
		self::assertStringContainsString( 'without content', $msg, 'The way to change the other fields.' );
		self::assertSame( array(), $GLOBALS['ab_test_updated'], 'Nothing was written, the title neither.' );
		self::assertSame( '<p>Text copy of the page</p>', get_post( 50 )->post_content );
	}

	public function testTheOtherFieldsStillChange(): void {
		$this->builders( array( 'elementor' ) );
		$this->elementorReader();
		$this->elementorPage();

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 50, 'title' => 'New title' ) );
		self::assertIsArray( $out );
		self::assertTrue( $out['updated'] );
		self::assertSame( 'New title', get_post( 50 )->post_title );
		self::assertArrayNotHasKey( 'builder_note', $out );
	}

	public function testAWriterNamedInWriteViaIsTheWayInTheMessage(): void {
		$this->builders( array( 'elementor' ) );
		$this->elementorReader();
		add_filter(
			'ab_mcp_builder_write_via',
			static function ( $tools ) {
				$tools[] = 'wp_update_builder_element';
				return $tools;
			}
		);
		$this->elementorPage();
		$guard = AB_MCP_Builders::content_update_guard( get_post( 50 ) );
		self::assertTrue( $guard['block'] );
		self::assertStringContainsString( 'Change its texts with wp_update_builder_element.', $guard['message'] );
		self::assertStringNotContainsString( 'no tool on this site', $guard['message'] );
	}

	public function testStorageBWithoutAReaderWritesAndWarns(): void {
		// Nothing gets worse than it was: without a reader for the builder the
		// free plugin cannot offer a way, so it does not take the old one away.
		$this->builders( array( 'elementor' ) );
		$this->elementorPage();

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 50, 'content' => '<p>New</p>' ) );
		self::assertIsArray( $out );
		self::assertSame( '<p>New</p>', get_post( 50 )->post_content );
		self::assertStringContainsString( 'looks built with Elementor', $out['builder_note'] );
		self::assertStringContainsString( 'may not be visible', $out['builder_note'] );
	}

	public function testStorageBOfUnknownStateWritesAndWarns(): void {
		// BeTheme has no documented active check: not refused.
		$this->page( 51, '<p>x</p>', array( 'mfn-page-items' => 'YToxOnt9' ) );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 51, 'content' => '<p>y</p>' ) );
		self::assertIsArray( $out );
		self::assertStringContainsString( 'BeTheme', $out['builder_note'] );
	}

	public function testAnInactiveBuilderLetsTheChangeThroughWithANote(): void {
		$this->builders( array(), array( 'elementor' ) );
		$this->elementorReader();
		$this->elementorPage();

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 50, 'content' => '<p>New</p>' ) );
		self::assertIsArray( $out );
		self::assertSame( '<p>New</p>', get_post( 50 )->post_content );
		self::assertStringContainsString( 'Elementor is not active', $out['builder_note'] );
	}

	public function testStorageA2WritesAndWarnsItWillBeLost(): void {
		$this->builders( array( 'seedprod' ) );
		$this->page( 52, '<div id="sp-page">Mo–Fr</div>', array( '_seedprod_page' => '1' ), array( 'post_content_filtered' => '{"document":{}}' ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 52, 'content' => '<div id="sp-page">Mo–Sa</div>' ) );
		self::assertIsArray( $out );
		self::assertSame( '<div id="sp-page">Mo–Sa</div>', get_post( 52 )->post_content );
		self::assertStringContainsString( 'restores it the next time someone saves the page in SeedProd', $out['builder_note'] );
	}

	public function testStorageAAndPlainPostsAreAsBefore(): void {
		$this->builders( array( 'kadence' ) );
		$this->page( 53, '<!-- wp:kadence/advancedheading {"uniqueID":"a1"} --><h2>Hi</h2><!-- /wp:kadence/advancedheading -->' );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 53, 'content' => 'x' ) );
		self::assertIsArray( $out );
		self::assertArrayNotHasKey( 'builder_note', $out );
		self::assertNull( AB_MCP_Builders::content_update_guard( get_post( 53 ) ) );

		$this->page( 54, '<p>plain</p>' );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 54, 'content' => 'y' ) );
		self::assertIsArray( $out );
		self::assertArrayNotHasKey( 'builder_note', $out );
	}

	public function testTheFilterIsTheSwitch(): void {
		$this->builders( array( 'elementor' ) );
		$this->elementorReader();
		$this->elementorPage();
		add_filter(
			'ab_mcp_builder_content_update_guard',
			static function () {
				return null;
			}
		);
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 50, 'content' => '<p>Deliberately</p>' ) );
		self::assertIsArray( $out, 'A site that switches the guard off writes as before.' );
		self::assertSame( '<p>Deliberately</p>', get_post( 50 )->post_content );
	}

	public function testForbiddenStaysForbiddenBeforeTheGuardSpeaks(): void {
		$this->builders( array( 'elementor' ) );
		$this->elementorReader();
		$this->elementorPage();
		$GLOBALS['ab_test_can'] = static function (): bool {
			return false;
		};
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 50, 'content' => 'x' ) );
		self::assertSame( 'ab_mcp_forbidden', $out->get_error_code(), 'No builder information for an account that may not edit.' );
	}
}
