<?php
/**
 * The trust between a staging site and its production site on two
 * WordPress sites (Z8, decisions 4 and 5 of the plan): site B is the
 * staging site, in processes of its own (AB_MCP_IT_Sync_Steps) and over
 * HTTP; site A, this process, is the production site.
 *
 * - B, confirmed as staging site by a person, has a source id and a key in
 *   two rows of its own without autoload; the private key is in no other
 *   row. Its route alphabridge/v1/staging-key answers the public part over
 *   HTTP without a login; A without the role answers 404.
 * - A reads what B's route answers (WordPress writes it with escaped
 *   slashes) and B's pairing code; the direct way asks only over https, so
 *   the integration sites (http) are never asked by it.
 * - A key A prepared reads no manifest until a person confirms it; then a
 *   manifest B signed is read, for A's own address exactly (another path,
 *   port or scheme is refused), and the key of an older confirmation is
 *   refused once B confirmed its role again.
 * - The print of the pair follows A's record and anchor.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Key;
use AB_MCP_Sync_Manifest;
use AB_MCP_Test_Sync_Fetcher;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-manifest.php';

final class SyncTrustTest extends TestCase {

	private const REPORT = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		wp_set_current_user( self::admin() );
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
	}

	private static function admin(): int {
		return (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'];
	}

	/** Steps on site B (bin/sync-site.php). */
	private static function b( array $steps ): array {
		return AB_MCP_IT_Sync_Steps::run( 'b', $steps );
	}

	/** B confirmed as the staging site of A by a person, with its key and pairing code. */
	private static function staging_b( string $partner = '' ): array {
		return self::b(
			array(
				array(
					'op'          => 'staging_save',
					'role'        => 'staging',
					'partner_url' => '' !== $partner ? $partner : AB_MCP_IT_Sites::url( 'a' ),
					'source'      => 'form',
				),
				array(
					'op' => 'sync_key',
					'as' => 'key',
				),
				array(
					'op' => 'pairing_code',
					'as' => 'code',
				),
			)
		);
	}

	/** A confirmed as the production site paired with B. */
	private static function production_a( string $source = 'form' ): void {
		$saved = AB_MCP_Staging::save( 'production', AB_MCP_IT_Sites::url( 'b' ), 'staging', self::admin(), $source );
		self::assertIsArray( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
	}

	/** A package as its maker names it. */
	private static function package( array $over = array() ): array {
		return array_merge(
			array(
				'package_id'  => str_repeat( 'ab', 16 ),
				'purpose'     => 'objects',
				'report_hash' => self::REPORT,
				'kinds'       => array(
					array(
						'complete' => true,
						'kind'     => 'post',
						'subtype'  => 'page',
					),
				),
				'objects'     => 1,
				'unpacked'    => 5,
				'chunks'      => array(
					array(
						'bytes'  => 5,
						'sha256' => hash( 'sha256', 'chunk' ),
					),
				),
				'bytes'       => 5,
				'sha256'      => hash( 'sha256', 'chunk' ),
			),
			$over
		);
	}

	/** B signs a package. */
	private static function signed_by_b(): array {
		return self::b(
			array(
				array(
					'op'      => 'sign_manifest',
					'package' => self::package(),
					'as'      => 'signed',
				),
			)
		)['signed'];
	}

	private static function verify_on_a( array $signed ) {
		return AB_MCP_Sync_Manifest::verify(
			$signed,
			array(
				'purpose'     => 'objects',
				'report_hash' => self::REPORT,
			)
		);
	}

	/** A anchors B's key from its code, and the person confirms it. */
	private static function anchor( string $code ): array {
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $code, self::admin() );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		$active = AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], self::admin() );
		self::assertIsArray( $active );
		return $active;
	}

	private static function refused( $result, string $code ): void {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
	}

	/** Whether a row stays out of autoload, by the values WordPress writes for it. */
	private static function assertNotAutoloaded( ?array $row, string $name ): void {
		self::assertNotNull( $row, $name . ' is stored.' );
		self::assertContains( $row['autoload'], array( 'off', 'no' ), $name . ' is not loaded on every request.' );
	}

	public function testTheStagingSiteGivesItsPublicKeyOverHttpAndKeepsThePrivateOne(): void {
		$b      = self::staging_b();
		$key    = $b['key'];
		$secret = AB_MCP_IT_Sites::option_row( 'b', AB_MCP_Sync_Key::OPTION_SECRET );
		self::assertNotAutoloaded( $secret, 'The private key' );
		self::assertNotAutoloaded( AB_MCP_IT_Sites::option_row( 'b', AB_MCP_Sync_Key::OPTION_SOURCE ), 'The public part' );
		$stored = unserialize( (string) $secret['option_value'] );
		$value  = (string) $stored['secret_key'];
		self::assertSame( 88, strlen( $value ), 'The private key, base64.' );
		foreach ( AB_MCP_IT_Sites::rows( 'b', 'options' ) as $row ) {
			if ( AB_MCP_Sync_Key::OPTION_SECRET !== $row['option_name'] ) {
				self::assertStringNotContainsString( $value, (string) $row['option_value'], $row['option_name'] . ' holds the private key.' );
			}
		}

		$answer = AB_MCP_IT_Sites::request( 'b', 'GET', '/alphabridge/v1/staging-key', array(), null, 'none' );
		self::assertSame( 200, $answer['status'], $answer['raw'] );
		self::assertSame(
			array(
				'format'      => 'ab-sync-key/1',
				'site'        => AB_MCP_Staging::normalize_url( AB_MCP_IT_Sites::url( 'b' ) ),
				'source_id'   => $key['source_id'],
				'key_id'      => $key['key_id'],
				'public_key'  => $key['public_key'],
				'fingerprint' => $key['fingerprint'],
			),
			$answer['body']
		);
		self::assertStringContainsString( 'no-store', implode( ' ', (array) ( $answer['headers']['cache-control'] ?? array() ) ) );
		self::assertStringNotContainsString( $value, $answer['raw'] );

		$none = AB_MCP_IT_Sites::request( 'a', 'GET', '/alphabridge/v1/staging-key', array(), null, 'none' );
		self::assertSame( 404, $none['status'], 'A site that is no staging site gives no key.' );
		self::assertSame( 'ab_mcp_staging_key_none', $none['body']['code'] );
	}

	public function testTheProductionSiteReadsTheRouteAndTheCodeButAsksOnlyOverHttps(): void {
		$b = self::staging_b();
		self::production_a();
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		self::refused( AB_MCP_Sync_Anchor::prepare_from_partner( self::admin(), $fetcher ), 'ab_mcp_sync_anchor_not_https' );
		self::assertSame( array(), $fetcher->calls, 'The http:// site B was not asked.' );

		// What B's route answers, as WordPress writes it, reads as B's key.
		$response = $fetcher->get( AB_MCP_Sync_Anchor::key_url( AB_MCP_Staging::normalize_url( AB_MCP_IT_Sites::url( 'b' ) ) ) );
		self::assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$read = AB_MCP_Sync_Key::read_answer( wp_remote_retrieve_body( $response ) );
		self::assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_message() : '' );
		self::assertSame( $b['key']['key_id'], $read['key_id'] );
		self::assertSame( $read, AB_MCP_Sync_Key::read_code( $b['code'] ), 'The code says the same.' );
	}

	public function testAManifestOfTheStagingSiteIsReadOnlyWithTheKeyAPersonConfirmed(): void {
		$b = self::staging_b();
		self::production_a();
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $b['code'], self::admin() );
		self::assertSame( 'code', $prepared['via'] );
		$signed = self::signed_by_b();
		self::refused( self::verify_on_a( $signed ), 'ab_mcp_sync_manifest_unconfirmed' );
		self::assertNull( AB_MCP_Sync_Anchor::pair_print() );

		AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], self::admin() );
		self::assertNotAutoloaded( AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Sync_Anchor::OPTION ), 'The anchor' );
		$read = self::verify_on_a( $signed );
		self::assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_message() : '' );
		self::assertSame( AB_MCP_Staging::normalize_url( AB_MCP_IT_Sites::url( 'a' ) ), $read['manifest']['target'] );
		self::assertSame( AB_MCP_Staging::normalize_url( AB_MCP_IT_Sites::url( 'b' ) ), $read['manifest']['source'] );
		self::assertSame( $b['key']['key_id'], $read['manifest']['key_id'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) AB_MCP_Sync_Anchor::pair_print() );
	}

	public function testAManifestForAnotherAddressOfTheProductionSiteIsRefused(): void {
		$b = self::staging_b();
		self::production_a();
		self::anchor( $b['code'] );
		$a     = AB_MCP_Staging::normalize_url( AB_MCP_IT_Sites::url( 'a' ) );
		$parts = wp_parse_url( $a );
		$other = array(
			'path'   => $a . '/sub',
			'port'   => $parts['scheme'] . '://' . $parts['host'] . ':' . ( (int) $parts['port'] + 100 ),
			'scheme' => 'https://' . $parts['host'] . ':' . $parts['port'],
		);
		$steps = array();
		foreach ( $other as $name => $target ) {
			$steps[] = array(
				'op'          => 'staging_save',
				'role'        => 'staging',
				'partner_url' => $target,
				'source'      => 'form',
			);
			$steps[] = array(
				'op'      => 'sign_manifest',
				'package' => self::package(),
				'as'      => $name,
			);
		}
		$signed = self::b( $steps );
		foreach ( $other as $name => $target ) {
			$refused = self::verify_on_a( $signed[ $name ] );
			self::refused( $refused, 'ab_mcp_sync_manifest_target' );
			self::assertSame( $target, $refused->get_error_data()['target'], $name );
		}
	}

	public function testAnotherConfirmationOfTheStagingRoleNeedsTheNewKeyConfirmed(): void {
		$b = self::staging_b();
		self::production_a();
		self::anchor( $b['code'] );
		$old = self::signed_by_b();
		self::assertIsArray( self::verify_on_a( $old ) );
		$first = AB_MCP_Sync_Anchor::pair_print();

		$again = self::b(
			array(
				array(
					'op'          => 'staging_save',
					'role'        => 'production',
					'partner_url' => AB_MCP_IT_Sites::url( 'a' ),
					'source'      => 'form',
				),
				array(
					'op'          => 'staging_save',
					'role'        => 'staging',
					'partner_url' => AB_MCP_IT_Sites::url( 'a' ),
					'source'      => 'form',
				),
				array(
					'op' => 'sync_key',
					'as' => 'key',
				),
				array(
					'op' => 'pairing_code',
					'as' => 'code',
				),
				array(
					'op'      => 'sign_manifest',
					'package' => self::package(),
					'as'      => 'signed',
				),
			)
		);
		self::assertNotSame( $b['key']['source_id'], $again['key']['source_id'], 'A new source.' );
		self::refused( self::verify_on_a( $again['signed'] ), 'ab_mcp_sync_manifest_key' );

		self::anchor( $again['code'] );
		self::assertNotSame( $first, AB_MCP_Sync_Anchor::pair_print(), 'A new anchor, a new print.' );
		self::assertIsArray( self::verify_on_a( $again['signed'] ) );
		self::refused( self::verify_on_a( $old ), 'ab_mcp_sync_manifest_key' );
	}

	public function testThePrintOfThePairFollowsTheRecordOfTheProductionSite(): void {
		$b = self::staging_b();
		self::production_a( 'hub' );
		self::anchor( $b['code'] );
		$first = AB_MCP_Sync_Anchor::pair_print();
		self::production_a( 'form' );
		self::assertNotNull( AB_MCP_Sync_Anchor::active(), 'The same pair keeps the anchor.' );
		self::assertNotSame( $first, AB_MCP_Sync_Anchor::pair_print(), 'A person confirmed role and pair anew.' );

		AB_MCP_Staging::save( 'production', 'https://staging.example.org', null, self::admin(), 'form' );
		self::assertNull( AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Sync_Anchor::OPTION ), 'Another pair: the anchor row is gone.' );
		self::assertNull( AB_MCP_Sync_Anchor::pair_print() );
	}
}
