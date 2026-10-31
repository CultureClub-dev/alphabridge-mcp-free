<?php
/**
 * The two pages of the menu «AlphaBridge» (since 4.16.0): what stands on
 * each, and where every address and every form of the plugin leads.
 * WordPress' own menu is under test in AdminMenuTest.
 *
 * What must hold:
 *
 * - AB_MCP_Guidance::settings_url() leads an anchor of the card «Staging»
 *   to «Staging» and every other one to «AI Connection»; a page named by the
 *   caller wins, an unknown one is «AI Connection». The table of the staging
 *   anchors is exactly the ids the page «Staging» shows, and no id of «AI
 *   Connection» is in it.
 * - Every form lands with its anchor and its notice on the page it was sent
 *   from: the four of the card on «Staging», all others on «AI Connection».
 * - «AI Connection» carries what the settings page carried but the card
 *   «Staging»: the boxes of add-ons on the hook of 4.16.0 and, right after,
 *   on the hook from before; the side column.
 * - «Staging» carries its heading, one sentence, the marker for admin
 *   notices, the notice of a form, the card and the hook below it; no side
 *   column, nothing of «AI Connection», no hook of it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Guidance;
use AB_MCP_Preview_Protection;
use AB_MCP_Staging;
use AB_MCP_Staging_Gate;
use AB_MCP_Tool_Registry;
use AbTestExit;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-preview-protection.php';
require_once __DIR__ . '/../includes/class-staging-gate.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';

final class AdminPagesTest extends TestCase {

	private const ADMIN = 'https://example.test/wp-admin/admin.php?page=';

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		$GLOBALS['ab_test_can']          = static fn( string $cap ): bool => 'manage_options' === $cap;
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	private static function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_list_posts', array( 'description' => 'List posts.' ) );
		$r->register( 'wp_update_post', array( 'description' => 'Update a post.' ) );
		return $r;
	}

	private static function connection_page(): string {
		ob_start();
		( new AB_MCP_Admin( self::registry() ) )->render();
		return (string) ob_get_clean();
	}

	private static function staging_page(): string {
		ob_start();
		( new AB_MCP_Admin( self::registry() ) )->render_staging();
		return (string) ob_get_clean();
	}

	private static function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	/** @return array<int,string> Every id on the page, in order. */
	private static function ids( string $html ): array {
		$out = array();
		foreach ( self::xpath( $html )->query( '//*[@id]' ) as $el ) {
			$out[] = $el->getAttribute( 'id' );
		}
		return $out;
	}

	/* ------------------------------------------------------------ addresses */

	public function testTheAnchorsOfTheCardStagingLeadToStaging(): void {
		foreach ( array( AB_MCP_Admin::STAGING_CARD, AB_MCP_Staging::ANCHOR, AB_MCP_Preview_Protection::ANCHOR, AB_MCP_Staging_Gate::ANCHOR ) as $anchor ) {
			self::assertContains( $anchor, AB_MCP_Guidance::STAGING_ANCHORS );
			self::assertSame( self::ADMIN . 'alphabridge-staging#' . $anchor, AB_MCP_Guidance::settings_url( $anchor ) );
		}
		self::assertSame( self::ADMIN . 'alphabridge-connection', AB_MCP_Guidance::settings_url() );
		foreach ( array( 'ab-mode', 'ab-fine', 'ab-tool-wp_list_posts', 'ab-connections', 'ab-doctor', 'ab-oauth-chatgpt', 'ab-undo', 'ab-deploy', 'ab-staging-elsewhere', 'ab-stagingx' ) as $anchor ) {
			self::assertSame( self::ADMIN . 'alphabridge-connection#' . $anchor, AB_MCP_Guidance::settings_url( $anchor ), $anchor );
		}
	}

	public function testThePageTheCallerNamesWinsAndAnUnknownOneIsAIConnection(): void {
		self::assertSame( self::ADMIN . 'alphabridge-staging#ab-undo', AB_MCP_Guidance::settings_url( 'ab-undo', 'staging' ), 'Pro names the page of its own anchors.' );
		self::assertSame( self::ADMIN . 'alphabridge-staging', AB_MCP_Guidance::settings_url( '', 'staging' ) );
		self::assertSame( self::ADMIN . 'alphabridge-connection#ab-preview', AB_MCP_Guidance::settings_url( 'ab-preview', 'connection' ) );
		self::assertSame( self::ADMIN . 'alphabridge-connection#ab-staging', AB_MCP_Guidance::settings_url( 'ab-staging', 'elsewhere' ) );
		self::assertSame( self::ADMIN . 'alphabridge-connection#ab-staging', AB_MCP_Guidance::settings_url( 'ab-staging', 'alphabridge-staging' ), 'The name of the page, not its slug.' );
		self::assertSame( self::ADMIN . 'alphabridge-staging#ab-staging', AB_MCP_Guidance::settings_url( 'ab-staging', array( 'staging' ) ), 'No text: the page of the anchor.' );
		// The anchor is filtered first, then looked up.
		self::assertSame( self::ADMIN . 'alphabridge-staging#ab-preview', AB_MCP_Guidance::settings_url( 'ab-pre"view' ) );
	}

	public function testTheTableIsTheIdsOfThePageStaging(): void {
		$ids = array_values( array_unique( self::ids( self::staging_page() ) ) );
		sort( $ids );
		$table = AB_MCP_Guidance::STAGING_ANCHORS;
		sort( $table );
		self::assertSame( $table, $ids, 'Every id on «Staging» is in the table, and every entry of the table is on the page.' );
		foreach ( $ids as $id ) {
			self::assertStringStartsWith( self::ADMIN . 'alphabridge-staging#', AB_MCP_Guidance::settings_url( $id ), $id );
		}
	}

	public function testNoIdOfAIConnectionLeadsToStaging(): void {
		$ids = self::ids( self::connection_page() );
		self::assertGreaterThan( 20, count( $ids ), 'The scan finds the ids.' );
		foreach ( $ids as $id ) {
			self::assertNotContains( $id, AB_MCP_Guidance::STAGING_ANCHORS, $id );
			self::assertStringStartsWith( self::ADMIN . 'alphabridge-connection#', AB_MCP_Guidance::settings_url( $id ), $id );
		}
	}

	/* ---------------------------------------------------------------- forms */

	/**
	 * Every form of the plugin's pages (admin-post.php), with what it posts,
	 * the page, the anchor and the notice it lands on.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:string,4:string}>
	 */
	public static function forms(): array {
		return array(
			'fine-tuning'                 => array( 'handle_save', array( '_wpnonce' => 'nonce-ab_mcp_save', 'enabled_tools' => array( 'wp_list_posts' ) ), 'connection', 'ab-fine', 'tools_saved' ),
			'write access off'            => array( 'handle_site_mode', array( '_wpnonce' => 'nonce-ab_mcp_site_mode', 'mode' => 'read' ), 'connection', '', 'mode_read' ),
			'write access, unknown value' => array( 'handle_site_mode', array( '_wpnonce' => 'nonce-ab_mcp_site_mode', 'mode' => 'x' ), 'connection', '', 'mode_unknown' ),
			'terms notice'                => array( 'handle_terms_notice_dismiss', array( '_wpnonce' => 'nonce-ab_mcp_terms_notice' ), 'connection', '', 'terms_notice_dismissed' ),
			'notice after the update'     => array( 'handle_mode_notice_dismiss', array( '_wpnonce' => 'nonce-ab_mcp_mode_notice' ), 'connection', '', 'mode_notice_dismissed' ),
			'rename a connection'         => array( 'handle_token', array( '_wpnonce' => 'nonce-ab_mcp_token', 'rename_token' => 'h', 'rename_label' => 'L' ), 'connection', '', 'saved' ),
			'delete a connection'         => array( 'handle_token', array( '_wpnonce' => 'nonce-ab_mcp_token', 'delete_token' => 'h' ), 'connection', '', 'token_deleted' ),
			'sign-in for Claude'          => array( 'handle_connection_settings', array( '_wpnonce' => 'nonce-ab_mcp_connection_settings', 'setting' => 'oauth_enabled', 'panel' => 'claude', 'oauth_enabled' => '1' ), 'connection', 'ab-oauth-claude', 'saved' ),
			'connector address'           => array( 'handle_connection_settings', array( '_wpnonce' => 'nonce-ab_mcp_connection_settings', 'setting' => 'connector_url_auth_enabled', 'panel' => 'cursor' ), 'connection', 'ab-url-auth', 'saved' ),
			'direct sign-in'              => array( 'handle_direct_signin', array( '_wpnonce' => 'nonce-ab_mcp_direct_signin', 'direct_signin' => 'off' ), 'connection', 'ab-oauth-chatgpt', 'saved' ),
			'firewall check'              => array( 'handle_firewall_check', array( '_wpnonce' => 'nonce-ab_mcp_firewall_check' ), 'connection', 'ab-oauth-chatgpt', 'checked' ),
			'connection check'            => array( 'handle_connection_check', array( '_wpnonce' => 'nonce-ab_mcp_connection_check' ), 'connection', 'ab-doctor', 'connection_checked' ),
			'clear the log'               => array( 'handle_audit_clear', array( '_wpnonce' => 'nonce-ab_mcp_audit_clear' ), 'connection', '', 'audit_cleared' ),
			'review, from the page'       => array( 'handle_review_dismiss', array( '_wpnonce' => 'nonce-ab_mcp_review_dismiss' ), 'connection', '', 'review_dismissed' ),
			'preview protection off'      => array( 'handle_preview_protection', array( '_wpnonce' => 'nonce-ab_mcp_preview_protection', 'preview' => 'off' ), 'staging', 'ab-preview', 'preview_off' ),
			'preview protection, other'   => array( 'handle_preview_protection', array( '_wpnonce' => 'nonce-ab_mcp_preview_protection', 'preview' => 'x' ), 'staging', 'ab-preview', 'saved' ),
			'role of this site'           => array( 'handle_staging_record', array( '_wpnonce' => 'nonce-ab_mcp_staging', 'staging_role' => 'production', 'partner_url' => 'https://staging.example.com/', 'host' => 'kinsta' ), 'staging', 'ab-staging', 'staging_saved' ),
			'role, a bad host'            => array( 'handle_staging_record', array( '_wpnonce' => 'nonce-ab_mcp_staging', 'staging_role' => 'production', 'host' => 'nowhere' ), 'staging', 'ab-staging', 'staging_bad_host' ),
			'staging first'               => array( 'handle_staging_first', array( '_wpnonce' => 'nonce-ab_mcp_staging_first', 'staging_first' => 'require' ), 'staging', 'ab-staging-first', 'staging_first_saved' ),
			'staging first, a connection' => array( 'handle_staging_connection', array( '_wpnonce' => 'nonce-ab_mcp_staging_connection', 'do' => 'release', 'hash' => 'unknown' ), 'staging', 'ab-staging-first', 'staging_connection_unknown' ),
		);
	}

	/** @param array<string,mixed> $post */
	#[DataProvider( 'forms' )]
	public function testEveryFormLandsWithItsAnchorAndNoticeOnItsPage( string $handler, array $post, string $page, string $anchor, string $notice ): void {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			( new AB_MCP_Admin( self::registry() ) )->$handler();
			self::fail( $handler . ' ended without a redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'redirect', $exit->kind, $exit->detail );
			self::assertSame(
				self::ADMIN . 'alphabridge-' . $page . '&ab_notice=' . $notice . ( '' !== $anchor ? '#' . $anchor : '' ),
				$exit->detail
			);
			self::assertSame( 302, $exit->status );
		}
	}

	public function testTheFormsOfTheCardAreTheOnesOnStaging(): void {
		$actions = array();
		foreach ( self::xpath( self::staging_page() )->query( '//form//input[@name="action"]' ) as $input ) {
			$actions[] = $input->getAttribute( 'value' );
		}
		$actions = array_values( array_unique( $actions ) );
		sort( $actions );
		self::assertSame( array( 'ab_mcp_preview_protection', 'ab_mcp_staging', 'ab_mcp_staging_first' ), $actions, 'The ones the data rows above send to «Staging» (the connections of «Staging first» show with a connection).' );
		$on_connection = array();
		foreach ( self::xpath( self::connection_page() )->query( '//form//input[@name="action"]' ) as $input ) {
			$on_connection[] = $input->getAttribute( 'value' );
		}
		self::assertSame( array(), array_intersect( $actions, $on_connection ), 'None of them on «AI Connection».' );
	}

	/* ------------------------------------------------------------- the pages */

	public function testAIConnectionCarriesTheSettingsPageButTheCard(): void {
		$GLOBALS['ab_test_actions'] = array();
		foreach ( array( 'ab_mcp_admin_main_boxes' => 'old-main', 'ab_mcp_admin_boxes_connection' => 'connection', 'ab_mcp_admin_side_boxes' => 'side', 'ab_mcp_admin_boxes_staging' => 'staging' ) as $hook => $id ) {
			add_action(
				$hook,
				static function () use ( $id ) {
					echo '<div id="addon-' . $id . '"></div>'; // phpcs:ignore
				}
			);
		}
		$html = self::connection_page();
		$x    = self::xpath( $html );

		self::assertSame( 1, $x->query( '//section[@id="ab-mode"]' )->length, 'The switch for write access.' );
		self::assertSame( 1, $x->query( '//h1[contains(@class, "ab-hero__brand")]' )->length );
		self::assertSame( 0, $x->query( '//*[@id="ab-staging-card"]' )->length, 'Without the card «Staging».' );
		self::assertSame( 1, $x->query( '//div[contains(@class, "ab-side")]//*[@id="addon-side"]' )->length, 'With the side column and its boxes.' );
		self::assertSame( 0, $x->query( '//*[@id="addon-staging"]' )->length );
		$connection = strpos( $html, 'id="addon-connection"' );
		$old        = strpos( $html, 'id="addon-old-main"' );
		self::assertIsInt( $connection );
		self::assertIsInt( $old );
		self::assertLessThan( $old, $connection, 'The hook of 4.16.0, then the one from before, in the same place.' );
		self::assertSame( 1, $x->query( '//div[contains(@class, "ab-main")]//*[@id="addon-old-main"]' )->length );
	}

	public function testStagingCarriesHeadingSentenceMarkerNoticeCardAndItsHook(): void {
		$_GET = array( 'ab_notice' => 'staging_saved' );
		foreach ( array( 'ab_mcp_admin_main_boxes' => 'old-main', 'ab_mcp_admin_boxes_connection' => 'connection', 'ab_mcp_admin_side_boxes' => 'side', 'ab_mcp_admin_boxes_staging' => 'staging' ) as $hook => $id ) {
			add_action(
				$hook,
				static function () use ( $id ) {
					echo '<div id="addon-' . $id . '"></div>'; // phpcs:ignore
				}
			);
		}
		$html = self::staging_page();
		$x    = self::xpath( $html );

		$wrap = $x->query( '/html/body/div' );
		self::assertSame( 1, $wrap->length );
		self::assertSame( 'wrap ab-mcp ab-mcp--staging', $wrap->item( 0 )->getAttribute( 'class' ) );
		$order = array();
		foreach ( $x->query( '/html/body/div/*' ) as $el ) {
			$order[] = $el->nodeName . ( $el->getAttribute( 'class' ) ? '.' . $el->getAttribute( 'class' ) : '' );
		}
		self::assertSame( array( 'h1', 'p.ab-lead', 'hr.wp-header-end', 'div.notice notice-success is-dismissible', 'div.ab-main' ), $order, 'Heading, one sentence, the marker, the notice, one column.' );
		self::assertSame( 'Staging', $x->query( '//h1' )->item( 0 )->textContent );
		$lead = trim( $x->query( '//p[@class="ab-lead"]' )->item( 0 )->textContent );
		self::assertSame( 'Try changes on a staging copy first: everything this site needs for that is set here.', $lead );
		self::assertSame( 1, preg_match_all( '/[.!?](\s|$)/', $lead ), 'One sentence.' );
		self::assertStringContainsString( 'Saved: the role of this site', $x->query( '//div[contains(@class, "notice")]' )->item( 0 )->textContent );

		$main = $x->query( '/html/body/div/div[@class="ab-main"]/*' );
		self::assertSame( 'ab-staging-card', $main->item( 0 )->getAttribute( 'id' ), 'The card first in the column.' );
		self::assertSame( 'addon-staging', $main->item( 1 )->getAttribute( 'id' ), 'Then the boxes of add-ons.' );
		self::assertSame( 2, $main->length );
		self::assertSame( 1, $x->query( '//section[@id="ab-staging-card"]//details[contains(@class, "ab-staging-card__fold")]' )->length, 'The card folds here too.' );

		foreach ( array( 'addon-old-main', 'addon-connection', 'addon-side', 'ab-mode', 'ab-connect', 'ab-fine', 'ab-caps', 'ab-connections', 'ab-log', 'ab-doctor' ) as $id ) {
			self::assertSame( 0, $x->query( '//*[@id="' . $id . '"]' )->length, $id . ' is on «AI Connection» only.' );
		}
		self::assertSame( 0, $x->query( '//*[contains(@class, "ab-side")]' )->length, 'No side column.' );
		self::assertSame( 0, $x->query( '//*[contains(@class, "ab-mode-notice")]' )->length, 'The notice after the update comes from admin_notices here (mode_notice()).' );
	}

	/** What tests/admin-js-test.mjs builds as the page «Staging» (runStagingPage()), here as the page draws it. */
	public function testThePageStagingCarriesWhatAdminJsUsesThere(): void {
		$x = self::xpath( self::staging_page() );
		foreach ( array(
			'//div[@class="wrap ab-mcp ab-mcp--staging"]/div[@class="ab-main"]/section[@id="ab-staging-card"]',
			'//section[@id="ab-staging-card"]/p[contains(@class, "ab-staging-card__state")]',
			'//section[@id="ab-staging-card"]/details[contains(@class, "ab-staging-card__fold")]/summary[@class="ab-staging-card__summary"]',
			'//details[contains(@class, "ab-staging-card__fold")]//div[@id="ab-staging-checklist"]',
			'//details[contains(@class, "ab-staging-card__fold")]//div[@id="ab-preview"]',
			'//div[@id="ab-preview"]//button[contains(@class, "ab-preview-ajax")][@data-preview="on"][@data-when="off"]',
			'//div[@id="ab-preview"]//button[contains(@class, "ab-preview-ajax")][@data-preview="password"][@data-when="on"]',
			'//div[@id="ab-preview"]//form[contains(@class, "ab-preview__off")][@data-when="on"]',
			'//div[@id="ab-preview"]//div[contains(@class, "ab-preview-reveal")][@hidden]//input[contains(@class, "ab-preview-password")]',
			'//div[@id="ab-preview"]//p[contains(@class, "ab-preview-status")]',
			'//div[@id="ab-preview"]//div[contains(@class, "ab-preview__loader")]//*[contains(@class, "ab-preview__loader-text")]',
			'//div[@id="ab-preview"]//div[contains(@class, "ab-preview__loader")]//textarea[contains(@class, "ab-preview__source")]',
		) as $path ) {
			self::assertSame( 1, $x->query( $path )->length, $path );
		}
		foreach ( array( '//*[@id="ab-mode"]', '//*[@id="ab-mode-confirm"]', '//*[@id="ab-mode-form"]', '//*[contains(@class, "ab-client")]', '//*[@data-panel]', '//*[@id="ab-connect"]', '//*[@id="ab-fine"]', '//*[@id="ab-caps"]', '//*[contains(@class, "ab-conn-rows")]' ) as $path ) {
			self::assertSame( 0, $x->query( $path )->length, $path . ': nothing of «AI Connection».' );
		}
	}

	public function testStagingAsksForTheRight(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		try {
			self::staging_page();
			self::fail( 'Shown without manage_options.' );
		} catch ( AbTestExit $exit ) {
			ob_end_clean();
			self::assertSame( 'die', $exit->kind );
		}
	}
}
