<?php
/**
 * The switch «Offer AlphaBridge's tools as WordPress abilities, for the MCP
 * Adapter» (AB_MCP_Own_Abilities, since 4.12.0) on the settings screen: it
 * sits in the fine-tuning, below the tool groups and outside them, posts with
 * the fine-tuning's one form, is off until switched on, and the fine-tuning's
 * button saves it through ab_mcp_fine_save, with the form's nonce and for an
 * administrator only. What the switch does is the standalone script
 * tests/standalone-abilities/own-abilities-test.php.
 *
 * Each test runs in a process of its own: the class is loaded there, as the
 * plugin loads it, and no other test of the suite renders the page with it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Own_Abilities;
use AB_MCP_Settings;
use AB_MCP_Tool_Registry;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OwnAbilitiesAdminTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	private static function load(): void {
		require_once dirname( __DIR__ ) . '/includes/abilities/class-own-abilities.php';
		AB_MCP_Own_Abilities::hook();
	}

	private static function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_list_posts', array( 'description' => 'List posts.' ) );
		$r->register( 'wp_delete_post', array( 'description' => 'Delete a post.', 'dangerous' => true ) );
		return $r;
	}

	private static function card(): DOMXPath {
		$r    = self::registry();
		$m    = new ReflectionMethod( AB_MCP_Admin::class, 'capabilities_card_html' );
		$html = (string) $m->invoke( new AB_MCP_Admin( $r ), $r->groups(), $r->all() );
		$doc  = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	/**
	 * Save the fine-tuning as its button does.
	 *
	 * @param array<string,mixed> $post Fields besides the nonce.
	 * @return string How the request ended: redirect or die.
	 */
	private static function save( array $post, string $nonce = 'nonce-ab_mcp_save' ): string {
		$_POST    = $post + array( '_wpnonce' => $nonce, 'action' => 'ab_mcp_save' );
		$_REQUEST = $_POST;
		try {
			( new AB_MCP_Admin( self::registry() ) )->handle_save();
		} catch ( \AbTestExit $exit ) {
			return $exit->kind;
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return 'no exit';
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheSwitchSitsInTheFineTuningOffAndOutsideTheGroups(): void {
		self::load();
		$x  = self::card();
		$in = $x->query( '//section[@id="ab-fine"]//div[@id="ab-own-abilities"]//input[@type="checkbox"][@name="ab_own_abilities"]' );
		self::assertSame( 1, $in->length, 'In the fine-tuning, with its anchor.' );
		$box = $in->item( 0 );
		self::assertSame( 'ab-caps', $box->getAttribute( 'form' ), 'Posts with the fine-tuning\'s one form.' );
		self::assertSame( 'switch', $box->getAttribute( 'role' ) );
		self::assertFalse( $box->hasAttribute( 'checked' ), 'Off until an administrator switches it on.' );
		self::assertStringNotContainsString( 'ab-tool', $box->getAttribute( 'class' ) );
		self::assertStringNotContainsString( 'ab-item', $box->getAttribute( 'class' ), 'The switches for all tools and the groups do not move it.' );
		self::assertSame( 0, $x->query( '//div[contains(@class, "ab-group")]//input[@name="ab_own_abilities"]' )->length, 'Outside the tool groups.' );
		self::assertSame( 1, $x->query( '//section[@id="ab-fine"]//input[@type="hidden"][@name="ab_own_abilities_shown"][@form="ab-caps"]' )->length );
		self::assertStringContainsString( 'Offer AlphaBridge\'s tools as WordPress abilities, for the MCP Adapter', $x->query( '//label[.//input[@name="ab_own_abilities"]]' )->item( 0 )->textContent );
		self::assertStringContainsString( 'with their WordPress rights', $x->query( '//div[@id="ab-own-abilities"]' )->item( 0 )->textContent );
		self::assertSame( 1, $x->query( '//div[@id="ab-own-abilities"]/following-sibling::div[contains(@class, "ab-savebar")]' )->length, 'Above the button that saves it.' );

		AB_MCP_Settings::set( AB_MCP_Own_Abilities::SETTING, true );
		self::assertTrue( self::card()->query( '//input[@name="ab_own_abilities"]' )->item( 0 )->hasAttribute( 'checked' ), 'On once switched on.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheButtonOfTheFineTuningSavesItWithItsNonce(): void {
		self::load();
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$tools                  = array( 'enabled_tools' => array( 'wp_list_posts', 'wp_delete_post' ) );

		self::assertSame( 'redirect', self::save( $tools + array( 'ab_own_abilities_shown' => '1', 'ab_own_abilities' => '1' ) ) );
		self::assertTrue( AB_MCP_Own_Abilities::enabled() );
		self::assertSame( array( 'wp_list_posts' => true, 'wp_delete_post' => true ), get_option( 'ab_mcp_tool_state' ), 'The tools are saved as before.' );

		self::assertSame( 'die', self::save( $tools + array( 'ab_own_abilities_shown' => '1' ), 'nonce-ab_mcp_oauth_settings' ) );
		self::assertTrue( AB_MCP_Own_Abilities::enabled(), 'Without the form\'s nonce nothing changes.' );

		self::assertSame( 'redirect', self::save( $tools ) );
		self::assertTrue( AB_MCP_Own_Abilities::enabled(), 'A form that did not show the switch leaves it.' );

		self::assertSame( 'redirect', self::save( $tools + array( 'ab_own_abilities_shown' => '1' ) ) );
		self::assertFalse( AB_MCP_Own_Abilities::enabled(), 'Shown and not ticked: off.' );

		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		self::assertSame( 'die', self::save( $tools + array( 'ab_own_abilities_shown' => '1', 'ab_own_abilities' => '1' ) ) );
		self::assertFalse( AB_MCP_Own_Abilities::enabled(), 'Only an administrator.' );
	}
}
