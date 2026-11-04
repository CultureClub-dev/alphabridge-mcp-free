<?php
/**
 * Two sites for the unit tests of the trust of the takeover (Z8, decisions
 * 4 and 5): the staging site and the production site, each with options of
 * its own and its own address, switched by on(). The bootstrap keeps one
 * store of options; on() keeps the store of the site it leaves and puts
 * back the one of the site it enters, so a site never reads what the other
 * wrote (as on two servers). The two talk only through what a test carries
 * over: the answer of the route, the pairing code, a signed manifest.
 *
 * Whether the same holds between two WordPress sites with a database and
 * HTTP is shown in tests/integration (SyncTrustTest).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Key;
use AB_MCP_Sync_Manifest;

trait SyncTrustSites {

	/** @var array<string,array> The options of each site while the other one runs. */
	private array $stores = array(
		'staging' => array(),
		'live'    => array(),
	);

	/** @var string The site that runs now. */
	private string $here = '';

	/** The addresses of the two sites. */
	private static function address( string $site ): string {
		return 'staging' === $site ? 'https://staging.example.com' : 'https://www.example.com';
	}

	/** Run as this site from now on: its options, its address. */
	private function on( string $site ): void {
		if ( '' !== $this->here ) {
			$this->stores[ $this->here ] = $GLOBALS['ab_test_options'];
		}
		$GLOBALS['ab_test_options'] = $this->stores[ $site ];
		$GLOBALS['ab_test_home']    = self::address( $site );
		$this->here                 = $site;
	}

	/** Both sites confirmed and paired by a person on the form; the staging site has its key. */
	private function paired(): void {
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		$this->on( 'staging' );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', self::address( 'live' ), null, 3, 'form' ) );
		$this->on( 'live' );
		self::assertIsArray( AB_MCP_Staging::save( 'production', self::address( 'staging' ), null, 3, 'form' ) );
	}

	/** The pairing code of the staging site. */
	private function code(): string {
		$this->on( 'staging' );
		$code = AB_MCP_Sync_Key::pairing_code();
		self::assertIsString( $code );
		return $code;
	}

	/** The production site anchors the key of the staging site from its code, and a person confirms it. */
	private function anchored(): array {
		$code = $this->code();
		$this->on( 'live' );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $code, 3 );
		self::assertIsArray( $prepared );
		$active = AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], 3 );
		self::assertIsArray( $active );
		return $active;
	}

	/** A package as its maker names it: one chunk of the given bytes. */
	private static function package( array $over = array(), string $data = 'chunk' ): array {
		return array_merge(
			array(
				'package_id'  => str_repeat( 'ab', 16 ),
				'purpose'     => 'objects',
				'report_hash' => str_repeat( 'c', 64 ),
				'kinds'       => array(
					array(
						'kind'     => 'post',
						'subtype'  => 'page',
						'complete' => true,
					),
				),
				'objects'     => 1,
				'unpacked'    => 100,
				'chunks'      => array(
					array(
						'bytes'  => strlen( $data ),
						'sha256' => hash( 'sha256', $data ),
					),
				),
				'bytes'       => strlen( $data ),
				'sha256'      => hash( 'sha256', $data ),
			),
			$over
		);
	}

	/** The staging site signs a package. */
	private function signed( array $over = array(), ?int $now = null ): array {
		$this->on( 'staging' );
		$signed = AB_MCP_Sync_Manifest::sign( self::package( $over ), $now );
		self::assertIsArray( $signed, is_object( $signed ) ? $signed->get_error_message() : '' );
		return $signed;
	}

	/** The raw private key of the staging site, for a test that signs bytes of its own. */
	private function secret(): string {
		$here = $this->here;
		$this->on( 'staging' );
		$raw = get_option( AB_MCP_Sync_Key::OPTION_SECRET );
		$this->on( $here );
		return sodium_base642bin( $raw['secret_key'], SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/** A text signed with the private key of the staging site, as the manifest's envelope. */
	private function resigned( string $text, ?string $secret = null ): array {
		return array(
			'manifest'  => $text,
			'signature' => sodium_bin2base64( sodium_crypto_sign_detached( AB_MCP_Sync_Manifest::CONTEXT . $text, $secret ?? $this->secret() ), SODIUM_BASE64_VARIANT_ORIGINAL ),
		);
	}
}
