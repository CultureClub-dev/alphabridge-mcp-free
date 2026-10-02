<?php
/**
 * The box about AlphaBridge MCP Pro in the settings screen's side column.
 *
 * It sells a separately distributed plugin, inside the bounds WordPress.org
 * sets: only on this plugin's own settings page, no feature of THIS plugin
 * presented as locked, no tracking parameter in the links, nothing loaded from
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

	/**
	 * Every link in the box: its opening tag, its visible text and its query.
	 *
	 * @return array<int, array{tag: string, text: string, url: array<string, mixed>, query: array<string, string>}>
	 */
	private function links( string $html ): array {
		preg_match_all( '/(<a\s[^>]*>)(.*?)<\/a>/s', $html, $found, PREG_SET_ORDER );
		$links = array();
		foreach ( $found as $match ) {
			preg_match( '/href="([^"]*)"/', $match[1], $href );
			$url   = (array) parse_url( html_entity_decode( $href[1] ?? '', ENT_QUOTES ) );
			$query = array();
			parse_str( (string) ( $url['query'] ?? '' ), $query );
			// The screen-reader note «(opens in a new tab)» is not what the link says.
			$text    = trim( (string) preg_replace( '/<span class="screen-reader-text">.*?<\/span>/s', '', $match[2] ) );
			$links[] = array(
				'tag'   => $match[1],
				'text'  => $text,
				'url'   => $url,
				'query' => $query,
			);
		}
		return $links;
	}

	public function testAFreeSiteIsOfferedTheTrialAndBothPrices(): void {
		$html = $this->box();

		self::assertStringContainsString( 'AlphaBridge MCP Pro', $html );
		self::assertStringContainsString( '7 days free', $html );
		self::assertStringContainsString( 'No card needed', $html );
		self::assertSame(
			array( 'Try Pro free for 7 days', '$9/month', '$49/year' ),
			array_column( $this->links( $html ), 'text' ),
			'The trial first, then the monthly and the yearly price.'
		);
	}

	public function testEachLinkOpensTheCheckoutForWhatItSays(): void {
		$links = $this->links( $this->box() );

		self::assertCount( 3, $links );
		$expected = array(
			'Try Pro free for 7 days' => array(
				'trial'         => 'free',
				'billing_cycle' => 'monthly',
			),
			'$9/month'                => array( 'billing_cycle' => 'monthly' ),
			'$49/year'                => array( 'billing_cycle' => 'annual' ),
		);
		foreach ( $links as $link ) {
			self::assertSame( 'https', $link['url']['scheme'] ?? null, $link['text'] );
			self::assertSame( 'checkout.freemius.com', $link['url']['host'] ?? null, $link['text'] );
			self::assertSame( '/plugin/35076/plan/57642/', $link['url']['path'] ?? null, $link['text'] );
			// Only what opens — trial, monthly or yearly. A tracking or referral
			// parameter (WordPress.org guidelines 7 and 11) would show up here.
			self::assertSame( $expected[ $link['text'] ] ?? null, $link['query'], $link['text'] );
			self::assertStringContainsString( 'target="_blank"', $link['tag'] );
			self::assertStringContainsString( 'rel="noopener"', $link['tag'] );
		}
		self::assertStringContainsString( 'class="ab-pro__cta"', $links[0]['tag'], 'The trial is the button.' );
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
			self::assertStringContainsString( 'Try Pro free for 7 days', $this->box( $edition ), var_export( $edition, true ) );
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
		// prices it would promise something they do not buy.
		self::assertStringNotContainsStringIgnoringCase( 'deploy', $html );
		self::assertStringNotContainsString( 'SFTP', $html );
	}

	/**
	 * Pro keeps an undo point for 7 days by default, adjustable from 1 to 30
	 * (AB_MCP_Pro_Undo::TTL_DAYS_DEFAULT, _MIN, _MAX, integration/pro-4.6).
	 * The box and the readme say so, and not the former 24 hours.
	 */
	public function testTheUndoWindowIsTheOneProKeeps(): void {
		$html   = $this->box();
		$readme = (string) file_get_contents( AB_MCP_PATH . 'readme.txt' );

		self::assertStringContainsString( 'Undo points for 7 days, adjustable from 1 to 30', $html );
		self::assertStringContainsString( 'undo points are kept for 7 days by default, adjustable from 1 to 30 days', $readme );
		foreach ( array( 'box' => $html, 'readme.txt' => $readme ) as $where => $text ) {
			self::assertDoesNotMatchRegularExpression( '/undo[^.]*24 hours/i', $text, $where );
		}
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
