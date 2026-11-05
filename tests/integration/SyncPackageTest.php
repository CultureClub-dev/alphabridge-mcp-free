<?php
/**
 * The packages of the takeover on two WordPress sites (Z8, decision 6 of the
 * plan; contract B): site B is the staging site, over HTTP and in processes
 * of its own (AB_MCP_IT_Sync_Steps); site A, this process, is the
 * production site, with the key of B a person confirmed.
 *
 * - wp_site_export on B (over MCP) makes a package of B's inventory in B's
 *   protected store: one line per object, every kind complete, the
 *   manifest signed for A, which A reads; a cursor makes the package from
 *   there, with only the kinds it read whole complete.
 * - B's route alphabridge/v1/staging-package answers a signed fetch address
 *   with the chunk as it is (application/octet-stream, no-store); every
 *   other request, a wrong or late signature, another parameter, a chunk it
 *   lacks, a package that is gone, another method, is answered exactly as
 *   WordPress answers a route it does not know.
 * - A fetches the package itself, at addresses it builds from B's stored
 *   address, through the test fetcher (the fetcher of the plugin asks only
 *   over https); a fetch that failed is taken up again with fresh fetch
 *   addresses, and a chunk that is there is not asked again.
 * - The relay: wp_site_export_chunk on B, wp_site_import_chunk on A (both
 *   over MCP, both sites without a public address); a changed chunk is
 *   refused.
 * - A checks the manifest on every call: another purpose, another package,
 *   another manifest for a package it holds, and a manifest after the
 *   anchor is gone are refused.
 * - A package is B's and its account's; after a day the clean-up removes it
 *   on both sites.
 * - In the process of a staging site (A as one): serve() answers only its
 *   route and only where no other filter answered, send() writes exactly
 *   the bytes of the answer serve() accepted, an expired manifest and a
 *   package whose making did not end give nothing.
 * - The whole package is checked against its hash once every chunk is
 *   there; a call fetches as far as its time reaches.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Protected_Store;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Export;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Manifest;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Sync_Fetcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';

final class SyncPackageTest extends TestCase {

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		wp_set_current_user( self::admin() );
	}

	protected function tearDown(): void {
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
	}

	/* ------------------------------------------------------------ helpers */

	private static function admin(): int {
		return (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'];
	}

	/** The stores of both sites, beside them (AB_IT_ROOT/ab-mcp-store). */
	private static function wipe(): void {
		$root = (string) AB_MCP_IT_Sites::state()['root'];
		self::remove_tree( $root . '/ab-mcp-store', $root );
	}

	private static function remove_tree( string $path, string $root ): void {
		if ( 0 !== strpos( $path, $root . '/' ) ) {
			throw new RuntimeException( "Will not remove $path: it is not in AB_IT_ROOT." );
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove_tree( $path . '/' . $entry, $root );
		}
		rmdir( $path );
	}

	/** Steps on site B (bin/sync-site.php). */
	private static function b( array $steps ): array {
		return AB_MCP_IT_Sync_Steps::run( 'b', $steps );
	}

	/**
	 * B the staging site of A, A the production site of B with B's key
	 * confirmed; both with write access on, as the tools that write need it.
	 */
	private static function pair(): void {
		$b = self::b(
			array(
				array( 'op' => 'write_access' ),
				array(
					'op'          => 'staging_save',
					'role'        => 'staging',
					'partner_url' => AB_MCP_IT_Sites::url( 'a' ),
					'source'      => 'form',
				),
				array(
					'op' => 'pairing_code',
					'as' => 'code',
				),
			)
		);
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		$saved = AB_MCP_Staging::save( 'production', AB_MCP_IT_Sites::url( 'b' ), 'staging', self::admin(), 'form' );
		self::assertIsArray( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $b['code'], self::admin() );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertIsArray( AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], self::admin() ) );
	}

	/** A tool over MCP: its structured answer, or the text of its error. */
	private static function tool( string $key, string $name, array $arguments ) {
		$answer = AB_MCP_IT_Sites::mcp(
			$key,
			'tools/call',
			array(
				'name'      => $name,
				'arguments' => $arguments,
			)
		);
		self::assertSame( 200, $answer['status'], $answer['raw'] );
		$result = $answer['body']['result'];
		return $result['isError'] ? $result['content'][0]['text'] : $result['structuredContent'];
	}

	/** B makes a package of its inventory, over MCP. */
	private static function export(): array {
		$package = self::tool( 'b', 'wp_site_export', array( 'purpose' => 'inventory' ) );
		self::assertIsArray( $package, is_string( $package ) ? $package : '' );
		return $package;
	}

	/** The query of a fetch address. */
	private static function query( string $package_id, array $grant ): array {
		return array(
			'package' => $package_id,
			'chunk'   => (string) $grant['chunk'],
			'expires' => (string) $grant['expires'],
			'sig'     => $grant['signature'],
		);
	}

	/** A request of B's route of the chunks, without a login. */
	private static function route( array $query, string $method = 'GET', string $route = '/alphabridge/v1/staging-package' ): array {
		return AB_MCP_IT_Sites::request( 'b', $method, $route, $query, null, 'none' );
	}

	/**
	 * B's answer to a request of the route exactly as its answer to the same
	 * request of a path WordPress does not know: status, body, headers.
	 */
	private static function assertNoRoute( array $query, string $method, string $case ): void {
		$answer = self::route( $query, $method );
		$want   = self::route( $query, $method, '/alphabridge/v1/staging-nothing' );
		self::assertStringContainsString( 'rest_no_route', $want['raw'], $case );
		self::assertSame( $want['status'], $answer['status'], $case );
		self::assertSame( $want['raw'], $answer['raw'], $case );
		$strip = static function ( array $headers ) {
			unset( $headers['date'] );
			ksort( $headers );
			return $headers;
		};
		self::assertSame( $strip( $want['headers'] ), $strip( $answer['headers'] ), $case );
	}

	/** The objects of a package: the lines of its chunks. */
	private static function lines( array $chunks ): array {
		$out = array();
		foreach ( $chunks as $chunk ) {
			$text = (string) gzdecode( $chunk );
			foreach ( '' === $text ? array() : explode( "\n", rtrim( $text, "\n" ) ) as $line ) {
				$out[] = json_decode( $line, true );
			}
		}
		return $out;
	}

	private static function refused( $result, string $code ): void {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
	}

	/* -------------------------------------------------------------- export */

	public function testTheStagingSiteMakesAPackageOfItsInventoryInItsProtectedStore(): void {
		self::pair();
		$package = self::export();
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}\z/', $package['package_id'] );
		self::assertSame( 'inventory', $package['purpose'] );
		self::assertNull( $package['cursor'], 'The inventory of a small site in one package.' );
		self::assertCount( $package['chunks'], $package['fetch'] );
		self::assertGreaterThan( 10, $package['objects'] );

		// The production site reads the manifest it was signed for.
		$read = AB_MCP_Sync_Manifest::verify( $package['manifest'], array( 'purpose' => 'inventory' ) );
		self::assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_message() : '' );
		self::assertSame( $package['package_id'], $read['manifest']['package_id'] );
		self::assertSame( $package['kinds'], $read['manifest']['kinds'] );

		// The chunks, over the route: each object of B's inventory once, a line each.
		$chunks = array();
		foreach ( $package['fetch'] as $grant ) {
			$answer = self::route( self::query( $package['package_id'], $grant ) );
			self::assertSame( 200, $answer['status'] );
			self::assertSame( 'application/octet-stream', $answer['headers']['content-type'] );
			self::assertSame( 'no-store', $answer['headers']['cache-control'] );
			self::assertSame( $read['manifest']['chunks'][ $grant['chunk'] ]['sha256'], hash( 'sha256', $answer['raw'] ), 'The chunk as the manifest names it.' );
			$chunks[] = $answer['raw'];
		}
		$objects = self::lines( $chunks );
		self::assertCount( $package['objects'], $objects );
		$titles = array();
		foreach ( $objects as $object ) {
			self::assertArrayNotHasKey( 'form', $object, 'The inventory, without the content.' );
			self::assertArrayHasKey( 'hash', $object );
			$titles[] = $object['kind'] . ':' . $object['ref'];
		}
		self::assertContains( 'option:blogname', $titles );
		self::assertSame( count( $titles ), count( array_unique( $titles ) ), 'Each object once.' );
		foreach ( $package['kinds'] as $kind ) {
			self::assertTrue( $kind['complete'], $kind['kind'] . ' ' . $kind['subtype'] . ': read whole.' );
		}

		// Again, with fresh fetch addresses, as long as B keeps it.
		$again = self::b(
			array(
				array(
					'op'         => 'export_again',
					'package_id' => $package['package_id'],
					'as'         => 'again',
				),
			)
		)['again'];
		self::assertSame( $package['manifest'], $again['manifest'] );
		self::assertCount( $package['chunks'], $again['fetch'] );
	}

	public function testAPackageFromACursorHoldsTheKindsFromThere(): void {
		self::pair();
		$package = self::b(
			array(
				array(
					'op'      => 'export',
					'purpose' => 'inventory',
					'cursor'  => array(
						'kind'  => 'term',
						'inner' => null,
					),
					'as'      => 'package',
				),
			)
		)['package'];
		$kinds = array_column( $package['kinds'], 'kind' );
		self::assertNotContains( 'post', $kinds, 'The posts were before the cursor.' );
		self::assertContains( 'option', $kinds );
		foreach ( $package['kinds'] as $kind ) {
			self::assertTrue( $kind['complete'], $kind['kind'] . ': begun at its start, read to its end.' );
		}
		$within = self::b(
			array(
				array(
					'op'      => 'export',
					'purpose' => 'inventory',
					'cursor'  => array(
						'kind'  => 'option',
						'inner' => array( 'after' => 1 ),
					),
					'as'      => 'package',
				),
			)
		)['package'];
		foreach ( $within['kinds'] as $kind ) {
			self::assertFalse( $kind['complete'], $kind['kind'] . ': begun within the kind.' );
		}
	}

	/* --------------------------------------------------------------- route */

	public function testTheRouteAnswersEveryOtherRequestAsARouteWordPressDoesNotKnow(): void {
		self::pair();
		$package = self::export();
		$grant   = $package['fetch'][0];
		$id      = $package['package_id'];
		$right   = self::query( $id, $grant );
		self::assertSame( 200, self::route( $right )['status'] );

		$cases = array(
			'another signature'   => array_merge( $right, array( 'sig' => str_repeat( 'a', 64 ) ) ),
			'another time'        => array_merge( $right, array( 'expires' => (string) ( $grant['expires'] - 1 ) ) ),
			'another parameter'   => array_merge( $right, array( 'x' => '1' ) ),
			'_envelope'           => array_merge( $right, array( '_envelope' => '1' ) ),
			'a parameter missing' => array_diff_key( $right, array( 'chunk' => true ) ),
			'the chunk as 00'     => array_merge( $right, array( 'chunk' => '00' ) ),
		);
		foreach ( $cases as $case => $query ) {
			self::assertNoRoute( $query, 'GET', $case );
		}
		self::assertNoRoute( $right, 'POST', 'POST' );

		// Signed by B, but late, valid too long, for a chunk or a package it lacks.
		$now    = time();
		$signed = self::b(
			array(
				array( 'op' => 'grants', 'package_id' => $id, 'count' => 1, 'now' => $now - 700, 'as' => 'late' ),
				array( 'op' => 'grants', 'package_id' => $id, 'count' => 1, 'now' => $now + 120, 'as' => 'long' ),
				array( 'op' => 'grants', 'package_id' => $id, 'count' => $package['chunks'] + 1, 'now' => $now, 'as' => 'more' ),
				array( 'op' => 'grants', 'package_id' => str_repeat( 'cd', 16 ), 'count' => 1, 'now' => $now, 'as' => 'other' ),
			)
		);
		self::assertNoRoute( self::query( $id, $signed['late'][0] ), 'GET', 'late' );
		self::assertNoRoute( self::query( $id, $signed['long'][0] ), 'GET', 'valid longer than ten minutes' );
		self::assertNoRoute( self::query( $id, $signed['more'][ $package['chunks'] ] ), 'GET', 'a chunk the package lacks' );
		self::assertNoRoute( self::query( str_repeat( 'cd', 16 ), $signed['other'][0] ), 'GET', 'a package B does not keep' );

		// After a day the clean-up removes the package.
		self::b(
			array(
				array( 'op' => 'store_cleanup', 'now' => $now + 86401 ),
			)
		);
		self::assertNoRoute( $right, 'GET', 'a package that is gone' );
	}

	/* --------------------------------------------------------------- fetch */

	public function testTheProductionSiteFetchesThePackageItselfAndTakesItUpAgain(): void {
		self::pair();
		$package = self::export();
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		$wrong   = array_map(
			static function ( array $grant ) {
				return array( 'signature' => str_repeat( 'a', 64 ) ) + $grant;
			},
			$package['fetch']
		);
		$first   = AB_MCP_Sync_Import::call(
			array(
				'package_id' => $package['package_id'],
				'manifest'   => $package['manifest'],
				'purpose'    => 'inventory',
				'fetch'      => $wrong,
			),
			$fetcher
		);
		self::assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );
		self::assertFalse( $first['complete'] );
		self::assertSame( range( 0, $package['chunks'] - 1 ), $first['missing'] );
		self::assertSame( 'ab_mcp_sync_import_fetch', $first['failed'][0]['code'], 'B answered 404.' );
		self::assertSame( 30, $fetcher->calls[0]['args']['timeout'] );
		$bytes = AB_MCP_Sync_Manifest::verify( $package['manifest'], array( 'purpose' => 'inventory' ) )['manifest']['chunks'][0]['bytes'];
		self::assertSame( $bytes + 1, $fetcher->calls[0]['args']['limit_response_size'], 'No more than the chunk: one byte more is a chunk that is not it.' );
		self::assertSame( 'application/octet-stream', $fetcher->calls[0]['args']['headers']['Accept'] );
		self::assertSame(
			AB_MCP_IT_Sites::url( 'b' ) . '/?rest_route=/alphabridge/v1/staging-package&package=' . $package['package_id'] . '&chunk=0&expires=' . $wrong[0]['expires'] . '&sig=' . str_repeat( 'a', 64 ),
			$fetcher->calls[0]['url'],
			'A builds the address from the stored address of B.'
		);

		$second = AB_MCP_Sync_Import::call(
			array(
				'package_id' => $package['package_id'],
				'fetch'      => $package['fetch'],
			),
			$fetcher
		);
		self::assertIsArray( $second, is_wp_error( $second ) ? $second->get_error_message() : '' );
		self::assertTrue( $second['complete'] );
		self::assertSame( array(), $second['missing'] );
		self::assertSame( $package['chunks'], $second['received'] );
		self::assertArrayNotHasKey( 'failed', $second );

		self::refused(
			AB_MCP_Sync_Import::call(
				array(
					'package_id' => $package['package_id'],
					'fetch'      => array( array( 'chunk' => $package['chunks'] ) + $package['fetch'][0] ),
				),
				$fetcher
			),
			'ab_mcp_sync_import_fetch_list'
		);
		$asked = count( $fetcher->calls );
		$third = AB_MCP_Sync_Import::call(
			array(
				'package_id' => $package['package_id'],
				'fetch'      => $package['fetch'],
			),
			$fetcher
		);
		self::assertTrue( $third['complete'] );
		self::assertCount( $asked, $fetcher->calls, 'A chunk that is there is not asked again.' );

		// What A keeps is what B made.
		$groups = AB_MCP_Protected_Store::groups( 'in' );
		self::assertCount( 1, $groups );
		self::assertSame( 'package-' . $package['package_id'], $groups[0]['label'] );
		$files = AB_MCP_Protected_Store::files( 'in', $groups[0]['gid'] );
		self::assertArrayHasKey( 'chunk-0000', $files );
		self::assertArrayHasKey( 'complete', $files );
	}

	public function testTheProductionSiteChecksTheManifestOnEveryCall(): void {
		self::pair();
		$package = self::export();
		$args    = array(
			'package_id' => $package['package_id'],
			'manifest'   => $package['manifest'],
			'purpose'    => 'inventory',
		);
		self::refused( AB_MCP_Sync_Import::call( array( 'purpose' => 'objects', 'report_hash' => str_repeat( 'c', 64 ) ) + $args ), 'ab_mcp_sync_manifest_purpose' );
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => str_repeat( 'cd', 16 ) ) + $args ), 'ab_mcp_sync_manifest_package' );
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'] ) ), 'ab_mcp_sync_import_unknown' );
		self::refused( AB_MCP_Sync_Import::call( array( 'purpose' => 'nothing' ) + $args ), 'ab_mcp_sync_import_purpose' );
		$begun = AB_MCP_Sync_Import::call( $args );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
		self::assertSame( range( 0, $package['chunks'] - 1 ), $begun['missing'] );
		self::assertIsArray( AB_MCP_Sync_Import::call( $args ), 'The same manifest again.' );

		$other = self::export();
		self::refused( AB_MCP_Sync_Import::call( array( 'manifest' => $other['manifest'] ) + $args ), 'ab_mcp_sync_import_other_manifest' );

		AB_MCP_Sync_Anchor::forget();
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'] ) ), 'ab_mcp_sync_manifest_no_anchor' );
	}

	/* --------------------------------------------------------------- relay */

	public function testTheRelayCarriesAChunkFromTheStagingSiteToTheProductionSite(): void {
		self::pair();
		$package = self::export();
		$begun   = self::tool(
			'a',
			'wp_site_import_chunk',
			array(
				'package_id' => $package['package_id'],
				'manifest'   => $package['manifest'],
				'purpose'    => 'inventory',
			)
		);
		self::assertIsArray( $begun, is_string( $begun ) ? $begun : '' );
		$last = null;
		foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
			$chunk = self::tool(
				'b',
				'wp_site_export_chunk',
				array(
					'package_id' => $package['package_id'],
					'chunk'      => $i,
				)
			);
			self::assertIsArray( $chunk, is_string( $chunk ) ? $chunk : '' );
			$changed = base64_encode( strrev( (string) base64_decode( $chunk['data'] ) ) );
			self::assertStringContainsString( 'is not the chunk the manifest names', (string) self::tool( 'a', 'wp_site_import_chunk', array( 'package_id' => $package['package_id'], 'chunk' => $i, 'data' => $changed ) ) );
			foreach ( array( '', rtrim( $chunk['data'], '=' ) . ' ' ) as $odd ) {
				self::assertStringContainsString( 'data is the chunk as base64', (string) self::tool( 'a', 'wp_site_import_chunk', array( 'package_id' => $package['package_id'], 'chunk' => $i, 'data' => $odd ) ) );
			}
			foreach ( array( 1, 2 ) as $time ) {
				$last = self::tool(
					'a',
					'wp_site_import_chunk',
					array(
						'package_id' => $package['package_id'],
						'chunk'      => $i,
						'data'       => $chunk['data'],
					)
				);
				self::assertIsArray( $last, ( is_string( $last ) ? $last : '' ) . ' (time ' . $time . ': a chunk that is there already stays)' );
			}
		}
		self::assertTrue( $last['complete'] );
		self::assertSame( array(), $last['missing'] );
		self::assertStringContainsString( 'The package has no chunk ' . $package['chunks'], (string) self::tool( 'b', 'wp_site_export_chunk', array( 'package_id' => $package['package_id'], 'chunk' => $package['chunks'] ) ) );
	}

	public function testAPackageIsCheckedWholeOnceEveryChunkIsThere(): void {
		self::pair();
		$signed = self::b(
			array(
				array(
					'op'      => 'sign_manifest',
					'package' => array(
						'package_id'  => str_repeat( 'ef', 16 ),
						'purpose'     => 'inventory',
						'report_hash' => null,
						'kinds'       => array(),
						'objects'     => 0,
						'unpacked'    => 0,
						'chunks'      => array(
							array(
								'bytes'  => 3,
								'sha256' => hash( 'sha256', 'abc' ),
							),
						),
						'bytes'       => 3,
						'sha256'      => hash( 'sha256', 'another package' ),
					),
					'as'      => 'signed',
				),
			)
		)['signed'];
		$args  = array( 'package_id' => str_repeat( 'ef', 16 ) );
		$begun = AB_MCP_Sync_Import::call( $args + array( 'manifest' => $signed, 'purpose' => 'inventory' ) );
		self::assertSame( array( 0 ), $begun['missing'] );
		self::refused( AB_MCP_Sync_Import::call( $args + array( 'chunk' => 0, 'data' => base64_encode( 'abc' ) ) ), 'ab_mcp_sync_package_hash' );
		$group = AB_MCP_Protected_Store::groups( 'in' )[0];
		self::assertArrayNotHasKey( 'complete', AB_MCP_Protected_Store::files( 'in', $group['gid'] ), 'Not marked complete.' );
		self::refused( AB_MCP_Sync_Import::call( $args ), 'ab_mcp_sync_package_hash' );
	}

	/* ------------------------------------------------------ whose and how long */

	public function testAPackageIsGivenOutToItsAccountAndRemovedAfterADay(): void {
		self::pair();
		$package = self::export();
		$steps   = self::b(
			array(
				array( 'op' => 'add_admin', 'login' => 'second', 'as' => 'second' ),
				array( 'op' => 'as_user', 'user' => '$second' ),
				array( 'op' => 'export_chunk', 'package_id' => $package['package_id'], 'chunk' => 0, 'expect' => 'error', 'as' => 'chunk' ),
				array( 'op' => 'export_again', 'package_id' => $package['package_id'], 'expect' => 'error', 'as' => 'again' ),
			)
		);
		self::assertSame( 'ab_mcp_sync_export_unknown', $steps['chunk']['code'], 'Another account reads no chunk of it.' );
		self::assertSame( 'ab_mcp_sync_export_unknown', $steps['again']['code'] );

		$late = self::b(
			array(
				array( 'op' => 'export_chunk', 'package_id' => $package['package_id'], 'chunk' => 0, 'now' => $package['expires'], 'expect' => 'error', 'as' => 'chunk' ),
				array( 'op' => 'export_chunk', 'package_id' => $package['package_id'], 'chunk' => 0, 'now' => $package['expires'] - 1, 'as' => 'before' ),
			)
		);
		self::assertSame( 'ab_mcp_sync_export_unknown', $late['chunk']['code'], 'Not after the manifest expired.' );
		self::assertSame( 0, $late['before']['chunk'] );

		// A takes it, and after a day neither site keeps it.
		self::assertTrue( AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'], 'manifest' => $package['manifest'], 'purpose' => 'inventory', 'fetch' => $package['fetch'] ), AB_MCP_Test_Sync_Fetcher::for_sites() )['complete'] );
		$now = time() + 86401;
		self::assertSame( 1, AB_MCP_Protected_Store::cleanup( $now )['removed'] );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'in' ) );
		$gone = self::b(
			array(
				array( 'op' => 'store_cleanup', 'now' => $now, 'as' => 'cleanup' ),
				array( 'op' => 'export_again', 'package_id' => $package['package_id'], 'expect' => 'error', 'as' => 'again' ),
			)
		);
		self::assertSame( 1, $gone['cleanup']['removed'] );
		self::assertSame( 'ab_mcp_sync_export_unknown', $gone['again']['code'] );
	}

	public function testACallFetchesAsFarAsItsTimeReaches(): void {
		self::pair();
		$package = self::export();
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		$times   = array( 1000.0, 1061.0 );
		AB_MCP_Sync_Import::$clock = static function () use ( &$times ) {
			return count( $times ) > 1 ? array_shift( $times ) : $times[0];
		};
		try {
			$state = AB_MCP_Sync_Import::call(
				array(
					'package_id' => $package['package_id'],
					'manifest'   => $package['manifest'],
					'purpose'    => 'inventory',
					'fetch'      => $package['fetch'],
				),
				$fetcher
			);
		} finally {
			AB_MCP_Sync_Import::$clock = null;
		}
		self::assertSame( array(), $fetcher->calls, 'Past its 60 seconds, nothing more is asked.' );
		self::assertSame( range( 0, $package['chunks'] - 1 ), $state['missing'] );
		self::assertArrayNotHasKey( 'failed', $state );
		self::assertSame( 60, AB_MCP_Sync_Import::BUDGET );
	}

	public function testAStagingSiteAnswersOnlyTheRequestOfItsRouteWithTheBytesOfTheChunk(): void {
		// A as the staging site of B, in this process.
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		$saved = AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' );
		self::assertIsArray( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
		$package = AB_MCP_Sync_Export::make( 'inventory' );
		self::assertIsArray( $package, is_wp_error( $package ) ? $package->get_error_message() : '' );
		$manifest = json_decode( $package['manifest']['manifest'], true );
		$request  = static function ( array $grant, string $route = '/alphabridge/v1/staging-package' ) use ( $package ) {
			$request = new \WP_REST_Request( 'GET', $route );
			$request->set_query_params( self::query( $package['package_id'], $grant ) );
			return $request;
		};
		$right    = $request( $package['fetch'][0] );

		$response = AB_MCP_Sync_Export::serve( null, rest_get_server(), $right );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'application/octet-stream', $response->get_headers()['Content-Type'] );
		self::assertSame( 'x', AB_MCP_Sync_Export::serve( 'x', rest_get_server(), $right ), 'What another filter answered stays.' );
		self::assertNull( AB_MCP_Sync_Export::serve( null, rest_get_server(), $request( $package['fetch'][0], '/alphabridge/v1/staging-key' ) ), 'Another path.' );

		self::assertFalse( AB_MCP_Sync_Export::send( false, new \WP_REST_Response( null ), $right ), 'Another answer is not written.' );
		ob_start();
		$served = AB_MCP_Sync_Export::send( false, $response, $right );
		$bytes  = (string) ob_get_clean();
		self::assertTrue( $served );
		self::assertSame( $manifest['chunks'][0]['sha256'], hash( 'sha256', $bytes ), 'Exactly the chunk.' );
		ob_start();
		self::assertFalse( AB_MCP_Sync_Export::send( false, $response, $right ), 'Once.' );
		self::assertSame( '', (string) ob_get_clean() );

		// Expired with its manifest, though the address is still valid.
		$late = AB_MCP_Sync_Export::grants( $package['package_id'], 1, $manifest['expires'] - 300 )[0];
		self::assertNull( AB_MCP_Sync_Export::requested( $request( $late ), $manifest['expires'] ) );
		self::assertSame( $bytes, AB_MCP_Sync_Export::requested( $request( $late ), $manifest['expires'] - 1 ) );

		// A package whose making did not end: chunks, no record.
		$gid = AB_MCP_Protected_Store::open( 'out', array( 'declared' => 10 ) );
		self::assertIsString( $gid );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'out', $gid, AB_MCP_Sync_Export::chunk_name( 0 ), 'abc' ) );
		self::refused( AB_MCP_Sync_Export::again( $gid ), 'ab_mcp_sync_export_unknown' );
		$grant = AB_MCP_Sync_Export::grants( $gid, 1, time() )[0];
		$req   = new \WP_REST_Request( 'GET', '/alphabridge/v1/staging-package' );
		$req->set_query_params( self::query( $gid, $grant ) );
		self::assertNull( AB_MCP_Sync_Export::requested( $req, time() ) );
	}
}
