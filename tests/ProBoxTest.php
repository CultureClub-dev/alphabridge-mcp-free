<?php
/**
 * The box about AlphaBridge MCP Pro in the settings screen's side column.
 *
 * It sells a separately distributed plugin, inside the bounds WordPress.org
 * sets: only on this plugin's own settings page, no feature of THIS plugin
 * presented as locked, no tracking parameter in the link, nothing loaded from
 * outside. A site that already runs Pro or Agency is not offered what it has.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use ReflectionMethod;

final class ProBoxTest extends TestCase {

	private const PRICING = 'https://www.alphabridge-mcp.com/#pricing';

	protected function setUp(): void {
		ab_test_reset();
	}

	/** The box as the site renders it, with the add-on reporting $edition (null: no add-on). */
	private function box( ?string $edition = null ): string {
		if ( null !== $edition ) {
			add_filter(
				'ab_mcp_site_edition',
				static function () use ( $edition ) {
					return $edition;
				}
			);
		}
		$method = new ReflectionMethod( AB_MCP_Admin::class, 'pro_box_html' );
		return (string) $method->invoke( new AB_MCP_Admin() );
	}

	public function testAFreeSiteIsOfferedProWithPriceAndTrial(): void {
		$html = $this->box();

		self::assertStringContainsString( 'AlphaBridge MCP Pro', $html );
		self::assertStringContainsString( 'class="ab-pro__cta"', $html );
		self::assertStringContainsString( 'Get Pro — $49/year', $html );
		self::assertStringContainsString( 'free for 7 days — no card needed', $html );
	}

	public function testEveryLinkLeadsToThePricesWithoutATrackingParameter(): void {
		$html = $this->box();

		preg_match_all( '/<a\s[^>]*>/', $html, $links );
		self::assertCount( 2, $links[0], 'The button and the trial line.' );
		foreach ( $links[0] as $link ) {
			self::assertStringContainsString( 'href="' . self::PRICING . '"', $link );
			self::assertStringContainsString( 'target="_blank"', $link );
			self::assertStringContainsString( 'rel="noopener"', $link );
		}
	}

	public function testASiteWithAPaidLicenceIsNotOfferedWhatItHas(): void {
		foreach ( array( 'pro', 'agency' ) as $edition ) {
			ab_test_reset();
			self::assertSame( '', $this->box( $edition ), $edition );
		}
	}

	/**
	 * Only a licence the add-on reports as valid hides the box. An expired one
	 * reports 'free', and anything else counts as free, as in the site's
	 * self-description.
	 */
	public function testAnyOtherEditionIsOfferedPro(): void {
		foreach ( array( 'free', '', 'enterprise', 'Pro' ) as $edition ) {
			ab_test_reset();
			self::assertStringContainsString( 'Get Pro — $49/year', $this->box( $edition ), var_export( $edition, true ) );
		}
	}

	public function testThisPluginIsNotPresentedAsLimited(): void {
		$html = $this->box();

		// WordPress.org guideline 9: nothing may imply that features of this
		// plugin must be paid for.
		self::assertStringContainsString( 'Everything in this free plugin is complete on its own', $html );
		self::assertStringNotContainsStringIgnoringCase( 'unlock', $html );
	}

	public function testTheListNamesOnlyWhatTheProPriceBuys(): void {
		$html = $this->box();

		// Site Deploy comes with Agency, not with Pro; listed beside the Pro
		// price it would promise something the button does not buy.
		self::assertStringNotContainsStringIgnoringCase( 'deploy', $html );
		self::assertStringNotContainsString( 'SFTP', $html );
	}

	public function testTheStylesheetLoadsOnThisScreenOnly(): void {
		$admin = new AB_MCP_Admin();

		$admin->enqueue_assets( 'index.php' );
		self::assertSame( array(), $GLOBALS['ab_test_styles'] );

		$admin->enqueue_assets( 'settings_page_alphabridge-mcp' );
		self::assertSame( AB_MCP_URL . 'assets/admin.css', $GLOBALS['ab_test_styles']['ab-mcp-admin'] ?? null );
	}

	public function testTheStylesheetLoadsNothingFromOutside(): void {
		$css = (string) file_get_contents( AB_MCP_PATH . 'assets/admin.css' );

		self::assertStringContainsString( '.ab-pro__cta', $css );
		self::assertStringNotContainsString( '@import', $css );
		self::assertStringNotContainsString( 'url(', $css );
		self::assertStringNotContainsString( 'http', $css );
	}
}
