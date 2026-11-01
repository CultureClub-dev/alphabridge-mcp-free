<?php
/**
 * The budgets of the protected store (AB_MCP_Store_Budget, Z8 decision 8),
 * as far as they are arithmetic: at most five open packages and 512 MiB
 * declared by them on the site, at most three open exports per account, a
 * package opened with its size, the limits read after the count; and the
 * limits of unpacking with their check.
 *
 * That the count holds under the lock with two requests at the same time,
 * and that a booking checks the free space and makes a test write, is
 * tests/integration/ProtectedStoreTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Store_Budget;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use WP_Error;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-protected-store.php';

final class StoreBudgetTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	protected function tearDown(): void {
		foreach ( array( AB_MCP_Store_Budget::FILTER_PACKAGES, AB_MCP_Store_Budget::FILTER_BYTES, AB_MCP_Store_Budget::FILTER_EXPORTS ) as $hook ) {
			unset( $GLOBALS['ab_test_filters'][ $hook ] );
		}
	}

	/** @return array[] Open packages: area, user, declared bytes. */
	private static function packages( array ...$list ): array {
		return array_map(
			static fn( array $p ): array => array(
				'area'     => $p[0],
				'user_id'  => $p[1],
				'declared' => $p[2],
			),
			$list
		);
	}

	public function testTheLimitsAreThoseOfThePlan(): void {
		self::assertSame( 5, AB_MCP_Store_Budget::MAX_PACKAGES );
		self::assertSame( 512 * 1024 * 1024, AB_MCP_Store_Budget::MAX_BYTES );
		self::assertSame( 3, AB_MCP_Store_Budget::MAX_EXPORTS );
		self::assertSame( 1024 * 1024, AB_MCP_Store_Budget::TEST_WRITE );
		self::assertSame(
			array(
				'object_bytes'  => 8 * 1024 * 1024,
				'depth'         => 64,
				'objects'       => 5000,
				'package_bytes' => 256 * 1024 * 1024,
			),
			AB_MCP_Store_Budget::UNPACK
		);
	}

	public function testAPackageNamesItsSize(): void {
		$res = AB_MCP_Store_Budget::package_refusal( array(), 'in', 1, 0 );
		self::assertSame( 'ab_mcp_store_declare', $res->get_error_code() );
		self::assertNull( AB_MCP_Store_Budget::package_refusal( array(), 'in', 1, 1 ) );
	}

	public function testThreeOpenExportsPerAccount(): void {
		$two   = self::packages( array( 'out', 1, 10 ), array( 'out', 1, 10 ), array( 'out', 2, 10 ), array( 'in', 1, 10 ) );
		$three = self::packages( array( 'out', 1, 10 ), array( 'out', 1, 10 ), array( 'out', 1, 10 ) );
		self::assertNull( AB_MCP_Store_Budget::package_refusal( $two, 'out', 1, 10 ), 'Two of this account; the other account\'s and an import do not count.' );
		$res = AB_MCP_Store_Budget::package_refusal( $three, 'out', 1, 10 );
		self::assertSame( 'ab_mcp_store_quota', $res->get_error_code() );
		self::assertSame( 3, $res->get_error_data()['limit'] );
		self::assertNull( AB_MCP_Store_Budget::package_refusal( $three, 'out', 2, 10 ), 'Another account has its own three.' );
		self::assertNull( AB_MCP_Store_Budget::package_refusal( $three, 'in', 1, 10 ), 'An import is no export.' );
		add_filter( AB_MCP_Store_Budget::FILTER_EXPORTS, static fn(): int => 4 );
		self::assertNull( AB_MCP_Store_Budget::package_refusal( $three, 'out', 1, 10 ), 'The filter raises it.' );
	}

	public function testFiveOpenPackagesOnTheSite(): void {
		$four = self::packages( array( 'out', 1, 10 ), array( 'out', 2, 10 ), array( 'in', 3, 10 ), array( 'in', 4, 10 ) );
		self::assertNull( AB_MCP_Store_Budget::package_refusal( $four, 'in', 5, 10 ) );
		$five = array_merge( $four, self::packages( array( 'in', 5, 10 ) ) );
		$res  = AB_MCP_Store_Budget::package_refusal( $five, 'in', 6, 10 );
		self::assertSame( 'ab_mcp_store_quota', $res->get_error_code() );
		self::assertSame( 5, $res->get_error_data()['limit'] );
		self::assertSame( 'ab_mcp_store_quota', AB_MCP_Store_Budget::package_refusal( $five, 'out', 6, 10 )->get_error_code(), 'Exports and imports together.' );
		add_filter( AB_MCP_Store_Budget::FILTER_PACKAGES, static fn(): int => 6 );
		self::assertNull( AB_MCP_Store_Budget::package_refusal( $five, 'in', 6, 10 ) );
	}

	public function testFiveHundredTwelveMebibytesDeclaredOnTheSite(): void {
		$max  = AB_MCP_Store_Budget::MAX_BYTES;
		$open = self::packages( array( 'out', 1, 200 * 1048576 ), array( 'in', 2, 300 * 1048576 ) );
		self::assertNull( AB_MCP_Store_Budget::package_refusal( $open, 'in', 3, $max - 500 * 1048576 ), 'Exactly at the limit.' );
		$res = AB_MCP_Store_Budget::package_refusal( $open, 'in', 3, $max - 500 * 1048576 + 1 );
		self::assertSame( 'ab_mcp_store_quota', $res->get_error_code() );
		self::assertSame( $max, $res->get_error_data()['limit'] );
		self::assertSame( 'ab_mcp_store_quota', AB_MCP_Store_Budget::package_refusal( array(), 'in', 3, $max + 1 )->get_error_code(), 'One package alone.' );
		add_filter( AB_MCP_Store_Budget::FILTER_BYTES, static fn(): int => 2 * 1073741824 );
		self::assertNull( AB_MCP_Store_Budget::package_refusal( $open, 'in', 3, $max ) );
	}

	public function testTheLimitsAreReadAfterTheCount(): void {
		// Under the lock the count comes first, then the limits (so a slow
		// filter widens the window a lock must close: the integration case).
		$seen = array();
		foreach ( array( AB_MCP_Store_Budget::FILTER_EXPORTS, AB_MCP_Store_Budget::FILTER_PACKAGES, AB_MCP_Store_Budget::FILTER_BYTES ) as $hook ) {
			add_filter(
				$hook,
				static function ( $value ) use ( $hook, &$seen ) {
					$seen[] = $hook;
					return $value;
				}
			);
		}
		AB_MCP_Store_Budget::package_refusal( array(), 'out', 1, 10 );
		self::assertSame( array( AB_MCP_Store_Budget::FILTER_EXPORTS, AB_MCP_Store_Budget::FILTER_PACKAGES, AB_MCP_Store_Budget::FILTER_BYTES ), $seen );
		$seen = array();
		AB_MCP_Store_Budget::package_refusal( array(), 'in', 1, 10 );
		self::assertSame( array( AB_MCP_Store_Budget::FILTER_PACKAGES, AB_MCP_Store_Budget::FILTER_BYTES ), $seen, 'The exports only for an export.' );
	}

	public function testEachUnpackLimitHoldsAtItsValueAndRefusesPastIt(): void {
		self::assertNull( AB_MCP_Store_Budget::unpack_refusal( array() ) );
		self::assertNull( AB_MCP_Store_Budget::unpack_refusal( AB_MCP_Store_Budget::UNPACK ), 'Every limit exactly reached.' );
		foreach ( AB_MCP_Store_Budget::UNPACK as $key => $max ) {
			$res = AB_MCP_Store_Budget::unpack_refusal( array( $key => $max + 1 ) );
			self::assertSame( 'ab_mcp_store_unpack_limit', $res->get_error_code(), $key );
			self::assertSame( $key, $res->get_error_data()['limit'] );
			self::assertStringContainsString( AB_MCP_Store_Budget::UNPACK_WHAT[ $key ], $res->get_error_message() );
		}
	}

	public function testEveryRefusalNamesTheWay(): void {
		$way = ( new ReflectionClassConstant( AnswerWayTest::class, 'WAY' ) )->getValue();
		$all = array(
			AB_MCP_Store_Budget::package_refusal( array(), 'out', 1, 0 ),
			AB_MCP_Store_Budget::package_refusal( self::packages( array( 'out', 1, 1 ), array( 'out', 1, 1 ), array( 'out', 1, 1 ) ), 'out', 1, 1 ),
			AB_MCP_Store_Budget::package_refusal( self::packages( array( 'in', 1, 1 ), array( 'in', 1, 1 ), array( 'in', 1, 1 ), array( 'in', 1, 1 ), array( 'in', 1, 1 ) ), 'in', 1, 1 ),
			AB_MCP_Store_Budget::package_refusal( array(), 'in', 1, AB_MCP_Store_Budget::MAX_BYTES + 1 ),
			AB_MCP_Store_Budget::unpack_refusal( array( 'depth' => 65 ) ),
		);
		foreach ( $all as $res ) {
			self::assertInstanceOf( WP_Error::class, $res );
			self::assertMatchesRegularExpression( $way, $res->get_error_message() );
		}
	}
}
