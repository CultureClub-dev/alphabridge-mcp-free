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
 *   leaves the part never read back with its value before (a hash equal to
 *   the target proves no ownership), puts the parts read back back.
 * - The one boundary (the decision of 07.11.2026, «A mit altem Wert»):
 *   where anything of an object deviates within its step (a hook changed a
 *   part, a call was refused or failed, a part read back other than
 *   written), nothing of it is put back, within the boundary or by the way
 *   back: every part not as the backup is left with its value before in the
 *   answer (never a credential), the step and the pair are unclear. An
 *   object without a deviation goes over and back whole (a post with a
 *   category and a tag, a page with a template).
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
 * - The ownership rule (Codex round 6 of branch 8, N1 to N4): ownership is
 *   a mark of its own in the journal, set where a call of the step's own
 *   changed the part, never taken from its state; a change by another hand
 *   before a refused call stays, a template a hook changed with the row is
 *   left on the way back as well, terms a hook changed as the row went back
 *   are not written over, a put back refused within the boundary keeps the
 *   ownership for the way back.
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
		AB_MCP_Sync_Resources::$time  = null;
		AB_MCP_Sync_Report::$page     = null;
		delete_option( \AB_MCP_Change_Lock::OPTION );
		if ( file_exists( WP_CONTENT_DIR . '/debug.log' ) ) {
			unlink( WP_CONTENT_DIR . '/debug.log' );
		}
		remove_all_filters( 'wp_insert_post_data' );
		remove_all_filters( 'sanitize_post_meta_note' );
		remove_all_filters( 'auth_post_meta_note' );
		remove_all_filters( 'map_meta_cap' );
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

	/** The step of the way back (op revert) a shown run names: the step that ran, or the first one of its steps. */
	private static function revert_step( array $shown ): array {
		if ( isset( $shown['step']['op'] ) && 'revert' === $shown['step']['op'] ) {
			return $shown['step'];
		}
		foreach ( isset( $shown['steps'] ) && is_array( $shown['steps'] ) ? $shown['steps'] : array() as $step ) {
			if ( 'revert' === $step['op'] ) {
				return $step;
			}
		}
		self::fail( 'No step of the way back.' );
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
		// The field rule of decision 28 (branch 9): a key equal on both sides
		// (note) is no part of the step and stays untouched.
		self::assertSame( array( 'row' ), array_column( $write['step']['parts']['parts'], 'part' ) );
		self::assertSame( array( 'read' ), array_column( $write['step']['parts']['parts'], 'state' ) );
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
		// The way back leads the same run to reverting. The row was never read
		// back (the request ended in the call): a hash equal to the target
		// proves no ownership, so it is left with its value before and the run
		// closes with that finding (decision 18 (b); the decision of
		// 07.11.2026). Before, the one boundary C put it back by its target.
		$begun = self::revert( $run_id );
		self::assertTrue( $begun['same_run'] );
		self::assertSame( $run_id, (int) $begun['run_id'] );
		self::assertSame( 'reverting', $begun['state'] );
		$back = self::revert_all( $run_id );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$outcome = array_column( self::revert_step( $back )['result']['parts'], null, 'part' );
		self::assertSame( 'left', $outcome['row']['state'] );
		self::assertStringContainsString( 'never read it back', $outcome['row']['note'] );
		self::assertStringContainsString( 'post_content: 12 bytes', $outcome['row']['value_before'], 'The value before, from the backup.' );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'], 'Left as it is.' );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $a['contact'] );
		self::assertSame( 'unclear', $pair['state'] );
		// Closing as checked takes a person; it is no tool.
		self::assertSame( 'ab_mcp_sync_run_unconfirmed', AB_MCP_Sync_Run::close_checked( $run_id, self::admin(), false )->get_error_code() );
	}

	public function testTheWayBackOfARunThatIsNotClosedBeginsWithAllOfItsStepsOrNotAtAll(): void {
		// GitHub Codex round 2 on free#137 (P1): the run moved to reverting and
		// its forward steps were settled before the steps of the way back were
		// inserted one by one; an insert that failed, or a request that ended
		// between two inserts, left the run reverting with a part of its plan,
		// and close_revert() reported it reverted. Now the change of state, the
		// settling and every insert are one transaction: the run is reverting
		// with the whole plan of its way back, or stays as it was, and the
		// refusal names the way. A trigger of the database stands in for the
		// insert that fails (the second step of the way back).
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		$planned = self::planned( array( 'contact', 'about' ) );
		$run     = self::begun( $planned['args'] );
		$run_id  = (int) $run['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check.' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The first page.' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The second page.' );
		$steps = AB_MCP_Sync_Run::steps( $run_id );
		self::assertIsArray( $steps );
		$count   = count( $steps );
		$planned = array();
		foreach ( $steps as $step ) {
			if ( 'planned' === $step['state'] ) {
				$planned[] = (int) $step['seq'];
			}
		}
		self::assertNotEmpty( $planned, 'The caches and the check of the run are still planned: the run is open.' );
		global $wpdb;
		$table = AB_MCP_Sync_Schema::table( 'steps' );
		$wpdb->query( "DROP TRIGGER IF EXISTS ab_it_refuse_step" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( sprintf( "CREATE TRIGGER ab_it_refuse_step BEFORE INSERT ON `%s` FOR EACH ROW BEGIN IF NEW.run_id = %d AND NEW.op = 'revert' AND NEW.seq = %d THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'; END IF; END", $table, $run_id, $count + 2 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::assertSame( '', $wpdb->last_error, 'The trigger is there.' );
		try {
			$args    = array( 'action' => 'plan', 'run_id' => $run_id );
			$preview = self::gated( 'wp_site_revert', $args );
			self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
			self::assertCount( 2, $preview['preview']['steps'] );
			$refused = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
			$message = self::refused( $refused, 'ab_mcp_sync_run_db' );
			self::assertStringContainsString( sprintf( 'The run %d stays open and nothing of its way back began', $run_id ), $message );
			self::assertStringContainsString( 'wp_site_revert action=plan', $message, 'The way.' );
		} finally {
			$wpdb->query( "DROP TRIGGER IF EXISTS ab_it_refuse_step" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		// The run as it was: open, no step of the way back, the forward steps
		// still planned, nothing skipped.
		$status = self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) );
		self::assertSame( 'open', $status['state'], json_encode( $status ) );
		self::assertSame( 0, $status['counts']['skipped'] );
		self::assertCount( $count, $status['steps'], 'No step of the way back stored.' );
		foreach ( $status['steps'] as $step ) {
			self::assertNotSame( 'revert', $step['op'] );
			if ( in_array( (int) $step['seq'], $planned, true ) ) {
				self::assertSame( 'planned', $step['state'] );
			}
		}
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'], 'Nothing written.' );
		// Without the refusal the way back begins whole: reverting, both steps
		// of the way back stored, the forward steps skipped; then everything
		// goes back.
		$begun = self::revert( $run_id );
		self::assertSame( 'reverting', $begun['state'] );
		self::assertSame( $run_id, (int) $begun['run_id'] );
		$reverts = array_filter( $begun['steps'], static fn( array $s ): bool => 'revert' === $s['op'] );
		self::assertCount( 2, $reverts );
		self::assertSame( count( $planned ), $begun['counts']['skipped'] );
		$back = self::revert_all( $run_id );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'] );
		self::assertSame( 'About us.', self::row_a( (int) $a['about'] )['post_content'] );
		// The run of the way back for a closed run is made with all its steps
		// or not at all as well (AB_MCP_Sync_Run::create()): a second run
		// writes both pages again and closes; the trigger refuses the second
		// step of the run of its way back: no run is made (before, one stood
		// closed as aborted, or open with a part of its steps), the site has no
		// open run, the pages keep the staging content; without the trigger the
		// way back runs whole.
		$planned = self::planned( array( 'contact', 'about' ) );
		$second  = (int) self::begun( $planned['args'] )['run_id'];
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( $second )['state'] );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'] );
		$runs_table = AB_MCP_Sync_Schema::table( 'runs' );
		$runs       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$runs_table`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( sprintf( "CREATE TRIGGER ab_it_refuse_step BEFORE INSERT ON `%s` FOR EACH ROW BEGIN IF NEW.run_id <> %d AND NEW.op = 'revert' AND NEW.seq = 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'; END IF; END", $table, $second ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::assertSame( '', $wpdb->last_error, 'The trigger is there.' );
		try {
			$args    = array( 'action' => 'plan', 'run_id' => $second );
			$preview = self::gated( 'wp_site_revert', $args );
			self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
			self::assertFalse( $preview['preview']['same_run'] );
			self::refused( self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) ), 'ab_mcp_sync_run_db' );
		} finally {
			$wpdb->query( "DROP TRIGGER IF EXISTS ab_it_refuse_step" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		self::assertSame( $runs, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$runs_table`" ), 'No run was made.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'No run is open.' );
		self::assertSame( 'closed_done', self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $second ) )['state'] );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'], 'Nothing written.' );
		$begun = self::revert( $second );
		self::assertSame( 'revert', $begun['type'] );
		self::assertCount( 2, $begun['steps'] );
		self::assertSame( 'closed_reverted', self::revert_all( (int) $begun['run_id'] )['state'] );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'] );
	}

	public function testTwoMetaKeysTheDatabaseComparesAlikeLockThePageBeforeTheFirstWriteAndAnOrdinaryPageRuns(): void {
		// GitHub Codex round 6 on free#137 (P2): the test database compares
		// meta_key with utf8mb4_unicode_ci (Foo and foo are one key), and
		// update_metadata() for Foo changes foo too. A page of this site with
		// both spellings is locked before the first write (post:meta_spelling),
		// nothing written; an ordinary page runs.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$contact = (int) $a['contact'];
		add_post_meta( $contact, 'Foo', 'a' );
		add_post_meta( $contact, 'foo', 'b' );
		self::assertSame( array( 'a', 'b' ), array_column( AB_MCP_IT_Sites::rows( 'a', 'postmeta', array( 'post_id' => (string) $contact ) ), 'meta_value' ), 'Two rows, two spellings.' );
		self::b( array( array( 'op' => 'add_meta', 'post' => (int) $b['contact'], 'key' => 'Foo', 'value' => 'a' ), array( 'op' => 'add_meta', 'post' => (int) $b['contact'], 'key' => 'foo', 'value' => 'b' ) ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		// The step writes foo (it differs on staging; branch 9: a key equal on
		// both sides would be no part, decision 28), and the database matches Foo with it.
		global $wpdb;
		self::assertSame( 1, (int) $wpdb->query( $wpdb->prepare( 'UPDATE `' . AB_MCP_IT_Sites::table( 'b', 'postmeta' ) . '` SET meta_value = %s WHERE post_id = %d AND CAST( meta_key AS BINARY ) = CAST( %s AS BINARY )', 'c', (int) $b['contact'], 'foo' ) ) );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$check   = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $check['state'], json_encode( $check ) );
		$finding = json_encode( self::step( $check, 1 )['result']['findings'] );
		self::assertStringContainsString( 'post:meta_spelling', $finding );
		self::assertStringContainsString( 'are one key to the database', $finding );
		self::assertSame( 'Write to us.', self::row_a( $contact )['post_content'], 'Nothing written.' );
		self::assertSame( array( 'a', 'b' ), array_column( AB_MCP_IT_Sites::rows( 'a', 'postmeta', array( 'post_id' => (string) $contact ) ), 'meta_value' ), 'Both rows as they were.' );
		// An ordinary page runs.
		$planned = self::planned( array( 'about' ) );
		$run     = self::begun( $planned['args'] );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( 'About us, on staging.', self::row_a( (int) $a['about'] )['post_content'] );
	}

	public function testTheReservationCountsTheBackupsOfTheObjectsOfThisSiteAndTheWriterHoldsTheRunToIt(): void {
		// GitHub Codex round 6 on free#137 (P2): the plan reserved from the
		// canonical size of the staging side only, while the backup holds the
		// rows of this site; large objects here with small replacements passed
		// the check and could fill the disk past the reserve. Now the plan
		// reserves from the rows of this site as they are (uncompressed, as the
		// store counts them), and the writer checks before each backup that it
		// fits what the run booked.
		self::pair();
		$big = str_repeat( 'Lorem ipsum dolor sit amet. ', 11000 );
		self::assertGreaterThan( 300000, strlen( $big ) );
		$a = self::pages_on_a( array( 'contact' => $big, 'about' => $big, 'team' => $big ) );
		$b = self::pages_on_b( array( 'contact' => $big, 'about' => $big, 'team' => $big ) );
		self::paired( self::staged() );
		foreach ( array( 'contact', 'about', 'team' ) as $name ) {
			self::change_on_b( $b[ $name ], $name, 'Short, on staging.' );
		}
		$planned = self::planned( array( 'contact', 'about', 'team' ) );
		// The disk: the reserve of the store, the test write and 400 KiB more.
		// From the staging side alone (three objects of a few hundred bytes
		// and the journals) that is enough; the backups of the three pages of
		// this site hold about 900 KB and do not fit.
		AB_MCP_Sync_Resources::$probe = static fn(): array => array(
			'free'                => \AB_MCP_Store_Budget::RESERVE + \AB_MCP_Store_Budget::TEST_WRITE + 400 * 1024,
			'time_limit'          => 0,
			'time_limit_settable' => false,
			'memory_limit'        => -1,
			'write_seconds'       => 0.01,
			'read_seconds'        => 0.01,
			'rows_seconds'        => 0.01,
		);
		try {
			$refused = self::gated( 'wp_site_push', $planned['args'] );
			$message = self::refused( $refused, 'ab_mcp_sync_run_resources' );
			self::assertStringContainsString( 'Free at least', $message );
		} finally {
			AB_MCP_Sync_Resources::$probe = null;
		}
		// With the disk as it is the run begins; its check books what the plan
		// reserved. A booking cut down afterwards (another run's bookings, say)
		// holds the writer: the backup of the first page does not fit, the step
		// fails before any mutation; with the booking back it writes.
		$run    = self::begun( $planned['args'] );
		$run_id = (int) $run['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check books.' );
		$booked = \AB_MCP_Store_Budget::bookings()[ $run_id ]['bytes'];
		self::assertGreaterThan( 900000, $booked, 'The booking holds the backups of this site.' );
		self::assertIsArray( \AB_MCP_Store_Budget::book( $run_id, 1000 ) );
		$failed = self::next( $run_id );
		self::assertSame( 'failed', $failed['step']['state'], json_encode( $failed['step'] ) );
		self::assertStringContainsString( 'would pass the booking, so nothing is written for the object', $failed['step']['result']['message'] );
		foreach ( $a as $id ) {
			self::assertSame( $big, self::row_a( (int) $id )['post_content'], 'Nothing written.' );
		}
		self::assertIsArray( \AB_MCP_Store_Budget::book( $run_id, $booked ) );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The next page, within the booking.' );
		// The backups the run holds already count: a booking that holds one more
		// backup alone (what the run holds, plus 1000 bytes) does not hold it
		// beside the one written, so the third page fails before any mutation.
		$groups = \AB_MCP_Protected_Store::groups( 'backups' );
		self::assertIsArray( $groups );
		$used = 0;
		foreach ( $groups as $entry ) {
			if ( (int) $entry['run_id'] === $run_id ) {
				$used += (int) $entry['used'];
			}
		}
		self::assertGreaterThan( 300000, $used, 'The backup of the page written holds the rows of this site.' );
		self::assertIsArray( \AB_MCP_Store_Budget::book( $run_id, $used + 1000 ) );
		$failed = self::next( $run_id );
		self::assertSame( 'failed', $failed['step']['state'], json_encode( $failed['step'] ) );
		self::assertStringContainsString( sprintf( 'its backups hold %d already', $used ), $failed['step']['result']['message'] );
		$written = 0;
		foreach ( $a as $id ) {
			if ( 'Short, on staging.' === self::row_a( (int) $id )['post_content'] ) {
				++$written;
			}
		}
		self::assertSame( 1, $written, 'The one page within the booking, no other.' );
	}

	public function testAPostWhoseTermHasAnOrderIsLockedBeforeTheFirstWriteAndOneWithoutGoesOverAndBack(): void {
		// GitHub Codex round 5 on free#137 (P2): a live relationship with a
		// term_order other than 0 (a plugin that sorts the terms) would lose
		// its order on the way back (wp_set_object_terms() makes every
		// relationship anew with 0), and the way back compared the keys only.
		// Such a post is locked before the first write (post:term_order,
		// nothing written); a post with the order 0, as the core writes it,
		// goes over and back as before.
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
		// A sorting plugin gives the relationship of News the order 3, past
		// every hook; the canonical form of the post carries it.
		global $wpdb;
		$ttid = (int) $news_a['term_taxonomy_id'];
		$wpdb->update( $wpdb->term_relationships, array( 'term_order' => 3 ), array( 'object_id' => $story_a, 'term_taxonomy_id' => $ttid ) );
		clean_object_term_cache( $story_a, 'post' );
		$slugs = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		// The order is part of the canonical form: the post changed on both
		// sides, a conflict, chosen staging_wins; the check before the first
		// write locks it all the same.
		$ids    = self::staged();
		$report = self::report( $ids );
		$entry  = self::post_entry( $report['all'], 'story' );
		self::assertSame( 'choose', $entry['action'], json_encode( $entry ) );
		$package = self::objects( $report['report_hash'], self::refs_of( array( $entry ) ) );
		$run     = self::begun( array( 'action' => 'plan', 'report_id' => $report['report_id'], 'choices' => array( array( 'entry' => (int) $entry['n'], 'choice' => 'staging_wins' ) ), 'packages' => array( $package ) ) );
		$check   = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $check['state'], json_encode( $check ) );
		$finding = json_encode( self::step( $check, 1 )['result']['findings'] );
		self::assertStringContainsString( 'post:term_order', $finding );
		self::assertStringContainsString( 'carries the term_order 3', $finding );
		self::assertSame( 'A story.', self::row_a( $story_a )['post_content'], 'Nothing written.' );
		self::assertSame( array( 'news' ), $slugs() );
		self::assertSame( '3', AB_MCP_IT_Sites::rows( 'a', 'term_relationships', array( 'object_id' => (string) $story_a, 'term_taxonomy_id' => (string) $ttid ) )[0]['term_order'], 'The order stands.' );
		// With the order 0 the post goes over and back as before, every part restored.
		$wpdb->update( $wpdb->term_relationships, array( 'term_order' => 0 ), array( 'object_id' => $story_a, 'term_taxonomy_id' => $ttid ) );
		clean_object_term_cache( $story_a, 'post' );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( 'other' ), $slugs() );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( array( 'news' ), $slugs() );
	}

	public function testAStagingPostWhoseTermHasAnOrderIsLockedBeforeTheFirstWriteAndOneWithoutGoesOverAndBack(): void {
		// GitHub Codex round 7 on free#137 (P2): a relationship of the staging
		// post with a term_order other than 0 is part of its canonical form
		// and of the target, but target_of() dropped the order and precheck()
		// asked the relationships of the post here only; the step wrote the
		// post and its terms with the order 0, the object as a whole did not
		// hash to its target, deviated, and its way back was gone. Such a post
		// is locked before the first write as well (post:term_order, the side,
		// the term and the order named; nothing written); a staging post with
		// the order 0 goes over and back as before.
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
		// On staging the post is changed and gets the category Other, which a
		// sorting plugin gives the order 7 there; the post here keeps 0.
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ) ) );
		self::assertSame( 1, self::b( array( array( 'op' => 'term_order', 'post' => $story_b, 'term' => $other_b, 'taxonomy' => 'category', 'order' => 7, 'as' => 'set' ) ) )['set'] );
		$slugs   = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		$orders  = static fn(): array => array_column( AB_MCP_IT_Sites::rows( 'a', 'term_relationships', array( 'object_id' => (string) $story_a ) ), 'term_order' );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		$check   = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $check['state'], json_encode( $check ) );
		$finding = json_encode( self::step( $check, 1 )['result']['findings'] );
		self::assertStringContainsString( 'post:term_order', $finding );
		self::assertStringContainsString( 'The term other of category on the post of the staging site carries the term_order 7', $finding );
		self::assertSame( 'A story.', self::row_a( $story_a )['post_content'], 'Nothing written.' );
		self::assertSame( array( 'news' ), $slugs() );
		self::assertSame( array( '0' ), $orders() );
		// With the order 0 on staging the post goes over and back as before.
		self::b( array( array( 'op' => 'term_order', 'post' => $story_b, 'term' => $other_b, 'taxonomy' => 'category', 'order' => 0 ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( 'A story, on staging.', self::row_a( $story_a )['post_content'] );
		self::assertSame( array( 'other' ), $slugs() );
		$begun = self::revert( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', self::revert_all( (int) $begun['run_id'] )['state'] );
		self::assertSame( array( 'news' ), $slugs() );
		self::assertSame( 'A story.', self::row_a( $story_a )['post_content'] );
	}

	public function testTheCachesOfPhaseSevenAreEmptiedInPartsWithinTheWorkOfEachCallEachObjectOnce(): void {
		// GitHub Codex round 5 on free#137 (P2): phase 7 emptied the caches of
		// every written object in one call, without a budget and without a
		// cursor; a call that ran out of time left the step planned, and the
		// next one began at the first object again. Now the caches are emptied
		// in parts within the work of each call, the cursor kept in the result
		// of the step; each object is emptied once; then the check closes the run.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.', 'team' => 'Our team.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.', 'team' => 'Our team.' ) );
		self::paired( self::staged() );
		foreach ( array( 'contact' => 'Write to us on staging.', 'about' => 'About us, on staging.', 'team' => 'Our team, on staging.' ) as $name => $content ) {
			self::change_on_b( $b[ $name ], $name, $content );
		}
		$planned = self::planned( array( 'contact', 'about', 'team' ) );
		$run_id  = (int) self::begun( $planned['args'] )['run_id'];
		for ( $i = 0; $i < 4; $i++ ) {
			self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check and the three pages.' );
		}
		// How often each page's cache is emptied (clean_post_cache fires the action).
		$cleaned = array();
		add_action(
			'clean_post_cache',
			static function ( $post_id ) use ( &$cleaned ) {
				$cleaned[ (int) $post_id ] = ( $cleaned[ (int) $post_id ] ?? 0 ) + 1;
			}
		);
		// A limit of 30 s, not raised: the work of a call is 15 s; with 20 s
		// passing per object one object is emptied per call.
		AB_MCP_Sync_Resources::$time = static fn(): array => array( 'before' => 30, 'after' => 30 );
		AB_MCP_Sync_Engine::$clock   = static function (): float {
			static $t = 1000.0;
			$t += 20;
			return $t;
		};
		try {
			$one = self::next( $run_id );
			self::assertIsArray( $one, is_wp_error( $one ) ? $one->get_error_message() : '' );
			self::assertSame( array( 'post', 'planned' ), array( $one['step']['op'], $one['step']['state'] ) );
			self::assertSame( 1, $one['step']['result']['cursor'] );
			self::assertCount( 1, $one['step']['result']['objects'] );
			self::assertCount( 1, $cleaned, 'One object emptied in the first call.' );
			$per = (int) max( $cleaned );
			self::assertGreaterThan( 0, $per, 'How often one emptying fires clean_post_cache.' );
			self::assertStringContainsString( 'caches of 1 of 3 written object(s) were emptied within the work of this call', $one['next'] );
			$two = self::next( $run_id );
			self::assertSame( 'planned', $two['step']['state'] );
			self::assertSame( 2, $two['step']['result']['cursor'] );
			self::assertCount( 2, $two['step']['result']['objects'] );
			$three = self::next( $run_id );
			self::assertSame( 'done', $three['step']['state'] );
			self::assertCount( 3, $three['step']['result']['objects'] );
			self::assertArrayNotHasKey( 'cursor', $three['step']['result'] );
			self::assertStringContainsString( 'caches of 3 written object(s) were emptied', $three['step']['result']['message'] );
		} finally {
			remove_all_actions( 'clean_post_cache' );
			AB_MCP_Sync_Engine::$clock   = null;
			AB_MCP_Sync_Resources::$time = null;
		}
		$ids = array_map( 'intval', array_values( $a ) );
		sort( $ids );
		ksort( $cleaned );
		self::assertSame( array_fill_keys( $ids, $per ), $cleaned, 'Each written object emptied exactly once over the three calls.' );
		self::probe_ok();
		$closed = self::finish( $run_id );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
	}

	public function testTheErrorLogIsCountedFromTheMarkOfTheCheckAndALogRotatedSinceIsAFinding(): void {
		// GitHub Codex round 4 on free#137 (P2): the check marks the error log
		// of the site (debug.log of wp-content) by its size; the verification
		// counts the lines it gained from there, in chunks (never the whole
		// file). Lines added during the run are a finding; a log smaller than
		// at the check (rotated or emptied) cannot be told from new lines and
		// is a finding as well, never «no new line».
		$log = WP_CONTENT_DIR . '/debug.log';
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		try {
			file_put_contents( $log, "[07-Nov-2026 10:00:00 UTC] PHP Notice: one\n[07-Nov-2026 10:00:01 UTC] PHP Notice: two\n" );
			// 1. Three lines added during the run: a finding with their count.
			$planned = self::planned( array( 'contact' ) );
			$run     = (int) self::begun( $planned['args'] )['run_id'];
			$check   = self::next( $run );
			self::assertSame( 'done', $check['step']['state'] );
			self::assertSame( filesize( $log ), $check['step']['result']['error_log_bytes'], 'The log marked by its size.' );
			self::assertSame( 'done', self::next( $run )['step']['state'], 'The page.' );
			file_put_contents( $log, "[07-Nov-2026 10:01:00 UTC] PHP Warning: three\n[07-Nov-2026 10:01:01 UTC] PHP Warning: four\n[07-Nov-2026 10:01:02 UTC] PHP Warning: five\n", FILE_APPEND );
			self::probe_ok();
			$closed = self::finish( $run );
			self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
			$messages = array_column( $closed['step']['result']['findings'], 'message' );
			self::assertCount( 1, $messages, json_encode( $messages ) );
			self::assertStringContainsString( 'has 3 new line(s) since the check', $messages[0] );
			// 2. The log rotated (smaller than at the check): a finding, said so.
			$planned = self::planned( array( 'about' ) );
			$run     = (int) self::begun( $planned['args'] )['run_id'];
			self::assertSame( 'done', self::next( $run )['step']['state'] );
			self::assertSame( 'done', self::next( $run )['step']['state'] );
			file_put_contents( $log, "[07-Nov-2026 10:02:00 UTC] PHP Notice: after the rotation\n" );
			$closed = self::finish( $run );
			self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
			$messages = array_column( $closed['step']['result']['findings'], 'message' );
			self::assertCount( 1, $messages, json_encode( $messages ) );
			self::assertStringContainsString( 'smaller than at the check before the run (rotated or emptied in between); it holds 1 line(s) now', $messages[0] );
		} finally {
			if ( file_exists( $log ) ) {
				unlink( $log );
			}
		}
		// 3. No log at the check, one made since: its lines are new whole.
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging, again.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = (int) self::begun( $planned['args'] )['run_id'];
		$check   = self::next( $run );
		self::assertSame( 0, $check['step']['result']['error_log_bytes'], 'No log yet: marked at 0.' );
		self::assertSame( 'done', self::next( $run )['step']['state'] );
		try {
			file_put_contents( $log, "[07-Nov-2026 10:03:00 UTC] PHP Warning: made since\n" );
			$closed = self::finish( $run );
			self::assertSame( 'closed_findings', $closed['state'] );
			self::assertStringContainsString( 'has 1 new line(s) since the check', array_column( $closed['step']['result']['findings'], 'message' )[0] );
		} finally {
			if ( file_exists( $log ) ) {
				unlink( $log );
			}
		}
	}

	public function testAnAbortTakesTheLockAndADelayedWriteStepFindsTheRunClosedAndWritesNothing(): void {
		// GitHub Codex round 4 on free#137 (P2): a next that had read the open
		// run and chosen a write step could still begin it after another call
		// aborted the run, and write into a run closed as aborted whose booking
		// was given back; the abort took no lock, and the boundary never read
		// the run again under it. Now the abort takes the lock of the boundary
		// (a step being written: busy, call again) and reads the run under it;
		// the boundary reads the run under the lock before a step is begun
		// (still()): not open, nothing is written.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$run_id  = (int) $run['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check.' );
		// The run as a delayed request read it: open, its write step planned.
		$read = AB_MCP_Sync_Run::get( $run_id );
		self::assertSame( 'open', $read['state'] );
		// An abort beside a step being written (another request holds the lock): busy.
		update_option( \AB_MCP_Change_Lock::OPTION, time() );
		self::assertStringContainsString( 'lock of the write boundary is held', self::refused( self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) ), 'ab_mcp_sync_run_busy' ) );
		delete_option( \AB_MCP_Change_Lock::OPTION );
		self::assertSame( 'open', AB_MCP_Sync_Run::get( $run_id )['state'] );
		// The abort.
		$closed = self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) );
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
		self::assertSame( 'closed_aborted', $closed['state'] );
		// The delayed request goes on with the run as it read it: the boundary
		// finds the run closed under the lock and writes nothing.
		$late = AB_MCP_Sync_Engine::next( $read, null );
		$text = self::refused( $late, 'ab_mcp_sync_run_state' );
		self::assertStringContainsString( 'was open when this call read it and is closed_aborted now', $text );
		self::assertStringContainsString( 'nothing was written', $text );
		self::assertSame( 'planned', AB_MCP_Sync_Run::step( $run_id, 2 )['state'], 'The step was not begun.' );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'], 'Nothing written.' );
		self::assertSame( 'closed_aborted', AB_MCP_Sync_Run::get( $run_id )['state'] );
		self::assertFalse( \AB_MCP_Change_Lock::held() );
		// A delayed abort with the run as it read it: the run is read again under the lock.
		self::assertStringContainsString( 'is closed_aborted now (another call moved it since this one read it as open): nothing was changed', self::refused( AB_MCP_Sync_Engine::abort( $read ), 'ab_mcp_sync_run_changed' ) );
	}

	public function testAnInterruptedRunOfTheWayBackIsLedOnWithTheTokenOrClosedAsCheckedAndFreesTheSite(): void {
		// GitHub Codex round 4 on free#137 (P1): a run of the way back whose
		// request ended inside a step became interrupted, took no next, and
		// the hint pointed to action=plan, which refused every run of the type
		// revert; abort takes open runs only, so the run held the site. Both
		// ways of decision 18 hold for a run of the way back as well: (b)
		// wp_site_revert action=plan with the token on this run leads it on
		// (the interrupted step stays unclear, its pair unclear, the steps
		// still planned go back one by one), (a) a person closes it as checked
		// (close_checked(); the button comes with branch 12). A request that
		// read the run before finds it moved under the lock and writes nothing.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.', 'team' => 'Our team.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.', 'team' => 'Our team.' ) );
		self::paired( self::staged() );
		foreach ( array( 'contact' => 'Write to us on staging.', 'about' => 'About us, on staging.', 'team' => 'Our team, on staging.' ) as $name => $content ) {
			self::change_on_b( $b[ $name ], $name, $content );
		}
		$planned = self::planned( array( 'contact', 'about', 'team' ) );
		$push    = (int) self::begun( $planned['args'] )['run_id'];
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( $push )['state'] );
		$begun = self::revert( $push );
		$r     = (int) $begun['run_id'];
		self::assertSame( 'revert', $begun['type'] );
		$seqs = array();
		foreach ( AB_MCP_Sync_Run::steps( $r ) as $step ) {
			if ( 'revert' === $step['op'] ) {
				$seqs[ (int) $step['parts']['forward_seq'] ] = (int) $step['seq'];
			}
		}
		ksort( $seqs );
		self::assertCount( 3, $seqs );
		list( $s1, $s2, $s3 ) = array_values( $seqs );
		$objects = array();
		foreach ( AB_MCP_Sync_Run::steps( $push ) as $step ) {
			if ( 'write' === $step['op'] ) {
				$objects[ (int) $step['seq'] ] = (int) $step['parts']['object']['live_ref'];
			}
		}
		$live = static fn( int $seq ) => $objects[ array_search( $seq, $seqs, true ) ];
		// The pages by their ids: what they held before the run, and on staging.
		$name_of  = array_flip( array_map( 'intval', $a ) );
		$before   = array( 'contact' => 'Write to us.', 'about' => 'About us.', 'team' => 'Our team.' );
		$staging  = array( 'contact' => 'Write to us on staging.', 'about' => 'About us, on staging.', 'team' => 'Our team, on staging.' );
		$first  = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $r ) );
		self::assertSame( 'done', $first['step']['state'], 'The first object back.' );
		$back_1 = $live( $s1 );
		// The run as a request read it before the interruption (open).
		$read = AB_MCP_Sync_Run::get( $r );
		self::assertSame( 'open', $read['state'] );
		// The interruption: the second step begun, its request gone (no lock).
		self::assertTrue( AB_MCP_Sync_Run::update_step( $r, $s2, array( 'state' => 'begun' ), 'planned' ) );
		$status = self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => $r ) );
		self::assertSame( 'interrupted', $status['state'] );
		self::assertStringContainsString( 'closes it as checked', $status['next'] );
		self::assertStringContainsString( 'wp_site_revert action=plan with this run_id leads it on', $status['next'], 'Way (b) for a run of the way back.' );
		$refused = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $r ) ), 'ab_mcp_sync_run_interrupted' );
		self::assertStringContainsString( '(a run of the way back)', $refused );
		self::assertStringContainsString( 'leads it on', $refused );
		// The request that read the run as open goes on: the boundary finds
		// the run interrupted under the lock and writes nothing.
		self::assertStringContainsString( 'is interrupted now', self::refused( AB_MCP_Sync_Engine::next( $read, null ), 'ab_mcp_sync_run_state' ) );
		self::assertSame( 'planned', AB_MCP_Sync_Run::step( $r, $s3 )['state'] );
		self::assertSame( $staging[ $name_of[ $live( $s3 ) ] ], self::row_a( $live( $s3 ) )['post_content'], 'Nothing written.' );
		// Way (b): plan and token on the run of the way back lead it on.
		$args    = array( 'action' => 'plan', 'run_id' => $r );
		$preview = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertTrue( $preview['preview']['same_run'] );
		self::assertSame( array( 'stays', 'back where own' ), array_column( $preview['preview']['steps'], 'will' ) );
		$led = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $led, is_wp_error( $led ) ? $led->get_error_message() : '' );
		self::assertSame( 'reverting', $led['state'] );
		self::assertSame( 'unclear', AB_MCP_Sync_Run::step( $r, $s2 )['state'], 'The interrupted step stays, unclear.' );
		self::assertStringContainsString( 'nothing of it is put back again', AB_MCP_Sync_Run::step( $r, $s2 )['result']['message'] );
		self::assertSame( 'planned', AB_MCP_Sync_Run::step( $r, $s3 )['state'], 'The step still planned goes back in its turn.' );
		self::assertSame( 1, $led['counts']['planned'] );
		$pair_2 = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $live( $s2 ) );
		self::assertSame( 'unclear', $pair_2['state'], 'The pair of the interrupted step.' );
		$third = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $r ) );
		self::assertSame( 'done', $third['step']['state'], 'The third object back.' );
		self::assertSame( 'closed_findings', $third['state'], json_encode( $third ) );
		self::assertSame( 1, $third['counts']['unclear'] );
		self::assertSame( $before[ $name_of[ $back_1 ] ], self::row_a( $back_1 )['post_content'] );
		self::assertSame( $before[ $name_of[ $live( $s3 ) ] ], self::row_a( $live( $s3 ) )['post_content'] );
		self::assertSame( $staging[ $name_of[ $live( $s2 ) ] ], self::row_a( $live( $s2 ) )['post_content'], 'The interrupted step: nothing put back again.' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free.' );
		// Way (a): another run of the way back (the object of the third step,
		// back on this site and still changed on staging, pushed again; the
		// object of the interrupted step has an unclear pair and is not planned
		// until a person aligns it), interrupted, closed as checked by a person
		// (close_checked(); the pair of its begun step unclear).
		$again   = $live( $s3 );
		$planned = self::planned( array( $name_of[ $again ] ) );
		$push_2  = (int) self::begun( $planned['args'] )['run_id'];
		self::assertSame( 'closed_done', self::finish( $push_2 )['state'] );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $again )['state'] );
		$r2 = (int) self::revert( $push_2 )['run_id'];
		$s  = null;
		foreach ( AB_MCP_Sync_Run::steps( $r2 ) as $step ) {
			if ( 'revert' === $step['op'] ) {
				$s = (int) $step['seq'];
			}
		}
		self::assertTrue( AB_MCP_Sync_Run::update_step( $r2, $s, array( 'state' => 'begun' ), 'planned' ) );
		self::assertSame( 'interrupted', self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => $r2 ) )['state'] );
		$closed = AB_MCP_Sync_Run::close_checked( $r2, self::admin(), true );
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
		self::assertSame( 'closed_checked', $closed['state'] );
		self::assertSame( 'unclear', AB_MCP_Sync_Run::step( $r2, $s )['state'] );
		self::assertSame( 'unclear', AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $again )['state'], 'The pair of the step taken back, unclear.' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'open_one is free.' );
		self::assertSame( 'open', self::begun( self::planned( array( $name_of[ $back_1 ] ) )['args'] )['state'], 'A new run begins (the first object, back here and still changed on staging).' );
	}

	public function testTheWayBackOfARunDoesNotBeginBesideAStepBeingWrittenNorOnAPlanTheRunHasLeftBehind(): void {
		// GitHub Codex round 3 on free#137 (P1): between plan() and the
		// transaction of begin() a concurrent next could begin a write step; the
		// run still open, the transition succeeded, the step was marked unclear
		// though the plan did not take it back, and the writer's journal
		// updates failed. Now begin() runs under the lock of the write boundary
		// for the whole call (a step being written holds it: refused as busy,
		// call again, the run as it was), settles the run under it (a step
		// begun there is one a request that ended left: the run is interrupted,
		// the plan confirmed for the open run refused, plan again), and checks
		// the plan confirmed against the run as it is under the lock (a step
		// wrote in between: refused, plan again, the run as it was).
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		$planned = self::planned( array( 'contact', 'about' ) );
		$run     = self::begun( $planned['args'] );
		$run_id  = (int) $run['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check.' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The first page.' );
		$third = null;
		foreach ( AB_MCP_Sync_Run::steps( $run_id ) as $step ) {
			if ( 'write' === $step['op'] && 'planned' === $step['state'] ) {
				$third = (int) $step['seq'];
			}
		}
		self::assertNotNull( $third, 'The second page is still planned.' );
		$args  = array( 'action' => 'plan', 'run_id' => $run_id );
		$begin = new \ReflectionMethod( \AB_MCP_Sync_Revert::class, 'begin' );
		$unchanged = static function ( string $when ) use ( $run_id, $third ): void {
			$run = AB_MCP_Sync_Run::get( $run_id );
			self::assertSame( 'open', $run['state'], $when );
			foreach ( AB_MCP_Sync_Run::steps( $run_id ) as $step ) {
				self::assertNotSame( 'revert', $step['op'], $when . ': no step of the way back.' );
				self::assertNotSame( 'unclear', $step['state'], $when . ': nothing marked unclear.' );
				self::assertNotSame( 'skipped', $step['state'], $when . ': nothing skipped.' );
			}
		};
		// 1. The plan made; then another request begins the write step and
		// holds the lock of the boundary: refused as busy.
		$plan = \AB_MCP_Sync_Revert::plan( $args );
		self::assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		self::assertCount( 1, $plan['items'] );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, $third, array( 'state' => 'begun' ), 'planned' ) );
		update_option( \AB_MCP_Change_Lock::OPTION, time() );
		self::assertTrue( AB_MCP_Sync_Run::lock_fresh() );
		$refused = $begin->invoke( null, $plan, $args );
		self::assertStringContainsString( 'the lock of the write boundary is held', self::refused( $refused, 'ab_mcp_sync_run_busy' ) );
		self::assertSame( 'begun', AB_MCP_Sync_Run::step( $run_id, $third )['state'], 'The step being written is left to its writer.' );
		$unchanged( 'busy' );
		// 2. The lock given back, the step still begun (its request did not
		// answer): under the lock of the call it is a step a request that
		// ended left, so the run is settled as interrupted there, and the plan
		// confirmed for the open run is refused (plan again: the way back of
		// the interrupted run marks the step unclear). Before, begin() refused
		// the step as begun and left the settling to a status call.
		delete_option( \AB_MCP_Change_Lock::OPTION );
		$refused = $begin->invoke( null, $plan, $args );
		self::assertStringContainsString( 'was open when the way back was planned and is interrupted now', self::refused( $refused, 'ab_mcp_sync_revert_changed' ) );
		self::assertSame( 'interrupted', AB_MCP_Sync_Run::get( $run_id )['state'] );
		self::assertSame( 'begun', AB_MCP_Sync_Run::step( $run_id, $third )['state'], 'Nothing of the way back began.' );
		// For the third case the run as before the step began (put back by the test).
		self::assertTrue( AB_MCP_Sync_Run::set_state( $run_id, 'interrupted', 'open' ) );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, $third, array( 'state' => 'planned' ), 'begun' ) );
		$unchanged( 'begun' );
		// 3. The plan made; then the write step runs to its end: the plan
		// confirmed is not the run as it is, refused; planned anew, the way
		// back takes both steps back.
		$plan = \AB_MCP_Sync_Revert::plan( $args );
		self::assertIsArray( $plan );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The second page, written in between.' );
		self::assertSame( 'About us, on staging.', self::row_a( (int) $a['about'] )['post_content'] );
		$refused = $begin->invoke( null, $plan, $args );
		self::assertStringContainsString( 'the run changed while it was planned', self::refused( $refused, 'ab_mcp_sync_revert_changed' ) );
		$unchanged( 'changed' );
		self::assertFalse( \AB_MCP_Change_Lock::held(), 'The lock given back in every way.' );
		$begun = self::revert( $run_id );
		self::assertSame( 'reverting', $begun['state'] );
		self::assertCount( 2, array_filter( $begun['steps'], static fn( array $s ): bool => 'revert' === $s['op'] ) );
		self::assertSame( 'closed_reverted', self::revert_all( $run_id )['state'] );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'] );
		self::assertSame( 'About us.', self::row_a( (int) $a['about'] )['post_content'] );
	}

	/**
	 * What a call that changes a run may change, read past every cache: the
	 * run and its steps as rows, the bookings of the store, the groups of the
	 * backups with their files, and the rows of the pages named.
	 */
	private static function untouched( int $run_id, array $pages ): array {
		global $wpdb;
		$runs     = AB_MCP_Sync_Schema::table( 'runs' );
		$steps    = AB_MCP_Sync_Schema::table( 'steps' );
		$bookings = \AB_MCP_Store_Budget::bookings();
		$groups   = AB_MCP_Protected_Store::groups( 'backups' );
		$backups  = array();
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			$backups[ (string) $group['gid'] ] = array( $group, AB_MCP_Protected_Store::files( 'backups', (string) $group['gid'] ) );
		}
		$rows = array();
		foreach ( $pages as $id ) {
			$rows[ (int) $id ] = self::row_a( (int) $id );
		}
		return array(
			'run'      => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$runs` WHERE run_id = %d", $run_id ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'steps'    => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$steps` WHERE run_id = %d ORDER BY seq", $run_id ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'bookings' => is_wp_error( $bookings ) ? $bookings->get_error_code() : $bookings,
			'backups'  => is_wp_error( $groups ) ? $groups->get_error_code() : $backups,
			'pages'    => $rows,
		);
	}

	public function testAnInterruptedWayBackOfTheSameRunIsLedOnWithTheTokenAndTheSiteIsFreeAfterwards(): void {
		// GitHub Codex round 6 on free#137 (P1, revert:212): the way back of a
		// run that was not closed runs in the same run, of the type push. A
		// request that ended inside one of its steps left the run interrupted;
		// wp_site_revert action=plan planned a new way back for it, found every
		// write step taken back already (the steps of the way back name them)
		// and refused, and neither next nor abort takes an interrupted run, so
		// it held the site. Way (b) of decision 18 is one way for every
		// interrupted run now (the decision of 07.11.2026 evening, Variante A):
		// where its way back has begun, exactly that way back is led on, of
		// either type: the step the interruption left begun stays unclear
		// (nothing of it is put back again), the steps still planned go back
		// one by one, no step is added; then the run closes with findings and
		// names the step that stayed, and the site is free.
		self::pair();
		$before  = array( 'contact' => 'Write to us.', 'about' => 'About us.', 'team' => 'Our team.' );
		$staging = array( 'contact' => 'Write to us on staging.', 'about' => 'About us, on staging.', 'team' => 'Our team, on staging.' );
		$a       = self::pages_on_a( $before );
		$b       = self::pages_on_b( $before );
		self::paired( self::staged() );
		foreach ( $staging as $name => $content ) {
			self::change_on_b( $b[ $name ], $name, $content );
		}
		$planned = self::planned( array( 'contact', 'about', 'team' ) );
		$run_id  = (int) self::begun( $planned['args'] )['run_id'];
		for ( $i = 0; $i < 4; $i++ ) {
			self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check and the three pages.' );
		}
		// The way back of the same run: it is open (the caches and the check
		// of the run still planned), so it stays a run of the type push.
		$begun = self::revert( $run_id );
		self::assertTrue( $begun['same_run'] );
		self::assertSame( $run_id, (int) $begun['run_id'] );
		self::assertSame( 'push', $begun['type'] );
		self::assertSame( 'reverting', $begun['state'] );
		$seqs    = array();
		$objects = array();
		foreach ( AB_MCP_Sync_Run::steps( $run_id ) as $step ) {
			if ( 'revert' === $step['op'] ) {
				$seqs[ (int) $step['parts']['forward_seq'] ] = (int) $step['seq'];
			} elseif ( 'write' === $step['op'] ) {
				$objects[ (int) $step['seq'] ] = (int) $step['parts']['object']['live_ref'];
			}
		}
		ksort( $seqs );
		self::assertCount( 3, $seqs );
		list( $s1, $s2, $s3 ) = array_values( $seqs );
		$live    = static fn( int $seq ): int => $objects[ array_search( $seq, $seqs, true ) ];
		$name_of = array_flip( array_map( 'intval', $a ) );
		self::assertSame( 'done', self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $run_id ) )['step']['state'], 'The first object back.' );
		self::assertSame( $before[ $name_of[ $live( $s1 ) ] ], self::row_a( $live( $s1 ) )['post_content'] );
		// The second step of the way back in a process of its own that exits
		// once its row went back (wp_update_post() fires wp_insert_post after
		// its UPDATE): the step stays begun, the shutdown gives the lock back.
		try {
			AB_MCP_IT_Sync_Steps::run( 'a', array( array( 'op' => 'revert', 'args' => array( 'action' => 'next', 'run_id' => $run_id ), 'exit_at' => 'wp_insert_post' ) ) );
			self::fail( 'The process exits inside the step of the way back.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'gave no JSON', $e->getMessage() );
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Backup::forget();
		self::assertSame( 'begun', AB_MCP_Sync_Run::step( $run_id, $s2 )['state'] );
		self::assertSame( 'reverting', AB_MCP_Sync_Run::get( $run_id )['state'], 'Nothing has settled it yet.' );
		self::assertFalse( AB_MCP_Sync_Run::lock_fresh(), 'The shutdown of the process gave the lock back.' );
		$left = self::row_a( $live( $s2 ) )['post_content'];
		// The first call after it changes the run: under its own lock it finds
		// the step a request that ended left begun, settles the run as
		// interrupted (no status call before) and names the way that goes.
		$refused = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $run_id ) ), 'ab_mcp_sync_run_interrupted' );
		self::assertStringContainsString( '(on its way back)', $refused );
		self::assertStringContainsString( 'wp_site_revert action=plan with this run_id leads it on', $refused );
		self::assertSame( 'interrupted', AB_MCP_Sync_Run::get( $run_id )['state'] );
		self::assertSame( 'planned', AB_MCP_Sync_Run::step( $run_id, $s3 )['state'], 'Nothing of the way back ran.' );
		$status = self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) );
		self::assertSame( 'interrupted', $status['state'] );
		self::assertStringContainsString( 'closes it as checked', $status['next'] );
		self::assertStringContainsString( 'wp_site_revert action=plan with this run_id leads it on', $status['next'], 'Way (b) for the way back of the same run.' );
		self::assertStringContainsString( 'leads it on', self::refused( self::next( $run_id ), 'ab_mcp_sync_run_interrupted' ) );
		// Way (b): plan and token on the run lead exactly its way back on;
		// steps is not taken (no step is added).
		$args    = array( 'action' => 'plan', 'run_id' => $run_id );
		self::refused( self::gated( 'wp_site_revert', $args + array( 'steps' => array( 2 ) ) ), 'ab_mcp_sync_revert_steps' );
		$preview = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertTrue( $preview['preview']['same_run'] );
		self::assertSame( array( 'stays', 'back where own' ), array_column( $preview['preview']['steps'], 'will' ) );
		$led = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $led, is_wp_error( $led ) ? $led->get_error_message() : '' );
		self::assertSame( 'reverting', $led['state'] );
		self::assertSame( 'push', $led['type'] );
		self::assertCount( 3, array_filter( $led['steps'], static fn( array $s ): bool => 'revert' === $s['op'] ), 'No step of the way back added.' );
		self::assertSame( 1, $led['counts']['planned'] );
		self::assertSame( 'unclear', AB_MCP_Sync_Run::step( $run_id, $s2 )['state'], 'The interrupted step stays, unclear.' );
		self::assertStringContainsString( 'nothing of it is put back again', AB_MCP_Sync_Run::step( $run_id, $s2 )['result']['message'] );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		self::assertSame( 'unclear', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $live( $s2 ) )['state'], 'The pair of the interrupted step.' );
		$third = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $run_id ) );
		self::assertIsArray( $third, is_wp_error( $third ) ? $third->get_error_message() : '' );
		self::assertSame( 'done', $third['step']['state'], 'The third object back.' );
		self::assertSame( 'closed_findings', $third['state'], json_encode( $third ) );
		self::assertSame( 1, $third['counts']['unclear'] );
		self::assertStringContainsString( sprintf( 'step %d of the way back stayed as it is', $s2 ), $third['next'], 'The close names what stayed.' );
		self::assertSame( $before[ $name_of[ $live( $s1 ) ] ], self::row_a( $live( $s1 ) )['post_content'] );
		self::assertSame( $before[ $name_of[ $live( $s3 ) ] ], self::row_a( $live( $s3 ) )['post_content'] );
		self::assertSame( $left, self::row_a( $live( $s2 ) )['post_content'], 'The interrupted step: as its request left it, nothing put back again.' );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $live( $s3 ) )['state'] );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free.' );
		self::assertSame( 'open', self::begun( self::planned( array( $name_of[ $live( $s1 ) ] ) )['args'] )['state'], 'A new run begins (the first object, back here and still changed on staging).' );
	}

	public function testACallThatChangesARunIsRefusedAsBusyWithoutAnyChangeWhileAnotherRequestHoldsTheLock(): void {
		// GitHub Codex rounds 3, 4 and 6 on free#137 found one change of a run
		// outside the lock each (the begin of the way back, the abort, the
		// close of the way back beside its last step). Now every call of
		// wp_site_push and wp_site_revert that can change a run takes the lock
		// of the write boundary at its start and holds it to its answer (the
		// decision of 07.11.2026 evening, Variante A): another request holding
		// it (a row of the lock with another owner) refuses next, abort, plan
		// with the token and the close of the way back as busy, and nothing of
		// the run, its steps, the bookings and the backups changes; once it is
		// free, each goes.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		$planned = self::planned( array( 'contact', 'about' ) );
		$run_id  = (int) self::begun( $planned['args'] )['run_id'];
		$pages   = array( (int) $a['contact'], (int) $a['about'] );
		$revert  = array( 'action' => 'plan', 'run_id' => $run_id );
		$busy    = function ( string $when, bool $wrote ) use ( $run_id, $pages, $revert ): void {
			update_option( \AB_MCP_Change_Lock::OPTION, time() );
			$was = self::untouched( $run_id, $pages );
			foreach ( array( 'next' => array( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) ), 'abort' => array( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) ) ) as $what => $call ) {
				$text = self::refused( self::gated( $call[0], $call[1] ), 'ab_mcp_sync_run_busy' );
				self::assertStringContainsString( 'the lock of the write boundary is held', $text, "$when: $what" );
				self::assertStringContainsString( 'Call again', $text, "$when: $what" );
			}
			// The engine takes the lock itself as well (a request that reached it).
			self::refused( AB_MCP_Sync_Engine::next( AB_MCP_Sync_Run::get( $run_id ), null ), 'ab_mcp_sync_run_busy' );
			self::refused( AB_MCP_Sync_Engine::abort( AB_MCP_Sync_Run::get( $run_id ) ), 'ab_mcp_sync_run_busy' );
			if ( $wrote ) {
				// The way back of the same run planned (a step wrote), its token refused as busy.
				$preview = self::gated( 'wp_site_revert', $revert );
				self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
				self::refused( self::gated( 'wp_site_revert', $revert + array( 'confirm_token' => $preview['confirm_token'] ) ), 'ab_mcp_sync_run_busy' );
			}
			self::assertSame( $was, self::untouched( $run_id, $pages ), "$when: nothing changed." );
			self::assertFalse( \AB_MCP_Change_Lock::held(), "$when: nothing is held." );
			delete_option( \AB_MCP_Change_Lock::OPTION );
		};
		// The check before the first write planned (before, it booked the
		// store for the run before it asked the lock), then a write step.
		$busy( 'the check', false );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check, once the lock is free.' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The first page.' );
		$busy( 'a write step', true );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The second page, once the lock is free.' );
		self::assertSame( 'About us, on staging.', self::row_a( (int) $a['about'] )['post_content'] );
		// The way back of the same run begins once the lock is free; its
		// first step goes back.
		$begun = self::revert( $run_id );
		self::assertSame( 'reverting', $begun['state'] );
		$reverts = array_values( array_filter( $begun['steps'], static fn( array $s ): bool => 'revert' === $s['op'] ) );
		self::assertCount( 2, $reverts );
		self::assertSame( 'done', self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $run_id ) )['step']['state'] );
		$seq = (int) $reverts[1]['seq'];
		// Its last step being put back by another request (begun, the lock
		// held): a request that read the run before (reverting) reaches the
		// close of the way back. Before, pick() found no step planned and
		// close_revert() closed the run beside the step, gave its booking back
		// and cleaned up without the lock (GitHub Codex round 6, P1, engine:162).
		$read = AB_MCP_Sync_Run::get( $run_id );
		self::assertSame( 'reverting', $read['state'] );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => 'begun' ), 'planned' ) );
		update_option( \AB_MCP_Change_Lock::OPTION, time() );
		$was = self::untouched( $run_id, $pages );
		self::assertStringContainsString( 'the lock of the write boundary is held', self::refused( AB_MCP_Sync_Engine::next( $read, null ), 'ab_mcp_sync_run_busy' ) );
		self::refused( self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $run_id ) ), 'ab_mcp_sync_run_busy' );
		self::assertSame( $was, self::untouched( $run_id, $pages ), 'The close of the way back: nothing changed.' );
		$status = self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => $run_id ) );
		self::assertSame( 'reverting', $status['state'], 'A step begun under a lock another request holds is not interrupted.' );
		delete_option( \AB_MCP_Change_Lock::OPTION );
		// The close itself, reached under a lock with a step of the way back
		// still begun (its own call, here): refused, nothing closed.
		$close = new \ReflectionMethod( AB_MCP_Sync_Engine::class, 'close_revert' );
		$kept  = AB_MCP_Sync_Run::under_lock( static fn() => $close->invoke( null, AB_MCP_Sync_Run::get( $run_id ) ), 'The test' );
		self::assertStringContainsString( sprintf( 'its step %d is begun', $seq ), self::refused( $kept, 'ab_mcp_sync_run_state' ) );
		self::assertSame( 'reverting', AB_MCP_Sync_Run::get( $run_id )['state'] );
		// The other request answered (its step as it was before it began, for
		// the test) and gave the lock back: the way back goes and closes.
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => 'planned' ), 'begun' ) );
		$back = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $run_id ) );
		self::assertIsArray( $back, is_wp_error( $back ) ? $back->get_error_message() : '' );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'] );
		self::assertSame( 'About us.', self::row_a( (int) $a['about'] )['post_content'] );
		self::assertNull( AB_MCP_Sync_Run::open_run() );
	}

	public function testADelayedCallWithAnOldReadOfTheRunWritesNothingAfterAnAbortOrAfterTheWayBackClosed(): void {
		// The run is read once, under the lock of the call, before anything is
		// decided (the one place of still(); the decision of 07.11.2026
		// evening, Variante A): a request that read the run before another
		// call aborted it, or before its way back closed, finds it moved and
		// writes nothing. Before, only the boundaries read the run again; the
		// check before the first write booked the store for an aborted run and
		// marked itself done.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run_id  = (int) self::begun( $planned['args'] )['run_id'];
		$pages   = array( (int) $a['contact'] );
		$read    = AB_MCP_Sync_Run::get( $run_id );
		self::assertSame( 'open', $read['state'] );
		self::assertSame( 'closed_aborted', self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) )['state'] );
		$was  = self::untouched( $run_id, $pages );
		$text = self::refused( AB_MCP_Sync_Engine::next( $read, null ), 'ab_mcp_sync_run_state' );
		self::assertStringContainsString( 'was open when this call read it and is closed_aborted now', $text );
		self::assertStringContainsString( 'nothing was written', $text );
		self::assertSame( $was, self::untouched( $run_id, $pages ), 'After the abort: the check not run, nothing booked.' );
		self::assertSame( 'planned', AB_MCP_Sync_Run::step( $run_id, 1 )['state'] );
		// The way back of a second run, read while it was reverting, closed in
		// between: the delayed next and abort write nothing.
		$second = (int) self::begun( self::planned( array( 'contact' ) )['args'] )['run_id'];
		self::assertSame( 'done', self::next( $second )['step']['state'], 'The check.' );
		self::assertSame( 'done', self::next( $second )['step']['state'], 'The page.' );
		self::assertSame( 'reverting', self::revert( $second )['state'] );
		$read = AB_MCP_Sync_Run::get( $second );
		self::assertSame( 'closed_reverted', self::revert_all( $second )['state'] );
		$was = self::untouched( $second, $pages );
		self::assertStringContainsString( 'was reverting when this call read it and is closed_reverted now', self::refused( AB_MCP_Sync_Engine::next( $read, null ), 'ab_mcp_sync_run_state' ) );
		self::refused( AB_MCP_Sync_Engine::abort( $read ), 'ab_mcp_sync_run_state' );
		self::assertSame( $was, self::untouched( $second, $pages ), 'After the close of the way back: nothing changed.' );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'] );
	}

	public function testARecordOfTheRunThatDoesNotReadBackIsDecidedOnByNoCall(): void {
		// GitHub Codex round 8 on free#137 (P2), the side that fails closed:
		// the records of a run are read through AB_MCP_Sync_Canon::decode()
		// (AB_MCP_Sync_Run::records()), so bytes come back as bytes; a record
		// whose marker does not read back (no writer of this version stores
		// one; here written by SQL) is read as absent and named in unread, and
		// no call decides on it: the plan of the way back refuses a step whose
		// journal does not read back, with the way (the other steps); a call
		// that changes the run refuses a run whose plan does not read back.
		// Nothing is written.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		$run_id = (int) self::begun( self::planned( array( 'contact', 'about' ) )['args'] )['run_id'];
		for ( $i = 0; $i < 3; $i++ ) {
			self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check and the two pages.' );
		}
		$writes = array();
		foreach ( AB_MCP_Sync_Run::steps( $run_id ) as $step ) {
			if ( 'write' === $step['op'] ) {
				$writes[] = (int) $step['seq'];
			}
		}
		list( $w1, $w2 ) = $writes;
		global $wpdb;
		$steps = AB_MCP_Sync_Schema::table( 'steps' );
		$runs  = AB_MCP_Sync_Schema::table( 'runs' );
		// The journal of the first page: a marker of bytes that are UTF-8 after all.
		self::assertSame( 1, $wpdb->update( $steps, array( 'parts' => '{"object":{"~b":"YWJj"}}' ), array( 'run_id' => $run_id, 'seq' => $w1 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::assertSame( array( 'parts' ), AB_MCP_Sync_Run::step( $run_id, $w1 )['unread'] );
		self::assertSame( array( 'parts' ), self::step( self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) ), $w1 )['unread'], 'The status names it.' );
		$text = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id ) ), 'ab_mcp_sync_revert_unread' );
		self::assertStringContainsString( sprintf( 'Pass steps without %d', $w1 ), $text, 'The way.' );
		$preview = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id, 'steps' => array( $w2 ) ) );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertSame( array( $w2 ), array_column( $preview['preview']['steps'], 'seq' ), 'The other step that wrote is planned.' );
		// The plan of the run: a float marker, which no plan carries.
		$plan = (string) $wpdb->get_var( $wpdb->prepare( "SELECT plan FROM `$runs` WHERE run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::assertSame( 1, $wpdb->update( $runs, array( 'plan' => '{"objects":{"~f":"000000000000f03f"}}' ), array( 'run_id' => $run_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$was = self::untouched( $run_id, array( (int) $a['contact'], (int) $a['about'] ) );
		unset( $was['run'] );
		$text = self::refused( self::next( $run_id ), 'ab_mcp_sync_run_unread' );
		self::assertStringContainsString( 'its plan does not read back as it was written', $text );
		$now = self::untouched( $run_id, array( (int) $a['contact'], (int) $a['about'] ) );
		unset( $now['run'] );
		self::assertSame( $was, $now, 'Nothing of the steps, the bookings, the backups or the pages changed.' );
		self::assertSame( 1, $wpdb->update( $runs, array( 'plan' => $plan ), array( 'run_id' => $run_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'With its plan read back, the run goes on (the caches).' );
	}

	/** The seq of the write step of each page of a run, by the slug of its page here. */
	private static function write_seqs( int $run_id, array $a ): array {
		$by_live = array_flip( array_map( 'intval', $a ) );
		$out     = array();
		foreach ( AB_MCP_Sync_Run::get( $run_id )['plan']['objects'] as $item ) {
			if ( isset( $item['live_ref'], $by_live[ (int) $item['live_ref'] ] ) ) {
				$out[ $by_live[ (int) $item['live_ref'] ] ] = (int) $item['seq'];
			}
		}
		return $out;
	}

	public function testAJournalThatCannotBeSavedStopsTheStepBeforeAnyFurtherMutation(): void {
		// GitHub Codex round 9 on free#137 (P2): the checkpoints of the
		// journal within a write boundary (ahead of every meta and terms call,
		// after every part read back) dropped what update_step() answered; a
		// checkpoint that could not be saved (a database error, a journal past
		// MAX_PARTS) let the step write on with a journal behind it, which a
		// request ending in the next call left stale for the way back. Now
		// every checkpoint is checked, and the boundary stops before any
		// further mutation: nothing of the object is written without its
		// journal ahead (the step failed), and once the row is written the
		// object deviated (the decision of 07.11.2026: left whole, unclear).
		// A trigger of the database refuses one checkpoint of each step.
		self::pair();
		$a     = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.', 'team' => 'Our team.' ) );
		$b     = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.', 'team' => 'Our team.' ) );
		$steps = array();
		foreach ( array( 'contact', 'about' ) as $name ) {
			add_post_meta( (int) $a[ $name ], 'note', 'abc' );
			$steps[] = array( 'op' => 'add_meta', 'post' => (int) $b[ $name ], 'key' => 'note', 'value' => 'abc' );
		}
		self::b( $steps );
		$news_a  = wp_insert_term( 'News', 'category' );
		$other_a = wp_insert_term( 'Other', 'category' );
		self::assertIsArray( $news_a );
		self::assertIsArray( $other_a );
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$other_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ) ) ) ), true );
		self::assertIsInt( $story_a );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) ), 'as' => 'post' ) ) )['post'];
		self::paired( self::staged() );
		foreach ( array( 'contact' => 'Write to us on staging.', 'about' => 'About us, on staging.', 'team' => 'Our team, on staging.' ) as $name => $content ) {
			self::change_on_b( $b[ $name ], $name, $content );
		}
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'def' ), array( 'op' => 'update_meta', 'post' => (int) $b['about'], 'key' => 'note', 'value' => 'def' ) ) );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ) ) );
		$run_id = (int) self::begun( self::planned( array( 'contact', 'about', 'team', 'story' ) )['args'] )['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check.' );
		$seq = self::write_seqs( $run_id, $a + array( 'story' => (string) $story_a ) );
		self::assertCount( 4, $seq );
		// The checkpoints refused: of team the journal ahead of its first part
		// (every part still unread), of contact the one after its row was read
		// back, of about the one ahead of its meta call, of story the one ahead
		// of its terms call. Only while the step is begun: its end is saved.
		global $wpdb;
		$table = AB_MCP_Sync_Schema::table( 'steps' );
		$wpdb->query( 'DROP TRIGGER IF EXISTS ab_it_refuse_save' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( sprintf( "CREATE TRIGGER ab_it_refuse_save BEFORE UPDATE ON `%s` FOR EACH ROW BEGIN IF NEW.run_id = %d AND NEW.state = 'begun' AND ( ( NEW.seq = %d AND NEW.parts LIKE '%%\"part\":\"row\",\"post\":null,\"pre\":null%%' ) OR ( NEW.seq = %d AND NEW.parts LIKE '%%\"part\":\"row\",%%\"state\":\"read\"%%' ) OR ( NEW.seq = %d AND NEW.parts LIKE '%%\"part\":\"meta:note\",\"post\":null,\"pre\":\"%%' ) OR ( NEW.seq = %d AND NEW.parts LIKE '%%\"part\":\"terms:category\",\"post\":null,\"pre\":\"%%' ) ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'; END IF; END", $table, $run_id, $seq['team'], $seq['contact'], $seq['about'], $seq['story'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::assertSame( '', $wpdb->last_error, 'The trigger is there.' );
		$results = array();
		try {
			for ( $i = 0; $i < 4; $i++ ) {
				$answer = self::next( $run_id );
				self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
				$results[ (int) $answer['step']['seq'] ] = $answer['step'];
			}
		} finally {
			$wpdb->query( 'DROP TRIGGER IF EXISTS ab_it_refuse_save' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		// team: nothing written, the step failed with its reason.
		self::assertSame( 'failed', $results[ $seq['team'] ]['state'] );
		self::assertStringContainsString( 'could not be written ahead of its first part', $results[ $seq['team'] ]['result']['message'] );
		self::assertSame( 'Our team.', self::row_a( (int) $a['team'] )['post_content'], 'Nothing written.' );
		// contact: the row written and read back; then nothing more.
		self::assertSame( 'unclear', $results[ $seq['contact'] ]['state'] );
		self::assertStringContainsString( 'could not be saved after the call of the part row', $results[ $seq['contact'] ]['result']['message'] );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'] );
		self::assertSame( array( 'abc' ), self::meta_a( (int) $a['contact'], 'note' ), 'No meta call after the checkpoint failed.' );
		// about: the row written; the meta call not made.
		self::assertSame( 'unclear', $results[ $seq['about'] ]['state'] );
		self::assertStringContainsString( 'could not be saved before the call of the part meta:note', $results[ $seq['about'] ]['result']['message'] );
		self::assertSame( array( 'abc' ), self::meta_a( (int) $a['about'], 'note' ), 'The meta call was not made.' );
		$about = array_column( $results[ $seq['about'] ]['parts']['parts'], null, 'part' );
		self::assertStringContainsString( 'so the call was not made', $about['meta:note']['note'] );
		self::assertSame( 'left', $about['row']['state'], 'The row the step wrote stays, with its value before.' );
		// story: the row written; the terms call not made.
		self::assertSame( 'unclear', $results[ $seq['story'] ]['state'] );
		self::assertStringContainsString( 'could not be saved before the call of the part terms:category', $results[ $seq['story'] ]['result']['message'] );
		self::assertSame( array( 'news' ), array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) ), 'The terms call was not made.' );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		foreach ( array( (int) $a['contact'], (int) $a['about'], $story_a ) as $id ) {
			self::assertSame( 'unclear', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $id )['state'] );
		}
		self::probe_ok();
		self::assertSame( 'closed_findings', self::finish( $run_id )['state'] );
	}

	public function testAStepWhoseEndCannotBeRecordedSaysSoAndLeavesTheRunInterrupted(): void {
		// GitHub Codex round 9 on free#137 (P2), the end of a step: where its
		// record cannot be saved, the call says so (ab_mcp_sync_run_journal)
		// instead of reporting an end it did not record; the step stays begun
		// and the next call finds the run interrupted, with its two ways. The
		// same for a step of the way back. Here a trigger of the database
		// refuses the end of the write step, then of the step of the way back.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		$run_id = (int) self::begun( self::planned( array( 'contact', 'about' ) )['args'] )['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check.' );
		$seq   = self::write_seqs( $run_id, $a );
		$first = min( $seq );
		global $wpdb;
		$table  = AB_MCP_Sync_Schema::table( 'steps' );
		$refuse = static function ( string $when ) use ( $wpdb, $table, $run_id ): void {
			$wpdb->query( 'DROP TRIGGER IF EXISTS ab_it_refuse_end' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( sprintf( "CREATE TRIGGER ab_it_refuse_end BEFORE UPDATE ON `%s` FOR EACH ROW BEGIN IF NEW.run_id = %d AND OLD.state = 'begun' AND NEW.state <> 'begun' AND %s THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'; END IF; END", $table, $run_id, $when ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::assertSame( '', $wpdb->last_error, 'The trigger is there.' );
		};
		$refuse( sprintf( 'NEW.seq = %d', $first ) );
		try {
			$text = self::refused( self::next( $run_id ), 'ab_mcp_sync_run_journal' );
		} finally {
			$wpdb->query( 'DROP TRIGGER IF EXISTS ab_it_refuse_end' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		self::assertStringContainsString( 'its end could not be recorded', $text );
		self::assertStringContainsString( 'the next call finds the run interrupted', $text );
		self::assertSame( 'begun', AB_MCP_Sync_Run::step( $run_id, $first )['state'] );
		$status = self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) );
		self::assertSame( 'interrupted', $status['state'] );
		self::assertStringContainsString( 'begins its way back', $status['next'] );
		// Way (b): the way back begins; the parts the step read back as its own
		// (saved before its end) go back. Its end refused as well.
		$begun = self::revert( $run_id );
		self::assertSame( 'reverting', $begun['state'] );
		$back = (int) self::revert_step( $begun )['seq'];
		$refuse( sprintf( "NEW.op = 'revert' AND NEW.seq = %d", $back ) );
		try {
			$text = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $run_id ) ), 'ab_mcp_sync_run_journal' );
		} finally {
			$wpdb->query( 'DROP TRIGGER IF EXISTS ab_it_refuse_end' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		self::assertStringContainsString( 'its end could not be recorded', $text );
		self::assertSame( 'begun', AB_MCP_Sync_Run::step( $run_id, $back )['state'] );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'], 'The row went back before the end was refused.' );
		// The run interrupted on its way back, led on: the step stays unclear, the run closes, the site is free.
		self::refused( self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $run_id ) ), 'ab_mcp_sync_run_interrupted' );
		$led = self::revert( $run_id );
		self::assertSame( 'reverting', $led['state'] );
		$closed = self::revert_all( $run_id );
		self::assertSame( 'closed_findings', $closed['state'] );
		self::assertSame( 'unclear', AB_MCP_Sync_Run::step( $run_id, $back )['state'] );
		self::assertNull( AB_MCP_Sync_Run::open_run() );
	}

	public function testUnderTheLockOfItsCallAStepTheCallBeganIsItsOwnAndAStepAnEndedRequestLeftIsInterrupted(): void {
		// settle() under the lock of the call (the decision of 07.11.2026
		// evening, Variante A): no other request is in a step there, so a step
		// begun that this request did not begin belongs to a request that
		// ended (interrupted), and a step this call began is its own, running,
		// never interrupted. Before, under its own lock settle() took every
		// begun step as running, also one a request that ended had left. A
		// request that reached the engine with the run read before finds the
		// interruption under the lock and writes nothing; closing as checked
		// (way (a)) runs under the lock as well and settles the run itself.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		$run_id = (int) self::begun( self::planned( array( 'contact', 'about' ) )['args'] )['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check.' );
		$writes = array();
		foreach ( AB_MCP_Sync_Run::steps( $run_id ) as $step ) {
			if ( 'write' === $step['op'] ) {
				$writes[] = (int) $step['seq'];
			}
		}
		list( $mine, $other ) = $writes;
		// 1. A step this call begins under its lock is its own.
		$inside = AB_MCP_Sync_Run::under_lock(
			static function () use ( $run_id, $mine ) {
				self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, $mine, array( 'state' => 'begun' ), 'planned' ) );
				return AB_MCP_Sync_Run::settle( AB_MCP_Sync_Run::get( $run_id ) );
			},
			'The test'
		);
		self::assertIsArray( $inside );
		self::assertSame( 'open', $inside['state'], 'The step this call began is its own.' );
		self::assertSame( 'open', AB_MCP_Sync_Run::get( $run_id )['state'] );
		self::assertFalse( \AB_MCP_Change_Lock::held() );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, $mine, array( 'state' => 'planned' ), 'begun' ) );
		// 2. The same step begun by a request that ended (begun here without
		// the lock, as that request did before it ended; the call of step 1 is
		// over, its own steps forgotten): a request that read the run before
		// reaches the engine, finds the run interrupted under the lock, and
		// begins no other step.
		$read = AB_MCP_Sync_Run::get( $run_id );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, $mine, array( 'state' => 'begun' ), 'planned' ) );
		$text = self::refused( AB_MCP_Sync_Engine::next( $read, null ), 'ab_mcp_sync_run_interrupted' );
		self::assertStringContainsString( 'begins its way back', $text, 'The way that goes for an interrupted forward run.' );
		self::assertSame( 'interrupted', AB_MCP_Sync_Run::get( $run_id )['state'] );
		self::assertSame( 'planned', AB_MCP_Sync_Run::step( $run_id, $other )['state'], 'No other step begun.' );
		self::assertSame( 'About us.', self::row_a( (int) $a['about'] )['post_content'], 'Nothing written.' );
		// 3. Closing as checked (way (a)) under the lock: refused as busy while
		// another request holds it; then it settles the run itself (put back
		// to open by the test, the step still begun) and closes it.
		self::assertTrue( AB_MCP_Sync_Run::set_state( $run_id, 'interrupted', 'open' ) );
		update_option( \AB_MCP_Change_Lock::OPTION, time() );
		$was = self::untouched( $run_id, array( (int) $a['contact'] ) );
		self::refused( AB_MCP_Sync_Run::close_checked( $run_id, self::admin(), true ), 'ab_mcp_sync_run_busy' );
		self::assertSame( $was, self::untouched( $run_id, array( (int) $a['contact'] ) ) );
		delete_option( \AB_MCP_Change_Lock::OPTION );
		$closed = AB_MCP_Sync_Run::close_checked( $run_id, self::admin(), true );
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
		self::assertSame( 'closed_checked', $closed['state'] );
		self::assertSame( 'unclear', AB_MCP_Sync_Run::step( $run_id, $mine )['state'] );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'open_one is free.' );
	}

	public function testAWriteStepIsNotRunUnderATimeLimitThatDoesNotCarryItWhileTheCheckGoesOnWithASmallerWork(): void {
		// GitHub Codex round 3 on free#137 (P2): on a host with a short PHP
		// time limit the loops of the check worked 50 s before they saved
		// their cursor, and a write step could end halfway. Now every call
		// derives its work from the limit it has after the raise
		// (AB_MCP_Sync_Resources::time_for_call()): the check goes on with the
		// smaller work and saves its cursor in time; a write step, and a step
		// of the way back, is not run under a limit below MIN_TIME, with the
		// way, and stays planned.
		self::pair();
		$a = self::pages_on_a( array( 'about' => 'About us.', 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'about' => 'About us.', 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'about', 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$run_id  = (int) $run['run_id'];
		// A limit of 30 s that set_time_limit() does not raise: the work of
		// a call is 15 s; with 20 s passing per object the check saves its
		// cursor after the first one (before, it worked 50 s).
		AB_MCP_Sync_Resources::$time = static fn(): array => array( 'before' => 30, 'after' => 30 );
		AB_MCP_Sync_Engine::$clock   = static function (): float {
			static $t = 1000.0;
			$t += 20;
			return $t;
		};
		$one = self::next( $run_id );
		self::assertIsArray( $one, is_wp_error( $one ) ? $one->get_error_message() : '' );
		self::assertSame( 'planned', $one['step']['state'] );
		self::assertSame( 1, $one['step']['result']['cursor'], 'One object within the work of 15 s.' );
		self::assertLessThanOrEqual( 25, $one['remaining']['seconds'], 'The work and the reserve of this call, not 60 s.' );
		AB_MCP_Sync_Engine::$clock = null;
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check goes on and passes.' );
		// A limit of 20 s, not raised: a write step is not run, with the way;
		// it stays planned, nothing is written.
		AB_MCP_Sync_Resources::$time = static fn(): array => array( 'before' => 20, 'after' => 20 );
		$refused                     = self::next( $run_id );
		$message                     = self::refused( $refused, 'ab_mcp_sync_run_time' );
		self::assertStringContainsString( 'the PHP time limit of this request is 20 s', $message );
		self::assertStringContainsString( 'max_execution_time to at least 30 s', $message, 'The way.' );
		self::assertSame( 'About us.', self::row_a( (int) $a['about'] )['post_content'], 'Nothing written.' );
		self::assertSame( 'open', AB_MCP_Sync_Run::get( $run_id )['state'] );
		self::assertSame( 1, AB_MCP_Sync_Run::shown( AB_MCP_Sync_Run::get( $run_id ) )['counts']['done'], 'The check only; the write step stays planned.' );
		// Raised to 120 s: the write runs; 30 s not raised carries a write as well.
		AB_MCP_Sync_Resources::$time = static fn(): array => array( 'before' => 20, 'after' => 120 );
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		self::assertSame( 'About us, on staging.', self::row_a( (int) $a['about'] )['post_content'] );
		AB_MCP_Sync_Resources::$time = static fn(): array => array( 'before' => 30, 'after' => 30 );
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		AB_MCP_Sync_Resources::$time = null;
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( $run_id )['state'] );
		// A step of the way back is a write as well (the way back of the closed
		// run is a run of its own).
		$begun = self::revert( $run_id );
		AB_MCP_Sync_Resources::$time = static fn(): array => array( 'before' => 20, 'after' => 20 );
		self::refused( self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => (int) $begun['run_id'] ) ), 'ab_mcp_sync_run_time' );
		self::assertSame( 'About us, on staging.', self::row_a( (int) $a['about'] )['post_content'], 'Nothing put back.' );
		AB_MCP_Sync_Resources::$time = null;
		self::assertSame( 'closed_reverted', self::revert_all( (int) $begun['run_id'] )['state'] );
		self::assertSame( 'About us.', self::row_a( (int) $a['about'] )['post_content'] );
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
		// A part left names its value before, from the backup (the decision
		// of 07.11.2026): the note, and the columns of the row that differ.
		$values = array_column( $step['result']['parts'], 'value_before', 'part' );
		self::assertSame( '"abc"', $values['meta:note'] );
		self::assertStringContainsString( 'post_title: "Contact"', $values['row'] );
		self::assertStringContainsString( 'post_content: 12 bytes (', $values['row'] );
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

	public function testADeviatingMetaPartLeavesTheObjectWholeWithTheOldValuesInTheAnswer(): void {
		// The one boundary (the decision of 07.11.2026, «A mit altem Wert»): a
		// hook of this site changes a value as it is written, so the
		// part reads back other than its target: the object deviated. Nothing
		// of it is put back: the row written before stays with the staging
		// content, the meta with what the sanitizer made of it, each left with
		// its value before in the journal of the answer; the step and the pair
		// are unclear. Before, the step turned and put both back (B8).
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
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		// A hook of this site that changes the value right after it is written
		// (not a sanitizer the check ahead knows: since branch 9, a
		// sanitize_post_meta_note filter refuses the step before its first
		// part, decision 17).
		$hook = static function ( $meta_id, $object_id, $meta_key ) use ( $a ) {
			global $wpdb;
			if ( 'note' === $meta_key && (int) $a['contact'] === (int) $object_id ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'X' ), array( 'meta_id' => (int) $meta_id ) );
				wp_cache_delete( (int) $object_id, 'post_meta' );
			}
		};
		add_action( 'updated_post_meta', $hook, 10, 3 );
		add_action( 'added_post_meta', $hook, 10, 3 );
		$write = self::next( (int) $run['run_id'] );
		remove_action( 'updated_post_meta', $hook, 10 );
		remove_action( 'added_post_meta', $hook, 10 );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['meta:note']['state'] );
		self::assertSame( 'left', $parts['row']['state'], 'The row written before stays as well: nothing of a deviated object is put back.' );
		self::assertTrue( $parts['row']['deviated'] );
		self::assertTrue( $parts['meta:note']['deviated'] );
		self::assertSame( 'meta:note', $write['step']['parts']['object']['deviated']['part'] );
		self::assertStringContainsString( 'reads back other than its target', $write['step']['parts']['object']['deviated']['why'] );
		self::assertSame( '"abc"', $parts['meta:note']['value_before'], 'The value before, from the backup.' );
		self::assertStringContainsString( 'post_content: 12 bytes (', $parts['row']['value_before'] );
		self::assertStringContainsString( 'deviated within the step', $write['step']['result']['message'] );
		self::assertStringContainsString( 'Nothing of it is put back', $write['step']['result']['message'] );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'], 'Left as written.' );
		self::assertSame( array( 'X' ), self::meta_a( (int) $a['contact'], 'note' ), 'Left as the hook made it.' );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $a['contact'] );
		self::assertSame( 'unclear', $pair['state'] );
		self::assertSame( 'proposal', $pair['base_origin'], 'The pair keeps its bases of before: nothing was taken over.' );
	}

	public function testARowAFilterChangedLeavesTheObjectWithTheTemplateWrittenWithIt(): void {
		// The core stores _wp_page_template inside wp_insert_post(), with the
		// row (page_template, B7). A filter of this site that only
		// wp_insert_post_data applies changes the title as the row is written:
		// the row reads back other than its target, the object deviated. The
		// row stays as the filter wrote it, and so does the template the core
		// wrote with the row (with_row()): both left with their value before;
		// the step and the pair unclear. Before, the step turned and put both
		// back (Codex round 2, N2).
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
		self::assertSame( array(), self::meta_a( (int) $a['contact'], '_wp_page_template' ), 'No template here before.' );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		// The filter hits the one write of the step and takes itself out, as a
		// filter of a plugin that reacts to a saved post once.
		$once = static function ( array $data ) use ( &$once ): array {
			remove_filter( 'wp_insert_post_data', $once );
			$data['post_title'] = 'Changed by a filter';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $once );
		$write = self::next( (int) $run['run_id'] );
		remove_all_filters( 'wp_insert_post_data' );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( array( 'row', 'meta:_wp_page_template' ), array_keys( $parts ) );
		self::assertSame( 'left', $parts['row']['state'] );
		self::assertSame( 'left', $parts['meta:_wp_page_template']['state'], 'Written with the row by the core, left with the row.' );
		self::assertStringContainsString( 'Written with the row', (string) $parts['meta:_wp_page_template']['note'], 'The journal says the core wrote it with the row.' );
		self::assertSame( 'row', $write['step']['parts']['object']['deviated']['part'] );
		self::assertStringContainsString( 'post_title: "Contact"', $parts['row']['value_before'] );
		self::assertSame( 'none (no row of the key)', $parts['meta:_wp_page_template']['value_before'] );
		self::assertSame( 'Changed by a filter', self::row_a( (int) $a['contact'] )['post_title'], 'Left as the filter wrote it.' );
		self::assertSame( array( $template ), self::meta_a( (int) $a['contact'], '_wp_page_template' ), 'The template the core wrote with the row stays.' );
		self::assertStringContainsString( 'deviated within the step', $write['step']['result']['message'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $a['contact'] );
		self::assertSame( 'unclear', $pair['state'] );
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

	public function testADeviatingTermPartLeavesTheObjectWhole(): void {
		// Terms are written on a way the lock does not refuse: another writer
		// adds a category in the moment of the step's own terms call, so the
		// part reads back other than its target: the object deviated. The row
		// written before stays with the staging content, the terms with the
		// foreign category, each left with its value before; the way back of
		// the closed run leaves the object whole (decisions 17 and 23; A2, now
		// within the one boundary of 07.11.2026).
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
		// Another writer adds a category by SQL in the moment the step's own
		// terms call sets them (the second time the action fires for the
		// category: the first is the row call passing the categories back,
		// which is not the step's terms call).
		$foreign = wp_insert_term( 'Foreign', 'category' );
		$ttid    = (int) $foreign['term_taxonomy_id'];
		$fired   = 0;
		add_action(
			'set_object_terms',
			static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $story_a, $ttid, &$fired ) {
				global $wpdb;
				if ( 'category' === $taxonomy && (int) $object_id === $story_a && 2 === ++$fired ) {
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
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['terms:category']['state'], 'Written, deviating: left.' );
		self::assertStringContainsString( 'Written, deviating', (string) $parts['terms:category']['note'] );
		self::assertSame( 'left', $parts['row']['state'], 'The row written before stays as well.' );
		self::assertSame( '"news"', $parts['terms:category']['value_before'], 'The slug of the category before, as the backup holds it.' );
		self::assertSame( 'terms:category', $write['step']['parts']['object']['deviated']['part'] );
		self::assertStringContainsString( 'deviated within the step', $write['step']['result']['message'] );
		self::probe_ok();
		self::assertSame( 'closed_findings', self::finish( (int) $run['run_id'] )['state'] );
		$slugs = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		self::assertEqualsCanonicalizing( array( 'news', 'foreign' ), $slugs() );
		// The way back: the whole object is left, the row as well.
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], null, 'part' );
		self::assertSame( 'left', $outcome['terms:category']['state'], 'Not provably the run\'s own.' );
		self::assertSame( 'left', $outcome['row']['state'], 'The row of a deviated object is never put back by itself.' );
		self::assertStringContainsString( 'deviated within its step', (string) $outcome['row']['note'] );
		self::assertStringContainsString( 'post_content: 8 bytes (', $outcome['row']['value_before'] );
		self::assertEqualsCanonicalizing( array( 'news', 'foreign' ), $slugs(), 'The foreign category stays.' );
		self::assertSame( 'A story, on staging.', self::row_a( $story_a )['post_content'], 'Left as written.' );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $story_a );
		self::assertSame( 'unclear', $pair['state'] );
	}

	public function testACategoryAnotherHandSetToTheTargetIsNotTheStepsOwnAndIsLeft(): void {
		// The ownership rule (Codex round 3): a post in News here, planned to
		// Other. While the row is written, another hand sets the category to
		// Other on the way the lock does not refuse. The category reads as the
		// target now, yet the step has not written it (an update passes the
		// categories back unchanged): another hand's, so the object deviated
		// right there (the one boundary of 07.11.2026): the step makes no
		// further call, nothing of it is put back, the row stays with the
		// staging content, the category as the other hand set it, each left
		// with its value before; the step is unclear, and the way back of the
		// closed run leaves the object whole as well. Without the mark of
		// another hand the step's own terms call would write the target over
		// the other hand's change and the step would be done.
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
		// The other hand sets the category once, as the core passes the
		// categories of the post back with the row (set_object_terms fires
		// inside).
		$other_id = (int) $other_a['term_id'];
		$hand     = static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $story_a, $other_id, &$hand ) {
			if ( 'category' === $taxonomy && (int) $object_id === $story_a ) {
				remove_action( 'set_object_terms', $hand, 10 );
				wp_set_object_terms( $story_a, array( $other_id ), 'category', false );
			}
		};
		add_action( 'set_object_terms', $hand, 10, 4 );
		$write = self::next( (int) $run['run_id'] );
		remove_all_actions( 'set_object_terms' );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['row']['state'], 'The row, written as its target, stays: nothing of a deviated object is put back.' );
		self::assertSame( 'left', $parts['terms:category']['state'], 'The category another hand set is not the step\'s own.' );
		self::assertFalse( $parts['terms:category']['own'] );
		self::assertNull( $parts['terms:category']['post'] ?? null, 'The step made no terms call: it ended with the row.' );
		self::assertStringContainsString( 'another hand', (string) $parts['terms:category']['note'] );
		self::assertSame( 'terms:category', $write['step']['parts']['object']['deviated']['part'] );
		self::assertStringContainsString( 'another hand changed the part terms:category', $write['step']['parts']['object']['deviated']['why'] );
		self::assertStringContainsString( 'though the part reads as its target now', $write['step']['parts']['object']['deviated']['why'] );
		self::assertSame( '"news"', $parts['terms:category']['value_before'] );
		self::assertStringContainsString( 'post_content: 8 bytes (', $parts['row']['value_before'] );
		self::assertSame( array( 'other' ), $slugs(), 'The category stays as the other hand set it, not put back to News.' );
		self::assertSame( 'A story, on staging.', self::row_a( $story_a )['post_content'], 'The row stays as written.' );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $story_a );
		self::assertSame( 'unclear', $pair['state'] );
		self::probe_ok();
		self::assertSame( 'closed_findings', self::finish( (int) $run['run_id'] )['state'] );
		// The way back of the closed run: the object deviated, so it is left
		// whole, the row as well.
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['terms:category'], 'Not the run\'s own.' );
		self::assertSame( 'left', $outcome['row'] );
		self::assertSame( array( 'other' ), $slugs(), 'The way back leaves the other hand\'s category.' );
		self::assertSame( 'A story, on staging.', self::row_a( $story_a )['post_content'] );
		self::assertNotSame( $before, self::row_a( $story_a ) );
	}

	public function testTermsAHookChangedWithTheRowOtherThanTheCoreWouldAreAnotherHandsEvenOnAPostWithoutACategory(): void {
		// A post without a category here (set so past the editor), paired so;
		// staging gives it Other. wp_update_post() gives a post without a
		// category the default one with the row (with_row() names it), but a
		// hook of this site reacting to the row sets Third instead: the terms
		// read back with the row other than what the row was to write, so they
		// are another hand's (foreign), not the step's own, and the object
		// deviated right there: the step makes no terms call, the row stays
		// with the staging content, the terms with the hook's category, each
		// left with its value before. Were every change with the row the step's
		// own where the row was to write the part, the step's own terms call
		// would write Other over the hook's category and the step would be done.
		self::pair();
		$other_a = wp_insert_term( 'Other', 'category' );
		$third_a = wp_insert_term( 'Third', 'category' );
		self::assertIsArray( $other_a, is_wp_error( $other_a ) ? $other_a->get_error_message() : '' );
		self::assertIsArray( $third_a, is_wp_error( $third_a ) ? $third_a->get_error_message() : '' );
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
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$third_id = (int) $third_a['term_id'];
		$hand     = static function ( $post_id, $post, $update ) use ( $story_a, $third_id, &$hand ) {
			if ( $update && (int) $post_id === $story_a ) {
				remove_action( 'wp_insert_post', $hand, 10 );
				wp_set_object_terms( $story_a, array( $third_id ), 'category', false );
			}
		};
		add_action( 'wp_insert_post', $hand, 10, 3 );
		$write = self::next( (int) $run['run_id'] );
		remove_action( 'wp_insert_post', $hand, 10 );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['terms:category']['state'], 'Read back with the row other than what the row was to write: another hand\'s.' );
		self::assertFalse( $parts['terms:category']['own'] );
		self::assertStringContainsString( 'other than what the row was to write', (string) $parts['terms:category']['note'] );
		self::assertSame( 'none (no term of the taxonomy)', $parts['terms:category']['value_before'] );
		self::assertSame( 'left', $parts['row']['state'] );
		self::assertSame( 'terms:category', $write['step']['parts']['object']['deviated']['part'] );
		self::assertSame( array( 'third' ), $slugs(), 'The hook\'s category stands; Other is not written over it.' );
		self::assertSame( 'A story, on staging.', self::row_a( $story_a )['post_content'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $story_a );
		self::assertSame( 'unclear', $pair['state'] );
	}

	public function testAPartTheStepNeverReachedIsNotTakenBackOnTheWayBack(): void {
		// Codex round 4: a post in News with a note, planned to Other and a new
		// note. The row is written and read back, then the meta call is refused:
		// the object deviated, the step ends before it reaches the terms. The
		// terms were never written by the step (not_reached, settled()); after
		// the step another hand sets the category to Other. The way back of the
		// closed run leaves the category as the other hand set it, and the row
		// of the deviated object as well.
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
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$slugs  = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		$refuse = static fn( $check, $object_id, $meta_key ) => ( (int) $object_id === $story_a && 'note' === $meta_key ) ? false : $check;
		add_filter( 'update_post_metadata', $refuse, 10, 3 );
		add_filter( 'add_post_metadata', $refuse, 10, 3 );
		add_filter( 'delete_post_metadata', $refuse, 10, 3 );
		$write = self::next( (int) $run['run_id'] );
		remove_filter( 'update_post_metadata', $refuse, 10 );
		remove_filter( 'add_post_metadata', $refuse, 10 );
		remove_filter( 'delete_post_metadata', $refuse, 10 );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['row']['state'], 'Written as its target, left: the object deviated.' );
		self::assertSame( 'failed', $parts['meta:note']['state'], 'The refused call changed nothing: the note is as the backup.' );
		self::assertArrayNotHasKey( 'value_before', $parts['meta:note'], 'A part as the backup carries no value before.' );
		self::assertSame( 'not_reached', $parts['terms:category']['state'], 'The step ended before it reached the terms.' );
		self::assertSame( 'meta:note', $write['step']['parts']['object']['deviated']['part'] );
		self::assertSame( array( 'news' ), $slugs() );
		self::assertSame( array( 'abc' ), self::meta_a( $story_a, 'note' ) );
		self::probe_ok();
		self::finish( (int) $run['run_id'] );
		// Another hand sets the category after the step.
		wp_set_object_terms( $story_a, array( (int) $other_a['term_id'] ), 'category', false );
		self::assertSame( array( 'other' ), $slugs() );
		// The way back of the closed run: the row (deviated object) is left,
		// the note is as the backup, the category (never written by the step)
		// is left as the other hand set it.
		$begun   = self::revert( (int) $run['run_id'] );
		$back    = self::revert_all( (int) $begun['run_id'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], null, 'part' );
		self::assertSame( 'left', $outcome['terms:category']['state'], 'Never written by the step, so not the run\'s own.' );
		self::assertSame( '"news"', $outcome['terms:category']['value_before'] );
		self::assertSame( 'unchanged', $outcome['meta:note']['state'] );
		self::assertSame( 'left', $outcome['row']['state'] );
		self::assertSame( array( 'other' ), $slugs(), 'The way back leaves the other hand\'s category.' );
		self::assertSame( 'A story, on staging.', self::row_a( $story_a )['post_content'], 'The row of the deviated object stays.' );
	}

	public function testARequestThatEndsInsideAMetaCallLeavesThePartForTheWayBackOfTheSameRun(): void {
		// Codex round 7, F1: the row is written and read back, then the meta
		// call writes the note and the request ends before its read back is
		// kept. The journal was written ahead of the call, so the part stands
		// without a read back (not with the row's read back of the old note),
		// for the display of the state reached. The way back of the interrupted
		// run puts the row back (read back, the step's own) and leaves the note
		// with its value before: never read back, a hash equal to the target
		// proves no ownership (decision 18 (b); the decision of 07.11.2026).
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
		$run_id  = (int) $run['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		try {
			AB_MCP_IT_Sync_Steps::run( 'a', array( array( 'op' => 'push', 'args' => array( 'action' => 'next', 'run_id' => $run_id ), 'exit_at' => 'updated_post_meta', 'exit_key' => 'note' ) ) );
			self::fail( 'The process exits inside the meta call.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'gave no JSON', $e->getMessage() );
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Backup::forget();
		wp_cache_flush();
		self::assertSame( array( 'def' ), self::meta_a( (int) $a['contact'], 'note' ), 'The meta call wrote the note before the exit.' );
		$parts = array_column( AB_MCP_Sync_Run::step( $run_id, 2 )['parts']['parts'], null, 'part' );
		self::assertNull( $parts['meta:note']['post'], 'Written ahead of the call: no read back of the note.' );
		self::assertSame( 'interrupted', self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) )['state'] );
		$begun = self::revert( $run_id );
		self::assertTrue( $begun['same_run'] );
		$back = self::revert_all( $run_id );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$outcome = array_column( self::revert_step( $back )['result']['parts'], null, 'part' );
		self::assertSame( 'restored', $outcome['row']['state'], 'Read back by the step and still its own: back.' );
		self::assertSame( 'left', $outcome['meta:note']['state'], 'Never read back: left, though it holds the target.' );
		self::assertStringContainsString( 'never read it back', $outcome['meta:note']['note'] );
		self::assertSame( '"abc"', $outcome['meta:note']['value_before'] );
		self::assertSame( array( 'def' ), self::meta_a( (int) $a['contact'], 'note' ), 'The note stays as the call wrote it.' );
		self::assertSame( 'Write to us.', self::row_a( (int) $a['contact'] )['post_content'], 'The row went back.' );
		self::assertSame( 'unclear', AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $a['contact'] )['state'] );
	}

	public function testTheDefaultCategoryOfTheRowGoingBackDoesNotTakeBackTermsAnotherHandChanged(): void {
		// Codex round 7, F2: a post in News, taken over by the step to the
		// default category of this site (the one wp_update_post() gives a post
		// without one). Before the way back another hand takes every category
		// off. Putting the row back, wp_update_post() gives the post the
		// default category again: exactly what the step read back (own_after),
		// yet not the step's own: what the row put back changed besides the
		// row is another hand's (the decision of 07.11.2026, no ownership
		// passes). The terms are left with their value before (not put back to
		// News over the other hand's removal), the step of the way back is
		// unclear. Before, the default category made the terms the step's own
		// again and News was written over the other hand's change.
		self::pair();
		$news_a = wp_insert_term( 'News', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		$news_b    = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$default_a = (int) get_option( 'default_category' );
		$default_b = (int) AB_MCP_IT_Sites::option_row( 'b', 'default_category' )['option_value'];
		self::assertGreaterThan( 0, $default_a );
		self::assertGreaterThan( 0, $default_b );
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ) ) ) ), true );
		self::assertIsInt( $story_a );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) ), 'as' => 'post' ) ) )['post'];
		self::paired( self::staged() );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $default_b ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		$slugs   = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		$default = get_term( $default_a, 'category' )->slug;
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( $default ), $slugs(), 'Taken over to the default category.' );
		wp_set_object_terms( $story_a, array(), 'category', false );
		self::assertSame( array(), $slugs(), 'Another hand took every category off.' );
		$begun   = self::revert( (int) $run['run_id'] );
		$back    = self::revert_all( (int) $begun['run_id'] );
		$step    = self::step( $back, 1 );
		$outcome = array_column( $step['result']['parts'], null, 'part' );
		self::assertSame( 'restored', $outcome['row']['state'] );
		self::assertSame( 'left', $outcome['terms:category']['state'], json_encode( $step['result'] ) );
		self::assertStringContainsString( 'putting the part row back changed the part terms:category besides it', (string) $outcome['terms:category']['note'], 'The halt of the way back for the object (Codex round 16) names the cause.' );
		self::assertSame( '"news"', $outcome['terms:category']['value_before'] );
		self::assertSame( array( $default ), $slugs(), 'The default category the row gave stands; News is not written over the other hand\'s removal.' );
		self::assertSame( 'unclear', $step['state'] );
		self::assertSame( 'closed_findings', $back['state'] );
	}

	public function testTagsAHookSetsAsTheCategoryGoesBackAreLeftNotTakenBack(): void {
		// Codex round 12: a post in News with tag Old, taken over to Other with
		// tag New. Another hand then sets the tag Third. On the way back, a
		// hook that sets the tag New as the category goes back to News fires;
		// the tags it set are another hand's (not the step's own, though they
		// hash as what the step wrote): they are left, not put back to Old.
		self::pair();
		$news_a  = wp_insert_term( 'News', 'category' );
		$other_a = wp_insert_term( 'Other', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		self::assertIsArray( $other_a, is_wp_error( $other_a ) ? $other_a->get_error_message() : '' );
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$other_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		foreach ( array( 'old', 'new', 'third' ) as $tag ) {
			self::assertIsArray( wp_insert_term( $tag, 'post_tag' ) );
			self::b( array( array( 'op' => 'insert_term', 'name' => $tag, 'taxonomy' => 'post_tag', 'as' => 'term' ) ) );
		}
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ), 'tags_input' => array( 'old' ) ) ) ), true );
		self::assertIsInt( $story_a );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ), 'tags_input' => array( 'old' ) ) ), 'as' => 'post' ) ) )['post'];
		self::paired( self::staged() );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ), 'tags_input' => array( 'new' ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		$tags    = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'post_tag' ) ) );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( 'new' ), $tags() );
		wp_set_object_terms( $story_a, array( 'third' ), 'post_tag', false );
		$news_id = (int) $news_a['term_id'];
		$hook    = static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $story_a, $news_id, &$hook ) {
			if ( 'category' === $taxonomy && (int) $object_id === $story_a && has_term( $news_id, 'category', $story_a ) ) {
				remove_action( 'set_object_terms', $hook, 10 );
				wp_set_object_terms( $story_a, array( 'new' ), 'post_tag', false );
			}
		};
		add_action( 'set_object_terms', $hook, 10, 4 );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		remove_all_actions( 'set_object_terms' );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['terms:post_tag'], json_encode( self::step( $back, 1 )['result'] ) );
		self::assertSame( array( 'new' ), $tags(), 'The tags the hook set stay; not put back to Old.' );
	}

	public function testAHookThatChangesAWrittenPartAsALaterPartIsWrittenDeviatesTheObject(): void {
		// Codex round 13: a hook of this site sets the plain meta label from
		// the category and the tag whenever their terms are set. The step
		// writes the label first (other/new, its target), then the category:
		// the hook makes the label other/old, then the tag: other/new again,
		// as the target. The change of the label in between is another
		// hand's: the object deviated, and nothing of it goes back by itself.
		self::pair();
		$news_a  = wp_insert_term( 'News', 'category' );
		$other_a = wp_insert_term( 'Other', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		self::assertIsArray( $other_a, is_wp_error( $other_a ) ? $other_a->get_error_message() : '' );
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$other_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		foreach ( array( 'old', 'new' ) as $tag ) {
			self::assertIsArray( wp_insert_term( $tag, 'post_tag' ) );
			self::b( array( array( 'op' => 'insert_term', 'name' => $tag, 'taxonomy' => 'post_tag', 'as' => 'term' ) ) );
		}
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ), 'tags_input' => array( 'old' ) ) ) ), true );
		self::assertIsInt( $story_a );
		add_post_meta( $story_a, 'label', 'news/old' );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ), 'tags_input' => array( 'old' ) ) ), 'as' => 'post' ) ) )['post'];
		self::b( array( array( 'op' => 'add_meta', 'post' => $story_b, 'key' => 'label', 'value' => 'news/old' ) ) );
		self::paired( self::staged() );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ), 'tags_input' => array( 'new' ) ) );
		self::b( array( array( 'op' => 'update_meta', 'post' => $story_b, 'key' => 'label', 'value' => 'other/new' ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$label = static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $story_a ) {
			if ( (int) $object_id !== $story_a || ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
				return;
			}
			$cat = wp_get_object_terms( $story_a, 'category', array( 'fields' => 'slugs' ) );
			$tag = wp_get_object_terms( $story_a, 'post_tag', array( 'fields' => 'slugs' ) );
			update_post_meta( $story_a, 'label', ( $cat[0] ?? '' ) . '/' . ( $tag[0] ?? '' ) );
		};
		add_action( 'set_object_terms', $label, 10, 4 );
		$write = self::next( (int) $run['run_id'] );
		remove_all_actions( 'set_object_terms' );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		self::assertSame( 'terms:category', $write['step']['parts']['object']['deviated']['part'], 'The call that changed another part.' );
		self::assertStringContainsString( 'meta:label', $write['step']['parts']['object']['deviated']['why'] );
		self::probe_ok();
		self::finish( (int) $run['run_id'] );
		$begun   = self::revert( (int) $run['run_id'] );
		$back    = self::revert_all( (int) $begun['run_id'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['meta:label'], json_encode( self::step( $back, 1 )['result'] ) );
		self::assertNotSame( array( 'news/old' ), self::meta_a( $story_a, 'label' ), 'The label the hook wrote is not put back.' );
	}

	public function testAHookThatChangesAPartStillToWriteAndBackAgainDeviatesTheObject(): void {
		// Codex round 14: a hook of this site keeps total = price × quantity.
		// The step writes price (total becomes 40 by the hook), then quantity
		// (total 20 again, as the backup and the target), then total. The
		// change of total in between is another hand's, though it reads as
		// before by its own call: the object deviated, nothing goes back.
		self::pair();
		$a = self::pages_on_a( array( 'order' => 'An order.' ) );
		$b = self::pages_on_b( array( 'order' => 'An order.' ) );
		foreach ( array( 'price' => '10', 'quantity' => '2', 'total' => '20' ) as $key => $value ) {
			add_post_meta( (int) $a['order'], $key, $value );
			self::b( array( array( 'op' => 'add_meta', 'post' => (int) $b['order'], 'key' => $key, 'value' => $value ) ) );
		}
		self::paired( self::staged() );
		self::change_on_b( $b['order'], 'order', 'An order, on staging.' );
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['order'], 'key' => 'price', 'value' => '20' ) ) );
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['order'], 'key' => 'quantity', 'value' => '1' ) ) );
		$planned = self::planned( array( 'order' ) );
		$run     = self::begun( $planned['args'] );
		$order   = (int) $a['order'];
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$keep = static function ( $meta_id, $object_id, $meta_key ) use ( $order ) {
			if ( (int) $object_id !== $order || ! in_array( $meta_key, array( 'price', 'quantity' ), true ) ) {
				return;
			}
			update_post_meta( $order, 'total', (string) ( (int) get_post_meta( $order, 'price', true ) * (int) get_post_meta( $order, 'quantity', true ) ) );
		};
		add_action( 'updated_post_meta', $keep, 10, 3 );
		$write = self::next( (int) $run['run_id'] );
		remove_all_actions( 'updated_post_meta' );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		self::assertSame( 'meta:price', $write['step']['parts']['object']['deviated']['part'], 'The call that changed another part.' );
		self::assertStringContainsString( 'meta:total', $write['step']['parts']['object']['deviated']['why'] );
		self::probe_ok();
		self::finish( (int) $run['run_id'] );
		$begun   = self::revert( (int) $run['run_id'] );
		$back    = self::revert_all( (int) $begun['run_id'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertNotSame( 'restored', $outcome['meta:price'] ?? 'absent', json_encode( self::step( $back, 1 )['result'] ) );
		self::assertSame( array( '20' ), self::meta_a( $order, 'price' ), 'Nothing of the object that deviated goes back.' );
	}

	public function testAHookThatAddsAndTakesAwayAKeyThePlanDoesNotNameDeviatesTheObject(): void {
		// Codex round 15: a hook of this site sets shortage=yes while reserved
		// exceeds available and takes the key away otherwise. Writing
		// available=1 adds shortage, writing reserved=0 takes it away again:
		// the end is the target, yet a call changed a key the plan does not
		// name. The object deviated; nothing goes back by itself.
		self::pair();
		$a = self::pages_on_a( array( 'stock' => 'A stock.' ) );
		$b = self::pages_on_b( array( 'stock' => 'A stock.' ) );
		foreach ( array( 'available' => '10', 'reserved' => '2' ) as $key => $value ) {
			add_post_meta( (int) $a['stock'], $key, $value );
			self::b( array( array( 'op' => 'add_meta', 'post' => (int) $b['stock'], 'key' => $key, 'value' => $value ) ) );
		}
		self::paired( self::staged() );
		self::change_on_b( $b['stock'], 'stock', 'A stock, on staging.' );
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['stock'], 'key' => 'available', 'value' => '1' ) ) );
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['stock'], 'key' => 'reserved', 'value' => '0' ) ) );
		$planned = self::planned( array( 'stock' ) );
		$run     = self::begun( $planned['args'] );
		$stock   = (int) $a['stock'];
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$keep = static function ( $meta_id, $object_id, $meta_key ) use ( $stock ) {
			if ( (int) $object_id !== $stock || ! in_array( $meta_key, array( 'available', 'reserved' ), true ) ) {
				return;
			}
			if ( (int) get_post_meta( $stock, 'reserved', true ) > (int) get_post_meta( $stock, 'available', true ) ) {
				update_post_meta( $stock, 'shortage', 'yes' );
			} else {
				delete_post_meta( $stock, 'shortage' );
			}
		};
		add_action( 'updated_post_meta', $keep, 10, 3 );
		$write = self::next( (int) $run['run_id'] );
		remove_all_actions( 'updated_post_meta' );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		self::assertStringContainsString( 'meta:shortage', $write['step']['parts']['object']['deviated']['why'] );
		self::probe_ok();
		self::finish( (int) $run['run_id'] );
		$begun   = self::revert( (int) $run['run_id'] );
		$back    = self::revert_all( (int) $begun['run_id'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertNotSame( 'restored', $outcome['meta:available'] ?? 'absent', json_encode( self::step( $back, 1 )['result'] ) );
		self::assertSame( array( '1' ), self::meta_a( $stock, 'available' ), 'Nothing of the object that deviated goes back.' );
	}

	public function testAKeyAHookAddsAsACategoryGoesBackStopsTheWayBackOfTheObject(): void {
		// Codex round 16: a hook sets review_needed while category News and
		// tag new are on the post together and takes the key away otherwise.
		// The step took the post to Other and tag new; on the way back News
		// goes back first and the hook adds the key: putting a part back
		// changed a key the plan does not name, so nothing more of the
		// object goes back by itself (the tag stays new), and the object is
		// not back as a whole.
		self::pair();
		$news_a  = wp_insert_term( 'News', 'category' );
		$other_a = wp_insert_term( 'Other', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		self::assertIsArray( $other_a, is_wp_error( $other_a ) ? $other_a->get_error_message() : '' );
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$other_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		foreach ( array( 'old', 'new' ) as $tag ) {
			self::assertIsArray( wp_insert_term( $tag, 'post_tag' ) );
			self::b( array( array( 'op' => 'insert_term', 'name' => $tag, 'taxonomy' => 'post_tag', 'as' => 'term' ) ) );
		}
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ), 'tags_input' => array( 'old' ) ) ) ), true );
		self::assertIsInt( $story_a );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ), 'tags_input' => array( 'old' ) ) ), 'as' => 'post' ) ) )['post'];
		self::paired( self::staged() );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ), 'tags_input' => array( 'new' ) ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		$tags    = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'post_tag' ) ) );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( 'new' ), $tags() );
		$news_id = (int) $news_a['term_id'];
		$review  = static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $story_a, $news_id ) {
			if ( (int) $object_id !== $story_a || ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
				return;
			}
			$slugs = wp_get_object_terms( $story_a, 'post_tag', array( 'fields' => 'slugs' ) );
			if ( has_term( $news_id, 'category', $story_a ) && in_array( 'new', $slugs, true ) ) {
				update_post_meta( $story_a, 'review_needed', 'yes' );
			} else {
				delete_post_meta( $story_a, 'review_needed' );
			}
		};
		add_action( 'set_object_terms', $review, 10, 4 );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		remove_all_actions( 'set_object_terms' );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['terms:post_tag'], json_encode( self::step( $back, 1 )['result'] ) );
		self::assertSame( array( 'new' ), $tags(), 'Nothing more of the object goes back once a part changed what the plan does not name.' );
		self::assertNotSame( 'closed_reverted', $back['state'] );
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

	public function testAMetaCallThatChangedThePartAndThenFailedLeavesTheObjectWithTheOldValues(): void {
		// P1: a key of two rows here, two on staging with another value:
		// write_meta() removes the rows here, then a filter of this site
		// refuses the new ones. The call answers with an error after it
		// changed the part: the object deviated (the one boundary of
		// 07.11.2026), nothing of it is put back: the rows stay removed, the
		// row written before stays with the staging content, each left with
		// its value before in the answer; the journal says the call failed
		// after it changed the part. Before, the step turned and put both back.
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
		self::assertSame( array( 'abc', 'def' ), self::meta_a( $contact, 'note' ) );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$refuse = static fn( $check, $object_id, $meta_key ) => ( (int) $object_id === $contact && 'note' === $meta_key ) ? false : $check;
		add_filter( 'add_post_metadata', $refuse, 10, 3 );
		$write = self::next( (int) $run['run_id'] );
		remove_filter( 'add_post_metadata', $refuse, 10 );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['meta:note']['state'], 'The rows the failed call removed stay removed.' );
		self::assertSame( 'left', $parts['row']['state'], 'The row written before stays as well.' );
		self::assertStringContainsString( 'after it changed the part', (string) $parts['meta:note']['note'], 'The journal says the call failed after it changed the part.' );
		self::assertTrue( $parts['meta:note']['own'], 'Its own call changed it (a description; nothing of a deviated object goes back).' );
		self::assertSame( '"abc", "def"', $parts['meta:note']['value_before'], 'The two values before, in the order of their rows.' );
		self::assertStringContainsString( 'failed', $write['step']['parts']['object']['deviated']['why'] );
		self::assertStringContainsString( 'after it changed the part', $write['step']['parts']['object']['deviated']['why'] );
		self::assertStringContainsString( 'deviated within the step', $write['step']['result']['message'] );
		self::assertSame( array(), self::meta_a( $contact, 'note' ), 'Nothing is put back by itself: the rows stay removed.' );
		self::assertSame( 'Write to us on staging.', self::row_a( $contact )['post_content'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $contact );
		self::assertSame( 'unclear', $pair['state'] );
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

	public function testTheDefaultCategoryTheRowGivesBackLeavesTheTermsOfAPostTheStepEmptied(): void {
		// A post in News here, paired so; staging takes its category away (set
		// so past the editor: WordPress itself gives every post a category).
		// The step writes the row (which keeps News with it) and then the
		// terms: none. On the way back the row goes first: wp_update_post()
		// gives the post, which has no category then, the default one. That
		// change is another hand's (the core's side effect), never the step's
		// own (the decision of 07.11.2026 after Codex round 7, F2: no
		// ownership passes to the row put back): the terms are left with their
		// value before, the step of the way back is unclear, and a person sets
		// the category by hand. Before, the terms went back to News. This is the
		// one case of an ordinary way back the one boundary reaches (reported to
		// the lead).
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
		$step  = self::step( $back, 1 );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $step['result'] ) );
		$outcome = array_column( $step['result']['parts'], null, 'part' );
		self::assertSame( 'restored', $outcome['row']['state'] );
		self::assertSame( 'left', $outcome['terms:category']['state'], 'The default category the row gave back is not the step\'s own: left.' );
		self::assertStringContainsString( 'putting the part row back changed the part terms:category besides it', (string) $outcome['terms:category']['note'], 'The halt names the cause.' );
		self::assertSame( '"news"', $outcome['terms:category']['value_before'] );
		self::assertSame( 'unclear', $step['state'] );
		$default = get_term( (int) get_option( 'default_category' ), 'category' );
		self::assertSame( array( $default->slug ), $slugs(), 'The default category the row gave stands; News is not written over it.' );
		self::assertSame( $before, self::row_a( $story_a ) );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $story_a );
		self::assertSame( 'unclear', $pair['state'] );
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

	/* --------- Codex round 6 on branch 8: ownership a mark of its own */

	public function testAChangeByAnotherHandBeforeARefusedMetaCallIsNotTheStepsOwnAndStays(): void {
		// N1: a note of alt here, neu on staging. A hook of this site reacting
		// to the row sets the note to fremd (another hand); the filter that
		// would refuse the step's own meta call is never reached. Before, the
		// step compared the read back with the backup, took the other hand's
		// value for its own change, put it back to alt and said restored. Now
		// the note reads back with the row other than before the row call and
		// other than what the row was to write: another hand's (foreign), and
		// the object deviated right there (the one boundary of 07.11.2026):
		// the step makes no further call, the other hand's value stands, the
		// row written before stays as well, each left with its value before,
		// the step is unclear, and the way back leaves the object whole.
		self::pair();
		$a       = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b       = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		$contact = (int) $a['contact'];
		add_post_meta( $contact, 'note', 'alt' );
		self::b( array( array( 'op' => 'add_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'alt' ) ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'neu' ) ) );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( $contact );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$refuse = static fn( $check, $object_id, $meta_key ) => ( (int) $object_id === $contact && 'note' === $meta_key ) ? false : $check;
		$hand   = static function ( $post_id ) use ( $contact, $refuse, &$hand ) {
			if ( (int) $post_id !== $contact ) {
				return;
			}
			remove_action( 'save_post', $hand, 10 );
			update_post_meta( $contact, 'note', 'fremd' );
			add_filter( 'update_post_metadata', $refuse, 10, 3 );
			add_filter( 'add_post_metadata', $refuse, 10, 3 );
			add_filter( 'delete_post_metadata', $refuse, 10, 3 );
		};
		add_action( 'save_post', $hand, 10, 1 );
		$write = self::next( (int) $run['run_id'] );
		remove_action( 'save_post', $hand, 10 );
		remove_filter( 'update_post_metadata', $refuse, 10 );
		remove_filter( 'add_post_metadata', $refuse, 10 );
		remove_filter( 'delete_post_metadata', $refuse, 10 );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['meta:note']['state'], 'Changed by another hand with the row: the other hand\'s value stays.' );
		self::assertFalse( $parts['meta:note']['own'], 'The step owns nothing of the note: what it holds is the other hand\'s.' );
		self::assertStringContainsString( 'another hand', (string) $parts['meta:note']['note'] );
		self::assertNull( $parts['meta:note']['post'] ?? null, 'The step made no call for the note: it ended with the row.' );
		self::assertSame( 'meta:note', $write['step']['parts']['object']['deviated']['part'] );
		self::assertStringContainsString( 'another hand changed the part meta:note', $write['step']['parts']['object']['deviated']['why'] );
		self::assertSame( '"alt"', $parts['meta:note']['value_before'] );
		self::assertSame( array( 'fremd' ), self::meta_a( $contact, 'note' ), 'The other hand\'s value stands; it is not put back to the backup.' );
		self::assertSame( 'left', $parts['row']['state'], 'The row written before stays: nothing of a deviated object is put back.' );
		self::assertTrue( $parts['row']['own'], 'The row is the step\'s own: its call changed it (a description).' );
		self::assertStringContainsString( 'post_content: 12 bytes (', $parts['row']['value_before'] );
		self::assertSame( 'Write to us on staging.', self::row_a( $contact )['post_content'] );
		self::probe_ok();
		self::assertSame( 'closed_findings', self::finish( (int) $run['run_id'] )['state'] );
		// The way back of the closed run: the object deviated, so the row is
		// left as well as the note.
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['row'] );
		self::assertSame( 'left', $outcome['meta:note'], 'Not the run\'s own.' );
		self::assertSame( array( 'fremd' ), self::meta_a( $contact, 'note' ), 'The way back leaves the other hand\'s value.' );
		self::assertSame( 'Write to us on staging.', self::row_a( $contact )['post_content'], 'The way back leaves the row of the deviated object.' );
		self::assertNotSame( $before, self::row_a( $contact ) );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $contact );
		self::assertSame( 'unclear', $pair['state'] );
	}

	public function testATemplateAHookChangedWithTheRowIsNotTheStepsOwnAndTheWayBackLeavesIt(): void {
		// N2: the core stores _wp_page_template with the row (page_template,
		// B7); a hook of this site reacting to the saved page sets another
		// template right after. The template reads back other than what the
		// row was to write: not provably the step's own, the object deviated,
		// left with the row, the step unclear. Before, the way back took the
		// hash it had read back for the step's own (left did not exclude it)
		// and put the hook's template back to none. Now the step owns nothing
		// of the part, and the way back leaves the object whole.
		self::pair();
		$a       = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b       = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		$contact = (int) $a['contact'];
		self::paired( self::staged() );
		$templates = wp_get_theme()->get_page_templates( null, 'page' );
		self::assertNotEmpty( $templates, 'The theme of the test site offers a template for pages: ' . get_stylesheet() );
		$template = (string) array_key_first( $templates );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.', array( 'meta_input' => array( '_wp_page_template' => $template ) ) );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( $contact );
		self::assertSame( array(), self::meta_a( $contact, '_wp_page_template' ), 'No template here before.' );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		// The hook stores default (a name the theme does not offer would make
		// wp_update_post() refuse the row on the way back, invalid_page_template,
		// a case of its own).
		$hand = static function ( $post_id ) use ( $contact, &$hand ) {
			if ( (int) $post_id === $contact ) {
				remove_action( 'save_post', $hand, 10 );
				update_post_meta( $contact, '_wp_page_template', 'default' );
			}
		};
		add_action( 'save_post', $hand, 10, 1 );
		$write = self::next( (int) $run['run_id'] );
		remove_action( 'save_post', $hand, 10 );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['row']['state'], 'Written as its target, left: the object deviated.' );
		self::assertSame( 'left', $parts['meta:_wp_page_template']['state'], 'Read back other than what the row was to write: not provably the step\'s own.' );
		self::assertFalse( $parts['meta:_wp_page_template']['own'], 'The step owns nothing of the template.' );
		self::assertStringContainsString( 'not provably', (string) $parts['meta:_wp_page_template']['note'] );
		self::assertSame( 'none (no row of the key)', $parts['meta:_wp_page_template']['value_before'] );
		self::assertSame( 'meta:_wp_page_template', $write['step']['parts']['object']['deviated']['part'] );
		self::assertSame( array( 'default' ), self::meta_a( $contact, '_wp_page_template' ), 'The hook\'s template stands.' );
		self::probe_ok();
		self::assertSame( 'closed_findings', self::finish( (int) $run['run_id'] )['state'] );
		// The way back of the closed run: the object deviated, so the row is
		// left as well as the template, whatever hash the step read back.
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['row'] );
		self::assertSame( 'left', $outcome['meta:_wp_page_template'], 'Not the run\'s own: the way back never takes it back.' );
		self::assertSame( array( 'default' ), self::meta_a( $contact, '_wp_page_template' ), 'The way back leaves the hook\'s template.' );
		self::assertSame( 'Write to us on staging.', self::row_a( $contact )['post_content'], 'The way back leaves the row of the deviated object.' );
		self::assertNotSame( $before, self::row_a( $contact ) );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $contact );
		self::assertSame( 'unclear', $pair['state'] );
	}

	public function testTermsAHookChangedAsTheRowWentBackAreLeftNotWrittenOver(): void {
		// N3: a post in News here, taken over with Other. On the way back a
		// hook of this site reacting to the row put back sets a third
		// category. The terms are decided afresh after the row went back: they
		// hold the hook's category, not what the step wrote, so they are left
		// and the object is unclear. Before, the terms were decided on the
		// state read before the row went back (Other, the step's own): News
		// was written over the hook's category and the way back said restored.
		self::pair();
		$news_a  = wp_insert_term( 'News', 'category' );
		$other_a = wp_insert_term( 'Other', 'category' );
		$third_a = wp_insert_term( 'Third', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		self::assertIsArray( $other_a, is_wp_error( $other_a ) ? $other_a->get_error_message() : '' );
		self::assertIsArray( $third_a, is_wp_error( $third_a ) ? $third_a->get_error_message() : '' );
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
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		$slugs = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		self::assertSame( array( 'other' ), $slugs() );
		// The hook reacts to the row put back (wp_update_post() fires
		// wp_insert_post) and sets the third category once.
		$third_id = (int) $third_a['term_id'];
		$hand     = static function ( $post_id, $post, $update ) use ( $story_a, $third_id, &$hand ) {
			if ( $update && (int) $post_id === $story_a ) {
				remove_action( 'wp_insert_post', $hand, 10 );
				wp_set_object_terms( $story_a, array( $third_id ), 'category', false );
			}
		};
		add_action( 'wp_insert_post', $hand, 10, 3 );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		remove_action( 'wp_insert_post', $hand, 10 );
		$step = self::step( $back, 1 );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $step['result'] ) );
		$outcome = array_column( $step['result']['parts'], 'state', 'part' );
		$notes   = array_column( $step['result']['parts'], 'note', 'part' );
		self::assertSame( 'restored', $outcome['row'] );
		self::assertSame( 'left', $outcome['terms:category'], 'Decided afresh after the row went back: the hook\'s category, not the step\'s own.' );
		self::assertStringContainsString( 'changed since the step wrote it', (string) ( $notes['terms:category'] ?? '' ) );
		self::assertStringContainsString( 'The way back stopped for this object: putting the part row back changed the part terms:category besides it', (string) ( $notes['terms:category'] ?? '' ), 'The halt names the cause (a hook of this site).' );
		self::assertSame( 'unclear', $step['state'] );
		self::assertSame( array( 'third' ), $slugs(), 'The hook\'s category stands; News is not written over it.' );
		self::assertSame( $before, self::row_a( $story_a ) );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $story_a );
		self::assertSame( 'unclear', $pair['state'] );
	}

	public function testADeviatedObjectIsLeftWholeOnTheWayBackOfTheClosedRunToo(): void {
		// The one boundary on the way back (the decision of 07.11.2026): a
		// filter of this site changes the title as the row is written, so the
		// row deviates and the object is left within the boundary. The run
		// closes with findings; wp_site_revert for the closed run names the
		// object as left in its preview, and its way back leaves every part
		// of the object with its value before, though the row still holds what
		// the step read back (own_after): decide() answers left for every part
		// of a deviated object. Before (N4), the step turned, and the way back
		// put the row back where it still held the step's own hash.
		self::pair();
		$a       = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b       = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		$contact = (int) $a['contact'];
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( $contact );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$once = static function ( array $data ) use ( &$once ): array {
			remove_filter( 'wp_insert_post_data', $once );
			$data['post_title'] = 'Changed by a filter';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $once );
		$write = self::next( (int) $run['run_id'] );
		remove_all_filters( 'wp_insert_post_data' );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'unclear', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['row']['state'] );
		self::assertTrue( $parts['row']['own'], 'The step\'s own call changed the row (a description).' );
		self::assertSame( 'Changed by a filter', self::row_a( $contact )['post_title'], 'Left as the filter wrote it.' );
		self::probe_ok();
		self::assertSame( 'closed_findings', self::finish( (int) $run['run_id'] )['state'] );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $contact );
		self::assertSame( 'unclear', $pair['state'] );
		// The preview of the way back names what will happen: left.
		$preview = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => (int) $run['run_id'] ) );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertSame( 'stays', $preview['preview']['steps'][0]['will'] );
		self::assertCount( 4, $preview['preview']['limits'], 'The limits of the way back name the one boundary.' );
		// The way back: every part of the deviated object is left, the row
		// though it holds what the step read back.
		$begun   = self::revert( (int) $run['run_id'] );
		$back    = self::revert_all( (int) $begun['run_id'] );
		$step    = self::step( $back, 1 );
		$outcome = array_column( $step['result']['parts'], null, 'part' );
		self::assertSame( 'left', $outcome['row']['state'], json_encode( $step['result'] ) );
		self::assertStringContainsString( 'deviated within its step', (string) $outcome['row']['note'] );
		self::assertStringContainsString( 'post_title: "Contact"', $outcome['row']['value_before'] );
		self::assertStringContainsString( 'post_content: 12 bytes (', $outcome['row']['value_before'] );
		self::assertSame( 'unclear', $step['state'] );
		self::assertStringContainsString( 'deviated within its step', $step['result']['message'] );
		self::assertSame( 'closed_findings', $back['state'] );
		self::assertSame( 'Changed by a filter', self::row_a( $contact )['post_title'], 'The way back leaves the row of the deviated object.' );
		self::assertNotSame( $before, self::row_a( $contact ) );
		self::assertSame( 'unclear', AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $contact )['state'] );
	}

	public function testARowCallRefusedBeforeItWroteFailsTheStepAndNothingIsLeft(): void {
		// A filter of this site refuses the one wp_update_post() of the step
		// (wp_insert_post_empty_content, as a plugin that fails on one save
		// would): the call answered with an error and changed nothing, so the
		// object deviated, but no part differs from the backup: the step is
		// failed, nothing is left, the pair stays as it was. The way back of
		// the closed run finds every part as the backup.
		self::pair();
		$a       = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b       = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		$contact = (int) $a['contact'];
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$before  = self::row_a( $contact );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$refuse = static function ( $maybe_empty, $postarr ) use ( $contact, &$refuse ) {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === $contact ) {
				remove_filter( 'wp_insert_post_empty_content', $refuse, 10 );
				return true;
			}
			return $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $refuse, 10, 2 );
		$write = self::next( (int) $run['run_id'] );
		remove_filter( 'wp_insert_post_empty_content', $refuse, 10 );
		self::assertIsArray( $write, is_wp_error( $write ) ? $write->get_error_message() : '' );
		self::assertSame( 'failed', $write['step']['state'], json_encode( $write['step'] ) );
		$parts = array_column( $write['step']['parts']['parts'], null, 'part' );
		self::assertSame( 'failed', $parts['row']['state'] );
		self::assertStringContainsString( 'changed nothing of the part', (string) $parts['row']['note'] );
		self::assertArrayNotHasKey( 'value_before', $parts['row'], 'Nothing is left: no value before.' );
		self::assertSame( 'row', $write['step']['parts']['object']['deviated']['part'], 'The object keeps its mark although nothing differs from the backup (Codex round 9).' );
		self::assertStringContainsString( 'Nothing was written', $write['step']['result']['message'] );
		self::assertSame( $before, self::row_a( $contact ), 'The object is as before the step.' );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $contact );
		self::assertSame( 'active', $pair['state'], 'Nothing was written: the pair stays as it was.' );
		self::probe_ok();
		self::assertSame( 'closed_findings', self::finish( (int) $run['run_id'] )['state'] );
		// The way back of the closed run: every part is as the backup; the
		// preview says the object stays (nothing of it goes back by itself),
		// the way back names every part unchanged (Codex rounds 8, 11).
		$preview = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => (int) $run['run_id'] ) );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertSame( 'stays', $preview['preview']['steps'][0]['will'] );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( self::step( $back, 1 )['result'] ) );
		self::assertSame( 'unchanged', array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' )['row'] );
		self::assertSame( $before, self::row_a( $contact ) );
	}

	public function testThePreviewOfAStepFailedAsTheBackupSaysLeftOnceAnotherHandChangedTheObject(): void {
		// Codex rounds 10 and 11: the row call refused before it wrote, the
		// step failed with its object as the backup; then another hand edits
		// the title. The preview says the object stays (it claims nothing of
		// what it holds now), and the way back leaves the edited row.
		self::pair();
		$a       = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b       = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		$contact = (int) $a['contact'];
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'] );
		$refuse = static function ( $maybe_empty, $postarr ) use ( $contact, &$refuse ) {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === $contact ) {
				remove_filter( 'wp_insert_post_empty_content', $refuse, 10 );
				return true;
			}
			return $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $refuse, 10, 2 );
		$write = self::next( (int) $run['run_id'] );
		remove_filter( 'wp_insert_post_empty_content', $refuse, 10 );
		self::assertSame( 'failed', $write['step']['state'], json_encode( $write['step'] ) );
		self::probe_ok();
		self::finish( (int) $run['run_id'] );
		$edited = wp_update_post( array( 'ID' => $contact, 'post_title' => 'Edited by hand' ), true );
		self::assertSame( $contact, $edited );
		$preview = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => (int) $run['run_id'] ) );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertSame( 'stays', $preview['preview']['steps'][0]['will'] );
		$begun   = self::revert( (int) $run['run_id'] );
		$back    = self::revert_all( (int) $begun['run_id'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['row'] );
		self::assertSame( 'Edited by hand', self::row_a( $contact )['post_title'] );
	}

	public function testAJournalThatMarksAPartForeignLeavesTheWholeObjectOnTheWayBack(): void {
		// Codex round 8: a step whose request ended after a part was marked as
		// changed by another hand (seen_before(), written ahead of the call)
		// and before the object was marked deviated. The way back reads the
		// mark: the object deviated all the same, nothing of it goes back,
		// the row included. Simulated on the journal of a written step.
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		$planned = self::planned( array( 'contact' ) );
		$run     = self::begun( $planned['args'] );
		$run_id  = (int) $run['run_id'];
		self::assertSame( 'closed_done', self::finish( $run_id )['state'] );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'] );
		$step    = AB_MCP_Sync_Run::step( $run_id, 2 );
		$journal = $step['parts'];
		self::assertArrayHasKey( 'deviated', $journal['object'] );
		$journal['object']['deviated'] = null;
		foreach ( $journal['parts'] as $k => $part ) {
			if ( 'row' !== $part['part'] ) {
				$journal['parts'][ $k ]['foreign'] = true;
			}
		}
		$any = false;
		foreach ( $journal['parts'] as $part ) {
			$any = $any || ! empty( $part['foreign'] );
		}
		if ( ! $any ) {
			$journal['parts'][0]['foreign'] = true;
		}
		$set = AB_MCP_Sync_Run::update_step( $run_id, 2, array( 'parts' => $journal ), 'done' );
		self::assertNotInstanceOf( WP_Error::class, $set );
		$preview = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id ) );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertSame( 'stays', $preview['preview']['steps'][0]['will'] );
		$begun   = self::revert( $run_id );
		$back    = self::revert_all( (int) $begun['run_id'] );
		$outcome = array_column( self::step( $back, 1 )['result']['parts'], 'state', 'part' );
		self::assertSame( 'left', $outcome['row'], json_encode( self::step( $back, 1 )['result'] ) );
		self::assertSame( 'Write to us on staging.', self::row_a( (int) $a['contact'] )['post_content'], 'Nothing of the object goes back.' );
	}

	public function testAPostWithACategoryAndATagAndAPageWithATemplateGoOverAndBackWithEveryPartRestored(): void {
		// The ordinary case under the one boundary (the decision of
		// 07.11.2026, to be checked: does the boundary reach ordinary content?):
		// a post with a category and a tag, changed on staging in content,
		// category and tag, and a page given a template on staging, go over in
		// one run without a deviation (every part read as its target, no mark
		// of another hand, no deviation), and the way back puts every part back
		// (restored where the step wrote it, unchanged where it did not); the
		// objects are byte for byte as before, the pairs back to their
		// snapshots, the run closed as reverted.
		self::pair();
		$news_a  = wp_insert_term( 'News', 'category' );
		$other_a = wp_insert_term( 'Other', 'category' );
		$tip_a   = wp_insert_term( 'Tip', 'post_tag' );
		$hint_a  = wp_insert_term( 'Hint', 'post_tag' );
		foreach ( array( $news_a, $other_a, $tip_a, $hint_a ) as $term ) {
			self::assertIsArray( $term, is_wp_error( $term ) ? $term->get_error_message() : '' );
		}
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$other_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		self::b( array( array( 'op' => 'insert_term', 'name' => 'Tip', 'taxonomy' => 'post_tag', 'as' => 'term' ) ) );
		self::b( array( array( 'op' => 'insert_term', 'name' => 'Hint', 'taxonomy' => 'post_tag', 'as' => 'term' ) ) );
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ), 'tags_input' => array( 'Tip' ) ) ) ), true );
		self::assertIsInt( $story_a );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ), 'tags_input' => array( 'Tip' ) ) ), 'as' => 'post' ) ) )['post'];
		$a       = self::pages_on_a( array( 'contact' => 'Write to us.' ) );
		$b       = self::pages_on_b( array( 'contact' => 'Write to us.' ) );
		$contact = (int) $a['contact'];
		self::paired( self::staged() );
		$templates = wp_get_theme()->get_page_templates( null, 'page' );
		self::assertNotEmpty( $templates, 'The theme of the test site offers a template for pages: ' . get_stylesheet() );
		$template = (string) array_key_first( $templates );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ), 'tags_input' => array( 'Hint' ) ) );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.', array( 'meta_input' => array( '_wp_page_template' => $template ) ) );
		$slugs = static fn( string $taxonomy ): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, $taxonomy ) ) );
		self::assertSame( array( 'news' ), $slugs( 'category' ) );
		self::assertSame( array( 'tip' ), $slugs( 'post_tag' ) );
		$planned      = self::planned( array( 'story', 'contact' ) );
		$run          = self::begun( $planned['args'] );
		$story_before = self::row_a( $story_a );
		$page_before  = self::row_a( $contact );
		self::probe_ok();
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$closed = self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => (int) $run['run_id'] ) );
		foreach ( $closed['steps'] as $step ) {
			if ( 'write' !== $step['op'] ) {
				continue;
			}
			self::assertSame( 'done', $step['state'], json_encode( $step ) );
			self::assertNull( $step['parts']['object']['deviated'], 'No deviation.' );
			foreach ( $step['parts']['parts'] as $part ) {
				self::assertSame( 'read', $part['state'], $part['part'] );
				self::assertArrayNotHasKey( 'deviated', $part );
				self::assertArrayNotHasKey( 'value_before', $part );
			}
		}
		self::assertSame( 'A story, on staging.', self::row_a( $story_a )['post_content'] );
		self::assertSame( array( 'other' ), $slugs( 'category' ) );
		self::assertSame( array( 'hint' ), $slugs( 'post_tag' ) );
		self::assertSame( array( $template ), self::meta_a( $contact, '_wp_page_template' ) );
		// The way back: every part back, the objects as before.
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		$back = self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => (int) $begun['run_id'] ) );
		foreach ( $back['steps'] as $step ) {
			self::assertSame( 'done', $step['state'] );
			foreach ( $step['result']['parts'] as $part ) {
				self::assertContains( $part['state'], array( 'restored', 'unchanged' ), json_encode( $part ) );
			}
		}
		self::assertSame( $story_before, self::row_a( $story_a ) );
		self::assertSame( $page_before, self::row_a( $contact ) );
		self::assertSame( array( 'news' ), $slugs( 'category' ) );
		self::assertSame( array( 'tip' ), $slugs( 'post_tag' ) );
		self::assertSame( array(), self::meta_a( $contact, '_wp_page_template' ) );
		foreach ( array( $story_a, $contact ) as $id ) {
			$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $id );
			self::assertSame( array( 'active', 'proposal' ), array( $pair['state'], $pair['base_origin'] ), 'The pair went back to its snapshot.' );
		}
	}

	public function testTheWayBackAsksTheRightsAtTheObjectAndLeavesAPartTheAccountMayNotWrite(): void {
		// GitHub Codex round 2 on free#137 (P2): the way back checked the owner
		// of the run, never the rights at the object, so an account that lost
		// the right to a post, a key or a term since the run wrote could still
		// put them back. Now every part asks the rights of the forward write
		// right before it goes back (decision 28): a key a filter of this site
		// protects (auth_post_meta_note), a page the account may not edit, a
		// term it may not assign are left with their values before; the other
		// parts go back, the run closes with findings.
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
		$a       = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$b       = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$contact = (int) $a['contact'];
		$about   = (int) $a['about'];
		add_post_meta( $contact, 'note', 'abc' );
		self::b( array( array( 'op' => 'add_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'abc' ) ) );
		self::paired( self::staged() );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ) ) );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'def' ) ) );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.' );
		$planned = self::planned( array( 'story', 'contact', 'about' ) );
		$run     = self::begun( $planned['args'] );
		$slugs   = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( 'def' ), self::meta_a( $contact, 'note' ) );
		self::assertSame( array( 'other' ), $slugs() );
		self::assertSame( 'About us, on staging.', self::row_a( $about )['post_content'] );
		// The account loses three rights before the way back: the key note (a
		// filter of this site, as WordPress protects a key), the page about
		// (edit_post), the term News (assign_term).
		add_filter( 'auth_post_meta_note', '__return_false' );
		$news_id = (int) $news_a['term_id'];
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $about, $news_id ) {
				if ( 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $about ) {
					return array( 'do_not_allow' );
				}
				if ( 'assign_term' === $cap && isset( $args[0] ) && (int) $args[0] === $news_id ) {
					return array( 'do_not_allow' );
				}
				return $caps;
			},
			10,
			4
		);
		self::assertFalse( current_user_can( 'edit_post_meta', $contact, 'note' ) );
		self::assertFalse( current_user_can( 'edit_post', $about ) );
		self::assertFalse( current_user_can( 'assign_term', $news_id ) );
		self::assertTrue( current_user_can( 'edit_post', $contact ) );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$back  = self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => (int) $begun['run_id'] ) );
		$steps = array();
		foreach ( $back['steps'] as $step ) {
			if ( 'revert' === $step['op'] ) {
				$steps[ (int) $step['result']['live_ref'] ] = $step;
			}
		}
		// contact: the row back, the key left with its value before.
		$parts = array_column( $steps[ $contact ]['result']['parts'], null, 'part' );
		self::assertSame( 'restored', $parts['row']['state'], json_encode( $parts ) );
		self::assertSame( 'left', $parts['meta:note']['state'] );
		self::assertStringContainsString( 'this account may not edit the meta key note of the post (edit_post_meta)', $parts['meta:note']['note'] );
		self::assertStringContainsString( 'wp-admin', $parts['meta:note']['note'], 'The way.' );
		self::assertSame( '"abc"', $parts['meta:note']['value_before'] );
		self::assertSame( 'unclear', $steps[ $contact ]['state'] );
		self::assertSame( array( 'def' ), self::meta_a( $contact, 'note' ), 'Not written.' );
		self::assertSame( 'Write to us.', self::row_a( $contact )['post_content'], 'The row went back.' );
		// about: the row left.
		$parts = array_column( $steps[ $about ]['result']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['row']['state'], json_encode( $parts ) );
		self::assertStringContainsString( sprintf( 'this account may not edit the post %d (edit_post)', $about ), $parts['row']['note'] );
		self::assertStringContainsString( 'post_content: 9 bytes', $parts['row']['value_before'] );
		self::assertSame( 'About us, on staging.', self::row_a( $about )['post_content'], 'Not written.' );
		// story: the row back, the category left.
		$parts = array_column( $steps[ $story_a ]['result']['parts'], null, 'part' );
		self::assertSame( 'restored', $parts['row']['state'], json_encode( $parts ) );
		self::assertSame( 'left', $parts['terms:category']['state'] );
		self::assertStringContainsString( sprintf( 'this account may not assign the term %d of category (assign_term)', $news_id ), $parts['terms:category']['note'] );
		self::assertSame( '"news"', $parts['terms:category']['value_before'] );
		self::assertSame( array( 'other' ), $slugs(), 'Not written.' );
		self::assertSame( 'A story.', self::row_a( $story_a )['post_content'] );
		foreach ( array( $contact, $about, $story_a ) as $id ) {
			$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $id );
			self::assertSame( 'unclear', $pair['state'], "Pair of $id." );
		}
	}

	public function testWithoutEditPostNothingOfThePostGoesBackNotEvenItsTerms(): void {
		// Codex round 18 of branch 8 (GitHub Codex round 2, P2, in part): the
		// owner of the run loses edit_post on the post but keeps the rights of
		// the taxonomy (assign_term asks nothing of the post itself); before,
		// the row was left and the category went back all the same
		// (wp_set_object_terms()). Every part is the post's: edit_post first,
		// whatever the part; the row and the category stay with their values
		// before.
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
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $other_b ), 'post_title' => 'Story, on staging' ) );
		$planned = self::planned( array( 'story' ) );
		$run     = self::begun( $planned['args'] );
		$slugs   = static fn(): array => array_values( array_map( static fn( $t ) => $t->slug, wp_get_object_terms( $story_a, 'category' ) ) );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( 'other' ), $slugs() );
		self::assertSame( 'Story, on staging', self::row_a( $story_a )['post_title'] );
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $story_a ) {
				return 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $story_a ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);
		self::assertFalse( current_user_can( 'edit_post', $story_a ) );
		self::assertTrue( current_user_can( 'assign_term', (int) $news_a['term_id'] ), 'The rights of the taxonomy stay.' );
		self::assertTrue( current_user_can( get_taxonomy( 'category' )->cap->assign_terms ) );
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$step  = self::revert_step( $back );
		$parts = array_column( $step['result']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['row']['state'], json_encode( $parts ) );
		self::assertStringContainsString( sprintf( 'this account may not edit the post %d (edit_post)', $story_a ), $parts['row']['note'] );
		self::assertSame( 'left', $parts['terms:category']['state'], 'The terms of a post the account may not edit stay as well.' );
		self::assertStringContainsString( sprintf( 'this account may not edit the post %d (edit_post, for the part terms:category)', $story_a ), $parts['terms:category']['note'] );
		self::assertSame( '"news"', $parts['terms:category']['value_before'] );
		self::assertSame( 'unclear', $step['state'] );
		self::assertSame( array( 'other' ), $slugs(), 'Not written.' );
		self::assertSame( 'Story, on staging', self::row_a( $story_a )['post_title'], 'Not written.' );
		$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $story_a );
		self::assertSame( 'unclear', $pair['state'] );
	}

	public function testAHookOfTheRightsCheckThatChangesAPartAsItGoesBackIsCaughtByTheSecondReadOfTheKind(): void {
		// Codex round 18 of branch 8: between the hash put_back() decides a part
		// on (decide(), read right before its turn) and the write of the kind,
		// the rights at the object are asked (restore_right()), and a site may
		// hook them (map_meta_cap, user_has_cap). A hook that changes the part
		// there is caught by the second read of the kind as it writes
		// (restore_row(), restore_meta() against what the step read back,
		// decision 23): the part is left, the hook's value stands, nothing is
		// written over it. (The entries writer-restore-row-own and
		// writer-restore-meta-own of the mutation list hold these clauses here.)
		self::pair();
		$a = self::pages_on_a( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$b = self::pages_on_b( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$contact = (int) $a['contact'];
		$about   = (int) $a['about'];
		add_post_meta( $contact, 'note', 'abc' );
		self::b( array( array( 'op' => 'add_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'abc' ) ) );
		self::paired( self::staged() );
		self::change_on_b( $b['contact'], 'contact', 'Write to us on staging.' );
		self::b( array( array( 'op' => 'update_meta', 'post' => (int) $b['contact'], 'key' => 'note', 'value' => 'def' ) ) );
		self::change_on_b( $b['about'], 'about', 'About us, on staging.', array( 'post_title' => 'About, on staging' ) );
		$planned = self::planned( array( 'contact', 'about' ) );
		$run     = self::begun( $planned['args'] );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( 'def' ), self::meta_a( $contact, 'note' ) );
		self::assertSame( 'About, on staging', self::row_a( $about )['post_title'] );
		// The hook: as the rights of the row of about are asked, the title is
		// changed once, past every lock (SQL); as the rights of the key note of
		// contact are asked, the value is changed once.
		$fired = array();
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $about, $contact, &$fired ) {
				global $wpdb;
				if ( 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $about && ! isset( $fired['row'] ) ) {
					$fired['row'] = true;
					$wpdb->update( $wpdb->posts, array( 'post_title' => 'Changed by a hook' ), array( 'ID' => $about ) );
					clean_post_cache( $about );
				}
				if ( 'edit_post_meta' === $cap && isset( $args[0], $args[1] ) && (int) $args[0] === $contact && 'note' === $args[1] && ! isset( $fired['meta'] ) ) {
					$fired['meta'] = true;
					$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'xyz' ), array( 'post_id' => $contact, 'meta_key' => 'note' ) );
					wp_cache_delete( $contact, 'post_meta' );
				}
				return $caps;
			},
			10,
			4
		);
		$begun = self::revert( (int) $run['run_id'] );
		$back  = self::revert_all( (int) $begun['run_id'] );
		self::assertSame( array( 'row' => true, 'meta' => true ), $fired, 'The hook fired as the rights were asked.' );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$back  = self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => (int) $begun['run_id'] ) );
		$steps = array();
		foreach ( $back['steps'] as $step ) {
			if ( 'revert' === $step['op'] ) {
				$steps[ (int) $step['result']['live_ref'] ] = $step;
			}
		}
		$parts = array_column( $steps[ $about ]['result']['parts'], null, 'part' );
		self::assertSame( 'left', $parts['row']['state'], json_encode( $parts ) );
		self::assertStringContainsString( 'The row was changed since the step wrote it', $parts['row']['note'] );
		self::assertStringContainsString( 'post_title: "About"', $parts['row']['value_before'] );
		self::assertSame( 'Changed by a hook', self::row_a( $about )['post_title'], 'The hook\'s title stands; the backup was not written over it.' );
		self::assertSame( 'About us, on staging.', self::row_a( $about )['post_content'] );
		$parts = array_column( $steps[ $contact ]['result']['parts'], null, 'part' );
		self::assertSame( 'restored', $parts['row']['state'], json_encode( $parts ) );
		self::assertSame( 'left', $parts['meta:note']['state'] );
		self::assertStringContainsString( 'The meta note was changed since the step wrote it', $parts['meta:note']['note'] );
		self::assertSame( '"abc"', $parts['meta:note']['value_before'] );
		self::assertSame( array( 'xyz' ), self::meta_a( $contact, 'note' ), 'The hook\'s value stands.' );
		self::assertSame( 'Write to us.', self::row_a( $contact )['post_content'], 'The row went back.' );
		foreach ( array( $contact, $about ) as $id ) {
			$pair = AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $id );
			self::assertSame( 'unclear', $pair['state'], "Pair of $id." );
		}
	}

	public function testAFormatNamedByItsSlugAndATaxonomyOnlyThisSiteHasAskTheRightToAssignBeforeTheFirstWrite(): void {
		// GitHub Codex round 2 on free#137 (P2): a format is written by its
		// slug (post_format), so the check before the first write never asked
		// assign_term for it, and a taxonomy only the post of this site carries,
		// which the step clears, was never asked at all. Now (decision 28): a
		// slug this site has no term of asks the right of the taxonomy to make
		// one; a slug it has a term of asks assign_term for that term; a
		// taxonomy only this site has asks its right to assign. Each refusal
		// closes the run before the first write, nothing written.
		self::pair();
		$news_a = wp_insert_term( 'News', 'category' );
		self::assertIsArray( $news_a, is_wp_error( $news_a ) ? $news_a->get_error_message() : '' );
		$news_b  = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'as' => 'term' ) ) )['term'];
		$story_a = wp_insert_post( wp_slash( self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( (int) $news_a['term_id'] ) ) ) ), true );
		self::assertIsInt( $story_a );
		$story_b = (int) self::b( array( array( 'op' => 'insert_post', 'args' => self::page( 'story', 'A story.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) ), 'as' => 'post' ) ) )['post'];
		self::paired( self::staged() );
		// On staging the story gets the format aside and new content.
		$aside_b = (int) self::b( array( array( 'op' => 'insert_term', 'name' => 'post-format-aside', 'taxonomy' => 'post_format', 'as' => 'term' ) ) )['term'];
		self::b( array( array( 'op' => 'set_terms', 'post' => $story_b, 'terms' => array( $aside_b ), 'taxonomy' => 'post_format' ) ) );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) );
		$format = static fn(): string|false => get_post_format( $story_a );
		self::assertFalse( $format() );
		$deny = null;
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( &$deny ) {
				return null !== $deny && $deny( (string) $cap, (array) $args ) ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);
		$aborted = function ( array $args, string $needs ) use ( $story_a ): void {
			$run = self::begun( $args );
			$closed = self::finish( (int) $run['run_id'] );
			self::assertSame( 'closed_aborted', $closed['state'], json_encode( $closed ) );
			$check = self::step( $closed, 1 );
			self::assertStringContainsString( 'this account may not ' . $needs, json_encode( $check['result'] ) );
			self::assertSame( 'A story.', self::row_a( $story_a )['post_content'], 'Nothing written.' );
		};
		// 1. This site has no term of the format yet: the right of post_format
		// to make one (manage_categories), asked before the first write.
		$planned = self::planned( array( 'story' ) );
		$deny    = static fn( string $cap, array $args ): bool => 'manage_categories' === $cap;
		self::assertFalse( current_user_can( 'manage_categories' ) );
		$aborted( $planned['args'], 'make the term post-format-aside of post_format, which this site has no term of yet (manage_categories)' );
		// 2. This site has the term: assign_term for it, by the id its slug names.
		$aside_a = wp_insert_term( 'post-format-aside', 'post_format' );
		self::assertIsArray( $aside_a, is_wp_error( $aside_a ) ? $aside_a->get_error_message() : '' );
		$aside_id = (int) $aside_a['term_id'];
		$deny     = static fn( string $cap, array $args ): bool => 'assign_term' === $cap && isset( $args[0] ) && (int) $args[0] === $aside_id;
		$aborted( $planned['args'], sprintf( 'assign the term %d of post_format (assign_term)', $aside_id ) );
		// With the rights the run writes the format.
		$deny = null;
		$run  = self::begun( $planned['args'] );
		self::probe_ok();
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( 'aside', $format() );
		self::assertSame( 'A story, on staging.', self::row_a( $story_a )['post_content'] );
		// 3. Staging takes the format off again: post_format is now a taxonomy
		// only this site's post carries, which the step clears; the right of
		// the taxonomy to assign its terms (edit_posts) is asked for it.
		self::b( array( array( 'op' => 'set_terms', 'post' => $story_b, 'terms' => array(), 'taxonomy' => 'post_format' ) ) );
		self::change_on_b( (string) $story_b, 'story', 'A story, on staging, again.', array( 'post_type' => 'post', 'post_category' => array( $news_b ) ) );
		$planned = self::planned( array( 'story' ) );
		$deny    = static fn( string $cap, array $args ): bool => 'edit_posts' === $cap && array() === $args;
		$run     = self::begun( $planned['args'] );
		$closed  = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $closed['state'], json_encode( $closed ) );
		self::assertStringContainsString( 'this account may not assign the terms of post_format (edit_posts)', json_encode( self::step( $closed, 1 )['result'] ) );
		self::assertSame( 'aside', $format(), 'Nothing written.' );
		$deny = null;
		$run  = self::begun( $planned['args'] );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertFalse( $format(), 'The format taken off, as on staging.' );
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

	public function testABackupGivesBytesThatAreNoUtf8BackAsBytesNotAsTheMarkerOfTheCanon(): void {
		// GitHub Codex round 2 on free#137 (P2): a backup is written in the one
		// spelling of AB_MCP_Sync_Canon::encode(), which writes bytes that are
		// no UTF-8 as {"~b":"<base64>"}. Read back with json_decode() alone, the
		// value was an array: meta_hash() hashed it as «Array» and the way back
		// handed the array to the SQL of restore_meta(). The backup reads back
		// through AB_MCP_Sync_Canon::decode(): the bytes are the bytes again,
		// and the hash of the backed-up part is the hash of the row as it was.
		global $wpdb;
		$wpdb->insert(
			AB_MCP_Sync_Schema::table( 'runs' ),
			array( 'run_id' => 9, 'type' => 'push', 'source_id' => 'src', 'state' => 'open', 'created' => time(), 'updated' => time(), 'closed' => 0, 'open_one' => 1, 'forward_closed_at' => 0, 'keep_until' => 0 )
		);
		self::assertSame( '', $wpdb->last_error );
		$current = array(
			'row'   => array( 'post_title' => "Caf\xE9", 'post_content' => 'Hello.', 'post_status' => 'publish' ),
			'meta'  => array( array( 'meta_id' => 7, 'key' => 'note', 'value' => "raw\xE9" ), array( 'meta_id' => 8, 'key' => 'other', 'value' => 'abc' ) ),
			'terms' => array( array( 'taxonomy' => 'category', 'term_id' => 3, 'slug' => 'news', 'term_order' => 0 ) ),
		);
		$ref = AB_MCP_Sync_Backup::put( 9, 'step-0002', $current );
		self::assertIsArray( $ref, is_wp_error( $ref ) ? $ref->get_error_message() : '' );
		$text = AB_MCP_Protected_Store::get( 'backups', AB_MCP_Sync_Backup::group( 9 ), $ref['name'], $ref['sha256'] );
		self::assertIsString( $text );
		self::assertStringContainsString( '{"~b":"' . base64_encode( "raw\xE9" ) . '"}', $text, 'Kept as the marker of the canon.' );
		$back = AB_MCP_Sync_Backup::get( 9, $ref );
		self::assertIsArray( $back, is_wp_error( $back ) ? $back->get_error_message() : '' );
		self::assertSame( array( "raw\xE9", 'abc' ), array_column( $back['meta'], 'value' ), 'The bytes, not an array.' );
		self::assertSame( "Caf\xE9", $back['row']['post_title'] );
		self::assertSame( \AB_MCP_Sync_Post_Writer::meta_hash( array( "raw\xE9" ) ), \AB_MCP_Sync_Post_Writer::part_hash( $back, 'meta:note' ), 'The hash the way back compares with is the hash of the row as it was.' );
		self::assertSame( \AB_MCP_Sync_Post_Writer::row_hash( $current['row'] ), \AB_MCP_Sync_Post_Writer::part_hash( $back, 'row' ) );
		self::assertEquals( $current['terms'], $back['terms'] );
		self::assertSame( 'none (no row of the key)', \AB_MCP_Sync_Post_Writer::before_value( 'meta:gone', $back, $back, 1 ) );
		self::assertSame( '(4 bytes, not UTF-8)', \AB_MCP_Sync_Post_Writer::before_value( 'meta:note', $back, array( 'row' => $back['row'], 'meta' => array(), 'terms' => array() ), 1 ), 'The value before of bytes that are no UTF-8: by their count.' );
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
