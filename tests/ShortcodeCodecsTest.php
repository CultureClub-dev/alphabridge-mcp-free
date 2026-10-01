<?php
/**
 * The encodings of the shortcode builders, decoded for display.
 *
 * Inputs are the vendor examples of the measurement folder of the
 * page-builder plan, word for word where the source has one
 * (alphabridge-docs: docs/recherche/2026-09-30-page-builder-messung/quellen/,
 * file and line named at each case); where a source documents only the rule,
 * the case is built from that rule and says so. None of it was measured at
 * an installation.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Shortcode_Codecs;
use PHPUnit\Framework\TestCase;

final class ShortcodeCodecsTest extends TestCase {

	public function testWpbakeryBacktickQuotesAndBrackets(): void {
		// wpbakery.md l. 96: the stored attribute, and the text it stands for (l. 94).
		self::assertSame( 'Er sagt "Hallo" [x]', AB_MCP_Shortcode_Codecs::wpbakery_attr( 'Er sagt ``Hallo`` `{`x`}`' ) );
	}

	public function testWpbakeryBracketsAreReplacedBeforeQuotes(): void {
		// Built from the rule of wpbakery.md d): a quote right before or after a bracket.
		self::assertSame( '"[', AB_MCP_Shortcode_Codecs::wpbakery_attr( '```{`' ) );
		self::assertSame( '["', AB_MCP_Shortcode_Codecs::wpbakery_attr( '`{```' ) );
		self::assertSame( '][', AB_MCP_Shortcode_Codecs::wpbakery_attr( '`}``{`' ) );
		self::assertSame( 'Über uns', AB_MCP_Shortcode_Codecs::wpbakery_attr( 'Über uns' ), 'Plain text stays as it is.' );
	}

	public function testWpbakeryLink(): void {
		// wpbakery.md l. 66.
		self::assertSame(
			array(
				'url'    => 'https://beispiel.ch/preise/',
				'title'  => 'Preise',
				'target' => '_blank',
				'rel'    => 'nofollow',
			),
			AB_MCP_Shortcode_Codecs::wpbakery_link( 'url:https%3A%2F%2Fbeispiel.ch%2Fpreise%2F|title:Preise|target:_blank|rel:nofollow' )
		);
	}

	public function testWpbakeryLinkWithEmptySegmentsAndEncodedTitle(): void {
		// Built from wpbakery.md c) and d): empty segments "||" occur ([54]); "Über uns" as a link title is
		// stored title:%C3%9Cber%20uns (d), "Folgen für eine einfache Textsuche").
		$link = AB_MCP_Shortcode_Codecs::wpbakery_link( 'url:%2Fkontakt%2F||title:%C3%9Cber%20uns|' );
		self::assertSame( '/kontakt/', $link['url'] );
		self::assertSame( 'Über uns', $link['title'] );
		self::assertSame( '', $link['target'] );
		self::assertSame( '', AB_MCP_Shortcode_Codecs::wpbakery_link( 'title:x' )['url'], 'No url segment: no address.' );
		self::assertSame( '', AB_MCP_Shortcode_Codecs::wpbakery_link( '' )['url'] );
		self::assertSame( array( 'url', 'title', 'target', 'rel' ), array_keys( AB_MCP_Shortcode_Codecs::wpbakery_link( 'onclick:alert%281%29|url:%23' ) ), 'Unknown keys are dropped.' );
	}

	public function testWpbakerySafeValuesAreRecognised(): void {
		// wpbakery.md d): "#E-8_" + base64 of a URL-encoded value.
		self::assertTrue( AB_MCP_Shortcode_Codecs::wpbakery_is_safe_encoded( '#E-8_JTNDaWZyYW1lJTNF' ) );
		self::assertFalse( AB_MCP_Shortcode_Codecs::wpbakery_is_safe_encoded( 'Kontakt #E-8_' ) );
	}

	public function testDiviPercentSequences(): void {
		// Built from the table in divi-4.md d): %22 = ", %92 = \, %91 = [, %93 = ].
		self::assertSame( 'Er sagt "Hallo" [x] C:\\', AB_MCP_Shortcode_Codecs::divi4_attr( 'Er sagt %22Hallo%22 %91x%93 C:%92' ) );
		self::assertSame( 'a%5cb', AB_MCP_Shortcode_Codecs::divi4_attr( 'a%5cb' ), '%5c is open in the source and stays.' );
		self::assertSame( 'Über uns', AB_MCP_Shortcode_Codecs::divi4_attr( 'Über uns' ) );
	}

	public function testDiviDynamicContentIsRecognised(): void {
		// divi-4.md d): prior knowledge in the source, "@ET-DC@<Base64-JSON>@".
		self::assertTrue( AB_MCP_Shortcode_Codecs::divi4_is_dynamic( '@ET-DC@eyJkeW5hbWljIjp0cnVlfQ==@' ) );
		self::assertFalse( AB_MCP_Shortcode_Codecs::divi4_is_dynamic( 'Mail @ET-DC@' ) );
	}

	public function testEnfoldLinks(): void {
		// enfold.md l. 58 (Doku-Zitat [20]): "manually,http://" is no link ([18] l. 842).
		self::assertSame( 'none', AB_MCP_Shortcode_Codecs::enfold_link( 'manually,http://' )['kind'] );
		// enfold.md l. 60: an internal link as type,id.
		self::assertSame(
			array(
				'kind' => 'internal',
				'url'  => '',
				'type' => 'page',
				'id'   => 42,
			),
			AB_MCP_Shortcode_Codecs::enfold_link( 'page,42' )
		);
		// enfold.md c): own addresses, mailto: and tel: included, after "manually,"; "lightbox".
		self::assertSame( 'https://beispiel.ch/kontakt/', AB_MCP_Shortcode_Codecs::enfold_link( 'manually,https://beispiel.ch/kontakt/' )['url'] );
		self::assertSame( 'mailto:info@beispiel.ch', AB_MCP_Shortcode_Codecs::enfold_link( 'manually,mailto:info@beispiel.ch' )['url'] );
		self::assertSame( 'lightbox', AB_MCP_Shortcode_Codecs::enfold_link( 'lightbox' )['kind'] );
		self::assertSame( 'none', AB_MCP_Shortcode_Codecs::enfold_link( '' )['kind'] );
		self::assertSame( 'unknown', AB_MCP_Shortcode_Codecs::enfold_link( 'something else' )['kind'] );
	}
}
