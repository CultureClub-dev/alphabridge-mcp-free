<?php
/**
 * The connect card: one panel per assistant.
 *
 * Claude starts in its connector directory: the entry «AlphaBridge MCP for
 * WordPress» connects through the AlphaBridge Connect hub, and the hub signs in
 * with this site's own login and consent — the OAuth flow. ChatGPT connects
 * with the same flow, straight to this site. With OAuth switched off neither
 * can work, so neither way is offered then.
 *
 * Only Cursor and other clients need a token; the token form belongs to them.
 * The one-time reveal of a new token sits outside every panel: rotating a
 * connection further down reveals a token too, whichever assistant is picked.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use AB_MCP_Settings;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ReflectionMethod;

final class ConnectorDirectoryTest extends TestCase {

	private const DIRECTORY = 'https://claude.ai/directory/alphabridge';

	protected function setUp(): void {
		ab_test_reset();
	}

	/** The card as the site renders it; $oauth null leaves the setting unstored. */
	private function card( ?bool $oauth = null ): DOMXPath {
		if ( null !== $oauth ) {
			AB_MCP_Settings::set( 'oauth_enabled', $oauth );
		}
		$method = new ReflectionMethod( AB_MCP_Admin::class, 'connect_card_html' );
		$html   = (string) $method->invoke( new AB_MCP_Admin() );

		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	private function panel( DOMXPath $x, string $client ): DOMElement {
		$node = $x->query( '//div[@id="ab-panel-' . $client . '"]' )->item( 0 );
		self::assertInstanceOf( DOMElement::class, $node, 'Panel ' . $client );
		return $node;
	}

	private function html( DOMElement $el ): string {
		return (string) $el->ownerDocument->saveHTML( $el );
	}

	public function testWithOAuthClaudeStartsInTheDirectoryWithThisSitesAddress(): void {
		$x      = $this->card( true );
		$claude = $this->html( $this->panel( $x, 'claude' ) );

		self::assertStringContainsString( 'href="' . self::DIRECTORY . '"', $claude, 'A direct link into Claude’s directory.' );
		self::assertStringContainsString( 'value="' . home_url() . '"', $claude, 'The site’s address, ready to copy.' );
		self::assertStringContainsString( 'AlphaBridge Connect hub', $claude, 'The card says that this way runs through the hub.' );
		self::assertStringContainsString( 'https://www.alphabridge-mcp.com/connector.html', $claude, 'The step-by-step guide.' );
		self::assertLessThan(
			strpos( $claude, 'Without the hub' ),
			strpos( $claude, self::DIRECTORY ),
			'The directory comes before the direct way.'
		);
	}

	public function testWithoutOAuthNeitherClaudeNorChatGPTIsOfferedAWayThatCannotWork(): void {
		$x = $this->card( false );

		foreach ( array( 'claude', 'chatgpt' ) as $client ) {
			$panel = $this->html( $this->panel( $x, $client ) );
			self::assertStringNotContainsString( self::DIRECTORY, $panel, $client );
			self::assertStringNotContainsString( 'chatgpt.com/plugins', $panel, $client );
			self::assertStringNotContainsString( 'hub', $panel, $client );
			self::assertStringContainsString( 'href="#ab-oauth"', $panel, $client . ': the way to the setting.' );
		}
		// Claude still has the way from before OAuth: a token in its connector URL.
		self::assertStringContainsString( 'pick “Other”', $this->html( $this->panel( $x, 'claude' ) ) );
		self::assertStringNotContainsString( 'pick “Other”', $this->html( $this->panel( $x, 'chatgpt' ) ) );
	}

	public function testOAuthIsOnUnlessTheSiteSwitchedItOff(): void {
		// A new site has no stored value: OAuth is on by default, so the entry shows.
		$claude = $this->html( $this->panel( $this->card(), 'claude' ) );

		self::assertStringContainsString( self::DIRECTORY, $claude );
	}

	public function testChatGPTGetsItsPluginsPageAndThisSitesEndpoint(): void {
		$chatgpt = $this->html( $this->panel( $this->card( true ), 'chatgpt' ) );

		self::assertStringContainsString( 'href="https://chatgpt.com/plugins"', $chatgpt );
		self::assertStringContainsString( 'value="' . rest_url( AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE ) . '"', $chatgpt );
	}

	public function testOnlyCursorAndOtherClientsGetTheTokenForm(): void {
		$x = $this->card( true );

		$create = $x->query( '//button[contains(concat(" ", normalize-space(@class), " "), " ab-create ")]' );
		self::assertSame( 1, $create->length, 'One create button.' );
		$token = $x->query( '//div[contains(concat(" ", normalize-space(@class), " "), " ab-token ")]' )->item( 0 );
		self::assertInstanceOf( DOMElement::class, $token );
		self::assertSame( 'cursor other', $token->getAttribute( 'data-for' ) );
		self::assertTrue( $token->hasAttribute( 'hidden' ), 'Hidden while Claude is picked.' );
		self::assertSame( 0, $x->query( '//div[contains(@class, "ab-panel")]//button[contains(@class, "ab-create")]' )->length, 'No panel carries a create button of its own.' );
	}

	public function testTheRevealSitsOutsideEveryPanel(): void {
		$x = $this->card( true );

		$reveal = $x->query( '//div[contains(concat(" ", normalize-space(@class), " "), " ab-reveal ")]' );
		self::assertSame( 1, $reveal->length );
		self::assertTrue( $reveal->item( 0 )->hasAttribute( 'hidden' ) );
		self::assertSame( 0, $x->query( '//div[contains(@class, "ab-panel")]//div[contains(concat(" ", normalize-space(@class), " "), " ab-reveal ")]' )->length );
	}

	public function testClaudeShowsFirstTheOthersWaitHidden(): void {
		$x = $this->card( true );

		self::assertFalse( $this->panel( $x, 'claude' )->hasAttribute( 'hidden' ) );
		foreach ( array( 'chatgpt', 'cursor', 'other' ) as $client ) {
			self::assertTrue( $this->panel( $x, $client )->hasAttribute( 'hidden' ), $client );
		}
	}

	public function testTheVideoMatchesTheAdminsLanguage(): void {
		$en = $this->html( $this->panel( $this->card( true ), 'claude' ) );
		self::assertStringContainsString( 'youtube.com/watch?v=DlMJ9tfrqJg', $en );

		$GLOBALS['ab_test_locale'] = 'de_CH';
		$de = $this->html( $this->panel( $this->card( true ), 'claude' ) );
		self::assertStringContainsString( 'youtube.com/watch?v=M262gCOc7gM', $de );
		self::assertStringNotContainsString( 'DlMJ9tfrqJg', $de );
	}
}
