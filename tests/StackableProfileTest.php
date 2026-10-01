<?php
/**
 * The Stackable profile on the samples of the measurement (Stackable 3.20.2,
 * WordPress 7.1.2): heading, text and button in the markup, the image with
 * its copy in imageUrl, and the CSS Stackable keeps inside the saved markup.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use PHPUnit\Framework\TestCase;

final class StackableProfileTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
	}

	/** The single element of a one-block fixture. */
	private function one( string $name ): array {
		$e = AB_MCP_Block_Reader::outline( $this->fixture( $name, 'stackable' )['content'], array() );
		self::assertCount( 1, $e, $name );
		return $e[0];
	}

	public function testThePreviewPage(): void {
		$e = AB_MCP_Block_Reader::outline( $this->fixture( 'preview-page', 'stackable' )['content'], array() );
		self::assertSame( array( '86365db', 'a7d0baf', '8f3cb19' ), array_column( $e, 'id' ), 'uniqueId is the element id.' );
		self::assertSame( array( 'stackable/text', 'stackable/button-group', 'stackable/button' ), array_column( $e, 'type' ) );
		self::assertStringStartsWith( 'So you wanna see what Stackable is all about?', $e[0]['fields']['text']['value'] );
		self::assertSame( 'html', $e[0]['fields']['text']['kind'] );
		self::assertArrayNotHasKey( 'fields', $e[1] );
		self::assertSame( 'a7d0baf', $e[2]['parent'] );
		self::assertSame(
			array(
				'text' => array(
					'kind'  => 'text',
					'value' => 'ewqdwq',
				),
				'url'  => array(
					'kind'  => 'url',
					'value' => '',
				),
			),
			$e[2]['fields'],
			'The vendor\'s button: an empty href is reported, so it can be filled.'
		);
		foreach ( $e as $element ) {
			self::assertArrayNotHasKey( 'note', $element, $element['id'] );
		}
	}

	public function testHeadingAndImage(): void {
		self::assertSame( array( 'kind' => 'heading', 'value' => 'heading_placeholder' ), $this->one( 'heading' )['fields']['text'] );
		self::assertSame( 'ab2c9eb', $this->one( 'heading' )['id'] );

		$img = $this->one( 'image' );
		self::assertSame( 5695, $img['fields']['image']['value'], 'imageId.' );
		self::assertSame( 'https://stackable-files.pages.dev/library-v4/images/stk-design-library-image-1.jpeg', $img['fields']['src']['value'] );
		self::assertArrayNotHasKey( 'alt', $img['fields'], 'The measured image has no alt.' );
		self::assertArrayNotHasKey( 'note', $img, 'imageUrl agrees with the markup.' );
	}

	public function testAnIdThatLooksLikeAPathFallsBackToThePath(): void {
		// The measured image's uniqueId is "b084424": "b" and digits, the form
		// of a path id. The reader never hands out such an id as the
		// builder's, so a client cannot mix up the two; the path stands in.
		$img = $this->one( 'image' );
		self::assertSame( 'b0', $img['id'] );
		$e = AB_MCP_Block_Reader::outline( $this->fixture( 'preview-page', 'stackable' )['content'] . "\n\n" . $this->fixture( 'image', 'stackable' )['content'], array() );
		self::assertSame( 'b4', $e[3]['id'], 'Its position on the page (white space between blocks counts).' );
	}

	public function testAStaleImageUrlIsNotedAndTheMarkupWins(): void {
		$h = $this->one( 'image-src-markup-only' );
		self::assertSame( 'https://example.org/neu-H3.jpg', $h['fields']['src']['value'] );
		self::assertSame( 'src: the copy in attribute imageUrl differs', $h['note'] );

		$a = $this->one( 'image-src-attribute-only' );
		self::assertSame( 'https://stackable-files.pages.dev/library-v4/images/stk-design-library-image-1.jpeg', $a['fields']['src']['value'], 'Measured: only imageUrl changed is invisible.' );
		self::assertSame( 'src: the copy in attribute imageUrl differs', $a['note'] );
	}

	public function testSpecialCharactersAreReadAsVisitorsSeeThem(): void {
		self::assertSame( 'A & B "Zitat" <x> – Grüsse ü', $this->one( 'heading-sonderzeichen' )['fields']['text']['value'] );
	}

	public function testTheCssInsideTheMarkupAndCustomCssAreNeverRead(): void {
		// Measured: the image keeps its generated CSS as <style> in the saved
		// markup.
		self::assertStringContainsString( '<style>', $this->fixture( 'image', 'stackable' )['content'] );
		self::assertStringNotContainsString( 'aspect-ratio', $this->flat( $this->one( 'image' ) ) );

		// Constructed (not measured): the measured text block with customCSS in
		// the comment and as <style class="stk-custom-css"> in the markup, the
		// two places stk.js:2 keeps it (README h)).
		$content = str_replace(
			array( '{"uniqueId":"86365db"}', 'data-block-id="86365db">' ),
			array( '{"uniqueId":"86365db","customCSS":".stk-86365db { color: rgb(4, 5, 6); }"}', 'data-block-id="86365db"><style class="stk-custom-css">.stk-86365db { color: rgb(4, 5, 6); }</style>' ),
			$this->fixture( 'text', 'stackable' )['content']
		);
		$e = AB_MCP_Block_Reader::outline( $content, array() );
		self::assertStringStartsWith( 'So you wanna see', $e[0]['fields']['text']['value'] );
		self::assertStringNotContainsString( 'rgb(4, 5, 6)', $this->flat( $e ) );
	}
}
