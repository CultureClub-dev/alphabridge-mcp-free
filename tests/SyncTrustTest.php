<?php
/**
 * The key of a staging source and the anchor of a production site (Z8,
 * decision 4 of the plan of 01.11.2026; AB_MCP_Sync_Key,
 * AB_MCP_Sync_Anchor, AB_MCP_Sync_Fetcher, the route
 * alphabridge/v1/staging-key and the part «Key» of the card «Staging»).
 *
 * What must hold:
 *
 * - A person who confirms the role staging site gives the site a new
 *   source id and Ed25519 key in two options of their own without
 *   autoload; every new confirmation a new one; a site with any other role
 *   keeps none; a save that keeps the role keeps the key; a staging site
 *   without a key gets one only from a person (make()), which never
 *   replaces one. A key counts only where both options agree.
 * - The route gives the public part to anyone (no-store) and 404 on a site
 *   without a key; the pairing code is the same public part, read back only
 *   in its one spelling.
 * - The production site prepares the key from the route of the stored
 *   staging address, over https only, through AB_MCP_Outbound::get(),
 *   following no redirect, or from a pairing code; the key must name the
 *   stored address exactly. A prepared key is not active; only a person's
 *   confirmation in wp-admin with the fingerprint shown makes it active,
 *   also for a replacement. Another pair or role takes the anchor away.
 * - The pair print changes with a new confirmation of the role, another
 *   pair and a new anchor.
 * - The private key is never in an answer, a code, the page, another
 *   option or the log, and the option tools cannot reach it.
 * - The forms of the key and the anchor work behind their nonce and
 *   manage_options and land on «Staging» at the part «Key».
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Option_Guard;
use AB_MCP_REST_Controller;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Fetcher;
use AB_MCP_Sync_Key;
use AB_MCP_Sync_Manifest;
use AB_MCP_Tool_Registry;
use AbTestExit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_REST_Request;

require_once __DIR__ . '/../includes/sync/class-sync-manifest.php';
require_once __DIR__ . '/SyncTrustSites.php';

final class SyncTrustTest extends TestCase {

	use SyncTrustSites;

	/** A public address of the staging site's host (ab_mcp_outbound_addresses). */
	private const IP = '93.184.216.34';

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can']         = static fn(): bool => true;
		$GLOBALS['ab_test_http']        = array();
		$GLOBALS['ab_test_http_answer'] = null;
		add_filter( 'ab_mcp_outbound_addresses', static fn( $found, string $host ) => array( 'staging.example.com' => array( self::IP ), 'other.example.com' => array( self::IP ) )[ $host ] ?? array(), 10, 2 );
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['ab_test_home'] );
	}

	/** The stored public part of the key of the site running now. */
	private static function source(): ?array {
		$raw = get_option( AB_MCP_Sync_Key::OPTION_SOURCE, null );
		return is_array( $raw ) ? $raw : null;
	}

	/** The key options of the staging site copied into the site running now, past save(). */
	private function leftover_key(): void {
		$here = $this->here;
		$this->on( 'staging' );
		$source = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
		$secret = get_option( AB_MCP_Sync_Key::OPTION_SECRET );
		$this->on( $here );
		update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $source );
		update_option( AB_MCP_Sync_Key::OPTION_SECRET, $secret );
		self::assertNotNull( AB_MCP_Sync_Key::current(), 'The key is there.' );
	}

	/** Answer the next GETs with this (url => response). */
	private static function answers( array $answers ): void {
		$GLOBALS['ab_test_http_answer'] = static fn( string $url ) => $answers[ $url ] ?? ab_test_http_response( 404, 'no such address' );
	}

	/** The answer of the staging site's route, as JSON. */
	private function route_body(): string {
		$this->on( 'staging' );
		return (string) wp_json_encode( AB_MCP_Sync_Key::answer() );
	}

	/** The address the production site fetches the key from. */
	private static function key_url( string $partner = 'https://staging.example.com' ): string {
		return $partner . '/?rest_route=/alphabridge/v1/staging-key';
	}

	/* ------------------------------------------------- the key of staging */

	public function testConfirmingTheRoleStagingMakesASourceIdAndAKeyInOptionsOfTheirOwn(): void {
		$this->paired();
		$this->on( 'staging' );
		$key = AB_MCP_Sync_Key::current();
		self::assertIsArray( $key );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $key['source_id'] );
		$public = sodium_base642bin( $key['public_key'], SODIUM_BASE64_VARIANT_ORIGINAL );
		self::assertSame( 32, strlen( $public ) );
		self::assertSame( substr( hash( 'sha256', $public ), 0, 32 ), $key['key_id'], 'The key id names the key.' );
		self::assertMatchesRegularExpression( '/^([0-9A-F]{4} ){7}[0-9A-F]{4}$/', $key['fingerprint'] );

		$secret = get_option( AB_MCP_Sync_Key::OPTION_SECRET );
		self::assertSame( $key['key_id'], $secret['key_id'] );
		self::assertSame( $public, sodium_crypto_sign_publickey_from_secretkey( sodium_base642bin( $secret['secret_key'], SODIUM_BASE64_VARIANT_ORIGINAL ) ), 'The private key of that very public key.' );
		self::assertFalse( $GLOBALS['ab_test_autoload'][ AB_MCP_Sync_Key::OPTION_SECRET ], 'The private key is not loaded on every request.' );
		self::assertFalse( $GLOBALS['ab_test_autoload'][ AB_MCP_Sync_Key::OPTION_SOURCE ] );
		self::assertStringNotContainsString( $key['public_key'], serialize( get_option( 'ab_mcp_options' ) ), 'Nothing of the key in the record of the site.' );

		$this->on( 'live' );
		self::assertNull( AB_MCP_Sync_Key::current(), 'The production site has none.' );
		self::assertFalse( get_option( AB_MCP_Sync_Key::OPTION_SECRET ) );
	}

	public function testEachConfirmationOfTheRoleStagingMakesANewKeyAndAnyOtherRoleNone(): void {
		ab_test_add_user( 3 );
		$this->on( 'staging' );
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', 'production', 3, 'hub' );
		$first = AB_MCP_Sync_Key::current();
		self::assertIsArray( $first, 'The hub confirms the role for a person too.' );

		AB_MCP_Staging::save( 'staging', 'https://www.example.com', 'production', 3, 'hub' );
		self::assertSame( $first, AB_MCP_Sync_Key::current(), 'The hub writing what is there confirms nothing new.' );

		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		$second = AB_MCP_Sync_Key::current();
		self::assertNotSame( $first['source_id'], $second['source_id'], 'The form confirms the role the hub wrote: a new confirmation, a new source.' );
		self::assertNotSame( $first['key_id'], $second['key_id'] );

		AB_MCP_Staging::save( 'staging', 'https://other.example.com', null, 3, 'form' );
		self::assertSame( $second, AB_MCP_Sync_Key::current(), 'Another pair keeps the role and the key.' );

		AB_MCP_Staging::save( 'production', 'https://other.example.com', null, 3, 'form' );
		self::assertNull( AB_MCP_Sync_Key::current(), 'A production site keeps no key.' );
		self::assertFalse( get_option( AB_MCP_Sync_Key::OPTION_SECRET ), 'Not even the private key.' );
		self::assertFalse( get_option( AB_MCP_Sync_Key::OPTION_SOURCE ) );

		AB_MCP_Staging::save( 'staging', '', null, 3, 'form' );
		$third = AB_MCP_Sync_Key::current();
		self::assertIsArray( $third, 'Confirmed as staging site again: a new key, also without a pair.' );
		self::assertNotSame( $second['source_id'], $third['source_id'] );

		AB_MCP_Staging::save( 'unconfirmed', '', null, 3, 'form' );
		self::assertNull( AB_MCP_Sync_Key::current(), 'The role taken back: no key.' );
		self::assertFalse( get_option( AB_MCP_Sync_Key::OPTION_SECRET ) );
	}

	public function testAStagingSiteWithoutAKeyGetsOneOnlyFromAPersonAndNeverASecond(): void {
		$this->paired();
		$this->on( 'staging' );
		delete_option( AB_MCP_Sync_Key::OPTION_SECRET );
		delete_option( AB_MCP_Sync_Key::OPTION_SOURCE );
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		self::assertNull( AB_MCP_Sync_Key::current(), 'A save that keeps the role makes no key.' );

		$made = AB_MCP_Sync_Key::make();
		self::assertTrue( $made['made'] );
		self::assertSame( $made['key'], AB_MCP_Sync_Key::current() );
		$again = AB_MCP_Sync_Key::make();
		self::assertFalse( $again['made'] );
		self::assertSame( $made['key'], $again['key'], 'A key that is there stays.' );

		$this->on( 'live' );
		self::assertSame( 'ab_mcp_sync_key_role', AB_MCP_Sync_Key::make()->get_error_code() );
		self::assertFalse( get_option( AB_MCP_Sync_Key::OPTION_SOURCE ), 'The production site made none.' );
		// A key left on a production site (written past save(), as a copy
		// of the database could bring it) makes it no staging site.
		$this->leftover_key();
		self::assertSame( 'ab_mcp_sync_key_role', AB_MCP_Sync_Key::make()->get_error_code() );
	}

	/** @return array<string,array{0:callable}> */
	public static function brokenKeys(): array {
		return array(
			'the private key of another key id' => array(
				static function () {
					$s           = get_option( AB_MCP_Sync_Key::OPTION_SECRET );
					$s['key_id'] = str_repeat( '0', 32 );
					update_option( AB_MCP_Sync_Key::OPTION_SECRET, $s );
				},
			),
			'the private key of another key'    => array(
				static function () {
					$s               = get_option( AB_MCP_Sync_Key::OPTION_SECRET );
					$s['secret_key'] = sodium_bin2base64( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ), SODIUM_BASE64_VARIANT_ORIGINAL );
					update_option( AB_MCP_Sync_Key::OPTION_SECRET, $s );
				},
			),
			'a private key without its key'     => array(
				static function () {
					$s = get_option( AB_MCP_Sync_Key::OPTION_SECRET );
					unset( $s['secret_key'] );
					update_option( AB_MCP_Sync_Key::OPTION_SECRET, $s );
				},
			),
			'a private key with a key id as a list' => array(
				static function () {
					$s           = get_option( AB_MCP_Sync_Key::OPTION_SECRET );
					$s['key_id'] = array( $s['key_id'] );
					update_option( AB_MCP_Sync_Key::OPTION_SECRET, $s );
				},
			),
			'a source id as a list'             => array(
				static function () {
					$p              = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
					$p['source_id'] = array( $p['source_id'] );
					update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $p );
				},
			),
			'a key id as a list'                => array(
				static function () {
					$p           = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
					$p['key_id'] = array( $p['key_id'] );
					update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $p );
				},
			),
			'a private key whose second half is not its public key' => array(
				static function () {
					$s               = get_option( AB_MCP_Sync_Key::OPTION_SECRET );
					$s['secret_key'] = base64_encode( substr( base64_decode( $s['secret_key'] ), 0, 32 ) . str_repeat( "\x01", 32 ) );
					update_option( AB_MCP_Sync_Key::OPTION_SECRET, $s );
				},
			),
			'a public key as a list'            => array(
				static function () {
					$p               = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
					$p['public_key'] = array( $p['public_key'] );
					update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $p );
				},
			),
			'no public key'                     => array(
				static function () {
					$p = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
					unset( $p['public_key'] );
					update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $p );
				},
			),
			'no private key'                    => array(
				static function () {
					delete_option( AB_MCP_Sync_Key::OPTION_SECRET );
				},
			),
			'a key id that is not the key\'s'   => array(
				static function () {
					$p           = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
					$p['key_id'] = str_repeat( 'f', 32 );
					update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $p );
				},
			),
			'a public key in another spelling'  => array(
				static function () {
					$p               = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
					$p['public_key'] = rtrim( $p['public_key'], '=' );
					update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $p );
				},
			),
			'a public key with bits for nothing' => array(
				static function () {
					$p               = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
					$p['public_key'] = self::loose( $p['public_key'] );
					update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $p );
				},
			),
			'a source id in capitals'           => array(
				static function () {
					$p              = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
					$p['source_id'] = strtoupper( $p['source_id'] );
					update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $p );
				},
			),
			'another format'                    => array(
				static function () {
					$p           = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
					$p['format'] = 'ab-sync-key/2';
					update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $p );
				},
			),
		);
	}

	#[DataProvider( 'brokenKeys' )]
	public function testAKeyCountsOnlyWhereBothOptionsAgree( callable $break ): void {
		$this->paired();
		$this->on( 'staging' );
		$key = AB_MCP_Sync_Key::current();
		self::assertIsString( AB_MCP_Sync_Key::sign( 'x', $key['key_id'] ), 'Signs before.' );
		$break();
		self::assertNull( AB_MCP_Sync_Key::current() );
		self::assertNull( AB_MCP_Sync_Key::answer(), 'Shown to nobody.' );
		self::assertNull( AB_MCP_Sync_Key::pairing_code() );
		self::assertInstanceOf( \WP_Error::class, AB_MCP_Sync_Key::sign( 'x', $key['key_id'] ), 'Signs nothing.' );
	}

	public function testSigningNamesTheKeyItSignsWith(): void {
		$this->paired();
		$this->on( 'staging' );
		$key       = AB_MCP_Sync_Key::current();
		$signature = AB_MCP_Sync_Key::sign( 'bytes', $key['key_id'] );
		self::assertTrue( sodium_crypto_sign_verify_detached( $signature, 'bytes', sodium_base642bin( $key['public_key'], SODIUM_BASE64_VARIANT_ORIGINAL ) ) );
		self::assertSame( 'ab_mcp_sync_key_changed', AB_MCP_Sync_Key::sign( 'bytes', str_repeat( 'a', 32 ) )->get_error_code(), 'Bytes that name another key are not signed.' );
		$this->on( 'live' );
		self::assertSame( 'ab_mcp_sync_key_role', AB_MCP_Sync_Key::sign( 'bytes', $key['key_id'] )->get_error_code() );
	}

	/* --------------------------------------------------------- the route */

	private function route_request(): \WP_REST_Response {
		$GLOBALS['ab_test_routes'] = array();
		( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->register_routes();
		$request = new WP_REST_Request();
		$request->set_method( 'GET' );
		$request->set_route( '/alphabridge/v1/staging-key' );
		$GLOBALS['ab_test_current_user'] = 0;
		return ab_test_rest_dispatch( $request );
	}

	public function testTheRouteGivesThePublicPartToAnyoneAndNothingElse(): void {
		$GLOBALS['ab_test_routes'] = array();
		( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->register_routes();
		$routes = array_values( array_filter( $GLOBALS['ab_test_routes'], static fn( array $r ): bool => '/staging-key' === $r['route'] ) );
		self::assertCount( 1, $routes );
		self::assertSame( 'alphabridge/v1', $routes[0]['namespace'] );
		self::assertSame( array( 'GET' ), array_column( $routes[0]['args'], 'methods' ), 'Read only.' );
		self::assertSame( '__return_true', $routes[0]['args'][0]['permission_callback'], 'No token: the production site has none for the staging site.' );

		$this->paired();
		$this->on( 'staging' );
		$key      = AB_MCP_Sync_Key::current();
		$response = $this->route_request();
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? null );
		self::assertSame(
			array(
				'format'      => 'ab-sync-key/1',
				'site'        => 'https://staging.example.com',
				'source_id'   => $key['source_id'],
				'key_id'      => $key['key_id'],
				'public_key'  => $key['public_key'],
				'fingerprint' => $key['fingerprint'],
			),
			$response->get_data()
		);

		delete_option( AB_MCP_Sync_Key::OPTION_SECRET );
		$response = $this->route_request();
		self::assertSame( 404, $response->get_status(), 'A staging site without a usable key gives none.' );
		self::assertSame( 'ab_mcp_staging_key_none', $response->get_data()['code'] );
		AB_MCP_Sync_Key::make();

		$this->on( 'live' );
		$response = $this->route_request();
		self::assertSame( 404, $response->get_status(), 'A production site gives none.' );
		self::assertSame( 'ab_mcp_staging_key_none', $response->get_data()['code'] );
		$this->leftover_key();
		self::assertNull( AB_MCP_Sync_Key::answer(), 'Not even with a key left over: only a confirmed staging site gives it.' );
		self::assertSame( 404, $this->route_request()->get_status() );

		$this->on( 'staging' );
		AB_MCP_Sync_Key::make();
		self::assertNotNull( AB_MCP_Sync_Key::answer() );
		$GLOBALS['ab_test_home'] = 'not an address';
		self::assertNull( AB_MCP_Sync_Key::answer(), 'Without an own address the key names no site.' );
		self::assertNull( AB_MCP_Sync_Key::pairing_code() );
	}

	/* -------------------------------------------------- the pairing code */

	public function testThePairingCodeReadsBackAsTheKeyOfTheStagingSite(): void {
		$this->paired();
		$this->on( 'staging' );
		$key  = AB_MCP_Sync_Key::current();
		$code = AB_MCP_Sync_Key::pairing_code();
		self::assertMatchesRegularExpression( '/^[A-Za-z0-9+\/]+=*$/', $code, 'One word to copy, in base64.' );
		self::assertSame(
			array(
				'site'        => 'https://staging.example.com',
				'source_id'   => $key['source_id'],
				'key_id'      => $key['key_id'],
				'public_key'  => $key['public_key'],
				'fingerprint' => $key['fingerprint'],
			),
			AB_MCP_Sync_Key::read_code( "\n " . $code . " \n" ),
			'White space around it, as a copy brings it, is left out.'
		);
	}

	/** @return array<string,array{0:callable}> */
	public static function badCodes(): array {
		$json = static fn( array $d ): string => base64_encode( (string) json_encode( $d, JSON_UNESCAPED_SLASHES ) );
		$data = static fn( string $code ): array => json_decode( base64_decode( $code ), true );
		return array(
			'empty'                        => array( static fn( string $c ): string => '' ),
			'no JSON inside'               => array( static fn( string $c ): string => base64_encode( 'not json' ) ),
			'too long, valid but for that' => array( static fn( string $c ): string => $json( array_merge( $data( $c ), array( 'site' => 'https://staging.example.com/' . str_repeat( 'a', 1600 ) ) ) ) ),
			'without its padding'          => array( static fn( string $c ): string => rtrim( self::padded( $data( $c ) ), '=' ) ),
			'bits that encode nothing'     => array( static fn( string $c ): string => self::loose( self::padded( $data( $c ) ) ) ),
			'a character out of base64'    => array( static fn( string $c ): string => substr( $c, 0, 10 ) . '-' . substr( $c, 11 ) ),
			'a space inside'               => array( static fn( string $c ): string => substr( $c, 0, 10 ) . ' ' . substr( $c, 10 ) ),
			'a line break inside'          => array( static fn( string $c ): string => substr( $c, 0, 10 ) . "\n" . substr( $c, 10 ) ),
			'one character changed'        => array( static fn( string $c ): string => substr( $c, 0, 40 ) . ( 'A' === $c[40] ? 'B' : 'A' ) . substr( $c, 41 ) ),
			'other order of the fields'    => array( static fn( string $c ): string => $json( array_reverse( $data( $c ), true ) ) ),
			'a field more'                 => array( static fn( string $c ): string => $json( $data( $c ) + array( 'note' => 'x' ) ) ),
			'a field less'                 => array(
				static function ( string $c ) use ( $data, $json ): string {
					$d = $data( $c );
					unset( $d['source_id'] );
					return $json( $d );
				},
			),
			'escaped slashes'              => array( static fn( string $c ): string => base64_encode( (string) json_encode( $data( $c ) ) ) ),
			'another format'               => array( static fn( string $c ): string => $json( array_merge( $data( $c ), array( 'format' => 'ab-sync-key/2' ) ) ) ),
			'an address not as stored'     => array( static fn( string $c ): string => $json( array_merge( $data( $c ), array( 'site' => 'https://staging.example.com/' ) ) ) ),
			'no address'                   => array( static fn( string $c ): string => $json( array_merge( $data( $c ), array( 'site' => '' ) ) ) ),
			'a key id not of the key'      => array( static fn( string $c ): string => $json( array_merge( $data( $c ), array( 'key_id' => str_repeat( 'e', 32 ) ) ) ) ),
		);
	}

	/**
	 * The code of these fields with an address of a length that makes its
	 * base64 end in padding: one spelling that has bits encoding nothing.
	 */
	private static function padded( array $d ): string {
		$d['site'] = 'https://staging.example.com';
		for ( $path = ''; 0 === strlen( (string) json_encode( $d, JSON_UNESCAPED_SLASHES ) ) % 3; $path .= 'a' ) {
			$d['site'] = 'https://staging.example.com/' . $path . 'a';
		}
		$code = base64_encode( (string) json_encode( $d, JSON_UNESCAPED_SLASHES ) );
		self::assertStringEndsWith( '=', $code );
		self::assertIsArray( AB_MCP_Sync_Key::read_code( $code ), 'The padded code itself reads.' );
		return $code;
	}

	/** The same bytes with the last bit before the padding set, which base64_decode() drops. */
	private static function loose( string $code ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
		$last     = strlen( rtrim( $code, '=' ) ) - 1;
		$loose    = substr( $code, 0, $last ) . $alphabet[ strpos( $alphabet, $code[ $last ] ) + 1 ] . substr( $code, $last + 1 );
		self::assertSame( base64_decode( $code ), base64_decode( $loose, true ), 'Another spelling of the same bytes.' );
		return $loose;
	}

	public function testAPairingCodeIsText(): void {
		self::assertSame( 'ab_mcp_sync_key_code', AB_MCP_Sync_Key::read_code( array( 'code' ) )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_key_code', AB_MCP_Sync_Key::read_code( null )->get_error_code() );
	}

	#[DataProvider( 'badCodes' )]
	public function testAPairingCodeReadsOnlyInItsOneSpelling( callable $change ): void {
		$this->paired();
		$code = $change( $this->code() );
		$read = AB_MCP_Sync_Key::read_code( $code );
		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'ab_mcp_sync_key_code', $read->get_error_code() );
	}

	/** @return array<string,array{0:callable}> */
	public static function badAnswers(): array {
		return array(
			'no JSON'                => array( static fn( array $a ): string => 'not json' ),
			'a field less'           => array(
				static function ( array $a ): string {
					unset( $a['fingerprint'] );
					return (string) json_encode( $a );
				},
			),
			'a field more'           => array( static fn( array $a ): string => (string) json_encode( $a + array( 'note' => 'x' ) ) ),
			'another fingerprint'    => array( static fn( array $a ): string => (string) json_encode( array_merge( $a, array( 'fingerprint' => 'AAAA AAAA AAAA AAAA AAAA AAAA AAAA AAAA' ) ) ) ),
			'a fingerprint not text' => array( static fn( array $a ): string => (string) json_encode( array_merge( $a, array( 'fingerprint' => 1 ) ) ) ),
			'another key id'         => array( static fn( array $a ): string => (string) json_encode( array_merge( $a, array( 'key_id' => str_repeat( 'e', 32 ) ) ) ) ),
			'a key that is no base64, with the key id of nothing' => array(
				static function ( array $a ): string {
					$a['public_key']  = 'not base64';
					$a['key_id']      = substr( hash( 'sha256', '' ), 0, 32 );
					$a['fingerprint'] = AB_MCP_Sync_Key::fingerprint( $a['source_id'], $a['key_id'], $a['public_key'] );
					return (string) json_encode( $a );
				},
			),
			'a key of 31 bytes'      => array(
				static function ( array $a ): string {
					$public          = str_repeat( "\x07", 31 );
					$a['public_key'] = base64_encode( $public );
					$a['key_id']     = substr( hash( 'sha256', $public ), 0, 32 );
					$a['fingerprint'] = AB_MCP_Sync_Key::fingerprint( $a['source_id'], $a['key_id'], $a['public_key'] );
					return (string) json_encode( $a );
				},
			),
		);
	}

	#[DataProvider( 'badAnswers' )]
	public function testTheAnswerOfTheRouteReadsOnlyAsTheRouteWritesIt( callable $change ): void {
		$this->paired();
		$this->on( 'staging' );
		$answer = AB_MCP_Sync_Key::answer();
		self::assertSame( 'https://staging.example.com', AB_MCP_Sync_Key::read_answer( (string) json_encode( $answer ) )['site'], 'As written, it reads; key order does not matter.' );
		self::assertIsArray( AB_MCP_Sync_Key::read_answer( (string) json_encode( array_reverse( $answer, true ) ) ) );
		$read = AB_MCP_Sync_Key::read_answer( $change( $answer ) );
		self::assertInstanceOf( \WP_Error::class, $read );
		self::assertSame( 'ab_mcp_sync_key_answer', $read->get_error_code() );
	}

	/* ------------------------------------------------- prepare the anchor */

	public function testTheProductionSiteFetchesTheKeyOverTheBoundFetcherAndFollowsNoRedirect(): void {
		$this->paired();
		self::answers( array( self::key_url() => ab_test_http_response( 200, $this->route_body() ) ) );
		$this->on( 'live' );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_partner( 3 );
		self::assertIsArray( $prepared, is_object( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertSame( 'route', $prepared['via'] );
		self::assertSame( 'https://staging.example.com', $prepared['partner_url'] );
		self::assertCount( 1, $GLOBALS['ab_test_http'] );
		$call = $GLOBALS['ab_test_http'][0];
		self::assertSame( self::key_url(), $call['url'], 'The address from the stored address of the staging site and the route, nothing else.' );
		self::assertSame( 0, $call['args']['redirection'] );
		self::assertSame( 10, $call['args']['timeout'] );
		self::assertSame( 8192, $call['args']['limit_response_size'] );

		// A redirect is the answer, not followed: the key is not prepared.
		$GLOBALS['ab_test_http'] = array();
		self::answers( array( self::key_url() => ab_test_http_response( 302, '', array( 'Location' => 'https://other.example.com/?rest_route=/alphabridge/v1/staging-key' ) ) ) );
		$refused = AB_MCP_Sync_Anchor::prepare_from_partner( 3 );
		self::assertSame( 'ab_mcp_sync_anchor_no_key', $refused->get_error_code() );
		self::assertCount( 1, $GLOBALS['ab_test_http'], 'One request: the target of the redirect is not asked.' );
	}

	public function testTheFetcherFollowsNoRedirectWhateverTheCallerAsksFor(): void {
		$this->paired();
		$this->on( 'live' );
		self::answers(
			array(
				'https://staging.example.com/a' => ab_test_http_response( 302, '', array( 'Location' => 'https://staging.example.com/b' ) ),
				'https://staging.example.com/b' => ab_test_http_response( 200, 'b' ),
			)
		);
		$response = ( new AB_MCP_Sync_Fetcher() )->get( 'https://staging.example.com/a', array( 'redirection' => 3 ) );
		self::assertSame( 302, wp_remote_retrieve_response_code( $response ) );
		self::assertSame( array( 'https://staging.example.com/a' ), array_column( $GLOBALS['ab_test_http'], 'url' ) );
	}

	public function testAnHttpAddressIsNeverAsked(): void {
		ab_test_add_user( 3 );
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'http://staging.example.com', null, 3, 'form' );
		self::answers( array( 'http://staging.example.com/?rest_route=/alphabridge/v1/staging-key' => ab_test_http_response( 200, '{}' ) ) );
		self::assertSame( 'ab_mcp_sync_anchor_not_https', AB_MCP_Sync_Anchor::prepare_from_partner( 3 )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_not_https', ( new AB_MCP_Sync_Fetcher() )->get( 'http://staging.example.com/', array() )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_not_https', ( new AB_MCP_Sync_Fetcher() )->get( 'HTTPS://staging.example.com/', array() )->get_error_code(), 'As normalize_url() stores it, in lower case.' );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'Nothing was asked.' );
		self::assertNull( AB_MCP_Sync_Anchor::prepared() );
	}

	public function testAnUnreachedOrWrongAnswerPreparesNothing(): void {
		$this->paired();
		$this->on( 'live' );
		self::answers( array() );
		self::assertSame( 'ab_mcp_sync_anchor_no_key', AB_MCP_Sync_Anchor::prepare_from_partner( 3 )->get_error_code(), '404: no key there.' );
		$GLOBALS['ab_test_http_answer'] = null;
		$unreached                      = AB_MCP_Sync_Anchor::prepare_from_partner( 3 );
		self::assertSame( 'ab_mcp_sync_anchor_unreached', $unreached->get_error_code() );
		self::assertSame( 'http_request_failed', $unreached->get_error_data()['cause'] );
		self::answers( array( self::key_url() => ab_test_http_response( 200, 'not json' ) ) );
		self::assertSame( 'ab_mcp_sync_key_answer', AB_MCP_Sync_Anchor::prepare_from_partner( 3 )->get_error_code() );
		self::assertNull( AB_MCP_Sync_Anchor::prepared() );
	}

	public function testOnlyTheConfirmedProductionSitePairedWithAStagingSitePrepares(): void {
		ab_test_add_user( 3 );
		$this->on( 'live' );
		self::assertSame( 'ab_mcp_sync_anchor_role', AB_MCP_Sync_Anchor::prepare_from_partner( 3 )->get_error_code(), 'No role.' );
		self::assertSame( 'ab_mcp_sync_anchor_role', AB_MCP_Sync_Anchor::prepare_from_code( 'x', 3 )->get_error_code() );
		AB_MCP_Staging::save( 'production', '', null, 3, 'form' );
		self::assertSame( 'ab_mcp_sync_anchor_pair', AB_MCP_Sync_Anchor::prepare_from_partner( 3 )->get_error_code(), 'No pair.' );
		AB_MCP_Staging::save( 'staging', 'https://www.example.org', null, 3, 'form' );
		self::assertSame( 'ab_mcp_sync_anchor_role', AB_MCP_Sync_Anchor::prepare_from_partner( 3 )->get_error_code(), 'A staging site anchors nothing.' );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
	}

	public function testAKeyForAnotherAddressThanTheStoredOneIsNotPrepared(): void {
		$this->paired();
		$this->on( 'staging' );
		$GLOBALS['ab_test_home'] = 'https://staging.example.com/sub';
		$code                    = AB_MCP_Sync_Key::pairing_code();
		$body                    = (string) wp_json_encode( AB_MCP_Sync_Key::answer() );
		$this->on( 'live' );
		$refused = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		self::assertSame( 'ab_mcp_sync_anchor_site', $refused->get_error_code(), 'The path differs.' );
		self::answers( array( self::key_url() => ab_test_http_response( 200, $body ) ) );
		self::assertSame( 'ab_mcp_sync_anchor_site', AB_MCP_Sync_Anchor::prepare_from_partner( 3 )->get_error_code(), 'Also fetched.' );
		self::assertNull( AB_MCP_Sync_Anchor::prepared() );
	}

	/* ------------------------------------------------- confirm the anchor */

	public function testAPreparedKeyIsNotActiveUntilAPersonConfirmsTheFingerprintShown(): void {
		$this->paired();
		$code = $this->code();
		$this->on( 'live' );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		self::assertSame( 'code', $prepared['via'] );
		self::assertSame( $prepared, AB_MCP_Sync_Anchor::prepared() );
		self::assertNull( AB_MCP_Sync_Anchor::active(), 'Prepared is not active.' );
		self::assertNull( AB_MCP_Sync_Anchor::pair_print() );
		self::assertFalse( $GLOBALS['ab_test_autoload'][ AB_MCP_Sync_Anchor::OPTION ] );

		$wrong = AB_MCP_Sync_Anchor::confirm( 'AAAA AAAA AAAA AAAA AAAA AAAA AAAA AAAA', 4 );
		self::assertSame( 'ab_mcp_sync_anchor_fingerprint', $wrong->get_error_code() );
		self::assertSame( 'ab_mcp_sync_anchor_fingerprint', AB_MCP_Sync_Anchor::confirm( null, 4 )->get_error_code() );
		self::assertNull( AB_MCP_Sync_Anchor::active() );

		$active = AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 4, 1700000000 );
		self::assertSame( $active, AB_MCP_Sync_Anchor::active() );
		self::assertSame( 4, $active['user_id'], 'Who confirmed.' );
		self::assertSame( 1700000000, $active['at'] );
		self::assertSame( 3, $active['prepared_by'] );
		self::assertSame( $prepared['key_id'], $active['key_id'] );
		self::assertNull( AB_MCP_Sync_Anchor::prepared(), 'Nothing is left prepared.' );
		self::assertSame( 'ab_mcp_sync_anchor_nothing', AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 4 )->get_error_code() );
	}

	public function testANewKeyStaysPreparedBesideTheActiveOneUntilConfirmed(): void {
		$this->paired();
		$old = $this->anchored();
		$this->on( 'staging' );
		AB_MCP_Staging::save( 'production', 'https://www.example.com', null, 3, 'form' );
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		$code = $this->code();
		$this->on( 'live' );
		$new = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		self::assertNotSame( $old['key_id'], $new['key_id'] );
		self::assertSame( $old, AB_MCP_Sync_Anchor::active(), 'The active one stays until the new one is confirmed.' );
		AB_MCP_Sync_Anchor::confirm( $new['fingerprint'], 3 );
		self::assertSame( $new['key_id'], AB_MCP_Sync_Anchor::active()['key_id'] );
	}

	public function testConfirmingNeedsAPreparedKeyOfTheStoredStagingSite(): void {
		$this->paired();
		$code = $this->code();
		$this->on( 'live' );
		self::assertSame( 'ab_mcp_sync_anchor_nothing', AB_MCP_Sync_Anchor::confirm( 'x', 3 )->get_error_code() );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		// The pair changed past save(), which would have taken the anchor away.
		$raw                            = get_option( 'ab_mcp_options' );
		$raw['staging']['pair']['partner_url'] = 'https://other.example.com';
		update_option( 'ab_mcp_options', $raw );
		self::assertSame( 'ab_mcp_sync_anchor_stale', AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 3 )->get_error_code() );
		self::assertNull( AB_MCP_Sync_Anchor::prepared(), 'Not for this staging site.' );
		self::assertNull( AB_MCP_Sync_Anchor::active() );
	}

	public function testOnlyTheConfirmedProductionSiteConfirms(): void {
		$this->paired();
		$code = $this->code();
		$this->on( 'live' );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		$stored   = get_option( 'ab_mcp_options' );
		$raw      = $stored;
		unset( $raw['staging']['role'] );
		update_option( 'ab_mcp_options', $raw );
		self::assertSame( 'ab_mcp_sync_anchor_role', AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 3 )->get_error_code(), 'The role taken back past save().' );
		update_option( 'ab_mcp_options', $stored );
		self::assertNull( AB_MCP_Sync_Anchor::active() );
		self::assertIsArray( AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 3 ) );
	}

	public function testAnotherPairOrRoleTakesTheAnchorAway(): void {
		$this->paired();
		$this->anchored();
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com', null, 3, 'form' );
		self::assertNotNull( AB_MCP_Sync_Anchor::active(), 'A save that keeps role and pair keeps the anchor.' );

		AB_MCP_Staging::save( 'production', 'https://other.example.com', null, 3, 'form' );
		self::assertFalse( get_option( AB_MCP_Sync_Anchor::OPTION ), 'Another pair: the anchor is gone.' );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com', null, 3, 'form' );
		self::assertNull( AB_MCP_Sync_Anchor::active(), 'Back to the old pair: confirmed anew, not revived.' );

		$this->anchored();
		$this->on( 'live' );
		AB_MCP_Staging::remove_pair();
		self::assertFalse( get_option( AB_MCP_Sync_Anchor::OPTION ), 'The hub takes the pair back: the anchor too.' );

		$this->paired();
		$this->anchored();
		$this->on( 'live' );
		AB_MCP_Staging::save( 'unconfirmed', '', null, 3, 'form' );
		self::assertFalse( get_option( AB_MCP_Sync_Anchor::OPTION ), 'The role taken back.' );
	}

	public function testAnAnchorHoldsOnlyForTheStoredStagingSiteOfAProductionSite(): void {
		$this->paired();
		$this->anchored();
		$this->on( 'live' );
		$stored = get_option( 'ab_mcp_options' );
		$raw    = $stored;
		$raw['staging']['pair']['partner_url'] = 'https://other.example.com';
		update_option( 'ab_mcp_options', $raw );
		self::assertNull( AB_MCP_Sync_Anchor::active(), 'Another pair past save(): not this anchor.' );
		$raw = $stored;
		$raw['staging']['role']['value']        = 'staging';
		$raw['staging']['pair']['partner_role'] = 'production';
		update_option( 'ab_mcp_options', $raw );
		self::assertNull( AB_MCP_Sync_Anchor::active(), 'Not on a staging site.' );
		update_option( 'ab_mcp_options', $stored );
		self::assertNotNull( AB_MCP_Sync_Anchor::active() );
	}

	/** @return array<string,array{0:string,1:mixed}> */
	public static function brokenAnchors(): array {
		return array(
			'another format'             => array( 'format', 'ab-sync-anchor/2' ),
			'an address not as stored'   => array( 'active.partner_url', 'https://staging.example.com/' ),
			'another fingerprint'        => array( 'active.fingerprint', 'AAAA AAAA AAAA AAAA AAAA AAAA AAAA AAAA' ),
			'a key id not of the key'    => array( 'active.key_id', str_repeat( 'e', 32 ) ),
			'an unknown way'             => array( 'active.via', 'hub' ),
			'who as text'                => array( 'active.user_id', '3' ),
			'no time'                    => array( 'active.at', 0 ),
			'who prepared it, missing'   => array( 'active.prepared_by', null ),
			'when it was prepared, text' => array( 'active.prepared_at', '1' ),
			'no address'                 => array( 'active.partner_url', null ),
			'no way'                     => array( 'active.via', null ),
			'who below zero'             => array( 'active.user_id', -1 ),
			'who prepared, below zero'   => array( 'active.prepared_by', -1 ),
			'prepared at 0'              => array( 'active.prepared_at', 0 ),
			'when as text'               => array( 'active.at', '1700000000' ),
			'who prepared it as text'    => array( 'active.prepared_by', '3' ),
		);
	}

	#[DataProvider( 'brokenAnchors' )]
	public function testTheAnchorReadsOnlyWhatItWrites( string $path, $value ): void {
		$this->paired();
		$this->anchored();
		$this->on( 'live' );
		self::assertNotNull( AB_MCP_Sync_Anchor::active() );
		$raw   = get_option( AB_MCP_Sync_Anchor::OPTION );
		$parts = explode( '.', $path );
		if ( 1 === count( $parts ) ) {
			$raw[ $parts[0] ] = $value;
		} elseif ( null === $value ) {
			unset( $raw[ $parts[0] ][ $parts[1] ] );
		} else {
			$raw[ $parts[0] ][ $parts[1] ] = $value;
		}
		update_option( AB_MCP_Sync_Anchor::OPTION, $raw );
		self::assertNull( AB_MCP_Sync_Anchor::active() );
	}

	/* ----------------------------------------------------------- the print */

	public function testThePairPrintChangesWithTheRoleThePairAndTheAnchor(): void {
		ab_test_add_user( 3 );
		$this->on( 'staging' );
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		$this->on( 'live' );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com', 'staging', 3, 'hub' );
		self::assertNull( AB_MCP_Sync_Anchor::pair_print(), 'No print without an active anchor.' );
		$this->anchored();
		$this->on( 'live' );
		$first = AB_MCP_Sync_Anchor::pair_print();
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $first );
		self::assertSame( $first, AB_MCP_Sync_Anchor::pair_print(), 'The same state, the same print.' );

		// The person confirms on the form what the hub wrote: role and pair
		// confirmed anew, the anchor stays (the pair is the same).
		AB_MCP_Staging::save( 'production', 'https://staging.example.com', null, 3, 'form' );
		self::assertNotNull( AB_MCP_Sync_Anchor::active() );
		$second = AB_MCP_Sync_Anchor::pair_print();
		self::assertNotSame( $first, $second, 'A new confirmation: a new print.' );

		// Each part on its own: the role, the pair, the anchor, the address.
		$stored = get_option( 'ab_mcp_options' );
		$prints = array( $second );
		foreach ( array( array( 'role', 'at' ), array( 'role', 'user_id' ), array( 'role', 'source' ), array( 'pair', 'at' ), array( 'pair', 'user_id' ), array( 'pair', 'source' ) ) as $field ) {
			$raw = $stored;
			$raw['staging'][ $field[0] ][ $field[1] ] = 'source' === $field[1] ? 'hub' : $raw['staging'][ $field[0] ][ $field[1] ] + 1;
			update_option( 'ab_mcp_options', $raw );
			self::assertNotNull( AB_MCP_Sync_Anchor::active() );
			$print = AB_MCP_Sync_Anchor::pair_print();
			self::assertNotContains( $print, $prints, implode( '.', $field ) . ' is in the print.' );
			$prints[] = $print;
		}
		update_option( 'ab_mcp_options', $stored );
		self::assertSame( $second, AB_MCP_Sync_Anchor::pair_print() );
		$GLOBALS['ab_test_home'] = 'https://www.example.com/shop';
		self::assertNotContains( AB_MCP_Sync_Anchor::pair_print(), $prints, 'The address of this site is in the print.' );
		$GLOBALS['ab_test_home'] = 'https://www.example.com';

		// A new anchor of the same staging site.
		$this->on( 'staging' );
		AB_MCP_Staging::save( 'production', 'https://www.example.com', null, 3, 'form' );
		AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' );
		$this->anchored();
		$this->on( 'live' );
		self::assertNotContains( AB_MCP_Sync_Anchor::pair_print(), $prints, 'A new anchor: a new print.' );
		$anchor = get_option( AB_MCP_Sync_Anchor::OPTION );
		$before = AB_MCP_Sync_Anchor::pair_print();
		foreach ( array( 'at', 'user_id' ) as $field ) {
			$raw = $anchor;
			++$raw['active'][ $field ];
			update_option( AB_MCP_Sync_Anchor::OPTION, $raw );
			self::assertNotNull( AB_MCP_Sync_Anchor::active() );
			self::assertNotSame( $before, AB_MCP_Sync_Anchor::pair_print(), 'anchor.' . $field . ' is in the print: who confirmed the anchor, and when.' );
		}
		update_option( AB_MCP_Sync_Anchor::OPTION, $anchor );
		self::assertSame( $before, AB_MCP_Sync_Anchor::pair_print() );

		// Another pair: no anchor, no print.
		AB_MCP_Staging::save( 'production', 'https://other.example.com', null, 3, 'form' );
		self::assertNull( AB_MCP_Sync_Anchor::pair_print() );
	}

	/* ---------------------------------------------- the private key stays */

	public function testThePrivateKeyIsNeverShownAndTheOptionToolsCannotReachIt(): void {
		$this->paired();
		$this->anchored();
		$this->on( 'staging' );
		$raw    = get_option( AB_MCP_Sync_Key::OPTION_SECRET );
		$secret = (string) $raw['secret_key'];
		$bin    = sodium_base642bin( $secret, SODIUM_BASE64_VARIANT_ORIGINAL );
		$seed   = substr( $bin, 0, 32 );
		$signed = AB_MCP_Sync_Manifest::sign( self::package() );

		$seen = array(
			'route'        => (string) wp_json_encode( $this->route_request()->get_data() ),
			'answer'       => (string) wp_json_encode( AB_MCP_Sync_Key::answer() ),
			'code'         => (string) base64_decode( (string) AB_MCP_Sync_Key::pairing_code() ),
			'manifest'     => (string) wp_json_encode( $signed ),
			'page'         => $this->card(),
			'other option' => serialize( array_diff_key( $GLOBALS['ab_test_options'], array( AB_MCP_Sync_Key::OPTION_SECRET => true ) ) ),
		);
		foreach ( $seen as $where => $text ) {
			self::assertStringNotContainsString( $secret, $text, $where );
			self::assertStringNotContainsString( bin2hex( $seed ), bin2hex( $text ), $where . ': not even the seed as bytes.' );
			self::assertStringNotContainsString( base64_encode( $seed ), $text, $where );
		}
		foreach ( array( AB_MCP_Sync_Key::OPTION_SECRET, AB_MCP_Sync_Key::OPTION_SOURCE, AB_MCP_Sync_Anchor::OPTION ) as $option ) {
			self::assertTrue( AB_MCP_Option_Guard::option_is_blocked( $option ), $option . ' is the plugin\'s own: no option tool reads or writes it.' );
		}
	}

	/* ------------------------------------------------- the page and forms */

	/** The card «Staging» as the page draws it. */
	private function card(): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, 'staging_card_html' );
		return (string) $m->invoke( new AB_MCP_Admin() );
	}

	/** Send a form of the part «Key». */
	private function submit( string $handler, array $post ): AbTestExit {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			( new AB_MCP_Admin() )->$handler();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( $handler . ' ended without a redirect.' );
	}

	private static function landed( AbTestExit $exit, string $notice ): void {
		self::assertSame( 'redirect', $exit->kind, $exit->detail );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=alphabridge-staging&ab_notice=' . $notice . '#ab-staging-trust', $exit->detail );
	}

	public function testTheFormsAreHookedForAdminPost(): void {
		$admin = new AB_MCP_Admin();
		self::assertSame( 10, has_action( 'admin_post_ab_mcp_staging_key', array( $admin, 'handle_staging_key' ) ) );
		self::assertSame( 10, has_action( 'admin_post_ab_mcp_staging_anchor', array( $admin, 'handle_staging_anchor' ) ) );
	}

	public function testTheKeyIsMadeBehindTheNonceAndTheCapability(): void {
		$this->paired();
		$this->on( 'staging' );
		delete_option( AB_MCP_Sync_Key::OPTION_SECRET );
		self::assertSame( 'die', $this->submit( 'handle_staging_key', array( '_wpnonce' => 'nonce-ab_mcp_staging_anchor' ) )->kind, 'Another form\'s nonce.' );
		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		self::assertSame( 'die', $this->submit( 'handle_staging_key', array( '_wpnonce' => 'nonce-ab_mcp_staging_key' ) )->kind );
		self::assertNull( AB_MCP_Sync_Key::current() );
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		self::landed( $this->submit( 'handle_staging_key', array( '_wpnonce' => 'nonce-ab_mcp_staging_key' ) ), 'staging_key_made' );
		$key = AB_MCP_Sync_Key::current();
		self::assertIsArray( $key );
		self::landed( $this->submit( 'handle_staging_key', array( '_wpnonce' => 'nonce-ab_mcp_staging_key' ) ), 'staging_key_kept' );
		self::assertSame( $key, AB_MCP_Sync_Key::current() );
		$this->on( 'live' );
		self::landed( $this->submit( 'handle_staging_key', array( '_wpnonce' => 'nonce-ab_mcp_staging_key' ) ), 'staging_trust_sync_key_role' );
	}

	public function testTheKeyIsConfirmedOnlyBehindTheNonceTheCapabilityAndTheFingerprint(): void {
		$this->paired();
		$code = $this->code();
		$this->on( 'live' );
		self::landed( $this->submit( 'handle_staging_anchor', array( '_wpnonce' => 'nonce-ab_mcp_staging_anchor', 'do' => 'code', 'pairing_code' => 'nonsense' ) ), 'staging_trust_sync_key_code' );
		self::landed( $this->submit( 'handle_staging_anchor', array( '_wpnonce' => 'nonce-ab_mcp_staging_anchor', 'do' => 'code', 'pairing_code' => $code ) ), 'staging_anchor_prepared' );
		$prepared = AB_MCP_Sync_Anchor::prepared();
		self::assertNotNull( $prepared );

		$confirm = array(
			'_wpnonce'    => 'nonce-ab_mcp_staging_anchor',
			'do'          => 'confirm',
			'fingerprint' => $prepared['fingerprint'],
		);
		self::assertSame( 'die', $this->submit( 'handle_staging_anchor', array_merge( $confirm, array( '_wpnonce' => 'nonce-ab_mcp_staging_key' ) ) )->kind );
		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		self::assertSame( 'die', $this->submit( 'handle_staging_anchor', $confirm )->kind );
		self::assertNull( AB_MCP_Sync_Anchor::active() );
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		self::landed( $this->submit( 'handle_staging_anchor', array_merge( $confirm, array( 'fingerprint' => 'AAAA AAAA AAAA AAAA AAAA AAAA AAAA AAAA' ) ) ), 'staging_trust_sync_anchor_fingerprint' );
		self::assertNull( AB_MCP_Sync_Anchor::active() );
		self::landed( $this->submit( 'handle_staging_anchor', $confirm ), 'staging_anchor_confirmed' );
		self::assertSame( $prepared['key_id'], AB_MCP_Sync_Anchor::active()['key_id'] );
		self::assertSame( 3, AB_MCP_Sync_Anchor::active()['user_id'] );

		self::landed( $this->submit( 'handle_staging_anchor', array( '_wpnonce' => 'nonce-ab_mcp_staging_anchor', 'do' => 'other' ) ), 'staging_anchor_unknown' );
		self::answers( array( self::key_url() => ab_test_http_response( 200, $this->route_body() ) ) );
		$this->on( 'live' );
		self::landed( $this->submit( 'handle_staging_anchor', array( '_wpnonce' => 'nonce-ab_mcp_staging_anchor', 'do' => 'fetch' ) ), 'staging_anchor_prepared' );
		self::assertSame( 'route', AB_MCP_Sync_Anchor::prepared()['via'] );
	}

	public function testEveryNoticeOfTheFormsIsOneThePageShows(): void {
		foreach ( AB_MCP_Admin::TRUST_NOTICES as $notice => $entry ) {
			$_GET = array( 'ab_notice' => $notice );
			ob_start();
			( new ReflectionMethod( AB_MCP_Admin::class, 'notice' ) )->invoke( new AB_MCP_Admin() );
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'notice-' . $entry[0], $html, $notice );
			self::assertStringContainsString( esc_html( $entry[1] ), $html, $notice );
		}
		$_GET = array();
		// Every refusal the forms can meet has its notice.
		foreach ( array( 'sync_key_role', 'sync_key_failed', 'sync_anchor_role', 'sync_anchor_pair', 'sync_anchor_not_https', 'sync_anchor_unreached', 'sync_anchor_no_key', 'sync_key_answer', 'sync_key_code', 'sync_anchor_site', 'sync_anchor_nothing', 'sync_anchor_stale', 'sync_anchor_fingerprint' ) as $code ) {
			self::assertArrayHasKey( 'staging_trust_' . $code, AB_MCP_Admin::TRUST_NOTICES, $code );
		}
	}

	public function testTheStagingSiteShowsItsFingerprintAndCode(): void {
		$this->paired();
		$this->on( 'staging' );
		$key  = AB_MCP_Sync_Key::current();
		$html = $this->card();
		self::assertStringContainsString( 'id="ab-staging-trust"', $html );
		self::assertStringContainsString( '<code class="ab-staging-trust__fingerprint">' . $key['fingerprint'] . '</code>', $html );
		self::assertStringContainsString( '>' . AB_MCP_Sync_Key::pairing_code() . '</textarea>', $html );
		self::assertStringNotContainsString( 'value="ab_mcp_staging_key"', $html, 'No button to make a second key.' );

		delete_option( AB_MCP_Sync_Key::OPTION_SECRET );
		$html = $this->card();
		self::assertStringContainsString( 'value="ab_mcp_staging_key"', $html, 'Without a key: the button that makes it.' );
		self::assertStringNotContainsString( 'ab-staging-trust__fingerprint', $html );
	}

	public function testTheProductionSiteShowsTheAnchorAndConfirmsOnlyThePreparedFingerprint(): void {
		$this->paired();
		$code = $this->code();
		$this->on( 'live' );
		$html = $this->card();
		self::assertStringContainsString( 'No key of the staging site https://staging.example.com is confirmed here yet', $html );
		self::assertStringContainsString( 'name="do" value="fetch"', $html );
		self::assertStringContainsString( 'name="pairing_code"', $html );
		self::assertStringNotContainsString( 'value="confirm"', $html, 'Nothing to confirm before a key is prepared.' );

		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		$html     = $this->card();
		self::assertStringContainsString( '<input type="hidden" name="do" value="confirm"><input type="hidden" name="fingerprint" value="' . $prepared['fingerprint'] . '">', $html, 'The button confirms the fingerprint the page shows.' );
		AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 3 );
		$html = $this->card();
		self::assertStringContainsString( 'Confirmed by user3 on', $html );
		self::assertStringContainsString( $prepared['fingerprint'], $html );
		self::assertStringNotContainsString( 'value="confirm"', $html );

		AB_MCP_Staging::save( 'production', 'http://staging.example.com', null, 3, 'form' );
		$html = $this->card();
		self::assertStringNotContainsString( 'name="do" value="fetch"', $html, 'An http:// staging site is not fetched from.' );
		self::assertStringContainsString( 'is not an https:// address', $html );
	}
}
