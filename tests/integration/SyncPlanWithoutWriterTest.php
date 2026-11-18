<?php
/**
 * The rules of N-c of the seam without the registration of a kind (first
 * step T3, I6; D6 of the build sheet): what the core does where the writer
 * of a kind is not registered, here only (after the move, a kind this plugin
 * no longer has). One case for each function of N-c, on two WordPress sites
 * as tests/integration/SyncContentTest.php (B the staging site, A this
 * process):
 *
 * - (a) planned_parents(): a run that updates a term, then term no longer
 *   registered. The check before the first write asks the writer of the
 *   term for its rule planned_parent() before its loop and finds none; the
 *   loop then finds «No step of this version writes the kind term.», the run
 *   is aborted, nothing is written, no error.
 * - (b) plan(): a new post and a new attachment under it (its weak parent),
 *   attachment not registered, all=true: the attachment is named as locked
 *   with «The kind attachment is not written by a run of this version.», not
 *   refused; the post is planned alone, without a parent step and without
 *   parent_later, and the reserve of the backup counts it alone (the bytes of
 *   the plan of the post alone).
 * - (c) order() and (d) term_depths(): a new category, a new page and a new
 *   post, term not registered: the category is locked, the page and the post
 *   keep the order they have beside it with every writer; no depth is set and
 *   plan() ends without an error (the same report).
 *
 * The registrations made as the engine loads are kept before each case and
 * put back after it (reflection), for the other tests of the process.
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
use AB_MCP_Sync_Push;
use AB_MCP_Sync_Report;
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

final class SyncPlanWithoutWriterTest extends TestCase {

	/** The uploads of this class on site B (case (b)), under its WordPress folder. */
	private const UPLOADS = 'wp-content/uploads-ohne-schreiber';

	/** The date every object of these tests carries. */
	private const DATE = '2026-10-01 10:00:00';

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

	/** The protected store of both sites and the uploads of this class on B removed. */
	private static function wipe(): void {
		$root = (string) AB_MCP_IT_Sites::state()['root'];
		self::remove_tree( $root . '/ab-mcp-store', $root );
		self::remove_tree( AB_MCP_IT_Sites::docroot( 'b' ) . '/' . self::UPLOADS, $root );
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

	/** A post or page of B, published. */
	private static function post_b( string $type, string $name ): int {
		$args = array(
			'post_type'     => $type,
			'post_status'   => 'publish',
			'post_title'    => ucfirst( $name ),
			'post_name'     => $name,
			'post_content'  => 'Text.',
			'post_author'   => 1,
			'post_date'     => self::DATE,
			'post_date_gmt' => self::DATE,
		);
		return self::on_b( array( 'op' => 'insert_post', 'args' => $args ) );
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

	/** The writers of this plugin registered again, all but the kind $kind (tearDown() puts the table back). */
	private static function without( string $kind ): void {
		AB_MCP_Sync_Writers::reset();
		foreach ( AB_MCP_Sync_Engine::WRITERS as $other => $class ) {
			if ( $kind !== $other ) {
				self::assertTrue( AB_MCP_Sync_Writers::register( $other, AB_MCP_Sync_Writers::WHOLE, $class ), $other );
			}
		}
		self::assertNull( AB_MCP_Sync_Engine::writer( $kind, '' ) );
	}

	/** The objects of a plan as kind:staging_ref, in the order of its steps. */
	private static function order_of( array $plan ): array {
		return array_map( static fn( $o ) => $o['kind'] . ':' . $o['staging_ref'], $plan['objects'] );
	}

	/* -------------------------------------------------------------- cases */

	public function testACheckWhoseTermHasNoWriterFindsItWithoutAnErrorAndWritesNothing(): void {
		// (a) planned_parents(), run before the loop of the check, asks the
		// writer of the term for its rule; without a writer it asks none.
		self::pair();
		$news_a = (int) wp_insert_term( 'News', 'category' )['term_id'];
		$news_b = self::on_b( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category' ) );
		self::paired( self::staged() );
		self::b( array( array( 'op' => 'update_term', 'term' => $news_b, 'taxonomy' => 'category', 'args' => array( 'description' => 'Fresh news.' ) ) ) );
		$report  = self::report( self::staged() );
		$args    = self::args( $report, array( self::entry( $report, 'term', 'category', 'news' ) ) );
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		$before = AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_id' => (string) $news_a ) );
		self::without( 'term' );
		$answer = null;
		for ( $i = 0; $i < 6 && ! in_array( $answer['state'] ?? null, AB_MCP_Sync_Schema::CLOSED, true ); $i++ ) {
			$answer = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => (int) $run['run_id'] ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		}
		self::assertSame( 'closed_aborted', $answer['state'], json_encode( $answer ) );
		$status = self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => (int) $run['run_id'] ) );
		self::assertIsArray( $status );
		$check = array_values( array_filter( $status['steps'], static fn( $s ) => 'check' === $s['op'] ) )[0];
		self::assertSame( array( 'No step of this version writes the kind term.' ), array_column( $check['result']['findings'], 'message' ) );
		self::assertSame( array(), array_values( array_filter( $status['steps'], static fn( $s ) => 'write' === $s['op'] && 'done' === $s['state'] ) ), 'No write step is done.' );
		self::assertSame( $before, AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_id' => (string) $news_a ) ), 'The term as it was.' );
	}

	public function testAnAttachmentWithoutAWriterIsNamedAsLockedAndThePostAboveItIsPlannedAlone(): void {
		// (b) plan(): the weak parent (N-c no. 3) and the objects of the
		// space of posts the run creates (no. 2) without the attachment.
		self::pair();
		self::paired( self::staged() );
		self::b( array( array( 'op' => 'update_option', 'name' => 'upload_path', 'value' => self::UPLOADS ) ) );
		$post = self::post_b( 'post', 'tips' );
		self::on_b( array( 'op' => 'upload', 'name' => 'bild.jpg', 'mime' => 'image/jpeg', 'image' => array( 'w' => 8, 'h' => 8, 'rgb' => array( 10, 80, 160 ) ), 'args' => array( 'post_title' => 'Bild', 'post_parent' => $post ) ) );
		$report = self::report( self::staged() );
		$entry  = self::entry( $report, 'post', 'post', 'tips' );
		$bild   = self::entry( $report, 'attachment', 'attachment', '2026/10/bild.jpg' );
		$args   = self::args( $report, array( $entry ) );
		$alone  = AB_MCP_Sync_Push::plan( $args );
		self::assertIsArray( $alone, is_wp_error( $alone ) ? $alone->get_error_message() : '' );
		self::without( 'attachment' );
		$all = AB_MCP_Sync_Push::plan( array( 'all' => true ) + array_diff_key( $args, array( 'select' => true ) ) );
		self::assertIsArray( $all, is_wp_error( $all ) ? $all->get_error_message() : '' );
		self::assertSame( array(), $all['locked'] );
		// all=true names what a takeover does not write (here also two
		// options of the site); of the two entries of this case, the
		// attachment alone.
		$named = array_filter( $all['skipped'], static fn( $l ) => in_array( $l['n'], array( (int) $entry['n'], (int) $bild['n'] ), true ) );
		self::assertSame( array( array( (int) $bild['n'], 'attachment', 'The kind attachment is not written by a run of this version.', 'Z8d' ) ), array_values( array_map( static fn( $l ) => array( $l['n'], $l['kind'], $l['reason'], $l['stage'] ), $named ) ), 'Named, not refused.' );
		self::assertSame( array( 'post:' . $entry['staging']['ref'] ), self::order_of( $all ) );
		self::assertArrayNotHasKey( 'parent_later', $all['objects'][0] );
		self::assertArrayNotHasKey( 'refs', $all['objects'][0] );
		self::assertSame( array( 'check', 'write', 'post', 'verify' ), array_column( $all['steps'], 'op' ), 'No parent step.' );
		self::assertSame( $alone['bytes'], $all['bytes'], 'The reserve of the backup counts the post alone.' );
	}

	public function testATermWithoutAWriterIsLockedAndTheOtherObjectsKeepTheirOrderWithoutADepth(): void {
		// (c) order() and (d) term_depths(): the rank of a term (no. 5) and
		// the depths (no. 6 to 8) of a plan that holds no term.
		self::pair();
		self::paired( self::staged() );
		self::on_b( array( 'op' => 'insert_term', 'name' => 'Topics', 'taxonomy' => 'category' ) );
		self::post_b( 'page', 'about' );
		self::post_b( 'post', 'tips' );
		$report  = self::report( self::staged() );
		$topics  = self::entry( $report, 'term', 'category', 'topics' );
		$args    = self::args( $report, array( $topics, self::entry( $report, 'post', 'page', 'about' ), self::entry( $report, 'post', 'post', 'tips' ) ) );
		$every   = AB_MCP_Sync_Push::plan( $args );
		self::assertIsArray( $every, is_wp_error( $every ) ? $every->get_error_message() : '' );
		self::assertSame( 'term:' . $topics['staging']['ref'], self::order_of( $every )[0], 'With its writer the term comes first.' );
		self::without( 'term' );
		$bare = AB_MCP_Sync_Push::plan( $args );
		self::assertIsArray( $bare, is_wp_error( $bare ) ? $bare->get_error_message() : '' );
		self::assertSame( array( array( (int) $topics['n'], 'term', 'The kind term is not written by a run of this version.', 'Z8d' ) ), array_map( static fn( $l ) => array( $l['n'], $l['kind'], $l['reason'], $l['stage'] ), $bare['locked'] ) );
		self::assertSame( array_slice( self::order_of( $every ), 1 ), self::order_of( $bare ), 'The page and the post in the order they have beside the term.' );
		self::assertSame( array(), array_filter( array_map( static fn( $o ) => isset( $o['depth'] ), $bare['objects'] ) ), 'No depth.' );
	}
}
