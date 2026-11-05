<?php
/**
 * Large inventories in series of packages (Z8, decision 6 and the budgets
 * of decision 8; Codex round 4 of branch 5, B-1): site A, this process, is
 * the staging site; site B, in processes of its own, the production site
 * with the key of A a person confirmed.
 *
 * - An inventory of 12,001 posts and the site's own objects goes through
 *   to its end in packages of at most 4000 objects: more packages than an
 *   account keeps open exports (3), as the packages of one series count as
 *   one.
 * - The production site takes a series of more packages than it keeps open
 *   (5), one after the other: a package checked whole is closed and counts
 *   no more.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Protected_Store;
use AB_MCP_Staging;
use AB_MCP_Store_Budget;
use AB_MCP_Sync_Export;
use AB_MCP_Sync_Key;
use AB_MCP_Sync_Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';

final class SyncPackageSeriesTest extends TestCase {

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

	private static function admin(): int {
		return (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'];
	}

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

	/** A the staging site of B, B the production site of A with A's key confirmed; both with write access. */
	private static function pair(): void {
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		$saved = AB_MCP_Staging::save( 'staging', AB_MCP_IT_Sites::url( 'b' ), 'production', self::admin(), 'form' );
		self::assertIsArray( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
		$code = AB_MCP_Sync_Key::pairing_code();
		self::assertIsString( $code );
		AB_MCP_IT_Sync_Steps::run(
			'b',
			array(
				array( 'op' => 'write_access' ),
				array(
					'op'          => 'staging_save',
					'role'        => 'production',
					'partner_url' => AB_MCP_IT_Sites::url( 'a' ),
					'source'      => 'form',
				),
				array( 'op' => 'anchor_code', 'code' => $code ),
			)
		);
	}

	/** The whole series of A's inventory, at most $objects objects a package. */
	private static function series( ?int $objects = null ): array {
		$packages = array();
		$cursor   = null;
		do {
			$package = AB_MCP_Sync_Export::make( 'inventory', $cursor, $objects );
			self::assertIsArray( $package, ( is_wp_error( $package ) ? $package->get_error_message() : '' ) . ' (package ' . ( count( $packages ) + 1 ) . ')' );
			$packages[] = $package;
			$cursor     = $package['cursor'];
		} while ( null !== $cursor && count( $packages ) < 100 );
		self::assertNull( $cursor, 'The series ends.' );
		return $packages;
	}

	/** B takes a package of A through the relay. */
	private static function import_on_b( array $package ): array {
		$steps = array(
			array(
				'op'   => 'import',
				'args' => array(
					'package_id' => $package['package_id'],
					'manifest'   => $package['manifest'],
					'purpose'    => 'inventory',
				),
				'as'   => 'begin',
			),
		);
		foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
			$chunk   = AB_MCP_Sync_Export::chunk( $package['package_id'], $i );
			self::assertIsArray( $chunk );
			$steps[] = array(
				'op'   => 'import',
				'args' => array(
					'package_id' => $package['package_id'],
					'chunk'      => $i,
					'data'       => $chunk['data'],
				),
				'as'   => 'last',
			);
		}
		return AB_MCP_IT_Sync_Steps::run( 'b', $steps )['last'];
	}

	public function testAnInventoryOfTwelveThousandAndOneObjectsGoesThroughToItsEnd(): void {
		global $wpdb;
		self::pair();
		$now  = gmdate( 'Y-m-d H:i:s' );
		$rows = array();
		for ( $i = 1; $i <= 12001; $i++ ) {
			$rows[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %d, %s, %s, %d)', self::admin(), $now, $now, 'Text ' . $i, 'Post ' . $i, '', 'publish', 'closed', 'closed', '', 'post-' . $i, '', '', $now, $now, '', 0, '', 0, 'post', '', 0 );
			if ( 0 === $i % 1000 || 12001 === $i ) {
				$wpdb->query( "INSERT INTO {$wpdb->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared row by row above.
				self::assertSame( '', $wpdb->last_error );
				$rows = array();
			}
		}
		wp_cache_flush();

		$packages = self::series();
		self::assertGreaterThanOrEqual( 4, count( $packages ), 'More packages than the three open exports of an account.' );
		self::assertSame( AB_MCP_Store_Budget::MAX_EXPORTS, 3 );
		$objects = array_sum( array_column( $packages, 'objects' ) );
		self::assertGreaterThan( 12001, $objects, 'Every post and the objects of the site.' );
		foreach ( array_slice( $packages, 0, -1 ) as $package ) {
			self::assertLessThanOrEqual( 4000 + 100, $package['objects'], 'At most 4000 a package, and what one row gives more.' );
		}
		self::assertCount( 1, array_unique( array_column( AB_MCP_Protected_Store::groups( 'out' ), 'label' ) ), 'One series.' );
		foreach ( $packages as $package ) {
			$state = self::import_on_b( $package );
			self::assertTrue( $state['complete'] );
		}
	}

	public function testTheProductionSiteTakesASeriesOfMorePackagesThanItKeepsOpen(): void {
		self::pair();
		$packages = self::series( 8 );
		self::assertGreaterThan( AB_MCP_Store_Budget::MAX_PACKAGES, count( $packages ), 'More packages than a site keeps open.' );
		foreach ( $packages as $i => $package ) {
			$state = self::import_on_b( $package );
			self::assertIsArray( $state, 'Package ' . ( $i + 1 ) );
			self::assertTrue( $state['complete'], 'Package ' . ( $i + 1 ) . ' checked whole, so it counts as open no more.' );
		}
	}
}
