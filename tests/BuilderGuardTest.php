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
use AB_MCP_Tools_Builders;
use AB_MCP_Tools_Content;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/beaver-model-stub.php';

final class BuilderGuardTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_now']          = strtotime( '2026-10-01 12:00:00 UTC' );
		$this->mayDoAnything();
	}

	protected function tearDown(): void {
		\FLBuilderModel::$post_types = null;
	}

	/** A stand-in Elementor reader, so the guard is tested apart from what the real one reads. */
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
		return $this->page( 50, '<p>Text copy of the page</p>', array( '_elementor_edit_mode' => 'builder', '_elementor_data' => $this->elementorTree() ) );
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
		self::assertStringContainsString( 'would not appear on the page', $msg );
		self::assertStringNotContainsString( 'no visible effect', $msg, 'Search and excerpts may still read post_content: the claim is the page, nothing more.' );
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

	/**
	 * _elementor_data values Elementor reads as an empty page: nothing stored
	 * (the editor was only opened, which already sets _elementor_edit_mode),
	 * an empty list or object, JSON that does not decode, and JSON nested
	 * deeper than json_decode() reads [SVN elementor@4.3.3
	 * core/base/document.php:1041-1053, includes/frontend.php:1105-1110,
	 * :1176-1180, core/editor/editor.php:111-112].
	 *
	 * @return array<string,array{0:string|null}>
	 */
	public static function elementorWithoutALayout(): array {
		return array(
			'never saved'   => array( null ),
			'empty string'  => array( '' ),
			'empty list'    => array( '[]' ),
			'empty object'  => array( '{}' ),
			'broken JSON'   => array( '{not json' ),
			'cut off'       => array( '[{"id":"a1b2c3d","elType":"container"' ),
			'nested deeply' => array( str_repeat( '[', 600 ) . str_repeat( ']', 600 ) ),
		);
	}

	#[DataProvider( 'elementorWithoutALayout' )]
	public function testAnElementorPageWithoutALayoutIsWrittenAsBefore( ?string $data ): void {
		// Elementor then shows post_content: the change is visible, as it
		// was before the guard existed (plan, principle 9).
		$this->builders( array( 'elementor' ) );
		$meta = array( '_elementor_edit_mode' => 'builder' );
		if ( null !== $data ) {
			$meta['_elementor_data'] = $data;
		}
		$this->page( 55, '<!-- wp:paragraph --><p>Shown text</p><!-- /wp:paragraph -->', $meta );
		$post = get_post( 55 );

		self::assertSame( 'A', AB_MCP_Builders::effective_storage( $post ) );
		self::assertNull( AB_MCP_Builders::content_update_guard( $post ) );
		self::assertSame( array( 'wp_update_post' ), AB_MCP_Builders::write_via( $post ) );
		self::assertSame( 'blocks', AB_MCP_Builders::for_post( $post )->id(), 'What the site shows is read.' );
		$built = AB_MCP_Builders::built_with( $post );
		self::assertSame( 'A', $built['storage'] );
		self::assertStringContainsString( 'shows no layout of its own', $built['note'] );

		$layout = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 55 ) );
		self::assertSame( 'elementor', $layout['builder'] );
		self::assertSame( 'A', $layout['storage'] );
		self::assertSame( 'Shown text', $layout['elements'][0]['fields']['text']['value'] );
		self::assertStringNotContainsString( 'only a copy', $this->flat( $layout ) );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 55, 'content' => '<p>New</p>' ) );
		self::assertIsArray( $out );
		self::assertSame( '<p>New</p>', get_post( 55 )->post_content );
		self::assertArrayNotHasKey( 'builder_note', $out );
	}

	public function testSiteOriginWithoutARowShowsPostContent(): void {
		// Its renderer returns nothing without grids, and the site shows
		// post_content [SVN siteorigin-panels@2.36.1 inc/renderer.php:711-713].
		$this->builders( array( 'siteorigin' ) );
		$rows = array(
			'grids'      => array( array( 'cells' => 1 ) ),
			'grid_cells' => array( array( 'grid' => 0, 'weight' => 1 ) ),
			'widgets'    => array(),
		);
		$this->page( 56, '<p>copy</p>', array( 'panels_data' => $rows ) );
		self::assertTrue( AB_MCP_Builders::content_update_guard( get_post( 56 ) )['block'], 'A layout with a row is what the site shows.' );

		foreach ( array( array( 'widgets' => array(), 'grids' => array() ), 'a:1:{broken' ) as $i => $data ) {
			$this->page( 57 + $i, '<p>copy</p>', array( 'panels_data' => $data ) );
			$post = get_post( 57 + $i );
			self::assertSame( array( 'siteorigin' ), array_column( AB_MCP_Builders::detect_all( $post ), 'id' ) );
			self::assertSame( 'A', AB_MCP_Builders::effective_storage( $post ) );
			self::assertNull( AB_MCP_Builders::content_update_guard( $post ) );
		}
	}

	public function testBeaverOnAPostTypeItIsNotEnabledForShowsPostContent(): void {
		// FLBuilderModel::is_builder_enabled() checks the post type
		// [SVN beaver-builder-lite-version@2.11.0.6 classes/class-fl-builder-model.php:756-761].
		$this->builders( array( 'beaver' ) );
		$this->page( 59, '<p>copy</p>', array( '_fl_builder_enabled' => '1', '_fl_builder_data' => array() ), array( 'post_type' => 'post' ) );
		$post = get_post( 59 );
		self::assertTrue( AB_MCP_Builders::content_update_guard( $post )['block'], 'Without Beaver\'s model loaded, its signature stands.' );

		\FLBuilderModel::$post_types = array( 'page', 'fl-builder-template' );
		self::assertSame( 'A', AB_MCP_Builders::effective_storage( $post ) );
		self::assertNull( AB_MCP_Builders::content_update_guard( $post ) );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 59, 'content' => '<p>New</p>' ) );
		self::assertIsArray( $out );
		self::assertSame( '<p>New</p>', get_post( 59 )->post_content );

		\FLBuilderModel::$post_types = array( 'page', 'post', 'fl-builder-template' );
		self::assertTrue( AB_MCP_Builders::content_update_guard( get_post( 59 ) )['block'], 'Enabled for posts: Beaver renders its layout, even an empty one.' );
	}

	public function testEnfoldWithoutShortcodesInItsMetaIsNotRefused(): void {
		// What Enfold shows when its layout meta holds no shortcodes is not
		// documented (quellen/enfold.md b)): warn, do not refuse.
		$this->builders( array( 'enfold' ) );
		$this->page( 60, "[av_textblock av_uid='av-1']Visible in post_content[/av_textblock]", array( '_aviaLayoutBuilder_active' => 'active', '_aviaLayoutBuilderCleanData' => '' ) );
		$post  = get_post( 60 );
		$guard = AB_MCP_Builders::content_update_guard( $post );
		self::assertFalse( $guard['block'] );
		self::assertStringContainsString( 'may not be visible', $guard['message'] );
		self::assertSame( 'B', AB_MCP_Builders::effective_storage( $post ) );
		self::assertSame( array(), AB_MCP_Builders::write_via( $post ), 'Not known to show: no tool is named as the way.' );

		$this->page( 61, "[av_textblock av_uid='av-1']Old copy[/av_textblock]", array( '_aviaLayoutBuilder_active' => 'active', '_aviaLayoutBuilderCleanData' => "[av_textblock av_uid='av-1']Shown[/av_textblock]" ) );
		self::assertTrue( AB_MCP_Builders::content_update_guard( get_post( 61 ) )['block'], 'The layout in the meta is what Enfold shows.' );
	}

	public function testStorageBWithoutAReaderWritesAndWarns(): void {
		// Nothing gets worse than it was: without a reader for the builder the
		// free plugin cannot offer a way, so it does not take the old one away.
		// The free core reads Elementor, so its reader is taken out here.
		$this->builders( array( 'elementor' ) );
		add_filter(
			'ab_mcp_builder_adapters',
			static function ( $list ) {
				return array_values(
					array_filter(
						$list,
						static function ( $adapter ): bool {
							return 'elementor' !== $adapter->id();
						}
					)
				);
			}
		);
		AB_MCP_Builders::reset();
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
