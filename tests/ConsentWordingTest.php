<?php
/**
 * The notice and the two boxes before write access goes on: what an
 * administrator agrees to (decision Thomas 07.10.2026, after the legal
 * review of the same day; the Terms of Use describe it in 1.7 and 4.3).
 *
 * What must hold:
 *
 * - The texts come from AB_MCP_Site_Mode::wordings(), never from gettext:
 *   a language pack from translate.wordpress.org wins over the bundled
 *   translations and could change a declaration. English and German only,
 *   as the Terms of Use; every other language reads English.
 * - German addresses the reader as du in de_DE, de_AT and the …_informal
 *   locales, as Sie in the other German ones, like the bundled files.
 * - No box states a fact («gelesen», «verstanden», «I have a backup»,
 *   «understood»): a pre-formulated confirmation of a fact is void
 *   (§ 309 Nr. 12 lit. b BGB). Both say what the administrator agrees to.
 * - The notice keeps «at your own risk» (decision 02.10.2026) next to the
 *   sentence that statutory rights and the liability rules of the terms
 *   remain unaffected.
 * - The second box names the provider and the version of the Terms of Use,
 *   as the date of TERMS_VERSION; the link goes to the German version for
 *   German.
 * - The second box is required without a paid licence and offered with
 *   one: the edition Pro reports (ab_mcp_site_edition) decides.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use AB_MCP_Site_Mode;

final class ConsentWordingTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	protected function tearDown(): void {
		$GLOBALS['ab_test_locale'] = 'en_US';
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function locales(): array {
		return array(
			'en_US'          => array( 'en_US', 'en' ),
			'en_GB'          => array( 'en_GB', 'en' ),
			'fr_FR'          => array( 'fr_FR', 'en' ),
			'it_IT'          => array( 'it_IT', 'en' ),
			'de_DE'          => array( 'de_DE', 'de_du' ),
			'de_AT'          => array( 'de_AT', 'de_du' ),
			'de_CH_informal' => array( 'de_CH_informal', 'de_du' ),
			'de_DE_formal'   => array( 'de_DE_formal', 'de_sie' ),
			'de_CH'          => array( 'de_CH', 'de_sie' ),
			'de'             => array( 'de', 'de_sie' ),
		);
	}

	#[DataProvider( 'locales' )]
	public function testEachLocaleReadsItsWording( string $locale, string $key ): void {
		self::assertSame( $key, AB_MCP_Site_Mode::wording_key( $locale ) );

		$GLOBALS['ab_test_locale'] = $locale;
		$notice                    = AB_MCP_Site_Mode::notice_text();
		$box                       = AB_MCP_Site_Mode::checkbox_text();
		$terms                     = AB_MCP_Site_Mode::terms_text();

		if ( 'en' === $key ) {
			self::assertSame( 'With write access, your AI assistants work directly on your live website. Changes take effect immediately: they can create, change and also delete content, files, settings and code, and not everything can be undone. You switch this on at your own risk. Your statutory rights and the liability rules of the Terms of Use remain unaffected. A current backup keeps you on the safe side.', $notice );
			self::assertSame( 'I agree: changes take effect immediately and can be destructive, not everything can be undone, and keeping a current backup is up to me.', $box );
			self::assertSame( 'I agree to the Terms of Use of CultureClub Kulturagentur UG (haftungsbeschränkt), version of 7 October 2026.', $terms );
			self::assertSame( 'https://alphabridge-mcp.com/terms', AB_MCP_Site_Mode::terms_url() );
			return;
		}
		self::assertSame( 'Ich bin einverstanden: Änderungen wirken sofort und können destruktiv sein, nicht alles lässt sich rückgängig machen, und für ein aktuelles Backup sorge ich selbst.', $box );
		self::assertSame( 'Ich bin mit den Nutzungsbedingungen der CultureClub Kulturagentur UG (haftungsbeschränkt), Fassung vom 7. Oktober 2026, einverstanden.', $terms );
		self::assertSame( 'https://alphabridge-mcp.com/terms#de', AB_MCP_Site_Mode::terms_url(), 'The German version of the terms.' );
		if ( 'de_sie' === $key ) {
			self::assertSame( 'Mit Schreibrechten arbeiten Ihre KI-Assistenten direkt auf Ihrer Live-Website. Änderungen wirken sofort: Sie können Inhalte, Dateien, Einstellungen und Code erstellen, ändern und auch löschen, und nicht alles lässt sich rückgängig machen. Sie schalten das auf eigenes Risiko ein. Ihre gesetzlichen Rechte und die Haftungsregeln der Nutzungsbedingungen bleiben unberührt. Ein aktuelles Backup gibt Ihnen Sicherheit.', $notice );
		} else {
			self::assertSame( 'Mit Schreibrechten arbeiten deine KI-Assistenten direkt auf deiner Live-Website. Änderungen wirken sofort: Sie können Inhalte, Dateien, Einstellungen und Code erstellen, ändern und auch löschen, und nicht alles lässt sich rückgängig machen. Du schaltest das auf eigenes Risiko ein. Deine gesetzlichen Rechte und die Haftungsregeln der Nutzungsbedingungen bleiben unberührt. Ein aktuelles Backup gibt dir Sicherheit.', $notice );
		}
	}

	/** @return array<int,string> Every text of every wording. */
	private static function all_texts(): array {
		$out = array();
		foreach ( array( 'en_US', 'de_DE', 'de_DE_formal' ) as $locale ) {
			$GLOBALS['ab_test_locale'] = $locale;
			$out[]                     = AB_MCP_Site_Mode::notice_text();
			$out[]                     = AB_MCP_Site_Mode::checkbox_text();
			$out[]                     = AB_MCP_Site_Mode::terms_text();
		}
		return $out;
	}

	/** A confirmation of a fact; «einverstanden» is none. */
	const FACT = '/\b(gelesen|verstanden|zur Kenntnis|bestätig\w*|habe ein|read and|have read|has read|understood|I have a|confirm\w*)\b/iu';

	public function testNoBoxConfirmsAFactBothSayWhatIsAgreed(): void {
		foreach ( self::all_texts() as $text ) {
			self::assertDoesNotMatchRegularExpression( self::FACT, $text );
		}
		foreach ( array( 'en_US' => 'I agree', 'de_CH' => 'Ich bin ' ) as $locale => $start ) {
			$GLOBALS['ab_test_locale'] = $locale;
			self::assertStringStartsWith( $start, AB_MCP_Site_Mode::checkbox_text() );
			self::assertStringStartsWith( $start, AB_MCP_Site_Mode::terms_text() );
		}
		// The counter-check: the wording of version 2 is caught, «einverstanden» is not.
		self::assertMatchesRegularExpression( self::FACT, 'Verstanden: Änderungen wirken sofort, ich schalte Schreiben auf eigenes Risiko ein und habe ein aktuelles Backup.' );
		self::assertMatchesRegularExpression( self::FACT, 'Understood: changes take effect immediately, I switch on write access at my own risk and I have a current backup.' );
		self::assertMatchesRegularExpression( self::FACT, 'Ich habe die Nutzungsbedingungen gelesen und bin einverstanden.' );
		self::assertDoesNotMatchRegularExpression( self::FACT, 'Ich bin einverstanden.' );
	}

	public function testTheNoticeKeepsTheRiskAndThatRightsRemainUnaffected(): void {
		$GLOBALS['ab_test_locale'] = 'en_US';
		self::assertStringContainsString( 'at your own risk', AB_MCP_Site_Mode::notice_text() );
		self::assertStringContainsString( 'Your statutory rights and the liability rules of the Terms of Use remain unaffected.', AB_MCP_Site_Mode::notice_text() );
		foreach ( array( 'de_DE', 'de_CH' ) as $locale ) {
			$GLOBALS['ab_test_locale'] = $locale;
			self::assertStringContainsString( 'auf eigenes Risiko', AB_MCP_Site_Mode::notice_text() );
			self::assertStringContainsString( 'gesetzlichen Rechte und die Haftungsregeln der Nutzungsbedingungen bleiben unberührt.', AB_MCP_Site_Mode::notice_text() );
		}
	}

	public function testTheSecondBoxNamesTheProviderAndTheVersionOfTheTerms(): void {
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', AB_MCP_Site_Mode::TERMS_VERSION );
		$months = array( 1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );
		self::assertNotFalse( $date, 'TERMS_VERSION is a date.' );
		self::assertSame( '2026-10-07', AB_MCP_Site_Mode::TERMS_VERSION, 'The version of the Terms of Use on the website (Stand 7. Oktober 2026).' );

		$GLOBALS['ab_test_locale'] = 'en_US';
		self::assertStringContainsString( 'CultureClub Kulturagentur UG (haftungsbeschränkt), version of ' . $date->format( 'j F Y' ) . '.', AB_MCP_Site_Mode::terms_text() );
		$GLOBALS['ab_test_locale'] = 'de_DE';
		self::assertStringContainsString( 'CultureClub Kulturagentur UG (haftungsbeschränkt), Fassung vom ' . $date->format( 'j' ) . '. ' . $months[ (int) $date->format( 'n' ) ] . ' ' . $date->format( 'Y' ) . ',', AB_MCP_Site_Mode::terms_text() );
	}

	public function testNoLanguagePackChangesTheWording(): void {
		// A language pack translates through gettext; these texts are not in
		// any gettext call, so whatever it holds for them changes nothing.
		$GLOBALS['ab_test_locale'] = 'en_US';
		$en                        = array( AB_MCP_Site_Mode::notice_text(), AB_MCP_Site_Mode::checkbox_text(), AB_MCP_Site_Mode::terms_text() );
		$GLOBALS['ab_test_locale'] = 'de_CH';
		$de                        = array( AB_MCP_Site_Mode::notice_text(), AB_MCP_Site_Mode::checkbox_text(), AB_MCP_Site_Mode::terms_text() );
		foreach ( $en as $text ) {
			$GLOBALS['ab_test_translations'][ $text ] = 'Ich habe alles gelesen.';
		}

		foreach ( array( 'fr_FR' => $en, 'de_CH' => $de ) as $locale => $want ) {
			$GLOBALS['ab_test_locale'] = $locale;
			self::assertSame( $want, array( AB_MCP_Site_Mode::notice_text(), AB_MCP_Site_Mode::checkbox_text(), AB_MCP_Site_Mode::terms_text() ), $locale );
		}

		// And no gettext call in the shipped code carries them.
		$code = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-site-mode.php' );
		foreach ( array_merge( $en, $de ) as $text ) {
			self::assertDoesNotMatchRegularExpression( '/\b(?:__|_x|esc_html__|esc_attr__)\(\s*\'' . preg_quote( substr( $text, 0, 40 ), '/' ) . '/', $code );
		}
	}

	public function testGermanNeedsNoSwissWording(): void {
		foreach ( self::all_texts() as $text ) {
			self::assertStringNotContainsString( 'ß', $text, 'Swiss German writes ss; with no ß de_CH reads the German wording as it is.' );
			self::assertStringNotContainsString( '„', $text );
		}
	}

	/** @return array<string,array{0:string,1:bool}> */
	public static function editions(): array {
		return array(
			'free'          => array( 'free', true ),
			'pro'           => array( 'pro', false ),
			'agency'        => array( 'agency', false ),
			'anything else' => array( 'enterprise', true ),
		);
	}

	#[DataProvider( 'editions' )]
	public function testTheTermsBoxIsRequiredWithoutAPaidLicence( string $edition, bool $required ): void {
		add_filter( 'ab_mcp_site_edition', static fn(): string => $edition );

		self::assertSame( $required, AB_MCP_Site_Mode::terms_required() );
	}

	public function testWithoutAFilterTheSiteIsFreeAndTheBoxRequired(): void {
		self::assertTrue( AB_MCP_Site_Mode::terms_required() );
	}
}
