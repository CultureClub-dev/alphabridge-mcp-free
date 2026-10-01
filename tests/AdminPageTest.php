<?php
/**
 * The settings screen around the connect card: the band at the top, the
 * capabilities, the order of the main column and the side column.
 *
 * The capabilities form must post what it always posted — the handler reads
 * enabled_tools[], all_tools and read_only — while the switches for all tools
 * and for a group only move the tool switches and post nothing themselves.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use AB_MCP_Tool_Registry;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;

final class AdminPageTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	/** Two groups, three tools; the dangerous one is off by default. */
	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_list_posts', array( 'description' => 'List posts. With filters and paging.' ) );
		$r->register(
			'wp_delete_post',
			array(
				'description' => 'Delete a post.',
				'dangerous'   => true,
			)
		);
		$r->set_current_group( 'seo', 'SEO' );
		$r->register( 'wp_seo_get', array( 'description' => 'Read the SEO fields of a post.' ) );
		return $r;
	}

	/** @param mixed ...$args */
	private function call( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out = $m->invoke( new AB_MCP_Admin(), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	private function caps(): DOMXPath {
		$r = $this->registry();
		return $this->xpath( $this->call( 'capabilities_card_html', $r->groups(), $r->all(), false ) );
	}

	public function testTheBandCountsConnectionsToolsAndReadOnly(): void {
		$all  = $this->registry()->all();
		$html = $this->call( 'hero_html', array( array( 'hash' => 'h1' ) ), $all, false );

		self::assertStringContainsString( '<b>1</b> connection<', $html, 'One connection, singular.' );
		self::assertStringContainsString( '<b>2</b> of <b>3</b> tools on', $html );
		self::assertStringContainsString( 'Read-only off', $html );

		$on = $this->call( 'hero_html', array(), $all, true );
		self::assertStringContainsString( '<b>0</b> connections', $on );
		self::assertStringContainsString( 'Read-only on', $on );
	}

	public function testTheBandOffersFourAssistantsWithClaudeFirst(): void {
		$x = $this->xpath( $this->call( 'hero_html', array(), $this->registry()->all(), false ) );

		$buttons = $x->query( '//button[contains(@class, "ab-client")]' );
		$clients = array();
		foreach ( $buttons as $b ) {
			$clients[ $b->getAttribute( 'data-client' ) ] = $b->getAttribute( 'aria-pressed' );
		}
		self::assertSame(
			array(
				'claude'  => 'true',
				'chatgpt' => 'false',
				'cursor'  => 'false',
				'other'   => 'false',
			),
			$clients
		);
		self::assertSame( AB_MCP_URL . 'assets/logo-128.png', $x->query( '//img[@class="ab-logo"]' )->item( 0 )->getAttribute( 'src' ), 'The logo ships with the plugin.' );
	}

	public function testCapabilitiesPostEveryToolAsBefore(): void {
		$x = $this->caps();

		$tools = array();
		foreach ( $x->query( '//form[@id="ab-caps"]//input[@name="enabled_tools[]"]' ) as $in ) {
			$tools[ $in->getAttribute( 'value' ) ] = $in->hasAttribute( 'checked' );
		}
		self::assertSame(
			array(
				'wp_list_posts'  => true,
				'wp_delete_post' => false,
				'wp_seo_get'     => true,
			),
			$tools
		);
		self::assertSame( 'wp_list_posts,wp_delete_post,wp_seo_get', $x->query( '//form[@id="ab-caps"]//input[@name="all_tools"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 1, $x->query( '//form[@id="ab-caps"]//input[@type="checkbox"][@name="read_only"]' )->length, 'Read-only mode is saved with the capabilities.' );
		foreach ( array( 'ab-all-toggle', 'ab-group-toggle' ) as $class ) {
			foreach ( $x->query( '//input[contains(@class, "' . $class . '")]' ) as $in ) {
				self::assertFalse( $in->hasAttribute( 'name' ), $class . ' posts nothing itself.' );
			}
		}
		self::assertSame( 'nonce-ab_mcp_save', $x->query( '//form[@id="ab-caps"]//input[@name="_wpnonce"]' )->item( 0 )->getAttribute( 'value' ), 'Signed for the save handler.' );
		self::assertSame( 'ab_mcp_save', $x->query( '//form[@id="ab-caps"]//input[@name="action"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 1, $x->query( '//input[@type="search"][contains(@class, "ab-search")]' )->length );
		self::assertSame( '2', trim( $x->query( '//*[contains(@class, "ab-on-count")]' )->item( 0 )->textContent ) );
	}

	public function testAGroupShowsHowManyOfItsToolsAreOn(): void {
		$x = $this->caps();

		$content = $x->query( '//details[@data-group="content"]' )->item( 0 );
		$seo     = $x->query( '//details[@data-group="seo"]' )->item( 0 );
		self::assertSame( '1/2', trim( $x->query( './/summary//*[contains(@class, "ab-status")]', $content )->item( 0 )->textContent ) );
		self::assertStringContainsString( 'ab-pill--partial', $x->query( './/summary//*[contains(@class, "ab-status")]', $content )->item( 0 )->getAttribute( 'class' ) );
		self::assertSame( 'On', trim( $x->query( './/summary//*[contains(@class, "ab-status")]', $seo )->item( 0 )->textContent ) );
		self::assertSame( '2 tools', trim( $x->query( './/*[contains(@class, "ab-group__n")]', $content )->item( 0 )->textContent ) );
		self::assertSame( '1 tool', trim( $x->query( './/*[contains(@class, "ab-group__n")]', $seo )->item( 0 )->textContent ), 'Singular, not «1 tools».' );
		self::assertFalse( $x->query( './/input[contains(@class, "ab-group-toggle")]', $content )->item( 0 )->hasAttribute( 'checked' ), 'Partly on: not checked, so a click switches all of it on.' );
		self::assertTrue( $x->query( './/input[contains(@class, "ab-group-toggle")]', $seo )->item( 0 )->hasAttribute( 'checked' ), 'All on: checked.' );
		self::assertSame( 1, $x->query( './/*[contains(@class, "ab-pill--mighty")]', $content )->length );
		self::assertSame( 0, $x->query( './/*[contains(@class, "ab-pill--mighty")]', $seo )->length );
	}

	public function testEachToolShowsItsFirstSentenceAndTheRestBehindTheInfo(): void {
		$x = $this->caps();

		$row = $x->query( '//li[.//input[@value="wp_list_posts"]]' )->item( 0 );
		self::assertSame( 'List posts.', trim( $x->query( './/p', $row )->item( 0 )->textContent ) );
		self::assertSame( 'List posts. With filters and paging.', trim( $x->query( './/*[contains(@class, "ab-tip")]', $row )->item( 0 )->textContent ) );

		$info = $x->query( './/button[contains(@class, "ab-info")]', $row )->item( 0 );
		self::assertSame( 'ab-tip-wp_list_posts', $info->getAttribute( 'aria-describedby' ), 'Screen readers get the whole description.' );
		self::assertSame( 1, $x->query( '//*[@id="ab-tip-wp_list_posts"]' )->length );

		$one = $x->query( '//li[.//input[@value="wp_seo_get"]]' )->item( 0 );
		self::assertSame( 0, $x->query( './/*[contains(@class, "ab-info")]', $one )->length, 'One sentence needs no «i».' );
	}

	public function testTheMainColumnKeepsItsOrder(): void {
		add_action(
			'ab_mcp_admin_main_boxes',
			static function () {
				echo '<div id="addon-main"></div>';
			}
		);
		$r    = $this->registry();
		$html = $this->call( 'render_main_column', array(), $r->groups(), $r->all(), false );

		$at = array();
		foreach ( array( 'id="ab-connect"', 'id="ab-caps"', 'id="ab-connections"', 'id="addon-main"', 'id="ab-log"' ) as $marker ) {
			$pos = strpos( $html, $marker );
			self::assertIsInt( $pos, $marker );
			$at[] = $pos;
		}
		$sorted = $at;
		sort( $sorted );
		self::assertSame( $sorted, $at, 'Connect, capabilities, connections, add-on boxes, log.' );
	}

	public function testEveryFormOnThePageIsSignedForItsHandler(): void {
		$x = $this->xpath( $this->call( 'render_connections_card', array() ) . $this->call( 'render_audit' ) );

		$forms = array();
		foreach ( $x->query( '//form' ) as $form ) {
			$action = $x->query( './/input[@name="action"]', $form )->item( 0 );
			$nonce  = $x->query( './/input[@name="_wpnonce"]', $form )->item( 0 );
			self::assertNotNull( $action );
			self::assertNotNull( $nonce );
			$forms[ $action->getAttribute( 'value' ) ] = $nonce->getAttribute( 'value' );
		}
		self::assertSame(
			array(
				'ab_mcp_connector_auth'    => 'nonce-ab_mcp_connector_auth',
				'ab_mcp_oauth_settings'    => 'nonce-ab_mcp_oauth_settings',
				'ab_mcp_protocol_settings' => 'nonce-ab_mcp_protocol_settings',
				'ab_mcp_audit_clear'       => 'nonce-ab_mcp_audit_clear',
			),
			$forms
		);
		self::assertSame( 1, $x->query( '//form//input[@type="checkbox"][@name="connector_url_auth_enabled"]' )->length );
		self::assertSame( 1, $x->query( '//form//input[@type="checkbox"][@name="oauth_enabled"]' )->length );
		self::assertSame( 1, $x->query( '//form//input[@type="checkbox"][@name="modern_protocol"]' )->length );
	}

	public function testTheProtocolSwitchShowsTheStoredSetting(): void {
		$checked = function (): bool {
			$x   = $this->xpath( $this->call( 'render_connections_card', array() ) );
			$box = $x->query( '//input[@name="modern_protocol"]' )->item( 0 );
			self::assertNotNull( $box );
			return $box->hasAttribute( 'checked' );
		};

		self::assertTrue( $checked(), 'On by default.' );
		\AB_MCP_Settings::set( 'modern_protocol', false );
		self::assertFalse( $checked() );
	}

	public function testSavingTheProtocolSwitchStoresIt(): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$save                   = function ( array $post ): string {
			$_POST = $post;
			try {
				( new AB_MCP_Admin() )->handle_protocol_settings();
			} catch ( \AbTestExit $exit ) {
				return $exit->kind;
			} finally {
				$_POST = array();
			}
			return 'no exit';
		};

		self::assertSame( 'redirect', $save( array() ) );
		self::assertFalse( \AB_MCP_Settings::get( 'modern_protocol' ), 'An unticked box is not posted: off.' );

		self::assertSame( 'redirect', $save( array( 'modern_protocol' => '1' ) ) );
		self::assertTrue( \AB_MCP_Settings::get( 'modern_protocol' ) );
	}

	public function testOnlyAnAdministratorCanSaveTheProtocolSwitch(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		$_POST                  = array();

		try {
			( new AB_MCP_Admin() )->handle_protocol_settings();
			self::fail( 'The guard did not stop the request.' );
		} catch ( \AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
		}
		self::assertTrue( \AB_MCP_Settings::get( 'modern_protocol' ), 'Unchanged.' );
	}

	public function testTheProtocolSwitchSaysWhenAFilterDecides(): void {
		$note = fn(): string => $this->call( 'render_connections_card', array() );

		self::assertStringNotContainsString( 'ab_mcp_modern_protocol filter', $note(), 'Without a filter the switch alone decides.' );

		add_filter( 'ab_mcp_modern_protocol', '__return_false' );
		self::assertStringContainsString( 'right now the revision is not answered', $note(), 'The admin learns why saving the switch changes nothing.' );
	}

	public function testTheSideColumnOrdersProBoxAddOnsGuides(): void {
		add_action(
			'ab_mcp_admin_side_boxes',
			static function () {
				echo '<div id="addon-side"></div>';
			}
		);
		$html = $this->call( 'sidebar_html' );

		$pro    = strpos( $html, 'class="postbox ab-pro"' );
		$addon  = strpos( $html, 'id="addon-side"' );
		$guides = strpos( $html, 'ab-guides' );
		self::assertIsInt( $pro );
		self::assertIsInt( $addon );
		self::assertIsInt( $guides );
		self::assertTrue( $pro < $addon && $addon < $guides, 'Pro box, add-on boxes, guides.' );
	}

	public function testASiteWithProSeesTheAddOnBoxesButNoProBox(): void {
		add_filter(
			'ab_mcp_site_edition',
			static function () {
				return 'pro';
			}
		);
		add_action(
			'ab_mcp_admin_side_boxes',
			static function () {
				echo '<div id="addon-side"></div>';
			}
		);
		$html = $this->call( 'sidebar_html' );

		self::assertStringNotContainsString( 'ab-pro', $html );
		self::assertStringContainsString( 'id="addon-side"', $html );
		self::assertStringContainsString( 'ab-guides', $html );
	}
}
