<?php
/**
 * The packages of the takeover (Z8, decision 6 of the plan of 01.11.2026;
 * contract B), as far as a test without a database, a protected store and a
 * web server shows them:
 *
 * - pack(): each object one line of the canonical encoding, no line split
 *   between two chunks, each chunk the gzip of its lines, at least one
 *   chunk; an object larger than a chunk or not encodable is refused, and
 *   so is a package past each limit of the manifest, by name; the gzip of
 *   the largest slice stays within a chunk.
 * - kinds(): each kind and subtype once, ascending; complete only for a kind
 *   of the database the package began at its start and read to its end.
 * - The fetch address: the MAC of the key of this staging site over package,
 *   chunk and until when (AB_MCP_Sync_Key::mac()); none without the key.
 * - The route takes only a request that is exactly right (grant()) and
 *   leaves every other one, and every other path, to WordPress, which
 *   answers it as a route it does not know.
 * - The relay needs https where the site has a public address.
 * - Nothing is made or given out on a network, by a site that is no
 *   confirmed staging site with a pair and a key, for another purpose than
 *   the inventory, or from a cursor that is not one.
 * - The production site builds the fetch address from the stored address
 *   of its staging site.
 * - The three tools are registered like the others: manage_options, the
 *   access level Full, not held by «Staging first», not destructive.
 *
 * The protected store, the route over HTTP, the direct fetch and the relay
 * between two WordPress sites: tests/integration/SyncPackageTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Staging;
use AB_MCP_Staging_Gate;
use AB_MCP_Sync_Export;
use AB_MCP_Sync_Grant;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Key;
use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Manifest;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

require_once __DIR__ . '/site-info-stubs.php';
require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-staging-gate.php';
require_once __DIR__ . '/../includes/sync/class-sync-import.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';
require_once __DIR__ . '/SyncTrustSites.php';

final class SyncPackageTest extends TestCase {

	use SyncTrustSites;

	private const IP = '93.184.216.34';

	private const ID = 'ab12ab12ab12ab12ab12ab12ab12ab12';

	protected function setUp(): void {
		ab_test_reset();
		AB_MCP_Sync_Kinds::reset();
		$GLOBALS['ab_test_can']         = static fn(): bool => true;
		$GLOBALS['ab_test_http']        = array();
		$GLOBALS['ab_test_http_answer'] = null;
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		add_filter( 'ab_mcp_outbound_addresses', static fn( $found, string $host ) => array( self::IP ), 10, 2 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ab_test_home'], $GLOBALS['wpdb'] );
		AB_MCP_Sync_Kinds::reset();
		\AB_MCP_Protected_Store::forget();
	}

	/* --------------------------------------------------------------- pack */

	/** The lines of a chunk, inflated. */
	private static function lines( string $chunk ): array {
		$text = (string) gzdecode( $chunk );
		return '' === $text ? array() : explode( "\n", rtrim( $text, "\n" ) );
	}

	public function testEachObjectIsOneLineAndNoLineIsSplitBetweenChunks(): void {
		$objects = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$objects[] = array(
				'kind' => 'post',
				'pad'  => str_repeat( 'x', 30 ),
				'ref'  => (string) $i,
			);
		}
		$line   = strlen( \AB_MCP_Sync_Canon::encode( $objects[0] ) ) + 1;
		$packed = AB_MCP_Sync_Export::pack( $objects, 3 * $line );
		self::assertIsArray( $packed );
		self::assertCount( 3, $packed['chunks'], 'Three lines a chunk: 3, 3, 1.' );
		self::assertSame( 7, $packed['objects'] );
		self::assertSame( 7 * $line, $packed['unpacked'] );
		$read = array();
		foreach ( $packed['chunks'] as $chunk ) {
			self::assertSame( "\x1f\x8b", substr( $chunk, 0, 2 ), 'Each chunk a gzip of its own.' );
			foreach ( self::lines( $chunk ) as $text ) {
				$read[] = json_decode( $text, true );
			}
		}
		self::assertSame( $objects, $read, 'Every object once, in order, whole.' );
		self::assertCount( 3, self::lines( $packed['chunks'][0] ) );
		self::assertCount( 1, self::lines( $packed['chunks'][2] ) );
		self::assertCount( 1, AB_MCP_Sync_Export::pack( $objects, 7 * $line )['chunks'], 'A slice holds as many lines as fit exactly.' );
	}

	public function testAPackageHasAChunkAlsoWithoutAnObject(): void {
		$packed = AB_MCP_Sync_Export::pack( array() );
		self::assertCount( 1, $packed['chunks'] );
		self::assertSame( '', gzdecode( $packed['chunks'][0] ) );
		self::assertSame( 0, $packed['objects'] );
		self::assertSame( 0, $packed['unpacked'] );
	}

	/** @return array<string,array{0:array,1:int}> */
	public static function unpackable(): array {
		return array(
			'larger than a chunk' => array( array( 'kind' => 'post', 'text' => str_repeat( 'y', 100 ) ), 50 ),
			'one byte too large'  => array( array( 'k' => 'v' ), strlen( '{"k":"v"}' ) ),
			'not encodable'       => array( array( 'kind' => 'post', 'deep' => self::deep( 4100 ) ), 100000 ),
		);
	}

	/** A value nested deeper than JSON writes it (AB_MCP_Sync_Canon::encode() gives ''). */
	private static function deep( int $levels ): array {
		$value = array();
		for ( $i = 0; $i < $levels; $i++ ) {
			$value = array( $value );
		}
		return $value;
	}

	#[DataProvider( 'unpackable' )]
	public function testAnObjectThatNoChunkHoldsIsRefused( array $object, int $slice ): void {
		$refused = AB_MCP_Sync_Export::pack( array( $object ), $slice );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_sync_export_object', $refused->get_error_code() );
		self::assertSame( 'post' === ( $object['kind'] ?? null ) ? 'post' : null, $refused->get_error_data()['kind'] );
		self::assertTrue( AB_MCP_Sync_Export::pack( array( array( 'k' => 'v' ) ), strlen( '{"k":"v"}' ) + 1 )['objects'] === 1, 'Exactly a slice fits.' );
	}

	/** @return array<string,array{0:string,1:array,2:int}> */
	public static function limits(): array {
		return array(
			'objects'  => array( 'objects', array( 'objects' => 2 ), 3 ),
			'unpacked' => array( 'unpacked', array( 'unpacked' => 20 ), 3 ),
			'chunks'   => array( 'chunks', array( 'chunks' => 2 ), 3 ),
			'bytes'    => array( 'bytes', array( 'bytes' => 40 ), 3 ),
		);
	}

	#[DataProvider( 'limits' )]
	public function testEachLimitOfTheManifestIsHeldByName( string $what, array $max, int $count ): void {
		$objects = array_fill( 0, $count, array( 'k' => 'vvvvvvvvvvvvvvvvvvvv' ) );
		$refused = AB_MCP_Sync_Export::pack( $objects, 30, $max );
		self::assertInstanceOf( \WP_Error::class, $refused, $what );
		self::assertSame( 'ab_mcp_sync_export_too_large', $refused->get_error_code() );
		self::assertSame( $what, $refused->get_error_data()['limit'] );
		$within = array_map( static fn( $v ) => $v * 100, $max );
		self::assertIsArray( AB_MCP_Sync_Export::pack( $objects, 30, $within ), 'Within it, the package is made.' );
	}

	public function testTheLimitsAreTheManifestsWhereNoneIsPassed(): void {
		$refused = AB_MCP_Sync_Export::pack( array_fill( 0, AB_MCP_Sync_Manifest::MAX_OBJECTS + 1, array() ) );
		self::assertSame( 'objects', $refused->get_error_data()['limit'] );
		self::assertSame( AB_MCP_Sync_Manifest::MAX_OBJECTS, $refused->get_error_data()['max'] );
		self::assertIsArray( AB_MCP_Sync_Export::pack( array_fill( 0, AB_MCP_Sync_Manifest::MAX_OBJECTS, array() ) ) );
	}

	public function testTheGzipOfTheLargestSliceStaysWithinAChunk(): void {
		self::assertSame( AB_MCP_Sync_Manifest::MAX_CHUNK - 65536, AB_MCP_Sync_Export::SLICE );
		self::assertSame( AB_MCP_Sync_Manifest::MAX_OBJECTS - 1000, AB_MCP_Sync_Export::OBJECTS );
		self::assertSame( 600, AB_MCP_Sync_Grant::GRANT_TTL );
		// Text that does not compress: one line of a full slice.
		$text   = substr( base64_encode( random_bytes( AB_MCP_Sync_Export::SLICE ) ), 0, AB_MCP_Sync_Export::SLICE - 12 );
		$packed = AB_MCP_Sync_Export::pack( array( array( 't' => $text ) ) );
		self::assertCount( 1, $packed['chunks'] );
		self::assertLessThanOrEqual( AB_MCP_Sync_Manifest::MAX_CHUNK, strlen( $packed['chunks'][0] ) );
	}

	/* -------------------------------------------------------------- kinds */

	/** Objects of every kind of the database, as the inventory names them. */
	private static function objects(): array {
		return array(
			array( 'kind' => 'post', 'identity' => array( 'post_type' => 'page', 'post_name' => 'a' ) ),
			array( 'kind' => 'post', 'identity' => array( 'post_type' => 'post', 'post_name' => 'b' ) ),
			array( 'kind' => 'post', 'identity' => array( 'post_type' => 'page', 'post_name' => 'c' ) ),
			array( 'kind' => 'term', 'identity' => array( 'taxonomy' => 'category', 'slug' => 'x' ) ),
			array( 'kind' => 'menu', 'identity' => array() ),
			array( 'kind' => 'menu_item', 'identity' => array( 'type' => 'custom' ) ),
			array( 'kind' => 'widget_area', 'identity' => array( 'area' => 'sidebar-1' ) ),
			array( 'kind' => 'option', 'identity' => array( 'name' => 'blogname' ) ),
			array( 'kind' => 'theme_mod', 'identity' => array( 'name' => 'x' ) ),
		);
	}

	public function testKindsCarryTheirSubtypeAscendingAndOnce(): void {
		$kinds = AB_MCP_Sync_Export::kinds( self::objects(), null, null );
		self::assertSame(
			array(
				array( 'complete' => true, 'kind' => 'menu', 'subtype' => '' ),
				array( 'complete' => true, 'kind' => 'menu_item', 'subtype' => '' ),
				array( 'complete' => true, 'kind' => 'option', 'subtype' => '' ),
				array( 'complete' => true, 'kind' => 'post', 'subtype' => 'page' ),
				array( 'complete' => true, 'kind' => 'post', 'subtype' => 'post' ),
				array( 'complete' => true, 'kind' => 'term', 'subtype' => 'category' ),
				array( 'complete' => true, 'kind' => 'theme_mod', 'subtype' => '' ),
				array( 'complete' => true, 'kind' => 'widget_area', 'subtype' => '' ),
			),
			$kinds
		);
	}

	/** @return array<string,array{0:array|null,1:array|null,2:string[]}> From, to, the kinds complete. */
	public static function reaches(): array {
		return array(
			'all of it'                      => array( null, null, array( 'menu', 'menu_item', 'option', 'post', 'term', 'theme_mod', 'widget_area' ) ),
			'ended in the terms'             => array( null, array( 'kind' => 'term', 'inner' => array( 'after' => 4 ) ), array( 'post' ) ),
			'ended before the terms'         => array( null, array( 'kind' => 'term', 'inner' => null ), array( 'post' ) ),
			'began in the terms'             => array( array( 'kind' => 'term', 'inner' => array( 'after' => 4 ) ), null, array( 'menu', 'menu_item', 'option', 'theme_mod', 'widget_area' ) ),
			'began at the terms'             => array( array( 'kind' => 'term', 'inner' => null ), null, array( 'menu', 'menu_item', 'option', 'term', 'theme_mod', 'widget_area' ) ),
			'within the menus'               => array( array( 'kind' => 'menu', 'inner' => array( 'after' => 1 ) ), array( 'kind' => 'menu', 'inner' => array( 'after' => 9 ) ), array() ),
			'from the terms to the options'  => array( array( 'kind' => 'term', 'inner' => null ), array( 'kind' => 'option', 'inner' => null ), array( 'custom_css', 'menu', 'menu_item', 'term', 'widget_area' ) ),
		);
	}

	#[DataProvider( 'reaches' )]
	public function testAKindIsCompleteOnlyWhereThePackageReadItFromItsStartToItsEnd( ?array $from, ?array $to, array $complete ): void {
		$found = array();
		foreach ( AB_MCP_Sync_Export::kinds( self::objects(), $from, $to ) as $kind ) {
			if ( $kind['complete'] ) {
				$found[] = $kind['kind'];
			}
		}
		$found = array_values( array_unique( $found ) );
		self::assertSame( array_values( array_diff( $complete, array( 'custom_css' ) ) ), $found );
	}

	public function testAKindAndSubtypeWithAWithheldObjectIsNotComplete(): void {
		$complete = array();
		foreach ( AB_MCP_Sync_Export::kinds( self::objects(), null, null, array( "post\0page" => true, "menu\0" => true ) ) as $kind ) {
			$complete[ $kind['kind'] . ':' . $kind['subtype'] ] = $kind['complete'];
		}
		self::assertFalse( $complete['post:page'] );
		self::assertTrue( $complete['post:post'], 'Another subtype of the same kind stays complete.' );
		self::assertFalse( $complete['menu:'] );
		self::assertTrue( $complete['menu_item:'] );
		self::assertSame( 6, count( array_filter( $complete ) ) );
	}

	/* ------------------------------------------------ the fetch address */

	public function testAFetchAddressIsTheMacOfTheKeyOfThisStagingSite(): void {
		$this->paired();
		$this->on( 'staging' );
		$grants = AB_MCP_Sync_Grant::grants( self::ID, 2, 1000 );
		self::assertSame( array( 0, 1 ), array_column( $grants, 'chunk' ) );
		self::assertSame( array( 1600, 1600 ), array_column( $grants, 'expires' ) );
		self::assertSame( AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', self::ID . "\n1\n1600" ), $grants[1]['signature'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}\z/', $grants[0]['signature'] );
		self::assertNotSame( $grants[0]['signature'], $grants[1]['signature'] );
		self::assertSame( self::ID . "\n1\n1600", AB_MCP_Sync_Grant::grant_text( self::ID, 1, 1600 ) );
		self::assertSame( 'chunk-0007', AB_MCP_Sync_Export::chunk_name( 7 ) );

		$this->on( 'live' );
		$none = AB_MCP_Sync_Grant::grants( self::ID, 2, 1000 );
		self::assertInstanceOf( \WP_Error::class, $none, 'The production site has no key.' );
		self::assertSame( 'ab_mcp_sync_key_missing', $none->get_error_code() );
	}

	public function testTheMacOfAStagingSiteIsItsOwnAndPerPurpose(): void {
		$this->paired();
		$this->on( 'staging' );
		$mac    = AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'text' );
		$stored = get_option( AB_MCP_Sync_Key::OPTION_MAC );
		self::assertSame( array( 'key_id', 'mac' ), array_keys( $stored ) );
		self::assertSame( AB_MCP_Sync_Key::current()['key_id'], $stored['key_id'] );
		self::assertSame( hash_hmac( 'sha256', "ab-sync-mac/1\nab-sync-fetch/1\ntext", (string) hex2bin( $stored['mac'] ) ), $mac, 'Keyed with the secret of the MACs.' );
		self::assertNotSame( hash_hmac( 'sha256', "ab-sync-mac/1\nab-sync-fetch/1\ntext", $this->secret() ), $mac, 'Not with the private key.' );
		self::assertFalse( $GLOBALS['ab_test_autoload'][ AB_MCP_Sync_Key::OPTION_MAC ], 'Without autoload.' );
		self::assertNotSame( $mac, AB_MCP_Sync_Key::mac( 'ab-sync-other/1', 'text' ), 'Another purpose, another code.' );
		foreach ( array( '', 'Ab', "a\nb", str_repeat( 'a', 65 ), 7 ) as $context ) {
			self::assertNull( AB_MCP_Sync_Key::mac( $context, 'text' ), var_export( $context, true ) );
		}
		self::assertNotNull( AB_MCP_Sync_Key::mac( str_repeat( 'a', 64 ), 'text' ) );
		foreach ( array(
			'of another key'   => array( 'key_id' => str_repeat( 'f', 32 ) ) + $stored,
			'not hex'          => array( 'mac' => str_repeat( 'g', 64 ) ) + $stored,
			'short'            => array( 'mac' => substr( $stored['mac'], 1 ) ) + $stored,
			'a list as secret' => array( 'mac' => array() ) + $stored,
			'no key id'        => array( 'mac' => $stored['mac'] ),
			'no array'         => 'x',
		) as $case => $odd ) {
			update_option( AB_MCP_Sync_Key::OPTION_MAC, $odd, false );
			self::assertNull( AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'text' ), 'A secret ' . $case . ' makes no MAC.' );
		}
		update_option( AB_MCP_Sync_Key::OPTION_MAC, $stored, false );
		$source = get_option( AB_MCP_Sync_Key::OPTION_SOURCE );
		update_option( AB_MCP_Sync_Key::OPTION_SOURCE, 'x', false );
		self::assertNull( AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'text' ), 'Not without the key it belongs to.' );
		update_option( AB_MCP_Sync_Key::OPTION_SOURCE, $source, false );
		self::assertSame( $mac, AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'text' ) );
		// The role written past save(), which would remove the key: a site
		// that is no confirmed staging site has no MAC, whatever its options hold.
		$raw                                = get_option( 'ab_mcp_options' );
		$kept                               = $raw;
		$raw['staging']['role']['value']    = 'production';
		update_option( 'ab_mcp_options', $raw );
		self::assertNotNull( get_option( AB_MCP_Sync_Key::OPTION_SECRET ), 'The key is still stored.' );
		self::assertNull( AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'text' ), 'Only a confirmed staging site.' );
		update_option( 'ab_mcp_options', $kept );
		self::assertNotNull( AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'text' ) );
		AB_MCP_Sync_Key::forget();
		self::assertFalse( get_option( AB_MCP_Sync_Key::OPTION_MAC ), 'Removed with the key.' );
		self::assertNull( AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'text' ), 'Not without its key.' );
	}

	public function testANewKeyMakesEveryFetchAddressBeforeItWorthless(): void {
		$this->paired();
		$this->on( 'staging' );
		$grant = AB_MCP_Sync_Grant::grants( self::ID, 1, 1000 )[0];
		self::assertNotNull( AB_MCP_Sync_Grant::grant( self::request( self::query( $grant ) ), 1000 ) );
		self::assertIsArray( AB_MCP_Sync_Key::renew() );
		self::assertNull( AB_MCP_Sync_Grant::grant( self::request( self::query( $grant ) ), 1000 ) );
	}

	/* ---------------------------------------------------------- the route */

	/** A request of the route. */
	private static function request( array $query, string $method = 'GET' ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_method( $method );
		$request->set_route( '/alphabridge/v1' . AB_MCP_Sync_Grant::ROUTE );
		$request->set_query_params( $query );
		return $request;
	}

	/** The query of a fetch address. */
	private static function query( array $grant, string $package = self::ID ): array {
		return array(
			'package' => $package,
			'chunk'   => (string) $grant['chunk'],
			'expires' => (string) $grant['expires'],
			'sig'     => $grant['signature'],
		);
	}

	public function testTheRouteTakesARequestThatIsExactlyRight(): void {
		$this->paired();
		$this->on( 'staging' );
		$grant = AB_MCP_Sync_Grant::grants( self::ID, 3, 1000 )[2];
		self::assertSame( array( 'package' => self::ID, 'chunk' => 2 ), AB_MCP_Sync_Grant::grant( self::request( self::query( $grant ) ), 1000 ) );
		self::assertSame( array( 'package' => self::ID, 'chunk' => 2 ), AB_MCP_Sync_Grant::grant( self::request( self::query( $grant ) + array( 'rest_route' => '/alphabridge/v1/staging-package' ) ), 1000 ), 'rest_route routed it.' );
		self::assertNotNull( AB_MCP_Sync_Grant::grant( self::request( self::query( $grant ) ), 1599 ), 'A second before its end.' );
		self::assertSame( array( 'chunk', 'expires', 'package', 'sig' ), AB_MCP_Sync_Grant::QUERY );
	}

	/** @return array<string,array{0:callable}> What turns a right request into another one. */
	public static function deviations(): array {
		$q = static fn( array $over ) => static function ( array $query ) use ( $over ) {
			return array_merge( $query, $over );
		};
		return array(
			'POST'                    => array( null, 'POST' ),
			'HEAD'                    => array( null, 'HEAD' ),
			'another parameter'       => array( $q( array( 'x' => '1' ) ) ),
			'_envelope'               => array( $q( array( '_envelope' => '1' ) ) ),
			'a parameter missing'     => array( static fn( array $query ) => array_diff_key( $query, array( 'sig' => true ) ) ),
			'a parameter as a list'   => array( $q( array( 'chunk' => array( '2' ) ) ) ),
			'the package in capitals' => array( static fn( array $query ) => array( 'package' => strtoupper( $query['package'] ) ) + $query ),
			'the package short'       => array( static fn( array $query ) => array( 'package' => substr( $query['package'], 1 ) ) + $query ),
			'the chunk as 02'         => array( $q( array( 'chunk' => '02' ) ) ),
			'the chunk as +2'         => array( $q( array( 'chunk' => '+2' ) ) ),
			'the chunk with a space'  => array( $q( array( 'chunk' => '2 ' ) ) ),
			'until when as 01600'     => array( $q( array( 'expires' => '01600' ) ) ),
			'until when as 1600.0'    => array( $q( array( 'expires' => '1600.0' ) ) ),
			'the signature in capitals' => array( static fn( array $query ) => array( 'sig' => strtoupper( $query['sig'] ) ) + $query ),
			'another signature'       => array( $q( array( 'sig' => str_repeat( 'a', 64 ) ) ) ),
			'another chunk'           => array( $q( array( 'chunk' => '1' ) ) ),
			'another package'         => array( $q( array( 'package' => str_repeat( 'cd', 16 ) ) ) ),
			'another time'            => array( $q( array( 'expires' => '1599' ) ) ),
			'a form field'            => array( null, 'GET', array( 'x' => '1' ) ),
			'a body'                  => array( null, 'GET', array(), 'x' ),
		);
	}

	#[DataProvider( 'deviations' )]
	public function testEveryOtherRequestIsNone( ?callable $change, string $method = 'GET', array $form = array(), string $body = '' ): void {
		$this->paired();
		$this->on( 'staging' );
		$grant   = AB_MCP_Sync_Grant::grants( self::ID, 3, 1000 )[2];
		$query   = null === $change ? self::query( $grant ) : $change( self::query( $grant ) );
		$request = self::request( $query, $method );
		$request->set_body_params( $form );
		$request->set_body( $body );
		self::assertNull( AB_MCP_Sync_Grant::grant( $request, 1000 ) );
	}

	/** @return array<string,array{0:int,1:int}> Time the address is signed at, time it is asked. */
	public static function times(): array {
		return array(
			'at its end'          => array( 1000, 1600 ),
			'after its end'       => array( 1000, 1700 ),
			'made in the future'  => array( 1001, 1000 ),
			'valid too long'      => array( 1500, 1000 ),
		);
	}

	#[DataProvider( 'times' )]
	public function testAFetchAddressHoldsTenMinutesFromNowAtMost( int $signed, int $asked ): void {
		$this->paired();
		$this->on( 'staging' );
		$grant = AB_MCP_Sync_Grant::grants( self::ID, 1, $signed )[0];
		self::assertNull( AB_MCP_Sync_Grant::grant( self::request( self::query( $grant ) ), $asked ) );
		self::assertNotNull( AB_MCP_Sync_Grant::grant( self::request( self::query( $grant ) ), $signed ), 'Within its time.' );
	}

	public function testOnTheProductionSiteNoRequestIsRight(): void {
		$this->paired();
		$this->on( 'staging' );
		$grant = AB_MCP_Sync_Grant::grants( self::ID, 1, 1000 )[0];
		$this->on( 'live' );
		self::assertNull( AB_MCP_Sync_Grant::grant( self::request( self::query( $grant ) ), 1000 ) );
	}

	public function testEveryOtherRequestAndPathGoesOnToWordPress(): void {
		// The protected store, without a proof here: no package is kept.
		$GLOBALS['wpdb'] = new \AbTestSyncWpdb();
		\AB_MCP_Protected_Store::forget();
		$this->paired();
		$this->on( 'staging' );
		$grant = AB_MCP_Sync_Grant::grants( self::ID, 1, time() )[0];
		$other = self::request( self::query( $grant ) );
		$other->set_route( '/alphabridge/v1/staging-key' );
		self::assertNull( AB_MCP_Sync_Grant::serve( null, null, $other ), 'Another path.' );
		self::assertSame( 'x', AB_MCP_Sync_Grant::serve( 'x', null, self::request( self::query( $grant ) ) ), 'What another filter chose.' );
		self::assertNull( AB_MCP_Sync_Grant::serve( null, null, self::request( array() ) ), 'A request that is not right.' );
		self::assertNull( AB_MCP_Sync_Grant::serve( null, null, self::request( self::query( $grant ) ) ), 'A right request for a package this site does not keep.' );
		self::assertNull( AB_MCP_Sync_Grant::serve( null, null, null ) );
		self::assertTrue( AB_MCP_Sync_Grant::send( true, null, null ), 'What another filter served stays served.' );
		self::assertFalse( AB_MCP_Sync_Grant::send( false, new \WP_REST_Response( null ), null ), 'An answer serve() did not accept is not written.' );

		// Through the REST server: answered as a route WordPress does not know.
		add_filter( 'rest_pre_dispatch', array( 'AB_MCP_Sync_Grant', 'serve' ), 10, 3 );
		$unknown = new WP_REST_Request();
		$unknown->set_method( 'GET' );
		$unknown->set_route( '/alphabridge/v1/staging-nothing' );
		$want = ab_test_rest_dispatch( $unknown );
		foreach ( array( self::request( self::query( $grant ) ), self::request( array( 'x' => '1' ) ), self::request( self::query( $grant ), 'POST' ) ) as $request ) {
			$got = ab_test_rest_dispatch( $request );
			self::assertSame( 404, $got->get_status() );
			self::assertSame( $want->get_data(), $got->get_data() );
			self::assertSame( $want->get_headers(), $got->get_headers() );
		}
		self::assertSame( 'rest_no_route', $want->get_data()['code'] );
	}

	/* ---------------------------------------------------------- the relay */

	public function testTheRelayNeedsHttpsWhereTheSiteHasAPublicAddress(): void {
		self::assertNull( AB_MCP_Sync_Export::relay_refusal(), 'Over https.' );
		$GLOBALS['ab_test_ssl'] = false;
		$refused                = AB_MCP_Sync_Export::relay_refusal();
		self::assertInstanceOf( \WP_Error::class, $refused, 'A public address over http.' );
		self::assertSame( 'ab_mcp_sync_relay_http', $refused->get_error_code() );
		foreach ( array( 'http://127.0.0.1:8080', 'http://localhost', 'http://wp.localhost', 'http://[::1]' ) as $home ) {
			$GLOBALS['ab_test_home'] = $home;
			self::assertNull( AB_MCP_Sync_Export::relay_refusal(), $home . ': no public address.' );
		}
		$GLOBALS['ab_test_home'] = 'http://staging.example.lan';
		add_filter( 'ab_mcp_outbound_addresses', static fn() => array( '10.0.0.5' ), 20 );
		self::assertNull( AB_MCP_Sync_Export::relay_refusal(), 'A name of a private network.' );
		add_filter( 'ab_mcp_outbound_addresses', static fn() => array(), 30 );
		self::assertInstanceOf( \WP_Error::class, AB_MCP_Sync_Export::relay_refusal(), 'A name that does not resolve counts as public.' );
	}

	public function testTheStagingSiteGivesNoChunkOverHttpWhereItIsPublic(): void {
		$this->paired();
		$this->on( 'staging' );
		$GLOBALS['ab_test_ssl'] = false;
		self::assertSame( 'ab_mcp_sync_relay_http', AB_MCP_Sync_Export::chunk( self::ID, 0 )->get_error_code() );
	}

	/* ---------------------------------------------------- what is refused */

	public function testOnlyAConfirmedStagingSiteWithAPairAndAKeyMakesOrGivesOutAPackage(): void {
		foreach ( array( 'make' => array( 'inventory' ), 'again' => array( self::ID ), 'chunk' => array( self::ID, 0 ) ) as $method => $args ) {
			ab_test_reset();
			$GLOBALS['ab_test_can'] = static fn(): bool => true;
			ab_test_add_user( 3 );
			\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
			self::assertSame( 'ab_mcp_sync_manifest_role', AB_MCP_Sync_Export::$method( ...$args )->get_error_code(), $method . ': no confirmed role.' );
			self::assertIsArray( AB_MCP_Staging::save( 'staging', '', null, 3, 'form' ) );
			self::assertSame( 'ab_mcp_sync_manifest_pair', AB_MCP_Sync_Export::$method( ...$args )->get_error_code(), $method . ': no pair.' );
			self::assertIsArray( AB_MCP_Staging::save( 'staging', 'https://www.example.com', null, 3, 'form' ) );
			\AB_MCP_Sync_Key::forget();
			$refused = AB_MCP_Sync_Export::$method( ...$args );
			self::assertSame( 'ab_mcp_sync_key_missing', $refused->get_error_code(), $method . ': no key.' );
			$GLOBALS['ab_test_multisite'] = true;
			self::assertSame( 'ab_mcp_sync_multisite', AB_MCP_Sync_Export::$method( ...$args )->get_error_code(), $method . ': a network.' );
		}
		self::assertStringContainsString( 'wp_export_content', AB_MCP_Sync_Export::multisite()->get_error_message() );
	}

	public function testAKeyWithoutTheSecretOfItsMacsGetsOneAndIsNotStuck(): void {
		$this->paired();
		$this->on( 'staging' );
		$key  = AB_MCP_Sync_Key::current();
		$kept = get_option( AB_MCP_Sync_Key::OPTION_MAC );
		self::assertTrue( AB_MCP_Sync_Key::mac_ready() );
		self::assertSame( $kept, get_option( AB_MCP_Sync_Key::OPTION_MAC ), 'A secret that is there stays.' );
		delete_option( AB_MCP_Sync_Key::OPTION_MAC );
		self::assertNull( AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'x' ), 'No secret, no MAC.' );
		// The next export makes it: refused for the package, not for the key.
		self::assertSame( 'ab_mcp_sync_export_purpose', AB_MCP_Sync_Export::make( 'objects' )->get_error_code() );
		self::assertNotNull( AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'x' ) );
		self::assertSame( $key, AB_MCP_Sync_Key::current(), 'The key and its confirmation stay.' );
		self::assertFalse( $GLOBALS['ab_test_autoload'][ AB_MCP_Sync_Key::OPTION_MAC ] );
		// So does make() of the key.
		delete_option( AB_MCP_Sync_Key::OPTION_MAC );
		$made = AB_MCP_Sync_Key::make();
		self::assertFalse( $made['made'] );
		self::assertSame( $key, $made['key'] );
		self::assertNotNull( AB_MCP_Sync_Key::mac( 'ab-sync-fetch/1', 'x' ) );
		// A secret of another key id is none and is made anew.
		$raw           = get_option( AB_MCP_Sync_Key::OPTION_MAC );
		$raw['key_id'] = str_repeat( '0', 32 );
		update_option( AB_MCP_Sync_Key::OPTION_MAC, $raw, false );
		self::assertTrue( AB_MCP_Sync_Key::mac_ready() );
		self::assertSame( $key['key_id'], get_option( AB_MCP_Sync_Key::OPTION_MAC )['key_id'] );
		// A write that does not stick: the way, which names the option.
		delete_option( AB_MCP_Sync_Key::OPTION_MAC );
		add_filter( 'pre_update_option_' . AB_MCP_Sync_Key::OPTION_MAC, static fn( $value, $old ) => $old, 10, 2 );
		self::assertFalse( AB_MCP_Sync_Key::mac_ready() );
		$refused = AB_MCP_Sync_Export::make( 'inventory' );
		self::assertSame( 'ab_mcp_sync_mac_failed', $refused->get_error_code() );
		self::assertStringContainsString( AB_MCP_Sync_Key::OPTION_MAC, $refused->get_error_message() );
		self::assertSame( 'ab_mcp_sync_mac_failed', AB_MCP_Sync_Key::make()->get_error_code() );
		AB_MCP_Sync_Key::forget();
		self::assertFalse( AB_MCP_Sync_Key::mac_ready(), 'No key, nothing to make it for.' );
	}

	public function testAPairThatGoesAndComesBackWithinASecondIsAnotherBinding(): void {
		$this->paired();
		$this->on( 'staging' );
		$first = AB_MCP_Sync_Export::binding();
		$was   = get_option( 'ab_mcp_options' )['staging'];
		self::assertIsArray( AB_MCP_Staging::save( 'staging', 'https://shop.example.com', null, 3, 'form' ) );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', self::address( 'live' ), null, 3, 'form' ) );
		$opts = get_option( 'ab_mcp_options' );
		$bind = $opts['staging']['bind'];
		self::assertNotSame( $was['bind'], $bind );
		// All as it was, who and when to the second, but the random value.
		$opts['staging'] = array( 'bind' => $bind ) + $was;
		update_option( 'ab_mcp_options', $opts );
		self::assertNotSame( $first, AB_MCP_Sync_Export::binding() );
		$opts['staging']['bind'] = $was['bind'];
		update_option( 'ab_mcp_options', $opts );
		self::assertSame( $first, AB_MCP_Sync_Export::binding(), 'Only the random value differed.' );
		// Without it no binding; the next export makes one.
		unset( $opts['staging']['bind'] );
		update_option( 'ab_mcp_options', $opts );
		self::assertNull( AB_MCP_Sync_Export::binding() );
		self::assertSame( 'ab_mcp_sync_export_purpose', AB_MCP_Sync_Export::make( 'objects' )->get_error_code() );
		self::assertNotNull( AB_MCP_Staging::bind() );
		self::assertNotSame( $first, AB_MCP_Sync_Export::binding() );
		// Where it cannot be stored: no package, and the way.
		$opts = get_option( 'ab_mcp_options' );
		unset( $opts['staging']['bind'] );
		update_option( 'ab_mcp_options', $opts );
		add_filter( 'pre_update_option_ab_mcp_options', static fn( $value, $old ) => $old, 10, 2 );
		self::assertNull( AB_MCP_Staging::new_bind() );
		self::assertSame( 'ab_mcp_sync_export_unbound', AB_MCP_Sync_Export::make( 'inventory' )->get_error_code() );
	}

	public function testOnlyAnAccountWithManageOptionsAndWriteAccessOnMakesOrGivesOutAPackage(): void {
		$this->paired();
		$this->on( 'staging' );
		foreach ( array( 'make' => array( 'objects' ), 'again' => array( self::ID ), 'chunk' => array( self::ID, 0 ) ) as $method => $args ) {
			$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' !== $cap;
			$refused                = AB_MCP_Sync_Export::$method( ...$args );
			self::assertSame( 'ab_mcp_sync_export_capability', $refused->get_error_code(), $method );
			self::assertStringContainsString( 'manage_options', $refused->get_error_message() );
			$GLOBALS['ab_test_can'] = static fn(): bool => true;
			\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::READ );
			$refused = AB_MCP_Sync_Export::$method( ...$args );
			self::assertSame( 'ab_mcp_sync_export_mode', $refused->get_error_code(), $method );
			self::assertStringContainsString( \AB_MCP_Site_Mode::way_text(), $refused->get_error_message() );
			\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		}
		// The way: with both, the request goes on to what it asks.
		self::assertSame( 'ab_mcp_sync_export_purpose', AB_MCP_Sync_Export::make( 'objects' )->get_error_code() );
		$GLOBALS['ab_test_ssl'] = false;
		self::assertSame( 'ab_mcp_sync_relay_http', AB_MCP_Sync_Export::chunk( self::ID, 0 )->get_error_code() );
	}

	public function testAChunkIsInflatedNoFurtherThanItMay(): void {
		$text = str_repeat( "a line\n", 1000 );
		self::assertSame( $text, AB_MCP_Sync_Import::inflate( (string) gzencode( $text ), strlen( $text ) ) );
		self::assertNull( AB_MCP_Sync_Import::inflate( (string) gzencode( $text ), strlen( $text ) - 1 ), 'One byte past the cap.' );
		self::assertFalse( AB_MCP_Sync_Import::inflate( 'no gzip', 100 ) );
		self::assertFalse( AB_MCP_Sync_Import::inflate( '', 100 ) );
		self::assertFalse( AB_MCP_Sync_Import::inflate( substr( (string) gzencode( $text ), 0, 20 ), 100000 ), 'A cut gzip.' );
		self::assertSame( '', AB_MCP_Sync_Import::inflate( (string) gzencode( '' ), 0 ) );
		// A chunk that inflates far: the inflation stops at the cap.
		// 24 MiB of zeros, compressed piece by piece (no 24 MiB string here).
		$deflate = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 9 ) );
		$zeros   = str_repeat( "\0", 1048576 );
		$bomb    = '';
		for ( $i = 0; $i < 24; $i++ ) {
			$bomb .= deflate_add( $deflate, $zeros, ZLIB_NO_FLUSH );
		}
		$bomb .= deflate_add( $deflate, '', ZLIB_FINISH );
		unset( $zeros );
		memory_reset_peak_usage();
		$before = memory_get_peak_usage();
		self::assertNull( AB_MCP_Sync_Import::inflate( $bomb, 1048576 ) );
		self::assertLessThan( 12582912, memory_get_peak_usage() - $before, 'Not inflated to its end (24 MiB).' );
		self::assertSame( 512, AB_MCP_Sync_Import::INFLATE_PIECE );
		// One gzip member and nothing after it.
		self::assertFalse( AB_MCP_Sync_Import::inflate( (string) gzencode( $text ) . 'X', 100000 ), 'A byte after the member.' );
		self::assertFalse( AB_MCP_Sync_Import::inflate( (string) gzencode( $text ) . (string) gzencode( 'more' ), 100000 ), 'A second member.' );
		$long = str_repeat( 'x', 5000 );
		self::assertFalse( AB_MCP_Sync_Import::inflate( (string) gzencode( $long ) . str_repeat( 'Y', 3000 ), 100000 ), 'Bytes after it beyond one piece.' );
		// Past the cap by at most what one piece inflates to.
		memory_reset_peak_usage();
		$start = memory_get_usage();
		self::assertNull( AB_MCP_Sync_Import::inflate( $bomb, 0 ) );
		self::assertLessThan( 2097152, memory_get_peak_usage() - $start, 'One piece of 512 bytes inflates to some 516 KiB at most.' );
	}

	public function testTheListOfHeldObjectsIsNamedByItsHashInBase32(): void {
		$sha  = hash( 'sha256', 'held' );
		$name = AB_MCP_Sync_Import::held_name( $sha );
		self::assertMatchesRegularExpression( '/^held-[a-z2-7]{52}\z/', $name );
		self::assertLessThanOrEqual( 64, strlen( $name ), 'A name of the protected store.' );
		self::assertSame( $sha, AB_MCP_Sync_Import::held_sha( $name ) );
		foreach ( array( str_repeat( '0', 64 ), str_repeat( 'f', 64 ), hash( 'sha256', '' ) ) as $other ) {
			self::assertSame( $other, AB_MCP_Sync_Import::held_sha( AB_MCP_Sync_Import::held_name( $other ) ) );
		}
		$last = substr( $name, -1 );
		// The last letter carries one bit and four bits of 0: another one there is no such name.
		foreach ( array( 'held', $sha, 'held-' . $sha, substr( $name, 0, -1 ), $name . 'a', strtoupper( $name ), substr( $name, 0, -1 ) . ( 'b' === $last ? 'c' : 'b' ) ) as $wrong ) {
			self::assertNull( AB_MCP_Sync_Import::held_sha( $wrong ), $wrong );
		}
	}

	public function testTheListOfHeldObjectsFitsItsReservationWhateverTheRefs(): void {
		// Each object a kind, the SHA-256 of its ref and a code: the longest of each, for as many objects as a package may hold.
		$longest = static fn( array $list ) => array_reduce( $list, static fn( $a, $b ) => strlen( (string) $b ) > strlen( (string) $a ) ? (string) $b : (string) $a, '' );
		$entry   = array( $longest( array_keys( AB_MCP_Sync_Import::IDENTITIES ) ), str_repeat( 'f', 64 ), $longest( AB_MCP_Sync_Import::HELD_CODES ) );
		$text    = \AB_MCP_Sync_Canon::encode(
			array(
				'format'  => AB_MCP_Sync_Import::HELD_FORMAT,
				'objects' => array_fill( 0, \AB_MCP_Store_Budget::UNPACK['objects'], $entry ),
			)
		);
		self::assertLessThanOrEqual( AB_MCP_Sync_Import::HELD_MAX, strlen( $text ) );
		self::assertSame( 'ab-sync-held/3', AB_MCP_Sync_Import::HELD_FORMAT );
	}

	public function testThisVersionMakesPackagesOfTheInventoryAndTheEnvironmentOnly(): void {
		$this->paired();
		$this->on( 'staging' );
		foreach ( array( 'objects', 'media', 'ENV', null, 'INVENTORY' ) as $purpose ) {
			self::assertSame( 'ab_mcp_sync_export_purpose', AB_MCP_Sync_Export::make( $purpose )->get_error_code(), var_export( $purpose, true ) );
		}
		self::assertSame( array( 'inventory', 'env' ), AB_MCP_Sync_Export::PURPOSES );
	}

	/** @return array<string,array{0:mixed}> */
	public static function cursors(): array {
		return array(
			'text'                  => array( 'post' ),
			'a list'                => array( array( 'post', null, self::ID ) ),
			'no package'            => array( array( 'kind' => 'post', 'inner' => null ) ),
			'no inner cursor'       => array( array( 'kind' => 'post', 'package' => self::ID ) ),
			'another key'           => array( array( 'kind' => 'post', 'inner' => null, 'package' => self::ID, 'x' => 1 ) ),
			'a kind that is a list' => array( array( 'kind' => array( 'post' ), 'inner' => null, 'package' => self::ID ) ),
		);
	}

	#[DataProvider( 'cursors' )]
	public function testACursorIsOneAnAnswerGave( $cursor ): void {
		$this->paired();
		$this->on( 'staging' );
		self::assertSame( 'ab_mcp_sync_cursor_invalid', AB_MCP_Sync_Export::make( 'inventory', $cursor )->get_error_code() );
	}

	public function testTheProductionSiteTakesAPackageIdAndEitherAChunkOrFetchAddresses(): void {
		foreach ( array( array(), array( 'package_id' => 'x' ), array( 'package_id' => strtoupper( self::ID ) ), array( 'package_id' => 7 ) ) as $a ) {
			self::assertSame( 'ab_mcp_sync_import_package', AB_MCP_Sync_Import::call( $a )->get_error_code() );
		}
		foreach ( array( array( 'data' => 'eA==', 'fetch' => array() ), array( 'chunk' => 0, 'fetch' => array() ) ) as $both ) {
			self::assertSame( 'ab_mcp_sync_import_both', AB_MCP_Sync_Import::call( array( 'package_id' => self::ID ) + $both )->get_error_code() );
		}
		$GLOBALS['ab_test_multisite'] = true;
		self::assertSame( 'ab_mcp_sync_multisite', AB_MCP_Sync_Import::call( array( 'package_id' => self::ID ) )->get_error_code() );
	}

	public function testTheProductionSiteTakesNoRelayedChunkOverHttpWhereItIsPublic(): void {
		$GLOBALS['ab_test_ssl'] = false;
		foreach ( array( array( 'chunk' => 0, 'data' => 'eA==' ), array( 'data' => 'eA==' ), array( 'chunk' => 0 ) ) as $relay ) {
			self::assertSame( 'ab_mcp_sync_relay_http', AB_MCP_Sync_Import::call( array( 'package_id' => self::ID ) + $relay )->get_error_code(), 'Before anything is read.' );
		}
	}

	/** @return array<string,array{0:mixed}> */
	public static function fetchLists(): array {
		$grant = array(
			'chunk'     => 0,
			'expires'   => 1600,
			'signature' => str_repeat( 'a', 64 ),
		);
		return array(
			'text'                   => array( 'x' ),
			'an empty list'          => array( array() ),
			'not a list'             => array( array( 'a' => $grant ) ),
			'an item that is text'   => array( array( 'x' ) ),
			'a key missing'          => array( array( array_diff_key( $grant, array( 'expires' => true ) ) ) ),
			'a key more'             => array( array( $grant + array( 'x' => 1 ) ) ),
			'the chunk as text'      => array( array( array( 'chunk' => '0' ) + $grant ) ),
			'until when as text'     => array( array( array( 'expires' => '1600' ) + $grant ) ),
			'the signature as a list' => array( array( array( 'signature' => array() ) + $grant ) ),
			'the signature short'    => array( array( array( 'signature' => str_repeat( 'a', 63 ) ) + $grant ) ),
			'the signature with &'   => array( array( array( 'signature' => str_repeat( 'a', 60 ) . '&x=1' ) + $grant ) ),
			'one good, one not'      => array( array( $grant, array( 'chunk' => 1.0 ) + $grant ) ),
		);
	}

	#[DataProvider( 'fetchLists' )]
	public function testFetchIsAListOfFetchAddressesAsTheStagingSiteGivesThem( $fetch ): void {
		self::assertSame( 'ab_mcp_sync_import_fetch_list', AB_MCP_Sync_Import::call( array( 'package_id' => self::ID, 'fetch' => $fetch ) )->get_error_code(), 'Before anything is read or asked.' );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
	}

	/* ------------------------------------------------- the fetch address */

	#[RequiresPhpExtension( 'intl' )]
	public function testTheProductionSiteBuildsTheAddressFromTheStoredAddressOfItsStagingSite(): void {
		self::assertSame(
			'https://staging.example.com:8443/blog/?rest_route=/alphabridge/v1/staging-package&package=' . self::ID . '&chunk=2&expires=1600&sig=' . str_repeat( 'f', 64 ),
			AB_MCP_Sync_Import::url( 'https://staging.example.com:8443/blog', self::ID, 2, 1600, str_repeat( 'f', 64 ) )
		);
		self::assertStringStartsWith( 'https://xn--bcher-kva.example/?rest_route=', AB_MCP_Sync_Import::url( 'https://bücher.example', self::ID, 0, 1600, str_repeat( 'f', 64 ) ), 'In the form the anchor compares.' );
	}

	/* ----------------------------------------------------------- the tools */

	public function testTheThreeToolsAreRegisteredLikeTheOthers(): void {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		foreach ( array( 'wp_site_export', 'wp_site_export_chunk', 'wp_site_import_chunk' ) as $name ) {
			$def = $r->get( $name );
			self::assertNotNull( $def, $name );
			self::assertSame( 'manage_options', $def['capability'], $name );
			self::assertFalse( AB_MCP_Tool_Registry::is_read_only( $name, $def ), $name . ' counts as writing: the access level Full.' );
			self::assertFalse( AB_MCP_Tool_Registry::is_destructive( $name, $def ), $name . ' writes only the protected store.' );
			self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'full', $name, $def ) );
			self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'content', $name, $def ), $name );
			self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'read', $name, $def ), $name );
			self::assertFalse( AB_MCP_Staging_Gate::concerns( $name, $def ), $name . ' is the carrying over (CARRY_OVER).' );
		}
		self::assertTrue( AB_MCP_Tool_Registry::is_open_world( 'wp_site_import_chunk', $r->get( 'wp_site_import_chunk' ) ), 'With fetch it asks the staging site.' );
		self::assertSame( AB_MCP_Sync_Export::PURPOSES, AB_MCP_Tools_Staging::EXPORT_PURPOSES );
		self::assertSame( AB_MCP_Tools_Staging::EXPORT_PURPOSES, $r->get( 'wp_site_export' )['inputSchema']['properties']['purpose']['enum'] );
		self::assertSame( AB_MCP_Tools_Staging::EXPORT_PURPOSES, $r->get( 'wp_site_import_chunk' )['inputSchema']['properties']['purpose']['enum'], 'The import takes the inventory and the environment in this version.' );
		self::assertArrayNotHasKey( 'report_hash', $r->get( 'wp_site_import_chunk' )['inputSchema']['properties'] );
		foreach ( array( 'wp_site_export' => '10 minutes', 'wp_site_export_chunk' => 'without passing it through the model', 'wp_site_import_chunk' => 'following no redirect' ) as $name => $says ) {
			self::assertStringContainsString( $says, $r->get( $name )['description'], $name );
		}
	}

	public function testTheToolsHandTheirArgumentsOn(): void {
		$this->paired();
		$this->on( 'staging' );
		self::assertSame( 'ab_mcp_sync_export_purpose', AB_MCP_Tools_Staging::site_export( array( 'purpose' => 'objects' ) )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_cursor_invalid', AB_MCP_Tools_Staging::site_export( array( 'purpose' => 'inventory', 'cursor' => 'x' ) )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_export_unknown', AB_MCP_Tools_Staging::site_export( array( 'package_id' => 'x', 'purpose' => 'objects' ) )->get_error_code(), 'With package_id, the package made before.' );
		self::assertSame( 'ab_mcp_sync_export_unknown', AB_MCP_Tools_Staging::site_export_chunk( array( 'package_id' => 'x', 'chunk' => 0 ) )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_import_package', AB_MCP_Tools_Staging::site_import_chunk( array( 'package_id' => 'x' ) )->get_error_code() );
	}

	/* ------------------------------------------------- the line of an object */

	/** An object of the inventory with every field it may have, and some it may not. */
	private static function object( array $over = array() ): array {
		return array_merge(
			array(
				'kind'            => 'post',
				'ref'             => '12',
				'identity'        => array( 'post_type' => 'page', 'post_name' => 'about', 'theme' => '' ),
				'canon'           => 'canon/1',
				'hash'            => str_repeat( 'd', 64 ),
				'mapped'          => str_repeat( 'e', 64 ),
				'mapped_possible' => str_repeat( 'f', 64 ),
				'size'            => 321,
				'refs'            => array( array( 'space' => 'post', 'id' => 7, 'at' => 'meta:_thumbnail_id', 'strength' => 'strong', 'value' => 'RAW-REF' ) ),
				'possible'        => array( array( 'value' => 987654321, 'at' => 'meta:service_settings.count' ) ),
				'locks'           => array( array( 'key' => 'post:builder', 'stage' => 'Z8b', 'reason' => 'A builder.', 'at' => 'meta:_elementor_data', 'value' => 'RAW-LOCK' ) ),
				'credentials'     => array( 'b9' => array( 'meta:api_token' ), 'mixed' => array() ),
				'complete'        => true,
				'unread'          => array(),
				'status'          => 'z8',
				'form'            => array( 'RAW-FORM' ),
				'other'           => 'RAW-OTHER',
			),
			$over
		);
	}

	public function testALineCarriesWhatTheInventoryNeedsAndNoValueOfTheContent(): void {
		self::assertSame(
			array(
				'kind'            => 'post',
				'ref'             => '12',
				'identity'        => array( 'post_type' => 'page', 'post_name' => 'about', 'theme' => '' ),
				'status'          => 'z8',
				'locks'           => array( array( 'key' => 'post:builder', 'stage' => 'Z8b', 'reason' => 'A builder.', 'at' => 'meta:_elementor_data' ) ),
				'canon'           => 'canon/1',
				'hash'            => str_repeat( 'd', 64 ),
				'mapped'          => str_repeat( 'e', 64 ),
				'mapped_possible' => str_repeat( 'f', 64 ),
				'size'            => 321,
				'complete'        => true,
				'unread'          => array(),
				'refs'            => array( array( 'space' => 'post', 'id' => 7, 'at' => 'meta:_thumbnail_id', 'strength' => 'strong' ) ),
				'possible'        => array( array( 'at' => 'meta:service_settings.count' ) ),
				'b9'              => array( 'meta:api_token' ),
			),
			AB_MCP_Sync_Export::line( self::object() )
		);
		$line = AB_MCP_Sync_Export::line( self::object( array( 'reason' => 'Never compared.', 'unread' => array( 'postmeta' ) ) ) );
		self::assertArrayNotHasKey( 'reason', $line, 'No free text: the reasons are those of its locks.' );
		self::assertSame( array( 'postmeta' ), $line['unread'] );
		self::assertSame( array(), AB_MCP_Sync_Export::line( array_diff_key( self::object(), array( 'unread' => true ) ) )['unread'], 'An option without its own list of what was not read.' );
		self::assertStringNotContainsString( 'RAW', (string) json_encode( AB_MCP_Sync_Export::line( self::object() ) ) );
		self::assertStringNotContainsString( '987654321', (string) json_encode( AB_MCP_Sync_Export::line( self::object() ) ) );
		// A row the takeover never reads: the code of its reason, never the text, and the row WordPress reads instead.
		$alias = AB_MCP_Sync_Export::line( self::object( array( 'status' => 'unreachable', 'reason' => 'WordPress reads the row disallowed_keys under this name.', 'reason_code' => 'read_as_other_row', 'target' => 'disallowed_keys' ) ) );
		self::assertSame( array( 'read_as_other_row', 'disallowed_keys' ), array( $alias['reason_code'], $alias['target'] ) );
		self::assertArrayNotHasKey( 'reason', $alias );
		self::assertArrayNotHasKey( 'reason_code', AB_MCP_Sync_Export::line( self::object() ) );
	}

	/** @return array<string,array{0:array}> */
	public static function heldByACredential(): array {
		$out = array(
			'a field of a credential in it' => array( array( 'credentials' => array( 'b9' => array(), 'mixed' => array( array( 'unit' => 'meta:service_settings', 'fields' => array( 'api_key' ) ) ) ) ) ),
			'the name of a credential'      => array( array( 'status' => 'b9', 'hash' => null, 'reason' => 'The name is the name of a credential (B9).' ) ),
		);
		foreach ( AB_MCP_Sync_Export::CREDENTIAL_LOCKS as $op ) {
			$key                         = ( 'password_protected' === $op ? 'post:' : '*:' ) . $op;
			$out[ 'a lock ' . $key ] = array( array( 'locks' => array( self::object()['locks'][0], array( 'key' => $key, 'stage' => 'Z8d', 'reason' => 'Held.', 'at' => 'post_content' ) ) ) );
		}
		return $out;
	}

	public static function otherLocks(): array {
		$out = array();
		foreach ( array( '*:possible_reference', '*:reference_spelling', '*:unread_reference', 'option:off_schema', '*:meta_without_rule', 'attachment:shared_path' ) as $key ) {
			$out[ $key ] = array( $key );
		}
		return $out;
	}

	#[DataProvider( 'otherLocks' )]
	public function testAnObjectWithAnotherLockKeepsItsLineAsBefore( string $key ): void {
		$object = self::object( array( 'locks' => array( array( 'key' => $key, 'stage' => 'Z8d', 'reason' => 'Other.', 'at' => 'post_content' ) ) ) );
		$line   = AB_MCP_Sync_Export::line( $object );
		self::assertSame( str_repeat( 'd', 64 ), $line['hash'] );
		self::assertSame( array( array( 'space' => 'post', 'id' => 7, 'at' => 'meta:_thumbnail_id', 'strength' => 'strong' ) ), $line['refs'] );
		self::assertSame( array( array( 'at' => 'meta:service_settings.count' ) ), $line['possible'] );
	}

	public function testTheLocksOfTheCredentialCheckAreTheOnesItGives(): void {
		self::assertSame( array( 'credential_field', 'password_protected', 'script', 'html_outside_kses', 'php_object', 'unreadable_value', 'unread_credential' ), AB_MCP_Sync_Export::CREDENTIAL_LOCKS );
		foreach ( \AB_MCP_Sync_Net::UNREADABLE as $op ) {
			self::assertContains( $op, AB_MCP_Sync_Export::CREDENTIAL_LOCKS, 'Every lock that makes a unit never clean: ' . $op );
		}
	}

	#[DataProvider( 'heldByACredential' )]
	public function testAnObjectACredentialHoldsCarriesItsIdentityAndTheReasonOnly( array $over ): void {
		$line = AB_MCP_Sync_Export::line( self::object( $over ) );
		self::assertSame( array( 'kind', 'ref', 'identity', 'status', 'locks' ), array_values( array_diff( array_keys( $line ), array( 'reason' ) ) ) );
		self::assertArrayNotHasKey( 'hash', $line, 'Not even its hash.' );
	}

	/* ------------------------------------------------------------ binding */

	public function testAPackageIsBoundToTheKeyThePairAndTheirConfirmations(): void {
		$this->paired();
		$this->on( 'staging' );
		$binding = AB_MCP_Sync_Export::binding();
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}\z/', $binding );
		self::assertSame( $binding, AB_MCP_Sync_Export::binding() );
		$GLOBALS['ab_test_home'] = 'https://staging.example.com/other';
		self::assertNotSame( $binding, AB_MCP_Sync_Export::binding(), 'Another address of this site.' );
		$GLOBALS['ab_test_home'] = self::address( 'staging' );
		self::assertIsArray( AB_MCP_Sync_Key::renew() );
		$renewed = AB_MCP_Sync_Export::binding();
		self::assertNotSame( $binding, $renewed, 'A new key.' );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', 'https://shop.example.com', null, 3, 'form' ) );
		self::assertNotSame( $renewed, AB_MCP_Sync_Export::binding(), 'Another production site.' );
		$paired = AB_MCP_Sync_Export::binding();
		$raw    = get_option( 'ab_mcp_options' );
		$raw['staging']['pair']['at'] = $raw['staging']['pair']['at'] + 1;
		update_option( 'ab_mcp_options', $raw );
		self::assertNotSame( $paired, AB_MCP_Sync_Export::binding(), 'The pair confirmed again.' );
		$raw['staging']['role']['at'] = $raw['staging']['role']['at'] + 1;
		$again = AB_MCP_Sync_Export::binding();
		update_option( 'ab_mcp_options', $raw );
		self::assertNotSame( $again, AB_MCP_Sync_Export::binding(), 'The role confirmed again.' );
		AB_MCP_Sync_Key::forget();
		self::assertNotNull( AB_MCP_Staging::record()['pair'] );
		self::assertNull( AB_MCP_Sync_Export::binding(), 'No key, no binding.' );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', '', null, 3, 'form' ) );
		self::assertIsArray( AB_MCP_Sync_Key::renew() );
		self::assertNull( AB_MCP_Staging::record()['pair'] );
		self::assertNull( AB_MCP_Sync_Export::binding(), 'No pair, no binding.' );
	}

	/* ------------------------------------------------- what a wrong MAC costs */

	public function testAWrongMacReadsNeitherThePrivateKeyNorAPackage(): void {
		$GLOBALS['wpdb'] = new \AbTestSyncWpdb();
		\AB_MCP_Protected_Store::forget();
		$this->paired();
		$this->on( 'staging' );
		$grant  = AB_MCP_Sync_Grant::grants( self::ID, 1, time() )[0];
		$wrong  = array( 'signature' => str_repeat( 'a', 64 ) ) + $grant;
		$before = count( $GLOBALS['wpdb']->queries );
		$GLOBALS['ab_test_option_reads'] = array();
		self::assertNull( AB_MCP_Sync_Export::requested( self::request( self::query( $wrong ) ), time() ) );
		self::assertArrayNotHasKey( AB_MCP_Sync_Key::OPTION_SECRET, $GLOBALS['ab_test_option_reads'], 'The private key is not read.' );
		self::assertSame( $before, count( $GLOBALS['wpdb']->queries ), 'No package is looked up.' );
		self::assertSame( 1, $GLOBALS['ab_test_option_reads'][ AB_MCP_Sync_Key::OPTION_MAC ], 'The secret of the MACs, once.' );
		self::assertNull( AB_MCP_Sync_Export::requested( self::request( self::query( $grant ) ), time() ) );
		self::assertGreaterThan( $before, count( $GLOBALS['wpdb']->queries ), 'A right MAC: the package is looked up.' );
	}

	/* --------------------------------------------------- the import, in Teil 2 */

	public function testTheProductionSiteTakesPackagesOfTheInventoryAndTheEnvironmentOnly(): void {
		foreach ( array( 'objects', 'media', 'Env', null, 'Inventory' ) as $purpose ) {
			$refused = AB_MCP_Sync_Import::call( array( 'package_id' => self::ID, 'manifest' => array( 'manifest' => 'x', 'signature' => 'y' ), 'purpose' => $purpose ) );
			self::assertSame( 'ab_mcp_sync_import_purpose', $refused->get_error_code(), var_export( $purpose, true ) . ': before anything is read.' );
			self::assertStringContainsString( 'purpose=inventory', $refused->get_error_message() );
		}
		$refused = AB_MCP_Sync_Import::call( array( 'package_id' => self::ID, 'manifest' => array(), 'report_hash' => str_repeat( 'c', 64 ) ) );
		self::assertSame( 'ab_mcp_sync_import_purpose', $refused->get_error_code(), 'No purpose, and a report is no purpose.' );
	}
}
