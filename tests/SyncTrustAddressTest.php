<?php
/**
 * The addresses of the trust of the takeover (Z8, decisions 4 and 5; Codex
 * round 1 of branch 5, findings B-1 and B-2).
 *
 * What must hold:
 *
 * - Anchor and manifest compare addresses as AB_MCP_Sync_Key::site_form()
 *   gives them: an international domain name in its xn-- form (UTS #46,
 *   nontransitional) where PHP has intl, so bücher.example and
 *   xn--bcher-kva.example are one site in either direction and either case,
 *   while another port, path or scheme stays another site; without intl
 *   byte for byte, with a refusal that names the xn-- form; a name
 *   idn_to_ascii() does not take is no site. The key is fetched from the
 *   xn-- form of the stored address.
 * - This site reads its own address from home_url() as it is, for the
 *   target of a package and for the print of the pair: a name like
 *   straße, which normalize_url() breaks, is this site in either spelling;
 *   a name that cannot be read gives no print (Codex round 2, 1).
 * - The staging site is asked on the port of its stored address also where
 *   WordPress would not ask it (8443): for that one address and request
 *   only, once (a request started while it runs is not allowed it, even
 *   for the same address), and the port is taken off again in every case,
 *   an exception included. The fetcher asks nothing outside the stored
 *   address and nothing whose path has a segment . or .., also written as
 *   %2e (Codex round 2, 2 and 3).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Fetcher;
use AB_MCP_Sync_Key;
use AB_MCP_Sync_Manifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-manifest.php';
require_once __DIR__ . '/SyncTrustSites.php';

final class SyncTrustAddressTest extends TestCase {

	use SyncTrustSites;

	private const IP = '93.184.216.34';

	/** A host idn_to_ascii() does not take: a label of 64 characters. */
	private const UNREADABLE = 'https://üüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüüü.example';

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can']         = static fn(): bool => true;
		$GLOBALS['ab_test_http']        = array();
		$GLOBALS['ab_test_http_answer'] = null;
		ab_test_add_user( 3 );
		add_filter( 'ab_mcp_outbound_addresses', static fn( $found, string $host ) => array( self::IP ), 10, 2 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ab_test_home'] );
	}

	/**
	 * The two sites at these addresses, each with the other's address as the
	 * person entered it, and the production site with the key of the
	 * staging site prepared from its code (or the refusal).
	 *
	 * @return array|\WP_Error
	 */
	private function pair_at( string $live_home, string $live_says, string $staging_home, string $staging_says ) {
		$this->on( 'staging' );
		$GLOBALS['ab_test_home'] = $staging_home;
		self::assertIsArray( AB_MCP_Staging::save( 'staging', $staging_says, null, 3, 'form' ) );
		$code = AB_MCP_Sync_Key::pairing_code();
		self::assertIsString( $code );
		$this->on( 'live' );
		$GLOBALS['ab_test_home'] = $live_home;
		self::assertIsArray( AB_MCP_Staging::save( 'production', $live_says, null, 3, 'form' ) );
		return AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
	}

	/* ------------------------------------------------------- the form */

	#[RequiresPhpExtension( 'intl' )]
	public function testAnInternationalHostIsComparedInItsXnForm(): void {
		self::assertSame( 'https://xn--bcher-kva.example', AB_MCP_Sync_Key::site_form( 'https://bücher.example' ) );
		self::assertSame( 'https://xn--bcher-kva.example/Pfad', AB_MCP_Sync_Key::site_form( 'https://BÜCHER.example/Pfad/' ), 'The host in any case, the path as it is.' );
		self::assertSame( 'https://xn--bcher-kva.example:8443/x', AB_MCP_Sync_Key::site_form( 'https://bücher.example:8443/x' ), 'The port stays.' );
		self::assertSame( 'http://xn--bcher-kva.example', AB_MCP_Sync_Key::site_form( 'http://bücher.example' ), 'The scheme stays.' );
		self::assertSame( 'https://xn--strae-oqa.example', AB_MCP_Sync_Key::site_form( 'https://straße.example' ), 'Nontransitional: ß stays ß, as browsers resolve it.' );
		self::assertSame( 'https://xn--bcher-kva.example', AB_MCP_Sync_Key::site_form( 'https://xn--bcher-kva.example' ) );
		self::assertSame( 'https://staging.example.com', AB_MCP_Sync_Key::site_form( 'https://Staging.Example.com/' ), 'An ASCII host as normalize_url() stores it.' );
		self::assertSame( 'https://xn--zz.example', AB_MCP_Sync_Key::site_form( 'https://xn--zz.example' ), 'An ASCII host is taken as stored, not read as a name (idn_to_ascii() refuses this one).' );
		self::assertSame( 'https://[2001:db8::1]/x', AB_MCP_Sync_Key::site_form( 'https://[2001:db8::1]/x' ), 'A host in brackets as stored.' );
		self::assertSame( '', AB_MCP_Sync_Key::site_form( 'https://bü<cher.example' ), 'No address normalize_url() refuses, though idn_to_ascii() takes it.' );
		self::assertSame( '', AB_MCP_Sync_Key::site_form( self::UNREADABLE ), 'A name idn_to_ascii() does not take is no site.' );
		self::assertSame( '', AB_MCP_Sync_Key::site_form( 'no address' ) );
		self::assertSame( '', AB_MCP_Sync_Key::site_form( 'https://bücher.example', static fn(): bool => false ), 'Nor one a stand-in does not take.' );
	}

	public function testWithoutIntlAnInternationalHostIsComparedByteForByte(): void {
		self::assertSame( 'https://bücher.example', AB_MCP_Sync_Key::site_form( 'https://bücher.example', false ) );
		self::assertSame( 'https://bÜcher.example', AB_MCP_Sync_Key::site_form( 'https://BÜCHER.example', false ), 'Only ASCII in lower case: another spelling is another site.' );
		self::assertSame( 'https://xn--bcher-kva.example', AB_MCP_Sync_Key::site_form( 'https://xn--bcher-kva.example', false ) );

		$hint = AB_MCP_Sync_Key::idn_hint( 'https://bücher.example', 'https://xn--bcher-kva.example', false );
		self::assertStringContainsString( 'xn-- form (Punycode), here https://xn--bcher-kva.example.', $hint, 'The way names the xn-- form where one side has it.' );
		self::assertStringContainsString( 'PHP has no intl', $hint );
		self::assertStringContainsString( 'xn-- form (Punycode).', AB_MCP_Sync_Key::idn_hint( 'https://bücher.example', 'https://bÜcher.example', false ) );
		self::assertSame( '', AB_MCP_Sync_Key::idn_hint( 'https://a.example', 'https://b.example', false ), 'No international name: nothing to say.' );
		self::assertSame( '', AB_MCP_Sync_Key::idn_hint( 'https://bücher.example', 'https://xn--bcher-kva.example', 'idn_to_ascii' ), 'With intl the names were compared already.' );
	}

	/* ---------------------------------------------------- the anchor */

	/** @return array<string,array{0:string,1:string}> */
	public static function oneSiteTwoSpellings(): array {
		return array(
			'stored in Unicode, the key in xn--' => array( 'https://bücher.example', 'https://xn--bcher-kva.example' ),
			'stored in xn--, the key in Unicode' => array( 'https://xn--bcher-kva.example', 'https://bücher.example' ),
			'stored in capitals'                 => array( 'https://BüCHER.example', 'https://xn--bcher-kva.example' ),
			'ß, nontransitional'                 => array( 'https://xn--strae-oqa.example', 'https://straße.example' ),
		);
	}

	#[DataProvider( 'oneSiteTwoSpellings' )]
	#[RequiresPhpExtension( 'intl' )]
	public function testAnInternationalNameAnchorsInEitherSpelling( string $stored, string $staging_home ): void {
		$prepared = $this->pair_at( 'https://www.example.com', $stored, $staging_home, 'https://www.example.com' );
		self::assertIsArray( $prepared, is_object( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertIsArray( AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 3 ) );
		$this->on( 'staging' );
		$GLOBALS['ab_test_home'] = $staging_home;
		$signed                  = AB_MCP_Sync_Manifest::sign( self::package() );
		self::assertIsArray( $signed );
		$this->on( 'live' );
		self::assertIsArray( AB_MCP_Sync_Manifest::verify( $signed, array( 'purpose' => 'objects', 'report_hash' => str_repeat( 'c', 64 ) ) ), 'The source of the package compares the same way.' );
	}

	/** @return array<string,array{0:string}> */
	public static function otherSites(): array {
		return array(
			'another port'   => array( 'https://bücher.example:8443' ),
			'another path'   => array( 'https://bücher.example/blog' ),
			'another scheme' => array( 'http://bücher.example' ),
			'another name'   => array( 'https://buecher.example' ),
		);
	}

	#[DataProvider( 'otherSites' )]
	#[RequiresPhpExtension( 'intl' )]
	public function testAnotherPortPathSchemeOrNameIsAnotherSite( string $stored ): void {
		$refused = $this->pair_at( 'https://www.example.com', $stored, 'https://xn--bcher-kva.example', 'https://www.example.com' );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_sync_anchor_site', $refused->get_error_code() );
	}

	#[RequiresPhpExtension( 'intl' )]
	public function testANameNormalizeUrlChangedWhenItWasStoredIsRefusedWithTheWay(): void {
		// parse_url() turns the bytes 0x80 to 0x9F into "_": the ß of
		// straße.example is stored as another host (AB_MCP_Staging::normalize_url()).
		$refused = $this->pair_at( 'https://www.example.com', 'https://straße.example', 'https://straße.example', 'https://www.example.com' );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_sync_anchor_site', $refused->get_error_code() );
		self::assertStringContainsString( 'cannot read the international domain name as it is stored: store the address on both sites in its xn-- form (Punycode), here https://xn--strae-oqa.example.', $refused->get_error_message(), 'The staging site names itself in its xn-- form, and the way names it.' );
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://xn--strae-oqa.example', null, 3, 'form' );
		$this->on( 'staging' );
		$GLOBALS['ab_test_home'] = 'https://straße.example';
		$code                    = AB_MCP_Sync_Key::pairing_code();
		$this->on( 'live' );
		self::assertIsArray( AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 ), 'Stored in that form, it anchors.' );
	}

	/** A pairing code of the key of the staging site that names this site. */
	private function code_naming( string $site ): string {
		$this->on( 'staging' );
		$key = AB_MCP_Sync_Key::current();
		return base64_encode(
			(string) wp_json_encode(
				array(
					'format'     => AB_MCP_Sync_Key::FORMAT,
					'site'       => $site,
					'source_id'  => $key['source_id'],
					'key_id'     => $key['key_id'],
					'public_key' => $key['public_key'],
				),
				JSON_UNESCAPED_SLASHES
			)
		);
	}

	public function testANameThatCannotBeReadIsNoSiteEvenWhereBothSpellItAlike(): void {
		$this->paired();
		$code = $this->code_naming( self::UNREADABLE );
		self::assertIsArray( AB_MCP_Sync_Key::read_code( $code ), 'The code itself reads.' );
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', self::UNREADABLE, null, 3, 'form' );
		$refused = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_sync_anchor_site', $refused->get_error_code() );
	}

	public function testAStagingSiteThatCannotReadAnAddressSignsNothing(): void {
		$this->paired();
		$this->on( 'staging' );
		AB_MCP_Staging::save( 'staging', self::UNREADABLE, null, 3, 'form' );
		self::assertSame( 'ab_mcp_sync_manifest_address', AB_MCP_Sync_Manifest::sign( self::package() )->get_error_code(), 'The production site.' );
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		$GLOBALS['ab_test_home'] = self::UNREADABLE;
		self::assertSame( 'ab_mcp_sync_manifest_address', AB_MCP_Sync_Manifest::sign( self::package() )->get_error_code(), 'This site.' );
		self::assertNull( AB_MCP_Sync_Key::answer(), 'Nor does it name itself.' );
	}

	#[RequiresPhpExtension( 'intl' )]
	public function testTheKeyIsFetchedFromTheXnFormOfTheStoredAddress(): void {
		$this->on( 'staging' );
		$GLOBALS['ab_test_home'] = 'https://bücher.example';
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		$body = (string) wp_json_encode( AB_MCP_Sync_Key::answer() );
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://bücher.example', null, 3, 'form' );
		$url                            = 'https://xn--bcher-kva.example/?rest_route=/alphabridge/v1/staging-key';
		$GLOBALS['ab_test_http_answer'] = static fn( string $asked ) => $url === $asked ? ab_test_http_response( 200, $body ) : ab_test_http_response( 404, '' );
		$prepared                       = AB_MCP_Sync_Anchor::prepare_from_partner( 3 );
		self::assertIsArray( $prepared, is_object( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertSame( array( $url ), array_column( $GLOBALS['ab_test_http'], 'url' ) );
	}

	/* -------------------------------------------------- the manifest */

	#[RequiresPhpExtension( 'intl' )]
	public function testThePackageIsForThisSiteInEitherSpelling(): void {
		$this->on( 'staging' );
		AB_MCP_Staging::save( 'staging', 'https://xn--bcher-kva.example', null, 3, 'form' );
		$code = AB_MCP_Sync_Key::pairing_code();
		$this->on( 'live' );
		$GLOBALS['ab_test_home'] = 'https://bücher.example';
		AB_MCP_Staging::save( 'production', 'https://staging.example.com', null, 3, 'form' );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 3 );
		$signed = $this->signed();
		$this->on( 'live' );
		$GLOBALS['ab_test_home'] = 'https://bücher.example';
		$expect                  = array(
			'purpose'     => 'objects',
			'report_hash' => str_repeat( 'c', 64 ),
		);
		self::assertIsArray( AB_MCP_Sync_Manifest::verify( $signed, $expect ) );
		$GLOBALS['ab_test_home'] = 'https://bücher.example:8443';
		self::assertSame( 'ab_mcp_sync_manifest_target', AB_MCP_Sync_Manifest::verify( $signed, $expect )->get_error_code(), 'Another port is another site.' );

		// A staging site without intl names the target in Unicode; this site
		// reads its own name in the xn-- form.
		$unicode                 = $this->resigned( \AB_MCP_Sync_Canon::encode( array_merge( json_decode( $signed['manifest'], true ), array( 'target' => 'https://bücher.example' ) ) ) );
		$this->on( 'live' );
		$GLOBALS['ab_test_home'] = 'https://xn--bcher-kva.example';
		self::assertIsArray( AB_MCP_Sync_Manifest::verify( $unicode, $expect ), 'The target in either spelling.' );
	}

	#[RequiresPhpExtension( 'intl' )]
	public function testThisSiteReadsItsOwnNameAsHomeUrlGivesIt(): void {
		// ß is the bytes C3 9F, of which parse_url() breaks the second.
		$this->on( 'staging' );
		AB_MCP_Staging::save( 'staging', 'https://xn--strae-oqa.example', null, 3, 'form' );
		$code = AB_MCP_Sync_Key::pairing_code();
		$this->on( 'live' );
		$GLOBALS['ab_test_home'] = 'https://straße.example';
		AB_MCP_Staging::save( 'production', 'https://staging.example.com', null, 3, 'form' );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 3 );
		$signed = $this->signed();
		$this->on( 'live' );
		$GLOBALS['ab_test_home'] = 'https://straße.example';
		$expect                  = array(
			'purpose'     => 'objects',
			'report_hash' => str_repeat( 'c', 64 ),
		);
		$read                    = AB_MCP_Sync_Manifest::verify( $signed, $expect );
		self::assertIsArray( $read, is_object( $read ) ? $read->get_error_message() : '' );
		self::assertSame( 'https://xn--strae-oqa.example', $read['manifest']['target'], 'Signed for the xn-- form, read by the site at the Unicode name.' );

		$print = AB_MCP_Sync_Anchor::pair_print();
		self::assertIsString( $print, 'The print of the pair, with the name read.' );
		$GLOBALS['ab_test_home'] = 'https://xn--strae-oqa.example';
		self::assertSame( $print, AB_MCP_Sync_Anchor::pair_print(), 'The same site in either spelling.' );
		$GLOBALS['ab_test_home'] = 'https://strasse.example';
		self::assertNotSame( $print, AB_MCP_Sync_Anchor::pair_print(), 'strasse is another name.' );
	}

	#[RequiresPhpExtension( 'intl' )]
	public function testASiteWhoseOwnNameCannotBeReadHasNoPrint(): void {
		$this->paired();
		$this->anchored();
		$this->on( 'live' );
		self::assertIsString( AB_MCP_Sync_Anchor::pair_print() );
		$GLOBALS['ab_test_home'] = self::UNREADABLE;
		self::assertNull( AB_MCP_Sync_Anchor::pair_print() );
	}

	public function testAnUnreadableNameIsNoTargetAndNoSourceEvenWhereBothSpellItAlike(): void {
		$this->paired();
		$active = $this->anchored();
		$data   = json_decode( $this->signed()['manifest'], true );
		$expect = array(
			'purpose'     => 'objects',
			'report_hash' => str_repeat( 'c', 64 ),
		);

		// For a site whose own name cannot be read, spelled alike.
		$this->on( 'live' );
		$GLOBALS['ab_test_home'] = self::UNREADABLE;
		$signed                  = $this->resigned( \AB_MCP_Sync_Canon::encode( array_merge( $data, array( 'target' => self::UNREADABLE ) ) ) );
		$this->on( 'live' );
		$GLOBALS['ab_test_home'] = self::UNREADABLE;
		self::assertSame( 'ab_mcp_sync_manifest_target', AB_MCP_Sync_Manifest::verify( $signed, $expect )->get_error_code() );

		// From a staging site whose name cannot be read, stored alike, past
		// hold(), which refuses it.
		$signed = $this->resigned( \AB_MCP_Sync_Canon::encode( array_merge( $data, array( 'source' => self::UNREADABLE ) ) ) );
		$this->on( 'live' );
		$raw = get_option( 'ab_mcp_options' );
		$raw['staging']['pair']['partner_url'] = self::UNREADABLE;
		update_option( 'ab_mcp_options', $raw );
		update_option(
			AB_MCP_Sync_Anchor::OPTION,
			array(
				'format' => AB_MCP_Sync_Anchor::FORMAT,
				'active' => array_merge( $active, array( 'partner_url' => self::UNREADABLE ) ),
			)
		);
		self::assertNotNull( AB_MCP_Sync_Anchor::active() );
		self::assertSame( 'ab_mcp_sync_manifest_source', AB_MCP_Sync_Manifest::verify( $signed, $expect )->get_error_code() );
	}

	/* ------------------------------------------------------ the port */

	public function testTheStagingSiteIsAskedOnThePortOfItsStoredAddressOnly(): void {
		$this->on( 'staging' );
		$GLOBALS['ab_test_home'] = 'https://staging.example.com:8443/blog';
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		$body = (string) wp_json_encode( AB_MCP_Sync_Key::answer() );
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com:8443/blog', null, 3, 'form' );
		$url                            = 'https://staging.example.com:8443/blog/?rest_route=/alphabridge/v1/staging-key';
		$seen                           = array();
		// Another address asked before the check of the request (while its
		// host is looked up), which uses nothing up.
		add_filter(
			'ab_mcp_outbound_addresses',
			static function ( $found ) use ( &$seen ) {
				$seen['other'] = apply_filters( 'http_allowed_safe_ports', array( 80, 443, 8080 ), 'staging.example.com', 'https://staging.example.com:8443/elsewhere' );
				return $found;
			},
			20
		);
		// What the check of the request gets, after the filter of get_partner().
		$watch = static function ( $ports, $host, $asked ) use ( &$seen ) {
			$seen['checked'][ $asked ][] = $ports;
			return $ports;
		};
		add_filter( 'http_allowed_safe_ports', $watch, 20, 3 );
		$GLOBALS['ab_test_http_answer'] = static function ( string $asked ) use ( $url, $body ) {
			return $url === $asked ? ab_test_http_response( 200, $body ) : ab_test_http_response( 404, '' );
		};
		$prepared                       = AB_MCP_Sync_Anchor::prepare_from_partner( 3 );
		self::assertIsArray( $prepared, is_object( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertSame( array( $url ), array_column( $GLOBALS['ab_test_http'], 'url' ) );
		self::assertSame( array( array( 80, 443, 8080, 8443 ) ), $seen['checked'][ $url ], 'Its port, for that address.' );
		self::assertSame( array( 80, 443, 8080 ), $seen['other'], 'Not for another address.' );
		remove_filter( 'http_allowed_safe_ports', $watch, 20 );
		self::assertFalse( has_filter( 'http_allowed_safe_ports' ), 'And taken off again.' );
	}

	public function testARequestStartedWhileTheFirstRunsIsNotAllowedThePort(): void {
		$this->on( 'staging' );
		$GLOBALS['ab_test_home'] = 'https://staging.example.com:8443/blog';
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		$body = (string) wp_json_encode( AB_MCP_Sync_Key::answer() );
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com:8443/blog', null, 3, 'form' );
		$url    = 'https://staging.example.com:8443/blog/?rest_route=/alphabridge/v1/staging-key';
		$nested = null;
		// A hook that runs while the request is under way asks the same
		// address again (once, whatever it gets).
		$GLOBALS['ab_test_http_answer'] = static function ( string $asked ) use ( $url, $body, &$nested ) {
			if ( null === $nested ) {
				$nested = false;
				$nested = wp_safe_remote_get( $url );
			}
			return $url === $asked ? ab_test_http_response( 200, $body ) : ab_test_http_response( 404, '' );
		};
		$prepared                       = AB_MCP_Sync_Anchor::prepare_from_partner( 3 );
		self::assertIsArray( $prepared, is_object( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertInstanceOf( \WP_Error::class, $nested, 'The second request is not allowed the port.' );
		self::assertSame( 'A valid URL was not provided.', $nested->get_error_message() );
		self::assertSame( array( $url ), array_column( $GLOBALS['ab_test_http'], 'url' ), 'Only the first was sent.' );
		self::assertFalse( has_filter( 'http_allowed_safe_ports' ) );
	}

	public function testWithoutAPortNothingIsAddedAndAnotherFiltersAnswerStays(): void {
		$this->paired();
		$this->on( 'live' );
		$seen                           = null;
		$GLOBALS['ab_test_http_answer'] = static function ( string $asked ) use ( &$seen ) {
			$seen = apply_filters( 'http_allowed_safe_ports', array( 80, 443, 8080 ), 'staging.example.com', $asked );
			return ab_test_http_response( 404, '' );
		};
		( new AB_MCP_Sync_Fetcher() )->get( 'https://staging.example.com/x' );
		self::assertSame( array( 80, 443, 8080 ), $seen, 'The port of the scheme needs nothing added.' );

		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com:8443', null, 3, 'form' );
		add_filter( 'http_allowed_safe_ports', static fn() => false, 5 );
		$answer = ( new AB_MCP_Sync_Fetcher() )->get( 'https://staging.example.com:8443/x' );
		self::assertSame( 'http_request_failed', $answer->get_error_code(), 'A filter before that allows no port at all is left as it answered.' );
	}

	public function testThePortIsTakenOffAlsoWhenTheRequestFails(): void {
		$this->paired();
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com:8443', null, 3, 'form' );
		$GLOBALS['ab_test_http_answer'] = static function () {
			throw new \RuntimeException( 'transport' );
		};
		try {
			( new AB_MCP_Sync_Fetcher() )->get( 'https://staging.example.com:8443/x' );
			self::fail( 'No exception.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'transport', $e->getMessage() );
		}
		self::assertFalse( has_filter( 'http_allowed_safe_ports' ) );
	}

	public function testTheFetcherAsksNothingOutsideTheStoredAddress(): void {
		$this->paired();
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com:8443/blog', null, 3, 'form' );
		foreach ( array( 'https://other.example.com/x', 'https://staging.example.com/blog/x', 'https://staging.example.com:8443/blogx', 'https://staging.example.com:8443/blog' ) as $url ) {
			self::assertSame( 'ab_mcp_sync_not_partner', ( new AB_MCP_Sync_Fetcher() )->get( $url )->get_error_code(), $url );
		}
		AB_MCP_Staging::save( 'unconfirmed', '', null, 3, 'form' );
		self::assertSame( 'ab_mcp_sync_not_partner', ( new AB_MCP_Sync_Fetcher() )->get( 'https://staging.example.com:8443/blog/x' )->get_error_code(), 'Nothing without a stored staging site.' );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
	}

	/** @return array<string, array{string}> */
	public static function dotSegments(): array {
		return array(
			'..'             => array( '/blog/../outside' ),
			'%2e%2e'         => array( '/blog/%2e%2e/outside' ),
			'.%2e'           => array( '/blog/.%2e/x' ),
			'.'              => array( '/blog/./x' ),
			'%2E'            => array( '/blog/%2E/x' ),
			'.. at the end'  => array( '/blog/x/..' ),
			'.. before a ?'  => array( '/blog/x/..?rest_route=/x' ),
		);
	}

	#[DataProvider( 'dotSegments' )]
	public function testAPathWithASegmentDotOrDotDotIsRefused( string $path ): void {
		$this->paired();
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com:8443/blog', null, 3, 'form' );
		$answer = ( new AB_MCP_Sync_Fetcher() )->get( 'https://staging.example.com:8443' . $path );
		self::assertInstanceOf( \WP_Error::class, $answer );
		self::assertSame( 'ab_mcp_sync_dot_segment', $answer->get_error_code() );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'Nothing asked.' );
	}

	public function testDotsThatAreNoSegmentOfThePathAreAsked(): void {
		$this->paired();
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com:8443/blog', null, 3, 'form' );
		$GLOBALS['ab_test_http_answer'] = static fn() => ab_test_http_response( 404, '' );
		$urls                           = array(
			'https://staging.example.com:8443/blog/.well-known/x',
			'https://staging.example.com:8443/blog/x../y',
			'https://staging.example.com:8443/blog/x?next=/../y',
		);
		foreach ( $urls as $url ) {
			$answer = ( new AB_MCP_Sync_Fetcher() )->get( $url );
			self::assertIsArray( $answer, $url . ': ' . ( is_object( $answer ) ? $answer->get_error_message() : '' ) );
		}
		self::assertSame( $urls, array_column( $GLOBALS['ab_test_http'], 'url' ) );
	}
}
