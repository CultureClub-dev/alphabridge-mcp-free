<?php
/**
 * The way back where the writer of a kind is not registered (I3 of the
 * seam, step T5; I2 follows in the next part): on two WordPress sites as
 * tests/integration/SyncPlanWithoutWriterTest.php (B the staging site, A
 * this process), a way back begun with every writer, interrupted, then led
 * on with post alone registered, by its subtypes post and page (as U1
 * allows it), here only (after the move, a kind this plugin no longer has):
 *
 * - (I3) the way back of three categories, interrupted at its first step
 *   and led on with action=plan and its token: no answer from then on is an
 *   internal error, each next answers without a WP_Error, and nothing of a
 *   term is put back, the steps still open as little as the one
 *   interrupted. The plan and the confirmation of the continuation, its
 *   preview, the states and texts of the steps and the end of the run are
 *   read to go on, not asserted.
 *
 * The registrations made as the engine loads are kept before each case and
 * put back after it (reflection), for the other tests of the process.
 *
 * R2 open (the seam, first step): the cases here assert only that no
 * object of a kind without a writer is written or put back. How the way
 * back plans, continues and ends the steps of such a kind is the state
 * of the first step, not what must hold after the move; that is open.
 * Once R2 is decided, the case (I3) is changed with it.
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
use AB_MCP_Sync_Engine;
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Sync_Writers;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';

final class SyncWayBackWithoutWriterTest extends TestCase {

	/** @var array|null The table of the writers as the engine registered it. */
	private $kept = null;

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
		AB_MCP_Sync_Guard::disarm();
		delete_option( AB_MCP_Sync_Guard::OPTION );
		delete_option( \AB_MCP_Change_Lock::OPTION );
		wp_set_current_user( self::admin() );
		self::assertIsArray( AB_MCP_Sync_Schema::install() );
		$this->kept = ( new ReflectionProperty( AB_MCP_Sync_Writers::class, 'writers' ) )->getValue();
	}

	protected function tearDown(): void {
		( new ReflectionProperty( AB_MCP_Sync_Writers::class, 'writers' ) )->setValue( null, $this->kept );
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Guard::disarm();
		delete_option( \AB_MCP_Change_Lock::OPTION );
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

	/** The protected store of both sites removed. */
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

	/** One step on B that makes an object: its id. */
	private static function on_b( array $step ): int {
		return (int) self::b( array( $step + array( 'as' => 'id' ) ) )['id'];
	}

	/** B packs its inventory and A takes it over: the package ids. */
	private static function staged(): array {
		self::b( array( array( 'op' => 'store_cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) ) );
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

	/** A tool of the staging group through the gate of the plugin, in this process. */
	private static function gated( string $name, array $a ) {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return AB_MCP_REST_Controller::run_tool( $name, $r->get( $name ), $a );
	}

	/** Every equal entry of a fresh proposal paired. */
	private static function paired( array $ids ): void {
		$answer = self::gated( 'wp_site_pairs', array( 'action' => 'propose', 'packages' => $ids ) );
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = self::gated( 'wp_site_pairs', array( 'action' => 'propose', 'proposal_id' => $answer['proposal_id'] ) );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'categories'  => array( 'equal' ),
		);
		$preview = self::gated( 'wp_site_pairs', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$done = self::gated( 'wp_site_pairs', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
	}

	/** wp_site_diff in this process, worked out to its end and paged: every entry in all. */
	private static function report( array $ids ): array {
		$answer = AB_MCP_Sync_Report::call( array( 'packages' => $ids ) );
		for ( $calls = 1; is_array( $answer ) && false === $answer['done'] && $calls < 300; $calls++ ) {
			$answer = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		self::assertTrue( $answer['done'] );
		$all  = $answer['entries'];
		$page = $answer;
		for ( $pages = 0; null !== $page['cursor'] && $pages < 300; $pages++ ) {
			$page = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'], 'cursor' => $page['cursor'] ) );
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
		}
		$answer['all'] = $all;
		return $answer;
	}

	/** The entry of an object of this kind and subtype by the name of its staging side (slug, or the file of an attachment). */
	private static function entry( array $report, string $kind, string $subtype, string $name ): array {
		foreach ( $report['all'] as $entry ) {
			$identity = $entry['staging']['identity'] ?? array();
			if ( $kind === $entry['kind'] && $subtype === $entry['subtype'] && in_array( $name, array( $identity['post_name'] ?? null, $identity['slug'] ?? null, $identity['file'] ?? null ), true ) ) {
				return $entry;
			}
		}
		self::fail( "No entry of the $kind $subtype $name in the report." );
	}

	/** The arguments of a plan of these entries of the report, with the package of their objects. */
	private static function args( array $report, array $entries ): array {
		$refs = array();
		foreach ( $entries as $entry ) {
			$refs[] = array(
				'kind' => $entry['kind'],
				'ref'  => (string) $entry['staging']['ref'],
			);
		}
		self::b( array( array( 'op' => 'store_cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) ) );
		$package = self::b(
			array(
				array(
					'op'          => 'export',
					'purpose'     => 'objects',
					'report_hash' => $report['report_hash'],
					'refs'        => $refs,
					'as'          => 'package',
				),
			)
		)['package'];
		return array(
			'action'    => 'plan',
			'report_id' => $report['report_id'],
			'select'    => array_map( static fn( $e ) => (int) $e['n'], $entries ),
			'packages'  => array( self::import_package( $package, 'objects', $report['report_hash'] ) ),
		);
	}

	/** Post alone registered, by its subtypes post and page, as U1 allows it (tearDown() puts the table back). */
	private static function only_post(): void {
		AB_MCP_Sync_Writers::reset();
		self::assertTrue( AB_MCP_Sync_Writers::register( 'post', array( 'post', 'page' ), AB_MCP_Sync_Engine::WRITERS['post'] ) );
		self::assertNull( AB_MCP_Sync_Engine::writer( 'post', 'wp_block' ) );
	}

	/** The way back of a run planned and confirmed in this process. */
	private static function revert( int $run_id ): array {
		$args    = array(
			'action' => 'plan',
			'run_id' => $run_id,
		);
		$preview = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$begun = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
		return $begun;
	}

	/** A run of these plan arguments, confirmed and led to its close with every writer, each answer no error: its id. */
	private static function forward( array $args ): int {
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		for ( $i = 0; $i < 8; $i++ ) {
			$answer = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => (int) $run['run_id'] ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return (int) $run['run_id'];
			}
		}
		self::fail( 'The run closes.' );
	}

	/** The rows of these terms of A in term_taxonomy, by the keys given. */
	private static function terms_a( array $ids ): array {
		return array_map( static fn( int $id ): array => AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_id' => (string) $id ) ), $ids );
	}

	/** An answer that is no internal error (ab_mcp_internal), whatever else it is. */
	private static function not_internal( $answer, string $what ): void {
		self::assertNotSame( 'ab_mcp_internal', is_wp_error( $answer ) ? $answer->get_error_code() : null, $what . ( is_wp_error( $answer ) ? ' ' . $answer->get_error_message() : '' ) );
	}

	/* -------------------------------------------------------------- cases */

	/**
	 * Prüft: (I3) a way back with open steps of a kind whose writer is gone
	 * (three categories; begun with every writer, interrupted at its first
	 * step, then led on with post alone registered, by its subtypes): no
	 * answer from then on is an internal error, each next answers without a
	 * WP_Error, and nothing of a term is put back, the steps still open as
	 * little as the one interrupted (the terms of A as the forward run left
	 * them). Read to go on, not asserted (R2): the plan and the confirmation
	 * of the continuation with its token, the preview, the counts, the
	 * states and texts of the steps, the end of the run.
	 * Sonst: Free would put a term back over a writer it no longer has, or
	 * end the continuation with an error.
	 */
	public function testAWayBackLedOnWithOpenStepsOfAKindWithoutAWriterPutsNothingBack(): void {
		self::pair();
		$names = array(
			'news'   => 'News',
			'tips'   => 'Tips',
			'events' => 'Events',
		);
		$on_a  = array();
		$on_b  = array();
		foreach ( $names as $slug => $name ) {
			$on_a[ $slug ] = (int) wp_insert_term( $name, 'category' )['term_id'];
			$on_b[ $slug ] = self::on_b( array( 'op' => 'insert_term', 'name' => $name, 'taxonomy' => 'category' ) );
		}
		self::paired( self::staged() );
		foreach ( $on_b as $slug => $id ) {
			self::b( array( array( 'op' => 'update_term', 'term' => $id, 'taxonomy' => 'category', 'args' => array( 'description' => 'Fresh ' . $slug . '.' ) ) ) );
		}
		$report  = self::report( self::staged() );
		$entries = array_map( static fn( string $slug ): array => self::entry( $report, 'term', 'category', $slug ), array_keys( $names ) );
		$push    = self::forward( self::args( $report, $entries ) );
		$written = self::terms_a( $on_a );
		self::assertSame( array( 'Fresh news.', 'Fresh tips.', 'Fresh events.' ), array_column( array_column( $written, 0 ), 'description' ), 'The forward run wrote the terms, so a way back would show.' );
		// The way back with every writer, interrupted at its first step: that
		// step begun, its request gone (as SyncRunTest leaves a step of the
		// way back), the interruption settled by a status call.
		$r    = (int) self::revert( $push )['run_id'];
		$seqs = array();
		foreach ( AB_MCP_Sync_Run::steps( $r ) as $step ) {
			if ( 'revert' === $step['op'] ) {
				$seqs[] = (int) $step['seq'];
			}
		}
		self::assertTrue( AB_MCP_Sync_Run::update_step( $r, min( $seqs ), array( 'state' => 'begun' ), 'planned' ) );
		$status = self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => $r ) );
		self::assertIsArray( $status, is_wp_error( $status ) ? $status->get_error_message() : '' );
		self::assertSame( 'interrupted', $status['state'], 'The way back is interrupted (the setup, still with every writer).' );
		self::only_post();
		$args = array(
			'action' => 'plan',
			'run_id' => $r,
		);
		$plan = self::gated( 'wp_site_revert', $args );
		self::not_internal( $plan, 'The plan of the continuation.' );
		$led = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $plan['confirm_token'] ) );
		self::not_internal( $led, 'The confirmation of the continuation.' );
		for ( $i = 0; $i < $led['counts']['planned']; $i++ ) {
			$answer = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $r ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		}
		self::assertSame( $written, self::terms_a( $on_a ), 'Nothing of a term put back, the interrupted step as little as the open ones.' );
	}
}
