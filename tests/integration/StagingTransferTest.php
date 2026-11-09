<?php
/**
 * The section «Takeover» of the page «Staging» and transfer in
 * wp_staging_status on two WordPress sites (Z8, decision 35 of the plan,
 * branch 12): site B is the staging site, site A, this process, the
 * production site with B's key confirmed; a person in wp-admin of A is the
 * administrator of A, through AB_MCP_Transfer, the class the forms of the
 * page call behind their nonces.
 *
 * - The normal case: after a report the page shows it (its counts, what a
 *   run can take over), the run with its state, steps, the time its
 *   backups are kept and M2 (steps, calls since the run was made, duration
 *   closed − created); the steps of the run with a form per pair; a pair
 *   taken back as the page showed it goes back byte for byte in a run of
 *   the way back of the person's own, closed as reverted; a form on a plan
 *   that changed since writes nothing.
 * - M3: from the third pair of a run taken back the line, and the notice
 *   only once its feature is published, once.
 * - M1: a report with ten object pairs a run can take over.
 * - A person aborts an open run another connection made (decision 15); the
 *   owner's way stays refused to other connections.
 * - An interrupted run (decision 18): wp_staging_status names its journal,
 *   parts and rest without writing; the page settles it, shows it first
 *   and closes it as checked; the site takes a run again.
 * - A pair discarded as the page showed it; a pair that changed since is
 *   not.
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
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use AB_MCP_Transfer;
use AB_MCP_Transfer_Moments;
use AB_MCP_Transfer_View;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-revert.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';
require_once AB_MCP_DIR . 'includes/class-transfer.php';
require_once AB_MCP_DIR . 'includes/class-transfer-view.php';

final class StagingTransferTest extends TestCase {

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
		delete_option( AB_MCP_Transfer_Moments::OPTION );
		AB_MCP_Transfer_Moments::$published = null;
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
		AB_MCP_Sync_Engine::$probe          = null;
		AB_MCP_Transfer_Moments::$published = null;
		delete_option( \AB_MCP_Change_Lock::OPTION );
		delete_option( AB_MCP_Transfer_Moments::OPTION );
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

	/** The probe of a published object answers as the live page would (decision 33). */
	private static function probe_ok(): void {
		AB_MCP_Sync_Engine::$probe = static function ( string $url ): array {
			$id = url_to_postid( $url );
			return array(
				'status' => 200,
				'body'   => '<html><head><title>' . esc_html( $id > 0 ? (string) get_post_field( 'post_title', $id, 'raw' ) : '' ) . '</title></head></html>',
			);
		};
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

	/** The same pages on both sites, paired by a proposal: name => [ A's id, B's id ]. */
	private static function same_pages( array $pages ): array {
		$out   = array();
		$steps = array();
		foreach ( $pages as $name => $content ) {
			$id = wp_insert_post( wp_slash( self::page( $name, $content ) ), true );
			self::assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
			$out[ $name ] = array( (int) $id, 0 );
			$steps[]      = array(
				'op'   => 'insert_post',
				'args' => self::page( $name, $content ),
				'as'   => $name,
			);
		}
		foreach ( self::b( $steps ) as $name => $id ) {
			$out[ $name ][1] = (int) $id;
		}
		self::paired( self::staged() );
		return $out;
	}

	/** A page of B changed, as wp-admin saves it. */
	private static function change_on_b( int $id, string $name, string $content ): void {
		self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => self::page( $name, $content, array( 'ID' => $id ) ),
				),
			)
		);
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

	/** A tool through the gate of the plugin, in this process. */
	private static function gated( string $name, array $a ) {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return AB_MCP_REST_Controller::run_tool( $name, $r->get( $name ), $a );
	}

	/** Every entry equal of a fresh proposal paired. */
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

	/** wp_site_diff in this process, worked out to its end: every entry. */
	private static function report( array $ids ): array {
		$answer = AB_MCP_Sync_Report::call( array( 'packages' => $ids ) );
		$calls  = 1;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
			self::assertLessThan( 300, ++$calls );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		return $answer;
	}

	/** A run made and confirmed in this process for the pages of these names, and its report. */
	private static function begun( array $names ): array {
		$report = self::report( self::staged() );
		$select = array();
		$refs   = array();
		foreach ( $report['entries'] as $entry ) {
			$name = $entry['staging']['identity']['post_name'] ?? null;
			if ( 'post' === $entry['kind'] && in_array( $name, $names, true ) ) {
				$select[] = (int) $entry['n'];
				$refs[]   = array(
					'kind' => 'post',
					'ref'  => (string) $entry['staging']['ref'],
				);
			}
		}
		self::assertCount( count( $names ), $select );
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
		$args    = array(
			'action'    => 'plan',
			'report_id' => $report['report_id'],
			'select'    => $select,
			'packages'  => array( self::import_package( $package, 'objects', $report['report_hash'] ) ),
		);
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		return array(
			'run_id' => (int) $run['run_id'],
			'report' => $report,
		);
	}

	/** wp_site_push action=next until the run is closed. */
	private static function finish( int $run_id ): array {
		self::probe_ok();
		for ( $i = 0; $i < 12; $i++ ) {
			$answer = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return $answer;
			}
		}
		self::fail( 'The run did not close.' );
	}

	/** The content of a post of A, past every cache. */
	private static function content_a( int $id ): string {
		$rows = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $id ) );
		self::assertCount( 1, $rows );
		return (string) $rows[0]['post_content'];
	}

	/** The write step of a run that wrote the post of A $id. */
	private static function seq_of( int $run_id, int $id ): int {
		foreach ( AB_MCP_Sync_Run::steps( $run_id ) as $step ) {
			if ( 'write' === $step['op'] && (string) $id === (string) ( $step['parts']['object']['live_ref'] ?? '' ) ) {
				return (int) $step['seq'];
			}
		}
		self::fail( "No write step for post $id." );
	}

	/** The entry of the way back the page shows for a step. */
	private static function back_entry( array $view, int $seq ): array {
		foreach ( $view['run']['pairs'] as $entry ) {
			if ( $seq === (int) $entry['seq'] ) {
				return $entry;
			}
		}
		self::fail( "No form for step $seq." );
	}

	/** Every pair of posts the page lists, page by page: live_ref => pair. */
	private static function all_pairs(): array {
		$out   = array();
		$after = 0;
		$pages = 0;
		do {
			$view = AB_MCP_Transfer::view( array( 'pairs_after' => $after ) );
			self::assertLessThanOrEqual( AB_MCP_Transfer::PAIRS, count( $view['pairs']['pairs'] ) );
			foreach ( $view['pairs']['pairs'] as $pair ) {
				if ( 'post' === $pair['kind'] ) {
					$out[ $pair['live_ref'] ] = $pair;
				}
			}
			$after = $view['pairs']['next'];
			self::assertLessThan( 100, ++$pages );
		} while ( null !== $after );
		return $out;
	}

	/* ------------------------------------------------- the normal case */

	public function testAfterAReportThePageShowsTheRunItsStepsAndTakesAPairBackAsThePersonSawIt(): void {
		self::pair();
		$p = self::same_pages( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		self::change_on_b( $p['contact'][1], 'contact', 'Write to us on staging.' );
		self::change_on_b( $p['about'][1], 'about', 'About us, on staging.' );
		$begun  = self::begun( array( 'contact', 'about' ) );
		$run_id = $begun['run_id'];

		// The report, as the page shows it.
		$view = AB_MCP_Transfer::view();
		self::assertSame( 'production', $view['side'] );
		self::assertSame( $begun['report']['report_id'], $view['report']['report_id'], 'The youngest report, made over another way than the page.' );
		self::assertTrue( $view['report']['done'] );
		self::assertSame( 2, $view['report']['takeable'] );
		self::assertSame( 2, $view['report']['actions']['take_over'] );
		self::assertNull( $view['report']['m1'], 'Two: no M1.' );
		self::assertGreaterThan( 0, AB_MCP_Transfer_Moments::state()['first_report'], 'The first report is noted.' );
		self::assertSame( 'outside', $view['store']['where'] );
		self::assertNull( $view['store']['rule'] );
		self::assertSame( $run_id, (int) $view['open']['run_id'] );
		self::assertTrue( $view['runs'][0]['abort'], 'An open run of the type push: abort.' );
		$open = \AB_MCP_Sync_Revert::plan_for_person( array( 'run_id' => $run_id ) );
		self::assertSame( 'ab_mcp_sync_revert_person', $open->get_error_code(), 'A run that is not closed goes back over its own connection, not from the page.' );

		$closed = self::finish( $run_id );
		self::assertSame( 'closed_done', $closed['state'] );
		$stored = AB_MCP_Sync_Run::get( $run_id );
		$view   = AB_MCP_Transfer::view( array( 'run' => $run_id ) );
		$row    = $view['runs'][0];
		self::assertSame( array( $run_id, 'push', 'closed_done' ), array( $row['run_id'], $row['type'], $row['state'] ) );
		self::assertSame( 5, $row['steps'] );
		self::assertSame( 5, $row['counts']['done'] );
		self::assertFalse( $row['abort'] );
		self::assertContains( $row['backups']['state'], array( 'last_three', 'until' ), 'A qualified close: kept as one of the last three.' );
		self::assertSame( sprintf( 'Run %1$d: 5 steps, %2$d calls since the run was made, duration %3$s.', $run_id, (int) $stored['calls'], AB_MCP_Transfer_Moments::duration( (int) $stored['closed'] - (int) $stored['created'] ) ), $row['m2'], 'M2 from the record of the run.' );
		self::assertGreaterThanOrEqual( 6, (int) $stored['calls'], 'Its making and five steps.' );
		self::assertSame( $row['m2'], $view['run']['m2'] );

		// The steps, with a form per pair.
		$contact = self::seq_of( $run_id, $p['contact'][0] );
		$about   = self::seq_of( $run_id, $p['about'][0] );
		$seqs = array( $about, $contact );
		rsort( $seqs );
		self::assertSame( $seqs, array_column( $view['run']['pairs'], 'seq' ), 'From the last step to the first.' );
		$entry = self::back_entry( $view, $contact );
		self::assertSame( 'back', $entry['will'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $entry['plan_state'] );
		$html = AB_MCP_Transfer_View::html( $view );
		self::assertStringContainsString( 'name="state" value="' . $entry['plan_state'] . '"', $html );
		self::assertStringContainsString( 'nonce', $html );

		// A form on a plan that changed since writes nothing.
		update_post_meta( $p['contact'][0], 'note', 'x' );
		delete_post_meta( $p['contact'][0], 'note' );
		$stale = AB_MCP_Transfer::take_back( $run_id, $contact, str_repeat( '0', 64 ), true );
		self::assertInstanceOf( WP_Error::class, $stale );
		self::assertSame( 'ab_mcp_sync_revert_changed', $stale->get_error_code() );
		self::assertSame( 'Write to us on staging.', self::content_a( $p['contact'][0] ) );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'No way back began.' );
		self::assertSame( 'ab_mcp_sync_run_unconfirmed', AB_MCP_Transfer::take_back( $run_id, $contact, $entry['plan_state'], false )->get_error_code(), 'Only behind the nonce.' );

		// The pair as the page showed it: back, byte for byte.
		$done = AB_MCP_Transfer::take_back( $run_id, $contact, $entry['plan_state'], true );
		self::assertIsString( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertStringStartsWith( 'Taken back', $done );
		self::assertSame( 'Write to us.', self::content_a( $p['contact'][0] ) );
		self::assertSame( 'About us, on staging.', self::content_a( $p['about'][0] ), 'The other pair stays.' );
		$reverts = AB_MCP_Sync_Run::reverts_of( $run_id );
		self::assertCount( 1, $reverts );
		self::assertSame( array( 'revert', 'closed_reverted', self::admin() ), array( $reverts[0]['type'], $reverts[0]['state'], (int) $reverts[0]['user_id'] ) );
		self::assertNull( AB_MCP_Sync_Run::owner_refusal( $reverts[0] ), 'The run of the way back is the person\'s, over wp-admin.' );
		self::assertNull( AB_MCP_Sync_Run::open_run() );

		$view = AB_MCP_Transfer::view( array( 'run' => $run_id ) );
		self::assertSame( 1, $view['run']['back'] );
		self::assertSame( array( $about ), array_column( $view['run']['pairs'], 'seq' ), 'A step goes back once.' );
		self::assertSame( array( 'revert', 'closed_reverted' ), array( $view['runs'][0]['type'], $view['runs'][0]['state'] ), 'The run of the way back first in the list.' );
		self::assertFalse( $view['runs'][0]['go_on'], 'Closed: nothing to go on with.' );
		self::assertDoesNotMatchRegularExpression( '/\bPro\b|USD|trial/', AB_MCP_Transfer_View::html( $view ), 'Nothing published: no edition part.' );

		// A way back the person began and did not finish (its request ended
		// after the begin): the list offers to go on with it; nobody aborts it.
		$entry = self::back_entry( $view, $about );
		$left  = \AB_MCP_Sync_Revert::begin_for_person( array( 'run_id' => $run_id, 'steps' => array( $about ) ), $entry['plan_state'], true );
		self::assertIsArray( $left, is_wp_error( $left ) ? $left->get_error_message() : '' );
		$view = AB_MCP_Transfer::view( array( 'run' => $run_id ) );
		self::assertSame( array( (int) $left['run_id'], 'revert', 'open' ), array( $view['runs'][0]['run_id'], $view['runs'][0]['type'], $view['runs'][0]['state'] ) );
		self::assertTrue( $view['runs'][0]['go_on'] );
		self::assertFalse( $view['runs'][0]['abort'], 'A run of the way back runs to its end.' );
		self::assertSame( 'ab_mcp_sync_run_state', AB_MCP_Transfer::abort( (int) $left['run_id'] )->get_error_code() );
		self::assertSame( 1, $view['run']['back'], 'A step of the way back still planned has taken nothing back.' );
		self::assertStringContainsString( 'value="ab_mcp_transfer_go_on"', AB_MCP_Transfer_View::html( $view ) );
		$done = AB_MCP_Transfer::go_on( (int) $left['run_id'] );
		self::assertIsString( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertStringStartsWith( 'Taken back', $done );
		self::assertSame( 'About us.', self::content_a( $p['about'][0] ) );
		self::assertSame( 2, AB_MCP_Transfer::view( array( 'run' => $run_id ) )['run']['back'] );
	}

	public function testFromTheThirdPairOfARunTakenBackM3AndItsNoticeOnlyOncePublished(): void {
		self::pair();
		$p = self::same_pages( array( 'one' => 'One.', 'two' => 'Two.', 'three' => 'Three.' ) );
		foreach ( $p as $name => $ids ) {
			self::change_on_b( $ids[1], $name, ucfirst( $name ) . ' on staging.' );
		}
		$run_id = self::begun( array( 'one', 'two', 'three' ) )['run_id'];
		self::finish( $run_id );
		foreach ( array( 'one', 'two', 'three' ) as $i => $name ) {
			$seq  = self::seq_of( $run_id, $p[ $name ][0] );
			$view = AB_MCP_Transfer::view( array( 'run' => $run_id ) );
			self::assertSame( $i, $view['run']['back'] );
			self::assertNull( $view['run']['m3'], 'Before the third pair.' );
			self::assertIsString( AB_MCP_Transfer::take_back( $run_id, $seq, self::back_entry( $view, $seq )['plan_state'], true ) );
		}
		$view = AB_MCP_Transfer::view( array( 'run' => $run_id ) );
		self::assertSame( 3, $view['run']['back'] );
		self::assertSame( sprintf( 'You are taking run %d back pair by pair.', $run_id ), $view['run']['m3'] );
		self::assertNull( $view['notice'], 'Its feature is not published: the quiet line only.' );
		self::assertStringContainsString( $view['run']['m3'], AB_MCP_Transfer_View::html( $view ) );

		AB_MCP_Transfer_Moments::$published = array( 'run_revert' );
		$view                               = AB_MCP_Transfer::view( array( 'run' => $run_id ) );
		self::assertSame( 'm3', $view['notice']['moment'] );
		self::assertStringContainsString( 'Pro takes the supported changes of a run back together', AB_MCP_Transfer_View::html( $view ) );
		self::assertNull( AB_MCP_Transfer::view( array( 'run' => $run_id ) )['notice'], 'Once per site.' );
		add_filter( 'ab_mcp_site_edition', static fn() => 'pro' );
		delete_option( AB_MCP_Transfer_Moments::OPTION );
		AB_MCP_Transfer_Moments::report_seen( 1 );
		self::assertNull( AB_MCP_Transfer::view( array( 'run' => $run_id ) )['notice'], 'Not in the edition that has it.' );
		remove_all_filters( 'ab_mcp_site_edition' );
	}

	public function testAReportWithTenObjectPairsARunCanTakeOverIsM1(): void {
		self::pair();
		$steps = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$steps[] = array(
				'op'   => 'insert_post',
				'args' => self::page( 'new-' . $i, 'Only on staging ' . $i . '.' ),
			);
		}
		self::b( $steps );
		$report = self::report( self::staged() );
		self::assertSame( 10, $report['counts']['actions']['create'] );
		$view = AB_MCP_Transfer::view();
		self::assertSame( 10, $view['report']['takeable'] );
		self::assertSame( '10 changes ready.', $view['report']['m1'] );
		self::assertNull( $view['notice'] );
		self::assertStringContainsString( '10 changes ready.', AB_MCP_Transfer_View::html( $view ) );
		self::assertGreaterThan( 0, AB_MCP_Transfer_Moments::state()['first_report'], 'The first report seen, without a run.' );
		AB_MCP_Transfer_Moments::$published = array( 'site_sync' );
		$view                               = AB_MCP_Transfer::view();
		self::assertSame( 'm1', $view['notice']['moment'], 'Once its feature is published, the notice.' );
		self::assertSame( '10 changes ready.', $view['notice']['text'] );
		self::assertStringEndsWith( 'You can take all 10 over one by one now.', $view['notice']['edition']['text'] );
		self::assertNull( AB_MCP_Transfer::view()['notice'], 'Once per site.' );
	}

	/* ------------------------------------------------ abort, interrupted */

	public function testAPersonAbortsAnOpenRunOfAnotherConnectionAndClosesAnInterruptedRunAsChecked(): void {
		global $wpdb;
		self::pair();
		$p = self::same_pages( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		self::change_on_b( $p['contact'][1], 'contact', 'Write to us on staging.' );
		self::change_on_b( $p['about'][1], 'about', 'About us, on staging.' );
		$run_id = self::begun( array( 'contact', 'about' ) )['run_id'];
		// Another connection made it: its owner's way is refused here.
		$wpdb->update( AB_MCP_Sync_Schema::table( 'runs' ), array( 'connection' => hash( 'sha256', 'another connection' ) ), array( 'run_id' => $run_id ) );
		self::assertSame( 'ab_mcp_sync_run_foreign', self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) )->get_error_code() );
		$view = AB_MCP_Transfer::view();
		self::assertTrue( $view['runs'][0]['abort'] );
		$done = AB_MCP_Transfer::abort( $run_id );
		self::assertIsString( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertStringContainsString( 'closed as aborted', $done );
		self::assertSame( 'closed_aborted', AB_MCP_Sync_Run::get( $run_id )['state'] );
		self::assertSame( 'ab_mcp_sync_run_state', AB_MCP_Transfer::abort( $run_id )->get_error_code(), 'A closed run is not aborted.' );

		// An interrupted run: the check done, the first write step begun by a
		// request that ended (no lock).
		$run_id = self::begun( array( 'contact', 'about' ) )['run_id'];
		self::assertSame( 'done', self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) )['step']['state'] );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 2, array( 'state' => 'begun' ), 'planned' ) );
		self::assertFalse( AB_MCP_Sync_Run::lock_fresh() );

		// wp_staging_status names it, and writes nothing.
		$before = AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_runs', array( 'run_id' => (string) $run_id ) );
		$status = AB_MCP_Staging::report( false )['transfer'];
		self::assertSame( AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_runs', array( 'run_id' => (string) $run_id ) ), $before, 'The tool reads only.' );
		self::assertSame( 'open', $status['run']['state'], 'Not settled by a reading tool.' );
		self::assertSame( 2, $status['run']['begun'] );
		self::assertStringContainsString( 'marks it interrupted', $status['run']['next'] );
		self::assertSame( array( 1, 2, 3, 4, 5 ), array_column( $status['run']['journal'], 'seq' ), 'The journal.' );
		self::assertSame( 'begun', $status['run']['journal'][1]['state'] );
		self::assertSame( array( 3, 4, 5 ), array_column( $status['run']['rest'], 'seq' ), 'The rest, named.' );
		self::assertSame( 'post', $status['run']['rest'][0]['object']['kind'] );
		self::assertSame( array( 'run_id', 'type', 'state', 'created', 'calls', 'counts', 'next', 'begun', 'journal', 'rest', 'rests', 'unclear' ), array_keys( $status['run'] ) );

		// The page settles it and shows it first.
		$view = AB_MCP_Transfer::view();
		self::assertSame( 'interrupted', $view['open']['state'] );
		self::assertSame( $run_id, (int) $view['interrupted']['run']['run_id'] );
		self::assertSame( array( 3, 4, 5 ), array_column( $view['interrupted']['rest'], 'seq' ) );
		$html = AB_MCP_Transfer_View::html( $view );
		self::assertStringContainsString( 'value="ab_mcp_transfer_checked"', $html );
		self::assertStringContainsString( sprintf( 'Run %d is interrupted', $run_id ), AB_MCP_Transfer_View::interrupted_line( $view['open'] ) );
		$status = AB_MCP_Staging::report( false )['transfer'];
		self::assertSame( 'interrupted', $status['run']['state'] );
		self::assertArrayHasKey( 'journal', $status['run'] );
		self::assertStringContainsString( 'closes it as checked', $status['run']['next'] );

		self::assertSame( 'ab_mcp_sync_run_unconfirmed', AB_MCP_Transfer::close_checked( $run_id, $view['interrupted']['print'], false )->get_error_code() );
		$done = AB_MCP_Transfer::close_checked( $run_id, $view['interrupted']['print'], true );
		self::assertIsString( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertSame( 'closed_checked', AB_MCP_Sync_Run::get( $run_id )['state'] );
		self::assertSame( 'unclear', AB_MCP_Sync_Run::step( $run_id, 2 )['state'] );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site takes a run again.' );
		self::assertNull( AB_MCP_Staging::report( false )['transfer']['run'] );

		// A site with runs has seen a report, also once its reports are gone.
		delete_option( AB_MCP_Transfer_Moments::OPTION );
		foreach ( AB_MCP_Protected_Store::groups( 'reports' ) as $group ) {
			AB_MCP_Protected_Store::remove( 'reports', $group['gid'] );
		}
		self::assertNull( AB_MCP_Transfer::view()['report'] );
		self::assertGreaterThan( 0, AB_MCP_Transfer_Moments::state()['first_report'] );
	}

	/* ----------------------------------------------------------- pairs */

	public function testAPairIsDiscardedAsThePageShowedItAndNotOnceItChanged(): void {
		self::pair();
		$p     = self::same_pages( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		$pairs = self::all_pairs();
		self::assertCount( 2, $pairs, 'The two pages, among the pairs of every kind.' );
		self::assertNotNull( AB_MCP_Transfer::view()['pairs']['next'], 'Every object of the site is paired: the list goes in pages.' );
		$contact = $pairs[ (string) $p['contact'][0] ];
		$about   = $pairs[ (string) $p['about'][0] ];
		self::assertSame( array( 'post', 'active' ), array( $contact['kind'], $contact['state'] ) );
		self::assertSame( (string) $p['contact'][1], $contact['staging_ref'] );

		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		AB_MCP_Sync_Pairs::set_state( $about['id'], 'unclear', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $about['id'] ) ) );
		self::assertSame( 'ab_mcp_sync_pairs_changed', AB_MCP_Transfer::discard( $about['id'], $about['discard'] )->get_error_code(), 'It changed since the page was loaded.' );
		self::assertIsArray( AB_MCP_Sync_Pairs::get( $about['id'] ) );

		$done = AB_MCP_Transfer::discard( $contact['id'], $contact['discard'] );
		self::assertIsString( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertNull( AB_MCP_Sync_Pairs::get( $contact['id'] ) );
		self::assertNull( AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $p['contact'][0] ) );
		self::assertSame( 'Write to us.', self::content_a( $p['contact'][0] ), 'Its objects stay.' );
		$after = self::all_pairs();
		self::assertArrayNotHasKey( (string) $p['contact'][0], $after );
		self::assertArrayHasKey( (string) $p['about'][0], $after );
		self::assertCount( count( $pairs ) - 1, $after );
	}

	/* ------------------------------------- Codex round 1 of branch 12 */

	/** The way back of a run planned and confirmed in this process. */
	private static function revert_begun( int $run_id ): array {
		$args    = array( 'action' => 'plan', 'run_id' => $run_id );
		$preview = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$begun = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
		return $begun;
	}

	/** A run with its check and its first object done and its second write step left begun by a request that ended. */
	private static function interrupted_run(): int {
		self::pair();
		$p = self::same_pages( array( 'contact' => 'Write to us.', 'about' => 'About us.' ) );
		self::change_on_b( $p['contact'][1], 'contact', 'Write to us on staging.' );
		self::change_on_b( $p['about'][1], 'about', 'About us, on staging.' );
		$run_id = self::begun( array( 'contact', 'about' ) )['run_id'];
		self::assertSame( 'done', self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) )['step']['state'], 'The check.' );
		self::assertSame( 'done', self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) )['step']['state'], 'The first object written.' );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 3, array( 'state' => 'begun' ), 'planned' ) );
		return $run_id;
	}

	public function testCloseAsCheckedClosesOnlyTheInterruptionThePageShowed(): void {
		// A: the page shows the forward run interrupted; then its way back
		// begins and is interrupted in turn. The old form closes nothing.
		$run_id = self::interrupted_run();
		$first  = AB_MCP_Transfer::view()['interrupted']['print'];
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $first );
		self::assertSame( $first, AB_MCP_Transfer::view()['interrupted']['print'], 'The same interruption, the same print.' );
		self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) );
		self::assertSame( $first, AB_MCP_Transfer::view()['interrupted']['print'], 'A status asked in between changes nothing of it.' );

		self::assertSame( 'reverting', self::revert_begun( $run_id )['state'], 'Way (b): the way back of the same run.' );
		$back = null;
		foreach ( AB_MCP_Sync_Run::steps( $run_id ) as $step ) {
			if ( 'revert' === $step['op'] && 'planned' === $step['state'] ) {
				$back = (int) $step['seq'];
			}
		}
		self::assertNotNull( $back );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, $back, array( 'state' => 'begun' ), 'planned' ) );
		$view = AB_MCP_Transfer::view();
		self::assertSame( 'interrupted', $view['open']['state'], 'Interrupted again, on its way back.' );
		$second = $view['interrupted']['print'];
		self::assertNotSame( $first, $second );

		$stale = AB_MCP_Transfer::close_checked( $run_id, $first, true );
		self::assertInstanceOf( WP_Error::class, $stale );
		self::assertSame( 'ab_mcp_sync_run_changed', $stale->get_error_code() );
		self::assertStringContainsString( 'AlphaBridge → Staging', $stale->get_error_message(), 'With the way.' );
		self::assertSame( 'interrupted', AB_MCP_Sync_Run::get( $run_id )['state'], 'Nothing was closed.' );
		self::assertSame( 'begun', AB_MCP_Sync_Run::step( $run_id, $back )['state'] );

		$done = AB_MCP_Transfer::close_checked( $run_id, $second, true );
		self::assertIsString( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertSame( 'closed_checked', AB_MCP_Sync_Run::get( $run_id )['state'] );
	}

	public function testADiscardDeletesOnlyThePairAsThePageShowedIt(): void {
		// A: the pair changes after the page compared it; nothing of the newer
		// state is deleted. The change comes with the first read of the pairs
		// after the state of the page (state()) was taken.
		self::pair();
		$p       = self::same_pages( array( 'contact' => 'Write to us.' ) );
		$contact = self::all_pairs()[ (string) $p['contact'][0] ];
		$id      = (int) $contact['id'];
		$table   = AB_MCP_Sync_Schema::table( 'pairs' );
		$fired   = false;
		$filter  = static function ( $query ) use ( &$fired, $id, $table ) {
			if ( $fired || false === strpos( (string) $query, $table ) ) {
				return $query;
			}
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
				if ( 'state' === ( $frame['function'] ?? '' ) && \AB_MCP_Sync_Pairs_Tool::class === ( $frame['class'] ?? '' ) ) {
					return $query;
				}
			}
			$fired = true;
			AB_MCP_Sync_Pairs::set_state( $id, 'unclear', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $id ) ) );
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$done = AB_MCP_Transfer::discard( $id, $contact['discard'] );
		} finally {
			remove_filter( 'query', $filter );
		}
		self::assertTrue( $fired, 'A run changed the pair in between.' );
		self::assertInstanceOf( WP_Error::class, $done, is_string( $done ) ? $done : '' );
		self::assertSame( 'ab_mcp_sync_pairs_changed', $done->get_error_code() );
		self::assertStringContainsString( 'a pair changed since the page was loaded', $done->get_error_message() );
		$pair = AB_MCP_Sync_Pairs::get( $id );
		self::assertIsArray( $pair, 'Not deleted.' );
		self::assertSame( 'unclear', $pair['state'] );
	}

	public function testADiscardWaitsForAStepOfARunBeingWritten(): void {
		// A: under the lock of the write boundary, as a step of a run.
		self::pair();
		$p       = self::same_pages( array( 'contact' => 'Write to us.' ) );
		$contact = self::all_pairs()[ (string) $p['contact'][0] ];
		update_option( \AB_MCP_Change_Lock::OPTION, time(), false );
		$busy = AB_MCP_Transfer::discard( (int) $contact['id'], $contact['discard'] );
		delete_option( \AB_MCP_Change_Lock::OPTION );
		self::assertInstanceOf( WP_Error::class, $busy );
		self::assertSame( 'ab_mcp_sync_run_busy', $busy->get_error_code() );
		self::assertIsArray( AB_MCP_Sync_Pairs::get( (int) $contact['id'] ) );
		self::assertIsString( AB_MCP_Transfer::discard( (int) $contact['id'], $contact['discard'] ) );
	}

	public function testWithoutTheKeyTheRunsStayOnThePageAndInTheStatus(): void {
		// B: the anchor goes while a run is interrupted.
		$run_id = self::interrupted_run();
		self::assertTrue( AB_MCP_Sync_Anchor::forget() );
		self::assertNull( AB_MCP_Sync_Anchor::active() );
		$view = AB_MCP_Transfer::view( array( 'run' => $run_id ) );
		self::assertFalse( $view['anchored'] );
		self::assertSame( $run_id, (int) $view['interrupted']['run']['run_id'], 'The interrupted run with its journal.' );
		self::assertSame( $run_id, (int) $view['runs'][0]['run_id'], 'The runs.' );
		self::assertNull( $view['report'] );
		self::assertNull( $view['pairs'] );
		self::assertStringContainsString( 'need the confirmed key of the staging site', $view['run']['no_back'] . ' ' . AB_MCP_Transfer_View::html( $view ) );
		self::assertStringContainsString( 'value="ab_mcp_transfer_checked"', AB_MCP_Transfer_View::html( $view ) );
		$status = AB_MCP_Staging::report( false )['transfer'];
		self::assertSame( $run_id, $status['run']['run_id'], 'wp_staging_status names it too.' );
		self::assertArrayHasKey( 'journal', $status['run'] );
		$done = AB_MCP_Transfer::close_checked( $run_id, $view['interrupted']['print'], true );
		self::assertIsString( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
	}

	public function testWithoutThePairTheRunsStayOnThePageAndInTheStatus(): void {
		// B: the pair removed while a run is interrupted.
		$run_id = self::interrupted_run();
		AB_MCP_Staging::save( 'production', '', null, self::admin(), 'form' );
		$view = AB_MCP_Transfer::view();
		self::assertSame( 'none', $view['side'] );
		self::assertSame( $run_id, (int) $view['interrupted']['run']['run_id'] );
		self::assertSame( $run_id, AB_MCP_Staging::report( false )['transfer']['run']['run_id'] );
		self::assertIsString( AB_MCP_Transfer::close_checked( $run_id, $view['interrupted']['print'], true ) );
	}

	public function testTheRunThatWroteStaysInViewBehindTenNewerRunsOfTheWayBack(): void {
		// B: ten newer runs push the run that wrote off the first page.
		self::pair();
		$p = self::same_pages( array( 'contact' => 'Write to us.' ) );
		self::change_on_b( $p['contact'][1], 'contact', 'Write to us on staging.' );
		$push = self::begun( array( 'contact' ) )['run_id'];
		self::finish( $push );
		$anchor = AB_MCP_Sync_Anchor::active();
		for ( $i = 0; $i < AB_MCP_Transfer::RUNS; $i++ ) {
			$made = AB_MCP_Sync_Run::create(
				array(
					'type'        => 'revert',
					'source_id'   => (string) $anchor['source_id'],
					'key_id'      => (string) $anchor['key_id'],
					'pair_print'  => (string) AB_MCP_Sync_Anchor::pair_print(),
					'report_hash' => str_repeat( 'a', 64 ),
					'plan_hash'   => hash( 'sha256', 'way back ' . $i ),
					'user_id'     => self::admin(),
					'connection'  => AB_MCP_Sync_Run::connection(),
					'expires'     => time() + 3600,
					'budget'      => array( 'bytes' => 0 ),
					'plan'        => array( 'reverts' => array(), 'items' => array() ),
				),
				array(
					array(
						'op'      => 'check',
						'phase'   => 0,
						'depends' => array(),
					),
				)
			);
			self::assertIsArray( $made, is_wp_error( $made ) ? $made->get_error_message() : '' );
			self::assertTrue( AB_MCP_Sync_Run::set_state( (int) $made['run_id'], 'open', 'closed_reverted' ) );
		}
		$view = AB_MCP_Transfer::view();
		self::assertCount( AB_MCP_Transfer::RUNS, $view['runs'] );
		self::assertNotContains( $push, array_column( $view['runs'], 'run_id' ), 'Off the first page.' );
		self::assertSame( array( $push ), array_column( $view['pinned'], 'run_id' ), 'Kept in view: its backups are kept and its pair can go back.' );
		self::assertNotNull( $view['runs_next'] );
		$older = AB_MCP_Transfer::view( array( 'runs_after' => $view['runs_next'] ) );
		self::assertSame( array( $push ), array_column( $older['runs'], 'run_id' ), 'The next page.' );
		self::assertSame( array(), $older['pinned'], 'Not twice.' );

		// Taken back: nothing left to act on.
		$entry = self::back_entry( AB_MCP_Transfer::view( array( 'run' => $push ) ), self::seq_of( $push, $p['contact'][0] ) );
		self::assertIsString( AB_MCP_Transfer::take_back( $push, (int) $entry['seq'], $entry['plan_state'], true ) );
		self::assertSame( array(), AB_MCP_Transfer::view()['pinned'] );
	}
}
