<?php
/**
 * A run of the code (Z8b branch 5; plan E5, E8, E19, 3.1 Z24, Z30, Z41, Z42)
 * over the public way: two sites alike, their units paired through
 * wp_site_pairs (no pair put there by a test, F55), a plugin updated on the
 * staging site, the report, the plan of wp_site_push with its token, and
 * the steps of the run one call at a time.
 *
 * This part: the plan of a run of the code and its step «bereitstellen»
 * (AB_MCP_Sync_Code_Plan, AB_MCP_Sync_Code_Ops), until the unit is staged
 * and ready; nothing of the site changes on the way.
 *
 * Effects to the outside of this part and their fault kinds (plan 8.3):
 * - rows (the run, its steps and their journal; the options active_plugins,
 *   stylesheet and template read): a journal, a plan, a run or a row of an
 *   option not of its form (read error, half written) does nothing or counts
 *   as code that runs; a step whose save fails stays planned;
 * - files (the stage in the protected store, the packages bound to the
 *   run): a package that is gone binds nothing and leaves no run open;
 * - HTTP (wordpress.org, through the stand-in of the stage): a download that
 *   fails fails the step, the swap is skipped.
 * Exit/fatal and the time limit of a step that stages: the step is still
 * planned and without a journal, the next call stages anew (nothing of the
 * site changed); the stage of branch 4 holds its own cases (CodeStageTest).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Code;
use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Protected_Store;
use AB_MCP_REST_Controller;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Code_Ops;
use AB_MCP_Sync_Code_Plan;
use AB_MCP_Sync_Code_Stage;
use AB_MCP_Sync_Code_Stage_Calls;
use AB_MCP_Sync_Code_Swap;
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Pairs_Tool;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use AB_MCP_Test_Wporg;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-code-stage.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';
require_once __DIR__ . '/support/class-test-wporg.php';

final class SyncCodeRunTest extends TestCase {

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
		AB_MCP_IT_Code::start();
		self::pair();
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Sync_Code_Swap::forget();
		AB_MCP_Sync_Code_Stage::$clock = null;
		AB_MCP_Sync_Run::$clock        = null;
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_IT_Code::clear();
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

	private static function root(): string {
		return (string) getenv( 'AB_IT_ROOT' );
	}

	private static function wipe(): void {
		foreach ( glob( self::root() . '/ab-mcp-store*' ) ?: array() as $path ) {
			self::remove_tree( $path );
		}
	}

	private static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) || 0 !== strpos( realpath( $path ), realpath( self::root() ) . '/ab-mcp-store' ) ) {
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove_tree( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}

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

	/** A package B makes with these arguments, taken over by A over the relay: its id. */
	private static function relayed( array $export ): array {
		$package = self::b( array( array( 'op' => 'export', 'as' => 'package' ) + $export ) )['package'];
		self::assertIsArray( $package );
		self::assertArrayHasKey( 'package_id', $package, var_export( $package, true ) );
		$state = AB_MCP_Sync_Import::call(
			array(
				'package_id' => $package['package_id'],
				'manifest'   => $package['manifest'],
				'purpose'    => $export['purpose'],
			) + ( isset( $export['report_hash'] ) ? array( 'report_hash' => $export['report_hash'] ) : array() )
		);
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
		return array( $package['package_id'], $package );
	}

	/** One purpose of B (a series), taken over by A: the package ids. */
	private static function staged( string $purpose ): array {
		$ids    = array();
		$cursor = null;
		do {
			list( $id, $package ) = self::relayed( array( 'purpose' => $purpose ) + ( null === $cursor ? array() : array( 'cursor' => $cursor ) ) );
			$cursor               = $package['cursor'];
			$ids[]                = $id;
		} while ( null !== $cursor && count( $ids ) < 50 );
		return $ids;
	}

	/** The exports of B before removed (an account keeps three open exports at most). */
	private static function clear_b(): void {
		self::b( array( array( 'op' => 'store_cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) ) );
	}

	private static function def( string $name ): array {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return $r->get( $name );
	}

	/** A tool through the gate of the plugin, in this process. */
	private static function gated( string $name, array $a ) {
		return AB_MCP_REST_Controller::run_tool( $name, self::def( $name ), $a );
	}

	private static function refused( $result, string $code ): string {
		self::assertInstanceOf( WP_Error::class, $result, is_array( $result ) ? (string) json_encode( $result ) : gettype( $result ) );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result->get_error_message();
	}

	/** Every unit that is equal on both sites paired, the public way: proposal, then confirmation with its token. */
	private static function paired(): void {
		self::clear_b();
		$answer = AB_MCP_Sync_Pairs_Tool::call(
			array(
				'action'      => 'propose',
				'packages'    => self::staged( 'inventory' ),
				'environment' => self::staged( 'env' ),
			)
		);
		$calls  = 0;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = AB_MCP_Sync_Pairs_Tool::call( array( 'action' => 'propose', 'proposal_id' => $answer['proposal_id'] ) );
			self::assertLessThan( 300, ++$calls );
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

	/** A report of both sites with the environment, worked out to its end: the answer, and its entries of the environment by subtype:ref. */
	private static function report(): array {
		self::clear_b();
		$env    = self::staged( 'env' );
		$answer = AB_MCP_Sync_Report::call(
			array(
				'packages'    => self::staged( 'inventory' ),
				'environment' => $env,
			)
		);
		$calls  = 0;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
			self::assertLessThan( 300, ++$calls );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$all  = $answer['entries'];
		$page = $answer;
		while ( null !== $page['cursor'] ) {
			$page = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'], 'cursor' => $page['cursor'] ) );
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
		}
		$units = array();
		foreach ( $all as $entry ) {
			if ( 'environment' === $entry['kind'] && 'more_paths' !== $entry['result'] ) {
				$units[ $entry['subtype'] . ':' . $entry['ref'] ] = $entry;
			}
		}
		return array( $answer, $units, $env, $all );
	}

	/** The stand-in of wordpress.org for the downloads of the stage, in the seam of the stage. */
	private static function wporg( ?array $offer = null ): SyncCodeRunStageCalls {
		$calls = new SyncCodeRunStageCalls( new AB_MCP_Test_Wporg( $offer ) );
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Code_Stage::class, 'naht_sync_code_stage', $calls );
		return $calls;
	}

	/** The files of a plugin of A by their SHA-256. */
	private static function live_bytes( string $slug ): array {
		$out  = array();
		$path = AB_MCP_IT_Code::path( 'a', 'plugin', $slug );
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$out[ substr( $file->getPathname(), strlen( $path ) ) ] = hash_file( 'sha256', $file->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	/** wp_site_push action=next, in this process. */
	private static function next( int $run_id ) {
		return self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
	}

	/** The step of an answer or of a status by its number. */
	private static function step( int $run_id, int $seq ): array {
		$step = AB_MCP_Sync_Run::step( $run_id, $seq );
		self::assertIsArray( $step );
		return $step;
	}

	/** The units paired, a plugin of B put to a version, and the report of it: the plan arguments for its entry, the entry, the packages of the environment. */
	private static function changed( string $slug, string $version, array $more = array() ): array {
		self::paired();
		AB_MCP_IT_Code::put( 'b', 'plugin', $slug, $version );
		list( $report, $units, $env ) = self::report();
		self::assertArrayHasKey( 'plugin:' . $slug, $units );
		$entry = $units[ 'plugin:' . $slug ];
		return array(
			array(
				'action'    => 'plan',
				'report_id' => $report['report_id'],
				'select'    => array( (int) $entry['n'] ),
			) + $more,
			$entry,
			$env,
			$units,
			$report,
		);
	}

	/** A plan confirmed in this process: the preview and the run. */
	private static function begun( array $args ): array {
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
		self::assertTrue( $preview['confirm_required'] );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_code() . ': ' . $run->get_error_message() : '' );
		self::assertSame( 'open', $run['state'] );
		return array( $preview, $run );
	}

	/* -------------------------------------------------------- normal case */

	public function testAnInactivePluginUpdatedOnStagingIsPlannedAloneStagedOverCallsAndReadyWithoutAChangeOfTheSite(): void {
		// F1 and F55, the part until «ready»: the public way to the plan, a
		// run of the code with its two steps for the unit, the unit staged
		// from wordpress.org and checked over calls. Nothing of the site
		// changes; an inactive plugin takes no release.
		list( $args, $entry, $env ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		self::assertSame( array( 'changed_on_staging', 'take_over' ), array( $entry['result'], $entry['action'] ), 'The report: its files changed on staging, unchanged here.' );
		$n      = (int) $entry['n'];
		$before = self::live_bytes( 'ab-it-inaktiv' );
		$calls  = self::wporg();

		list( $preview, $run ) = self::begun( $args );
		$shown = $preview['preview'];
		self::assertSame( array( 'check', 'write', 'write', 'post', 'verify' ), array_column( $shown['steps'], 'op' ) );
		self::assertSame( array( 1, 2, 2, 7, 8 ), array_column( $shown['steps'], 'phase' ), 'The steps of the code are of phase 2; no step of the contents (phase 5).' );
		self::assertSame( array( 'entry' => $n, 'kind' => 'plugin', 'ref' => 'ab-it-inaktiv', 'op' => 'stage' ), $shown['steps'][1]['unit'] );
		self::assertSame( array( 'entry' => $n, 'kind' => 'plugin', 'ref' => 'ab-it-inaktiv', 'op' => 'swap' ), $shown['steps'][2]['unit'] );
		self::assertSame( array( 1, 2 ), $shown['steps'][2]['depends'], 'The swap after its stage.' );
		self::assertSame( 0, $shown['objects'] );
		self::assertSame( array(), $shown['locked'] );
		// Z42: the operation, the versions, the sentence on the routines, the
		// source and what the stage needs.
		self::assertCount( 1, $shown['units'] );
		$unit = $shown['units'][0];
		self::assertSame( array( $n, 'plugin', 'ab-it-inaktiv', 'swap', false, false ), array( $unit['entry'], $unit['kind'], $unit['ref'], $unit['operation'], $unit['runs'], $unit['accept'] ) );
		self::assertSame( array( 'here' => '1.0.0', 'staging' => '1.1.0' ), $unit['version'] );
		self::assertSame( array( 2, 3 ), array( $unit['stage'], $unit['swap'] ) );
		self::assertSame( 'wordpress.org', $unit['source'] );
		self::assertGreaterThan( 0, $unit['need']['files'] );
		self::assertSame( $unit['need']['files'], count( $unit['need']['paths'] ) );
		self::assertStringContainsString( 'Files come back through the way back', $unit['says'] );
		self::assertStringContainsString( 'Makes a run of the code', $shown['effect'] );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Making the run changes nothing.' );
		self::assertSame( array(), $calls->wporg->calls, 'And fetches nothing.' );

		// The run: its plan holds the unit for each step, and the packages of
		// the environment of the report are bound to it (V20).
		$run_id = (int) $run['run_id'];
		$stored = AB_MCP_Sync_Run::get( $run_id );
		self::assertSame( array(), $stored['plan']['objects'] );
		self::assertSame( $env, $stored['plan']['code']['env'] );
		self::assertSame( array( array( 2, 'stage', null ), array( 3, 'swap', 2 ) ), array_map( static fn( $u ) => array( $u['seq'], $u['op'], $u['stage_seq'] ), $stored['plan']['code']['units'] ) );
		self::assertArrayNotHasKey( 'code_shown', $stored['plan'], 'What only the preview shows is not stored.' );
		$bound = array();
		foreach ( AB_MCP_Protected_Store::groups( 'in' ) as $group ) {
			$bound[ substr( (string) $group['label'], strlen( AB_MCP_Sync_Import::LABEL ) ) ] = (int) $group['run_id'];
		}
		foreach ( $env as $id ) {
			self::assertSame( $run_id, $bound[ $id ], 'A package of the environment is kept for the run.' );
		}

		// Step 1: the check of the run.
		$check = self::next( $run_id );
		self::assertIsArray( $check, is_wp_error( $check ) ? $check->get_error_message() : '' );
		self::assertSame( array( 1, 'check', 'done' ), array( $check['step']['seq'], $check['step']['op'], $check['step']['state'] ) );
		// Step 2, first call: the unit is staged; the step stays planned and
		// its journal names the stage.
		$staged = self::next( $run_id );
		self::assertIsArray( $staged, is_wp_error( $staged ) ? $staged->get_error_code() . ': ' . $staged->get_error_message() : '' );
		self::assertSame( array( 2, 'write', 'planned' ), array( $staged['step']['seq'], $staged['step']['op'], $staged['step']['state'] ) );
		self::assertStringContainsString( 'is staged in the protected store', $staged['next'] );
		self::assertStringContainsString( 'from wordpress.org', $staged['next'] );
		self::assertSame( array( 'https://downloads.wordpress.org/plugin/ab-it-inaktiv.1.1.0.zip' ), array_column( $calls->wporg->calls, 'url' ), 'The exact version, once.' );
		$journal = AB_MCP_Sync_Code_Ops::staged( self::step( $run_id, 2 )['parts'] );
		self::assertIsArray( $journal, 'The journal of the step reads in its form.' );
		self::assertSame( array( 'plugin', 'ab-it-inaktiv', 'stage' ), array_values( $journal['unit'] ) );
		self::assertSame( 'wordpress.org', $journal['stage']['source'] );
		self::assertNull( AB_MCP_Sync_Code_Stage::ready( $journal['stage']['gid'], $journal['stage']['name'], $journal['stage']['hash'] ), 'Staged is not ready.' );
		$stage_groups = array_values( array_filter( AB_MCP_Protected_Store::groups( 'stage' ), static fn( $g ) => (int) $g['run_id'] === $run_id ) );
		self::assertCount( 1, $stage_groups, 'The stage belongs to the run.' );
		// Step 2, the calls after: its check, until it is done; never a second stage.
		$done = null;
		for ( $i = 0; $i < 20 && null === $done; $i++ ) {
			$answer = self::next( $run_id );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
			self::assertSame( 2, $answer['step']['seq'], 'The same step until it is done.' );
			$done = 'done' === $answer['step']['state'] ? $answer : null;
		}
		self::assertIsArray( $done, 'The check of the stage ended.' );
		self::assertStringContainsString( 'ready for its swap', $done['next'] );
		self::assertSame( array( 'kind' => 'plugin', 'ref' => 'ab-it-inaktiv' ), $done['step']['result']['staged'] );
		self::assertFalse( $done['step']['result']['same'] );
		self::assertGreaterThan( 0, $done['step']['result']['checked'] );
		self::assertIsArray( AB_MCP_Sync_Code_Stage::ready( $journal['stage']['gid'], $journal['stage']['name'], $journal['stage']['hash'] ), 'Ready, by complete() alone.' );
		self::assertCount( 1, $calls->wporg->calls, 'Fetched once.' );
		self::assertCount( 1, array_filter( AB_MCP_Protected_Store::groups( 'stage' ), static fn( $g ) => (int) $g['run_id'] === $run_id ), 'Staged once.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the live unit changed.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ) );
		// Step 3 is the swap, the next part of this branch: until it is
		// built the step does not run and stays planned.
		$swap = self::next( $run_id );
		self::refused( $swap, 'ab_mcp_sync_code_swap_not_built' );
		self::assertSame( 'planned', self::step( $run_id, 3 )['state'] );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
		$closed = self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) );
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free.' );
	}

	public function testAUnitFromAPackageOfCodePassesTheCheckOfTheRunAndIsStagedFromIt(): void {
		// The normal way of a plugin that is not on wordpress.org (E3 (b)):
		// its files exported on the staging site as a package of code, taken
		// over here and passed in packages. The check of the run reads no
		// package of code as one of objects (S1 of the handover of branch 5),
		// the package is bound to the run, and the stage takes the unit from
		// it; wordpress.org is never asked.
		list( $args, $entry, , , $report ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		$before = self::live_bytes( 'ab-it-inaktiv' );
		$calls  = self::wporg( array() );
		self::clear_b();
		list( $code ) = self::relayed(
			array(
				'purpose'     => 'code',
				'report_hash' => $report['report_hash'],
				'refs'        => array(
					array(
						'kind' => 'plugin',
						'ref'  => 'ab-it-inaktiv',
					),
				),
			)
		);
		list( $preview, $run ) = self::begun( $args + array( 'packages' => array( $code ) ) );
		self::assertSame( array( (int) $entry['n'], 'packages' ), array( $preview['preview']['units'][0]['entry'], $preview['preview']['units'][0]['source'] ) );
		$run_id = (int) $run['run_id'];
		$bound  = array();
		foreach ( AB_MCP_Protected_Store::groups( 'in' ) as $group ) {
			$bound[ substr( (string) $group['label'], strlen( AB_MCP_Sync_Import::LABEL ) ) ] = (int) $group['run_id'];
		}
		self::assertSame( $run_id, $bound[ $code ], 'The package of code is kept for the run.' );
		$check = self::next( $run_id );
		self::assertIsArray( $check, is_wp_error( $check ) ? $check->get_error_code() . ': ' . $check->get_error_message() : '' );
		self::assertSame( array( 1, 'check', 'done' ), array( $check['step']['seq'], $check['step']['op'], $check['step']['state'] ), 'The check of the run passes with a package of code: ' . (string) json_encode( $check['step']['result'] ) );
		$staged = self::next( $run_id );
		self::assertIsArray( $staged, is_wp_error( $staged ) ? $staged->get_error_code() . ': ' . $staged->get_error_message() : '' );
		self::assertSame( array( 2, 'planned' ), array( $staged['step']['seq'], $staged['step']['state'] ) );
		self::assertStringContainsString( 'from packages', $staged['next'] );
		$done = null;
		for ( $i = 0; $i < 20 && null === $done; $i++ ) {
			$answer = self::next( $run_id );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
			$done = 'done' === $answer['step']['state'] ? $answer : null;
		}
		self::assertIsArray( $done, 'The check of the stage ended.' );
		self::assertStringContainsString( 'ready for its swap', $done['next'] );
		self::assertSame( array(), $calls->wporg->calls, 'wordpress.org is not asked where packages are passed.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the live unit changed.' );
	}

	/* ------------------------------------------- the release and the plan */

	public function testTheSwapOfCodeThatRunsIsPlannedOnlyWithItsNumberInAcceptAndTheTokenBindsIt(): void {
		// F2 and F21, the part of the plan (Z24, the early refusal; Z41): an
		// active plugin is planned only with its number in accept.
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		list( $args, $entry, , $units ) = self::changed( 'ab-it-aktiv', '2.0.0' );
		$n = (int) $entry['n'];
		$m = (int) $units['plugin:ab-it-inaktiv']['n'];
		self::wporg();
		$message = self::refused( self::gated( 'wp_site_push', $args ), 'ab_mcp_sync_push_nothing' );
		self::assertStringContainsString( "Entry $n swaps code this site runs", $message, 'Locked with the operation.' );
		self::assertStringContainsString( 'the plugin ab-it-aktiv is active', $message );
		self::assertStringContainsString( 'Pass accept with this number', $message, 'With the way.' );
		// accept that names no unit chosen: refused before anything is planned.
		self::assertStringContainsString( 'is in accept but is no plugin or theme chosen', self::refused( self::gated( 'wp_site_push', $args + array( 'accept' => array( $n + 1000 ) ) ), 'ab_mcp_sync_push_selection' ) );
		self::assertStringContainsString( 'accept is a list of entry numbers', self::refused( self::gated( 'wp_site_push', $args + array( 'accept' => array( 'all' ) ) ), 'ab_mcp_sync_push_selection' ) );
		// With its number: planned as a swap of running code, released.
		$released = $args + array( 'accept' => array( $n ) );
		$preview  = self::gated( 'wp_site_push', $released );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
		$unit = $preview['preview']['units'][0];
		self::assertSame( array( 'swap_running_code', true, true ), array( $unit['operation'], $unit['runs'], $unit['accept'] ) );
		self::assertSame( array( 'here' => '1.0.0', 'staging' => '2.0.0' ), $unit['version'] );
		// Z41: the token of the preview is bound to accept as to every
		// argument. Without accept the call is no plan of this unit at all;
		// with accept naming a further unit chosen (the inactive plugin of
		// $m, a plan of its own) the token of the first plan confirms nothing.
		$token = $preview['confirm_token'];
		self::refused( self::gated( 'wp_site_push', $args + array( 'confirm_token' => $token ) ), 'ab_mcp_sync_push_nothing' );
		$both = array( 'select' => array( $n, $m ) ) + $released;
		$one  = self::gated( 'wp_site_push', $both );
		self::assertIsArray( $one, is_wp_error( $one ) ? $one->get_error_code() . ': ' . $one->get_error_message() : '' );
		self::refused( self::gated( 'wp_site_push', array( 'accept' => array( $n, $m ) ) + $both + array( 'confirm_token' => $one['confirm_token'] ) ), 'ab_mcp_confirm_mismatch' );
		self::refused( self::gated( 'wp_site_push', $both + array( 'confirm_token' => $token ) ), 'ab_mcp_confirm_mismatch' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'No run was made.' );
		$run = self::gated( 'wp_site_push', $released + array( 'confirm_token' => $token ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_code() . ': ' . $run->get_error_message() : '' );
		$units = AB_MCP_Sync_Run::get( (int) $run['run_id'] )['plan']['code']['units'];
		self::assertSame( array( array( true, true ), array( true, true ) ), array_map( static fn( $u ) => array( $u['runs'], $u['accept'] ), $units ), 'The plan of the run holds that its code runs and that it is released.' );
		self::assertSame( array( $n ), AB_MCP_Sync_Run::get( (int) $run['run_id'] )['plan']['selection']['accept'] );
		// The same confirmed call again: the same run (the replay of decision 15).
		$again = self::gated( 'wp_site_push', $released + array( 'confirm_token' => $token ) );
		self::assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_code() . ': ' . $again->get_error_message() : '' );
		self::assertSame( array( (int) $run['run_id'], false ), array( (int) $again['run_id'], $again['created_now'] ) );
	}

	public function testWhetherCodeRunsNotKnownLocksTheUnitWithAcceptOrWithoutAndAnInactivePluginNeedsNoRelease(): void {
		// Reading 3 as the lead decided it (13.11.), over the public way (S9):
		// a row of active_plugins that does not read is no state, neither
		// «does not run» nor «runs»: the unit is locked with its own reason
		// and way, and accept releases nothing on it.
		list( $args, $entry ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		$n = (int) $entry['n'];
		self::wporg();
		self::assertIsArray( self::gated( 'wp_site_push', $args ), 'Inactive: planned without a release.' );
		global $wpdb;
		$row = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'" );
		foreach ( array( 'half written' => substr( (string) $row, 0, 12 ), 'a text' => 'none', 'keys that are texts' => serialize( array( 'x' => 'a/a.php' ) ) ) as $what => $value ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => 'active_plugins' ) );
			try {
				$got      = self::gated( 'wp_site_push', $args );
				$released = self::gated( 'wp_site_push', $args + array( 'accept' => array( $n ) ) );
			} finally {
				$wpdb->update( $wpdb->options, array( 'option_value' => $row ), array( 'option_name' => 'active_plugins' ) );
			}
			foreach ( array( 'without accept' => $got, 'with accept' => $released ) as $how => $answer ) {
				$message = self::refused( $answer, 'ab_mcp_sync_push_nothing' );
				self::assertStringContainsString( "Whether the plugin ab-it-inaktiv of entry $n runs on this site could not be read", $message, "$what, $how" );
				self::assertStringContainsString( '(code:runs_unknown)', $message, "$what, $how: its own reason." );
				self::assertStringContainsString( 'Check the plugins of this site in wp-admin', $message, "$what, $how: with the way." );
				self::assertStringNotContainsString( 'swaps code this site runs', $message, "$what, $how: not planned as running." );
			}
		}
		self::assertIsArray( self::gated( 'wp_site_push', $args ), 'Read again as it is: planned.' );
	}

	public function testTwoVersionsThatAreNoneAreNotKnownAndLockTheUnit(): void {
		// Reading 2 as the lead decided it (13.11.): a head that names no
		// version on either site is no version known, so it is no «same
		// version» (edit) but code:version_unknown, as any version not known.
		$main = static fn( string $site ): string => AB_MCP_IT_Code::path( $site, 'plugin', 'ab-it-inaktiv' ) . '/ab-it-inaktiv.php';
		foreach ( array( 'a', 'b' ) as $site ) {
			$text = (string) file_get_contents( $main( $site ) );
			self::assertStringContainsString( " * Version: 1.0.0\n", $text );
			file_put_contents( $main( $site ), str_replace( " * Version: 1.0.0\n", '', $text ) );
		}
		self::paired();
		file_put_contents( AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-inaktiv' ) . '/readme.txt', "Changed on staging.\n", FILE_APPEND );
		list( $report, $units ) = self::report();
		$entry = $units['plugin:ab-it-inaktiv'];
		self::assertSame( array( 'changed_on_staging', 'take_over' ), array( $entry['result'], $entry['action'] ) );
		self::wporg();
		$message = self::refused(
			self::gated(
				'wp_site_push',
				array(
					'action'    => 'plan',
					'report_id' => $report['report_id'],
					'select'    => array( (int) $entry['n'] ),
				)
			),
			'ab_mcp_sync_push_nothing'
		);
		self::assertStringContainsString( '(code:version_unknown)', $message );
		self::assertStringContainsString( 'Give the plugin or theme a Version in its head on both sites', $message, 'With the way.' );
	}

	public function testThePreviewNamesEveryPathThatGoesIntoTheBackup(): void {
		// Reading 4 as the lead decided it (13.11.; Z42, E1, F47): every path
		// that lies here and that the new version lacks is named, more than
		// the 100 a page of the report shows in the entry of the unit too.
		$extra = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$extra[] = sprintf( 'alt/datei-%03d.txt', $i );
		}
		foreach ( array( 'a', 'b' ) as $site ) {
			$dir = AB_MCP_IT_Code::path( $site, 'plugin', 'ab-it-inaktiv' );
			mkdir( $dir . '/alt' );
			foreach ( $extra as $path ) {
				file_put_contents( $dir . '/' . $path, $path . "\n" );
			}
		}
		self::paired();
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		list( $report, $units ) = self::report();
		$entry = $units['plugin:ab-it-inaktiv'];
		self::assertSame( 'take_over', $entry['action'] );
		self::assertSame( array( 150, 100 ), array( $entry['paths']['only_live_count'], count( $entry['paths']['only_live'] ) ), 'The report shows 100 in the entry, the others after it.' );
		self::wporg();
		$preview = self::gated(
			'wp_site_push',
			array(
				'action'    => 'plan',
				'report_id' => $report['report_id'],
				'select'    => array( (int) $entry['n'] ),
			)
		);
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
		$backup = $preview['preview']['units'][0]['to_backup'];
		$named  = $backup['paths'];
		sort( $named );
		self::assertSame( $extra, $named, 'Every path, none cut off.' );
		self::assertSame( 150, $backup['count'] );
		self::assertStringContainsString( 'after the swap each of them lies in the backup, back through the way back', $backup['says'] );
	}

	public function testARunOfTheCodeHoldsNoStepOfTheContents(): void {
		// Z30, E19: units and contents chosen together are refused with the
		// way; all=true never plans a unit.
		list( $args, $entry, , , $report ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		$n = (int) $entry['n'];
		self::wporg();
		$other = null;
		foreach ( $report['entries'] as $one ) {
			if ( 'environment' !== $one['kind'] ) {
				$other = (int) $one['n'];
				break;
			}
		}
		$other   = $other ?? ( 1 === $n ? 2 : 1 );
		$message = self::refused( self::gated( 'wp_site_push', array( 'select' => array( $n, $other ) ) + $args ), 'ab_mcp_sync_push_code_mix' );
		self::assertStringContainsString( 'A run of the code holds no step of the contents', $message );
		self::assertStringContainsString( 'make a new report (wp_site_diff), then plan the contents', $message, 'With the way.' );
		self::refused( self::gated( 'wp_site_push', $args + array( 'all' => true ) ), 'ab_mcp_sync_push_code_mix' );
		self::refused( self::gated( 'wp_site_push', $args + array( 'as_draft' => array( $n ) ) ), 'ab_mcp_sync_push_code_mix' );
		self::refused( self::gated( 'wp_site_push', $args + array( 'choices' => array( array( 'entry' => $other, 'choice' => 'keep_live' ) ) ) ), 'ab_mcp_sync_push_code_mix' );
		// A choice or a draft for the entry of the unit itself: no step of the
		// contents either.
		self::refused( self::gated( 'wp_site_push', $args + array( 'choices' => array( array( 'entry' => $n, 'choice' => 'keep_live' ) ) ) ), 'ab_mcp_sync_push_code_mix' );
		// accept without a unit of code chosen (Z41): refused with the way,
		// never a plan of the contents.
		$message = self::refused( self::gated( 'wp_site_push', array( 'select' => array( $other ), 'accept' => array( $other ) ) + $args ), 'ab_mcp_sync_push_selection' );
		self::assertStringContainsString( 'accept names entries, but the selection names no plugin and no theme', $message );
		self::assertStringContainsString( 'Leave accept out, or select the unit', $message, 'With the way.' );
		self::assertNull( AB_MCP_Sync_Run::open_run() );
		// The unit alone: a plan of the code, every step of phase 2 or of the frame.
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview );
		self::assertNotContains( 5, array_column( $preview['preview']['steps'], 'phase' ) );
		// A plan of the contents without its packages is refused as ever.
		self::refused( self::gated( 'wp_site_push', array( 'select' => array( $other ) ) + $args ), 'ab_mcp_sync_push_packages' );
	}

	public function testPathsThatGoIntoTheBackupNotKnownLockTheUnit(): void {
		// Reading 4: where the report does not hold every path of the unit
		// here that the new version lacks, the unit is locked with its own
		// reason (code:paths_unknown), never planned with fewer paths.
		list( , $entry, $env ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		self::wporg();
		$unit      = new \ReflectionMethod( AB_MCP_Sync_Code_Plan::class, 'unit' );
		$pair      = \AB_MCP_Sync_Pairs::get( $entry['pair']['id'] );
		$with      = array( 'source' => $pair['source_id'] );
		$selection = array(
			'accept'   => array(),
			'packages' => array(),
		);
		$planned   = $unit->invoke( null, array(), $entry['n'], $entry, $env, $selection, $with );
		self::assertArrayHasKey( 'item', $planned, 'The entry as the report holds it is planned.' );
		foreach ( array( 'only_live_count', 'only_live' ) as $field ) {
			$broken = $entry;
			unset( $broken['paths'][ $field ] );
			$got = $unit->invoke( null, array(), $entry['n'], $broken, $env, $selection, $with );
			self::assertArrayHasKey( 'locked', $got, $field );
			self::assertStringContainsString( '(code:paths_unknown)', $got['locked']['reason'], $field );
			self::assertStringContainsString( 'Make a new report (wp_site_diff) and plan again', $got['locked']['reason'], "$field: with the way." );
		}
	}

	public function testAPathAddedOrRemovedHereAfterTheReportLocksTheUnitAndTheTreeOfTheReportIsNamed(): void {
		// A1 of Codex round 1 on the code (the decision of the lead of 13.11.):
		// the paths of the unit here that the new version lacks are read now,
		// when the plan is made, and named where they are those of the
		// report; a path added here or gone since locks the unit with its own
		// reason and the way, a new report. Never a preview with fewer paths.
		foreach ( array( 'a', 'b' ) as $site ) {
			file_put_contents( AB_MCP_IT_Code::path( $site, 'plugin', 'ab-it-inaktiv' ) . '/extra.txt', "a file the new version lacks\n" );
		}
		self::paired();
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		list( $report, $units ) = self::report();
		$entry = $units['plugin:ab-it-inaktiv'];
		self::assertSame( 'take_over', $entry['action'] );
		self::assertSame( array( 'extra.txt' ), $entry['paths']['only_live'] );
		$args = array(
			'action'    => 'plan',
			'report_id' => $report['report_id'],
			'select'    => array( (int) $entry['n'] ),
		);
		self::wporg();
		$named = static function () use ( $args ): void {
			$preview = self::gated( 'wp_site_push', $args );
			self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
			$backup = $preview['preview']['units'][0]['to_backup'];
			self::assertSame( array( 1, array( 'extra.txt' ) ), array( $backup['count'], $backup['paths'] ), 'Read now and the one of the report.' );
		};
		$named();
		$dir    = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-inaktiv' );
		$locked = static function () use ( $args ): void {
			$message = self::refused( self::gated( 'wp_site_push', $args ), 'ab_mcp_sync_push_nothing' );
			self::assertStringContainsString( '(code:paths_changed)', $message );
			self::assertStringContainsString( 'Make a new report (wp_site_diff), then plan again', $message, 'With the way.' );
			self::assertNull( AB_MCP_Sync_Run::open_run() );
		};
		// A path added here after the report (the case of Codex).
		file_put_contents( $dir . '/spaet.txt', "added here after the report\n" );
		$locked();
		// The tree of the report again: named again, the lock is no state.
		unlink( $dir . '/spaet.txt' );
		$named();
		// A path of the report gone here since.
		unlink( $dir . '/extra.txt' );
		$locked();
	}

	public function testFilesOfTheUnitHereNotReadWholeLockTheUnitNeverAsNoPaths(): void {
		// A1: a reading of the files of the unit here that is not whole is
		// not known, never «no paths»: the unit is locked with its own reason
		// and the way.
		list( $args ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		self::wporg();
		$file = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-inaktiv' ) . '/readme.txt';
		chmod( $file, 0 );
		try {
			self::assertFalse( is_readable( $file ), 'The suite runs as a user the mode of a file binds.' );
			$message = self::refused( self::gated( 'wp_site_push', $args ), 'ab_mcp_sync_push_nothing' );
		} finally {
			chmod( $file, 0644 );
		}
		self::assertStringContainsString( '(code:paths_unread)', $message );
		self::assertStringContainsString( 'then make a new report (wp_site_diff) and plan again', $message, 'With the way.' );
		self::assertNull( AB_MCP_Sync_Run::open_run() );
	}

	public function testAStageStillCheckingSaysSoAndTheStepStays(): void {
		// The check of a stage over calls: the budget of a call spent, the
		// stage is still checking, the step stays planned and says so; the
		// next call goes on. Nothing of the site changed.
		// ab-it-zwei: two PHP files to check (its two main files).
		list( $args, $entry ) = self::changed( 'ab-it-zwei', '1.1.0' );
		$calls                = self::wporg();
		list( , $run )        = self::begun( $args + array( 'accept' => array( (int) $entry['n'] ) ) );
		$run_id               = (int) $run['run_id'];
		$before               = self::live_bytes( 'ab-it-zwei' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		self::assertSame( 'planned', self::next( $run_id )['step']['state'], 'Staged.' );
		// The time of the call is over once the parser had its first file
		// (as CodeStageTest does it: the stage compares with times of its own
		// reading, so the clock goes on from the real one and jumps by 1000
		// seconds, far past the budget of 20).
		$calls->parsed                 = 0;
		AB_MCP_Sync_Code_Stage::$clock = static fn(): float => microtime( true ) + ( $calls->parsed >= 1 ? 1000.0 : 0.0 );
		try {
			$checking = self::next( $run_id );
		} finally {
			AB_MCP_Sync_Code_Stage::$clock = null;
		}
		self::assertIsArray( $checking, is_wp_error( $checking ) ? $checking->get_error_code() . ': ' . $checking->get_error_message() : '' );
		self::assertStringContainsString( 'is being checked', (string) wp_json_encode( $checking ) );
		self::assertSame( 'planned', self::step( $run_id, 2 )['state'], 'Still checking: the step stays.' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'Checked in the next call.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-zwei' ) );
	}

	public function testAStageTheCheckRefusesIsNotStagedAndItsSwapIsSkipped(): void {
		// The check refuses the stage (here: its PHP files could not be listed
		// within the time of one call, code:syntax_unchecked, by a clock of
		// its own that jumps 100 seconds at each reading): the step fails with
		// the reason and the way, it is never ready, nothing of the site
		// changed.
		list( $args ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		self::wporg();
		list( , $run ) = self::begun( $args );
		$run_id = (int) $run['run_id'];
		$before = self::live_bytes( 'ab-it-inaktiv' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		self::assertSame( 'planned', self::next( $run_id )['step']['state'], 'Staged.' );
		$time                          = 1000.0;
		AB_MCP_Sync_Code_Stage::$clock = static function () use ( &$time ): float {
			$time += 100.0;
			return $time;
		};
		try {
			$refused = self::next( $run_id );
		} finally {
			AB_MCP_Sync_Code_Stage::$clock = null;
		}
		self::assertIsArray( $refused, is_wp_error( $refused ) ? $refused->get_error_code() . ': ' . $refused->get_error_message() : '' );
		$step = self::step( $run_id, 2 );
		self::assertSame( array( 'failed', 'code:syntax_unchecked' ), array( $step['state'], $step['result']['code'] ) );
		self::assertStringContainsString( 'is not staged (code:syntax_unchecked), so it is not swapped; nothing of this site changed', $step['result']['message'] );
		self::assertStringContainsString( 'Stage the unit again', $step['result']['message'], 'With the way.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
	}

	/** A run of ab-it-inaktiv with its unit staged: the run, the staged journal and the files of the site before. */
	private static function staged_run(): array {
		list( $args ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		self::wporg();
		list( , $run ) = self::begun( $args );
		$run_id = (int) $run['run_id'];
		$before = self::live_bytes( 'ab-it-inaktiv' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		self::assertSame( 'planned', self::next( $run_id )['step']['state'], 'Staged.' );
		return array( $run_id, self::step( $run_id, 2 )['parts'], $before );
	}

	/** Step 2 planned again with this journal, as the next call finds it. */
	private static function replan( int $run_id, array $journal ): void {
		global $wpdb;
		$steps = AB_MCP_Sync_Schema::table( 'steps' );
		$wpdb->query( $wpdb->prepare( "UPDATE `$steps` SET state = 'planned', result = NULL WHERE run_id = %d AND seq = 2", $run_id ) ); // phpcs:ignore WordPress.DB
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 2, array( 'parts' => $journal ), 'planned' ) );
	}

	/** The step of the stage ended failed with this code, its swap not run, nothing of the site changed. */
	private static function stage_failed( int $run_id, string $code, array $before ): array {
		$step = self::step( $run_id, 2 );
		self::assertSame( array( 'failed', $code ), array( $step['state'], $step['result']['code'] ?? null ), (string) wp_json_encode( $step['result'] ) );
		self::assertStringContainsString( "is not staged ($code), so it is not swapped; nothing of this site changed", $step['result']['message'] );
		self::assertSame( 'planned', self::step( $run_id, 3 )['state'], 'The swap did not run.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
		return $step;
	}

	public function testAStageThatIsNotTheOneOfItsGroupOrNotSignedFailsItsStepAndItsSwapIsSkipped(): void {
		// A3 of the Codex round 1 on the code: a refusal of the stage that no
		// later call can change ends the step failed and its swap is skipped,
		// never planned for ever; a reading that failed stays planned
		// (reading 5, the test of what does not read).
		list( $run_id, $journal, $before ) = self::staged_run();
		// The unit not signed in the environment the check is given (the one
		// entrance called directly, with an environment that names no unit).
		$item = AB_MCP_Sync_Code_Ops::item( AB_MCP_Sync_Run::get( $run_id )['plan'], 2 );
		self::assertIsArray( $item );
		AB_MCP_Sync_Code_Ops::run( AB_MCP_Sync_Run::get( $run_id ), self::step( $run_id, 2 ), array( 'env' => array() ) + $item );
		$step = self::stage_failed( $run_id, 'code:unsigned', $before );
		self::assertStringContainsString( 'Take the environment of the staging site over anew', $step['result']['message'], 'With the way.' );
		// The hash of the tree not the one its group holds, through the public way.
		$wrong                   = $journal;
		$wrong['stage']['hash']  = str_repeat( 'f', 64 );
		self::replan( $run_id, $wrong );
		$failed = self::next( $run_id );
		self::assertIsArray( $failed, is_wp_error( $failed ) ? $failed->get_error_code() . ': ' . $failed->get_error_message() : '' );
		$step = self::stage_failed( $run_id, 'code:stage_other', $before );
		self::assertStringContainsString( 'Stage the unit again', $step['result']['message'], 'With the way.' );
		// The run goes on: the swap is skipped, the run closes with the finding.
		$closed = null;
		for ( $i = 0; $i < 6 && null === $closed; $i++ ) {
			$answer = self::next( $run_id );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
			$closed = in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ? $answer : null;
		}
		self::assertIsArray( $closed, 'The run closed.' );
		self::assertSame( 'skipped', self::step( $run_id, 3 )['state'], 'The swap of a unit whose stage failed never runs.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
	}

	public function testAReadyStageNotBoundToThisRunThisUnitAndItsStagingHashNeverMakesTheStepDone(): void {
		// A2 of the Codex round 1 on the code: a journal of its form may name a
		// ready stage of another run, another unit or another version of the
		// staging site; the step is done only on the stage bound to this run,
		// this unit and the staging_hash of the plan, else it fails with its
		// reason and way.
		list( $run_id, $journal, $before ) = self::staged_run();
		$gid  = $journal['stage']['gid'];
		$item = AB_MCP_Sync_Code_Ops::item( AB_MCP_Sync_Run::get( $run_id )['plan'], 2 );
		self::assertIsArray( $item );
		$foreign = static function ( string $what ) use ( $run_id, $before ): void {
			$step = self::stage_failed( $run_id, 'code:stage_foreign', $before );
			self::assertStringContainsString( 'is not bound to this run, this unit and the version of the staging site the plan names', $step['result']['message'], $what );
			self::assertStringContainsString( 'wp_site_push action=abort', $step['result']['message'], "$what: with the way." );
		};
		// The group bound to another run.
		self::assertTrue( AB_MCP_Protected_Store::bind( 'stage', $gid, $run_id + 1 ) );
		try {
			$answer = self::next( $run_id );
		} finally {
			self::assertTrue( AB_MCP_Protected_Store::bind( 'stage', $gid, $run_id ) );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$foreign( 'another run' );
		// Another version of the staging site than the plan names.
		self::replan( $run_id, $journal );
		AB_MCP_Sync_Code_Ops::run( AB_MCP_Sync_Run::get( $run_id ), self::step( $run_id, 2 ), array( 'unit' => array( 'staging_hash' => str_repeat( 'e', 64 ) ) + $item['unit'] ) + $item );
		$foreign( 'another staging_hash' );
		// Another unit than the stage holds (its journal of that unit too).
		$other                 = $journal;
		$other['unit']['ref']  = 'ab-it-aktiv';
		self::replan( $run_id, $other );
		AB_MCP_Sync_Code_Ops::run( AB_MCP_Sync_Run::get( $run_id ), self::step( $run_id, 2 ), array( 'unit' => array( 'ref' => 'ab-it-aktiv' ) + $item['unit'] ) + $item );
		$foreign( 'another unit' );
		// Another kind of unit than the stage holds (its journal of that kind too).
		$kind                  = $journal;
		$kind['unit']['kind']  = 'theme';
		self::replan( $run_id, $kind );
		AB_MCP_Sync_Code_Ops::run( AB_MCP_Sync_Run::get( $run_id ), self::step( $run_id, 2 ), array( 'unit' => array( 'kind' => 'theme' ) + $item['unit'] ) + $item );
		$foreign( 'another kind' );
		// Bound as the plan names it: done.
		self::replan( $run_id, $journal );
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
	}

	/* ------------------------------------------- what does not read */

	public function testAStepOfTheCodeWhoseRunPlanOrJournalDoesNotReadInItsFormDoesNothing(): void {
		// V18 and V19 of the pre-check: what a step of the code reads comes
		// in the form of its row; what is not of it is not known, the step
		// does nothing, and nothing is taken for a step of the contents.
		list( $args ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		$calls        = self::wporg();
		list( , $run ) = self::begun( $args );
		$run_id = (int) $run['run_id'];
		$before = self::live_bytes( 'ab-it-inaktiv' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		self::assertSame( 'planned', self::next( $run_id )['step']['state'], 'Staged; its journal names the stage.' );
		$journal = self::step( $run_id, 2 )['parts'];
		global $wpdb;
		$steps = AB_MCP_Sync_Schema::table( 'steps' );
		$runs  = AB_MCP_Sync_Schema::table( 'runs' );
		// A journal not of its form: the step refuses and stays planned.
		$wrong = $journal;
		$wrong['stage']['hash'] = 'not-a-hash';
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 2, array( 'parts' => $wrong ), 'planned' ) );
		$message = self::refused( self::next( $run_id ), 'ab_mcp_sync_code_journal' );
		self::assertStringContainsString( 'wp_site_push action=abort', $message, 'With the way.' );
		self::assertSame( 'planned', self::step( $run_id, 2 )['state'] );
		// A journal of another unit than the plan names for the step.
		$wrong = $journal;
		$wrong['unit']['ref'] = 'ab-it-aktiv';
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 2, array( 'parts' => $wrong ), 'planned' ) );
		self::refused( self::next( $run_id ), 'ab_mcp_sync_code_journal' );
		// The state of the stage does not read (reading 5 as the lead decided
		// it, 13.11.): complete() gives an error, the step stays planned, its
		// swap does not run, and the answer names the error and the way.
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 2, array( 'parts' => $journal ), 'planned' ) );
		// The folder of a group is named by its area, its id and the id of
		// the installation (AB_MCP_Protected_Store::group_name()).
		$named  = new \ReflectionMethod( AB_MCP_Protected_Store::class, 'group_name' );
		$folder = $named->invoke( null, 'stage', $journal['stage']['gid'], AB_MCP_Protected_Store::install_id( false ) );
		$states = glob( AB_MCP_Protected_Store::root() . '/stage/' . $folder . '/' . AB_MCP_Sync_Code_Stage::STATE . '*' );
		self::assertCount( 1, $states );
		rename( $states[0], $states[0] . '.weg' );
		try {
			$error = self::next( $run_id );
		} finally {
			rename( $states[0] . '.weg', $states[0] );
		}
		$message = self::refused( $error, 'code:stage_unknown' );
		self::assertStringContainsString( 'The state of the stage does not read back', $message, 'The error.' );
		self::assertStringContainsString( 'Step 2 stays planned, and no unit is swapped without a stage read as ready', $message );
		self::assertStringContainsString( 'Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort)', $message, 'The way.' );
		self::assertSame( array( 'planned', 'planned' ), array( self::step( $run_id, 2 )['state'], self::step( $run_id, 3 )['state'] ) );
		// The journal as it was: the check goes on.
		$answer = self::next( $run_id );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() : '' );
		self::assertSame( 2, $answer['step']['seq'] );
		// A plan of the code that names no unit for its write steps (its
		// units of another kind): no step runs, none is run as an object.
		$stored                             = AB_MCP_Sync_Run::get( $run_id )['plan'];
		$stored['code']['units'][0]['kind'] = 'mu_plugin';
		$stored['code']['units'][1]['kind'] = 'mu_plugin';
		$plan = $wpdb->get_var( $wpdb->prepare( "SELECT plan FROM `$runs` WHERE run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 2, array( 'parts' => null ), null ) );
		$wpdb->query( $wpdb->prepare( "UPDATE `$steps` SET state = 'planned', result = NULL WHERE run_id = %d AND seq IN ( 2, 3 )", $run_id ) ); // phpcs:ignore WordPress.DB
		$fetched = count( $calls->wporg->calls );
		$item    = AB_MCP_Sync_Code_Ops::item( $stored, 2 );
		self::refused( $item, 'ab_mcp_sync_code_plan' );
		self::assertSame( $fetched, count( $calls->wporg->calls ), 'Nothing fetched on a plan that does not read.' );
		self::assertIsString( $plan );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the site changed in any of these.' );
	}

	public function testAPackageThatIsGoneBindsNothingAndTheBindingIsChecked(): void {
		// V20: the packages a run of the code reads until its swaps are bound
		// to it, each binding checked.
		list( $args, , $env ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		self::wporg();
		$plan = \AB_MCP_Sync_Push::plan( $args );
		self::assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		self::assertTrue( AB_MCP_Sync_Code_Plan::bind( $plan, 1 ) );
		$gone                    = $plan;
		$gone['code']['env'][]   = str_repeat( 'f', 32 );
		$message                 = self::refused( AB_MCP_Sync_Code_Plan::bind( $gone, 1 ), 'ab_mcp_sync_code_bind' );
		self::assertStringContainsString( str_repeat( 'f', 32 ), $message, 'The package is named.' );
		self::assertStringContainsString( 'make a new report (wp_site_diff) and plan again', $message, 'With the way.' );
		self::assertNotSame( array(), $env );
	}

	public function testADownloadThatFailsFailsTheStageAndItsSwapIsSkipped(): void {
		// HTTP of the stage, return false: wordpress.org has no such package.
		// The step failed with the refusal, its swap never runs, nothing changed.
		list( $args )  = self::changed( 'ab-it-inaktiv', '1.1.0' );
		self::wporg( array() );
		list( , $run ) = self::begun( $args );
		$run_id        = (int) $run['run_id'];
		$before        = self::live_bytes( 'ab-it-inaktiv' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		$failed = self::next( $run_id );
		self::assertIsArray( $failed, is_wp_error( $failed ) ? $failed->get_error_code() : '' );
		self::assertSame( array( 2, 'failed' ), array( $failed['step']['seq'], $failed['step']['state'] ) );
		self::assertStringContainsString( 'is not staged (code:org_fetch)', $failed['step']['result']['message'] );
		self::assertStringContainsString( 'so it is not swapped; nothing of this site changed', $failed['step']['result']['message'] );
		self::assertNull( self::step( $run_id, 2 )['parts'], 'No journal of a stage that is not there.' );
		// The run goes on: the swap is skipped (its stage failed), then the
		// caches and the check close it with the finding.
		$closed = null;
		for ( $i = 0; $i < 6 && null === $closed; $i++ ) {
			$answer = self::next( $run_id );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
			$closed = in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ? $answer : null;
		}
		self::assertIsArray( $closed, 'The run closed.' );
		self::assertSame( 'closed_findings', $closed['state'] );
		self::assertSame( 'skipped', self::step( $run_id, 3 )['state'], 'The swap of a unit that is not staged never runs.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
	}
}

/**
 * The calls of the stage with the stand-in of wordpress.org for the
 * download, and the real ones else.
 */
final class SyncCodeRunStageCalls {

	/** @var AB_MCP_Test_Wporg */
	public $wporg;

	/** @var int The files the parser had, for a clock that ends a call after the first. */
	public $parsed = 0;

	/** @var AB_MCP_Sync_Code_Stage_Calls */
	private $real;

	public function __construct( AB_MCP_Test_Wporg $wporg ) {
		$this->wporg = $wporg;
		$this->real  = new AB_MCP_Sync_Code_Stage_Calls();
	}

	public function get( $url, array $args ) {
		return $this->wporg->get( $url, $args );
	}

	// Every other call of the seam goes to the real calls, each by its name
	// (AB_MCP_Test_Seam asks the stand-in for every method the product calls).

	public function method() {
		return $this->real->method();
	}

	public function unzip( $zip, $to ) {
		return $this->real->unzip( $zip, $to );
	}

	public function unpacked_size( $zip ) {
		return $this->real->unpacked_size( $zip );
	}

	public function web_ready( array $paths ) {
		return $this->real->web_ready( $paths );
	}

	public function zip_names( $zip ) {
		return $this->real->zip_names( $zip );
	}

	public function taken( $package_id ) {
		return $this->real->taken( $package_id );
	}

	public function extract( array $taken, array $jobs ) {
		return $this->real->extract( $taken, $jobs );
	}

	public function copy( $from, $to ) {
		return $this->real->copy( $from, $to );
	}

	public function mkdir( $path ) {
		return $this->real->mkdir( $path );
	}

	public function read( $path, $max ) {
		return $this->real->read( $path, $max );
	}

	public function tokenizer() {
		return $this->real->tokenizer();
	}

	public function parse_php( $code ) {
		++$this->parsed;
		return $this->real->parse_php( $code );
	}

	public function memory() {
		return $this->real->memory();
	}

	public function lint( $file, $label ) {
		return $this->real->lint( $file, $label );
	}
}
