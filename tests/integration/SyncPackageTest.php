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

	/** A line of the inventory for an option, as AB_MCP_Sync_Export::line() writes one. */
	private static function option_line( string $name, array $over = array() ): array {
		return array_merge(
			array(
				'identity' => array( 'name' => $name ),
				'kind'     => 'option',
				'locks'    => array(),
				'ref'      => $name,
				'status'   => 'z8',
			),
			$over
		);
	}

	/**
	 * A package of the given lines, a list per chunk (a line as an array is
	 * encoded as the staging site encodes it, a string is taken as it is; a
	 * chunk given as a string is its text as it is):
	 * what its manifest names and the chunks. $over changes the manifest.
	 */
	private static function package_of( string $id, array $chunks, array $over = array(), bool $gzip = true ): array {
		$data     = array();
		$list     = array();
		$unpacked = 0;
		$objects  = 0;
		$all      = '';
		foreach ( $chunks as $lines ) {
			$text = is_string( $lines ) ? $lines : '';
			foreach ( is_string( $lines ) ? array() : $lines as $line ) {
				$text .= ( is_string( $line ) ? $line : \AB_MCP_Sync_Canon::encode( $line ) ) . "\n";
				++$objects;
			}
			$unpacked += strlen( $text );
			$chunk     = $gzip ? (string) gzencode( $text, 6 ) : $text;
			$data[]    = $chunk;
			$list[]    = array(
				'bytes'  => strlen( $chunk ),
				'sha256' => hash( 'sha256', $chunk ),
			);
			$all      .= $chunk;
		}
		return array(
			'package' => array_merge(
				array(
					'package_id'  => $id,
					'purpose'     => 'inventory',
					'report_hash' => null,
					'kinds'       => array(),
					'objects'     => $objects,
					'unpacked'    => $unpacked,
					'chunks'      => $list,
					'bytes'       => strlen( $all ),
					'sha256'      => hash( 'sha256', $all ),
				),
				$over
			),
			'data'    => $data,
		);
	}

	/** A package of chunks given as they are (bytes), with the objects and unpacked size named. */
	private static function package_raw( string $id, array $chunks, int $objects, int $unpacked ): array {
		$list = array();
		foreach ( $chunks as $chunk ) {
			$list[] = array(
				'bytes'  => strlen( $chunk ),
				'sha256' => hash( 'sha256', $chunk ),
			);
		}
		return array(
			'package' => array(
				'package_id'  => $id,
				'purpose'     => 'inventory',
				'report_hash' => null,
				'kinds'       => array(),
				'objects'     => $objects,
				'unpacked'    => $unpacked,
				'chunks'      => $list,
				'bytes'       => strlen( implode( '', $chunks ) ),
				'sha256'      => hash( 'sha256', implode( '', $chunks ) ),
			),
			'data'    => $chunks,
		);
	}

	/** B signs a package. */
	private static function signed_by_b( array $package ): array {
		return self::b(
			array(
				array(
					'op'      => 'sign_manifest',
					'as'      => 'signed',
					'package' => $package,
				),
			)
		)['signed'];
	}

	/** A begins a package B signed and takes its chunks over the relay: the last answer. */
	private static function import_relayed( array $package ) {
		$id    = $package['package']['package_id'];
		$state = AB_MCP_Sync_Import::call(
			array(
				'package_id' => $id,
				'manifest'   => self::signed_by_b( $package['package'] ),
				'purpose'    => 'inventory',
			)
		);
		foreach ( $package['data'] as $i => $data ) {
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$state = AB_MCP_Sync_Import::call(
				array(
					'package_id' => $id,
					'chunk'      => $i,
					'data'       => base64_encode( $data ),
				)
			);
		}
		return $state;
	}

	/** The groups of A's area in for a package. */
	private static function groups_of( string $id ): array {
		return array_values( array_filter( AB_MCP_Protected_Store::groups( 'in' ), static fn( $group ) => 'package-' . $id === $group['label'] ) );
	}

	/**
	 * The list of held objects of a package A took over: kind, SHA-256 of
	 * the ref and code of each. $text is set to the list as it is kept.
	 */
	private static function held_of( string $id, &$text = null ): array {
		$gid   = self::groups_of( $id )[0]['gid'];
		$names = array_values( array_filter( array_map( 'strval', array_keys( AB_MCP_Protected_Store::files( 'in', $gid ) ) ), static fn( $name ) => null !== AB_MCP_Sync_Import::held_sha( $name ) ) );
		self::assertCount( 1, $names );
		$text = (string) AB_MCP_Protected_Store::get( 'in', $gid, $names[0], AB_MCP_Sync_Import::held_sha( $names[0] ) );
		$list = json_decode( $text, true );
		self::assertSame( AB_MCP_Sync_Import::HELD_FORMAT, $list['format'] );
		return $list['objects'];
	}

	/** An entry of the list of held objects: the kind, the SHA-256 of the ref as the canon spells it, the code. */
	private static function held( string $kind, $ref, string $code ): array {
		return array( $kind, hash( 'sha256', \AB_MCP_Sync_Canon::encode( $ref ) ), $code );
	}

	/** The lines of a package A made in this process, read from its store. */
	private static function lines_out( array $package ): array {
		$manifest = json_decode( $package['manifest']['manifest'], true );
		$chunks   = array();
		foreach ( $manifest['chunks'] as $i => $chunk ) {
			$chunks[] = AB_MCP_Protected_Store::get( 'out', $package['package_id'], AB_MCP_Sync_Export::chunk_name( $i ), $chunk['sha256'] );
		}
		return self::lines( $chunks );
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
		$id     = str_repeat( 'ab', 16 );
		$two    = self::package_of( $id, array( array( self::option_line( 'zz_a' ) ), array( self::option_line( 'zz_b' ) ) ) );
		$signed = self::signed_by_b( $two['package'] );
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
		self::assertIsArray( AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 0, 'data' => base64_encode( $two['data'][0] ) ) ) );
		$refused = self::store_failing_from( 1, static fn() => AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 1, 'data' => base64_encode( $two['data'][1] ) ) ), $reads );
		self::assertInstanceOf( WP_Error::class, $refused );
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id ) );
		self::assertSame( array( 1 ), $state['missing'], 'What arrived stays: only the chunk that failed is missing.' );

		// Interrupted after the last chunk was kept, before it was checked whole.
		$gid = AB_MCP_Protected_Store::groups( 'in' )[0]['gid'];
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Export::chunk_name( 1 ), $two['data'][1] ) );
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

	public function testAPairEnteredAgainGoesOnWithARenewedKeyAndNotWithTheOldOne(): void {
		self::pair();
		$old  = self::export();
		$code = self::b( array( array( 'op' => 'pairing_code', 'as' => 'code' ) ) )['code'];
		// A: the pair removed, the same staging site entered again, the code of before.
		self::assertIsArray( AB_MCP_Staging::save( 'production', '', null, self::admin(), 'form' ) );
		self::assertIsArray( AB_MCP_Staging::save( 'production', AB_MCP_IT_Sites::url( 'b' ), 'staging', self::admin(), 'form' ) );
		$refused = AB_MCP_Sync_Anchor::prepare_from_code( $code, self::admin() );
		self::refused( $refused, 'ab_mcp_sync_anchor_retired' );
		self::assertStringContainsString( '«Renew the key»', $refused->get_error_message() );
		// The page shows it (the notice of the form, with its steps).
		require_once AB_MCP_DIR . 'includes/class-admin.php';
		$_GET = array( 'ab_notice' => 'staging_trust_sync_anchor_retired' );
		ob_start();
		( new \ReflectionMethod( \AB_MCP_Admin::class, 'notice' ) )->invoke( new \AB_MCP_Admin() );
		$shown = (string) ob_get_clean();
		$_GET  = array();
		self::assertStringContainsString( 'notice-error', $shown );
		self::assertStringContainsString( esc_html( '1. On the staging site, open AlphaBridge → Staging and click «Renew the key»' ), $shown );

		// B: «Renew the key»; the role stays.
		$b = self::b(
			array(
				array( 'op' => 'staging_raw', 'as' => 'before' ),
				array( 'op' => 'key_replace', 'as' => 'key' ),
				array( 'op' => 'pairing_code', 'as' => 'code' ),
				array( 'op' => 'staging_raw', 'as' => 'after' ),
			)
		);
		self::assertSame( $b['before'], $b['after'], 'The role and the pair as they were.' );
		self::assertNotSame( $code, $b['code'] );
		// A: the new code, confirmed.
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $b['code'], self::admin() );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertSame( $b['key']['key_id'], $prepared['key_id'] );
		self::assertIsArray( AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], self::admin() ) );

		// Export and import work again.
		$package = self::export();
		$state   = AB_MCP_Sync_Import::call(
			array(
				'package_id' => $package['package_id'],
				'manifest'   => $package['manifest'],
				'purpose'    => 'inventory',
				'fetch'      => $package['fetch'],
			),
			AB_MCP_Test_Sync_Fetcher::for_sites()
		);
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertTrue( $state['complete'] );
		// A manifest of the old key is refused, and B gives its package out no more.
		self::refused(
			AB_MCP_Sync_Import::call(
				array(
					'package_id' => $old['package_id'],
					'manifest'   => $old['manifest'],
					'purpose'    => 'inventory',
				)
			),
			'ab_mcp_sync_manifest_key'
		);
		self::assertSame( 404, self::route( self::query( $old['package_id'], $old['fetch'][0] ) )['status'] );
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
		$two    = self::package_of( $id, array( array( self::option_line( 'zz_a' ) ), array( self::option_line( 'zz_b' ) ) ) );
		$signed = self::signed_by_b( $two['package'] );
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
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 0, 'data' => base64_encode( $two['data'][0] ) ) );
		self::assertSame( array( 1, array( 1 ) ), array( $state['received'], $state['missing'] ) );
		$refused = self::write_failing_at( 'in', 1, static fn() => AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 1, 'data' => base64_encode( $two['data'][1] ) ) ), $reads );
		self::refused( $refused, 'ab_mcp_store_write' );
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id ) );
		self::assertSame( array( 2, 1, array( 1 ), false ), array( $state['chunks'], $state['received'], $state['missing'], $state['complete'] ), 'What arrived stays; only the chunk that failed is missing.' );
		// The same chunk again: done, checked whole.
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 1, 'data' => base64_encode( $two['data'][1] ) ) );
		self::assertSame( array( 2, 2, array(), true ), array( $state['chunks'], $state['received'], $state['missing'], $state['complete'] ) );
	}

	/* -------------------------------------- what goes into a package (9) */

	public function testAPackageHoldsWhatTheAccountMayReadAndNamesTheRest(): void {
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' ) );
		$public  = wp_insert_post( array( 'post_title' => 'Rights public', 'post_status' => 'publish' ), true );
		$private = wp_insert_post( array( 'post_title' => 'Rights private', 'post_status' => 'private' ), true );
		$draft   = wp_insert_post( array( 'post_title' => 'Rights draft', 'post_status' => 'draft' ), true );
		$menu    = wp_create_nav_menu( 'Rights menu' );
		self::assertIsInt( $menu );
		update_post_meta( $draft, 'service_settings', '{"api_key":"123456789","enabled":true}' );
		update_post_meta( $public, 'api_token', 'zz 987650001' );
		$deny  = static function ( $caps, $cap, $user_id, $args ) use ( $public, $private ) {
			unset( $user_id );
			if ( in_array( $cap, array( 'read_post', 'edit_post' ), true ) && isset( $args[0] ) && (int) $args[0] === $public ) {
				return array( 'do_not_allow' );
			}
			if ( 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $private ) {
				return array( 'do_not_allow' );
			}
			return $caps;
		};
		$theme = static function ( $allcaps ) {
			unset( $allcaps['edit_theme_options'] );
			return $allcaps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		add_filter( 'user_has_cap', $theme );
		try {
			$package = AB_MCP_Sync_Export::make( 'inventory' );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
			remove_filter( 'user_has_cap', $theme );
		}
		self::assertIsArray( $package, is_wp_error( $package ) ? $package->get_error_message() : '' );
		self::assertNull( $package['cursor'], 'One package holds the inventory of this site.' );
		$posts = array();
		$refs  = array();
		foreach ( self::lines_out( $package ) as $line ) {
			$refs[] = $line['kind'] . ':' . $line['ref'];
			if ( 'post' === $line['kind'] ) {
				$posts[ (int) $line['ref'] ] = $line;
			}
		}
		self::assertArrayNotHasKey( $public, $posts, 'A post the account may not read.' );
		self::assertArrayNotHasKey( $private, $posts, 'A post that is not public, which the account may not edit.' );
		self::assertArrayHasKey( $draft, $posts );
		self::assertNotContains( 'menu:' . $menu, $refs, 'A menu without edit_theme_options.' );
		self::assertNotContains( 'option:mailserver_pass', $refs, 'A name of a credential (B9, a core option) is never packed.' );
		// Counted and named, with the capability each needs and the way.
		$named = array();
		foreach ( $package['withheld']['objects'] as $object ) {
			$named[ $object['kind'] . ':' . $object['ref'] ] = $object['needs'];
		}
		self::assertSame( 'read_post', $named[ 'post:' . $public ] );
		self::assertSame( 'edit_post', $named[ 'post:' . $private ] );
		self::assertSame( 'edit_theme_options', $named[ 'menu:' . $menu ] );
		self::assertSame( count( $package['withheld']['objects'] ), $package['withheld']['count'] );
		self::assertStringContainsString( 'Call wp_site_export again', $package['withheld']['way'] );
		self::assertGreaterThanOrEqual( 1, $package['credentials']['names'] );
		self::assertGreaterThanOrEqual( 1, $package['credentials']['held'], 'The draft with a field of a credential.' );
		self::assertSame( 0, $package['credentials']['units'], 'The post with a unit named as a credential was withheld.' );
		self::assertSame( array( 'identity', 'kind', 'locks', 'ref', 'status' ), self::sorted( array_keys( array_diff_key( $posts[ $draft ], array( 'reason' => true ) ) ) ) );
		$complete = array();
		foreach ( $package['kinds'] as $kind ) {
			$complete[ $kind['kind'] . ':' . $kind['subtype'] ] = $kind['complete'];
		}
		self::assertFalse( $complete['post:post'], 'Not complete: posts were withheld.' );
		$menus = array_filter( $package['kinds'], static fn( $kind ) => 'menu' === $kind['kind'] );
		self::assertNotSame( array(), $menus );
		self::assertNotContains( true, array_column( $menus, 'complete' ), 'Menus withheld: not complete.' );
		self::assertTrue( $complete['option:'], 'A name of a credential leaves its kind complete: it is never compared on either site.' );

		// The way: with the rights, a new package holds them, complete.
		$again = AB_MCP_Sync_Export::make( 'inventory' );
		self::assertIsArray( $again );
		self::assertSame( 0, $again['withheld']['count'] );
		self::assertArrayNotHasKey( 'way', $again['withheld'] );
		self::assertSame( 1, $again['credentials']['units'], 'meta:api_token of the public post, left out of it.' );
		$refs = array_map( static fn( $line ) => $line['kind'] . ':' . $line['ref'], self::lines_out( $again ) );
		foreach ( array( 'post:' . $public, 'post:' . $private, 'menu:' . $menu ) as $ref ) {
			self::assertContains( $ref, $refs );
		}
		foreach ( $again['kinds'] as $kind ) {
			self::assertTrue( $kind['complete'], $kind['kind'] . ':' . $kind['subtype'] );
		}
		self::assertSame( array_diff_key( $again, array( 'fetch' => true ) ), array_diff_key( AB_MCP_Sync_Export::again( $again['package_id'] ), array( 'fetch' => true ) ), 'again() answers the same, the report included.' );
		$first = AB_MCP_Sync_Export::again( $package['package_id'] );
		self::assertSame( $package['withheld'], $first['withheld'], 'Also what was withheld, named.' );
		self::assertSame( $package['credentials'], $first['credentials'] );
	}

	public function testAnAnswerNamesTheFirstTwoHundredWithheldObjectsAndCountsAll(): void {
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' ) );
		$ids = array();
		for ( $i = 0; $i < 201; $i++ ) {
			$ids[] = (int) wp_insert_post( array( 'post_title' => 'Withheld ' . $i, 'post_status' => 'publish' ) );
		}
		$deny = static fn( $caps, $cap, $user_id, $args ) => in_array( $cap, array( 'read_post', 'edit_post' ), true ) && isset( $args[0] ) && in_array( (int) $args[0], $ids, true ) ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		try {
			$package = AB_MCP_Sync_Export::make( 'inventory' );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
		self::assertIsArray( $package );
		self::assertSame( 201, $package['withheld']['count'] );
		self::assertCount( 200, $package['withheld']['objects'] );
		self::assertSame( 200, AB_MCP_Sync_Export::WITHHELD_LIST );
	}

	public function testTheRightsOfAnObjectAreAskedOfWordPress(): void {
		$rights  = static fn( string $kind, string $ref, array $identity = array() ) => \AB_MCP_Sync_Export_Rights::withheld(
			array(
				'kind'     => $kind,
				'ref'      => $ref,
				'identity' => $identity,
			)
		);
		$public  = (string) wp_insert_post( array( 'post_title' => 'R public', 'post_status' => 'publish' ) );
		$private = (string) wp_insert_post( array( 'post_title' => 'R private', 'post_status' => 'private' ) );
		$draft   = (string) wp_insert_post( array( 'post_title' => 'R draft', 'post_status' => 'draft' ) );
		register_post_type( 'zz_gone_type' );
		$gone_type = (string) wp_insert_post( array( 'post_title' => 'R gone type', 'post_status' => 'private', 'post_type' => 'zz_gone_type' ) );
		unregister_post_type( 'zz_gone_type' );
		// A type as ACF registers its field groups: its own capabilities, no
		// read_post among them, so map_meta_cap stays off.
		register_post_type(
			'zz_acf_group',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'capabilities'    => array(
					'edit_post'    => 'manage_options',
					'delete_post'  => 'manage_options',
					'edit_posts'   => 'manage_options',
					'delete_posts' => 'manage_options',
				),
				'supports'        => false,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
		$acf = (string) wp_insert_post( array( 'post_title' => 'R field group', 'post_status' => 'publish', 'post_type' => 'zz_acf_group' ) );
		register_taxonomy( 'zz_gone_tax', 'post' );
		$gone_tax = wp_insert_term( 'R gone tax', 'zz_gone_tax' );
		unregister_taxonomy( 'zz_gone_tax' );
		$category = wp_insert_term( 'R category', 'category' );
		$pattern  = wp_insert_term( 'R pattern', 'wp_pattern_category' );
		$denied   = array();
		$deny     = static function ( $caps, $cap, $user_id, $args ) use ( &$denied ) {
			unset( $user_id );
			return isset( $args[0], $denied[ $cap ] ) && in_array( (int) $args[0], $denied[ $cap ], true ) ? array( 'do_not_allow' ) : $caps;
		};
		$wrong    = 0;
		$count    = static function () use ( &$wrong ) {
			++$wrong;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		add_action( 'doing_it_wrong_run', $count );
		try {
			// Content: read_post; not public, edit_post as well.
			self::assertNull( $rights( 'post', $public ) );
			self::assertNull( $rights( 'post', $private ) );
			self::assertNull( $rights( 'post', $draft ) );
			$denied = array( 'read_post' => array( (int) $public ) );
			self::assertNull( $rights( 'post', $public ), 'Who may edit it reads it.' );
			$denied = array(
				'read_post' => array( (int) $public ),
				'edit_post' => array( (int) $public ),
			);
			self::assertSame( 'read_post', $rights( 'post', $public ) );
			$denied = array( 'edit_post' => array( (int) $private, (int) $public ) );
			self::assertSame( 'edit_post', $rights( 'post', $private ), 'Not public: edit_post as well.' );
			self::assertNull( $rights( 'post', $public ), 'Public: read_post is enough.' );
			$denied = array();
			// Gone, or no id: withheld.
			self::assertSame( 'gone', $rights( 'post', '999999' ) );
			self::assertSame( 'gone', $rights( 'post', 'abc' ) );
			self::assertSame( 'gone', $rights( 'post', $public . 'x' ), 'Not another post by its leading digits.' );
			// A type WordPress does not know: edit_others_posts, as WordPress falls back to.
			self::assertNull( $rights( 'post', $gone_type ) );
			$without = static function ( $allcaps ) {
				unset( $allcaps['edit_others_posts'], $allcaps['manage_categories'] );
				return $allcaps;
			};
			add_filter( 'user_has_cap', $without );
			try {
				self::assertTrue( current_user_can( 'manage_options' ) );
				self::assertSame( 'edit_others_posts', $rights( 'post', $gone_type ), 'manage_options is not enough for a private post of a type WordPress does not know.' );
				self::assertSame( 'manage_categories', $rights( 'term', (string) $gone_tax['term_id'], array( 'taxonomy' => 'zz_gone_tax' ) ) );
			} finally {
				remove_filter( 'user_has_cap', $without );
			}
			// A type with capabilities of its own: who may edit it reads it.
			self::assertFalse( current_user_can( 'read_post', (int) $acf ), 'WordPress gives no read_post here.' );
			self::assertNull( $rights( 'post', $acf ) );
			// Without edit_post for it (its capability, as map_meta_cap() gives it): not read.
			$denied = array( 'manage_options' => array( (int) $acf ) );
			self::assertSame( 'read_post', $rights( 'post', $acf ) );
			$denied = array();
			self::assertSame( 0, $wrong, 'Without a notice of doing it wrong.' );
			// Terms: a public taxonomy as anyone reads it, the others with edit_term.
			$denied = array( 'edit_term' => array( (int) $category['term_id'], (int) $pattern['term_id'] ) );
			self::assertNull( $rights( 'term', (string) $category['term_id'], array( 'taxonomy' => 'category' ) ) );
			self::assertSame( 'edit_term', $rights( 'term', (string) $pattern['term_id'], array( 'taxonomy' => 'wp_pattern_category' ) ) );
			$denied = array();
			self::assertNull( $rights( 'term', (string) $pattern['term_id'], array( 'taxonomy' => 'wp_pattern_category' ) ) );
			self::assertNull( $rights( 'term', (string) $gone_tax['term_id'], array( 'taxonomy' => 'zz_gone_tax' ) ), 'A taxonomy WordPress does not know, with manage_categories.' );
			self::assertSame( 'gone', $rights( 'term', '999999', array( 'taxonomy' => 'category' ) ) );
			self::assertSame( 'gone', $rights( 'term', (string) $category['term_id'] ), 'Without its taxonomy.' );
			self::assertSame( 'gone', $rights( 'term', 'x', array( 'taxonomy' => 'category' ) ) );
			self::assertSame( 'gone', $rights( 'term', $category['term_id'] . 'x', array( 'taxonomy' => 'category' ) ) );
			// The theme: edit_theme_options.
			$theme = static function ( $allcaps ) {
				unset( $allcaps['edit_theme_options'] );
				return $allcaps;
			};
			foreach ( \AB_MCP_Sync_Export_Rights::THEME as $kind ) {
				self::assertNull( $rights( $kind, 'x' ), $kind );
			}
			add_filter( 'user_has_cap', $theme );
			try {
				foreach ( \AB_MCP_Sync_Export_Rights::THEME as $kind ) {
					self::assertSame( 'edit_theme_options', $rights( $kind, 'x' ), $kind );
				}
			} finally {
				remove_filter( 'user_has_cap', $theme );
			}
			self::assertSame( array( 'menu', 'menu_item', 'widget_area', 'theme_mod', 'custom_css' ), \AB_MCP_Sync_Export_Rights::THEME );
			// Options with manage_options, which every export asks first.
			self::assertNull( $rights( 'option', 'blogname' ) );
			// A kind without a rule here: withheld.
			self::assertSame( 'kind', $rights( 'attachment', $public ) );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
			remove_action( 'doing_it_wrong_run', $count );
			unregister_post_type( 'zz_acf_group' );
		}
		self::assertSame( 0, $wrong, 'No notice of doing it wrong at all.' );
	}

	public function testAFieldGroupOfAcfIsInThePackageOfAnAdministrator(): void {
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' ) );
		register_post_type(
			'acf-field-group',
			array(
				'public'          => false,
				'hierarchical'    => true,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'_builtin'        => false,
				'capability_type' => 'post',
				'capabilities'    => array(
					'edit_post'    => 'manage_options',
					'delete_post'  => 'manage_options',
					'edit_posts'   => 'manage_options',
					'delete_posts' => 'manage_options',
				),
				'supports'        => false,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
		try {
			$group   = wp_insert_post( array( 'post_title' => 'Group', 'post_status' => 'publish', 'post_type' => 'acf-field-group' ) );
			$package = AB_MCP_Sync_Export::make( 'inventory' );
			self::assertIsArray( $package );
			self::assertContains( 'post:' . $group, array_map( static fn( $line ) => $line['kind'] . ':' . $line['ref'], self::lines_out( $package ) ), 'An administrator has the field group in the package.' );
			self::assertSame( 0, $package['withheld']['count'] );
			// An account without edit_post for it does not.
			// map_meta_cap() hands the filter the capability of the type (manage_options) for edit_post.
			$deny = static fn( $caps, $cap, $user_id, $args ) => isset( $args[0] ) && (int) $args[0] === $group ? array( 'do_not_allow' ) : $caps;
			add_filter( 'map_meta_cap', $deny, 10, 4 );
			try {
				$without = AB_MCP_Sync_Export::make( 'inventory' );
			} finally {
				remove_filter( 'map_meta_cap', $deny, 10 );
			}
			self::assertSame(
				array(
					'kind'    => 'post',
					'needs'   => 'read_post',
					'ref'     => (string) $group,
					'subtype' => 'acf-field-group',
				),
				$without['withheld']['objects'][0]
			);
		} finally {
			unregister_post_type( 'acf-field-group' );
		}
	}

	public function testWithWriteAccessOffAStagingSiteMakesAndGivesOutNothing(): void {
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' ) );
		$package = AB_MCP_Sync_Export::make( 'inventory' );
		self::assertIsArray( $package );
		$request = new \WP_REST_Request( 'GET', '/alphabridge/v1/staging-package' );
		$request->set_query_params( self::query( $package['package_id'], $package['fetch'][0] ) );
		self::assertIsString( AB_MCP_Sync_Export::requested( $request, time() ) );
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::READ );
		self::assertNull( AB_MCP_Sync_Export::requested( $request, time() ), 'No chunk given out.' );
		self::refused( AB_MCP_Sync_Export::make( 'inventory' ), 'ab_mcp_sync_export_mode' );
		self::refused( AB_MCP_Sync_Export::again( $package['package_id'] ), 'ab_mcp_sync_export_mode' );
		// Switched on again: given out again.
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		self::assertIsString( AB_MCP_Sync_Export::requested( $request, time() ) );
	}

	/* ------------------------------------------ unpacked within limits (8) */

	public function testAnImportUnpacksEachLineWithinTheLimitsAndChecksItAgain(): void {
		self::pair();
		// A name only this site counts as a credential: listed as held.
		$keys = static fn( $list ) => array_merge( (array) $list, array( 'zz_live_only_name' ) );
		add_filter( 'ab_mcp_redact_keys', $keys );
		try {
			$mod   = array(
				'identity' => array( 'name' => 'theme_mods_zz', 'theme_mod' => 'zz_live_only_name' ),
				'kind'     => 'theme_mod',
				'locks'    => array(),
				'ref'      => 'zz/zz_live_only_name',
				'status'   => 'locked',
			);
			$alias = self::option_line( 'zz_alias', array( 'reason_code' => 'read_as_other_row', 'status' => 'unreachable', 'target' => 'zz_live_only_name' ) );
			$state = self::import_relayed( self::package_of( str_repeat( '1a', 16 ), array( array( self::option_line( 'zz_live_only_name' ), self::option_line( 'zz_plain' ) ), array( self::option_line( 'zz_other' ), $mod, $alias ) ) ) );
			self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
			self::assertTrue( $state['complete'] );
			self::assertSame( 3, $state['held'], 'The option, the theme mod and the option WordPress reads it for.' );
			self::assertSame( 3, AB_MCP_Sync_Import::call( array( 'package_id' => str_repeat( '1a', 16 ) ) )['held'], 'Read back on a later call.' );
			self::assertSame( array( self::held( 'option', 'zz_live_only_name', 'credential_name' ), self::held( 'theme_mod', 'zz/zz_live_only_name', 'credential_name' ), self::held( 'option', 'zz_alias', 'credential_name' ) ), self::held_of( str_repeat( '1a', 16 ) ) );
		} finally {
			remove_filter( 'ab_mcp_redact_keys', $keys );
		}
		$name_only = array(
			'identity' => array( 'name' => 'theme_mods_zz' ),
			'kind'     => 'theme_mod',
			'locks'    => array(),
			'ref'      => 'zz',
			'status'   => 'locked',
		);
		$state     = self::import_relayed( self::package_of( str_repeat( '1b', 16 ), array( array( self::option_line( 'zz_live_only_name' ), $name_only ) ) ) );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertSame( 0, $state['held'], 'Without that rule, nothing held; a theme mod row by its name only goes through.' );
		// A row that could not be read names its object by its ref: held where that names a credential.
		$state = self::import_relayed( self::package_of( str_repeat( '1d', 16 ), array( array( array( 'complete' => false, 'identity' => array(), 'kind' => 'theme_mod', 'locks' => array(), 'ref' => 'api_token', 'status' => 'z8', 'unread' => array( 'options' ) ) ) ) ) );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertSame( 1, $state['held'] );
		// An option whose name is bytes that are no UTF-8 (a site in latin1): its name and ref in the byte form.
		$state = self::import_relayed( self::package_of( str_repeat( '1e', 16 ), array( array( array( 'identity' => array( 'name' => "zz\xE9" ), 'kind' => 'option', 'locks' => array(), 'ref' => "zz\xE9", 'status' => 'z8' ) ) ) ) );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertTrue( $state['complete'] );
		// A package without an object: one empty chunk.
		$state = self::import_relayed( self::package_of( str_repeat( '1c', 16 ), array( array() ) ) );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertSame( array( true, 0 ), array( $state['complete'], $state['held'] ) );
		// The names of a line never refuse the package: any text, of any
		// length; a credential written into one holds that line, with the
		// code of why (Codex round 3 of part 3).
		$lock    = \AB_MCP_Sync_Kinds::lock( 'option', 'unclassified' );
		$layered = 'nothing to see here at all';
		for ( $i = 0; $i < 5; $i++ ) {
			$layered = base64_encode( $layered );
		}
		$class   = 'Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer';
		$names   = array(
			'a name with backslashes'      => array( self::option_line( 'wc_batch_' . $class . '_' . md5( $class ) ), null ),
			'a ref with a quote'           => array( self::option_line( 'zz"q' ), null ),
			'an empty name'                => array( self::option_line( '' ), null ),
			'a place with a line break'    => array( self::option_line( 'zz_pl', array( 'b9' => array( "meta:a\nb" ) ) ), null ),
			'an unread place with a quote' => array( self::option_line( 'zz_pq', array( 'unread' => array( 'depth:"x"' ) ) ), null ),
			'an empty place'               => array( self::option_line( 'zz_pe', array( 'b9' => array( '' ) ) ), null ),
			'a place over 4 KiB'           => array( self::option_line( 'zz_pt', array( 'possible' => array( array( 'at' => 'option_value.' . str_repeat( 'k', 4200 ) ) ) ) ), null ),
			'a page reset-password'        => array( array( 'identity' => array( 'post_name' => 'reset-password', 'post_type' => 'page', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => '8', 'status' => 'z8' ), null ),
			'a slug of a credential'       => array( array( 'identity' => array( 'post_name' => '{"password":"CANARY-ONLY"}', 'post_type' => 'post', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => '7', 'status' => 'z8' ), 'credential_field' ),
			'a name that does not read'    => array( self::option_line( '<script>x</script>' ), 'unreadable_name' ),
			'a place of a credential'      => array( self::option_line( 'zz_pj', array( 'possible' => array( array( 'at' => '{"password":"CANARY-ONLY"}' ) ) ) ), 'credential_field' ),
			'a place of a reference'       => array( self::option_line( 'zz_ra', array( 'refs' => array( array( 'at' => '{"password":"x"}', 'id' => 7, 'space' => 'post', 'strength' => 'strong' ) ) ) ), 'credential_field' ),
			'a login of a credential'      => array( self::option_line( 'zz_lg', array( 'refs' => array( array( 'at' => 'post_author', 'id' => 1, 'login' => '{"password":"x"}', 'space' => 'user', 'strength' => 'strong' ) ) ) ), 'credential_field' ),
			'a place of a lock'            => array( self::option_line( 'zz_la', array( 'locks' => array( $lock + array( 'at' => '{"password":"x"}' ) ), 'status' => 'locked' ) ), 'credential_field' ),
			'a place of a unit'            => array( self::option_line( 'zz_b9', array( 'b9' => array( 'meta:{"password":"x"}' ) ) ), 'credential_field' ),
			'a target of a credential'     => array( self::option_line( 'zz_tg', array( 'reason_code' => 'read_as_other_row', 'status' => 'unreachable', 'target' => '{"password":"x"}' ) ), 'credential_field' ),
			// The net over the raw value, as the export runs it (Codex round 4 of part 3): decoded, in base64.
			'a slug of a credential in base64' => array( array( 'identity' => array( 'post_name' => 'eyJwYXNzd29yZCI6IkNBTkFSWS1PTkxZIn0=', 'post_type' => 'post', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => '9', 'status' => 'z8' ), 'credential_field' ),
			'a slug of a credential in code' => array( array( 'identity' => array( 'post_name' => '{password:"CANARY-ONLY"}', 'post_type' => 'post', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => '10', 'status' => 'z8' ), 'credential_field' ),
			'a slug of a credential encoded twice' => array( array( 'identity' => array( 'post_name' => 'password%253DCANARY-ONLY', 'post_type' => 'post', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => '11', 'status' => 'z8' ), 'credential_field' ),
			'a slug the net does not read to its end' => array( array( 'identity' => array( 'post_name' => $layered, 'post_type' => 'post', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => '12', 'status' => 'z8' ), 'unreadable_name' ),
			'a slug whose braces cannot be counted' => array( array( 'identity' => array( 'post_name' => 'a}, b: c', 'post_type' => 'post', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => '13', 'status' => 'z8' ), 'unreadable_name' ),
			'a slug with a field of a credential and no value' => array( array( 'identity' => array( 'post_name' => '{"password":""}', 'post_type' => 'post', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => '16', 'status' => 'z8' ), 'credential_field' ),
			'a slug read as code with a RegExp literal' => array( array( 'identity' => array( 'post_name' => '{}if (x) /re/, token: v', 'post_type' => 'post', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => '15', 'status' => 'z8' ), 'unreadable_name' ),
			'a slug with a data address that is no text' => array( array( 'identity' => array( 'post_name' => 'data:text/plain;base64,//79', 'post_type' => 'post', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => '14', 'status' => 'z8' ), 'unreadable_name' ),
			'a ref that carries a value'   => array( self::option_line( 'zz_note {"password":"CANARY-ONLY"}' ), 'credential_field' ),
			'a ref of a credential with its value' => array( self::option_line( 'api_key=CANARY-ONLY' ), 'credential_name' ),
		);
		// Ordinary names stay as they are: slugs, Unicode and number slugs,
		// the names and places of WooCommerce, Elementor and Yoast.
		foreach ( array( 'api-docs', 'token-sale', 'about-us', 'r-d', '&amp;', 'R&D', '%c3%bcber-uns', 'über-uns', '2024', 'lost-password', 'my-account' ) as $i => $slug ) {
			$names[ 'the slug ' . $slug ] = array( array( 'identity' => array( 'post_name' => $slug, 'post_type' => 'page', 'theme' => '' ), 'kind' => 'post', 'locks' => array(), 'ref' => (string) ( 100 + $i ), 'status' => 'z8' ), null );
		}
		foreach ( array( 'woocommerce_api_enabled', 'woocommerce_myaccount_orders_endpoint', 'woocommerce_checkout_pay_endpoint', 'wpseo_titles', 'elementor_active_kit', '_elementor_page_settings' ) as $option ) {
			$names[ 'the option ' . $option ] = array( self::option_line( $option ), null );
		}
		foreach ( array( 'meta:_yoast_wpseo_title', 'meta:_yoast_wpseo_metadesc', 'meta:_elementor_data.0.elements.0.settings.__dynamic__.link', 'meta:_wc_order_attribution_session_count', 'meta:_regular_price', 'post_content:html:a.href' ) as $i => $place ) {
			$names[ 'the place ' . $place ] = array( self::option_line( 'zz_place_' . $i, array( 'possible' => array( array( 'at' => $place ) ) ) ), null );
		}
		$state   = self::import_relayed( self::package_of( str_repeat( '1f', 16 ), array( array_column( $names, 0 ) ) ) );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		$want = array();
		foreach ( $names as $given ) {
			if ( null !== $given[1] ) {
				$want[] = self::held( $given[0]['kind'], $given[0]['ref'], $given[1] );
			}
		}
		self::assertSame( array( true, count( $want ) ), array( $state['complete'], $state['held'] ) );
		self::assertSame( $want, self::held_of( str_repeat( '1f', 16 ), $text ), 'Kind, the SHA-256 of the ref and code.' );
		self::assertStringNotContainsString( 'CANARY', $text, 'No value, not even in a ref.' );
		// Refs of any length: the list holds a hash of each, so it fits its
		// reservation however long they are (five of a million bytes, each
		// line in a chunk of its own, as the export cuts them).
		$long = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$area   = 'password=CANARY-ONLY-' . $i . str_repeat( 'a', 1000000 );
			$long[] = array( 'identity' => array( 'area' => $area ), 'kind' => 'widget_area', 'locks' => array(), 'ref' => $area, 'status' => 'z8' );
		}
		$state = self::import_relayed( self::package_of( str_repeat( 'd3', 16 ), array_map( static fn( $line ) => array( $line ), $long ) ) );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertSame( array( true, 5 ), array( $state['complete'], $state['held'] ) );
		self::assertSame( array_map( static fn( $line ) => self::held( 'widget_area', $line['ref'], 'credential_field' ), $long ), self::held_of( str_repeat( 'd3', 16 ), $text ) );
		self::assertLessThan( 1024, strlen( $text ) );

		// Packages that do not unpack as they must: refused, removed.
		$deep = '1';
		for ( $i = 0; $i < 70; $i++ ) {
			$deep = '{"d":' . $deep . '}';
		}
		$many = array();
		for ( $i = 0; $i < 5001; $i++ ) {
			$many[] = self::option_line( 'o' . $i );
		}
		$lock   = array( \AB_MCP_Sync_Kinds::lock( 'option', 'credential_field' ) + array( 'at' => 'option_value' ) );
		$base   = self::package_of( str_repeat( '2a', 16 ), array( array( self::option_line( 'zz_a' ) ) ) );
		$one    = \AB_MCP_Sync_Canon::encode( self::option_line( 'zz_one' ) ) . "\n";
		$big    = self::package_of( str_repeat( '2c', 16 ), array( array( self::option_line( 'zz_big', array( 'reason' => str_repeat( 'a', 8388608 ) ) ) ) ) );
		$cases  = array(
			'no gzip'                  => array( self::package_of( str_repeat( '2b', 16 ), array( array( self::option_line( 'zz_a' ) ) ), array(), false ), 'ab_mcp_sync_import_lines' ),
			'more than declared'       => array( self::package_of( str_repeat( '2a', 16 ), array( array( self::option_line( 'zz_a' ) ) ), array( 'unpacked' => $base['package']['unpacked'] - 1 ) ), 'ab_mcp_sync_import_lines' ),
			'a chunk past one object'  => array( $big, 'ab_mcp_store_unpack_limit' ),
			'too deep'                 => array( self::package_of( str_repeat( '2d', 16 ), array( array( '{"identity":' . $deep . ',"kind":"option","locks":[],"ref":"x","status":"z8"}' ) ) ), 'ab_mcp_store_unpack_limit' ),
			'more than 5000 objects'   => array( self::package_of( str_repeat( '2e', 16 ), array( $many ), array( 'objects' => 5000 ) ), 'ab_mcp_store_unpack_limit' ),
			'another spelling'         => array( self::package_of( str_repeat( '2f', 16 ), array( array( '{"identity": {"name":"x"},"kind":"option","locks":[],"ref":"x","status":"z8"}' ) ) ), 'ab_mcp_sync_import_lines' ),
			'no JSON'                  => array( self::package_of( str_repeat( '3a', 16 ), array( array( 'zz' ) ) ), 'ab_mcp_sync_import_lines' ),
			'a field of content'       => array( self::package_of( str_repeat( '3b', 16 ), array( array( self::option_line( 'zz_v', array( 'value' => 'content' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a field missing'          => array( self::package_of( str_repeat( '3c', 16 ), array( array( array_diff_key( self::option_line( 'zz_m' ), array( 'locks' => true ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a name of a credential'   => array( self::package_of( str_repeat( '3d', 16 ), array( array( self::option_line( 'password_9', array( 'status' => 'b9' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a held line with a hash'  => array( self::package_of( str_repeat( '3e', 16 ), array( array( self::option_line( 'zz_h', array( 'locks' => $lock, 'hash' => str_repeat( 'a', 64 ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'fewer lines than declared' => array( self::package_of( str_repeat( '3f', 16 ), array( array( self::option_line( 'zz_f' ) ) ), array( 'objects' => 2 ) ), 'ab_mcp_sync_import_lines' ),
			'shorter than declared'    => array( self::package_of( str_repeat( '4b', 16 ), array( array( self::option_line( 'zz_a' ) ) ), array( 'unpacked' => $base['package']['unpacked'] + 1 ) ), 'ab_mcp_sync_import_lines' ),
			'a lock that is no lock'   => array( self::package_of( str_repeat( '4c', 16 ), array( array( self::option_line( 'zz_l', array( 'locks' => array( 'x' ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'no whole line'            => array( self::package_of( str_repeat( '4a', 16 ), array( \AB_MCP_Sync_Canon::encode( self::option_line( 'zz_w' ) ) ), array( 'objects' => 1 ) ), 'ab_mcp_sync_import_lines' ),
			'X instead of a line break' => array( self::package_of( str_repeat( '4d', 16 ), array( \AB_MCP_Sync_Canon::encode( self::option_line( 'zz_x' ) ) . 'X' ), array( 'objects' => 1 ) ), 'ab_mcp_sync_import_lines' ),
			'bytes after the gzip'     => array( self::package_raw( str_repeat( '4e', 16 ), array( (string) gzencode( $one ) . 'XYZ' ), 1, strlen( $one ) ), 'ab_mcp_sync_import_lines' ),
			'a second gzip member'     => array( self::package_raw( str_repeat( '4f', 16 ), array( (string) gzencode( $one ) . (string) gzencode( $one ) ), 1, strlen( $one ) ), 'ab_mcp_sync_import_lines' ),
			'a field in the identity'  => array( self::package_of( str_repeat( '5a', 16 ), array( array( self::option_line( 'zz_i', array( 'identity' => array( 'name' => 'zz_i', 'password' => 'CANARY' ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a field in a lock'        => array( self::package_of( str_repeat( '5b', 16 ), array( array( self::option_line( 'zz_k', array( 'locks' => array( $lock[0] + array( 'value' => 'x' ) ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a field in a reference'   => array( self::package_of( str_repeat( '5c', 16 ), array( array( self::option_line( 'zz_r', array( 'refs' => array( array( 'at' => 'value', 'id' => 7, 'space' => 'post', 'strength' => 'strong', 'token' => 'x' ) ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a value in a possible one' => array( self::package_of( str_repeat( '5d', 16 ), array( array( self::option_line( 'zz_p', array( 'possible' => array( array( 'at' => 'value', 'value' => 7 ) ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a hash that is a list'    => array( self::package_of( str_repeat( '5e', 16 ), array( array( self::option_line( 'zz_h2', array( 'hash' => array( 'x' ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a size that is text'      => array( self::package_of( str_repeat( '5f', 16 ), array( array( self::option_line( 'zz_s', array( 'size' => '12' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'unread that is no list of text' => array( self::package_of( str_repeat( '6a', 16 ), array( array( self::option_line( 'zz_u', array( 'unread' => array( 7 ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a ref that is not its name' => array( self::package_of( str_repeat( '6b', 16 ), array( array( self::option_line( 'zz_n', array( 'ref' => 'zz_other' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a post ref that is no id' => array( self::package_of( str_repeat( '6c', 16 ), array( array( array( 'complete' => false, 'identity' => array(), 'kind' => 'post', 'locks' => array(), 'ref' => 'x', 'status' => 'z8', 'unread' => array( 'posts' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a kind without a rule'    => array( self::package_of( str_repeat( '6d', 16 ), array( array( array( 'identity' => array(), 'kind' => 'attachment', 'locks' => array(), 'ref' => '7', 'status' => 'z8' ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a post with another identity' => array( self::package_of( str_repeat( '6e', 16 ), array( array( array( 'identity' => array( 'name' => 'x' ), 'kind' => 'post', 'locks' => array(), 'ref' => '7', 'status' => 'z8' ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a theme mod of another key' => array( self::package_of( str_repeat( '6f', 16 ), array( array( array( 'identity' => array( 'name' => 'theme_mods_zz', 'theme_mod' => 'a' ), 'kind' => 'theme_mod', 'locks' => array(), 'ref' => 'zz/b', 'status' => 'locked' ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a status of another form' => array( self::package_of( str_repeat( '7a', 16 ), array( array( self::option_line( 'zz_t', array( 'status' => 'Z8 ' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'menus that are no ids'    => array( self::package_of( str_repeat( '7b', 16 ), array( array( array( 'identity' => array( 'menus' => array( 'x' ), 'object' => '', 'position' => '1', 'type' => 'custom' ), 'kind' => 'menu_item', 'locks' => array(), 'ref' => '9', 'status' => 'z8' ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a widget area of another name' => array( self::package_of( str_repeat( '7c', 16 ), array( array( array( 'identity' => array( 'area' => 'sidebar-1' ), 'kind' => 'widget_area', 'locks' => array(), 'ref' => 'sidebar-2', 'status' => 'z8' ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'the CSS of another stylesheet' => array( self::package_of( str_repeat( '7d', 16 ), array( array( array( 'identity' => array( 'linked' => false, 'post' => 5, 'stylesheet' => 'a' ), 'kind' => 'custom_css', 'locks' => array(), 'ref' => 'a@6', 'status' => 'z8' ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'locks that are no list'   => array( self::package_of( str_repeat( '7f', 16 ), array( array( self::option_line( 'zz_lk', array( 'locks' => array( 'a' => $lock[0] ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a reference without its strength' => array( self::package_of( str_repeat( '8b', 16 ), array( array( self::option_line( 'zz_rs', array( 'refs' => array( array( 'at' => 'value', 'id' => 7, 'space' => 'post' ) ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'complete that is text'    => array( self::package_of( str_repeat( '8c', 16 ), array( array( self::option_line( 'zz_c', array( 'complete' => 'yes' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a reason of a lock that is a number' => array( self::package_of( str_repeat( '8d', 16 ), array( array( self::option_line( 'zz_rn', array( 'locks' => array( array( 'reason' => 7 ) + $lock[0] ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'canon that is a number'   => array( self::package_of( str_repeat( '8e', 16 ), array( array( self::option_line( 'zz_cn', array( 'canon' => 1 ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a reason as free text'    => array( self::package_of( str_repeat( '9a', 16 ), array( array( self::option_line( 'zz_rf', array( 'reason' => '{"password":"CANARY-ONLY"}' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'canon of another version' => array( self::package_of( str_repeat( '9b', 16 ), array( array( self::option_line( 'zz_cv', array( 'canon' => 'canon/9' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a lock no table has'      => array( self::package_of( str_repeat( '9c', 16 ), array( array( self::option_line( 'zz_lt', array( 'locks' => array( array( 'key' => '*:password', 'stage' => 'Z8d', 'reason' => 'x', 'at' => 'option_value' ) ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a lock with another reason' => array( self::package_of( str_repeat( '9d', 16 ), array( array( self::option_line( 'zz_lr', array( 'locks' => array( array( 'reason' => '{"password":"CANARY-ONLY"}' ) + $lock[0] ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a lock with another stage' => array( self::package_of( str_repeat( '9e', 16 ), array( array( self::option_line( 'zz_ls', array( 'locks' => array( array( 'stage' => 'Z99' ) + $lock[0] ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a space no reader writes' => array( self::package_of( str_repeat( 'a4', 16 ), array( array( self::option_line( 'zz_sp', array( 'refs' => array( array( 'at' => 'value', 'id' => 7, 'space' => 'option', 'strength' => 'strong' ) ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a strength no reader writes' => array( self::package_of( str_repeat( 'a5', 16 ), array( array( self::option_line( 'zz_st', array( 'refs' => array( array( 'at' => 'value', 'id' => 7, 'space' => 'post', 'strength' => 'weak' ) ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a status no reader writes' => array( self::package_of( str_repeat( 'a7', 16 ), array( array( self::option_line( 'zz_ex', array( 'status' => 'excluded' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'no identity, readable'    => array( self::package_of( str_repeat( 'a8', 16 ), array( array( array( 'identity' => array(), 'kind' => 'option', 'locks' => array(), 'ref' => 'api_token', 'status' => 'z8' ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'no identity, complete'    => array( self::package_of( str_repeat( 'a9', 16 ), array( array( array( 'complete' => true, 'identity' => array(), 'kind' => 'post', 'locks' => array(), 'ref' => '7', 'status' => 'z8', 'unread' => array( 'posts' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'no identity, another unread' => array( self::package_of( str_repeat( 'b1', 16 ), array( array( array( 'complete' => false, 'identity' => array(), 'kind' => 'post', 'locks' => array(), 'ref' => '7', 'status' => 'z8', 'unread' => array( 'encode' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'bytes not as the canon writes them' => array( self::package_of( str_repeat( 'b5', 16 ), array( array( array( 'identity' => array( 'name' => array( '~b' => rtrim( base64_encode( "\xC3\x28" ), '=' ) ) ), 'kind' => 'option', 'locks' => array(), 'ref' => "\xC3\x28", 'status' => 'z8' ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'bytes that are UTF-8'     => array( self::package_of( str_repeat( 'b2', 16 ), array( array( array( 'identity' => array( 'name' => array( '~b' => base64_encode( 'zz' ) ) ), 'kind' => 'option', 'locks' => array(), 'ref' => 'zz', 'status' => 'z8' ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a reason code of another status' => array( self::package_of( str_repeat( 'c2', 16 ), array( array( self::option_line( 'zz_rc', array( 'reason_code' => 'option_guard' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a reason code outside its list' => array( self::package_of( str_repeat( 'c3', 16 ), array( array( self::option_line( 'zz_ro', array( 'reason_code' => 'read_as_other_row', 'status' => 'guarded' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a reason without its code'      => array( self::package_of( str_repeat( 'c4', 16 ), array( array( self::option_line( 'zz_rw', array( 'status' => 'unreadable' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'unreachable without its target' => array( self::package_of( str_repeat( 'c5', 16 ), array( array( self::option_line( 'zz_ut', array( 'reason_code' => 'read_as_other_row', 'status' => 'unreachable' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a target elsewhere'             => array( self::package_of( str_repeat( 'c6', 16 ), array( array( self::option_line( 'zz_te', array( 'reason_code' => 'option_guard', 'status' => 'guarded', 'target' => 'x' ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a target that is no name'       => array( self::package_of( str_repeat( 'c7', 16 ), array( array( self::option_line( 'zz_tn', array( 'reason_code' => 'read_as_other_row', 'status' => 'unreachable', 'target' => 7 ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a login that is a number'       => array( self::package_of( str_repeat( 'c8', 16 ), array( array( self::option_line( 'zz_ln', array( 'refs' => array( array( 'at' => 'post_author', 'id' => 1, 'login' => 7, 'space' => 'user', 'strength' => 'strong' ) ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a kind that is a list'          => array( self::package_of( str_repeat( 'd2', 16 ), array( array( self::option_line( 'zz_kl', array( 'kind' => array( 'option' ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'unread that is no list'         => array( self::package_of( str_repeat( 'd1', 16 ), array( array( self::option_line( 'zz_ul', array( 'unread' => array( 'a' => 'options' ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a place in a broken byte form'  => array( self::package_of( str_repeat( 'c9', 16 ), array( array( self::option_line( 'zz_bb', array( 'possible' => array( array( 'at' => array( '~b' => 'x' ) ) ) ) ) ) ) ), 'ab_mcp_sync_import_lines' ),
			'a position that is a word' => array( self::package_of( str_repeat( '8f', 16 ), array( array( array( 'identity' => array( 'menus' => array( 3 ), 'object' => '', 'position' => 'x', 'type' => 'custom' ), 'kind' => 'menu_item', 'locks' => array(), 'ref' => '9', 'status' => 'z8' ) ) ) ), 'ab_mcp_sync_import_lines' ),
		);
		foreach ( $cases as $case => $given ) {
			$result = self::import_relayed( $given[0] );
			self::assertInstanceOf( WP_Error::class, $result, $case );
			self::assertSame( $given[1], $result->get_error_code(), $case . ': ' . $result->get_error_message() );
			self::assertSame( array(), self::groups_of( $given[0]['package']['package_id'] ), $case . ': what arrived was removed.' );
		}
		self::assertSame( 'depth', self::import_relayed( $cases['too deep'][0] )->get_error_data()['limit'] );
		self::assertSame( 'objects', self::import_relayed( $cases['more than 5000 objects'][0] )->get_error_data()['limit'] );
		self::assertSame( 'object_bytes', self::import_relayed( $big )->get_error_data()['limit'] );
		self::assertStringContainsString( 'Export the package again', self::import_relayed( $cases['no gzip'][0] )->get_error_message() );
		self::assertStringContainsString( 'inflates to more than the manifest declares', self::import_relayed( $cases['more than declared'][0] )->get_error_message(), 'Inflated no further than declared.' );
		self::assertStringContainsString( 'not as many, or not as long', self::import_relayed( $cases['shorter than declared'][0] )->get_error_message() );
		// The limits of the manifest are those of unpacking.
		self::assertSame( \AB_MCP_Store_Budget::UNPACK['package_bytes'], AB_MCP_Sync_Manifest::MAX_UNPACKED );
		self::assertSame( \AB_MCP_Store_Budget::UNPACK['objects'], AB_MCP_Sync_Manifest::MAX_OBJECTS );
		// The way: a package as the staging site makes it goes through.
		$package = self::export();
		$state   = AB_MCP_Sync_Import::call(
			array(
				'package_id' => $package['package_id'],
				'manifest'   => $package['manifest'],
				'purpose'    => 'inventory',
				'fetch'      => $package['fetch'],
			),
			AB_MCP_Test_Sync_Fetcher::for_sites()
		);
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertTrue( $state['complete'] );
		self::assertSame( 0, $state['held'] );
	}

	public function testTheInventoryOfBothSitesOverEveryKindPassesTheCheckOfTheImport(): void {
		self::pair();
		// B with an object of every kind.
		self::b(
			array(
				array( 'op' => 'insert_post', 'as' => 'post', 'args' => array( 'post_title' => 'Every post', 'post_status' => 'publish', 'post_content' => '<p>Text <a href="?p=1">link</a></p>' ) ),
				array( 'op' => 'insert_post', 'as' => 'page', 'args' => array( 'post_title' => 'Every page', 'post_type' => 'page', 'post_status' => 'draft' ) ),
				array( 'op' => 'add_meta', 'post' => '$post', 'key' => '_thumbnail_id', 'value' => '1' ),
				array( 'op' => 'add_meta', 'post' => '$post', 'key' => 'service_settings', 'value' => '{"api_key":"x1","enabled":true}' ),
				array( 'op' => 'add_meta', 'post' => '$page', 'key' => 'api_token', 'value' => 'x2' ),
				array( 'op' => 'insert_term', 'as' => 'cat', 'name' => 'Every category', 'taxonomy' => 'category' ),
				array( 'op' => 'set_terms', 'post' => '$post', 'terms' => array( '$cat' ), 'taxonomy' => 'category' ),
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => array( 2 => array( 'title' => 'Every', 'text' => 'widget' ), '_multiwidget' => 1 ) ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => array( 'wp_inactive_widgets' => array(), 'sidebar-1' => array( 'text-2' ), 'array_version' => 3 ) ),
				array( 'op' => 'update_option', 'name' => 'zz_plugin_settings', 'value' => array( 'a' => 1, 'b' => array( 'c' => 'd' ) ) ),
				array( 'op' => 'menu_css_mods', 'as' => 'theme' ),
			)
		);
		$cursor = null;
		$kinds  = array();
		$count  = 0;
		do {
			$package = self::tool( 'b', 'wp_site_export', array( 'purpose' => 'inventory' ) + ( null === $cursor ? array() : array( 'cursor' => $cursor ) ) );
			self::assertIsArray( $package, is_string( $package ) ? $package : '' );
			$state = AB_MCP_Sync_Import::call(
				array(
					'package_id' => $package['package_id'],
					'manifest'   => $package['manifest'],
					'purpose'    => 'inventory',
					'fetch'      => $package['fetch'],
				),
				AB_MCP_Test_Sync_Fetcher::for_sites()
			);
			self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
			self::assertTrue( $state['complete'] );
			self::assertSame( 0, $state['held'] );
			foreach ( $package['kinds'] as $kind ) {
				$kinds[ $kind['kind'] ] = true;
			}
			$count += $package['objects'];
			$cursor = $package['cursor'];
		} while ( null !== $cursor );
		ksort( $kinds );
		self::assertSame( array( 'custom_css', 'menu', 'menu_item', 'option', 'post', 'term', 'theme_mod', 'widget_area' ), array_keys( $kinds ), 'Every kind of B went through.' );
		self::assertGreaterThan( 50, $count );

		// A's own inventory, line by line, through the same check.
		$check = new \ReflectionMethod( AB_MCP_Sync_Import::class, 'line_shaped' );
		$lines = 0;
		foreach ( \AB_MCP_Sync_Inventory::run( \AB_MCP_Sync_Inventory::source( \AB_MCP_IT_Sync_Steps::addresses() ) )['objects'] as $object ) {
			if ( 'b9' === $object['status'] ) {
				continue;
			}
			$line = json_decode( \AB_MCP_Sync_Canon::encode( AB_MCP_Sync_Export::line( $object ) ), true );
			self::assertTrue( $check->invoke( null, $line ), \AB_MCP_Sync_Canon::encode( $line ) );
			++$lines;
		}
		self::assertGreaterThan( 50, $lines );
	}

	public function testEveryFormOfALineTheReadersMakeGoesThroughTheImport(): void {
		global $wpdb;
		$post    = wp_insert_post( array( 'post_title' => 'Forms', 'post_status' => 'publish', 'post_content' => '<p>x</p>' ), true );
		$bytes   = wp_insert_post( array( 'post_title' => 'Bytes', 'post_status' => 'draft' ), true );
		$unread  = wp_insert_post( array( 'post_title' => 'Unread', 'post_status' => 'draft' ), true );
		$menu    = wp_create_nav_menu( 'Forms menu' );
		$item    = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Below', 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ) );
		self::assertIsInt( $item );
		$wpdb->update( $wpdb->posts, array( 'menu_order' => -1 ), array( 'ID' => $item ) );
		clean_post_cache( $item );
		update_post_meta( $post, 'service_settings', '{"api_key":"x1","enabled":true}' );
		$units = wp_insert_post( array( 'post_title' => 'Units', 'post_status' => 'publish' ), true );
		update_post_meta( $units, 'api_token', 'x2' );
		update_post_meta( $units, 'zz_related', (string) $post );
		update_option( 'password_1', 'guarded' );
		update_option( 'theme_mods_zzbroken', 'not an array' );
		wp_update_custom_css_post( 'body { color: #123; }' );
		// What ordinary sites hold (Codex round 3 of part 3): the state of a
		// batch of WooCommerce, named with its class and backslashes
		// (BatchProcessingController); keys of a serialized value with a
		// quote, a backslash and braces, and a path over 4 KiB, each over a
		// local link; a login beyond ASCII (kept by a filter of a plugin); a
		// slug that holds a credential and a page reset-password; a row
		// WordPress reads under another name, one that could not be looked up.
		$class = 'Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer';
		$wc    = 'wc_batch_' . $class . '_' . md5( $class );
		update_option( $wc, array( 'total_time_spent' => 0, 'current_batch_size' => 100, 'last_error' => null ), false );
		$link = home_url( '/?p=' . $post );
		$long = str_repeat( 'k', 4200 );
		update_option( 'zz_keys', array( 'a"b' => $link, 'Vendor\Service' => array( 'page' => $link ), '{{WRAPPER}}' => '<a href="' . $link . '">x</a>', $long => $link ) );
		$keep = static fn( $user, $raw ) => $raw;
		add_filter( 'sanitize_user', $keep, 10, 2 );
		$joerg = wp_insert_user( array( 'user_login' => 'jörg', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'joerg@example.test', 'role' => 'author' ) );
		remove_filter( 'sanitize_user', $keep, 10 );
		self::assertIsInt( $joerg );
		self::assertIsInt( wp_insert_post( array( 'post_title' => 'By jörg', 'post_status' => 'publish', 'post_author' => $joerg ), true ) );
		$a2 = wp_insert_post( array( 'post_title' => 'A2', 'post_status' => 'publish' ), true );
		$wpdb->update( $wpdb->posts, array( 'post_name' => '{"password":"CANARY-ONLY"}' ), array( 'ID' => $a2 ) );
		clean_post_cache( $a2 );
		self::assertIsInt( wp_insert_post( array( 'post_title' => 'Reset', 'post_name' => 'reset-password', 'post_type' => 'page', 'post_status' => 'publish' ), true ) );
		$wpdb->insert( $wpdb->options, array( 'option_name' => 'blacklist_keys', 'option_value' => 'x', 'autoload' => 'off' ) );
		update_option( 'zz_unreadable_name', 'x' );
		// Ordinary names that hold no credential (Codex round 4 of part 3):
		// slugs, Unicode and number slugs, a slug with a character reference,
		// the meta and options of WooCommerce, Elementor and Yoast.
		foreach ( array( 'api-docs', 'token-sale', 'about-us', 'r-d', '中文', '2024' ) as $slug ) {
			self::assertIsInt( wp_insert_post( array( 'post_title' => $slug, 'post_name' => $slug, 'post_type' => 'page', 'post_status' => 'publish' ), true ) );
		}
		$amp = wp_insert_post( array( 'post_title' => 'R&D', 'post_status' => 'publish' ), true );
		$wpdb->update( $wpdb->posts, array( 'post_name' => '&amp;' ), array( 'ID' => $amp ) );
		clean_post_cache( $amp );
		$shop = wp_insert_post( array( 'post_title' => 'Product', 'post_status' => 'publish' ), true );
		foreach ( array( '_yoast_wpseo_title' => 'Title %%sep%% %%sitename%%', '_yoast_wpseo_metadesc' => 'See ' . $link, '_wc_order_attribution_session_count' => '3', '_regular_price' => '19.90', '_elementor_page_settings' => array( 'background_image' => array( 'id' => $post, 'url' => $link ) ) ) as $key => $value ) {
			update_post_meta( $shop, $key, $value );
		}
		update_option( 'woocommerce_api_enabled', 'no' );
		update_option( 'woocommerce_myaccount_orders_endpoint', 'orders' );
		update_option( 'wpseo_titles', array( 'title-page' => '%%title%%', 'breadcrumbs-home' => 'Home', 'page_for_posts' => $post ) );
		update_option( 'elementor_active_kit', (string) $post );
		// A slug and a meta key of bytes that are no UTF-8 (tables that keep
		// bytes, as old sites in latin1 have).
		$wpdb->query( "ALTER TABLE {$wpdb->posts} MODIFY post_name VARBINARY(200) NOT NULL DEFAULT ''" );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_name = UNHEX(%s) WHERE ID = %d", 'c328c328', $bytes ) );
		$wpdb->query( "ALTER TABLE {$wpdb->postmeta} MODIFY meta_key VARBINARY(255) NULL DEFAULT NULL" );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value ) VALUES ( %d, UNHEX(%s), %s )", $bytes, '7a7ae9', (string) $post ) );
		// A row of theme mods named in bytes whose value does not unpack: its name only.
		$wpdb->query( "ALTER TABLE {$wpdb->options} MODIFY option_name VARBINARY(191) NOT NULL DEFAULT ''" );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( UNHEX(%s), %s, 'off' )", bin2hex( "theme_mods_z\xE9" ), 'not an array' ) );
		// A row whose meta could not be read; an option that could not be looked up.
		$break = static function ( $query ) use ( $wpdb, $unread ) {
			return false !== strpos( $query, "FROM {$wpdb->postmeta} WHERE post_id = {$unread} " ) || false !== strpos( $query, "WHERE option_name = 'zz_unreadable_name' LIMIT 1" ) ? 'SELECT broken FROM ab_it_no_such_table' : $query;
		};
		$quiet = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$objects = \AB_MCP_Sync_Inventory::run( \AB_MCP_Sync_Inventory::source( \AB_MCP_IT_Sync_Steps::addresses() ) )['objects'];
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $quiet );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_name = %s WHERE ID = %d", 'bytes', $bytes ) );
			$wpdb->query( "ALTER TABLE {$wpdb->posts} MODIFY post_name VARCHAR(200) NOT NULL DEFAULT ''" );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $bytes ) );
			$wpdb->query( "ALTER TABLE {$wpdb->postmeta} MODIFY meta_key VARCHAR(255) NULL DEFAULT NULL" );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = UNHEX(%s)", bin2hex( "theme_mods_z\xE9" ) ) );
			$wpdb->query( "ALTER TABLE {$wpdb->options} MODIFY option_name VARCHAR(191) NOT NULL DEFAULT ''" );
		}
		$check = new \ReflectionMethod( AB_MCP_Sync_Import::class, 'line_shaped' );
		$named = new \ReflectionMethod( AB_MCP_Sync_Import::class, 'names_of' );
		$code  = new \ReflectionMethod( AB_MCP_Sync_Import::class, 'held_code' );
		$forms = array();
		$held  = array();
		$lines = array();
		$codes = 0;
		$all   = array();
		foreach ( $objects as $object ) {
			// A code of its reason only on a row the takeover never reads (not on B9 of a name the guard refuses, mailserver_pass).
			self::assertSame( isset( \AB_MCP_Sync_Option_Schema::REASON_CODES[ $object['status'] ] ), isset( $object['reason_code'] ), $object['kind'] . ':' . \AB_MCP_Sync_Canon::encode( $object['ref'] ) );
			if ( 'b9' === $object['status'] && 'option:mailserver_pass' === $object['kind'] . ':' . $object['ref'] ) {
				++$codes;
			}
			if ( 'b9' === $object['status'] ) {
				continue;
			}
			$lines[] = AB_MCP_Sync_Export::line( $object );
			$line    = json_decode( \AB_MCP_Sync_Canon::encode( AB_MCP_Sync_Export::line( $object ) ), true );
			self::assertTrue( $check->invoke( null, $line ), \AB_MCP_Sync_Canon::encode( $line ) );
			$ref = $line['kind'] . ':' . \AB_MCP_Sync_Canon::encode( $line['ref'] );
			$why = $code->invoke( null, $line, \AB_MCP_Redactor::extra_keys() );
			if ( null !== $why ) {
				$held[ $ref ] = $why;
			}
			if ( $wc === $line['ref'] ) {
				$forms['a name with backslashes'] = $ref;
			}
			foreach ( $named->invoke( null, $line ) as $name ) {
				$all[] = $name;
				foreach ( array( 'a"b' => 'a quote', 'Vendor\Service' => 'a backslash', '{{WRAPPER}}' => 'braces' ) as $part => $form ) {
					if ( false !== strpos( $name, $part ) ) {
						$forms[ 'a place with ' . $form ] = $ref;
					}
				}
				if ( strlen( $name ) > 4096 ) {
					$forms['a place over 4 KiB'] = $ref;
				}
			}
			if ( array() === $line['identity'] ) {
				$forms['no identity'] = $ref;
			}
			if ( isset( $line['identity']['post_name'] ) && is_array( $line['identity']['post_name'] ) ) {
				$forms['bytes'] = $ref;
			}
			if ( isset( $line['identity']['position'] ) && '-1' === (string) $line['identity']['position'] ) {
				$forms['position -1'] = $ref;
			}
			if ( \AB_MCP_Sync_Export::credential_locked( $line['locks'] ) ) {
				$forms['held by a credential'] = $ref;
			}
			if ( array() !== ( $line['b9'] ?? array() ) ) {
				$forms['units of credentials'] = $ref;
			}
			if ( array() !== ( $line['possible'] ?? array() ) ) {
				$forms['possible'] = $ref;
				foreach ( $line['possible'] as $one ) {
					if ( is_array( $one['at'] ) ) {
						$forms['a place in bytes'] = $ref;
					}
				}
			}
			foreach ( $line['refs'] ?? array() as $one ) {
				if ( 'user' === $one['space'] && null !== $one['login'] ) {
					$forms['user'] = $ref;
				}
				if ( 'user' === $one['space'] && 'jörg' === $one['login'] ) {
					$forms['a login beyond ASCII'] = $ref;
				}
			}
			// The reason of a row the takeover never reads, as a code; where WordPress reads another row, that row.
			$reason = array( $line['status'], $line['reason_code'] ?? null, $line['target'] ?? null );
			if ( array( 'guarded', 'option_guard', null ) === $reason ) {
				$forms['guarded'] = $ref;
			}
			if ( array( 'unreachable', 'read_as_other_row', 'disallowed_keys' ) === $reason ) {
				$forms['unreachable'] = $ref;
			}
			if ( array( 'unreadable', 'lookup_failed', null ) === $reason ) {
				$forms['unreadable'] = $ref;
			}
			if ( 'theme_mod' === $line['kind'] && array( 'name' ) === array_keys( $line['identity'] ) ) {
				$forms['theme mods by name'] = $ref;
				if ( is_array( $line['identity']['name'] ) && "theme_mods_z\xE9" === AB_MCP_Sync_Import::bytes( $line['identity']['name'] ) ) {
					$forms['theme mods by a name in bytes'] = $ref;
				}
			}
			if ( array() !== $line['locks'] && ! \AB_MCP_Sync_Export::credential_locked( $line['locks'] ) ) {
				$forms['other locks'] = $ref;
			}
			$forms[ 'kind ' . $line['kind'] ] = $ref;
		}
		ksort( $forms );
		self::assertSame(
			array( 'a login beyond ASCII', 'a name with backslashes', 'a place in bytes', 'a place over 4 KiB', 'a place with a backslash', 'a place with a quote', 'a place with braces', 'bytes', 'guarded', 'held by a credential', 'kind custom_css', 'kind menu', 'kind menu_item', 'kind option', 'kind post', 'kind term', 'kind theme_mod', 'kind widget_area', 'no identity', 'other locks', 'position -1', 'possible', 'theme mods by a name in bytes', 'theme mods by name', 'units of credentials', 'unreachable', 'unreadable', 'user' ),
			array_keys( $forms ),
			'Each form was read and went through.'
		);
		self::assertSame( 1, $codes, 'mailserver_pass: B9, and the guard refuses it.' );
		// The ordinary names were read, each a name of a line.
		$places = implode( "\n", $all );
		foreach ( array( 'api-docs', 'token-sale', 'about-us', 'r-d', '%e4%b8%ad%e6%96%87', '&amp;', 'woocommerce_api_enabled', 'woocommerce_myaccount_orders_endpoint', 'wpseo_titles', 'elementor_active_kit' ) as $name ) {
			self::assertContains( $name, $all, $name );
		}
		self::assertMatchesRegularExpression( '/^2024(-\d+)?$/m', $places, 'A number slug (WordPress may add a suffix).' );
		self::assertMatchesRegularExpression( '/^meta:_elementor_page_settings\.background_image/m', $places );
		self::assertMatchesRegularExpression( '/^meta:_yoast_wpseo_metadesc/m', $places );
		self::assertMatchesRegularExpression( '/^wpseo_titles|^option_value\.page_for_posts/m', $places );
		self::assertSame( 'guarded', \AB_MCP_Sync_Option_Schema::classify( 'mailserver_pass' )['status'] );
		// Only the slug that holds a credential is held; reset-password is no field of one.
		self::assertSame( array( 'post:"' . $a2 . '"' => 'credential_field' ), $held );
		// The whole package of these lines: taken, complete, that one line held.
		self::pair();
		$state = self::import_relayed( self::package_of( str_repeat( 'c1', 16 ), array( $lines ) ) );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertSame( array( true, 1 ), array( $state['complete'], $state['held'] ) );
		self::assertSame( array( self::held( 'post', (string) $a2, 'credential_field' ) ), self::held_of( str_repeat( 'c1', 16 ) ) );
		// Each table a reader notes when it could not read a row at all.
		$tables = array(
			'posts'              => 'post',
			'postmeta'           => 'post',
			'term_relationships' => 'post',
			'terms'              => 'term',
			'menu_item'          => 'menu_item',
			'theme_mods'         => 'custom_css',
			'options'            => 'theme_mod',
		);
		self::assertSame( array_keys( $tables ), AB_MCP_Sync_Import::ROW_UNREAD );
		foreach ( $tables as $table => $kind ) {
			$line = array(
				'complete' => false,
				'identity' => array(),
				'kind'     => $kind,
				'locks'    => array(),
				'ref'      => '7',
				'status'   => 'z8',
				'unread'   => array( $table ),
			);
			self::assertTrue( $check->invoke( null, $line ), $table );
		}
	}

	public function testARecordOfAnOlderVersionIsDiscardedWithTheWay(): void {
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		self::assertIsArray( AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' ) );
		$package = AB_MCP_Sync_Export::make( 'inventory' );
		self::assertIsArray( $package );
		// A package as the version before kept it: its record without a report.
		$gid = AB_MCP_Protected_Store::open( 'out', array( 'declared' => 1048576 + AB_MCP_Sync_Export::RECORD_MAX ) );
		self::assertIsString( $gid );
		$manifest = json_decode( $package['manifest']['manifest'], true );
		foreach ( $manifest['chunks'] as $i => $chunk ) {
			$data = AB_MCP_Protected_Store::get( 'out', $package['package_id'], AB_MCP_Sync_Export::chunk_name( $i ), $chunk['sha256'] );
			self::assertIsArray( AB_MCP_Protected_Store::put( 'out', $gid, AB_MCP_Sync_Export::chunk_name( $i ), $data ) );
		}
		$signed = AB_MCP_Sync_Manifest::sign( array( 'package_id' => $gid ) + array_intersect_key( $manifest, array_flip( array( 'bytes', 'chunks', 'kinds', 'objects', 'purpose', 'report_hash', 'sha256', 'unpacked' ) ) ) );
		self::assertIsArray( $signed, is_wp_error( $signed ) ? $signed->get_error_message() : '' );
		$record = \AB_MCP_Sync_Canon::encode(
			array(
				'binding'   => AB_MCP_Sync_Export::binding(),
				'cursor'    => null,
				'format'    => AB_MCP_Sync_Export::RECORD,
				'manifest'  => $signed['manifest'],
				'series'    => str_repeat( 'a', 32 ),
				'signature' => $signed['signature'],
			)
		);
		self::assertIsArray( AB_MCP_Protected_Store::put( 'out', $gid, hash( 'sha256', $record ), $record ) );
		$refused = AB_MCP_Sync_Export::again( $gid );
		self::refused( $refused, 'ab_mcp_sync_export_stale' );
		// A report that is not one: the same.
		$other = AB_MCP_Protected_Store::open( 'out', array( 'declared' => 1048576 + AB_MCP_Sync_Export::RECORD_MAX ) );
		$again = AB_MCP_Sync_Manifest::sign( array( 'package_id' => $other ) + array_intersect_key( $manifest, array_flip( array( 'bytes', 'chunks', 'kinds', 'objects', 'purpose', 'report_hash', 'sha256', 'unpacked' ) ) ) );
		$text  = \AB_MCP_Sync_Canon::encode(
			array(
				'binding'   => AB_MCP_Sync_Export::binding(),
				'cursor'    => null,
				'format'    => AB_MCP_Sync_Export::RECORD,
				'manifest'  => $again['manifest'],
				'report'    => array(
					'credentials' => array( 'held' => 0, 'names' => 0, 'units' => '0' ),
					'withheld'    => array( 'count' => 0, 'objects' => array() ),
				),
				'series'    => str_repeat( 'b', 32 ),
				'signature' => $again['signature'],
			)
		);
		self::assertIsArray( AB_MCP_Protected_Store::put( 'out', $other, hash( 'sha256', $text ), $text ) );
		self::refused( AB_MCP_Sync_Export::again( $other ), 'ab_mcp_sync_export_stale' );
		self::assertStringContainsString( 'Make a new one with wp_site_export', $refused->get_error_message() );
		self::assertNotContains( $gid, array_column( AB_MCP_Protected_Store::groups( 'out' ), 'gid' ), 'Discarded.' );
		// The way: a new package.
		self::assertIsArray( AB_MCP_Sync_Export::make( 'inventory' ) );
	}

	public function testACompleteImportWithoutItsListOfHeldObjectsIsFetchedAnew(): void {
		self::pair();
		$package = self::package_of( str_repeat( '8a', 16 ), array( array( self::option_line( 'zz_a' ) ) ) );
		$signed  = self::signed_by_b( $package['package'] );
		$begin   = array(
			'package_id' => str_repeat( '8a', 16 ),
			'manifest'   => $signed,
			'purpose'    => 'inventory',
		);
		self::assertIsArray( AB_MCP_Sync_Import::call( $begin ) );
		// Complete as the version before marked it: the chunk and the mark, no list.
		$gid = self::groups_of( str_repeat( '8a', 16 ) )[0]['gid'];
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Export::chunk_name( 0 ), $package['data'][0] ) );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Import::COMPLETE, $package['package']['sha256'] ) );
		$refused = AB_MCP_Sync_Import::call( array( 'package_id' => str_repeat( '8a', 16 ) ) );
		self::refused( $refused, 'ab_mcp_sync_import_held' );
		self::assertStringContainsString( 'Fetch the package anew', $refused->get_error_message() );
		self::assertSame( array(), self::groups_of( str_repeat( '8a', 16 ) ), 'Removed.' );
		// A list as the version before kept it (kind and ref, no code): the same.
		self::assertIsArray( AB_MCP_Sync_Import::call( $begin ) );
		$gid  = self::groups_of( str_repeat( '8a', 16 ) )[0]['gid'];
		$text = \AB_MCP_Sync_Canon::encode( array( 'format' => 'ab-sync-held/1', 'objects' => array() ) );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Import::held_name( hash( 'sha256', $text ) ), $text ) );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Export::chunk_name( 0 ), $package['data'][0] ) );
		self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Import::COMPLETE, $package['package']['sha256'] ) );
		self::refused( AB_MCP_Sync_Import::call( array( 'package_id' => str_repeat( '8a', 16 ) ) ), 'ab_mcp_sync_import_held' );
		self::assertSame( array(), self::groups_of( str_repeat( '8a', 16 ) ), 'Removed.' );
		// The way: begun anew with the same manifest, it completes.
		self::assertIsArray( AB_MCP_Sync_Import::call( $begin ) );
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => str_repeat( '8a', 16 ), 'chunk' => 0, 'data' => base64_encode( $package['data'][0] ) ) );
		self::assertSame( array( true, 0 ), array( $state['complete'], $state['held'] ) );
	}
}
