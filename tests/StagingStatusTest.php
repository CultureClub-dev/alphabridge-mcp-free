<?php
/**
 * The staging record of a site and wp_staging_status (AB_MCP_Staging, the
 * tool, the hub's REST route alphabridge/v1/staging-pair, the role in the
 * site contract and the form on the settings page; free since 4.15.0).
 *
 * What must hold:
 *
 * - A site whose role nobody confirmed reads as «unconfirmed» and counts as
 *   the production site; a broken record never reads as a confirmed role
 *   or a pair: a pair needs a confirmed role, the other role and an address
 *   exactly as save() stores it.
 * - The settings page confirms the role, the other site (whose role is the
 *   other one) and the host; the hub confirms role and pair with both roles
 *   named. A part that stays as it was keeps who confirmed it, when and
 *   where; nothing is written when anything is wrong, and every problem is
 *   named. Taking the role back removes the pair; an address needs a role;
 *   the own address is no other site; an address is http(s) of a host,
 *   without user name, password, query or fragment.
 * - The suggestion names each hint with its source (environment type, the
 *   beginning and the end of the address, the preview protection, the
 *   search engine visibility only while the protection is off) and is never
 *   the role.
 * - The host is the person's choice, else what the server shows (each
 *   marker its own case), else unknown.
 * - The check of the other site runs only when asked for, only to a public
 *   http(s) address (a private or local one is not asked at all), through
 *   wp_safe_remote_get without redirects and without anything of this site;
 *   each answer has its finding: AlphaBridge answers (its header or its
 *   error code), an HTTP login, a redirect, something else, no answer.
 * - The checklist, item by item (role confirmed, staging site there and
 *   answering, preview protection on the staging site, pair set), and the
 *   next step: the first open item with its steps, fallback and link, else
 *   the check to make, else what to do with the way in place; the warning
 *   for a protected production site.
 * - The tool reads only (also for read-only connections and with write
 *   access off), needs manage_options, changes nothing, also when it checks
 *   the other site, and advertises nothing.
 * - The route: no token, a wrong token, an account without manage_options,
 *   a token below the full access level for writing, a browser of another
 *   origin, an invalid body, the same role twice, a source other than the
 *   hub: refused, nothing written; a valid write by the hub is stored with
 *   who and where, counts once towards the rate limit, and is refused when
 *   that is reached; the answer is not to be stored on the way; reading
 *   works with any access level; the preview protection leaves the route
 *   open.
 * - The site contract reports the role as staging_role.
 * - The form on the settings page saves behind its nonce and
 *   manage_options, saves nothing when anything is wrong, and names the
 *   problem; the card shows who confirmed what, when and where.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Auth;
use AB_MCP_Guidance;
use AB_MCP_Outbound;
use AB_MCP_Preview_Protection;
use AB_MCP_REST_Controller;
use AB_MCP_Security;
use AB_MCP_Settings;
use AB_MCP_Site_Mode;
use AB_MCP_Staging;
use AB_MCP_Tool_Labels;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use AbTestExit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-preview-protection.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';

final class StagingStatusTest extends TestCase {

	const STAGING = 'https://staging.example.com';

	/** A public address of the examples, and what each test host resolves to (ab_mcp_outbound_addresses); any other name has no address. */
	const PUBLIC_V4 = '93.184.216.34';
	const PUBLIC_V6 = '2606:2800:220:1:248:1893:25c8:1946';
	const DNS       = array(
		'staging.example.com' => array( self::PUBLIC_V4 ),
		'stage.example.com'   => array( self::PUBLIC_V4 ),
		'example.org'         => array( self::PUBLIC_V4, self::PUBLIC_V6 ),
	);

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user']           = 7;
		$GLOBALS['ab_test_home']                   = 'https://example.com';
		$GLOBALS['ab_test_options']['blog_public'] = '1';
		$_GET                                      = array();
		$_POST                                     = array();
		$_REQUEST                                  = array();
		unset( $_SERVER['cw_allowed_ip'], $_SERVER['KINSTA_CACHE_ZONE'] );
		$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
		// No DNS in the tests: the hosts answer from DNS, as a site's own
		// resolver could through the filter.
		add_filter( 'ab_mcp_outbound_addresses', static fn( $addresses, string $host ) => self::DNS[ $host ] ?? array(), 10, 2 );
		if ( ! \defined( 'WPMU_PLUGIN_DIR' ) ) {
			\define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/ab-mcp-mu-plugins-staging-' . getmypid() );
		}
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		self::scope( 'full' );
		$dir = WPMU_PLUGIN_DIR;
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			rmdir( $dir );
		}
	}

	/** The access level of the current connection, as AB_MCP_Auth records it after the token check. */
	private static function scope( string $scope ): void {
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, $scope );
	}

	/** The stored record as it is in the option. */
	private static function raw(): array {
		$opts = $GLOBALS['ab_test_options'][ AB_MCP_Settings::OPT_OPTIONS ] ?? array();
		return is_array( $opts ) && isset( $opts[ AB_MCP_Staging::KEY ] ) && is_array( $opts[ AB_MCP_Staging::KEY ] ) ? $opts[ AB_MCP_Staging::KEY ] : array();
	}

	/** Put a record into the option as it is. */
	private static function store( array $record ): void {
		AB_MCP_Settings::set( AB_MCP_Staging::KEY, $record );
	}

	/** Confirm a role and a pair as the settings page does. */
	private static function confirm( string $role, string $partner = '' ): void {
		$saved = AB_MCP_Staging::save( $role, $partner, null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertIsArray( $saved );
	}

	/** The codes of a refusal of save(). */
	private static function problems( $result ): array {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ab_mcp_staging_invalid', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 400, $data['status'] );
		return $data['problems'];
	}

	/** One item of a checklist by its id. */
	private static function item( array $items, string $id ): array {
		foreach ( $items as $item ) {
			if ( $id === $item['id'] ) {
				return $item;
			}
		}
		self::fail( 'No item ' . $id );
	}

	/** Answer every outgoing request with the given response, and record which function sent it. */
	private static function answer( $response ): void {
		$GLOBALS['ab_test_http_answer'] = static fn() => $response;
		$GLOBALS['ab_test_safe_get']    = 0;
		add_action(
			'http_api_curl',
			static function (): void {
				++$GLOBALS['ab_test_safe_get'];
			}
		);
	}

	/* ------------------------------------------------------------ record */

	public function testUnconfirmedByDefaultCountsAsTheProductionSite(): void {
		self::assertSame( 'unconfirmed', AB_MCP_Staging::role() );
		self::assertSame( 'production', AB_MCP_Staging::treated_as() );
		$summary = AB_MCP_Staging::summary();
		self::assertSame(
			array(
				'value'      => 'unconfirmed',
				'confirmed'  => false,
				'treated_as' => 'production',
				'set_by'     => null,
			),
			$summary['role']
		);
		self::assertNull( $summary['pair'] );
	}

	/** @return array<string,array{0:array,1:string,2:bool}> */
	public static function brokenRecords(): array {
		$who  = array(
			'user_id'    => 7,
			'user_login' => 'user7',
			'source'     => 'form',
			'at'         => 100,
		);
		$role = array( 'value' => 'production' ) + $who;
		$pair = array(
			'partner_url'  => self::STAGING,
			'partner_role' => 'staging',
		) + $who;
		return array(
			'an unknown role'                => array( array( 'role' => array( 'value' => 'live' ) + $who ), 'unconfirmed', false ),
			'a role that is no array'        => array( array( 'role' => 'staging' ), 'unconfirmed', false ),
			'a pair without a role'          => array( array( 'pair' => $pair ), 'unconfirmed', false ),
			'a pair of the same role'        => array( array( 'role' => array( 'value' => 'staging' ) + $who, 'pair' => $pair ), 'staging', false ),
			'a pair with an unknown role'    => array( array( 'role' => $role, 'pair' => array( 'partner_role' => 'live' ) + $pair ), 'production', false ),
			'a pair address not as stored'   => array( array( 'role' => $role, 'pair' => array( 'partner_url' => self::STAGING . '/' ) + $pair ), 'production', false ),
			'a pair address that is no site' => array( array( 'role' => $role, 'pair' => array( 'partner_url' => 'ftp://staging.example.com' ) + $pair ), 'production', false ),
			'an empty pair address'          => array( array( 'role' => $role, 'pair' => array( 'partner_url' => '' ) + $pair ), 'production', false ),
			'a pair address that is no text' => array( array( 'role' => $role, 'pair' => array( 'partner_url' => array( self::STAGING ) ) + $pair ), 'production', false ),
			'a role that is an object'       => array( array( 'role' => (object) $role ), 'unconfirmed', false ),
			'a pair that is an object'       => array( array( 'role' => $role, 'pair' => (object) $pair ), 'production', false ),
			'a pair without its role'        => array( array( 'role' => $role, 'pair' => array_diff_key( $pair, array( 'partner_role' => 1 ) ) ), 'production', false ),
			'a pair that is whole'           => array( array( 'role' => $role, 'pair' => $pair ), 'production', true ),
		);
	}

	#[DataProvider( 'brokenRecords' )]
	public function testABrokenRecordNeverReadsAsARoleOrAPair( array $record, string $role, bool $paired ): void {
		self::store( $record );
		self::assertSame( $role, AB_MCP_Staging::role() );
		self::assertSame( $paired, null !== AB_MCP_Staging::record()['pair'] );
	}

	public function testABrokenHostChoiceReadsAsNone(): void {
		self::store( array( 'host' => array( 'value' => 'godaddy' ) ) );
		self::assertNull( AB_MCP_Staging::record()['host'] );
		self::store( array( 'host' => array( 'value' => array( 'kinsta' ) ) ) );
		self::assertNull( AB_MCP_Staging::record()['host'] );
		self::store( array( 'host' => (object) array( 'value' => 'kinsta' ) ) );
		self::assertNull( AB_MCP_Staging::record()['host'] );
		self::store( array( 'host' => array( 'value' => 'unknown' ) ) );
		self::assertSame( 'unknown', AB_MCP_Staging::record()['host']['value'] );
	}

	public function testWhoWhenAndWhereAreReadInTheirShape(): void {
		self::store(
			array(
				'role' => array(
					'value'      => 'staging',
					'user_id'    => '7',
					'user_login' => array( 'x' ),
					'source'     => 'tool',
					'at'         => '100',
				),
			)
		);
		self::assertSame( array( 'value' => 'staging', 'user_id' => 7, 'user_login' => '', 'source' => '', 'at' => 100 ), AB_MCP_Staging::record()['role'] );
		self::store( array( 'role' => array( 'value' => 'staging', 'source' => 'hub' ) ) );
		self::assertSame( array( 'value' => 'staging', 'user_id' => 0, 'user_login' => '', 'source' => 'hub', 'at' => 0 ), AB_MCP_Staging::record()['role'] );
		self::assertSame( '', AB_MCP_Staging::summary()['role']['set_by']['at'], 'No time, no date.' );
	}

	public function testTheFormConfirmsTheRoleWithWhoWhenAndWhere(): void {
		$before = time();
		$saved  = AB_MCP_Staging::save( 'staging', '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'role_changed' => true, 'pair_changed' => false ), $saved );
		$role = AB_MCP_Staging::record()['role'];
		self::assertSame( 'staging', $role['value'] );
		self::assertSame( 7, $role['user_id'] );
		self::assertSame( 'user7', $role['user_login'] );
		self::assertSame( 'form', $role['source'] );
		self::assertGreaterThanOrEqual( $before, $role['at'] );
		self::assertLessThanOrEqual( time(), $role['at'] );
		self::assertSame( 'staging', AB_MCP_Staging::treated_as() );
		$set_by = AB_MCP_Staging::summary()['role']['set_by'];
		self::assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $role['at'] ), $set_by['at'] );
		self::assertSame( 'form', $set_by['source'] );
	}

	public function testTheFormGivesThePairTheOtherRole(): void {
		$saved = AB_MCP_Staging::save( 'production', '  https://Staging.EXAMPLE.com:8443/Site/ ', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'role_changed' => true, 'pair_changed' => true ), $saved );
		$pair = AB_MCP_Staging::record()['pair'];
		self::assertSame( 'https://staging.example.com:8443/Site', $pair['partner_url'], 'Scheme and host in lower case, the port kept, the slash at the end gone; the path as written.' );
		self::assertSame( 'staging', $pair['partner_role'] );
		self::assertSame( 'form', $pair['source'] );
		self::assertSame( 'user7', $pair['user_login'] );

		AB_MCP_Staging::save( 'staging', 'https://example.org', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( 'production', AB_MCP_Staging::record()['pair']['partner_role'] );
	}

	public function testAPartThatStaysKeepsWhoConfirmedItAndWhere(): void {
		ab_test_add_user( 9 );
		AB_MCP_Staging::save( 'production', self::STAGING, 'staging', 9, AB_MCP_Staging::SOURCE_HUB );
		$writes = ab_test_writes( AB_MCP_Settings::OPT_OPTIONS );
		$saved  = AB_MCP_Staging::save( 'production', self::STAGING . '/', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'role_changed' => false, 'pair_changed' => false ), $saved );
		self::assertSame( $writes, ab_test_writes( AB_MCP_Settings::OPT_OPTIONS ), 'Nothing changed, nothing written.' );
		$record = AB_MCP_Staging::record();
		self::assertSame( array( 'hub', 'user9' ), array( $record['role']['source'], $record['role']['user_login'] ) );
		self::assertSame( array( 'hub', 'user9' ), array( $record['pair']['source'], $record['pair']['user_login'] ) );

		$saved = AB_MCP_Staging::save( 'production', 'https://stage.example.com', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'role_changed' => false, 'pair_changed' => true ), $saved, 'Another address: the pair is new, the role stays.' );
		$record = AB_MCP_Staging::record();
		self::assertSame( 'hub', $record['role']['source'] );
		self::assertSame( array( 'form', 'user7' ), array( $record['pair']['source'], $record['pair']['user_login'] ) );
	}

	public function testANewRoleNeverKeepsTheOldPair(): void {
		self::confirm( 'production', self::STAGING );
		$saved = AB_MCP_Staging::save( 'staging', self::STAGING, null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'role_changed' => true, 'pair_changed' => true ), $saved );
		self::assertSame( 'production', AB_MCP_Staging::record()['pair']['partner_role'] );
	}

	public function testTakingTheRoleBackRemovesThePair(): void {
		self::confirm( 'production', self::STAGING );
		$saved = AB_MCP_Staging::save( 'unconfirmed', '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'role_changed' => true, 'pair_changed' => true ), $saved );
		self::assertSame( array(), self::raw() );
		self::assertSame( 'unconfirmed', AB_MCP_Staging::role() );

		$writes = ab_test_writes( AB_MCP_Settings::OPT_OPTIONS );
		self::assertSame( array( 'role_changed' => false, 'pair_changed' => false ), AB_MCP_Staging::save( 'unconfirmed', '', null, 7, AB_MCP_Staging::SOURCE_FORM ) );
		self::assertSame( $writes, ab_test_writes( AB_MCP_Settings::OPT_OPTIONS ) );
	}

	public function testAnEmptyAddressRemovesThePairAndKeepsTheRole(): void {
		self::confirm( 'production', self::STAGING );
		$saved = AB_MCP_Staging::save( 'production', '', null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'role_changed' => false, 'pair_changed' => true ), $saved );
		self::assertNull( AB_MCP_Staging::record()['pair'] );
		self::assertSame( 'production', AB_MCP_Staging::role() );
	}

	public function testAnAddressWithoutARoleIsRefusedAndNothingIsWritten(): void {
		self::confirm( 'production', self::STAGING );
		$raw = self::raw();
		self::assertSame( array( 'no_role' ), self::problems( AB_MCP_Staging::save( 'unconfirmed', self::STAGING, null, 7, AB_MCP_Staging::SOURCE_FORM ) ) );
		self::assertSame( $raw, self::raw(), 'The role and the pair stay.' );
	}

	/** @return array<string,array{0:string,1:string,2:bool}> */
	public static function ownAddresses(): array {
		return array(
			'as it is'               => array( 'https://example.com', 'https://example.com', true ),
			'upper case and a slash' => array( 'https://example.com', 'https://EXAMPLE.com/', true ),
			'the https port'         => array( 'https://example.com', 'https://example.com:443', true ),
			'the http port'          => array( 'http://example.com', 'http://example.com:80/', true ),
			'the other scheme'       => array( 'https://example.com', 'http://example.com', true ),
			'a home with its port'   => array( 'https://example.com:443', 'https://example.com', true ),
			'a sub-folder home'      => array( 'https://example.com/blog', 'https://example.com/blog/', true ),
			'another port'           => array( 'https://example.com', 'https://example.com:8443', false ),
			'another folder'         => array( 'https://example.com/blog', 'https://example.com', false ),
			'another host'           => array( 'https://example.com', 'https://staging.example.com', false ),
		);
	}

	#[DataProvider( 'ownAddresses' )]
	public function testTheOwnAddressIsNoOtherSite( string $home, string $partner, bool $self ): void {
		$GLOBALS['ab_test_home'] = $home;
		$saved                   = AB_MCP_Staging::save( 'production', $partner, null, 7, AB_MCP_Staging::SOURCE_FORM );
		if ( $self ) {
			self::assertSame( array( 'self' ), self::problems( $saved ) );
			self::assertSame( array(), self::raw() );
		} else {
			self::assertIsArray( $saved );
		}
		self::assertSame( $self, AB_MCP_Staging::same_site( $partner, $home ) );
	}

	/** @return array<string,array{0:string}> */
	public static function ipv6Literals(): array {
		return array(
			'public'          => array( 'https://[2606:2800:220:1:248:1893:25c8:1946]' ),
			'loopback'        => array( 'http://[::1]:8080' ),
			'ULA with a path' => array( 'https://[fd12:3456::1]/staging' ),
			'mapped IPv4'     => array( 'http://[::ffff:127.0.0.1]' ),
		);
	}

	#[DataProvider( 'ipv6Literals' )]
	public function testAnIpv6AddressIsRefusedWithTheWayToTheHostName( string $partner ): void {
		$result = AB_MCP_Staging::save( 'production', $partner, null, 7, AB_MCP_Staging::SOURCE_FORM );
		self::assertSame( array( 'ipv6' ), self::problems( $result ) );
		self::assertStringContainsString( 'use the host name of the other site', $result->get_error_message() );
		self::assertSame( array( 'ipv6' ), self::problems( AB_MCP_Staging::save( 'production', $partner, 'staging', 7, AB_MCP_Staging::SOURCE_HUB ) ) );
		self::assertSame( array(), self::raw() );
	}

	public function testAnIpv4AddressStaysAllowed(): void {
		foreach ( array( 'https://' . self::PUBLIC_V4, 'http://192.168.1.20/staging' ) as $partner ) {
			self::assertIsArray( AB_MCP_Staging::save( 'production', $partner, null, 7, AB_MCP_Staging::SOURCE_FORM ), $partner );
		}
		self::assertSame( 'http://192.168.1.20/staging', AB_MCP_Staging::record()['pair']['partner_url'], 'A fresh staging server may have no name yet; a local copy is checked as local.' );
	}

	public function testSameSiteNeedsTwoSites(): void {
		self::assertFalse( AB_MCP_Staging::same_site( '', '' ), 'Two addresses that are none are no site.' );
		self::assertFalse( AB_MCP_Staging::same_site( 'ftp://example.com', 'ftp://example.com' ) );
	}

	/** @return array<string,array{0:mixed,1:string}> */
	public static function addresses(): array {
		return array(
			'https'                 => array( 'https://staging.example.com', 'https://staging.example.com' ),
			'http'                  => array( 'http://staging.example.com', 'http://staging.example.com' ),
			'upper-case scheme'     => array( 'HTTPS://staging.example.com', 'https://staging.example.com' ),
			'slashes at the end'    => array( 'https://staging.example.com///', 'https://staging.example.com' ),
			'a sub-folder'          => array( 'https://example.com/staging/', 'https://example.com/staging' ),
			'a port'                => array( 'http://localhost:8080', 'http://localhost:8080' ),
			'the https port'        => array( 'https://staging.example.com:443/', 'https://staging.example.com' ),
			'the http port'         => array( 'http://staging.example.com:80', 'http://staging.example.com' ),
			'443 on http'           => array( 'http://staging.example.com:443', 'http://staging.example.com:443' ),
			'80 on https'           => array( 'https://staging.example.com:80', 'https://staging.example.com:80' ),
			'percent-encoded'       => array( 'https://example.com/pr%C3%BCfung/', 'https://example.com/pr%C3%BCfung' ),
			'spaces around'         => array( ' https://staging.example.com ', 'https://staging.example.com' ),
			'ftp'                   => array( 'ftp://staging.example.com', '' ),
			'javascript'            => array( 'javascript:alert(1)', '' ),
			'no scheme'             => array( 'staging.example.com', '' ),
			'no host'               => array( 'https:///path', '' ),
			'a user name'           => array( 'https://admin@staging.example.com', '' ),
			'a user and a password' => array( 'https://admin:secret@staging.example.com', '' ),
			'a query'               => array( 'https://staging.example.com/?p=1', '' ),
			'a fragment'            => array( 'https://staging.example.com/#top', '' ),
			'too long'              => array( 'https://staging.example.com/' . str_repeat( 'a', 2030 ), '' ),
			'just long enough'      => array( 'https://staging.example.com/' . str_repeat( 'a', 2020 ), 'https://staging.example.com/' . str_repeat( 'a', 2020 ) ),
			'a space inside'        => array( 'https://staging.example.com/a b', '' ),
			'a space in the host'   => array( 'https://staging .example.com', '' ),
			'a quote'               => array( 'https://staging.example.com/"x', '' ),
			'an angle bracket'      => array( 'https://staging.example.com/<x>', '' ),
			'a backslash'           => array( 'https://staging.example.com\\x', '' ),
			'a control character'   => array( "https://staging.example.com/\x01", '' ),
			'a curly brace'         => array( 'https://staging.example.com/{x}', '' ),
			'a letter beyond ASCII' => array( 'https://bücher.example', 'https://bücher.example' ),
			'empty'                 => array( '', '' ),
			'no text'               => array( array( 'https://staging.example.com' ), '' ),
		);
	}

	#[DataProvider( 'addresses' )]
	public function testAnAddressIsStoredAsASiteOrNotAtAll( $url, string $stored ): void {
		self::assertSame( $stored, AB_MCP_Staging::normalize_url( $url ) );
	}

	public function testEveryProblemIsNamedAtOnce(): void {
		$result = AB_MCP_Staging::save( 'live', 'ftp://x', 'live', 7, AB_MCP_Staging::SOURCE_HUB );
		self::assertSame( array( 'role', 'partner_url', 'partner_role' ), self::problems( $result ) );
		self::assertStringStartsWith( 'Nothing was saved: The role of this site must be production site or staging site. The address of the other site must be', $result->get_error_message() );
		self::assertSame( array( 'same_roles' ), self::problems( AB_MCP_Staging::save( 'staging', self::STAGING, 'staging', 7, AB_MCP_Staging::SOURCE_HUB ) ) );
		self::assertSame( array( 'source' ), self::problems( AB_MCP_Staging::save( 'staging', '', null, 7, 'tool' ) ) );
		self::assertSame( array(), self::raw() );
	}

	public function testTheHubNamesTheOtherSiteAndCannotTakeTheRoleBack(): void {
		self::assertSame( array( 'partner_url', 'partner_role' ), self::problems( AB_MCP_Staging::save( 'staging', '', null, 7, AB_MCP_Staging::SOURCE_HUB ) ) );
		self::assertSame( array( 'role' ), self::problems( AB_MCP_Staging::save( 'unconfirmed', self::STAGING, 'staging', 7, AB_MCP_Staging::SOURCE_HUB ) ) );
		self::assertSame( array(), self::raw() );
	}

	/* -------------------------------------------------------- suggestion */

	public function testNothingSuggestsAStagingSiteOnAPlainSite(): void {
		self::assertSame( array( 'role' => 'production', 'hints' => array() ), AB_MCP_Staging::suggestion() );
	}

	/** @return array<string,array{0:string}> */
	public static function environments(): array {
		return array(
			'staging'     => array( 'staging' ),
			'development' => array( 'development' ),
			'local'       => array( 'local' ),
		);
	}

	#[DataProvider( 'environments' )]
	public function testTheEnvironmentTypeIsAHint( string $type ): void {
		$GLOBALS['ab_test_environment'] = $type;
		$suggestion                     = AB_MCP_Staging::suggestion();
		self::assertSame( 'staging', $suggestion['role'] );
		self::assertSame( array( array( 'source' => 'environment_type', 'finding' => 'WordPress reports the environment type “' . $type . '”.' ) ), $suggestion['hints'] );
		self::assertSame( 'unconfirmed', AB_MCP_Staging::role(), 'A hint is never the role.' );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function addressHints(): array {
		return array(
			'staging.'   => array( 'https://staging.example.com', 'The address begins with “staging.”.' ),
			'stage.'     => array( 'https://stage.example.com', 'The address begins with “stage.”.' ),
			'dev.'       => array( 'https://dev.example.com', 'The address begins with “dev.”.' ),
			'test.'      => array( 'https://TEST.example.com', 'The address begins with “test.”.' ),
			'.test'      => array( 'http://shop.test', 'The address ends in “.test”, a name for testing or local development.' ),
			'.local'     => array( 'http://shop.local', 'The address ends in “.local”, a name for testing or local development.' ),
			'.localhost' => array( 'http://shop.localhost', 'The address ends in “.localhost”, a name for testing or local development.' ),
		);
	}

	#[DataProvider( 'addressHints' )]
	public function testTheAddressIsAHint( string $home, string $finding ): void {
		$GLOBALS['ab_test_home'] = $home;
		$suggestion              = AB_MCP_Staging::suggestion();
		self::assertSame( 'staging', $suggestion['role'] );
		self::assertSame( array( array( 'source' => 'domain', 'finding' => $finding ) ), $suggestion['hints'] );
	}

	/** @return array<string,array{0:string}> */
	public static function noAddressHints(): array {
		return array(
			'staging inside the name' => array( 'https://mystaging.example.com' ),
			'staging in the path'     => array( 'https://example.com/staging' ),
			'a longer ending'         => array( 'https://shop.testing' ),
			'the ending alone'        => array( 'http://.test' ),
		);
	}

	#[DataProvider( 'noAddressHints' )]
	public function testOnlyTheBeginningAndTheEndOfTheHostAreHints( string $home ): void {
		$GLOBALS['ab_test_home'] = $home;
		self::assertSame( array(), AB_MCP_Staging::suggestion()['hints'] );
	}

	public function testTheProtectionIsAHintAndItsOwnZeroIsNot(): void {
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		self::assertSame( '0', (string) get_option( 'blog_public' ) );
		self::assertSame( array( array( 'source' => 'preview_protection', 'finding' => 'The preview protection is on.' ) ), AB_MCP_Staging::suggestion()['hints'], 'The 0 is the protection\'s own and no second hint.' );
		AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );

		$GLOBALS['ab_test_options']['blog_public'] = '0';
		$suggestion                                = AB_MCP_Staging::suggestion();
		self::assertSame( 'staging', $suggestion['role'] );
		self::assertSame( array( array( 'source' => 'blog_public', 'finding' => 'Search engines are discouraged (blog_public is 0).' ) ), $suggestion['hints'] );
		$GLOBALS['ab_test_options']['blog_public'] = 0;
		self::assertCount( 1, AB_MCP_Staging::suggestion()['hints'], 'As WordPress stores it, a number too.' );
	}

	/* -------------------------------------------------------------- host */

	public function testNoMarkerNoHost(): void {
		self::assertSame( array( 'host' => '', 'evidence' => array() ), AB_MCP_Staging::detect_host( array( 'DOCUMENT_ROOT' => '/var/www' ), array() ) );
	}

	/** @return array<string,array{0:array,1:array,2:string}> */
	public static function hostMarkers(): array {
		return array(
			'Cloudways by its variable'      => array( array( 'cw_allowed_ip' => '1.2.3.4' ), array(), 'cloudways' ),
			'Cloudways by its document root' => array( array( 'DOCUMENT_ROOT' => '/home/123.cloudwaysapps.com/abc/public_html' ), array(), 'cloudways' ),
			'Kinsta'                         => array( array( 'KINSTA_CACHE_ZONE' => 'zone' ), array(), 'kinsta' ),
			'Kinsta, also empty'             => array( array( 'KINSTA_CACHE_ZONE' => '' ), array(), 'kinsta' ),
			'SiteGround'                     => array( array(), array( 'akismet/akismet.php', 'sg-cachepress/sg-cachepress.php' ), 'siteground' ),
			'Cloudways before SiteGround'    => array( array( 'cw_allowed_ip' => '1' ), array( 'sg-cachepress/sg-cachepress.php' ), 'cloudways' ),
		);
	}

	#[DataProvider( 'hostMarkers' )]
	public function testEachMarkerShowsItsHostWithTheEvidence( array $server, array $plugins, string $host ): void {
		$found = AB_MCP_Staging::detect_host( $server, $plugins );
		self::assertSame( $host, $found['host'] );
		self::assertCount( 1, $found['evidence'] );
	}

	public function testANearMarkerShowsNoHost(): void {
		self::assertSame( '', AB_MCP_Staging::detect_host( array( 'DOCUMENT_ROOT' => '/home/cloudwaysapps/x' ), array( 'sg-cachepress/other.php' ) )['host'] );
	}

	public function testTheSiteGroundMarkerSaysItIsAHint(): void {
		self::assertStringContainsString( 'runs on other hosts too', AB_MCP_Staging::detect_host( array(), array( 'sg-cachepress/sg-cachepress.php' ) )['evidence'][0] );
	}

	public function testTheServerAndThePluginsOfTheSiteAreRead(): void {
		$_SERVER['KINSTA_CACHE_ZONE'] = 'z';
		self::assertSame( 'kinsta', AB_MCP_Staging::detect_host()['host'] );
		unset( $_SERVER['KINSTA_CACHE_ZONE'] );
		$GLOBALS['ab_test_options']['active_plugins'] = array( 'sg-cachepress/sg-cachepress.php' );
		self::assertSame( 'siteground', AB_MCP_Staging::detect_host()['host'] );
		$GLOBALS['ab_test_options']['active_plugins'] = array();
		$GLOBALS['ab_test_multisite']                 = true;
		$GLOBALS['ab_test_site_options']['active_sitewide_plugins'] = array( 'sg-cachepress/sg-cachepress.php' => 1 );
		self::assertSame( 'siteground', AB_MCP_Staging::detect_host()['host'], 'Active for the network.' );
		$GLOBALS['ab_test_multisite'] = false;
		self::assertSame( '', AB_MCP_Staging::detect_host()['host'], 'A single site reads no network option.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testWpEngineByItsClassAndItsFunctionTogether(): void {
		eval( 'class WpeCommon {}' );
		self::assertSame( '', AB_MCP_Staging::detect_host( array(), array() )['host'], 'The class alone is not enough.' );
		eval( 'function wpe_param() {}' );
		self::assertSame( 'wpengine', AB_MCP_Staging::detect_host( array(), array() )['host'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testWpEngineNotByItsFunctionAlone(): void {
		eval( 'function wpe_param() {}' );
		self::assertSame( '', AB_MCP_Staging::detect_host( array(), array() )['host'] );
	}

	public function testThePersonsChoiceBeatsTheDetectedHost(): void {
		$_SERVER['KINSTA_CACHE_ZONE'] = 'z';
		$host                         = AB_MCP_Staging::host();
		self::assertSame( array( 'kinsta', 'Kinsta', 'detected', 'kinsta' ), array( $host['value'], $host['name'], $host['source'], $host['detected'] ) );
		self::assertSame( array( 'The server variable KINSTA_CACHE_ZONE is set.' ), $host['evidence'] );
		self::assertNull( $host['chosen_by'] );

		self::assertTrue( AB_MCP_Staging::set_host( 'infomaniak', 7 ) );
		$host = AB_MCP_Staging::host();
		self::assertSame( array( 'infomaniak', 'Infomaniak', 'chosen', 'kinsta' ), array( $host['value'], $host['name'], $host['source'], $host['detected'] ) );
		self::assertSame( 'user7', $host['chosen_by']['user_login'] );
		self::assertArrayNotHasKey( 'source', $host['chosen_by'] );

		self::assertTrue( AB_MCP_Staging::set_host( 'unknown', 7 ) );
		self::assertSame( array( 'unknown', '', 'chosen' ), array( AB_MCP_Staging::host()['value'], AB_MCP_Staging::host()['name'], AB_MCP_Staging::host()['source'] ) );

		unset( $_SERVER['KINSTA_CACHE_ZONE'] );
		self::assertTrue( AB_MCP_Staging::set_host( '', 7 ) );
		$host = AB_MCP_Staging::host();
		self::assertSame( array( 'unknown', 'none', null ), array( $host['value'], $host['source'], $host['detected'] ) );
	}

	public function testAChoiceOfHostOutsideTheListIsRefusedAndTheSameChangesNothing(): void {
		self::assertSame( array( 'host' ), self::problems( AB_MCP_Staging::set_host( 'godaddy', 7 ) ) );
		self::assertSame( 'Nothing was saved: The host must be one of the list, or not known.', AB_MCP_Staging::set_host( 'godaddy', 7 )->get_error_message() );
		self::assertSame( array(), self::raw() );
		self::assertFalse( AB_MCP_Staging::set_host( '', 7 ), 'No choice to take back.' );
		AB_MCP_Staging::set_host( 'kinsta', 7 );
		$writes = ab_test_writes( AB_MCP_Settings::OPT_OPTIONS );
		self::assertFalse( AB_MCP_Staging::set_host( 'kinsta', 7 ) );
		self::assertSame( $writes, ab_test_writes( AB_MCP_Settings::OPT_OPTIONS ) );
		foreach ( array( '', 'unknown', 'kinsta', 'wpengine', 'siteground', 'cloudways', 'hostinger', 'infomaniak' ) as $choice ) {
			self::assertTrue( AB_MCP_Staging::is_host_choice( $choice ), $choice );
		}
		foreach ( array( 'godaddy', 'Kinsta', null, 0 ) as $choice ) {
			self::assertFalse( AB_MCP_Staging::is_host_choice( $choice ), var_export( $choice, true ) );
		}
	}

	/* ----------------------------------------------------- partner check */

	public function testTheOtherSiteIsAskedOnlyOnRequest(): void {
		self::confirm( 'production', self::STAGING );
		self::answer( ab_test_http_response( 401, '{"code":"ab_mcp_unauthorized"}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$out = AB_MCP_Staging::report( false );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'Without check_partner nothing leaves the site.' );
		self::assertNull( $out['partner_check'] );
		$item = self::item( $out['checklist'], 'staging_connected' );
		self::assertNull( $item['done'] );
		self::assertStringContainsString( 'has not been checked', $item['finding'] );
		self::assertSame( 'Call wp_staging_status with check_partner=true to check that the staging site answers.', $out['next_step'] );

		$out = AB_MCP_Tools_Staging::staging_status( array() );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
		$out = AB_MCP_Tools_Staging::staging_status( array( 'check_partner' => 'false' ) );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
		$out = AB_MCP_Tools_Staging::staging_status( array( 'check_partner' => true ) );
		self::assertCount( 1, $GLOBALS['ab_test_http'] );
		self::assertSame( 'reachable', $out['partner_check']['status'] );
	}

	public function testWithoutAPairThereIsNothingToAsk(): void {
		self::answer( ab_test_http_response( 401, '' ) );
		$out = AB_MCP_Staging::report( true );
		self::assertSame( array( 'status' => 'no_pair' ), $out['partner_check'] );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
	}

	/** @return array<string,array{0:string}> */
	public static function privateAddresses(): array {
		return array(
			'loopback'        => array( 'http://127.0.0.1:8080' ),
			'localhost'       => array( 'http://localhost' ),
			'a .localhost'    => array( 'http://shop.localhost' ),
			'a private net'   => array( 'http://10.0.0.5' ),
			'a home net'      => array( 'https://192.168.1.20/staging' ),
			'link-local'      => array( 'http://169.254.169.254' ),
		);
	}

	#[DataProvider( 'privateAddresses' )]
	public function testALocalOrPrivateAddressIsNotAsked( string $partner ): void {
		self::confirm( 'production', $partner );
		self::answer( ab_test_http_response( 401, '{"code":"ab_mcp_unauthorized"}' ) );
		$out = AB_MCP_Staging::report( true );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'No request to ' . $partner );
		self::assertSame( 'blocked', $out['partner_check']['status'] );
		self::assertNull( $out['partner_check']['http_status'] );
		self::assertStringContainsString( 'local or private', $out['partner_check']['finding'] );
		$item = self::item( $out['checklist'], 'staging_connected' );
		self::assertNull( $item['done'], 'Not decided here: a local copy is allowed.' );
		self::assertArrayNotHasKey( 'check', $item, 'And there is nothing to check from here.' );
		self::assertSame( $item['finding'], $out['next_step'], 'Not «the staging site answers»: it was not asked.' );
	}

	/** @return array<string,array{0:array<int,string>}> */
	public static function internalAnswers(): array {
		return array(
			'public and private mixed' => array( array( self::PUBLIC_V4, '10.0.0.1' ) ),
			'only the AAAA internal'   => array( array( self::PUBLIC_V4, 'fd00::1' ) ),
			'AAAA loopback'            => array( array( self::PUBLIC_V4, '::1' ) ),
			'IPv4 mapped into IPv6'    => array( array( '::ffff:127.0.0.1' ) ),
			'a mapped public one'      => array( array( '::ffff:' . self::PUBLIC_V4 ) ),
			'link-local, the cloud'    => array( array( '169.254.169.254' ) ),
			'shared address space'     => array( array( '100.64.0.1' ) ),
			'IPv6 link-local'          => array( array( self::PUBLIC_V6, 'fe80::1' ) ),
			'no address at all, as text' => array( array( 'not-an-address' ) ),
		);
	}

	/** @param array<int,string> $addresses */
	#[DataProvider( 'internalAnswers' )]
	public function testANameWithAnyAddressThatIsNotPublicIsNotAsked( array $addresses ): void {
		self::confirm( 'production', 'https://rebind.example' );
		add_filter( 'ab_mcp_outbound_addresses', static fn( $found, string $host ) => 'rebind.example' === $host ? $addresses : $found, 20, 2 );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$out = AB_MCP_Staging::report( true );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'No request: one internal address is enough.' );
		self::assertSame( 'blocked', $out['partner_check']['status'] );
	}

	public function testANameWithOnlyPublicAddressesIsAsked(): void {
		self::confirm( 'production', 'https://example.org' );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		self::assertSame( 'reachable', AB_MCP_Staging::report( true )['partner_check']['status'] );
		self::assertCount( 1, $GLOBALS['ab_test_http'] );
	}

	public function testANameWithoutAnAddressIsNotAskedAndSaysSo(): void {
		self::confirm( 'production', 'https://nowhere.example' );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$out = AB_MCP_Staging::report( true );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
		self::assertSame( 'unresolved', $out['partner_check']['status'] );
		self::assertSame( 'The name of the other site https://nowhere.example does not resolve to an address.', $out['partner_check']['finding'] );
		$item = self::item( $out['checklist'], 'staging_connected' );
		self::assertFalse( $item['done'], 'A wrong name is a problem to fix, not a local copy.' );
		self::assertStringContainsString( 'Open the address in a browser', $item['steps'] );
		self::assertStringStartsWith( $item['finding'] . ' 1. Open the address', $out['next_step'] );
	}

	/** Entries of a hook, to see that a call left none behind. */
	private static function hooked( string $hook ): int {
		return count( $GLOBALS['ab_test_filters'][ $hook ] ?? array() );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function pins(): array {
		return array(
			'https, IPv4 and IPv6' => array( 'https://example.org', 'example.org:443:' . self::PUBLIC_V4 . ',' . self::PUBLIC_V6 ),
			'http'                 => array( 'http://example.org', 'example.org:80:' . self::PUBLIC_V4 . ',' . self::PUBLIC_V6 ),
			'its own port'         => array( 'https://staging.example.com:8443', 'staging.example.com:8443:' . self::PUBLIC_V4 ),
		);
	}

	/**
	 * cURL connects to the addresses that were checked and does not look the
	 * name up again (DNS rebinding): cURL's own log of a real handle, see
	 * wp_safe_remote_get() in the bootstrap.
	 */
	#[DataProvider( 'pins' )]
	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension( 'curl' )]
	public function testTheConnectionIsTiedToTheCheckedAddresses( string $partner, string $entry ): void {
		self::confirm( 'production', $partner );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$GLOBALS['ab_test_http_curl'] = true;
		$curl                         = self::hooked( 'http_api_curl' );
		$out                          = AB_MCP_Staging::report( true );
		self::assertSame( 'reachable', $out['partner_check']['status'] );
		self::assertStringContainsString( ' ' . $entry . ' ', $GLOBALS['ab_test_http'][0]['curl'] );
		self::assertSame( $curl, self::hooked( 'http_api_curl' ), 'Taken off after the request: no other request of the site is tied.' );
		self::assertSame( 0, self::hooked( 'requests-fsockopen.before_request' ) );
	}

	public function testTheBoundRequestSwitchesAProxyOfTheEnvironmentOff(): void {
		self::assertSame(
			array(
				CURLOPT_RESOLVE => array( 'staging.example.com:443:' . self::PUBLIC_V4 ),
				CURLOPT_PROXY   => '',
			),
			AB_MCP_Outbound::curl_options( 'staging.example.com:443:' . self::PUBLIC_V4 ),
			'An empty proxy disables http_proxy, HTTPS_PROXY and all_proxy for this handle (libcurl, CURLOPT_PROXY).'
		);
		self::assertSame( array( CURLOPT_PROXY => '' ), AB_MCP_Outbound::curl_options( null ), 'An address as host: no entry, no proxy all the same.' );
	}

	/** @return array<string,array{0:string}> */
	public static function partnersOfAnyKind(): array {
		return array(
			'an IPv4 address as host' => array( 'https://' . self::PUBLIC_V4 ),
			'a host name'             => array( self::STAGING ),
		);
	}

	/**
	 * The request's own handle, as the hook leaves it: a later hook sees it
	 * (the hook of get() runs first) and starts it towards this machine,
	 * with the environment's proxy set to a port that refuses. cURL must
	 * not try that proxy, for an address as host as for a host name.
	 */
	#[DataProvider( 'partnersOfAnyKind' )]
	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension( 'curl' )]
	public function testEveryRequestOfThePartnerCheckIgnoresTheProxyOfTheEnvironment( string $partner ): void {
		self::confirm( 'production', $partner );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$verbose = null;
		add_action(
			'http_api_curl',
			static function ( $handle ) use ( &$verbose ): void {
				$log = fopen( 'php://temp', 'w+' );
				putenv( 'http_proxy=http://127.0.0.1:9' );
				try {
					curl_setopt_array(
						$handle,
						array(
							CURLOPT_URL               => 'http://pin.example:80/',
							CURLOPT_RESOLVE           => array( 'pin.example:80:127.0.0.1' ),
							CURLOPT_VERBOSE           => true,
							CURLOPT_STDERR            => $log,
							CURLOPT_RETURNTRANSFER    => true,
							CURLOPT_CONNECTTIMEOUT_MS => 500,
							CURLOPT_TIMEOUT_MS        => 1000,
						)
					);
					curl_exec( $handle );
					rewind( $log );
					$verbose = (string) stream_get_contents( $log );
				} finally {
					putenv( 'http_proxy' );
					fclose( $log );
				}
			},
			20
		);
		self::assertSame( 'reachable', AB_MCP_Staging::report( true )['partner_check']['status'] );
		self::assertIsString( $verbose, 'The request ran through cURL with the hook.' );
		self::assertStringNotContainsString( 'http_proxy', $verbose, 'cURL names an environment proxy it uses; it uses none.' );
		self::assertStringNotContainsString( '127.0.0.1:9', $verbose );
	}

	public function testAnAddressAsHostIsAskedWithFsockopenToo(): void {
		self::confirm( 'production', 'https://' . self::PUBLIC_V4 );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$GLOBALS['ab_test_http_transport'] = 'fsockopen';
		self::assertSame( 'reachable', AB_MCP_Staging::report( true )['partner_check']['status'], 'Nothing to tie, and fsockopen reads no proxy from the environment.' );
		self::assertSame( 0, self::hooked( 'requests-fsockopen.before_request' ) );
	}

	/**
	 * The hook applies curl_options() to the handle of the request: a hook
	 * after it sees the handle, and cURL's log shows the address entry the
	 * options carry (testTheConnectionIsTiedToTheCheckedAddresses); here the
	 * proxy part, on a handle of its own, with the environment's proxy set
	 * to a port that refuses: cURL must not try it.
	 */
	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension( 'curl' )]
	public function testWithTheOptionsCurlIgnoresTheProxyOfTheEnvironment(): void {
		$handle = curl_init();
		$log    = fopen( 'php://temp', 'w+' );
		putenv( 'http_proxy=http://127.0.0.1:9' );
		try {
			curl_setopt_array(
				$handle,
				AB_MCP_Outbound::curl_options( 'pin.example:80:127.0.0.1' ) + array(
					CURLOPT_URL               => 'http://pin.example:80/',
					CURLOPT_VERBOSE           => true,
					CURLOPT_STDERR            => $log,
					CURLOPT_RETURNTRANSFER    => true,
					CURLOPT_CONNECTTIMEOUT_MS => 500,
					CURLOPT_TIMEOUT_MS        => 1000,
				)
			);
			curl_exec( $handle );
			rewind( $log );
			$verbose = (string) stream_get_contents( $log );
		} finally {
			putenv( 'http_proxy' );
			fclose( $log );
		}
		self::assertStringNotContainsString( 'http_proxy', $verbose, 'cURL names an environment proxy it uses; it uses none.' );
		self::assertStringNotContainsString( '127.0.0.1:9', $verbose );
		self::assertMatchesRegularExpression( '/127\.0\.0\.1.*\b80\b/', $verbose, 'It goes to the pinned address on this machine instead.' );
	}

	public function testTheHooksAreGoneAfterAFailedRequestToo(): void {
		self::confirm( 'production', self::STAGING );
		self::answer( new WP_Error( 'http_request_failed', 'timed out' ) );
		$curl = self::hooked( 'http_api_curl' );
		self::assertSame( 'unreachable', AB_MCP_Staging::report( true )['partner_check']['status'] );
		self::assertSame( $curl, self::hooked( 'http_api_curl' ) );
		self::assertSame( 0, self::hooked( 'requests-fsockopen.before_request' ) );
	}

	public function testWithoutCurlTheRequestIsNotSent(): void {
		self::confirm( 'production', self::STAGING );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$GLOBALS['ab_test_http_transport'] = 'fsockopen';
		$out                               = AB_MCP_Staging::report( true );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'Refused before the transport connects.' );
		self::assertSame( 'unbound', $out['partner_check']['status'] );
		self::assertStringContainsString( 'without cURL', $out['partner_check']['finding'] );
		$item = self::item( $out['checklist'], 'staging_connected' );
		self::assertNull( $item['done'], 'Not decided here.' );
		self::assertSame( $item['finding'], $out['next_step'] );
		self::assertSame( 0, self::hooked( 'requests-fsockopen.before_request' ) );
	}

	public function testAnAnswerThatCameWithoutTheBindingIsDiscarded(): void {
		self::confirm( 'production', self::STAGING );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$GLOBALS['ab_test_http_transport'] = 'other';
		$out                               = AB_MCP_Staging::report( true );
		self::assertCount( 1, $GLOBALS['ab_test_http'] );
		self::assertSame( 'unbound', $out['partner_check']['status'], 'Not «reachable»: the answer did not come from the checked address for sure.' );
	}

	public function testAnAddressAsHostNeedsNoBinding(): void {
		self::confirm( 'production', 'https://' . self::PUBLIC_V4 );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		self::assertSame( 'reachable', AB_MCP_Staging::report( true )['partner_check']['status'] );
		self::assertSame( 1, $GLOBALS['ab_test_safe_get'], 'Through wp_safe_remote_get as well.' );
		self::assertSame( 0, $GLOBALS['ab_test_http'][0]['args']['redirection'] );
		$GLOBALS['ab_test_http']           = array();
		$GLOBALS['ab_test_http_transport'] = 'other';
		self::assertSame( 'reachable', AB_MCP_Staging::report( true )['partner_check']['status'], 'Nothing is looked up for it.' );
		self::assertSame( 0, $GLOBALS['ab_test_http'][0]['args']['redirection'] );
	}

	public function testTheResolverAsksForEveryAAndAaaaRecord(): void {
		$GLOBALS['ab_test_filters']['ab_mcp_outbound_addresses'] = array();
		$asked = array();
		$dns   = static function ( string $host, int $type ) use ( &$asked ): array {
			$asked[] = array( $host, $type );
			return array(
				array( 'type' => 'CNAME', 'target' => 'edge.example' ),
				array( 'type' => 'A', 'ip' => self::PUBLIC_V4 ),
				array( 'type' => 'AAAA', 'ipv6' => 'fd00::1' ),
				array( 'type' => 'A', 'ip' => self::PUBLIC_V4 ),
			);
		};
		$fallback = static function (): array {
			self::fail( 'No fallback while DNS answers.' );
		};
		self::assertSame( array( self::PUBLIC_V4, 'fd00::1' ), AB_MCP_Outbound::resolve( 'x.example', $dns, $fallback ) );
		self::assertSame( array( array( 'x.example', DNS_A | DNS_AAAA ) ), $asked );
		self::assertSame( 'local', AB_MCP_Outbound::public_addresses( 'x.example', $dns, $fallback ), 'Only the AAAA record is internal: refused.' );
	}

	public function testWithoutAnAnswerFromDnsTheSystemResolverIsAsked(): void {
		$dns_filter = $GLOBALS['ab_test_filters']['ab_mcp_outbound_addresses'];
		$GLOBALS['ab_test_filters']['ab_mcp_outbound_addresses'] = array();
		$fallback = static fn( string $host ): array => array( self::PUBLIC_V4 );
		self::assertSame( array( self::PUBLIC_V4 ), AB_MCP_Outbound::resolve( 'x.example', static fn() => false, $fallback ) );
		self::assertSame( array( self::PUBLIC_V4 ), AB_MCP_Outbound::resolve( 'x.example', static fn() => array( array( 'type' => 'MX', 'target' => 'm' ) ), $fallback ) );
		self::assertSame( array(), AB_MCP_Outbound::resolve( 'x.example', static fn() => false, static fn() => false ) );
		self::assertSame( 'unresolved', AB_MCP_Outbound::public_addresses( 'x.example', static fn() => false, static fn() => false ) );
		$GLOBALS['ab_test_filters']['ab_mcp_outbound_addresses'] = $dns_filter;
		$no_lookup = static function (): array {
			self::fail( 'The filter answers before the lookup.' );
		};
		self::assertSame( array( self::PUBLIC_V4, self::PUBLIC_V6 ), AB_MCP_Outbound::public_addresses( 'example.org', $no_lookup, $no_lookup ) );
	}

	public function testThisMachineAndAnAddressAsHostAreDecidedWithoutALookup(): void {
		$dns = static function (): array {
			self::fail( 'No lookup.' );
		};
		foreach ( array( 'localhost', 'LOCALHOST', 'shop.localhost', '127.0.0.1', '[::1]', '10.1.2.3' ) as $host ) {
			self::assertSame( 'local', AB_MCP_Outbound::public_addresses( $host, $dns, $dns ), $host );
		}
		self::assertSame( array( self::PUBLIC_V4 ), AB_MCP_Outbound::public_addresses( self::PUBLIC_V4, $dns, $dns ) );
		self::assertSame( array( self::PUBLIC_V6 ), AB_MCP_Outbound::public_addresses( '[' . self::PUBLIC_V6 . ']', $dns, $dns ) );
	}

	public function testAFilterCannotOpenTheWayToAPrivateAddress(): void {
		add_filter( 'ab_mcp_outbound_addresses', static fn(): array => array( '192.168.0.10' ), 30 );
		self::assertSame( 'local', AB_MCP_Outbound::public_addresses( 'staging.example.com' ) );
		add_filter( 'ab_mcp_outbound_addresses', static fn(): string => self::PUBLIC_V4, 40 );
		self::assertSame( 'unresolved', AB_MCP_Outbound::public_addresses( 'staging.example.com' ), 'A filter answer that is no list counts as none.' );
	}

	/** @return array<string,array{0:string,1:array<int,string>,2:string|null}> */
	public static function resolveEntries(): array {
		return array(
			'https'        => array( 'https://app.example/x', array( self::PUBLIC_V4 ), 'app.example:443:' . self::PUBLIC_V4 ),
			'http'         => array( 'http://app.example/x', array( self::PUBLIC_V4 ), 'app.example:80:' . self::PUBLIC_V4 ),
			'own port'     => array( 'http://app.example:8080/x', array( self::PUBLIC_V4 ), 'app.example:8080:' . self::PUBLIC_V4 ),
			'two'          => array( 'https://app.example/x', array( self::PUBLIC_V4, self::PUBLIC_V6 ), 'app.example:443:' . self::PUBLIC_V4 . ',' . self::PUBLIC_V6 ),
			'IPv4 as host' => array( 'https://' . self::PUBLIC_V4 . '/x', array( self::PUBLIC_V4 ), null ),
			'IPv6 as host' => array( 'https://[' . self::PUBLIC_V6 . ']/x', array( self::PUBLIC_V6 ), null ),
			'no addresses' => array( 'https://app.example/x', array(), null ),
		);
	}

	/** @param array<int,string> $addresses */
	#[DataProvider( 'resolveEntries' )]
	public function testTheResolveEntry( string $url, array $addresses, ?string $entry ): void {
		self::assertSame( $entry, AB_MCP_Outbound::resolve_entry( $url, $addresses ) );
	}

	public function testOnlyHttpAndHttpsAreAsked(): void {
		$result = AB_MCP_Outbound::get( 'ftp://staging.example.com/x', array() );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ab_mcp_outbound_local', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
	}

	public function testTheRequestGoesSafelyToTheEndpointAndCarriesNothingOfThisSite(): void {
		self::confirm( 'production', 'https://example.org/staging' );
		self::answer( ab_test_http_response( 401, '{"code":"ab_mcp_unauthorized"}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		AB_MCP_Staging::report( true );
		self::assertCount( 1, $GLOBALS['ab_test_http'] );
		$call = $GLOBALS['ab_test_http'][0];
		self::assertSame( 'https://example.org/staging/?rest_route=/alphabridge/v1/mcp', $call['url'] );
		self::assertSame( 1, $GLOBALS['ab_test_safe_get'], 'Through wp_safe_remote_get, which checks every address again.' );
		self::assertSame( 0, $call['args']['redirection'], 'No redirect is followed.' );
		self::assertSame( AB_MCP_Staging::CHECK_TIMEOUT, $call['args']['timeout'] );
		self::assertSame( 'AlphaBridge-MCP/' . AB_MCP_VERSION, $call['args']['user-agent'], 'Not WordPress\'s, which names this site.' );
		self::assertSame( array( 'Accept' => 'application/json' ), $call['args']['headers'], 'No token, no login.' );
		self::assertArrayNotHasKey( 'cookies', $call['args'] );
		self::assertArrayNotHasKey( 'body', $call['args'] );
		self::assertSame( 4096, $call['args']['limit_response_size'] );
	}

	/** @return array<string,array{0:mixed,1:string,2:bool|null,3:string}> */
	public static function answers(): array {
		return array(
			'its 401 header'       => array( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ), 'reachable', true, 'answers, and AlphaBridge MCP runs there' ),
			'its error code'       => array( ab_test_http_response( 401, '{"code":"ab_mcp_unauthorized","message":"x"}' ), 'reachable', true, 'answers, and AlphaBridge MCP runs there' ),
			'an HTTP login'        => array( ab_test_http_response( 401, 'login', array( 'WWW-Authenticate' => 'Basic realm="x"' ) ), 'login', false, 'asks for an HTTP login' ),
			'a redirect'           => array( ab_test_http_response( 301, '', array( 'Location' => 'https://www.staging.example.com/' ) ), 'redirect', false, 'redirects to https://www.staging.example.com/' ),
			'a 3xx without where'  => array( ab_test_http_response( 304, '' ), 'not_alphabridge', false, 'answers with HTTP 304, but not as AlphaBridge MCP' ),
			'a WordPress 404'      => array( ab_test_http_response( 404, '{"code":"rest_no_route"}' ), 'not_alphabridge', false, 'answers with HTTP 404, but not as AlphaBridge MCP' ),
			'a 404 with a Location' => array( ab_test_http_response( 404, '', array( 'Location' => 'https://x.example/' ) ), 'not_alphabridge', false, 'answers with HTTP 404, but not as AlphaBridge MCP' ),
			'a 200 with a Location' => array( ab_test_http_response( 200, '', array( 'Location' => 'https://x.example/' ) ), 'not_alphabridge', false, 'answers with HTTP 200, but not as AlphaBridge MCP' ),
			'a page'               => array( ab_test_http_response( 200, '<html>' ), 'not_alphabridge', false, 'answers with HTTP 200, but not as AlphaBridge MCP' ),
			'no answer'            => array( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ), 'unreachable', false, 'could not be reached: cURL error 6: Could not resolve host' ),
		);
	}

	#[DataProvider( 'answers' )]
	public function testEachAnswerHasItsFinding( $response, string $status, ?bool $done, string $finding ): void {
		self::confirm( 'production', self::STAGING );
		self::answer( $response );
		$out = AB_MCP_Staging::report( true );
		self::assertSame( $status, $out['partner_check']['status'] );
		self::assertStringContainsString( 'The other site ' . self::STAGING . ' ' . $finding, $out['partner_check']['finding'] );
		$item = self::item( $out['checklist'], 'staging_connected' );
		self::assertSame( $done, $item['done'] );
		self::assertSame( $out['partner_check']['finding'], $item['finding'] );
		if ( false === $done ) {
			self::assertMatchesRegularExpression( '/^1\. .+ 2\. /', $item['steps'] );
			self::assertStringStartsWith( $item['finding'] . ' ' . $item['steps'], $out['next_step'] );
		}
		if ( 'redirect' === $status ) {
			self::assertSame( 'https://www.staging.example.com/', $out['partner_check']['location'] );
		}
		if ( $response instanceof WP_Error ) {
			self::assertNull( $out['partner_check']['http_status'] );
		} else {
			self::assertSame( $response['response']['code'], $out['partner_check']['http_status'] );
		}
	}

	public function testEachFailedCheckHasItsOwnSteps(): void {
		self::confirm( 'production', self::STAGING );
		$steps = array();
		foreach ( self::answers() as $case ) {
			if ( false !== $case[2] ) {
				continue;
			}
			self::answer( $case[0] );
			$steps[ $case[1] ] = self::item( AB_MCP_Staging::report( true )['checklist'], 'staging_connected' )['steps'];
		}
		self::assertStringContainsString( 'password protection', $steps['login'] );
		self::assertStringContainsString( 'enter that address', $steps['redirect'] );
		self::assertStringContainsString( 'Install and activate AlphaBridge MCP on the staging site', $steps['not_alphabridge'] );
		self::assertStringContainsString( 'Open the address in a browser', $steps['unreachable'] );
		self::assertCount( 4, array_unique( $steps ) );
	}

	/* --------------------------------------------------------- checklist */

	public function testAnUnconfirmedSiteIsAskedForItsRoleFirst(): void {
		$out = AB_MCP_Staging::report( false );
		self::assertSame( array( 'role_confirmed', 'staging_connected', 'protection_on_staging', 'pair_set' ), array_column( $out['checklist'], 'id' ) );
		self::assertSame( array( false, false, null, false ), array_column( $out['checklist'], 'done' ) );
		$role = self::item( $out['checklist'], 'role_confirmed' );
		self::assertSame( 'The role of this site is not confirmed yet, so it counts as the production site.', $role['finding'] );
		self::assertSame(
			AB_MCP_Guidance::compose( $role['finding'], $role['steps'], AB_MCP_Guidance::settings_url( 'ab-staging' ) ),
			$out['next_step']
		);
		self::assertStringContainsString( 'Direct link: ' . AB_MCP_Guidance::settings_url( 'ab-staging' ), $out['next_step'] );
		self::assertStringContainsString( AB_MCP_Guidance::HANDOVER, $out['next_step'] );
		self::assertArrayNotHasKey( 'steps', self::item( $out['checklist'], 'protection_on_staging' ), 'Only an open item has steps.' );
	}

	public function testAProductionSiteWithoutStagingIsToldHowToMakeOneForItsHost(): void {
		self::confirm( 'production' );
		$out  = AB_MCP_Staging::report( false );
		$item = self::item( $out['checklist'], 'staging_connected' );
		self::assertFalse( $item['done'] );
		self::assertSame( 'No staging site is known for this site yet.', $item['finding'] );
		self::assertStringContainsString( 'with the staging function of your host, if it has one, or with a staging plugin', $item['steps'] );
		self::assertStringContainsString( 'work in drafts and previews', $item['fallback'] );
		self::assertSame( AB_MCP_Guidance::compose( $item['finding'], $item['steps'] . ' ' . $item['fallback'], AB_MCP_Guidance::settings_url( 'ab-staging' ) ), $out['next_step'], 'The next step carries the fallback.' );
		self::assertTrue( self::item( $out['checklist'], 'role_confirmed' )['done'] );
		self::assertSame( 'This site is confirmed as the production site.', self::item( $out['checklist'], 'role_confirmed' )['finding'] );

		AB_MCP_Staging::set_host( 'hostinger', 7 );
		$item = self::item( AB_MCP_Staging::report( false )['checklist'], 'staging_connected' );
		self::assertStringContainsString( 'with the staging function in the control panel of Hostinger', $item['steps'] );

		AB_MCP_Staging::set_host( 'unknown', 7 );
		self::assertStringContainsString( 'if it has one', self::item( AB_MCP_Staging::report( false )['checklist'], 'staging_connected' )['steps'] );
	}

	public function testAStagingSiteWithoutProtectionIsToldToSwitchItOn(): void {
		self::confirm( 'staging' );
		$out = AB_MCP_Staging::report( false );
		self::assertSame( array( true, true, false, false ), array_column( $out['checklist'], 'done' ) );
		self::assertSame( 'This site is confirmed as the staging site.', self::item( $out['checklist'], 'role_confirmed' )['finding'] );
		self::assertSame( 'This site is the staging site.', self::item( $out['checklist'], 'staging_connected' )['finding'] );
		$item = self::item( $out['checklist'], 'protection_on_staging' );
		self::assertStringContainsString( 'visitors and search engines can read the staging site', $item['finding'] );
		self::assertStringContainsString( 'wp_set_preview_protection', $item['steps'] );
		self::assertStringStartsWith( $item['finding'], $out['next_step'] );
		self::assertSame( array(), $out['warnings'] );
	}

	public function testAProtectedStagingSiteWithoutAPairIsToldToPairIt(): void {
		self::confirm( 'staging' );
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		$out = AB_MCP_Staging::report( false );
		self::assertSame( array( true, true, true, false ), array_column( $out['checklist'], 'done' ) );
		self::assertSame( 'The preview protection is on.', self::item( $out['checklist'], 'protection_on_staging' )['finding'] );
		$pair = self::item( $out['checklist'], 'pair_set' );
		self::assertSame( 'No pair is set yet.', $pair['finding'] );
		self::assertStringStartsWith( 'No pair is set yet. 1. Confirm the role', $out['next_step'] );
		self::assertSame( array(), $out['warnings'], 'A protected staging site is as it should be.' );
	}

	public function testAStagingSiteWithEverythingInPlaceSaysWhatToDoNow(): void {
		self::confirm( 'staging', 'https://example.com.au' );
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		$out = AB_MCP_Staging::report( false );
		self::assertSame( array( true, true, true, true ), array_column( $out['checklist'], 'done' ) );
		self::assertSame( 'Paired with the production site https://example.com.au.', self::item( $out['checklist'], 'pair_set' )['finding'] );
		self::assertSame( 'The staging way is in place: this site is the protected staging site of https://example.com.au. Make the changes here and show them with the preview password; carry them over to the production site one at a time once they are approved.', $out['next_step'] );
	}

	public function testAProductionSiteWhoseStagingSiteAnswersSaysWhatToDoNow(): void {
		self::confirm( 'production', self::STAGING );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$out = AB_MCP_Staging::report( true );
		self::assertSame( array( true, true, null, true ), array_column( $out['checklist'], 'done' ) );
		self::assertSame( 'Paired with the staging site ' . self::STAGING . '.', self::item( $out['checklist'], 'pair_set' )['finding'] );
		self::assertSame( 'The preview protection is checked on the staging site itself.', self::item( $out['checklist'], 'protection_on_staging' )['finding'] );
		self::assertSame( 'The staging way is in place: the staging site ' . self::STAGING . ' answers and is paired with this site. Make the changes there and carry them over to this site one at a time once they are approved.', $out['next_step'] );
	}

	public function testOnAStagingSiteTheCheckOfTheProductionSiteIsReportedOnly(): void {
		self::confirm( 'staging', 'https://example.org' );
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		self::answer( ab_test_http_response( 404, '' ) );
		$out = AB_MCP_Staging::report( true );
		self::assertSame( 'not_alphabridge', $out['partner_check']['status'] );
		self::assertSame( array( true, true, true, true ), array_column( $out['checklist'], 'done' ), 'The staging site is this one.' );
	}

	public function testAProtectedProductionSiteIsWarned(): void {
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		self::assertSame( array(), AB_MCP_Staging::report( false )['warnings'], 'Unconfirmed: the suggestion says staging, no warning.' );
		self::confirm( 'production' );
		self::assertSame( array( 'The preview protection is on, but this site is confirmed as the production site: its visitors see the password page.' ), AB_MCP_Staging::report( false )['warnings'] );
		AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		self::assertSame( array(), AB_MCP_Staging::report( false )['warnings'] );
	}

	public function testTheReportCarriesTheStateAroundTheChecklist(): void {
		$GLOBALS['ab_test_home'] = 'https://staging.example.com';
		self::confirm( 'staging', 'https://example.com' );
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		$out = AB_MCP_Staging::report( false );
		self::assertSame( array( 'role', 'pair', 'partner_check', 'host', 'preview_protection', 'blog_public', 'checklist', 'next_step', 'warnings' ), array_keys( $out ) );
		self::assertSame( 'staging', $out['role']['value'] );
		self::assertSame( 'staging', $out['role']['suggestion']['role'] );
		self::assertSame( array( 'domain', 'preview_protection' ), array_column( $out['role']['suggestion']['hints'], 'source' ) );
		self::assertStringContainsString( 'never proof', $out['role']['suggestion']['note'] );
		self::assertSame( array( 'partner_url' => 'https://example.com', 'partner_role' => 'production' ), array_slice( $out['pair'], 0, 2 ) );
		self::assertSame( 'form', $out['pair']['confirmed_by']['source'] );
		self::assertTrue( $out['preview_protection']['enabled'] );
		self::assertNotSame( '', $out['preview_protection']['since'] );
		self::assertSame( '0', $out['blog_public'] );
		self::assertSame( 'unknown', $out['host']['value'] );
	}

	/* -------------------------------------------------------------- tool */

	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'staging', 'Staging' );
		AB_MCP_Tools_Staging::register( $r );
		return $r;
	}

	public function testTheToolReadsOnlyAndNeedsManageOptions(): void {
		$def = $this->registry()->get( 'wp_staging_status' );
		self::assertIsArray( $def );
		self::assertSame( 'manage_options', $def['capability'] );
		self::assertSame( 'staging', $def['group'] );
		self::assertTrue( AB_MCP_Tool_Registry::is_read_only( 'wp_staging_status', $def ) );
		self::assertFalse( AB_MCP_Tool_Registry::is_destructive( 'wp_staging_status', $def ) );
		self::assertFalse( AB_MCP_Tool_Registry::is_mighty( $def ) );
		self::assertTrue( AB_MCP_Tool_Registry::is_open_world( 'wp_staging_status', $def ), 'With check_partner it asks another site.' );
		self::assertTrue( AB_MCP_Site_Mode::runs_in_read( 'wp_staging_status', $def ), 'It runs with write access off.' );
		self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'read', 'wp_staging_status', $def ), 'And for a read-only connection.' );
		self::assertSame( array( 'check_partner' ), array_keys( $def['inputSchema']['properties'] ) );
		self::assertSame( 'boolean', $def['inputSchema']['properties']['check_partner']['type'] );
		self::assertArrayNotHasKey( 'confirm_token', $def['inputSchema']['properties'] );
		self::assertSame( 'Show the staging status', AB_MCP_Tool_Labels::english()['wp_staging_status'] );
		foreach ( array( 'never proof', 'counts as the production site', 'check_partner=true', 'every address of its host is public and tied to those addresses', 'not through a proxy', 'no redirect followed', 'Reads only and changes nothing', 'manage_options', 'next_step' ) as $said ) {
			self::assertStringContainsString( $said, $def['description'], $said );
		}
		self::assertDoesNotMatchRegularExpression( '/\bPro\b|Agency|upgrade/i', $def['description'] );
	}

	public function testTheToolIsRefusedWithoutTheCapability(): void {
		$def = $this->registry()->get( 'wp_staging_status' );
		AB_MCP_Settings::set( AB_MCP_Site_Mode::KEY, AB_MCP_Site_Mode::READ );
		self::scope( 'read' );
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' !== $cap;
		$refused                = AB_MCP_Security::authorize( 'wp_staging_status', $def );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_forbidden', $refused->get_error_code() );
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		self::assertTrue( AB_MCP_Security::authorize( 'wp_staging_status', $def ), 'With it, also read-only and with write access off.' );
	}

	public function testTheToolChangesNothingAlsoWhenItAsksTheOtherSite(): void {
		self::confirm( 'production', self::STAGING );
		AB_MCP_Staging::set_host( 'kinsta', 7 );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$options    = $GLOBALS['ab_test_options'];
		$transients = $GLOBALS['ab_test_transients'];
		$writes     = $GLOBALS['ab_test_writes'];
		foreach ( array( array(), array( 'check_partner' => true ) ) as $args ) {
			AB_MCP_Tools_Staging::staging_status( $args );
		}
		self::assertSame( $options, $GLOBALS['ab_test_options'] );
		self::assertSame( $transients, $GLOBALS['ab_test_transients'] );
		self::assertSame( $writes, $GLOBALS['ab_test_writes'], 'Not one write, not even of the same value.' );
	}

	public function testTheAnswerAdvertisesNothing(): void {
		self::confirm( 'production' );
		$json = (string) json_encode( AB_MCP_Tools_Staging::staging_status( array() ) );
		self::assertDoesNotMatchRegularExpression( '/\bPro\b|Agency|upgrade|licen[cs]e|price/i', $json );
		self::confirm( 'staging' );
		$json = (string) json_encode( AB_MCP_Tools_Staging::staging_status( array() ) );
		self::assertDoesNotMatchRegularExpression( '/\bPro\b|Agency|upgrade|licen[cs]e|price/i', $json );
	}

	/* ------------------------------------------------------------- route */

	/** Register the routes and send one request to the staging pair. */
	private function route( string $method, array $body = array(), string $token = '', array $headers = array() ): WP_REST_Response {
		$GLOBALS['ab_test_routes'] = array();
		( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->register_routes();
		$request = new WP_REST_Request();
		$request->set_method( $method );
		$request->set_route( '/alphabridge/v1/staging-pair' );
		if ( '' !== $token ) {
			$request->set_header( 'Authorization', 'Bearer ' . $token );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		if ( array() !== $body ) {
			$request->set_json_params( $body );
		}
		$GLOBALS['ab_test_current_user'] = 0;
		return ab_test_rest_dispatch( $request );
	}

	/** A valid body of the hub. */
	private static function body( array $over = array() ): array {
		return array_merge(
			array(
				'partner_url'  => self::STAGING,
				'role'         => 'production',
				'partner_role' => 'staging',
				'source'       => 'hub',
			),
			$over
		);
	}

	/** A token of user 7 with manage_options. */
	private static function token( string $scope = 'full' ): string {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		return AB_MCP_Settings::add_token( 7, 'Hub', $scope, 0 );
	}

	public function testTheRouteIsRegisteredForReadingAndWriting(): void {
		$GLOBALS['ab_test_routes'] = array();
		( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->register_routes();
		$routes = array_values( array_filter( $GLOBALS['ab_test_routes'], static fn( array $r ): bool => '/staging-pair' === $r['route'] ) );
		self::assertCount( 1, $routes );
		self::assertSame( 'alphabridge/v1', $routes[0]['namespace'] );
		self::assertSame( array( 'GET', 'POST' ), array_column( $routes[0]['args'], 'methods' ) );
		self::assertSame( 'check_staging_permission', $routes[0]['args'][0]['permission_callback'][1] );
		self::assertSame( 'check_staging_write_permission', $routes[0]['args'][1]['permission_callback'][1] );
	}

	public function testTheProtectionLeavesTheRouteOpen(): void {
		self::assertTrue( AB_MCP_Preview_Protection::rest_exempt( '/alphabridge/v1/staging-pair' ) );
	}

	public function testWithoutAValidTokenTheRouteAnswers401AndWritesNothing(): void {
		foreach ( array( 'GET', 'POST' ) as $method ) {
			$response = $this->route( $method, self::body() );
			self::assertSame( 401, $response->get_status(), $method );
			self::assertSame( 'ab_mcp_unauthorized', $response->get_data()['code'] );
			$response = $this->route( $method, self::body(), 'abmcp_' . str_repeat( 'f', 48 ) );
			self::assertSame( 401, $response->get_status(), $method . ' with a token nobody has' );
		}
		self::assertSame( array(), self::raw() );
	}

	public function testAnAccountWithoutManageOptionsIsRefused(): void {
		$token                  = self::token();
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' !== $cap;
		foreach ( array( 'GET', 'POST' ) as $method ) {
			$response = $this->route( $method, self::body(), $token );
			self::assertSame( 403, $response->get_status(), $method );
			self::assertSame( 'ab_mcp_forbidden', $response->get_data()['code'] );
			self::assertStringContainsString( 'lacks the capability "manage_options" that the staging pair needs', $response->get_data()['message'] );
			self::assertStringContainsString( 'ask an administrator to give the account "user7"', $response->get_data()['message'] );
		}
		self::assertSame( array(), self::raw() );
	}

	public function testWritingNeedsTheFullAccessLevelReadingAnyLevel(): void {
		foreach ( array( 'read', 'content' ) as $scope ) {
			$token    = self::token( $scope );
			$response = $this->route( 'POST', self::body(), $token );
			self::assertSame( 403, $response->get_status(), $scope );
			self::assertSame( 'ab_mcp_scope', $response->get_data()['code'] );
			self::assertStringContainsString( 'which does not include setting the staging pair', $response->get_data()['message'] );
			self::assertSame( 'GET', $response->get_headers()['Allow'], 'Reading stays allowed.' );
			self::assertSame( 200, $this->route( 'GET', array(), $token )->get_status(), $scope . ' reads' );
		}
		self::assertSame( array(), self::raw() );
	}

	public function testABrowserOfAnotherOriginIsRefused(): void {
		$response = $this->route( 'POST', self::body(), self::token(), array( 'Origin' => 'https://evil.example' ) );
		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'ab_mcp_bad_origin', $response->get_data()['code'] );
		self::assertSame( array(), self::raw() );
	}

	public function testTheHubWritesTheRoleAndThePairWithWhoAndWhere(): void {
		$response = $this->route( 'POST', self::body(), self::token() );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$data = $response->get_data();
		self::assertTrue( $data['role_changed'] );
		self::assertTrue( $data['pair_changed'] );
		self::assertSame( 'production', $data['role']['value'] );
		self::assertSame( array( 'partner_url' => self::STAGING, 'partner_role' => 'staging' ), array_slice( $data['pair'], 0, 2 ) );
		$record = AB_MCP_Staging::record();
		self::assertSame( array( 'hub', 7, 'user7' ), array( $record['role']['source'], $record['role']['user_id'], $record['role']['user_login'] ) );
		self::assertSame( array( 'hub', 7, 'user7' ), array( $record['pair']['source'], $record['pair']['user_id'], $record['pair']['user_login'] ) );

		$read = $this->route( 'GET', array(), self::token() );
		self::assertSame( 200, $read->get_status() );
		self::assertSame( 'no-store', $read->get_headers()['Cache-Control'] );
		self::assertSame( AB_MCP_Staging::summary(), $read->get_data() );
		self::assertSame( 'hub', $read->get_data()['pair']['confirmed_by']['source'] );
	}

	public function testTheBodyMayComeAsAForm(): void {
		$GLOBALS['ab_test_routes'] = array();
		( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->register_routes();
		$request = new WP_REST_Request();
		$request->set_route( '/alphabridge/v1/staging-pair' );
		$request->set_header( 'Authorization', 'Bearer ' . self::token() );
		$request->set_body_params( self::body( array( 'role' => 'staging', 'partner_role' => 'production', 'partner_url' => 'https://example.org' ) ) );
		self::assertSame( 200, ab_test_rest_dispatch( $request )->get_status() );
		self::assertSame( 'staging', AB_MCP_Staging::role() );
	}

	/** @return array<string,array{0:array,1:string[]}> */
	public static function badBodies(): array {
		return array(
			'no address'            => array( array( 'partner_url' => null ), array( 'partner_url' ) ),
			'an ftp address'        => array( array( 'partner_url' => 'ftp://staging.example.com' ), array( 'partner_url' ) ),
			'an address with login' => array( array( 'partner_url' => 'https://a:b@staging.example.com' ), array( 'partner_url' ) ),
			'the own address'       => array( array( 'partner_url' => 'https://example.com' ), array( 'self' ) ),
			'an unknown role'       => array( array( 'role' => 'live' ), array( 'role' ) ),
			'taking the role back'  => array( array( 'role' => 'unconfirmed' ), array( 'role' ) ),
			'no partner role'       => array( array( 'partner_role' => null ), array( 'partner_role' ) ),
			'the same role twice'   => array( array( 'role' => 'staging', 'partner_role' => 'staging' ), array( 'same_roles' ) ),
			'the source of a form'  => array( array( 'source' => 'form' ), array( 'source' ) ),
			'no source'             => array( array( 'source' => null ), array( 'source' ) ),
			'a role that is no text' => array( array( 'role' => array( 'production' ) ), array( 'role' ) ),
			'everything wrong'      => array( array( 'source' => 'x', 'role' => 'x', 'partner_role' => 'x', 'partner_url' => 'x' ), array( 'source', 'role', 'partner_url', 'partner_role' ) ),
		);
	}

	#[DataProvider( 'badBodies' )]
	public function testAnInvalidBodyIsRefusedAndNothingIsWritten( array $over, array $problems ): void {
		$body     = array_filter( self::body( $over ), static fn( $v ): bool => null !== $v );
		$response = $this->route( 'POST', $body, self::token() );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'ab_mcp_staging_invalid', $response->get_data()['code'] );
		self::assertSame( $problems, $response->get_data()['data']['problems'] );
		self::assertStringStartsWith( 'Nothing was saved: ', $response->get_data()['message'] );
		self::assertSame( array(), self::raw() );
	}

	public function testAWriteCountsOnceTowardsTheRateLimitAndIsRefusedBeyondIt(): void {
		$token = self::token();
		$count = static function (): int {
			$n = 0;
			foreach ( $GLOBALS['ab_test_transients'] as $key => $value ) {
				if ( 0 === strpos( (string) $key, 'ab_mcp_rl_7_' ) ) {
					$n += (int) $value;
				}
			}
			return $n;
		};
		self::assertSame( 200, $this->route( 'POST', self::body(), $token )->get_status() );
		self::assertSame( 1, $count(), 'Once, although WordPress asks the permission again for the Allow header.' );
		self::assertSame( 200, $this->route( 'GET', array(), $token )->get_status() );
		self::assertSame( 1, $count(), 'Reading does not count.' );

		AB_MCP_Settings::set( 'rate_limit_per_min', 1 );
		foreach ( array( 0, 60 ) as $offset ) {
			$GLOBALS['ab_test_transients'][ 'ab_mcp_rl_7_' . gmdate( 'YmdHi', time() + $offset ) ] = 1;
		}
		$response = $this->route( 'POST', self::body( array( 'partner_url' => 'https://stage.example.com' ) ), $token );
		self::assertSame( 429, $response->get_status() );
		self::assertSame( 'ab_mcp_rate_limited', $response->get_data()['code'] );
		self::assertSame( self::STAGING, AB_MCP_Staging::record()['pair']['partner_url'], 'Nothing written.' );
	}

	public function testCheckRateCountsTheCallOfTheAccount(): void {
		AB_MCP_Settings::set( 'rate_limit_per_min', 2 );
		self::assertTrue( AB_MCP_Security::check_rate( 7 ) );
		self::assertTrue( AB_MCP_Security::check_rate( 7 ) );
		$refused = AB_MCP_Security::check_rate( 7 );
		if ( true === $refused ) {
			// The minute changed between the calls; the next one is the third of the new minute.
			self::assertTrue( AB_MCP_Security::check_rate( 7 ) );
			$refused = AB_MCP_Security::check_rate( 7 );
		}
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertTrue( AB_MCP_Security::check_rate( 8 ), 'Per account.' );
	}

	/* ------------------------------------------------------ site contract */

	public function testTheSiteContractCarriesTheRole(): void {
		$meta = new ReflectionMethod( AB_MCP_REST_Controller::class, 'site_meta' );
		$ctrl = new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() );
		self::assertSame( 'unconfirmed', $meta->invoke( $ctrl )['staging_role'] );
		self::confirm( 'staging' );
		self::assertSame( 'staging', $meta->invoke( $ctrl )['staging_role'] );
		self::confirm( 'production' );
		self::assertSame( 'production', $meta->invoke( $ctrl )['staging_role'] );
		self::assertSame( 1, $meta->invoke( $ctrl )['contract'], 'An addition within contract 1.' );
	}

	/* ------------------------------------------------------- settings page */

	/** @param mixed ...$args */
	private function admin( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin(), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	/** Send the form; returns where it redirected to. */
	private function submit( array $post, string $nonce = 'nonce-ab_mcp_staging' ): AbTestExit {
		$_POST    = array_merge( $post, array( '_wpnonce' => $nonce ) );
		$_REQUEST = $_POST;
		try {
			( new AB_MCP_Admin() )->handle_staging_record();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( 'The form ended without a redirect.' );
	}

	public function testTheCardCarriesTheFormWithTheStateAndTheSuggestion(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$html                   = $this->admin( 'preview_card_html' );
		self::assertStringContainsString( '<div class="ab-staging" id="ab-staging">', $html );
		self::assertStringContainsString( 'Role of this site</h3>', $html );
		self::assertStringContainsString( 'Not confirmed: this site counts as the production site.', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="nonce-ab_mcp_staging"', $html );
		self::assertStringContainsString( '<input type="hidden" name="action" value="ab_mcp_staging">', $html );
		self::assertStringContainsString( 'value="unconfirmed" checked=\'checked\'> Not confirmed (counts as the production site)', $html );
		self::assertStringContainsString( 'value="production"> Production site', $html );
		self::assertStringContainsString( 'value="staging"> Staging site', $html );
		self::assertStringContainsString( 'Suggestion: production site; nothing on this site points to a staging site.', $html );
		self::assertStringContainsString( 'name="partner_url" class="regular-text" value=""', $html );
		self::assertStringContainsString( '<option value="" selected=\'selected\'>As detected: nothing found</option>', $html );
		self::assertStringContainsString( '<option value="infomaniak">Infomaniak</option>', $html );
		self::assertLessThan( strpos( $html, 'id="ab-staging"' ), strpos( $html, 'ab-preview__actions' ), 'Below the protection, in the same card.' );
		self::assertDoesNotMatchRegularExpression( '/\bPro\b|Agency/', $html );

		$GLOBALS['ab_test_home']      = 'https://staging.example.com';
		$_SERVER['KINSTA_CACHE_ZONE'] = 'z';
		self::confirm( 'staging', 'https://example.com' );
		AB_MCP_Staging::set_host( 'kinsta', 7 );
		$html = $this->admin( 'preview_card_html' );
		self::assertStringContainsString( 'value="staging" checked=\'checked\'> Staging site', $html );
		self::assertMatchesRegularExpression( '/Staging site, confirmed by user7 on \d{4}-\d\d-\d\d \d\d:\d\d on the settings page\. Other site: https:\/\/example\.com \(Production site\), entered by user7 on [^.]+ on the settings page\./', $html );
		self::assertStringContainsString( 'Suggestion: staging site. The address begins with “staging.”. A suggestion is a hint, not proof.', $html );
		self::assertStringContainsString( 'value="https://example.com"', $html );
		self::assertStringContainsString( '<option value="">As detected: Kinsta</option>', $html );
		self::assertStringContainsString( '<option value="kinsta" selected=\'selected\'>Kinsta</option>', $html );
	}

	public function testTheStateLineNamesTheHub(): void {
		AB_MCP_Staging::save( 'production', self::STAGING, 'staging', 7, AB_MCP_Staging::SOURCE_HUB );
		$text = $this->admin( 'staging_status_text', AB_MCP_Staging::record() );
		self::assertMatchesRegularExpression( '/^Production site, confirmed by user7 on .+ in the hub\. Other site: https:\/\/staging\.example\.com \(Staging site\), entered by user7 on .+ in the hub\.$/', $text );
	}

	public function testTheFormSavesBehindTheNonceAndTheCapability(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$exit                   = $this->submit( array( 'staging_role' => 'production', 'partner_url' => 'https://staging.example.com/', 'host' => 'cloudways' ) );
		self::assertSame( 'redirect', $exit->kind );
		self::assertStringContainsString( 'ab_notice=staging_saved', $exit->detail );
		self::assertStringEndsWith( '#ab-staging', $exit->detail );
		self::assertSame( 'production', AB_MCP_Staging::role() );
		self::assertSame( self::STAGING, AB_MCP_Staging::record()['pair']['partner_url'] );
		self::assertSame( 'cloudways', AB_MCP_Staging::record()['host']['value'] );
		self::assertSame( 'form', AB_MCP_Staging::record()['role']['source'] );

		$raw  = self::raw();
		$exit = $this->submit( array( 'staging_role' => 'staging', 'partner_url' => '', 'host' => '' ), 'nonce-ab_mcp_save' );
		self::assertSame( 'die', $exit->kind, 'Another form\'s nonce.' );
		self::assertSame( $raw, self::raw() );

		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		$exit                   = $this->submit( array( 'staging_role' => 'staging', 'partner_url' => '', 'host' => '' ) );
		self::assertSame( 'die', $exit->kind );
		self::assertSame( 'Insufficient permissions.', $exit->detail );
		self::assertSame( $raw, self::raw() );
	}

	/** @return array<string,array{0:array,1:string}> */
	public static function badForms(): array {
		return array(
			'an address that is no site' => array( array( 'staging_role' => 'production', 'partner_url' => 'staging.example.com', 'host' => '' ), 'staging_bad_partner_url' ),
			'the own address'            => array( array( 'staging_role' => 'production', 'partner_url' => 'https://example.com', 'host' => '' ), 'staging_bad_self' ),
			'an address without role'    => array( array( 'staging_role' => 'unconfirmed', 'partner_url' => self::STAGING, 'host' => '' ), 'staging_bad_no_role' ),
			'an unknown role'            => array( array( 'staging_role' => 'live', 'partner_url' => '', 'host' => '' ), 'staging_bad_role' ),
			'no role at all'             => array( array( 'partner_url' => '', 'host' => '' ), 'staging_bad_role' ),
			'an unknown host'            => array( array( 'staging_role' => 'staging', 'partner_url' => '', 'host' => 'godaddy' ), 'staging_bad_host' ),
		);
	}

	#[DataProvider( 'badForms' )]
	public function testAFormWithAProblemSavesNothingAndNamesIt( array $post, string $notice ): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$exit                   = $this->submit( $post );
		self::assertSame( 'redirect', $exit->kind );
		self::assertStringContainsString( 'ab_notice=' . $notice . '#', $exit->detail . '#' );
		self::assertSame( array(), self::raw(), 'Nothing, not the role, not the host.' );

		$_GET['ab_notice'] = $notice;
		$html              = $this->admin( 'notice' );
		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'Nothing was saved: ' . AB_MCP_Staging::problem( substr( $notice, strlen( 'staging_bad_' ) ) ), $html );
	}

	public function testTheFormTakesTheAddressAsTypedAndAnUnchangedPairStays(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		ab_test_add_user( 9 );
		AB_MCP_Staging::save( 'production', 'https://staging.example.com/pr%C3%BCfung', 'staging', 9, AB_MCP_Staging::SOURCE_HUB );
		$raw = self::raw();
		self::assertSame( 'https://staging.example.com/pr%C3%BCfung', $raw['pair']['partner_url'] );
		$html = $this->admin( 'preview_card_html' );
		self::assertStringContainsString( 'value="https://staging.example.com/pr%C3%BCfung"', $html, 'The field holds the stored address.' );

		$writes = ab_test_writes( AB_MCP_Settings::OPT_OPTIONS );
		$exit   = $this->submit( array( 'staging_role' => 'production', 'partner_url' => ' https://staging.example.com/pr%C3%BCfung ', 'host' => '' ) );
		self::assertStringContainsString( 'ab_notice=staging_saved', $exit->detail );
		self::assertSame( $raw, self::raw(), 'The unchanged form keeps the hub as the source, the account and the time.' );
		self::assertSame( $writes, ab_test_writes( AB_MCP_Settings::OPT_OPTIONS ), 'And writes nothing.' );

		$this->submit( array( 'staging_role' => 'production', 'partner_url' => 'https://staging.example.com/pr%C3%BCfung-2', 'host' => '' ) );
		self::assertSame( 'https://staging.example.com/pr%C3%BCfung-2', AB_MCP_Staging::record()['pair']['partner_url'], 'Percent-encoded characters stay as typed.' );
		self::assertSame( 'form', AB_MCP_Staging::record()['pair']['source'] );

		$this->submit( array( 'staging_role' => 'production', 'partner_url' => array( 'https://x.example' ), 'host' => '' ) );
		self::assertNull( AB_MCP_Staging::record()['pair'], 'A field that is no text is an empty address.' );
	}

	public function testTheRouteRefusesAnIpv6Address(): void {
		$response = $this->route( 'POST', self::body( array( 'partner_url' => 'https://[2606:2800:220:1:248:1893:25c8:1946]' ) ), self::token() );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( array( 'ipv6' ), $response->get_data()['data']['problems'] );
		self::assertSame( array(), self::raw() );
	}

	public function testTheFormNamesAnIpv6Address(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$exit                   = $this->submit( array( 'staging_role' => 'production', 'partner_url' => 'https://[fd00::1]', 'host' => '' ) );
		self::assertStringContainsString( 'ab_notice=staging_bad_ipv6#', $exit->detail . '#' );
		$_GET['ab_notice'] = 'staging_bad_ipv6';
		self::assertStringContainsString( 'Nothing was saved: The address of the other site is an IPv6 address', $this->admin( 'notice' ) );
	}

	/* -------------------------------------------------------------- proxy */

	/** Proxy settings as in wp-config.php; constants, so each test in a process of its own. */
	private static function proxy( string $bypass = '' ): void {
		\define( 'WP_PROXY_HOST', 'proxy.example' );
		\define( 'WP_PROXY_PORT', '3128' );
		if ( '' !== $bypass ) {
			\define( 'WP_PROXY_BYPASS_HOSTS', $bypass );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testThroughAProxyTheOtherSiteIsNotAsked(): void {
		self::proxy();
		self::confirm( 'production', self::STAGING );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$out = AB_MCP_Staging::report( true );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'The proxy would look the name up itself.' );
		self::assertSame( 'proxy', $out['partner_check']['status'] );
		self::assertStringContainsString( 'through a proxy (WP_PROXY_HOST)', $out['partner_check']['finding'] );
		$item = self::item( $out['checklist'], 'staging_connected' );
		self::assertFalse( $item['done'] );
		self::assertStringContainsString( 'WP_PROXY_BYPASS_HOSTS', $item['steps'] );
		self::assertStringContainsString( 'check by hand', $item['steps'] );
		self::assertStringStartsWith( $item['finding'] . ' 1. To let this site ask', $out['next_step'] );
		self::assertSame( 0, self::hooked( 'requests-fsockopen.before_request' ) );
	}

	/** @return array<string,array{0:string}> */
	public static function bypasses(): array {
		return array(
			'the host'     => array( 'localhost, staging.example.com' ),
			'a wildcard'   => array( '*.example.com' ),
		);
	}

	#[DataProvider( 'bypasses' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension( 'curl' )]
	public function testAHostTheProxyLeavesOutIsAskedDirectlyAndTied( string $bypass ): void {
		self::proxy( $bypass );
		self::confirm( 'production', self::STAGING );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		$GLOBALS['ab_test_http_curl'] = true;
		self::assertSame( 'reachable', AB_MCP_Staging::report( true )['partner_check']['status'] );
		self::assertStringContainsString( ' staging.example.com:443:' . self::PUBLIC_V4 . ' ', $GLOBALS['ab_test_http'][0]['curl'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAProxyAFilterLeavesOutIsNotInTheWay(): void {
		self::proxy();
		add_filter( 'pre_http_send_through_proxy', '__return_false' );
		self::confirm( 'production', self::STAGING );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		self::assertSame( 'reachable', AB_MCP_Staging::report( true )['partner_check']['status'] );
	}

	public function testWithoutAProxyNothingStandsInTheWay(): void {
		self::assertFalse( ( new \WP_HTTP_Proxy() )->is_enabled(), 'No proxy in this process.' );
		self::confirm( 'production', self::STAGING );
		self::answer( ab_test_http_response( 401, '{}', array( 'X-AB-MCP-Auth' => 'missing' ) ) );
		self::assertSame( 'reachable', AB_MCP_Staging::report( true )['partner_check']['status'] );
	}

	public function testTheNoticeAfterSaving(): void {
		$_GET['ab_notice'] = 'staging_saved';
		$html              = $this->admin( 'notice' );
		self::assertStringContainsString( 'notice-success', $html );
		self::assertStringContainsString( 'Saved: the role of this site, the address of the other site and the host.', $html );
	}

	public function testTheFormIsHooked(): void {
		$admin = new AB_MCP_Admin();
		self::assertSame( 10, has_action( 'admin_post_ab_mcp_staging', array( $admin, 'handle_staging_record' ) ) );
	}

	/* ------------------------------------------------------------ uninstall */

	public function testTheRecordLivesInTheOptionUninstallRemoves(): void {
		self::confirm( 'production', self::STAGING );
		self::assertArrayHasKey( AB_MCP_Staging::KEY, $GLOBALS['ab_test_options'][ AB_MCP_Settings::OPT_OPTIONS ] );
		self::assertStringContainsString( "'" . AB_MCP_Settings::OPT_OPTIONS . "',", (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ) );
	}
}
