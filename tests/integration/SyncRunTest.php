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
		AB_MCP_Sync_Engine::$probe    = null;
		AB_MCP_Sync_Resources::$probe = null;
		AB_MCP_Sync_Report::$page     = null;
		remove_all_filters( 'wp_insert_post_data' );
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
		$run = self::gated( 'wp_site_push', $planned['args'] + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		self::assertTrue( $run['created_now'] );
		$run_id = (int) $run['run_id'];
		self::assertSame( $before, self::row_a( (int) $a['contact'] ), 'Making the run writes nothing.' );

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
		AB_MCP_Sync_Engine::$probe = static fn( string $url ): int => 200;
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
		// A new plan can begin now (the token of the old preview no longer fits).
		self::assertStringContainsString( 'not one this site issued', self::refused( self::gated( 'wp_site_push', $planned['args'] + array( 'confirm_token' => 'x' ) ), 'ab_mcp_confirm_invalid' ) );
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
		AB_MCP_Sync_Engine::$probe = static fn( string $url ): int => 200;
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
		// In another request of this process: armed, update_metadata_by_mid()
		// and the trash are refused; the owner passes.
		AB_MCP_Sync_Guard::$owner = false;
		AB_MCP_Sync_Guard::arm();
		self::assertFalse( update_metadata_by_mid( 'post', (int) $mid, 'changed' ) );
		self::assertFalse( delete_metadata_by_mid( 'post', (int) $mid ) );
		self::assertFalse( update_post_meta( $id, 'note', 'changed' ) );
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
		AB_MCP_Sync_Engine::$probe = static fn( string $url ): int => 200;
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
		AB_MCP_Sync_Engine::$probe = static fn( string $url ): int => 200;
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

	public function testTheRetentionKeepsTheLastThreeQualifiedClosesAndWhatAWayBackNeeds(): void {
		global $wpdb;
		$runs   = AB_MCP_Sync_Schema::table( 'runs' );
		$now    = time();
		$make   = static function ( int $id, string $state, int $forward, string $type = 'push', ?array $plan = null ) use ( $wpdb, $runs, $now ): void {
			$wpdb->insert(
				$runs,
				array(
					'run_id'            => $id,
					'type'              => $type,
					'source_id'         => 'src',
					'state'             => $state,
					'created'           => $now - 100 * $id,
					'updated'           => $now,
					'closed'            => in_array( $state, AB_MCP_Sync_Schema::CLOSED, true ) ? $now - 10 * $id : 0,
					'open_one'          => in_array( $state, AB_MCP_Sync_Schema::CLOSED, true ) ? null : 1,
					'forward_closed_at' => $forward,
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
		$wpdb->update( $runs, array( 'closed' => $now - 8 * DAY_IN_SECONDS ), array( 'run_id' => 4 ) );
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
		$wpdb->update( $runs, array( 'state' => 'closed_reverted', 'open_one' => null, 'closed' => $now ), array( 'run_id' => 6 ) );
		AB_MCP_Protected_Store::cleanup();
		self::assertEqualsCanonicalizing( array( 3, 5, 6 ), $kept(), 'Run 6 closed just now: seven days; run 2 pushed out.' );
		// Pro's filter keeps a qualified close by its age as well.
		add_filter( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER, static fn(): int => 30 );
		$make( 8, 'closed_done', $now - 10 );
		$make( 9, 'closed_done', $now - 5 );
		AB_MCP_Protected_Store::cleanup();
		self::assertEqualsCanonicalizing( array( 3, 5, 6 ), $kept(), 'Within 30 days every qualified close stays.' );
		remove_all_filters( AB_MCP_Protected_Store::BACKUP_DAYS_FILTER );
		AB_MCP_Protected_Store::cleanup();
		self::assertEqualsCanonicalizing( array( 6 ), $kept(), 'Free: the last three qualified closes are 7, 8 and 9, which have no backups; 3 and 5 go.' );
	}
}
