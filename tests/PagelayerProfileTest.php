<?php
/**
 * The Pagelayer profile on the pages of the measurement (Pagelayer 2.2.2,
 * WordPress 7.1.2): as the abilities wrote it, after a save in the editor,
 * after AlphaBridge replaced a text, and the page with code elements. Also
 * where the page is stored: post_content is the source, the tree in
 * pagelayer-data a derived copy.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use AB_MCP_Builders;
use AB_MCP_Tools_Builders;
use PHPUnit\Framework\TestCase;

final class PagelayerProfileTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
	}

	/** Elements by id. */
	private function byId( string $content ): array {
		return array_column( AB_MCP_Block_Reader::outline( $content, array() ), null, 'id' );
	}

	public function testThePageTheAbilitiesWrote(): void {
		$e = AB_MCP_Block_Reader::outline( $this->fixture( 'ability-page', 'pagelayer' )['content'], array() );
		self::assertSame( array( '1up5665', 'dru5380', 'xd36828', 'c924782', 'tbb2714', '31j3269' ), array_column( $e, 'id' ), 'pagelayer-id is the element id.' );
		self::assertSame( array( 'pagelayer/pl_row', 'pagelayer/pl_col', 'pagelayer/pl_heading', 'pagelayer/pl_text', 'pagelayer/pl_btn', 'pagelayer/pl_image' ), array_column( $e, 'type' ) );
		self::assertSame( array( 'text' => array( 'kind' => 'heading', 'value' => 'Willkommen bei Alpine Bikes' ) ), $e[2]['fields'], 'The <h1> of the content, as text.' );
		self::assertSame( array( 'text' => array( 'kind' => 'html', 'value' => 'Öffnungszeiten: Mo–Fr 9–18 Uhr' ) ), $e[3]['fields'] );
		self::assertSame(
			array(
				'text' => array(
					'kind'  => 'text',
					'value' => 'Jetzt reservieren',
				),
				'url'  => array(
					'kind'  => 'url',
					'value' => 'https://example.com/reservieren',
				),
			),
			$e[4]['fields'],
			'Text and link only in the comment.'
		);
		self::assertSame(
			array(
				'image' => array(
					'kind'  => 'image',
					'value' => 4,
				),
				'link'  => array(
					'kind'  => 'url',
					'value' => 'https://example.com/reservieren',
				),
			),
			$e[5]['fields'],
			'The attachment id "4" as a number.'
		);
		self::assertSame( 'dru5380', $e[2]['parent'] );
		self::assertSame( 2, $e[2]['depth'] );
		foreach ( $e as $element ) {
			self::assertArrayNotHasKey( 'note', $element, $element['id'] );
		}
	}

	public function testAfterAnEditorSaveTheTextCopiesAgree(): void {
		// Measured: after «Update» in the editor the heading and the text stand
		// twice — as content and, with escaped angle brackets, in "text" — the
		// button is self-closing, and a pl_post_props block leads the page.
		$content = $this->fixture( 'after-editor', 'pagelayer' )['content'];
		self::assertStringContainsString( '"text":"\\u003ch1\\u003eWillkommen am Bahnhof\\u003c/h1\\u003e"', $content );
		$by = $this->byId( $content );
		self::assertSame( 'Willkommen am Bahnhof', $by['xd36828']['fields']['text']['value'] );
		self::assertSame( 'Jetzt buchen', $by['tbb2714']['fields']['text']['value'] );
		self::assertSame( 'pagelayer/pl_post_props', $by['rnu5988']['type'] );
		self::assertArrayNotHasKey( 'fields', $by['rnu5988'], 'The page properties are no content of the page.' );
		self::assertStringNotContainsString( 'Pagelayer Messseite', $this->flat( $by ) );
		foreach ( $by as $element ) {
			self::assertArrayNotHasKey( 'note', $element, $element['id'] . ': content and the copy in "text" agree.' );
		}

		// Measured: AlphaBridge's replacement hit both, so they still agree.
		$by = $this->byId( $this->fixture( 'after-alphabridge', 'pagelayer' )['content'] );
		self::assertSame( 'Öffnungszeiten: Mo–Sa 9–17 Uhr', $by['c924782']['fields']['text']['value'] );
		self::assertArrayNotHasKey( 'note', $by['c924782'] );
	}

	public function testAStaleTextCopyIsNotedAndTheContentWins(): void {
		// Constructed from the measured editor page: only the content changed,
		// as a plain post_content edit of the visible <p> would do. The content
		// is what Pagelayer renders (shortcode_functions.php:144-147).
		$content = str_replace( "\n<p>Öffnungszeiten: Mo–Fr 9–18 Uhr</p>\n<!-- /wp:pagelayer/pl_text", "\n<p>Öffnungszeiten: Mo–Sa 9–17 Uhr</p>\n<!-- /wp:pagelayer/pl_text", $this->fixture( 'after-editor', 'pagelayer' )['content'] );
		$text    = $this->byId( $content )['c924782'];
		self::assertSame( 'Öffnungszeiten: Mo–Sa 9–17 Uhr', $text['fields']['text']['value'] );
		self::assertSame( 'text: the copy in attribute text differs', $text['note'] );

		// The abilities' page has no copy in "text", so there is nothing to compare.
		self::assertArrayNotHasKey( 'note', $this->byId( $this->fixture( 'post-content-changed', 'pagelayer' )['content'] )['c924782'] );
	}

	public function testCodeElementsAreLockedAndElementCssIsNeverRead(): void {
		// Measured (README g)): the raw HTML of pl_embed reached the page with
		// its script, the shortcode in pl_shortcodes ran, ele_css landed in
		// <style>.
		$e = AB_MCP_Block_Reader::outline( $this->fixture( 'code-page', 'pagelayer' )['content'], array() );
		$by = array_column( $e, null, 'id' );
		self::assertSame( array( 'mzr001', 'mzc001', 'mze001', 'mzs001', 'mzh001' ), array_keys( $by ) );
		self::assertSame( array( 'id' => 'mze001', 'type' => 'pagelayer/pl_embed', 'parent' => 'mzc001', 'depth' => 2, 'locked' => true, 'reason' => 'html block' ), $by['mze001'] );
		self::assertSame( array( 'id' => 'mzs001', 'type' => 'pagelayer/pl_shortcodes', 'parent' => 'mzc001', 'depth' => 2, 'locked' => true, 'reason' => 'shortcode' ), $by['mzs001'] );
		self::assertSame( array( 'text' => array( 'kind' => 'heading', 'value' => 'Code-Test' ) ), $by['mzh001']['fields'], 'The heading with element CSS stays readable; its CSS does not.' );
		$all = $this->flat( $e );
		foreach ( array( 'Rohes HTML', 'mzRoh', 'script', 'caption', 'Bildunterschrift', 'rgb(1, 2, 3)', '{{element}}', 'ele_css' ) as $never ) {
			self::assertStringNotContainsString( $never, $all, $never );
		}
		self::assertSame( array( 'mzr001', 'mzc001', 'mzh001' ), array_column( AB_MCP_Block_Reader::outline( $this->fixture( 'code-page', 'pagelayer' )['content'], array( 'include_locked' => false ) ), 'id' ) );

		// pl_missing (shortcodes.php:8244: "saved exactly as it is"); constructed.
		$e = AB_MCP_Block_Reader::outline( '<!-- wp:pagelayer/pl_missing {"pagelayer-id":"mis001"} --><div onclick="x()">Old widget</div><!-- /wp:pagelayer/pl_missing -->', array() );
		self::assertSame( 'unknown element type', $e[0]['reason'] );
		self::assertStringNotContainsString( 'Old widget', $this->flat( $e ) );
	}

	public function testPostContentIsTheSourceAndPagelayerDataADerivedCopy(): void {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
		$this->builders( array( 'pagelayer' ) );
		// The editor keeps a time stamp in pagelayer-data, the abilities the
		// tree (README b)); the value here is a stand-in, not measured. The
		// header code is page code and is never read.
		$post = $this->page(
			321,
			$this->fixture( 'after-editor', 'pagelayer' )['content'],
			array(
				'pagelayer-data'        => 'a:1:{i:0;a:2:{s:3:"tag";s:6:"pl_row";s:7:"content";a:0:{}}}',
				'pagelayer_header_code' => '<script>window.mzKopf=1;</script>',
			)
		);
		self::assertSame(
			array(
				array(
					'loc'   => 'post_content',
					'shown' => true,
					'role'  => 'source',
				),
				array(
					'loc'   => 'meta:pagelayer-data',
					'shown' => false,
					'role'  => 'copy',
				),
			),
			AB_MCP_Builders::copies( $post )
		);
		$out = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 321 ) );
		self::assertSame( 'pagelayer', $out['builder'] );
		self::assertSame( 'A', $out['storage'] );
		self::assertTrue( $out['verified'], 'Every element of the page was read with a measured profile.' );
		self::assertSame( 7, $out['element_count'] );
		self::assertSame( array( 'wp_update_post' ), $out['write_via'] );
		self::assertStringNotContainsString( 'mzKopf', $this->flat( $out ) );
		self::assertStringNotContainsString( 'pagelayer_header_code', $this->flat( $out ) );

		// The hash covers the copy as well: a change to the abilities' tree
		// changes it, as Pro's conflict check needs.
		$before = AB_MCP_Builders::layout_hash( $post );
		$GLOBALS['ab_test_meta'][321]['pagelayer-data'] = array( 'a:0:{}' );
		self::assertNotSame( $before, AB_MCP_Builders::layout_hash( get_post( 321 ) ) );
	}
}
