<?php
/**
 * The consent screen an app sends the site owner to when it connects.
 *
 * It describes each token scope in one sentence; the content sentence names
 * what a content token changes and promises nothing about code; the page
 * opens with «content» checked, and an approval without a choice is content.
 * While the site is in Read (AB_MCP_Site_Mode) it says, above the choice,
 * that the connection can only read until an administrator switches to Full;
 * the scope itself is granted as chosen.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AbTestExit;
use AB_MCP_OAuth;
use AB_MCP_Site_Mode;
use AB_MCP_Tool_Registry;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;

final class ConsentScopesTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	/** The whole consent page for user 7, as echoed. */
	private function consent_page(): string {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$m                               = new ReflectionMethod( AB_MCP_OAuth::class, 'render_consent' );
		ob_start();
		$m->invoke(
			null,
			array( 'name' => 'Claude' ),
			array(
				'client_id'      => 'abc',
				'redirect_uri'   => 'https://claude.ai/api/mcp/auth_callback',
				'code_challenge' => str_repeat( 'a', 43 ),
			),
			'st4te',
			AB_MCP_Tool_Registry::scopes()
		);
		return (string) ob_get_clean();
	}

	public function testInReadTheConsentPageSaysTheConnectionOnlyReads(): void {
		$x    = $this->xpath( $this->consent_page() );
		$note = $x->query( '//*[contains(@class, "mode-read")]' );

		self::assertSame( 1, $note->length );
		self::assertSame( AB_MCP_Site_Mode::consent_text(), trim( $note->item( 0 )->textContent ) );
		self::assertStringContainsString( 'until an administrator switches AlphaBridge MCP to Full', AB_MCP_Site_Mode::consent_text() );
		// Above the choice, so it is read before the access level.
		self::assertSame( 0, $x->query( '//*[contains(@class, "mode-read")]/preceding::input[@name="ab_scope"]' )->length );
		self::assertSame( 3, $x->query( '//*[contains(@class, "mode-read")]/following::input[@name="ab_scope"]' )->length, 'Every access level is still offered.' );
	}

	public function testInFullTheConsentPageSaysNothingAboutTheMode(): void {
		update_option( 'ab_mcp_options', array( 'site_mode' => 'full' ) );

		self::assertSame( 0, $this->xpath( $this->consent_page() )->query( '//*[contains(@class, "mode-read")]' )->length );
	}

	private function scope_choices( string $default ): DOMXPath {
		$m = new ReflectionMethod( AB_MCP_OAuth::class, 'scope_choices_html' );
		return $this->xpath( (string) $m->invoke( null, AB_MCP_Tool_Registry::scopes(), $default ) );
	}

	public function testTheConsentScreenDescribesEveryScopeInOneSentence(): void {
		$default = ( new ReflectionMethod( AB_MCP_OAuth::class, 'default_scope' ) )->invoke( null, AB_MCP_Tool_Registry::scopes() );
		$x       = $this->scope_choices( $default );

		$choices = array();
		foreach ( $x->query( '//fieldset//label' ) as $label ) {
			$radio = $x->query( './/input[@type="radio"][@name="ab_scope"]', $label )->item( 0 );
			$text  = trim( $x->query( './/*[@class="scope-text"]', $label )->item( 0 )->textContent );
			self::assertSame( 1, preg_match_all( '/[.!?](\s|$)/', $text ), 'One sentence: ' . $text );
			$choices[ $radio->getAttribute( 'value' ) ] = array( trim( $x->query( './/b', $label )->item( 0 )->textContent ), $radio->hasAttribute( 'checked' ) );
		}

		self::assertSame(
			array(
				'read'    => array( 'Read only', false ),
				'content' => array( 'Content', true ),
				'full'    => array( 'Full', false ),
			),
			$choices,
			'Narrowest first; content stays the default.'
		);
		$content = AB_MCP_Tool_Registry::scopes()['content'];
		self::assertStringContainsString( 'change or delete posts, pages, media, terms and comments', $content );
		self::assertStringContainsString( 'no theme or plugin files and no site settings', $content );
		// For an account with unfiltered_html WordPress stores scripts in
		// content whatever the scope, and only an add-on writes the texts of
		// builder pages that keep them outside the content field.
		self::assertStringNotContainsString( 'no code', $content );
		self::assertStringNotContainsString( 'page-builder', $content );
		self::assertStringContainsString( 'Access level', $x->query( '//legend' )->item( 0 )->textContent );
	}

	public function testTheConsentPageOpensWithContentChecked(): void {
		$x = $this->xpath( $this->consent_page() );

		$radios = $x->query( '//form[@method="post"]//input[@type="radio"][@name="ab_scope"]' );
		$order  = array();
		$on     = array();
		foreach ( $radios as $radio ) {
			$order[] = $radio->getAttribute( 'value' );
			if ( $radio->hasAttribute( 'checked' ) ) {
				$on[] = $radio->getAttribute( 'value' );
			}
		}
		self::assertSame( array( 'read', 'content', 'full' ), $order, 'Every scope, narrowest first, inside the form that posts.' );
		self::assertSame( array( 'content' ), $on, 'Exactly one checked, and never full.' );
		self::assertStringContainsString( 'user7', $x->query( '//*[@class="who"]' )->item( 0 )->textContent, 'The page it is part of rendered.' );
	}

	public function testAContentTokenChangesWhatItsSentenceNames(): void {
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/class-tools-*.php' ) as $file ) {
			require_once $file;
		}
		$r = new AB_MCP_Tool_Registry();
		foreach ( get_declared_classes() as $class ) {
			if ( 0 === strpos( $class, 'AB_MCP_Tools_' ) && ! ( new \ReflectionClass( $class ) )->isAbstract() ) {
				$class::register( $r );
			}
		}

		$writers = array();
		foreach ( $r->all() as $name => $def ) {
			if ( AB_MCP_Tool_Registry::scope_allows( 'content', $name, $def ) && ! AB_MCP_Tool_Registry::is_read_only( $name, $def ) ) {
				$writers[] = $name;
				self::assertMatchesRegularExpression( '/_(post|media|term|comment)(_|$)/', $name, 'A content token changes only posts, pages, media, terms and comments.' );
			}
		}
		self::assertGreaterThan( 10, count( $writers ), 'Free\'s writers are all there.' );
		self::assertSame(
			array( 'wp_delete_post', 'wp_delete_media', 'wp_delete_term', 'wp_delete_comment' ),
			array_values( array_filter( $writers, static fn( string $n ): bool => 0 === strpos( $n, 'wp_delete_' ) ) ),
			'Deleting is among them, so the sentence says so.'
		);
	}

	public function testWithoutContentTheConsentScreenFallsBackToReadNeverFull(): void {
		$default = ( new ReflectionMethod( AB_MCP_OAuth::class, 'default_scope' ) )->invoke( null, array( 'read' => 'r', 'full' => 'f' ) );

		self::assertSame( 'read', $default );
	}

	public function testAnApprovalWithoutAChoiceIsContent(): void {
		$redirect  = 'https://app.example/callback';
		$verifier  = 'verifier-verifier-verifier-verifier-verifier-1234';
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array(
				'abc_registered' => array(
					'name'          => 'Registered',
					'redirect_uris' => array( $redirect ),
				),
			)
		);
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_logged_in']    = true;
		$GLOBALS['ab_test_can']          = static fn(): bool => true;
		$_GET                            = array(
			'ab_mcp_oauth'          => 'authorize',
			'client_id'             => 'abc_registered',
			'redirect_uri'          => $redirect,
			'response_type'         => 'code',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'state'                 => 'st4te',
		);
		$_REQUEST                        = $_GET;
		$_POST                           = array( '_wpnonce' => 'nonce-ab_mcp_oauth_approve' ); // No ab_scope.
		$_SERVER['REQUEST_METHOD']       = 'POST';

		try {
			AB_MCP_OAuth::handle_authorize();
			self::fail( 'The approval ended without a redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'redirect', $exit->kind );
		}

		$scopes = array();
		foreach ( $GLOBALS['ab_test_transients'] as $record ) {
			if ( is_array( $record ) && isset( $record['scope'] ) ) {
				$scopes[] = $record['scope'];
			}
		}
		self::assertSame( array( 'content' ), $scopes );
	}
}
