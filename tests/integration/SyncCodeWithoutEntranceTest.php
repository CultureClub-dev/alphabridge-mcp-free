<?php
/**
 * A run of the code without its forward entrances (I4 of the seam, step
 * T4; D5 and D6 of the build sheet): what the core does where the file of
 * AB_MCP_Sync_Code_Plan or of AB_MCP_Sync_Code_Ops did not put its entrance
 * (AB_MCP_Sync_Push::admit_code(), AB_MCP_Sync_Engine::admit_code()), here
 * only (this plugin puts both as its files load). On two WordPress sites as
 * tests/integration/SyncCodeRunTest.php (B the staging site, A this
 * process), a plugin updated on B, its unit paired, its report:
 *
 * - (a) the planning: without its entrance plan() plans no run of the code
 *   and ends where a run of the contents without packages ends, with the
 *   text it has for that; no run is made. With it, the preview of the run
 *   of the code. begin() itself, with a plan of the code made before,
 *   refuses with the same text and closes the run it made at once.
 * - (b) the forward way: a run of the code planned, confirmed and checked
 *   with both entrances, then the one of the engine taken away. Its stage
 *   fails with the text the core has for a kind no step writes («No step of
 *   this version writes the kind code.», as boundary() fails a step of a
 *   kind without a writer), its swap is skipped, the run closes with
 *   findings; nothing is staged or swapped, the files of the unit stay.
 * - (c) the check of the run: as (b), its two steps done as if the entrance
 *   had run them. The check closes the run with that text as the finding of
 *   each step, no error; written() itself gives it without the entrance and
 *   the finding of the code (none) with it.
 * - (d) with the entrance of the planning, as this plugin puts it, begin()
 *   of a run of the code makes no refusal (no ab_mcp_sync_push_packages
 *   through the action wp_error_added, its text never through the filter
 *   gettext), and the run begins as before.
 *
 * The way back without the entrance holds SyncCodeRunTest (with the place of
 * AB_MCP_Sync_Revert emptied). The entrances are put back after each case.
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
use AB_MCP_Sync_Engine;
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Pairs_Tool;
use AB_MCP_Sync_Push;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';

final class SyncCodeWithoutEntranceTest extends TestCase {

	/** The text of the core for a kind no step writes, with the kind code (Engine check_item() and boundary()). */
	private const NONE = 'No step of this version writes the kind code.';

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
		AB_MCP_Sync_Engine::admit_code( 'AB_MCP_Sync_Code_Ops' );
		AB_MCP_Sync_Push::admit_code( 'AB_MCP_Sync_Code_Plan' );
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
			)
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

	/** A tool through the gate of the plugin, in this process. */
	private static function gated( string $name, array $a ) {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return AB_MCP_REST_Controller::run_tool( $name, $r->get( $name ), $a );
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

	/** The units paired, the plugin ab-it-inaktiv of B put to 1.1.0, the report of both sites with the environment: the plan arguments for its entry. */
	private static function changed(): array {
		self::paired();
		AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-inaktiv', '1.1.0' );
		self::clear_b();
		$answer = AB_MCP_Sync_Report::call(
			array(
				'packages'    => self::staged( 'inventory' ),
				'environment' => self::staged( 'env' ),
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
		foreach ( $all as $entry ) {
			if ( 'environment' === $entry['kind'] && 'plugin' === $entry['subtype'] && 'ab-it-inaktiv' === $entry['ref'] ) {
				return array(
					'action'    => 'plan',
					'report_id' => $answer['report_id'],
					'select'    => array( (int) $entry['n'] ),
				);
			}
		}
		self::fail( 'The report names the plugin ab-it-inaktiv.' );
	}

	/** A plan confirmed in this process, its check run with both entrances: the run id. */
	private static function checked( array $args ): int {
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_code() . ': ' . $run->get_error_message() : '' );
		self::assertSame( 'open', $run['state'] );
		$run_id = (int) $run['run_id'];
		self::assertSame( 'done', self::next( $run_id )['step']['state'], 'The check of the run.' );
		return $run_id;
	}

	/** wp_site_push action=next, in this process. */
	private static function next( int $run_id ) {
		return self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
	}

	/** wp_site_push action=next until the run is closed, each answer no error: the last answer. */
	private static function closed( int $run_id ): array {
		for ( $i = 0; $i < 8; $i++ ) {
			$answer = self::next( $run_id );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return $answer;
			}
		}
		self::fail( 'The run closes.' );
	}

	/** The step of a run by its number. */
	private static function step( int $run_id, int $seq ): array {
		$step = AB_MCP_Sync_Run::step( $run_id, $seq );
		self::assertIsArray( $step );
		return $step;
	}

	/** The findings of the check of a closed run (its last step) by the number of their step. */
	private static function findings( int $run_id ): array {
		$steps = AB_MCP_Sync_Run::steps( $run_id );
		self::assertIsArray( $steps );
		$out = array();
		foreach ( end( $steps )['result']['findings'] as $finding ) {
			$out[ (int) $finding['seq'] ][] = $finding['message'];
		}
		return $out;
	}

	/** The groups of the stage bound to a run. */
	private static function stages_of( int $run_id ): array {
		$groups = AB_MCP_Protected_Store::groups( 'stage' );
		self::assertIsArray( $groups );
		return array_values( array_filter( $groups, static fn( $g ) => (int) $g['run_id'] === $run_id ) );
	}

	/** The files of the plugin ab-it-inaktiv of A by their SHA-256. */
	private static function live_bytes(): array {
		$out  = array();
		$path = AB_MCP_IT_Code::path( 'a', 'plugin', 'ab-it-inaktiv' );
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$out[ substr( $file->getPathname(), strlen( $path ) ) ] = hash_file( 'sha256', $file->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	/* -------------------------------------------------------------- cases */

	/**
	 * Prüft: (a) without the entrance of the planning a selection of a unit
	 * is no plan of the code: plan() ends at the text of a run of the
	 * contents without packages, and no run is made; with it, the preview of
	 * the run of the code with its unit. begin(), with a plan of the code
	 * made with the entrance, refuses without it with the same text and
	 * closes the run it made at once, nothing staged.
	 * Sonst: the core plans or begins a run of the code past its one
	 * entrance (D5), or a run is left open without it.
	 */
	public function testWithoutTheEntranceOfThePlanningNoRunOfTheCodeIsPlannedOrBegun(): void {
		$args = self::changed();
		AB_MCP_Sync_Push::admit_code( null );
		try {
			$answer = self::gated( 'wp_site_push', $args );
		} finally {
			AB_MCP_Sync_Push::admit_code( 'AB_MCP_Sync_Code_Plan' );
		}
		$says = self::refused( $answer, 'ab_mcp_sync_push_packages' );
		self::assertStringStartsWith( 'A run writes from packages of objects of the staging site: pass packages', $says, 'The text of a run of the contents without packages.' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'No run.' );
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
		self::assertSame( array( 'ab-it-inaktiv' ), array_column( $preview['preview']['units'], 'ref' ), 'With the entrance: the run of the code.' );
		$plan = AB_MCP_Sync_Push::plan( $args );
		self::assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		self::assertArrayHasKey( 'code', $plan );
		$begin = new ReflectionMethod( AB_MCP_Sync_Push::class, 'begin' );
		AB_MCP_Sync_Push::admit_code( null );
		try {
			$begun = $begin->invoke( null, $plan );
		} finally {
			AB_MCP_Sync_Push::admit_code( 'AB_MCP_Sync_Code_Plan' );
		}
		$says = self::refused( $begun, 'ab_mcp_sync_push_packages' );
		self::assertStringStartsWith( 'A run writes from packages of objects of the staging site: pass packages', $says );
		self::assertSame( 1, preg_match( '/Run ([1-9][0-9]*) was made for this plan and closed again as aborted at once: nothing was staged or written\./', $says, $made ), $says );
		self::assertSame( 'closed_aborted', AB_MCP_Sync_Run::get( (int) $made[1] )['state'], 'The run made is closed again at once.' );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'The site is free.' );
		self::assertSame( array(), self::stages_of( (int) $made[1] ), 'Nothing staged.' );
	}

	/**
	 * Prüft: (b) without the forward entrance of the engine the stage of a
	 * run of the code fails with the text the core has for a kind no step
	 * writes, the answer names the way on, its swap is skipped, and the run
	 * closes with that finding; nothing is staged, the files of the unit stay
	 * as they were.
	 * Sonst: the core runs a step of the code past its one entrance (D5), or
	 * a run of the code ends with an error because its class is missing (1a
	 * 2.5 way 4).
	 */
	public function testWithoutTheForwardEntranceAStepOfTheCodeFailsAndNothingIsStagedOrSwapped(): void {
		$run_id = self::checked( self::changed() );
		$before = self::live_bytes();
		AB_MCP_Sync_Engine::admit_code( null );
		try {
			$failed = self::next( $run_id );
			$closed = self::closed( $run_id );
		} finally {
			AB_MCP_Sync_Engine::admit_code( 'AB_MCP_Sync_Code_Ops' );
		}
		self::assertIsArray( $failed, is_wp_error( $failed ) ? $failed->get_error_code() . ': ' . $failed->get_error_message() : '' );
		self::assertSame( array( 2, 'failed', 'Not written: ' . self::NONE ), array( $failed['step']['seq'], $failed['step']['state'], $failed['step']['result']['message'] ) );
		self::assertStringContainsString( 'The run goes on with the next step (action=next).', $failed['next'], 'With the way.' );
		self::assertNull( self::step( $run_id, 2 )['parts'], 'No journal of a stage.' );
		self::assertSame( array( 'skipped', 'Skipped: waits for step 2, which is failed.' ), array( self::step( $run_id, 3 )['state'], self::step( $run_id, 3 )['result']['message'] ), 'The swap never runs.' );
		self::assertSame( 'closed_findings', $closed['state'] );
		self::assertSame( array( 'Step 2 is failed. Not written: ' . self::NONE ), self::findings( $run_id )[2] );
		self::assertSame( array(), self::stages_of( $run_id ), 'Nothing staged.' );
		self::assertSame( $before, self::live_bytes(), 'Nothing swapped.' );
		self::assertSame( '1.0.0', AB_MCP_IT_Code::fassung( 'a', 'plugin', 'ab-it-inaktiv' ) );
	}

	/**
	 * Prüft: (c) the check of a run of the code without the forward entrance
	 * (its two steps done as if the entrance had run them, as
	 * SyncCodeRunTest sets a step): the run closes with the text the core has
	 * for a kind no step writes as the finding of each step, no error.
	 * written() itself (the check closes over it): that finding without the
	 * entrance, the finding of the code (none) with it.
	 * Sonst: the check of a run asks the code past its one entrance (D5), or
	 * ends with an error where it is not loaded.
	 */
	public function testWithoutTheForwardEntranceTheCheckNamesTheStepsOfTheCodeAsFindings(): void {
		$run_id = self::checked( self::changed() );
		foreach ( array( 2, 3 ) as $seq ) {
			self::assertTrue( AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => 'done' ), 'planned' ) );
		}
		$written = new ReflectionMethod( AB_MCP_Sync_Engine::class, 'written' );
		$run     = AB_MCP_Sync_Run::get( $run_id );
		$two     = self::step( $run_id, 2 );
		self::assertSame( array( 0, null ), $written->invoke( null, $run, $two, array(), array() ), 'With the entrance: no finding of a step of the code done.' );
		AB_MCP_Sync_Engine::admit_code( null );
		try {
			$direct = $written->invoke( null, $run, $two, array(), array() );
			$closed = self::closed( $run_id );
		} finally {
			AB_MCP_Sync_Engine::admit_code( 'AB_MCP_Sync_Code_Ops' );
		}
		self::assertSame( array( 0, array( 'seq' => 2, 'message' => self::NONE ) ), $direct, 'A finding, no error.' );
		self::assertSame( 'closed_findings', $closed['state'] );
		$found = self::findings( $run_id );
		self::assertSame( array( array( self::NONE ), array( self::NONE ) ), array( $found[2] ?? null, $found[3] ?? null ) );
	}

	/**
	 * Prüft: (d) with the entrance of the planning (as this plugin puts it)
	 * begin() of a run of the code makes no refusal: no WP_Error
	 * ab_mcp_sync_push_packages through the action wp_error_added, the text
	 * of packages_refusal() never through the filter gettext; the run
	 * begins and its check runs as before. The refusal is made only without
	 * the entrance (a).
	 * Sonst: every run of the code that begins makes and drops a refusal,
	 * with the hooks of WordPress on it (the lead on 7ae8e08, Nachtrag 2).
	 */
	public function testWithTheEntranceOfThePlanningARunOfTheCodeBeginsWithoutMakingTheRefusal(): void {
		$args    = self::changed();
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() : '' );
		$made  = array();
		$said  = array();
		$added = static function ( $code ) use ( &$made ) {
			$made[] = (string) $code;
		};
		$text  = static function ( $translation, $text ) use ( &$said ) {
			$said[] = (string) $text;
			return $translation;
		};
		add_action( 'wp_error_added', $added );
		add_filter( 'gettext', $text, 10, 2 );
		try {
			$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		} finally {
			remove_action( 'wp_error_added', $added );
			remove_filter( 'gettext', $text, 10 );
		}
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_code() . ': ' . $run->get_error_message() : '' );
		self::assertSame( 'open', $run['state'], 'The run begins.' );
		self::assertNotContains( 'ab_mcp_sync_push_packages', $made, 'No refusal of the packages is made.' );
		self::assertSame( array(), preg_grep( '/^A run writes from packages of objects of the staging site/', $said ), 'Its text is never asked for.' );
		self::assertSame( 'done', self::next( (int) $run['run_id'] )['step']['state'], 'The check of the run, as before.' );
	}
}
