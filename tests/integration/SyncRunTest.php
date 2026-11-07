<?php
/**
 * wp_site_push and wp_site_revert on two WordPress sites (Z8, decisions 15
 * to 20, 23, 32 and 33 of the plan; branch 8): site B is the staging site,
 * in processes of its own; site A, this process, is the production site
 * with B's key confirmed, takes B's packages over, pairs, reports, plans a
 * run, writes it step by step, checks it and takes it back.
 *
 * - End to end: report → plan (preview and token in the same call) → run
 *   → one step per call (the check, a changed page, a new page, the caches,
 *   the check of the run) → the pairs with new bases → wp_site_revert: the
 *   changed page back byte for byte, the new page left as a rest with its
 *   pair residual, the run closed with findings; a second confirmed plan
 *   gives the same run_id; a second plan while a run is open is refused;
 *   another connection is refused; the backups of the run lie in the
 *   protected store; a qualified close marks forward_closed_at.
 * - The check before the first write: a page changed here since the plan
 *   closes the run as aborted, nothing written.
 * - Interruption (decision 18): a request that exits inside a boundary
 *   leaves the step begun; once the lock is gone the run is interrupted, no
 *   next and no new run; wp_site_revert leads the same run to reverting and
 *   puts the part back.
 * - Byte for byte (decision 23): a meta changed from abc to ABC and a title
 *   given a trailing space after the run are left by the way back.
 * - The lock matrix (decision 19): a REST save of another user during a
 *   boundary gets the message of the boundary; update_metadata_by_mid() is
 *   refused; the owner passes.
 * - The target (decision 21): a published page of the top level while a
 *   menu adds pages by itself is locked, with as_draft written as draft,
 *   its pair with the override; the next report shows it equal and names
 *   the target.
 * - The time-dependent status (decision 17, 23): a scheduled staging post
 *   whose date passed is refused by the check; the way back of a backed-up
 *   future after its date is «check needed», nothing published.
 * - The retention (decision 20): the fourth qualified close pushes the
 *   first run's backups out; a way back that is not closed keeps the
 *   backups it needs; unqualified closes count for nothing.
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
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Resources;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-revert.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';

final class SyncRunTest extends TestCase {

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
		AB_MCP_Sync_Backup::forget();
		AB_MCP_Sync_Guard::disarm();
		delete_option( AB_MCP_Sync_Guard::OPTION );
		delete_option( \AB_MCP_Change_Lock::OPTION );
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
		AB_MCP_Sync_Backup::forget();
		AB_MCP_Sync_Guard::disarm();
		AB_MCP_Sync_Run::$clock       = null;
		AB_MCP_Sync_Engine::$clock    = null;
		AB_MCP_Sync_Engine::$probe    = null;
		AB_MCP_Sync_Resources::$probe = null;
		AB_MCP_Sync_Report::$page     = null;
		remove_all_filters( 'wp_insert_post_data' );
		remove_all_filters( 'sanitize_post_meta_note' );
		remove_all_actions( 'set_object_terms' );
		delete_option( 'nav_menu_options' );
	}

	/**
	 * The probe of a published object answers as the live page would: 200
	 * and the title of the object in the body (decision 33; the real request
	 * is the live probe, GRENZEN-LAUF.md).
	 */
	private static function probe_ok(): void {
		AB_MCP_Sync_Engine::$probe = static function ( string $url ): array {
			$id = url_to_postid( $url );
			return array(
				'status' => 200,
				'body'   => '<html><head><title>' . esc_html( $id > 0 ? (string) get_post_field( 'post_title', $id, 'raw' ) : '' ) . '</title></head></html>',
			);
		};
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

	/** A tool over MCP on A: its structured answer, or the text of its error. */
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
	private static function change_on_b( string $id, string $name, string $content, array $over = array() ): void {
		self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => self::page( $name, $content, array( 'ID' => (int) $id ) + $over ),
				),
			)
		);
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

	/** A package of B taken over here, chunk by chunk (relay in this process). */
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
		self::assertSame( 'objects', $package['purpose'] );
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

	/** Every entry of these categories of a fresh proposal paired. */
	private static function paired( array $ids, array $categories = array( 'equal' ) ): void {
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

	/** The entry of a post by its slug, on either side. */
	private static function post_entry( array $entries, string $name ): array {
		foreach ( $entries as $entry ) {
			foreach ( array( 'staging', 'live' ) as $side ) {
				if ( 'post' === $entry['kind'] && isset( $entry[ $side ]['identity']['post_name'] ) && $name === $entry[ $side ]['identity']['post_name'] ) {
					return $entry;
				}
			}
		}
		self::fail( "No entry for $name." );
	}

	/** The staging refs of entries, as the export of objects takes them. */
	private static function refs_of( array $entries ): array {
		$refs = array();
		foreach ( $entries as $entry ) {
			if ( isset( $entry['staging']['ref'] ) ) {
				$refs[] = array(
					'kind' => $entry['kind'],
					'ref'  => (string) $entry['staging']['ref'],
				);
			}
		}
		return $refs;
	}

	/** The report, the objects package and the arguments of a plan for these entries. */
	private static function planned( array $names, array $extra = array() ): array {
		$ids    = self::staged();
		$report = self::report( $ids );
		$select = array();
		$chosen = array();
		foreach ( $names as $name ) {
			$entry    = self::post_entry( $report['all'], $name );
			$select[] = (int) $entry['n'];
			$chosen[] = $entry;
		}
		$package = self::objects( $report['report_hash'], self::refs_of( $chosen ) );
		return array(
			'report' => $report,
			'args'   => array(
				'action'    => 'plan',
				'report_id' => $report['report_id'],
				'select'    => $select,
				'packages'  => array( $package ),
			) + $extra,
		);
	}

	/** A plan confirmed in this process: the run. */
	private static function begun( array $args ): array {
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertTrue( $preview['confirm_required'] );
		self::assertArrayHasKey( 'steps', $preview['preview'] );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		self::assertSame( 'open', $run['state'] );
		return $run;
	}

	/** wp_site_push action=next, in this process. */
	private static function next( int $run_id ) {
		return self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
	}

	/** Steps until the run is closed, at most $max. */
	private static function finish( int $run_id, int $max = 12 ): array {
		$answer = null;
		for ( $i = 0; $i < $max; $i++ ) {
			$answer = self::next( $run_id );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return $answer;
			}
		}
		self::fail( 'The run did not close within ' . $max . ' steps: ' . json_encode( $answer ) );
	}

	/** The way back of a run planned and confirmed in this process. */
	private static function revert( ?int $run_id, array $extra = array() ): array {
		$args    = array( 'action' => 'plan' ) + ( null === $run_id ? array() : array( 'run_id' => $run_id ) ) + $extra;
		$preview = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertTrue( $preview['confirm_required'] );
		$begun = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
		return $begun;
	}

	/** wp_site_revert action=next until closed. */
	private static function revert_all( int $run_id, int $max = 12 ): array {
		$answer = null;
		for ( $i = 0; $i < $max; $i++ ) {
			$answer = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $run_id ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return $answer;
			}
		}
		self::fail( 'The way back did not close within ' . $max . ' steps.' );
	}

	/**
	 * The row of a post of A, read past every cache, without the columns
	 * WordPress writes by itself on every save (post_modified, which the
	 * takeover never compares either, AB_MCP_Sync_Exclusions).
	 */
	private static function row_a( int $id ): array {
		$rows = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $id ) );
		self::assertCount( 1, $rows, "Post $id of A." );
		unset( $rows[0]['post_modified'], $rows[0]['post_modified_gmt'] );
		return $rows[0];
	}

	/** A meta value of a post of A, read past every cache. */
	private static function meta_a( int $id, string $key ): array {
		return array_column( AB_MCP_IT_Sites::rows( 'a', 'postmeta', array( 'post_id' => (string) $id, 'meta_key' => $key ) ), 'meta_value' );
	}

	private static function refused( $result, string $code ): string {
		self::assertInstanceOf( WP_Error::class, $result, is_array( $result ) ? (string) json_encode( $result ) : ( is_scalar( $result ) ? (string) $result : gettype( $result ) ) );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result->get_error_message();
	}

	/** The step of a status (its steps) or of the answer of a step (the step that ran) by its seq. */
	private static function step( array $shown, int $seq ): array {
		foreach ( isset( $shown['steps'] ) && is_array( $shown['steps'] ) ? $shown['steps'] : array() as $step ) {
			if ( (int) $step['seq'] === $seq ) {
				return $step;
			}
		}
		if ( isset( $shown['step']['seq'] ) && (int) $shown['step']['seq'] === $seq ) {
			return $shown['step'];
		}
		self::fail( "No step $seq." );
	}

	/* ------------------------------------------------------- end to end */

	public function testARunWritesAChangedPageAndANewPageAndTheWayBackLeavesTheNewOneAsARest(): void {
		self::pair();
		$a = self::pages_on_a( array( 'about' => 'About us.', 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'about' => 'About us.', 'contact' => 'Write to us.' ) );
		// A plain meta key on both sides, so the row and the meta go together.
		add_post_meta( (int) $a['contact'], 'note', 'abc' );
		self::b( array( array( 'op' => 'add_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'abc' ) ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'staging-new', 'Only on staging.' ) ) ) );
		$planned = self::planned( array( 'contact', 'staging-new' ) );
		$before  = self::row_a( (int) $a['contact'] );

		// The preview names the steps; the confirmed call makes the run.
		$preview = self::gated( 'wp_site_push', $planned['args'] );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertSame( array( 'check', 'write', 'write', 'post', 'verify' ), array_column( $preview['preview']['steps'], 'op' ) );
		self::assertSame( 2, $preview['preview']['objects'] );
		self::assertStringStartsWith( 'not compared', $preview['preview']['environment'], 'The report was made without the environment: said, not held back.' );
		self::assertSame( array(), $preview['preview']['locked'] );
		// A site without a run hooks nothing for the lock (N3); making the run
		// marks it, before its first step.
		update_option( AB_MCP_Sync_Guard::MARK, '0', true );
		$run = self::gated( 'wp_site_push', $planned['args'] + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		self::assertTrue( $run['created_now'] );
		$run_id = (int) $run['run_id'];
		self::assertSame( $before, self::row_a( (int) $a['contact'] ), 'Making the run writes nothing.' );
		self::assertSame( '1', get_option( AB_MCP_Sync_Guard::MARK ), 'The site has a run: its requests hook the filters of the lock from here on.' );

		// The same confirmed call again: the same run (decision 15).
		$again = self::gated( 'wp_site_push', $planned['args'] + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_message() : '' );
		self::assertSame( $run_id, (int) $again['run_id'] );
		self::assertFalse( $again['created_now'] );
		// A second plan while the run is open is refused with the way.
		$other = self::gated( 'wp_site_push', array_merge( $planned['args'], array( 'select' => array( $planned['args']['select'][0] ) ) ) );
		self::assertStringContainsString( 'at most one run per site', self::refused( $other, 'ab_mcp_sync_run_open' ) );
		// Another connection (the token of MCP) is refused.
		$foreign = self::tool( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) );
		self::assertIsString( $foreign );
		self::assertStringContainsString( 'another account or connection', $foreign );

		// Step 1: the check.
		$check = self::next( $run_id );
		self::assertIsArray( $check, is_wp_error( $check ) ? $check->get_error_message() : '' );
		self::assertSame( array( 1, 'check', 'done' ), array( $check['step']['seq'], $check['step']['op'], $check['step']['state'] ) );
		self::assertSame( 2, $check['step']['result']['checked'] );
		self::assertSame( $before, self::row_a( (int) $a['contact'] ), 'The check writes nothing.' );
		// action=step cannot run the caches (phase 7) before the write steps
		// (decision 16; B2): the phases go in order.
		self::assertStringContainsString( 'phase 7', self::refused( self::gated( 'wp_site_push', array( 'action' => 'step', 'seq' => 4, 'run_id' => $run_id ) ), 'ab_mcp_sync_step_waits' ) );
		self::assertSame( 'planned', self::step( self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) ), 4 )['state'] );
		// Step 2: the changed page.
		$write = self::next( $run_id );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( array( 2, 'write', 'done' ), array( $write['step']['seq'], $write['step']['op'], $write['step']['state'] ) );
		self::assertSame( (string) $a['contact'], $write['step']['result']['live_ref'] );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'] );
		self::assertSame( array( 'row', 'meta:note' ), array_column( $write['step']['parts']['parts'], 'part' ) );
		self::assertSame( array( 'read', 'read' ), array_column( $write['step']['parts']['parts'], 'state' ) );
		self::assertMatchesRegularExpression( '/^step-0002$/', $write['step']['parts']['backup']['name'] );
		// Step 3: the new page.
		$create = self::next( $run_id );
		self::assertIsArray( $create );
		self::assertSame( array( 3, 'write', 'done' ), array( $create['step']['seq'], $create['step']['op'], $create['step']['state'] ) );
		self::assertTrue( $create['step']['result']['created'] );
		$new_id = (int) $create['step']['result']['live_ref'];
		self::assertSame( 'staging-new', self::row_a( $new_id )['post_name'] );
		self::assertSame( 'publish', self::row_a( $new_id )['post_status'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $new_id );
		self::assertIsArray( $pair );
		self::assertSame( array( 'active', 'push', $run_id ), array( $pair['state'], $pair['base_origin'], $pair['last_run'] ) );
		// Steps 4 and 5: the caches and the check, which closes the run.
		self::probe_ok();
		$post = self::next( $run_id );
		self::assertSame( array( 4, 'post', 'done' ), array( $post['step']['seq'], $post['step']['op'], $post['step']['state'] ) );
		$verify = self::next( $run_id );
		self::assertIsArray( $verify, is_wp_error( $verify ) ? $verify->get_error_message() : '' );
		self::assertSame( array( 5, 'verify', 'done' ), array( $verify['step']['seq'], $verify['step']['op'], $verify['step']['state'] ) );
		self::assertSame( array(), $verify['step']['result']['findings'], json_encode( $verify['step']['result'] ) );
		self::assertSame( 'closed_done', $verify['state'] );
		$stored = AB_MCP_Sync_Run::get( $run_id );
		self::assertGreaterThan( 0, $stored['forward_closed_at'], 'A qualified close (decision 20).' );
		self::assertNull( $stored['open_one'] );
		self::assertGreaterThanOrEqual( 6, $stored['calls'] );
		// The pair of the changed page moved on: the next report shows both equal.
		$report = self::report( self::staged() );
		self::assertSame( 'equal', self::post_entry( $report['all'], 'contact' )['result'] );
		self::assertSame( 'equal', self::post_entry( $report['all'], 'staging-new' )['result'] );
		// The backups lie in the protected store, bound to the run.
		$groups = array_values( array_filter( AB_MCP_Protected_Store::groups( 'backups' ), static fn( $g ) => (int) $g['run_id'] === $run_id ) );
		self::assertCount( 1, $groups );
		self::assertSame( array( 'step-0002', 'step-0003' ), array_keys( AB_MCP_Protected_Store::files( 'backups', $groups[0]['gid'] ) ) );

		// The way back: the changed page back byte for byte, the new page a rest.
		$begun = self::revert( $run_id );
		self::assertFalse( $begun['same_run'] );
		self::assertSame( 'revert', $begun['type'] );
		$back = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], 'A rest remains.' );
		self::assertSame( $before, self::row_a( (int) $a['contact'] ), 'The row is byte for byte what it was.' );
		self::assertSame( array( 'abc' ), self::meta_a( (int) $a['contact'], 'note' ) );
		self::assertSame( 'staging-new', self::row_a( $new_id )['post_name'], 'The new page stays.' );
		self::assertCount( 1, $back['rests'] );
		self::assertSame( (string) $new_id, $back['rests'][0]['live_ref'] );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::get( (int) $pair['id'] )['state'] );
		$contact = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $a['contact'] );
		self::assertSame( 'active', $contact['state'] );
		self::assertSame( 'proposal', $contact['base_origin'], 'The pair went back to its snapshot before the step.' );
		// A run of the way back is no qualified close.
		self::assertSame( 0, AB_MCP_Sync_Run::get( (int) $begun['run_id'] )['forward_closed_at'] );
		// The next report: the rest is named, nothing taken over for it.
		$report = self::report( self::staged() );
		self::assertSame( 'residual', self::post_entry( $report['all'], 'staging-new' )['result'] );
		self::assertSame( 'changed_on_staging', self::post_entry( $report['all'], 'contact' )['result'] );
	}

	public function testAReportIsPlannedInPartsOneRunAfterTheOther(): void {
		// More objects than one run takes go in parts (AB_MCP_Sync_Push::
		// MAX_OBJECTS; N4): a selection of this report, the run to its close,
		// then the next entries of the same report and the same package of
		// objects, as the refusal of a selection that is too large says.
		self::pair();
		$a = self::pages_on_a( array( 'about' => 'About us.', 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'about' => 'About us.', 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'about', 'contact' ) );
		self::probe_ok();
		list( $about, $contact ) = $planned['args']['select'];
		$first = self::begun( array_merge( $planned['args'], array( 'select' => array( $about ) ) ) );
		self::assertSame( 'closed_done', self::finish( (int) $first['run_id'] )['state'] );
		self::assertSame( 'About us, on staging.', self::row_a( (int) $a['about'] )['post_content'] );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'], 'The rest of the report is not written.' );
		// The next part: the same report_id and packages, the other entry.
		$second = self::begun( array_merge( $planned['args'], array( 'select' => array( $contact ) ) ) );
		self::assertNotSame( (int) $first['run_id'], (int) $second['run_id'] );
		self::assertSame( 'closed_done', self::finish( (int) $second['run_id'] )['state'] );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'] );
		// The next report shows both equal: nothing of the parts is left.
		$report = self::report( self::staged() );
		self::assertSame( 'equal', self::post_entry( $report['all'], 'about' )['result'] );
		self::assertSame( 'equal', self::post_entry( $report['all'], 'contact' )['result'] );
	}

	public function testTheCheckBeforeTheFirstWriteAbortsOnADeviationAndWritesNothing(): void {
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		// This site changes the page after the plan.
		wp_update_post( wp_slash( self::page( 'contact', 'Changed here after the plan.', array( 'ID' => (int) $a['contact'] ) ) ) );
		$before = self::row_a( (int) $a['contact'] );
		$check  = self::next( (int) $run['run_id'] );
		self::assertIsArray( $check, is_wp_error( $check ) ? $check->get_error_message() : '' );
		self::assertSame( 'failed', $check['step']['state'] );
		self::assertSame( 'closed_aborted', $check['state'] );
		self::assertStringContainsString( 'changed since the plan', $check['step']['result']['findings'][0]['message'] );
		self::assertSame( $before, self::row_a( (int) $a['contact'] ), 'Nothing written.' );
		self::assertSame( 0, AB_MCP_Sync_Run::get( (int) $run['run_id'] )['forward_closed_at'], 'No qualified close.' );
		self::assertStringContainsString( 'closed', self::refused( self::next( (int) $run['run_id'] ), 'ab_mcp_sync_run_state' ) );
		// No run holds a new plan back now; this report no longer holds for
		// the page, which changed since it was compared (GitHub Codex round
		// 1 on free#137): a new report, then a new plan.
		self::assertNull( AB_MCP_Sync_Run::open_run() );
		self::assertStringContainsString( 'new report', self::refused( self::gated( 'wp_site_push', $planned['args'] + array( 'confirm_token' => 'x' ) ), 'ab_mcp_sync_push_changed' ) );
	}

	public function testARequestThatExitsInABoundaryLeavesTheRunInterruptedAndTheWayBackLeadsTheSameRun(): void {
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$run_id  = (int) $run['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		// The write step in a process of its own that exits once the row is
		// written (the hook wp_insert_post fires after the UPDATE).
		try {
			AB_MCP_IT_Sync_Steps::run( 'a', array( array( 'op' => 'push', 'args' => array( 'action' => 'next', 'run_id' => $run_id ), 'exit_at' => 'wp_insert_post' ) ) );
			self::fail( 'The process exits inside the boundary.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'gave no JSON', $e->getMessage() );
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Backup::forget();
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'], 'The row was written before the exit.' );
		$step = AB_MCP_Sync_Run::step( $run_id, 2 );
		self::assertSame( 'begun', $step['state'] );
		self::assertSame( 'planned', $step['parts']['parts'][0]['state'], 'The journal was written ahead; the exit came before the read back.' );
		self::assertFalse( AB_MCP_Sync_Run::lock_fresh(), 'The shutdown of the process gave the lock back.' );
		// No next, no new run.
		$status = self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) );
		self::assertSame( 'interrupted', $status['state'] );
		self::assertStringContainsString( 'interrupted', self::refused( self::next( $run_id ), 'ab_mcp_sync_run_interrupted' ) );
		self::assertSame( 'ab_mcp_sync_run_open', self::gated( 'wp_site_push', $planned['args'] )->get_error_code() );
		self::assertSame( 'ab_mcp_sync_run_state', AB_MCP_Sync_Engine::abort( AB_MCP_Sync_Run::get( $run_id ) )->get_error_code(), 'An interrupted run is not aborted.' );
		// The way back leads the same run to reverting and puts the row back.
		$begun = self::revert( $run_id );
		self::assertTrue( $begun['same_run'] );
		self::assertSame( $run_id, (int) $begun['run_id'] );
		self::assertSame( 'reverting', $begun['state'] );
		$back = self::revert_all( $run_id );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $a['contact'] );
		self::assertSame( 'active', $pair['state'] );
		// Closing as checked takes a person; it is no tool.
		self::assertSame( 'ab_mcp_sync_run_unconfirmed', AB_MCP_Sync_Run::close_checked( $run_id, self::admin(), false )->get_error_code() );
	}

	public function testTheWayBackIsByteForByteAndLeavesWhatChangedSince(): void {
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		add_post_meta( (int) $a['contact'], 'note', 'abc' );
		self::b( array( array( 'op' => 'add_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'abc' ) ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.', array( 'post_title' => 'Contact us' ) );
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'def' ) ) );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( 'def' ), self::meta_a( (int) $a['contact'], 'note' ) );
		// Another writer: the meta to ABC (the collation calls it equal, the
		// bytes do not), the title with a space at its end, past every hook.
		global $wpdb;
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'DEF' ), array( 'post_id' => (int) $a['contact'], 'meta_key' => 'note' ) );
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'Contact us ' ), array( 'ID' => (int) $a['contact'] ) );
		clean_post_cache( (int) $a['contact'] );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'] );
		$step = self::step( $back, 1 );
		self::assertSame( 'unclear', $step['state'] );
		$parts = array_column( $step['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $parts['meta:note'], 'DEF is not def: left.' );
		self::assertSame( 'left', $parts['row'], 'A trailing space: left.' );
		self::assertSame( array( 'DEF' ), self::meta_a( (int) $a['contact'], 'note' ), 'Not written.' );
		self::assertSame( 'Contact us ', self::row_a( (int) $a['contact'] )['post_title'], 'Not written.' );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $a['contact'] );
		self::assertSame( 'unclear', $pair['state'] );
	}

	public function testTheLockOfABoundaryRefusesOtherWritersAndLetsTheOwnerPass(): void {
		self::pair();
		$a    = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$id   = (int) $a['contact'];
		$mid  = add_post_meta( $id, 'note', 'abc' );
		$slug = get_post( $id )->post_name;
		// This process passed plugins_loaded long before the boundary began,
		// as a request of a site that has had a run does (the mark, N3; a
		// site without one hooks nothing): armed with no record in sight.
		AB_MCP_Sync_Guard::mark();
		AB_MCP_Sync_Guard::arm();
		// A boundary over the page, as a step writes it (the record in the option).
		self::assertTrue( AB_MCP_Sync_Guard::begin( 77, 2, array( $id => 'page' ) ) );
		self::assertSame( array( 'run_id' => 77, 'seq' => 2 ), array_intersect_key( get_option( AB_MCP_Sync_Guard::OPTION ), array( 'run_id' => 1, 'seq' => 1 ) ) );
		// Another request (the REST API over HTTP, as the block editor saves):
		// refused with the message of the boundary.
		$answer = AB_MCP_IT_Sites::request( 'a', 'POST', '/wp/v2/pages/' . $id, array(), array( 'title' => 'Changed by another user' ) );
		self::assertSame( 423, $answer['status'], $answer['raw'] );
		self::assertSame( AB_MCP_Sync_Guard::CODE, $answer['body']['code'] );
		self::assertStringContainsString( 'A takeover from the staging site is writing this object', $answer['body']['message'] );
		self::assertStringContainsString( 'run 77, step 2', $answer['body']['message'] );
		self::assertSame( 'Contact', self::row_a( $id )['post_title'] );
		// Its meta over REST: not written either (the short-circuit of the meta API).
		$meta = AB_MCP_IT_Sites::request( 'a', 'POST', '/wp/v2/pages/' . $id, array(), array( 'meta' => array() ) );
		self::assertSame( 423, $meta['status'] );
		// Another page is free.
		$other = self::pages_on_a( array( 'free' => 'Free.' ) );
		$free  = AB_MCP_IT_Sites::request( 'a', 'POST', '/wp/v2/pages/' . $other['free'], array(), array( 'title' => 'Still free' ) );
		self::assertSame( 200, $free['status'], $free['raw'] );
		// This process passed plugins_loaded long before the boundary began
		// (its filters were armed with no record in sight, Codex round 1 of
		// branch 8, A1): update_metadata_by_mid(), the meta, a delete of the
		// key across every post and the trash are refused all the same; the
		// owner passes.
		AB_MCP_Sync_Guard::$owner = false;
		self::assertFalse( update_metadata_by_mid( 'post', (int) $mid, 'changed' ) );
		self::assertFalse( delete_metadata_by_mid( 'post', (int) $mid ) );
		self::assertFalse( update_post_meta( $id, 'note', 'changed' ) );
		self::assertFalse( delete_post_meta_by_key( 'note' ), 'delete_post_meta_by_key() would reach the covered page.' );
		self::assertFalse( wp_trash_post( $id ) );
		self::assertSame( array( 'abc' ), self::meta_a( $id, 'note' ) );
		self::assertSame( 'publish', self::row_a( $id )['post_status'] );
		$classic = wp_update_post( array( 'ID' => $id, 'post_title' => 'Classic' ), true );
		self::assertInstanceOf( WP_Error::class, $classic, 'The classic way meets the core text of an empty post.' );
		self::assertSame( 'empty_content', $classic->get_error_code() );
		AB_MCP_Sync_Guard::$owner = true;
		self::assertNotFalse( update_post_meta( $id, 'note', 'owner' ) );
		self::assertSame( array( 'owner' ), self::meta_a( $id, 'note' ) );
		AB_MCP_Sync_Guard::end( 77, 2 );
		self::assertFalse( get_option( AB_MCP_Sync_Guard::OPTION ) );
		self::assertSame( $slug, self::row_a( $id )['post_name'] );
		// With the record gone, the REST save goes through.
		$after = AB_MCP_IT_Sites::request( 'a', 'POST', '/wp/v2/pages/' . $id, array(), array( 'title' => 'Changed by another user' ) );
		self::assertSame( 200, $after['status'], $after['raw'] );
	}

	public function testAPageOfTheTopLevelWithTheMenuAutomaticIsLockedAndTakenOverAsDraftWithTheOverride(): void {
		self::pair();
		self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'landing', 'A new landing page.' ) ) ) );
		update_option( 'nav_menu_options', array( 'auto_add' => array( 5 ) ) );
		$planned = self::planned( array( 'landing' ) );
		$run     = self::begun( $planned['args'] );
		$check   = self::next( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $check['state'] );
		self::assertStringContainsString( 'post:menu_automatic', $check['step']['result']['findings'][0]['message'] );
		self::assertSame( array(), AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'post_name' => 'landing' ) ), 'Nothing created.' );
		// With the confirmed choice: written as draft, the pair with the override.
		$n       = $planned['args']['select'][0];
		$run     = self::begun( $planned['args'] + array( 'as_draft' => array( $n ) ) );
		self::probe_ok();
		$closed  = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$rows = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'post_name' => 'landing' ) );
		self::assertCount( 1, $rows );
		self::assertSame( 'draft', $rows[0]['post_status'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', $rows[0]['ID'] );
		self::assertSame( array( 'post_status' => 'draft' ), $pair['override'] );
		self::assertNotSame( $pair['base_live'], $pair['base_staging'], 'base_live is the hash of the target, base_staging of the staging version.' );
		// The next report: equal against the target, which it names; no second takeover.
		$report = self::report( self::staged() );
		$entry  = self::post_entry( $report['all'], 'landing' );
		self::assertSame( array( 'equal', 'none' ), array( $entry['result'], $entry['action'] ) );
		self::assertStringContainsString( 'post_status draft', $entry['note'] );
		// Without the choice, draft instead of publish would be a deviation:
		// the run wrote publish only where no automatic holds.
		delete_option( 'nav_menu_options' );
	}

	public function testAScheduledStagingPostWhoseDatePassedIsRefusedAndTheWayBackAfterTheDateNeedsACheck(): void {
		self::pair();
		$future = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS );
		$a      = self::pages_on_a( array( 'launch' => 'Soon.' ), );
		wp_update_post( wp_slash( array( 'ID' => (int) $a['launch'], 'post_status' => 'future', 'post_date' => $future, 'post_date_gmt' => $future, 'edit_date' => true ) ) );
		$b = self::pages_on_b( array( 'launch' => 'Soon.' ) );
		self::b( array( array( 'op' => 'update_post', 'args' => array( 'ID' => (int) $b['launch'], 'post_status' => 'future', 'post_date' => $future, 'post_date_gmt' => $future, 'edit_date' => true ) ) ) );
		self::paired( self::staged() );
		self::assertSame( 'future', self::row_a( (int) $a['launch'] )['post_status'] );
		// Staging: a scheduled post whose date passed, written by SQL (WordPress
		// itself would have stored publish): the check refuses it.
		$past = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		self::b( array( array( 'op' => 'raw_post', 'post' => (int) $b['launch'], 'set' => array( 'post_date' => $past, 'post_date_gmt' => $past, 'post_content' => 'Soon, on staging.' ) ) ) );
		$planned = self::planned( array( 'launch' ) );
		$run     = self::begun( $planned['args'] );
		$check   = self::next( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $check['state'] );
		self::assertStringContainsString( 'post:status_date', $check['step']['result']['findings'][0]['message'] );
		self::assertSame( 'future', self::row_a( (int) $a['launch'] )['post_status'], 'Nothing written.' );
		// Staging takes it to draft: the run writes draft; the backup holds future.
		self::b( array( array( 'op' => 'raw_post', 'post' => (int) $b['launch'], 'set' => array( 'post_date' => $future, 'post_date_gmt' => $future ) ), array( 'op' => 'update_post', 'args' => array( 'ID' => (int) $b['launch'], 'post_status' => 'draft', 'post_date' => $future, 'post_date_gmt' => $future, 'edit_date' => true ) ) ) );
		$planned = self::planned( array( 'launch' ) );
		$run     = self::begun( $planned['args'] );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( 'draft', self::row_a( (int) $a['launch'] )['post_status'] );
		// The way back after the date passed: not put back, check needed, nothing published.
		AB_MCP_Sync_Run::$clock = static fn(): int => time() + 3 * DAY_IN_SECONDS;
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'] );
		$parts = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'check_needed', $parts['row'] );
		self::assertSame( 'draft', self::row_a( (int) $a['launch'] )['post_status'], 'Nothing published.' );
		AB_MCP_Sync_Run::$clock = null;
	}

	public function testANewParentPageAndItsNewChildPageAreWrittenInOneRun(): void {
		// The child refers to a parent the same run creates (B1): the check
		// before the first write takes the parent as pending, the boundary
		// of the child maps it by the pair the parent's step made.
		self::pair();
		$parent = self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'section', 'A new section.' ), 'as' => 'parent' ) ) )['parent'];
		self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'chapter', 'Its chapter.', array( 'post_parent' => (int) $parent ) ) ) ) );
		// A menu adds pages of the top level by itself: the parent is taken
		// over as draft (the confirmed choice); the child is no page of the top
		// level, pending parent or not, so it is published as on staging.
		update_option( 'nav_menu_options', array( 'auto_add' => array( 5 ) ) );
		$planned = self::planned( array( 'chapter', 'section' ) );
		$n       = (int) self::post_entry( $planned['report']['all'], 'section' )['n'];
		$args    = $planned['args'] + array( 'as_draft' => array( $n ) );
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertSame( array(), $preview['preview']['locked'] );
		$steps = $preview['preview']['steps'];
		self::assertSame( (string) $parent, $steps[1]['object']['staging_ref'], 'The parent first (the order of the graph).' );
		self::assertSame( array( 1, 2 ), $steps[2]['depends'], 'The child depends on the parent.' );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		self::probe_ok();
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$section = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'post_name' => 'section' ) );
		$chapter = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'post_name' => 'chapter' ) );
		self::assertCount( 1, $section );
		self::assertCount( 1, $chapter );
		self::assertSame( 'draft', $section[0]['post_status'] );
		self::assertSame( 'publish', $chapter[0]['post_status'] );
		self::assertSame( $section[0]['ID'], $chapter[0]['post_parent'], 'The child under its new parent on this site.' );
		delete_option( 'nav_menu_options' );
	}

	public function testTheCheckBeforeTheFirstWriteKeepsItsFindingsAcrossCalls(): void {
		// A deviation found in one call of the check, then its time runs out:
		// the next call carries it on and the run is aborted, not passed (B3).
		self::pair();
		$a = self::pages_on_a( array( 'about' => 'About us.', 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'about' => 'About us.', 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'about', 'contact' ) );
		$run     = self::begun( $planned['args'] );
		// The object the check reaches first (the smaller entry) changes here.
		$first = min( $planned['args']['select'] ) === (int) self::post_entry( $planned['report']['all'], 'about' )['n'] ? 'about' : 'contact';
		wp_update_post( wp_slash( self::page( $first, 'Changed here after the plan.', array( 'ID' => (int) $a[ $first ] ) ) ) );
		// A clock that passes the budget after the first object of each call.
		AB_MCP_Sync_Engine::$clock = static function (): float {
			static $t = 1000.0;
			$t += 60;
			return $t;
		};
		$one = self::next( (int) $run['run_id'] );
		self::assertIsArray( $one, is_wp_error( $one ) ? $one->get_error_message() : '' );
		self::assertSame( 'open', $one['state'] );
		self::assertSame( 'planned', $one['step']['state'] );
		self::assertSame( 1, $one['step']['result']['cursor'] );
		self::assertCount( 1, $one['step']['result']['findings'], 'The finding of this call is kept with the cursor.' );
		$two = self::next( (int) $run['run_id'] );
		self::assertIsArray( $two, is_wp_error( $two ) ? $two->get_error_message() : '' );
		self::assertSame( 'closed_aborted', $two['state'], json_encode( $two ) );
		self::assertSame( 'failed', $two['step']['state'] );
		self::assertCount( 1, $two['step']['result']['findings'] );
		self::assertStringContainsString( 'changed since the plan', $two['step']['result']['findings'][0]['message'] );
		self::assertSame( 2, $two['step']['result']['checked'] );
		AB_MCP_Sync_Engine::$clock = null;
	}

	public function testTheCheckOfTheRunNeedsTheTitleBesides200AndGoesOnOverCalls(): void {
		// Decision 33 (B4): a published object has to answer 200 with its
		// title (an old page from a cache does not); the hashes of the written
		// objects are checked over calls where the budget runs out.
		self::pair();
		$a = self::pages_on_a( array( 'about' => 'About us.', 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'about' => 'About us.', 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'about', 'contact' ) );
		$run     = self::begun( $planned['args'] );
		AB_MCP_Sync_Engine::$probe = static fn( string $url ): array => array( 'status' => 200, 'body' => '<title>An old page from a cache</title>' );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$findings = $closed['step']['result']['findings'];
		self::assertCount( 1, $findings, json_encode( $findings ) );
		self::assertStringContainsString( 'did not show the title', $findings[0]['message'] );
		self::assertSame( 'passed', $closed['step']['result']['probe'], 'The PHP probe passed whole.' );
		self::assertGreaterThan( 0, AB_MCP_Sync_Run::get( (int) $run['run_id'] )['forward_closed_at'], 'Every write step was written: a qualified close all the same.' );
		// The way back, then the run again with the budget running out after
		// the first object of the check: the check goes on in the next call.
		$begun = self::revert( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', self::revert_all( (int) $begun['run_id'] )['state'] );
		$planned = self::planned( array( 'about', 'contact' ) );
		$run     = self::begun( $planned['args'] );
		self::probe_ok();
		foreach ( range( 1, 4 ) as $seq ) {
			self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'], "step $seq" );
		}
		$advance                   = true;
		AB_MCP_Sync_Engine::$clock = static function () use ( &$advance ): float {
			static $t = 1000.0;
			$t += $advance ? 60 : 0;
			return $t;
		};
		$one = self::next( (int) $run['run_id'] );
		self::assertIsArray( $one, is_wp_error( $one ) ? $one->get_error_message() : '' );
		self::assertSame( 'open', $one['state'] );
		self::assertSame( array( 'hashes', 1 ), array( $one['step']['result']['stage'], $one['step']['result']['cursor'] ), 'One object checked, the cursor kept.' );
		$advance = false;
		$two     = self::next( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $two['state'], json_encode( $two ) );
		self::assertSame( 2, $two['step']['result']['written'] );
		self::assertTrue( $two['step']['result']['sample']['asked'] );
		AB_MCP_Sync_Engine::$clock = null;
	}

	public function testThePageTemplateGoesThroughTheCoreWayAndTheThemeHasToOfferIt(): void {
		// _wp_page_template through page_template of wp_insert_post() (decisions
		// 17 and 28; B7): a template the theme of this site offers is written
		// once; one it does not offer is refused before anything is written.
		self::pair();
		self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'landing', 'A landing page.', array( 'meta_input' => array( '_wp_page_template' => 'landing.php' ) ) ) ) ) );
		$planned = self::planned( array( 'landing' ) );
		$run     = self::begun( $planned['args'] );
		$check   = self::next( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $check['state'], json_encode( $check ) );
		self::assertStringContainsString( 'post:page_template', $check['step']['result']['findings'][0]['message'] );
		self::assertSame( array(), AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'post_name' => 'landing' ) ), 'Nothing created.' );
		// A template the theme offers for pages.
		$templates = wp_get_theme()->get_page_templates( null, 'page' );
		self::assertNotEmpty( $templates, 'The theme of the test site offers a template for pages: ' . get_stylesheet() );
		$template = (string) array_key_first( $templates );
		self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'cover', 'A cover page.', array( 'meta_input' => array( '_wp_page_template' => $template ) ) ) ) ) );
		$planned = self::planned( array( 'cover' ) );
		$run     = self::begun( $planned['args'] );
		self::probe_ok();
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$rows = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'post_name' => 'cover' ) );
		self::assertCount( 1, $rows );
		self::assertSame( array( $template ), self::meta_a( (int) $rows[0]['ID'], '_wp_page_template' ), 'One row, written by the core with the row of the post.' );
		$write = self::step( self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => (int) $run['run_id'] ) ), 2 );
		self::assertSame( array( 'read', 'read' ), array_column( $write['parts']['parts'], 'state' ) );
	}

	public function testADeviatingMetaPartTakesTheRowBackWithItAndTheStepSaysSo(): void {
		// A meta sanitizer of this site changes a value as it is written: the
		// part deviates and goes back, and so does the row written before it
		// (each the step's own, read back under the lock); the step is
		// restored only because nothing of the object changed (B8).
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		add_post_meta( (int) $a['contact'], 'note', 'abc' );
		self::b( array( array( 'op' => 'add_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'abc' ) ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'def' ) ) );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( (int) $a['contact'] );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		add_filter( 'sanitize_post_meta_note', static fn() => 'X' );
		$write = self::next( (int) $run['run_id'] );
		remove_all_filters( 'sanitize_post_meta_note' );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'restored', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], 'state', 'part' );
		self::assertSame( 'restored', $parts['meta:note'] );
		self::assertSame( 'restored', $parts['row'], 'The row written before the deviating part went back as well.' );
		self::assertSame( $before, self::row_a( (int) $a['contact'] ), 'Nothing of the object changed.' );
		self::assertSame( array( 'abc' ), self::meta_a( (int) $a['contact'], 'note' ) );
		self::assertStringContainsString( 'nothing of the object changed', $write['step']['result']['message'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $a['contact'] );
		self::assertSame( 'active', $pair['state'] );
		self::assertSame( 'proposal', $pair['base_origin'], 'The pair keeps its bases of before.' );
	}

	public function testATemplateWrittenWithTheRowGoesBackWithTheRow(): void {
		// The core stores _wp_page_template inside wp_insert_post(), with the
		// row (page_template, B7). A filter of this site that only
		// wp_insert_post_data applies changes the title as the row is written:
		// the row reads back other than its target and goes back, and so does
		// the template the core wrote with it; the step says restored only
		// because nothing of the object changed (Codex round 2, N2).
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		$templates = wp_get_theme()->get_page_templates( null, 'page' );
		self::assertNotEmpty( $templates, 'The theme of the test site offers a template for pages: ' . get_stylesheet() );
		$template = (string) array_key_first( $templates );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.', array( 'meta_input' => array( '_wp_page_template' => $template ) ) );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( (int) $a['contact'] );
		self::assertSame( array(), self::meta_a( (int) $a['contact'], '_wp_page_template' ), 'No template here before.' );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		// The filter hits the one write of the step and takes itself out, as a
		// filter of a plugin that reacts to a saved post once; the way back of
		// the row (wp_update_post() with the backup) meets no filter then.
		$once = static function ( array $data ) use ( &$once ): array {
			remove_filter( 'wp_insert_post_data', $once );
			$data['post_title'] = 'Changed by a filter';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $once );
		$write = self::next( (int) $run['run_id'] );
		remove_all_filters( 'wp_insert_post_data' );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'restored', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], 'state', 'part' );
		self::assertSame( array( 'row', 'meta:_wp_page_template' ), array_keys( $parts ) );
		self::assertSame( 'restored', $parts['row'] );
		self::assertSame( 'restored', $parts['meta:_wp_page_template'], 'Written with the row by the core, back with the row.' );
		$notes = array_column( $write['step']['parts']['parts'], 'note', 'part' );
		self::assertStringContainsString( 'Written with the row', (string) ( $notes['meta:_wp_page_template'] ?? '' ), 'The journal says the core wrote it with the row.' );
		self::assertSame( $before, self::row_a( (int) $a['contact'] ), 'Nothing of the object changed.' );
		self::assertSame( array(), self::meta_a( (int) $a['contact'], '_wp_page_template' ), 'The template is as before: none.' );
		self::assertStringContainsString( 'nothing of the object changed', $write['step']['result']['message'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $a['contact'] );
		self::assertSame( 'active', $pair['state'] );
		self::assertSame( 'proposal', $pair['base_origin'], 'The pair keeps its bases of before.' );
	}

	public function testAStepThatFailedBeforeItsBackupIsNoRestOnTheWayBack(): void {
		// The target changes between the check and the write step: the step
		// fails before its backup and mutation. The way back has nothing to
		// take back for it, and never calls it a new object (B9).
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		wp_update_post( wp_slash( self::page( 'contact', 'Changed here after the check.', array( 'ID' => (int) $a['contact'] ) ) ) );
		$write = self::next( (int) $run['run_id'] );
		self::assertSame( 'failed', $write['step']['state'] );
		self::assertStringContainsString( 'changed since the plan', $write['step']['result']['message'] );
		self::assertNull( $write['step']['parts']['backup'], 'Failed before the backup: nothing was written.' );
		self::probe_ok();
		self::assertSame( 'closed_findings', self::finish( (int) $run['run_id'] )['state'] );
		self::assertStringContainsString( 'no written step to take back', self::refused( self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => (int) $run['run_id'] ) ), 'ab_mcp_sync_revert_nothing' ) );
		self::assertStringContainsString( 'wrote nothing', self::refused( self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => (int) $run['run_id'], 'steps' => array( 2 ) ) ), 'ab_mcp_sync_revert_steps' ) );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $a['contact'] );
		self::assertSame( 'active', $pair['state'], 'No rest, no residual pair.' );
		self::assertSame( 'Changed here after the check.', self::row_a( (int) $a['contact'] )['post_content'] );
	}

	public function testADeviatingTermPartIsNeverTakenBack(): void {
		// Terms are written on a way the lock does not refuse: a part that
		// read back other than its target is not provably the run's own, so
		// the way back leaves it (decisions 17 and 23; A2).
		self::pair();
		$news_a  = wp_insert_term( 'News', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ) ) ) ), true );
		self::assertIsInt( $story_a );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) ), 'as' => 'post' ) ) )['post'];
		self::paired( self::staged() );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		// Another writer adds a category by SQL in the moment the terms are set.
		$foreign = wp_insert_term( 'Foreign', 'category' );
		$ttid    = (int) $foreign['term_taxonomy_id'];
		add_action(
			'set_object_terms',
			static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $story_a, $ttid ) {
				global $wpdb;
				if ( 'category' === $taxonomy && (int) $object_id === $story_a ) {
					$wpdb->insert( $wpdb->term_relationships, array( 'object_id' => $story_a, 'term_taxonomy_id' => $ttid, 'term_order' => 0 ) );
					clean_object_term_cache( $story_a, 'post' );
				}
			},
			10,
			4
		);
		$write = self::next( (int) $run['run_id'] );
		remove_all_actions( 'set_object_terms' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], 'state', 'part' );
		self::assertSame( 'written', $parts['terms:category'], 'Written, deviating.' );
		self::assertStringContainsString( 'does not refuse', $write['step']['result']['message'] );
		self::probe_ok();
		self::assertSame( 'closed_findings', self::finish( (int) $run['run_id'] )['state'] );
		$slugs = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		self::assertEqualsCanonicalizing( array( 'news', 'foreign' ), $slugs() );
		// The way back: the terms are left (not the run's own), the row goes back.
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['terms:category'], 'Not provably the run\'s own.' );
		self::assertSame( 'restored', $outcome['row'] );
		self::assertEqualsCanonicalizing( array( 'news', 'foreign' ), $slugs(), 'The foreign category stays.' );
		self::assertSame( 'A story.', self::row_a( $story_a )['post_content'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $story_a );
		self::assertSame( 'unclear', $pair['state'] );
	}

	public function testACategoryAnotherHandSetToTheTargetIsNotTheStepsOwnAndIsLeft(): void {
		// The ownership rule (Codex round 3): a post in News here, planned to
		// Other. While the row is written, another hand sets the category to
		// Other on the way the lock does not refuse, and a filter changes the
		// title, so the row reads back other than its target and the step
		// turns. The category reads as the target now, yet the step has not
		// written it (an update passes the categories back unchanged): it is
		// another hand's, never written down as read, never put back; the
		// row goes back, the category stays, the step is unclear, not
		// restored, and the way back of the closed run leaves it as well.
		self::pair();
		$news_a  = wp_insert_term( 'News', 'category' );
		$other_a = wp_insert_term( 'Other', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		self::assertIsArray( $other_a, is_wp_error( $other_a ) ? $other_a->get_error_message() : '' );
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$other_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ) ) ) ), true );
		self::assertIsInt( $story_a );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) ), 'as' => 'post' ) ) )['post'];
		self::paired( self::staged() );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( $story_a );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$slugs = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		self::assertSame( array( 'news' ), $slugs() );
		// The filter hits the one write of the step and takes itself out; the
		// other hand sets the category once, as the core passes the categories
		// of the post back with the row (set_object_terms fires inside).
		$once = static function ( array $data ) use ( &$once ): array {
			remove_filter( 'wp_insert_post_data', $once );
			$data['post_title'] = 'Changed by a filter';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $once );
		$other_id = (int) $other_a['term_id'];
		$hand     = static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $story_a, $other_id, &$hand ) {
			if ( 'category' === $taxonomy && (int) $object_id === $story_a ) {
				remove_action( 'set_object_terms', $hand, 10 );
				wp_set_object_terms( $story_a, array( $other_id ), 'category', false );
			}
		};
		add_action( 'set_object_terms', $hand, 10, 4 );
		$write = self::next( (int) $run['run_id'] );
		remove_all_filters( 'wp_insert_post_data' );
		remove_all_actions( 'set_object_terms' );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], 'state', 'part' );
		$notes = array_column( $write['step']['parts']['parts'], 'note', 'part' );
		self::assertSame( 'restored', $parts['row'], 'The row, the step\'s own, went back.' );
		self::assertSame( 'left', $parts['terms:category'], 'The category another hand set is not the step\'s own.' );
		self::assertStringContainsString( 'another hand', (string) ( $notes['terms:category'] ?? '' ) );
		self::assertStringContainsString( 'another hand', $write['step']['result']['message'] );
		self::assertSame( array( 'other' ), $slugs(), 'The category stays as the other hand set it, not put back to News.' );
		self::assertSame( $before, self::row_a( $story_a ), 'The row is as before.' );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $story_a );
		self::assertSame( 'unclear', $pair['state'] );
		self::probe_ok();
		self::assertSame( 'closed_findings', self::finish( (int) $run['run_id'] )['state'] );
		// The way back of the closed run: the row was put back already, the
		// category is left (not the run's own), the object went back in part.
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['terms:category'], 'Not the run\'s own.' );
		self::assertSame( 'unchanged', $outcome['row'] );
		self::assertSame( array( 'other' ), $slugs(), 'The way back leaves the other hand\'s category.' );
		self::assertSame( $before, self::row_a( $story_a ) );
	}

	public function testAPartTheStepNeverReachedIsNotTakenBackOnTheWayBack(): void {
		// Codex round 4: a post in News with a note, planned to Other and a new
		// note. While the row is written, another hand sets the category to
		// Other (the target) on the way the lock does not refuse; the row reads
		// back as written, then the meta write is refused and the step ends
		// unclear before it reaches the terms. The terms were never written by
		// the step (not_reached, settled()): the way back of the closed run
		// leaves the category as the other hand set it.
		self::pair();
		$news_a  = wp_insert_term( 'News', 'category' );
		$other_a = wp_insert_term( 'Other', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		self::assertIsArray( $other_a, is_wp_error( $other_a ) ? $other_a->get_error_message() : '' );
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$other_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ) ) ) ), true );
		self::assertIsInt( $story_a );
		add_post_meta( $story_a, 'note', 'abc' );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) ), 'as' => 'post' ) ) )['post'];
		self::b( array( array( 'op' => 'add_meta', 'post' => $story_b, 'key' => 'note', 'value' => 'abc' ) ) );
		self::paired( self::staged() );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ) ) );
		self::b( array( array( 'op' => 'update_meta', 'post' => $story_b, 'key' => 'note', 'value' => 'def' ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( $story_a );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$slugs    = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		$other_id = (int) $other_a['term_id'];
		$hand     = static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $story_a, $other_id, &$hand ) {
			if ( 'category' === $taxonomy && (int) $object_id === $story_a ) {
				remove_action( 'set_object_terms', $hand, 10 );
				wp_set_object_terms( $story_a, array( $other_id ), 'category', false );
			}
		};
		add_action( 'set_object_terms', $hand, 10, 4 );
		$refuse = static fn( $check, $object_id, $meta_key ) => ( (int) $object_id === $story_a && 'note' === $meta_key ) ? false : $check;
		add_filter( 'update_post_metadata', $refuse, 10, 3 );
		add_filter( 'add_post_metadata', $refuse, 10, 3 );
		add_filter( 'delete_post_metadata', $refuse, 10, 3 );
		$write = self::next( (int) $run['run_id'] );
		remove_all_actions( 'set_object_terms' );
		remove_filter( 'update_post_metadata', $refuse, 10 );
		remove_filter( 'add_post_metadata', $refuse, 10 );
		remove_filter( 'delete_post_metadata', $refuse, 10 );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], 'state', 'part' );
		self::assertSame( 'failed', $parts['meta:note'] );
		self::assertSame( 'not_reached', $parts['terms:category'], 'The step ended before it reached the terms.' );
		self::assertSame( array( 'other' ), $slugs(), 'The other hand set the category.' );
		self::assertSame( array( 'abc' ), self::meta_a( $story_a, 'note' ) );
		self::probe_ok();
		self::finish( (int) $run['run_id'] );
		// The way back of the closed run: the row (the step's own) goes back,
		// the category (never written by the step) is left.
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['terms:category'], 'Never written by the step, so not the run\'s own.' );
		self::assertSame( 'restored', $outcome['row'] );
		self::assertSame( array( 'other' ), $slugs(), 'The way back leaves the other hand\'s category.' );
		self::assertSame( $before, self::row_a( $story_a ), 'The row is as before.' );
	}

	public function testTheDefaultCategoryOfANewPostIsWrittenWithTheRowAndTheJournalSaysSo(): void {
		// wp_insert_post() gives a new post the default category with the row
		// (with_row()): the step's own, written with the row, and written as
		// its target by the step's own terms call right after; the journal
		// says both, and never calls it another hand's.
		self::pair();
		$other_a = wp_insert_term( 'Other', 'category' );
		self::assertIsArray( $other_a, is_wp_error( $other_a ) ? $other_a->get_error_message() : '' );
		$other_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'fresh', 'A fresh story.', array( 'post_type' => 'post', 'post_category' => array( $other_b ) ) ), 'as' => 'post' ) ) );
		self::paired( self::staged() );
		$planned = self::planned( array( 'fresh' ) );
		$run     = self::begun( $planned['args'] );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$write = self::next( (int) $run['run_id'] );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'done', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], 'state', 'part' );
		$notes = array_column( $write['step']['parts']['parts'], 'note', 'part' );
		self::assertSame( 'read', $parts['terms:category'] );
		self::assertStringContainsString( 'Written with the row by the core', (string) ( $notes['terms:category'] ?? '' ), 'The default category: the step\'s own, with the row.' );
		self::assertStringContainsString( 'Written as its target by the step after that', (string) ( $notes['terms:category'] ?? '' ) );
		self::assertStringNotContainsString( 'another hand', (string) ( $notes['terms:category'] ?? '' ) );
		$live = (int) $write['step']['result']['live_ref'];
		self::assertSame( array( 'other' ), array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $live, 'category' ) ) ) );
	}

	public function testTheWayBackDoesNotRepublishATopLevelPageWhileAMenuAddsPages(): void {
		// A published page taken over as draft; the way back would publish it
		// again while a menu adds pages by itself (decisions 21 and 23; A3):
		// the row is left as «check needed», nothing is published.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		update_option( 'nav_menu_options', array( 'auto_add' => array( 5 ) ) );
		$planned = self::planned( array( 'contact' ) );
		$n       = $planned['args']['select'][0];
		$run     = self::begun( $planned['args'] + array( 'as_draft' => array( $n ) ) );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( 'draft', self::row_a( (int) $a['contact'] )['post_status'] );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'] );
		$parts = self::step( $back, 1 )['result']['parts'];
		$row   = array_values( array_filter( $parts, static fn( $p ) => 'row' === $p['part'] ) )[0];
		self::assertSame( 'check_needed', $row['state'] );
		self::assertStringContainsString( 'add it by itself', $row['note'] );
		self::assertSame( 'draft', self::row_a( (int) $a['contact'] )['post_status'], 'Nothing published.' );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'] );
		// The automatic off: the way back of another run puts it back.
		delete_option( 'nav_menu_options' );
	}

	/* ----------------------------- GitHub Codex round 1 on free#137 */

	public function testAMetaCallThatChangedThePartAndThenFailedIsTheStepsOwnAndGoesBack(): void {
		// P1: a key of two rows here, two on staging with another value:
		// write_meta() removes the rows here, then a filter of this site
		// refuses the new ones. The call answers with an error, yet the part
		// changed: the step reads every part back whatever the call answered,
		// so the part is the step's own, written with its hash, and the step
		// turns: the rows come back byte for byte, the row written before as
		// well, every part read as the backup after; the step is restored,
		// nothing of the object changed. Before, the part was failed without
		// a hash, the rows were gone, and the way back skipped it as unchanged.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		add_post_meta( (int) $a['contact'], 'note', 'abc' );
		add_post_meta( (int) $a['contact'], 'note', 'def' );
		self::b(
			array(
				array( 'op' => 'add_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'abc' ),
				array( 'op' => 'add_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'def' ),
			)
		);
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		// update_post_meta() without a previous value sets every row of the key.
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'xyz' ) ) );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$contact = (int) $a['contact'];
		$before  = self::row_a( $contact );
		self::assertSame( array( 'abc', 'def' ), self::meta_a( $contact, 'note' ) );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$refuse = static fn( $check, $object_id, $meta_key ) => ( (int) $object_id === $contact && 'note' === $meta_key ) ? false : $check;
		add_filter( 'add_post_metadata', $refuse, 10, 3 );
		$write = self::next( (int) $run['run_id'] );
		remove_filter( 'add_post_metadata', $refuse, 10 );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'restored', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], 'state', 'part' );
		$notes = array_column( $write['step']['parts']['parts'], 'note', 'part' );
		self::assertSame( 'restored', $parts['meta:note'], 'The rows the failed call removed came back.' );
		self::assertSame( 'restored', $parts['row'], 'The row written before went back with it.' );
		self::assertStringContainsString( 'changed the part and then failed', (string) ( $notes['meta:note'] ?? '' ), 'The journal says the call failed after it changed the part.' );
		self::assertStringContainsString( 'not written whole', $write['step']['result']['message'] );
		self::assertStringContainsString( 'nothing of the object changed', $write['step']['result']['message'] );
		self::assertSame( array( 'abc', 'def' ), self::meta_a( $contact, 'note' ), 'The two rows, as before the run.' );
		self::assertSame( $before, self::row_a( $contact ), 'The row is as before.' );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $contact );
		self::assertSame( 'active', $pair['state'] );
		self::assertSame( 'proposal', $pair['base_origin'], 'The pair keeps its bases of before.' );
	}

	public function testTheWayBackPutsTheRowBackBeforeTheTermsSoAPostWithoutACategoryStaysWithout(): void {
		// P2: a post without a category here and on staging (set so past the
		// editor), paired; staging gives it a category. wp_update_post() gives
		// a post without a category the default one with the row (with_row()),
		// so the way back puts the row back first and the terms after it,
		// which undo that side effect; then every part is read again against
		// the backup. Before, the terms went back first and the row after
		// them, which gave the post the default category once more while the
		// way back said restored.
		self::pair();
		$other_a = wp_insert_term( 'Other', 'category' );
		self::assertIsArray( $other_a, is_wp_error( $other_a ) ? $other_a->get_error_message() : '' );
		$other_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post' ) ) ), true );
		self::assertIsInt( $story_a );
		wp_set_object_terms( $story_a, array(), 'category', false );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post' ) ), 'as' => 'post' ) ) )['post'];
		self::b( array( array( 'op' => 'set_terms', 'post' => $story_b, 'terms' => array(), 'taxonomy' => 'category' ) ) );
		$slugs = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		self::assertSame( array(), $slugs(), 'No category here.' );
		self::paired( self::staged() );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( $story_a );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$write = self::next( (int) $run['run_id'] );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'done', $write['step']['state'], json_encode( $write['step'] ) );
		self::assertSame( array( 'other' ), $slugs() );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( self::step( $back, 1 )['result'] ) );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'restored', $outcome['row'] );
		self::assertSame( 'restored', $outcome['terms:category'] );
		self::assertSame( array(), $slugs(), 'No category, as before the run: the default category wp_update_post() gave the post with the row was undone by the terms put back after it, and read so.' );
		self::assertSame( $before, self::row_a( $story_a ) );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $story_a );
		self::assertSame( 'active', $pair['state'] );
	}

	public function testTheTermsGoBackByTheStateReadBeforeTheRowWentBack(): void {
		// The mirror case of the one before: a post in News here, paired so;
		// staging takes its category away (set so past the editor). The step
		// writes the row (which keeps News with it) and then the terms: none.
		// On the way back the row goes first: wp_update_post() gives the post,
		// which has no category then, the default one. The terms are decided
		// on the state read before that (none, the step's own) and go back to
		// News all the same, read as the backup after.
		self::pair();
		$news_a = wp_insert_term( 'News', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ) ) ) ), true );
		self::assertIsInt( $story_a );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) ), 'as' => 'post' ) ) )['post'];
		self::paired( self::staged() );
		// The content first (wp_update_post() would give a post without a
		// category the default one), then the category taken away.
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post' ) );
		self::b( array( array( 'op' => 'set_terms', 'post' => $story_b, 'terms' => array(), 'taxonomy' => 'category' ) ) );
		$slugs   = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( $story_a );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array(), $slugs(), 'The step wrote the target: no category.' );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( self::step( $back, 1 )['result'] ) );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'restored', $outcome['row'] );
		self::assertSame( 'restored', $outcome['terms:category'], 'Decided on the state before the row went back, not on the default category the row gave the post.' );
		self::assertSame( array( 'news' ), $slugs() );
		self::assertSame( $before, self::row_a( $story_a ) );
	}

	public function testAPartAHookChangedAfterItWasPutBackIsUnclearNotRestored(): void {
		// The check after the parts went back: a post in News with a note,
		// changed on staging in content, note and category. The way back puts
		// the row, the meta and then the terms back; a hook of this site
		// reacting to the terms (set_object_terms) writes the note once more.
		// Every part is read again against the backup: the note reads other
		// than the backup, so it is unclear, the object went back only in
		// part, the pair is unclear. Before, the way back said restored.
		self::pair();
		$news_a  = wp_insert_term( 'News', 'category' );
		$other_a = wp_insert_term( 'Other', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		self::assertIsArray( $other_a, is_wp_error( $other_a ) ? $other_a->get_error_message() : '' );
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$other_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ) ) ) ), true );
		self::assertIsInt( $story_a );
		add_post_meta( $story_a, 'note', 'abc' );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) ), 'as' => 'post' ) ) )['post'];
		self::b( array( array( 'op' => 'add_meta', 'post' => $story_b, 'key' => 'note', 'value' => 'abc' ) ) );
		self::paired( self::staged() );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ) ) );
		self::b( array( array( 'op' => 'update_meta', 'post' => $story_b, 'key' => 'note', 'value' => 'def' ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( $story_a );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( 'def' ), self::meta_a( $story_a, 'note' ) );
		// The hook reacts to the terms put back (News again; the row put back
		// before passes the categories of the moment, Other, through the same
		// action) and writes the note once more, as a plugin keeping a field
		// of its own in step with the category would.
		$news_id = (int) $news_a['term_id'];
		$hook    = static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $story_a, $news_id, &$hook ) {
			if ( 'category' === $taxonomy && (int) $object_id === $story_a && in_array( $news_id, array_map( 'intval', (array) $terms ), true ) ) {
				remove_action( 'set_object_terms', $hook, 10 );
				update_post_meta( $story_a, 'note', 'set by a hook' );
			}
		};
		add_action( 'set_object_terms', $hook, 10, 4 );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		remove_all_actions( 'set_object_terms' );
		self::assertSame( 'closed_findings', $back['state'] );
		$step    = self::step( $back, 1 );
		$outcome = array_column( $step['result']['parts'], 'state', 'part' );
		$notes   = array_column( $step['result']['parts'], 'note', 'part' );
		self::assertSame( 'restored', $outcome['row'] );
		self::assertSame( 'restored', $outcome['terms:category'] );
		self::assertSame( 'unclear', $outcome['meta:note'], 'Put back, then changed by the hook: read again, other than the backup.' );
		self::assertStringContainsString( 'after the parts went back', (string) ( $notes['meta:note'] ?? '' ) );
		self::assertSame( 'unclear', $step['state'] );
		self::assertStringContainsString( 'went back only in part', $step['result']['message'] );
		self::assertSame( array( 'set by a hook' ), self::meta_a( $story_a, 'note' ), 'What the hook wrote stands; the way back writes nothing twice.' );
		self::assertSame( $before, self::row_a( $story_a ) );
		self::assertSame( array( 'news' ), array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) ) );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $story_a );
		self::assertSame( 'unclear', $pair['state'] );
	}

	public function testATargetThatChangedSinceTheReportIsNotPlannedWhateverTheReportSaid(): void {
		// P1: the report says take_over for a page this site had not changed;
		// then this site changes it, before the plan. The plan read the page
		// as it is now and bound that hash, so the check and the boundary
		// passed and the change here was written over without a choice. The
		// plan compares the page with the state the report compared and
		// refuses with the way; a new report shows the conflict and asks for
		// the choice.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		self::assertSame( 'take_over', self::post_entry( $planned['report']['all'], 'contact' )['action'] );
		wp_update_post( wp_slash( self::page( 'contact', 'Changed here after the report.', array( 'ID' => (int) $a['contact'] ) ) ) );
		$before  = self::row_a( (int) $a['contact'] );
		$message = self::refused( self::gated( 'wp_site_push', $planned['args'] ), 'ab_mcp_sync_push_changed' );
		self::assertStringContainsString( 'changed since the report', $message );
		self::assertStringContainsString( 'new report', $message );
		self::assertSame( $before, self::row_a( (int) $a['contact'] ), 'Nothing written.' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'No run was made.' );
		// A new report compares the page as it is now: a conflict, which asks
		// for the choice.
		$report = self::report( self::staged() );
		self::assertSame( 'choose', self::post_entry( $report['all'], 'contact' )['action'] );
	}

	public function testAStepGoesBackOnceAlsoAcrossRunsOfTheWayBack(): void {
		// P2: the way back of a closed run is a run of its own (decision 15).
		// A second plan for the same run looked for steps of the way back in
		// the run that wrote only, found none, and took the step back once
		// more: after a later takeover of the same object, that undid the
		// later one. Every run of the way back that names the run counts now:
		// a step goes back once.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		$begun = self::revert( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', self::revert_all( (int) $begun['run_id'] )['state'] );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'] );
		// The same run again: nothing is left to take back.
		$again = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => (int) $run['run_id'] ) );
		self::assertStringContainsString( 'taken back already', self::refused( $again, 'ab_mcp_sync_revert_nothing' ) );
		// The step named: refused, with the run of the way back that took it.
		$named   = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => (int) $run['run_id'], 'steps' => array( 2 ) ) );
		$message = self::refused( $named, 'ab_mcp_sync_revert_steps' );
		self::assertStringContainsString( 'taken back already', $message );
		self::assertStringContainsString( sprintf( 'run %d', (int) $begun['run_id'] ), $message );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'], 'Nothing written.' );
	}

	public function testAWayBackForAClosedRunIsMadeUnderTheLockOfTheStore(): void {
		// The clean-up reads the runs whose backups it keeps and removes
		// groups under the lock of the store; a way back for a closed run is
		// made under the same lock, so no sweep removes the backups it needs
		// beside its making (decision 20, rule 4; A6): while another request
		// holds the lock, the making waits.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$run = self::begun( self::planned( array( 'contact' ) )['args'] );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		$args    = array( 'action' => 'plan', 'run_id' => (int) $run['run_id'] );
		$preview = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		// Another process of site A holds the lock for three seconds.
		$command = array( PHP_BINARY, dirname( __DIR__ ) . '/integration/bin/sync-site.php', AB_MCP_IT_Sites::state_file(), 'a' );
		$holder  = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $holder );
		fwrite( $pipes[0], (string) json_encode( array( array( 'op' => 'store_hold_lock', 'seconds' => 3, 'as' => 'held' ) ) ) );
		fclose( $pipes[0] );
		usleep( 1200000 );
		$started = microtime( true );
		$begun   = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		$waited  = microtime( true ) - $started;
		$out     = stream_get_contents( $pipes[1] );
		$err     = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $holder ), $err . $out );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
		self::assertSame( 'revert', $begun['type'] );
		self::assertGreaterThan( 1.0, $waited, 'The making waited for the lock the other request held.' );
		self::assertSame( 'closed_reverted', self::revert_all( (int) $begun['run_id'] )['state'] );
	}

	public function testTheRetentionKeepsTheLastThreeQualifiedClosesAndWhatAWayBackNeeds(): void {
		global $wpdb;
		$runs   = AB_MCP_Sync_Schema::table( 'runs' );
		$now    = time();
		$make   = static function ( int $id, string $state, int $forward, string $type = 'push', ?array $plan = null, ?int $keep_until = null ) use ( $wpdb, $runs, $now ): void {
			$closed = in_array( $state, AB_MCP_Sync_Schema::CLOSED, true ) ? $now - 10 * $id : 0;
			$wpdb->insert(
				$runs,
				array(
					'run_id'            => $id,
					'type'              => $type,
					'source_id'         => 'src',
					'state'             => $state,
					'created'           => $now - 100 * $id,
					'updated'           => $now,
					'closed'            => $closed,
					'open_one'          => $closed > 0 ? null : 1,
					'forward_closed_at' => $forward,
					// The period a close stores (set_state(); Free): the time of
					// the close for a qualified one, seven days for the rest.
					'keep_until'        => null !== $keep_until ? $keep_until : ( $closed > 0 ? AB_MCP_Sync_Schema::keep_until( $closed, $forward > 0, 0 ) : 0 ),
					'plan'              => null === $plan ? null : json_encode( $plan ),
				)
			);
			self::assertSame( '', $wpdb->last_error );
		};
		// The backups of a run lie in a group of the store, bound to the run
		// (opened after the run exists, as the engine does; opening a group
		// sweeps the store).
		$backup = static function ( int $id ): void {
			$group = AB_MCP_Sync_Backup::group( $id );
			self::assertIsString( $group, is_wp_error( $group ) ? $group->get_error_message() : '' );
			self::assertIsArray( AB_MCP_Sync_Backup::put( $id, 'step-0002', array( 'x' => $id ) ) );
		};
		// Three qualified closes: all kept.
		$make( 1, 'closed_done', $now - 300 );
		$backup( 1 );
		$make( 2, 'closed_findings', $now - 200 );
		$backup( 2 );
		$make( 3, 'closed_done', $now - 100 );
		$backup( 3 );
		// An aborted run of long ago: its backups go (unqualified, 7 days).
		$make( 4, 'closed_aborted', 0 );
		$wpdb->update( $runs, array( 'closed' => $now - 8 * DAY_IN_SECONDS, 'keep_until' => $now - 1 * DAY_IN_SECONDS ), array( 'run_id' => 4 ) );
		$backup( 4 );
		$named = AB_MCP_Sync_Schema::backup_runs( $now, 0 );
		ksort( $named );
		self::assertSame( array( 1 => true, 2 => true, 3 => true ), $named, 'The retention names the three qualified closes.' );
		AB_MCP_Protected_Store::cleanup();
		$kept = static function (): array {
			$groups = AB_MCP_Protected_Store::groups( 'backups' );
			self::assertIsArray( $groups, is_wp_error( $groups ) ? $groups->get_error_message() : '' );
			return array_values( array_map( 'intval', array_column( $groups, 'run_id' ) ) );
		};
		self::assertEqualsCanonicalizing( array( 1, 2, 3 ), $kept(), 'Three qualified closes stay; the old aborted run goes.' );
		// The fourth qualified close pushes the first out.
		$make( 5, 'closed_done', $now - 50 );
		$backup( 5 );
		AB_MCP_Protected_Store::cleanup();
		self::assertEqualsCanonicalizing( array( 2, 3, 5 ), $kept() );
		// A way back that is not closed needs run 2: kept, whatever comes.
		$make( 6, 'open', 0, 'revert', array( 'reverts' => array( 2 ) ) );
		$backup( 6 );
		$make( 7, 'closed_done', $now - 20 );
		AB_MCP_Protected_Store::cleanup();
		self::assertEqualsCanonicalizing( array( 2, 3, 5, 6 ), $kept(), 'Run 2 stays for the way back; run 7 has no backups yet.' );
		// The way back closes: run 2 goes with the next clean-up.
		$wpdb->update( $runs, array( 'state' => 'closed_reverted', 'open_one' => null, 'closed' => $now, 'keep_until' => $now + 7 * DAY_IN_SECONDS ), array( 'run_id' => 6 ) );
		AB_MCP_Protected_Store::cleanup();
		self::assertEqualsCanonicalizing( array( 3, 5, 6 ), $kept(), 'Run 6 closed just now: seven days; run 2 pushed out.' );
		// Every close stores the period it got (decision 20, rules 3 and 5;
		// A7): seven days in Free, thirty under Pro's filter, for a close that
		// does not qualify as well.
		$make( 8, 'open', 0 );
		self::assertTrue( AB_MCP_Sync_Run::set_state( 8, 'open', 'closed_aborted' ) );
		$closed = AB_MCP_Sync_Run::get( 8 );
		self::assertSame( $closed['closed'] + 7 * DAY_IN_SECONDS, $closed['keep_until'], 'Free: seven days for an unqualified close.' );
		add_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, static fn(): int => 30 );
		$make( 9, 'open', 0 );
		self::assertTrue( AB_MCP_Sync_Run::set_state( 9, 'open', 'closed_aborted' ) );
		$closed = AB_MCP_Sync_Run::get( 9 );
		self::assertSame( $closed['closed'] + 30 * DAY_IN_SECONDS, $closed['keep_until'], 'Pro: 30 days for an unqualified close (rule 5).' );
		$make( 10, 'open', 0 );
		self::assertTrue( AB_MCP_Sync_Run::set_state( 10, 'open', 'closed_done', true ) );
		$closed = AB_MCP_Sync_Run::get( 10 );
		self::assertSame( $closed['closed'] + 30 * DAY_IN_SECONDS, $closed['keep_until'], 'Pro: 30 days for a qualified close (rule 3).' );
		self::assertGreaterThan( 0, $closed['forward_closed_at'] );
		remove_all_filters( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER );
		// Pro → Free (rule 6): the periods stay with the runs. Runs 3 and 5
		// closed under Pro, say, and two new qualified closes (11, 12) push
		// them out of the last three: their backups stay until their
		// keep_until, as do those of run 9, closed as aborted 8 days ago.
		$wpdb->update( $runs, array( 'keep_until' => $now + 20 * DAY_IN_SECONDS ), array( 'run_id' => 3 ) );
		$wpdb->update( $runs, array( 'keep_until' => $now + 20 * DAY_IN_SECONDS ), array( 'run_id' => 5 ) );
		$wpdb->update( $runs, array( 'closed' => $now - 8 * DAY_IN_SECONDS, 'keep_until' => $now + 22 * DAY_IN_SECONDS ), array( 'run_id' => 9 ) );
		$backup( 9 );
		$make( 11, 'closed_done', $now - 4 );
		$make( 12, 'closed_done', $now - 3 );
		AB_MCP_Protected_Store::cleanup();
		self::assertEqualsCanonicalizing( array( 3, 5, 6, 9 ), $kept(), 'The last three qualified closes are 10, 11 and 12 (no backups); 3, 5 and 9 stay by the period their close got.' );
		// Their periods reached: gone, whatever the filter says now.
		foreach ( array( 3, 5, 9 ) as $id ) {
			$wpdb->update( $runs, array( 'keep_until' => $now - 1 ), array( 'run_id' => $id ) );
		}
		AB_MCP_Protected_Store::cleanup();
		self::assertEqualsCanonicalizing( array( 6 ), $kept(), 'Run 6 within its seven days; 3, 5 and 9 go.' );
		// A row of version 2 (closed before the period was stored, keep_until
		// 0) is kept as long as it is there, fail closed (N1): an aborted run
		// of a year ago and a qualified close past the last three alike.
		$make( 13, 'closed_aborted', 0, 'push', null, 0 );
		$wpdb->update( $runs, array( 'closed' => $now - 365 * DAY_IN_SECONDS ), array( 'run_id' => 13 ) );
		$backup( 13 );
		$make( 14, 'closed_done', $now - 300 * DAY_IN_SECONDS, 'push', null, 0 );
		$wpdb->update( $runs, array( 'closed' => $now - 300 * DAY_IN_SECONDS ), array( 'run_id' => 14 ) );
		$backup( 14 );
		AB_MCP_Protected_Store::cleanup();
		self::assertEqualsCanonicalizing( array( 6, 13, 14 ), $kept(), 'Rows without a stored period stay.' );
	}
}
