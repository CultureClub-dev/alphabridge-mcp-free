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
use AB_MCP_Sync_Grant;
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
			if ( ! array_key_exists( 'hash', $object ) ) {
				self::assertSame( 'b9', $object['status'], $object['kind'] . ':' . $object['ref'] . ': without its hash only where a credential holds it.' );
			}
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

	public function testTheInventoryInASeriesOfPackagesHoldsEachObjectOnceAndAKindWholeWhereItIs(): void {
		self::pair();
		// The whole inventory in one package (twice: the account holds two
		// open exports), then the same in packages of at most 25 objects,
		// which go on as its third, one series.
		self::b( array( array( 'op' => 'export', 'purpose' => 'inventory', 'as' => 'p' ) ) );
		$whole = self::b( array( array( 'op' => 'export', 'purpose' => 'inventory', 'as' => 'p' ) ) )['p'];
		$all   = self::objects_of( $whole );
		self::assertNull( $whole['cursor'] );
		$series = array();
		$cursor = null;
		do {
			$step = array( 'op' => 'export', 'purpose' => 'inventory', 'objects' => 25, 'as' => 'p' );
			if ( null !== $cursor ) {
				$step['cursor'] = $cursor;
			}
			$package  = self::b( array( $step ) )['p'];
			$series[] = $package;
			$cursor   = $package['cursor'];
			if ( null !== $cursor ) {
				self::assertSame( $package['package_id'], $cursor['package'], 'The cursor names the package it goes on from.' );
			}
		} while ( null !== $cursor && count( $series ) < 50 );
		self::assertNull( $cursor, 'The series ends.' );
		self::assertGreaterThan( 3, count( $series ), 'More packages than open exports an account keeps: one series is one export.' );
		$seen = array();
		foreach ( $series as $package ) {
			$objects = self::objects_of( $package );
			foreach ( $objects as $key => $object ) {
				self::assertArrayNotHasKey( $key, $seen, $key . ' once.' );
				$seen[ $key ] = $object;
			}
			foreach ( $package['kinds'] as $kind ) {
				if ( ! $kind['complete'] ) {
					continue;
				}
				foreach ( $all as $key => $object ) {
					if ( $object['kind'] === $kind['kind'] && ( $object['identity']['post_type'] ?? $object['identity']['taxonomy'] ?? '' ) === $kind['subtype'] ) {
						self::assertArrayHasKey( $key, $objects, $kind['kind'] . ' ' . $kind['subtype'] . ' is complete only where every object of it is.' );
					}
				}
			}
		}
		ksort( $seen );
		ksort( $all );
		self::assertSame( array_keys( $all ), array_keys( $seen ), 'Together, the whole inventory.' );

		// A cursor no answer gave, or of another package, goes nowhere.
		$second = $series[1];
		foreach ( array(
			'no package'         => array( 'kind' => $second['cursor']['kind'] ?? 'post', 'inner' => null ),
			'another package'    => array( 'package' => $whole['package_id'] ) + ( $series[0]['cursor'] ),
			'another place'      => array( 'inner' => array( 'after' => 1 ) ) + ( $series[0]['cursor'] ),
		) as $case => $odd ) {
			$refused = self::b( array( array( 'op' => 'export', 'purpose' => 'inventory', 'cursor' => $odd, 'expect' => 'error', 'as' => 'p' ) ) )['p'];
			self::assertSame( 'ab_mcp_sync_cursor_invalid', $refused['code'], $case );
		}
	}

	/** The objects of a package of B, by kind and ref, through the relay of B. */
	private static function objects_of( array $package ): array {
		$steps = array();
		foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
			$steps[] = array( 'op' => 'export_chunk', 'package_id' => $package['package_id'], 'chunk' => $i, 'as' => 'c' . $i );
		}
		$chunks = array();
		foreach ( self::b( $steps ) as $chunk ) {
			$chunks[] = (string) base64_decode( $chunk['data'] );
		}
		$out = array();
		foreach ( self::lines( $chunks ) as $object ) {
			$out[ $object['kind'] . ':' . $object['ref'] ] = $object;
		}
		return $out;
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
		self::assertEquals( 30, $fetcher->calls[0]['args']['timeout'] );
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
		self::refused( AB_MCP_Sync_Import::call( array( 'purpose' => 'objects' ) + $args ), 'ab_mcp_sync_import_purpose' );
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

		$response = AB_MCP_Sync_Grant::serve( null, rest_get_server(), $right );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'application/octet-stream', $response->get_headers()['Content-Type'] );
		self::assertSame( 'x', AB_MCP_Sync_Grant::serve( 'x', rest_get_server(), $right ), 'What another filter answered stays.' );
		self::assertNull( AB_MCP_Sync_Grant::serve( null, rest_get_server(), $request( $package['fetch'][0], '/alphabridge/v1/staging-key' ) ), 'Another path.' );

		self::assertFalse( AB_MCP_Sync_Grant::send( false, new \WP_REST_Response( null ), $right ), 'Another answer is not written.' );
		ob_start();
		$served = AB_MCP_Sync_Grant::send( false, $response, $right );
		$bytes  = (string) ob_get_clean();
		self::assertTrue( $served );
		self::assertSame( $manifest['chunks'][0]['sha256'], hash( 'sha256', $bytes ), 'Exactly the chunk.' );
		ob_start();
		self::assertFalse( AB_MCP_Sync_Grant::send( false, $response, $right ), 'Once.' );
		self::assertSame( '', (string) ob_get_clean() );

		// Expired with its manifest, though the address is still valid.
		$late = AB_MCP_Sync_Grant::grants( $package['package_id'], 1, $manifest['expires'] - 300 )[0];
		self::assertNull( AB_MCP_Sync_Export::requested( $request( $late ), $manifest['expires'] ) );
		self::assertSame( $bytes, AB_MCP_Sync_Export::requested( $request( $late ), $manifest['expires'] - 1 ) );

		// A package whose making did not end: chunks, no record.
		$gid = AB_MCP_Protected_Store::open( 'out', array( 'declared' => 10 ) );
		self::assertIsString( $gid );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'out', $gid, AB_MCP_Sync_Export::chunk_name( 0 ), 'abc' ) );
		self::refused( AB_MCP_Sync_Export::again( $gid ), 'ab_mcp_sync_export_unknown' );
		$grant = AB_MCP_Sync_Grant::grants( $gid, 1, time() )[0];
		$req   = new \WP_REST_Request( 'GET', '/alphabridge/v1/staging-package' );
		$req->set_query_params( self::query( $gid, $grant ) );
		self::assertNull( AB_MCP_Sync_Export::requested( $req, time() ) );
	}

	public function testTheRouteChecksTheMacBeforeItLoadsTheExport(): void {
		self::pair();
		$package = self::export();
		$right   = self::query( $package['package_id'], $package['fetch'][0] );
		$wrong   = array( 'sig' => str_repeat( '0', 64 ) ) + $right;
		$none    = array( 'export' => false, 'inventory' => false, 'kinds' => false );
		$out     = self::b(
			array(
				array(
					'op' => 'loaded',
					'as' => 'boot',
				),
				array(
					'op'    => 'serve',
					'as'    => 'wrong',
					'query' => $wrong,
				),
				array(
					'op' => 'loaded',
					'as' => 'after_wrong',
				),
				array(
					'op'    => 'serve',
					'as'    => 'right',
					'query' => $right,
				),
				array(
					'op' => 'loaded',
					'as' => 'after_right',
				),
			)
		);
		self::assertSame( $none, $out['boot'], 'WordPress with the plugin loads none of them.' );
		self::assertSame( 404, $out['wrong'] );
		self::assertSame( $none, $out['after_wrong'], 'A wrong MAC loads none of them.' );
		self::assertSame( 200, $out['right'] );
		self::assertSame( array( 'export' => true, 'inventory' => true, 'kinds' => true ), $out['after_right'], 'Only a right MAC.' );
	}

	/* ------------------------------------- no value of the content (A-1) */

	public function testNoValueOfTheContentLeavesTheStagingSite(): void {
		self::pair();
		$raw = array( 'zzraw', '987650001', '123456789', '555666777', '444555666', '333444555', '222333444', '111222333' );
		self::b(
			array(
				array( 'op' => 'insert_post', 'args' => array( 'post_title' => 'Probe', 'post_status' => 'publish', 'post_content' => '<p>zzrawpost 987650001</p>' ), 'as' => 'post' ),
				array( 'op' => 'add_meta', 'post' => '$post', 'key' => 'service_settings', 'value' => '{"api_key":"123456789","enabled":true}' ),
				array( 'op' => 'add_meta', 'post' => '$post', 'key' => 'plain_note', 'value' => 'zzrawmeta 555666777' ),
				array( 'op' => 'insert_term', 'name' => 'Probe term', 'taxonomy' => 'category', 'args' => array( 'description' => 'zzrawterm 444555666' ) ),
				array( 'op' => 'update_option', 'name' => 'zz_custom_setting', 'value' => 'zzrawoption 333444555' ),
				array( 'op' => 'update_option', 'name' => 'password_1', 'value' => 'zzrawsecret 222333444' ),
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => array( 2 => array( 'title' => 'Probe', 'text' => 'zzrawwidget 111222333' ), '_multiwidget' => 1 ) ),
			)
		);
		$package = self::export();
		$chunks  = array();
		foreach ( $package['fetch'] as $grant ) {
			$chunks[] = self::route( self::query( $package['package_id'], $grant ) )['raw'];
		}
		$text = '';
		foreach ( $chunks as $chunk ) {
			$text .= (string) gzdecode( $chunk );
		}
		foreach ( $raw as $value ) {
			self::assertStringNotContainsString( $value, $text, 'No value of the content: ' . $value );
		}
		$held = null;
		foreach ( self::lines( $chunks ) as $object ) {
			if ( 'post' === $object['kind'] && 'probe' === ( $object['identity']['post_name'] ?? '' ) ) {
				$held = $object;
			}
		}
		self::assertNotNull( $held, 'The post with the credential is there.' );
		self::assertSame( array( 'identity', 'kind', 'locks', 'ref', 'status' ), self::sorted( array_keys( array_diff_key( $held, array( 'reason' => true ) ) ) ), 'With its identity and the reason only.' );
		self::assertContains( 'credential_field', array_map( static fn( $lock ) => substr( $lock['key'], strpos( $lock['key'], ':' ) + 1 ), $held['locks'] ) );
	}

	public function testAnObjectAnyLockOfTheCredentialCheckHoldsCarriesItsIdentityAndTheReasonOnly(): void {
		self::pair();
		self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => array(
						'post_title'   => 'Script probe',
						'post_name'    => 'script-probe',
						'post_status'  => 'publish',
						'post_content' => '<script>var api_key = "https://staging.example.com/?p=123456789";</script>',
					),
				),
			)
		);
		$package = self::export();
		$chunks  = array();
		foreach ( $package['fetch'] as $grant ) {
			$chunks[] = self::route( self::query( $package['package_id'], $grant ) )['raw'];
		}
		$held = null;
		foreach ( self::lines( $chunks ) as $object ) {
			if ( 'post' === $object['kind'] && 'script-probe' === ( $object['identity']['post_name'] ?? '' ) ) {
				$held = $object;
			}
		}
		self::assertNotNull( $held, 'The post is there.' );
		self::assertSame( array( 'identity', 'kind', 'locks', 'ref', 'status' ), self::sorted( array_keys( array_diff_key( $held, array( 'reason' => true ) ) ) ), 'With its identity and the reason only: no hash, no references, no possible ones.' );
		$ops = array_map( static fn( $lock ) => substr( $lock['key'], strpos( $lock['key'], ':' ) + 1 ), $held['locks'] );
		self::assertNotSame( array(), array_intersect( $ops, AB_MCP_Sync_Export::CREDENTIAL_LOCKS ), 'A lock of the credential check: ' . implode( ', ', $ops ) );
		$text = '';
		foreach ( $chunks as $chunk ) {
			$text .= (string) gzdecode( $chunk );
		}
		self::assertStringNotContainsString( '123456789', $text );
	}

	private static function sorted( array $list ): array {
		sort( $list );
		return $list;
	}

	/* ------------------------------------------------ the binding (A-2) */

	public function testAPackageIsGivenOutOnlyAsLongAsThePairAndTheKeyHold(): void {
		self::pair();
		$package = self::export();
		$right   = self::query( $package['package_id'], $package['fetch'][0] );
		self::assertSame( 200, self::route( $right )['status'] );

		// B paired again, with another production site.
		$steps = self::b(
			array(
				array( 'op' => 'staging_save', 'role' => 'staging', 'partner_url' => 'https://shop.example.com', 'source' => 'form' ),
				array( 'op' => 'export_again', 'package_id' => $package['package_id'], 'expect' => 'error', 'as' => 'again' ),
			)
		);
		self::assertNoRoute( $right, 'GET', 'the address of a package of the pair before' );
		self::assertSame( 'ab_mcp_sync_export_stale', $steps['again']['code'] );
		self::assertSame( 'ab_mcp_sync_export_unknown', self::b( array( array( 'op' => 'export_again', 'package_id' => $package['package_id'], 'expect' => 'error', 'as' => 'again' ) ) )['again']['code'], 'Discarded where it was met.' );

		// The role of B confirmed anew (production, then staging again): a new key.
		self::b( array( array( 'op' => 'staging_save', 'role' => 'staging', 'partner_url' => AB_MCP_IT_Sites::url( 'a' ), 'source' => 'form' ) ) );
		$second = self::b( array( array( 'op' => 'export', 'purpose' => 'inventory', 'as' => 'p' ) ) )['p'];
		$steps  = self::b(
			array(
				array( 'op' => 'sync_key', 'as' => 'before' ),
				array( 'op' => 'staging_save', 'role' => 'production', 'partner_url' => AB_MCP_IT_Sites::url( 'a' ), 'source' => 'form' ),
				array( 'op' => 'staging_save', 'role' => 'staging', 'partner_url' => AB_MCP_IT_Sites::url( 'a' ), 'source' => 'form' ),
				array( 'op' => 'sync_key', 'as' => 'after' ),
				array( 'op' => 'export_again', 'package_id' => $second['package_id'], 'expect' => 'error', 'as' => 'again' ),
			)
		);
		self::assertNotSame( $steps['before']['key_id'], $steps['after']['key_id'], 'A new key.' );
		self::assertSame( 'ab_mcp_sync_export_stale', $steps['again']['code'], 'No fresh fetch addresses for a package of the key before.' );
	}

	public function testABindingThatChangedWithoutANewKeyIsNoBindingEither(): void {
		// A as the staging site of B, in this process.
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' ) );
		$package = AB_MCP_Sync_Export::make( 'inventory' );
		self::assertIsArray( $package, is_wp_error( $package ) ? $package->get_error_message() : '' );
		$request = new \WP_REST_Request( 'GET', '/alphabridge/v1/staging-package' );
		$request->set_query_params( self::query( $package['package_id'], $package['fetch'][0] ) );
		self::assertIsString( AB_MCP_Sync_Export::requested( $request, time() ) );

		// The pair confirmed again, the key as it was.
		$raw                          = \AB_MCP_Settings::get( AB_MCP_Staging::KEY, array() );
		$raw['pair']['at']            = (int) $raw['pair']['at'] + 1;
		\AB_MCP_Settings::set( AB_MCP_Staging::KEY, $raw );
		self::assertNull( AB_MCP_Sync_Export::requested( $request, time() ), 'Given out no more.' );
		self::refused( AB_MCP_Sync_Export::again( $package['package_id'] ), 'ab_mcp_sync_export_unknown' );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'out' ), 'And discarded.' );

		// A package of a binding before is discarded before a new one is made.
		$first = AB_MCP_Sync_Export::make( 'inventory' );
		$raw['pair']['at'] = (int) $raw['pair']['at'] + 1;
		\AB_MCP_Settings::set( AB_MCP_Staging::KEY, $raw );
		$next = AB_MCP_Sync_Export::make( 'inventory' );
		self::assertSame( array( $next['package_id'] ), array_column( AB_MCP_Protected_Store::groups( 'out' ), 'gid' ), 'Only the new package: ' . $first['package_id'] . ' is gone.' );
	}

	public function testAPairThatGoesAndComesBackGivesNoPackageOfBeforeOut(): void {
		self::pair();
		$package = self::export();
		$right   = self::query( $package['package_id'], $package['fetch'][0] );
		self::assertSame( 200, self::route( $right )['status'] );
		// B paired with another production site and back with A, within the
		// same second as a person on the page could: the record as it was, to
		// the second, but the random value its writes gave it.
		$steps = self::b(
			array(
				array( 'op' => 'staging_raw', 'as' => 'before' ),
				array( 'op' => 'staging_save', 'role' => 'staging', 'partner_url' => 'https://shop.example.com', 'source' => 'form' ),
				array( 'op' => 'staging_save', 'role' => 'staging', 'partner_url' => AB_MCP_IT_Sites::url( 'a' ), 'source' => 'form' ),
				array( 'op' => 'staging_raw', 'as' => 'after' ),
			)
		);
		self::assertNotSame( $steps['before']['bind'], $steps['after']['bind'] );
		$same  = array( 'bind' => $steps['after']['bind'] ) + $steps['before'];
		$steps = self::b(
			array(
				array( 'op' => 'staging_raw_set', 'value' => $same, 'as' => 'set' ),
				array(
					'op'         => 'export_again',
					'as'         => 'again',
					'package_id' => $package['package_id'],
					'expect'     => 'error',
				),
			)
		);
		self::assertSame( $same, $steps['set'] );
		self::assertSame( 'ab_mcp_sync_export_stale', $steps['again']['code'], $steps['again']['message'] );
		self::assertSame( 404, self::route( $right )['status'], 'Not given out either.' );
		$next = self::export();
		self::assertNotSame( $package['package_id'], $next['package_id'] );
		self::assertSame( 200, self::route( self::query( $next['package_id'], $next['fetch'][0] ) )['status'], 'A new package is.' );
	}

	/* -------------------------------------------- the account (A-3) */

	public function testAnImportBelongsToTheAccountThatBeganIt(): void {
		self::pair();
		$package = self::export();
		$data    = self::b( array( array( 'op' => 'export_chunk', 'package_id' => $package['package_id'], 'chunk' => 0, 'as' => 'c' ) ) )['c']['data'];
		$args    = array(
			'package_id' => $package['package_id'],
			'manifest'   => $package['manifest'],
			'purpose'    => 'inventory',
		);
		self::assertIsArray( AB_MCP_Sync_Import::call( $args ) );
		$other = wp_insert_user(
			array(
				'user_login' => 'second-admin',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'second-admin@example.invalid',
				'role'       => 'administrator',
			)
		);
		self::assertIsInt( $other );
		wp_set_current_user( $other );
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'] ) ), 'ab_mcp_sync_import_unknown' );
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'], 'chunk' => 0, 'data' => $data ) ), 'ab_mcp_sync_import_unknown' );
		$own = AB_MCP_Sync_Import::call( $args );
		self::assertSame( range( 0, $package['chunks'] - 1 ), $own['missing'], 'Its own group, from the start.' );
		$groups = AB_MCP_Protected_Store::groups( 'in' );
		$owners = array_map( 'intval', array_column( $groups, 'user_id' ) );
		sort( $owners );
		self::assertSame( array( self::admin(), $other ), $owners, 'Two groups, one of each account.' );

		wp_set_current_user( self::admin() );
		$done = AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'], 'chunk' => 0, 'data' => $data ) );
		self::assertTrue( $done['complete'] );
		wp_set_current_user( $other );
		self::assertFalse( AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'] ) )['complete'], 'The other account\'s package stays its own.' );
	}

	/* ------------------------------------------- the time of a fetch (B-2) */

	public function testAFetchTakesNoLongerThanWhatIsLeftOfTheBudget(): void {
		self::pair();
		$package = self::export();
		$args    = array(
			'package_id' => $package['package_id'],
			'manifest'   => $package['manifest'],
			'purpose'    => 'inventory',
			'fetch'      => $package['fetch'],
		);
		foreach ( array( 'twelve seconds left' => array( 1048.0, 12.0 ), 'thirty and more left' => array( 1000.0, 30.0 ), 'four seconds left' => array( 1056.0, null ) ) as $case => list( $then, $timeout ) ) {
			$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
			$times   = array( 1000.0, $then );
			AB_MCP_Sync_Import::$clock = static function () use ( &$times ) {
				return count( $times ) > 1 ? array_shift( $times ) : $times[0];
			};
			try {
				$state = AB_MCP_Sync_Import::call( $args, $fetcher );
			} finally {
				AB_MCP_Sync_Import::$clock = null;
			}
			self::assertIsArray( $state, $case );
			if ( null === $timeout ) {
				self::assertSame( array(), $fetcher->calls, $case . ': less than ' . AB_MCP_Sync_Import::MIN_FETCH . ' seconds, no fetch.' );
				continue;
			}
			self::assertEquals( $timeout, $fetcher->calls[0]['args']['timeout'], $case );
			self::assertTrue( $state['complete'], $case );
			AB_MCP_Protected_Store::remove( 'in', AB_MCP_Protected_Store::groups( 'in' )[0]['gid'] );
		}
		self::assertSame( 5, AB_MCP_Sync_Import::MIN_FETCH );
	}

	/* --------------------------------------- a write that fails (B-4) */

	/**
	 * Run $fn while every read of the record of the store's proof from the
	 * $from-th on fails, as a store that cannot be used any more; the reads
	 * counted.
	 */
	private static function store_failing_from( int $from, callable $fn, int &$reads ) {
		global $wpdb;
		$reads = 0;
		$table = $wpdb->options;
		$break = static function ( $query ) use ( &$reads, $from, $table ) {
			if ( 0 === strpos( ltrim( (string) $query ), 'SELECT option_value FROM ' . $table . " WHERE option_name = 'ab_mcp_store_proof'" ) ) {
				++$reads;
				if ( $reads >= $from ) {
					return 'SELECT option_value FROM ab_it_no_such_table WHERE 1 = 0';
				}
			}
			return $query;
		};
		$quiet = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			return $fn();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $quiet );
			AB_MCP_Protected_Store::forget();
		}
	}

	public function testAPackageThatCannotBeWrittenWholeLeavesNothingHalfMade(): void {
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' ) );
		// The store proved once, so every run below reads it alike.
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'out' ) );
		AB_MCP_Protected_Store::forget();
		$reads = 0;
		$made  = self::store_failing_from( PHP_INT_MAX, static fn() => AB_MCP_Sync_Export::make( 'inventory' ), $reads );
		self::assertIsArray( $made );
		$all = $reads;
		self::assertGreaterThan( 2, $all, 'The store is asked before each write.' );
		AB_MCP_Protected_Store::remove( 'out', $made['package_id'] );
		// The store fails at every point of the making in turn: a package is
		// there whole, or not at all.
		$failed = 0;
		for ( $from = 1; $from <= $all; $from++ ) {
			$result = self::store_failing_from( $from, static fn() => AB_MCP_Sync_Export::make( 'inventory' ), $reads );
			$failed = $failed + ( is_wp_error( $result ) ? 1 : 0 );
			foreach ( AB_MCP_Protected_Store::groups( 'out' ) as $group ) {
				self::assertIsArray( AB_MCP_Sync_Export::again( $group['gid'] ), 'Read ' . $from . ': a package that is there is whole, nothing half made.' );
				AB_MCP_Protected_Store::remove( 'out', $group['gid'] );
			}
		}
		self::assertGreaterThan( 1, $failed, 'The making failed at several points.' );
	}

	public function testAnImportKeepsWhatArrivedAndEndsWhatAnInterruptionLeft(): void {
		self::pair();
		$signed = self::b(
			array(
				array(
					'op'      => 'sign_manifest',
					'package' => array(
						'package_id'  => str_repeat( 'ab', 16 ),
						'purpose'     => 'inventory',
						'report_hash' => null,
						'kinds'       => array(),
						'objects'     => 0,
						'unpacked'    => 0,
						'chunks'      => array(
							array( 'bytes' => 3, 'sha256' => hash( 'sha256', 'abc' ) ),
							array( 'bytes' => 3, 'sha256' => hash( 'sha256', 'def' ) ),
						),
						'bytes'       => 6,
						'sha256'      => hash( 'sha256', 'abcdef' ),
					),
					'as'      => 'signed',
				),
			)
		)['signed'];
		$id    = str_repeat( 'ab', 16 );
		$begin = array( 'package_id' => $id, 'manifest' => $signed, 'purpose' => 'inventory' );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'in' ) );
		AB_MCP_Protected_Store::forget();
		$reads = 0;
		self::store_failing_from( PHP_INT_MAX, static fn() => AB_MCP_Sync_Import::call( $begin ), $reads );
		AB_MCP_Protected_Store::remove( 'in', AB_MCP_Protected_Store::groups( 'in' )[0]['gid'] );
		$all = $reads;
		// The store fails at every point of the beginning in turn: what is
		// there has its record and goes on, nothing is half made.
		$errors = 0;
		for ( $from = 1; $from <= $all; $from++ ) {
			$failed = self::store_failing_from( $from, static fn() => AB_MCP_Sync_Import::call( $begin ), $reads );
			$errors = $errors + ( is_wp_error( $failed ) ? 1 : 0 );
			foreach ( AB_MCP_Protected_Store::groups( 'in' ) as $group ) {
				self::assertIsArray( AB_MCP_Sync_Import::call( array( 'package_id' => $id ) ), 'Read ' . $from . ': a beginning that is there goes on.' );
				AB_MCP_Protected_Store::remove( 'in', $group['gid'] );
			}
		}
		self::assertGreaterThan( 1, $errors, 'The beginning failed at several points.' );
		self::assertIsArray( AB_MCP_Sync_Import::call( $begin ) );
		self::assertIsArray( AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 0, 'data' => base64_encode( 'abc' ) ) ) );
		$refused = self::store_failing_from( 1, static fn() => AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 1, 'data' => base64_encode( 'def' ) ) ), $reads );
		self::assertInstanceOf( WP_Error::class, $refused );
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id ) );
		self::assertSame( array( 1 ), $state['missing'], 'What arrived stays: only the chunk that failed is missing.' );

		// Interrupted after the last chunk was kept, before it was checked whole.
		$gid = AB_MCP_Protected_Store::groups( 'in' )[0]['gid'];
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Export::chunk_name( 1 ), 'def' ) );
		self::assertFalse( AB_MCP_Protected_Store::groups( 'in' )[0]['closed'] );
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id ) );
		self::assertTrue( $state['complete'], 'The next call checks it whole and ends it.' );
		self::assertTrue( AB_MCP_Protected_Store::groups( 'in' )[0]['closed'], 'Closed: it counts as an open package no more.' );
	}

	/* ---------------------------------- the print of the pair (point 3) */

	/** B the staging site of A, A the production site of B with B's key prepared, not confirmed. */
	private static function pair_unconfirmed(): array {
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
		self::assertIsArray( AB_MCP_Staging::save( 'production', AB_MCP_IT_Sites::url( 'b' ), 'staging', self::admin(), 'form' ) );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $b['code'], self::admin() );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		return $prepared;
	}

	public function testTheFirstImportWaitsForThePersonAndThenRunsAsItWas(): void {
		$prepared = self::pair_unconfirmed();
		$package  = self::export();
		$fetcher  = AB_MCP_Test_Sync_Fetcher::for_sites();
		$call     = array(
			'package_id' => $package['package_id'],
			'manifest'   => $package['manifest'],
			'purpose'    => 'inventory',
			'fetch'      => $package['fetch'],
		);
		self::refused( AB_MCP_Sync_Import::call( $call, $fetcher ), 'ab_mcp_sync_manifest_unconfirmed' );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'in' ), 'Nothing begun.' );
		self::assertSame( array(), $fetcher->calls, 'Nothing fetched.' );
		// The person confirms; the same call runs.
		self::assertIsArray( AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], self::admin() ) );
		$state = AB_MCP_Sync_Import::call( $call, $fetcher );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertTrue( $state['complete'] );
		// A second click on «Confirm» is no new confirmation: the import holds.
		$print = AB_MCP_Sync_Anchor::pair_print();
		self::assertIsArray( AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], self::admin() ) );
		self::assertSame( $print, AB_MCP_Sync_Anchor::pair_print() );
		$again = AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'] ) );
		self::assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_message() : '' );
		self::assertTrue( $again['complete'] );
	}

	public function testAnImportEndsWhereThePairChangedSinceItBegan(): void {
		self::pair();
		$package = self::export();
		$begin   = array(
			'package_id' => $package['package_id'],
			'manifest'   => $package['manifest'],
			'purpose'    => 'inventory',
		);
		$state   = AB_MCP_Sync_Import::call( $begin );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertFalse( $state['complete'] );
		self::assertIsArray( AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'] ) ), 'Goes on while the pair holds.' );

		// The role of A confirmed again (past save(), as a person would anew).
		$raw               = \AB_MCP_Settings::get( AB_MCP_Staging::KEY, array() );
		$raw['role']['at'] = (int) $raw['role']['at'] + 1;
		\AB_MCP_Settings::set( AB_MCP_Staging::KEY, $raw );
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => $package['package_id'] ) ), 'ab_mcp_sync_import_pair_changed' );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'in' ), 'What arrived of it was removed.' );
		// The way: the package anew; with the same anchor its manifest is still read.
		$fetcher = AB_MCP_Test_Sync_Fetcher::for_sites();
		$state   = AB_MCP_Sync_Import::call( $begin + array( 'fetch' => $package['fetch'] ), $fetcher );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertTrue( $state['complete'] );

		// Also with the manifest passed again, and where the anchor went.
		self::assertIsArray( AB_MCP_Sync_Import::call( $begin ) );
		$raw['role']['at'] = (int) $raw['role']['at'] + 1;
		\AB_MCP_Settings::set( AB_MCP_Staging::KEY, $raw );
		self::refused( AB_MCP_Sync_Import::call( $begin ), 'ab_mcp_sync_import_pair_changed' );
	}

	/* --------------------------------- the purpose of a continuation (B-3) */

	public function testAGroupHeldForAnotherPurposeIsEndedBeforeItIsReadOn(): void {
		self::pair();
		$id     = str_repeat( 'cd', 16 );
		$signed = self::b(
			array(
				array(
					'op'      => 'sign_manifest',
					'as'      => 'signed',
					'package' => array(
						'package_id'  => $id,
						'purpose'     => 'objects',
						'report_hash' => str_repeat( 'c', 64 ),
						'kinds'       => array(),
						'objects'     => 0,
						'unpacked'    => 0,
						'chunks'      => array( array( 'bytes' => 3, 'sha256' => hash( 'sha256', 'abc' ) ) ),
						'bytes'       => 3,
						'sha256'      => hash( 'sha256', 'abc' ),
					),
				),
			)
		)['signed'];
		// A group as another version would have begun it: for objects, with
		// the print of the pair as it is now.
		$gid = AB_MCP_Protected_Store::open(
			'in',
			array(
				'declared' => 3 + AB_MCP_Sync_Import::RECORD_MAX,
				'label'    => AB_MCP_Sync_Import::LABEL . $id,
			)
		);
		self::assertIsString( $gid );
		$record = \AB_MCP_Sync_Canon::encode(
			array(
				'expect'    => array( 'package_id' => $id, 'purpose' => 'objects' ),
				'format'    => AB_MCP_Sync_Import::RECORD,
				'manifest'  => $signed['manifest'],
				'print'     => AB_MCP_Sync_Anchor::pair_print(),
				'signature' => $signed['signature'],
			)
		);
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, hash( 'sha256', $record ), $record ) );
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 0, 'data' => base64_encode( 'abc' ) ) ), 'ab_mcp_sync_import_purpose_held' );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'in' ), 'Ended: nothing of it stays.' );
	}

	public function testAGroupWithoutThePrintOfItsBeginningIsEnded(): void {
		self::pair();
		$package = self::export();
		$id      = $package['package_id'];
		$gid     = AB_MCP_Protected_Store::open(
			'in',
			array(
				'declared' => 1048576 + AB_MCP_Sync_Import::RECORD_MAX,
				'label'    => AB_MCP_Sync_Import::LABEL . $id,
			)
		);
		self::assertIsString( $gid );
		$record = \AB_MCP_Sync_Canon::encode(
			array(
				'expect'    => array( 'package_id' => $id, 'purpose' => 'inventory' ),
				'format'    => AB_MCP_Sync_Import::RECORD,
				'manifest'  => $package['manifest']['manifest'],
				'signature' => $package['manifest']['signature'],
			)
		);
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, hash( 'sha256', $record ), $record ) );
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => $id ) ), 'ab_mcp_sync_import_pair_changed' );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'in' ) );
	}

	/* ------------------------------------- writes that really fail (B-4) */

	/**
	 * Run $fn while one write of the protected store fails for real: when
	 * the store asks for its proof for the $at-th time, the folder of the
	 * area and of each of its groups are made read-only; the next write
	 * fails on the file system, and once it did (its error, which the store
	 * silences, reaches the handler) they are writable again, so the
	 * clean-up after it works. $reads counts the proofs asked.
	 */
	private static function write_failing_at( string $area, int $at, callable $fn, int &$reads ) {
		global $wpdb;
		$reads = 0;
		$table = $wpdb->options;
		$root  = AB_MCP_Protected_Store::root();
		self::assertIsString( $root );
		$locked = array();
		$unlock = static function () use ( &$locked ): void {
			foreach ( $locked as $dir => $mode ) {
				chmod( $dir, $mode );
			}
			$locked = array();
			clearstatcache();
		};
		$hook = static function ( $query ) use ( &$reads, &$locked, $at, $table, $root, $area ) {
			if ( 0 === strpos( ltrim( (string) $query ), 'SELECT option_value FROM ' . $table . " WHERE option_name = 'ab_mcp_store_proof'" ) ) {
				++$reads;
				if ( $reads === $at && is_dir( $root . '/' . $area ) ) {
					foreach ( array_merge( array( $root . '/' . $area ), (array) glob( $root . '/' . $area . '/*', GLOB_ONLYDIR ) ) as $dir ) {
						$locked[ $dir ] = fileperms( $dir ) & 0777;
						chmod( $dir, 0500 );
					}
					clearstatcache();
				}
			}
			return $query;
		};
		$previous = null;
		$previous = set_error_handler(
			static function ( int $no, string $text, string $file = '', int $line = 0 ) use ( &$locked, &$previous, $unlock ): bool {
				if ( array() !== $locked && false !== strpos( $text, 'Permission denied' ) ) {
					$unlock();
					return true;
				}
				return is_callable( $previous ) ? (bool) $previous( $no, $text, $file, $line ) : false;
			}
		);
		add_filter( 'query', $hook );
		try {
			return $fn();
		} finally {
			remove_filter( 'query', $hook );
			restore_error_handler();
			$unlock();
			AB_MCP_Protected_Store::forget();
		}
	}

	/** Skip where the file system lets this process write a read-only folder (root). */
	private static function needs_real_permissions(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Runs as root, which writes a read-only folder: no write fails for real here.' );
		}
	}

	public function testAPackageWhoseWriteFailsIsNeitherLeftNorGivenOutAndIsMadeAgain(): void {
		self::needs_real_permissions();
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' ) );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'out' ) );
		AB_MCP_Protected_Store::forget();
		$reads = 0;
		$clean = self::write_failing_at( 'out', PHP_INT_MAX, static fn() => AB_MCP_Sync_Export::make( 'inventory' ), $reads );
		self::assertIsArray( $clean );
		$all     = $reads;
		$objects = $clean['objects'];
		AB_MCP_Protected_Store::remove( 'out', $clean['package_id'] );
		$failed = 0;
		for ( $at = 1; $at <= $all; $at++ ) {
			$result = self::write_failing_at( 'out', $at, static fn() => AB_MCP_Sync_Export::make( 'inventory' ), $reads );
			$groups = AB_MCP_Protected_Store::groups( 'out' );
			if ( is_wp_error( $result ) ) {
				++$failed;
				self::assertSame( 'ab_mcp_store_write', $result->get_error_code(), 'Write ' . $at . ': ' . $result->get_error_message() );
				self::assertSame( array(), $groups, 'Write ' . $at . ': nothing of a making that failed is left.' );
			} else {
				self::assertSame( array( $result['package_id'] ), array_column( $groups, 'gid' ) );
				self::assertIsArray( AB_MCP_Sync_Export::again( $result['package_id'] ), 'Write ' . $at . ': a package that is there is whole.' );
				AB_MCP_Protected_Store::remove( 'out', $result['package_id'] );
			}
			// Made again at once, with the same count.
			$again = AB_MCP_Sync_Export::make( 'inventory' );
			self::assertIsArray( $again );
			self::assertSame( $objects, $again['objects'], 'Write ' . $at . ': the same objects.' );
			AB_MCP_Protected_Store::remove( 'out', $again['package_id'] );
		}
		self::assertGreaterThan( 1, $failed, 'A write failed for real at several points.' );
	}

	public function testAMakingStoppedBetweenItsChunksAndItsRecordIsNeverGivenOutAndGoesLater(): void {
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' ) );
		$clean = AB_MCP_Sync_Export::make( 'inventory' );
		self::assertIsArray( $clean );
		AB_MCP_Protected_Store::remove( 'out', $clean['package_id'] );
		AB_MCP_Protected_Store::forget();
		global $wpdb;
		$root  = AB_MCP_Protected_Store::root();
		$table = $wpdb->options;
		// The process stops when the store is asked for the write after the
		// chunks: a group with its chunks, no record.
		$stop = static function ( $query ) use ( $root, $table ) {
			if ( 0 === strpos( ltrim( (string) $query ), 'SELECT option_value FROM ' . $table . " WHERE option_name = 'ab_mcp_store_proof'" ) ) {
				foreach ( (array) glob( $root . '/out/*', GLOB_ONLYDIR ) as $dir ) {
					$names = array_diff( (array) scandir( $dir ), array( '.', '..' ) );
					if ( array() !== preg_grep( '/^chunk-/', $names ) && array() === preg_grep( '/^[0-9a-f]{64}$/', $names ) ) {
						throw new RuntimeException( 'stopped' );
					}
				}
			}
			return $query;
		};
		add_filter( 'query', $stop );
		$stopped = false;
		try {
			AB_MCP_Sync_Export::make( 'inventory' );
		} catch ( RuntimeException $e ) {
			$stopped = 'stopped' === $e->getMessage();
		} finally {
			remove_filter( 'query', $stop );
			AB_MCP_Protected_Store::forget();
		}
		self::assertTrue( $stopped, 'Stopped between the chunks and the record.' );
		$groups = AB_MCP_Protected_Store::groups( 'out' );
		self::assertCount( 1, $groups );
		$gid = $groups[0]['gid'];
		self::assertNotSame( array(), AB_MCP_Protected_Store::files( 'out', $gid ), 'Its chunks are there.' );
		self::refused( AB_MCP_Sync_Export::again( $gid ), 'ab_mcp_sync_export_unknown' );
		self::assertNull( AB_MCP_Sync_Export::deliver( array( 'package' => $gid, 'chunk' => 0 ), time() ), 'No chunk of it is given out.' );
		// Made again: as a clean making.
		$again = AB_MCP_Sync_Export::make( 'inventory' );
		self::assertIsArray( $again );
		self::assertSame( $clean['objects'], $again['objects'] );
		self::assertSame( $clean['chunks'], $again['chunks'] );
		self::assertContains( $gid, array_column( AB_MCP_Protected_Store::groups( 'out' ), 'gid' ), 'Within ABANDONED seconds it may still be a making under way.' );
		// ABANDONED seconds after it began, the next making removes it.
		foreach ( (array) glob( $root . '/out/*.json' ) as $side ) {
			$entry = json_decode( (string) file_get_contents( $side ), true );
			if ( is_array( $entry ) && $gid === ( $entry['gid'] ?? null ) ) {
				$entry['created'] = time() - AB_MCP_Sync_Export::ABANDONED - 1;
				file_put_contents( $side, (string) wp_json_encode( $entry ) );
			}
		}
		AB_MCP_Protected_Store::forget();
		$last = AB_MCP_Sync_Export::make( 'inventory' );
		self::assertIsArray( $last );
		self::assertNotContains( $gid, array_column( AB_MCP_Protected_Store::groups( 'out' ), 'gid' ), 'Gone: it holds no place in the budgets any more.' );
		self::assertSame( 600, AB_MCP_Sync_Export::ABANDONED );
	}

	public function testAnImportWhoseWriteFailsKeepsWhatArrivedAndGoesOn(): void {
		self::needs_real_permissions();
		self::pair();
		$id     = str_repeat( 'ef', 16 );
		$signed = self::b(
			array(
				array(
					'op'      => 'sign_manifest',
					'as'      => 'signed',
					'package' => array(
						'package_id'  => $id,
						'purpose'     => 'inventory',
						'report_hash' => null,
						'kinds'       => array(),
						'objects'     => 0,
						'unpacked'    => 0,
						'chunks'      => array(
							array( 'bytes' => 3, 'sha256' => hash( 'sha256', 'abc' ) ),
							array( 'bytes' => 3, 'sha256' => hash( 'sha256', 'def' ) ),
						),
						'bytes'       => 6,
						'sha256'      => hash( 'sha256', 'abcdef' ),
					),
				),
			)
		)['signed'];
		$begin = array( 'package_id' => $id, 'manifest' => $signed, 'purpose' => 'inventory' );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'in' ) );
		AB_MCP_Protected_Store::forget();
		$reads = 0;
		self::assertIsArray( self::write_failing_at( 'in', PHP_INT_MAX, static fn() => AB_MCP_Sync_Import::call( $begin ), $reads ) );
		AB_MCP_Protected_Store::remove( 'in', AB_MCP_Protected_Store::groups( 'in' )[0]['gid'] );
		$all = $reads;
		// The beginning fails for real at each write in turn: nothing of it
		// is left, or it has its record and goes on.
		$errors = 0;
		for ( $at = 1; $at <= $all; $at++ ) {
			$result = self::write_failing_at( 'in', $at, static fn() => AB_MCP_Sync_Import::call( $begin ), $reads );
			$groups = AB_MCP_Protected_Store::groups( 'in' );
			if ( is_wp_error( $result ) ) {
				++$errors;
				self::assertSame( array(), $groups, 'Write ' . $at . ': nothing of a beginning that failed is left.' );
			} else {
				self::assertCount( 1, $groups );
				self::assertSame( array( 0, 1 ), AB_MCP_Sync_Import::call( array( 'package_id' => $id ) )['missing'], 'Write ' . $at . ': it goes on.' );
				AB_MCP_Protected_Store::remove( 'in', $groups[0]['gid'] );
			}
		}
		self::assertGreaterThan( 0, $errors, 'The beginning failed for real.' );

		// The first chunk arrives; the store fails for real at the second.
		self::assertIsArray( AB_MCP_Sync_Import::call( $begin ) );
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 0, 'data' => base64_encode( 'abc' ) ) );
		self::assertSame( array( 1, array( 1 ) ), array( $state['received'], $state['missing'] ) );
		$refused = self::write_failing_at( 'in', 1, static fn() => AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 1, 'data' => base64_encode( 'def' ) ) ), $reads );
		self::refused( $refused, 'ab_mcp_store_write' );
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id ) );
		self::assertSame( array( 2, 1, array( 1 ), false ), array( $state['chunks'], $state['received'], $state['missing'], $state['complete'] ), 'What arrived stays; only the chunk that failed is missing.' );
		// The same chunk again: done, checked whole.
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 1, 'data' => base64_encode( 'def' ) ) );
		self::assertSame( array( 2, 2, array(), true ), array( $state['chunks'], $state['received'], $state['missing'], $state['complete'] ) );
	}
}
