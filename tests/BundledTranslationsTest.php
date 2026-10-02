<?php
/**
 * The German translations of the site mode, bundled in languages/.
 *
 * WordPress has no language pack of this plugin yet, and the notice an
 * administrator confirms before switching to Full must be readable in the
 * site's language from the release that brings it. So the plugin ships the
 * texts of the site mode for de_DE, de_DE_formal, de_AT, de_CH and
 * de_CH_informal, and loads them behind a language pack from
 * translate.wordpress.org, which wins for every string it has.
 *
 * What must hold:
 *
 * - Every locale has a .po and a compiled .mo with the same entries, and
 *   every msgid is a string the plugin's code really translates — a text
 *   changed in the code without its translation fails here.
 * - The notice, the box, the refusal, the notice after the update, the
 *   consent sentence and the two words of the mode are translated
 *   everywhere, with their placeholders.
 * - Swiss German writes ss, never ß, and «» for quotes; de_DE_formal and
 *   de_CH address the reader as Sie, the others as du.
 * - The language pack is asked for before the bundled file is added.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use AB_MCP_Site_Mode;

final class BundledTranslationsTest extends TestCase {

	const LOCALES = array( 'de_DE', 'de_DE_formal', 'de_AT', 'de_CH', 'de_CH_informal' );

	/** The texts that must read in German wherever a German locale is set. */
	const REQUIRED = array(
		'Full lets AI assistants create, change and delete content, files, settings and code on this live site. Switching it on can be destructive and is at your own risk. Make sure you have a current backup.',
		'I understand that switching to Full can be destructive and is at my own risk, and I have a current backup.',
		'AlphaBridge MCP is in read mode on this site; the tool "%s" writes. An administrator can switch to Full at the top of Settings → AlphaBridge MCP — switching it on can be destructive and is at the site owner\'s own risk.',
		'AlphaBridge MCP now only reads on this site.',
		'Since this update the plugin starts in the mode Read on every site: assistants can read, and every tool that creates, changes or deletes is refused. To let them write again, an administrator switches to Full at the top of Settings → AlphaBridge MCP — switching it on can be destructive and is at your own risk.',
		'This site is in read mode: whatever access level you choose, the connection can only read until an administrator switches AlphaBridge MCP to Full.',
		"site mode\x04Read",
		"site mode\x04Full",
		'Mode: %s',
		'Switch to Full',
		'Switch back to Read',
		'Fine-tuning (for advanced users)',
		'Full since %1$s, confirmed by %2$s',
	);

	protected function setUp(): void {
		ab_test_reset();
	}

	private static function file( string $locale, string $ext ): string {
		return dirname( __DIR__ ) . '/languages/alphabridge-mcp-' . $locale . '.' . $ext;
	}

	/**
	 * Entries of a compiled .mo file, the way WordPress reads it: key is the
	 * msgid, with "context\x04" in front where there is a context.
	 *
	 * @return array<string,string>
	 */
	private static function mo( string $path ): array {
		$data = (string) file_get_contents( $path );
		$magic = unpack( 'V', substr( $data, 0, 4 ) )[1];
		self::assertSame( 0x950412de, $magic, basename( $path ) . ' is a little-endian .mo file.' );
		$h       = unpack( 'Vrev/Vn/Vorig/Vtrans', substr( $data, 4, 16 ) );
		$entries = array();
		for ( $i = 0; $i < $h['n']; $i++ ) {
			$o = unpack( 'Vlen/Voff', substr( $data, $h['orig'] + 8 * $i, 8 ) );
			$t = unpack( 'Vlen/Voff', substr( $data, $h['trans'] + 8 * $i, 8 ) );
			$entries[ substr( $data, $o['off'], $o['len'] ) ] = substr( $data, $t['off'], $t['len'] );
		}
		unset( $entries[''] ); // The header.
		return $entries;
	}

	/**
	 * Entries of a .po file, keyed like mo().
	 *
	 * @return array<string,string>
	 */
	private static function po( string $path ): array {
		$entries = array();
		$unq     = static fn( string $s ): string => stripcslashes( substr( $s, 1, -1 ) );
		foreach ( preg_split( '/\n\s*\n/', (string) file_get_contents( $path ) ) as $block ) {
			$ctx = preg_match( '/^msgctxt (".*")$/m', $block, $m ) ? $unq( $m[1] ) . "\x04" : '';
			if ( ! preg_match( '/^msgid (".*")$/m', $block, $id ) || ! preg_match( '/^msgstr (".*")$/m', $block, $str ) ) {
				continue;
			}
			$key = $ctx . $unq( $id[1] );
			if ( '' !== $key ) {
				$entries[ $key ] = $unq( $str[1] );
			}
		}
		return $entries;
	}

	/**
	 * Every string literal in the shipped PHP, as PHP reads it.
	 *
	 * @return array<string,true>
	 */
	private static function literals(): array {
		$root = dirname( __DIR__ );
		$all  = array();
		$files = array_merge( glob( $root . '/*.php' ), glob( $root . '/includes/*.php' ), glob( $root . '/includes/*/*.php' ), glob( $root . '/includes/*/*/*.php' ) );
		foreach ( $files as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $t ) {
				if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
					$all[ eval( 'return ' . $t[1] . ';' ) ] = true; // phpcs:ignore Squiz.PHP.Eval -- a literal from our own source.
				}
			}
		}
		return $all;
	}

	/** @return array<string,array{0:string}> */
	public static function locales(): array {
		$out = array();
		foreach ( self::LOCALES as $l ) {
			$out[ $l ] = array( $l );
		}
		return $out;
	}

	#[DataProvider( 'locales' )]
	public function testTheCompiledFileHoldsWhatTheSourceSays( string $locale ): void {
		self::assertFileExists( self::file( $locale, 'po' ) );
		self::assertFileExists( self::file( $locale, 'mo' ) );

		$po = self::po( self::file( $locale, 'po' ) );
		$mo = self::mo( self::file( $locale, 'mo' ) );
		ksort( $po, SORT_STRING );
		ksort( $mo, SORT_STRING );
		self::assertGreaterThan( 20, count( $po ) );
		self::assertSame( $po, $mo, 'Compile the .po again: msgfmt -o alphabridge-mcp-' . $locale . '.mo alphabridge-mcp-' . $locale . '.po' );
	}

	#[DataProvider( 'locales' )]
	public function testEveryEntryIsATextTheCodeTranslates( string $locale ): void {
		$literals = self::literals();
		foreach ( array_keys( self::mo( self::file( $locale, 'mo' ) ) ) as $key ) {
			$text = false !== strpos( $key, "\x04" ) ? substr( $key, strpos( $key, "\x04" ) + 1 ) : $key;
			self::assertArrayHasKey( $text, $literals, 'Not in the code (changed or removed?): ' . $text );
		}
	}

	#[DataProvider( 'locales' )]
	public function testTheTextsOfTheModeAreTranslatedWithTheirPlaceholders( string $locale ): void {
		$mo = self::mo( self::file( $locale, 'mo' ) );
		foreach ( self::REQUIRED as $key ) {
			self::assertArrayHasKey( $key, $mo, $locale . ': ' . $key );
			self::assertNotSame( '', $mo[ $key ] );
			preg_match_all( '/%(\d\$)?s/', $key, $want );
			preg_match_all( '/%(\d\$)?s/', $mo[ $key ], $have );
			self::assertSame( $want[0], $have[0], $locale . ' keeps the placeholders of: ' . $key );
		}
		self::assertSame( 'Lesend', $mo["site mode\x04Read"] );
		self::assertSame( 'Full', $mo["site mode\x04Full"] );
		self::assertSame( array_keys( self::mo( self::file( 'de_DE', 'mo' ) ) ), array_keys( $mo ), 'Every locale translates the same texts.' );
	}

	public function testTheTextsInTheCodeAreTheOnesTranslated(): void {
		$mo = self::mo( self::file( 'de_DE', 'mo' ) );
		foreach ( array( AB_MCP_Site_Mode::notice_text(), AB_MCP_Site_Mode::checkbox_text(), AB_MCP_Site_Mode::consent_text(), AB_MCP_Site_Mode::refusal( '%s' )->get_error_message() ) as $text ) {
			self::assertArrayHasKey( $text, $mo );
		}
		self::assertSame( 'Full erlaubt KI-Assistenten, auf dieser Live-Website Inhalte, Dateien, Einstellungen und Code zu erstellen, zu ändern und zu löschen. Das Einschalten kann destruktiv sein und geschieht auf eigenes Risiko. Stelle sicher, dass du ein aktuelles Backup hast.', $mo[ AB_MCP_Site_Mode::notice_text() ] );
		self::assertSame( 'Ich verstehe, dass das Umschalten auf Full destruktiv sein kann und auf mein eigenes Risiko geschieht, und ich habe ein aktuelles Backup.', $mo[ AB_MCP_Site_Mode::checkbox_text() ] );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function swiss(): array {
		return array(
			'de_CH follows de_DE_formal'   => array( 'de_CH', 'de_DE_formal' ),
			'de_CH_informal follows de_DE' => array( 'de_CH_informal', 'de_DE' ),
		);
	}

	#[DataProvider( 'swiss' )]
	public function testSwissGermanWritesSsAndGuillemets( string $swiss, string $german ): void {
		$ch = self::mo( self::file( $swiss, 'mo' ) );
		$de = self::mo( self::file( $german, 'mo' ) );
		foreach ( $ch as $key => $text ) {
			self::assertStringNotContainsString( 'ß', $text, $swiss );
			self::assertStringNotContainsString( '„', $text, $swiss );
			self::assertSame( strtr( $de[ $key ], array( 'ß' => 'ss', '„' => '«', '“' => '»' ) ), $text, $swiss . ' says what ' . $german . ' says: ' . $key );
		}
		self::assertStringContainsString( '«%s»', $ch['AlphaBridge MCP is in read mode on this site; the tool "%s" writes. An administrator can switch to Full at the top of Settings → AlphaBridge MCP — switching it on can be destructive and is at the site owner\'s own risk.'] );
	}

	/** @return array<string,array{0:string,1:bool}> */
	public static function address(): array {
		return array(
			'de_DE'          => array( 'de_DE', false ),
			'de_AT'          => array( 'de_AT', false ),
			'de_CH_informal' => array( 'de_CH_informal', false ),
			'de_DE_formal'   => array( 'de_DE_formal', true ),
			'de_CH'          => array( 'de_CH', true ),
		);
	}

	#[DataProvider( 'address' )]
	public function testFormalLocalesSaySieTheOthersDu( string $locale, bool $formal ): void {
		$notice = self::mo( self::file( $locale, 'mo' ) )[ AB_MCP_Site_Mode::notice_text() ];

		self::assertStringContainsString( $formal ? 'Stellen Sie sicher, dass Sie' : 'Stelle sicher, dass du', $notice );
		foreach ( self::mo( self::file( $locale, 'mo' ) ) as $text ) {
			self::assertDoesNotMatchRegularExpression( $formal ? '/\b(du|dein\w*|dich|dir)\b/' : '/\b(Sie|Ihr\w*|Ihnen)\b/', $text, $locale );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheLanguagePackIsAskedForBeforeTheBundledFileIsAdded(): void {
		require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
		$GLOBALS['ab_test_locale'] = 'de_CH';

		self::assertTrue( \AB_MCP_Plugin::load_bundled_translations() );
		self::assertSame(
			array(
				array( 'pack', 'alphabridge-mcp' ),
				array( 'file', 'alphabridge-mcp', AB_MCP_DIR . 'languages/alphabridge-mcp-de_CH.mo', 'de_CH' ),
			),
			$GLOBALS['ab_test_i18n']
		);

		// switch_to_locale(): change_locale passes the new locale.
		$GLOBALS['ab_test_i18n'] = array();
		self::assertTrue( \AB_MCP_Plugin::load_bundled_translations( 'de_AT' ) );
		self::assertSame( AB_MCP_DIR . 'languages/alphabridge-mcp-de_AT.mo', $GLOBALS['ab_test_i18n'][1][2] );

		// Nothing bundled for this locale: nothing is loaded, the pack decides.
		$GLOBALS['ab_test_i18n']   = array();
		$GLOBALS['ab_test_locale'] = 'fr_FR';
		self::assertFalse( \AB_MCP_Plugin::load_bundled_translations() );
		self::assertSame( array(), $GLOBALS['ab_test_i18n'] );
	}

	public function testThePluginLoadsThemAndAgainAfterASwitchOfLocale(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );

		self::assertStringContainsString( 'self::load_bundled_translations();', $source );
		self::assertStringContainsString( "add_action( 'change_locale', array( __CLASS__, 'load_bundled_translations' ) );", $source );
	}
}
