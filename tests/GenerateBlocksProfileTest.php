<?php
/**
 * The GenerateBlocks profile on the blocks of the measurement (GenerateBlocks
 * 2.4.1, 2.x and 1.x blocks, WordPress 7.1.2): where text, link and image
 * are, the copies in htmlAttributes, the element ids, special characters, and
 * what is never read.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use PHPUnit\Framework\TestCase;

final class GenerateBlocksProfileTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
	}

	/** The single element of a one-block fixture. */
	private function one( string $name ): array {
		$e = AB_MCP_Block_Reader::outline( $this->fixture( $name, 'generateblocks' )['content'], array() );
		self::assertCount( 1, $e, $name );
		return $e[0];
	}

	public function testTwoXHeadingTextAndButtonAreOneBlockReadFromTheMarkup(): void {
		$heading = $this->one( 'gb2-heading' );
		self::assertSame(
			array(
				'id'     => '2ddee60a',
				'type'   => 'generateblocks/text',
				'parent' => null,
				'depth'  => 0,
				'fields' => array(
					'text' => array(
						'kind'  => 'html',
						'value' => 'Take some action',
					),
				),
			),
			$heading,
			'Measured: the text only in the markup; uniqueId is the element id; no note, the profile was measured.'
		);
		self::assertSame( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.', $this->one( 'gb2-text' )['fields']['text']['value'] );

		$button = $this->one( 'gb2-button' );
		self::assertSame( '4e08132d', $button['id'] );
		self::assertSame( array( 'kind' => 'html', 'value' => 'Button' ), $button['fields']['text'] );
		self::assertSame( array( 'kind' => 'url', 'value' => '#' ), $button['fields']['url'] );
		self::assertArrayNotHasKey( 'note', $button, 'Markup and htmlAttributes.href agree.' );
	}

	public function testALinkInsideTheTextIsNoButtonLink(): void {
		// Constructed (not measured): a paragraph of the measured form with a
		// link in its rich text. The button link is the element itself.
		$content = str_replace( '>Lorem ipsum dolor sit amet, consectetur adipiscing elit.<', '>Read <a href="https://example.org/more">more</a><', $this->fixture( 'gb2-text', 'generateblocks' )['content'] );
		$e       = AB_MCP_Block_Reader::outline( $content, array() );
		self::assertSame( 'Read <a href="https://example.org/more">more</a>', $e[0]['fields']['text']['value'] );
		self::assertArrayNotHasKey( 'url', $e[0]['fields'] );
	}

	public function testTwoXMediaHasImageIdAndAddress(): void {
		$media = $this->one( 'gb2-media' );
		self::assertSame( '48cac4fb', $media['id'] );
		self::assertSame( 4934, $media['fields']['image']['value'], 'mediaId, only in the comment.' );
		self::assertSame( 'https://patterns.generatepress.com/wp-content/uploads/2024/10/placeholder800x-768x768.png', $media['fields']['src']['value'] );
		self::assertArrayNotHasKey( 'alt', $media['fields'], 'The measured image has no alt.' );
		self::assertArrayNotHasKey( 'note', $media );
	}

	public function testAStaleHtmlAttributesCopyIsNotedAndTheMarkupWins(): void {
		// Measured variants: H = only the markup changed (visible), A = only
		// htmlAttributes changed (invisible). The outline shows what the page
		// shows — the markup — and notes the copy that differs.
		$h = $this->one( 'gb2-button-link-markup-only' );
		self::assertSame( 'https://example.org/neu-H2', $h['fields']['url']['value'] );
		self::assertSame( 'url: the copy in attribute htmlAttributes.href differs', $h['note'] );

		$a = $this->one( 'gb2-button-link-attribute-only' );
		self::assertSame( '#', $a['fields']['url']['value'], 'The invisible change is not what the outline reports.' );
		self::assertSame( 'url: the copy in attribute htmlAttributes.href differs', $a['note'] );

		$h = $this->one( 'gb2-media-src-markup-only' );
		self::assertSame( 'https://example.org/neu-H3.jpg', $h['fields']['src']['value'] );
		self::assertSame( 'src: the copy in attribute htmlAttributes.src differs', $h['note'] );

		$a = $this->one( 'gb2-media-src-attribute-only' );
		self::assertSame( 'https://patterns.generatepress.com/wp-content/uploads/2024/10/placeholder800x-768x768.png', $a['fields']['src']['value'] );
		self::assertSame( 'src: the copy in attribute htmlAttributes.src differs', $a['note'] );
	}

	public function testSpecialCharactersStayHtmlInTheTextAndTheAttributeMapIsNeverRead(): void {
		// Measured: htmlAttributes.title holds «A \u0026 B \u0022Zitat\u0022 \u003cx\u003e – Grüsse ü»
		// (serialize_block_attributes), the markup «A &amp; B "Zitat" &lt;x> …».
		// The text field is simple HTML: the markup as the page has it,
		// entities included.
		$e = $this->one( 'gb2-sonderzeichen' );
		self::assertSame( 'A &amp; B "Zitat" &lt;x> – Grüsse ü', $e['fields']['text']['value'] );
		self::assertSame( array( 'text', 'url' ), array_keys( $e['fields'] ), 'The title in htmlAttributes is no field.' );
		self::assertStringNotContainsString( 'u0022', $this->flat( $e ), 'Nothing of the attribute map reaches the outline.' );
	}

	public function testOneXHeadlineButtonAndImage(): void {
		$h = $this->one( 'gb1-headline' );
		self::assertSame( '0f1bafd0', $h['id'] );
		self::assertSame( 'generateblocks/headline', $h['type'] );
		self::assertSame( array( 'kind' => 'html', 'value' => 'Take some action' ), $h['fields']['text'] );
		self::assertSame( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.', $this->one( 'gb1-headline-div' )['fields']['text']['value'], 'The same block as text (element div).' );

		$b = $this->one( 'gb1-button' );
		self::assertSame( '18ce89a0', $b['id'] );
		self::assertSame( array( 'kind' => 'text', 'value' => 'Read more' ), $b['fields']['text'] );
		self::assertSame( array( 'kind' => 'url', 'value' => '#' ), $b['fields']['url'] );

		$i = $this->one( 'gb1-image' );
		self::assertSame( 4346, $i['fields']['image']['value'] );
		self::assertSame( 'https://patterns.generateblocks.com/wp-content/uploads/2023/08/placeholder800x-300x300.png', $i['fields']['src']['value'] );
		self::assertSame( array( 'kind' => 'text', 'value' => '' ), $i['fields']['alt'], 'An empty alt is reported, so it can be filled.' );

		self::assertSame( 'A &amp; B "Zitat" &lt;x> – Grüsse ü', $this->one( 'gb1-sonderzeichen' )['fields']['text']['value'] );
	}

	public function testOneXHeadlineAndButtonWithAnIconKeepTheirText(): void {
		// Constructed (not measured): the 1.x form with an icon, where the text
		// sits in its own span next to the SVG (blocks.js:15, :33 selectors).
		$e = AB_MCP_Block_Reader::outline(
			'<!-- wp:generateblocks/headline {"uniqueId":"aa11bb22","element":"h2","hasIcon":true} --><h2 class="gb-headline gb-headline-aa11bb22"><span class="gb-icon"><svg viewBox="0 0 1 1"><path d="M0 0"></path></svg></span><span class="gb-headline-text">Icon heading</span></h2><!-- /wp:generateblocks/headline -->'
			. '<!-- wp:generateblocks/button {"uniqueId":"cc33dd44","hasUrl":true,"hasIcon":true} --><a class="gb-button gb-button-cc33dd44" href="https://example.org/go"><span class="gb-icon"><svg viewBox="0 0 1 1"></svg></span><span class="gb-button-text">Go</span></a><!-- /wp:generateblocks/button -->',
			array()
		);
		self::assertSame( 'Icon heading', $e[0]['fields']['text']['value'] );
		self::assertSame( 'Go', $e[1]['fields']['text']['value'] );
		self::assertSame( 'https://example.org/go', $e[1]['fields']['url']['value'] );
		self::assertStringNotContainsString( 'svg', $this->flat( $e ) );
	}

	public function testDynamicDataLocksAOneXBlock(): void {
		// Measured (README GenerateBlocks 1.x c)): with useDynamicData the saved
		// markup is a placeholder — GenerateBlocks put the permalink where the
		// markup says href="#". The measurement removed useDynamicData from the
		// button for that reason; here it is set again (constructed).
		$measured = $this->fixture( 'gb1-button', 'generateblocks' )['content'];
		$dynamic  = str_replace( '{"uniqueId":"18ce89a0",', '{"uniqueId":"18ce89a0","useDynamicData":true,', $measured );
		self::assertNotSame( $measured, $dynamic );
		$e = AB_MCP_Block_Reader::outline( $dynamic, array() );
		self::assertSame(
			array(
				'id'     => '18ce89a0',
				'type'   => 'generateblocks/button',
				'parent' => null,
				'depth'  => 0,
				'locked' => true,
				'reason' => 'dynamic value',
			),
			$e[0]
		);
		self::assertSame( array(), AB_MCP_Block_Reader::outline( $dynamic, array( 'include_locked' => false ) ) );

		// Off is off: false, '' and 0 leave the block readable.
		foreach ( array( 'false', '""', '0' ) as $off ) {
			$e = AB_MCP_Block_Reader::outline( str_replace( '{"uniqueId":"18ce89a0",', '{"uniqueId":"18ce89a0","useDynamicData":' . $off . ',', $measured ), array() );
			self::assertSame( 'Read more', $e[0]['fields']['text']['value'], $off );
		}

		foreach ( array( 'gb1-headline', 'gb1-image' ) as $name ) {
			$content = preg_replace( '~\{"uniqueId":"([0-9a-f]+)",~', '{"uniqueId":"$1","useDynamicData":true,', $this->fixture( $name, 'generateblocks' )['content'], 1 );
			self::assertSame( 'dynamic value', AB_MCP_Block_Reader::outline( (string) $content, array() )[0]['reason'], $name );
		}
	}

	public function testShapeIsLockedWithoutItsSvg(): void {
		// Constructed (not measured): the shape block in the form the registry
		// describes (attribute html from .gb-shape, inventar.json).
		$e = AB_MCP_Block_Reader::outline( '<!-- wp:generateblocks/shape {"uniqueId":"ee55ff66"} --><span class="gb-shape gb-shape-ee55ff66"><svg viewBox="0 0 10 10"><title>Wave</title><path d="M0 0h10"></path></svg></span><!-- /wp:generateblocks/shape -->', array() );
		self::assertSame( 'ee55ff66', $e[0]['id'] );
		self::assertTrue( $e[0]['locked'] );
		self::assertSame( 'svg markup', $e[0]['reason'] );
		self::assertArrayNotHasKey( 'fields', $e[0] );
		self::assertStringNotContainsString( 'Wave', $this->flat( $e ) );
	}

	public function testCssAndStylesAreNeverAField(): void {
		$all = '';
		foreach ( array( 'gb2-heading', 'gb2-text', 'gb2-button', 'gb2-media', 'gb2-sonderzeichen' ) as $name ) {
			$all .= $this->flat( $this->one( $name ) );
		}
		foreach ( array( '.gb-text-', 'margin', 'background', ':hover', '&:is', 'placeholder800x"', '"title"' ) as $never ) {
			self::assertStringNotContainsString( $never, $all, $never );
		}
	}
}
