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
 * its step «tauschen» (F56, AB_MCP_Sync_Code_Swap::run()), with the cases
 * F46, F47 and F55 as far as the swap reaches (part D3b-2 of the rest),
 * and F57 (Z24 after option C, part C of the rest).
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
use AB_MCP_IT_Process;
use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Php_Probe;
use AB_MCP_Protected_Store;
use AB_MCP_REST_Controller;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Backup;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Code_Ops;
use AB_MCP_Sync_Code_Plan;
use AB_MCP_Sync_Code_Stage;
use AB_MCP_Sync_Code_Stage_Calls;
use AB_MCP_Sync_Code_Swap;
use AB_MCP_Sync_Env;
use AB_MCP_Sync_File_Manifest;
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Pairs_Tool;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Revert;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use AB_MCP_Test_Sync_Fetcher;
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

	/**
	 * The line of the instruction of a mark after its places (Codex on
	 * 5199de3, findings 1 and 2): only the command of the section the true
	 * case of its first lines names, and of that command only that it
	 * contains checks and to stop where it prints no OK.
	 */
	private const RUN_ONLY = 'In a shell, run only the command of the section that the true case above names. The command contains checks; where it does not print OK, stop and ask before anything else is moved.';

	/**
	 * The sentence of Z24 after option C (F57) whole, for the kind and the
	 * name of the unit: only in the answer of a swap done, once its end is
	 * recorded.
	 */
	private const Z24 = 'Whether the %1$s %2$s is active (for a theme: the theme of this site or its parent), this answer knows as its last check read it right before the swap began: an activation of it (for a theme: a switch of the theme) that this site records only after that check, or one switched on and off again before it, it does not see.';

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
		AB_MCP_Sync_Revert::admit( array( 'AB_MCP_Sync_Code_Ops', 'back' ) );
		AB_MCP_Sync_Code_Swap::forget();
		AB_MCP_Sync_Code_Swap::$clock  = null;
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

	/** Every unit that is equal on both sites paired, the public way: proposal, then confirmation with its token; those of $live_base (they differ already) by their files on the state of this site (Frage F, answer b; F55). */
	private static function paired( array $live_base = array() ): void {
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
		foreach ( $answer['entries'] as $entry ) {
			if ( ! empty( $entry['unit'] ) && in_array( $entry['kind'] . ':' . $entry['ref'], $live_base, true ) ) {
				$args['select'][] = $entry['n'];
				$args['units'][]  = array( 'entry' => $entry['n'], 'part' => 'files', 'mode' => 'live_base' );
			}
		}
		self::assertCount( count( $live_base ), $args['units'] ?? array(), 'Each unit of live_base on the first page of the proposal.' );
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

	/** The units paired, a plugin of B put to a version ($then adds to its folder), and the report of it: the plan arguments for its entry, the entry, the packages of the environment. $ref is the name the report gives the unit, where it is not $slug (a plugin of one file: its file). */
	private static function changed( string $slug, string $version, array $more = array(), ?callable $then = null, string $ref = '', array $live_base = array() ): array {
		self::paired( $live_base );
		$put = AB_MCP_IT_Code::put( 'b', 'plugin', $slug, $version );
		if ( null !== $then ) {
			// What the test adds to the unit of B before its report.
			$then( $put );
		}
		list( $report, $units, $env ) = self::report();
		$key = 'plugin:' . ( '' === $ref ? $slug : $ref );
		self::assertArrayHasKey( $key, $units, implode( ' ', array_keys( $units ) ) );
		$entry = $units[ $key ];
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
	 * begins (the stand-in of wordpress.org installed). $case makes it the
	 * run of another case of D3a: slug and version (the unit of staging; ref
	 * the name the report gives it, where it is not slug),
	 * then (what the test does to the unit of B before its report, as for
	 * changed()), runs (whether it runs here: then released with accept),
	 * accept (released with accept, where it is not as runs has it),
	 * packages (a callable given the report and the arguments of the plan
	 * that returns the package ids of code the run is given; then
	 * wordpress.org offers nothing).
	 *
	 * @return array{0:int,1:array,2:string} The run, the unit of step 3, its target.
	 */
	private static function ready_to_swap( ?callable $before = null, array $case = array() ): array {
		$case                 = $case + array(
			'slug'      => 'ab-it-aktiv',
			'version'   => '2.0.0',
			'then'      => null,
			'runs'      => true,
			'accept'    => null,
			'packages'  => null,
			'ref'       => '',
			'live_base' => array(),
		);
		$slug                 = $case['slug'];
		list( $args, $entry, , , $report ) = self::changed( $slug, $case['version'], array(), $case['then'], $case['ref'], $case['live_base'] );
		$target               = AB_MCP_IT_Code::path( 'a', 'plugin', $slug );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', $slug ) );
		$main = AB_MCP_IT_Code::unit( 'plugin', $slug )['main'][0];
		self::assertSame( $case['runs'], in_array( $main, self::active(), true ), 'The plugin runs here, or not, as the case has it.' );
		self::wporg( null === $case['packages'] ? null : array() );
		if ( null !== $before ) {
			$before();
		}
		$accept = $case['accept'] ?? $case['runs'];
		$more   = $accept ? array( 'accept' => array( (int) $entry['n'] ) ) : array();
		if ( null !== $case['packages'] ) {
			$more['packages'] = ( $case['packages'] )( $report, $args + $more );
		}
		list( , $run ) = self::begun( $args + $more );
		$run_id        = (int) $run['run_id'];
		$unit          = AB_MCP_Sync_Code_Ops::item( AB_MCP_Sync_Run::get( $run_id )['plan'], 3 )['unit'];
		self::assertSame( array( 'swap', $case['runs'], $accept ), array( $unit['op'], $unit['runs'], $unit['accept'] ) );
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
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', $slug ), 'Staged, nothing of the site changed.' );
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
		if ( is_file( $root ) ) {
			chmod( $root, 0600 );
			return;
		}
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
	 * target, the backup, the way back and the commands by hand. Then F2,
	 * its way back over wp_site_revert (put_back(), part E): the old version
	 * back at the target, the new one at its place, the mark closed, the
	 * pair unclear, and the answer says that what the new version wrote
	 * into the database stays; the plugin still active.
	 * Sonst: the run would not update the plugin of the customer, or update
	 * it without the old version to go back to, without the mark that tells
	 * a swap cut off from one that ended, with old code still in OPcache,
	 * with a file undo point that writes a file of the old version into the
	 * new one, or with an answer that does not say how to go back; or the
	 * way back would not bring the old version back, or claim the data of
	 * the new one were taken back.
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
		$swap = self::swapped_whole(
			$run_id,
			$unit,
			$target,
			array(
				'slug'     => 'ab-it-aktiv',
				'old'      => self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '1.0.0' ) ),
				'new'      => self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '2.0.0' ) ),
				'versions' => array( '1.0.0', '2.0.0' ),
			)
		);
		// The stage came with the rights of a stage of a package
		// (package_rights()), a folder below the top as well; a file PHP runs
		// that does not end in .php is cleared in OPcache (Codex D1, finding 1).
		$at = $swap['chmods'];
		self::assertSame( array( 0700, 0700, 0600 ), array( $at[ $target ], $at[ $target . '/includes' ], $at[ $target . '/includes/teil.inc' ] ), 'The stage came with the rights of a stage of a package (package_rights()).' );
		self::assertContains( $target . '/includes/teil.inc', $swap['opcache'], 'A file PHP runs that does not end in .php is one of them (Codex D1, finding 1).' );
		// The file undo points of the unit go, the other one stays.
		self::assertInstanceOf( WP_Error::class, AB_MCP_Undo::entry_for( $points['ab-it-aktiv'] ), 'The file undo point of the unit is discarded.' );
		self::assertIsArray( AB_MCP_Undo::entry_for( $points['ab-it-inaktiv'] ), 'The file undo point of another plugin stays.' );
		self::assertSame( 1, $swap['result']['discarded'] );
		// The plugin still runs, in its new version.
		self::assertContains( $main, self::active(), 'The plugin is still active.' );
		self::assertSame( '2.0.0', AB_MCP_IT_Code::mark( 'a', 'ab-it-aktiv' ), 'The site runs the new version.' );
		self::put_back(
			$run_id,
			$unit,
			$target,
			array(
				'old' => self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '1.0.0' ) ),
				'new' => self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '2.0.0' ) ),
			)
		);
		self::assertContains( $main, self::active(), 'Back in its old version, the plugin is still active.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
	}

	/**
	 * Prüft: F1: an inactive plugin with a new version from wordpress.org
	 * (the stand-in) is swapped in the run without accept, as F56 swaps: the
	 * test requests before the swap run as before every swap (reading 5
	 * (a)), none after the first rename and no swap back (15.23); the old
	 * version lies byte for byte in the backup, the folder itself, and the
	 * answer names the way back; the plugin stays inactive. Then the way
	 * back of the run puts the old version back (put_back(), part E), the
	 * plugin still inactive.
	 * Sonst: the run would not update an inactive plugin of the customer,
	 * or update it without the test requests before it, without the old
	 * version to go back to, or switch it on; or its way back would not
	 * bring the old version back, or lose the new one.
	 */
	public function testAnInactivePluginFromWordpressOrgIsSwappedWithoutAcceptWithItsBackupAndTheWayBack(): void {
		list( $run_id, $unit, $target ) = self::ready_to_swap(
			null,
			array(
				'slug'    => 'ab-it-inaktiv',
				'version' => '1.1.0',
				'runs'    => false,
			)
		);
		self::assertSame( 'wordpress.org', self::step( $run_id, 2 )['parts']['stage']['source'], 'Staged from wordpress.org (the stand-in).' );
		$old = self::bytes_of( $target );
		$new = self::bytes_of( AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-inaktiv' ) );
		self::swapped_whole(
			$run_id,
			$unit,
			$target,
			array(
				'slug'     => 'ab-it-inaktiv',
				'old'      => $old,
				'new'      => $new,
				'versions' => array( '1.0.0', '1.1.0' ),
			)
		);
		self::assertSame( '1.1.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ) );
		self::assertNotContains( 'ab-it-inaktiv/ab-it-inaktiv.php', self::active(), 'The plugin stays inactive.' );
		self::put_back(
			$run_id,
			$unit,
			$target,
			array(
				'old' => $old,
				'new' => $new,
			)
		);
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ) );
		self::assertNotContains( 'ab-it-inaktiv/ab-it-inaktiv.php', self::active(), 'Back in its old version, the plugin stays inactive.' );
	}

	/**
	 * Prüft: F3: an own plugin (one wordpress.org does not offer) with a new
	 * version, active and released with accept, comes as a package of code
	 * of the staging site, fetched by this site itself (direct) or handed
	 * over in chunks (relay), and is swapped as F56 swaps: staged from the
	 * package, the test requests before the swap, the note, the journal and
	 * the mark before the first rename, the old version whole in the backup,
	 * the pair new, the answer with the way back. Without accept it is
	 * locked as in F2: the lock reads the code that runs here, not where the
	 * new version comes from (testTheSwapOfCodeThatRunsIsPlannedOnlyWithItsNumberInAcceptAndTheTokenBindsIt).
	 * Sonst: an own plugin of the customer, which wordpress.org does not
	 * have, could not be updated by the run, or only over one of the two
	 * ways a package comes.
	 */
	#[DataProvider( 'package_ways' )]
	public function testAnOwnPluginWithANewVersionComesAsAPackageOfCodeDirectOrOverTheRelayAndIsSwapped( string $way ): void {
		$ref = array(
			'kind' => 'plugin',
			'ref'  => 'ab-it-aktiv',
		);
		list( $run_id, $unit, $target ) = self::ready_to_swap(
			null,
			array(
				'packages' => static function ( array $report ) use ( $way, $ref ): array {
					self::clear_b();
					$export = array(
						'purpose'     => 'code',
						'report_hash' => $report['report_hash'],
						'refs'        => array( $ref ),
					);
					return array( 'direct' === $way ? self::fetched( $export ) : self::relayed( $export )[0] );
				},
			)
		);
		self::assertSame( 'packages', self::step( $run_id, 2 )['parts']['stage']['source'], 'Staged from the package, not from wordpress.org.' );
		self::swapped_whole(
			$run_id,
			$unit,
			$target,
			array(
				'slug'     => 'ab-it-aktiv',
				'old'      => self::bytes_of( $target ),
				'new'      => self::bytes_of( AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-aktiv' ) ),
				'versions' => array( '1.0.0', '2.0.0' ),
			)
		);
		self::assertSame( '2.0.0', AB_MCP_IT_Code::mark( 'a', 'ab-it-aktiv' ), 'The site runs the new version.' );
		self::closed( $run_id );
	}

	/** The two ways a package of code comes to this site: fetched by it (direct), handed over in chunks (relay). */
	public static function package_ways(): array {
		return array(
			'direct' => array( 'direct' ),
			'relay'  => array( 'relay' ),
		);
	}

	/** A package B makes with these arguments, fetched by A itself from B (fetch, the direct way): its id. */
	private static function fetched( array $export ): string {
		$package = self::b( array( array( 'op' => 'export', 'as' => 'package' ) + $export ) )['package'];
		self::assertIsArray( $package );
		self::assertArrayHasKey( 'fetch', $package, var_export( $package, true ) );
		$state = AB_MCP_Sync_Import::call(
			array(
				'package_id'  => $package['package_id'],
				'manifest'    => $package['manifest'],
				'purpose'     => $export['purpose'],
				'report_hash' => $export['report_hash'],
				'fetch'       => $package['fetch'],
			),
			AB_MCP_Test_Sync_Fetcher::for_sites()
		);
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertTrue( $state['complete'], 'Fetched whole in one call.' );
		return $package['package_id'];
	}

	/**
	 * Prüft: F4 (A12): an own plugin with three files changed on staging and
	 * the same version is an edit: the stage needs only those three files
	 * (the preview names them), the package of code holds only them, and the
	 * swap puts the whole unit of staging at the target as F56 swaps, with
	 * edit_plugins. Without edit_plugins for the account, or with
	 * DISALLOW_FILE_EDIT, the swap is refused before any mutation (E10): the
	 * step stays planned, nothing written or moved, nothing in the protected
	 * store, and the answer names the capability and the way: the abort of
	 * the run by its own account first, as another account may neither go on
	 * with it nor abort it, then a new run, which an account that may can
	 * plan (Codex D6, finding 3). The file system method is not asked then,
	 * and nothing is written in wp-content (finding 2: without FS_METHOD it
	 * writes a test file there). The constant
	 * itself is not defined in this process, where it could not be undone:
	 * its effect in the core (map_meta_cap adds do_not_allow to
	 * edit_plugins) comes through the same filter.
	 * Sonst: the run could change the files of a plugin on a site that
	 * forbids editing them, or for an account that may not; or an own
	 * plugin edited on staging could not be brought over with a package of
	 * the files changed.
	 */
	#[DataProvider( 'edits' )]
	public function testAnOwnPluginWithThreeFilesChangedInItsVersionIsSwappedOnlyWithEditPlugins( string $case ): void {
		$changed = array( 'ab-it-inaktiv.php', 'includes/neu.php', 'readme.txt' );
		$ref     = array(
			'kind' => 'plugin',
			'ref'  => 'ab-it-inaktiv',
		);
		// The package of code for a plan: the preview names the three files,
		// B packs them, the relay hands them over.
		$packages = static function ( array $report, array $args ) use ( $changed, $ref ): array {
			$preview = self::gated( 'wp_site_push', $args );
			self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
			$paths = $preview['preview']['units'][0]['need']['paths'];
			sort( $paths, SORT_STRING );
			self::assertSame( $changed, $paths, 'The stage needs only the three files changed.' );
			self::clear_b();
			list( $id, $package ) = self::relayed(
				array(
					'purpose'     => 'code',
					'report_hash' => $report['report_hash'],
					'refs'        => array( $ref + array( 'paths' => $paths ) ),
				)
			);
			self::assertSame( array( array( 'kind' => 'plugin', 'ref' => 'ab-it-inaktiv', 'files' => 3 ) ), $package['code']['packed'], 'The package holds only the three files.' );
			return array( $id );
		};
		list( $run_id, $unit, $target ) = self::ready_to_swap(
			null,
			array(
				'slug'     => 'ab-it-inaktiv',
				'version'  => '1.0.0',
				'runs'     => false,
				'then'     => static function ( string $put ): void {
					self::assertNotFalse( file_put_contents( $put . '/ab-it-inaktiv.php', "\n// changed on staging\n", FILE_APPEND ) );
					self::assertNotFalse( file_put_contents( $put . '/readme.txt', "\nChanged on staging.\n", FILE_APPEND ) );
					self::assertTrue( mkdir( $put . '/includes', 0755 ) );
					self::assertNotFalse( file_put_contents( $put . '/includes/neu.php', "<?php\n// new on staging\n" ) );
				},
				'packages' => $packages,
			)
		);
		self::assertSame( 'edit', $unit['unit_op'], 'The same version with other files is an edit.' );
		if ( 'with edit_plugins' === $case ) {
			self::swapped_whole(
				$run_id,
				$unit,
				$target,
				array(
					'slug'     => 'ab-it-inaktiv',
					'old'      => self::bytes_of( $target ),
					'new'      => self::bytes_of( AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-inaktiv' ) ),
					'versions' => array( '1.0.0', '1.0.0' ),
				)
			);
			self::closed( $run_id );
			return;
		}
		// Without edit_plugins: this account only (another one may); with
		// DISALLOW_FILE_EDIT: every account, as the constant does it.
		$admin  = self::admin();
		$other  = wp_insert_user( array( 'user_login' => 'zz_second_admin', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'zz2@example.test', 'role' => 'administrator' ) );
		$deny   = 'without edit_plugins' === $case
			? static function ( array $allcaps, array $caps, array $args, $user ) use ( $admin ): array {
				if ( (int) $user->ID === $admin ) {
					unset( $allcaps['edit_plugins'] );
				}
				return $allcaps;
			}
			: static function ( array $caps, string $cap ): array {
				return 'edit_plugins' === $cap ? array_merge( $caps, array( 'do_not_allow' ) ) : $caps;
			};
		$hook   = 'without edit_plugins' === $case ? 'user_has_cap' : 'map_meta_cap';
		$before = self::before_swap( $target, 'ab-it-inaktiv' );
		self::assertIsInt( $other );
		// The file system method not asked (Codex D6, finding 2): without
		// FS_METHOD, get_filesystem_method() writes and removes a test file
		// in wp-content, which leaves no file but a new time of the folder,
		// and its filter runs at its end.
		self::assertFalse( defined( 'FS_METHOD' ), 'No FS_METHOD: the method would write its test file.' );
		$asked = 0;
		$count = static function ( $method ) use ( &$asked ) {
			++$asked;
			return $method;
		};
		$then  = time() - 3600;
		self::assertTrue( touch( WP_CONTENT_DIR, $then ) );
		add_filter( $hook, $deny, 10, 4 );
		add_filter( 'filesystem_method', $count );
		try {
			self::assertFalse( current_user_can( 'edit_plugins' ), 'The account has no edit_plugins.' );
			$answer = self::swap_with( $run_id, array() );
			clearstatcache();
			self::assertSame( array( 0, $then ), array( $asked, filemtime( WP_CONTENT_DIR ) ), 'No file system method asked, nothing written in wp-content.' );
			self::assertSame( array(), glob( WP_CONTENT_DIR . '/temp-write-test-*' ), 'No test file of the method in wp-content.' );
			$says = self::nothing_begun( $run_id, $target, $before, $answer, 'ab_mcp_sync_rights', 'Abort the run first with its own account and connection (wp_site_push action=abort), then plan a new one (wp_site_push action=plan), without this entry where it stays refused.' );
			self::assertStringContainsString( 'may not change the files of the plugin ab-it-inaktiv on this site in this way (edit_plugins; with DISALLOW_FILE_EDIT nobody has edit_plugins', $says );
			// The way the answer names (Codex D6, finding 3): another account
			// may not go on with the run nor abort it; this one aborts it, and
			// the site is free for a new run.
			wp_set_current_user( $other );
			self::refused( self::next( $run_id ), 'ab_mcp_sync_run_foreign' );
			self::refused( self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) ), 'ab_mcp_sync_run_foreign' );
			wp_set_current_user( $admin );
			$closed = self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) );
			self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_code() . ': ' . $closed->get_error_message() : '' );
			self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free for a new run.' );
			if ( 'without edit_plugins' === $case ) {
				// A new run with the account that may: its own report and
				// its own package of the three files.
				wp_set_current_user( $other );
				self::assertTrue( current_user_can( 'edit_plugins' ), 'The other account has edit_plugins.' );
				list( $report, $units ) = self::report();
				$plan                   = array(
					'action'    => 'plan',
					'report_id' => $report['report_id'],
					'select'    => array( (int) $units['plugin:ab-it-inaktiv']['n'] ),
				);
				list( , $again )        = self::begun( $plan + array( 'packages' => $packages( $report, $plan ) ) );
				self::assertSame( (int) $other, (int) AB_MCP_Sync_Run::get( (int) $again['run_id'] )['user_id'], 'The new run is the one of the account that may.' );
			}
		} finally {
			wp_set_current_user( $admin );
			remove_filter( 'filesystem_method', $count );
			remove_filter( $hook, $deny, 10 );
		}
		clearstatcache();
		self::assertSame( $before['old'], self::digest( $target ), 'The old version at the target as it was.' );
	}

	/** The edit of F4: with edit_plugins, without it for the account, with DISALLOW_FILE_EDIT (its effect in the core). */
	public static function edits(): array {
		return array(
			'with edit_plugins'        => array( 'with edit_plugins' ),
			'without edit_plugins'     => array( 'without edit_plugins' ),
			'with DISALLOW_FILE_EDIT' => array( 'with DISALLOW_FILE_EDIT' ),
		);
	}

	/**
	 * Prüft: Codex D6, finding 1 (A): where another engine holds the name
	 * code in the registry of the kinds (a foreign entry, as a plugin could
	 * put one there), AB_MCP_Sync_Code_Kind::engine() gives a WP_Error, and
	 * the step of the swap answers with it before any mutation: the step
	 * stays planned, nothing written or moved, nothing in the protected
	 * store, and the answer names the way. With the engine of the kind code
	 * held again, the next call swaps.
	 * Sonst: the call would end in a fatal error (a method of the engine
	 * asked of the error) with no answer and no way, and the step of the
	 * swap could not go on.
	 */
	public function testAForeignEngineUnderTheNameCodeIsTheAnswerOfTheSwapBeforeAnythingIsWritten(): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$kinds  = new \ReflectionProperty( \AB_MCP_Sync_Kinds::class, 'kinds' );
		$held   = $kinds->getValue();
		$other  = $this->createStub( \AB_MCP_Sync_Kind::class );
		$before = self::before_swap( $target );
		$other->method( 'kind' )->willReturn( 'code' );
		$kinds->setValue( null, array( 'code' => $other ) + $held );
		try {
			self::assertInstanceOf( WP_Error::class, \AB_MCP_Sync_Code_Kind::engine(), 'The name code is held by another engine.' );
			$answer = self::swap_with( $run_id, array() );
		} finally {
			$kinds->setValue( null, $held );
		}
		self::nothing_begun( $run_id, $target, $before, $answer, 'ab_mcp_sync_kind_duplicate', 'Use AB_MCP_Sync_Code_Kind::engine() to register the kind code, and nothing else.' );
		// The engine of the kind code held again: the step goes on.
		$swapped = self::next( $run_id );
		self::assertIsArray( $swapped, is_wp_error( $swapped ) ? $swapped->get_error_code() . ': ' . $swapped->get_error_message() : '' );
		self::assertSame( array( 3, 'done' ), array( $swapped['step']['seq'], $swapped['step']['state'] ), $swapped['next'] ?? '' );
		self::assertSame( '2.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
	}

	/**
	 * Prüft: F6: a plugin of one file (ab-it-einzeln.php), active and
	 * released with accept, with a new version as a package of code, is
	 * swapped as F56 swaps, as the file itself: the stage is one file of the
	 * protected store named as the file of the unit, the place of the backup
	 * is named so too (basename() of the target), the two renames move the
	 * file (the old one into the backup, the stage to the target), OPcache
	 * is cleared for the one PHP file (php_paths() of a file), and the note
	 * of the replacement stands under the kind and the name of the unit
	 * (plugin:ab-it-einzeln.php) before the first rename; the answer names
	 * the way back. Only the normal way: the answers after a fault past the
	 * first mutation wait for the decision on Part 1 of Codex D4 (the lead,
	 * 16.11. 05:37); the window left between lstat() and rename() for a file
	 * is the named limit G-5-10. Then the way back of the run puts the old
	 * file back and the new one at its place, each a file (put_back(),
	 * part E).
	 * Sonst: a plugin of one file could not be updated by the run, or only
	 * with the old file not whole in the backup, or with old code of it
	 * still in OPcache; or its way back would treat the file as a folder.
	 */
	public function testAPluginOfOneFileIsSwappedAsTheFileItselfWithItsBackupAndTheWayBack(): void {
		list( $run_id, $unit, $target ) = self::ready_to_swap(
			null,
			array(
				'slug'     => 'ab-it-einzeln',
				'ref'      => 'ab-it-einzeln.php',
				'version'  => '1.1.0',
				'packages' => static function ( array $report ): array {
					self::clear_b();
					$export = array(
						'purpose'     => 'code',
						'report_hash' => $report['report_hash'],
						'refs'        => array(
							array(
								'kind' => 'plugin',
								'ref'  => 'ab-it-einzeln.php',
							),
						),
					);
					return array( self::relayed( $export )[0] );
				},
			)
		);
		self::assertSame( 'ab-it-einzeln.php', $unit['ref'], 'The unit is named by its file.' );
		self::assertTrue( is_file( $target ), 'The target is a file.' );
		self::assertSame( 'ab-it-einzeln.php', self::step( $run_id, 2 )['parts']['stage']['name'], 'The stage is named as the file of the unit.' );
		$old  = self::bytes_of( $target );
		$new  = self::bytes_of( AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-einzeln' ) );
		$swap = self::swapped_whole(
			$run_id,
			$unit,
			$target,
			array(
				'slug'     => 'ab-it-einzeln.php',
				'old'      => $old,
				'new'      => $new,
				'versions' => array( '1.0.0', '1.1.0' ),
			)
		);
		self::assertSame( 'ab-it-einzeln.php', basename( $swap['backup'] ), 'The backup is named as the file (basename() of the target).' );
		self::assertTrue( is_file( $swap['backup'] ) && is_file( $target ), 'The backup and the target are files.' );
		self::assertSame( array( $target ), $swap['opcache'], 'OPcache for the one PHP file.' );
		self::assertSame( '1.1.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-einzeln' ) );
		self::assertContains( 'ab-it-einzeln.php', self::active(), 'The plugin is still active.' );
		$back = self::put_back(
			$run_id,
			$unit,
			$target,
			array(
				'old' => $old,
				'new' => $new,
			)
		);
		self::assertTrue( is_file( $target ) && is_file( $back['result']['place'] ), 'The old version at the target and the new one at its place are files.' );
		self::assertSame( 'ab-it-einzeln.php', basename( $back['result']['place'] ) );
		self::assertSame( 1, $back['result']['opcache'], 'OPcache for the one PHP file at the target.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-einzeln' ) );
		self::assertContains( 'ab-it-einzeln.php', self::active(), 'Back in its old version, the plugin is still active.' );
	}

	/**
	 * Prüft: F47: an active plugin whose version here carries the data file
	 * data/export.csv, unchanged here, and whose new version on staging
	 * lacks it, is swapped as F56 swaps (Entscheid 1 A); after it the file
	 * is gone from the folder of the site and lies byte for byte in the
	 * backup of the run, with the old folder whole. The way back of the run
	 * puts the old folder back and the file with it, byte for byte
	 * (put_back(), part E).
	 * Sonst: a file of the customer the new version does not have would be
	 * lost with the swap, or stay beside the new version as if it were part
	 * of it, or not come back with the way back.
	 */
	public function testADataFileTheNewVersionLacksLeavesTheSiteAndLiesInTheBackupByteForByte(): void {
		$file = 'data/export.csv';
		list( $run_id, $unit, $target ) = self::ready_to_swap(
			null,
			array(
				'slug'    => 'ab-it-daten',
				'version' => '1.1.0',
			)
		);
		$old = self::bytes_of( $target );
		$new = self::bytes_of( AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-daten' ) );
		self::assertSame( array( true, false ), array( isset( $old[ $file ] ), isset( $new[ $file ] ) ), 'The data file here, not in the new version.' );
		$swap = self::swapped_whole(
			$run_id,
			$unit,
			$target,
			array(
				'slug'     => 'ab-it-daten',
				'old'      => $old,
				'new'      => $new,
				'versions' => array( '1.0.0', '1.1.0' ),
			)
		);
		self::assertFileDoesNotExist( $target . '/' . $file, 'Gone from the folder of the site.' );
		self::assertSame( hash_file( 'sha256', AB_MCP_IT_Code::source( 'plugin', 'ab-it-daten', '1.0.0' ) . '/' . $file ), hash_file( 'sha256', $swap['backup'] . '/' . $file ), 'Byte for byte in the backup.' );
		self::put_back(
			$run_id,
			$unit,
			$target,
			array(
				'old' => $old,
				'new' => $new,
			)
		);
		self::assertSame( hash_file( 'sha256', AB_MCP_IT_Code::source( 'plugin', 'ab-it-daten', '1.0.0' ) . '/' . $file ), hash_file( 'sha256', $target . '/' . $file ), 'The data file is back in the folder of the site byte for byte.' );
	}

	/**
	 * Prüft: F46: a plugin that writes a log into its folder on every
	 * request, on both sites, its files paired the public way on the state
	 * of this site (Frage F, answer b); a new version on staging, and the
	 * log written here again: the entry is changed on both sides with the
	 * path of the log, locked code:live_changed without the choice «staging
	 * wins», with the way of the update by hand in wp-admin; its plan begins
	 * no run and moves nothing. Brought here to the same version and paired
	 * anew, the next new version of staging is locked again, as the plugin
	 * wrote here since.
	 * Sonst: the swap would put the folder of staging over the log the
	 * plugin writes here, so that the log of the customer is gone, or the
	 * customer would not learn the way that works for such a plugin.
	 */
	public function testAPluginThatWritesIntoItsFolderIsLockedWithTheWayByHandAndNothingMoves(): void {
		$slug   = 'ab-it-schreiber';
		$log    = 'log/zugriffe.log';
		$write  = static function (): void {
			\AB_MCP_IT_Process::calls( 'a', array( 'calls' => array() ) );
		};
		$locked = static function ( callable $then ) use ( $slug, $log ): void {
			list( $args, $entry ) = self::changed( $slug, '1.1.0', array(), $then, '', array( 'plugin:' . $slug ) );
			self::assertSame( array( 'conflict', 'locked', 'code:live_changed' ), array( $entry['result'], $entry['action'], $entry['locks'][0]['key'] ), 'Changed on both sides.' );
			self::assertArrayNotHasKey( 'choices', $entry, 'No choice «staging wins».' );
			self::assertStringContainsString( 'update it by hand in wp-admin', $entry['locks'][0]['reason'] );
			self::assertContains( $log, $entry['paths']['differs'], 'The path of its log.' );
			$here    = static fn(): array => array_diff_key( self::live_bytes( $slug ), array( '/' . $log => true ) );
			$before  = $here();
			$message = self::refused( self::gated( 'wp_site_push', $args ), 'ab_mcp_sync_push_nothing' );
			self::assertStringContainsString( $entry['locks'][0]['reason'], $message, 'The lock with its way.' );
			self::assertNull( AB_MCP_Sync_Run::open_run(), 'No run begins.' );
			self::assertSame( $before, $here(), 'Nothing of the plugin here moved.' );
		};
		$write();
		$locked( $write );
		// The same version here (by hand), the plugin writes its log, and
		// the pair is made anew on it; then the next version of staging.
		AB_MCP_IT_Code::put( 'a', 'plugin', $slug, '1.1.0' );
		$write();
		$locked(
			static function ( string $put ) use ( $write ): void {
				self::assertNotFalse( file_put_contents( $put . '/naechste.txt', '1.2.0' ) );
				$write();
			}
		);
		// The case against it: paired anew, and the plugin not asked here
		// since; the next version of staging is taken over, not locked.
		list( , $entry ) = self::changed(
			$slug,
			'1.1.0',
			array(),
			static function ( string $put ): void {
				self::assertNotFalse( file_put_contents( $put . '/naechste.txt', '1.2.0' ) );
			},
			'',
			array( 'plugin:' . $slug )
		);
		self::assertSame( array( 'changed_on_staging', 'take_over' ), array( $entry['result'], $entry['action'] ), 'Locked by what the plugin wrote here, nothing else.' );
	}

	/**
	 * Prüft: F55 with sites that differ already: an active plugin whose
	 * files differ here and on staging is paired the public way with its
	 * files on the state of this site (Frage F, answer b), then updated on
	 * staging; the run takes it over with accept and swaps it as F56 swaps,
	 * and its way back puts the old version back (put_back(), part E).
	 * Sonst: a unit the customer paired by answer b would not be updated by
	 * the run, or its difference of before would count as a change here and
	 * lock it, or not go back.
	 */
	public function testAPluginThatDifferedWhenItWasPairedOnTheStateOfThisSiteIsSwappedAfterAnUpdateOnStaging(): void {
		self::assertNotFalse( file_put_contents( AB_MCP_IT_Code::path( 'b', 'plugin', 'ab-it-aktiv' ) . '/anders.txt', 'staging' ), 'The files differ before the pair.' );
		list( $run_id, $unit, $target ) = self::ready_to_swap( null, array( 'live_base' => array( 'plugin:ab-it-aktiv' ) ) );
		$old = self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '1.0.0' ) );
		$new = self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '2.0.0' ) );
		self::swapped_whole(
			$run_id,
			$unit,
			$target,
			array(
				'slug'     => 'ab-it-aktiv',
				'old'      => $old,
				'new'      => $new,
				'versions' => array( '1.0.0', '2.0.0' ),
			)
		);
		self::put_back(
			$run_id,
			$unit,
			$target,
			array(
				'old' => $old,
				'new' => $new,
			)
		);
	}

	/* ------------------------------------------ the way back of a swap (part E) */

	/** The run of F56 with its swap done: the run, the unit of step 3, its target, the backup of the old version. */
	private static function swapped_f56(): array {
		list( $run_id, $unit, $target ) = self::ready_to_swap();
		$answer = self::next( $run_id );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$swap = self::step( $run_id, 3 );
		self::assertSame( 'done', $swap['state'], $answer['next'] );
		return array( $run_id, $unit, $target, $swap['result']['backup'] );
	}

	/** What the test requests find on each target of this site now (AB_MCP_Php_Probe::run(), every target, not stopped at a fatal one), by target. */
	private static function probe_now(): array {
		$got = AB_MCP_Php_Probe::run( array(), false );
		self::assertIsArray( $got );
		return array_column( $got['targets'], 'result', 'target' );
	}

	/** The calls of the swap with these faults (AB_MCP_Test_Seam), for a way back. */
	private static function seam_of_swap( array $faults ): void {
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Code_Swap::class, 'naht_sync_code_swap', new \AB_MCP_Sync_Code_Swap_Calls(), $faults );
	}

	/**
	 * Prüft: F59, case 1 (plan F59; Z22, Z13, Z16): a new version that ends
	 * fatal only on the home page (ab-it-aktiv startseite, 2.0.3) is swapped
	 * as F56 swaps: the test requests before the swap pass, none goes after
	 * it, the step is done and its answer names the way back, target, backup
	 * and the commands by hand (swapped_whole()). Then the home page ends
	 * fatal and MCP answers (AB_MCP_Php_Probe::run()). The way back of the
	 * run over MCP puts the old version back (put_back()): two renames, each
	 * onto a place that is free, and OPcache for each PHP file of the old
	 * and of the new version under its path at the target; then the home
	 * page runs again.
	 * Sonst: a version that breaks the home page of the customer while MCP
	 * still answers could not be taken back by the run, or only with code
	 * of the new version left in OPcache, or onto a place another process
	 * holds.
	 */
	public function testAVersionFatalOnTheHomePageIsPutBackOverMcpAndTheHomePageRunsAgain(): void {
		list( $run_id, $unit, $target ) = self::ready_to_swap( null, array( 'version' => 'startseite' ) );
		$old = self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '1.0.0' ) );
		$new = self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', 'startseite' ) );
		self::swapped_whole(
			$run_id,
			$unit,
			$target,
			array(
				'slug'     => 'ab-it-aktiv',
				'old'      => $old,
				'new'      => $new,
				'versions' => array( '1.0.0', '2.0.3' ),
			)
		);
		$after = self::probe_now();
		self::assertSame( array( 'fatal', 'ok' ), array( $after['home'], $after['mcp'] ), 'The home page ends fatal, MCP answers: ' . json_encode( $after ) );
		$opcache = array();
		$renames = array();
		self::seam_of_swap(
			array(
				array(
					'method' => 'opcache',
					'kind'   => 'before',
					'before' => static function ( $method, array $args ) use ( &$opcache ): void {
						$opcache[] = $args[0];
					},
				),
				array(
					'method' => 'rename',
					'kind'   => 'before',
					'before' => static function ( $method, array $args ) use ( &$renames ): void {
						clearstatcache();
						$renames[] = array( $args[1], file_exists( $args[1] ) || is_link( $args[1] ) );
					},
				),
			)
		);
		$back = self::put_back(
			$run_id,
			$unit,
			$target,
			array(
				'old' => $old,
				'new' => $new,
			)
		);
		self::assertSame( array( array( $back['result']['place'], false ), array( $target, false ) ), $renames, 'The new version to its place, then the old one to the target, each onto a place that is free.' );
		$php = array();
		foreach ( array_merge( array_keys( $old ), array_keys( $new ) ) as $rel ) {
			if ( AB_MCP_Sync_Code_Stage::is_php( $rel ) ) {
				$php[ $target . '/' . $rel ] = true;
			}
		}
		$php = array_keys( $php );
		sort( $php, SORT_STRING );
		self::assertSame( $php, $opcache, 'OPcache for each PHP file of the old and of the new version, by its path at the target.' );
		self::assertSame( count( $php ), $back['result']['opcache'] );
		$again = self::probe_now();
		self::assertSame( array( 'ok', 'ok' ), array( $again['home'], $again['mcp'] ), 'The home page runs again: ' . json_encode( $again ) );
	}

	/**
	 * Prüft: F59, case 2 (plan F59): a new version that ends fatal on every
	 * call. The version of F56 is swapped as F56 swaps, then its main file
	 * at the target takes the bytes of ab-it-aktiv fatal (2.0.1), in the
	 * same folder: the staging site cannot carry a version fatal on every
	 * call, its own steps would end fatal (CodeFixturesTest). Neither the
	 * home page nor MCP answers, so the way back of the run cannot be
	 * called. The command line of the answer of the swap, taken from it as
	 * it stands there (not built again, not copied), run in a shell on the
	 * server, puts the old version back (its checks read the inodes, not
	 * the bytes), and the site answers again.
	 * Sonst: a version that ends every request of the customer fatal would
	 * leave the site down, with no way back that works without MCP.
	 */
	public function testAVersionFatalOnEveryCallIsPutBackByTheLinesOfTheAnswerOfItsSwapInAShell(): void {
		list( $run_id, $unit, $target ) = self::ready_to_swap();
		$old  = self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '1.0.0' ) );
		$swap = self::swapped_whole(
			$run_id,
			$unit,
			$target,
			array(
				'slug'     => 'ab-it-aktiv',
				'old'      => $old,
				'new'      => self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '2.0.0' ) ),
				'versions' => array( '1.0.0', '2.0.0' ),
			)
		);
		self::assertTrue( copy( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', 'fatal' ) . '/ab-it-aktiv.php', $target . '/ab-it-aktiv.php' ) );
		$after = self::probe_now();
		self::assertSame( array( 'fatal', 'fatal' ), array( $after['home'], $after['mcp'] ), 'Neither the home page nor MCP answers: ' . json_encode( $after ) );
		self::assertStringEndsWith( 'OK', self::run_by_hand( explode( "\n", $swap['answer']['next'] ) ), 'The command of the way by hand, as the answer of the swap gives it, ran whole.' );
		clearstatcache();
		self::assertSame( $old, self::bytes_of( $target ), 'The old version is back at the target.' );
		$again = self::probe_now();
		self::assertSame( array( 'ok', 'ok' ), array( $again['home'], $again['mcp'] ), 'The site answers again: ' . json_encode( $again ) );
	}

	/**
	 * Prüft: F37 (plan F37; Z22, option B): the way back of a swap done
	 * moves nothing where what it would move is not proved in its call: the
	 * backup gone, a file of the backup changed, or another version at the
	 * target than the swap left there. Each ends the step of the way back
	 * unclear with the proof that failed, the frame of the way back and the
	 * paths of target and backup; the target is byte for byte what it was,
	 * and the pair is to be confirmed anew.
	 * Sonst: the way back would put a backup it did not prove onto the
	 * site, or move away a version the swap did not leave there.
	 */
	#[DataProvider( 'not_proved_back' )]
	public function testTheWayBackMovesNothingWhereTheBackupOrTheVersionAtTheTargetIsNotProved( string $case ): void {
		list( $run_id, $unit, $target, $backup ) = self::swapped_f56();
		if ( 'the backup is gone' === $case ) {
			self::assertTrue( rename( $backup, $backup . '-weg' ) );
		} elseif ( 'a file of the backup changed' === $case ) {
			self::assertNotFalse( file_put_contents( $backup . '/readme.txt', "\nanders", FILE_APPEND ) );
		} else {
			self::assertTrue( rename( $target, $target . '-weg' ) );
			AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-aktiv', 'php' );
		}
		clearstatcache();
		$before = self::bytes_of( $target );
		$way    = self::way_back_of( $run_id );
		$step   = self::back_step( (int) $way['begun']['run_id'] );
		$says   = $step['result']['message'];
		self::assertSame( 'unclear', $step['state'], $says );
		self::assertStringStartsWith( 'another version at the target' === $case ? 'The version at ' . $target . ' could not be proved as the new version of the swap (' : 'The old version could not be proved whole in the backup ' . $backup . ' (', $says );
		self::assertStringContainsString( ' The step of the way back is unclear: nothing more of the unit is moved by itself; check it by hand, then confirm its pair anew (wp_site_pairs).', $says );
		self::assertStringEndsWith( "\nTarget: " . $target . "\nBackup: " . $backup, $says );
		self::assertNull( $step['result']['place'] );
		clearstatcache();
		self::assertSame( $before, self::bytes_of( $target ), 'The target is byte for byte what it was.' );
		self::assertSame( 'unclear', AB_MCP_Sync_Pairs::get( $unit['pair_id'] )['state'], 'The pair is to be confirmed anew.' );
	}

	/** What the way back does not prove (F37). */
	public static function not_proved_back(): array {
		return array(
			'the backup is gone'            => array( 'the backup is gone' ),
			'a file of the backup changed'  => array( 'a file of the backup changed' ),
			'another version at the target' => array( 'another version at the target' ),
		);
	}

	/**
	 * Prüft: N12 and the one entrance (part E, question 2): with no
	 * entrance in the core (AB_MCP_Sync_Revert::admit( null ), as where the
	 * file of AB_MCP_Sync_Code_Ops is not loaded), a run of the code is not
	 * planned back, a way back planned with it does not run its step, and a
	 * way back interrupted on its step is not led on: each refuses with
	 * ab_mcp_sync_revert_code and the way by hand (the lines of the answer
	 * of its swap, wp-admin, and the instruction of an open mark in the
	 * folder of the marks; part F, C5), and the run stays as it was (its
	 * state, its steps, the target). The sentence of the answer of the swap
	 * on the way by hand holds in both states (part F, section 1): it names
	 * the lines below it where the way back is refused on this site, and
	 * here, with the place emptied, wp_site_revert refuses it and says why.
	 * Sonst: a way back of code could run past its one entrance (N12), a
	 * run of the code be planned back or led on without it, or an answer
	 * name a way that is closed where it says so.
	 */
	#[DataProvider( 'no_entrance' )]
	public function testWithoutTheEntranceARunOfTheCodeIsNeitherPlannedBackNorRunBackNorLedOn( string $at ): void {
		list( $run_id, , $target ) = self::swapped_f56();
		$back = $run_id;
		if ( 'plan' !== $at ) {
			$preview = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id ) );
			self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
			$begun = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id, 'confirm_token' => $preview['confirm_token'] ) );
			self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
			$back = (int) $begun['run_id'];
		}
		if ( 'continue' === $at ) {
			// The step of the way back begun by a call that ended without
			// its end (no lock of its own): the run is interrupted on its way
			// back.
			self::assertTrue( AB_MCP_Sync_Run::update_step( $back, (int) self::back_step( $back )['seq'], array( 'state' => 'begun' ), 'planned' ) );
			self::assertSame( 'interrupted', self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $back ) )['state'] );
		}
		$state  = static fn(): array => array( AB_MCP_Sync_Run::get( $back )['state'], array_column( AB_MCP_Sync_Run::steps( $back ), 'state', 'seq' ), self::bytes_of( $target ) );
		$before = $state();
		AB_MCP_Sync_Revert::admit( null );
		try {
			$answer = self::gated( 'wp_site_revert', array( 'action' => 'next' === $at ? 'next' : 'plan', 'run_id' => $back ) );
		} finally {
			AB_MCP_Sync_Revert::admit( array( 'AB_MCP_Sync_Code_Ops', 'back' ) );
		}
		$says = self::refused( $answer, 'ab_mcp_sync_revert_code' );
		self::assertStringStartsWith( sprintf( 'Run %d is a run of plugins or themes, and this installation of AlphaBridge has no way back for code (its entrance is not loaded), so none of its steps is planned or put back, and none left begun is closed as checked.', $run_id ), $says, 'The refusal says why.' );
		self::assertStringContainsString( 'Put the old version back by hand', $says );
		$named = AB_MCP_Protected_Store::named_by_swap();
		self::assertIsArray( $named );
		self::assertStringEndsWith( sprintf( ' Where the mark of a swap of it is still open in %s, its instruction there says how to put the old version back in a shell on the server.', $named['marks'] ), $says );
		self::assertStringNotContainsString( 'AlphaBridge Pro', $says, 'No reason by a licence.' );
		self::assertSame( $before, $state(), 'The run, its steps and the target stay as they were.' );
		// The answer of the swap: the way by hand at the same place, where the
		// way back is refused, with the lines it names; no reason of its own.
		$result = self::step( $run_id, 3 )['result'];
		self::assertStringContainsString( ' or where that way back is refused on this site (wp_site_revert says why), put the old version back by hand in a shell on the server, as the lines below say:' . "\n" . implode( "\n", $result['by_hand'] ), $result['message'] );
		self::assertStringNotContainsString( 'licence', $result['message'] );
	}

	/** Where the core asks the entrance: the preview, the step of the way back, its continuation. */
	public static function no_entrance(): array {
		return array(
			'plan'     => array( 'plan' ),
			'next'     => array( 'next' ),
			'continue' => array( 'continue' ),
		);
	}

	/**
	 * Prüft: N12, fail closed (skeptic E1, B4): where the record of a run of
	 * the code does not read back (its plan with a marker of bytes that does
	 * not hold, written by SQL; AB_MCP_Sync_Run::records() reads it as
	 * absent and names it in unread), whether it is a run of the code is not
	 * known, and the one entrance is not passed by: the plan of its way
	 * back, the step of a way back planned before and the continuation of
	 * one interrupted each refuse with ab_mcp_sync_run_unread and the way by
	 * hand, and the run, its steps and the target stay as they were. With
	 * its plan read back, the same call goes on.
	 * Sonst: a run of the code whose plan does not read would be taken for
	 * a run of the contents: its swap would count as a step that wrote
	 * nothing, or its step of the way back would go to the boundary of the
	 * contents, past the one entrance.
	 */
	#[DataProvider( 'no_entrance' )]
	public function testARunOfTheCodeWhosePlanDoesNotReadBackIsNeitherPlannedBackNorRunBackNorLedOn( string $at ): void {
		global $wpdb;
		list( $run_id, , $target ) = self::swapped_f56();
		if ( 'next' === $at ) {
			// The run closed first: its way back is a run of its own (revert),
			// whose step reaches the entrance (run_revert()); on the same run
			// AB_MCP_Sync_Engine::fresh() refuses its record before.
			for ( $i = 0; $i < 6 && ! in_array( AB_MCP_Sync_Run::get( $run_id )['state'], AB_MCP_Sync_Schema::CLOSED, true ); $i++ ) {
				self::next( $run_id );
			}
			self::assertContains( AB_MCP_Sync_Run::get( $run_id )['state'], AB_MCP_Sync_Schema::CLOSED, 'The run of the code is closed.' );
		}
		$back = 'plan' === $at ? $run_id : self::planned_back( $run_id );
		if ( 'continue' === $at ) {
			// The step of the way back begun by a call that ended without its
			// end: the run is interrupted on its way back.
			self::assertTrue( AB_MCP_Sync_Run::update_step( $back, (int) self::back_step( $back )['seq'], array( 'state' => 'begun' ), 'planned' ) );
			self::assertSame( 'interrupted', self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $back ) )['state'] );
		}
		$runs   = AB_MCP_Sync_Schema::table( 'runs' );
		$plan   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT plan FROM `$runs` WHERE run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$state  = static fn(): array => array( AB_MCP_Sync_Run::get( $back )['state'], array_column( AB_MCP_Sync_Run::steps( $back ), 'state', 'seq' ), self::bytes_of( $target ) );
		$args   = array(
			'action' => 'next' === $at ? 'next' : 'plan',
			'run_id' => $back,
		);
		$before = $state();
		// The plan of the run of the code: a float marker, which no plan carries.
		self::assertSame( 1, $wpdb->update( $runs, array( 'plan' => '{"code":{"~f":"000000000000f03f"}}' ), array( 'run_id' => $run_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			self::assertSame( array( 'plan' ), AB_MCP_Sync_Run::get( $run_id )['unread'], 'The plan of the run of the code does not read back.' );
			$answer = self::gated( 'wp_site_revert', $args );
		} finally {
			self::assertSame( 1, $wpdb->update( $runs, array( 'plan' => $plan ), array( 'run_id' => $run_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$says = self::refused( $answer, 'ab_mcp_sync_run_unread' );
		self::assertStringStartsWith( sprintf( 'The record of run %d (plan) does not read back as it was written, so nothing is decided on it: no way back that rests on it is planned, put back or closed as checked.', $run_id ), $says );
		self::assertStringContainsString( 'put the old version back by hand', $says );
		self::assertSame( $before, $state(), 'The run, its steps and the target stay as they were.' );
		$again = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_code() . ': ' . $again->get_error_message() : '' );
		if ( 'next' === $at ) {
			self::assertSame( 'done', self::back_step( $back )['state'], 'With its plan read back, the step of the way back puts the old version back.' );
		}
	}

	/**
	 * Prüft: N12, fail closed (rest of B4, decision of the lead): where the
	 * entry of a step of the way back does not read back (its parts with a
	 * marker of bytes that does not hold, written by SQL), the core still
	 * asks the one entrance for it: the run that wrote is the one the plan
	 * of the run of the way back names (reverts), not what the step names.
	 * With the entrance, the continuation of the way back interrupted on it
	 * (action=plan) and way (a) refuse with nothing written, and the step
	 * of the way back (next) fails through the entrance with nothing moved,
	 * its sentence naming that step; without the entrance each refuses with
	 * ab_mcp_sync_revert_code and nothing written. A run of the way back
	 * whose plan names no run it takes back, or a run that is itself of the
	 * way back, is refused as not known (ab_mcp_sync_run_unread). Never the
	 * sentence of the contents.
	 * Sonst: a step of the way back of a swap whose entry does not read
	 * would go to the contents past the one entrance: way (a) would close
	 * it as checked without asking the entrance, and the preview and the
	 * step would speak of contents.
	 */
	#[DataProvider( 'entry_unread' )]
	public function testAStepOfTheWayBackWhoseEntryDoesNotReadBackStillGoesThroughTheOneEntrance( string $at, bool $entrance, string $unread ): void {
		global $wpdb;
		list( $run_id, , $target ) = self::swapped_f56();
		// The run closed first: its way back is a run of its own (revert).
		for ( $i = 0; $i < 6 && ! in_array( AB_MCP_Sync_Run::get( $run_id )['state'], AB_MCP_Sync_Schema::CLOSED, true ); $i++ ) {
			self::next( $run_id );
		}
		$back = self::planned_back( $run_id );
		$seq  = (int) self::back_step( $back )['seq'];
		if ( 'next' !== $at ) {
			// The step of the way back begun by a call that ended without its
			// end: the run of the way back is interrupted on it.
			self::assertTrue( AB_MCP_Sync_Run::update_step( $back, $seq, array( 'state' => 'begun' ), 'planned' ) );
			self::assertSame( 'interrupted', self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $back ) )['state'] );
		}
		if ( 'parts' === $unread ) {
			self::assertSame( 1, $wpdb->update( AB_MCP_Sync_Schema::table( 'steps' ), array( 'parts' => '{"forward_run":{"~f":"000000000000f03f"}}' ), array( 'run_id' => $back, 'seq' => $seq ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::assertSame( array( 'parts' ), AB_MCP_Sync_Run::step( $back, $seq )['unread'], 'The entry of the step of the way back does not read back.' );
		} elseif ( 'plan' === $unread ) {
			$plan = AB_MCP_Sync_Run::get( $back )['plan'];
			self::assertSame( 1, $wpdb->update( AB_MCP_Sync_Schema::table( 'runs' ), array( 'plan' => AB_MCP_Sync_Canon::encode( array( 'items' => $plan['items'] ) ) ), array( 'run_id' => $back ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::assertSame( array( 'items' ), array_keys( AB_MCP_Sync_Run::get( $back )['plan'] ), 'The plan of the run of the way back names no run it takes back.' );
		} else {
			// The run its plan names is itself of the type revert (by SQL):
			// read as the run that wrote, it is not known (the reader, S1).
			self::assertSame( 1, $wpdb->update( AB_MCP_Sync_Schema::table( 'runs' ), array( 'type' => 'revert' ), array( 'run_id' => $run_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$state  = static fn(): array => array( AB_MCP_Sync_Run::get( $back )['state'], array_column( AB_MCP_Sync_Run::steps( $back ), 'state', 'seq' ), self::bytes_of( $target ) );
		$before = $state();
		if ( ! $entrance ) {
			AB_MCP_Sync_Revert::admit( null );
		}
		try {
			$answer = 'checked' === $at ? AB_MCP_Sync_Run::close_checked( $back, self::admin(), true ) : self::gated( 'wp_site_revert', array( 'action' => 'next' === $at ? 'next' : 'plan', 'run_id' => $back ) );
		} finally {
			AB_MCP_Sync_Revert::admit( array( 'AB_MCP_Sync_Code_Ops', 'back' ) );
			if ( 'type' === $unread ) {
				self::assertSame( 1, $wpdb->update( AB_MCP_Sync_Schema::table( 'runs' ), array( 'type' => 'push' ), array( 'run_id' => $run_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		self::assertStringNotContainsString( 'names no written step with a journal', is_wp_error( $answer ) ? $answer->get_error_message() : (string) json_encode( $answer ), 'Never the sentence of the contents.' );
		if ( 'parts' !== $unread ) {
			$says = self::refused( $answer, 'ab_mcp_sync_run_unread' );
			self::assertStringStartsWith( sprintf( 'The record of run %d (plan) does not read back as it was written', 'plan' === $unread ? $back : $run_id ), $says );
		} elseif ( ! $entrance ) {
			$says = self::refused( $answer, 'ab_mcp_sync_revert_code' );
			self::assertStringStartsWith( sprintf( 'Run %d is a run of plugins or themes, and this installation of AlphaBridge has no way back for code', $run_id ), $says, 'The run that wrote, from the plan of the way back.' );
		} elseif ( 'next' !== $at ) {
			$says = self::refused( $answer, 'code:journal_unknown' );
			self::assertStringStartsWith( sprintf( 'Step %1$d of run %2$d does not read as a swap of code wrote it', $seq, $back ), $says, 'The entrance names the step whose entry does not read.' );
		} else {
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
			$step = self::step( $back, $seq );
			self::assertSame( 'failed', $step['state'], 'The step of the way back fails through the entrance.' );
			self::assertStringStartsWith( sprintf( 'Step %1$d of run %2$d does not read as a swap of code wrote it', $seq, $back ), $step['result']['message'] );
			self::assertSame( $before[2], $state()[2], 'Nothing is moved: the target is byte for byte what it was.' );
			return;
		}
		self::assertSame( $before, $state(), 'Nothing was written: the run, its steps and the target stay as they were.' );
	}

	/** Where the core asks for a step of a run of the way back of its own: its step (next), its continuation (action=plan), way (a); with and without the entrance; its entry or the plan of the run not read. */
	public static function entry_unread(): array {
		return array(
			'next'                          => array( 'next', true, 'parts' ),
			'continue'                      => array( 'continue', true, 'parts' ),
			'way (a)'                       => array( 'checked', true, 'parts' ),
			'next without the entrance'     => array( 'next', false, 'parts' ),
			'continue without the entrance' => array( 'continue', false, 'parts' ),
			'way (a) without the entrance'  => array( 'checked', false, 'parts' ),
			'a plan that names no run'      => array( 'continue', true, 'plan' ),
			'a plan that names a way back'  => array( 'continue', true, 'type' ),
		);
	}

	/**
	 * Prüft: the case of the skeptic E1b (B1, question 2) for a run of the
	 * code (the reader, S3): its way back, a run of its own closed as
	 * reverted, whose step has an entry that does not read back (by SQL),
	 * refuses the plan of the run of the code with the sentence of the one
	 * reader (ab_mcp_sync_revert_unread); its entry read back again, the
	 * swap is taken back already, and a step goes back once.
	 * Sonst: the swap would be planned back a second time, through the one
	 * entrance, after its way back was closed.
	 */
	public function testAStepOfTheCodeIsNotPlannedBackASecondTimeWhereItsWayBackDoesNotReadBack(): void {
		global $wpdb;
		list( $run_id ) = self::swapped_f56();
		for ( $i = 0; $i < 6 && ! in_array( AB_MCP_Sync_Run::get( $run_id )['state'], AB_MCP_Sync_Schema::CLOSED, true ); $i++ ) {
			self::next( $run_id );
		}
		$way  = self::way_back_of( $run_id );
		$back = (int) $way['begun']['run_id'];
		self::assertSame( 'closed_reverted', $way['back']['state'], json_encode( $way['back'] ) );
		$seq   = (int) self::back_step( $back )['seq'];
		$steps = AB_MCP_Sync_Schema::table( 'steps' );
		$entry = (string) $wpdb->get_var( $wpdb->prepare( "SELECT parts FROM `$steps` WHERE run_id = %d AND seq = %d", $back, $seq ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::assertSame( 1, $wpdb->update( $steps, array( 'parts' => '{"forward_run":{"~f":"000000000000f03f"}}' ), array( 'run_id' => $back, 'seq' => $seq ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$says = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id ) ), 'ab_mcp_sync_revert_unread' );
		self::assertStringStartsWith( sprintf( 'The record of step %1$d of run %2$d (parts) does not read back as it was written', $seq, $back ), $says, 'Never planned a second time.' );
		self::assertSame( 1, $wpdb->update( $steps, array( 'parts' => $entry ), array( 'run_id' => $back, 'seq' => $seq ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::assertStringContainsString( 'taken back already', self::refused( self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id, 'steps' => array( 3 ) ) ), 'ab_mcp_sync_revert_steps' ) );
	}

	/**
	 * Prüft: a swap broken off between its two renames (the second throws,
	 * and the call ends without the end of the step): its step stays begun,
	 * the old version whole in
	 * the backup, the target empty, the mark open, and the run is
	 * interrupted. Its way back on the same run puts the old version from
	 * the backup onto the empty target (place_empty()), proves it there,
	 * closes the open mark of the swap (found by its run and step in the
	 * list of the marks, as the swap gave no result) and is done; nothing
	 * is moved aside, and the new version stays in its stage.
	 * Sonst: a swap cut off half way would leave the site without the
	 * plugin, or its mark open with the stage and the backup it holds.
	 */
	public function testASwapBrokenOffBetweenItsRenamesIsPutBackByTheWayBackOfItsRun(): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$old = self::bytes_of( $target );
		$answer = self::swap_with(
			$run_id,
			array(
				array(
					'method' => 'rename',
					'nth'    => 2,
					'kind'   => 'throw',
				),
			)
		);
		AB_MCP_Test_Seam::restore_all();
		$swap = self::step( $run_id, 3 );
		self::assertSame( 'begun', $swap['state'], json_encode( is_wp_error( $answer ) ? array( $answer->get_error_code(), $answer->get_error_message() ) : $answer ) );
		clearstatcache();
		self::assertFalse( file_exists( $target ), 'The target is empty.' );
		self::assertSame( $old, self::bytes_of( $swap['parts']['old']['path'] ), 'The old version lies whole in the backup.' );
		self::assertSame( 'open', self::mark_of_swap( $run_id )['state'] );
		self::assertSame( 'interrupted', self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) )['state'] );
		$way = self::way_back_of( $run_id );
		self::assertSame( $run_id, (int) $way['begun']['run_id'], 'The way back goes on the same run.' );
		$step = self::back_step( $run_id );
		self::assertSame( 'done', $step['state'], $step['result']['message'] );
		self::assertNull( $step['result']['place'], 'Nothing to move aside: the target was empty.' );
		clearstatcache();
		self::assertSame( $old, self::bytes_of( $target ), 'The old version is back at the target.' );
		self::assertSame( 'closed', self::mark_of_swap( $run_id )['state'], 'The open mark of the swap is closed.' );
		self::assertSame( self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '2.0.0' ) ), self::bytes_of( $swap['parts']['new']['path'] ), 'The new version stays in its stage.' );
	}

	/**
	 * Prüft: Z13 on the way back: where OPcache cannot be cleared for a file
	 * the way back put back or moved away, its step is unclear, and its
	 * answer says to have OPcache cleared at the host (the sentence of the
	 * swap), with the frame of the way back and target and backup; it does
	 * not say that the old version is back.
	 * Sonst: the site could run code of the new version from OPcache while
	 * the answer says the old one is back.
	 */
	public function testOpcacheNotClearedOnTheWayBackEndsItsStepUnclearWithTheHost(): void {
		list( $run_id, , $target, $backup ) = self::swapped_f56();
		self::seam_of_swap(
			array(
				array(
					'method' => 'opcache',
					'nth'    => 1,
					'kind'   => 'return',
					'value'  => 'not_possible',
				),
			)
		);
		$way  = self::way_back_of( $run_id );
		$step = self::back_step( (int) $way['begun']['run_id'] );
		$says = $step['result']['message'];
		self::assertSame( 'unclear', $step['state'], $says );
		self::assertStringContainsString( 'Have OPcache of this site cleared at the host, or PHP restarted (PHP-FPM or the web server), so that PHP runs the files that are at the target now. The step of the way back is unclear', $says );
		self::assertStringEndsWith( "\nTarget: " . $target . "\nBackup: " . $backup, $says );
		self::assertStringNotContainsString( 'is in its old version', $says );
	}

	/**
	 * Prüft: Z20b: the way back of a swap done is done only where close()
	 * with kept proves the old version at the target in its call; a file of
	 * the old version changed right after the renames (while OPcache is
	 * cleared) leaves the step unclear with the proof that failed, which
	 * names the old version as the one that was to be there (skeptic E1,
	 * B2), and the answer does not say that the old version is back.
	 * Sonst: the way back would end done on a version at the target it did
	 * not prove, or its answer would name the new version as not found and
	 * read as if nothing had moved.
	 */
	public function testTheWayBackIsDoneOnlyWhereTheOldVersionIsProvedAtTheTargetAfterItsRenames(): void {
		list( $run_id, , $target ) = self::swapped_f56();
		self::seam_of_swap(
			array(
				array(
					'method' => 'opcache',
					'nth'    => 1,
					'kind'   => 'before',
					'before' => static function () use ( $target ): void {
						self::assertNotFalse( file_put_contents( $target . '/readme.txt', "\nanders", FILE_APPEND ) );
					},
				),
			)
		);
		$way  = self::way_back_of( $run_id );
		$step = self::back_step( (int) $way['begun']['run_id'] );
		$says = $step['result']['message'];
		self::assertSame( 'unclear', $step['state'], $says );
		self::assertStringStartsWith( 'The version at ' . $target . ' could not be proved as the old version of the swap (', $says );
		self::assertStringNotContainsString( 'is in its old version', $says );
	}

	/**
	 * Prüft: Z26 on the way back (question 5 of part E): the rights of the
	 * operation of the unit are asked right before the first write of the
	 * way back; where this site lets WordPress change no files (the filter
	 * file_mod_allowed, as DISALLOW_FILE_MODS), its step refuses with the
	 * code of rights() and the sentence of the way back (which right, and
	 * the way by hand; no abort of the run), stays planned, and nothing
	 * moves. Another engine under the name code is no refusal of the
	 * rights: its WP_Error is the answer as it is, as in the swap (skeptic
	 * E1, C2), and the step stays planned too. With the right given back,
	 * the next call puts the old version back.
	 * Sonst: the way back would rename the folders of a site that lets no
	 * file be changed, or send the person to abort the run and plan anew,
	 * or name a right as missing where another engine holds the kind code.
	 */
	public function testTheWayBackAsksTheRightsBeforeItsFirstWriteAndStaysPlannedWithoutThem(): void {
		list( $run_id, , $target ) = self::swapped_f56();
		$preview = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id ) );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$begun = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id, 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
		$back   = (int) $begun['run_id'];
		$before = self::bytes_of( $target );
		add_filter( 'file_mod_allowed', '__return_false' );
		try {
			$says = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $back ) ), 'code:file_mods' );
		} finally {
			remove_filter( 'file_mod_allowed', '__return_false' );
		}
		self::assertStringStartsWith( 'The plugin ab-it-aktiv is not put back: this account or this site may not change its files (code:file_mods), and the step of the way back stays planned. Give that right and call wp_site_revert action=next again, or put the old version back by hand', $says );
		self::assertStringEndsWith( ' or, where its mark is still open, its instruction.', $says, 'The way by hand also where the swap gave no lines (skeptic E1, C5).' );
		self::assertStringNotContainsString( 'action=abort', $says );
		self::assertSame( 'planned', self::back_step( $back )['state'] );
		self::assertSame( $before, self::bytes_of( $target ), 'Nothing moved.' );
		// Another engine under the name code (as in the swap, Codex D6).
		$kinds = new \ReflectionProperty( \AB_MCP_Sync_Kinds::class, 'kinds' );
		$held  = $kinds->getValue();
		$other = $this->createStub( \AB_MCP_Sync_Kind::class );
		$other->method( 'kind' )->willReturn( 'code' );
		$kinds->setValue( null, array( 'code' => $other ) + $held );
		try {
			$says = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $back ) ), 'ab_mcp_sync_kind_duplicate' );
		} finally {
			$kinds->setValue( null, $held );
		}
		self::assertStringStartsWith( 'The kind code is registered with another engine than AB_MCP_Sync_Code_Kind', $says );
		self::assertStringNotContainsString( 'may not change its files', $says, 'No refusal of the rights.' );
		self::assertSame( 'planned', self::back_step( $back )['state'] );
		self::assertSame( $before, self::bytes_of( $target ), 'Nothing moved.' );
		$done = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $back ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertSame( 'done', self::back_step( $back )['state'] );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
	}

	/**
	 * Prüft: Z10 and the clock on the way back: where the lock for changes
	 * is not taken (its answer not true) or the clock of the call gives no
	 * time, the step of the way back refuses before its first write with
	 * code:lock or code:clock, stays planned and moves nothing; the next
	 * call puts the old version back.
	 * Sonst: the way back would rename the folders of the site without the
	 * lock for changes, or with no time for the proofs of its call, or a
	 * refusal would end a step the next call can do.
	 */
	#[DataProvider( 'not_taken_back' )]
	public function testTheWayBackWithoutTheLockOrTheTimeOfItsCallRefusesAndStaysPlanned( string $code ): void {
		list( $run_id, , $target ) = self::swapped_f56();
		$back   = self::planned_back( $run_id );
		$before = self::bytes_of( $target );
		if ( 'code:lock' === $code ) {
			self::seam_of_swap(
				array(
					array(
						'method' => 'take_lock',
						'kind'   => 'return',
						'value'  => false,
					),
				)
			);
		} else {
			AB_MCP_Sync_Code_Swap::$clock = static fn(): float => NAN;
		}
		try {
			$says = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $back ) ), $code );
		} finally {
			AB_MCP_Test_Seam::restore_all();
			AB_MCP_Sync_Code_Swap::$clock = null;
		}
		self::assertStringStartsWith( 'code:lock' === $code ? 'The lock for changes of the code is not read as held by this request' : 'The time of this call could not be measured', $says );
		self::assertSame( 'planned', self::back_step( $back )['state'] );
		self::assertSame( $before, self::bytes_of( $target ), 'Nothing moved.' );
		self::assertFalse( AB_MCP_Sync_Code_Swap::lock_held(), 'No lock for changes held.' );
		$done = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $back ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertSame( 'done', self::back_step( $back )['state'] );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
	}

	/** What the step of the way back does not have before its first write: the lock for changes, the time of its call. */
	public static function not_taken_back(): array {
		return array(
			'the lock for changes not taken'    => array( 'code:lock' ),
			'the time of the call not measured' => array( 'code:clock' ),
		);
	}

	/**
	 * Prüft: a rename of the way back that fails (Z16; F35 on the way back):
	 * the first (the new version from the target to its place) moves
	 * nothing; where the second (the old version from the backup to the
	 * target) fails, the new one goes back to the target, again only onto
	 * a free place. Either way the step of the way back is unclear, and its
	 * answer says that a rename failed and nothing on what lies where
	 * (option B), with the frame and the paths; the new version is at the
	 * target, the folder of the swap itself, and the old one whole in the
	 * backup, and the result of the step names no place. Where the new
	 * version cannot go back either, the target is empty and the result of
	 * the step names the place where it stays (skeptic E1, B5; G-5-14), the
	 * answer still not (option B).
	 * Sonst: a failed rename of the way back would leave the site without
	 * the plugin while the new version waits at its place, an answer would
	 * claim a state no proof read, or the new version would lie at a place
	 * nothing names.
	 *
	 * @param int[] $nths The renames of the way back that fail.
	 */
	#[DataProvider( 'renames_back' )]
	public function testARenameOfTheWayBackThatFailsEndsItsStepUnclearAndTheNewVersionIsWhereItsResultSays( array $nths ): void {
		list( $run_id, , $target, $backup ) = self::swapped_f56();
		clearstatcache();
		$at     = lstat( $target );
		$new    = self::bytes_of( $target );
		$old    = self::bytes_of( $backup );
		$faults = array();
		foreach ( $nths as $nth ) {
			$faults[] = array(
				'method' => 'rename',
				'nth'    => $nth,
				'kind'   => 'return',
				'value'  => false,
			);
		}
		self::seam_of_swap( $faults );
		$way  = self::way_back_of( $run_id );
		$step = self::back_step( (int) $way['begun']['run_id'] );
		$says = $step['result']['message'];
		self::assertSame( 'unclear', $step['state'], $says );
		self::assertStringStartsWith( 'A rename of the way back failed (rename() failed, or the place was not free). The step of the way back is unclear:', $says );
		self::assertStringEndsWith( "\nTarget: " . $target . "\nBackup: " . $backup, $says );
		self::assertSame( $old, self::bytes_of( $backup ), 'The old version lies whole in the backup.' );
		clearstatcache();
		if ( 2 === count( $nths ) ) {
			self::assertFalse( file_exists( $target ), 'The target is empty.' );
			self::assertIsString( $step['result']['place'], 'The result of the step names the place of the new version.' );
			self::assertStringNotContainsString( $step['result']['place'], $says, 'The answer does not (option B).' );
			$now = lstat( $step['result']['place'] );
			self::assertSame( array( $at['dev'], $at['ino'], $new ), array( $now['dev'], $now['ino'], self::bytes_of( $step['result']['place'] ) ), 'The new version is at its place, the folder of the swap itself.' );
			return;
		}
		self::assertNull( $step['result']['place'], 'No place is filled.' );
		$now = lstat( $target );
		self::assertSame( array( $at['dev'], $at['ino'], $new ), array( $now['dev'], $now['ino'], self::bytes_of( $target ) ), 'The new version is at the target, the folder of the swap itself.' );
	}

	/** Which renames of the way back fail: the first, the second, the second and the one that takes the new version back. */
	public static function renames_back(): array {
		return array(
			'the first rename'                 => array( array( 1 ) ),
			'the second rename'                => array( array( 2 ) ),
			'the second rename and its return' => array( array( 2, 3 ) ),
		);
	}

	/**
	 * Prüft: the place of the new version not given on the way back (Z22,
	 * question 1 B of part E; skeptic E1, B1): where the group of its own in
	 * the backups of the way back cannot be made (open(): the protected
	 * store makes no folder), the step of the way back refuses with the
	 * refusal of the store before its journal begun: it stays planned,
	 * nothing moves, the new version is at the target (the folder of the
	 * swap itself) and the old one whole in the backup. The next call, with
	 * the store writable again, takes the place and puts the old version
	 * back, as the sentence of the store («then try again») says.
	 * Sonst: the way back would rename the new version to a place it was
	 * not given, or end its step unclear on a place not given, so that the
	 * call the refusal names finds the step gone back already.
	 */
	public function testTheWayBackWithoutAPlaceForTheNewVersionRefusesAndStaysPlanned(): void {
		list( $run_id, , $target, $backup ) = self::swapped_f56();
		clearstatcache();
		$at   = lstat( $target );
		$new  = self::bytes_of( $target );
		$old  = self::bytes_of( $backup );
		$back = self::planned_back( $run_id );
		AB_MCP_Test_Seam::install(
			AB_MCP_Protected_Store::class,
			'naht_protected_store',
			new \AB_MCP_Protected_Store_Calls(),
			array(
				array(
					'method' => 'mkdir',
					'nth'    => 1,
					'kind'   => 'return',
					'value'  => false,
				),
			)
		);
		try {
			$says = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $back ) ), 'ab_mcp_store_write' );
		} finally {
			AB_MCP_Test_Seam::restore_all();
		}
		self::assertStringStartsWith( 'The group could not be made in the protected store. ', $says );
		self::assertStringEndsWith( ', then try again.', $says );
		$step = self::back_step( $back );
		self::assertSame( 'planned', $step['state'], 'Nothing begun: the journal of the step is as it was.' );
		clearstatcache();
		$now = lstat( $target );
		self::assertSame( array( $at['dev'], $at['ino'], $new ), array( $now['dev'], $now['ino'], self::bytes_of( $target ) ), 'The new version is at the target, the folder of the swap itself.' );
		self::assertSame( $old, self::bytes_of( $backup ), 'The old version lies whole in the backup.' );
		$done = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $back ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_code() . ': ' . $done->get_error_message() : '' );
		self::assertSame( 'done', self::back_step( $back )['state'], 'Tried again, the step puts the old version back.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
		self::assertSame( $new, self::bytes_of( self::back_step( $back )['result']['place'] ), 'The new version lies at its place.' );
	}

	/**
	 * Prüft: G-5-14, the mark gone: the folder of the mark of a swap done goes
	 * a day after the mark closed (AB_MCP_Protected_Store::cleanup(); here
	 * moved aside under a name that is no mark). Its way back then finds no
	 * mark in the list and closes none, and the proof of the old version at
	 * the target stands alone: the step is done.
	 * Sonst: the way back of a swap older than a day would end unclear for
	 * a mark that is gone by design.
	 */
	public function testTheWayBackOfASwapWhoseMarkIsGoneIsDoneOnTheProofAtTheTargetAlone(): void {
		list( $run_id, $unit, $target ) = self::swapped_f56();
		$named = AB_MCP_Protected_Store::named_by_swap();
		self::assertIsArray( $named );
		$folder = $named['marks'] . '/' . self::mark_of_swap( $run_id )['mid'];
		self::assertTrue( rename( $folder, $folder . '-weg' ) );
		self::assertNull( self::mark_of_swap( $run_id ), 'No mark of the swap in the list.' );
		$way  = self::way_back_of( $run_id );
		$step = self::back_step( (int) $way['begun']['run_id'] );
		self::assertSame( 'done', $step['state'], $step['result']['message'] );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
	}

	/**
	 * Prüft: a swap whose journal no longer reads as one (its row step.swap
	 * without the target): the plan of the way back refuses with the
	 * sentence of back() and, as only a plan takes steps, the way of the
	 * other steps (skeptic E1, B3); written after the way back was planned,
	 * its step of the way back fails with the sentence alone (the step and
	 * run of the swap, the way by hand, by the lines of its swap or the
	 * instruction of its open mark, C5), never the way of steps, and nothing
	 * moves.
	 * Sonst: the way back would act on a journal it cannot read, keep a
	 * step planned that no later call can do, or send the person to a way
	 * that is refused there.
	 */
	public function testAJournalThatNoLongerReadsAsASwapFailsItsStepOfTheWayBackWithNothingMoved(): void {
		list( $run_id, , $target, $backup ) = self::swapped_f56();
		$read  = self::step( $run_id, 3 )['parts'];
		$parts = $read;
		unset( $parts['target'] );
		$no = sprintf( 'Step 3 of run %d does not read as a swap of code wrote it (its unit in the plan of the run, or its journal), so no way back is planned or run on it and nothing of it is put back. Check the unit by hand in wp-admin, or with the lines the answer of its swap named (wp_site_push action=status) or, where its mark is still open, its instruction.', $run_id );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 3, array( 'parts' => $parts ), 'done' ) );
		$says = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id ) ), 'code:journal_unknown' );
		self::assertSame( $no . ' To plan the way back of the other steps that wrote, pass steps without 3.', $says, 'The plan names the way of the other steps.' );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 3, array( 'parts' => $read ), 'done' ) );
		$back = self::planned_back( $run_id );
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 3, array( 'parts' => $parts ), 'done' ) );
		clearstatcache();
		$before = self::bytes_of( $target );
		$old    = self::bytes_of( $backup );
		$answer = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $back ) );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step = self::back_step( $back );
		$says = $step['result']['message'];
		self::assertSame( 'failed', $step['state'], $says );
		self::assertSame( $no, $says, 'The step of the way back names no way of steps (B3).' );
		clearstatcache();
		self::assertSame( array( $before, $old ), array( self::bytes_of( $target ), self::bytes_of( $backup ) ), 'Nothing moved.' );
	}

	/**
	 * Prüft: a swap that failed with nothing moved (its first rename
	 * refused) wrote nothing: the way back of its run plans no step on it
	 * and refuses with ab_mcp_sync_revert_nothing (AB_MCP_Sync_Code_Ops::back(),
	 * plan: a swap failed is no write).
	 * Sonst: the way back would plan a step on a swap that moved nothing,
	 * and move the version of the site.
	 */
	public function testASwapThatFailedWithNothingMovedIsNoStepOfTheWayBack(): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$answer = self::swap_with(
			$run_id,
			array(
				array(
					'method' => 'rename',
					'nth'    => 1,
					'kind'   => 'return',
					'value'  => false,
				),
			)
		);
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		self::assertSame( 'failed', self::step( $run_id, 3 )['state'], $answer['next'] );
		AB_MCP_Test_Seam::restore_all();
		$before = self::bytes_of( $target );
		self::refused( self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id ) ), 'ab_mcp_sync_revert_nothing' );
		self::assertSame( $before, self::bytes_of( $target ) );
	}

	/**
	 * Prüft: F9 (plan F9; Z22, Z20b; section 2 of part E): the child theme
	 * that runs here (ab-it-kind, a child of ab-it-eltern) with its
	 * functions.php changed on staging (1.1.0) is planned the public way,
	 * released with accept, staged from wordpress.org (the stand-in) and
	 * swapped in the run; the site runs the new functions.php, and the theme
	 * that runs is still ab-it-kind. accept of the way back for its swap
	 * step, or for the step that staged it, is refused with the way (no swap
	 * of a plugin that ran when it began), and nothing of the way back
	 * begins. Then the way back of the run puts the old version back
	 * (put_back()); the theme still runs, in its old version.
	 * Sonst: a child theme changed on staging could not be brought over or
	 * taken back by the run, or accept would be taken for a step whose way
	 * back activates nothing, and the person would read it as a release that
	 * does something.
	 */
	public function testAChildThemeWithAChangedFunctionsFileIsSwappedAndPutBackByTheWayBackOfItsRun(): void {
		self::paired();
		AB_MCP_IT_Code::put( 'b', 'theme', 'ab-it-kind', '1.1.0' );
		list( $report, $units ) = self::report();
		self::assertArrayHasKey( 'theme:ab-it-kind', $units, implode( ' ', array_keys( $units ) ) );
		$n      = (int) $units['theme:ab-it-kind']['n'];
		$target = AB_MCP_IT_Code::path( 'a', 'theme', 'ab-it-kind' );
		self::assertSame( array( '1.0.0', 'ab-it-kind' ), array( AB_MCP_IT_Code::fassung( 'a', 'theme', 'ab-it-kind' ), AB_MCP_IT_Code::theme_options( 'a' )['stylesheet'] ) );
		self::wporg();
		list( , $run ) = self::begun(
			array(
				'action'    => 'plan',
				'report_id' => $report['report_id'],
				'select'    => array( $n ),
				'accept'    => array( $n ),
			)
		);
		$run_id = (int) $run['run_id'];
		$unit   = AB_MCP_Sync_Code_Ops::item( AB_MCP_Sync_Run::get( $run_id )['plan'], 3 )['unit'];
		self::assertSame( array( 'theme', 'ab-it-kind', 'swap', true, true ), array( $unit['kind'], $unit['ref'], $unit['op'], $unit['runs'], $unit['accept'] ) );
		// The check, the stage over calls, then the swap.
		for ( $i = 0; $i < 25 && 'planned' === self::step( $run_id, 3 )['state']; $i++ ) {
			$answer = self::next( $run_id );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		}
		self::assertSame( 'done', self::step( $run_id, 3 )['state'], json_encode( self::step( $run_id, 3 )['result'] ) );
		self::assertSame( array( '1.1.0', '1.1.0', 'ab-it-kind' ), array( AB_MCP_IT_Code::fassung( 'a', 'theme', 'ab-it-kind' ), AB_MCP_IT_Code::mark( 'a', 'ab-it-kind' ), AB_MCP_IT_Code::theme_options( 'a' )['stylesheet'] ), 'The site runs the new functions.php of the theme that runs.' );
		foreach ( array( 2, 3 ) as $seq ) {
			$says = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id, 'accept' => array( $seq ) ) ), 'ab_mcp_sync_code_accept' );
			self::assertSame( sprintf( 'Step %1$d of run %2$d is in accept, but it is no swap of a plugin that ran when the swap began, so its way back activates nothing: accept takes the steps whose plugin the way back activates again, with its activation routine, where it is inactive after it. Pass accept without %1$d.', $seq, $run_id ), $says );
		}
		self::assertSame( 'open', AB_MCP_Sync_Run::get( $run_id )['state'], 'Nothing of the way back began.' );
		self::put_back(
			$run_id,
			$unit,
			$target,
			array(
				'old' => self::bytes_of( AB_MCP_IT_Code::source( 'theme', 'ab-it-kind', '1.0.0' ) ),
				'new' => self::bytes_of( AB_MCP_IT_Code::source( 'theme', 'ab-it-kind', '1.1.0' ) ),
			)
		);
		self::assertSame( array( '1.0.0', '1.0.0', 'ab-it-kind' ), array( AB_MCP_IT_Code::fassung( 'a', 'theme', 'ab-it-kind' ), AB_MCP_IT_Code::mark( 'a', 'ab-it-kind' ), AB_MCP_IT_Code::theme_options( 'a' )['stylesheet'] ), 'The theme still runs, in its old version.' );
	}

	/**
	 * Prüft: Z25 and Z43 (section 2 of part E): a plugin that ran when its
	 * swap began (F56) and is deactivated after it (here silently, as
	 * WordPress does with a plugin whose main file it misses) comes back in
	 * its old version by the way back of the run and is activated again only
	 * with its step in accept. The preview names it, with its activation
	 * routine or as staying inactive with the way. With accept the step
	 * activates it through the Activation Guard, its activation routine
	 * counted once, and only where its old version is proved at the target
	 * (OPcache not cleared: unclear, nothing activated); a refusal of the
	 * guard (no time left in the call for its test requests) and its
	 * warning (the test requests switched off) are in the answer as they
	 * are, the refusal with the way. Without accept it stays inactive, its
	 * routine not run, and the answer names it with the way.
	 * Sonst: the way back would leave a plugin of the customer switched off
	 * without a word, or switch it on without the release of the person,
	 * without its activation routine or its test requests, or on a target
	 * it did not prove.
	 */
	#[DataProvider( 'activations_back' )]
	public function testAPluginDeactivatedAfterItsSwapIsActivatedAgainByTheWayBackOnlyWithAccept( string $case ): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$main = 'ab-it-aktiv/ab-it-aktiv.php';
		list( $run_id ) = self::swapped_f56();
		deactivate_plugins( $main, true );
		self::assertNotContains( $main, self::active(), 'Deactivated after the swap.' );
		$counted = AB_MCP_IT_Code::counters( 'a', 'ab-it-aktiv' )['aktiviert'];
		$accept  = 'without accept' !== $case;
		if ( 'unclear' === $case ) {
			self::seam_of_swap(
				array(
					array(
						'method' => 'opcache',
						'nth'    => 1,
						'kind'   => 'return',
						'value'  => 'not_possible',
					),
				)
			);
		}
		if ( 'not checked' === $case ) {
			add_filter( 'ab_mcp_php_probe_enabled', '__return_false' );
		}
		// The way back is a request of its own for the budget of the guard
		// (AB_MCP_Update_Guard::request_start()); one that took 60 seconds
		// already has no time left for its test requests.
		$started                       = $_SERVER['REQUEST_TIME_FLOAT'];
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - ( 'out of time' === $case ? 60 : 0 );
		try {
			$way = self::way_back_of( $run_id, $accept ? array( 'accept' => array( 3 ) ) : array() );
		} finally {
			$_SERVER['REQUEST_TIME_FLOAT'] = $started;
			remove_filter( 'ab_mcp_php_probe_enabled', '__return_false' );
		}
		$shown = array_values( array_filter( $way['preview']['preview']['steps'], static fn( $s ) => 3 === (int) $s['seq'] ) );
		$off   = sprintf( 'The plugin %s ran when its swap began and is not read as active now, and step 3 is not in accept of its way back (wp_site_revert action=plan), so the way back leaves it inactive. Activate it in wp-admin (Plugins → Activate) or with wp_activate_plugin; either runs its activation routine.', $main );
		$will  = sprintf( 'The plugin %s ran when its swap began and is not read as active now: once its old version is proved at the target, the way back activates it again with its activation routine, checked like an update (wp_activate_plugin).', $main );
		self::assertSame( array( $main => $accept ? $will : $off ), $shown[0]['activate'], 'The preview names it with its activation routine, or as staying inactive with the way (Z43).' );
		$step = self::back_step( (int) $way['begun']['run_id'] );
		$says = $step['result']['message'];
		self::assertSame( 'unclear' === $case ? 'unclear' : 'done', $step['state'], $says );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), 'Its old version is at the target.' );
		$on      = sprintf( 'The plugin %s is active again, with its activation routine, checked like an update (wp_activate_plugin).', $main );
		$now     = array( in_array( $main, self::active(), true ), AB_MCP_IT_Code::counters( 'a', 'ab-it-aktiv' )['aktiviert'] - $counted );
		$expects = array(
			'without accept' => array( array( false, 0 ), $off ),
			'with accept'    => array( array( true, 1 ), $on ),
			'not checked'    => array( array( true, 1 ), $on . ' Not checked: the test requests are switched off with the ab_mcp_php_probe_enabled filter;' ),
			'out of time'    => array( array( false, 0 ), sprintf( 'So the way back leaves the plugin %s inactive: activate it in wp-admin (Plugins → Activate) or with wp_activate_plugin.', $main ) ),
			'unclear'        => array( array( false, 0 ), 'The step of the way back is unclear' ),
		);
		self::assertSame( $expects[ $case ][0], $now, 'Active, and its activation routine run: ' . $says );
		self::assertStringContainsString( $expects[ $case ][1], $says );
		if ( 'out of time' === $case ) {
			self::assertStringContainsString( 'Not activated: ', $says, 'The refusal of the guard as it is.' );
		}
		if ( 'unclear' === $case ) {
			self::assertStringNotContainsString( 'ran when its swap began', $says, 'Nothing of the plugin is said or done where the old version is not proved.' );
		}
	}

	/** The way back of a plugin deactivated after its swap: without accept, with it, with its test requests off, with no time left for them, and where the old version is not proved (OPcache). */
	public static function activations_back(): array {
		return array(
			'without accept' => array( 'without accept' ),
			'with accept'    => array( 'with accept' ),
			'not checked'    => array( 'not checked' ),
			'out of time'    => array( 'out of time' ),
			'unclear'        => array( 'unclear' ),
		);
	}

	/**
	 * Prüft: way (a) for a run of the code (decision 18; Z20a, Z20b;
	 * question 2 of part E): a swap broken off (the call ends without the
	 * end of its step) leaves its run interrupted, its step begun and its
	 * mark open. A person closes it as checked (AB_MCP_Sync_Run::
	 * close_checked()) only where, read now, the target holds the new
	 * version and the backup the old one whole, or the target the old
	 * version: then the mark of the swap closes, the step and the pair of
	 * the unit are unclear (question 3 A) and the run is closed as checked.
	 * With the target empty (broken off between the renames) it is refused
	 * before anything is written, with the way back and the way by hand:
	 * the run stays interrupted, its step begun, the mark open, the pair as
	 * it was, the target empty. A run interrupted on a step after its swap
	 * was done asks nothing of the swap: it closes as checked although its
	 * backup is gone by now. Way (a) on a run of the way back of its own,
	 * interrupted on its step with no version at the target: the refusal
	 * names that run and its way (its continuation, wp_site_revert
	 * action=plan with its number), not the run that wrote (skeptic E1b,
	 * B2).
	 * Sonst: a person could close a run of the code as checked with the
	 * plugin missing at its target and its mark closed, or a run with a
	 * version proved, or one whose swap was done long before, could not be
	 * closed as checked; or the refusal on a run of the way back would send
	 * to the plan of the run that wrote, which is refused while its way back
	 * is open.
	 */
	#[DataProvider( 'closed_as_checked' )]
	public function testARunOfTheCodeBrokenOffIsClosedAsCheckedOnlyWhereAVersionIsProvedAtTheTarget( string $case, ?array $fault ): void {
		if ( 'a run of the way back' === $case ) {
			list( $run_id, , $target ) = self::swapped_f56();
			for ( $i = 0; $i < 6 && ! in_array( AB_MCP_Sync_Run::get( $run_id )['state'], AB_MCP_Sync_Schema::CLOSED, true ); $i++ ) {
				self::next( $run_id );
			}
			$back = self::planned_back( $run_id );
			self::assertNotSame( $run_id, $back, 'The way back is a run of its own.' );
			self::assertTrue( AB_MCP_Sync_Run::update_step( $back, (int) self::back_step( $back )['seq'], array( 'state' => 'begun' ), 'planned' ) );
			self::assertSame( 'interrupted', self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $back ) )['state'] );
			self::assertTrue( rename( $target, $target . '-weg' ), 'No version at the target.' );
			$says = self::refused( AB_MCP_Sync_Run::close_checked( $back, self::admin(), true ), 'code:checked' );
			self::assertStringContainsString( sprintf( 'So run %1$d is not closed as checked: that takes the old version of the plugin ab-it-aktiv proved at its target, or the new one there and the old one whole in the backup. Nothing was written, and the run stays interrupted. Take its way back (wp_site_revert action=plan with run_id %1$d). Where the mark of its swap (run %2$d, step ', $back, $run_id ), $says, 'The way by hand only where the mark of the swap is still open (skeptic F2+G, C1).' );
			self::assertSame( 'interrupted', AB_MCP_Sync_Run::get( $back )['state'] );
			self::assertIsArray( self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $back ) ), 'The way it names is open: the continuation of the way back.' );
			return;
		}
		if ( null === $fault ) {
			list( $run_id, $unit, , $backup ) = self::swapped_f56();
			self::assertTrue( rename( $backup, $backup . '-weg' ), 'The backup gone.' );
			self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 4, array( 'state' => 'begun' ), 'planned' ), 'Its next step begun by a call that ended.' );
			self::assertSame( 'interrupted', self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) )['state'] );
			$closed = AB_MCP_Sync_Run::close_checked( $run_id, self::admin(), true );
			self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_code() . ': ' . $closed->get_error_message() : '' );
			self::assertSame( array( 'closed_checked', 'done', 'unclear' ), array( $closed['state'], self::step( $run_id, 3 )['state'], self::step( $run_id, 4 )['state'] ), 'Closed as checked; the swap is not asked again.' );
			return;
		}
		list( $run_id, $unit, $target ) = self::ready_to_swap();
		$old = self::bytes_of( $target );
		self::swap_with( $run_id, array( $fault ) );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( 'begun', self::step( $run_id, 3 )['state'] );
		self::assertSame( 'interrupted', self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) )['state'] );
		$state = static fn(): array => array( AB_MCP_Sync_Run::get( $run_id )['state'], self::step( $run_id, 3 )['state'], self::mark_of_swap( $run_id )['state'], AB_MCP_Sync_Pairs::get( $unit['pair_id'] )['state'] );
		self::assertSame( array( 'interrupted', 'begun', 'open', 'active' ), $state() );
		$closed = AB_MCP_Sync_Run::close_checked( $run_id, self::admin(), true );
		clearstatcache();
		if ( 'target empty' === $case ) {
			$says = self::refused( $closed, 'code:checked' );
			self::assertStringStartsWith( sprintf( 'The version at %s could not be proved as the old version of the swap', $target ), $says );
			self::assertStringContainsString( sprintf( 'So run %1$d is not closed as checked: that takes the old version of the plugin ab-it-aktiv proved at its target, or the new one there and the old one whole in the backup. Nothing was written, and the run stays interrupted. Take its way back (wp_site_revert action=plan with run_id %1$d). Where the mark of its swap (run ', $run_id ), $says );
			self::assertSame( array( 'interrupted', 'begun', 'open', 'active' ), $state(), 'Nothing was written.' );
			self::assertFalse( file_exists( $target ), 'The target is still empty.' );
			return;
		}
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_code() . ': ' . $closed->get_error_message() : '' );
		self::assertSame( array( 'closed_checked', 'unclear', 'closed', 'unclear' ), $state(), 'The mark closed, the step and the pair unclear, the run closed as checked.' );
		self::assertSame( 'new version at the target' === $case ? self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '2.0.0' ) ) : $old, self::bytes_of( $target ) );
	}

	/** Where a swap is broken off: between its renames (the target empty), after them (the new version at the target, OPcache), before the first (the old version at the target); a run interrupted on its next step after its swap was done, and a run of the way back of its own interrupted on its step with no version at the target (no fault: the cases of their own). */
	public static function closed_as_checked(): array {
		return array(
			'target empty'              => array(
				'target empty',
				array(
					'method' => 'rename',
					'nth'    => 2,
					'kind'   => 'throw',
				),
			),
			// Call 1 of OPcache is the one of prepare(); 2 the first after the renames.
			'new version at the target' => array(
				'new version at the target',
				array(
					'method' => 'opcache',
					'nth'    => 2,
					'kind'   => 'throw',
				),
			),
			'old version at the target' => array(
				'old version at the target',
				array(
					'method' => 'rename',
					'nth'    => 1,
					'kind'   => 'throw',
				),
			),
			'after the swap'            => array( 'after the swap', null ),
			'a run of the way back'     => array( 'a run of the way back', null ),
		);
	}

	/**
	 * Prüft: F58 (plan F58 and Rest b; Zettel F, section 2, with the
	 * decision of the lead on its question 1): the swap of step 3, in a
	 * process of its own, aborted hard (SIGKILL, no shutdown function) at
	 * each of its three points, for the active plugin of F56 and for the
	 * child theme that runs (as F9): P1 before the first rename, P2 between
	 * the renames, P3 after the second. The lock for changes the child left
	 * keeps the run open (its swap may still run) until it is TIMEOUT old;
	 * then the run is interrupted, its step begun, its mark open, the pair
	 * as it was, the note of the replacement written, and the first lines
	 * of the instruction of the mark send what is on the disk to the
	 * section of that point. At P1 and P3 that is the state the tests of
	 * their ways by hand and of way (a) hold, so those ways are not run
	 * here again (question 1): the old version at the target, nothing at
	 * the place of the backup, the new one in the stage, section
	 * [vor-den-umbenennungen], as testAnOldVersionBackAtTheTargetWhose
	 * ProofOrMarkFailsEndsUnclearWithTheShortAnswer (its section) shows it;
	 * the new version at the target, the old one in the backup, nothing at
	 * the place of the stage, section [nach-den-umbenennungen], as
	 * testOpcacheNotClearedAfterTheRenamesEndsUnclearWithTheWayBackTheWay
	 * ByHandAndTheHost shows it; and the run, the step, the mark and the
	 * pair as testARunOfTheCodeBrokenOffIsClosedAsCheckedOnlyWhereAVersion
	 * IsProvedAtTheTarget has them before way (a) (its cases of the old and
	 * of the new version at the target).
	 * At P2 way (a) refuses before anything is written, with the way back
	 * and the way by hand; without the entrance (no way back for code)
	 * wp_site_revert refuses and names the folder of the marks, and the
	 * command of the section between the renames puts the old version back
	 * at the target.
	 * Then way (b), the way back of the run, on the same run: the old
	 * version at the target, the folder it was before the swap; the mark
	 * closed, the pair unclear, the step done and the run closed as
	 * reverted; at P1 and after the way by hand nothing moved, no OPcache,
	 * the stage as it was; at P2 the old version back from the backup and
	 * OPcache for its PHP files; at P3 the new version in a group of its own
	 * and OPcache for the PHP files of both. Where WordPress let go of the
	 * missing unit at P2 (validate_active_plugins(), validate_current_
	 * theme(), as wp-admin does), the answer names the plugin left inactive
	 * or the theme that does not run now, each with its way; a theme is
	 * named only where this call read it as not running, never where its
	 * rows are not read (option B).
	 * Sonst: a hard abort at one of the points would leave a state that one
	 * of the ways does not know, or a site without its plugin or theme and
	 * no word of it.
	 */
	#[DataProvider( 'aborted_hard' )]
	public function testASwapAbortedHardAtEachPointLeavesAStateItsWaysBackKnow( string $kind, string $point, string $way ): void {
		list( $run_id, $unit, $target, $old, $new ) = self::ready_to_swap_of( $kind );
		clearstatcache();
		$ino = lstat( $target )['ino'];
		self::aborted_at( $run_id, $point );
		$state = static fn(): array => array( AB_MCP_Sync_Run::get( $run_id )['state'], self::step( $run_id, 3 )['state'], self::mark_of_swap( $run_id )['state'], AB_MCP_Sync_Pairs::get( $unit['pair_id'] )['state'] );
		self::assertSame( array( 'interrupted', 'begun', 'open', 'active' ), $state(), 'The run, its step, the mark and the pair, as way (a) of part E finds them.' );
		self::assertNotNull( self::replaced_row(), 'The note of the replacement is written, and it stays.' );
		list( $guide, $places ) = self::guide_of( $run_id );
		self::assertSame( $ino, $places['old'], 'The mark names the folder of the target before the swap as the old version.' );
		$at    = array(
			'P1' => array( 'old', null, 'new', 'vor-den-umbenennungen' ),
			'P2' => array( null, 'old', 'new', 'zwischen-den-umbenennungen' ),
			'P3' => array( 'new', 'old', null, 'nach-den-umbenennungen' ),
		)[ $point ];
		$shown = self::shown_by( $guide, $places );
		self::assertSame( array_map( static fn( $v ) => null === $v ? null : $places[ $v ], array_slice( $at, 0, 3 ) ), array_values( $shown ), 'What the first lines of the instruction show at target, backup and stage.' );
		self::assertSame( $at[3], self::fits( $guide, $places, $shown ), 'The first lines send this state to the section of its point.' );
		clearstatcache();
		self::assertSame( array( 'P1' => $old, 'P2' => array(), 'P3' => $new )[ $point ], file_exists( $target ) ? self::bytes_of( $target ) : array(), 'The version at the target, byte for byte, or none.' );
		$sentence = sprintf( 'The theme %s is not active on this site now (neither the theme of this site nor its parent); where it, or a child theme of it, was active before the swap, switch back to that theme in wp-admin (Appearance → Themes).', $unit['ref'] );
		if ( 'by hand' === $way ) {
			self::assertTrue( 'theme' !== $kind || self::theme_let_go(), 'WordPress lets go of the theme whose folder it misses.' );
			AB_MCP_Sync_Revert::admit( null );
			try {
				$says = self::refused( self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id ) ), 'ab_mcp_sync_revert_code' );
			} finally {
				AB_MCP_Sync_Revert::admit( array( 'AB_MCP_Sync_Code_Ops', 'back' ) );
			}
			$marks = AB_MCP_Protected_Store::named_by_swap()['marks'];
			self::assertStringEndsWith( sprintf( 'Where the mark of a swap of it is still open in %s, its instruction there says how to put the old version back in a shell on the server.', $marks ), $says );
			self::assertStringStartsWith( $marks . '/', $guide, 'The instruction lies in that folder.' );
			self::assertStringEndsWith( 'OK', self::run_section( $guide, $at[3], 'The target is missing; the old version lies whole in the backup. Put it back and check it:' ), 'The command puts the old version back.' );
			clearstatcache();
			self::assertSame( array( $ino, $old ), array( lstat( $target )['ino'], self::bytes_of( $target ) ), 'The old version at the target, the folder it was.' );
			if ( 'theme' === $kind ) {
				// The rows of the theme not read in the calls of the way
				// back: runs() gives null, and no sentence is said (option B).
				self::seam_of_swap(
					array(
						array(
							'method' => 'option',
							'kind'   => 'return',
							'value'  => false,
						),
					)
				);
			}
		} elseif ( 'P2' === $point ) {
			$says = self::refused( AB_MCP_Sync_Run::close_checked( $run_id, self::admin(), true ), 'code:checked' );
			self::assertStringStartsWith( sprintf( 'The version at %s could not be proved as the old version of the swap', $target ), $says );
			self::assertStringContainsString( sprintf( 'So run %1$d is not closed as checked: that takes the old version of the %2$s %3$s proved at its target, or the new one there and the old one whole in the backup. Nothing was written, and the run stays interrupted. Take its way back (wp_site_revert action=plan with run_id %1$d). Where the mark of its swap (run ', $run_id, $unit['kind'], $unit['ref'] ), $says );
			clearstatcache();
			self::assertSame( array( array( 'interrupted', 'begun', 'open', 'active' ), false ), array( $state(), file_exists( $target ) ), 'Nothing was written; the target is still empty.' );
			// What wp-admin does with a unit whose files it misses.
			if ( 'plugin' === $kind ) {
				self::assertArrayHasKey( $unit['ref'] . '/' . $unit['ref'] . '.php', validate_active_plugins() );
			} else {
				self::assertTrue( self::theme_let_go(), 'WordPress switches away from the theme.' );
			}
		}
		$back = self::way_back_of( $run_id );
		self::assertSame( array( $run_id, 'closed_reverted' ), array( (int) $back['begun']['run_id'], $back['back']['state'] ), 'The way back on the same run, closed as reverted.' );
		$step = self::back_step( $run_id );
		$says = $step['result']['message'];
		self::assertSame( 'done', $step['state'], $says );
		self::assertStringStartsWith( sprintf( 'The %1$s %2$s is in its old version at %3$s, proved there', $unit['kind'], $unit['ref'], $target ), $says );
		clearstatcache();
		self::assertSame( array( $ino, $old ), array( lstat( $target )['ino'], self::bytes_of( $target ) ), 'The old version at the target, the folder it was before the swap.' );
		self::assertSame( array( 'closed', 'unclear' ), array( self::mark_of_swap( $run_id )['state'], AB_MCP_Sync_Pairs::get( $unit['pair_id'] )['state'] ), 'The mark closed, the pair to be confirmed anew.' );
		$moved = array(
			'P1' => array(),
			'P2' => 'by hand' === $way ? array() : array( $old ),
			'P3' => array( $old, $new ),
		)[ $point ];
		$php   = array();
		foreach ( $moved as $version ) {
			foreach ( array_keys( $version ) as $rel ) {
				$php[ $rel ] = $php[ $rel ] ?? AB_MCP_Sync_Code_Stage::is_php( $rel );
			}
		}
		self::assertSame( count( array_filter( $php ) ), $step['result']['opcache'], 'OPcache for each PHP file of what moved, by its path at the target.' );
		if ( 'P3' === $point ) {
			$place = $step['result']['place'];
			self::assertIsString( $place, 'The new version moved aside.' );
			self::assertSame( $new, self::bytes_of( $place ), 'The new version lies byte for byte at its place.' );
			$own = array_values( array_filter( (array) AB_MCP_Protected_Store::groups( 'backups' ), static fn( $g ) => (int) $g['run_id'] === $run_id && 'back-' . $run_id . '-' . (int) $step['seq'] === $g['label'] ) );
			self::assertCount( 1, $own, 'A group of its own of the backups of the way back.' );
			$tree = AB_MCP_Protected_Store::tree( 'backups', $own[0]['gid'], basename( $target ), self::step( $run_id, 3 )['parts']['new']['hash'] );
			self::assertSame( $place, is_array( $tree ) ? $tree['path'] : $tree->get_error_message(), 'The new version is the tree of that group.' );
		} else {
			self::assertNull( $step['result']['place'], 'Nothing moved aside.' );
			clearstatcache();
			self::assertSame( array( $places['new'], $new ), array( lstat( $places['stage'] )['ino'], self::bytes_of( $places['stage'] ) ), 'The stage as it was.' );
		}
		if ( 'plugin' === $kind ) {
			$main = $unit['ref'] . '/' . $unit['ref'] . '.php';
			self::assertSame( 'P2' === $point && 'way (b)' === $way, str_contains( $says, sprintf( 'The plugin %1$s ran when its swap began and is not read as active now, and step 3 is not in accept of its way back (wp_site_revert action=plan), so the way back leaves it inactive. Activate it in wp-admin (Plugins → Activate) or with wp_activate_plugin; either runs its activation routine.', $main ) ), 'The plugin WordPress let go of, named with its way: ' . $says );
			return;
		}
		self::assertSame( 'P2' === $point && 'way (b)' === $way, str_contains( $says, $sentence ), 'A theme read as not running, named with its way: ' . $says );
		self::assertSame( 'P2' === $point ? 'twentytwentyfive' : 'ab-it-kind', AB_MCP_IT_Code::theme_options( 'a' )['stylesheet'], 'The theme that runs.' );
	}

	/** The three points of a swap aborted hard, for a plugin and for a theme; between the renames (P2) once with way (a) before way (b), once with the way by hand without the entrance. */
	public static function aborted_hard(): array {
		$out = array();
		foreach ( array( 'plugin', 'theme' ) as $kind ) {
			$out[ "$kind, before the first rename" ]         = array( $kind, 'P1', 'way (b)' );
			$out[ "$kind, between the renames" ]             = array( $kind, 'P2', 'way (b)' );
			$out[ "$kind, between the renames, by hand" ]    = array( $kind, 'P2', 'by hand' );
			$out[ "$kind, after the second rename" ]         = array( $kind, 'P3', 'way (b)' );
		}
		return $out;
	}

	/**
	 * A run ready for its swap (step 3), F56 for a plugin (ab-it-aktiv
	 * 1.0.0 to 2.0.0, active, released with accept) or as F9 for a theme
	 * (ab-it-kind 1.0.0 to 1.1.0, the child theme that runs): its number,
	 * the unit of the swap, its target, and the bytes of the old and of the
	 * new version.
	 */
	private static function ready_to_swap_of( string $kind ): array {
		if ( 'plugin' === $kind ) {
			list( $run_id, $unit, $target ) = self::ready_to_swap();
			return array( $run_id, $unit, $target, self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '1.0.0' ) ), self::bytes_of( AB_MCP_IT_Code::source( 'plugin', 'ab-it-aktiv', '2.0.0' ) ) );
		}
		self::paired();
		AB_MCP_IT_Code::put( 'b', 'theme', 'ab-it-kind', '1.1.0' );
		list( $report, $units ) = self::report();
		$n = (int) $units['theme:ab-it-kind']['n'];
		self::wporg();
		list( , $run ) = self::begun(
			array(
				'action'    => 'plan',
				'report_id' => $report['report_id'],
				'select'    => array( $n ),
				'accept'    => array( $n ),
			)
		);
		$run_id = (int) $run['run_id'];
		for ( $i = 0; $i < 25 && 'done' !== self::step( $run_id, 2 )['state']; $i++ ) {
			$answer = self::next( $run_id );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		}
		self::assertSame( array( 'done', 'planned', 'ab-it-kind' ), array( self::step( $run_id, 2 )['state'], self::step( $run_id, 3 )['state'], AB_MCP_IT_Code::theme_options( 'a' )['stylesheet'] ), 'Staged, ready for its swap; the theme runs.' );
		return array( $run_id, AB_MCP_Sync_Code_Ops::item( AB_MCP_Sync_Run::get( $run_id )['plan'], 3 )['unit'], AB_MCP_IT_Code::path( 'a', 'theme', 'ab-it-kind' ), self::bytes_of( AB_MCP_IT_Code::source( 'theme', 'ab-it-kind', '1.0.0' ) ), self::bytes_of( AB_MCP_IT_Code::source( 'theme', 'ab-it-kind', '1.1.0' ) ) );
	}

	/** What wp-admin does with a theme whose folder is missing (validate_current_theme()), in a request of its own on site A, as WordPress takes the paths of its theme as it loads: whether it switched away from it. */
	private static function theme_let_go(): bool {
		return false === AB_MCP_IT_Process::calls( 'a', array( 'calls' => array( array( 'call' => 'validate_current_theme', 'as' => 'valid' ) ) ) )['results']['valid'];
	}

	/**
	 * Baustein 1 of Zettel F: the swap of step 3, in a process of its own
	 * on site A as its admin (AB_MCP_Sync_Engine::next() with the run that
	 * process reads), aborted hard at a point (AB_MCP_Test_Abort,
	 * no_shutdown): P1 before the first rename, P2 after it, P3 after the
	 * second; its marker and the signal 9 prove the point. The lock for
	 * changes the child left is fresh, so the run is not interrupted (its
	 * swap may still run); its row is put TIMEOUT + 1 s back, as a call
	 * finds it once that time is over (the pattern of SiteAddressJournalTest
	 * :233), and status finds the run interrupted.
	 */
	private static function aborted_at( int $run_id, string $point ): void {
		list( $nth, $when ) = array(
			'P1' => array( 1, 'before' ),
			'P2' => array( 1, 'after' ),
			'P3' => array( 2, 'after' ),
		)[ $point ];
		$abort = AB_MCP_IT_Process::abort(
			'a',
			array(
				'require' => array( AB_MCP_DIR . 'includes/sync/class-sync-push.php' ),
				'seams'   => array(
					array(
						'owner'    => 'AB_MCP_Sync_Code_Swap',
						'property' => 'naht_sync_code_swap',
						'real'     => 'AB_MCP_Sync_Code_Swap_Calls',
						'faults'   => array(
							array(
								'method' => 'rename',
								'nth'    => $nth,
								'kind'   => 'abort',
								'when'   => $when,
								'point'  => $point,
								'mode'   => 'no_shutdown',
							),
						),
					),
				),
				'calls'   => array(
					array(
						'call'   => 'AB_MCP_Sync_Run::get',
						'args'   => array( $run_id ),
						'as'     => 'run',
						'expect' => 'result',
					),
					array(
						'call' => 'AB_MCP_Sync_Engine::next',
						'args' => array( '$run' ),
					),
				),
			)
		);
		self::assertSame( array( $point, 9 ), array( $abort['point'], $abort['run']['signal'] ), 'Ended at its point by the signal 9, before any shutdown function.' );
		self::assertSame( 3, AB_MCP_Sync_Run::settle( AB_MCP_Sync_Run::get( $run_id ) )['running'] ?? null, 'The lock the child left is fresh: the swap may still run, the run is not interrupted.' );
		update_option( AB_MCP_Change_Lock::OPTION, (string) ( time() - AB_MCP_Change_Lock::TIMEOUT - 1 ) );
		self::assertSame( 'interrupted', self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) )['state'] );
	}

	/**
	 * Prüft: F55 with a new plugin (F5): a plugin only on staging, planned
	 * the public way, is checked and staged; its swap refuses
	 * code:create_not_built with the way (abort, install by hand) before
	 * anything of this site changes, and the run is aborted, the site free.
	 * Sonst: the run would put a unit this version does not swap onto the
	 * site without its backup and its mark, or leave the customer without a
	 * way.
	 */
	public function testANewPluginOnStagingIsStagedAndItsSwapRefusesBeforeAnythingOfThisSiteChanges(): void {
		$slug   = 'ab-it-inaktiv';
		$target = AB_MCP_IT_Code::path( 'a', 'plugin', $slug );
		AB_MCP_IT_Code::remove( 'a', 'plugin', $slug );
		list( $args, $entry ) = self::changed( $slug, '1.1.0' );
		self::assertSame( 'create', $entry['action'], 'Only on staging: made new.' );
		self::wporg();
		list( , $run ) = self::begun( $args );
		$run_id        = (int) $run['run_id'];
		$answer        = null;
		for ( $i = 0; $i < 25 && ! is_wp_error( $answer ); $i++ ) {
			$answer = self::next( $run_id );
		}
		self::assertStringContainsString( 'Abort the run (wp_site_push action=abort) and install the unit by hand', self::refused( $answer, 'code:create_not_built' ) );
		clearstatcache();
		self::assertFileDoesNotExist( $target, 'Nothing of this site changed.' );
		self::closed( $run_id );
	}

	/**
	 * Prüft: F57, Z24 after option C (plan 15.23): an inactive plugin that
	 * is activated after prepare() and before the first write of its swap
	 * (the row active_plugins written by another request, as wp-admin
	 * writes it, past the cache of this one), without accept or with it, an
	 * active plugin deactivated there, with accept, or a row active_plugins
	 * that does not read there, ends the swap before its journal:
	 * code:runs_changed with the way of a new preparation, the step
	 * planned, nothing written at the target or in the records of the site
	 * (nothing_begun()), no file .maintenance. The next call prepares anew:
	 * the plugin now active without accept is refused there (not released);
	 * with accept, or the row read again, it is swapped as F56 swaps, and
	 * its answer is the one of a swap done with, once, the whole sentence on
	 * what its last check of the unit could not see, right before the call
	 * to go on and not in the result of the step.
	 * Sonst: code activated in the seconds of a swap would be replaced
	 * without its release and without OPcache cleared for it, prepared as
	 * code that does not run; code deactivated there would be swapped as
	 * code that runs, against the words of code:runs_changed; accept would
	 * stand in for a preparation that did not see the code run; a row not
	 * read would count as no code running; or the answer of a swap done
	 * would claim more than its last check saw.
	 */
	#[DataProvider( 'changes_after_the_preparation' )]
	public function testAPluginActivatedAfterThePreparationIsNotSwappedUntilItIsPreparedAnew( string $change, bool $accept ): void {
		global $wpdb;
		$off     = 'deactivated' === $change;
		$slug    = $off ? 'ab-it-aktiv' : 'ab-it-inaktiv';
		$version = $off ? '2.0.0' : '1.1.0';
		$main    = $slug . '/' . $slug . '.php';
		list( $run_id, $unit, $target ) = self::ready_to_swap(
			null,
			array(
				'slug'    => $slug,
				'version' => $version,
				'runs'    => $off,
				'accept'  => $accept,
			)
		);
		$before = self::before_swap( $target, $slug );
		$old    = self::bytes_of( $target );
		$row    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) );
		self::assertIsString( $row );
		// Another request writes the row, not the cache of this one.
		$write   = static function ( string $value ): void {
			global $wpdb;
			self::assertSame( 1, $wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => 'active_plugins' ) ) );
		};
		$changed = false;
		// After prepare() and plan_of(), at the first rights of the stage.
		$answer = self::swap_with(
			$run_id,
			array(
				array(
					'method' => 'chmod',
					'nth'    => 1,
					'kind'   => 'before',
					'before' => static function () use ( &$changed, $write, $change, $main ): void {
						$changed = true;
						$rows    = array(
							'activated'   => serialize( array_merge( self::active(), array( $main ) ) ),
							'deactivated' => serialize( array_values( array_diff( self::active(), array( $main ) ) ) ),
							'unknown'     => 'not in the form WordPress writes it',
						);
						$write( $rows[ $change ] );
					},
				),
			)
		);
		self::assertTrue( $changed, 'The change came after prepare(), at the rights of the stage.' );
		$says = self::nothing_begun( $run_id, $target, $before, $answer, 'code:runs_changed', 'accept does not replace preparing it anew. Call action=next again: it prepares the swap anew with what is active now.', array( 'group', 'rights' ) );
		self::assertStringContainsString( 'Whether the plugin ' . $slug . ' is active on this site (for a theme: the theme of this site or its parent) was read again right before its swap began', $says );
		self::assertFileDoesNotExist( ABSPATH . '.maintenance', 'No maintenance mode.' );
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Sync_Code_Swap::forget();
		if ( 'unknown' === $change ) {
			$write( $row );
		}
		if ( ! $accept ) {
			// Prepared anew: the plugin runs now, and the plan does not release it.
			self::refused( self::next( $run_id ), 'ab_mcp_sync_code_not_released' );
			self::assertSame( 'planned', self::step( $run_id, 3 )['state'], 'The step stays planned.' );
			self::assertSame( $old, self::bytes_of( $target ), 'The old version at the target.' );
			self::closed( $run_id );
			return;
		}
		$swap = self::swapped_whole(
			$run_id,
			$unit,
			$target,
			array(
				'slug'     => $slug,
				'old'      => $old,
				'new'      => self::bytes_of( AB_MCP_IT_Code::path( 'b', 'plugin', $slug ) ),
				'versions' => array( '1.0.0', $version ),
			)
		);
		$says = $swap['answer']['next'];
		$z24  = sprintf( self::Z24, 'plugin', $slug );
		self::assertSame( 1, substr_count( $says, $z24 ), 'The sentence of Z24 once in the answer of a swap done.' );
		self::assertStringNotContainsString( $z24, $swap['result']['message'], 'Not in the result of the step: it speaks of this answer.' );
		self::assertSame( $swap['result']['message'] . "\n" . $z24 . "\nCall action=next for the next step.", $says, 'The answer of a swap done, the sentence after the commands by hand, before the call to go on.' );
		self::assertSame( 'activated' === $change, in_array( $main, self::active(), true ), 'The plugin runs as it was activated or deactivated, or not.' );
		self::assertSame( $version, AB_MCP_IT_Code::fassung( 'a', 'plugin', $slug ) );
		self::assertFileDoesNotExist( ABSPATH . '.maintenance', 'No maintenance mode.' );
		self::closed( $run_id );
	}

	/** What changes after prepare(): the inactive plugin activated, without accept and with it; the active one deactivated, with accept; the row active_plugins not read, with accept. */
	public static function changes_after_the_preparation(): array {
		return array(
			'activated, without accept' => array( 'activated', false ),
			'activated, with accept'    => array( 'activated', true ),
			'deactivated, with accept'  => array( 'deactivated', true ),
			'not read, with accept'     => array( 'unknown', true ),
		);
	}

	/** The bytes of a folder (each file by its path relative to it) or of a file (by its name), by their SHA-256. */
	private static function bytes_of( string $path ): array {
		clearstatcache();
		return is_dir( $path ) ? self::digest( $path ) : array( basename( $path ) => hash_file( 'sha256', $path ) );
	}

	/** The run aborted after its swap, and the site free. */
	private static function closed( int $run_id ): void {
		$closed = self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) );
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free.' );
	}

	/**
	 * The way back of a run as a client takes it (plan Z22): wp_site_revert
	 * plan (with $more, accept), its token, next until the run of the way
	 * back is closed.
	 *
	 * @return array{preview:array,begun:array,back:array} The preview, the run of the way back as
	 *         it began, its last answer.
	 */
	private static function way_back_of( int $run_id, array $more = array() ): array {
		$preview = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id ) + $more );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
		self::assertTrue( $preview['confirm_required'] );
		$begun = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id, 'confirm_token' => $preview['confirm_token'] ) + $more );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_code() . ': ' . $begun->get_error_message() : '' );
		$back = null;
		for ( $i = 0; $i < 12; $i++ ) {
			$back = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => (int) $begun['run_id'] ) );
			self::assertIsArray( $back, is_wp_error( $back ) ? $back->get_error_code() . ': ' . $back->get_error_message() : '' );
			if ( in_array( $back['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return array(
					'preview' => $preview,
					'begun'   => $begun,
					'back'    => $back,
				);
			}
		}
		self::fail( 'The way back did not close within 12 calls: ' . json_encode( $back ) );
	}

	/** The way back of a run planned with its token, its step not run yet: the number of the run of the way back. */
	private static function planned_back( int $run_id ): int {
		$preview = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id ) );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
		$begun = self::gated( 'wp_site_revert', array( 'action' => 'plan', 'run_id' => $run_id, 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_code() . ': ' . $begun->get_error_message() : '' );
		return (int) $begun['run_id'];
	}

	/** The step of the way back of a run that goes back over step 3, the swap, as stored. */
	private static function back_step( int $back ): array {
		foreach ( AB_MCP_Sync_Run::steps( $back ) as $one ) {
			if ( 'revert' === $one['op'] && 3 === (int) $one['parts']['forward_seq'] ) {
				return $one;
			}
		}
		self::fail( 'The way back has no step for the swap.' );
	}

	/**
	 * A swap put back by the way back of its run (Z22, Z20b), the frame of
	 * F1, F2, F6, F47 and F55: the preview names the swap step with its
	 * target and backup; then the old version is at the target byte for
	 * byte, the folder or file of the backup itself (moved, not copied),
	 * the new one byte for byte at its place in a group of its own of the
	 * backups of the way back (question 1 B), the mark of the swap closed,
	 * the pair of the unit unclear (question 3 A), the step of the way back
	 * done with the answer that names the old version, what stays in the
	 * database and wp_site_pairs, and the run of the way back closed.
	 *
	 * @param array $want old and new (bytes_of() of the two versions).
	 * @return array The step of the way back as stored.
	 */
	private static function put_back( int $run_id, array $unit, string $target, array $want ): array {
		$backup = self::step( $run_id, 3 )['result']['backup'];
		clearstatcache();
		$old = lstat( $backup );
		self::assertIsArray( $old, 'The old version lies in the backup.' );
		$way   = self::way_back_of( $run_id );
		$shown = array_values( array_filter( $way['preview']['preview']['steps'], static fn( $s ) => 3 === (int) $s['seq'] ) );
		self::assertCount( 1, $shown, 'The preview names the swap step: ' . json_encode( $way['preview']['preview'] ) );
		self::assertSame( array( $unit['kind'], $unit['ref'], 'back', $target, $backup ), array( $shown[0]['kind'], $shown[0]['live_ref'], $shown[0]['will'], $shown[0]['target'], $shown[0]['backup'] ) );
		$back = (int) $way['begun']['run_id'];
		$step = self::back_step( $back );
		self::assertSame( 'done', $step['state'], json_encode( $step['result'] ) );
		$says = $step['result']['message'];
		self::assertStringContainsString( sprintf( 'The %1$s %2$s is in its old version at %3$s, proved there', $unit['kind'], $unit['ref'], $target ), $says );
		self::assertStringContainsString( 'stays as it is; confirm the pair of the unit anew (wp_site_pairs)', $says );
		self::assertStringNotContainsString( 'ran when its swap began', $says, 'No plugin to activate again: it runs on, or did not run (section 2 of part E).' );
		self::assertSame( array(), $shown[0]['activate'], 'The preview names no plugin to activate again.' );
		clearstatcache();
		$now = lstat( $target );
		self::assertSame( array( $old['dev'], $old['ino'] ), array( $now['dev'], $now['ino'] ), 'The old version is the folder or file of the backup itself, moved back.' );
		self::assertSame( $want['old'], self::bytes_of( $target ), 'The old version is at the target byte for byte.' );
		self::assertFalse( file_exists( $backup ), 'The backup is moved, not copied.' );
		$place = $step['result']['place'];
		self::assertIsString( $place );
		self::assertSame( $want['new'], self::bytes_of( $place ), 'The new version lies byte for byte at its place.' );
		$groups = AB_MCP_Protected_Store::groups( 'backups' );
		self::assertIsArray( $groups );
		$own = array_values( array_filter( $groups, static fn( $g ) => (int) $g['run_id'] === $back && 'back-' . $back . '-' . (int) $step['seq'] === $g['label'] ) );
		self::assertCount( 1, $own, 'The place of the new version is a group of its own of the backups of the way back (question 1 B).' );
		$tree = AB_MCP_Protected_Store::tree( 'backups', $own[0]['gid'], basename( $target ), self::step( $run_id, 3 )['parts']['new']['hash'] );
		self::assertSame( $place, is_array( $tree ) ? $tree['path'] : $tree->get_error_message(), 'The new version is the tree of that group, read back against its hash.' );
		self::assertSame( 'closed', self::mark_of_swap( $run_id )['state'], 'The mark of the swap is closed.' );
		self::assertSame( 'unclear', AB_MCP_Sync_Pairs::get( $unit['pair_id'] )['state'], 'The pair is to be confirmed anew (question 3 A).' );
		self::assertSame( 'closed_reverted', $way['back']['state'], json_encode( $way['back'] ) );
		return $step;
	}

	/**
	 * Step 3 swapped and proved as F56 proves it, the shared frame of the
	 * cases of D3a (F56, F1, F3, F4, F6): the requests over HTTP, the state
	 * at the first rename, the paths OPcache is asked for and the rights
	 * given, watched. prepare() as built: the test requests before the swap
	 * go to the own server of this site, also for code that does not run
	 * (reading 5 (a)), and none goes after the first rename. Before the
	 * first rename the note of the replacement (its key the kind and the
	 * name of the unit), the journal of the step and the mark with its
	 * instruction stand, the target still the old version. Then the step is
	 * done with two renames and no swap back; the stage itself is the
	 * target, with the bytes of staging and the rights of the site, each
	 * entry given them once before the note; the old version lies whole in
	 * the backup, the folder or file itself; OPcache is cleared for each PHP
	 * file replaced after the second rename; the mark is closed, the pair
	 * takes the version of staging as its base, the lock for changes is
	 * given back; the answer names the versions, target, backup, the way
	 * back and the one command by hand.
	 *
	 * @param array $want slug; old (bytes_of() the target before) and new (of the unit of
	 *                    staging); versions (here before, now).
	 * @return array{answer:array,result:array,backup:string,stage:string,chmods:array,opcache:string[]}
	 */
	private static function swapped_whole( int $run_id, array $unit, string $target, array $want ): array {
		$slug = $want['slug'];
		clearstatcache();
		$old  = lstat( $target );
		$file = ! is_dir( $target );
		self::assertSame( $want['old'], self::bytes_of( $target ), 'The old version at the target before the swap.' );
		$http   = array();
		$record = static function ( $response, $context, $class, $parsed, $url ) use ( &$http ): void {
			$http[] = (string) $url;
		};
		$seen    = null;
		$renames = 0;
		$opcache = array();
		$chmods  = array();
		$noted   = false;
		$first   = static function () use ( &$seen, &$http, $run_id, $target ): void {
			global $wpdb;
			clearstatcache();
			$row   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", AB_MCP_Update_Log::REPLACED ) );
			$marks = AB_MCP_Protected_Store::marks();
			$mine  = is_array( $marks ) ? array_values( array_filter( $marks['marks'], static fn( $m ) => (int) $m['run_id'] === $run_id && 3 === (int) $m['seq'] ) ) : array();
			$guide = 1 === count( $mine ) ? AB_MCP_Protected_Store::root() . '/' . AB_MCP_Protected_Store::MARKS . '/' . $mine[0]['mid'] . '/' . AB_MCP_Protected_Store::GUIDE_FILE : '';
			$seen  = array(
				'http'   => count( $http ),
				'note'   => is_string( $row ) ? unserialize( $row, array( 'allowed_classes' => false ) ) : null,
				'step'   => AB_MCP_Sync_Run::step( $run_id, 3 ),
				'marks'  => $mine,
				'guide'  => '' !== $guide && is_file( $guide ) ? (string) file_get_contents( $guide ) : null,
				'bytes'  => self::bytes_of( $target ),
				'staged' => 1 === count( $mine ) ? array_map( static fn( $tree ) => file_exists( AB_MCP_Protected_Store::root() . '/' . $tree['path'] ), array_column( $mine[0]['trees'], null, 'role' ) ) : array(),
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
		$asks   = static function ( string $url, string $at ): bool {
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
		self::assertArrayHasKey( 'plugin:' . $slug, $seen['note'], 'The note of the replacement before the first rename, under the kind and the name of the unit.' );
		self::assertIsArray( $seen['step'] );
		self::assertSame( 'begun', $seen['step']['state'], 'The step begun before the first rename.' );
		self::assertSame( array( 'kind' => 'plugin', 'op' => 'swap', 'ref' => $slug ), $seen['step']['parts']['unit'], 'The journal names the unit (stored in its canonical form, its keys sorted).' );
		self::assertSame( $seen['note'][ 'plugin:' . $slug ], $seen['step']['parts']['since'], 'The note and the journal hold the same beginning.' );
		self::assertSame( $target, $seen['step']['parts']['target'] );
		self::assertCount( 1, $seen['marks'], 'One mark of this step before the first rename.' );
		$mark = $seen['marks'][0];
		self::assertSame( array( 'open', 'swap', $target ), array( $mark['state'], $mark['op'], $mark['target'] ) );
		self::assertSame( array( 'kind' => 'plugin', 'ref' => $slug ), $mark['unit'] );
		$trees = array_column( $mark['trees'], null, 'role' );
		self::assertSame( array( 'backup', 'stage' ), array_keys( $trees ) );
		self::assertSame( array( 'backup' => false, 'stage' => true ), $seen['staged'], 'At the first rename the stage is there and the place of the backup free.' );
		$stage = AB_MCP_Protected_Store::root() . '/' . $trees['stage']['path'];
		self::assertSame( array( $old['dev'], $old['ino'] ), array( $trees['backup']['dev'], $trees['backup']['ino'] ), 'The mark names the old version by its identity.' );
		self::assertIsString( $seen['guide'], 'The instruction lies beside the mark.' );
		self::assertStringContainsString( '[nach-den-umbenennungen]', $seen['guide'] );
		// Its first lines, after the title (option B, Thomas 16.11.2026): the
		// command that only reads, and the cases of what it shows that are
		// sent to a section, else to none.
		$head = self::head_of( $target, AB_MCP_Protected_Store::root() . '/' . $trees['backup']['path'], $stage, $run_id );
		self::assertSame( $head, array_slice( explode( "\n", $seen['guide'] ), 2, count( $head ) ), 'The first lines of the instruction tell which of its commands fits, or that none does.' );
		self::assertContains( self::RUN_ONLY, explode( "\n", $seen['guide'] ), 'Only the command of the section the first lines name, and of it only that it contains checks.' );
		self::assertStringContainsString( $target, $seen['guide'] );
		self::assertSame( $want['old'], $seen['bytes'], 'The target is the old version until the first rename.' );

		// The step done, two renames, no swap back.
		self::assertSame( array( 3, 'done' ), array( $swapped['step']['seq'], $swapped['step']['state'] ), $swapped['next'] ?? '' );
		self::assertSame( 2, $renames, 'Two renames, no swap back.' );
		$result = self::step( $run_id, 3 )['result'];
		self::assertSame( array( $target, $mark['mid'] ), array( $result['target'], $result['mark'] ) );
		$backup = $result['backup'];
		self::assertSame( AB_MCP_Protected_Store::root() . '/' . $trees['backup']['path'], $backup, 'The backup is the one the mark names (relative to the store).' );
		// The new version at the target, the stage itself (its identity); the
		// stage is gone from the store.
		self::assertSame( $want['new'], self::bytes_of( $target ), 'The bytes of staging at the target.' );
		$now = lstat( $target );
		self::assertSame( array( $trees['stage']['dev'], $trees['stage']['ino'] ), array( $now['dev'], $now['ino'] ), 'The stage is the target now.' );
		self::assertFalse( file_exists( $stage ), 'The stage is gone from the store.' );
		// The rights of the site, given before the first write (Codex D1,
		// finding 2): each folder and file of the stage set once, before the
		// note of the replacement; at the target each folder and file has the
		// rights WordPress gives them, and the hash is the one of staging.
		$rights = self::site_rights();
		$at     = array();
		foreach ( $chmods as $chmod ) {
			self::assertFalse( $chmod[2], 'The rights before the note of the replacement: ' . $chmod[0] );
			self::assertSame( 0, strpos( $chmod[0], $stage ), 'Only the stage takes the rights: ' . $chmod[0] );
			$at[ $target . substr( $chmod[0], strlen( $stage ) ) ] = $chmod[1];
		}
		$tree = array( $target );
		foreach ( $file ? array() : AB_MCP_IT_Code::files( $target ) as $rel ) {
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
		clearstatcache();
		foreach ( $tree as $path ) {
			self::assertSame( sprintf( '%04o', $rights[ is_dir( $path ) ? 'folder' : 'file' ] ), sprintf( '%04o', fileperms( $path ) & 07777 ), 'The rights of the site at ' . $path );
		}
		$read = \AB_MCP_Sync_File_Manifest::unit( dirname( $target ), $unit['ref'], 'live', microtime( true ) + 30, true, 'plugin' );
		self::assertSame( array( true, $unit['staging_hash'] ), array( $read['complete'], $read['hash'] ), 'The rights are no part of the hash: the new version read at the target is the one of staging.' );
		// The old version whole in the backup: its bytes and its identity.
		self::assertSame( $want['old'], self::bytes_of( $backup ), 'The old version whole in the backup.' );
		$kept = lstat( $backup );
		self::assertSame( array( $old['dev'], $old['ino'] ), array( $kept['dev'], $kept['ino'] ), 'The backup is the old version itself.' );
		// OPcache: after the second rename, each PHP file of the old and of the
		// new version by its path at the target; none between the renames.
		$php = array();
		foreach ( array( $want['old'], $want['new'] ) as $bytes ) {
			foreach ( array_keys( $bytes ) as $rel ) {
				if ( AB_MCP_Sync_Code_Stage::is_php( (string) $rel ) ) {
					$php[ $file ? $target : $target . '/' . $rel ] = true;
				}
			}
		}
		$paths = array_keys( $php );
		sort( $paths, SORT_STRING );
		self::assertNotSame( array(), $paths, 'The unit has PHP files.' );
		self::assertSame( array(), array_filter( $opcache, static fn( $call ) => 1 === $call[0] ), 'No OPcache between the renames.' );
		self::assertSame( $paths, array_column( array_filter( $opcache, static fn( $call ) => 2 === $call[0] ), 1 ), 'OPcache for each PHP file replaced.' );
		self::assertSame( count( $paths ), $result['opcache'] );
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
		self::assertFalse( AB_MCP_Sync_Code_Swap::lock_held(), 'The lock for changes is given back.' );
		// The answer: versions, target, backup, the way back, the commands by hand.
		$says = $swapped['next'];
		self::assertStringContainsString( 'version ' . $want['versions'][0] . ' here before, ' . $want['versions'][1] . ' now', $says );
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
		self::assertStringEndsWith( "\nCall action=next for the next step.", $says, 'The call to go on, its end recorded.' );
		return array(
			'answer'  => $swapped,
			'result'  => $result,
			'backup'  => $backup,
			'stage'   => $stage,
			'chmods'  => $at,
			'opcache' => $paths,
		);
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

	/**
	 * Prüft: rights and the time of the call (D3a 1, open from D2b; T3 1):
	 * the time of the call runs out while the new version takes the rights
	 * of the site (given_rights() asks it before each entry), or the time
	 * kept for the swap is not left right after the rights (time_left() in
	 * swapped()). The swap is refused before any mutation at the target,
	 * with code:rights or code:means and the way; the step stays planned, no
	 * journal, mark or note; in the protected store only the group of the
	 * backups and the rights of the stage; the next call swaps it.
	 * Sonst: the swap could begin its renames without the time to end them
	 * in this call, or with a new version only part of whose entries took
	 * the rights of the site; cut off by the time limit of PHP, the site
	 * would stay between the two versions.
	 */
	#[DataProvider( 'rights_late' )]
	public function testTheTimeOfTheCallRunningOutDuringOrRightAfterTheRightsRefusesTheSwapBeforeTheTarget( string $case, string $code, string $way ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$before = self::before_swap( $target );
		$given  = 0;
		// The clock of the swap jumps an hour on: at the second entry of the
		// rights, or right after the last one (time_left() in swapped()).
		AB_MCP_Sync_Code_Swap::$clock = static function () use ( &$given, $case ): float {
			$late = 'during the rights' === $case ? $given > 0 && self::within( 'given_rights' ) : $given > 0 && ! self::within( 'given_rights' );
			return microtime( true ) + ( $late ? 3600.0 : 0.0 );
		};
		$answer = self::swap_with(
			$run_id,
			array(
				array(
					'method' => 'chmod',
					'kind'   => 'before',
					'before' => static function () use ( &$given ): void {
						++$given;
					},
				),
			)
		);
		AB_MCP_Sync_Code_Swap::$clock = null;
		self::assertSame( 'during the rights' === $case ? 1 : 5, $given, 'The rights given to one entry, or to each of the 5 (the folder, its two files, the folder includes and its file).' );
		self::nothing_begun( $run_id, $target, $before, $answer, $code, $way, array( 'group', 'rights' ) );
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Sync_Code_Swap::forget();
		$swapped = self::next( $run_id );
		self::assertIsArray( $swapped, is_wp_error( $swapped ) ? $swapped->get_error_code() . ': ' . $swapped->get_error_message() : '' );
		self::assertSame( 'done', $swapped['step']['state'], 'The next call swaps it.' );
	}

	/** Where the time runs out: during the rights (code:rights), right after them (code:means). */
	public static function rights_late(): array {
		return array(
			'during the rights'    => array( 'during the rights', 'code:rights', 'each set and read back within the time of this call, so it is not swapped and nothing of this site changed. Call action=next again;' ),
			'right after the rights' => array( 'right after the rights', 'code:means', 'so it is not swapped in this call. Call action=next again; where it keeps failing, ask the host for a longer time limit of PHP (max_execution_time), or update the unit by hand in wp-admin.' ),
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
	 * before the way back, nothing is moved onto it, and the step is unclear
	 * with the short answer (option B, Thomas 16.11.2026): what the call
	 * did, the way back of the run and the instruction on the server, and
	 * nothing of what is at the target now, which the answer does not read
	 * (F35 has its own test). The first lines of the instruction show the
	 * folder of the other process at the target and the old version in the
	 * backup; the command of the section for a target taken stops at its
	 * check and moves nothing onto that folder, and once it is moved away
	 * the same command puts the old version back.
	 * Sonst: the swap could replace a folder another process put at a place
	 * and with it what it holds, or put its backup into the site where
	 * another process put a folder, also with a command of its instruction;
	 * or a person would read in the answer that the target is missing where
	 * a folder is there.
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
			self::assertSame( 'open', self::mark_of_swap( $run_id )['state'], 'The mark of the swap is open.' );
			// The short answer (option B): what the call did, nothing of
			// what is at the target now (the answer does not read it), and
			// no command.
			list( $why, $guide, $places ) = self::short_answer( $run_id, $step, $says );
			self::assertSame( 'The swap of the plugin ab-it-aktiv did not finish: its old version was moved into the backup, the new one could not be put at the target, and the old one could not be put back.', $why );
			// The instruction on the server: its first lines show the folder
			// of the other process at the target and the old version in the
			// backup (the test moved the stage aside) and send that state to
			// the section for a target taken; its command stops at its
			// check, so it moves nothing onto that folder.
			$shown = self::shown_by( $guide, $places );
			self::assertSame( array( $foreign[1], $old['ino'], null ), array_values( $shown ), 'What the first lines of the instruction show.' );
			$point = self::fits( $guide, $places, $shown );
			self::assertSame( 'zwischen-den-umbenennungen-ziel-belegt', $point, 'The first lines send this state to the section for a target taken.' );
			$taken = 'The target is taken by another entry; the old version lies whole in the backup. Only after that entry is moved away, put the old version back and check it:';
			self::assertStringNotContainsString( 'OK', self::run_section( $guide, $point, $taken ), 'The command stops at its check.' );
			clearstatcache();
			self::assertSame( array( $foreign[1], array( '.', '..', 'fremd.txt' ) ), array( fileinode( $target ), scandir( $target ) ), 'The folder of the other process stays as it was.' );
			$kept = lstat( $step['result']['backup'] );
			self::assertSame( array( $old['dev'], $old['ino'] ), array( $kept['dev'], $kept['ino'] ), 'The old version stays in the backup.' );
			// Moved away (aside, nothing deleted), the same command puts the
			// old version back, as the answer says.
			self::assertTrue( rename( $target, $target . '-fremd' ) );
			self::assertStringEndsWith( 'OK', self::run_section( $guide, $point, $taken ), 'After the entry is moved away, the command runs to its end.' );
			clearstatcache();
			$now = lstat( $target );
			self::assertSame( array( $old['dev'], $old['ino'] ), array( $now['dev'], $now['ino'] ), 'The old version is back at the target.' );
			self::assertSame( array( '.', '..', 'fremd.txt' ), scandir( $target . '-fremd' ), 'What the other process put there stays.' );
			return;
		}
		self::assertSame( 'failed', $step['state'], $says );
		self::assertSame( array( $old['dev'], $old['ino'] ), array( $now['dev'], $now['ino'] ), 'The old version at its place.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
		self::assertSame( 'closed', self::mark_of_swap( $run_id )['state'], 'The mark closed on the old version (kept).' );
		self::assertStringContainsString( 'The old version could not be moved into the backup of the run', $says );
		// The note stands without a replacement, and the answer says so
		// (the lead on D2a, 16.11. 03:57).
		self::assertSame( $step['parts']['since'], AB_MCP_Update_Log::replaced_at( 'plugin', 'ab-it-aktiv' ) );
		self::assertStringContainsString( 'The note that its files are replaced stays written all the same (it is not taken back): the file undo points of these files from before this call are refused, as after a swap.', $says );
	}

	/** The places of a rename another process or a failure takes. */
	public static function places(): array {
		return array(
			'an empty folder at the place of the backup before the swap looks' => array( 'an empty folder at the place of the backup before the swap looks' ),
			'a folder with a file at the place of the backup between the look and the rename' => array( 'a folder with a file at the place of the backup between the look and the rename' ),
			'a folder with a file at the target before the way back' => array( 'a folder with a file at the target before the way back' ),
		);
	}

	/** The command line of a way by hand (the one of these lines that begins with "$ ", as it stands there), run in bash without -e: its output. */
	private static function run_by_hand( array $by_hand ): string {
		$lines = array_values( array_filter( $by_hand, static fn( $line ) => 0 === strpos( $line, '$ ' ) ) );
		self::assertCount( 1, $lines, 'One command line.' );
		$out = array();
		exec( 'bash -c ' . escapeshellarg( substr( $lines[0], 2 ) ) . ' 2>&1', $out );
		return implode( "\n", $out );
	}

	/**
	 * The short answer of an unclear step (option B, Thomas 16.11.2026),
	 * checked whole: after what happened in the call it holds «The step is
	 * unclear.», the way back of the run with its call, the instruction of
	 * the mark on the server, the paths of target and backup, and nothing
	 * else, so no sentence on what lies where or on the mark and no command;
	 * the journal holds the same answer, and its result the paths, the mark,
	 * the file of the instruction and no command.
	 *
	 * @return array{0:string,1:string,2:array} What happened (the first part of the answer); the
	 *         file of the instruction; the places of the swap as its mark names them (target,
	 *         backup, stage: absolute paths; old, new: the inodes of the two versions; run: its
	 *         number).
	 */
	private static function short_answer( int $run_id, array $step, string $says ): array {
		$result = $step['result'];
		list( $guide, $places, $mark ) = self::guide_of( $run_id );
		$tail = " The step is unclear.\n"
			. '1. To go back, call wp_site_revert action=plan with run_id ' . $run_id . ".\n"
			. '2. Where this site answers no request any more, or where 1. is refused on this site (wp_site_revert says why): in a shell on the server, open the file ' . $guide . " and run the command its first lines point to. The command contains checks; where it does not print OK, stop.\n"
			. 'Target: ' . $places['target'] . "\n"
			. 'Backup: ' . $places['backup'];
		self::assertSame( $result['message'], $says, 'The journal holds the answer.' );
		self::assertStringEndsWith( $tail, $says, 'After what happened: the step unclear, the way back, the instruction, target and backup, and nothing after them.' );
		$why = substr( $says, 0, -strlen( $tail ) );
		self::assertStringNotContainsString( "\n", $why, 'What happened is one paragraph: no line of a command.' );
		foreach ( array( 'stays open', 'is closed', 'not confirmed', 'is missing', 'lies whole', 'is not at its place', 'is at its place', "mv '", '$ ', 'ls -' ) as $state ) {
			self::assertStringNotContainsString( $state, $says, 'No sentence on what lies where or on the mark, and no command: ' . $state );
		}
		// Of the command of the instruction only that it contains checks: a
		// check after a move can fail after the move (Codex on 5199de3,
		// finding 1).
		foreach ( array( 'moves nothing', 'nothing is moved', 'checks first' ) as $promise ) {
			self::assertStringNotContainsString( $promise, $says, 'No promise that the command moves nothing where a check fails: ' . $promise );
		}
		self::assertSame( array( $places['target'], $places['backup'], $mark['mid'], $guide, array() ), array( $result['target'], $result['backup'], $result['mark'], $result['guide'], $result['by_hand'] ), 'The result: the paths, the mark, the instruction, no command.' );
		return array( $why, $guide, $places );
	}

	/**
	 * The instruction of the mark of the swap of step 3 on the server, the
	 * places of the swap as its mark names them (target, backup, stage:
	 * absolute paths; old, new: the inodes of the two versions; run: its
	 * number), and the mark.
	 *
	 * @return array{0:string,1:array,2:array}
	 */
	private static function guide_of( int $run_id ): array {
		$mark = self::mark_of_swap( $run_id );
		self::assertIsArray( $mark, 'The mark of the swap.' );
		$root  = AB_MCP_Protected_Store::root();
		$trees = array_column( $mark['trees'], null, 'role' );
		$guide = $root . '/' . AB_MCP_Protected_Store::MARKS . '/' . $mark['mid'] . '/' . AB_MCP_Protected_Store::GUIDE_FILE;
		self::assertFileExists( $guide, 'The instruction of the mark on the server.' );
		return array(
			$guide,
			array(
				'target' => $mark['target'],
				'backup' => $root . '/' . $trees['backup']['path'],
				'stage'  => $root . '/' . $trees['stage']['path'],
				'old'    => $trees['backup']['ino'],
				'new'    => $trees['stage']['ino'],
				'run'    => $run_id,
			),
			$mark,
		);
	}

	/**
	 * The first lines of the instruction of the mark of a swap (an old and
	 * a new version), after its title: the command that only reads, the
	 * cases of what it shows that are surely the state of one section, each
	 * with that section, and the line for every other state: run nothing,
	 * stop, take the way back of the run (Codex on 3014d8b, finding 2).
	 *
	 * @return string[]
	 */
	private static function head_of( string $target, string $backup, string $stage, int $run_id ): array {
		return array(
			'First see which command fits. The next line only reads: for the target and for each place of the store named below it shows the inode of what is there (the first number of its line), or says that nothing is there:',
			"$ ls -di '" . $target . "' '" . $backup . "' '" . $stage . "'",
			'Then take the case below that is true of what it showed, and run the command of the section it names. An entry is the old version where it has the inode named below for the backup, the new version where it has the inode named below for the stage, and another entry where it has neither.',
			'- The old version is at the target: section [vor-den-umbenennungen]. Its command only checks.',
			'- Nothing is at the target, and the old version is at the place of the backup: section [zwischen-den-umbenennungen].',
			'- Another entry is at the target, and the old version is at the place of the backup: section [zwischen-den-umbenennungen-ziel-belegt].',
			'- The new version is at the target, the old version is at the place of the backup, and nothing is at the place of the stage: section [nach-den-umbenennungen].',
			'Where none of them is true (another inode, or none, where a case names a version; or an entry where a case says that nothing is there), run no command of this file. Stop, and take the way back of the run as soon as the site answers again: wp_site_revert action=plan with run_id ' . $run_id . '; where it refuses it (it says why), put the version of before back in wp-admin by hand.',
			'',
		);
	}

	/**
	 * What the first lines of the instruction of a mark show (option B):
	 * the lines that tell which command fits (head_of()), and among them
	 * the one command that only reads (ls -di of the target, the backup and
	 * the stage), run in bash: for each of the three places the inode of
	 * what is there, null where nothing is there.
	 *
	 * @return array{target:int|null,backup:int|null,stage:int|null}
	 */
	private static function shown_by( string $guide, array $places ): array {
		$lines = explode( "\n", (string) file_get_contents( $guide ) );
		$head  = self::head_of( $places['target'], $places['backup'], $places['stage'], $places['run'] );
		self::assertSame( $head, array_slice( $lines, 2, count( $head ) ), 'The first lines of the instruction, after its title.' );
		$out = array();
		exec( 'bash -c ' . escapeshellarg( substr( $lines[3], 2 ) ) . ' 2>/dev/null', $out );
		$shown = array(
			'target' => null,
			'backup' => null,
			'stage'  => null,
		);
		foreach ( $out as $line ) {
			$parts = explode( ' ', trim( $line ), 2 );
			$place = array_search( $parts[1] ?? '', array_slice( $places, 0, 3, true ), true );
			self::assertIsString( $place, 'A line of the command names one of the three places: ' . $line );
			$shown[ $place ] = (int) $parts[0];
		}
		return $shown;
	}

	/**
	 * The section the first lines of the instruction of a mark send a state
	 * to, by their own rule and not by what the test expects: each case of
	 * them («- … is at …: section [name].») is read as it stands, a place
	 * holds the old or the new version by its inode, another entry or
	 * nothing, and the case that is true of what the command showed names
	 * the section. Null where no case is true: the lines say to run no
	 * command then.
	 *
	 * @param array $shown shown_by().
	 */
	private static function fits( string $guide, array $places, array $shown ): ?string {
		$lines = explode( "\n", (string) file_get_contents( $guide ) );
		$holds = array();
		foreach ( array( 'target' => 'the target', 'backup' => 'the place of the backup', 'stage' => 'the place of the stage' ) as $place => $name ) {
			$holds[ $name ] = 'another entry';
			if ( null === $shown[ $place ] ) {
				$holds[ $name ] = 'nothing';
			} elseif ( $places['old'] === $shown[ $place ] ) {
				$holds[ $name ] = 'the old version';
			} elseif ( $places['new'] === $shown[ $place ] ) {
				$holds[ $name ] = 'the new version';
			}
		}
		$true = array();
		// The cases come after the title, its empty line and three lines.
		for ( $i = 5; isset( $lines[ $i ] ) && 0 === strpos( $lines[ $i ], '- ' ); $i++ ) {
			self::assertSame( 1, preg_match( '/^- (.+): section \[([a-z-]+)\]\.(?: Its command only checks\.)?$/', $lines[ $i ], $case ), 'A case says what is where and names its section: ' . $lines[ $i ] );
			$all = true;
			foreach ( preg_split( '/, (?:and )?/', $case[1] ) as $part ) {
				self::assertSame( 1, preg_match( '/^(the old version|the new version|another entry|nothing) is at (the target|the place of the backup|the place of the stage)$/', lcfirst( $part ), $said ), 'A case says what is at a place: ' . $part );
				$all = $all && $holds[ $said[2] ] === $said[1];
			}
			if ( $all ) {
				$true[] = $case[2];
			}
		}
		self::assertGreaterThan( 5, $i, 'The first lines hold cases.' );
		self::assertStringStartsWith( 'Where none of them is true (', $lines[ $i ], 'After the cases: what to do where none is true.' );
		self::assertLessThanOrEqual( 1, count( $true ), 'At most one case is true of a state.' );
		return $true[0] ?? null;
	}

	/**
	 * The command of a section of the instruction of a mark as its file on
	 * the server holds it, run in bash without -e: its output. The section
	 * begins with the line $first, which says what it is for.
	 */
	private static function run_section( string $guide, string $point, string $first ): string {
		$text = (string) file_get_contents( $guide );
		self::assertStringContainsString( "\n[" . $point . "]\n" . $first . "\n$ ", $text, 'The section, the line that says what it is for, then its command.' );
		$commands = \AB_MCP_IT_Handweg::commands( $text, $point );
		self::assertCount( 1, $commands, 'One command line.' );
		$out = array();
		exec( 'bash -c ' . escapeshellarg( $commands[0] ) . ' 2>&1', $out );
		return implode( "\n", $out );
	}

	/**
	 * Prüft: F35 and the way back of the swap (T3 2 and 6): where the second
	 * rename fails, the old version goes back to the target, nothing is
	 * changed, the step ends failed with the mark closed (kept), and its
	 * journal holds the swap and the answer. Where the way back fails too,
	 * the step ends unclear with the mark open and the short answer (option
	 * B, Thomas 16.11.2026): what the call did, the way back of the run, the
	 * instruction on the server, target and backup; no sentence on what lies
	 * where and no command, the same with the backup as it was, changed, or
	 * with no time left in the call (the answer reads nothing for it). The
	 * first lines of the instruction show that nothing is at the target and
	 * the old version in the backup, and the command of that section puts
	 * it back. In each case the lock for changes is free and nothing is
	 * lost.
	 * Sonst: a failed swap could leave the site without its plugin, a person
	 * would have no way by hand that works, or would read in the answer a
	 * state of the target, the backup or the mark that nothing proved.
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
			'the way back fails, the backup as it was' => array(
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
			self::assertStringContainsString( 'The note that its files are replaced stays written all the same (it is not taken back): the file undo points of these files from before this call are refused, as after a swap.', $says, 'The note stands without a replacement.' );
			self::assertSame( 'closed', $mark['state'], 'The mark closed on the old version (kept).' );
			self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ) );
			self::assertSame( $seen['old'], self::digest( $target ), 'Nothing of the site changed.' );
			self::nothing_lost( $seen );
			return;
		}
		self::assertSame( 'unclear', $step['state'], $says );
		self::assertStringContainsString( 'and the old one could not be put back', $says );
		self::assertSame( 'open', $mark['state'], 'The mark of the swap is open.' );
		// The short answer (option B), the same in the three cases: it
		// reads nothing of the backup and says nothing of it.
		list( $why, $guide, $places ) = self::short_answer( $run_id, $step, $says );
		self::assertSame( 'The swap of the plugin ab-it-aktiv did not finish: its old version was moved into the backup, the new one could not be put at the target, and the old one could not be put back.', $why );
		self::assertSame( array( $target, $seen['backup'], $seen['stage'] ), array( $places['target'], $places['backup'], $places['stage'] ) );
		if ( 'the way back fails, the backup as it was' === $case ) {
			self::nothing_lost( $seen );
			// The instruction on the server: its first lines show that
			// nothing is at the target and the old version in the backup;
			// the command of that section puts it back.
			$shown = self::shown_by( $guide, $places );
			self::assertSame( array( null, $places['old'], $places['new'] ), array_values( $shown ), 'What the first lines of the instruction show.' );
			$point = self::fits( $guide, $places, $shown );
			self::assertSame( 'zwischen-den-umbenennungen', $point, 'The first lines send this state to the section between the renames.' );
			self::assertStringEndsWith( 'OK', self::run_section( $guide, $point, 'The target is missing; the old version lies whole in the backup. Put it back and check it:' ), 'The command does all of it.' );
			clearstatcache();
			self::assertSame( array( '1.0.0', $seen['old'] ), array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ) ), 'The old version is back, whole.' );
			return;
		}
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
			'the way back fails, the backup as it was' => array( 'the way back fails, the backup as it was' ),
			'the way back fails, the backup changed' => array( 'the way back fails, the backup changed' ),
			'the way back fails, no time left'       => array( 'the way back fails, no time left' ),
		);
	}

	/**
	 * Prüft: option B on the way of a swap not made (Thomas 16.11.2026; T3 2
	 * and 6): the second rename fails and the old version goes back to the
	 * target (F35), then close() does not end the mark: the old version is
	 * not proved at the target (another process put a file into it), or it
	 * is proved and the mark does not close. Each ends unclear with the
	 * short answer: what happened, the way back of the run, the instruction
	 * on the server, target and backup; no sentence on what lies where or
	 * on the mark, no command, and never the answer of a swap with both
	 * versions proved, whose commands swap back (those cases are in
	 * testAfterTheRenamesAStepNotProvedIsUnclearAndItsAnswerSaysWhatIsProved).
	 * The first lines of the instruction show the old version at the target
	 * and nothing at the place of the backup, and the command of that
	 * section only checks and moves nothing. The lock for changes is free
	 * and nothing is lost.
	 * Where the mark did not close, the way back of the run (question 4 of
	 * part E) moves nothing, closes the mark on the old version proved at
	 * the target, and its step is done.
	 * Sonst: after a swap that did not happen a person would read commands
	 * that swap back, or sentences on the backup and the mark that nothing
	 * proved in the call; or its way back would move the old version, or
	 * leave the mark open.
	 */
	#[DataProvider( 'kept_not_closed' )]
	public function testAnOldVersionBackAtTheTargetWhoseProofOrMarkFailsEndsUnclearWithTheShortAnswer( string $case ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$seen   = null;
		$faults = array(
			self::watch( $seen ),
			// The second rename fails; the third, the way back, runs.
			array(
				'method' => 'rename',
				'nth'    => 2,
				'kind'   => 'return',
				'value'  => false,
			),
		);
		if ( 'the mark does not close' === $case ) {
			$faults[] = array(
				'method' => 'close_mark',
				'nth'    => 1,
				'kind'   => 'return',
				'value'  => new WP_Error( 'ab_it_mark', 'The mark refused its end.' ),
			);
		} else {
			// Right before close() looks at the target: a file of another
			// process in the old version, which is back there.
			$faults[] = array(
				'method' => 'lstat',
				'kind'   => 'before',
				'before' => static function () use ( $target ): void {
					if ( self::within( 'at_target' ) && ! file_exists( $target . '/fremd.txt' ) ) {
						self::assertNotFalse( file_put_contents( $target . '/fremd.txt', 'fremd' ) );
					}
				},
			);
		}
		$answer = self::swap_with( $run_id, $faults );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step = self::step( $run_id, 3 );
		$says = $answer['next'];
		self::assertSame( 'unclear', $step['state'], $says );
		self::assertSame( 'open', self::mark_of_swap( $run_id )['state'], 'The mark of the swap is open.' );
		list( $why, $guide, $places ) = self::short_answer( $run_id, $step, $says );
		if ( 'the mark does not close' === $case ) {
			self::assertSame( 'The mark refused its end.', $why );
		} else {
			self::assertStringStartsWith( 'The version at ' . $target . ' could not be proved as the old version of the swap (', $why );
			// What the test put into the old version goes aside again (nothing deleted).
			self::assertTrue( rename( $target . '/fremd.txt', $target . '-fremd.txt' ) );
		}
		clearstatcache();
		self::assertSame( array( '1.0.0', $seen['old'] ), array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ) ), 'The old version at the target, whole.' );
		self::nothing_lost( $seen );
		// The instruction on the server: its first lines show the old
		// version at the target and nothing at the place of the backup; the
		// command of that section only checks.
		$shown = self::shown_by( $guide, $places );
		self::assertSame( array( $places['old'], null, $places['new'] ), array_values( $shown ), 'What the first lines of the instruction show.' );
		$point = self::fits( $guide, $places, $shown );
		self::assertSame( 'vor-den-umbenennungen', $point, 'The first lines send this state to the section before the renames.' );
		self::assertStringEndsWith( 'OK', self::run_section( $guide, $point, 'Before the first rename nothing was moved. Check that the target is the old version:' ), 'The command checks the old version at the target.' );
		clearstatcache();
		self::assertSame( $seen['old'], self::digest( $target ), 'The command moved nothing.' );
		self::nothing_lost( $seen );
		if ( 'the mark does not close' === $case ) {
			// Question 4 of part E: the old version at the target already
			// (the swap went back and could not close its mark): the way
			// back moves nothing, close() with kept proves it and closes
			// the mark, and its step is done.
			AB_MCP_Test_Seam::restore_all();
			clearstatcache();
			$inode = lstat( $target )['ino'];
			$way   = self::way_back_of( $run_id );
			$back  = self::back_step( (int) $way['begun']['run_id'] );
			self::assertSame( 'done', $back['state'], $back['result']['message'] );
			self::assertNull( $back['result']['place'], 'Nothing moved aside.' );
			clearstatcache();
			self::assertSame( array( $inode, $seen['old'] ), array( lstat( $target )['ino'], self::digest( $target ) ), 'The old version stays at the target, unmoved.' );
			self::assertSame( 'closed', self::mark_of_swap( $run_id )['state'], 'The mark of the swap is closed.' );
		}
	}

	/** A swap not made whose mark close() does not end. */
	public static function kept_not_closed(): array {
		return array(
			'the old version not proved at the target' => array( 'the old version not proved at the target' ),
			'the mark does not close'                  => array( 'the mark does not close' ),
		);
	}

	/**
	 * Prüft: Codex on 3014d8b, finding 1 (T3 3, a file that does not read):
	 * the mark of a swap cannot be read when close() is to end it (its file
	 * holds no mark any more), after a swap not made (the old version back
	 * at the target and proved there) and after a swap with both versions
	 * proved. The store refuses with ab_mcp_store_mark_read and its own
	 * sentence, which says that the mark stays open and that what it names
	 * stays; here that is the refusal of the store itself, not a text of
	 * the test. The answer of the swap says of it only that the mark could
	 * not be read, in the words of the swap: the short answer after the
	 * swap not made, the answer with the commands after the swap proved;
	 * no sentence of the store and none on the state of the mark. The step
	 * is unclear, the lock for changes is free and nothing is lost.
	 * Sonst: a person would read in the answer that the mark stays open and
	 * that what it names stays, which nothing read in this call (the mark
	 * may be closed by now), and would rely on a backup held by it.
	 */
	#[DataProvider( 'mark_unread' )]
	public function testAMarkThatCannotBeReadAtItsEndIsSaidAsThatWithNoStateOfIt( string $case ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$seen   = null;
		$file   = null;
		$stored = null;
		$faults = array(
			self::watch( $seen ),
			// Right before the store closes the mark: its file holds no mark
			// any more (what it held goes back after the call).
			array(
				'method' => 'close_mark',
				'nth'    => 1,
				'kind'   => 'before',
				'before' => static function ( $method, array $args ) use ( &$file, &$stored ): void {
					$file   = AB_MCP_Protected_Store::root() . '/' . AB_MCP_Protected_Store::MARKS . '/' . $args[0] . '/' . AB_MCP_Protected_Store::MARK_FILE;
					$stored = file_get_contents( $file );
					self::assertIsString( $stored, 'The mark as stored.' );
					self::assertSame( 1, file_put_contents( $file, '{' ) );
				},
			),
		);
		if ( 'after a swap not made' === $case ) {
			// The second rename fails; the third, the way back, runs.
			$faults[] = array(
				'method' => 'rename',
				'nth'    => 2,
				'kind'   => 'return',
				'value'  => false,
			);
		}
		$answer = self::swap_with( $run_id, $faults );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step = self::step( $run_id, 3 );
		$says = $answer['next'];
		self::assertSame( 'unclear', $step['state'], $says );
		// What the store itself says of this mark as it lies there: its
		// refusal, with the sentence for its own callers; it wrote nothing
		// into the file.
		self::assertIsString( $file, 'close() came to the end of the mark.' );
		$refused = AB_MCP_Protected_Store::close_mark( basename( dirname( $file ) ) );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_store_mark_read', $refused->get_error_code() );
		self::assertStringContainsString( 'so it stays open and what it names stays', $refused->get_error_message(), 'The sentence of the store.' );
		self::assertSame( '{', file_get_contents( $file ), 'The store wrote nothing into the mark it could not read.' );
		// What the mark held goes back, so that the test can read it.
		self::assertSame( strlen( $stored ), file_put_contents( $file, $stored ) );
		foreach ( array( 'stays open', 'what it names stays', 'the mark of the swap is', 'Ask the host to check the rights', 'try again' ) as $of_store ) {
			self::assertStringNotContainsString( $of_store, $says, 'No sentence of the store and none on the state of the mark: ' . $of_store );
		}
		if ( 'after a swap not made' === $case ) {
			// The short answer; what happened is only that the mark could
			// not be read.
			list( $why ) = self::short_answer( $run_id, $step, $says );
			self::assertSame( 'The mark of the swap could not be read.', $why );
			clearstatcache();
			self::assertSame( array( '1.0.0', $seen['old'] ), array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ) ), 'The old version at the target, whole.' );
			self::nothing_lost( $seen );
			return;
		}
		// Both versions proved in this call: the answer as of a swap done,
		// with the way back of the run and the commands; before them only
		// that the mark could not be read.
		$result = $step['result'];
		self::assertNotSame( array(), $result['by_hand'] );
		self::assertSame(
			'The mark of the swap could not be read. The step is unclear. The way back of this run puts the old version back (wp_site_revert action=plan with run_id ' . $run_id . '). Where this site answers no request any more, or where that way back is refused on this site (wp_site_revert says why), put it back by hand in a shell on the server, as the lines below say:' . "\n" . implode( "\n", $result['by_hand'] ),
			$result['message'],
			'What happened, the step unclear, the way back and the commands of the result, nothing else.'
		);
		self::assertStringContainsString( $result['message'], $says, 'The journal holds the answer.' );
		self::assertSame( '2.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), 'The new version is at the target.' );
		self::nothing_lost( $seen );
	}

	/** A mark that cannot be read when close() is to end it. */
	public static function mark_unread(): array {
		return array(
			'after a swap not made'                 => array( 'after a swap not made' ),
			'after a swap with both versions proved' => array( 'after a swap with both versions proved' ),
		);
	}

	/** The renames the seam of the swap saw. */
	private static function renames(): int {
		$calls = AB_MCP_Test_Seam::recorded()[ AB_MCP_Sync_Code_Swap::class . '::$naht_sync_code_swap' ] ?? array();
		return count( array_filter( $calls, static fn( $call ) => 'rename' === $call['method'] ) );
	}

	/**
	 * Prüft: F36 (T3 2): where the journal of the swap cannot be written
	 * (its first write, begun) or the place of the backup is not given
	 * (put_tree() refuses: a tree of that name is there), there is no swap
	 * and no rename; the step stays planned, the answer names the way on,
	 * the lock for changes is free, and the old version is at the target as
	 * it was; in neither case is the note of the replacement written (it
	 * comes after the journal and the mark, the lead on D2a 16.11. 03:57).
	 * Sonst: a swap could begin without its journal, so that no way back
	 * knows what it moved, or a step would end failed where the next call
	 * could do it, or the note would name a swap that did not begin and
	 * refuse the file undo points of the unit from before it.
	 */
	#[DataProvider( 'not_begun' )]
	public function testASwapWhoseJournalOrBackupCannotBeWrittenMovesNothingAndItsStepStaysPlanned( string $case ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$old    = self::digest( $target );
		$note   = self::replaced_row();
		$since  = AB_MCP_Update_Log::replaced_at( 'plugin', 'ab-it-aktiv' );
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
		self::assertSame( $since, AB_MCP_Update_Log::replaced_at( 'plugin', 'ab-it-aktiv' ), 'No note of the replacement.' );
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
	 * Prüft: D2a 3, the mark not written (T3 2): where put_mark() fails, there
	 * is no rename; the step ends failed with the old version at its place,
	 * the lock for changes is free, and the answer says that a part of the
	 * mark may be left open in the folder of the marks, as when put_mark()
	 * fails only after mark.json (its instruction not written: an open mark
	 * whose id the swap does not know; the Festlegung of this part). The note
	 * of the replacement is not written (it comes after the mark).
	 * Sonst: a swap could rename without its mark and its instruction, so
	 * that no way by hand is written, or a mark left open would keep the
	 * stage in the store without a word, or the note would name a swap that
	 * did not begin and refuse the file undo points of the unit from before
	 * it.
	 */
	#[DataProvider( 'marks_not_written' )]
	public function testAMarkThatCannotBeWrittenMovesNothingAndTheAnswerNamesAMarkLeftInPart( string $case ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$old    = self::digest( $target );
		$since  = AB_MCP_Update_Log::replaced_at( 'plugin', 'ab-it-aktiv' );
		$faults = array();
		if ( 'put_mark fails' === $case ) {
			$faults[] = array(
				'method' => 'put_mark',
				'nth'    => 1,
				'kind'   => 'return',
				'value'  => new WP_Error( 'ab_it_mark', 'The mark refused.' ),
			);
		} else {
			// The instruction of the mark aside before its rename, so that
			// only mark.json is written.
			AB_MCP_Test_Seam::install(
				AB_MCP_Protected_Store::class,
				'naht_protected_store',
				new \AB_MCP_Protected_Store_Calls(),
				array(
					array(
						'method' => 'rename',
						'kind'   => 'before',
						'before' => static function ( $method, array $args ): void {
							if ( AB_MCP_Protected_Store::GUIDE_FILE === basename( $args[1] ) ) {
								self::assertTrue( rename( $args[0], $args[0] . '-beiseite' ) );
							}
						},
					),
				)
			);
		}
		$answer = self::swap_with( $run_id, $faults );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step = self::step( $run_id, 3 );
		$says = $answer['next'];
		self::assertSame( 'failed', $step['state'], $says );
		self::assertSame( 0, self::renames(), 'No rename.' );
		self::assertStringContainsString( 'is not swapped, and the old version is at its place', $says );
		self::assertStringContainsString( 'A mark of this swap may be left in part in ' . AB_MCP_Protected_Store::root() . '/' . AB_MCP_Protected_Store::MARKS . ' (run ' . $run_id . ', step 3, open): it moved nothing, and while it is open the protected store keeps the stage of this unit', $says );
		self::assertFalse( AB_MCP_Sync_Code_Swap::lock_held(), 'The swap holds no lock for changes.' );
		self::assertTrue( AB_MCP_Change_Lock::take(), 'The lock for changes can be taken again.' );
		AB_MCP_Change_Lock::release();
		clearstatcache();
		self::assertSame( array( '1.0.0', $old ), array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ) ), 'The old version at the target as it was.' );
		self::assertSame( $since, AB_MCP_Update_Log::replaced_at( 'plugin', 'ab-it-aktiv' ), 'No note of the replacement.' );
		$mark = self::mark_of_swap( $run_id );
		if ( 'put_mark fails' === $case ) {
			self::assertStringContainsString( 'The mark refused.', $says );
			self::assertNull( $mark, 'No mark.' );
			return;
		}
		self::assertSame( 'open', $mark['state'] ?? null, 'Its mark.json is there, open, as the answer says.' );
		self::assertFileDoesNotExist( AB_MCP_Protected_Store::root() . '/' . AB_MCP_Protected_Store::MARKS . '/' . $mark['mid'] . '/' . AB_MCP_Protected_Store::GUIDE_FILE );
	}

	/** How the mark of a swap is not written. */
	public static function marks_not_written(): array {
		return array(
			'put_mark fails'                   => array( 'put_mark fails' ),
			'put_mark fails after mark.json' => array( 'put_mark fails after mark.json' ),
		);
	}

	/**
	 * Prüft: the note of the replacement not read back (T3 2; it comes last,
	 * after the journal and the mark, the lead on D2a 16.11. 03:57): there
	 * is no rename; the mark is closed on the old version (kept), the step
	 * ends failed, the answer names the note and the way on, the note is as
	 * it was, the lock for changes is free and nothing is lost.
	 * Sonst: a swap could rename without its note, so that a file undo point
	 * of before it would write a file of the old version into the new one,
	 * or a mark left open would keep the stage in the store.
	 */
	public function testANoteOfTheReplacementNotReadBackMovesNothingAndClosesTheMarkOnTheOldVersion(): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$since  = AB_MCP_Update_Log::replaced_at( 'plugin', 'ab-it-aktiv' );
		$seen   = null;
		$answer = self::swap_with(
			$run_id,
			array(
				self::watch( $seen ),
				// The note not written: reading it back finds no note of this swap.
				array(
					'method' => 'replaced',
					'nth'    => 1,
					'kind'   => 'return',
					'value'  => null,
				),
			)
		);
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step = self::step( $run_id, 3 );
		$says = $answer['next'];
		self::assertSame( 'failed', $step['state'], $says );
		self::assertSame( 0, self::renames(), 'No rename.' );
		self::assertSame( 'closed', self::mark_of_swap( $run_id )['state'], 'The mark closed on the old version (kept).' );
		self::assertStringContainsString( 'The plugin ab-it-aktiv is not swapped, and the old version is at its place: The note that the files of the plugin ab-it-aktiv are replaced', $says );
		self::assertStringContainsString( 'did not read back as written, so nothing of it was moved', $says );
		self::assertStringContainsString( 'The run goes on with the next step (action=next)', $says );
		self::assertSame( $since, AB_MCP_Update_Log::replaced_at( 'plugin', 'ab-it-aktiv' ), 'The note as it was.' );
		clearstatcache();
		self::assertSame( array( '1.0.0', $seen['old'] ), array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ) ), 'The old version at the target as it was.' );
		self::nothing_lost( $seen );
	}

	/**
	 * Prüft: D2a 5, the end of the step not written (T3 2: the journal after
	 * the renames; the lead on D2a 16.11. 03:57, way a): the mark is closed
	 * after the proof of both versions, the step stays begun, and the answer
	 * says that the swap happened (the new version at the target, the old
	 * one whole in the backup, each with its path), that its end is not
	 * recorded, without a state of the step claimed from the write that
	 * failed (option B: the status of the run shows it), and names the way
	 * back of the run, without the sentence of Z24 (F57: only the answer of
	 * a swap done, its end recorded); the lock for changes is
	 * free and nothing is lost. The next call (action=next) swaps nothing
	 * again and changes nothing: no call of the swap, the new version at
	 * the target and the old one in the backup as they were, the step begun
	 * and the mark closed. The answer does not call for the next step
	 * (Codex D3, finding 3).
	 * Sonst: a swap that happened would read as one that did not, a person
	 * would not know where the old version lies or how to go back, the
	 * answer after a failure would say more than this call proved (option
	 * B), or the next call would swap again or move the old version.
	 */
	public function testASwapWhoseEndCannotBeWrittenSaysItIsSwappedAndTheNextCallChangesNothing(): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$seen    = null;
		$refused = self::swap_with(
			$run_id,
			array(
				self::watch( $seen ),
				// Call 1 writes the step begun (begun()), 2 its end (ended()).
				array(
					'method' => 'journal',
					'nth'    => 2,
					'kind'   => 'return',
					'value'  => new WP_Error( 'ab_it_db', 'The database refused the write.' ),
				),
			)
		);
		self::assertInstanceOf( WP_Error::class, $refused );
		$says = $refused->get_error_message();
		self::assertSame( 'ab_mcp_sync_run_journal', $refused->get_error_code(), $says );
		self::assertSame( 2, self::renames(), 'Both renames, no swap back.' );
		self::assertStringContainsString( 'The plugin ab-it-aktiv of the staging site is swapped in (version 1.0.0 here before, 2.0.0 now): its new version lies at ' . $target . ', the old one whole in the backup ' . $seen['backup'] . '.', $says );
		self::assertStringContainsString( 'The end of step 3 could not be recorded (The database refused the write.). Ask for the status of the run (wp_site_push action=status): where it shows the run interrupted, wp_site_revert action=plan with this run_id goes on from there, or a person closes it as checked on the page AlphaBridge → Staging.', $says );
		self::assertStringNotContainsString( 'stays begun', $says, 'No state of the step from a write that failed (option B); the status of the run shows it.' );
		self::assertStringContainsString( 'wp_site_revert action=plan with run_id ' . $run_id, $says, 'The way back of the run.' );
		self::assertStringNotContainsString( 'Call action=next for the next step.', $says, 'No call to go on while its end is not recorded (Codex D3, finding 3).' );
		self::assertStringNotContainsString( sprintf( self::Z24, 'plugin', 'ab-it-aktiv' ), $says, 'The sentence of Z24 only in the answer of a swap done, its end recorded (option B: the answer after a failure says only what this call proved).' );
		self::assertSame( 'begun', self::step( $run_id, 3 )['state'] );
		self::assertSame( 'closed', self::mark_of_swap( $run_id )['state'], 'The mark closed after the proof of both versions (way a).' );
		$swapped = static function () use ( $seen, $target ): array {
			clearstatcache();
			return array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ), self::digest( $seen['backup'] ) );
		};
		self::assertSame( array( '2.0.0', $seen['new'], $seen['old'] ), $swapped(), 'The new version at the target, the old one whole in the backup.' );
		self::nothing_lost( $seen );

		$next = self::swap_with( $run_id, array() );
		self::assertInstanceOf( WP_Error::class, $next );
		self::assertSame( 'ab_mcp_sync_run_interrupted', $next->get_error_code(), $next->get_error_message() );
		self::assertSame( array(), AB_MCP_Test_Seam::recorded()[ AB_MCP_Sync_Code_Swap::class . '::$naht_sync_code_swap' ], 'No call of the swap.' );
		self::assertSame( array( '2.0.0', $seen['new'], $seen['old'] ), $swapped(), 'Nothing moved.' );
		self::assertSame( array( 'begun', 'closed' ), array( self::step( $run_id, 3 )['state'], self::mark_of_swap( $run_id )['state'] ) );
		self::assertFalse( AB_MCP_Sync_Code_Swap::lock_held(), 'The swap holds no lock for changes.' );
		// What it writes is the state of the run (decision 18): it finds
		// the step begun by a request that ended.
		self::assertSame( 'interrupted', AB_MCP_Sync_Run::get( $run_id )['state'] );
	}

	/**
	 * Prüft: D2a 4 (T3 2), OPcache not cleared after the second rename
	 * (invalidate_opcache() answers not_possible): the step ends unclear,
	 * the mark stays open, and close() is not reached, so the answer is the
	 * short one (option B, Thomas 16.11.2026): what happened, that OPcache
	 * is to be cleared at the host, the way back of the run, the
	 * instruction on the server, target and backup; no sentence on what
	 * lies where and no command, the same with the backup as it was or
	 * changed (the answer reads nothing for it). The first lines of the
	 * instruction show the new version at the target and the old one in the
	 * backup, and the command of that section swaps back (the old version
	 * at the target, the stage at its place again). The lock for changes is
	 * free and nothing is lost.
	 * Sonst: the step would be done with old code left in OPcache, or a
	 * person would get no way by hand that works, or an answer that names a
	 * state or gives a command nothing proved in the call.
	 */
	#[DataProvider( 'opcache_faults' )]
	public function testOpcacheNotClearedAfterTheRenamesEndsUnclearWithTheWayBackTheWayByHandAndTheHost( string $case ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$seen   = null;
		$faults = array(
			self::watch( $seen ),
			// Call 1 is the one of prepare(); 2 the first after the renames.
			array(
				'method' => 'opcache',
				'nth'    => 2,
				'kind'   => 'return',
				'value'  => 'not_possible',
			),
		);
		if ( 'the backup changed' === $case ) {
			$faults[] = array(
				'method' => 'rename',
				'nth'    => 2,
				'kind'   => 'before',
				'before' => static function () use ( &$seen ): void {
					self::assertNotFalse( file_put_contents( $seen['backup'] . '/fremd.txt', 'fremd' ) );
				},
			);
		}
		$answer = self::swap_with( $run_id, $faults );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step = self::step( $run_id, 3 );
		$says = $answer['next'];
		self::assertSame( 'unclear', $step['state'], $says );
		self::assertSame( '2.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), 'The new version is at the target.' );
		self::assertSame( 'open', self::mark_of_swap( $run_id )['state'], 'The mark of the swap is open.' );
		// close() was not reached: the short answer (option B), the same
		// with the backup as it was or changed.
		list( $why, $guide, $places ) = self::short_answer( $run_id, $step, $says );
		self::assertStringStartsWith( 'OPcache could not be cleared for ' . $target . '/', $why );
		self::assertStringEndsWith( ' Have OPcache of this site cleared at the host, or PHP restarted (PHP-FPM or the web server), so that PHP runs the files that are at the target now.', $why );
		if ( 'the backup changed' === $case ) {
			// What the test put into the backup goes aside again (nothing deleted).
			self::assertTrue( rename( $seen['backup'] . '/fremd.txt', $seen['backup'] . '-fremd.txt' ) );
			self::nothing_lost( $seen );
			return;
		}
		self::nothing_lost( $seen );
		// The instruction on the server: its first lines show the new
		// version at the target and the old one in the backup; the command
		// of that section swaps back.
		$shown = self::shown_by( $guide, $places );
		self::assertSame( array( $places['new'], $places['old'], null ), array_values( $shown ), 'What the first lines of the instruction show.' );
		$point = self::fits( $guide, $places, $shown );
		self::assertSame( 'nach-den-umbenennungen', $point, 'The first lines send this state to the section after the renames.' );
		self::assertStringEndsWith( 'OK', self::run_section( $guide, $point, 'The new version is at the target, the old one in the backup. Swap back and check it:' ), 'The command does all of it.' );
		clearstatcache();
		self::assertSame( array( '1.0.0', $seen['old'], $seen['new'] ), array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ), self::digest( $seen['stage'] ) ), 'The old version is back, the stage at its place, each whole.' );
	}

	/** OPcache not cleared, with the backup as it is or changed. */
	public static function opcache_faults(): array {
		return array(
			'the backup as it was' => array( 'the backup as it was' ),
			'the backup changed' => array( 'the backup changed' ),
		);
	}

	/**
	 * Prüft: Codex on 3014d8b, finding 2 (T3 5 and 6, on the way back by
	 * hand): after a swap that ended unclear with the short answer, a swap
	 * back by hand stopped after its first move (the target is missing, the
	 * new version is back in the stage), and at the place of the backup
	 * lies a folder of another process, with another inode. The first lines
	 * of the instruction send this state to no section (the test reads
	 * their cases as they stand and picks nothing itself): no case is true,
	 * so they say to run no command, to stop and to take the way back of
	 * the run. Each command of the instruction, run all the same, first the
	 * one of a swap back that stopped, checks before it moves: it prints no
	 * OK, the target stays missing, the folder of the other process stays
	 * at its place byte for byte, and the new version in the stage and the
	 * old one (which the test moved aside) stay whole. With the old version
	 * at the place of the backup again, the first lines send the state to
	 * the section between the renames, and its command puts the old version
	 * back.
	 * Sonst: where the site answers no request any more, a person who
	 * follows the instruction would move a folder that is not the old
	 * version to the target (its inode was checked only after the move).
	 */
	public function testTheInstructionSendsOnlyASureStateToASectionAndNoCommandOfItMovesAnotherEntry(): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$seen   = null;
		$answer = self::swap_with(
			$run_id,
			array(
				self::watch( $seen ),
				// OPcache not cleared after the renames (call 1 is the one of
				// prepare()): the step ends unclear with the short answer.
				array(
					'method' => 'opcache',
					'nth'    => 2,
					'kind'   => 'return',
					'value'  => 'not_possible',
				),
			)
		);
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step = self::step( $run_id, 3 );
		self::assertSame( 'unclear', $step['state'], $answer['next'] );
		list( , $guide, $places ) = self::short_answer( $run_id, $step, $answer['next'] );
		self::assertSame( $target, $places['target'] );
		// A swap back by hand that stopped after its first move: the new
		// version is back in the stage, the target is missing. And at the
		// place of the backup a folder of another process; the old version
		// goes aside (nothing is deleted).
		$aside = $places['backup'] . '-alt';
		self::assertTrue( rename( $places['target'], $places['stage'] ) );
		self::assertTrue( rename( $places['backup'], $aside ) );
		self::assertTrue( mkdir( $places['backup'], 0700 ) );
		self::assertSame( 5, file_put_contents( $places['backup'] . '/fremd.txt', 'fremd' ) );
		$entry = static function ( string $path ): ?array {
			clearstatcache();
			return is_dir( $path ) ? array( fileinode( $path ), self::digest( $path ) ) : null;
		};
		$state = static function () use ( $entry, $places, $aside ): array {
			return array(
				'target' => $entry( $places['target'] ),
				'backup' => $entry( $places['backup'] ),
				'stage'  => $entry( $places['stage'] ),
				'aside'  => $entry( $aside ),
			);
		};
		$before = $state();
		self::assertNull( $before['target'], 'The target is missing.' );
		self::assertSame( array( 'fremd.txt' ), array_keys( $before['backup'][1] ), 'The folder of the other process at the place of the backup.' );
		self::assertNotSame( $places['old'], $before['backup'][0], 'It has another inode than the old version.' );
		self::assertSame( array( array( $places['new'], $seen['new'] ), array( $places['old'], $seen['old'] ) ), array( $before['stage'], $before['aside'] ), 'The new version whole in the stage, the old one whole aside.' );
		// The first lines of the instruction: no case of them is true of
		// this state, so they send it to no section.
		$shown = self::shown_by( $guide, $places );
		self::assertSame( array( null, $before['backup'][0], $places['new'] ), array_values( $shown ), 'What the first lines of the instruction show.' );
		self::assertNull( self::fits( $guide, $places, $shown ), 'No case is true: the first lines say to run no command and to take the way back of the run.' );
		// Each command of the instruction, run all the same: first the one of
		// a swap back that stopped, which the state looks like. None prints
		// OK, none moves anything.
		$points = array(
			'waehrend-des-ruecktauschs'              => 'The swap back stopped: the target holds the new version or is missing. Finish it and check it:',
			'vor-den-umbenennungen'                  => 'Before the first rename nothing was moved. Check that the target is the old version:',
			'zwischen-den-umbenennungen'             => 'The target is missing; the old version lies whole in the backup. Put it back and check it:',
			'zwischen-den-umbenennungen-ziel-belegt' => 'The target is taken by another entry; the old version lies whole in the backup. Only after that entry is moved away, put the old version back and check it:',
			'nach-den-umbenennungen'                 => 'The new version is at the target, the old one in the backup. Swap back and check it:',
			'nach-der-aktivierung'                   => 'After the activation: as after the renames. Swap back and check it:',
		);
		foreach ( $points as $point => $first ) {
			self::assertStringNotContainsString( 'OK', self::run_section( $guide, $point, $first ), $point . ': a check fails, no OK.' );
			self::assertSame( $before, $state(), $point . ': nothing is moved, the folder of the other process stays byte for byte.' );
		}
		// The folder of the other process goes aside and the old version to
		// the place of the backup again: now a case is true, and its section
		// puts the old version back.
		self::assertTrue( rename( $places['backup'], $places['backup'] . '-fremd' ) );
		self::assertTrue( rename( $aside, $places['backup'] ) );
		$shown = self::shown_by( $guide, $places );
		self::assertSame( array( null, $places['old'], $places['new'] ), array_values( $shown ), 'What the first lines of the instruction show now.' );
		$point = self::fits( $guide, $places, $shown );
		self::assertSame( 'zwischen-den-umbenennungen', $point, 'The first lines send this state to the section between the renames.' );
		self::assertStringEndsWith( 'OK', self::run_section( $guide, $point, $points[ $point ] ), 'The command does all of it.' );
		clearstatcache();
		self::assertSame( array( '1.0.0', $seen['old'], $seen['new'] ), array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ), self::digest( $places['stage'] ) ), 'The old version is back at the target, the new one in the stage, each whole.' );
		self::assertSame( array( 'fremd.txt' ), array_keys( self::digest( $places['backup'] . '-fremd' ) ), 'What the other process put there stays.' );
		self::nothing_lost( $seen );
	}

	/**
	 * Prüft: Codex on 5199de3, findings 1 (A) and 2 (C): after a swap that
	 * ended unclear with the short answer (OPcache not cleared after the
	 * renames), neither the short answer nor the instruction it names says
	 * that a command moves nothing where a check fails, as a check after a
	 * move (the last ls -di) can fail after the move: of the command each
	 * says only that it contains checks and to stop where it prints no OK.
	 * The instruction sends only to the section the true case of its first
	 * lines names; no line of it sends to the point where the swap stopped
	 * beside them.
	 * Sonst: a person whose command stopped without OK after it had moved
	 * the backup would take the target for untouched and could move
	 * another entry onto it, or would run the command of a section the
	 * first lines of the instruction had ruled out.
	 */
	public function testNeitherTheShortAnswerNorTheInstructionSaysThatACommandMovesNothingWhereACheckFails(): void {
		list( $run_id ) = self::ready_to_swap();
		$answer         = self::swap_with(
			$run_id,
			array(
				// OPcache not cleared after the renames (call 1 is the one of
				// prepare()): the step ends unclear with the short answer.
				array(
					'method' => 'opcache',
					'nth'    => 2,
					'kind'   => 'return',
					'value'  => 'not_possible',
				),
			)
		);
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step = self::step( $run_id, 3 );
		self::assertSame( 'unclear', $step['state'], $answer['next'] );
		// The short answer whole, its line of the instruction with no such
		// promise (short_answer()).
		list( , $guide ) = self::short_answer( $run_id, $step, $answer['next'] );
		self::assertStringContainsString( 'run the command its first lines point to. The command contains checks; where it does not print OK, stop.', $answer['next'] );
		$text = (string) file_get_contents( $guide );
		self::assertContains( self::RUN_ONLY, explode( "\n", $text ), 'The instruction: only the command of the section its first lines name, and of it only that it contains checks.' );
		foreach ( array( 'moves nothing', 'nothing is moved', 'checks first', 'come first', 'the point where the swap stopped' ) as $promise ) {
			self::assertStringNotContainsString( $promise, $text, 'No promise that a command moves nothing where a check fails, and no choice beside the first lines: ' . $promise );
		}
	}

	/**
	 * Prüft: option B (Thomas 16.11.2026) after both renames, with Codex D1,
	 * findings 4, 5 and 8: a step not proved ends unclear, and its answer
	 * says of the disk only what close() proved in this call. Not both
	 * versions proved: a listing of the files PHP runs that is not whole (a
	 * link in the new version, an entry whose lstat() fails) clears no
	 * OPcache for good and stops before close(), with the sentence to have
	 * OPcache cleared at the host; the backup changed, or the time of the
	 * call over right before it is read (in_backup() proves only within its
	 * deadline); a new version at the target not proved by close() (D2a 5,
	 * code:close_target). Each leaves the mark open and gives the short
	 * answer: what happened, the way back of the run, the instruction on
	 * the server, target and backup, no sentence on what lies where or on
	 * the mark, and no command. Both versions proved, then something fails:
	 * a pair that takes no new bases after the mark was closed, or a mark
	 * written closed whose read back fails. The answer is as of a swap
	 * done, with the way back of the run and the command that swaps back,
	 * which does it; of the mark it says closed only where the store closed
	 * it and read it back, else what the store said and no state. In each
	 * case the lock for changes is free and nothing is lost (D2a 6).
	 * Sonst: the step would be done with old code left in OPcache; a person
	 * would read commands or sentences on the backup and the mark that
	 * nothing proved in the call, and put a backup that is not the old
	 * version into the site with them; or, after a swap proved and its mark
	 * closed, would have no commands once the folder of the mark is gone;
	 * or a mark would close on a version at the target that is not the one
	 * the swap put there, or on a backup read after the time of the call.
	 */
	#[DataProvider( 'after_renames' )]
	public function testAfterTheRenamesAStepNotProvedIsUnclearAndItsAnswerSaysWhatIsProved( string $case ): void {
		list( $run_id, $unit, $target ) = self::ready_to_swap();
		$backup = null;
		// The mark written closed, then its read back fails once (Codex D3,
		// finding 2).
		$unread = static function (): void {
			$calls = new \AB_MCP_IT_Store_Calls();
			AB_MCP_Test_Seam::install(
				AB_MCP_Protected_Store::class,
				'naht_protected_store',
				$calls,
				array(
					array(
						'method' => 'rename',
						'kind'   => 'before',
						'before' => static function ( $method, array $args ) use ( $calls ): void {
							if ( AB_MCP_Protected_Store::MARK_FILE === basename( $args[1] ) && false !== strpos( (string) file_get_contents( $args[0] ), '"state": "closed"' ) ) {
								$calls->fail_once['read'][ $args[1] ] = 1;
							}
						},
					),
				)
			);
		};
		$hooks  = array(
			'the closed mark not read back'     => $unread,
			// Its fault is the one on lstat() below.
			'no time left for the backup'       => static function (): void {},
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
			'the new version changed'           => static function ( $method, array $args ): void {
				self::assertNotFalse( file_put_contents( $args[0] . '/fremd.txt', 'fremd' ) );
			},
		);
		$seen = null;
		try {
			$answer = self::swap_with(
				$run_id,
				array(
					self::watch( $seen ),
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
					// «no time left for the backup»: the time of the call runs
					// out after close() proved the target, right before it
					// looks at the backup.
					array(
						'method' => 'lstat',
						'kind'   => 'before',
						'before' => static function () use ( $case ): void {
							if ( 'no time left for the backup' === $case && self::within( 'in_backup' ) ) {
								AB_MCP_Sync_Env::$clock = static fn(): float => microtime( true ) + 86400.0;
							}
						},
					),
				)
			);
		} finally {
			AB_MCP_Sync_Env::$clock = null;
			if ( is_dir( $target . '/includes' ) ) {
				chmod( $target . '/includes', 0755 );
			}
		}
		// The store as it is again (its mark read as stored).
		AB_MCP_Test_Seam::restore_all();
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		$step   = self::step( $run_id, 3 );
		$says   = $answer['next'];
		$result = $step['result'];
		self::assertSame( 'unclear', $step['state'], $says );
		// What the test put into a version goes aside again (nothing
		// deleted), then nothing is lost.
		$aside = array(
			'a link in the new version' => $target . '/verweis',
			'the backup changed'        => $backup . '/fremd.txt',
			'the new version changed'   => $target . '/fremd.txt',
		);
		if ( isset( $aside[ $case ] ) ) {
			self::assertTrue( rename( $aside[ $case ], dirname( $aside[ $case ] ) . '-' . basename( $aside[ $case ] ) ) );
		}
		self::assertSame( '2.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), 'The new version is at the target.' );
		$mark = self::mark_of_swap( $run_id );
		self::nothing_lost( $seen );
		if ( ! in_array( $case, array( 'the pair changed before its bases', 'the closed mark not read back' ), true ) ) {
			// Not both versions proved in this call (close() not reached, or
			// one of its proofs failed): the short answer, the mark open.
			self::assertSame( 'open', $mark['state'], 'The mark of the swap is open.' );
			list( $why ) = self::short_answer( $run_id, $step, $says );
			$listing     = 'The PHP files in ' . $target . ' could not all be listed';
			$in_backup   = 'The old version could not be proved whole in the backup ' . $backup . ' (';
			$whys        = array(
				'a link in the new version'   => $listing,
				'an entry not read'           => $listing,
				'the backup changed'          => $in_backup,
				'no time left for the backup' => $in_backup,
				'the new version changed'     => 'The version at ' . $target . ' could not be proved as the new version of the swap (',
			);
			self::assertStringStartsWith( $whys[ $case ], $why );
			if ( $listing === $whys[ $case ] ) {
				self::assertStringEndsWith( ' Have OPcache of this site cleared at the host, or PHP restarted (PHP-FPM or the web server), so that PHP runs the files that are at the target now.', $why );
			}
			return;
		}
		// Both versions proved in this call, then something failed: the
		// answer as of a swap done, with the way back of the run and the
		// commands, as the mark may be closed and its folder go.
		self::assertSame( array( $target, $backup, $mark['mid'] ), array( $result['target'], $result['backup'], $result['mark'] ) );
		self::assertNotSame( array(), $result['by_hand'] );
		$frame = ' The step is unclear. The way back of this run puts the old version back (wp_site_revert action=plan with run_id ' . $run_id . '). Where this site answers no request any more, or where that way back is refused on this site (wp_site_revert says why), put it back by hand in a shell on the server, as the lines below say:' . "\n" . implode( "\n", $result['by_hand'] );
		self::assertStringContainsString( $result['message'], $says, 'The journal holds the answer.' );
		self::assertStringEndsWith( $frame, $result['message'], 'After what happened: the step unclear, the way back and the commands of the result, nothing else.' );
		$why = substr( $result['message'], 0, -strlen( $frame ) );
		self::assertStringContainsString( " && mv '" . $backup . "' '" . $target . "' && ", $says, 'The command that puts the proved backup back.' );
		foreach ( array( 'stays open', 'not confirmed' ) as $state ) {
			self::assertStringNotContainsString( $state, $says, 'No state of the mark that nothing proved: ' . $state );
		}
		if ( 'the pair changed before its bases' === $case ) {
			// The mark closed and read back by the store: the answer says so.
			self::assertStringStartsWith( 'The new version is at the target and the mark of the swap is closed, but the files of its pair did not take their new bases (', $why );
			self::assertSame( 'closed', $mark['state'] );
		} else {
			// Written closed, not read back: the answer passes on what the
			// store said and names no state of the mark.
			self::assertStringStartsWith( 'The closed mark did not read back as written.', $why );
			self::assertStringNotContainsString( 'the mark of the swap is', $says, 'No state of the mark.' );
		}
		// The command of the answer swaps back: the old version at the
		// target, the stage at its place again.
		self::assertStringEndsWith( 'OK', self::run_by_hand( $result['by_hand'] ), 'The command does all of it.' );
		clearstatcache();
		self::assertSame( array( '1.0.0', $seen['old'], $seen['new'] ), array( AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), self::digest( $target ), self::digest( $seen['stage'] ) ), 'The old version is back, the stage at its place, each whole.' );
	}

	/** What is not proved after the renames. */
	public static function after_renames(): array {
		return array(
			'a link in the new version (findings 4 and 5)'   => array( 'a link in the new version' ),
			'an entry not read (findings 4 and 5)'           => array( 'an entry not read' ),
			'the backup changed (finding 5)'                 => array( 'the backup changed' ),
			'the pair changed before its bases (finding 8)' => array( 'the pair changed before its bases' ),
			'the new version changed (D2a 5, close_target)' => array( 'the new version changed' ),
			'the closed mark not read back (Codex D3, finding 2)' => array( 'the closed mark not read back' ),
			'no time left for the backup (in_backup() within its deadline)' => array( 'no time left for the backup' ),
		);
	}

	/* ------------------------- D2b: the refusals before the first mutation */

	/** The site and the swap as they are before a call that is to refuse (D2b): the old version and its identity, the note of the replacement of the plugin $slug, the groups of the backups. */
	private static function before_swap( string $target, string $slug = 'ab-it-aktiv' ): array {
		clearstatcache();
		$id = lstat( $target );
		return array(
			'slug'   => $slug,
			'old'    => self::digest( $target ),
			'id'     => array( $id['dev'], $id['ino'] ),
			'note'   => self::replaced_row(),
			'since'  => AB_MCP_Update_Log::replaced_at( 'plugin', $slug ),
			'groups' => self::backup_groups(),
		);
	}

	/** The groups of the area backups of the protected store, by their id. */
	private static function backup_groups(): array {
		$groups = AB_MCP_Protected_Store::groups( 'backups' );
		self::assertIsArray( $groups, is_wp_error( $groups ) ? $groups->get_error_message() : '' );
		$gids = array_column( $groups, 'gid' );
		sort( $gids, SORT_STRING );
		return $gids;
	}

	/**
	 * A swap refused before its first mutation at the target (D2b; Codex
	 * D4, finding 4): the refusal with its code and its way; the step
	 * planned, without a journal; no call of the swap that writes the site
	 * or its records (the journal, the mark, the note, a rename, the close of
	 * a mark, the file undo points); no mark, no note of the replacement;
	 * the lock for changes free; the old version at the target as $before
	 * holds it, the same folder. In the protected store only what $store
	 * names arose, as the refusal at its place needs it: group (the group
	 * of the backups of the run, opened for the place of the backup) and
	 * rights (the rights of the site given to the stage). Its message.
	 */
	private static function nothing_begun( int $run_id, string $target, array $before, $answer, string $code, string $way, array $store = array() ): string {
		$says = self::refused( $answer, $code );
		self::assertStringContainsString( $way, $says, 'The answer with its way.' );
		$step = self::step( $run_id, 3 );
		self::assertSame( 'planned', $step['state'], 'The step stays planned.' );
		self::assertNull( $step['parts'] ?? null, 'No journal.' );
		$calls = array_column( AB_MCP_Test_Seam::recorded()[ AB_MCP_Sync_Code_Swap::class . '::$naht_sync_code_swap' ] ?? array(), 'method' );
		self::assertSame( array(), array_values( array_intersect( $calls, array( 'journal', 'put_mark', 'replaced', 'rename', 'close_mark', 'discard_files' ) ) ), 'No journal, no mark, no note, no rename, no undo point discarded.' );
		$arose = array();
		if ( self::backup_groups() !== $before['groups'] ) {
			$arose[] = 'group';
		}
		if ( in_array( 'chmod', $calls, true ) ) {
			$arose[] = 'rights';
		}
		self::assertSame( $store, $arose, 'In the protected store only what the refusal at its place needs.' );
		self::assertNull( self::mark_of_swap( $run_id ), 'No mark.' );
		self::assertSame( array( $before['since'], $before['note'] ), array( AB_MCP_Update_Log::replaced_at( 'plugin', $before['slug'] ), self::replaced_row() ), 'No note of the replacement.' );
		self::assertFalse( AB_MCP_Sync_Code_Swap::lock_held(), 'The swap holds no lock for changes.' );
		self::assertTrue( AB_MCP_Change_Lock::take(), 'The lock for changes can be taken again.' );
		AB_MCP_Change_Lock::release();
		clearstatcache();
		self::assertSame( $before['old'], self::digest( $target ), 'The old version at the target as it was.' );
		$id = lstat( $target );
		self::assertSame( $before['id'], array( $id['dev'], $id['ino'] ), 'The same folder at the target.' );
		return $says;
	}

	/** Whether a call runs within a method of the swap. */
	private static function within( string $method ): bool {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( AB_MCP_Sync_Code_Swap::class === ( $frame['class'] ?? null ) && $method === $frame['function'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prüft: D2b 1 (T3 4) and Codex D4, finding 4: the clock of the run,
	 * which the engine asked before the step, gives no time when the swap
	 * asks it: right after prepare(), before the writes that prepare the
	 * swap in the protected store (swapped()), or during the rights of the
	 * new version, when the swap asks it for the time it begins
	 * (rename_marked(), before its journal). The swap is refused before its
	 * first write at the target: the step stays planned, nothing is written
	 * or moved at the target, no journal, mark or note; in the protected
	 * store after prepare() nothing at all, during the rights only the group
	 * of the backups and the rights of the stage; the lock for changes is
	 * free, and the answer names the clock and the way.
	 * Sonst: a swap could begin without a time for its note and its journal,
	 * and the undo of Pro could not tell a file undo point of before it from
	 * one of after it; the step would end failed where the next call can do
	 * it; or a refusal that needs no write would leave a group of the
	 * backups and a stage with other rights behind.
	 */
	#[DataProvider( 'clocks_off' )]
	public function testAClockThatGivesNoTimeBeforeTheFirstWriteOfTheSwapRefusesItBeforeAnythingIsWritten( string $from, array $store ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$before = self::before_swap( $target );
		$off    = false;
		// From the last check of prepare() (the lock for changes read as held,
		// in checked()) or from the rights of the new version on, the clock
		// of the run gives no time.
		AB_MCP_Sync_Run::$clock = static function () use ( &$off ) {
			return $off ? null : time();
		};
		$answer = self::swap_with(
			$run_id,
			array(
				array(
					'method' => 'checked' === $from ? 'lock_row' : 'chmod',
					'kind'   => 'before',
					'before' => static function () use ( &$off, $from ): void {
						$off = $off || self::within( $from );
					},
				),
			)
		);
		AB_MCP_Sync_Run::$clock = null;
		self::assertTrue( $off, 'The clock failed after the engine asked it.' );
		self::nothing_begun( $run_id, $target, $before, $answer, 'ab_mcp_sync_run_clock', 'Try again; if it keeps failing, check the code that sets the clock of AB_MCP_Sync_Run.', $store );
	}

	/** Where the clock of the run fails: after prepare() (nothing in the store), during the rights (the group of the backups and the rights). */
	public static function clocks_off(): array {
		return array(
			'after prepare()'     => array( 'checked', array() ),
			'during the rights' => array( 'given_rights', array( 'group', 'rights' ) ),
		);
	}

	/**
	 * Prüft: F38 (T3 1, the time of a call): a unit too large to be hashed
	 * within one call is refused before the first mutation where the swap
	 * reads it whole, in prepare() (the version at the target against the
	 * base of its pair) and in put_tree() (the place of its backup, read
	 * from the target): the step stays planned, nothing is written or moved
	 * at the target, in the protected store only the group of the backups
	 * where put_tree() asked for the place (Codex D4, finding 4), the lock
	 * for changes is free, and the answer names the way, in both places the
	 * update by hand in wp-admin (Codex D4, finding 6).
	 * Sonst: the swap could move a unit it never read whole, so that neither
	 * the backup nor the way back is proved, or the step would end failed;
	 * or the answer would send a unit that stays too large into the same
	 * refusal again and again.
	 */
	#[DataProvider( 'too_large' )]
	public function testAUnitTooLargeToBeHashedInOneCallIsRefusedBeforeTheSwapWritesAnything( string $where ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		$before = self::before_swap( $target );
		$late   = false;
		// Within the reading at that place, the time of the call is over:
		// the first unit of a call that does not end in it is too large.
		AB_MCP_Test_Seam::install(
			AB_MCP_Sync_File_Manifest::class,
			'naht_sync_file_manifest',
			new \AB_MCP_Sync_File_Manifest_Calls(),
			array(
				array(
					'method' => 'lstat',
					'kind'   => 'before',
					'before' => static function () use ( $where, &$late ): void {
						if ( ! $late && self::within( $where ) ) {
							$late                  = true;
							AB_MCP_Sync_Env::$clock = static fn() => microtime( true ) + 3600.0;
						}
					},
				),
			)
		);
		$answer               = self::swap_with( $run_id, array() );
		AB_MCP_Sync_Env::$clock = null;
		self::assertTrue( $late, 'The time ran out within the reading.' );
		if ( 'unchanged' === $where ) {
			self::nothing_begun( $run_id, $target, $before, $answer, 'code:live_unknown', 'could not be read whole now (a file or folder not read, or the time of the call ran out), so whether it is still the version its pair is based on is not known and it is not swapped. Call action=next again; where it keeps failing, update it by hand in wp-admin.' );
			return;
		}
		$says = self::nothing_begun( $run_id, $target, $before, $answer, 'ab_mcp_store_tree_unread', 'So the plugin ab-it-aktiv is not swapped. Call action=next again; where it keeps failing, update it by hand in wp-admin.', array( 'group' ) );
		self::assertStringContainsString( '(too_large)', $says );
	}

	/** Where the swap reads the unit whole: prepare() (unchanged()), put_tree() (plan_of()). */
	public static function too_large(): array {
		return array(
			'in prepare()'  => array( 'unchanged' ),
			'in put_tree()' => array( 'plan_of' ),
		);
	}

	/**
	 * Prüft: F41 (T3 5): where the folder of the unit on this site changed
	 * after the report (a file, or an automatic update that put another
	 * version there), or changes between prepare() and put_tree(), the swap
	 * is refused with code:live_changed before its first mutation: the step
	 * stays planned, nothing is written or moved, what changed stays, the
	 * lock for changes is free, and the answer names the way. This site and
	 * the staging site lie at different paths, as F56 swaps with them: the
	 * manifests compare without the paths.
	 * Sonst: the version of the staging site could win over a change or an
	 * update of the site the report never saw, and it would be lost.
	 */
	#[DataProvider( 'live_changed' )]
	public function testAUnitChangedOnThisSiteAfterTheReportIsNotSwapped( string $case ): void {
		self::assertNotSame( AB_MCP_IT_Sites::docroot( 'a' ), AB_MCP_IT_Sites::docroot( 'b' ), 'The two sites lie at different paths.' );
		list( $run_id, , $target ) = self::ready_to_swap();
		$faults = array();
		if ( 'a file after the report' === $case ) {
			self::assertNotFalse( file_put_contents( $target . '/neu.txt', 'neu' ) );
		} elseif ( 'an automatic update after the report' === $case ) {
			AB_MCP_IT_Code::put( 'a', 'plugin', 'ab-it-aktiv', '2.0.0' );
		} else {
			$faults[] = array(
				'method' => 'stat',
				'kind'   => 'before',
				'before' => static function () use ( $target ): void {
					if ( self::within( 'checked' ) && ! file_exists( $target . '/neu.txt' ) ) {
						self::assertNotFalse( file_put_contents( $target . '/neu.txt', 'neu' ) );
					}
				},
			);
		}
		$before = self::before_swap( $target );
		$answer = self::swap_with( $run_id, $faults );
		if ( 'between prepare() and put_tree()' === $case ) {
			self::assertTrue( file_exists( $target . '/neu.txt' ), 'The change came after prepare() read the unit.' );
			$before['old'] = self::digest( $target );
		}
		self::nothing_begun( $run_id, $target, $before, $answer, 'code:live_changed', 'Where the plugin writes into its own folder while it runs, update it by hand in wp-admin; else make a new report (wp_site_diff) and plan again.', 'between prepare() and put_tree()' === $case ? array( 'group' ) : array() );
	}

	/** What changed the unit on this site after the report. */
	public static function live_changed(): array {
		return array(
			'a file after the report'              => array( 'a file after the report' ),
			'an automatic update after the report' => array( 'an automatic update after the report' ),
			'between prepare() and put_tree()'     => array( 'between prepare() and put_tree()' ),
		);
	}

	/** The calls of a run until its step 3 ended, a call refused or no step is left, at most $max: seq, state and message of each (a refusal: error, code, message). */
	private static function steps_until_swap( int $run_id, int $max = 30 ): array {
		$trail = array();
		for ( $i = 0; $i < $max; $i++ ) {
			$answer  = self::next( $run_id );
			$trail[] = is_wp_error( $answer ) ? array( 'error', $answer->get_error_code(), $answer->get_error_message() ) : array( $answer['step']['seq'] ?? null, $answer['step']['state'] ?? null, (string) ( $answer['next'] ?? '' ) );
			if ( is_wp_error( $answer ) || ( 3 === ( $answer['step']['seq'] ?? null ) && 'planned' !== $answer['step']['state'] ) || ! isset( $answer['step'] ) ) {
				break;
			}
		}
		return $trail;
	}

	/**
	 * Prüft: F43: more code of the staging site than one package holds (a
	 * unit with more files than the lines of the manifest of one package,
	 * 5000), the unit over two packages. A run given only the first one does
	 * not swap it: its step of the stage ends failed (code:stage_incomplete),
	 * its swap is skipped without any call (no journal, no mark, no note),
	 * the lock for changes is free, nothing of this site changed. The answer
	 * names the paths of the files missing and the way: a new run with
	 * every package, as this run does not stage it again (Codex D4, finding
	 * 5). That way followed (the rest exported with the paths of the
	 * answer, a new run given both packages), the swap puts the whole unit
	 * at the target.
	 * Sonst: the swap could put a part of a plugin into the site, without
	 * the files of its next package; or the customer would not learn which
	 * files to export and would call action=next on a run that never stages
	 * the unit again.
	 */
	public function testAUnitOverSeveralPackagesIsSwappedOnlyWithItsWholeManifestTheRestInTheNextRun(): void {
		$b                         = null;
		list( $args, , , , $report ) = self::changed(
			'ab-it-inaktiv',
			'1.1.0',
			array(),
			static function ( string $put ) use ( &$b ): void {
				$b = $put;
				self::assertTrue( mkdir( $b . '/viele', 0755 ) );
				for ( $i = 0; $i < 5001; $i++ ) {
					file_put_contents( $b . '/viele/f' . $i . '.txt', (string) $i );
				}
			}
		);
		$target = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-inaktiv' );
		$before = self::live_bytes( 'ab-it-inaktiv' );
		$since  = AB_MCP_Update_Log::replaced_at( 'plugin', 'ab-it-inaktiv' );
		self::wporg( array() );
		$ref   = array(
			'kind' => 'plugin',
			'ref'  => 'ab-it-inaktiv',
		);
		$first = static function () use ( $report, $ref ): array {
			self::clear_b();
			list( $id, $p ) = self::relayed(
				array(
					'purpose'     => 'code',
					'report_hash' => $report['report_hash'],
					'refs'        => array( $ref ),
				)
			);
			self::assertCount( 1, $p['code']['left'], 'Not every file of the unit in the first package.' );
			return array( $id, $p['code']['left'][0]['paths'] );
		};
		list( $one, $left ) = $first();
		list( , $run )      = self::begun( $args + array( 'packages' => array( $one ) ) );
		$run_id             = (int) $run['run_id'];
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Code_Swap::class, 'naht_sync_code_swap', new \AB_MCP_Sync_Code_Swap_Calls() );
		$trail = self::steps_until_swap( $run_id );
		self::assertSame( array( array( 1, 'done' ), array( 2, 'failed' ), array( null, null ) ), array_map( static fn( $t ) => array( $t[0], $t[1] ), $trail ), (string) json_encode( $trail ) );
		$says = $trail[1][2];
		self::assertStringContainsString( 'The plugin ab-it-inaktiv is not staged (code:stage_incomplete), so it is not swapped; nothing of this site changed.', $says );
		self::assertStringContainsString( 'Export them on the staging site (wp_site_export with purpose=code', $says, 'With the way.' );
		// The answer names the paths of the files missing (the result holds
		// them too) and the new run, for this one does not stage it again.
		self::assertSame( 1, preg_match( '/for paths of wp_site_export: (\[.*?\])\. This run does not stage/s', $says, $m ), $says );
		$named = json_decode( $m[1], true );
		self::assertIsArray( $named );
		self::assertNotSame( array(), $named, 'The answer names the files missing.' );
		self::assertSame( array(), array_values( array_diff( $named, $left ) ), 'Each of them one the first package left.' );
		self::assertSame( $named, self::step( $run_id, 2 )['result']['paths'], 'The result of the step holds the same paths.' );
		self::assertStringContainsString( 'This run does not stage the plugin ab-it-inaktiv again: plan a new run (wp_site_push action=plan) with every package of the unit, the one with these files as well; where this run is still open, abort it first (wp_site_push action=abort).', $says );
		// The swap of the step not run: skipped, without a journal; no call
		// of the swap, no mark, no note, the lock for changes free.
		$step = self::step( $run_id, 3 );
		self::assertSame( array( 'skipped', null ), array( $step['state'], $step['parts'] ?? null ) );
		self::assertSame( array(), AB_MCP_Test_Seam::recorded()[ AB_MCP_Sync_Code_Swap::class . '::$naht_sync_code_swap' ] ?? array(), 'No call of the swap.' );
		self::assertNull( self::mark_of_swap( $run_id ), 'No mark.' );
		self::assertSame( $since, AB_MCP_Update_Log::replaced_at( 'plugin', 'ab-it-inaktiv' ), 'No note of the replacement.' );
		self::assertFalse( AB_MCP_Sync_Code_Swap::lock_held(), 'The swap holds no lock for changes.' );
		self::assertTrue( AB_MCP_Change_Lock::take(), 'The lock for changes can be taken again.' );
		AB_MCP_Change_Lock::release();
		self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the live unit changed.' );
		// The way of the answer: abort this run, export the paths it named,
		// and a new run with every package of the unit.
		$closed = self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) );
		self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
		list( $one )  = $first();
		list( $two )  = self::relayed(
			array(
				'purpose'     => 'code',
				'report_hash' => $report['report_hash'],
				'refs'        => array( $ref + array( 'paths' => $named ) ),
			)
		);
		list( , $run ) = self::begun( $args + array( 'packages' => array( $one, $two ) ) );
		$next          = (int) $run['run_id'];
		$trail         = self::steps_until_swap( $next );
		self::assertSame( array( 3, 'done' ), array_slice( (array) end( $trail ), 0, 2 ), (string) json_encode( $trail ) );
		self::assertStringContainsString( 'from packages', $trail[1][2] );
		clearstatcache();
		self::assertCount( 5001, (array) glob( $target . '/viele/f*.txt' ) );
		self::assertSame( self::digest( $b ), self::digest( $target ), 'The whole unit of the staging site at the target.' );
	}

	/**
	 * Prüft: F43, the way of an answer that does not name every file
	 * missing (Codex D5: a list cut to the first paths the result of a step
	 * holds says that the new run names the next ones). Here the packages
	 * of a run hold only a part of the files it named as missing, which
	 * leaves files missing as a cut list does: the new run with every
	 * package so far does not swap the unit either, its step of the stage
	 * ends failed again and names exactly the files still missing, in their
	 * order and none a package holds by now; nothing of this site changed.
	 * With those exported as well, the run after it swaps the whole unit.
	 * Sonst: the way of a cut list would end nowhere: the new run would
	 * name the same files again or none, and the unit would never be staged
	 * whole.
	 */
	public function testAUnitWhoseFilesMissingComeInSeveralExportsIsNamedTheNextOnesByEachNewRunUntilItIsSwappedWhole(): void {
		$b                         = null;
		list( $args, , , , $report ) = self::changed(
			'ab-it-inaktiv',
			'1.1.0',
			array(),
			static function ( string $put ) use ( &$b ): void {
				$b = $put;
				self::assertTrue( mkdir( $b . '/neu', 0755 ) );
				foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $name ) {
					file_put_contents( $b . '/neu/' . $name . '.txt', $name );
				}
			}
		);
		$target = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-inaktiv' );
		$before = self::live_bytes( 'ab-it-inaktiv' );
		self::wporg( array() );
		$ref = array(
			'kind' => 'plugin',
			'ref'  => 'ab-it-inaktiv',
		);
		// The packages of a run, made anew for it: one per list of paths.
		$packages = static function ( array $lists ) use ( $report, $ref ): array {
			self::clear_b();
			$ids = array();
			foreach ( $lists as $paths ) {
				list( $ids[] ) = self::relayed(
					array(
						'purpose'     => 'code',
						'report_hash' => $report['report_hash'],
						'refs'        => array( $ref + array( 'paths' => $paths ) ),
					)
				);
			}
			return $ids;
		};
		// A run with these packages that does not stage the unit: the paths
		// its answer names as missing; its swap skipped, nothing changed,
		// the run aborted as the answer says.
		$named = static function ( array $ids ) use ( $args, $before ): array {
			list( , $run ) = self::begun( $args + array( 'packages' => $ids ) );
			$run_id        = (int) $run['run_id'];
			$trail         = self::steps_until_swap( $run_id );
			self::assertSame( array( array( 1, 'done' ), array( 2, 'failed' ), array( null, null ) ), array_map( static fn( $t ) => array( $t[0], $t[1] ), $trail ), (string) json_encode( $trail ) );
			$says = $trail[1][2];
			self::assertStringContainsString( 'The plugin ab-it-inaktiv is not staged (code:stage_incomplete), so it is not swapped; nothing of this site changed.', $says );
			self::assertSame( 1, preg_match( '/for paths of wp_site_export: (\[.*?\])\. This run does not stage/s', $says, $m ), $says );
			$paths = json_decode( $m[1], true );
			self::assertIsArray( $paths );
			self::assertSame( $paths, self::step( $run_id, 2 )['result']['paths'], 'The result of the step holds the same paths.' );
			self::assertSame( 'skipped', self::step( $run_id, 3 )['state'] );
			self::assertSame( $before, self::live_bytes( 'ab-it-inaktiv' ), 'Nothing of the live unit changed.' );
			$closed = self::gated( 'wp_site_push', array( 'action' => 'abort', 'run_id' => $run_id ) );
			self::assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
			return $paths;
		};
		// The first run has the main file only: every other file of the unit
		// that this site does not have is missing.
		$first   = array( 'ab-it-inaktiv.php' );
		$missing = $named( $packages( array( $first ) ) );
		self::assertSame( array(), array_values( array_diff( array( 'neu/a.txt', 'neu/b.txt', 'neu/c.txt', 'neu/d.txt', 'neu/e.txt' ), $missing ) ), (string) json_encode( $missing ) );
		// The second run has the first three of them as well, as after a
		// list that named only those: it names the next ones, and only them.
		$part = array_slice( $missing, 0, 3 );
		$rest = array_slice( $missing, 3 );
		self::assertNotSame( array(), $rest );
		self::assertSame( $rest, $named( $packages( array( $first, $part ) ) ), 'The new run names the files still missing, in their order, and none a package holds by now.' );
		// The third run has every package of the unit: it is swapped whole.
		list( , $run ) = self::begun( $args + array( 'packages' => $packages( array( $first, $part, $rest ) ) ) );
		$trail         = self::steps_until_swap( (int) $run['run_id'] );
		self::assertSame( array( 3, 'done' ), array_slice( (array) end( $trail ), 0, 2 ), (string) json_encode( $trail ) );
		self::assertStringContainsString( 'from packages', $trail[1][2] );
		clearstatcache();
		self::assertSame( self::digest( $b ), self::digest( $target ), 'The whole unit of the staging site at the target.' );
	}

	/**
	 * Prüft: G-5-4: the swap takes only the stage the journal of its step of
	 * the stage names (AB_MCP_Sync_Code_Ops::swap(), staged()). A group of
	 * the stage of the same unit left without a journal (a stage whose
	 * journal was not saved) is not taken: the swap puts the stage of the
	 * journal at the target, and that group stays as it was. A journal that
	 * names the stage of another unit, or another group than the one of its
	 * tree, is refused before the first mutation: the step stays planned,
	 * nothing is written or moved.
	 * Sonst: the swap could put a stage the run never checked, or the wrong
	 * unit, into the site.
	 */
	#[DataProvider( 'stages' )]
	public function testTheSwapTakesOnlyTheStageTheJournalOfItsStepNames( string $case ): void {
		list( $run_id, , $target ) = self::ready_to_swap();
		// A group of the stage of this unit without a journal, as stage()
		// leaves one where its journal is not saved: here with the version
		// of this site, so that it differs from the stage of the journal.
		$gid = AB_MCP_Protected_Store::open(
			'stage',
			array(
				'run_id' => $run_id,
				'label'  => 'code',
			)
		);
		self::assertIsString( $gid, is_wp_error( $gid ) ? $gid->get_error_message() : '' );
		$orphan = AB_MCP_Protected_Store::put_tree( 'stage', $gid, 'ab-it-aktiv', $target, $target );
		self::assertIsArray( $orphan, is_wp_error( $orphan ) ? $orphan->get_error_message() : '' );
		$kept    = self::digest( $orphan['path'] );
		$journal = self::step( $run_id, 2 )['parts'];
		if ( 'an orphan group of the same unit' === $case ) {
			$answer = self::swap_with( $run_id, array() );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
			self::assertSame( 'done', self::step( $run_id, 3 )['state'], $answer['next'] );
			self::assertSame( '2.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-aktiv' ), 'The stage of the journal at the target.' );
			self::assertSame( $journal['stage']['hash'], self::step( $run_id, 3 )['parts']['new']['hash'], 'The tree the journal names.' );
			clearstatcache();
			self::assertSame( $kept, self::digest( $orphan['path'] ), 'The orphan group stays as it was.' );
			return;
		}
		$wrong = $journal;
		if ( 'a journal of another unit' === $case ) {
			$wrong['unit']['ref'] = 'ab-it-inaktiv';
		} else {
			$wrong['stage']['gid'] = $gid;
		}
		self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, 2, array( 'parts' => $wrong ), 'done' ) );
		$before = self::before_swap( $target );
		$answer = self::swap_with( $run_id, array() );
		if ( 'a journal of another unit' === $case ) {
			self::nothing_begun( $run_id, $target, $before, $answer, 'ab_mcp_sync_code_journal', 'Abort the run (wp_site_push action=abort), make a new report (wp_site_diff) and plan again.' );
		} else {
			self::nothing_begun( $run_id, $target, $before, $answer, 'code:stage_not_ready', 'Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort) and plan again.' );
		}
		clearstatcache();
		self::assertSame( $kept, self::digest( $orphan['path'] ), 'The other group stays as it was.' );
	}

	/** What the journal of the stage names beside the stage of its tree. */
	public static function stages(): array {
		return array(
			'an orphan group of the same unit' => array( 'an orphan group of the same unit' ),
			'a journal of another unit'        => array( 'a journal of another unit' ),
			'a journal of another group'       => array( 'a journal of another group' ),
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
		// active plugin is planned only with its number in accept; without it
		// the lock names the operation and the versions (F2, D3a).
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		list( $args, $entry, , $units ) = self::changed( 'ab-it-aktiv', '2.0.0' );
		$n = (int) $entry['n'];
		$m = (int) $units['plugin:ab-it-inaktiv']['n'];
		self::wporg();
		$message = self::refused( self::gated( 'wp_site_push', $args ), 'ab_mcp_sync_push_nothing' );
		self::assertStringContainsString( "Entry $n swaps code this site runs", $message, 'Locked with the operation.' );
		self::assertStringContainsString( 'the plugin ab-it-aktiv is active, version 1.0.0 here, 2.0.0 on the staging site. It is taken over only with its number in accept', $message, 'With the versions (F2, D3a).' );
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
		self::replan( $run_id, $journal );
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
