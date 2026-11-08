<?php
/**
 * Z8 branch 11 (heikel/z8-struktur) on two WordPress sites: the kinds of the
 * structure of a site, end to end through wp_site_push and wp_site_revert.
 * Site B is the staging site, in processes of its own; site A, this
 * process, the production site (as tests/integration/SyncContentTest.php).
 *
 * The ordinary cases, each over and back (the plan of Z8, branch 11):
 * - a changed menu item (title, target, order) and a new item in a menu
 *   there; the way back puts the changed ones back and leaves the new one as
 *   a rest;
 * - a new menu at a position of the theme; the way back leaves the menu as a
 *   rest and puts the position back;
 * - a changed text widget and image widget in a sidebar; a new instance
 *   makes the whole area a rest with its mapping kept (decision 23);
 * - changed Additional CSS of the active theme, the rendered page showing it
 *   and, after the way back, the CSS before;
 * - changed core options (blogname, blogdescription, posts_per_page, the
 *   permalinks with the rewrite rules built anew).
 * No step and no step of the way back deletes a post, a term or an option of
 * the site (decisions 21, 22).
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
use AB_MCP_Sync_Backup;
use AB_MCP_Sync_Engine;
use AB_MCP_Sync_Exclusions;
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Resources;
use AB_MCP_Sync_Schema;
use AB_MCP_Sync_Shared_Row;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use AB_MCP_Widget_Writer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-revert.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';
require_once AB_MCP_DIR . 'includes/tools/class-widget-writer.php';

final class SyncStructureTest extends TestCase {

	/** The date every page of these tests carries, on both sites. */
	private const DATE = '2026-10-01 10:00:00';

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	/** @var array[] The deletions the filters of a case saw (watch_deletions()). */
	private $deletions = array();

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		AB_MCP_Sync_Backup::forget();
		AB_MCP_Sync_Guard::disarm();
		delete_option( AB_MCP_Sync_Guard::OPTION );
		delete_option( \AB_MCP_Change_Lock::OPTION );
		wp_set_current_user( self::admin() );
		self::assertIsArray( AB_MCP_Sync_Schema::install() );
		// The positions of the theme of site A (Twenty Twenty-Five registers
		// none; a classic theme would, register_nav_menus()).
		register_nav_menus(
			array(
				'primary' => 'Primary',
				'footer'  => 'Footer',
			)
		);
		$this->deletions = array();
		// The caches of this process, after the rows were put back.
		wp_cache_flush();
		$GLOBALS['_wp_sidebars_widgets'] = array();
	}

	protected function tearDown(): void {
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Backup::forget();
		AB_MCP_Sync_Guard::disarm();
		AB_MCP_Sync_Engine::$probe    = null;
		AB_MCP_Sync_Resources::$probe = null;
		AB_MCP_Sync_Resources::$time  = null;
		delete_option( \AB_MCP_Change_Lock::OPTION );
		unregister_nav_menu( 'primary' );
		unregister_nav_menu( 'footer' );
		remove_all_filters( 'pre_delete_post' );
		remove_all_filters( 'pre_trash_post' );
		remove_all_actions( 'pre_delete_term' );
		remove_all_actions( 'delete_option' );
		remove_all_filters( 'wp_revisions_to_keep' );
		remove_all_filters( 'wp_custom_css_revisions_to_keep' );
		remove_post_type_support( 'nav_menu_item', 'revisions' );
		wp_cache_flush();
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
	}

	/* ------------------------------------------------------------ helpers */

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

	/** The arguments of a page, the same on both sites. */
	private static function page( string $name ): array {
		return array(
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_title'    => ucfirst( $name ),
			'post_name'     => $name,
			'post_content'  => 'The page ' . $name . '.',
			'post_author'   => 1,
			'post_date'     => self::DATE,
			'post_date_gmt' => self::DATE,
		);
	}

	/** A post of A, in this process. */
	private static function on_a( array $args ): int {
		$id = wp_insert_post( wp_slash( $args ), true );
		self::assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/** One step on B, its answer. */
	private static function one_b( array $step ) {
		return self::b( array( $step + array( 'as' => 'out' ) ) )['out'];
	}

	/** B's packages of before removed (an account keeps three open exports at most). */
	private static function clear_b(): void {
		self::b( array( array( 'op' => 'store_cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) ) );
	}

	/** B packs its inventory and A takes it over: the package ids. */
	private static function staged(): array {
		self::clear_b();
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
			$package = self::b( array( $step ) )['package'];
			$cursor  = $package['cursor'];
			$ids[]   = self::import_package( $package, 'inventory', null );
		} while ( null !== $cursor && count( $ids ) < 50 );
		return $ids;
	}

	/** A package of B taken over here, chunk by chunk. */
	private static function import_package( array $package, string $purpose, ?string $report_hash ): string {
		$begin = array(
			'package_id' => $package['package_id'],
			'manifest'   => $package['manifest'],
			'purpose'    => $purpose,
		);
		if ( null !== $report_hash ) {
			$begin['report_hash'] = $report_hash;
		}
		$state = AB_MCP_Sync_Import::call( $begin );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
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
			$state = AB_MCP_Sync_Import::call(
				array(
					'package_id' => $package['package_id'],
					'chunk'      => $i,
					'data'       => $data[ 'c' . $i ]['data'],
				)
			);
			self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		}
		self::assertTrue( $state['complete'] );
		return (string) $package['package_id'];
	}

	/** B packs the objects of a report and A takes them over: the package id. */
	private static function objects( string $report_hash, array $refs ): string {
		self::clear_b();
		$package = self::b(
			array(
				array(
					'op'          => 'export',
					'purpose'     => 'objects',
					'report_hash' => $report_hash,
					'refs'        => $refs,
					'as'          => 'package',
				),
			)
		)['package'];
		self::assertSame( 'objects', $package['purpose'], json_encode( $package ) );
		return self::import_package( $package, 'objects', $report_hash );
	}

	/** The definition of a tool of the staging group. */
	private static function def( string $name ): array {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return $r->get( $name );
	}

	/** A tool through the gate of the plugin, in this process. */
	private static function gated( string $name, array $a ) {
		return AB_MCP_REST_Controller::run_tool( $name, self::def( $name ), $a );
	}

	/** Every entry of these categories of a fresh proposal paired; the proposal. */
	private static function paired( array $ids, array $categories = array( 'equal' ) ): array {
		$answer = self::gated( 'wp_site_pairs', array( 'action' => 'propose', 'packages' => $ids ) );
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = self::gated( 'wp_site_pairs', array( 'action' => 'propose', 'proposal_id' => $answer['proposal_id'] ) );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'categories'  => $categories,
		);
		$preview = self::gated( 'wp_site_pairs', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$done = self::gated( 'wp_site_pairs', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		return $done;
	}

	/** wp_site_diff in this process, worked out to its end and paged: every entry. */
	private static function report( array $ids ): array {
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
			$page = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'], 'cursor' => $page['cursor'] ) );
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
			++$pages;
			self::assertLessThan( 300, $pages );
		}
		$answer['all'] = $all;
		return $answer;
	}

	/** The entry of an object by its kind and the ref of one side. */
	private static function entry( array $entries, string $kind, string $side, string $ref ): array {
		foreach ( $entries as $entry ) {
			if ( $kind === $entry['kind'] && isset( $entry[ $side ]['ref'] ) && (string) $entry[ $side ]['ref'] === $ref ) {
				return $entry;
			}
		}
		self::fail( "No entry for $kind $ref on the side $side: " . json_encode( array_map( static fn( $e ) => array( $e['kind'], $e['staging']['ref'] ?? null, $e['live']['ref'] ?? null, $e['result'], $e['action'] ), array_filter( $entries, static fn( $e ) => $e['kind'] === $kind ) ) ) );
	}

	/** The report, the objects package and the arguments of a plan for these entries (kind, side, ref). */
	private static function planned( array $wanted ): array {
		$report = self::report( self::staged() );
		$select = array();
		$refs   = array();
		foreach ( $wanted as list( $kind, $side, $ref ) ) {
			$entry    = self::entry( $report['all'], $kind, $side, (string) $ref );
			$select[] = (int) $entry['n'];
			if ( isset( $entry['staging']['ref'] ) ) {
				$refs[] = array(
					'kind' => $entry['kind'],
					'ref'  => (string) $entry['staging']['ref'],
				);
			}
		}
		$package = self::objects( $report['report_hash'], $refs );
		return array(
			'report' => $report,
			'args'   => array(
				'action'    => 'plan',
				'report_id' => $report['report_id'],
				'select'    => $select,
				'packages'  => array( $package ),
			),
		);
	}

	/** A plan confirmed in this process: the run. */
	private static function begun( array $args ): array {
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertTrue( $preview['confirm_required'], json_encode( $preview ) );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		self::assertSame( 'open', $run['state'] );
		return $run;
	}

	/** Steps until the run is closed, at most $max. */
	private static function finish( int $run_id, int $max = 14 ): array {
		$answer = null;
		for ( $i = 0; $i < $max; $i++ ) {
			$answer = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return $answer;
			}
		}
		self::fail( 'The run did not close within ' . $max . ' steps: ' . json_encode( $answer ) );
	}

	/** The status of a run, its steps with their journals. */
	private static function run_status( int $run_id ): array {
		$status = self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) );
		self::assertIsArray( $status, is_wp_error( $status ) ? $status->get_error_message() : '' );
		return $status;
	}

	/** The way back of a run, planned, confirmed and run to its close: its status. */
	private static function back( int $run_id ): array {
		$args    = array(
			'action' => 'plan',
			'run_id' => $run_id,
		);
		$preview = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$begun = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
		$answer = null;
		for ( $i = 0; $i < 14; $i++ ) {
			$answer = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => (int) $begun['run_id'] ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => (int) $begun['run_id'] ) );
			}
		}
		self::fail( 'The way back did not close: ' . json_encode( $answer ) );
	}

	/** The write steps of a status. */
	private static function writes( array $status ): array {
		return array_values( array_filter( $status['steps'], static fn( $s ) => 'write' === $s['op'] ) );
	}

	/** Each write step done, without a deviation, every part read as its target. */
	private static function assertWrittenWhole( array $status ): void {
		foreach ( self::writes( $status ) as $step ) {
			self::assertSame( 'done', $step['state'], json_encode( $step ) );
			self::assertNull( $step['parts']['object']['deviated'], json_encode( $step['parts'] ) );
			foreach ( $step['parts']['parts'] as $part ) {
				self::assertSame( 'read', $part['state'], $part['part'] . ': ' . json_encode( $part ) );
			}
		}
	}

	/** The rests a way back names, as kind and ref here. */
	private static function rests( array $back ): array {
		$out = array();
		foreach ( $back['steps'] as $step ) {
			if ( isset( $step['result']['rest'] ) ) {
				$out[] = array( $step['result']['rest']['kind'], $step['result']['rest']['live_ref'] );
			}
		}
		return $out;
	}

	/** The value of an option of a site, read from its row, unpacked. */
	/** The findings of the check of a run by the staging ref of their object. */
	private static function findings( array $status ): array {
		$out = array();
		foreach ( $status['steps'] as $step ) {
			if ( 'check' === $step['op'] ) {
				foreach ( $step['result']['findings'] ?? array() as $finding ) {
					$out[ (string) ( $finding['staging_ref'] ?? '' ) ] = (string) $finding['message'];
				}
			}
		}
		return $out;
	}

	private static function option_of( string $site, string $name ) {
		$row = AB_MCP_IT_Sites::option_row( $site, $name );
		return null === $row ? null : maybe_unserialize( (string) $row['option_value'] );
	}

	/** The row of a post of A, read past every cache. */
	private static function post_row_a( int $id ): array {
		$rows = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $id ) );
		self::assertCount( 1, $rows, "Post $id of A." );
		unset( $rows[0]['post_modified'], $rows[0]['post_modified_gmt'] );
		return $rows[0];
	}

	/** The meta rows of a post of A, as key => values, read past every cache. */
	private static function meta_rows_a( int $id ): array {
		$out = array();
		foreach ( AB_MCP_IT_Sites::rows( 'a', 'postmeta', array( 'post_id' => (string) $id ) ) as $row ) {
			if ( '_edit_lock' !== $row['meta_key'] ) {
				$out[ $row['meta_key'] ][] = $row['meta_value'];
			}
		}
		ksort( $out );
		return $out;
	}

	/** The source id of the anchor. */
	private static function source(): string {
		return (string) AB_MCP_Sync_Anchor::active()['source_id'];
	}

	/** Filters that record every deletion of a post, a term or an option of the site. */
	private function watch_deletions(): void {
		add_filter(
			'pre_delete_post',
			function ( $check, $post ) {
				$this->deletions[] = array( 'delete_post', (int) ( is_object( $post ) ? $post->ID : $post ) );
				return $check;
			},
			1,
			2
		);
		add_filter(
			'pre_trash_post',
			function ( $check, $post ) {
				$this->deletions[] = array( 'trash_post', (int) ( is_object( $post ) ? $post->ID : $post ) );
				return $check;
			},
			1,
			2
		);
		add_action(
			'pre_delete_term',
			function ( $term, $taxonomy ) {
				$this->deletions[] = array( 'delete_term', (int) $term, (string) $taxonomy );
			},
			1,
			2
		);
		add_action(
			'delete_option',
			function ( $option ) {
				if ( ! AB_MCP_Sync_Exclusions::option( (string) $option ) ) {
					$this->deletions[] = array( 'delete_option', (string) $option );
				}
			},
			1,
			1
		);
	}

	/** The settings of an instance as the widget's update() stores them, made in this process. */
	private static function widget_settings( string $base, array $sent ): array {
		$clean = AB_MCP_Widget_Writer::sanitize( $base, 2, $sent, array() );
		self::assertIsArray( $clean, is_wp_error( $clean ) ? $clean->get_error_message() : '' );
		return $clean;
	}

	/** The same instances in the same area of both sites, as the rows hold them. */
	private static function widgets_on_both( array $instances, string $area = 'sidebar-1' ): void {
		$rows = array();
		foreach ( $instances as $id => $settings ) {
			list( $base, $number ) = array( substr( $id, 0, strrpos( $id, '-' ) ), (int) substr( $id, strrpos( $id, '-' ) + 1 ) );
			$rows[ $base ][ $number ] = $settings;
		}
		$areas            = self::option_of( 'a', 'sidebars_widgets' );
		$areas[ $area ]   = array_merge( $areas[ $area ], array_keys( $instances ) );
		$steps            = array();
		foreach ( $rows as $base => $row ) {
			$row['_multiwidget'] = 1;
			update_option( 'widget_' . $base, $row );
			$steps[] = array( 'op' => 'update_option', 'name' => 'widget_' . $base, 'value' => $row );
		}
		update_option( 'sidebars_widgets', $areas );
		$steps[] = array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas );
		self::b( $steps );
	}

	/* ------------------------------------------------------------- menus */

	public function testAChangedMenuItemAndANewItemGoOverAndTheWayBackPutsTheChangedOnesBackAndLeavesTheNewOneAsARest(): void {
		// The ordinary menu (decision 25, A5): a menu paired on both sites
		// with an item of a page and a custom link; on staging the page item
		// gets a title of its own and moves down, the link moves up and opens
		// in a new tab, and a new link comes at the end. All three go over in
		// one run through wp_update_nav_menu_item(); the way back puts the
		// two changed items back byte for byte and leaves the new one as a
		// rest, its pair residual. Nothing is deleted.
		self::pair();
		$about_a = self::on_a( self::page( 'about' ) );
		$about_b = (int) self::one_b( array( 'op' => 'insert_post', 'args' => self::page( 'about' ) ) );
		$menu_a  = (int) wp_create_nav_menu( 'Main' );
		$menu_b  = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Main' ) );
		$page    = static fn( int $id ) => array( 'menu-item-object-id' => $id, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 );
		$link    = array( 'menu-item-title' => 'Shop', 'menu-item-url' => 'https://example.test/shop/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 2 );
		$item_a  = (int) wp_update_nav_menu_item( $menu_a, 0, $page( $about_a ) );
		$link_a  = (int) wp_update_nav_menu_item( $menu_a, 0, $link );
		$item_b  = (int) self::one_b( array( 'op' => 'nav_menu_item', 'menu' => $menu_b, 'args' => $page( $about_b ) ) );
		$link_b  = (int) self::one_b( array( 'op' => 'nav_menu_item', 'menu' => $menu_b, 'args' => $link ) );
		// Menu items with revisions on this site (as a plugin adds them), two
		// of the page item, whose title the run changes (WordPress saves a
		// revision, and cuts the oldest, where the title, content or excerpt
		// change); the site keeps one (the lead after Codex round 1 of branch
		// 11): none of them goes, forward or back.
		add_post_type_support( 'nav_menu_item', 'revisions' );
		wp_update_post( array( 'ID' => $item_a, 'post_content' => 'Once.' ) );
		wp_update_post( array( 'ID' => $item_a, 'post_content' => '' ) );
		// The same saves on staging, so both items stay alike.
		self::b(
			array(
				array( 'op' => 'update_post', 'args' => array( 'ID' => $item_b, 'post_content' => 'Once.' ) ),
				array( 'op' => 'update_post', 'args' => array( 'ID' => $item_b, 'post_content' => '' ) ),
			)
		);
		$revisions = array_keys( wp_get_post_revisions( $item_a ) );
		sort( $revisions );
		self::assertGreaterThan( 1, count( $revisions ) );
		$kept = static function () use ( $revisions ): array {
			global $wpdb;
			return array_map( 'intval', $wpdb->get_col( 'SELECT ID FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', array_map( 'intval', $revisions ) ) . ') ORDER BY ID' ) );
		};
		add_filter( 'wp_revisions_to_keep', static fn( $num, $post ) => 'nav_menu_item' === $post->post_type ? 1 : $num, 10, 2 );
		self::paired( self::staged() );
		self::b(
			array(
				array( 'op' => 'nav_menu_item', 'menu' => $menu_b, 'item' => $item_b, 'args' => array_merge( $page( $about_b ), array( 'menu-item-title' => 'Who we are', 'menu-item-position' => 2 ) ) ),
				array( 'op' => 'nav_menu_item', 'menu' => $menu_b, 'item' => $link_b, 'args' => array_merge( $link, array( 'menu-item-target' => '_blank', 'menu-item-position' => 1 ) ) ),
				array( 'op' => 'nav_menu_item', 'menu' => $menu_b, 'args' => array( 'menu-item-title' => 'Blog', 'menu-item-url' => 'https://example.test/blog/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 3 ), 'as' => 'new' ),
			)
		);
		$before = array(
			'item'      => self::post_row_a( $item_a ),
			'item_meta' => self::meta_rows_a( $item_a ),
			'link'      => self::post_row_a( $link_a ),
			'link_meta' => self::meta_rows_a( $link_a ),
		);
		$report = self::report( self::staged() );
		$items  = array_values( array_filter( $report['all'], static fn( $e ) => 'menu_item' === $e['kind'] && in_array( $e['action'], array( 'take_over', 'create' ), true ) ) );
		self::assertCount( 3, $items, json_encode( $items ) );
		self::assertSame( 'take_over', self::entry( $report['all'], 'menu_item', 'live', (string) $item_a )['action'] );
		self::assertSame( 'take_over', self::entry( $report['all'], 'menu_item', 'live', (string) $link_a )['action'] );
		$created = array_values( array_filter( $items, static fn( $e ) => 'create' === $e['action'] ) );
		self::assertCount( 1, $created );
		$planned = self::planned(
			array(
				array( 'menu_item', 'live', $item_a ),
				array( 'menu_item', 'live', $link_a ),
				array( 'menu_item', 'staging', (string) $created[0]['staging']['ref'] ),
			)
		);
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( self::run_status( (int) $run['run_id'] ) ) );
		self::assertWrittenWhole( self::run_status( (int) $run['run_id'] ) );
		self::assertSame( array( '2', '1' ), array( self::post_row_a( $item_a )['menu_order'], self::post_row_a( $link_a )['menu_order'] ), json_encode( array( self::post_row_a( $item_a ), self::post_row_a( $link_a ) ) ) );
		wp_cache_flush();
		$items_now = wp_get_nav_menu_items( $menu_a );
		self::assertSame( array( 'Shop', 'Who we are', 'Blog' ), array_map( static fn( $i ) => $i->title, $items_now ), 'The order and the titles of staging.' );
		self::assertSame( array( '_blank' ), self::meta_rows_a( $link_a )['_menu_item_target'] );
		$new_a = (int) end( $items_now )->ID;
		self::assertSame( array( 'https://example.test/blog/' ), self::meta_rows_a( $new_a )['_menu_item_url'] );
		self::assertSame( $revisions, $kept(), 'No revision of the page item was deleted.' );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( self::source(), 'menu_item', (string) $new_a )['state'] );
		// The next report sees the menu as equal.
		$again = self::report( self::staged() );
		foreach ( array( $item_a, $link_a, $new_a ) as $id ) {
			self::assertSame( 'equal', self::entry( $again['all'], 'menu_item', 'live', (string) $id )['result'], (string) $id );
		}
		// The way back: the changed items back as they were, the new one a rest.
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		self::assertSame( $before['item'], self::post_row_a( $item_a ), 'Byte for byte as before the run.' );
		self::assertSame( $before['item_meta'], self::meta_rows_a( $item_a ) );
		self::assertSame( $before['link'], self::post_row_a( $link_a ) );
		self::assertSame( $before['link_meta'], self::meta_rows_a( $link_a ) );
		self::assertSame( $revisions, $kept(), 'Nor on the way back.' );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::find_live( self::source(), 'menu_item', (string) $new_a )['state'] );
		self::assertSame( 'publish', self::post_row_a( $new_a )['post_status'], 'The new item stays.' );
		self::assertSame( array( array( 'menu_item', (string) $new_a ) ), self::rests( $back ) );
		self::assertSame( array(), $this->deletions, 'Nothing was deleted, forward or back.' );
	}

	public function testANewMenuAtAPositionOfTheThemeGoesOverAndTheWayBackPutsThePositionBackAndLeavesTheMenuAsARest(): void {
		// A new menu at a position of the theme (decisions 17 and 25): the
		// position primary holds the menu Old on both sites; on staging a new
		// menu Footer with one link takes primary. The menu and its item are
		// made in one run, the menu first (graph order), the position set
		// through set_theme_mod( 'nav_menu_locations' ); the way back leaves
		// the menu and the item as rests and gives primary back to Old
		// (decision 23: «setzt … die Positionen zurück»). The rest of the theme
		// mods is a field of the row the step does not change (decision 24).
		self::pair();
		$old_a = (int) wp_create_nav_menu( 'Old' );
		$old_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Old' ) );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $old_a ) );
		set_theme_mod( 'header_textcolor', '333333' );
		self::b(
			array(
				array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'primary' => $old_b ) ),
				array( 'op' => 'theme_mod', 'name' => 'header_textcolor', 'value' => '333333' ),
			)
		);
		self::paired( self::staged() );
		$new_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Footer' ) );
		$link  = (int) self::one_b( array( 'op' => 'nav_menu_item', 'menu' => $new_b, 'args' => array( 'menu-item-title' => 'Imprint', 'menu-item-url' => 'https://example.test/imprint/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) ) );
		self::one_b( array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'primary' => $new_b ) ) );
		$mods_before = AB_MCP_IT_Sites::option_row( 'a', 'theme_mods_twentytwentyfive' )['option_value'];
		$planned     = self::planned(
			array(
				array( 'menu', 'staging', (string) $new_b ),
				array( 'menu_item', 'staging', (string) $link ),
			)
		);
		self::assertSame( 'create', self::entry( $planned['report']['all'], 'menu', 'staging', (string) $new_b )['action'] );
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( self::run_status( (int) $run['run_id'] ) ) );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertWrittenWhole( $status );
		self::assertSame( array( 'menu', 'menu_item' ), array_map( static fn( $s ) => $s['parts']['object']['kind'], self::writes( $status ) ), 'The menu first, then its item.' );
		wp_cache_flush();
		$new_a = (int) get_term_by( 'slug', 'footer', 'nav_menu' )->term_id;
		self::assertSame( array( 'primary' => $new_a ), self::option_of( 'a', 'theme_mods_twentytwentyfive' )['nav_menu_locations'], 'The new menu at the position of staging.' );
		self::assertSame( '333333', self::option_of( 'a', 'theme_mods_twentytwentyfive' )['header_textcolor'], 'The rest of the theme mods as it was.' );
		self::assertSame( array( 'Imprint' ), array_map( static fn( $i ) => $i->title, wp_get_nav_menu_items( $new_a ) ) );
		// The way back: the position back to Old, the menu and its item rests.
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		// The theme mods as before the run, byte for byte in every field but
		// the cache of the core for the CSS post (custom_css_post_id -1, which
		// wp_get_custom_css_post() sets as a page of this site is shown: the
		// probe of the check after the run showed one).
		$mods = self::option_of( 'a', 'theme_mods_twentytwentyfive' );
		self::assertSame( -1, $mods['custom_css_post_id'] ?? -1 );
		unset( $mods['custom_css_post_id'] );
		self::assertSame( $mods_before, serialize( $mods ), 'The theme mods byte for byte as before the run.' );
		wp_cache_flush();
		self::assertIsObject( get_term( $new_a, 'nav_menu' ), 'The new menu stays.' );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::find_live( self::source(), 'menu', (string) $new_a )['state'] );
		$rests = self::rests( $back );
		sort( $rests );
		self::assertSame( 'menu', $rests[0][0] );
		self::assertSame( 'menu_item', $rests[1][0] );
		self::assertSame( array(), $this->deletions, 'Nothing was deleted, forward or back.' );
	}

	public function testANewMenuAtAPositionNoMenuHoldsYetGoesOverAndTheWayBackLeavesThePositionEmptyAgain(): void {
		// A new menu at a position the theme registers and no menu holds yet
		// (Codex round 1 of branch 11, B1): the field is absent before the
		// term call and after it, then the step sets it; the way back unsets
		// it again and leaves the menu and its item as rests.
		self::pair();
		self::paired( self::staged() );
		$new_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Footer' ) );
		$link  = (int) self::one_b( array( 'op' => 'nav_menu_item', 'menu' => $new_b, 'args' => array( 'menu-item-title' => 'Imprint', 'menu-item-url' => 'https://example.test/imprint/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) ) );
		self::one_b( array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'footer' => $new_b ) ) );
		$planned = self::planned(
			array(
				array( 'menu', 'staging', (string) $new_b ),
				array( 'menu_item', 'staging', (string) $link ),
			)
		);
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( self::run_status( (int) $run['run_id'] ) ) );
		self::assertWrittenWhole( self::run_status( (int) $run['run_id'] ) );
		wp_cache_flush();
		$new_a = (int) get_term_by( 'slug', 'footer', 'nav_menu' )->term_id;
		self::assertSame( array( 'footer' => $new_a ), self::option_of( 'a', 'theme_mods_twentytwentyfive' )['nav_menu_locations'] );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$mods = self::option_of( 'a', 'theme_mods_twentytwentyfive' );
		self::assertSame( array(), $mods['nav_menu_locations'] ?? array(), 'The position no menu held before is empty again.' );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::find_live( self::source(), 'menu', (string) $new_a )['state'] );
		self::assertSame( array(), $this->deletions, 'Nothing was deleted, forward or back.' );
	}

	public function testACredentialInTheNameOfAMenuOrOfItsPositionLandsInNoPackage(): void {
		// Codex round 1 of branch 11, A4: the content of a menu item carries
		// the name of its menu, appended after the credential check of the
		// form; the form of a menu carries the names of its positions. A
		// credential in either holds the object, and its value is nowhere in
		// the package of objects.
		self::pair();
		self::paired( self::staged() );
		$secret = 'synthetic-review-value';
		$menu   = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Shop password=' . $secret ) );
		$item   = (int) self::one_b( array( 'op' => 'nav_menu_item', 'menu' => $menu, 'args' => array( 'menu-item-title' => 'Imprint', 'menu-item-url' => 'https://example.test/imprint/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) ) );
		$other  = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Footer' ) );
		self::one_b( array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'password=' . $secret => $other ) ) );
		$package = self::b(
			array(
				array(
					'op'          => 'export',
					'purpose'     => 'objects',
					'report_hash' => str_repeat( 'a', 64 ),
					'refs'        => array(
						array( 'kind' => 'menu_item', 'ref' => (string) $item ),
						array( 'kind' => 'menu', 'ref' => (string) $other ),
					),
					'as'          => 'package',
				),
			)
		)['package'];
		$taken = AB_MCP_Sync_Import::taken( self::import_package( $package, 'objects', str_repeat( 'a', 64 ) ), 'objects' );
		self::assertIsArray( $taken, is_wp_error( $taken ) ? $taken->get_error_message() : '' );
		self::assertCount( 2, $taken['lines'] );
		self::assertStringNotContainsString( $secret, (string) json_encode( array( $package, $taken ) ), 'No value of a credential in the package.' );
		self::assertSame( 2, $package['credentials']['held'], (string) json_encode( $package['credentials'] ) );
	}

	public function testAMenuThatTakesThePositionOfAnotherMenuOfTheRunGoesAfterItAndAnExchangeIsLocked(): void {
		// Codex round 1 of branch 11, B2: Main holds primary and Footer holds
		// footer on both sites. On staging they exchange their positions:
		// the plan locks both before anything is written
		// (menu:position_exchange). Then on staging Main moves to footer and
		// Footer holds none: Footer gives footer up first, Main takes it
		// after, both written.
		self::pair();
		$main_a = (int) wp_create_nav_menu( 'Main' );
		$foot_a = (int) wp_create_nav_menu( 'Footer' );
		$main_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Main' ) );
		$foot_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Footer' ) );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $main_a, 'footer' => $foot_a ) );
		self::one_b( array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'primary' => $main_b, 'footer' => $foot_b ) ) );
		self::paired( self::staged() );
		$wanted = array( array( 'menu', 'live', (string) $main_a ), array( 'menu', 'live', (string) $foot_a ) );
		self::one_b( array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'primary' => $foot_b, 'footer' => $main_b ) ) );
		$planned = self::planned( $wanted );
		$refused = self::gated( 'wp_site_push', $planned['args'] );
		self::assertInstanceOf( \WP_Error::class, $refused, json_encode( $refused ) );
		self::assertSame( 'ab_mcp_sync_push_nothing', $refused->get_error_code() );
		self::assertSame( 2, substr_count( $refused->get_error_message(), '(menu:position_exchange)' ), $refused->get_error_message() );
		self::assertSame( array( 'primary' => $main_a, 'footer' => $foot_a ), self::option_of( 'a', 'theme_mods_twentytwentyfive' )['nav_menu_locations'], 'Nothing written.' );
		// A move: Footer gives footer up, Main takes it.
		self::one_b( array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'footer' => $main_b ) ) );
		$planned = self::planned( $wanted );
		$run     = self::begun( $planned['args'] );
		$closed  = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( self::run_status( (int) $run['run_id'] ) ) );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertWrittenWhole( $status );
		self::assertSame( array( (string) $foot_a, (string) $main_a ), array_map( static fn( $s ) => (string) $s['parts']['object']['live_ref'], self::writes( $status ) ), 'Footer first, then Main.' );
		self::assertSame( array( 'footer' => $main_a ), self::option_of( 'a', 'theme_mods_twentytwentyfive' )['nav_menu_locations'] );
	}

	public function testAnotherRequestThatWritesTheThemeModsAsANewMenuGivesItsPositionBackIsHeldOffByTheLock(): void {
		// Codex round 1 of branch 11, C3: the way back of a new menu puts its
		// positions back under the takeover lock, which covers the theme mods
		// (shared_back()). Another request that writes them in that moment
		// (a theme mod of site A, in a process of its own) is refused: its
		// value does not land, and the position goes back. Without the lock
		// its write would land and the position stay as it is (left).
		self::pair();
		$old_a = (int) wp_create_nav_menu( 'Old' );
		$old_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Old' ) );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $old_a ) );
		set_theme_mod( 'header_textcolor', '333333' );
		self::b(
			array(
				array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'primary' => $old_b ) ),
				array( 'op' => 'theme_mod', 'name' => 'header_textcolor', 'value' => '333333' ),
			)
		);
		self::paired( self::staged() );
		$new_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Footer' ) );
		self::one_b( array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'primary' => $new_b ) ) );
		$planned = self::planned( array( array( 'menu', 'staging', (string) $new_b ) ) );
		$run     = self::begun( $planned['args'] );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		$wrote = false;
		add_filter(
			'query',
			static function ( $query ) use ( &$wrote ) {
				if ( $wrote || false === strpos( (string) $query, "option_name = 'theme_mods_twentytwentyfive'" ) ) {
					return $query;
				}
				// Inside shared_back(), as the writer reads the row to put the
				// position back (the lock taken before).
				$frames = array();
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					$frames[] = ( $frame['class'] ?? '' ) . '::' . $frame['function'];
				}
				if ( in_array( 'AB_MCP_Sync_Engine::shared_back', $frames, true ) && in_array( 'AB_MCP_Sync_Menu_Writer::restore_part', $frames, true ) ) {
					$wrote = true;
					AB_MCP_IT_Sync_Steps::run( 'a', array( array( 'op' => 'theme_mod', 'name' => 'header_textcolor', 'value' => 'ff0000' ) ) );
				}
				return $query;
			}
		);
		$back = self::back( (int) $run['run_id'] );
		remove_all_filters( 'query' );
		self::assertTrue( $wrote, 'The other request ran as the position went back.' );
		wp_cache_flush();
		$mods = self::option_of( 'a', 'theme_mods_twentytwentyfive' );
		self::assertSame( '333333', $mods['header_textcolor'], 'The write of the other request did not land.' );
		self::assertSame( array( 'primary' => $old_a ), $mods['nav_menu_locations'], 'The position went back: ' . json_encode( $back ) );
	}

	public function testANewObjectWhosePairCannotBecomeARestOnTheWayBackLeavesItsStepUnclearWithItsWay(): void {
		// The lead after Codex round 1 of branch 11 (as C1): the way back
		// keeps a new object as a rest and sets its pair residual. Where the
		// pairs do not take that save, the step of the way back says so and
		// is unclear: a later run could make the object again.
		self::pair();
		self::paired( self::staged() );
		$new_b   = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Footer' ) );
		$planned = self::planned( array( array( 'menu', 'staging', (string) $new_b ) ) );
		$run     = self::begun( $planned['args'] );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		wp_cache_flush();
		$new_a   = (int) get_term_by( 'slug', 'footer', 'nav_menu' )->term_id;
		$pair_id = (int) AB_MCP_Sync_Pairs::find_live( self::source(), 'menu', (string) $new_a )['id'];
		add_filter(
			'query',
			static function ( $query ) {
				$back = false;
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					$back = $back || ( 'AB_MCP_Sync_Engine' === ( $frame['class'] ?? '' ) && 'revert_boundary' === $frame['function'] );
				}
				return $back && false !== strpos( (string) $query, "SET state = 'residual'" ) ? 'UPDATE ab_mcp_no_such_table SET x = 1' : $query;
			}
		);
		$back = self::back( (int) $run['run_id'] );
		remove_all_filters( 'query' );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$steps = array_values( array_filter( $back['steps'], static fn( $s ) => isset( $s['result']['rest'] ) ) );
		self::assertCount( 1, $steps, json_encode( $back['steps'] ) );
		self::assertSame( 'unclear', $steps[0]['state'], json_encode( $steps[0] ) );
		self::assertStringContainsString( 'could not be kept as a rest', (string) $steps[0]['result']['message'] );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::get( $pair_id )['state'], 'The state did not change.' );
	}

	/** The version of the theme of the CSS: in the clear, and in base64. */
	public static function themeVersions(): array {
		return array(
			'in the clear' => array( 'password=synthetic-review-value' ),
			'in base64'    => array( base64_encode( 'password=synthetic-review-value' ) ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'themeVersions' )]
	public function testACredentialInTheVersionOfTheThemeOfTheCssLandsInNoPackage( string $version ): void {
		// Codex round 2 of branch 11, A8: the content of the CSS carries the
		// version of its theme, appended after the check of the form. The
		// export checks the finished content once more: a credential there
		// holds the CSS, and its value is nowhere in the package of objects.
		// Codex round 3, A8: with the net of the kinds, also in base64.
		self::pair();
		self::paired( self::staged() );
		$secret = 'synthetic-review-value';
		$dir    = AB_MCP_IT_Sites::docroot( 'b' ) . '/wp-content/themes/ab-it-version';
		self::assertTrue( wp_mkdir_p( $dir ) );
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: AB IT Version\nTemplate: twentytwentyfive\nVersion: $version\n*/\n" );
		try {
			self::b(
				array(
					array( 'op' => 'update_option', 'name' => 'stylesheet', 'value' => 'ab-it-version' ),
					array( 'op' => 'custom_css', 'css' => 'body { color: #111111; }' ),
				)
			);
			$package = self::b(
				array(
					array(
						'op'          => 'export',
						'purpose'     => 'objects',
						'report_hash' => str_repeat( 'a', 64 ),
						'refs'        => array( array( 'kind' => 'custom_css', 'ref' => 'ab-it-version' ) ),
						'as'          => 'package',
					),
				)
			)['package'];
			$taken = AB_MCP_Sync_Import::taken( self::import_package( $package, 'objects', str_repeat( 'a', 64 ) ), 'objects' );
			self::assertIsArray( $taken, is_wp_error( $taken ) ? $taken->get_error_message() : '' );
			self::assertCount( 1, $taken['lines'] );
			self::assertStringNotContainsString( $secret, (string) json_encode( array( $package, $taken ) ), 'No value of a credential in the package.' );
			self::assertStringNotContainsString( $version, (string) json_encode( array( $package, $taken ) ), 'Nor the version as it is stored.' );
			self::assertSame( 1, $package['credentials']['held'], (string) json_encode( $package['credentials'] ) );
		} finally {
			self::b( array( array( 'op' => 'update_option', 'name' => 'stylesheet', 'value' => 'twentytwentyfive' ) ) );
			@unlink( $dir . '/style.css' );
			@rmdir( $dir );
		}
	}

	public function testAHarmlessTextWidgetAndSerializedValuesOfMetaAndOptionsGoIntoThePackageWithTheirContent(): void {
		// Codex round 4 of branch 11, B: the export checks the finished
		// content of each object once more (A8), and since this round in the
		// form its kind checked it: the settings of an instance of a widget
		// unpacked, as the kind of the area reads them, not the serialized
		// text the content carries, whose braces made the net read the prose
		// of a text widget as a field of code (*:unread_credential, the line
		// locked). A serialized value of meta and a serialized option, which
		// their kinds read as stored, go as normal objects as well.
		self::pair();
		self::paired( self::staged() );
		$text = self::widget_settings( 'text', array( 'title' => 'Spielanleitung', 'text' => 'Spieler: Anna, Token: drei', 'filter' => true, 'visual' => true ) );
		self::widgets_on_both( array( 'text-2' => $text ) );
		$post = (int) self::b( array( array( 'op' => 'insert_post', 'args' => array( 'post_title' => 'Spielregeln', 'post_status' => 'publish' ), 'as' => 'post' ) ) )['post'];
		$was  = self::option_of( 'b', 'sticky_posts' );
		self::b(
			array(
				array( 'op' => 'add_meta', 'post' => $post, 'key' => 'ab_it_layout', 'value' => array( 'layout' => 'wide', 'columns' => 3, 'label' => 'Spieler: Anna' ) ),
				array( 'op' => 'update_option', 'name' => 'sticky_posts', 'value' => array( $post ) ),
			)
		);
		try {
			$package = self::b(
				array(
					array(
						'op'          => 'export',
						'purpose'     => 'objects',
						'report_hash' => str_repeat( 'a', 64 ),
						'refs'        => array(
							array( 'kind' => 'widget_area', 'ref' => 'sidebar-1' ),
							array( 'kind' => 'post', 'ref' => (string) $post ),
							array( 'kind' => 'option', 'ref' => 'sticky_posts' ),
						),
						'as'          => 'package',
					),
				)
			)['package'];
		} finally {
			self::b( array( array( 'op' => 'update_option', 'name' => 'sticky_posts', 'value' => is_array( $was ) ? $was : array() ) ) );
		}
		self::assertSame( 0, $package['credentials']['held'], (string) json_encode( $package['credentials'] ) );
		$taken = AB_MCP_Sync_Import::taken( self::import_package( $package, 'objects', str_repeat( 'a', 64 ) ), 'objects' );
		self::assertIsArray( $taken, is_wp_error( $taken ) ? $taken->get_error_message() : '' );
		$lines = array();
		foreach ( $taken['lines'] as $line ) {
			$lines[ $line['kind'] . ':' . $line['ref'] ] = $line;
		}
		foreach ( array( 'widget_area:sidebar-1', 'post:' . $post, 'option:sticky_posts' ) as $ref ) {
			self::assertArrayHasKey( $ref, $lines, (string) json_encode( array_keys( $lines ) ) );
			self::assertArrayHasKey( 'hash', $lines[ $ref ], $ref . ': ' . json_encode( $lines[ $ref ] ) );
			self::assertNotSame( array(), $lines[ $ref ]['content'], $ref );
			self::assertSame( array(), array_values( array_filter( array_column( $lines[ $ref ]['locks'], 'key' ), static fn( $key ) => \AB_MCP_Sync_Export::credential_locked( array( array( 'key' => $key ) ) ) ) ), $ref . ': no lock of the credential check.' );
		}
		self::assertSame( array( array( 'text-2', serialize( $text ) ) ), array_values( array_filter( $lines['widget_area:sidebar-1']['content']['meta'], static fn( $row ) => 'text-2' === $row[0] ) ), 'The instance goes with its settings.' );
	}

	/* ----------------------------------------------------------- widgets */

	public function testAChangedTextWidgetAndImageWidgetGoOverAndBack(): void {
		// The ordinary widget area (decision 25, A7): a text widget and an
		// image widget in sidebar-1, paired on both sites; on staging both are
		// changed. The area goes over as a whole with each instance a field of
		// its row (decision 24), through the widget writer; the way back puts
		// both back byte for byte and the pair to its bases of before.
		self::pair();
		$text  = self::widget_settings( 'text', array( 'title' => 'Hours', 'text' => 'Mo–Fr 9–17', 'filter' => true, 'visual' => true ) );
		$image = self::widget_settings( 'media_image', array( 'title' => 'Logo', 'url' => 'https://example.test/logo.png', 'alt' => 'Logo', 'size' => 'full', 'link_type' => 'none' ) );
		self::widgets_on_both( array( 'text-2' => $text, 'media_image-2' => $image ) );
		self::paired( self::staged() );
		$text_new  = self::widget_settings( 'text', array( 'title' => 'Opening hours', 'text' => 'Mo–Sa 9–18', 'filter' => true, 'visual' => true ) );
		$image_new = self::widget_settings( 'media_image', array( 'title' => 'Our logo', 'url' => 'https://example.test/logo-2.png', 'alt' => 'Logo', 'size' => 'full', 'link_type' => 'none' ) );
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => array( 2 => $text_new, '_multiwidget' => 1 ) ),
				array( 'op' => 'update_option', 'name' => 'widget_media_image', 'value' => array( 2 => $image_new, '_multiwidget' => 1 ) ),
			)
		);
		$before  = array( AB_MCP_IT_Sites::option_row( 'a', 'widget_text' )['option_value'], AB_MCP_IT_Sites::option_row( 'a', 'widget_media_image' )['option_value'], AB_MCP_IT_Sites::option_row( 'a', 'sidebars_widgets' )['option_value'] );
		$pair    = AB_MCP_Sync_Pairs::find_live( self::source(), 'widget_area', 'sidebar-1' );
		$planned = self::planned( array( array( 'widget_area', 'live', 'sidebar-1' ) ) );
		self::assertSame( 'take_over', self::entry( $planned['report']['all'], 'widget_area', 'live', 'sidebar-1' )['action'] );
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( self::run_status( (int) $run['run_id'] ) ) );
		self::assertWrittenWhole( self::run_status( (int) $run['run_id'] ) );
		self::assertSame( $text_new, self::option_of( 'a', 'widget_text' )[2] );
		self::assertSame( $image_new, self::option_of( 'a', 'widget_media_image' )[2] );
		self::assertSame( $before[2], AB_MCP_IT_Sites::option_row( 'a', 'sidebars_widgets' )['option_value'], 'The list of the area did not change.' );
		$again = self::report( self::staged() );
		self::assertSame( 'equal', self::entry( $again['all'], 'widget_area', 'live', 'sidebar-1' )['result'] );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( $before, array( AB_MCP_IT_Sites::option_row( 'a', 'widget_text' )['option_value'], AB_MCP_IT_Sites::option_row( 'a', 'widget_media_image' )['option_value'], AB_MCP_IT_Sites::option_row( 'a', 'sidebars_widgets' )['option_value'] ), 'Byte for byte as before the run.' );
		$now = AB_MCP_Sync_Pairs::find_live( self::source(), 'widget_area', 'sidebar-1' );
		self::assertSame( array( 'active', $pair['base_live'], $pair['base_extra'] ), array( $now['state'], $now['base_live'], $now['base_extra'] ), 'The pair with its bases of before.' );
		self::assertSame( array(), $this->deletions );
	}

	public function testANewInstanceMakesTheWholeAreaARestWithItsMappingAndNoLaterRunMakesItTwice(): void {
		// Decision 23, finding R7-2: a paired area, the run makes the instance
		// text-3 of staging (its number free here); the way back leaves it,
		// and with it the whole area residual, its mapping (staging instance →
		// number here) kept in base_extra. A later report shows the rest; a
		// later run does not write the area, also where staging changes the
		// instance again, and makes no second instance; wp_site_pairs confirm
		// makes the pair active with new bases, the mapping kept.
		self::pair();
		$text = self::widget_settings( 'text', array( 'title' => 'Hours', 'text' => 'Mo–Fr', 'filter' => true, 'visual' => true ) );
		self::widgets_on_both( array( 'text-2' => $text ) );
		self::paired( self::staged() );
		$added = self::widget_settings( 'text', array( 'title' => 'News', 'text' => 'Fresh.', 'filter' => true, 'visual' => true ) );
		$areas = self::option_of( 'b', 'sidebars_widgets' );
		$areas['sidebar-1'][] = 'text-3';
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => array( 2 => $text, 3 => $added, '_multiwidget' => 1 ) ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas ),
			)
		);
		$planned = self::planned( array( array( 'widget_area', 'live', 'sidebar-1' ) ) );
		$run     = self::begun( $planned['args'] );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'], json_encode( self::run_status( (int) $run['run_id'] ) ) );
		self::assertSame( $added, self::option_of( 'a', 'widget_text' )[3], 'The number of staging, free here.' );
		self::assertContains( 'text-3', self::option_of( 'a', 'sidebars_widgets' )['sidebar-1'] );
		$pair = AB_MCP_Sync_Pairs::find_live( self::source(), 'widget_area', 'sidebar-1' );
		self::assertSame( 'text-3', $pair['base_extra']['map']['text-3'], 'The mapping of the run in base_extra.' );
		// The way back: text-3 stays, so does the list; the area a rest.
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		self::assertSame( $added, self::option_of( 'a', 'widget_text' )[3] );
		$rest = AB_MCP_Sync_Pairs::find_live( self::source(), 'widget_area', 'sidebar-1' );
		self::assertSame( 'residual', $rest['state'] );
		self::assertSame( 'text-3', $rest['base_extra']['map']['text-3'], 'The mapping kept.' );
		self::assertSame( array( array( 'widget_area', 'sidebar-1' ) ), self::rests( $back ) );
		// Staging changes the instance again: the report shows the rest, a
		// run writes nothing of the area and makes no second instance.
		$again = self::widget_settings( 'text', array( 'title' => 'News', 'text' => 'Fresher.', 'filter' => true, 'visual' => true ) );
		self::one_b( array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => array( 2 => $text, 3 => $again, '_multiwidget' => 1 ) ) );
		$report = self::report( self::staged() );
		$entry  = self::entry( $report['all'], 'widget_area', 'live', 'sidebar-1' );
		self::assertSame( '0', (string) $entry['rule'], json_encode( $entry ) );
		self::assertNotContains( $entry['action'], array( 'take_over', 'create', 'choose' ) );
		self::assertSame( array( 2, 3 ), array_values( array_filter( array_keys( self::option_of( 'a', 'widget_text' ) ), 'is_int' ) ), 'No second instance.' );
		// confirm: active with new bases of both sides, the mapping kept.
		self::paired( self::staged(), array( 'equal', 'realign' ) );
		$confirmed = AB_MCP_Sync_Pairs::find_live( self::source(), 'widget_area', 'sidebar-1' );
		self::assertSame( 'active', $confirmed['state'], json_encode( $confirmed ) );
		self::assertSame( 'text-3', $confirmed['base_extra']['map']['text-3'] );
	}

	/* --------------------------------------------------------------- CSS */

	public function testChangedAdditionalCssGoesOverAndBackAndThePageShowsIt(): void {
		// The Additional CSS of the active theme (decision 25, A9): linked on
		// both sites (custom_css_post_id), changed on staging. It goes over
		// through wp_update_custom_css_post(), which changes the linked post
		// and leaves the link; the rendered page shows the new CSS; the way
		// back puts the content back and the page shows the CSS before.
		self::pair();
		wp_update_custom_css_post( 'body { color: #000000; }' );
		wp_update_custom_css_post( 'body { color: #111111; }' );
		self::one_b( array( 'op' => 'custom_css', 'css' => 'body { color: #111111; }' ) );
		self::paired( self::staged() );
		self::one_b( array( 'op' => 'custom_css', 'css' => 'body { color: #222222; }' ) );
		$link_before = self::option_of( 'a', 'theme_mods_twentytwentyfive' )['custom_css_post_id'];
		// This site keeps one revision (as WP_POST_REVISIONS = 1 would; Codex
		// round 1 of branch 11, A3): no revision the post has goes, forward
		// or back (nothing is deleted in Z8).
		$revisions = array_keys( wp_get_post_revisions( (int) $link_before ) );
		sort( $revisions );
		self::assertNotSame( array(), $revisions );
		add_filter( 'wp_revisions_to_keep', static fn( $num, $post ) => 'custom_css' === $post->post_type ? 1 : $num, 10, 2 );
		// And by the filter of the type, which the core asks after it (Codex
		// round 2 of branch 11, A7).
		add_filter( 'wp_custom_css_revisions_to_keep', static fn() => 1 );
		$kept = static function () use ( $revisions ): array {
			global $wpdb;
			return array_map( 'intval', $wpdb->get_col( 'SELECT ID FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', array_map( 'intval', $revisions ) ) . ') ORDER BY ID' ) );
		};
		$planned     = self::planned( array( array( 'custom_css', 'live', 'twentytwentyfive' ) ) );
		$run         = self::begun( $planned['args'] );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'], json_encode( self::run_status( (int) $run['run_id'] ) ) );
		self::assertWrittenWhole( self::run_status( (int) $run['run_id'] ) );
		self::assertSame( 'body { color: #222222; }', self::post_row_a( (int) $link_before )['post_content'] );
		self::assertSame( $link_before, self::option_of( 'a', 'theme_mods_twentytwentyfive' )['custom_css_post_id'], 'The link as it was.' );
		self::assertSame( array_map( 'intval', $revisions ), $kept(), 'No revision of the post was deleted.' );
		$page = wp_remote_get( AB_MCP_IT_Sites::url( 'a', '/' ), array( 'timeout' => 30 ) );
		self::assertStringContainsString( 'color: #222222;', (string) wp_remote_retrieve_body( $page ), 'The rendered page shows the new CSS.' );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( 'body { color: #111111; }', self::post_row_a( (int) $link_before )['post_content'] );
		$page = wp_remote_get( AB_MCP_IT_Sites::url( 'a', '/' ), array( 'timeout' => 30 ) );
		$body = (string) wp_remote_retrieve_body( $page );
		self::assertStringContainsString( 'color: #111111;', $body, 'The rendered page shows the CSS before.' );
		self::assertStringNotContainsString( 'color: #222222;', $body );
		self::assertSame( array_map( 'intval', $revisions ), $kept(), 'Nor on the way back.' );
		remove_all_filters( 'wp_revisions_to_keep' );
	}

	/* ----------------------------------------------------- stale caches */

	/**
	 * The cache of an option of this process made stale: it holds $cached,
	 * the row in the database stays as it was (as another process that wrote
	 * the row by SQL, or a cache that missed a write, leaves it). The row is
	 * one that is not loaded with all options (autoload off, as WordPress
	 * keeps a large row): its cache has a key of its own, which the change
	 * lock does not forget as it forgets the rows it loads with all of them.
	 */
	private static function stale( string $option, $cached ): void {
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'autoload' => 'off' ), array( 'option_name' => $option ) );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( $option, 'options' );
		$raw = AB_MCP_IT_Sites::option_row( 'a', $option )['option_value'];
		update_option( $option, $cached );
		$wpdb->update( $wpdb->options, array( 'option_value' => $raw ), array( 'option_name' => $option ) );
		self::assertSame( $raw, AB_MCP_IT_Sites::option_row( 'a', $option )['option_value'], 'The row as it was.' );
		self::assertEquals( $cached, get_option( $option ), 'The cache of this process holds another value.' );
	}

	/**
	 * Steps until the run is closed, each write step after its preparation
	 * (by kind and live ref, as the preview of the plan names its object).
	 */
	private static function finish_with( int $run_id, array $plan_args, array $before ): array {
		$preview = self::gated( 'wp_site_push', $plan_args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$keys = array();
		foreach ( $preview['preview']['steps'] as $one ) {
			if ( 'write' === $one['op'] ) {
				$keys[ (int) $one['seq'] ] = $one['object']['kind'] . ' ' . (string) $one['object']['live_ref'];
			}
		}
		for ( $i = 0; $i < 14; $i++ ) {
			foreach ( self::run_status( $run_id )['steps'] as $step ) {
				if ( 'planned' !== $step['state'] ) {
					continue;
				}
				$key = $keys[ (int) $step['seq'] ] ?? '';
				if ( isset( $before[ $key ] ) ) {
					$before[ $key ]();
					unset( $before[ $key ] );
				}
				break;
			}
			$answer = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				self::assertSame( array(), array_keys( $before ), 'Every preparation ran before its step: ' . json_encode( $answer ) );
				return $answer;
			}
		}
		self::fail( 'The run did not close.' );
	}

	public function testAStepWritesFromTheRowsAsTheyAreAndNeverFromAStaleCacheOfThem(): void {
		// Codex round 1 of branch 11, A1: the core functions a step writes
		// with (set_theme_mod(), wp_update_custom_css_post(), update_option(),
		// wp_set_sidebars_widgets()) read the row again through the cache of
		// the options, the step reads it from the database. Before each step
		// the cache of its row is made stale: a theme mod of another field
		// (custom_logo), the link of the CSS post, a core option and the rows
		// of the widgets as the target already holds them. Each step writes
		// from the row as it is, and no other field goes back to the cache.
		self::pair();
		$menu_a = (int) wp_create_nav_menu( 'Main' );
		$menu_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Main' ) );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu_a ) );
		set_theme_mod( 'custom_logo', 222 );
		wp_update_custom_css_post( 'body { color: #111111; }' );
		update_option( 'blogdescription', 'Things' );
		$text = self::widget_settings( 'text', array( 'title' => 'Hours', 'text' => 'Mo–Fr', 'filter' => true, 'visual' => true ) );
		self::widgets_on_both( array( 'text-2' => $text ) );
		// Two more in sidebar-2, in the same row widget_text.
		$row                    = self::option_of( 'a', 'widget_text' );
		$row[3]                 = $text;
		$row[4]                 = $text;
		$areas                  = self::option_of( 'a', 'sidebars_widgets' );
		$areas['sidebar-2']     = array_merge( $areas['sidebar-2'], array( 'text-3', 'text-4' ) );
		$sidebar_2              = $areas['sidebar-2'];
		update_option( 'widget_text', $row );
		update_option( 'sidebars_widgets', $areas );
		self::b(
			array(
				array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'primary' => $menu_b ) ),
				array( 'op' => 'theme_mod', 'name' => 'custom_logo', 'value' => 222 ),
				array( 'op' => 'custom_css', 'css' => 'body { color: #111111; }' ),
				array( 'op' => 'update_option', 'name' => 'blogdescription', 'value' => 'Things' ),
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $row ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas ),
			)
		);
		self::paired( self::staged() );
		$text_new = self::widget_settings( 'text', array( 'title' => 'Opening hours', 'text' => 'Mo–Sa', 'filter' => true, 'visual' => true ) );
		$areas_b  = self::option_of( 'a', 'sidebars_widgets' );
		$areas_b['sidebar-2'] = array_reverse( $areas_b['sidebar-2'] );
		$row_b    = self::option_of( 'a', 'widget_text' );
		$row_b[2] = $text_new;
		self::b(
			array(
				array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'footer' => $menu_b ) ),
				array( 'op' => 'custom_css', 'css' => 'body { color: #222222; }' ),
				array( 'op' => 'update_option', 'name' => 'blogdescription', 'value' => 'Fine things' ),
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $row_b ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas_b ),
			)
		);
		$mods    = 'theme_mods_twentytwentyfive';
		$css_id  = (int) self::option_of( 'a', $mods )['custom_css_post_id'];
		$planned = self::planned(
			array(
				array( 'menu', 'live', (string) $menu_a ),
				array( 'custom_css', 'live', 'twentytwentyfive' ),
				array( 'option', 'live', 'blogdescription' ),
				array( 'widget_area', 'live', 'sidebar-1' ),
				array( 'widget_area', 'live', 'sidebar-2' ),
			)
		);
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish_with(
			(int) $run['run_id'],
			$planned['args'],
			array(
				'menu ' . $menu_a               => static fn() => self::stale( $mods, array( 'custom_logo' => 111 ) + self::option_of( 'a', $mods ) ),
				'custom_css twentytwentyfive'   => static fn() => self::stale( $mods, array( 'custom_css_post_id' => -1 ) + self::option_of( 'a', $mods ) ),
				'option blogdescription'        => static fn() => self::stale( 'blogdescription', 'Fine things' ),
				'widget_area sidebar-1'         => static fn() => self::stale( 'widget_text', array( 2 => $text_new ) + self::option_of( 'a', 'widget_text' ) ),
				'widget_area sidebar-2'         => static function () {
					$target              = self::option_of( 'a', 'sidebars_widgets' );
					$target['sidebar-2'] = array_reverse( $target['sidebar-2'] );
					self::stale( 'sidebars_widgets', $target );
				},
			)
		);
		self::assertSame( 'closed_done', $closed['state'], json_encode( self::run_status( (int) $run['run_id'] ) ) );
		self::assertWrittenWhole( self::run_status( (int) $run['run_id'] ) );
		$now = self::option_of( 'a', $mods );
		self::assertSame( array( 222, array( 'footer' => $menu_a ), $css_id ), array( $now['custom_logo'], $now['nav_menu_locations'], (int) $now['custom_css_post_id'] ), 'The other fields of the theme mods as they were.' );
		self::assertSame( 'body { color: #222222; }', self::post_row_a( $css_id )['post_content'] );
		self::assertSame( 1, count( get_posts( array( 'post_type' => 'custom_css', 'post_status' => 'any', 'numberposts' => -1 ) ) ), 'No second CSS post.' );
		self::assertSame( 'Fine things', AB_MCP_IT_Sites::option_row( 'a', 'blogdescription' )['option_value'] );
		self::assertSame( $text_new, self::option_of( 'a', 'widget_text' )[2] );
		self::assertSame( array_reverse( $sidebar_2 ), self::option_of( 'a', 'sidebars_widgets' )['sidebar-2'] );
		self::assertSame( array(), $this->deletions );
	}

	public function testAWidgetFilterThatChangesAnotherInstanceAsTheStepWritesKeepsItsChangeAndTheStepWritesNothing(): void {
		// Codex round 1 of branch 11, A2: widget_update_callback, run as the
		// step writes an instance, changes another instance of the same row
		// (text-3 in sidebar-2). Its change stays as the filter left it, and
		// the engine reads it as another hand's: the step is unclear, nothing
		// of it is put back by itself (Entscheid A). Since Codex round 3, A6:
		// the writer reads the area after the filter and the rights and
		// compares it with the state the engine read right before the call;
		// it differs, so the instance is not written at all, the journal owns
		// nothing of it, and the step failed with every part of it as before.
		self::pair();
		$text = self::widget_settings( 'text', array( 'title' => 'Hours', 'text' => 'Mo–Fr', 'filter' => true, 'visual' => true ) );
		self::widgets_on_both( array( 'text-2' => $text ) );
		$row                = self::option_of( 'a', 'widget_text' );
		$row[3]             = $text;
		$areas              = self::option_of( 'a', 'sidebars_widgets' );
		$areas['sidebar-2'] = array_merge( $areas['sidebar-2'], array( 'text-3' ) );
		update_option( 'widget_text', $row );
		update_option( 'sidebars_widgets', $areas );
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $row ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas ),
			)
		);
		self::paired( self::staged() );
		$text_new = self::widget_settings( 'text', array( 'title' => 'Opening hours', 'text' => 'Mo–Sa', 'filter' => true, 'visual' => true ) );
		$row_b    = $row;
		$row_b[2] = $text_new;
		self::one_b( array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $row_b ) );
		$planned = self::planned( array( array( 'widget_area', 'live', 'sidebar-1' ) ) );
		$changed = false;
		add_filter(
			'widget_update_callback',
			static function ( $instance ) use ( &$changed ) {
				$writing = false;
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					$writing = $writing || ( 'AB_MCP_Sync_Widget_Area_Writer' === ( $frame['class'] ?? '' ) && 'write_part' === $frame['function'] );
				}
				if ( $writing && ! $changed ) {
					$changed         = true;
					$other           = get_option( 'widget_text' );
					$other[3]['title'] = 'Changed by the filter';
					update_option( 'widget_text', $other );
				}
				return $instance;
			}
		);
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		remove_all_filters( 'widget_update_callback' );
		self::assertTrue( $changed, 'The filter ran as the step wrote.' );
		self::assertSame( 'Changed by the filter', self::option_of( 'a', 'widget_text' )[3]['title'], 'The change of the filter stays.' );
		self::assertSame( $text, self::option_of( 'a', 'widget_text' )[2], 'Not written: the area changed right before the call.' );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$step = self::writes( self::run_status( (int) $run['run_id'] ) )[0];
		self::assertSame( 'failed', $step['state'], json_encode( $step ) );
		self::assertStringContainsString( 'the part instance:text-2 was not written: the part instance:text-3 changed right before the call', (string) $step['parts']['object']['deviated']['why'] );
		self::assertStringContainsString( 'Nothing was written: the part instance:text-3 changed right before the call of the part instance:text-2', (string) $step['result']['message'] );
		self::assertStringContainsString( 'That change stays as it is', (string) $step['result']['message'] );
		$parts = array_column( $step['parts']['parts'], null, 'part' );
		self::assertSame( 'failed', $parts['instance:text-2']['state'], json_encode( $parts ) );
		self::assertTrue( empty( $parts['instance:text-2']['own'] ), 'Nothing written, nothing of it the step\'s own.' );
		self::assertStringContainsString( 'Not written (Nothing was written: the object changed right before the call', (string) $parts['instance:text-2']['note'] );
	}

	public function testAHookOfTheRightThatChangesTheInstanceTheStepWritesKeepsItsChangeAndTheStepOwnsNothing(): void {
		// Codex round 3 of branch 11, A6: a hook of the right (user_has_cap)
		// that allows and changes the very instance the step is to write, as
		// the writer asks its right right before the call. The writer reads
		// the area after it, compares it with the state the engine read, and
		// writes nothing; the change of the hook stays, and the engine marks
		// the part as another hand's, never as the step's own.
		self::pair();
		$text = self::widget_settings( 'text', array( 'title' => 'Hours', 'text' => 'Mo–Fr', 'filter' => true, 'visual' => true ) );
		self::widgets_on_both( array( 'text-2' => $text ) );
		self::paired( self::staged() );
		$row      = self::option_of( 'a', 'widget_text' );
		$text_new = self::widget_settings( 'text', array( 'title' => 'Opening hours', 'text' => 'Mo–Sa', 'filter' => true, 'visual' => true ) );
		$row_b    = $row;
		$row_b[2] = $text_new;
		self::one_b( array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $row_b ) );
		$planned = self::planned( array( array( 'widget_area', 'live', 'sidebar-1' ) ) );
		$changed = false;
		add_filter(
			'user_has_cap',
			static function ( $allcaps ) use ( &$changed ) {
				$asking = false;
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					$asking = $asking || ( 'AB_MCP_Sync_Write' === ( $frame['class'] ?? '' ) && 'core' === $frame['function'] );
				}
				if ( $asking && ! $changed ) {
					$changed           = true;
					$other             = get_option( 'widget_text' );
					$other[2]['title'] = 'Changed by the hook';
					update_option( 'widget_text', $other );
				}
				return $allcaps;
			}
		);
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		remove_all_filters( 'user_has_cap' );
		self::assertTrue( $changed, 'The hook ran as the right was asked.' );
		self::assertSame( 'Changed by the hook', self::option_of( 'a', 'widget_text' )[2]['title'], 'Its change stays: nothing was written over it.' );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$step = self::writes( self::run_status( (int) $run['run_id'] ) )[0];
		self::assertSame( 'unclear', $step['state'], json_encode( $step ) );
		self::assertStringContainsString( 'the part instance:text-2 was not written: the part instance:text-2 changed right before the call', (string) $step['parts']['object']['deviated']['why'] );
		$part = array_column( $step['parts']['parts'], null, 'part' )['instance:text-2'];
		self::assertTrue( empty( $part['own'] ), 'Not the step\'s own: ' . json_encode( $part ) );
		$stored = array_column( \AB_MCP_Sync_Run::step( (int) $run['run_id'], (int) $step['seq'] )['parts']['parts'], null, 'part' )['instance:text-2'];
		self::assertTrue( ! empty( $stored['foreign'] ), 'Another hand\'s, in the journal as it is stored.' );
		self::assertSame( 'left', $part['state'], 'Left with its value before.' );
		self::assertStringContainsString( 'Changed right before the step\'s call of the part instance:text-2', (string) $part['note'] );
	}

	public function testAHookOfTheRightThatChangesTheOptionAndThenRefusesTheRightLeavesNothingOfItTheStepsOwn(): void {
		// Codex round 4 of branch 11, item 3: a hook of the right that
		// changes blogname as the writer asks manage_options right before
		// its call, and then refuses it. No call of the core is made; before,
		// the journal named the row the step's own (own true, foreign false),
		// because it reads other than before. Every refusal of the helper
		// owns nothing, not only a changed state.
		self::pair();
		update_option( 'blogname', 'A shop' );
		self::one_b( array( 'op' => 'update_option', 'name' => 'blogname', 'value' => 'A shop' ) );
		self::paired( self::staged() );
		self::one_b( array( 'op' => 'update_option', 'name' => 'blogname', 'value' => 'A shop & more' ) );
		$planned = self::planned( array( array( 'option', 'live', 'blogname' ) ) );
		$asked   = false;
		add_filter(
			'user_has_cap',
			static function ( $allcaps, $caps ) use ( &$asked ) {
				$writing = false;
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					$writing = $writing || ( 'AB_MCP_Sync_Write' === ( $frame['class'] ?? '' ) && 'core' === $frame['function'] );
				}
				if ( $writing && in_array( 'manage_options', (array) $caps, true ) ) {
					if ( ! $asked ) {
						$asked = true;
						update_option( 'blogname', 'Changed by the hook' );
					}
					unset( $allcaps['manage_options'] );
				}
				return $allcaps;
			},
			10,
			2
		);
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		remove_all_filters( 'user_has_cap' );
		self::assertTrue( $asked, 'The hook ran as the right was asked.' );
		self::assertSame( 'Changed by the hook', AB_MCP_IT_Sites::option_row( 'a', 'blogname' )['option_value'], 'Not written: the change of the hook stays.' );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$step = self::writes( self::run_status( (int) $run['run_id'] ) )[0];
		self::assertSame( 'unclear', $step['state'], json_encode( $step ) );
		self::assertStringContainsString( 'the part row was not written (Not written: this account may not change the option blogname', (string) $step['parts']['object']['deviated']['why'] );
		self::assertStringContainsString( 'the part row changed right before it', (string) $step['parts']['object']['deviated']['why'] );
		$stored = array_column( \AB_MCP_Sync_Run::step( (int) $run['run_id'], (int) $step['seq'] )['parts']['parts'], null, 'part' )['row'];
		self::assertTrue( empty( $stored['own'] ), 'No call of the core: nothing of it the step\'s own. ' . json_encode( $stored ) );
		self::assertTrue( ! empty( $stored['foreign'] ), 'Another hand\'s.' );
		self::assertSame( 'left', $stored['state'] );
	}

	public function testAHookInTheOwnCallThatWritesAnotherInstanceOfTheRowNestedIsRefused(): void {
		// Codex round 5 of branch 11, C1: a hook in
		// pre_update_option_widget_text, run in the step's own update_option()
		// of the row, writes another instance of the same row nested
		// (text-3.title = FOREIGN). Before, the request owned the boundary
		// whole: the nested write went through, and the outer call then stored
		// the row it had read before over it, unseen by the comparison before
		// and after. Now, while its own call runs, the row takes the planned
		// value only (AB_MCP_Sync_Guard::only(), decision 24): the nested write
		// is refused as any other hand's, never silently: the journal names
		// it, the object deviates (decision A) with the field and the way.
		self::pair();
		$text = self::widget_settings( 'text', array( 'title' => 'Hours', 'text' => 'Mo–Fr', 'filter' => true, 'visual' => true ) );
		self::widgets_on_both( array( 'text-2' => $text ) );
		$row                = self::option_of( 'a', 'widget_text' );
		$row[3]             = $text;
		$areas              = self::option_of( 'a', 'sidebars_widgets' );
		$areas['sidebar-2'] = array_merge( $areas['sidebar-2'], array( 'text-3' ) );
		update_option( 'widget_text', $row );
		update_option( 'sidebars_widgets', $areas );
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $row ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas ),
			)
		);
		self::paired( self::staged() );
		$text_new = self::widget_settings( 'text', array( 'title' => 'Opening hours', 'text' => 'Mo–Sa', 'filter' => true, 'visual' => true ) );
		$row_b    = $row;
		$row_b[2] = $text_new;
		self::one_b( array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $row_b ) );
		$planned = self::planned( array( array( 'widget_area', 'live', 'sidebar-1' ) ) );
		$nested  = null;
		$between = null;
		add_filter(
			'pre_update_option_widget_text',
			static function ( $value ) use ( &$nested, &$between ) {
				$writing = false;
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					$writing = $writing || ( 'AB_MCP_Sync_Widget_Area_Writer' === ( $frame['class'] ?? '' ) && 'write_part' === $frame['function'] );
				}
				if ( $writing && null === $nested ) {
					$nested            = 'running';
					$other             = get_option( 'widget_text' );
					$other[3]['title'] = 'FOREIGN';
					$nested            = update_option( 'widget_text', $other );
					$between           = maybe_unserialize( AB_MCP_IT_Sites::option_row( 'a', 'widget_text' )['option_value'] )[3]['title'];
				}
				return $value;
			}
		);
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		remove_all_filters( 'pre_update_option_widget_text' );
		self::assertNotNull( $nested, 'The hook ran in the own call.' );
		self::assertFalse( $nested, 'The nested write of the hook is refused.' );
		self::assertSame( 'Hours', $between, 'Never in the row, not for a moment.' );
		self::assertSame( $text_new, self::option_of( 'a', 'widget_text' )[2], 'The own instance is written.' );
		self::assertSame( 'Hours', self::option_of( 'a', 'widget_text' )[3]['title'] );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$step = self::writes( self::run_status( (int) $run['run_id'] ) )[0];
		self::assertSame( 'unclear', $step['state'], json_encode( $step ) );
		self::assertStringContainsString( 'another writer tried to change the field 3 of the row widget_text as the step\'s own call wrote it (a hook or filter of this site) and was refused', (string) $step['parts']['object']['deviated']['why'] );
		self::assertStringContainsString( 'find the plugin or theme that writes it', (string) $step['parts']['object']['deviated']['why'], 'With its way.' );
		$stored = \AB_MCP_Sync_Run::step( (int) $run['run_id'], (int) $step['seq'] )['parts']['object'];
		self::assertEquals( array( array( 'fields' => array( '3' ), 'option' => 'widget_text' ) ), $stored['refused_writes'], 'In the journal.' );
	}

	public function testAHookInTheOwnCallThatSetsAnotherPositionNestedIsRefused(): void {
		// Codex round 5 of branch 11, C1, for the positions: a hook in
		// pre_set_theme_mod_nav_menu_locations, run in the step's own
		// set_theme_mod(), sets another position nested (footer). Before, the
		// outer call then stored the theme mods it had read before over it.
		// Now the nested write to the row is refused, named in the journal,
		// and the object deviates.
		self::pair();
		$old_a = (int) wp_create_nav_menu( 'Old' );
		$old_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Old' ) );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $old_a ) );
		self::one_b( array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'primary' => $old_b ) ) );
		self::paired( self::staged() );
		$new_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Footer' ) );
		self::one_b( array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'primary' => $new_b ) ) );
		$planned = self::planned( array( array( 'menu', 'staging', (string) $new_b ) ) );
		$nested  = null;
		$between = null;
		$hook    = static function ( $value ) use ( $old_a, &$nested, &$between ) {
			$writing = false;
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
				$writing = $writing || ( 'AB_MCP_Sync_Menu_Writer' === ( $frame['class'] ?? '' ) && 'write_part' === $frame['function'] );
			}
			if ( $writing && null === $nested ) {
				$nested = 'running';
				$nested = set_theme_mod( 'nav_menu_locations', array( 'footer' => $old_a ) + (array) get_theme_mod( 'nav_menu_locations', array() ) );
				$mods   = maybe_unserialize( AB_MCP_IT_Sites::option_row( 'a', 'theme_mods_twentytwentyfive' )['option_value'] );
				$between = $mods['nav_menu_locations']['footer'] ?? null;
			}
			return $value;
		};
		add_filter( 'pre_set_theme_mod_nav_menu_locations', $hook );
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		remove_filter( 'pre_set_theme_mod_nav_menu_locations', $hook );
		self::assertNotNull( $nested, 'The hook ran in the own call.' );
		self::assertFalse( $nested, 'The nested write of the hook is refused.' );
		self::assertNull( $between, 'Never in the row, not for a moment.' );
		wp_cache_flush();
		$new_a = (int) get_term_by( 'slug', 'footer', 'nav_menu' )->term_id;
		self::assertSame( array( 'primary' => $new_a ), self::option_of( 'a', 'theme_mods_twentytwentyfive' )['nav_menu_locations'], 'The own position is set, the other one not.' );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$step = self::writes( self::run_status( (int) $run['run_id'] ) )[0];
		self::assertSame( 'unclear', $step['state'], json_encode( $step ) );
		self::assertStringContainsString( 'another writer tried to change the field nav_menu_locations.footer of the row theme_mods_twentytwentyfive', (string) $step['parts']['object']['deviated']['why'] );
	}

	/** The save of unit_rest() that fails: the new bases, or the state residual. */
	public static function failingSaves(): array {
		return array(
			'the new bases'      => array( 'SET canon = ' ),
			'the state residual' => array( "SET state = 'residual'" ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'failingSaves' )]
	public function testAPairThatCannotBeKeptAsARestMakesTheStepUnclearWithItsWay( string $failing ): void {
		// Codex round 1 of branch 11, C1: a step that made an instance and
		// deviated keeps its unit as a rest (unit_rest()). Where the pairs do
		// not take that save (either of its two), the step says so and is
		// unclear: a later run could make the part again.
		self::pair();
		$text = self::widget_settings( 'text', array( 'title' => 'Hours', 'text' => 'Mo–Fr', 'filter' => true, 'visual' => true ) );
		self::widgets_on_both( array( 'text-2' => $text ) );
		$row                = self::option_of( 'a', 'widget_text' );
		$row[3]             = $text;
		$areas              = self::option_of( 'a', 'sidebars_widgets' );
		$areas['sidebar-2'] = array_merge( $areas['sidebar-2'], array( 'text-3' ) );
		update_option( 'widget_text', $row );
		update_option( 'sidebars_widgets', $areas );
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $row ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas ),
			)
		);
		self::paired( self::staged() );
		$row_b                = $row;
		$row_b[5]             = self::widget_settings( 'text', array( 'title' => 'News', 'text' => 'New', 'filter' => true, 'visual' => true ) );
		$areas_b              = $areas;
		$areas_b['sidebar-1'] = array_merge( $areas['sidebar-1'], array( 'text-5' ) );
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $row_b ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas_b ),
			)
		);
		$planned = self::planned( array( array( 'widget_area', 'live', 'sidebar-1' ) ) );
		$pair_id = (int) AB_MCP_Sync_Pairs::find_live( self::source(), 'widget_area', 'sidebar-1' )['id'];
		// Right after the new instance is written, a hook of the row changes
		// another one (the step deviates, the instance stays made; Codex
		// round 3, A6: a change before the call, in the update() of the
		// widget, would leave it not made); the save of the pair as a rest
		// fails.
		$changed = false;
		add_action(
			'update_option_widget_text',
			static function ( $old, $value ) use ( &$changed ) {
				unset( $old );
				$writing = false;
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					$writing = $writing || ( 'AB_MCP_Sync_Widget_Area_Writer' === ( $frame['class'] ?? '' ) && 'write_part' === $frame['function'] );
				}
				if ( $writing && ! $changed && isset( $value[5] ) ) {
					$changed           = true;
					$other             = get_option( 'widget_text' );
					$other[3]['title'] = 'Changed by the hook';
					update_option( 'widget_text', $other );
				}
			},
			10,
			2
		);
		add_filter(
			'query',
			static function ( $query ) use ( $failing ) {
				$saving = false;
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					$saving = $saving || ( 'AB_MCP_Sync_Engine' === ( $frame['class'] ?? '' ) && 'unit_rest' === $frame['function'] );
				}
				return $saving && 0 === stripos( ltrim( (string) $query ), 'UPDATE' ) && false !== strpos( (string) $query, $failing ) ? 'UPDATE ab_mcp_no_such_table SET x = 1' : $query;
			}
		);
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		remove_all_actions( 'update_option_widget_text' );
		remove_all_filters( 'query' );
		self::assertTrue( $changed, 'The hook ran after the instance was written.' );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$step = self::writes( self::run_status( (int) $run['run_id'] ) )[0];
		self::assertSame( 'unclear', $step['state'], json_encode( $step ) );
		self::assertStringContainsString( 'could not be kept as a rest', (string) $step['result']['message'] );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::get( $pair_id )['state'], 'The state did not change.' );
	}

	/* ----------------------------------------------------------- options */

	public function testChangedCoreOptionsGoOverAndBackAndThePermalinksBuildTheirRulesAnew(): void {
		// Core options with a schema (decision 27): blogname, blogdescription,
		// posts_per_page and the permalinks, equal and paired on both sites,
		// changed on staging. Each goes over through update_option(); the
		// permalinks build the rewrite rules anew (decision 33, A19); the way
		// back puts every one back byte for byte, and the rules with them.
		self::pair();
		foreach ( array( 'blogname' => 'A shop', 'blogdescription' => 'Things', 'posts_per_page' => '10', 'permalink_structure' => '/%year%/%postname%/' ) as $name => $value ) {
			update_option( $name, $value );
			self::one_b( array( 'op' => 'update_option', 'name' => $name, 'value' => $value ) );
		}
		// The rules of that structure built here, as its permalink screen
		// leaves them: a run that changes the structure has to build them anew.
		$GLOBALS['wp_rewrite']->init();
		flush_rewrite_rules( false );
		self::paired( self::staged() );
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'blogname', 'value' => 'A shop & more' ),
				array( 'op' => 'update_option', 'name' => 'blogdescription', 'value' => 'Fine things' ),
				array( 'op' => 'update_option', 'name' => 'posts_per_page', 'value' => '6' ),
				array( 'op' => 'update_option', 'name' => 'permalink_structure', 'value' => '/%postname%/' ),
			)
		);
		$names  = array( 'blogname', 'blogdescription', 'posts_per_page', 'permalink_structure' );
		$before = array();
		foreach ( $names as $name ) {
			$before[ $name ] = AB_MCP_IT_Sites::option_row( 'a', $name )['option_value'];
		}
		// The rule of a post by its name alone is not among the rules of this
		// site before (they are of another structure), so its rules show the
		// flush of the run.
		$name_rule  = '"([^/]+)(?:/([0-9]+))?/?$"';
		$rules_were = (string) ( AB_MCP_IT_Sites::option_row( 'a', 'rewrite_rules' )['option_value'] ?? '' );
		self::assertStringContainsString( '([0-9]{4})/([^/]+)(?:/([0-9]+))?/?$', $rules_were );
		self::assertStringNotContainsString( $name_rule, $rules_were );
		$planned = self::planned( array_map( static fn( $n ) => array( 'option', 'live', $n ), $names ) );
		$this->watch_deletions();
		$run = self::begun( $planned['args'] );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'], 20 )['state'], json_encode( self::run_status( (int) $run['run_id'] ) ) );
		self::assertWrittenWhole( self::run_status( (int) $run['run_id'] ) );
		self::assertSame( array( 'A shop &amp; more', 'Fine things', '6', '/%postname%/' ), array_map( static fn( $n ) => AB_MCP_IT_Sites::option_row( 'a', $n )['option_value'], $names ) );
		$rules = (string) AB_MCP_IT_Sites::option_row( 'a', 'rewrite_rules' )['option_value'];
		self::assertStringContainsString( $name_rule, $rules, 'The rewrite rules of the new structure.' );
		// Changed by hand since the run (Entscheid A): left as it is, the
		// others back byte for byte.
		update_option( 'blogdescription', 'Changed by hand' );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		self::assertSame( 'Changed by hand', AB_MCP_IT_Sites::option_row( 'a', 'blogdescription' )['option_value'] );
		foreach ( array_diff( $names, array( 'blogdescription' ) ) as $name ) {
			self::assertSame( $before[ $name ], AB_MCP_IT_Sites::option_row( 'a', $name )['option_value'], $name );
		}
		self::assertSame( array(), $this->deletions, 'No option of the site was deleted.' );
	}

	public function testAFieldOfASharedRowGoesBackOnlyWhereItsBytesAreStillThoseReadAndItsCachesForgetIt(): void {
		// The raw way back of a shared row (decision 24): the row is written
		// only where it holds exactly the bytes read, in length and in case,
		// and the caches of the option (alloptions) do not keep the old value.
		update_option( 'blogname', 'Old' );
		self::assertSame( 'Old', get_option( 'blogname' ) );
		self::assertFalse( AB_MCP_Sync_Shared_Row::put_back( 'blogname', 'Ol', 'X' ) );
		self::assertFalse( AB_MCP_Sync_Shared_Row::put_back( 'blogname', 'Old ', 'X' ), 'Not with one space more.' );
		self::assertFalse( AB_MCP_Sync_Shared_Row::put_back( 'blogname', 'OLD', 'X' ), 'Nor in other capitals.' );
		self::assertSame( 'Old', AB_MCP_IT_Sites::option_row( 'a', 'blogname' )['option_value'] );
		self::assertTrue( AB_MCP_Sync_Shared_Row::put_back( 'blogname', 'Old', 'X' ) );
		self::assertSame( 'X', AB_MCP_IT_Sites::option_row( 'a', 'blogname' )['option_value'] );
		self::assertSame( 'X', get_option( 'blogname' ), 'The caches of the option forget it.' );
	}

	/* ------------------------------------------------------------- locks */

	public function testWhatARunCannotWriteAsTheCoreWouldIsLockedWithItsKeyAndNothingOfItIsWritten(): void {
		// The rare cases fail closed, each with its own key, reason and way
		// (Grenzen C of branch 11, GRENZEN-STRUKTUR.md), one of each stage:
		// an option of a plugin is locked in the report already
		// (option:unclassified); Additional CSS with the closing tag of STYLE
		// (custom_css:markup, the rule of WP_Customize_Custom_CSS_Setting::
		// validate()) and a new menu at a position the theme of this site does
		// not register (menu:location_unregistered) are refused by the check
		// of the run, before any step writes. The site stays as it was.
		self::pair();
		wp_update_custom_css_post( 'body { color: #111111; }' );
		update_option( 'acme_settings', 'a' );
		self::b(
			array(
				array( 'op' => 'custom_css', 'css' => 'body { color: #111111; }' ),
				array( 'op' => 'update_option', 'name' => 'acme_settings', 'value' => 'a' ),
			)
		);
		self::paired( self::staged() );
		$menu_b = (int) self::one_b( array( 'op' => 'nav_menu', 'name' => 'Aside' ) );
		self::b(
			array(
				array( 'op' => 'custom_css', 'css' => 'body { color: #222222; } </style>' ),
				array( 'op' => 'update_option', 'name' => 'acme_settings', 'value' => 'b' ),
				array( 'op' => 'theme_mod', 'name' => 'nav_menu_locations', 'value' => array( 'aside' => $menu_b ) ),
			)
		);
		$css_a       = (int) self::option_of( 'a', 'theme_mods_twentytwentyfive' )['custom_css_post_id'];
		$mods_before = AB_MCP_IT_Sites::option_row( 'a', 'theme_mods_twentytwentyfive' )['option_value'];
		$wanted      = array(
			array( 'custom_css', 'live', 'twentytwentyfive' ),
			array( 'option', 'live', 'acme_settings' ),
			array( 'menu', 'staging', (string) $menu_b ),
		);
		$planned     = self::planned( $wanted );
		$option      = self::entry( $planned['report']['all'], 'option', 'live', 'acme_settings' );
		self::assertSame( 'locked', $option['action'], json_encode( $option ) );
		self::assertSame( 'option:unclassified', $option['locks'][0]['key'] );
		$preview = self::gated( 'wp_site_push', $planned['args'] );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertSame( array( 'option' ), array_column( $preview['preview']['locked'], 'kind' ), 'The option is not in the steps.' );
		self::assertSame( array( 'custom_css', 'menu' ), array_map( static fn( $s ) => $s['object']['kind'], array_values( array_filter( $preview['preview']['steps'], static fn( $s ) => 'write' === $s['op'] ) ) ) );
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $closed['state'], json_encode( $closed ) );
		$status = self::run_status( (int) $run['run_id'] );
		$found  = self::findings( $status );
		self::assertStringContainsString( 'custom_css:markup', $found['twentytwentyfive'] ?? '', json_encode( $found ) );
		self::assertStringContainsString( 'menu:location_unregistered', $found[ (string) $menu_b ] ?? '', json_encode( $found ) );
		self::assertNotContains( 'done', array_column( self::writes( $status ), 'state' ), 'No step wrote.' );
		self::assertSame( 'body { color: #111111; }', self::post_row_a( $css_a )['post_content'] );
		self::assertSame( 'a', AB_MCP_IT_Sites::option_row( 'a', 'acme_settings' )['option_value'] );
		self::assertSame( $mods_before, AB_MCP_IT_Sites::option_row( 'a', 'theme_mods_twentytwentyfive' )['option_value'], 'No position set.' );
		wp_cache_flush();
		self::assertFalse( get_term_by( 'slug', 'aside', 'nav_menu' ), 'No menu made.' );
		self::assertSame( array(), $this->deletions );
	}
}
