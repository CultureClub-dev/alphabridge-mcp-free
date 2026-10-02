<?php
/**
 * The block reader on real markup: the core blocks measured on WordPress
 * 7.1.2 (bloecke/ of the measurement) and core patterns of WordPress 7.0.2
 * for the blocks the measurement did not cover.
 *
 * What it must never do is hand out code: no CSS, no script, no attributes,
 * classes or styles in any field, and nothing of a locked element.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use AB_MCP_Builders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockReaderTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
	}

	/** @return array<int,array> */
	private function read( string $content, array $o = array() ): array {
		return AB_MCP_Block_Reader::outline( $content, $o );
	}

	/** Elements by id. */
	private function byId( array $elements ): array {
		$out = array();
		foreach ( $elements as $e ) {
			$out[ $e['id'] ] = $e;
		}
		return $out;
	}

	public function testTheCopiedParserRoundTripsTheFixturesByteForByte(): void {
		// The measurement found serialize_blocks( parse_blocks( x ) ) === x for
		// every sample; the fixed copy in tests/support must do the same, or
		// it is not the parser WordPress runs. Not media-text: that pattern is
		// written by hand with a raw "--" in an attribute, which a save through
		// WordPress would have stored as \u002d\u002d.
		foreach ( array( 'heading', 'paragraph', 'button', 'image', 'sonderzeichen', 'cover', 'quote', 'list' ) as $name ) {
			$content = $this->fixture( $name )['content'];
			self::assertSame( $content, serialize_blocks( parse_blocks( $content ) ), $name );
		}
	}

	public function testMeasuredHeading(): void {
		$e = $this->read( $this->fixture( 'heading' )['content'] );
		self::assertSame(
			array(
				array(
					'id'     => 'b0',
					'type'   => 'core/heading',
					'parent' => null,
					'depth'  => 0,
					'fields' => array(
						'text' => array(
							'kind'  => 'heading',
							'value' => 'New arrivals',
						),
					),
				),
			),
			$e
		);
	}

	public function testMeasuredParagraphIsSimpleHtml(): void {
		$e = $this->read( $this->fixture( 'paragraph' )['content'] );
		self::assertSame( 'html', $e[0]['fields']['text']['kind'] );
		self::assertSame( 'Like flowers that bloom in unexpected places, every story unfolds with beauty and resilience, revealing hidden wonders.', $e[0]['fields']['text']['value'] );
	}

	public function testMeasuredButtonHasTextAndLink(): void {
		$e = $this->read( $this->fixture( 'button' )['content'] );
		self::assertSame( 'core/button', $e[0]['type'] );
		self::assertSame( array( 'kind' => 'text', 'value' => 'Amazon' ), $e[0]['fields']['text'], 'White space of the pattern collapsed.' );
		self::assertSame( array( 'kind' => 'url', 'value' => '#' ), $e[0]['fields']['url'] );
	}

	public function testMeasuredImageHasIdSourceAndAlt(): void {
		$e = $this->read( $this->fixture( 'image' )['content'] );
		self::assertSame( 8, $e[0]['fields']['image']['value'], 'The id from the block comment, as a number.' );
		self::assertStringStartsWith( 'data:image/png;base64,', $e[0]['fields']['src']['value'], 'An inline raster image is a picture, not code.' );
		self::assertSame( '', $e[0]['fields']['alt']['value'], 'An empty alt is reported, so it can be filled.' );
		self::assertArrayNotHasKey( 'caption', $e[0]['fields'] );
		self::assertArrayNotHasKey( 'note', $e[0], 'Only measured fields were read.' );
	}

	public function testAnImageLinkAndCaptionFromBlockJson(): void {
		// Constructed in the form core/image saves (block.json: href = "figure > a",
		// caption = figcaption); not measured, so the element says so.
		$e = $this->read( '<!-- wp:image {"id":9,"linkDestination":"custom"} --><figure class="wp-block-image"><a href="https://example.com/x"><img src="https://example.com/i.jpg" alt="Alt" class="wp-image-9"/></a><figcaption class="wp-element-caption">See <a href="https://example.com/c">this</a></figcaption></figure><!-- /wp:image -->' );
		self::assertSame( 'https://example.com/x', $e[0]['fields']['link']['value'], 'The link around the image, not the one in the caption.' );
		self::assertSame( 'See <a href="https://example.com/c">this</a>', $e[0]['fields']['caption']['value'] );
		self::assertSame( AB_MCP_Block_Reader::NOTE_UNMEASURED, $e[0]['note'] );
		// Whether and with which tool a field can be changed is write_via's
		// answer; a writer does change unmeasured fields (with a warning), so
		// the note must not call the element read only.
		self::assertStringNotContainsString( 'read only', $e[0]['note'] );

		$e = $this->read( '<!-- wp:image {"id":9} --><figure class="wp-block-image"><img src="https://example.com/i.jpg" alt=""/><figcaption>Only <a href="https://example.com/c">caption</a> link</figcaption></figure><!-- /wp:image -->' );
		self::assertArrayNotHasKey( 'link', $e[0]['fields'], 'A link inside the caption is not the image link.' );
	}

	public function testSpecialCharactersAreReadAsVisitorsSeeThem(): void {
		// Measured: «A & B "Zitat" <x> – Grüsse ü» stands as A &amp; B … &lt;x>
		// in the markup and as & … in the attribute.
		$e = $this->read( $this->fixture( 'sonderzeichen' )['content'] );
		self::assertSame( 'A & B "Zitat" <x> – Grüsse ü', $e[0]['fields']['text']['value'] );
		self::assertSame( 'b0', $e[0]['id'] );
	}

	public function testABrokenTailBecomesOneLockedClassicElement(): void {
		// The measured fragment ends in closers without openers: the parser
		// gives up there and hands the rest over as classic content.
		$by = $this->byId( $this->read( $this->fixture( 'sonderzeichen' )['content'] ) );
		self::assertSame( array( 'b0', 'b2', 'b4', 'b4.0', 'b5' ), array_keys( $by ), 'White space between blocks is skipped, its index kept.' );
		self::assertSame( 'core/freeform', $by['b5']['type'] );
		self::assertTrue( $by['b5']['locked'] );
		self::assertSame( 'classic content', $by['b5']['reason'] );
		self::assertArrayNotHasKey( 'fields', $by['b5'] );
		self::assertSame( 'b4', $by['b4.0']['parent'] );
		self::assertSame( 1, $by['b4.0']['depth'] );
		self::assertSame( 'Learn more', $by['b4.0']['fields']['text']['value'] );
		self::assertSame( array( 'kind' => 'url', 'value' => '' ), $by['b4.0']['fields']['url'], 'A button without href lists an empty link, so one can be added.' );
	}

	public function testAButtonWithoutALinkListsAnEmptyOne(): void {
		// Twenty Twenty-Five 1.5, patterns/cta-centered-heading.php (WordPress
		// 7.0.2): the pattern's button has no href. core/button keeps its link
		// in the href of its <a> (block.json: url = a[href]), so the link is
		// listed empty, the way an empty alt is: a client can see there is
		// none yet and where one goes.
		$e = $this->read( "<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\">Learn more</a></div>\n<!-- /wp:button -->" );
		self::assertSame(
			array(
				'text' => array(
					'kind'  => 'text',
					'value' => 'Learn more',
				),
				'url'  => array(
					'kind'  => 'url',
					'value' => '',
				),
			),
			$e[0]['fields']
		);
		self::assertArrayNotHasKey( 'note', $e[0], 'Text and link are measured fields of core/button.' );

		// Constructed in the form core/button saves with tagName button: no
		// <a>, so nothing could carry a link, and none is listed.
		$e = $this->read( '<!-- wp:button {"tagName":"button"} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Send</button></div><!-- /wp:button -->' );
		self::assertSame( array( 'text' ), array_keys( $e[0]['fields'] ) );
	}

	public function testEmptyOkListsAMissingAttributeAndNothingElse(): void {
		add_filter(
			'ab_mcp_builder_block_profiles',
			static function ( array $profiles ): array {
				$profiles[] = array(
					'id'         => 'acme',
					'verified'   => true,
					'namespaces' => array( 'acme/' ),
					'blocks'     => array(
						'acme/cta' => array(
							'fields' => array(
								'url'   => array( 'kind' => 'url', 'from' => 'attr', 'path' => 'link.url', 'empty_ok' => true ),
								'href'  => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => 'a', 'attr' => 'href', 'empty_ok' => true ),
								'title' => array( 'kind' => 'text', 'from' => 'html_text', 'selector' => 'h3', 'empty_ok' => true ),
							),
						),
					),
				);
				return $profiles;
			}
		);
		AB_MCP_Builders::reset();

		// Missing attribute, <a> without href, no <h3>: two empty links, no title.
		$e = $this->read( '<!-- wp:acme/cta --><div><a>Go</a></div><!-- /wp:acme/cta -->' );
		self::assertSame( array( 'url', 'href' ), array_keys( $e[0]['fields'] ) );
		self::assertSame( array( '', '' ), array( $e[0]['fields']['url']['value'], $e[0]['fields']['href']['value'] ) );

		// No <a> at all: nothing could take a link. A value that is there but
		// no text (an object, true) is not "missing" either.
		$e = $this->read( '<!-- wp:acme/cta {"link":{"url":{"id":5}}} --><div><span>Go</span></div><!-- /wp:acme/cta -->' );
		self::assertArrayNotHasKey( 'fields', $e[0] );
		$e = $this->read( '<!-- wp:acme/cta {"link":{"url":true}} --><div></div><!-- /wp:acme/cta -->' );
		self::assertArrayNotHasKey( 'fields', $e[0] );
		$e = $this->read( '<!-- wp:acme/cta {"link":"https://example.org/"} --><div></div><!-- /wp:acme/cta -->' );
		self::assertArrayNotHasKey( 'fields', $e[0], 'link is a text, not a map with url: not a missing key.' );

		// A value that is there is read as always.
		$e = $this->read( '<!-- wp:acme/cta {"link":{"url":"/kontakt"}} --><div><a href="/x">Go</a></div><!-- /wp:acme/cta -->' );
		self::assertSame( array( '/kontakt', '/x' ), array( $e[0]['fields']['url']['value'], $e[0]['fields']['href']['value'] ) );
	}

	public function testCoreBlocksFromCodeAreReadAndMarkedNotMeasured(): void {
		$by = $this->byId( $this->read( $this->fixture( 'list' )['content'] ) );
		self::assertSame( 'core/list-item', $by['b0.3.0.2.0']['type'] );
		self::assertSame( 'Get access to our paid articles and weekly newsletter.', $by['b0.3.0.2.0']['fields']['text']['value'] );
		self::assertSame( AB_MCP_Block_Reader::NOTE_UNMEASURED, $by['b0.3.0.2.0']['note'] );
		self::assertArrayNotHasKey( 'note', $by['b0.3.0.2'], 'A container reads nothing, so it needs no note.' );

		$by = $this->byId( $this->read( $this->fixture( 'quote' )['content'] ) );
		self::assertSame( 'Jo Mulligan <br><sub>Atlanta, GA</sub>', $by['b0.0.0.0.1']['fields']['citation']['value'] );
		self::assertSame( '“Superb product and customer service!”', $by['b0.0.0.0.1.0.0']['fields']['text']['value'], 'The quote text is its inner blocks.' );

		$by = $this->byId( $this->read( $this->fixture( 'cover' )['content'] ) );
		self::assertSame( 'https://example.test/wp-content/themes/twentytwentyfive/assets/images/poster-image-background.webp', $by['b0']['fields']['src']['value'] );
		self::assertSame( 'Picture of a historical building in ruins.', $by['b0']['fields']['alt']['value'] );
		self::assertSame( 'Let’s hear them.', $by['b0.0.1.0.0']['fields']['text']['value'] );

		$by = $this->byId( $this->read( $this->fixture( 'media-text' )['content'] ) );
		self::assertSame( 'https://example.test/wp-content/themes/twentytwentytwo/assets/images/bird-on-black.jpg', $by['b0']['fields']['src']['value'] );
		self::assertSame( 'Emery Driscoll', $by['b0.2.0']['fields']['text']['value'], 'A line break separates words.' );
	}

	public function testUnknownBlocksAreReadGenerallyAndSaySo(): void {
		$by = $this->byId( $this->read( $this->fixture( 'media-text' )['content'] ) );
		self::assertSame( 'core/site-logo', $by['b0.1']['type'] );
		self::assertSame( AB_MCP_Block_Reader::NOTE_UNVERIFIED, $by['b0.1']['note'] );

		$e = $this->read( '<!-- wp:acme/card {"x":1} --><div class="acme"><h3>Title</h3><p>Body &amp; more</p><style>.acme{color:red}</style></div><!-- /wp:acme/card -->' );
		self::assertSame( 'acme/card', $e[0]['type'] );
		self::assertSame( array( 'kind' => 'text', 'value' => 'Title Body & more' ), $e[0]['fields']['text'] );
		self::assertSame( 'profile not verified, read only', $e[0]['note'] );
	}

	/**
	 * Constructed inputs (not measured): every locked core block, as
	 * WordPress serializes it.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function lockedBlocks(): array {
		return array(
			'html'      => array( '<!-- wp:html --><script>alert(1)</script><p>raw</p><!-- /wp:html -->', 'core/html', 'html block' ),
			'shortcode' => array( '<!-- wp:shortcode -->[contact-form-7 id="1"]<!-- /wp:shortcode -->', 'core/shortcode', 'shortcode' ),
			'code'      => array( '<!-- wp:code --><pre class="wp-block-code"><code>rm -rf /</code></pre><!-- /wp:code -->', 'core/code', 'code element' ),
			'freeform'  => array( '<!-- wp:freeform --><p onclick="x()">old</p><!-- /wp:freeform -->', 'core/freeform', 'classic content' ),
			'classic'   => array( '<p onclick="x()">Classic</p>', 'core/freeform', 'classic content' ),
		);
	}

	#[DataProvider( 'lockedBlocks' )]
	public function testLockedBlocksAreListedWithoutContent( string $content, string $type, string $reason ): void {
		$e = $this->read( $content );
		self::assertCount( 1, $e );
		self::assertSame( $type, $e[0]['type'] );
		self::assertTrue( $e[0]['locked'] );
		self::assertSame( $reason, $e[0]['reason'] );
		self::assertArrayNotHasKey( 'fields', $e[0] );
		foreach ( array( 'alert', 'raw', 'contact-form-7', 'rm -rf', 'onclick', 'old', 'Classic' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $this->flat( $e ) );
		}
		self::assertSame( array(), $this->read( $content, array( 'include_locked' => false ) ), 'include_locked=false leaves it out.' );
	}

	public function testChildrenOfALockedBlockAreNotListed(): void {
		// A synced pattern reference is a global part; it may hold inner blocks
		// in a broken copy — none of them is listed.
		$e = $this->read( '<!-- wp:block {"ref":77} --><!-- wp:paragraph --><p>inside</p><!-- /wp:paragraph --><!-- /wp:block -->' );
		self::assertCount( 1, $e );
		self::assertSame( 'global element', $e[0]['reason'] );
		self::assertTrue( $e[0]['global'] );
		self::assertSame( 'its content is post #77', $e[0]['note'] );
		self::assertStringNotContainsString( 'inside', $this->flat( $e ) );
	}

	public function testNoCodeStylingOrAttributesEverReachAField(): void {
		$content = '<!-- wp:paragraph --><p class="has-x" style="color:red" onclick="steal()">Hi <strong class="s">you</strong> <a href="https://example.com/a" target="_blank" onmouseover="x()">link</a> <a href="javascript:alert(1)">bad</a><script>evil()</script><style>p{}</style><svg><title>icon</title></svg></p><!-- /wp:paragraph -->'
			. '<!-- wp:heading --><h2 style="font-size:9px">Head<script>evil()</script></h2><!-- /wp:heading -->'
			. '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="javascript:alert(2)">Go</a></div><!-- /wp:button -->';
		$e = $this->byId( $this->read( $content ) );
		self::assertSame( 'Hi <strong>you</strong> <a href="https://example.com/a">link</a> <a>bad</a>', $e['b0']['fields']['text']['value'] );
		self::assertSame( 'Head', $e['b1']['fields']['text']['value'] );
		self::assertArrayNotHasKey( 'url', $e['b2']['fields'], 'A script address is no link.' );
		self::assertSame( 'url: an address that would run code, left out', $e['b2']['note'] );
		$all = $this->flat( $e );
		foreach ( array( 'class=', 'style', 'onclick', 'onmouseover', 'target=', '_blank', 'javascript', 'evil', 'steal', 'script', '<svg', 'icon', 'font-size', 'has-x', 'color:red', 'wp-block-button__link' ) as $never ) {
			self::assertStringNotContainsString( $never, $all, $never );
		}
	}

	public function testNoFixtureLeaksMarkupAttributes(): void {
		foreach ( array( 'heading', 'paragraph', 'button', 'image', 'sonderzeichen', 'cover', 'quote', 'list', 'media-text' ) as $name ) {
			$all = $this->flat( $this->read( $this->fixture( $name )['content'] ) );
			foreach ( array( 'class=', 'style=', '<style', '<script', 'var(--', 'is-layout' ) as $never ) {
				self::assertStringNotContainsString( $never, $all, $name . ': ' . $never );
			}
		}
	}

	public function testProfilesCannotReadCodeAttributes(): void {
		add_filter(
			'ab_mcp_builder_block_profiles',
			static function ( $profiles ) {
				$profiles[] = array(
					'id'     => 'probe',
					'blocks' => array(
						'probe/box' => array(
							'fields' => array(
								'css'     => array( 'kind' => 'text', 'from' => 'attr', 'path' => 'kadenceBlockCSS' ),
								'style'   => array( 'kind' => 'text', 'from' => 'attr', 'path' => 'style.color' ),
								'class'   => array( 'kind' => 'text', 'from' => 'attr', 'path' => 'className' ),
								'attrs'   => array( 'kind' => 'url', 'from' => 'attr', 'path' => 'htmlAttributes.href' ),
								'handler' => array( 'kind' => 'text', 'from' => 'attr', 'path' => 'onClick' ),
								'tagattr' => array( 'kind' => 'text', 'from' => 'html_attr', 'selector' => 'div', 'attr' => 'style' ),
								'evt'     => array( 'kind' => 'text', 'from' => 'html_attr', 'selector' => 'div', 'attr' => 'onclick' ),
								'desc'    => array( 'kind' => 'text', 'from' => 'attr', 'path' => 'description' ),
								'nokind'  => array( 'from' => 'attr', 'path' => 'description' ),
								'badfrom' => array( 'kind' => 'text', 'from' => 'php', 'path' => 'description' ),
							),
						),
					),
				);
				return $profiles;
			}
		);
		AB_MCP_Builders::reset();
		$e = $this->read( '<!-- wp:probe/box {"kadenceBlockCSS":"a{}","style":{"color":"red"},"className":"c","htmlAttributes":{"href":"#"},"onClick":"x()","description":"Visible"} --><div style="color:red" onclick="x()">t</div><!-- /wp:probe/box -->' );
		self::assertSame( array( 'desc' => array( 'kind' => 'text', 'value' => 'Visible' ) ), $e[0]['fields'], 'Only the visible description survives.' );
	}

	public function testEveryFieldOfTheCoreProfileIsReadable(): void {
		// The reader drops a field it may not read; a profile that lists one
		// is wrong as written. Every field declared in blocks-core.php has to
		// survive, so a code field added there turns this red.
		$raw  = include dirname( __DIR__ ) . '/includes/builders/profiles/blocks-core.php';
		$norm = AB_MCP_Block_Reader::profiles()[0];
		self::assertSame( 'core', $norm['id'] );
		foreach ( $raw['blocks'] as $name => $spec ) {
			self::assertSame( array_keys( $spec['fields'] ?? array() ), array_keys( $norm['blocks'][ $name ]['fields'] ), $name );
		}
	}

	public function testLibraryIdsAreUsedWhenUniqueAndUsable(): void {
		add_filter(
			'ab_mcp_builder_block_profiles',
			static function ( $profiles ) {
				$profiles[] = array(
					'id'         => 'probe',
					'namespaces' => array( 'probe/' ),
					'id_attr'    => 'uniqueID',
					'blocks'     => array(
						'probe/text' => array( 'fields' => array( 'text' => array( 'kind' => 'text', 'from' => 'html_text' ) ) ),
					),
				);
				return $profiles;
			}
		);
		AB_MCP_Builders::reset();
		$content = '<!-- wp:probe/text {"uniqueID":"19290_62d41f-67"} --><p>one</p><!-- /wp:probe/text -->'
			. '<!-- wp:probe/text {"uniqueID":"dup"} --><p>two</p><!-- /wp:probe/text -->'
			. '<!-- wp:probe/text {"uniqueID":"dup"} --><p>three</p><!-- /wp:probe/text -->'
			. '<!-- wp:probe/text {"uniqueID":"b0"} --><p>looks like a path</p><!-- /wp:probe/text -->'
			. '<!-- wp:probe/other {"uniqueID":"ns-1"} --><p>namespace default</p><!-- /wp:probe/other -->'
			. '<!-- wp:probe/text {"uniqueID":"has space"} --><p>unusable</p><!-- /wp:probe/text -->';
		$ids = array_column( $this->read( $content ), 'id' );
		self::assertSame( array( '19290_62d41f-67', 'b1', 'b2', 'b3', 'ns-1', 'b5' ), $ids );
		self::assertSame( count( $ids ), count( array_unique( $ids ) ), 'Every id is unique on the page.' );
	}

	public function testAStaleCopyIsNoted(): void {
		// The cover keeps its address twice: in the comment and in the img.
		$content = '<!-- wp:cover {"url":"https://example.test/old.jpg","id":5} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-5" alt="" src="https://example.test/new.jpg"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';
		$e       = $this->read( $content );
		self::assertSame( 'https://example.test/new.jpg', $e[0]['fields']['src']['value'], 'The page shows the markup.' );
		self::assertStringContainsString( 'src: the copy in attribute url differs', $e[0]['note'] );
		self::assertSame( 5, $e[0]['fields']['image']['value'] );
	}

	public function testFieldsAreCutAtTheLimit(): void {
		$long = str_repeat( 'ä', 150 );
		$e    = $this->read( '<!-- wp:heading --><h2>' . $long . '</h2><!-- /wp:heading -->', array( 'max_field_chars' => 100 ) );
		self::assertSame( str_repeat( 'ä', 100 ), $e[0]['fields']['text']['value'], 'Cut in characters, not bytes.' );
		self::assertTrue( $e[0]['fields']['text']['truncated'] );

		$e = $this->read( '<!-- wp:heading --><h2>' . $long . '</h2><!-- /wp:heading -->', array( 'max_field_chars' => 5 ) );
		self::assertSame( 100, mb_strlen( $e[0]['fields']['text']['value'] ), 'Never below 100.' );

		$e = $this->read( '<!-- wp:heading --><h2>short</h2><!-- /wp:heading -->', array( 'max_field_chars' => 100 ) );
		self::assertArrayNotHasKey( 'truncated', $e[0]['fields']['text'] );
	}

	public function testTheDefaultCapComesFromTheFilter(): void {
		add_filter(
			'ab_mcp_builder_field_max_chars',
			static function () {
				return 120;
			}
		);
		$e = $this->read( '<!-- wp:heading --><h2>' . str_repeat( 'x', 200 ) . '</h2><!-- /wp:heading -->' );
		self::assertSame( 120, strlen( $e[0]['fields']['text']['value'] ) );
		self::assertSame( 2000, AB_MCP_Builders::FIELD_MAX_CHARS );
	}

	public function testMaxElementsStopsTheWalk(): void {
		$content = str_repeat( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', 10 );
		self::assertCount( 3, $this->read( $content, array( 'max_elements' => 3 ) ) );
		self::assertCount( 10, $this->read( $content ) );
	}
}
