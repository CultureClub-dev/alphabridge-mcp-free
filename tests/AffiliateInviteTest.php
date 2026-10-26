<?php
/**
 * The line in the settings screen's side column that invites to the affiliate
 * programme.
 *
 * It advertises inside the bounds WordPress.org sets: only on this plugin's own
 * settings page, a link without tracking to the programme's page on the product
 * website, and it says that the commission comes from Pro and Agency sales —
 * never from this free plugin. Where the Pro add-on shows the site's own
 * affiliate state, its box takes the line's place: an add-on answers the filter
 * `ab_mcp_affiliate_invite`, and an older Pro that hangs its affiliate box on the
 * side column without answering keeps the line away.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use ReflectionMethod;

final class AffiliateInviteTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	private function call( string $method ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		return (string) $m->invoke( new AB_MCP_Admin() );
	}

	private function edition( string $edition ): void {
		add_filter(
			'ab_mcp_site_edition',
			static function () use ( $edition ) {
				return $edition;
			}
		);
	}

	/**
	 * The one link: its opening tag, its visible text and its parsed address.
	 *
	 * @return array{tag: string, text: string, url: array<string, mixed>}
	 */
	private function link( string $html ): array {
		self::assertSame( 1, preg_match_all( '/(<a\s[^>]*>)(.*?)<\/a>/s', $html, $found, PREG_SET_ORDER ), 'Exactly one link.' );
		preg_match( '/href="([^"]*)"/', $found[0][1], $href );
		return array(
			'tag'  => $found[0][1],
			'text' => trim( (string) preg_replace( '/<span class="screen-reader-text">.*?<\/span>/s', '', $found[0][2] ) ),
			'url'  => (array) parse_url( html_entity_decode( $href[1] ?? '', ENT_QUOTES ) ),
		);
	}

	public function testAFreeSiteIsInvited(): void {
		$html = $this->call( 'affiliate_invite_html' );

		self::assertStringContainsString( 'class="ab-invite"', $html );
		self::assertStringContainsString( '<strong>Recommend AlphaBridge and profit.</strong>', $html );
		self::assertStringContainsString( 'As an affiliate you profit from every Pro and Agency purchase made through your link.', $html );
		self::assertSame( 'Become an affiliate', $this->link( $html )['text'] );
	}

	public function testTheCommissionIsNamedAsComingFromProAndAgencyOnly(): void {
		$text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $this->call( 'affiliate_invite_html' ) ) ) );

		self::assertStringContainsString( 'Pro and Agency purchase', $text );
		self::assertStringNotContainsString( 'free plugin', $text, 'The free plugin earns no commission; the line must not suggest it.' );
	}

	public function testTheLinkGoesToTheProgrammePageWithoutTracking(): void {
		$link = $this->link( $this->call( 'affiliate_invite_html' ) );

		self::assertSame( 'https', $link['url']['scheme'] ?? null );
		self::assertSame( 'www.alphabridge-mcp.com', $link['url']['host'] ?? null );
		self::assertSame( '/affiliates.html', $link['url']['path'] ?? null );
		self::assertArrayNotHasKey( 'query', $link['url'], 'No query, so no tracking parameter.' );
		self::assertArrayNotHasKey( 'fragment', $link['url'] );
		self::assertStringContainsString( 'target="_blank"', $link['tag'] );
		self::assertStringContainsString( 'rel="noopener"', $link['tag'] );
		self::assertStringContainsString( '(opens in a new tab)', $this->call( 'affiliate_invite_html' ) );
	}

	/**
	 * Decision of 26.10.2026: the line invites, it does not hold the rate under
	 * every customer's nose. No percentage, no amount, in no bundled language;
	 * the icon is decoration and hidden from screen readers.
	 */
	public function testTheLineNamesNoRate(): void {
		$html = $this->call( 'affiliate_invite_html' );

		self::assertMatchesRegularExpression( '/<span class="ab-invite__icon"><svg class="ab-ico ab-ico--gift"[^>]*aria-hidden="true"/', $html );
		self::assertDoesNotMatchRegularExpression( '/\d\s*%|%|\$\s*\d|\bpercent\b/i', strip_tags( $html ), 'The line names a rate.' );

		foreach ( glob( AB_MCP_PATH . 'languages/alphabridge-mcp-*.po' ) as $po ) {
			$source = (string) file_get_contents( $po );
			foreach ( array( 'Recommend AlphaBridge and profit.', 'As an affiliate you profit from every Pro and Agency purchase made through your link.' ) as $msgid ) {
				self::assertSame( 1, preg_match( '/^msgid "' . preg_quote( $msgid, '/' ) . '"\nmsgstr "(.+)"$/m', $source, $m ), basename( $po ) . ': not translated: ' . $msgid );
				self::assertDoesNotMatchRegularExpression( '/\d|%|\$/', $m[1], basename( $po ) . ': the translation names a rate.' );
			}
		}
	}

	public function testAnOlderProAffiliateBoxKeepsTheLineAway(): void {
		// Pro 4.5.12 and 4.5.13 hang their affiliate box on the side column and
		// do not answer the filter.
		add_action( 'ab_mcp_admin_side_boxes', 'ab_mcp_pro_aff_side_box' );

		self::assertSame( '', $this->call( 'affiliate_invite_html' ) );
	}

	public function testOtherSideBoxesDoNotKeepItAway(): void {
		add_action( 'ab_mcp_admin_side_boxes', 'some_other_side_box' );

		self::assertNotSame( '', $this->call( 'affiliate_invite_html' ) );
	}

	public function testTheAddOnDecidesThroughTheFilter(): void {
		add_action( 'ab_mcp_admin_side_boxes', 'ab_mcp_pro_aff_side_box' );
		$default = null;
		add_filter(
			'ab_mcp_affiliate_invite',
			static function ( $show ) use ( &$default ) {
				$default = $show;
				return true; // Pro 4.5.14 on a site without Freemius, e.g. with the owner unlock.
			}
		);
		self::assertNotSame( '', $this->call( 'affiliate_invite_html' ) );
		self::assertFalse( $default, 'The filter gets false as its default where an older Pro box hangs.' );

		ab_test_reset();
		add_filter( 'ab_mcp_affiliate_invite', '__return_false' );
		self::assertSame( '', $this->call( 'affiliate_invite_html' ), 'Pro shows its own box: no line.' );
	}

	public function testOrderInTheSideColumnOfAFreeSite(): void {
		$html = $this->call( 'sidebar_html' );

		$pro    = strpos( $html, 'class="postbox ab-pro"' );
		$invite = strpos( $html, 'class="ab-invite"' );
		$guides = strpos( $html, 'class="ab-card ab-guides"' );
		self::assertIsInt( $pro );
		self::assertIsInt( $invite );
		self::assertIsInt( $guides );
		self::assertTrue( $pro < $invite && $invite < $guides, 'Pro box, then the invitation, then the guides.' );
	}

	public function testOnASiteWithProTheLineTakesThePlaceOfTheProBox(): void {
		// The owner unlock and a Pro add-on that answers true: no Pro box, the line first.
		$this->edition( 'agency' );
		add_filter( 'ab_mcp_affiliate_invite', '__return_true' );
		$html = $this->call( 'sidebar_html' );

		self::assertStringNotContainsString( 'class="postbox ab-pro"', $html );
		self::assertStringStartsWith( '<div class="ab-side"><div class="ab-invite">', $html );
	}

	/**
	 * The readme answers the question in its FAQ with the same facts as the line
	 * in the settings: the sentence is read from the line itself, so a change
	 * there (editions, wording) fails here until the readme follows. Neither names
	 * a rate; that stands on the programme's page.
	 * The link goes to the same programme page, and the answer says that this free
	 * plugin earns none and needs no membership.
	 */
	public function testTheReadmeFaqNamesTheProgrammeWithTheSameFacts(): void {
		$readme = (string) file_get_contents( AB_MCP_PATH . 'readme.txt' );
		self::assertSame( 1, preg_match( '/^= Is there an affiliate programme\? =\n(.+)$/m', $readme, $m ), 'FAQ entry missing.' );
		$answer = $m[1];

		$invite = $this->call( 'affiliate_invite_html' );
		$text   = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $invite ) ) );
		self::assertSame( 1, preg_match( '/Recommend AlphaBridge and profit\. (.+?)\. Become an affiliate/', $text, $c ), 'The sentence about the programme not found in the line.' );
		self::assertStringContainsString( $c[1], $answer, 'The FAQ must say what the line says.' );
		self::assertDoesNotMatchRegularExpression( '/\d\s*%/', $answer, 'Like the line, the FAQ names no rate.' );

		$line = $this->link( $invite )['url'];
		$host = preg_replace( '/^www\./', '', (string) ( $line['host'] ?? '' ) );
		$path = preg_replace( '/\.html$/', '', (string) ( $line['path'] ?? '' ) );
		self::assertStringContainsString( 'https://' . $host . $path, $answer, 'The FAQ links to the page the line links to.' );

		self::assertStringContainsString( 'open worldwide to anyone over 18 who does not live in a country under a US embargo', $answer );
		self::assertStringContainsString( 'This free plugin itself earns none', $answer );
		self::assertLessThan( strpos( $readme, '== Screenshots ==' ), strpos( $readme, '= Is there an affiliate programme? =' ), 'The entry belongs to the FAQ.' );
	}
}
