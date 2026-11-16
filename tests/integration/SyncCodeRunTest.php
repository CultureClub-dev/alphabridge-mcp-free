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
 * and ready; nothing of the site changes on the way. And the normal case of
 * its step «tauschen» (F56, AB_MCP_Sync_Code_Swap::run()).
 *
 * Effects to the outside of this part and their fault kinds (plan 8.3):
 * - rows (the run, its steps and their journal; the options active_plugins,
 *   stylesheet and template read): a journal, a plan, a run or a row of an
 *   option not of its form (read error, half written) does nothing or counts
 *   as code that runs; a step whose save fails stays planned; a step another
 *   call moved between its reading and its writing is never written over;
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

use AB_MCP_Change_Lock;
use AB_MCP_IT_Code;
use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Php_Probe;
use AB_MCP_Protected_Store;
use AB_MCP_REST_Controller;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Backup;
use AB_MCP_Sync_Code_Ops;
use AB_MCP_Sync_Code_Plan;
use AB_MCP_Sync_Code_Stage;
use AB_MCP_Sync_Code_Stage_Calls;
use AB_MCP_Sync_Code_Swap;
use AB_MCP_Sync_Env;
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Pairs_Tool;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use AB_MCP_Test_Wporg;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use AB_MCP_Undo;
use AB_MCP_Update_Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-code-stage.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';
require_once __DIR__ . '/support/class-test-wporg.php';
require_once __DIR__ . '/support/class-it-store-calls.php';

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
		AB_MCP_Sync_Env::$clock        = null;
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
		// The binding the step expects of its stage: this run, the unit and
		// the staging_hash of the plan (A2, K2).
		$unit  = AB_MCP_Sync_Code_Ops::item( AB_MCP_Sync_Run::get( $run_id )['plan'], 2 )['unit'];
		$bound = array(
			'run_id' => $run_id,
			'kind'   => $unit['kind'],
			'ref'    => $unit['ref'],
			'signed' => $unit['staging_hash'],
		);
		self::assertNull( AB_MCP_Sync_Code_Stage::ready( $journal['stage']['gid'], $journal['stage']['name'], $journal['stage']['hash'], $bound ), 'Staged is not ready.' );
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
		self::assertIsArray( AB_MCP_Sync_Code_Stage::ready( $journal['stage']['gid'], $journal['stage']['name'], $journal['stage']['hash'], $bound ), 'Ready, by complete() alone.' );
		foreach ( array( 'run_id' => $run_id + 1, 'kind' => 'theme', 'ref' => 'ab-it-aktiv', 'signed' => str_repeat( 'e', 64 ) ) as $field => $other ) {
			self::assertNull( AB_MCP_Sync_Code_Stage::ready( $journal['stage']['gid'], $journal['stage']['name'], $journal['stage']['hash'], array( $field => $other ) + $bound ), "Ready only for the step it is bound to: another $field." );
		}
		self::assertCount( 1, $calls->wporg->calls, 'Fetched once.' );
		self::assertCount( 1, array_filter( AB_MCP_Protected_Store::groups( 'stage' ), static fn( $g ) => (int) $g['run_id'] === $run_id ), 'Staged once.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the live unit changed.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ) );
		// Ready, and the run aborted before its swap (the swap: F56 below).
		self::assertSame( 'planned', self::step( $run_id, 3 )['state'] );
		$closed = self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) );
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free.' );
	}

	/** The main files of the active plugins as the row is stored now, past every cache. */
	private static function active(): array {
		global $wpdb;
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) );
		$got = is_string( $row ) ? unserialize( $row, array( 'allowed_classes' => false ) ) : null;
		self::assertIsArray( $got, 'The row of the active plugins reads.' );
		return $got;
	}

	/** The hash of every file below a folder, by its path relative to it. */
	private static function digest( string $dir ): array {
		$out = array();
		foreach ( AB_MCP_IT_Code::files( $dir ) as $rel ) {
			$out[ $rel ] = hash_file( 'sha256', $dir . '/' . $rel );
		}
		return $out;
	}

	/**
	 * The run of F56 until its swap: ab-it-aktiv 2.0.0 on staging, released
	 * with accept, the check and the stage of the run done; this site still
	 * at 1.0.0 with the plugin active. $before runs right before the run
	 * begins (the stand-in of wordpress.org installed).
	 *
	 * @return array{0:int,1:array,2:string} The run, the unit of step 3, its target.
	 */
	private static function ready_to_swap( ?callable $before = null ): array {
		list( $args, $entry ) = self::changed( 'ab-it-aktiv', '2.0.0' );
		$target = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-aktiv' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
		self::assertContains( 'ab-it-aktiv/ab-it-aktiv.php', self::active(), 'The plugin runs here.' );
		self::wporg();
		if ( null !== $before ) {
			$before();
		}
		list( , $run ) = self::begun( $args + array( 'accept' => array( (int) $entry['n'] ) ) );
		$run_id        = (int) $run['run_id'];
		$unit          = AB_MCP_Sync_Code_Ops::item( AB_MCP_Sync_Run::get( $run_id )['plan'], 3 )['unit'];
		self::assertSame( array( 'swap', true, true ), array( $unit['op'], $unit['runs'], $unit['accept'] ) );
		// Step 1, the check; step 2, the stage, until it is ready.
		$check = self::next( $run_id );
		self::assertIsArray( $check, is_wp_error( $check ) ? $check->get_error_message() : '' );
		self::assertSame( array( 1, 'done' ), array( $check['step']['seq'], $check['step']['state'] ) );
		$ready = null;
		for ( $i = 0; $i < 20 && null === $ready; $i++ ) {
			$answer = self::next( $run_id );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
			self::assertSame( 2, $answer['step']['seq'], 'The stage until it is done.' );
			$ready = 'done' === $answer['step']['state'] ? $answer : null;
		}
		self::assertIsArray( $ready, 'The stage is ready for its swap.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), 'Staged, nothing of the site changed.' );
		return array( $run_id, $unit, $target );
	}

	/** Step 3 of a run ready to swap, the calls of the swap with these faults: its answer. */
	private static function swap_with( int $run_id, array $faults ) {
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Code_Swap::class, 'naht_sync_code_swap', new \AB_MCP_Sync_Code_Swap_Calls(), $faults );
		return self::next( $run_id );
	}

	/** The mark of step 3 of a run as the store lists it; null where there is none. */
	private static function mark_of_swap( int $run_id ): ?array {
		$marks = AB_MCP_Protected_Store::marks();
		self::assertIsArray( $marks );
		$mine = array_values( array_filter( $marks['marks'], static fn( $m ) => (int) $m['run_id'] === $run_id && 3 === (int) $m['seq'] ) );
		self::assertLessThanOrEqual( 1, count( $mine ) );
		return $mine[0] ?? null;
	}

	/** The row of the note of the replacement as stored, past every cache. */
	private static function replaced_row() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", AB_MCP_Update_Log::REPLACED ) );
	}

	/**
	 * The rights of a stage of a package of code below a folder: its
	 * building folder makes each folder 0700 (AB_MCP_Sync_Code_Stage_Calls::mkdir(),
	 * Codex D1 finding 2), here each file 0600. A stage from wordpress.org
	 * comes with the rights of unzip_file() of the core, those of the site.
	 */
	private static function package_rights( string $root ): void {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $entry ) {
			chmod( $entry->getPathname(), $entry->isDir() ? 0700 : 0600 );
		}
		chmod( $root, 0700 );
	}

	/** The rights WordPress gives a folder (folder) and a file (file) of a plugin it installs (WP_Filesystem()). */
	private static function site_rights(): array {
		return array(
			'folder' => defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : ( fileperms( ABSPATH ) & 0777 | 0755 ),
			'file'   => defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ),
		);
	}

	/**
	 * A fault that only looks: right before the mark of the swap is written
	 * (the last step before the first rename), the places of the backup and
	 * the stage and the digests of the old version at the target and of the
	 * stage, into $seen (for nothing_lost()).
	 */
	private static function watch( ?array &$seen ): array {
		return array(
			'method' => 'put_mark',
			'nth'    => 1,
			'kind'   => 'before',
			'before' => static function ( $method, array $args ) use ( &$seen ): void {
				$trees = array_column( $args[0]['trees'], 'path', 'role' );
				$seen  = array(
					'target' => $args[0]['target'],
					'backup' => $trees['backup'],
					'stage'  => $trees['stage'],
					'old'    => self::digest( $args[0]['target'] ),
					'new'    => self::digest( $trees['stage'] ),
				);
			},
		);
	}

	/**
	 * After a swap that did not end done (D2a 6): the lock for changes is
	 * free (this request does not hold it, and it can be taken again), and
	 * nothing is lost on the disk: the old version whole at the target or
	 * whole in the backup, the stage whole at the target or at its place (or
	 * at a place in $also, where the test itself moved it).
	 */
	private static function nothing_lost( ?array $seen, array $also = array() ): void {
		self::assertIsArray( $seen, 'The swap came to its mark.' );
		self::assertFalse( AB_MCP_Sync_Code_Swap::lock_held(), 'The swap holds no lock for changes.' );
		self::assertTrue( AB_MCP_Change_Lock::take(), 'The lock for changes can be taken again.' );
		AB_MCP_Change_Lock::release();
		clearstatcache();
		$whole = static function ( array $want, array $places ): array {
			return array_values( array_filter( $places, static fn( $place ) => is_dir( $place ) && self::digest( $place ) === $want ) );
		};
		self::assertCount( 1, $whole( $seen['old'], array( $seen['target'], $seen['backup'] ) ), 'The old version whole at the target or in the backup.' );
		self::assertCount( 1, $whole( $seen['new'], array_merge( array( $seen['target'], $seen['stage'] ), $also ) ), 'The stage whole at the target or at its place.' );
	}

	/**
	 * Prüft: F56, the normal case of the swap: an active plugin with a new
	 * version on staging, released with accept, is swapped in the run in one
	 * call. prepare() as built (the test requests before the swap go to the
	 * own server of this site); before the first rename the note of the
	 * replacement, the journal of the step and the mark with its instruction
	 * stand, each as written; then the old folder lies whole in the backup
	 * and the stage at the target, OPcache is cleared for each PHP file
	 * replaced, the mark is closed, the pair takes its new bases, the file
	 * undo points of the unit go and those of another unit stay; no test
	 * request after the first rename and no swap back; the answer names the
	 * target, the backup, the way back and the commands by hand.
	 * Sonst: the run would not update the plugin of the customer, or update
	 * it without the old version to go back to, without the mark that tells
	 * a swap cut off from one that ended, with old code still in OPcache,
	 * with a file undo point that writes a file of the old version into the
	 * new one, or with an answer that does not say how to go back.
	 */
	public function testAnActivePluginReleasedWithAcceptIsSwappedInTheRunWithItsBackupItsMarkAndTheWayBack(): void {
		$main   = 'ab-it-aktiv/ab-it-aktiv.php';
		$points = array();
		list( $run_id, $unit, $target ) = self::ready_to_swap(
			static function () use ( &$points ): void {
				// Two file undo points: one of the unit, one of another plugin.
				foreach ( array( 'ab-it-aktiv', 'ab-it-inaktiv' ) as $slug ) {
					$points[ $slug ] = AB_MCP_Undo::record_result(
						array(
							'tool'    => 'wp_write_plugin_file',
							'cap'     => 'edit_plugins',
							'kind'    => 'file',
							'summary' => $slug . '/' . $slug . '.php',
							'items'   => 1,
							'data'    => array(
								'jail'    => 'plugin',
								'slug'    => $slug,
								'rel'     => $slug . '.php',
								'existed' => true,
								'bytes'   => "<?php\n// before\n",
							),
						)
					);
					self::assertIsString( $points[ $slug ], is_wp_error( $points[ $slug ] ) ? $points[ $slug ]->get_error_message() : '' );
				}
			}
		);
		foreach ( $points as $slug => $token ) {
			self::assertIsArray( AB_MCP_Undo::entry_for( $token ), "The undo point of $slug is there before the swap." );
		}
		clearstatcache();
		$old = lstat( $target );

		// Step 3, the swap, watched: the requests over HTTP, the state at the
		// first rename, the paths OPcache is asked for and after which rename.
		$http   = array();
		$record = static function ( $response, $context, $class, $parsed, $url ) use ( &$http ): void {
			$http[] = (string) $url;
		};
		$seen    = null;
		$renames = 0;
		$opcache = array();
		$chmods  = array();
		$noted   = false;
		$first   = static function () use ( &$seen, &$http, $run_id ): void {
			global $wpdb;
			clearstatcache();
			$row   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", AB_MCP_Update_Log::REPLACED ) );
			$marks = AB_MCP_Protected_Store::marks();
			$mine  = is_array( $marks ) ? array_values( array_filter( $marks['marks'], static fn( $m ) => (int) $m['run_id'] === $run_id && 3 === (int) $m['seq'] ) ) : array();
			$guide = 1 === count( $mine ) ? AB_MCP_Protected_Store::root() . '/' . AB_MCP_Protected_Store::MARKS . '/' . $mine[0]['mid'] . '/' . AB_MCP_Protected_Store::GUIDE_FILE : '';
			$seen  = array(
				'http'    => count( $http ),
				'note'    => is_string( $row ) ? unserialize( $row, array( 'allowed_classes' => false ) ) : null,
				'step'    => AB_MCP_Sync_Run::step( $run_id, 3 ),
				'marks'   => $mine,
				'guide'   => '' !== $guide && is_file( $guide ) ? (string) file_get_contents( $guide ) : null,
				'fassung' => AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ),
				'staged'  => 1 === count( $mine ) ? array_map( static fn( $tree ) => is_dir( AB_MCP_Protected_Store::root() . '/' . $tree['path'] ), array_column( $mine[0]['trees'], null, 'role' ) ) : array(),
			);
		};
		AB_MCP_Test_Seam::install(
			AB_MCP_Sync_Code_Swap::class,
			'naht_sync_code_swap',
			new \AB_MCP_Sync_Code_Swap_Calls(),
			array(
				array(
					'method' => 'rename',
					'kind'   => 'before',
					'before' => static function () use ( &$renames, $first ): void {
						if ( 1 === ++$renames ) {
							$first();
						}
					},
				),
				array(
					'method' => 'opcache',
					'kind'   => 'before',
					'before' => static function ( $method, array $args ) use ( &$opcache, &$renames ): void {
						$opcache[] = array( $renames, $args[0] );
					},
				),
				array(
					'method' => 'chmod',
					'kind'   => 'before',
					'before' => static function ( $method, array $args ) use ( &$chmods, &$noted ): void {
						if ( array() === $chmods ) {
							self::package_rights( $args[0] );
						}
						clearstatcache();
						$chmods[] = array( $args[0], fileperms( $args[0] ) & 07777, $noted );
					},
				),
				array(
					'method' => 'replaced',
					'kind'   => 'before',
					'before' => static function () use ( &$noted ): void {
						$noted = true;
					},
				),
			)
		);
		add_action( 'http_api_debug', $record, 10, 5 );
		try {
			$swapped = self::next( $run_id );
			$asked   = $http;
		} finally {
			remove_action( 'http_api_debug', $record, 10 );
		}
		self::assertIsArray( $swapped, is_wp_error( $swapped ) ? $swapped->get_error_code() . ': ' . $swapped->get_error_message() : '' );

		// prepare() as built: the test requests before the swap, to home,
		// the MCP route and admin-post.php of this site.
		self::assertIsArray( $seen, 'The swap renamed.' );
		$before = array_slice( $asked, 0, $seen['http'] );
		$asks = static function ( string $url, string $at ): bool {
			$got  = parse_url( $url );
			$want = parse_url( $at );
			parse_str( $got['query'] ?? '', $query );
			parse_str( $want['query'] ?? '', $named );
			foreach ( array( 'scheme', 'host', 'port', 'path' ) as $part ) {
				if ( ( $got[ $part ] ?? null ) !== ( $want[ $part ] ?? null ) ) {
					return false;
				}
			}
			return array_intersect_assoc( $named, $query ) === $named;
		};
		foreach ( array( 'home', 'mcp', 'admin_post' ) as $name ) {
			$at = AB_MCP_Php_Probe::targets()[ $name ];
			self::assertNotSame( array(), array_filter( $before, static fn( $url ) => $asks( $url, $at ) ), "A test request to $name ($at) before the first rename: " . implode( ' ', $before ) );
		}
		// No test request after the first rename until the step ended (no
		// probe after the swap).
		self::assertSame( $seen['http'], count( $asked ), 'No request over HTTP after the first rename.' );
		// Before the first rename: the note of the replacement as stored, the
		// journal of the step begun with that time, the mark open with its
		// instruction; the target still the old version.
		self::assertIsArray( $seen['note'] );
		self::assertArrayHasKey( 'plugin:ab-it-aktiv', $seen['note'], 'The note of the replacement before the first rename.' );
		self::assertIsArray( $seen['step'] );
		self::assertSame( 'begun', $seen['step']['state'], 'The step begun before the first rename.' );
		self::assertSame( array( 'kind' => 'plugin', 'op' => 'swap', 'ref' => 'ab-it-aktiv' ), $seen['step']['parts']['unit'], 'The journal names the unit (stored in its canonical form, its keys sorted).' );
		self::assertSame( $seen['note']['plugin:ab-it-aktiv'], $seen['step']['parts']['since'], 'The note and the journal hold the same beginning.' );
		self::assertSame( $target, $seen['step']['parts']['target'] );
		self::assertCount( 1, $seen['marks'], 'One mark of this step before the first rename.' );
		$mark = $seen['marks'][0];
		self::assertSame( array( 'open', 'swap', $target ), array( $mark['state'], $mark['op'], $mark['target'] ) );
		self::assertSame( array( 'kind' => 'plugin', 'ref' => 'ab-it-aktiv' ), $mark['unit'] );
		$trees = array_column( $mark['trees'], null, 'role' );
		self::assertSame( array( 'backup', 'stage' ), array_keys( $trees ) );
		self::assertSame( array( 'backup' => false, 'stage' => true ), $seen['staged'], 'At the first rename the stage is there and the place of the backup free.' );
		$stage = AB_MCP_Protected_Store::root() . '/' . $trees['stage']['path'];
		self::assertSame( array( $old['dev'], $old['ino'] ), array( $trees['backup']['dev'], $trees['backup']['ino'] ), 'The mark names the old folder by its identity.' );
		self::assertIsString( $seen['guide'], 'The instruction lies beside the mark.' );
		self::assertStringContainsString( '[nach-den-umbenennungen]', $seen['guide'] );
		self::assertStringContainsString( $target, $seen['guide'] );
		self::assertSame( '1.0.0', $seen['fassung'], 'The target is the old version until the first rename.' );

		// The step done, two renames, no swap back.
		self::assertSame( array( 3, 'done' ), array( $swapped['step']['seq'], $swapped['step']['state'] ), $swapped['next'] ?? '' );
		self::assertSame( 2, $renames, 'Two renames, no swap back.' );
		$result = self::step( $run_id, 3 )['result'];
		self::assertSame( array( $target, $mark['mid'] ), array( $result['target'], $result['mark'] ) );
		$backup = $result['backup'];
		self::assertSame( AB_MCP_Protected_Store::root() . '/' . $trees['backup']['path'], $backup, 'The backup is the one the mark names (relative to the store).' );
		// The new version at the target, the stage itself (its identity); the
		// stage is gone from the store.
		clearstatcache();
		self::assertSame( '2.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
		$now = lstat( $target );
		self::assertSame( array( $trees['stage']['dev'], $trees['stage']['ino'] ), array( $now['dev'], $now['ino'] ), 'The stage is the target now.' );
		self::assertFalse( file_exists( $stage ), 'The stage is gone from the store.' );
		// The rights of the site, given before the first write (Codex D1,
		// finding 2): each folder and file of the stage set once, before the
		// note of the replacement; the stage came with the rights of its
		// building folder; at the target each folder and file has the
		// rights WordPress gives them, and the hash is the one of staging.
		$rights = self::site_rights();
		$at     = array();
		foreach ( $chmods as $chmod ) {
			self::assertFalse( $chmod[2], 'The rights before the note of the replacement: ' . $chmod[0] );
			self::assertSame( 0, strpos( $chmod[0], $stage ), 'Only the stage takes the rights: ' . $chmod[0] );
			$at[ $target . substr( $chmod[0], strlen( $stage ) ) ] = $chmod[1];
		}
		self::assertSame( array( 0700, 0700, 0600 ), array( $at[ $target ], $at[ $target . '/includes' ], $at[ $target . '/includes/teil.inc' ] ), 'The stage came with the rights of a stage of a package (package_rights()).' );
		$tree = array( $target );
		foreach ( AB_MCP_IT_Code::files( $target ) as $rel ) {
			for ( $dir = dirname( $rel ); '.' !== $dir; $dir = dirname( $dir ) ) {
				$tree[] = $target . '/' . $dir;
			}
			$tree[] = $target . '/' . $rel;
		}
		$tree = array_values( array_unique( $tree ) );
		sort( $tree, SORT_STRING );
		$set = array_keys( $at );
		sort( $set, SORT_STRING );
		self::assertSame( $tree, $set, 'Each folder and file of the new version took the rights once.' );
		self::assertContains( $target . '/includes', $tree, 'A folder below the top as well.' );
		clearstatcache();
		foreach ( $tree as $path ) {
			self::assertSame( sprintf( '%04o', $rights[ is_dir( $path ) ? 'folder' : 'file' ] ), sprintf( '%04o', fileperms( $path ) & 07777 ), 'The rights of the site at ' . $path );
		}
		$read = \AB_MCP_Sync_File_Manifest::unit( dirname( $target ), 'ab-it-aktiv', 'live', microtime( true ) + 30, true, 'plugin' );
		self::assertSame( array( true, $unit['staging_hash'] ), array( $read['complete'], $read['hash'] ), 'The rights are no part of the hash: the new version read at the target is the one of staging.' );
		// The old folder whole in the backup: its bytes and its identity.
		self::assertSame( self::digest( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '1.0.0' ) ), self::digest( $backup ), 'The old version whole in the backup.' );
		$kept = lstat( $backup );
		self::assertSame( array( $old['dev'], $old['ino'] ), array( $kept['dev'], $kept['ino'] ), 'The backup is the old folder itself.' );
		// OPcache: after the second rename, each PHP file of the old and of the
		// new version by its path at the target; none between the renames.
		$php = array();
		foreach ( array( '1.0.0', '2.0.0' ) as $version ) {
			foreach ( AB_MCP_IT_Code::files( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', $version ) ) as $rel ) {
				if ( AB_MCP_Sync_Code_Stage::is_php( $rel ) ) {
					$php[ $target . '/' . $rel ] = true;
				}
			}
		}
		$want = array_keys( $php );
		sort( $want, SORT_STRING );
		self::assertSame( array(), array_filter( $opcache, static fn( $call ) => 1 === $call[0] ), 'No OPcache between the renames.' );
		self::assertSame( $want, array_column( array_filter( $opcache, static fn( $call ) => 2 === $call[0] ), 1 ), 'OPcache for each PHP file replaced.' );
		self::assertContains( $target . '/includes/teil.inc', $want, 'A file PHP runs that does not end in .php is one of them (Codex D1, finding 1).' );
		self::assertSame( count( $want ), $result['opcache'] );
		// The mark closed (Z20a).
		$marks = AB_MCP_Protected_Store::marks();
		self::assertIsArray( $marks );
		$closed_mark = array_values( array_filter( $marks['marks'], static fn( $m ) => $m['mid'] === $mark['mid'] ) );
		self::assertCount( 1, $closed_mark );
		self::assertSame( 'closed', $closed_mark[0]['state'], 'The mark is closed.' );
		// The pair: its files based on the version of staging, by this run.
		$pair = AB_MCP_Sync_Pairs::get( $unit['pair_id'] );
		self::assertIsArray( $pair );
		self::assertSame( array( 'live' => $unit['staging_hash'], 'staging' => $unit['staging_hash'] ), AB_MCP_Sync_Pairs::part_bases( $pair, 'files' ), 'The files of the pair take the version of staging as their base on both sides.' );
		self::assertSame( array( $run_id, 'push' ), array( $pair['last_run'], $pair['base_origin'] ) );
		// The file undo points of the unit go, the other one stays.
		self::assertInstanceOf( WP_Error::class, AB_MCP_Undo::entry_for( $points['ab-it-aktiv'] ), 'The file undo point of the unit is discarded.' );
		self::assertIsArray( AB_MCP_Undo::entry_for( $points['ab-it-inaktiv'] ), 'The file undo point of another plugin stays.' );
		self::assertSame( 1, $result['discarded'] );
		// The plugin still runs, in its new version, and the lock for changes
		// is given back.
		self::assertContains( $main, self::active(), 'The plugin is still active.' );
		self::assertFalse( AB_MCP_Sync_Code_Swap::lock_held(), 'The lock for changes is given back.' );
		self::assertSame( '2.0.0', AB_MCP_IT_Code::mark( 'a', 'ab-it-aktiv' ), 'The site runs the new version.' );
		// The answer: target, backup, the way back, the commands by hand.
		$says = $swapped['next'];
		self::assertStringContainsString( 'version 1.0.0 here before, 2.0.0 now', $says );
		self::assertStringContainsString( 'its new version lies at ' . $target, $says );
		self::assertStringContainsString( 'the old one whole in the backup ' . $backup, $says );
		self::assertStringContainsString( 'wp_site_revert action=plan with run_id ' . $run_id, $says );
		self::assertStringContainsString( " && mv '" . $target . "' '" . $stage . "' && ", $says, 'The command that moves the new version out, after its checks.' );
		self::assertStringContainsString( " && mv '" . $backup . "' '" . $target . "' && ", $says, 'The command that puts the old version back.' );
		self::assertNotSame( array(), $result['by_hand'] );
		$commands = array_values( array_filter( $result['by_hand'], static fn( $line ) => 0 === strpos( $line, '$ ' ) ) );
		self::assertCount( 1, $commands, 'One command line, its parts joined with && (Codex D1, finding 6).' );
		self::assertSame( 0, strpos( $commands[0], '$ test ' ), 'Its checks first.' );
		self::assertSame( ' && echo OK', substr( $commands[0], -11 ), 'And OK at its end.' );
		self::assertStringContainsString( 'Then clear OPcache of this site, or have PHP restarted', $says, 'After putting back, OPcache (Codex D1, finding 7).' );
		self::assertStringContainsString( "\n" . implode( "\n", $result['by_hand'] ) . "\n", $says, 'The answer carries the commands of the result, one after the other.' );
		$closed = self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) );
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free.' );
	}

	/**
	 * Prüft: Codex D1, finding 2: where the new version cannot be given the
	 * rights of the site (chmod() fails, or the rights do not read back as
	 * set), the swap is refused before its first write: the step stays
	 * planned, nothing of the site changed (no note of the replacement, no
	 * mark, the old folder at the target), and the next call swaps it.
	 * Sonst: the swap would put the new version into the site with the
	 * rights of its building folder (0700), so that a web server under
	 * another user reads none of its styles and images while the step says
	 * done; or a refusal there would leave the site half changed.
	 */
	#[DataProvider( 'rights_faults' )]
	public function testANewVersionThatCannotTakeTheRightsOfTheSiteIsNotSwappedAndItsStepStaysPlanned( array $faults ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		clearstatcache();
		$old     = lstat( $target );
		$note    = self::replaced_row();
		$refused = self::swap_with( $run_id, $faults );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'code:rights', $refused->get_error_code(), $refused->get_error_message() );
		self::assertStringContainsString( 'could not be given the rights WordPress gives the files of a plugin or theme it installs', $refused->get_error_message() );
		self::assertStringContainsString( 'Call action=next again', $refused->get_error_message() );
		self::assertSame( 'planned', self::step( $run_id, 3 )['state'], 'The step stays planned.' );
		clearstatcache();
		$now = lstat( $target );
		self::assertSame( array( $old['dev'], $old['ino'] ), array( $now['dev'], $now['ino'] ), 'The old folder is at the target.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
		self::assertSame( $note, self::replaced_row(), 'No note of the replacement.' );
		self::assertNull( self::mark_of_swap( $run_id ), 'No mark.' );
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Sync_Code_Swap::forget();
		$swapped = self::next( $run_id );
		self::assertIsArray( $swapped, is_wp_error( $swapped ) ? $swapped->get_error_code() . ': ' . $swapped->get_error_message() : '' );
		self::assertSame( 'done', $swapped['step']['state'], 'The next call swaps it.' );
	}

	/**
	 * The faults of the rights: the call fails; the stage with the rights of
	 * a stage of a package (package_rights(), at the first call), and the
	 * call for its third entry, the folder includes, answers true and sets
	 * nothing (it keeps 0700).
	 */
	public static function rights_faults(): array {
		$package = array(
			'method' => 'chmod',
			'nth'    => 1,
			'kind'   => 'before',
			'before' => array( self::class, 'package_rights_of_call' ),
		);
		return array(
			'chmod() fails'                      => array( array( array( 'method' => 'chmod', 'nth' => 2, 'kind' => 'return', 'value' => false ) ) ),
			'the rights do not read back as set' => array( array( $package, array( 'method' => 'chmod', 'nth' => 3, 'kind' => 'return', 'value' => true ) ) ),
		);
	}

	/** package_rights() for the path of a call of the seam. */
	public static function package_rights_of_call( string $method, array $args ): void {
		self::package_rights( $args[0] );
	}

	/**
	 * Prüft: Z16 and F35 without a reservation of the place (the lead after
	 * Codex D1, round 2): each rename goes only to a place that is free
	 * (lstat()), and rename() replaces no folder with content (rename(2),
	 * ENOTEMPTY). Another process that puts a folder at the place of the
	 * backup keeps it, before the swap looks or with files in it between the
	 * look and the rename (the step fails, the old version at its place, the
	 * mark closed on it); where another process put a folder at the target
	 * before the way back, nothing is moved onto it, the step is unclear, and
	 * the command by hand for the backup the answer proved stops at its
	 * check and moves nothing onto that folder (F35 has its own test).
	 * Sonst: the swap could replace a folder another process put at a place
	 * and with it what it holds, or put its backup into the site where
	 * another process put a folder, also with the command of its answer.
	 */
	#[DataProvider( 'places' )]
	public function testASwapRenamesOnlyOntoAFreePlaceAndReplacesNothingAnotherProcessPutThere( string $case ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		clearstatcache();
		$old     = lstat( $target );
		$foreign = null;
		// A folder of another process at a place, with a file in it or empty.
		$put    = static function ( string $place, bool $files ) use ( &$foreign ): void {
			self::assertTrue( mkdir( $place, 0700 ) );
			if ( $files ) {
				self::assertNotFalse( file_put_contents( $place . '/fremd.txt', 'fremd' ) );
			}
			clearstatcache();
			$foreign = array( $place, fileinode( $place ), $files );
		};
		$faults = array(
			'an empty folder at the place of the backup before the swap looks' => array(
				array(
					'method' => 'put_mark',
					'nth'    => 1,
					'kind'   => 'before',
					'before' => static function ( $method, array $args ) use ( $put ): void {
						$put( array_column( $args[0]['trees'], 'path', 'role' )['backup'], false );
					},
				),
			),
			'a folder with a file at the place of the backup between the look and the rename' => array(
				array(
					'method' => 'rename',
					'nth'    => 1,
					'kind'   => 'before',
					'before' => static function ( $method, array $args ) use ( $put ): void {
						$put( $args[1], true );
					},
				),
			),
			'a folder with a file at the target before the way back' => array(
				array(
					'method' => 'rename',
					'nth'    => 2,
					'kind'   => 'before',
					'before' => static function ( $method, array $args ) use ( $put ): void {
						// The stage aside, so that the second rename fails; at the
						// target, free now, a folder of another process.
						self::assertTrue( rename( $args[0], $args[0] . '-beiseite' ) );
						$put( $args[1], true );
					},
				),
			),
		);
		$answer = self::swap_with( $run_id, $faults[ $case ] );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step = self::step( $run_id, 3 );
		$says = $answer['next'];
		clearstatcache();
		$now = lstat( $target );
		if ( null !== $foreign ) {
			self::assertSame( $foreign[1], fileinode( $foreign[0] ), 'The folder of the other process stays: nothing replaced it.' );
			self::assertSame( $foreign[2] ? array( '.', '..', 'fremd.txt' ) : array( '.', '..' ), scandir( $foreign[0] ), 'Nothing moved into it.' );
			if ( $foreign[2] ) {
				self::assertSame( 'fremd', file_get_contents( $foreign[0] . '/fremd.txt' ), 'What the other process put there stays.' );
			}
		}
		if ( 'a folder with a file at the target before the way back' === $case ) {
			self::assertSame( 'unclear', $step['state'], $says );
			self::assertSame( $target, $foreign[0] );
			$kept = lstat( $step['result']['backup'] );
			self::assertSame( array( $old['dev'], $old['ino'] ), array( $kept['dev'], $kept['ino'] ), 'The old version stays whole in the backup.' );
			self::assertStringContainsString( 'and the old one could not be put back', $says );
			self::assertSame( array( 'open', 'open' ), array( self::mark_of_swap( $run_id )['state'], $step['result']['mark'] ) );
			// The backup proved, the answer gives its command; its checks
			// come first, so it moves nothing onto the folder at the target.
			self::assertStringContainsString( " && mv '" . $step['result']['backup'] . "' '" . $target . "' && ", $says );
			self::assertStringNotContainsString( 'OK', self::run_by_hand( $step['result']['by_hand'] ), 'The command stops at its check.' );
			clearstatcache();
			self::assertSame( array( $foreign[1], array( '.', '..', 'fremd.txt' ) ), array( fileinode( $target ), scandir( $target ) ), 'The folder of the other process stays as it was.' );
			$kept = lstat( $step['result']['backup'] );
			self::assertSame( array( $old['dev'], $old['ino'] ), array( $kept['dev'], $kept['ino'] ), 'The old version stays in the backup.' );
			return;
		}
		self::assertSame( 'failed', $step['state'], $says );
		self::assertSame( array( $old['dev'], $old['ino'] ), array( $now['dev'], $now['ino'] ), 'The old version at its place.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
		self::assertSame( 'closed', self::mark_of_swap( $run_id )['state'], 'The mark closed on the old version (kept).' );
		self::assertStringContainsString( 'The old version could not be moved into the backup of the run', $says );
	}

	/** The places of a rename another process or a failure takes. */
	public static function places(): array {
		return array(
			'an empty folder at the place of the backup before the swap looks' => array( 'an empty folder at the place of the backup before the swap looks' ),
			'a folder with a file at the place of the backup between the look and the rename' => array( 'a folder with a file at the place of the backup between the look and the rename' ),
			'a folder with a file at the target before the way back' => array( 'a folder with a file at the target before the way back' ),
		);
	}

	/** The command line of a way by hand (the one line of by_hand that begins with "$ "), run in bash without -e: its output. */
	private static function run_by_hand( array $by_hand ): string {
		$lines = array_values( array_filter( $by_hand, static fn( $line ) => 0 === strpos( $line, '$ ' ) ) );
		self::assertCount( 1, $lines, 'One command line.' );
		$out = array();
		exec( 'bash -c ' . escapeshellarg( substr( $lines[0], 2 ) ) . ' 2>&1', $out );
		return implode( "\n", $out );
	}

	/**
	 * Prüft: F35 and the way back of the swap (T3 2 and 6): where the second
	 * rename fails, the old version goes back to the target, nothing is
	 * changed, the step ends failed with the mark closed (kept), and its
	 * journal holds the swap and the answer. Where the way back fails too,
	 * the step ends unclear with the mark open, and the answer first proves
	 * the backup (the lead on D2a, «Zu 2»): proved, it gives the one command
	 * line that puts the old version back, and that line does it; not proved
	 * (the backup changed) or with no time left, no such command, but the
	 * sentence to check the backup first, and the way back of the run. In
	 * each case the lock for changes is free and nothing is lost.
	 * Sonst: a failed swap could leave the site without its plugin, a person
	 * would have no way by hand that works, or would put a backup into the
	 * site that was never proved.
	 */
	#[DataProvider( 'ways_back' )]
	public function testASecondRenameThatFailsGoesBackOrEndsUnclearWithAWayByHandThatWorks( string $case ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$seen   = null;
		$aside  = static function ( $method, array $args ): void {
			// The stage aside, so that the second rename fails.
			self::assertTrue( rename( $args[0], $args[0] . '-beiseite' ) );
		};
		$second = array(
			'the second rename fails'                => array(
				'kind'  => 'return',
				'value' => false,
			),
			'the way back fails, the backup proved'  => array(
				'kind'  => 'return',
				'value' => false,
			),
			'the way back fails, the backup changed' => array(
				'kind'   => 'before',
				'before' => static function ( $method, array $args ) use ( $aside, &$seen ): void {
					self::assertNotFalse( file_put_contents( $seen['backup'] . '/fremd.txt', 'fremd' ) );
					$aside( $method, $args );
				},
			),
			'the way back fails, no time left'       => array(
				'kind'   => 'before',
				'before' => static function ( $method, array $args ) use ( $aside ): void {
					AB_MCP_Sync_Env::$clock = static fn(): float => microtime( true ) + 86400.0;
					$aside( $method, $args );
				},
			),
		);
		$faults = array(
			self::watch( $seen ),
			array(
				'method' => 'rename',
				'nth'    => 2,
			) + $second[ $case ],
		);
		if ( 'the second rename fails' !== $case ) {
			$faults[] = array(
				'method' => 'rename',
				'nth'    => 3,
				'kind'   => 'return',
				'value'  => false,
			);
		}
		try {
			$answer = self::swap_with( $run_id, $faults );
		} finally {
			AB_MCP_Sync_Env::$clock = null;
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step = self::step( $run_id, 3 );
		$says = $answer['next'];
		self::assertStringContainsString( $step['result']['message'], $says, 'The journal holds the answer.' );
		self::assertSame( array( array( 'kind' => 'plugin', 'op' => 'swap', 'ref' => 'ab-it-aktiv' ), $target, $seen['backup'], $seen['stage'] ), array( $step['parts']['unit'], $step['parts']['target'], $step['parts']['old']['path'], $step['parts']['new']['path'] ), 'The journal holds the swap.' );
		$mark = self::mark_of_swap( $run_id );
		if ( 'the second rename fails' === $case ) {
			self::assertSame( 'failed', $step['state'], $says );
			self::assertStringContainsString( 'so the old one went back to its place', $says );
			self::assertSame( 'closed', $mark['state'], 'The mark closed on the old version (kept).' );
			self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
			self::assertSame( $seen['old'], self::digest( $target ), 'Nothing of the site changed.' );
			self::nothing_lost( $seen );
			return;
		}
		self::assertSame( 'unclear', $step['state'], $says );
		self::assertStringContainsString( 'and the old one could not be put back', $says );
		self::assertSame( array( 'open', 'open' ), array( $mark['state'], $step['result']['mark'] ), 'The mark stays open.' );
		self::assertStringContainsString( 'wp_site_revert action=plan with run_id ' . $run_id, $says, 'The way back of the run.' );
		if ( 'the way back fails, the backup proved' === $case ) {
			self::nothing_lost( $seen );
			self::assertStringContainsString( " && mv '" . $seen['backup'] . "' '" . $target . "' && ", $says, 'The command that puts the proved backup back.' );
			self::assertStringEndsWith( 'OK', self::run_by_hand( $step['result']['by_hand'] ), 'The command does all of it.' );
			clearstatcache();
			self::assertSame( array( '1.0.0', $seen['old'] ), array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ) ), 'The old version is back, whole.' );
			return;
		}
		self::assertStringContainsString( 'The backup ' . $seen['backup'] . ' is not proved as the old version, so this answer gives no command that puts it back: check the backup first', $says );
		self::assertSame( array(), $step['result']['by_hand'], 'No command by hand.' );
		self::assertStringNotContainsString( "mv '", $says, 'No command moves anything.' );
		if ( 'the way back fails, the backup changed' === $case ) {
			// What the test put into the backup goes aside again (nothing deleted).
			self::assertTrue( rename( $seen['backup'] . '/fremd.txt', $seen['backup'] . '-fremd.txt' ) );
		}
		self::nothing_lost( $seen, array( $seen['stage'] . '-beiseite' ) );
	}

	/** The second rename and the way back, each failing. */
	public static function ways_back(): array {
		return array(
			'the second rename fails (F35)'          => array( 'the second rename fails' ),
			'the way back fails, the backup proved'  => array( 'the way back fails, the backup proved' ),
			'the way back fails, the backup changed' => array( 'the way back fails, the backup changed' ),
			'the way back fails, no time left'       => array( 'the way back fails, no time left' ),
		);
	}

	/** The renames the seams of this test saw. */
	private static function renames(): int {
		$n = 0;
		foreach ( AB_MCP_Test_Seam::recorded() as $calls ) {
			$n += count( array_filter( $calls, static fn( $call ) => 'rename' === $call['method'] ) );
		}
		return $n;
	}

	/**
	 * Prüft: F36 (T3 2): where the journal of the swap cannot be written
	 * (its first write, begun) or the place of the backup is not given
	 * (put_tree() refuses: a tree of that name is there), there is no swap
	 * and no rename; the step stays planned, the answer names the way on,
	 * the lock for changes is free, and the old version is at the target as
	 * it was; with the place refused, not even the note of the replacement
	 * is written.
	 * Sonst: a swap could begin without its journal, so that no way back
	 * knows what it moved, or a step would end failed where the next call
	 * could do it.
	 */
	#[DataProvider( 'not_begun' )]
	public function testASwapWhoseJournalOrBackupCannotBeWrittenMovesNothingAndItsStepStaysPlanned( string $case ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$old    = self::digest( $target );
		$note   = self::replaced_row();
		$faults = array();
		if ( 'the journal not written' === $case ) {
			$faults[] = array(
				'method' => 'journal',
				'nth'    => 1,
				'kind'   => 'return',
				'value'  => new WP_Error( 'ab_it_db', 'The database refused the write.' ),
			);
		} else {
			$place = AB_MCP_Protected_Store::put_tree( 'backups', AB_MCP_Sync_Backup::group( $run_id ), basename( $target ), $target );
			self::assertIsArray( $place, is_wp_error( $place ) ? $place->get_error_message() : '' );
			self::assertTrue( mkdir( $place['path'], 0700 ), 'A tree of that name at the place of the backup.' );
		}
		$refused = self::swap_with( $run_id, $faults );
		self::assertInstanceOf( WP_Error::class, $refused );
		$says = $refused->get_error_message();
		self::assertSame( 'planned', self::step( $run_id, 3 )['state'], 'The step stays planned.' );
		self::assertSame( 0, self::renames(), 'No rename.' );
		self::assertNull( self::mark_of_swap( $run_id ), 'No mark.' );
		self::assertFalse( AB_MCP_Sync_Code_Swap::lock_held(), 'The swap holds no lock for changes.' );
		self::assertTrue( AB_MCP_Change_Lock::take(), 'The lock for changes can be taken again.' );
		AB_MCP_Change_Lock::release();
		clearstatcache();
		self::assertSame( array( '1.0.0', $old ), array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ) ), 'The old version at the target as it was.' );
		if ( 'the journal not written' === $case ) {
			self::assertSame( 'code:journal', $refused->get_error_code(), $says );
			self::assertStringContainsString( 'could not be written and read back before its first rename (The database refused the write.), so nothing of it was moved. Ask for the status of the run (wp_site_push action=status) and go on from there', $says );
			return;
		}
		self::assertStringContainsString( 'So the plugin ab-it-aktiv is not swapped. Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort) and plan again.', $says );
		self::assertSame( $note, self::replaced_row(), 'No note of the replacement.' );
	}

	/** What keeps a swap from beginning. */
	public static function not_begun(): array {
		return array(
			'the journal not written (F36)'         => array( 'the journal not written' ),
			'the place of the backup refused (F36)' => array( 'the place of the backup refused' ),
		);
	}

	/**
	 * Prüft: Codex D1, findings 4, 5 and 8: after both renames a step not
	 * proved ends unclear, and its answer says what is proved. A listing of
	 * the files PHP runs that is not whole (a link in the new version, an
	 * entry whose lstat() fails) clears no OPcache for good and leaves the
	 * mark open, with the sentence to have OPcache cleared at the host; the
	 * backup counts as proved only where in_backup() answered true (finding
	 * 5, round 2): where the listing stopped before close(), the answer asks
	 * it first and, proved, gives the command that puts it back (the lead on
	 * D2a); where it changed, no command, but the sentence to check it
	 * first, with its path; a pair that takes no new bases after the mark
	 * was closed names the mark closed, with the command.
	 * Sonst: the step would be done with old code left in OPcache, a person
	 * would put a backup that was never proved as the old version into the
	 * site with the commands of the answer, or would look for an open mark
	 * that is closed.
	 */
	#[DataProvider( 'after_renames' )]
	public function testAfterTheRenamesAStepNotProvedIsUnclearAndItsAnswerSaysWhatIsProved( string $case ): void {
		list( $run_id, $unit, $target ) = self::ready_to_swap();
		$backup = null;
		$hooks  = array(
			'a link in the new version'          => static function ( $method, array $args ): void {
				self::assertTrue( symlink( '/ab-it-verweis-ohne-ziel', $args[0] . '/verweis' ) );
			},
			'an entry not read'                  => static function ( $method, array $args ): void {
				self::assertTrue( chmod( $args[0] . '/includes', 0600 ) );
			},
			'the backup changed'                 => static function () use ( &$backup ): void {
				file_put_contents( $backup . '/fremd.txt', 'fremd' );
			},
			'the pair changed before its bases' => static function () use ( $unit ): void {
				global $wpdb;
				self::assertSame( 1, $wpdb->update( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'last_run' => 999999 ), array( 'id' => (int) $unit['pair_id'] ) ) );
			},
		);
		try {
			$answer = self::swap_with(
				$run_id,
				array(
					array(
						'method' => 'rename',
						'nth'    => 1,
						'kind'   => 'before',
						'before' => static function ( $method, array $args ) use ( &$backup ): void {
							$backup = $args[1];
						},
					),
					array(
						'method' => 'rename',
						'nth'    => 2,
						'kind'   => 'before',
						'before' => $hooks[ $case ],
					),
				)
			);
		} finally {
			if ( is_dir( $target . '/includes' ) ) {
				chmod( $target . '/includes', 0755 );
			}
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step   = self::step( $run_id, 3 );
		$says   = $answer['next'];
		$result = $step['result'];
		self::assertSame( 'unclear', $step['state'], $says );
		self::assertSame( '2.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), 'The new version is at the target.' );
		$mark = self::mark_of_swap( $run_id );
		if ( 'the backup changed' === $case ) {
			// in_backup() in close() answered false: not asked again.
			self::assertStringContainsString( 'The step is unclear, and the mark of the swap stays open. The backup ' . $backup . ' is not proved as the old version, so this answer gives no command that puts it back: check the backup first', $says );
			self::assertSame( array(), $result['by_hand'], 'No command by hand.' );
			self::assertStringNotContainsString( "mv '" . $backup . "'", $says, 'No command moves the backup.' );
			self::assertSame( array( 'open', 'open' ), array( $mark['state'], $result['mark'] ) );
			return;
		}
		// The backup proved: by close() (the pair) or, where the listing
		// stopped before close(), by unclear() (the lead on D2a).
		self::assertNotSame( array(), $result['by_hand'] );
		self::assertStringContainsString( " && mv '" . $backup . "' '" . $target . "' && ", $says, 'The command that puts the proved backup back.' );
		if ( 'the pair changed before its bases' === $case ) {
			self::assertStringContainsString( 'the files of its pair did not take their new bases', $says );
			self::assertStringContainsString( 'The step is unclear; the mark of the swap is closed, as the new version at the target and the old one in the backup were proved.', $says );
			self::assertStringNotContainsString( 'stays open', $says, 'The mark is not named open.' );
			self::assertSame( array( 'closed', 'closed' ), array( $mark['state'], $result['mark'] ) );
			return;
		}
		self::assertStringContainsString( 'The PHP files in ' . $target . ' could not all be listed', $says );
		self::assertStringContainsString( 'Have OPcache of this site cleared at the host, or PHP restarted (PHP-FPM or the web server), so that PHP runs the files that are at the target now.', $says );
		self::assertStringContainsString( 'The step is unclear, and the mark of the swap stays open: the way back of this run puts the old version back', $says );
		self::assertSame( array( 'open', 'open' ), array( $mark['state'], $result['mark'] ) );
	}

	/** What is not proved after the renames. */
	public static function after_renames(): array {
		return array(
			'a link in the new version (findings 4 and 5)'   => array( 'a link in the new version' ),
			'an entry not read (findings 4 and 5)'           => array( 'an entry not read' ),
			'the backup changed (finding 5)'                 => array( 'the backup changed' ),
			'the pair changed before its bases (finding 8)' => array( 'the pair changed before its bases' ),
		);
	}

	public function testAUnitThisSiteHasAsTheStagingSiteHasItAtItsStepIsDoneWithSameAndNothingIsStagedOrChanged(): void {
		// B1 of the Fable check 2 on d1e0c77: the unit was changed on the
		// staging site when the report was made, and between the plan and its
		// step this site got the same files (here: the version of the staging
		// site put on A after the check of the run). The step of the stage is
		// done with same: true and the sentence of the way; nothing is fetched
		// or staged (no group of the stage, no journal), and the step changes
		// nothing of the site.
		list( $args )  = self::changed( 'ab-it-inaktiv', '1.1.0' );
		$calls         = self::wporg();
		list( , $run ) = self::begun( $args );
		$run_id        = (int) $run['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check of the run.' );
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		self::assertSame( '1.1.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ), 'This site has the files of the staging site now.' );
		$before = self::live_bytes( 'ab-it-inaktiv' );

		$same = self::next( $run_id );
		self::assertIsArray( $same, is_wp_error( $same ) ? $same->get_error_code() . ': ' . $same->get_error_message() : '' );
		self::assertSame( array( 2, 'write', 'done' ), array( $same['step']['seq'], $same['step']['op'], $same['step']['state'] ) );
		self::assertStringContainsString( 'This site already has the plugin ab-it-inaktiv as the staging site has it (the same files), so nothing is staged and nothing of it is swapped.', $same['next'] );
		self::assertStringContainsString( 'confirm its pair anew (wp_site_pairs)', $same['next'], 'With the way.' );
		$step = self::step( $run_id, 2 );
		self::assertSame( array( 'done', null ), array( $step['state'], $step['parts'] ), 'Done, with no journal of a stage.' );
		self::assertSame( array( 'kind' => 'plugin', 'ref' => 'ab-it-inaktiv' ), $step['result']['staged'] );
		self::assertTrue( $step['result']['same'] );
		self::assertStringContainsString( 'so nothing is staged and nothing of it is swapped', $step['result']['message'] );
		self::assertSame( array(), $calls->wporg->calls, 'Nothing fetched.' );
		self::assertSame( array(), AB_MCP_Protected_Store::groups( 'stage' ), 'No group of the stage.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the site changed.' );
		self::assertSame( '1.1.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ) );
		$closed = self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) );
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
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
		self::assertStringContainsString( 'Where the time of this call did not suffice, plan again (wp_site_push action=plan)', $message, 'With the way of each cause.' );
		self::assertStringContainsString( 'cannot be read: make it readable, then plan again', $message, 'With the way of each cause.' );
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
		// The unit not signed in the environment the plan of the run names,
		// through the public way (B of the Codex confirmation on d4a0a56):
		// the environment of the staging site taken over anew without the
		// unit, and the plan of the run naming it (its row written as a
		// plan that reads); the stage is the one of the step.
		global $wpdb;
		$runs = AB_MCP_Sync_Schema::table( 'runs' );
		$row  = $wpdb->get_var( $wpdb->prepare( "SELECT plan FROM `$runs` WHERE run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB
		self::assertIsString( $row );
		AB_MCP_IT_Code::remove( 'b', 'plugin', 'ab-it-inaktiv' );
		self::clear_b();
		$plan                = AB_MCP_Sync_Run::get( $run_id )['plan'];
		$plan['code']['env'] = self::staged( 'env' );
		$wpdb->update( $runs, array( 'plan' => wp_json_encode( $plan ) ), array( 'run_id' => $run_id ) ); // phpcs:ignore WordPress.DB
		try {
			$answer = self::next( $run_id );
		} finally {
			$wpdb->update( $runs, array( 'plan' => $row ), array( 'run_id' => $run_id ) ); // phpcs:ignore WordPress.DB
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
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
		// The stage checked and ready, the step planned again on it.
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		self::replan( $run_id, $journal );
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

	/** The folder of the group of a stage (AB_MCP_Protected_Store::group_name(): its area, its id and the id of the installation). */
	private static function stage_folder( string $gid ): string {
		$named = new \ReflectionMethod( AB_MCP_Protected_Store::class, 'group_name' );
		return (string) $named->invoke( null, 'stage', $gid, AB_MCP_Protected_Store::install_id( false ) );
	}

	/** Every file of the folder of a group of the stage by its SHA-256. */
	private static function stage_bytes( string $gid ): array {
		$out = array();
		$dir = AB_MCP_Protected_Store::root() . '/stage/' . self::stage_folder( $gid );
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$out[ substr( $file->getPathname(), strlen( $dir ) ) ] = hash_file( 'sha256', $file->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	public function testAStageStillCheckingNotBoundToThisStepIsNeverWrittenAndTheStepFails(): void {
		// A2 of the Codex confirmation on d4a0a56 (option A of the decision of
		// Thomas, 13.11. 14:22, K2): the stage holds the binding before it
		// writes anything. A journal of its form may name a stage still
		// checking of another run, another unit or another version of the
		// staging site: the step fails with its reason and way, and the stage
		// is never written, neither refused nor checked further (its state
		// and its check stay byte for byte).
		list( $run_id, $journal, $before ) = self::staged_run();
		$gid  = $journal['stage']['gid'];
		$item = AB_MCP_Sync_Code_Ops::item( AB_MCP_Sync_Run::get( $run_id )['plan'], 2 );
		self::assertIsArray( $item );
		$untouched = self::stage_bytes( $gid );
		self::assertCount( 1, preg_grep( '#^/' . AB_MCP_Sync_Code_Stage::STATE . '#', array_keys( $untouched ) ), 'Staged and still checking: its first state alone.' );
		$foreign = static function ( string $what ) use ( $run_id, $gid, $before, $untouched ): void {
			$step = self::stage_failed( $run_id, 'code:stage_foreign', $before );
			self::assertStringContainsString( 'is not bound to this run, this unit and the version of the staging site the plan names', $step['result']['message'], $what );
			self::assertSame( $untouched, self::stage_bytes( $gid ), "$what: the stage is not written." );
		};
		// The group bound to another run, through the public way; with an
		// environment of the step that would refuse the stage as well.
		self::assertTrue( AB_MCP_Protected_Store::bind( 'stage', $gid, $run_id + 1 ) );
		try {
			$answer = self::next( $run_id );
		} finally {
			self::assertTrue( AB_MCP_Protected_Store::bind( 'stage', $gid, $run_id ) );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$foreign( 'another run' );
		self::replan( $run_id, $journal );
		self::assertTrue( AB_MCP_Protected_Store::bind( 'stage', $gid, $run_id + 1 ) );
		try {
			AB_MCP_Sync_Code_Ops::run( AB_MCP_Sync_Run::get( $run_id ), self::step( $run_id, 2 ), array( 'env' => array() ) + $item );
		} finally {
			self::assertTrue( AB_MCP_Protected_Store::bind( 'stage', $gid, $run_id ) );
		}
		$foreign( 'another run, an environment without the unit' );
		// Another version of the staging site, another unit, another kind.
		$cases = array(
			'another staging_hash' => array( array( 'staging_hash' => str_repeat( 'e', 64 ) ), array() ),
			'another unit'         => array( array( 'ref' => 'ab-it-aktiv' ), array( 'ref' => 'ab-it-aktiv' ) ),
			'another kind'         => array( array( 'kind' => 'theme' ), array( 'kind' => 'theme' ) ),
		);
		foreach ( $cases as $what => list( $unit, $of ) ) {
			$named         = $journal;
			$named['unit'] = $of + $journal['unit'];
			self::replan( $run_id, $named );
			AB_MCP_Sync_Code_Ops::run( AB_MCP_Sync_Run::get( $run_id ), self::step( $run_id, 2 ), array( 'unit' => $unit + $item['unit'] ) + $item );
			$foreign( $what );
		}
		// A binding not of its form (a staging_hash that is no SHA-256): not
		// known, the step stays planned and the stage is not written.
		self::replan( $run_id, $journal );
		$got = AB_MCP_Sync_Code_Ops::run( AB_MCP_Sync_Run::get( $run_id ), self::step( $run_id, 2 ), array( 'unit' => array( 'staging_hash' => 'not-a-hash' ) + $item['unit'] ) + $item );
		self::assertStringContainsString( 'does not read in its form, so the stage is neither checked nor taken', self::refused( $got, 'code:stage_bound' ) );
		self::assertSame( 'planned', self::step( $run_id, 2 )['state'] );
		self::assertSame( $untouched, self::stage_bytes( $gid ), 'A binding not of its form: the stage is not written.' );
		// Bound as the plan names it: checked and done, its state written.
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		self::assertNotSame( $untouched, self::stage_bytes( $gid ), 'The stage of the step is checked.' );
	}

	public function testTheEntryOfTheGroupOfAStageNotReadLeavesTheStepPlannedNeverFailed(): void {
		// A2 of the Codex confirmation on d4a0a56 (K2): the run a group of the
		// stage is bound to is read from its entry by its id; an entry that
		// does not read is not known, never «not bound» (a group left out of
		// a listing): the step stays planned with the error and the way, and
		// the next call goes on. The read fails through the seam of the store
		// (naht_protected_store) for the sidecar of the group, wherever it is
		// read for anything but the state of the stage.
		list( $run_id, $journal, $before ) = self::staged_run();
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'Checked and ready.' );
		self::replan( $run_id, $journal );
		$calls = new SyncCodeRunEntryCalls( '/stage/' . self::stage_folder( $journal['stage']['gid'] ) . '.json' );
		AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, 'naht_protected_store', $calls );
		try {
			$error = self::next( $run_id );
		} finally {
			AB_MCP_Test_Seam::restore_all();
		}
		self::assertGreaterThan( 0, $calls->replaced, 'The entry of the group was read beside the state, and did not read.' );
		$message = self::refused( $error, 'ab_mcp_store_entry' );
		self::assertStringContainsString( 'so what the group is bound to is not known', $message, 'The error.' );
		self::assertStringContainsString( 'Step 2 stays planned', $message );
		self::assertStringContainsString( 'Call action=next again', $message, 'The way.' );
		self::assertSame( array( 'planned', 'planned' ), array( self::step( $run_id, 2 )['state'], self::step( $run_id, 3 )['state'] ) );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'Read again: done.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
	}

	public function testASidecarOfTheGroupOfAStageHoldingAnotherEntryLeavesTheStepPlannedAndTheStageUnwritten(): void {
		// B of the closed Codex confirmation on K2: the sidecar of the group,
		// named right, holds an entry whole but not the one of this group (a
		// copy gone wrong): the entry of another group (another gid), or an
		// entry of another area of the store with this gid. entry() refuses
		// it as not read whole; the step stays planned, never failed, its
		// journal stays, and the stage still checking is not written. The
		// sidecar as it is: checked and done.
		list( $run_id, $journal, $before ) = self::staged_run();
		$gid       = $journal['stage']['gid'];
		$untouched = self::stage_bytes( $gid );
		$side      = '/stage/' . self::stage_folder( $gid ) . '.json';
		$real      = (string) file_get_contents( AB_MCP_Protected_Store::root() . $side );
		$entry     = json_decode( $real, true );
		self::assertIsArray( $entry );
		self::assertSame( array( $gid, 'stage', $run_id ), array( $entry['gid'], $entry['area'], $entry['run_id'] ), 'The sidecar of the group of the stage, bound to the run.' );
		// Each entry in the bytes write_entry() writes (a sidecar in another
		// form does not read whole, the case of the test before).
		self::assertSame( $real, (string) json_encode( $entry, JSON_UNESCAPED_SLASHES ), 'The sidecar as the store writes it.' );
		$cases = array(
			'the entry of another group'        => array_replace( $entry, array( 'gid' => str_repeat( 'a' === $gid[0] ? 'b' : 'a', 32 ) ) ),
			'an entry of another area, its gid' => array_replace( $entry, array( 'area' => 'backups' ) ),
		);
		foreach ( $cases as $what => $other ) {
			$calls = new SyncCodeRunEntryCalls( $side, (string) json_encode( $other, JSON_UNESCAPED_SLASHES ) );
			AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, 'naht_protected_store', $calls );
			try {
				$error = self::next( $run_id );
			} finally {
				AB_MCP_Test_Seam::restore_all();
			}
			self::assertGreaterThan( 0, $calls->replaced, "$what: the entry of the group was read beside the state." );
			$message = self::refused( $error, 'ab_mcp_store_entry' );
			self::assertStringContainsString( 'so what the group is bound to is not known', $message, $what );
			self::assertStringContainsString( 'Step 2 stays planned', $message, $what );
			self::assertStringContainsString( 'Call action=next again', $message, "$what: the way." );
			self::assertSame( array( 'planned', 'planned' ), array( self::step( $run_id, 2 )['state'], self::step( $run_id, 3 )['state'] ), "$what: planned, never failed." );
			self::assertSame( $journal, self::step( $run_id, 2 )['parts'], "$what: the journal stays." );
			self::assertSame( $untouched, self::stage_bytes( $gid ), "$what: the stage is not written." );
		}
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The sidecar as it is: checked and done.' );
		self::assertNotSame( $untouched, self::stage_bytes( $gid ), 'The stage of the step is checked.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
	}

	public function testADamagedSidecarOfTheStageLeavesTheStepPlannedWithItsReasonUntilItIsBackOrTheRunIsAborted(): void {
		// K4 and K4d (Thomas, 13.11.2026: one honest message): the sidecar of
		// the group of the stage, there but damaged on disk (not JSON, the
		// entry of another group) while the unit is staged: wp_site_push
		// action=next refuses with the reason of the store, which claims
		// neither that the group is gone nor that it is there and names both,
		// and the way; the step stays planned, call after call, and the stage
		// is not written. The
		// sidecar back: the step goes on. Damaged again: abort closes the run.
		list( $run_id, $journal, $before ) = self::staged_run();
		$gid   = $journal['stage']['gid'];
		$side  = AB_MCP_Protected_Store::root() . '/stage/' . self::stage_folder( $gid ) . '.json';
		$real  = (string) file_get_contents( $side );
		$entry = json_decode( $real, true );
		self::assertSame( $real, (string) json_encode( $entry, JSON_UNESCAPED_SLASHES ), 'The sidecar as the store writes it.' );
		$unread = static function ( string $what, array $untouched ) use ( $run_id, $journal, $before ): void {
			$message = self::refused( self::next( $run_id ), 'ab_mcp_store_no_group' );
			self::assertStringContainsString( 'the store does not tell which of these is why', $message, $what );
			self::assertStringContainsString( 'It is not there: it was never opened here', $message, "$what: it may be gone." );
			self::assertStringContainsString( 'Or it is still there, but its entry (its sidecar) is missing, is no plain file, cannot be read, is not of its form or names another group', $message, "$what: or there and not read." );
			self::assertStringContainsString( 'Where it should still be there, try again', $message, "$what: the way of a group that is there." );
			self::assertStringNotContainsString( 'The group is not in the protected store', $message, "$what: never the claim that it is gone." );
			self::assertStringNotContainsString( 'The group is in the protected store', $message, "$what: never the claim that it is there." );
			self::assertStringNotContainsString( 'kept a day', $message, "$what: nothing on how long a group is kept (K4f)." );
			self::assertStringNotContainsString( 'as many days', $message, "$what: nothing on how long a group is kept (K4f)." );
			self::assertStringContainsString( 'Step 2 stays planned', $message, $what );
			self::assertStringContainsString( 'where it keeps failing, abort the run (wp_site_push action=abort)', $message, "$what: the way." );
			self::assertSame( array( 'planned', 'planned' ), array( self::step( $run_id, 2 )['state'], self::step( $run_id, 3 )['state'] ), "$what: planned, never failed." );
			self::assertSame( $journal, self::step( $run_id, 2 )['parts'], "$what: the journal stays." );
			self::assertSame( $untouched, self::stage_bytes( $journal['stage']['gid'] ), "$what: the stage is not written." );
			self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), "$what: nothing of the site changed." );
		};
		$untouched = self::stage_bytes( $gid );
		$damaged   = array(
			'not JSON'                   => '{',
			'the entry of another group' => (string) json_encode( array_replace( $entry, array( 'gid' => str_repeat( 'a' === $gid[0] ? 'b' : 'a', 32 ) ) ), JSON_UNESCAPED_SLASHES ),
		);
		foreach ( $damaged as $what => $bytes ) {
			file_put_contents( $side, $bytes );
			try {
				$unread( $what, $untouched );
				$unread( "$what, the next call", $untouched );
			} finally {
				file_put_contents( $side, $real );
			}
		}
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The sidecar back: checked and done.' );
		// The step planned again on its stage, the sidecar damaged: abort.
		self::replan( $run_id, $journal );
		$checked = self::stage_bytes( $gid );
		file_put_contents( $side, '{' );
		try {
			$unread( 'before the abort', $checked );
			$closed = self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) );
		} finally {
			file_put_contents( $side, $real );
		}
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_code() . ': ' . $closed->get_error_message() : '' );
		self::assertSame( 'closed_aborted', AB_MCP_Sync_Run::get( $run_id )['state'], 'The way the answer names closes the run.' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
	}

	/* ------------------------------------------- what does not read */

	public function testAStepOfTheCodeWhoseRunPlanOrJournalDoesNotReadInItsFormDoesNothing(): void {
		// V18 and V19 of the pre-check: what a step of the code reads comes
		// in the form of its row; what is not of it is not known, the step
		// does nothing, and nothing is taken for a step of the contents.
		list( $args ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		self::wporg();
		list( , $run ) = self::begun( $args );
		$run_id = (int) $run['run_id'];
		$before = self::live_bytes( 'ab-it-inaktiv' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'] );
		self::assertSame( 'planned', self::next( $run_id )['step']['state'], 'Staged; its journal names the stage.' );
		$journal = self::step( $run_id, 2 )['parts'];
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
		// The journal as it was: the check goes on. (A plan of the run that
		// does not read: testAPlanOfTheCodeThatDoesNotReadInTheRunRefusesItsStepAndWritesNothing.)
		$answer = self::next( $run_id );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() : '' );
		self::assertSame( 2, $answer['step']['seq'] );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the site changed in any of these.' );
	}

	public function testAStepAnotherCallMovedBetweenItsReadingAndItsWritingIsNeverWrittenOver(): void {
		// B1 of the Fable check 2 on d1e0c77, rows with a writer in between: a
		// step of the code writes the journal of its stage and its end only on
		// the step in the state it read it in, planned
		// (AB_MCP_Sync_Run::update_step() with that state). Where another call
		// moved it in between (here: ended it failed after this call read it),
		// neither is written over that: the answer is the move
		// (ab_mcp_sync_run_changed), the step stays as the other call left
		// it. (The stage made before its journal is refused stays a group of
		// the run without a journal, G-5-4.)
		list( $args )  = self::changed( 'ab-it-inaktiv', '1.1.0' );
		self::wporg();
		list( , $run ) = self::begun( $args );
		$run_id        = (int) $run['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check of the run.' );
		$item = AB_MCP_Sync_Code_Ops::item( AB_MCP_Sync_Run::get( $run_id )['plan'], 2 );
		self::assertIsArray( $item );
		// (Its keys in the order the row gives them back: sorted.)
		$other = array(
			'code'    => 'code:other_call',
			'message' => 'Ended by another call.',
		);
		// The step read planned, without a journal; another call ends it;
		// then the call that read it goes on with what it read.
		$moved = static function () use ( $run_id, $item, $other ): void {
			$read = self::step( $run_id, 2 );
			self::assertSame( array( 'planned', null ), array( $read['state'], $read['parts'] ) );
			self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 2, array( 'state' => 'failed', 'result' => $other ), 'planned' ) );
			$after   = self::steps_of( $run_id );
			$message = self::refused( AB_MCP_Sync_Code_Ops::run( AB_MCP_Sync_Run::get( $run_id ), $read, $item ), 'ab_mcp_sync_run_changed' );
			self::assertStringContainsString( 'Step 2 of run ' . $run_id . ' is no longer in the state this call read it in (planned; it is failed now)', $message );
			self::assertSame( $after, self::steps_of( $run_id ), 'Nothing is written over the step another call moved.' );
			$step = self::step( $run_id, 2 );
			self::assertSame( array( 'failed', null, $other ), array( $step['state'], $step['parts'], $step['result'] ) );
		};
		// The journal of the stage (:182): the unit is staged in this call.
		$before = self::live_bytes( 'ab-it-inaktiv' );
		$moved();
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
		// The end of the step (ended()): the unit is the same here as on the
		// staging site, so the step would be done at once.
		global $wpdb;
		$steps = AB_MCP_Sync_Schema::table( 'steps' );
		$wpdb->query( $wpdb->prepare( "UPDATE `$steps` SET state = 'planned', parts = NULL, result = NULL WHERE run_id = %d AND seq = 2", $run_id ) ); // phpcs:ignore WordPress.DB
		AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		$before = self::live_bytes( 'ab-it-inaktiv' );
		$moved();
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ) );
	}

	public function testAStageWhoseAnswerDoesNotReadAsAJournalFailsTheStepAndSavesNoJournal(): void {
		// B1 of the Codex check of 75d6a84 (entry c of B1 of the Fable check 2
		// on d1e0c77): only a journal that reads back in its form is saved
		// (AB_MCP_Sync_Code_Ops::stage(), the check before update_step()).
		// The answer of the stage comes here through the seam of the stage:
		// web_ready() gives back what stage() then answers unchanged (any
		// answer but true), here one whose fields are all there but its group
		// is no id of a group. The step fails with code:stage_unknown and the
		// way, no journal is saved, nothing is fetched or staged, nothing of
		// the site changed.
		list( $args )  = self::changed( 'ab-it-inaktiv', '1.1.0' );
		list( , $run ) = self::begun( $args );
		$run_id        = (int) $run['run_id'];
		$before        = self::live_bytes( 'ab-it-inaktiv' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check of the run.' );
		$wporg  = new AB_MCP_Test_Wporg();
		$answer = array(
			'gid'    => 'x',
			'name'   => 'ab-it-inaktiv',
			'hash'   => str_repeat( 'a', 64 ),
			'source' => 'wordpress.org',
			'files'  => 1,
			'bytes'  => 1,
			'state'  => AB_MCP_Sync_Code_Stage::CHECKING,
		);
		$seam   = AB_MCP_Test_Seam::install( AB_MCP_Sync_Code_Stage::class, 'naht_sync_code_stage', new SyncCodeRunStageCalls( $wporg ), array( array( 'method' => 'web_ready', 'kind' => 'return', 'value' => $answer ) ) );
		$failed = self::next( $run_id );
		self::assertSame( array( array( 'method' => 'web_ready', 'n' => 1, 'fault' => 'return' ) ), $seam->calls, 'The stage asked the store once, and got the answer of the seam.' );
		self::assertIsArray( $failed, is_wp_error( $failed ) ? $failed->get_error_code() . ': ' . $failed->get_error_message() : '' );
		self::assertSame( array( 2, 'failed' ), array( $failed['step']['seq'], $failed['step']['state'] ) );
		$step = self::step( $run_id, 2 );
		self::assertSame( array( 'failed', 'code:stage_unknown', null ), array( $step['state'], $step['result']['code'], $step['parts'] ), 'Failed, and no journal saved.' );
		self::assertStringContainsString( 'is not staged (code:stage_unknown), so it is not swapped; nothing of this site changed', $step['result']['message'] );
		self::assertStringContainsString( 'The stage did not answer in the form a step of the code takes (its group, tree and hash).', $step['result']['message'] );
		self::assertStringContainsString( 'The run goes on with the next step (action=next).', $failed['next'], 'With the way.' );
		self::assertSame( 'planned', self::step( $run_id, 3 )['state'], 'The swap did not run.' );
		self::assertSame( array(), $wporg->calls, 'Nothing fetched.' );
		self::assertSame( array(), self::stages_of( $run_id ), 'Nothing staged.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the site changed.' );
	}

	/** Of every step of a run: its number, its state, its journal and its result. */
	private static function steps_of( int $run_id ): array {
		$steps = AB_MCP_Sync_Run::steps( $run_id );
		self::assertIsArray( $steps );
		return array_map( static fn( $s ) => array( $s['seq'], $s['state'], $s['parts'], $s['result'] ), $steps );
	}

	/** The groups of the stage bound to a run. */
	private static function stages_of( int $run_id ): array {
		$groups = AB_MCP_Protected_Store::groups( 'stage' );
		self::assertIsArray( $groups );
		return array_values( array_filter( $groups, static fn( $g ) => (int) $g['run_id'] === $run_id ) );
	}

	public function testAPlanOfTheCodeThatDoesNotReadInTheRunRefusesItsStepAndWritesNothing(): void {
		// B of the Codex round 1 on the code (finding 4), through the public
		// way: the plan stored with a run of the code no longer reads in its
		// form (its row written so after the check of the run). wp_site_push
		// action=next refuses the step with the reason and the way; the run
		// stays open, no step is written, nothing is fetched or staged,
		// nothing of the site changes, and the step is never taken for a
		// step of the contents. The plan as it was: the step stages.
		list( $args ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		$calls        = self::wporg();
		list( , $run ) = self::begun( $args );
		$run_id = (int) $run['run_id'];
		$before = self::live_bytes( 'ab-it-inaktiv' );
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check of the run.' );
		global $wpdb;
		$runs = AB_MCP_Sync_Schema::table( 'runs' );
		$row  = $wpdb->get_var( $wpdb->prepare( "SELECT plan FROM `$runs` WHERE run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB
		self::assertIsString( $row );
		$plan                              = AB_MCP_Sync_Run::get( $run_id )['plan'];
		$kinds                             = $plan;
		$kinds['code']['units'][0]['kind'] = 'mu_plugin';
		$kinds['code']['units'][1]['kind'] = 'mu_plugin';
		$cases = array(
			'its units of another kind' => $kinds,
			'its code no record'        => array( 'code' => 'not a plan' ) + $plan,
		);
		$steps = self::steps_of( $run_id );
		self::assertSame( array( 'done', 'planned', 'planned' ), array_slice( array_column( $steps, 1 ), 0, 3 ) );
		foreach ( $cases as $what => $wrong ) {
			$wpdb->update( $runs, array( 'plan' => wp_json_encode( $wrong ) ), array( 'run_id' => $run_id ) ); // phpcs:ignore WordPress.DB
			try {
				$answer = self::next( $run_id );
			} finally {
				$wpdb->update( $runs, array( 'plan' => $row ), array( 'run_id' => $run_id ) ); // phpcs:ignore WordPress.DB
			}
			$message = self::refused( $answer, 'ab_mcp_sync_code_plan' );
			self::assertStringContainsString( 'names no unit of code for step 2', $message, $what );
			self::assertStringContainsString( 'nothing of this site changed', $message, $what );
			self::assertStringContainsString( 'Abort the run (wp_site_push action=abort), make a new report (wp_site_diff) and plan again', $message, "$what: with the way." );
			self::assertSame( 'open', AB_MCP_Sync_Run::get( $run_id )['state'], "$what: the run stays open." );
			self::assertSame( $steps, self::steps_of( $run_id ), "$what: no step is written." );
			self::assertSame( array(), $calls->wporg->calls, "$what: nothing fetched." );
			self::assertSame( array(), self::stages_of( $run_id ), "$what: nothing staged." );
			self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), "$what: nothing of the site changed." );
		}
		// The plan as it was: the same step stages.
		$staged = self::next( $run_id );
		self::assertIsArray( $staged, is_wp_error( $staged ) ? $staged->get_error_code() . ': ' . $staged->get_error_message() : '' );
		self::assertSame( array( 2, 'planned' ), array( $staged['step']['seq'], $staged['step']['state'] ) );
		self::assertCount( 1, $calls->wporg->calls, 'Fetched once.' );
		self::assertCount( 1, self::stages_of( $run_id ), 'Staged.' );
	}

	public function testARunWhosePackagesCannotBeBoundIsClosedAgainAtOnceAndNothingIsStagedOrWritten(): void {
		// B of the Codex round 1 on the code (finding 5), through the public
		// way: a package of the environment of the report is removed after
		// the plan was read and before the packages are bound to the run made
		// of it (by another request; here when the row of the run is
		// written). The confirmation of wp_site_push refuses with the package
		// and the way; the run made is closed again as aborted at once, the
		// site is free, no step ran, nothing is fetched, staged or written.
		list( $args, , $env ) = self::changed( 'ab-it-inaktiv', '1.1.0' );
		$calls   = self::wporg();
		$before  = self::live_bytes( 'ab-it-inaktiv' );
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
		$gone = (string) end( $env );
		$gid  = null;
		foreach ( AB_MCP_Protected_Store::groups( 'in' ) as $group ) {
			if ( AB_MCP_Sync_Import::LABEL . $gone === (string) $group['label'] ) {
				$gid = (string) $group['gid'];
			}
		}
		self::assertIsString( $gid, 'The package is there when the plan is read.' );
		global $wpdb;
		$runs    = AB_MCP_Sync_Schema::table( 'runs' );
		$removed = null;
		$remove  = static function ( $query ) use ( $runs, $gid, &$removed ) {
			if ( null === $removed && 0 === strpos( ltrim( (string) $query ), "INSERT INTO `$runs`" ) ) {
				$removed = AB_MCP_Protected_Store::remove( 'in', $gid );
			}
			return $query;
		};
		add_filter( 'query', $remove );
		try {
			$answer = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		} finally {
			remove_filter( 'query', $remove );
		}
		self::assertTrue( $removed, 'The package was removed once the run was written, before its packages were bound.' );
		$message = self::refused( $answer, 'ab_mcp_sync_code_bind' );
		self::assertStringContainsString( "The package $gone of this plan is no longer in the protected store", $message, 'The package is named.' );
		self::assertSame( 1, preg_match( '/Run ([1-9][0-9]*) was made for this plan and closed again as aborted at once: nothing was staged or written\./', $message, $made ), $message );
		self::assertStringContainsString( 'Call wp_site_push action=plan again once the packages are there', $message, 'With the way.' );
		$run_id = (int) $made[1];
		$run    = AB_MCP_Sync_Run::get( $run_id );
		self::assertIsArray( $run );
		self::assertSame( 'closed_aborted', $run['state'], 'The run made is closed again at once.' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free.' );
		$steps = self::steps_of( $run_id );
		self::assertSame( array( 1, 2, 3, 4, 5 ), array_column( $steps, 0 ) );
		self::assertSame( array( 'planned' ), array_values( array_unique( array_column( $steps, 1 ) ) ), 'No step ran.' );
		self::assertSame( array( null ), array_values( array_unique( array_column( $steps, 2 ), SORT_REGULAR ) ), 'No step has a journal.' );
		self::assertSame( array(), $calls->wporg->calls, 'Nothing fetched.' );
		self::assertSame( array(), self::stages_of( $run_id ), 'Nothing staged.' );
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the site changed.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ) );
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

/**
 * The calls of the protected store for SyncCodeRunTest: each the one of
 * AB_MCP_IT_Store_Calls, but the read of one sidecar (a path ending so)
 * gives false, or the bytes given, wherever it is not read for the state
 * of a stage (AB_MCP_Sync_Code_Stage::state()): the entry of the group read
 * for anything else does not read, or holds another entry.
 */
final class SyncCodeRunEntryCalls {

	/** @var \AB_MCP_IT_Store_Calls */
	private $real;

	/** @var string The end of the path of the sidecar. */
	private $side;

	/** @var string|false What such a read gives. */
	private $bytes;

	/** @var int The reads that gave it. */
	public $replaced = 0;

	/**
	 * @param string       $side  The end of the path of the sidecar.
	 * @param string|false $bytes What its read gives: false (it does not read) or these bytes.
	 */
	public function __construct( string $side, $bytes = false ) {
		$this->real  = new \AB_MCP_IT_Store_Calls();
		$this->side  = $side;
		$this->bytes = $bytes;
	}

	public function read( $path, $max ) {
		if ( substr( (string) $path, -strlen( $this->side ) ) === $this->side && ! self::for_state() ) {
			++$this->replaced;
			return $this->bytes;
		}
		return $this->real->read( $path, $max );
	}

	private static function for_state(): bool {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( isset( $frame['class'] ) && AB_MCP_Sync_Code_Stage::class === $frame['class'] && 'state' === $frame['function'] ) {
				return true;
			}
		}
		return false;
	}

	public function lstat( $path ) {
		return $this->real->lstat( $path );
	}

	public function stat( $path ) {
		return $this->real->stat( $path );
	}

	public function free( $dir ) {
		return $this->real->free( $dir );
	}

	public function scandir( $dir ) {
		return $this->real->scandir( $dir );
	}

	public function mkdir( $path, $mode ) {
		return $this->real->mkdir( $path, $mode );
	}

	public function rmdir( $path ) {
		return $this->real->rmdir( $path );
	}

	public function unlink( $path ) {
		return $this->real->unlink( $path );
	}

	public function rename( $from, $to ) {
		return $this->real->rename( $from, $to );
	}

	public function chmod( $path, $mode ) {
		return $this->real->chmod( $path, $mode );
	}

	public function create( $path ) {
		return $this->real->create( $path );
	}

	public function put( $path, $bytes ) {
		return $this->real->put( $path, $bytes );
	}

	public function copy_into( $from, $to ) {
		return $this->real->copy_into( $from, $to );
	}

	public function ask( $url, array $args, $fetcher ) {
		return $this->real->ask( $url, $args, $fetcher );
	}
}
