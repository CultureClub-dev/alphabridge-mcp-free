<?php
/**
 * wp_site_diff on two WordPress sites (Z8, decisions 1, 14, 16, 21 and 22 of
 * the plan; branch 6, part 2): site B is the staging site, in processes of
 * its own; site A, this process, is the production site with B's key a
 * person confirmed, takes B's packages over and pairs its objects with them
 * (wp_site_pairs), then reports.
 *
 * - Over MCP: the rows of the table on real rows (equal, changed on
 *   staging, changed here, a conflict, deleted on staging, the trash),
 *   without a pair (only on staging, only here), an unclear pair; the report
 *   lies in the protected store with its report_hash, the answer pages by
 *   its cursor, nothing of either site is written.
 * - The check before: WordPress newer on staging refused with the way of
 *   wp_update_core; a report of another account, of another pair, expired.
 * - The inventory of this site in parts over calls, the same report.
 * - Locked as a whole: a menu with an item removed on staging, a widget area
 *   with an instance removed on staging (the instances of its base kept
 *   with the pair); a new term with meta; the kinds Z8 locks with their
 *   stages; mutual references; a reference to an attachment waits.
 * - Rule 0 and an override on rows: a rest stays a rest, also where staging
 *   changes it, a rest removed here, a confirmed target compared and named.
 * - A name holding a credential never in plain text: also of a pair whose
 *   name this site calls a credential since, on neither side, in the answer
 *   and in every file of the report.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Protected_Store;
use AB_MCP_REST_Controller;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Identity;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Inventory;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Proposal;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Schema;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';

final class SyncReportTest extends TestCase {

	/** The date every page of these tests carries, on both sites. */
	private const DATE = '2026-10-01 10:00:00';

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
		self::assertIsArray( AB_MCP_Sync_Schema::install() );
	}

	protected function tearDown(): void {
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Proposal::$limit  = null;
		AB_MCP_Sync_Report::$clock    = null;
		AB_MCP_Sync_Report::$page     = null;
		AB_MCP_Sync_Inventory::$clock = null;
		AB_MCP_Sync_Identity::$slice  = null;
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
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

	/** Steps on site B. */
	private static function b( array $steps ): array {
		return AB_MCP_IT_Sync_Steps::run( 'b', $steps );
	}

	/** B the staging site of A, A the production site of B with B's key confirmed. */
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
	private static function tool( string $name, array $arguments ) {
		$answer = AB_MCP_IT_Sites::mcp(
			'a',
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

	/** The arguments of a page, the same on both sites but for $over. */
	private static function page( string $name, string $content, array $over = array() ): array {
		return array_merge(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_title'    => ucfirst( $name ),
				'post_name'     => $name,
				'post_content'  => $content,
				'post_author'   => 1,
				'post_date'     => self::DATE,
				'post_date_gmt' => self::DATE,
			),
			$over
		);
	}

	/** Pages on A, in this process. */
	private static function pages_on_a( array $pages ): array {
		$ids = array();
		foreach ( $pages as $name => $content ) {
			$id = wp_insert_post( wp_slash( self::page( $name, $content ) ), true );
			self::assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
			$ids[ $name ] = (string) $id;
		}
		return $ids;
	}

	/** Pages on B. */
	private static function pages_on_b( array $pages ): array {
		$steps = array();
		foreach ( $pages as $name => $content ) {
			$steps[] = array(
				'op'   => 'insert_post',
				'args' => self::page( $name, $content ),
				'as'   => $name,
			);
		}
		return array_map( 'strval', self::b( $steps ) );
	}

	/** A page of B changed, as wp-admin saves it. */
	private static function change_on_b( string $id, string $name, string $content ): void {
		self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => self::page( $name, $content, array( 'ID' => (int) $id ) ),
				),
			)
		);
	}

	/** B packs its inventory and A takes it over: the package ids. */
	private static function staged( array $before = array(), bool $mcp = false ): array {
		// A package belongs to the connection that took it over: over MCP
		// for a report over MCP, in this process for one in this process.
		$import = static fn( array $args ) => $mcp ? self::tool( 'wp_site_import_chunk', $args ) : AB_MCP_Sync_Import::call( $args );
		$ids    = array();
		$cursor = null;
		do {
			$step = array(
				'op'      => 'export',
				'purpose' => 'inventory',
				'as'      => 'package',
			);
			if ( null !== $cursor ) {
				$step['cursor'] = $cursor;
			}
			$package = self::b( array_merge( $before, array( $step ) ) )['package'];
			$cursor  = $package['cursor'];
			$state   = $import(
				array(
					'package_id' => $package['package_id'],
					'manifest'   => $package['manifest'],
					'purpose'    => 'inventory',
				)
			);
			self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : ( is_string( $state ) ? $state : '' ) );
			$steps = array();
			foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
				$steps[] = array(
					'op'         => 'export_chunk',
					'package_id' => $package['package_id'],
					'chunk'      => $i,
					'as'         => 'c' . $i,
				);
			}
			$data = self::b( $steps );
			foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
				$state = $import(
					array(
						'package_id' => $package['package_id'],
						'chunk'      => $i,
						'data'       => $data[ 'c' . $i ]['data'],
					)
				);
				self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : ( is_string( $state ) ? $state : '' ) );
			}
			self::assertTrue( $state['complete'] );
			$ids[] = $package['package_id'];
		} while ( null !== $cursor && count( $ids ) < 50 );
		return $ids;
	}

	/** The definition of a tool of the staging group. */
	private static function def( string $name ): array {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return $r->get( $name );
	}

	/** wp_site_pairs through the gate of the plugin, in this process. */
	private static function pairs( array $a ) {
		return AB_MCP_REST_Controller::run_tool( 'wp_site_pairs', self::def( 'wp_site_pairs' ), $a );
	}

	/** Every entry of these categories of a fresh proposal paired, with its token. */
	private static function paired( array $ids, array $categories = array( 'equal' ) ): void {
		$answer = self::pairs(
			array(
				'action'   => 'propose',
				'packages' => $ids,
			)
		);
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = self::pairs(
				array(
					'action'      => 'propose',
					'proposal_id' => $answer['proposal_id'],
				)
			);
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'categories'  => $categories,
		);
		$preview = self::pairs( $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$done = self::pairs( $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertArrayNotHasKey( 'more_after', $done );
	}

	/** wp_site_diff in this process, worked out to its end and paged: every entry. */
	private static function report( array $ids, int &$calls = 0 ): array {
		$answer = AB_MCP_Sync_Report::call( array( 'packages' => $ids ) );
		$calls  = 1;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
			++$calls;
			self::assertLessThan( 300, $calls );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$all   = $answer['entries'];
		$page  = $answer;
		$pages = 0;
		while ( null !== $page['cursor'] ) {
			$page = AB_MCP_Sync_Report::call(
				array(
					'report_id' => $answer['report_id'],
					'cursor'    => $page['cursor'],
				)
			);
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
			// A cursor that does not move on would page for ever; the test
			// fails instead (mutation bericht/rep-page-after, CI 06.11.2026).
			++$pages;
			self::assertLessThan( 300, $pages, 'The cursor moves on.' );
		}
		$answer['all'] = $all;
		return $answer;
	}

	/** The entry of a post by its slug, on either side. */
	private static function post_entry( array $entries, string $name ): array {
		foreach ( $entries as $entry ) {
			foreach ( array( 'staging', 'live' ) as $side ) {
				if ( 'post' === $entry['kind'] && isset( $entry[ $side ]['identity']['post_name'] ) && in_array( $entry[ $side ]['identity']['post_name'], array( $name, $name . '__trashed' ), true ) ) {
					return $entry;
				}
			}
		}
		self::fail( "No entry for $name." );
	}

	/** The entry of an object by its kind and ref on a side. */
	private static function entry_of( array $entries, string $kind, string $side, string $ref ): array {
		foreach ( $entries as $entry ) {
			if ( $kind === $entry['kind'] && isset( $entry[ $side ]['ref'] ) && $ref === (string) $entry[ $side ]['ref'] ) {
				return $entry;
			}
		}
		self::fail( "No entry for $kind $ref on $side." );
	}

	private static function keys( array $entry ): array {
		return array_column( $entry['locks'] ?? array(), 'key' );
	}

	private static function refused( $result, string $code ): string {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result->get_error_message();
	}

	/**
	 * What of A a report must not change: every row of its posts, terms,
	 * options and pairs; not the records an MCP call writes (the options of
	 * this plugin, transients).
	 */
	private static function rows_of_a(): string {
		global $wpdb;
		$out = array();
		foreach ( array( $wpdb->posts => 'ID', $wpdb->postmeta => 'meta_id', $wpdb->terms => 'term_id', $wpdb->term_taxonomy => 'term_taxonomy_id', $wpdb->termmeta => 'meta_id', AB_MCP_Sync_Schema::table( 'pairs' ) => 'id' ) as $table => $key ) {
			$out[ $table ] = $wpdb->get_results( "SELECT * FROM $table ORDER BY $key", ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		$out['options'] = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND option_name NOT LIKE 'ab\\_mcp\\_%' AND option_name NOT IN ( 'cron' ) ORDER BY option_name", ARRAY_A ); // phpcs:ignore WordPress.DB
		return hash( 'sha256', (string) json_encode( $out ) );
	}

	/* ---------------------------------------------------------------- MCP */

	public function testAReportOverMcpDecidesEachObjectAndWritesNothingButTheReport(): void {
		self::pair();
		$a = self::pages_on_a(
			array(
				'about'    => 'About us.',
				'contact'  => 'Write to us.',
				'team'     => 'Our team.',
				'prices'   => 'Our prices.',
				'old'      => 'Old page.',
				'trashed'  => 'A page.',
				'differs'  => 'Here.',
				'live-new' => 'Only here.',
			)
		);
		$b = self::pages_on_b(
			array(
				'about'   => 'About us.',
				'contact' => 'Write to us.',
				'team'    => 'Our team.',
				'prices'  => 'Our prices.',
				'old'     => 'Old page.',
				'trashed' => 'A page.',
				'differs' => 'There.',
			)
		);
		self::paired( self::staged(), array( 'equal', 'differs' ) );
		// After the pairing: staging changes contact and prices, deletes old,
		// trashes trashed and makes a new page; this site changes team and
		// prices.
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::change_on_b( $b['prices'], 'prices', 'Prices of staging.' );
		self::b(
			array(
				array( 'op' => 'delete_post', 'post' => (int) $b['old'] ),
				array( 'op' => 'trash_post', 'post' => (int) $b['trashed'] ),
				array( 'op' => 'insert_post', 'args' => self::page( 'staging-new', 'Only on staging.' ) ),
			)
		);
		wp_update_post( wp_slash( self::page( 'team', 'Our new team.', array( 'ID' => (int) $a['team'] ) ) ) );
		wp_update_post( wp_slash( self::page( 'prices', 'Prices of this site.', array( 'ID' => (int) $a['prices'] ) ) ) );
		$ids    = self::staged( array(), true );
		$before = self::rows_of_a();

		$answer = self::tool( 'wp_site_diff', array( 'packages' => $ids ) );
		self::assertIsArray( $answer, is_string( $answer ) ? $answer : '' );
		self::assertTrue( $answer['done'] );
		self::assertNull( $answer['cursor'], 'A small site in one page.' );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}\z/', $answer['report_hash'] );
		self::assertSame( array( 'live' => $GLOBALS['wp_version'], 'staging' => $GLOBALS['wp_version'] ), $answer['wordpress'] );
		self::assertSame( array(), $answer['staging']['incomplete'] );
		$rows = array(
			'about'       => array( 'equal', '3', 1, 'none' ),
			'contact'     => array( 'changed_on_staging', '3', 2, 'take_over' ),
			'old'         => array( 'deleted_on_staging', '3', 3, 'locked' ),
			'trashed'     => array( 'deleted_on_staging', '3', 3, 'locked' ),
			'team'        => array( 'changed_live', '3', 4, 'keep' ),
			'prices'      => array( 'conflict', '3', 5, 'choose' ),
			'differs'     => array( 'unclear', '1', null, 'choose' ),
			'staging-new' => array( 'only_staging', '4', 1, 'create' ),
			'live-new'    => array( 'only_live', '4', 2, 'keep' ),
		);
		foreach ( $rows as $name => $want ) {
			$entry = self::post_entry( $answer['entries'], $name );
			self::assertSame( $want, array( $entry['result'], $entry['rule'], $entry['row'], $entry['action'] ), $name . ': ' . json_encode( $entry ) );
		}
		self::assertSame( array( '*:delete' ), self::keys( self::post_entry( $answer['entries'], 'old' ) ) );
		self::assertSame( 'Z8d', self::post_entry( $answer['entries'], 'old' )['locks'][0]['stage'] );
		self::assertSame( 'trash', self::post_entry( $answer['entries'], 'trashed' )['staging']['post_status'], 'The trash is a deletion, no status stands in for it.' );
		self::assertStringNotContainsString( '"hash"', (string) json_encode( $answer ), 'No hash in an answer.' );
		self::assertSame( $before, self::rows_of_a(), 'A report writes nothing of the site.' );

		// It lies in the protected store, with its hash.
		$groups = array_values( array_filter( AB_MCP_Protected_Store::groups( 'reports' ), static fn( $g ) => 'report' === $g['label'] ) );
		self::assertSame( array( $answer['report_id'] ), array_column( $groups, 'gid' ) );
		$again = self::tool( 'wp_site_diff', array( 'report_id' => $answer['report_id'] ) );
		self::assertIsArray( $again );
		self::assertSame( array( $answer['report_hash'], $answer['entries'] ), array( $again['report_hash'], $again['entries'] ), 'Paged from the store, the same report.' );

		// Paged by its cursor; in this process from packages taken over in
		// this process (a package belongs to its connection).
		AB_MCP_Sync_Report::$page = 3;
		self::assertStringContainsString( 'this connection', self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $ids ) ), 'ab_mcp_sync_import_unknown' ) );
		$here                     = self::report( self::staged() );
		self::assertSame( $answer['total'], count( $here['all'] ) );
		self::assertSame( range( 1, $answer['total'] ), array_column( $here['all'], 'n' ) );
		self::assertSame( 3, $here['cursor'] );
		self::assertNotSame( $answer['report_hash'], '', 'The report of this process is its own.' );
	}

	public function testWordPressNewerOnStagingIsRefusedWithTheWayOfAnUpdate(): void {
		self::pair();
		$ids = self::staged( array( array( 'op' => 'wp_version', 'version' => '9.9' ) ) );
		$msg = self::refused( AB_MCP_Sync_Report::call( array( 'packages' => $ids ) ), 'ab_mcp_sync_report_wordpress' );
		self::assertStringContainsString( 'WordPress on the staging site (9.9) is newer than on this site', $msg );
		self::assertStringContainsString( 'wp_update_core', $msg );
		self::assertStringContainsString( 'Direct link:', $msg );
		self::assertSame( array(), array_filter( AB_MCP_Protected_Store::groups( 'reports' ), static fn( $g ) => 'report' === $g['label'] ), 'No report begun.' );
		$older = self::staged( array( array( 'op' => 'wp_version', 'version' => '6.9' ) ) );
		self::assertTrue( self::report( $older )['done'], 'An older WordPress on staging is reported.' );
	}

	public function testTheCheckBeforeAndAReportOfAnotherAccountPairOrDay(): void {
		$msg = self::refused( AB_MCP_Sync_Report::call( array( 'packages' => array( str_repeat( 'a', 32 ) ) ) ), 'ab_mcp_sync_report_pair' );
		self::assertStringContainsString( 'page=alphabridge-staging#ab-staging-partner', $msg );
		self::pair();
		$ids    = self::staged();
		$answer = self::report( $ids );
		$other  = wp_insert_user(
			array(
				'user_login' => 'second',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'second@example.invalid',
				'role'       => 'administrator',
			)
		);
		self::assertIsInt( $other );
		wp_set_current_user( $other );
		self::refused( AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) ), 'ab_mcp_sync_report_foreign' );
		wp_set_current_user( self::admin() );
		AB_MCP_Sync_Report::$clock = static fn(): int => (int) $answer['expires'] - 1;
		self::assertIsArray( AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) ), 'Before its expiry.' );
		AB_MCP_Sync_Report::$clock = static fn(): int => (int) $answer['expires'];
		self::refused( AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) ), 'ab_mcp_sync_report_expired' );
		AB_MCP_Sync_Report::$clock = null;
		self::assertIsArray( AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) ) );
		// A new key of the staging site, confirmed here: the print of the pair changed.
		$code = self::b( array( array( 'op' => 'key_replace' ), array( 'op' => 'pairing_code', 'as' => 'code' ) ) )['code'];
		$prep = AB_MCP_Sync_Anchor::prepare_from_code( $code, self::admin() );
		self::assertIsArray( $prep );
		self::assertIsArray( AB_MCP_Sync_Anchor::confirm( $prep['fingerprint'], self::admin() ) );
		self::refused( AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) ), 'ab_mcp_sync_report_stale' );
	}

	public function testTheInventoryOfThisSiteIsReadInPartsAndGivesTheSameReport(): void {
		self::pair();
		self::pages_on_a( array( 'about' => 'About us.', 'live-new' => 'x' ) );
		self::pages_on_b( array( 'about' => 'About us.', 'staging-new' => 'y' ) );
		$ids   = self::staged();
		$calls = 0;
		$whole = self::report( $ids, $calls );
		self::assertSame( 1, $calls );
		AB_MCP_Sync_Proposal::$limit = 3;
		$parts                       = self::report( $ids, $calls );
		self::assertGreaterThan( 3, $calls, 'Three objects a call.' );
		$strip = static fn( array $entries ): array => array_map( static fn( $e ) => array( $e['n'], $e['result'], $e['action'], $e['staging']['ref'] ?? null, $e['live']['ref'] ?? null ), $entries );
		self::assertSame( $strip( $whole['all'] ), $strip( $parts['all'] ), 'The same report over calls.' );
		self::assertSame( $whole['live']['objects'], $parts['live']['objects'] );
		self::assertSame( $whole['counts'], $parts['counts'] );
		self::refused( AB_MCP_Sync_Report::call( array( 'report_id' => $whole['report_id'] ) ), 'ab_mcp_sync_report_unknown' );
	}

	/* ----------------------------------------------------- locked as a whole */

	public function testAMenuWithAnItemRemovedOnStagingIsLockedAsAWhole(): void {
		self::pair();
		$a        = self::pages_on_a( array( 'about' => 'x', 'contact' => 'y' ) );
		$a_main   = (int) wp_create_nav_menu( 'Main' );
		$a_items  = array();
		foreach ( array( 'about', 'contact' ) as $i => $name ) {
			$a_items[ $name ] = (int) wp_update_nav_menu_item( $a_main, 0, array( 'menu-item-object-id' => (int) $a[ $name ], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => $i + 1 ) );
		}
		$a_footer = (int) wp_create_nav_menu( 'Footer' );
		$a_foot   = (int) wp_update_nav_menu_item( $a_footer, 0, array( 'menu-item-object-id' => (int) $a['about'], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) );
		$b        = self::pages_on_b( array( 'about' => 'x', 'contact' => 'y' ) );
		$bm       = self::b(
			array(
				array( 'op' => 'nav_menu', 'name' => 'Main', 'as' => 'main' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$main', 'args' => array( 'menu-item-object-id' => (int) $b['about'], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ), 'as' => 'about' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$main', 'args' => array( 'menu-item-object-id' => (int) $b['contact'], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 2 ), 'as' => 'contact' ),
				array( 'op' => 'nav_menu', 'name' => 'Footer', 'as' => 'footer' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$footer', 'args' => array( 'menu-item-object-id' => (int) $b['about'], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ), 'as' => 'foot' ),
			)
		);
		self::paired( self::staged() );
		// Staging removes the item of contact and adds another one for about.
		self::b(
			array(
				array( 'op' => 'delete_post', 'post' => (int) $bm['contact'] ),
				array( 'op' => 'nav_menu_item', 'menu' => (int) $bm['main'], 'args' => array( 'menu-item-object-id' => (int) $b['about'], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1, 'menu-item-title' => 'Who we are' ) ),
			)
		);
		$answer  = self::report( self::staged() );
		$removed = self::entry_of( $answer['all'], 'menu_item', 'live', (string) $a_items['contact'] );
		self::assertSame( array( 'deleted_on_staging', 'locked' ), array( $removed['result'], $removed['action'] ) );
		self::assertContains( '*:delete', self::keys( $removed ) );
		$menu = self::entry_of( $answer['all'], 'menu', 'live', (string) $a_main );
		self::assertSame( array( 'menu:remove_item' ), self::keys( $menu ), 'The menu as a whole (its term unchanged: nothing to take over of it anyway).' );
		self::assertSame( 'Z8d', $menu['locks'][0]['stage'] );
		$taken = array_filter( $answer['all'], static fn( $e ) => in_array( $e['kind'], array( 'menu', 'menu_item' ), true ) && in_array( 'menu:remove_item', self::keys( $e ), true ) && in_array( $e['action'], array( 'take_over', 'create', 'choose' ), true ) );
		self::assertSame( array(), $taken, 'Nothing of the menu is taken over.' );
		self::assertContains( 'menu:remove_item', self::keys( self::entry_of( $answer['all'], 'menu_item', 'live', (string) $a_items['about'] ) ) );
		$new = array_values( array_filter( $answer['all'], static fn( $e ) => 'menu_item' === $e['kind'] && 'only_staging' === $e['result'] ) );
		self::assertCount( 1, $new, 'The item added on staging.' );
		self::assertSame( 'locked', $new[0]['action'] );
		$foot = self::entry_of( $answer['all'], 'menu_item', 'live', (string) $a_foot );
		self::assertArrayNotHasKey( 'locks', $foot, 'Another menu stays as it is.' );
		self::assertArrayNotHasKey( 'locks', self::entry_of( $answer['all'], 'menu', 'live', (string) $a_footer ) );
	}

	public function testAWidgetAreaWithAnInstanceRemovedOnStagingIsLockedAsAWhole(): void {
		self::pair();
		$widgets = array(
			2 => array( 'title' => 'Hello', 'text' => 'Welcome.', 'filter' => false ),
			3 => array( 'title' => 'Hours', 'text' => 'Mon to Fri.', 'filter' => false ),
			'_multiwidget' => 1,
		);
		$areas   = array(
			'wp_inactive_widgets' => array(),
			'sidebar-1'           => array( 'text-2', 'text-3' ),
			'sidebar-2'           => array(),
			'array_version'       => 3,
		);
		update_option( 'widget_text', $widgets );
		update_option( 'sidebars_widgets', $areas );
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $widgets ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas ),
			)
		);
		self::paired( self::staged() );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'widget_area', 'sidebar-1' );
		self::assertIsArray( $pair );
		self::assertSame( array( 'instances' => array( 'live' => array( 'text-2', 'text-3' ), 'staging' => array( 'text-2', 'text-3' ) ) ), $pair['base_extra'], 'The confirmation keeps the instances of both sides with the bases.' );

		// Staging removes text-3.
		self::b( array( array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => array( 'sidebar-1' => array( 'text-2' ) ) + $areas ) ) );
		$entry = self::entry_of( self::report( self::staged() )['all'], 'widget_area', 'live', 'sidebar-1' );
		self::assertSame( array( 'changed_on_staging', 'locked', array( 'widget_area:remove_instance' ) ), array( $entry['result'], $entry['action'], self::keys( $entry ) ) );
		self::assertSame( array( 'text-2' ), $entry['staging']['instances'] );

		// Staging adds one instead: taken over.
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => array( 4 => array( 'title' => 'New', 'text' => 'More.', 'filter' => false ) ) + $widgets ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => array( 'sidebar-1' => array( 'text-2', 'text-3', 'text-4' ) ) + $areas ),
			)
		);
		$entry = self::entry_of( self::report( self::staged() )['all'], 'widget_area', 'live', 'sidebar-1' );
		self::assertSame( array( 'changed_on_staging', 'take_over' ), array( $entry['result'], $entry['action'] ) );
	}

	public function testANewTermWithMetaIsLockedAndOneWithoutIsCreated(): void {
		self::pair();
		self::b(
			array(
				array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'news' ),
				array( 'op' => 'insert_term', 'name' => 'Featured', 'taxonomy' => 'category', 'as' => 'featured' ),
				array( 'op' => 'add_term_meta', 'term' => '$featured', 'key' => 'color', 'value' => 'red' ),
			)
		);
		$answer = self::report( self::staged() );
		$terms  = array();
		foreach ( $answer['all'] as $entry ) {
			if ( 'term' === $entry['kind'] && isset( $entry['staging']['identity']['slug'] ) ) {
				$terms[ $entry['staging']['identity']['slug'] ] = $entry;
			}
		}
		self::assertSame( array( 'only_staging', 'create' ), array( $terms['news']['result'], $terms['news']['action'] ) );
		self::assertSame( array( 'only_staging', 'locked', array( 'term:create_with_meta' ) ), array( $terms['featured']['result'], $terms['featured']['action'], self::keys( $terms['featured'] ) ) );
	}

	public function testTheKindsZ8LocksAreShownWithTheirReasonAndStage(): void {
		self::pair();
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'woocommerce_currency', 'value' => 'CHF' ),
				// A draft of the Customizer (WordPress lets no account edit a
				// scheduled one, so the export holds that back, decision 9).
				array( 'op' => 'insert_post', 'args' => array( 'post_type' => 'customize_changeset', 'post_status' => 'draft', 'post_name' => 'f2a6b3a4-1c8d-4b8a-9a3e-1d2c3b4a5f6e', 'post_title' => '', 'post_content' => '{}' ) ),
				// A theme mod, the menu positions and the additional CSS.
				array( 'op' => 'menu_css_mods', 'as' => 'mods' ),
				array( 'op' => 'insert_post', 'args' => array( 'post_type' => 'product', 'post_status' => 'publish', 'post_name' => 'shirt', 'post_title' => 'Shirt', 'post_content' => 'Cotton.' ) ),
			)
		);
		$answer  = self::report( self::staged() );
		$blocked = array();
		foreach ( $answer['blocked'] as $lock ) {
			$blocked[ $lock['key'] ] = $lock['stage'];
		}
		foreach ( array( 'option:unclassified' => 'Z8d', 'post_type:*' => 'Z8d', 'theme_mod:*' => 'Z8d', 'custom_css:create' => 'Z8d' ) as $key => $stage ) {
			self::assertSame( $stage, $blocked[ $key ] ?? null, $key );
		}
		// The changeset is held back by the export, its subtype not whole, and named locked there.
		$changeset = array_values( array_filter( $answer['staging']['incomplete'], static fn( $i ) => 'customize_changeset' === $i['subtype'] ) );
		self::assertCount( 1, $changeset );
		self::assertSame( array( 'post_type:customize_changeset', 'Z8d' ), array( $changeset[0]['lock']['key'], $changeset[0]['lock']['stage'] ) );
		$option = self::entry_of( $answer['all'], 'option', 'staging', 'woocommerce_currency' );
		self::assertSame( array( 'only_staging', 'locked', array( 'option:unclassified' ) ), array( $option['result'], $option['action'], self::keys( $option ) ) );
		self::assertNotSame( '', $option['locks'][0]['reason'] );
		$product = self::post_entry( $answer['all'], 'shirt' );
		self::assertSame( array( 'locked', array( 'post_type:*' ) ), array( $product['action'], self::keys( $product ) ) );
	}

	/* ------------------------------------------------------------- graph */

	public function testReferencesAnObjectNeedsAreFollowed(): void {
		self::pair();
		$b = self::pages_on_b( array( 'parent' => 'The parent.', 'one' => 'x', 'two' => 'y' ) );
		self::b(
			array(
				array( 'op' => 'insert_post', 'args' => self::page( 'child', 'The child.', array( 'post_parent' => (int) $b['parent'] ) ) ),
				// Two pages that link to each other by their ids.
				array( 'op' => 'insert_post', 'args' => self::page( 'one', '<a href="' . AB_MCP_IT_Sites::url( 'b' ) . '/?page_id=' . $b['two'] . '">two</a>', array( 'ID' => (int) $b['one'] ) ) ),
				array( 'op' => 'insert_post', 'args' => self::page( 'two', '<a href="' . AB_MCP_IT_Sites::url( 'b' ) . '/?page_id=' . $b['one'] . '">one</a>', array( 'ID' => (int) $b['two'] ) ) ),
				// A page with a featured image whose attachment has no file:
				// the attachment is locked (since branch 10 the inventory reads
				// it, attachment:unsafe_path), the page waits for it.
				array( 'op' => 'insert_post', 'args' => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_name' => 'bild', 'post_title' => 'Bild', 'post_mime_type' => 'image/jpeg' ), 'as' => 'image' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'with-image', 'x' ), 'as' => 'with_image' ),
				array( 'op' => 'add_meta', 'post' => '$with_image', 'key' => '_thumbnail_id', 'value' => '{$image}' ),
			)
		);
		$answer = self::report( self::staged() );
		self::assertSame( 'create', self::post_entry( $answer['all'], 'parent' )['action'] );
		self::assertSame( 'create', self::post_entry( $answer['all'], 'child' )['action'], 'Its parent is created first.' );
		foreach ( array( 'one', 'two' ) as $name ) {
			$entry = self::post_entry( $answer['all'], $name );
			self::assertSame( array( 'locked', array( '*:mutual_references' ) ), array( $entry['action'], self::keys( $entry ) ), $name );
			self::assertSame( 'Z11c', $entry['locks'][0]['stage'] );
		}
		$image = self::post_entry( $answer['all'], 'with-image' );
		self::assertSame( 'waits', $image['action'] );
		self::assertStringContainsString( 'attachment', $image['waits_for'][0]['why'] );
		self::assertStringContainsString( 'held back', $image['waits_for'][0]['why'], 'Its attachment is created in the same run but locked.' );
	}

	/* ------------------------------------------------- rule 0 and override */

	public function testARestStaysARestAndAConfirmedTargetIsComparedAndNamed(): void {
		self::pair();
		$a = self::pages_on_a( array( 'about' => 'About us.', 'draft' => 'A page.' ) );
		$b = self::pages_on_b( array( 'about' => 'About us.', 'draft' => 'A page.' ) );
		self::paired( self::staged() );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		// about: a rest of a run. draft: taken over with status draft.
		$rest = AB_MCP_Sync_Pairs::find_live( $source, 'post', $a['about'] );
		self::assertIsArray( AB_MCP_Sync_Pairs::set_state( (int) $rest['id'], 'residual', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $rest['id'] ) ) ) );
		wp_update_post( wp_slash( self::page( 'draft', 'A page.', array( 'ID' => (int) $a['draft'], 'post_status' => 'draft' ) ) ) );
		$draft = AB_MCP_Sync_Pairs::find_live( $source, 'post', $a['draft'] );
		$now   = AB_MCP_Sync_Proposal::live_now( array( array( 'kind' => 'post', 'ref' => $a['draft'] ) ) )[ "post\0" . $a['draft'] ];
		self::assertIsArray( AB_MCP_Sync_Pairs::rebase( (int) $draft['id'], array( 'canon' => 'canon/1', 'base_live' => $now, 'base_staging' => $draft['base_staging'], 'base_origin' => 'push', 'override' => array( 'post_status' => 'draft' ) ), AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $draft['id'] ) ) ) );
		self::change_on_b( $b['about'], 'about', 'Changed on staging.' );
		$answer = self::report( self::staged() );
		$entry  = self::post_entry( $answer['all'], 'about' );
		self::assertSame( array( 'residual', '0', 'none' ), array( $entry['result'], $entry['rule'], $entry['action'] ), 'Also where staging changes it.' );
		$entry = self::post_entry( $answer['all'], 'draft' );
		self::assertSame( array( 'equal', 'none' ), array( $entry['result'], $entry['action'] ) );
		self::assertSame( 'Compared against the confirmed target: taken over with post_status draft.', $entry['note'] );
		// The rest removed here by hand.
		wp_delete_post( (int) $a['about'], true );
		$entry = self::entry_of( self::report( self::staged() )['all'], 'post', 'live', $a['about'] );
		self::assertSame( array( 'residual_removed', '0' ), array( $entry['result'], $entry['rule'] ) );
	}

	/** Put a file into the group of a report, named as a report names its files. */
	private static function put_file( string $gid, string $prefix, ?int $seq, array $data ): void {
		$text = \AB_MCP_Sync_Canon::encode( $data );
		$put  = AB_MCP_Protected_Store::put( 'reports', $gid, AB_MCP_Sync_Proposal::name( $prefix, $seq, hash( 'sha256', $text ) ), $text );
		self::assertIsArray( $put, is_wp_error( $put ) ? $put->get_error_message() : '' );
	}

	/** A group of the area reports with a head of its own, as a report of this connection would have it. */
	private static function head( array $over, string $label = 'report' ): string {
		$gid = AB_MCP_Protected_Store::open( 'reports', array( 'label' => $label ) );
		self::assertIsString( $gid );
		self::put_file(
			$gid,
			'h',
			null,
			array_merge(
				array(
					'canon'      => 'canon/1',
					'connection' => AB_MCP_Sync_Proposal::connection(),
					'created'    => time(),
					'expires'    => time() + 3600,
					'format'     => AB_MCP_Sync_Report::FORMAT,
					'packages'   => array( str_repeat( 'a', 32 ) ),
					'print'      => (string) AB_MCP_Sync_Anchor::pair_print(),
					'source_id'  => AB_MCP_Sync_Anchor::active()['source_id'],
					'wordpress'  => array( 'live' => '7.1.3', 'staging' => '7.1.3' ),
				),
				$over
			)
		);
		return $gid;
	}

	public function testAReportOfAnotherCanonSourceFormatOrLabelIsNone(): void {
		self::pair();
		foreach ( array( 'canon' => 'canon/9', 'source_id' => 'another-source', 'print' => hash( 'sha256', 'another pair' ) ) as $field => $value ) {
			self::refused( AB_MCP_Sync_Report::call( array( 'report_id' => self::head( array( $field => $value ) ) ) ), 'ab_mcp_sync_report_stale' );
		}
		self::assertNotSame( 'ab_mcp_sync_report_stale', AB_MCP_Sync_Report::call( array( 'report_id' => self::head( array() ) ) )->get_error_code(), 'The same head as it should be is no stale one.' );
		self::refused( AB_MCP_Sync_Report::call( array( 'report_id' => self::head( array( 'format' => AB_MCP_Sync_Proposal::FORMAT ) ) ) ), 'ab_mcp_sync_report_unknown' );
		self::refused( AB_MCP_Sync_Report::call( array( 'report_id' => self::head( array(), 'proposal' ) ) ), 'ab_mcp_sync_report_unknown' );
		self::refused( AB_MCP_Sync_Report::call( array( 'report_id' => self::head( array( 'connection' => hash( 'sha256', 'x' ) ) ) ) ), 'ab_mcp_sync_report_foreign' );
	}

	public function testAReportWhoseWorkingOutFailedIsWorkedOutByTheNextCall(): void {
		self::pair();
		self::pages_on_a( array( 'about' => 'About us.', 'live-new' => 'x' ) );
		self::pages_on_b( array( 'about' => 'About us.' ) );
		$ids   = self::staged();
		$whole = self::report( $ids );
		$table = AB_MCP_Sync_Schema::table( 'pairs' );
		$fail  = true;
		add_filter(
			'query',
			static function ( $sql ) use ( $table, &$fail ) {
				if ( $fail && false !== strpos( (string) $sql, 'FROM ' . $table ) ) {
					$fail = false;
					return 'SELECT broken FROM nowhere_at_all';
				}
				return $sql;
			}
		);
		$failed = AB_MCP_Sync_Report::call( array( 'packages' => $ids ) );
		remove_all_filters( 'query' );
		self::refused( $failed, 'ab_mcp_staging_db_error' );
		$groups = array_values( array_filter( AB_MCP_Protected_Store::groups( 'reports' ), static fn( $g ) => 'report' === $g['label'] && $whole['report_id'] !== $g['gid'] ) );
		self::assertCount( 1, $groups );
		$again = AB_MCP_Sync_Report::call( array( 'report_id' => $groups[0]['gid'] ) );
		self::assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_message() : '' );
		self::assertTrue( $again['done'] );
		$strip = static fn( array $entries ): array => array_map( static fn( $e ) => array( $e['result'], $e['staging']['ref'] ?? null, $e['live']['ref'] ?? null ), $entries );
		self::assertSame( $strip( $whole['entries'] ), $strip( $again['entries'] ), 'Its inventory was not read a second time.' );
		self::assertSame( $whole['live']['objects'], $again['live']['objects'] );
	}

	public function testAnObjectOfAPairWhoseNameHoldsACredentialHereSinceIsNotDeleted(): void {
		self::pair();
		update_option( 'my_setting', 'on' );
		self::b( array( array( 'op' => 'update_option', 'name' => 'my_setting', 'value' => 'on' ) ) );
		$ids = self::staged();
		self::paired( $ids );
		self::assertIsArray( AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'option', 'my_setting' ) );
		$held = array(
			'held'       => 'credential_name',
			'ref_sha256' => hash( 'sha256', '"my_setting"' ),
		);
		// Neither side by its name (Codex round 1 of part 2, A): not in the
		// answer, not in any file of the report in the protected store.
		$check = static function ( array $answer, string $case ) use ( $held ): void {
			$entries = array_values( array_filter( $answer['all'], static fn( $e ) => 'option' === $e['kind'] && $held === $e['live'] ) );
			self::assertCount( 1, $entries, $case );
			self::assertSame( $held, $entries[0]['staging'], $case . ': the staging side as well, by the SHA-256 of its ref' );
			self::assertSame( array( 'not_compared', 'not_compared', 'none' ), array( $entries[0]['result'], $entries[0]['sides']['live'], $entries[0]['action'] ), $case . ': not deleted, counted here by the SHA-256 of its ref' );
			self::assertStringNotContainsString( 'my_setting', (string) json_encode( $answer ), $case );
			$text = '';
			foreach ( array_keys( AB_MCP_Protected_Store::files( 'reports', $answer['report_id'] ) ) as $name ) {
				$parsed = AB_MCP_Sync_Proposal::parse_name( (string) $name );
				self::assertNotNull( $parsed, (string) $name );
				$text .= (string) AB_MCP_Protected_Store::get( 'reports', $answer['report_id'], $parsed['name'], $parsed['sha'] );
			}
			self::assertStringContainsString( $held['ref_sha256'], $text, $case );
			self::assertStringNotContainsString( 'my_setting', $text, $case . ': the stored report' );
		};
		// The rules of this site call the name a credential from now on.
		add_filter( 'ab_mcp_redact_keys', static fn( $keys ) => array_merge( (array) $keys, array( 'my_setting' ) ) );
		try {
			$check( self::report( $ids ), 'its staging line there' );
			// Then removed on staging: the staging side by the ref of its pair.
			self::b( array( array( 'op' => 'delete_option', 'name' => 'my_setting' ) ) );
			$check( self::report( self::staged() ), 'its staging line gone' );
		} finally {
			remove_all_filters( 'ab_mcp_redact_keys' );
		}
	}

	public function testANewAlignmentOfAWidgetAreaKeepsWhatItsBaseExtraHolds(): void {
		self::pair();
		$widgets = array(
			2              => array( 'title' => 'Hello', 'text' => 'Welcome.', 'filter' => false ),
			'_multiwidget' => 1,
		);
		$areas   = array(
			'wp_inactive_widgets' => array(),
			'sidebar-1'           => array( 'text-2' ),
			'array_version'       => 3,
		);
		update_option( 'widget_text', $widgets );
		update_option( 'sidebars_widgets', $areas );
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $widgets ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas ),
			)
		);
		$ids = self::staged();
		self::paired( $ids );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'widget_area', 'sidebar-1' );
		// A mapping a run kept, then the pair unclear.
		$extra = array( 'instances' => array( 'live' => array( 'text-9' ), 'staging' => array( 'text-9' ) ), 'map' => array( 'text-2' => 'text-2' ) );
		self::assertIsArray( AB_MCP_Sync_Pairs::rebase( (int) $pair['id'], array( 'canon' => 'canon/1', 'base_live' => $pair['base_live'], 'base_staging' => $pair['base_staging'], 'base_origin' => 'push', 'base_extra' => $extra ), AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] ) ) ) );
		self::assertIsArray( AB_MCP_Sync_Pairs::set_state( (int) $pair['id'], 'unclear', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] ) ) ) );
		self::paired( $ids, array( 'realign' ) );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'widget_area', 'sidebar-1' );
		self::assertSame( 'active', $pair['state'] );
		self::assertSame( array( 'instances' => array( 'live' => array( 'text-2' ), 'staging' => array( 'text-2' ) ), 'map' => array( 'text-2' => 'text-2' ) ), $pair['base_extra'], 'The instances of now, the mapping of the run kept.' );
	}

	/* --------------------------------------------------------------- held */

	public function testANameHoldingACredentialIsNeverInPlainText(): void {
		global $wpdb;
		self::pair();
		update_option( 'my_api_key', 'CANARY-B9' );
		self::b( array( array( 'op' => 'update_option', 'name' => 'my_api_key', 'value' => 'CANARY-B9' ) ) );
		$slug = '{"password":"CANARY-ONLY"}';
		$a    = self::pages_on_a( array( 'plain' => 'x' ) );
		$wpdb->update( $wpdb->posts, array( 'post_name' => $slug ), array( 'ID' => (int) $a['plain'] ) );
		clean_post_cache( (int) $a['plain'] );
		$answer = self::report( self::staged() );
		self::assertGreaterThanOrEqual( 1, $answer['live']['credentials'] );
		$json = (string) json_encode( $answer );
		self::assertStringNotContainsString( 'CANARY', $json );
		self::assertStringNotContainsString( 'my_api_key', $json, 'A name of a credential is counted, never listed.' );
		$held = array_values( array_filter( $answer['all'], static fn( $e ) => 'held' === $e['result'] ) );
		self::assertCount( 1, $held );
		self::assertSame( array( array( 'held' => 'credential_field', 'ref_sha256' => hash( 'sha256', '"' . $a['plain'] . '"' ) ) ), $held[0]['live'] );
	}
}
