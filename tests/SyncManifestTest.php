<?php
/**
 * The signed manifest of a package (Z8, decision 5 of the plan of
 * 01.11.2026; AB_MCP_Sync_Manifest), between the staging site that signs it
 * and the production site that checks it (two sites of their own,
 * SyncTrustSites).
 *
 * What must hold (the plan, «Prüfung → Vertrauen»):
 *
 * - The staging site signs only as the confirmed staging site, for the
 *   production site it is paired with, with its key, and only a manifest
 *   with every field as the format has it.
 * - The production site reads a manifest only with a key a person
 *   confirmed: not with a prepared one, not with none; one signed by
 *   another key, naming another key id or source, or changed on the way is
 *   refused, as is one whose target is not exactly this site's address
 *   (path, port, scheme), whose source is not exactly the stored staging
 *   site, that expired or comes from too far in the future, that is used for
 *   another purpose, another report or another package.
 * - Only the one spelling the staging site writes is read; the envelope is
 *   the text and its signature; every field and limit is checked, each with
 *   a case, and the limits themselves pass.
 * - Each chunk is read only against its size and hash (a swapped chunk is
 *   refused), the whole against the hash of the package.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Key;
use AB_MCP_Sync_Manifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../includes/sync/class-sync-manifest.php';
require_once __DIR__ . '/SyncTrustSites.php';
require_once __DIR__ . '/WrongForms.php';
require_once __DIR__ . '/../includes/class-store-inputs.php';

final class SyncManifestTest extends TestCase {

	use SyncTrustSites;

	private const REPORT = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ab_test_home'] );
	}

	/** The production site checks a signed manifest for objects of REPORT. */
	private function verify( array $signed, array $expect = array( 'purpose' => 'objects', 'report_hash' => self::REPORT ), ?int $now = null ) {
		$this->on( 'live' );
		return AB_MCP_Sync_Manifest::verify( $signed, $expect, $now );
	}

	private static function refused( $result, string $code, ?string $field = null ): void {
		self::assertInstanceOf( WP_Error::class, $result, 'Refused.' );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		if ( null !== $field ) {
			self::assertSame( $field, $result->get_error_data()['field'] );
		}
	}

	/** The decoded manifest of a signed one. */
	private static function data( array $signed ): array {
		return json_decode( $signed['manifest'], true );
	}

	/* ------------------------------------------------------------- read */

	public function testAManifestSignedOnTheStagingSiteIsReadOnTheProductionSite(): void {
		$this->paired();
		$this->anchored();
		$this->on( 'staging' );
		$key    = AB_MCP_Sync_Key::current();
		$signed = $this->signed( array(), 1800000000 );
		$read   = $this->verify( $signed, array( 'purpose' => 'objects', 'report_hash' => self::REPORT, 'package_id' => str_repeat( 'ab', 16 ) ), 1800000000 );
		self::assertIsArray( $read, is_object( $read ) ? $read->get_error_message() : '' );
		self::assertSame( hash( 'sha256', $signed['manifest'] ), $read['hash'] );
		$m = $read['manifest'];
		self::assertSame( AB_MCP_Sync_Manifest::FIELDS, array_keys( $m ), 'Every field, in the order of the one spelling.' );
		self::assertSame( 'ab-sync/1', $m['format'] );
		self::assertSame( 'canon/1', $m['canon'] );
		self::assertSame( $key['source_id'], $m['source_id'] );
		self::assertSame( $key['key_id'], $m['key_id'] );
		self::assertSame( 'https://staging.example.com', $m['source'] );
		self::assertSame( 'https://www.example.com', $m['target'] );
		self::assertSame( 1800000000, $m['created'] );
		self::assertSame( 1800000000 + 86400, $m['expires'] );
		self::assertSame( '6.9.1', $m['wordpress'], 'The version of WordPress of the staging site ($wp_version), for the report (branch 6, part 2).' );
		self::assertSame( AB_MCP_Sync_Canon::encode( self::package() ), AB_MCP_Sync_Canon::encode( array_intersect_key( $m, self::package() ) ), 'What the maker named, as named.' );
		self::assertSame( AB_MCP_Sync_Canon::encode( $m ), $signed['manifest'] );

		$inventory = $this->signed( array( 'purpose' => 'inventory', 'report_hash' => null ) );
		self::assertIsArray( $this->verify( $inventory, array( 'purpose' => 'inventory' ) ), 'Inventory and env are for no report.' );
	}

	/**
	 * V5 of Z8b branch 4: a manifest is signed and read only with a time of
	 * the one checked clock (AB_MCP_Sync_Run::now(), period.clock); a clock
	 * that gives none of its form signs and reads nothing, never a time made
	 * up. The driver of the reader AB_MCP_Sync_Manifest::clock with every
	 * wrong form of period.clock (StoreClockAndReadersTest).
	 */
	public function testNoTimeOfTheClockSignsOrReadsAPackage(): void {
		require_once __DIR__ . '/../includes/sync/class-sync-run.php';
		$this->paired();
		$this->anchored();
		$this->on( 'staging' );
		$signed = $this->signed( array(), 1800000000 );
		$before = \AB_MCP_Sync_Run::$clock;
		$wrong  = \AlphaBridge\Tests\WrongForms::of( \AB_MCP_Store_Inputs::TABLE['period.clock']['form'], 1800000100 ) + array( 'fixed: zero' => 0 );
		try {
			foreach ( $wrong as $what => $value ) {
				\AB_MCP_Sync_Run::$clock = static fn() => $value;
				$this->on( 'staging' );
				self::refused( AB_MCP_Sync_Manifest::sign( self::package() ), 'ab_mcp_sync_manifest_clock' );
				self::refused( $this->verify( $signed ), 'ab_mcp_sync_manifest_clock' );
				self::assertNotNull( $what );
			}
			\AB_MCP_Sync_Run::$clock = static fn() => 1800000100;
			$this->on( 'staging' );
			self::assertIsArray( AB_MCP_Sync_Manifest::sign( self::package() ) );
			self::assertIsArray( $this->verify( $signed ), 'The clock read within the day of the package.' );
			\AB_MCP_Sync_Run::$clock = static fn() => 1800000000 + 86400;
			self::refused( $this->verify( $signed ), 'ab_mcp_sync_manifest_expired' );
		} finally {
			\AB_MCP_Sync_Run::$clock = $before;
		}
		self::refused( AB_MCP_Sync_Manifest::verify( $signed, array( 'purpose' => 'objects', 'report_hash' => self::REPORT ), '1800000100' ), 'ab_mcp_sync_manifest_clock', null );
	}

	public function testAPackageOfCodeIsForOneReport(): void {
		$this->paired();
		$this->anchored();
		$this->on( 'staging' );
		$signed = $this->signed( array( 'purpose' => 'code' ), 1800000000 );
		self::assertIsArray( $this->verify( $signed, array( 'purpose' => 'code', 'report_hash' => self::REPORT ), 1800000000 ) );
		self::refused( $this->verify( $signed, array( 'purpose' => 'code', 'report_hash' => str_repeat( 'd', 64 ) ), 1800000000 ), 'ab_mcp_sync_manifest_report' );
		self::refused( $this->verify( $signed, array( 'purpose' => 'media', 'report_hash' => self::REPORT ), 1800000000 ), 'ab_mcp_sync_manifest_purpose' );
		$this->on( 'staging' );
		self::refused( AB_MCP_Sync_Manifest::sign( self::package( array( 'purpose' => 'code', 'report_hash' => null ) ) ), 'ab_mcp_sync_manifest_shape', 'report_hash' );
	}

	public function testOnlyTheConfirmedStagingSiteSignsForItsPairWithItsKey(): void {
		$this->paired();
		$this->on( 'live' );
		self::refused( AB_MCP_Sync_Manifest::sign( self::package() ), 'ab_mcp_sync_manifest_role' );
		$this->on( 'staging' );
		AB_MCP_Staging::save( 'staging', '', null, 3, 'form' );
		self::refused( AB_MCP_Sync_Manifest::sign( self::package() ), 'ab_mcp_sync_manifest_pair' );
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		delete_option( AB_MCP_Sync_Key::OPTION_SECRET );
		self::refused( AB_MCP_Sync_Manifest::sign( self::package() ), 'ab_mcp_sync_key_missing' );
		AB_MCP_Sync_Key::make();

		$package = self::package();
		unset( $package['unpacked'] );
		self::refused( AB_MCP_Sync_Manifest::sign( $package ), 'ab_mcp_sync_manifest_shape', 'unpacked' );
		self::refused( AB_MCP_Sync_Manifest::sign( self::package() + array( 'note' => 'x' ) ), 'ab_mcp_sync_manifest_shape', 'note' );
		self::refused( AB_MCP_Sync_Manifest::sign( self::package( array( 'target' => 'https://other.example.com' ) ) ), 'ab_mcp_sync_manifest_shape', 'target' );
		self::refused( AB_MCP_Sync_Manifest::sign( self::package( array( 'objects' => '1' ) ) ), 'ab_mcp_sync_manifest_shape', 'objects' );
		self::refused(
			AB_MCP_Sync_Manifest::sign(
				self::package(
					array(
						'kinds' => array(
							array(
								'kind'     => 'post',
								'subtype'  => "pa\xffge",
								'complete' => true,
							),
						),
					)
				)
			),
			'ab_mcp_sync_manifest_shape',
			'kinds'
		);
		self::assertIsArray( AB_MCP_Sync_Manifest::sign( self::package() ) );
	}

	public function testAManifestIsReadOnlyWithAKeyAPersonConfirmed(): void {
		$this->paired();
		$signed = $this->signed();
		self::refused( $this->verify( $signed ), 'ab_mcp_sync_manifest_no_anchor' );
		$code = $this->code();
		$this->on( 'live' );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		self::refused( $this->verify( $signed ), 'ab_mcp_sync_manifest_unconfirmed', null );
		AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 3 );
		self::assertIsArray( $this->verify( $signed ) );
	}

	/* ------------------------------------------------------ the key, signed */

	public function testAManifestSignedWithAnotherKeyIsRefused(): void {
		$this->paired();
		$this->anchored();
		$signed = $this->signed();
		$other  = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		self::refused( $this->verify( $this->resigned( $signed['manifest'], $other ) ), 'ab_mcp_sync_manifest_signature' );

		$m           = self::data( $signed );
		$m['key_id'] = AB_MCP_Sync_Key::key_id( sodium_crypto_sign_publickey_from_secretkey( $other ) );
		self::refused( $this->verify( $this->resigned( AB_MCP_Sync_Canon::encode( $m ), $other ) ), 'ab_mcp_sync_manifest_key', null );
		$m              = self::data( $signed );
		$m['source_id'] = str_repeat( 'd', 32 );
		self::refused( $this->verify( $this->resigned( AB_MCP_Sync_Canon::encode( $m ) ) ), 'ab_mcp_sync_manifest_key', null );
	}

	public function testAPackageWithTheKeyOfAnOlderAnchorIsRefused(): void {
		$this->paired();
		$this->anchored();
		$old = $this->signed();
		self::assertIsArray( $this->verify( $old ) );
		$this->on( 'staging' );
		AB_MCP_Staging::save( 'production', 'https://www.example.com', null, 3, 'form' );
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		$new = $this->signed();
		self::refused( $this->verify( $new ), 'ab_mcp_sync_manifest_key', null );
		$this->anchored();
		self::assertIsArray( $this->verify( $new ) );
		$refused = $this->verify( $old );
		self::refused( $refused, 'ab_mcp_sync_manifest_key', null );
		self::assertSame( self::data( $old )['key_id'], $refused->get_error_data()['key_id'] );
	}

	public function testAManifestChangedOnTheWayIsRefused(): void {
		$this->paired();
		$this->anchored();
		$signed = $this->signed();
		self::refused( $this->verify( array( 'manifest' => str_replace( '"objects":1', '"objects":2', $signed['manifest'] ), 'signature' => $signed['signature'] ) ), 'ab_mcp_sync_manifest_signature' );
		self::refused( $this->verify( array( 'manifest' => $signed['manifest'], 'signature' => rtrim( $signed['signature'], '=' ) ) ), 'ab_mcp_sync_manifest_signature', null );
		self::refused( $this->verify( array( 'manifest' => $signed['manifest'], 'signature' => base64_encode( str_repeat( 'x', 63 ) ) ) ), 'ab_mcp_sync_manifest_signature' );
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
		$last     = strlen( rtrim( $signed['signature'], '=' ) ) - 1;
		$loose    = substr( $signed['signature'], 0, $last ) . $alphabet[ strpos( $alphabet, $signed['signature'][ $last ] ) + 1 ] . substr( $signed['signature'], $last + 1 );
		self::assertSame( base64_decode( $signed['signature'] ), base64_decode( $loose, true ), 'The same signature, spelled with bits for nothing.' );
		self::refused( $this->verify( array( 'manifest' => $signed['manifest'], 'signature' => $loose ) ), 'ab_mcp_sync_manifest_signature' );
		self::refused( $this->verify( array( 'manifest' => $signed['manifest'], 'signature' => ' ' . $signed['signature'] ) ), 'ab_mcp_sync_manifest_signature' );
	}

	/* ------------------------------------------------- for and from whom */

	/** @return array<string,array{0:string}> */
	public static function otherTargets(): array {
		return array(
			'another path'   => array( 'https://www.example.com/blog' ),
			'another port'   => array( 'https://www.example.com:8443' ),
			'another scheme' => array( 'http://www.example.com' ),
			'another host'   => array( 'https://example.com' ),
		);
	}

	#[DataProvider( 'otherTargets' )]
	public function testAManifestForAnotherAddressThanThisSiteIsRefused( string $target ): void {
		$this->paired();
		$this->anchored();
		$this->on( 'staging' );
		AB_MCP_Staging::save( 'staging', $target, null, 3, 'form' );
		$this->anchored();
		$refused = $this->verify( $this->signed() );
		self::refused( $refused, 'ab_mcp_sync_manifest_target', null );
		self::assertSame( $target, $refused->get_error_data()['target'] );
	}

	public function testTheTargetIsComparedAsNormalizeUrlStoresTheAddress(): void {
		$this->paired();
		$this->anchored();
		$signed = $this->signed();
		$this->on( 'live' );
		$GLOBALS['ab_test_home'] = 'https://WWW.example.com:443/';
		self::assertIsArray( AB_MCP_Sync_Manifest::verify( $signed, array( 'purpose' => 'objects', 'report_hash' => self::REPORT ) ), 'The same address, written another way.' );
		$GLOBALS['ab_test_home'] = 'not an address';
		self::refused( AB_MCP_Sync_Manifest::verify( $signed, array( 'purpose' => 'objects', 'report_hash' => self::REPORT ) ), 'ab_mcp_sync_manifest_target', null );
	}

	public function testAManifestFromAnotherAddressThanTheStagingSiteIsRefused(): void {
		$this->paired();
		$this->anchored();
		$this->on( 'staging' );
		$GLOBALS['ab_test_home'] = 'https://staging.example.com:8443';
		$signed                  = AB_MCP_Sync_Manifest::sign( self::package() );
		$refused                 = $this->verify( $signed );
		self::refused( $refused, 'ab_mcp_sync_manifest_source', null );
		self::assertSame( 'https://staging.example.com:8443', $refused->get_error_data()['source'] );
	}

	/* --------------------------------------------------------------- time */

	public function testAManifestIsReadFor24HoursFromWhenItWasMade(): void {
		$this->paired();
		$this->anchored();
		$signed = $this->signed( array(), 1800000000 );
		self::assertIsArray( $this->verify( $signed, array( 'purpose' => 'objects', 'report_hash' => self::REPORT ), 1800000000 + 86399 ) );
		self::refused( $this->verify( $signed, array( 'purpose' => 'objects', 'report_hash' => self::REPORT ), 1800000000 + 86400 ), 'ab_mcp_sync_manifest_expired' );
		self::assertIsArray( $this->verify( $signed, array( 'purpose' => 'objects', 'report_hash' => self::REPORT ), 1800000000 - 300 ), 'Made up to five minutes later than the clock here says.' );
		self::refused( $this->verify( $signed, array( 'purpose' => 'objects', 'report_hash' => self::REPORT ), 1800000000 - 301 ), 'ab_mcp_sync_manifest_future' );
	}

	/* -------------------------------------------------------- what for */

	public function testAPackageIsReadOnlyForItsPurposeItsReportAndItself(): void {
		$this->paired();
		$this->anchored();
		$signed = $this->signed();
		self::refused( $this->verify( $signed, array( 'purpose' => 'objects', 'report_hash' => str_repeat( 'd', 64 ) ) ), 'ab_mcp_sync_manifest_report' );
		self::refused( $this->verify( $signed, array( 'purpose' => 'objects' ) ), 'ab_mcp_sync_manifest_report' );
		self::refused( $this->verify( $signed, array( 'purpose' => 'objects', 'report_hash' => 5 ) ), 'ab_mcp_sync_manifest_report' );
		self::refused( $this->verify( $signed, array( 'purpose' => 'media', 'report_hash' => self::REPORT ) ), 'ab_mcp_sync_manifest_purpose' );
		self::refused( $this->verify( $signed, array( 'report_hash' => self::REPORT ) ), 'ab_mcp_sync_manifest_purpose' );
		self::refused( $this->verify( $signed, array( 'purpose' => 'objects', 'report_hash' => self::REPORT, 'package_id' => str_repeat( 'cd', 16 ) ) ), 'ab_mcp_sync_manifest_package' );
		$media = $this->signed( array( 'purpose' => 'media' ) );
		self::refused( $this->verify( $media, array( 'purpose' => 'media', 'report_hash' => str_repeat( 'd', 64 ) ) ), 'ab_mcp_sync_manifest_report', null );
		self::assertIsArray( $this->verify( $media, array( 'purpose' => 'media', 'report_hash' => self::REPORT ) ) );
		$env = $this->signed( array( 'purpose' => 'env', 'report_hash' => null ) );
		self::assertIsArray( $this->verify( $env, array( 'purpose' => 'env', 'report_hash' => str_repeat( 'd', 64 ) ) ), 'A package for no report is read for its purpose.' );
	}

	/* ------------------------------------------------------------ chunks */

	public function testEachChunkIsReadOnlyAgainstItsSizeAndHash(): void {
		$this->paired();
		$this->anchored();
		$a      = 'first chunk';
		$b      = 'other chunk';
		$signed = $this->signed(
			array(
				'chunks' => array(
					array(
						'bytes'  => strlen( $a ),
						'sha256' => hash( 'sha256', $a ),
					),
					array(
						'bytes'  => strlen( $b ),
						'sha256' => hash( 'sha256', $b ),
					),
				),
				'bytes'  => strlen( $a . $b ),
				'sha256' => hash( 'sha256', $a . $b ),
			)
		);
		$m = $this->verify( $signed )['manifest'];
		self::assertTrue( AB_MCP_Sync_Manifest::chunk( $m, 0, $a ) );
		self::assertTrue( AB_MCP_Sync_Manifest::chunk( $m, 1, $b ) );
		self::refused( AB_MCP_Sync_Manifest::chunk( $m, 0, $b ), 'ab_mcp_sync_chunk_hash', null );
		self::refused( AB_MCP_Sync_Manifest::chunk( $m, 1, $a ), 'ab_mcp_sync_chunk_hash', null );
		self::refused( AB_MCP_Sync_Manifest::chunk( $m, 0, $a . 'x' ), 'ab_mcp_sync_chunk_hash', null );
		self::refused( AB_MCP_Sync_Manifest::chunk( $m, 0, null ), 'ab_mcp_sync_chunk_hash', null );
		self::refused( AB_MCP_Sync_Manifest::chunk( $m, 0, array( $a ) ), 'ab_mcp_sync_chunk_hash', null );
		self::refused( AB_MCP_Sync_Manifest::chunk( $m, 2, $a ), 'ab_mcp_sync_chunk_index', null );
		self::refused( AB_MCP_Sync_Manifest::chunk( $m, '0', $a ), 'ab_mcp_sync_chunk_index', null );
		self::assertTrue( AB_MCP_Sync_Manifest::whole( $m, hash( 'sha256', $a . $b ) ) );
		self::refused( AB_MCP_Sync_Manifest::whole( $m, hash( 'sha256', $b . $a ) ), 'ab_mcp_sync_package_hash', null );
		self::refused( AB_MCP_Sync_Manifest::whole( $m, null ), 'ab_mcp_sync_package_hash', null );
	}

	public function testAChunkOfTheSameSizeWithOtherBytesIsRefused(): void {
		$this->paired();
		$this->anchored();
		$m = $this->verify( $this->signed() )['manifest'];
		self::refused( AB_MCP_Sync_Manifest::chunk( $m, 0, 'chunK' ), 'ab_mcp_sync_chunk_hash', null );
	}

	/* --------------------------------------------------------- spelling */

	/** @return array<string,array{0:callable}> */
	public static function otherSpellings(): array {
		return array(
			'white space'              => array( static fn( string $t ): string => (string) json_encode( json_decode( $t, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ),
			'escaped slashes'          => array( static fn( string $t ): string => (string) json_encode( json_decode( $t, true ) ) ),
			'keys in another order'    => array( static fn( string $t ): string => (string) json_encode( array_reverse( json_decode( $t, true ), true ), JSON_UNESCAPED_SLASHES ) ),
			'a key twice'              => array( static fn( string $t ): string => '{"objects":7,' . substr( $t, 1 ) ),
			'a line break at the end'  => array( static fn( string $t ): string => $t . "\n" ),
			'a number as a float'      => array( static fn( string $t ): string => str_replace( '"objects":1', '"objects":1.0', $t ) ),
			'an escaped character'     => array( static fn( string $t ): string => str_replace( '"format":"ab-sync/1"', '"format":"ab\\u002dsync/1"', $t ) ),
		);
	}

	#[DataProvider( 'otherSpellings' )]
	public function testOnlyTheOneSpellingIsReadEvenWithAValidSignature( callable $respell ): void {
		$this->paired();
		$this->anchored();
		$text  = $this->signed()['manifest'];
		$other = $respell( $text );
		self::assertNotSame( $text, $other );
		self::refused( $this->verify( $this->resigned( $other ) ), 'ab_mcp_sync_manifest_spelling' );
	}

	/** @return array<string,array{0:mixed}> */
	public static function badEnvelopes(): array {
		return array(
			'no array'          => array( 'manifest' ),
			'no signature'      => array( array( 'manifest' => '{}' ) ),
			'a key more'        => array(
				array(
					'manifest'  => '{}',
					'signature' => 'x',
					'note'      => 'x',
				),
			),
			'manifest as array' => array(
				array(
					'manifest'  => array(),
					'signature' => 'x',
				),
			),
			'empty manifest'    => array(
				array(
					'manifest'  => '',
					'signature' => 'x',
				),
			),
			'too long'          => array(
				array(
					'manifest'  => str_repeat( ' ', 1048577 ),
					'signature' => 'x',
				),
			),
			'signature no text' => array(
				array(
					'manifest'  => '{}',
					'signature' => 5,
				),
			),
		);
	}

	public function testAManifestThatIsNoObjectIsNotRead(): void {
		$this->paired();
		$this->anchored();
		foreach ( array( '5', '"manifest"', 'null', 'true' ) as $text ) {
			self::refused( $this->verify( $this->resigned( $text ) ), 'ab_mcp_sync_manifest_spelling' );
		}
	}

	#[DataProvider( 'badEnvelopes' )]
	public function testTheEnvelopeIsTheTextAndItsSignatureOnly( $envelope ): void {
		$this->paired();
		$this->anchored();
		$this->on( 'live' );
		self::refused( AB_MCP_Sync_Manifest::verify( $envelope, array( 'purpose' => 'objects', 'report_hash' => self::REPORT ) ), 'ab_mcp_sync_manifest_envelope' );
	}

	/* ----------------------------------------------------------- fields */

	/** A list of n kinds of post, ascending. */
	private static function kinds( int $n ): array {
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$out[] = array(
				'complete' => true,
				'kind'     => 'post',
				'subtype'  => sprintf( 's%04d', $i ),
			);
		}
		return $out;
	}

	/** n chunks of the given bytes each, as the manifest names them, with their sum. */
	private static function chunks( int $n, int $bytes ): array {
		return array(
			'chunks' => array_fill(
				0,
				$n,
				array(
					'bytes'  => $bytes,
					'sha256' => str_repeat( 'e', 64 ),
				)
			),
			'bytes'  => $n * $bytes,
		);
	}

	/** @return array<string,array{0:callable,1:string,2:?string}> */
	public static function badFields(): array {
		$mb = 1048576;
		return array(
			'a field missing'               => array( static fn( array $m ): array => array_diff_key( $m, array( 'unpacked' => 1 ) ), 'ab_mcp_sync_manifest_shape', 'unpacked' ),
			'a field more'                  => array( static fn( array $m ): array => $m + array( 'note' => 'x' ), 'ab_mcp_sync_manifest_shape', 'note' ),
			'another format'                => array( static fn( array $m ): array => array_merge( $m, array( 'format' => 'ab-sync/2' ) ), 'ab_mcp_sync_manifest_format', null ),
			'another canon'                 => array( static fn( array $m ): array => array_merge( $m, array( 'canon' => 'canon/2' ) ), 'ab_mcp_sync_canon_version', null ),
			'a canon that is none'          => array( static fn( array $m ): array => array_merge( $m, array( 'canon' => 'canon/x' ) ), 'ab_mcp_sync_manifest_shape', 'canon' ),
			'a source id in capitals'       => array( static fn( array $m ): array => array_merge( $m, array( 'source_id' => strtoupper( $m['source_id'] ) ) ), 'ab_mcp_sync_manifest_shape', 'source_id' ),
			'a source id as a list'         => array( static fn( array $m ): array => array_merge( $m, array( 'source_id' => array( $m['source_id'] ) ) ), 'ab_mcp_sync_manifest_shape', 'source_id' ),
			'a canon as a list'             => array( static fn( array $m ): array => array_merge( $m, array( 'canon' => array( 'canon/1' ) ) ), 'ab_mcp_sync_manifest_shape', 'canon' ),
			'a hash as a list'              => array( static fn( array $m ): array => array_merge( $m, array( 'sha256' => array( $m['sha256'] ) ) ), 'ab_mcp_sync_manifest_shape', 'sha256' ),
			'a kind as a list'              => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( array_merge( $m['kinds'][0], array( 'kind' => array( 'post' ) ) ) ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'a short key id'                => array( static fn( array $m ): array => array_merge( $m, array( 'key_id' => substr( $m['key_id'], 1 ) ) ), 'ab_mcp_sync_manifest_shape', 'key_id' ),
			'a source not as stored'        => array( static fn( array $m ): array => array_merge( $m, array( 'source' => $m['source'] . '/' ) ), 'ab_mcp_sync_manifest_shape', 'source' ),
			'no target'                     => array( static fn( array $m ): array => array_merge( $m, array( 'target' => '' ) ), 'ab_mcp_sync_manifest_shape', 'target' ),
			'a target not as stored'        => array( static fn( array $m ): array => array_merge( $m, array( 'target' => 'HTTPS://www.example.com' ) ), 'ab_mcp_sync_manifest_shape', 'target' ),
			'a package id of 31'            => array( static fn( array $m ): array => array_merge( $m, array( 'package_id' => str_repeat( 'a', 31 ) ) ), 'ab_mcp_sync_manifest_shape', 'package_id' ),
			'an unknown purpose'            => array( static fn( array $m ): array => array_merge( $m, array( 'purpose' => 'everything' ) ), 'ab_mcp_sync_manifest_shape', 'purpose' ),
			'created as text'               => array( static fn( array $m ): array => array_merge( $m, array( 'created' => (string) $m['created'] ) ), 'ab_mcp_sync_manifest_shape', 'created' ),
			'created at 0'                  => array( static fn( array $m ): array => array_merge( $m, array( 'created' => 0, 'expires' => 86400 ) ), 'ab_mcp_sync_manifest_shape', 'created' ),
			'objects as text'               => array( static fn( array $m ): array => array_merge( $m, array( 'objects' => '1' ) ), 'ab_mcp_sync_manifest_shape', 'objects' ),
			'too many objects'              => array( static fn( array $m ): array => array_merge( $m, array( 'objects' => 5001 ) ), 'ab_mcp_sync_manifest_shape', 'objects' ),
			'fewer than no objects'         => array( static fn( array $m ): array => array_merge( $m, array( 'objects' => -1 ) ), 'ab_mcp_sync_manifest_shape', 'objects' ),
			'too much unpacked'             => array( static fn( array $m ): array => array_merge( $m, array( 'unpacked' => 256 * $mb + 1 ) ), 'ab_mcp_sync_manifest_shape', 'unpacked' ),
			'less than nothing unpacked'    => array( static fn( array $m ): array => array_merge( $m, array( 'unpacked' => -1 ) ), 'ab_mcp_sync_manifest_shape', 'unpacked' ),
			'a hash in capitals'            => array( static fn( array $m ): array => array_merge( $m, array( 'sha256' => strtoupper( $m['sha256'] ) ) ), 'ab_mcp_sync_manifest_shape', 'sha256' ),
			'objects without their report'  => array( static fn( array $m ): array => array_merge( $m, array( 'report_hash' => null ) ), 'ab_mcp_sync_manifest_shape', 'report_hash' ),
			'an inventory with a report'    => array( static fn( array $m ): array => array_merge( $m, array( 'purpose' => 'inventory' ) ), 'ab_mcp_sync_manifest_shape', 'report_hash' ),
			'expires a second later'        => array( static fn( array $m ): array => array_merge( $m, array( 'expires' => $m['expires'] + 1 ) ), 'ab_mcp_sync_manifest_shape', 'expires' ),
			'expires as text'               => array( static fn( array $m ): array => array_merge( $m, array( 'expires' => (string) $m['expires'] ) ), 'ab_mcp_sync_manifest_shape', 'expires' ),
			'kinds as a map'                => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( 'post' => $m['kinds'][0] ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'kinds as text'                 => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => 'post' ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'a kind without complete'       => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( array_diff_key( $m['kinds'][0], array( 'complete' => 1 ) ) ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'complete as text'              => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( array_merge( $m['kinds'][0], array( 'complete' => 'yes' ) ) ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'a kind in capitals'            => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( array_merge( $m['kinds'][0], array( 'kind' => 'Post' ) ) ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'a subtype not text'            => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( array_merge( $m['kinds'][0], array( 'subtype' => 5 ) ) ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'a subtype with a line break'   => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( array_merge( $m['kinds'][0], array( 'subtype' => "pa\nge" ) ) ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'a subtype of 192 characters'   => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( array_merge( $m['kinds'][0], array( 'subtype' => str_repeat( 'ä', 192 ) ) ) ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'kinds out of order'            => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array_reverse( self::kinds( 2 ) ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'kinds out of order by kind'    => array(
				static fn( array $m ): array => array_merge(
					$m,
					array(
						'kinds' => array(
							array_merge( $m['kinds'][0], array( 'kind' => 'term', 'subtype' => 'a' ) ),
							array_merge( $m['kinds'][0], array( 'kind' => 'post', 'subtype' => 'z' ) ),
						),
					)
				),
				'ab_mcp_sync_manifest_shape',
				'kinds',
			),
			'a kind twice'                  => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( $m['kinds'][0], $m['kinds'][0] ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'too many kinds'                => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => self::kinds( 2001 ) ) ), 'ab_mcp_sync_manifest_shape', 'kinds' ),
			'chunks as text'                => array( static fn( array $m ): array => array_merge( $m, array( 'chunks' => 'chunk' ) ), 'ab_mcp_sync_manifest_shape', 'chunks' ),
			'no chunk'                      => array( static fn( array $m ): array => array_merge( $m, array( 'chunks' => array() ) ), 'ab_mcp_sync_manifest_shape', 'chunks' ),
			'chunks as a map'               => array( static fn( array $m ): array => array_merge( $m, array( 'chunks' => array( 'a' => $m['chunks'][0] ) ) ), 'ab_mcp_sync_manifest_shape', 'chunks' ),
			'an empty chunk'                => array( static fn( array $m ): array => array_merge( $m, self::chunks( 1, 0 ) ), 'ab_mcp_sync_manifest_shape', 'chunks' ),
			'a chunk over 5 MiB'            => array( static fn( array $m ): array => array_merge( $m, self::chunks( 1, 5 * $mb + 1 ) ), 'ab_mcp_sync_manifest_shape', 'chunks' ),
			'a chunk hash in capitals'      => array( static fn( array $m ): array => array_merge( $m, array( 'chunks' => array( array_merge( $m['chunks'][0], array( 'sha256' => strtoupper( $m['chunks'][0]['sha256'] ) ) ) ) ) ), 'ab_mcp_sync_manifest_shape', 'chunks' ),
			'a chunk with a field more'     => array( static fn( array $m ): array => array_merge( $m, array( 'chunks' => array( $m['chunks'][0] + array( 'note' => 'x' ) ) ) ), 'ab_mcp_sync_manifest_shape', 'chunks' ),
			'too many chunks'               => array( static fn( array $m ): array => array_merge( $m, self::chunks( 2001, 1 ) ), 'ab_mcp_sync_manifest_shape', 'chunks' ),
			'bytes not the sum'             => array( static fn( array $m ): array => array_merge( $m, array( 'bytes' => $m['bytes'] + 1 ) ), 'ab_mcp_sync_manifest_shape', 'bytes' ),
			// The version of WordPress on the staging site (branch 6, part 2).
			'no version of WordPress'       => array( static fn( array $m ): array => array_diff_key( $m, array( 'wordpress' => 1 ) ), 'ab_mcp_sync_manifest_shape', 'wordpress' ),
			'a version that is none'        => array( static fn( array $m ): array => array_merge( $m, array( 'wordpress' => 'latest' ) ), 'ab_mcp_sync_manifest_shape', 'wordpress' ),
			'a version as a number'         => array( static fn( array $m ): array => array_merge( $m, array( 'wordpress' => 7 ) ), 'ab_mcp_sync_manifest_shape', 'wordpress' ),
			'a version with a line break'   => array( static fn( array $m ): array => array_merge( $m, array( 'wordpress' => "7.1.3\n" ) ), 'ab_mcp_sync_manifest_shape', 'wordpress' ),
			'an empty version'              => array( static fn( array $m ): array => array_merge( $m, array( 'wordpress' => '' ) ), 'ab_mcp_sync_manifest_shape', 'wordpress' ),
			'a package over 64 MiB'         => array( static fn( array $m ): array => array_merge( $m, self::chunks( 13, 5 * $mb ) ), 'ab_mcp_sync_manifest_shape', 'bytes' ),
		);
	}

	public function testTheVersionOfWordPressIsReadAsVersionPhpSetsIt(): void {
		$before = $GLOBALS['wp_version'];
		try {
			foreach ( array( '7.1.3', '7.2', '7.2-beta1', '7.2-alpha-61234-src', '10.0.1.2' ) as $version ) {
				$GLOBALS['wp_version'] = $version;
				self::assertSame( $version, AB_MCP_Sync_Manifest::wordpress(), $version );
			}
			foreach ( array( '', '7', 'seven', ' 7.1.3', "7.1.3\n", '7.1.3-', '7..1', null, 713 ) as $version ) {
				$GLOBALS['wp_version'] = $version;
				self::assertSame( '', AB_MCP_Sync_Manifest::wordpress(), (string) json_encode( $version ) );
			}
			unset( $GLOBALS['wp_version'] );
			self::assertSame( '', AB_MCP_Sync_Manifest::wordpress() );
			$this->paired();
			$this->anchored();
			$this->on( 'staging' );
			$refused = AB_MCP_Sync_Manifest::sign( self::package() );
			self::refused( $refused, 'ab_mcp_sync_manifest_shape', 'wordpress' );
		} finally {
			$GLOBALS['wp_version'] = $before;
		}
	}

	#[DataProvider( 'badFields' )]
	public function testEveryFieldIsAsTheFormatHasItWhateverTheSignature( callable $change, string $code, ?string $field ): void {
		$this->paired();
		$this->anchored();
		$m       = $change( self::data( $this->signed() ) );
		$refused = $this->verify( $this->resigned( AB_MCP_Sync_Canon::encode( $m ) ) );
		self::refused( $refused, $code, $field );
	}

	/** @return array<string,array{0:callable}> */
	public static function limits(): array {
		$mb = 1048576;
		return array(
			'5000 objects'              => array( static fn( array $m ): array => array_merge( $m, array( 'objects' => 5000 ) ) ),
			'no objects'                => array( static fn( array $m ): array => array_merge( $m, array( 'objects' => 0 ) ) ),
			'256 MiB unpacked'          => array( static fn( array $m ): array => array_merge( $m, array( 'unpacked' => 256 * $mb ) ) ),
			'a chunk of 5 MiB'          => array( static fn( array $m ): array => array_merge( $m, self::chunks( 1, 5 * $mb ) ) ),
			'a chunk of one byte'       => array( static fn( array $m ): array => array_merge( $m, self::chunks( 1, 1 ) ) ),
			'64 MiB in chunks'          => array( static fn( array $m ): array => array_merge( $m, array( 'chunks' => array_merge( self::chunks( 12, 5 * $mb )['chunks'], self::chunks( 1, 4 * $mb )['chunks'] ), 'bytes' => 64 * $mb ) ) ),
			'2000 chunks'               => array( static fn( array $m ): array => array_merge( $m, self::chunks( 2000, 1 ) ) ),
			'2000 kinds'                => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => self::kinds( 2000 ) ) ) ),
			'no kinds'                  => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array() ) ) ),
			'a subtype of 191'          => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( array_merge( $m['kinds'][0], array( 'subtype' => str_repeat( 'ä', 191 ) ) ) ) ) ) ),
			'an empty subtype'          => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => array( array_merge( $m['kinds'][0], array( 'subtype' => '' ) ) ) ) ) ),
			'one kind, two subtypes'    => array( static fn( array $m ): array => array_merge( $m, array( 'kinds' => self::kinds( 2 ) ) ) ),
			'two kinds, lower subtype'  => array(
				static fn( array $m ): array => array_merge(
					$m,
					array(
						'kinds' => array(
							array_merge( $m['kinds'][0], array( 'kind' => 'post', 'subtype' => 'z' ) ),
							array_merge( $m['kinds'][0], array( 'kind' => 'term', 'subtype' => 'a' ) ),
						),
					)
				),
			),
		);
	}

	#[DataProvider( 'limits' )]
	public function testTheLimitsThemselvesAreRead( callable $change ): void {
		$this->paired();
		$this->anchored();
		$m    = $change( self::data( $this->signed() ) );
		$read = $this->verify( $this->resigned( AB_MCP_Sync_Canon::encode( $m ) ) );
		self::assertIsArray( $read, is_object( $read ) ? $read->get_error_message() : '' );
	}
}
